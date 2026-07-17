<?php
// ─── State & Config Files ────────────────────────────────────────────────────
define('CONFIG_FILE',  'config.json');
define('LOG_FILE',     'process.log');
define('LOCK_FILE',    'process.lock');
define('STATS_FILE',   'stats.json');
define('STOP_FLAG',    'stop.flag');
define('QUEUE_FILE',   'processing.txt');

// ─── Defaults ────────────────────────────────────────────────────────────────
$defaults = [
    'input_file'      => '',
    'output_dir'      => '',
    'batch_size'      => 1,
    'delay'           => 1,
    'skip_existing'   => true,
    'naming_mode'     => 'tail',
    'collision_suffix'=> false,
    'run_mode'        => 'spawn',
    'max_retries'     => 3,
    'user_agent'      => 'DownloadQueueManager/1.0',
    'random_ua'       => false,
];

$config = file_exists(CONFIG_FILE)
    ? array_merge($defaults, json_decode(file_get_contents(CONFIG_FILE), true))
    : $defaults;

$message      = '';
$message_type = 'info';

// ─── AJAX: Server-side file/folder browser ───────────────────────────────────
if (isset($_GET['browse'])) {
    header('Content-Type: application/json');
    $type = $_GET['type'] ?? 'file'; // 'file' or 'dir'
    $path = realpath($_GET['path'] ?? __DIR__);
    $root = realpath(__DIR__);

    // Safety: never browse outside the script's own directory tree
    if (!$path || strpos($path, $root) !== 0) {
        $path = $root;
    }

    $items = [
        'current' => $path,
        'parent'  => (dirname($path) !== $path && strpos(dirname($path), $root) === 0)
                        ? dirname($path) : null,
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

// ─── AJAX: Status polling ─────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    $log_tail = 'No log entries yet.';
    if (file_exists(LOG_FILE)) {
        $lines    = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $log_tail = implode("\n", array_slice($lines, -100));
    }

    $stats = file_exists(STATS_FILE)
        ? json_decode(file_get_contents(STATS_FILE), true)
        : ['total' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0];

    $lock_info = null;
    if (file_exists(LOCK_FILE)) {
        $lock_info = json_decode(file_get_contents(LOCK_FILE), true);
    }

    echo json_encode([
        'running'   => file_exists(LOCK_FILE),
        'stop_flag' => file_exists(STOP_FLAG),
        'stats'     => $stats,
        'log_tail'  => $log_tail,
        'lock_info' => $lock_info,
    ]);
    exit;
}

// ─── POST Actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Save config
    if (isset($_POST['save_config'])) {
        $config['input_file']       = trim($_POST['input_file'] ?? '');
        $config['output_dir']       = trim($_POST['output_dir'] ?? '');
        $config['batch_size']       = max(1, (int)($_POST['batch_size'] ?? 1));
        $config['delay']            = max(0, (int)($_POST['delay'] ?? 1));
        $config['skip_existing']    = isset($_POST['skip_existing']);
        $config['collision_suffix'] = isset($_POST['collision_suffix']);
        $config['naming_mode']      = $_POST['naming_mode'] ?? 'tail';
        $config['run_mode']         = $_POST['run_mode'] ?? 'spawn';
        $config['max_retries']      = max(0, (int)($_POST['max_retries'] ?? 3));
        $config['user_agent']       = trim($_POST['user_agent'] ?? 'DownloadQueueManager/1.0');
        $config['random_ua']        = isset($_POST['random_ua']);
        file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT));
        $message      = 'Configuration saved.';
        $message_type = 'success';
    }

    // Start queue
    if (isset($_POST['start'])) {
        if (file_exists(LOCK_FILE)) {
            $message      = 'A worker is already running. Stop it first or wait for it to finish.';
            $message_type = 'warning';
        } elseif (empty($config['input_file']) || empty($config['output_dir'])) {
            $message      = 'Please set and save an input file and output folder before starting.';
            $message_type = 'error';
        } else {
            // Remove any stale stop flag
            if (file_exists(STOP_FLAG)) unlink(STOP_FLAG);

            // Trigger processor via a non-blocking cURL call to itself
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

            $message      = 'Worker triggered. Monitor the log below.';
            $message_type = 'success';
        }
    }

    // Stop
    if (isset($_POST['stop'])) {
        touch(STOP_FLAG);
        $message      = 'Stop signal sent. Worker will exit after the current download.';
        $message_type = 'warning';
    }

    // Reset queue
    if (isset($_POST['reset_queue'])) {
        if (!file_exists($config['input_file'])) {
            $message      = 'Input file not found. Cannot reset queue.';
            $message_type = 'error';
        } else {
            copy($config['input_file'], QUEUE_FILE);
            // Reset stats
            file_put_contents(STATS_FILE, json_encode(['total'=>0,'done'=>0,'skipped'=>0,'failed'=>0]));
            $message      = 'Queue reset from source file.';
            $message_type = 'success';
        }
    }

    // Clear stop flag
    if (isset($_POST['clear_stop'])) {
        if (file_exists(STOP_FLAG)) unlink(STOP_FLAG);
        $message      = 'Stop signal cleared.';
        $message_type = 'success';
    }

    // Clear log
    if (isset($_POST['clear_log'])) {
        file_put_contents(LOG_FILE, '');
        $message      = 'Log cleared.';
        $message_type = 'success';
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function checked_if(bool $cond): string {
    return $cond ? 'checked' : '';
}

function selected_if(bool $cond): string {
    return $cond ? 'selected' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Download Queue Manager</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-size: 13px;
    background: #f0f2f5;
    color: #1a1a2e;
    padding: 20px;
    line-height: 1.5;
  }

  /* ── Layout ── */
  .header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
  }
  .header h1 { font-size: 18px; font-weight: 600; }
  .header p  { font-size: 12px; color: #666; margin-top: 2px; }

  .grid-2 {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 14px;
    align-items: start;
  }

  /* ── Cards ── */
  .card {
    background: #fff;
    border: 1px solid #dde1e7;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 14px;
  }
  .card-title {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #555;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #eee;
  }

  /* ── Form elements ── */
  label.field { display: block; margin-bottom: 10px; }
  label.field span { display: block; font-size: 11px; font-weight: 600; color: #555; margin-bottom: 4px; }
  input[type="text"],
  input[type="number"],
  select,
  textarea {
    width: 100%;
    padding: 7px 9px;
    border: 1px solid #ccc;
    border-radius: 5px;
    font: inherit;
    color: #1a1a2e;
    background: #fff;
  }
  input[type="text"]:focus,
  input[type="number"]:focus,
  select:focus { outline: 2px solid #4a90d9; border-color: transparent; }

  .input-row { display: flex; gap: 6px; }
  .input-row input { flex: 1; }

  label.toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    cursor: pointer;
    margin-bottom: 8px;
  }

  /* ── Buttons ── */
  button, .btn {
    font: inherit;
    cursor: pointer;
    border: 1px solid #ccc;
    border-radius: 5px;
    padding: 7px 14px;
    background: #f5f5f5;
    color: #1a1a2e;
    transition: background .15s;
  }
  button:hover { background: #e8e8e8; }
  .btn-primary   { background: #2563eb; color: #fff; border-color: #2563eb; }
  .btn-primary:hover { background: #1d4ed8; }
  .btn-success   { background: #16a34a; color: #fff; border-color: #16a34a; }
  .btn-success:hover { background: #15803d; }
  .btn-danger    { background: #dc2626; color: #fff; border-color: #dc2626; }
  .btn-danger:hover  { background: #b91c1c; }
  .btn-warning   { background: #d97706; color: #fff; border-color: #d97706; }
  .btn-sm { padding: 4px 10px; font-size: 11px; }
  .btn-full { width: 100%; margin-bottom: 6px; }

  .btn-row { display: flex; gap: 8px; margin-bottom: 6px; }
  .btn-row button { flex: 1; }

  /* ── Status badge ── */
  #status-badge {
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: #e5e7eb;
    color: #555;
  }
  #status-badge.running { background: #dcfce7; color: #15803d; }
  #status-badge.stopped { background: #fee2e2; color: #b91c1c; }
  #status-badge.stopping { background: #fef3c7; color: #b45309; }

  /* ── Stats ── */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-bottom: 12px;
  }
  .stat-box {
    background: #f8f9fb;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 10px;
    text-align: center;
  }
  .stat-box .num { font-size: 22px; font-weight: 600; }
  .stat-box .lbl { font-size: 11px; color: #888; margin-top: 2px; }
  .stat-box.done   .num { color: #16a34a; }
  .stat-box.skip   .num { color: #2563eb; }
  .stat-box.fail   .num { color: #dc2626; }
  .stat-box.queue  .num { color: #555; }

  /* ── Log ── */
  #log-area {
    background: #0f1117;
    color: #c9d1d9;
    font-family: 'Courier New', monospace;
    font-size: 11px;
    line-height: 1.6;
    padding: 12px;
    border-radius: 6px;
    height: 280px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-break: break-all;
  }
  .log-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
  }
  .log-filter { display: flex; gap: 6px; align-items: center; }

  /* ── Alert ── */
  .alert {
    padding: 10px 14px;
    border-radius: 6px;
    margin-bottom: 14px;
    font-size: 13px;
    border-left: 4px solid;
  }
  .alert.success { background: #f0fdf4; border-color: #16a34a; color: #15803d; }
  .alert.error   { background: #fef2f2; border-color: #dc2626; color: #b91c1c; }
  .alert.warning { background: #fffbeb; border-color: #d97706; color: #92400e; }
  .alert.info    { background: #eff6ff; border-color: #2563eb; color: #1d4ed8; }

  /* ── File browser modal ── */
  #browser-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.5);
    z-index: 100;
    align-items: center;
    justify-content: center;
  }
  #browser-overlay.open { display: flex; }
  #browser-box {
    background: #fff;
    border-radius: 10px;
    width: 480px;
    max-height: 70vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  #browser-header {
    padding: 12px 16px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 600;
  }
  #browser-path {
    padding: 8px 16px;
    font-size: 11px;
    font-family: monospace;
    background: #f8f9fb;
    border-bottom: 1px solid #eee;
    color: #555;
    word-break: break-all;
  }
  #browser-list {
    overflow-y: auto;
    flex: 1;
    padding: 8px 0;
  }
  .browser-item {
    padding: 8px 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
  }
  .browser-item:hover { background: #f0f4ff; }
  .browser-item .icon { font-size: 15px; }
  #browser-footer {
    padding: 10px 16px;
    border-top: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
  }
  #browser-selected {
    font-size: 11px;
    color: #555;
    font-family: monospace;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* ── Path confirm ── */
  .path-confirm {
    margin-top: 5px;
    font-size: 11px;
    padding: 5px 8px;
    border-radius: 4px;
    background: #f0fdf4;
    color: #15803d;
    border: 1px solid #bbf7d0;
    display: none;
    word-break: break-all;
  }
  .path-confirm.visible { display: block; }
  .path-confirm.empty   { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
</style>
</head>
<body>

<!-- ── File Browser Modal ─────────────────────────────────────────────────── -->
<div id="browser-overlay">
  <div id="browser-box">
    <div id="browser-header">
      <span id="browser-title">Browse</span>
      <button class="btn-sm" onclick="closeBrowser()">✕ Close</button>
    </div>
    <div id="browser-path">/</div>
    <div id="browser-list"></div>
    <div id="browser-footer">
      <span id="browser-selected">Nothing selected</span>
      <button class="btn-primary btn-sm" onclick="confirmBrowser()">Select</button>
    </div>
  </div>
</div>

<!-- ── Header ─────────────────────────────────────────────────────────────── -->
<div class="header">
  <div>
    <h1>Download Queue Manager</h1>
    <p>Resumable URL batch downloader — browser-triggered, server-side worker.</p>
  </div>
  <div id="status-badge">● IDLE</div>
</div>

<?php if ($message): ?>
<div class="alert <?= esc($message_type) ?>"><?= esc($message) ?></div>
<?php endif; ?>

<div class="grid-2">

  <!-- ── LEFT COLUMN ──────────────────────────────────────────────────────── -->
  <div>

    <!-- Source & Destination -->
    <div class="card">
      <div class="card-title">1. Source &amp; Destination</div>

      <label class="field">
        <span>URL list file (.txt)</span>
        <div class="input-row">
          <input type="text" id="input_file_display"
                 value="<?= esc($config['input_file']) ?>"
                 placeholder="e.g. urls.txt" readonly>
          <button type="button" onclick="openBrowser('file','input_file_display','input_file_hidden','input_confirm')">Browse</button>
        </div>
        <div id="input_confirm" class="path-confirm <?= $config['input_file'] ? 'visible' : '' ?>">
          <?= $config['input_file'] ? '✔ ' . esc($config['input_file']) : '' ?>
        </div>
      </label>

      <label class="field">
        <span>Output folder</span>
        <div class="input-row">
          <input type="text" id="output_dir_display"
                 value="<?= esc($config['output_dir']) ?>"
                 placeholder="e.g. downloads" readonly>
          <button type="button" onclick="openBrowser('dir','output_dir_display','output_dir_hidden','output_confirm')">Browse</button>
        </div>
        <div id="output_confirm" class="path-confirm <?= $config['output_dir'] ? 'visible' : '' ?>">
          <?= $config['output_dir'] ? '✔ ' . esc($config['output_dir']) : '' ?>
        </div>
      </label>

      <p style="font-size:11px;color:#888;margin-top:4px">
        Browse is restricted to the directory where this script lives:<br>
        <code><?= esc(__DIR__) ?></code>
      </p>
    </div>

    <!-- Download Behavior -->
    <div class="card">
      <div class="card-title">2. Download Behavior</div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <label class="field" style="margin:0">
          <span>Filename strategy</span>
          <select name="naming_mode" id="naming_mode">
            <option value="tail"        <?= selected_if($config['naming_mode']==='tail') ?>>Tail of URL</option>
            <option value="mirror"      <?= selected_if($config['naming_mode']==='mirror') ?>>Mirror URL folders</option>
            <option value="incremental" <?= selected_if($config['naming_mode']==='incremental') ?>>Incremental (001, 002…)</option>
          </select>
        </label>
        <label class="field" style="margin:0">
          <span>Delay between requests (sec)</span>
          <input type="number" id="delay" value="<?= (int)$config['delay'] ?>" min="0">
        </label>
        <label class="field" style="margin:0">
          <span>Batch size</span>
          <input type="number" id="batch_size" value="<?= (int)$config['batch_size'] ?>" min="1">
        </label>
        <label class="field" style="margin:0">
          <span>Retry failed URLs</span>
          <input type="number" id="max_retries" value="<?= (int)$config['max_retries'] ?>" min="0">
        </label>
      </div>

      <label class="toggle">
        <input type="checkbox" id="skip_existing" <?= checked_if($config['skip_existing']) ?>>
        Skip if destination file already exists
      </label>
      <label class="toggle">
        <input type="checkbox" id="collision_suffix" <?= checked_if($config['collision_suffix']) ?>>
        Add numeric suffix on filename collision
      </label>
    </div>

    <!-- User Agent -->
    <div class="card">
      <div class="card-title">3. Request Identity</div>

      <label class="toggle" style="margin-bottom:10px">
        <input type="checkbox" id="random_ua" onchange="toggleUA()" <?= checked_if($config['random_ua']) ?>>
        Use a random User-Agent per request
      </label>

      <label class="field">
        <span>Custom User-Agent</span>
        <input type="text" id="user_agent"
               value="<?= esc($config['user_agent']) ?>"
               <?= $config['random_ua'] ? 'disabled' : '' ?>>
      </label>
      <p id="ua-note" style="font-size:11px;color:#888">
        <?= $config['random_ua']
            ? 'A random browser string will be selected from the built-in preset list for each download.'
            : 'This string will be sent as the User-Agent header for every request.' ?>
      </p>
    </div>

    <!-- Run Mode & Controls -->
    <div class="card">
      <div class="card-title">4. Run Mode &amp; Controls</div>

      <label class="toggle" style="margin-bottom:6px">
        <input type="radio" name="run_mode" id="mode_spawn" value="spawn"
               <?= checked_if($config['run_mode']==='spawn') ?>>
        <div>
          <strong>Spawn worker</strong>
          <div style="font-size:11px;color:#888">Each worker starts the next. Recommended — avoids memory buildup.</div>
        </div>
      </label>
      <label class="toggle" style="margin-bottom:14px">
        <input type="radio" name="run_mode" id="mode_browser" value="browser"
               <?= checked_if($config['run_mode']==='browser') ?>>
        <div>
          <strong>Browser session</strong>
          <div style="font-size:11px;color:#888">One request handles the full queue. Depends on server time limits.</div>
        </div>
      </label>

      <button type="button" class="btn btn-primary btn-full" onclick="saveConfig()">Save Configuration</button>

      <div class="btn-row">
        <button type="button" class="btn btn-success" onclick="startQueue()">▶ Start Queue</button>
        <button type="button" class="btn btn-danger"  onclick="stopQueue()">■ Stop Safely</button>
      </div>
      <div class="btn-row">
        <button type="button" class="btn" onclick="resetQueue()">↺ Reset Queue</button>
        <button type="button" class="btn" onclick="clearStop()">✕ Clear Stop Signal</button>
      </div>
    </div>

  </div><!-- /left -->

  <!-- ── RIGHT COLUMN ─────────────────────────────────────────────────────── -->
  <div>

    <!-- Stats -->
    <div class="card">
      <div class="card-title">Queue Status</div>
      <div class="stats-grid">
        <div class="stat-box queue"><div class="num" id="s-queued">—</div><div class="lbl">Queued</div></div>
        <div class="stat-box done"> <div class="num" id="s-done">—</div>  <div class="lbl">Saved</div></div>
        <div class="stat-box skip"> <div class="num" id="s-skip">—</div>  <div class="lbl">Skipped</div></div>
        <div class="stat-box fail"> <div class="num" id="s-fail">—</div>  <div class="lbl">Failed</div></div>
      </div>
      <div style="font-size:11px;color:#888;display:flex;gap:16px">
        <span>Worker lock: <b id="lock-status">checking…</b></span>
        <span>Stop signal: <b id="stop-status">checking…</b></span>
      </div>
    </div>

    <!-- Log -->
    <div class="card">
      <div class="log-controls">
        <div class="card-title" style="margin:0;border:none;padding:0">Activity Log</div>
        <div class="log-filter">
          <select id="log-filter" onchange="applyFilter()" style="font-size:11px;padding:4px 6px">
            <option value="all">All events</option>
            <option value="error">Errors only</option>
            <option value="saved">Saved only</option>
            <option value="skip">Skipped only</option>
            <option value="fail">Failed only</option>
          </select>
          <label style="font-size:11px;display:flex;align-items:center;gap:4px;cursor:pointer">
            <input type="checkbox" id="autoscroll" checked> Auto-scroll
          </label>
          <button class="btn-sm" onclick="copyLog()">Copy</button>
          <button class="btn-sm btn-danger" onclick="clearLog()">Clear</button>
        </div>
      </div>
      <div id="log-area">Waiting for worker…</div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;font-size:11px;color:#888">
        <span>Showing last 100 lines · polling every 3s</span>
        <span id="last-updated"></span>
      </div>
    </div>

  </div><!-- /right -->
</div><!-- /grid -->

<!-- Hidden inputs for form submission -->
<form id="action-form" method="POST" style="display:none">
  <input type="hidden" name="input_file"       id="input_file_hidden"  value="<?= esc($config['input_file']) ?>">
  <input type="hidden" name="output_dir"        id="output_dir_hidden"  value="<?= esc($config['output_dir']) ?>">
  <input type="hidden" name="batch_size"        id="fld_batch_size">
  <input type="hidden" name="delay"             id="fld_delay">
  <input type="hidden" name="naming_mode"       id="fld_naming_mode">
  <input type="hidden" name="max_retries"       id="fld_max_retries">
  <input type="hidden" name="user_agent"        id="fld_user_agent">
  <input type="hidden" name="run_mode"          id="fld_run_mode">
  <input type="hidden" name="skip_existing"     id="fld_skip_existing">
  <input type="hidden" name="collision_suffix"  id="fld_collision_suffix">
  <input type="hidden" name="random_ua"         id="fld_random_ua">
  <input type="hidden" name="action"            id="fld_action">
</form>

<script>
// ── Browser state ─────────────────────────────────────────────────────────────
let browserMode       = 'file';
let browserTargetDisp = '';
let browserTargetHid  = '';
let browserTargetConf = '';
let browserSelected   = '';
let browserCurrentPath= '';
let rawLog            = '';

// ── File browser ──────────────────────────────────────────────────────────────
function openBrowser(mode, dispId, hidId, confId) {
  browserMode       = mode;
  browserTargetDisp = dispId;
  browserTargetHid  = hidId;
  browserTargetConf = confId;
  browserSelected   = '';
  document.getElementById('browser-title').innerText = mode === 'file' ? 'Select URL list file' : 'Select output folder';
  document.getElementById('browser-overlay').classList.add('open');
  loadBrowser('<?= addslashes(__DIR__) ?>');
}

function closeBrowser() {
  document.getElementById('browser-overlay').classList.remove('open');
}

function loadBrowser(path) {
  fetch('?browse=1&type=' + browserMode + '&path=' + encodeURIComponent(path))
    .then(r => r.json())
    .then(data => {
      browserCurrentPath = data.current;
      document.getElementById('browser-path').innerText = data.current;
      document.getElementById('browser-selected').innerText = browserSelected || 'Nothing selected';

      let html = '';
      if (data.parent) {
        html += `<div class="browser-item" onclick="loadBrowser('${esc2(data.parent)}')"><span class="icon">⬆</span> .. (up)</div>`;
      }
      data.dirs.forEach(d => {
        const full = data.current + '/' + d;
        if (browserMode === 'dir') {
          html += `<div class="browser-item" onclick="selectBrowserItem('${esc2(full)}')" ondblclick="loadBrowser('${esc2(full)}')"><span class="icon">📁</span>${d}</div>`;
        } else {
          html += `<div class="browser-item" onclick="loadBrowser('${esc2(full)}')"><span class="icon">📁</span>${d}</div>`;
        }
      });
      data.files.forEach(f => {
        const full = data.current + '/' + f;
        html += `<div class="browser-item" onclick="selectBrowserItem('${esc2(full)}')"><span class="icon">📄</span>${f}</div>`;
      });
      document.getElementById('browser-list').innerHTML = html || '<div style="padding:16px;color:#888;font-size:12px">Empty folder.</div>';
    });
}

function selectBrowserItem(path) {
  browserSelected = path;
  document.getElementById('browser-selected').innerText = path;
  // Highlight selected
  document.querySelectorAll('.browser-item').forEach(el => el.style.background = '');
  event.currentTarget.style.background = '#e0eaff';
}

function confirmBrowser() {
  if (!browserSelected) {
    // For dir mode, allow selecting the current directory
    if (browserMode === 'dir') browserSelected = browserCurrentPath;
    else { alert('Please select a file.'); return; }
  }
  document.getElementById(browserTargetDisp).value = browserSelected;
  document.getElementById(browserTargetHid).value  = browserSelected;
  const conf = document.getElementById(browserTargetConf);
  conf.className = 'path-confirm visible';
  conf.innerText = '✔ ' + browserSelected;
  closeBrowser();
}

function esc2(s) { return s.replace(/'/g, "\\'"); }

// ── Config save ───────────────────────────────────────────────────────────────
function saveConfig() {
  document.getElementById('fld_batch_size').value       = document.getElementById('batch_size').value;
  document.getElementById('fld_delay').value            = document.getElementById('delay').value;
  document.getElementById('fld_naming_mode').value      = document.getElementById('naming_mode').value;
  document.getElementById('fld_max_retries').value      = document.getElementById('max_retries').value;
  document.getElementById('fld_user_agent').value       = document.getElementById('user_agent').value;
  document.getElementById('fld_run_mode').value         = document.querySelector('input[name="run_mode"]:checked').value;
  document.getElementById('fld_skip_existing').value    = document.getElementById('skip_existing').checked ? '1' : '';
  document.getElementById('fld_collision_suffix').value = document.getElementById('collision_suffix').checked ? '1' : '';
  document.getElementById('fld_random_ua').value        = document.getElementById('random_ua').checked ? '1' : '';
  document.getElementById('fld_action').name            = 'save_config';
  document.getElementById('action-form').submit();
}

function startQueue() { submitAction('start'); }
function stopQueue()  { submitAction('stop'); }
function resetQueue() { if (confirm('Reset the queue from the source file? This will re-queue all URLs.')) submitAction('reset_queue'); }
function clearStop()  { submitAction('clear_stop'); }
function clearLog()   { if (confirm('Clear the log file?')) submitAction('clear_log'); }

function submitAction(action) {
  document.getElementById('fld_action').name = action;
  const form = document.getElementById('action-form');
  // Add a hidden input for the action name
  let inp = document.createElement('input');
  inp.type = 'hidden';
  inp.name = action;
  inp.value = '1';
  form.appendChild(inp);
  form.submit();
}

// ── User-Agent toggle ─────────────────────────────────────────────────────────
function toggleUA() {
  const on = document.getElementById('random_ua').checked;
  document.getElementById('user_agent').disabled = on;
  document.getElementById('ua-note').innerText = on
    ? 'A random browser string will be selected from the built-in preset list for each download.'
    : 'This string will be sent as the User-Agent header for every request.';
}

// ── Log filter ────────────────────────────────────────────────────────────────
function applyFilter() {
  const filter = document.getElementById('log-filter').value;
  if (!rawLog) return;
  const lines = rawLog.split('\n');
  const filtered = filter === 'all' ? lines : lines.filter(l => {
    if (filter === 'error')  return l.includes('[ERROR]') || l.includes('[FAIL]');
    if (filter === 'saved')  return l.includes('[SAVED]');
    if (filter === 'skip')   return l.includes('[SKIP]');
    if (filter === 'fail')   return l.includes('[FAIL]');
    return true;
  });
  const area = document.getElementById('log-area');
  area.innerText = filtered.join('\n') || '(no matching entries)';
  if (document.getElementById('autoscroll').checked) area.scrollTop = area.scrollHeight;
}

function copyLog() {
  navigator.clipboard.writeText(rawLog).then(() => alert('Log copied to clipboard.'));
}

// ── Polling ───────────────────────────────────────────────────────────────────
function poll() {
  fetch('?ajax=1')
    .then(r => r.json())
    .then(data => {
      // Badge
      const badge = document.getElementById('status-badge');
      if (data.stop_flag && data.running) {
        badge.className = 'stopping'; badge.innerText = '● STOPPING';
      } else if (data.running) {
        badge.className = 'running'; badge.innerText = '● RUNNING';
      } else {
        badge.className = 'stopped'; badge.innerText = '● IDLE';
      }

      // Stats
      const s = data.stats;
      const queued = Math.max(0, (s.total || 0) - (s.done || 0) - (s.skipped || 0) - (s.failed || 0));
      document.getElementById('s-queued').innerText = queued;
      document.getElementById('s-done').innerText   = s.done    || 0;
      document.getElementById('s-skip').innerText   = s.skipped || 0;
      document.getElementById('s-fail').innerText   = s.failed  || 0;

      // Lock / stop
      document.getElementById('lock-status').innerText = data.running
        ? (data.lock_info ? 'active (PID ' + data.lock_info.pid + ')' : 'active') : 'none';
      document.getElementById('stop-status').innerText = data.stop_flag ? 'pending' : 'none';

      // Log
      rawLog = data.log_tail;
      applyFilter();

      // Timestamp
      document.getElementById('last-updated').innerText = 'Updated ' + new Date().toLocaleTimeString();
    })
    .catch(() => {});
}

setInterval(poll, 3000);
poll();
</script>
</body>
</html>
