<?php
// ─── Shared Configurations and State Helpers ─────────────────────────────────

define('CONFIG_FILE', 'config.json');
define('STATE_FILE',  'queue_state.json');
define('LOG_FILE',    'process.log');
define('STOP_FLAG',   'stop.flag');

// Defaults
$defaults = [
    'input_file'       => '',
    'output_dir'       => '',
    'concurrency_limit'=> 3, // parallel workers
    'delay'            => 1,
    'skip_existing'    => true,
    'naming_mode'      => 'tail',
    'collision_suffix' => false,
    'max_retries'      => 3,
    'user_agent'       => 'DownloadQueueManager/1.0',
    'random_ua'        => false,
    'headers'          => '', // Raw custom headers (newline separated)
    'cookies'          => '', // Raw cookies
    'referer'          => '', // Referer header
    'speed_limit'      => 0,  // Speed limit in KB/s (0 = unlimited)
    'post_processing'  => '', // CLI command or PHP script path to execute on success
];

function load_config(): array {
    global $defaults;
    if (!file_exists(CONFIG_FILE)) {
        return $defaults;
    }
    $config = json_decode(file_get_contents(CONFIG_FILE), true) ?: [];
    return array_merge($defaults, $config);
}

function save_config(array $config): bool {
    return (bool)file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX);
}

// ─── Logging ──────────────────────────────────────────────────────────────────
function log_msg(string $level, string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [PID:' . getmypid() . '] [' . strtoupper($level) . '] ' . $msg;
    file_put_contents(LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ─── Safe State Management using flock ────────────────────────────────────────

/**
 * Executes a callback with an exclusive lock on the queue state.
 * This guarantees atomic read-modify-write operations across parallel workers.
 */
function update_queue_state(callable $callback) {
    $lockFile = STATE_FILE . '.lock';
    $fp = fopen($lockFile, 'c+');
    if (!$fp) {
        log_msg('error', 'Could not open lock file: ' . $lockFile);
        return false;
    }

    if (!flock($fp, LOCK_EX)) {
        log_msg('error', 'Could not acquire exclusive lock on: ' . $lockFile);
        fclose($fp);
        return false;
    }

    // Read current state
    $state = [];
    if (file_exists(STATE_FILE)) {
        $content = file_get_contents(STATE_FILE);
        $state = json_decode($content, true) ?: [];
    }

    // Ensure basic structure
    if (!isset($state['tasks'])) {
        $state['tasks'] = [];
    }

    // Run callback to modify state
    $result = $callback($state);

    // Save modified state back
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));

    flock($fp, LOCK_UN);
    fclose($fp);

    return $result;
}

/**
 * Read queue state with a shared lock (allows parallel reads).
 */
function read_queue_state(): array {
    $lockFile = STATE_FILE . '.lock';
    $fp = fopen($lockFile, 'c+');
    if (!$fp) {
        // Fallback if lock file can't be opened
        if (file_exists(STATE_FILE)) {
            return json_decode(file_get_contents(STATE_FILE), true) ?: ['tasks' => []];
        }
        return ['tasks' => []];
    }

    flock($fp, LOCK_SH);
    $state = ['tasks' => []];
    if (file_exists(STATE_FILE)) {
        $content = file_get_contents(STATE_FILE);
        $state = json_decode($content, true) ?: ['tasks' => []];
    }
    flock($fp, LOCK_UN);
    fclose($fp);

    return $state;
}
