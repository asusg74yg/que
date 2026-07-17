<?php
require_once __DIR__ . '/shared.php';

// Disable PHP memory limits and run indefinitely
ignore_user_abort(true);
set_time_limit(0);

// Initialize CONFIG and STATE if they do not exist
$config = load_config();
if (!file_exists(STATE_FILE)) {
    update_queue_state(function(&$state) {
        $state['tasks'] = [];
    });
}

// ─── Sanitizer helper to prevent XSS ─────────────────────────────────────────
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX / API ENDPOINTS
// ─────────────────────────────────────────────────────────────────────────────

// 1. GET Stats & Queue Info (Status polling)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json');

    // Load active logs
    $log_tail = 'No log entries yet.';
    if (file_exists(LOG_FILE)) {
        $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $log_tail = implode("\n", array_slice($lines, -150));
    }

    $state = read_queue_state();
    $tasks = $state['tasks'] ?? [];

    // Calculate live status stats
    $stats = [
        'pending'     => 0,
        'downloading' => 0,
        'completed'   => 0,
        'skipped'     => 0,
        'failed'      => 0,
        'total_speed' => 0.0,
    ];

    foreach ($tasks as $t) {
        $status = $t['status'] ?? 'pending';
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
        if ($status === 'downloading') {
            $stats['total_speed'] += (float)($t['download_speed'] ?? 0.0);
        }
    }

    // Determine lock active slot counts
    $active_workers = 0;
    $concurrency = (int)($config['concurrency_limit'] ?? 3);
    $active_slots = [];
    for ($i = 1; $i <= $concurrency; $i++) {
        $lockFile = __DIR__ . '/worker_' . $i . '.lock';
        if (file_exists($lockFile)) {
            $fp = fopen($lockFile, 'r');
            if ($fp) {
                if (!flock($fp, LOCK_SH | LOCK_NB)) {
                    $active_workers++;
                    $pid = trim(fgets($fp)) ?: 'Active';
                    $active_slots[] = "Slot {$i} (PID {$pid})";
                }
                fclose($fp);
            }
        }
    }

    echo json_encode([
        'running'        => ($active_workers > 0),
        'active_workers' => $active_workers,
        'active_slots'   => $active_slots,
        'stop_flag'      => file_exists(STOP_FLAG),
        'stats'          => $stats,
        'log_tail'       => $log_tail,
        'tasks'          => $tasks,
        'config'         => $config,
    ]);
    exit;
}

// 2. GET File Manager List
if (isset($_GET['ajax']) && $_GET['ajax'] === 'files') {
    header('Content-Type: application/json');
    $outputDir = $config['output_dir'] ?: __DIR__ . '/downloads';

    $files = [];
    if (is_dir($outputDir)) {
        $items = scandir($outputDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $fullPath = $outputDir . DIRECTORY_SEPARATOR . $item;
            if (is_file($fullPath)) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $fullPath);
                finfo_close($finfo);

                $files[] = [
                    'name' => $item,
                    'size' => filesize($fullPath),
                    'mime' => $mime,
                    'time' => filemtime($fullPath),
                ];
            }
        }
    }
    // Sort files by modified time desc
    usort($files, function($a, $b) {
        return $b['time'] <=> $a['time'];
    });

    echo json_encode($files);
    exit;
}

// 3. GET Browse Folders & Files (for config picker)
if (isset($_GET['browse'])) {
    header('Content-Type: application/json');
    $type = $_GET['type'] ?? 'file'; // 'file' or 'dir'
    $path = realpath($_GET['path'] ?? __DIR__);
    $root = realpath(__DIR__);

    // Safety constraint
    if (!$path || strpos($path, $root) !== 0) {
        $path = $root;
    }

    $items = [
        'current' => $path,
        'parent'  => (dirname($path) !== $path && strpos(dirname($path), $root) === 0) ? dirname($path) : null,
        'dirs'    => [],
        'files'   => [],
    ];

    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($full)) {
            $items['dirs'][] = $entry;
        } elseif ($type === 'file' && is_file($full)) {
            $items['files'][] = $entry;
        }
    }

    echo json_encode($items);
    exit;
}

// 4. Download File from File Manager
if (isset($_GET['download_file'])) {
    $filename = basename($_GET['download_file']);
    $outputDir = $config['output_dir'] ?: __DIR__ . '/downloads';
    $filePath = $outputDir . DIRECTORY_SEPARATOR . $filename;
    if (file_exists($filePath) && is_file($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
    http_response_code(404);
    echo "File not found.";
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST ACTIONS
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $action = $input['action'] ?? '';

    // Action: Save Config
    if ($action === 'save_config') {
        $config['input_file']       = trim($input['input_file'] ?? '');
        $config['output_dir']       = trim($input['output_dir'] ?? '');
        $config['concurrency_limit']= max(1, (int)($input['concurrency_limit'] ?? 3));
        $config['delay']            = max(0, (int)($input['delay'] ?? 1));
        $config['skip_existing']    = isset($input['skip_existing']) ? (bool)$input['skip_existing'] : false;
        $config['collision_suffix'] = isset($input['collision_suffix']) ? (bool)$input['collision_suffix'] : false;
        $config['naming_mode']      = $input['naming_mode'] ?? 'tail';
        $config['max_retries']      = max(0, (int)($input['max_retries'] ?? 3));
        $config['user_agent']       = trim($input['user_agent'] ?? 'DownloadQueueManager/1.0');
        $config['random_ua']        = isset($input['random_ua']) ? (bool)$input['random_ua'] : false;

        // Advanced settings
        $config['headers']          = trim($input['headers'] ?? '');
        $config['cookies']          = trim($input['cookies'] ?? '');
        $config['referer']          = trim($input['referer'] ?? '');
        $config['proxy']            = trim($input['proxy'] ?? '');
        $config['speed_limit']      = max(0, (int)($input['speed_limit'] ?? 0));
        $config['post_processing']  = trim($input['post_processing'] ?? '');

        save_config($config);
        echo json_encode(['success' => true, 'message' => 'Configuration saved successfully!']);
        exit;
    }

    // Action: Add URLs to Queue
    if ($action === 'add_urls') {
        $raw_urls = $input['urls'] ?? '';
        $lines = explode("\n", str_replace("\r", "", $raw_urls));
        $added = 0;

        update_queue_state(function(&$state) use ($lines, &$added) {
            foreach ($lines as $line) {
                $url = trim($line);
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    // Check if URL is already in queue
                    $exists = false;
                    foreach ($state['tasks'] as $t) {
                        if ($t['url'] === $url) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $state['tasks'][] = [
                            'id'             => uniqid('task_'),
                            'url'            => $url,
                            'status'         => 'pending',
                            'progress'       => 0,
                            'size'           => 0,
                            'download_speed' => 0.0,
                            'eta'            => 0,
                            'retry_count'    => 0,
                            'error_message'  => '',
                            'added_at'       => time(),
                            'local_path'     => '',
                        ];
                        $added++;
                    }
                }
            }
        });

        echo json_encode(['success' => true, 'message' => "Added {$added} valid URLs to the queue."]);
        exit;
    }

    // Action: Delete Specific Task from Queue
    if ($action === 'delete_task') {
        $taskId = $input['task_id'] ?? '';
        $deleted = false;

        update_queue_state(function(&$state) use ($taskId, &$deleted) {
            foreach ($state['tasks'] as $index => $t) {
                if ($t['id'] === $taskId) {
                    unset($state['tasks'][$index]);
                    $state['tasks'] = array_values($state['tasks']);
                    $deleted = true;
                    break;
                }
            }
        });

        echo json_encode(['success' => $deleted, 'message' => $deleted ? 'Task deleted from queue.' : 'Task not found.']);
        exit;
    }

    // Action: Prioritize/Move Task to Top
    if ($action === 'prioritize_task') {
        $taskId = $input['task_id'] ?? '';
        $prioritized = false;

        update_queue_state(function(&$state) use ($taskId, &$prioritized) {
            $matched = null;
            foreach ($state['tasks'] as $index => $t) {
                if ($t['id'] === $taskId) {
                    $matched = $t;
                    unset($state['tasks'][$index]);
                    $state['tasks'] = array_values($state['tasks']);
                    break;
                }
            }
            if ($matched) {
                // Prepend to top
                array_unshift($state['tasks'], $matched);
                $prioritized = true;
            }
        });

        echo json_encode(['success' => $prioritized, 'message' => $prioritized ? 'Task prioritized to top.' : 'Task not found.']);
        exit;
    }

    // Action: Start / Trigger Background Workers
    if ($action === 'start') {
        if (file_exists(STOP_FLAG)) {
            unlink(STOP_FLAG);
        }

        // Spawn as many parallel worker requests as concurrency limit allows
        $concurrency = (int)($config['concurrency_limit'] ?? 3);
        $triggered_count = 0;

        for ($i = 0; $i < $concurrency; $i++) {
            $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                 . '://' . $_SERVER['HTTP_HOST']
                 . dirname($_SERVER['REQUEST_URI']) . '/processor.php?action=start';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 2,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
            $triggered_count++;
        }

        log_msg('info', "Triggered {$triggered_count} parallel worker spawning attempts.");
        echo json_encode(['success' => true, 'message' => "Parallel downloading engine started successfully."]);
        exit;
    }

    // Action: Stop Queue Safely
    if ($action === 'stop') {
        touch(STOP_FLAG);
        log_msg('info', "Stop signal triggered by user.");
        echo json_encode(['success' => true, 'message' => "Stop flag set. Current downloads will finish and worker threads will exit."]);
        exit;
    }

    // Action: Clear Stop Signal
    if ($action === 'clear_stop') {
        if (file_exists(STOP_FLAG)) {
            unlink(STOP_FLAG);
        }
        echo json_encode(['success' => true, 'message' => "Stop signal cleared successfully."]);
        exit;
    }

    // Action: Reset Queue States
    if ($action === 'reset_queue') {
        // Reset all downloading/failed/skipped tasks to pending, speed, retry count to 0
        update_queue_state(function(&$state) {
            foreach ($state['tasks'] as &$t) {
                $t['status'] = 'pending';
                $t['progress'] = 0;
                $t['download_speed'] = 0.0;
                $t['eta'] = 0;
                $t['retry_count'] = 0;
                $t['error_message'] = '';
            }
        });

        echo json_encode(['success' => true, 'message' => "All tasks have been reset to Pending status."]);
        exit;
    }

    // Action: Clear / Purge Queue
    if ($action === 'clear_queue') {
        update_queue_state(function(&$state) {
            $state['tasks'] = [];
        });
        echo json_encode(['success' => true, 'message' => "Queue cleared."]);
        exit;
    }

    // Action: File Manager Delete
    if ($action === 'delete_file') {
        $filename = basename($input['filename'] ?? '');
        $outputDir = $config['output_dir'] ?: __DIR__ . '/downloads';
        $filePath = $outputDir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($filePath) && is_file($filePath)) {
            unlink($filePath);
            echo json_encode(['success' => true, 'message' => "File deleted successfully."]);
            exit;
        }
        echo json_encode(['success' => false, 'message' => "File not found."]);
        exit;
    }

    // Action: Clear Log File
    if ($action === 'clear_log') {
        file_put_contents(LOG_FILE, '');
        echo json_encode(['success' => true, 'message' => "Logs cleared."]);
        exit;
    }

    // Default response for unhandled POST
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => "Unknown action."]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-950">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StreamLock — Parallel Download Manager</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome Icons for modern aesthetic -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Custom scrollbar for dark terminal styling */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #020617;
        }
        ::-webkit-scrollbar-thumb {
            background: #1e293b;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #334155;
        }
    </style>
</head>
<body class="h-full text-slate-100 font-sans antialiased flex flex-col">

<!-- ─── Browse Dialog Modal ──────────────────────────────────────────────── -->
<div id="browser-overlay" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center hidden">
    <div class="bg-slate-900 border border-slate-800 rounded-xl w-full max-w-lg shadow-2xl overflow-hidden flex flex-col max-h-[75vh]">
        <div class="px-6 py-4 border-b border-slate-800 flex justify-between items-center bg-slate-950/50">
            <h3 class="font-semibold text-lg text-white flex items-center gap-2">
                <i class="fa-solid fa-folder-open text-blue-400"></i>
                <span id="browser-title">Browse Directory</span>
            </h3>
            <button class="text-slate-400 hover:text-white transition-colors" onclick="closeBrowser()">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        <div id="browser-path" class="px-6 py-2 bg-slate-950/30 text-xs font-mono text-slate-400 border-b border-slate-800 break-all">/</div>
        <div id="browser-list" class="flex-1 overflow-y-auto p-4 space-y-1"></div>
        <div class="px-6 py-4 border-t border-slate-800 flex justify-between items-center bg-slate-950/50">
            <span id="browser-selected" class="text-xs font-mono text-blue-400 truncate max-w-[280px]">Nothing selected</span>
            <button class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors flex items-center gap-2" onclick="confirmBrowser()">
                Select <i class="fa-solid fa-check"></i>
            </button>
        </div>
    </div>
</div>

<!-- ─── Toast Notifications ───────────────────────────────────────────────── -->
<div id="toast-container" class="fixed bottom-5 right-5 z-50 flex flex-col gap-3"></div>

<div class="flex flex-1 overflow-hidden">
    <!-- ─── Sidebar Navigation ──────────────────────────────────────────────── -->
    <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col justify-between">
        <div>
            <!-- Brand -->
            <div class="p-6 border-b border-slate-800 flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-tr from-blue-600 to-indigo-500 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/10">
                    <i class="fa-solid fa-bolt text-lg text-white animate-pulse"></i>
                </div>
                <div>
                    <h1 class="font-bold text-white tracking-wide">StreamLock</h1>
                    <span class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold">Parallel Engine</span>
                </div>
            </div>

            <!-- Nav Links -->
            <nav class="p-4 space-y-1">
                <button onclick="switchTab('dashboard')" class="tab-btn active w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-all text-slate-400 hover:text-white hover:bg-slate-800/50">
                    <i class="fa-solid fa-chart-line text-lg w-5"></i>
                    <span>Dashboard</span>
                </button>
                <button onclick="switchTab('queue')" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-all text-slate-400 hover:text-white hover:bg-slate-800/50">
                    <i class="fa-solid fa-list-check text-lg w-5"></i>
                    <span>Queue Manager</span>
                </button>
                <button onclick="switchTab('files')" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-all text-slate-400 hover:text-white hover:bg-slate-800/50" onclick="loadFileBrowser()">
                    <i class="fa-solid fa-box-open text-lg w-5"></i>
                    <span>File Manager</span>
                </button>
                <button onclick="switchTab('settings')" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-all text-slate-400 hover:text-white hover:bg-slate-800/50">
                    <i class="fa-solid fa-sliders text-lg w-5"></i>
                    <span>Settings</span>
                </button>
                <button onclick="switchTab('logs')" class="tab-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-all text-slate-400 hover:text-white hover:bg-slate-800/50">
                    <i class="fa-solid fa-terminal text-lg w-5"></i>
                    <span>Live Logs</span>
                </button>
            </nav>
        </div>

        <!-- Engine Status Card -->
        <div class="p-4 border-t border-slate-800 bg-slate-950/30">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs text-slate-400 font-medium">Engine Status</span>
                <span id="status-badge" class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-800 text-slate-400 flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> IDLE
                </span>
            </div>
            <div class="text-[11px] text-slate-500 font-mono space-y-1">
                <div class="flex justify-between">
                    <span>Stop Flag:</span>
                    <span id="stop-flag-status" class="font-semibold">NONE</span>
                </div>
                <div class="flex justify-between">
                    <span>Active Slots:</span>
                    <span id="active-slots-count" class="font-semibold">0</span>
                </div>
            </div>
        </div>
    </aside>

    <!-- ─── Main Content Window ────────────────────────────────────────────── -->
    <main class="flex-1 flex flex-col bg-slate-950 overflow-y-auto">
        <!-- Top bar / Live Header Info -->
        <header class="h-16 border-b border-slate-800 bg-slate-900/40 backdrop-blur-md px-8 flex items-center justify-between">
            <div class="flex items-center gap-3 text-sm text-slate-400 font-medium">
                <i class="fa-regular fa-clock"></i>
                <span id="header-time">-</span>
                <span class="text-slate-600">|</span>
                <span id="active-slots-desc" class="text-xs text-slate-500">Checking worker locking slots...</span>
            </div>
            <!-- Global action quick-btns -->
            <div class="flex items-center gap-2">
                <button onclick="triggerEngineAction('start')" class="bg-emerald-600 hover:bg-emerald-500 text-white text-xs px-3.5 py-1.5 rounded-lg font-semibold transition-colors flex items-center gap-1.5 shadow-lg shadow-emerald-600/10">
                    <i class="fa-solid fa-play"></i> Start Engine
                </button>
                <button onclick="triggerEngineAction('stop')" class="bg-amber-600 hover:bg-amber-500 text-white text-xs px-3.5 py-1.5 rounded-lg font-semibold transition-colors flex items-center gap-1.5 shadow-lg shadow-amber-600/10">
                    <i class="fa-solid fa-pause"></i> Pause safely
                </button>
            </div>
        </header>

        <!-- Dynamic Page Tabs container -->
        <div class="p-8 flex-1 max-w-7xl w-full mx-auto space-y-8">

            <!-- ==================== TAB: DASHBOARD ==================== -->
            <section id="tab-dashboard" class="tab-pane space-y-8">
                <!-- Stat Cards Grid -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-5">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex items-center gap-4">
                        <div class="w-12 h-12 bg-blue-500/10 text-blue-400 rounded-lg flex items-center justify-center text-xl">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </div>
                        <div>
                            <span class="block text-xs text-slate-400 font-semibold uppercase tracking-wider">Pending</span>
                            <span id="stat-pending" class="text-2xl font-bold text-white">-</span>
                        </div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex items-center gap-4">
                        <div class="w-12 h-12 bg-indigo-500/10 text-indigo-400 rounded-lg flex items-center justify-center text-xl">
                            <i class="fa-solid fa-circle-notch animate-spin"></i>
                        </div>
                        <div>
                            <span class="block text-xs text-slate-400 font-semibold uppercase tracking-wider">Active</span>
                            <span id="stat-downloading" class="text-2xl font-bold text-white">-</span>
                        </div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex items-center gap-4">
                        <div class="w-12 h-12 bg-emerald-500/10 text-emerald-400 rounded-lg flex items-center justify-center text-xl">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <span class="block text-xs text-slate-400 font-semibold uppercase tracking-wider">Completed</span>
                            <span id="stat-completed" class="text-2xl font-bold text-white">-</span>
                        </div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex items-center gap-4">
                        <div class="w-12 h-12 bg-amber-500/10 text-amber-400 rounded-lg flex items-center justify-center text-xl">
                            <i class="fa-solid fa-forward-step"></i>
                        </div>
                        <div>
                            <span class="block text-xs text-slate-400 font-semibold uppercase tracking-wider">Skipped</span>
                            <span id="stat-skipped" class="text-2xl font-bold text-white">-</span>
                        </div>
                    </div>
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 flex items-center gap-4">
                        <div class="w-12 h-12 bg-rose-500/10 text-rose-400 rounded-lg flex items-center justify-center text-xl">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <div>
                            <span class="block text-xs text-slate-400 font-semibold uppercase tracking-wider">Failed</span>
                            <span id="stat-failed" class="text-2xl font-bold text-white">-</span>
                        </div>
                    </div>
                </div>

                <!-- Live Download Speed Overview & Quick Action Buttons -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 flex flex-col md:flex-row items-center justify-between gap-6">
                    <div class="flex items-center gap-4">
                        <div class="p-4 bg-blue-600/10 text-blue-400 rounded-full text-2xl">
                            <i class="fa-solid fa-gauge-high"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-white text-lg">Total Download Bandwidth</h3>
                            <p class="text-xs text-slate-400">Total active multi-threaded aggregate transfer rate</p>
                        </div>
                    </div>
                    <div class="flex items-baseline gap-1 text-right">
                        <span id="stat-total-speed" class="text-4xl font-extrabold text-blue-400">0.0</span>
                        <span class="text-sm font-semibold text-slate-400">KB/s</span>
                    </div>
                </div>

                <!-- Active Downloads Grid / Live Progress Trackers -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-spinner animate-spin text-blue-400 text-sm"></i>
                            Active Transfer Streams
                        </h3>
                        <div class="flex gap-2">
                            <button onclick="triggerEngineAction('reset_queue')" class="bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs px-3.5 py-1.5 rounded-lg font-semibold transition-colors flex items-center gap-1.5 border border-slate-700">
                                <i class="fa-solid fa-rotate-left"></i> Reset Tasks
                            </button>
                            <button onclick="triggerEngineAction('clear_queue')" class="bg-rose-950/40 hover:bg-rose-950/80 text-rose-300 text-xs px-3.5 py-1.5 rounded-lg font-semibold transition-colors flex items-center gap-1.5 border border-rose-900/50">
                                <i class="fa-solid fa-trash-can"></i> Clear Queue
                            </button>
                        </div>
                    </div>

                    <div id="active-downloads-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Dynamic downloads populate here -->
                    </div>
                </div>
            </section>

            <!-- ==================== TAB: QUEUE MANAGER ==================== -->
            <section id="tab-queue" class="tab-pane space-y-6 hidden">
                <!-- Add URLs Box -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-4">
                    <div>
                        <h3 class="font-bold text-white text-lg flex items-center gap-2">
                            <i class="fa-solid fa-plus text-blue-400"></i> Append Target URLs to Queue
                        </h3>
                        <p class="text-xs text-slate-400">Add URLs (one per line). Duplicates will be safely ignored automatically.</p>
                    </div>
                    <textarea id="queue-paste-input" rows="5" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-4 text-slate-100 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-blue-600 focus:border-transparent" placeholder="https://example.com/file1.zip&#10;https://example.com/largefile.mp4"></textarea>
                    <div class="flex justify-end">
                        <button onclick="addUrlsToQueue()" class="bg-blue-600 hover:bg-blue-500 text-white text-sm px-5 py-2.5 rounded-lg font-semibold transition-colors flex items-center gap-2 shadow-lg shadow-blue-600/15">
                            <i class="fa-solid fa-circle-plus"></i> Import into Queue
                        </button>
                    </div>
                </div>

                <!-- Active Queue Table & Pagination/Filters -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <!-- Filters Panel -->
                    <div class="p-6 border-b border-slate-800 flex flex-col md:flex-row items-center justify-between gap-4 bg-slate-950/20">
                        <div class="flex items-center gap-3 w-full md:w-auto">
                            <div class="relative flex-1 md:w-64">
                                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500"></i>
                                <input type="text" id="queue-search" oninput="renderQueueTable()" class="w-full bg-slate-950 border border-slate-800 rounded-lg pl-10 pr-4 py-2 text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="Search URLs...">
                            </div>
                            <select id="queue-filter-status" onchange="renderQueueTable()" class="bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-600">
                                <option value="all">All Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="downloading">Downloading</option>
                                <option value="completed">Completed</option>
                                <option value="skipped">Skipped</option>
                                <option value="failed">Failed</option>
                            </select>
                        </div>
                        <span id="queue-pagination-info" class="text-xs text-slate-400 font-medium">Showing 0 of 0 URLs</span>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-slate-800 text-xs font-semibold text-slate-400 uppercase tracking-wider bg-slate-950/50">
                                    <th class="py-4 px-6 w-[5%]">#</th>
                                    <th class="py-4 px-6 w-[45%]">Target URL</th>
                                    <th class="py-4 px-6 w-[15%]">Status</th>
                                    <th class="py-4 px-6 w-[15%]">Size</th>
                                    <th class="py-4 px-6 w-[20%] text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="queue-table-body" class="divide-y divide-slate-800/60 text-sm">
                                <!-- Dynamic queue rows -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Controls -->
                    <div class="p-6 border-t border-slate-800 flex justify-between items-center bg-slate-950/10">
                        <button id="queue-prev-btn" onclick="queuePrevPage()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs font-semibold transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                            <i class="fa-solid fa-chevron-left mr-1"></i> Previous
                        </button>
                        <button id="queue-next-btn" onclick="queueNextPage()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs font-semibold transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                            Next <i class="fa-solid fa-chevron-right ml-1"></i>
                        </button>
                    </div>
                </div>
            </section>

            <!-- ==================== TAB: FILE MANAGER ==================== -->
            <section id="tab-files" class="tab-pane space-y-6 hidden">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-white text-lg">Downloaded Outputs Directory</h3>
                        <p class="text-xs text-slate-400">Verify and manage compiled download artifacts located on local storage.</p>
                    </div>
                    <button onclick="loadFileBrowser()" class="bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs px-3.5 py-2 rounded-lg font-semibold border border-slate-700 flex items-center gap-2">
                        <i class="fa-solid fa-arrows-rotate"></i> Refresh Files
                    </button>
                </div>

                <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-slate-800 text-xs font-semibold text-slate-400 uppercase tracking-wider bg-slate-950/50">
                                    <th class="py-4 px-6">Filename</th>
                                    <th class="py-4 px-6">Mime Type</th>
                                    <th class="py-4 px-6">Size</th>
                                    <th class="py-4 px-6">Downloaded At</th>
                                    <th class="py-4 px-6 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="file-table-body" class="divide-y divide-slate-800/60 text-sm">
                                <!-- Dynamic files populate here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ==================== TAB: SETTINGS ==================== -->
            <section id="tab-settings" class="tab-pane hidden">
                <form id="settings-form" onsubmit="saveSettings(event)" class="space-y-8">
                    <!-- Core settings panel -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-6">
                        <h3 class="font-bold text-white text-lg border-b border-slate-800 pb-3 flex items-center gap-2">
                            <i class="fa-solid fa-gear text-blue-400"></i> Core Downloading Properties
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Queue Input Source (.txt / JSON)</label>
                                <div class="flex gap-2">
                                    <input type="text" id="config-input-file" class="flex-1 bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="e.g. queue.txt">
                                    <button type="button" onclick="openDirPicker('file', 'config-input-file')" class="bg-slate-800 hover:bg-slate-700 text-slate-300 px-4 rounded-lg text-sm border border-slate-700 transition-colors">
                                        Browse
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Output Target Folder</label>
                                <div class="flex gap-2">
                                    <input type="text" id="config-output-dir" class="flex-1 bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="e.g. downloads">
                                    <button type="button" onclick="openDirPicker('dir', 'config-output-dir')" class="bg-slate-800 hover:bg-slate-700 text-slate-300 px-4 rounded-lg text-sm border border-slate-700 transition-colors">
                                        Browse
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Naming Strategy</label>
                                <select id="config-naming-mode" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-600">
                                    <option value="tail">Tail of URL</option>
                                    <option value="mirror">Mirror Directories</option>
                                    <option value="incremental">Incremental Indexing</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Parallel Workers</label>
                                <input type="number" id="config-concurrency" min="1" max="10" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-600">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Delay per Request (s)</label>
                                <input type="number" id="config-delay" min="0" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-600">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Max Retry Limits</label>
                                <input type="number" id="config-retries" min="0" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-600">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" id="config-skip-existing" class="w-4 h-4 bg-slate-950 border-slate-800 rounded focus:ring-blue-600 text-blue-600">
                                <div>
                                    <span class="block text-sm font-semibold text-slate-200">Skip Existing Files</span>
                                    <span class="block text-xs text-slate-500">Do not re-download files already present in destination.</span>
                                </div>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" id="config-collision-suffix" class="w-4 h-4 bg-slate-950 border-slate-800 rounded focus:ring-blue-600 text-blue-600">
                                <div>
                                    <span class="block text-sm font-semibold text-slate-200">Append Suffix on Collision</span>
                                    <span class="block text-xs text-slate-500">Append numeric index suffixes if files share names.</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Custom Request Identity panel -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-6">
                        <h3 class="font-bold text-white text-lg border-b border-slate-800 pb-3 flex items-center gap-2">
                            <i class="fa-solid fa-fingerprint text-indigo-400"></i> Identity &amp; Network Proxies
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Custom User-Agent Profile</label>
                                <input type="text" id="config-user-agent" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-600">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">HTTP Network Proxy</label>
                                <input type="text" id="config-proxy" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="e.g. 127.0.0.1:8080">
                            </div>
                        </div>

                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" id="config-random-ua" class="w-4 h-4 bg-slate-950 border-slate-800 rounded focus:ring-blue-600 text-blue-600">
                            <div>
                                <span class="block text-sm font-semibold text-slate-200">Randomize Agent String Per Worker Request</span>
                                <span class="block text-xs text-slate-500">Forces random identity rotators from high-performance UA presets.</span>
                            </div>
                        </label>
                    </div>

                    <!-- Speed & Advanced Parameters panel -->
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-6">
                        <h3 class="font-bold text-white text-lg border-b border-slate-800 pb-3 flex items-center gap-2">
                            <i class="fa-solid fa-network-wired text-purple-400"></i> Speed Throttle &amp; Advanced Request Headers
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Speed Limit (KB/s per Worker)</label>
                                <input type="number" id="config-speed-limit" min="0" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="e.g. 500 for 500KB/s (0 = unlimited)">
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">HTTP Referer Header URL</label>
                                <input type="text" id="config-referer" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="https://referrer.domain.com">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Custom Request Headers (One per line)</label>
                                <textarea id="config-headers" rows="4" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-slate-100 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="Authorization: Bearer token123&#10;X-Custom-Header: True"></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Custom Request Cookies (Raw Header String)</label>
                                <textarea id="config-cookies" rows="4" class="w-full bg-slate-950 border border-slate-800 rounded-xl p-3 text-slate-100 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="sessionid=abc123xyz; theme=dark"></textarea>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Success Post-Processing Hook</label>
                            <input type="text" id="config-post-processing" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-sm text-slate-100 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-600" placeholder="e.g. php /path/to/script.php {file}">
                            <span class="block text-[11px] text-slate-500 mt-1">Accepts `{file}` placeholder which gets auto-resolved as the completed downloaded file's local path.</span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end pt-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-bold text-sm px-6 py-3 rounded-lg transition-colors shadow-lg shadow-blue-500/10">
                            Save Settings Config
                        </button>
                    </div>
                </form>
            </section>

            <!-- ==================== TAB: LOGS ==================== -->
            <section id="tab-logs" class="tab-pane space-y-4 hidden">
                <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                    <div>
                        <h3 class="font-bold text-white text-lg">System Performance Logging Console</h3>
                        <p class="text-xs text-slate-400">Aggregated tracking, queue, worker, and file streams logs in real-time.</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <select id="log-filter" onchange="applyLogFilter()" class="bg-slate-900 border border-slate-800 rounded-lg px-3 py-2 text-xs text-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-600">
                            <option value="all">All Logs</option>
                            <option value="errors">Errors Only</option>
                            <option value="saved">Downloads (SAVED)</option>
                            <option value="info">System Info</option>
                        </select>
                        <button onclick="copyLogConsole()" class="bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs px-3.5 py-2 rounded-lg font-semibold border border-slate-700 flex items-center gap-1.5 transition-colors">
                            <i class="fa-solid fa-copy"></i> Copy
                        </button>
                        <button onclick="triggerEngineAction('clear_log')" class="bg-rose-950/40 hover:bg-rose-950/80 text-rose-300 text-xs px-3.5 py-2 rounded-lg font-semibold border border-rose-900/50 flex items-center gap-1.5 transition-colors">
                            <i class="fa-solid fa-trash-can"></i> Clear
                        </button>
                    </div>
                </div>

                <!-- Log Area Terminal -->
                <div class="relative bg-slate-950 border border-slate-800 rounded-xl overflow-hidden shadow-2xl">
                    <div class="absolute top-3 right-4 flex items-center gap-2">
                        <span class="w-2.5 h-2.5 bg-emerald-500 rounded-full animate-ping"></span>
                        <span class="text-[10px] text-emerald-500 font-bold uppercase tracking-wider">Live Polling</span>
                    </div>
                    <div id="log-area" class="h-[450px] overflow-y-auto p-5 font-mono text-xs leading-relaxed text-slate-300 space-y-1">
                        <!-- Raw log output -->
                    </div>
                </div>
                <div class="flex items-center justify-between text-xs text-slate-500">
                    <span id="log-stat">Updated just now</span>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="autoscroll" checked class="w-4 h-4 bg-slate-950 border-slate-800 rounded text-blue-600 focus:ring-blue-600"> Auto-scroll Console
                    </label>
                </div>
            </section>

        </div>
    </main>
</div>

<!-- ─── Client Scripts ────────────────────────────────────────────────────── -->
<script>
    // App States
    let activeTab = 'dashboard';
    let currentLogs = '';
    let queueTasks = [];
    let config = {};

    // Pagination state for Queue Manager
    let queueCurrentPage = 1;
    const queuePageSize = 10;

    // Pickers states
    let targetPickerId = '';
    let browseMode = 'dir';

    // XSS Sanitizer for rendering task params in templates safely
    function cleanHTML(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Toast Alert notification helper
    function showToast(message, type = 'info') {
        const bgColors = {
            success: 'bg-emerald-900/90 border-emerald-700 text-emerald-200',
            error: 'bg-rose-900/90 border-rose-700 text-rose-200',
            info: 'bg-blue-900/90 border-blue-700 text-blue-200',
            warning: 'bg-amber-900/90 border-amber-700 text-amber-200',
        };
        const icons = {
            success: '<i class="fa-solid fa-circle-check text-emerald-400"></i>',
            error: '<i class="fa-solid fa-triangle-exclamation text-rose-400"></i>',
            info: '<i class="fa-solid fa-circle-info text-blue-400"></i>',
            warning: '<i class="fa-solid fa-circle-exclamation text-amber-400"></i>',
        };

        const toast = document.createElement('div');
        toast.className = `flex items-center gap-3 px-4 py-3 rounded-xl border shadow-xl backdrop-blur-md transition-all duration-300 transform translate-y-2 opacity-0 ${bgColors[type] || bgColors.info}`;
        toast.innerHTML = `${icons[type] || icons.info} <span class="text-xs font-semibold">${cleanHTML(message)}</span>`;

        const container = document.getElementById('toast-container');
        container.appendChild(toast);

        // Animate inside
        setTimeout(() => {
            toast.classList.remove('translate-y-2', 'opacity-0');
        }, 10);

        // Remove after timeout
        setTimeout(() => {
            toast.classList.add('translate-y-2', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    // Switch Tab Panels
    function switchTab(tabId) {
        activeTab = tabId;
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.add('hidden'));
        document.getElementById(`tab-${tabId}`).classList.remove('hidden');

        // Styles on sidebar
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('active', 'text-white', 'bg-slate-800');
            b.classList.add('text-slate-400');
        });
        const activeBtn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.getAttribute('onclick').includes(tabId));
        if (activeBtn) {
            activeBtn.classList.add('active', 'text-white', 'bg-slate-800');
            activeBtn.classList.remove('text-slate-400');
        }

        if (tabId === 'files') {
            loadFileBrowser();
        }
    }

    // Header Clock
    function updateClock() {
        const d = new Date();
        document.getElementById('header-time').innerText = d.toLocaleTimeString();
    }
    setInterval(updateClock, 1000);
    updateClock();

    // ─────────────────────────────────────────────────────────────────────────
    // Polling System AJAX Engine Stats
    // ─────────────────────────────────────────────────────────────────────────
    function poll() {
        fetch('?ajax=stats')
            .then(res => res.json())
            .then(data => {
                queueTasks = data.tasks || [];
                config = data.config || {};
                currentLogs = data.log_tail || '';

                // Render Logs Console
                applyLogFilter();

                // Engine Badge
                const badge = document.getElementById('status-badge');
                if (data.stop_flag && data.running) {
                    badge.className = "px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-900/50 text-amber-400 flex items-center gap-1.5 border border-amber-700";
                    badge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-ping"></span> STOPPING`;
                } else if (data.running) {
                    badge.className = "px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-900/50 text-emerald-400 flex items-center gap-1.5 border border-emerald-700";
                    badge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-ping"></span> RUNNING`;
                } else {
                    badge.className = "px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-800 text-slate-400 flex items-center gap-1.5 border border-slate-700";
                    badge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> IDLE`;
                }

                // Header Workers lock slot info
                document.getElementById('stop-flag-status').innerText = data.stop_flag ? 'STOPPING...' : 'NONE';
                document.getElementById('active-slots-count').innerText = data.active_workers;
                if (data.active_slots && data.active_slots.length > 0) {
                    document.getElementById('active-slots-desc').innerText = `Locked slots: ${cleanHTML(data.active_slots.join(', '))}`;
                } else {
                    document.getElementById('active-slots-desc').innerText = "No active locked slots";
                }

                // Stats Values
                document.getElementById('stat-pending').innerText = data.stats.pending;
                document.getElementById('stat-downloading').innerText = data.stats.downloading;
                document.getElementById('stat-completed').innerText = data.stats.completed;
                document.getElementById('stat-skipped').innerText = data.stats.skipped;
                document.getElementById('stat-failed').innerText = data.stats.failed;
                document.getElementById('stat-total-speed').innerText = parseFloat(data.stats.total_speed).toFixed(1);

                // Populate Form Inputs first time loaded
                if (activeTab === 'settings' && !document.getElementById('config-input-file').value) {
                    document.getElementById('config-input-file').value = config.input_file || '';
                    document.getElementById('config-output-dir').value = config.output_dir || '';
                    document.getElementById('config-naming-mode').value = config.naming_mode || 'tail';
                    document.getElementById('config-concurrency').value = config.concurrency_limit || 3;
                    document.getElementById('config-delay').value = config.delay || 1;
                    document.getElementById('config-retries').value = config.max_retries || 3;
                    document.getElementById('config-user-agent').value = config.user_agent || '';
                    document.getElementById('config-proxy').value = config.proxy || '';
                    document.getElementById('config-speed-limit').value = config.speed_limit || 0;
                    document.getElementById('config-referer').value = config.referer || '';
                    document.getElementById('config-headers').value = config.headers || '';
                    document.getElementById('config-cookies').value = config.cookies || '';
                    document.getElementById('config-post-processing').value = config.post_processing || '';
                    document.getElementById('config-skip-existing').checked = !!config.skip_existing;
                    document.getElementById('config-collision-suffix').checked = !!config.collision_suffix;
                    document.getElementById('config-random-ua').checked = !!config.random_ua;
                }

                // Render Dashboard Downloads
                renderDashboardDownloads();

                // Render Queue Manager Table
                renderQueueTable();
            })
            .catch(() => {});
    }

    // Render Dashboard active downloading cards
    function renderDashboardDownloads() {
        const container = document.getElementById('active-downloads-container');
        const activeTasks = queueTasks.filter(t => t.status === 'downloading');

        if (activeTasks.length === 0) {
            container.innerHTML = `
                <div class="col-span-2 bg-slate-900/50 border border-slate-800/60 rounded-xl p-8 text-center text-slate-500">
                    <i class="fa-solid fa-circle-down text-3xl mb-2 text-slate-700 block animate-bounce"></i>
                    No active download streams right now
                </div>`;
            return;
        }

        let html = '';
        activeTasks.forEach(t => {
            const progress = parseFloat(t.progress || 0).toFixed(1);
            const speed = parseFloat(t.download_speed || 0).toFixed(1);
            const sizeMB = t.size ? (t.size / (1024 * 1024)).toFixed(2) : '0.00';
            const eta = t.eta ? formatETA(t.eta) : 'Estimating...';

            html += `
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="truncate flex-1">
                            <span class="block text-xs text-blue-400 font-bold truncate">${cleanHTML(t.url)}</span>
                            <span class="block text-[10px] text-slate-500 font-mono mt-0.5 truncate">${cleanHTML(t.local_path) || 'Writing to file stream'}</span>
                        </div>
                        <span class="px-2 py-0.5 bg-blue-900/40 border border-blue-800 text-[10px] font-bold text-blue-300 rounded uppercase">Downloading</span>
                    </div>

                    <div class="space-y-1.5">
                        <div class="flex justify-between text-xs font-semibold">
                            <span>Progress</span>
                            <span class="text-blue-400">${progress}%</span>
                        </div>
                        <div class="w-full bg-slate-950 rounded-full h-2 overflow-hidden border border-slate-800">
                            <div class="bg-gradient-to-r from-blue-600 to-indigo-500 h-full rounded-full transition-all duration-300" style="width: ${progress}%"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2 pt-1 border-t border-slate-800/60 text-center">
                        <div>
                            <span class="block text-[10px] text-slate-500 font-semibold uppercase">Speed</span>
                            <span class="block text-xs font-bold text-white">${speed} KB/s</span>
                        </div>
                        <div>
                            <span class="block text-[10px] text-slate-500 font-semibold uppercase">Total Size</span>
                            <span class="block text-xs font-bold text-white">${sizeMB} MB</span>
                        </div>
                        <div>
                            <span class="block text-[10px] text-slate-500 font-semibold uppercase">ETA</span>
                            <span class="block text-xs font-bold text-white">${eta}</span>
                        </div>
                    </div>
                </div>`;
        });
        container.innerHTML = html;
    }

    // Helper format seconds to ETA string
    function formatETA(sec) {
        if (sec === Infinity || isNaN(sec)) return 'Estimating...';
        if (sec < 60) return `${sec}s`;
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        if (m < 60) return `${m}m ${s}s`;
        const h = Math.floor(m / 60);
        const remM = m % 60;
        return `${h}h ${remM}m`;
    }

    // Trigger post action helpers
    function triggerEngineAction(action) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: action})
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                poll();
            } else {
                showToast(data.message || 'Action execution error.', 'error');
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tab: Queue Manager Table & Search
    // ─────────────────────────────────────────────────────────────────────────
    function renderQueueTable() {
        const searchVal = document.getElementById('queue-search').value.toLowerCase();
        const statusVal = document.getElementById('queue-filter-status').value;

        // Filter Tasks
        let filteredTasks = queueTasks;
        if (statusVal !== 'all') {
            filteredTasks = filteredTasks.filter(t => t.status === statusVal);
        }
        if (searchVal) {
            filteredTasks = filteredTasks.filter(t => t.url.toLowerCase().includes(searchVal));
        }

        // Pagination calculations
        const total = filteredTasks.length;
        const totalPages = Math.ceil(total / queuePageSize) || 1;
        if (queueCurrentPage > totalPages) {
            queueCurrentPage = totalPages;
        }

        document.getElementById('queue-pagination-info').innerText = `Showing ${filteredTasks.length === 0 ? 0 : (queueCurrentPage - 1) * queuePageSize + 1} to ${Math.min(queueCurrentPage * queuePageSize, total)} of ${total} URLs`;

        // Buttons active states
        document.getElementById('queue-prev-btn').disabled = (queueCurrentPage === 1);
        document.getElementById('queue-next-btn').disabled = (queueCurrentPage === totalPages);

        // Slice current page
        const start = (queueCurrentPage - 1) * queuePageSize;
        const pageTasks = filteredTasks.slice(start, start + queuePageSize);

        const tbody = document.getElementById('queue-table-body');
        if (pageTasks.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="py-8 px-6 text-center text-slate-500 font-medium">
                        No matches or queue tasks added
                    </td>
                </tr>`;
            return;
        }

        const badgeClasses = {
            pending: 'bg-slate-800 text-slate-400 border-slate-700',
            downloading: 'bg-blue-900/30 text-blue-400 border-blue-800',
            completed: 'bg-emerald-900/30 text-emerald-400 border-emerald-800',
            skipped: 'bg-amber-900/30 text-amber-400 border-amber-800',
            failed: 'bg-rose-900/30 text-rose-400 border-rose-800',
        };

        let html = '';
        pageTasks.forEach((t, i) => {
            const idx = start + i + 1;
            const sizeMB = t.size ? (t.size / (1024 * 1024)).toFixed(2) + ' MB' : 'Pending';
            const sizeStr = t.status === 'completed' || t.status === 'downloading' || t.status === 'skipped' ? sizeMB : '-';
            const badge = badgeClasses[t.status] || badgeClasses.pending;

            html += `
                <tr class="hover:bg-slate-900/30 transition-colors">
                    <td class="py-4 px-6 font-semibold text-slate-500">${idx}</td>
                    <td class="py-4 px-6">
                        <span class="block font-medium text-slate-200 truncate max-w-lg" title="${cleanHTML(t.url)}">${cleanHTML(t.url)}</span>
                        ${t.error_message ? `<span class="block text-[11px] text-rose-400 font-semibold mt-1"><i class="fa-solid fa-triangle-exclamation"></i> ${cleanHTML(t.error_message)}</span>` : ''}
                    </td>
                    <td class="py-4 px-6">
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase border ${badge}">${cleanHTML(t.status)}</span>
                    </td>
                    <td class="py-4 px-6 text-slate-300 font-mono text-xs">${sizeStr}</td>
                    <td class="py-4 px-6 text-right space-x-1.5">
                        <button onclick="prioritizeTask('${cleanHTML(t.id)}')" class="text-indigo-400 hover:text-indigo-300 bg-indigo-950/40 p-2 rounded border border-indigo-900/40 transition-colors" title="Prioritize to top">
                            <i class="fa-solid fa-arrow-up"></i>
                        </button>
                        <button onclick="deleteTask('${cleanHTML(t.id)}')" class="text-rose-400 hover:text-rose-300 bg-rose-950/40 p-2 rounded border border-rose-900/40 transition-colors" title="Delete Task">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </td>
                </tr>`;
        });
        tbody.innerHTML = html;
    }

    function queuePrevPage() { if (queueCurrentPage > 1) { queueCurrentPage--; renderQueueTable(); } }
    function queueNextPage() { queueCurrentPage++; renderQueueTable(); }

    // Task prioritizations & deletions
    function prioritizeTask(taskId) {
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'prioritize_task', task_id: taskId})
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                poll();
            }
        });
    }

    function deleteTask(taskId) {
        if (!confirm('Are you sure you want to remove this URL from the queue?')) return;
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'delete_task', task_id: taskId})
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                poll();
            }
        });
    }

    function addUrlsToQueue() {
        const urls = document.getElementById('queue-paste-input').value;
        if (!urls.trim()) {
            showToast('Please paste or type at least one valid URL.', 'warning');
            return;
        }

        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'add_urls', urls: urls})
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                document.getElementById('queue-paste-input').value = '';
                poll();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tab: File Manager Browser
    // ─────────────────────────────────────────────────────────────────────────
    function loadFileBrowser() {
        fetch('?ajax=files')
            .then(res => res.json())
            .then(files => {
                const tbody = document.getElementById('file-table-body');
                if (files.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="5" class="py-8 px-6 text-center text-slate-500 font-medium">
                                No files inside output folder yet
                            </td>
                        </tr>`;
                    return;
                }

                let html = '';
                files.forEach(f => {
                    const size = (f.size / (1024 * 1024)).toFixed(2) + ' MB';
                    const date = new Date(f.time * 1000).toLocaleString();

                    html += `
                        <tr class="hover:bg-slate-900/30 transition-colors">
                            <td class="py-4 px-6 font-bold text-slate-200">
                                <span class="flex items-center gap-2">
                                    <i class="fa-solid fa-file-arrow-down text-blue-400"></i> ${cleanHTML(f.name)}
                                </span>
                            </td>
                            <td class="py-4 px-6 text-xs text-slate-400">${cleanHTML(f.mime)}</td>
                            <td class="py-4 px-6 font-mono text-xs text-slate-300">${size}</td>
                            <td class="py-4 px-6 text-xs text-slate-400">${date}</td>
                            <td class="py-4 px-6 text-right space-x-1.5">
                                <a href="?download_file=${encodeURIComponent(f.name)}" class="text-emerald-400 hover:text-emerald-300 bg-emerald-950/40 px-3 py-1.5 rounded border border-emerald-900/40 text-xs font-semibold inline-block transition-colors">
                                    <i class="fa-solid fa-download mr-1"></i> Get
                                </a>
                                <button onclick="deleteLocalFile('${cleanHTML(f.name)}')" class="text-rose-400 hover:text-rose-300 bg-rose-950/40 px-3 py-1.5 rounded border border-rose-900/40 text-xs font-semibold transition-colors">
                                    <i class="fa-solid fa-trash-can mr-1"></i> Delete
                                </button>
                            </td>
                        </tr>`;
                });
                tbody.innerHTML = html;
            });
    }

    function deleteLocalFile(filename) {
        if (!confirm(`Are you sure you want to permanently delete: ${filename}?`)) return;
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'delete_file', filename: filename})
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                loadFileBrowser();
            } else {
                showToast(data.message, 'error');
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tab: Live Log Console Filter & Autoscroll
    // ─────────────────────────────────────────────────────────────────────────
    function applyLogFilter() {
        if (activeTab !== 'logs') return;
        const filter = document.getElementById('log-filter').value;
        const area = document.getElementById('log-area');

        if (!currentLogs) {
            area.innerHTML = '<span class="text-slate-600 italic">Console buffer is currently empty.</span>';
            return;
        }

        const lines = currentLogs.split('\n');
        let filtered = lines;

        if (filter === 'errors') {
            filtered = lines.filter(l => l.includes('[ERROR]') || l.includes('[FAIL]'));
        } else if (filter === 'saved') {
            filtered = lines.filter(l => l.includes('[SAVED]'));
        } else if (filter === 'info') {
            filtered = lines.filter(l => l.includes('[INFO]'));
        }

        // Colorize lines nicely inside HTML span
        let logsHtml = '';
        filtered.forEach(line => {
            if (!line.trim()) return;
            let color = 'text-slate-300';
            if (line.includes('[ERROR]') || line.includes('[FAIL]')) color = 'text-rose-400 font-semibold';
            if (line.includes('[SAVED]')) color = 'text-emerald-400 font-semibold';
            if (line.includes('[WARN]')) color = 'text-amber-400';

            logsHtml += `<span class="block ${color}">${escapeHTML(line)}</span>`;
        });

        area.innerHTML = logsHtml || '<span class="text-slate-600 italic">No matching lines found for current filter.</span>';

        if (document.getElementById('autoscroll').checked) {
            area.scrollTop = area.scrollHeight;
        }

        document.getElementById('log-stat').innerText = `Buffer updated at ${new Date().toLocaleTimeString()} • ${filtered.length} matching lines`;
    }

    // For log escaping specifically (only escaped visually)
    function escapeHTML(str) {
        return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
    }

    function copyLogConsole() {
        navigator.clipboard.writeText(currentLogs).then(() => showToast('Console logs buffer copied.', 'success'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tab: Settings Form Config Saver
    // ─────────────────────────────────────────────────────────────────────────
    function saveSettings(event) {
        event.preventDefault();

        const payload = {
            action: 'save_config',
            input_file: document.getElementById('config-input-file').value,
            output_dir: document.getElementById('config-output-dir').value,
            naming_mode: document.getElementById('config-naming-mode').value,
            concurrency_limit: document.getElementById('config-concurrency').value,
            delay: document.getElementById('config-delay').value,
            max_retries: document.getElementById('config-retries').value,
            user_agent: document.getElementById('config-user-agent').value,
            proxy: document.getElementById('config-proxy').value,
            speed_limit: document.getElementById('config-speed-limit').value,
            referer: document.getElementById('config-referer').value,
            headers: document.getElementById('config-headers').value,
            cookies: document.getElementById('config-cookies').value,
            post_processing: document.getElementById('config-post-processing').value,
            skip_existing: document.getElementById('config-skip-existing').checked ? 1 : 0,
            collision_suffix: document.getElementById('config-collision-suffix').checked ? 1 : 0,
            random_ua: document.getElementById('config-random-ua').checked ? 1 : 0,
        };

        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                poll();
            } else {
                showToast(data.message, 'error');
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Folder / Directory Picker overlay
    // ─────────────────────────────────────────────────────────────────────────
    function openDirPicker(mode, targetId) {
        targetPickerId = targetId;
        browseMode = mode;
        document.getElementById('browser-title').innerText = mode === 'file' ? 'Select Import Source File' : 'Select Target Downloads Directory';
        document.getElementById('browser-overlay').classList.remove('hidden');
        loadDirPicker('<?php echo addslashes(__DIR__); ?>');
    }

    function closeBrowser() {
        document.getElementById('browser-overlay').classList.add('hidden');
    }

    function loadDirPicker(path) {
        fetch(`?browse=1&type=${browseMode}&path=${encodeURIComponent(path)}`)
            .then(res => res.json())
            .then(data => {
                document.getElementById('browser-path').innerText = data.current;
                document.getElementById('browser-selected').innerText = 'Nothing selected';

                let html = '';
                if (data.parent) {
                    html += `
                        <div onclick="loadDirPicker('${escapeJsString(data.parent)}')" class="flex items-center gap-3 px-3 py-2 text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg cursor-pointer transition-colors text-sm">
                            <i class="fa-solid fa-arrow-turn-up text-blue-400 rotate-270 w-5"></i> .. (up a folder)
                        </div>`;
                }

                data.dirs.forEach(d => {
                    const full = data.current + '/' + d;
                    if (browseMode === 'dir') {
                        html += `
                            <div onclick="selectPickerItem('${escapeJsString(full)}')" ondblclick="loadDirPicker('${escapeJsString(full)}')" class="picker-item flex items-center justify-between px-3 py-2 text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg cursor-pointer transition-colors text-sm">
                                <span class="flex items-center gap-3"><i class="fa-solid fa-folder text-amber-400 w-5"></i> ${cleanHTML(d)}</span>
                                <span class="text-[10px] text-slate-500 uppercase tracking-widest font-semibold">Folder</span>
                            </div>`;
                    } else {
                        html += `
                            <div onclick="loadDirPicker('${escapeJsString(full)}')" class="flex items-center justify-between px-3 py-2 text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg cursor-pointer transition-colors text-sm">
                                <span class="flex items-center gap-3"><i class="fa-solid fa-folder text-amber-400 w-5"></i> ${cleanHTML(d)}</span>
                                <span class="text-[10px] text-slate-500 uppercase tracking-widest font-semibold">Folder</span>
                            </div>`;
                    }
                });

                data.files.forEach(f => {
                    const full = data.current + '/' + f;
                    html += `
                        <div onclick="selectPickerItem('${escapeJsString(full)}')" class="picker-item flex items-center justify-between px-3 py-2 text-slate-300 hover:text-white hover:bg-slate-800/50 rounded-lg cursor-pointer transition-colors text-sm">
                            <span class="flex items-center gap-3"><i class="fa-solid fa-file-invoice text-blue-400 w-5"></i> ${cleanHTML(f)}</span>
                            <span class="text-[10px] text-slate-500 uppercase tracking-widest font-semibold">File</span>
                        </div>`;
                });

                const container = document.getElementById('browser-list');
                container.innerHTML = html || `<span class="block p-4 text-center text-slate-600 text-xs italic">Folder is empty</span>`;
            });
    }

    let selectedPickerPath = '';

    function selectPickerItem(path) {
        selectedPickerPath = path;
        document.getElementById('browser-selected').innerText = path;

        // highlight styles
        document.querySelectorAll('.picker-item').forEach(el => el.classList.remove('bg-blue-600/10', 'border-blue-700/50'));
        event.currentTarget.classList.add('bg-blue-600/10', 'border', 'border-blue-700/50');
    }

    function confirmBrowser() {
        if (!selectedPickerPath) {
            if (browseMode === 'dir') {
                selectedPickerPath = document.getElementById('browser-path').innerText;
            } else {
                showToast('Please select a file to confirm.', 'warning');
                return;
            }
        }
        document.getElementById(targetPickerId).value = selectedPickerPath;
        closeBrowser();
        showToast('Path successfully resolved.', 'info');
    }

    function escapeJsString(str) {
        return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    }

    // Begin Loop Polling
    setInterval(poll, 1500);
    poll();
</script>
</body>
</html>
