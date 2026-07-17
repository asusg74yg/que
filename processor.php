<?php
ignore_user_abort(true);
set_time_limit(0);

// ─── File references (must match index.php) ───────────────────────────────────
define('CONFIG_FILE',  'config.json');
define('LOG_FILE',     'process.log');
define('LOCK_FILE',    'process.lock');
define('STATS_FILE',   'stats.json');
define('STOP_FLAG',    'stop.flag');
define('QUEUE_FILE',   'processing.txt');
define('FAILED_LOG',   'failed.log');

// ─── Random UA pool ───────────────────────────────────────────────────────────
const UA_POOL = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Edge/124.0.0.0 Safari/537.36',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.6367.82 Mobile Safari/537.36',
];

// ─── Load config ──────────────────────────────────────────────────────────────
if (!file_exists(CONFIG_FILE)) {
    die("processor: config.json not found.\n");
}
$config = json_decode(file_get_contents(CONFIG_FILE), true);

// ─── Logging ──────────────────────────────────────────────────────────────────
function log_msg(string $level, string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [PID:' . getmypid() . '] [' . strtoupper($level) . '] ' . $msg;
    file_put_contents(LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ─── Lock file ────────────────────────────────────────────────────────────────
function acquire_lock(): bool {
    if (file_exists(LOCK_FILE)) {
        $data = json_decode(file_get_contents(LOCK_FILE), true);
        if ($data && isset($data['pid'])) {
            // Check if PID is still alive
            if (PHP_OS_FAMILY !== 'Windows' && file_exists('/proc/' . $data['pid'])) {
                log_msg('warn', 'Another worker is already running (PID ' . $data['pid'] . '). Exiting.');
                return false;
            }
            if (PHP_OS_FAMILY === 'Windows') {
                $out = [];
                exec('tasklist /FI "PID eq ' . (int)$data['pid'] . '" 2>&1', $out);
                if (count($out) > 1) {
                    log_msg('warn', 'Another worker is already running (PID ' . $data['pid'] . '). Exiting.');
                    return false;
                }
            }
        }
        // Stale lock — remove it
        unlink(LOCK_FILE);
        log_msg('info', 'Removed stale lock file.');
    }
    file_put_contents(LOCK_FILE, json_encode(['pid' => getmypid(), 'started' => time()]));
    return true;
}

function release_lock(): void {
    if (file_exists(LOCK_FILE)) unlink(LOCK_FILE);
}

// ─── Stats ────────────────────────────────────────────────────────────────────
function load_stats(): array {
    return file_exists(STATS_FILE)
        ? json_decode(file_get_contents(STATS_FILE), true)
        : ['total' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0];
}

function save_stats(array $s): void {
    file_put_contents(STATS_FILE, json_encode($s), LOCK_EX);
}

// ─── Queue helpers ────────────────────────────────────────────────────────────
function init_queue(array $config): bool {
    if (file_exists(QUEUE_FILE)) return true;
    if (!file_exists($config['input_file'])) {
        log_msg('error', 'Input file not found: ' . $config['input_file']);
        return false;
    }
    copy($config['input_file'], QUEUE_FILE);
    // Count total lines for stats
    $lines = count(array_filter(array_map('trim', file(QUEUE_FILE))));
    $s = load_stats();
    $s['total'] = $lines;
    save_stats($s);
    log_msg('info', 'Queue initialized with ' . $lines . ' items.');
    return true;
}

function get_batch(array $config): array {
    if (!file_exists(QUEUE_FILE)) return [];
    $lines = file(QUEUE_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_slice(array_map('trim', $lines), 0, $config['batch_size']);
}

function remove_from_queue(array $processed): void {
    if (!file_exists(QUEUE_FILE)) return;
    $set  = array_flip($processed);
    $keep = array_filter(
        array_map('trim', file(QUEUE_FILE, FILE_IGNORE_NEW_LINES)),
        fn($l) => $l !== '' && !isset($set[$l])
    );
    file_put_contents(QUEUE_FILE, implode(PHP_EOL, $keep) . PHP_EOL, LOCK_EX);
}

// ─── Filename resolution ──────────────────────────────────────────────────────
function resolve_filename(string $url, string $output_dir, string $mode, int $index, bool $collision_suffix): string {
    switch ($mode) {
        case 'mirror':
            $parsed = parse_url($url);
            $path   = ltrim($parsed['path'] ?? 'file', '/');
            $dest   = $output_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $dir    = dirname($dest);
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            break;

        case 'incremental':
            $ext  = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            $ext  = $ext ? '.' . $ext : '';
            $dest = $output_dir . DIRECTORY_SEPARATOR . str_pad($index, 4, '0', STR_PAD_LEFT) . $ext;
            break;

        default: // tail
            $basename = basename(parse_url($url, PHP_URL_PATH));
            if (!$basename) $basename = 'file_' . md5($url);
            $dest = $output_dir . DIRECTORY_SEPARATOR . $basename;
            break;
    }

    // Handle collisions
    if ($collision_suffix && file_exists($dest)) {
        $info  = pathinfo($dest);
        $i     = 1;
        do {
            $dest = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '_' . $i . (isset($info['extension']) ? '.' . $info['extension'] : '');
            $i++;
        } while (file_exists($dest));
    }

    return $dest;
}

// ─── Download ─────────────────────────────────────────────────────────────────
function download_url(string $url, string $ua): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',   // Accept any encoding
    ]);
    $body     = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    return [
        'ok'      => ($code === 200 && $body !== false && $body !== ''),
        'code'    => $code,
        'body'    => $body,
        'error'   => $err,
    ];
}

// ─── Spawn next worker ────────────────────────────────────────────────────────
function spawn_next(): void {
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
    log_msg('info', 'Spawned next worker.');
}

// ─── Main ─────────────────────────────────────────────────────────────────────
if (!acquire_lock()) exit;

log_msg('info', 'Worker started.');

// Ensure output directory exists
if (!is_dir($config['output_dir'])) {
    mkdir($config['output_dir'], 0777, true);
}

// Initialize queue if needed
if (!init_queue($config)) {
    release_lock();
    exit;
}

$stats       = load_stats();
$batch       = get_batch($config);
$processed   = [];
$item_index  = $stats['done'] + $stats['skipped'];

if (empty($batch)) {
    log_msg('info', 'Queue is empty. All done.');
    release_lock();
    exit;
}

foreach ($batch as $url) {
    // Check stop flag
    if (file_exists(STOP_FLAG)) {
        log_msg('info', 'Stop flag detected. Exiting cleanly.');
        break;
    }

    $item_index++;

    // Resolve user agent
    $ua = !empty($config['random_ua'])
        ? UA_POOL[array_rand(UA_POOL)]
        : ($config['user_agent'] ?? 'DownloadQueueManager/1.0');

    // Resolve destination path
    $dest = resolve_filename(
        $url,
        $config['output_dir'],
        $config['naming_mode'] ?? 'tail',
        $item_index,
        !empty($config['collision_suffix'])
    );

    // Skip existing
    if (!empty($config['skip_existing']) && file_exists($dest)) {
        log_msg('skip', '[SKIP] Already exists: ' . basename($dest) . ' ← ' . $url);
        $stats['skipped']++;
        save_stats($stats);
        $processed[] = $url;
        continue;
    }

    // Download with retries
    $max_retries = max(0, (int)($config['max_retries'] ?? 3));
    $attempt     = 0;
    $result      = null;

    do {
        if ($attempt > 0) {
            log_msg('info', 'Retry ' . $attempt . '/' . $max_retries . ' for: ' . $url);
            sleep(2);
        }
        $result = download_url($url, $ua);
        $attempt++;
    } while (!$result['ok'] && $attempt <= $max_retries);

    if ($result['ok']) {
        file_put_contents($dest, $result['body']);
        log_msg('saved', '[SAVED] ' . $dest . ' (' . strlen($result['body']) . ' bytes) ← ' . $url);
        $stats['done']++;
        save_stats($stats);
        $processed[] = $url;
    } else {
        log_msg('fail', '[FAIL] HTTP ' . $result['code'] . ' — ' . ($result['error'] ?: 'empty response') . ' ← ' . $url);
        file_put_contents(FAILED_LOG, $url . PHP_EOL, FILE_APPEND | LOCK_EX);
        $stats['failed']++;
        save_stats($stats);
        // Failed items stay in queue for retry on next run
        // Only mark as processed if retries exhausted AND we want to skip permanently
        // For now: leave in queue so user can retry via Reset
    }

    // Delay
    if (!empty($config['delay']) && $config['delay'] > 0) {
        sleep((int)$config['delay']);
    }

    gc_collect_cycles();
}

// Remove successfully processed items from queue
remove_from_queue($processed);

release_lock();
log_msg('info', 'Batch complete. Processed: ' . count($processed) . ' item(s).');

// Spawn next worker if queue still has items and no stop flag
if (!file_exists(STOP_FLAG) && !empty(get_batch($config)) && ($config['run_mode'] ?? 'spawn') === 'spawn') {
    spawn_next();
} else {
    log_msg('info', 'No further spawning needed.');
}
