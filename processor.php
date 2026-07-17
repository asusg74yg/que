<?php
ignore_user_abort(true);
set_time_limit(0);

require_once __DIR__ . '/shared.php';

// ─── Worker Locking (Dynamic Worker Slots) ───────────────────────────────────
// We use cross-platform flock-based locks on worker files: worker_1.lock, worker_2.lock, etc.
// Up to concurrency_limit.

function acquire_worker_slot(int $concurrency_limit, &$slot_id, &$lock_fp): bool {
    for ($i = 1; $i <= $concurrency_limit; $i++) {
        $lockFile = __DIR__ . '/worker_' . $i . '.lock';
        $fp = fopen($lockFile, 'c+');
        if (!$fp) continue;

        // Non-blocking exclusive lock check
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            // Check if there's a stale lock or write PID to the file
            ftruncate($fp, 0);
            fwrite($fp, (string)getmypid());
            fflush($fp);

            $slot_id = $i;
            $lock_fp = $fp;
            return true;
        }
        fclose($fp);
    }
    return false;
}

function release_worker_slot($fp): void {
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// ─── Parallel Worker Spawning helper ─────────────────────────────────────────
function spawn_another_worker(): void {
    $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
         . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . dirname($_SERVER['REQUEST_URI'] ?? '/') . '/processor.php?action=start';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    curl_exec($ch);
    curl_close($ch);
    log_msg('info', 'Triggered another background worker spawning check.');
}

// ─── Filename Resolution ──────────────────────────────────────────────────────
function get_filename_from_headers(array $headers, string $url): ?string {
    // Parse Content-Disposition
    foreach ($headers as $header) {
        if (stripos($header, 'Content-Disposition') !== false) {
            // matches filename="..." or filename=...
            if (preg_match('/filename\s*=\s*(["\']?)([^"\';]+)\1/i', $header, $matches)) {
                return trim($matches[2]);
            }
        }
    }
    return null;
}

function get_extension_from_content_type(string $contentType): ?string {
    $map = [
        'text/html' => 'html',
        'text/plain' => 'txt',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'audio/mpeg' => 'mp3',
        'video/mp4' => 'mp4',
        'application/octet-stream' => 'bin',
    ];
    $contentType = strtolower(trim(explode(';', $contentType)[0]));
    return $map[$contentType] ?? null;
}

function get_ext_from_headers(array $headers): ?string {
    foreach ($headers as $header) {
        if (stripos($header, 'Content-Type') !== false) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                return get_extension_from_content_type($parts[1]);
            }
        }
    }
    return null;
}

function resolve_unique_filename(string $targetPath): string {
    if (!file_exists($targetPath)) {
        return $targetPath;
    }
    $info = pathinfo($targetPath);
    $dir = $info['dirname'];
    $name = $info['filename'];
    $ext = isset($info['extension']) ? '.' . $info['extension'] : '';

    $i = 1;
    do {
        $newPath = $dir . DIRECTORY_SEPARATOR . $name . '_' . $i . $ext;
        $i++;
    } while (file_exists($newPath));

    return $newPath;
}

// ─── Download Worker Execution ────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'start') {
    $config = load_config();
    $concurrency = (int)($config['concurrency_limit'] ?? 3);

    // Acquire lock slot
    $slot_id = null;
    $lock_fp = null;
    if (!acquire_worker_slot($concurrency, $slot_id, $lock_fp)) {
        // Concurrency limit reached
        exit;
    }

    log_msg('info', "Worker slot {$slot_id} acquired.");

    // Loop and fetch pending items
    while (true) {
        // Check global stop flag
        if (file_exists(STOP_FLAG)) {
            log_msg('info', "Worker slot {$slot_id} detected stop flag. Exiting.");
            break;
        }

        // Find a pending task atomically and claim it
        $task = null;
        update_queue_state(function (&$state) use (&$task) {
            foreach ($state['tasks'] as &$t) {
                if ($t['status'] === 'pending') {
                    $t['status'] = 'downloading';
                    $t['error_message'] = '';
                    $task = $t;
                    break;
                }
            }
        });

        if (!$task) {
            // No pending tasks left
            break;
        }

        log_msg('info', "Slot {$slot_id} started downloading: {$task['url']}");

        // Perform Stream cURL Download
        $download_success = false;
        $error_msg = '';
        $final_dest = null;

        try {
            $url = $task['url'];

            // Build custom headers
            $custom_headers = [];
            if (!empty($config['headers'])) {
                $lines = explode("\n", str_replace("\r", "", $config['headers']));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line) $custom_headers[] = $line;
                }
            }
            if (!empty($config['referer'])) {
                $custom_headers[] = 'Referer: ' . trim($config['referer']);
            }

            // Setup User Agent
            $ua = $config['user_agent'] ?: 'DownloadQueueManager/1.0';
            if ($config['random_ua']) {
                $uaPool = [
                    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
                    'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
                    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Edge/124.0.0.0 Safari/537.36',
                ];
                $ua = $uaPool[array_rand($uaPool)];
            }

            // Target naming
            $namingMode = $config['naming_mode'] ?: 'tail';
            $outputDir = $config['output_dir'] ?: __DIR__ . '/downloads';
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0777, true);
            }

            // To support resuming, we need to do a preflight request or resolve the target name first.
            // Let's resolve the target name without body using a curl preflight HEAD request or stream resolution,
            // so we know exactly which target file to resume/append to before downloading.

            $temp_headers = [];
            $ch_head = curl_init();
            curl_setopt_array($ch_head, [
                CURLOPT_URL            => $url,
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$temp_headers) {
                    $temp_headers[] = $header;
                    return strlen($header);
                },
            ]);
            if (!empty($config['cookies'])) {
                curl_setopt($ch_head, CURLOPT_COOKIE, $config['cookies']);
            }
            if (!empty($config['proxy'])) {
                curl_setopt($ch_head, CURLOPT_PROXY, $config['proxy']);
            }
            curl_exec($ch_head);
            curl_close($ch_head);

            // Now resolve the name
            $disposition_filename = get_filename_from_headers($temp_headers, $url);
            if ($disposition_filename) {
                $target_name = $disposition_filename;
            } else {
                if ($namingMode === 'mirror') {
                    $parsed = parse_url($url);
                    $path_part = ltrim($parsed['path'] ?? 'file', '/');
                    $target_name = str_replace('/', DIRECTORY_SEPARATOR, $path_part);
                } else if ($namingMode === 'incremental') {
                    $tasks = read_queue_state()['tasks'];
                    $index = 1;
                    foreach ($tasks as $t) {
                        if ($t['id'] === $task['id']) break;
                        $index++;
                    }
                    $ext = get_ext_from_headers($temp_headers) ?: 'bin';
                    $target_name = str_pad($index, 4, '0', STR_PAD_LEFT) . '.' . $ext;
                } else { // tail
                    $target_name = basename(parse_url($url, PHP_URL_PATH));
                    if (!$target_name) {
                        $ext = get_ext_from_headers($temp_headers) ?: 'bin';
                        $target_name = 'download_' . substr(md5($url), 0, 8) . '.' . $ext;
                    } else {
                        $ext_info = pathinfo($target_name, PATHINFO_EXTENSION);
                        if (!$ext_info) {
                            $ext = get_ext_from_headers($temp_headers);
                            if ($ext) {
                                $target_name .= '.' . $ext;
                            }
                        }
                    }
                }
            }

            $resolved_path = $outputDir . DIRECTORY_SEPARATOR . $target_name;
            if (!empty($config['collision_suffix'])) {
                $resolved_path = resolve_unique_filename($resolved_path);
            }

            $final_dest = $resolved_path;

            // Create subdirectories if needed
            $sub_dir = dirname($final_dest);
            if (!is_dir($sub_dir)) {
                mkdir($sub_dir, 0777, true);
            }

            $is_resuming = false;
            $resume_offset = 0;
            if (file_exists($final_dest)) {
                if (!empty($config['skip_existing'])) {
                    // Skip existing file completely
                    log_msg('info', "Skipping existing file: {$final_dest}");
                    $download_success = true;
                    $is_skipped = true;
                } else {
                    $is_resuming = true;
                    $resume_offset = filesize($final_dest);
                }
            }

            if (!(isset($is_skipped) && $is_skipped)) {
                // Open file pointer
                $file_fp = fopen($final_dest, $is_resuming ? 'ab' : 'wb');

                // Headers parsed from response during download
                $response_headers = [];
                $downloaded_bytes_session = 0;
                $start_time = microtime(true);
                $last_update_time = 0;
                $speed_limit_kb = (float)($config['speed_limit'] ?? 0);

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $url,
                    CURLOPT_RETURNTRANSFER => false, // We're using stream callbacks
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 5,
                    CURLOPT_USERAGENT      => $ua,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_ENCODING       => '',
                    CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$response_headers) {
                        $len = strlen($header);
                        $response_headers[] = $header;
                        return $len;
                    },
                ]);

                if (!empty($config['cookies'])) {
                    curl_setopt($ch, CURLOPT_COOKIE, $config['cookies']);
                }

                if (!empty($config['proxy'])) {
                    curl_setopt($ch, CURLOPT_PROXY, $config['proxy']);
                }

                if ($is_resuming) {
                    curl_setopt($ch, CURLOPT_RESUME_FROM, $resume_offset);
                }

                curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (
                    &$file_fp, &$response_headers, &$task, &$downloaded_bytes_session,
                    &$resume_offset, &$start_time, &$last_update_time, $speed_limit_kb
                ) {
                    $len = strlen($data);
                    if ($file_fp) {
                        fwrite($file_fp, $data);
                    }

                    $downloaded_bytes_session += $len;
                    $total_downloaded = $resume_offset + $downloaded_bytes_session;

                    // Throttling / Speed limits
                    if ($speed_limit_kb > 0) {
                        $elapsed_sec = microtime(true) - $start_time;
                        if ($elapsed_sec > 0) {
                            $expected_sec = $downloaded_bytes_session / ($speed_limit_kb * 1024);
                            if ($expected_sec > $elapsed_sec) {
                                usleep((int)(($expected_sec - $elapsed_sec) * 1000000));
                            }
                        }
                    }

                    // Periodically update the progress state in JSON (e.g. every 0.5s)
                    $now = microtime(true);
                    if ($now - $last_update_time >= 0.5) {
                        $last_update_time = $now;

                        // Get Content-Length
                        $content_length = 0;
                        foreach ($response_headers as $h) {
                            if (stripos($h, 'Content-Length') !== false) {
                                $p = explode(':', $h, 2);
                                if (count($p) === 2) {
                                    $content_length = (int)trim($p[1]);
                                }
                            }
                        }

                        $total_size = $content_length > 0 ? ($content_length + $resume_offset) : $total_downloaded;

                        // Calculate speed and ETA
                        $elapsed = $now - $start_time;
                        $speed = $elapsed > 0 ? ($downloaded_bytes_session / $elapsed) : 0; // bytes/sec
                        $eta = 0;
                        if ($speed > 0 && $content_length > 0) {
                            $eta = (int)(($content_length - $downloaded_bytes_session) / $speed);
                        }

                        // Update JSON state
                        update_queue_state(function(&$state) use ($task, $total_size, $total_downloaded, $speed, $eta) {
                            foreach ($state['tasks'] as &$t) {
                                if ($t['id'] === $task['id']) {
                                    $t['size'] = $total_size;
                                    $t['download_speed'] = (float)($speed / 1024); // KB/s
                                    $t['eta'] = $eta;
                                    $t['progress'] = $total_size > 0 ? round(($total_downloaded / $total_size) * 100, 2) : 0;
                                    break;
                                }
                            }
                        });
                    }

                    return $len;
                });

                if ($custom_headers) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $custom_headers);
                }

                $res = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_err = curl_error($ch);
                curl_close($ch);

                if ($file_fp) {
                    fclose($file_fp);
                }

                if ($res && ($http_code === 200 || $http_code === 206)) {
                    $download_success = true;
                } else {
                    $error_msg = $curl_err ?: "HTTP Status Code: {$http_code}";
                }
            }

        } catch (Exception $e) {
            $error_msg = $e->getMessage();
        }

        // Apply delay between requests
        $delay = (int)($config['delay'] ?? 0);
        if ($delay > 0) {
            sleep($delay);
        }

        // Update task state on complete or fail
        $is_skipped_state = isset($is_skipped) && $is_skipped;

        update_queue_state(function (&$state) use ($task, $download_success, $error_msg, $final_dest, $is_skipped_state, $config) {
            foreach ($state['tasks'] as &$t) {
                if ($t['id'] === $task['id']) {
                    if ($download_success) {
                        $t['status'] = $is_skipped_state ? 'skipped' : 'completed';
                        $t['error_message'] = '';
                        $t['progress'] = 100;
                        if ($final_dest) {
                            $t['local_path'] = $final_dest;
                        }

                        // Trigger Post-Processing if configured
                        if (!$is_skipped_state && !empty($config['post_processing']) && $final_dest) {
                            $cmd = str_replace('{file}', escapeshellarg($final_dest), $config['post_processing']);
                            log_msg('info', "Running post-processing command: {$cmd}");
                            exec($cmd . ' > /dev/null 2>&1 &'); // run in background
                        }
                    } else {
                        // Retry count check
                        $t['retry_count']++;
                        $maxRetries = (int)($config['max_retries'] ?? 3);
                        if ($t['retry_count'] >= $maxRetries) {
                            $t['status'] = 'failed';
                            $t['error_message'] = $error_msg ?: 'Failed after maximum retries';
                            log_msg('error', "Task failed permanently: {$t['url']}. Error: {$error_msg}");
                        } else {
                            $t['status'] = 'pending'; // Put back to retry
                            $t['error_message'] = "Attempt failed: " . ($error_msg ?: 'Unknown error');
                            log_msg('warn', "Task failed attempt {$t['retry_count']}/{$maxRetries}: {$t['url']}. Retrying...");
                        }
                    }
                    break;
                }
            }
        });

        // Trigger safe next worker spawning
        spawn_another_worker();
    }

    // Release worker slot lock
    release_worker_slot($lock_fp);
    log_msg('info', "Worker slot {$slot_id} released.");
} else {
    // Direct call with no start action
    echo "High-Performance Background Downloader Engine active.";
}
