<?php
session_start();

// ===== KONFIGURASI =====
// Ganti password ini!
$ACCESS_PASSWORD = 'admin'; 

// ===== AUTHENTICATION =====
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (!isset($_SESSION['monitor_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $ACCESS_PASSWORD) {
            $_SESSION['monitor_logged_in'] = true;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = "Password salah!";
        }
    }
}

if (!isset($_SESSION['monitor_logged_in'])):
?>
<!DOCTYPE html>
<html>
<head>
    <title>Monitor Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { background: #1a1a1a; color: #fff; font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: #2a2a2a; padding: 2rem; border-radius: 8px; border: 1px solid #333; width: 300px; }
        input { width: 100%; padding: 10px; margin: 10px 0; background: #333; border: 1px solid #444; color: #fff; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        button:hover { background: #0056b3; }
        .error { color: #ff4444; margin-bottom: 10px; text-align: center; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2 style="text-align:center; margin-top:0;">System Monitor</h2>
        <?php if(isset($error)) echo "<div class='error'>$error</div>"; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Enter Password" required autofocus>
            <button type="submit">LOGIN</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
endif;

// ===== SYSTEM FUNCTIONS =====

// 1. Disk Usage
$path = __DIR__;
$dt = disk_total_space($path);
$df = disk_free_space($path);
$du = $dt - $df;
$dp = sprintf('%.2f',($du / $dt) * 100);

function format_size($size) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    for ($i = 0; $size >= 1024 && $i < 4; $i++) $size /= 1024;
    return round($size, 2) . $units[$i];
}

// 2. Log Detection
$log_files = ['error_log', 'error.txt', 'php_error.log', 'debug.log'];
$found_logs = [];
foreach ($log_files as $log) {
    if (file_exists($log)) {
        $found_logs[$log] = [
            'size' => format_size(filesize($log)),
            'content' => shell_exec_enabled() ? `tail -n 20 $log` : file_get_contents_chunked($log, 4096)
        ];
    }
}

// Helper for restricted reading
function file_get_contents_chunked($file, $bytes, $offset = -1) {
    if (!file_exists($file)) return null;
    $filesize = filesize($file);
    if ($filesize === 0) return "Empty file";
    
    // Read last $bytes bytes
    $start = max(0, $filesize - $bytes);
    return file_get_contents($file, false, null, $start, $bytes);
}

function shell_exec_enabled() {
    return function_exists('shell_exec') && is_callable('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))));
}

// 3. Clear Log Action
if (isset($_POST['clear_log'])) {
    $file_to_clear = $_POST['clear_log'];
    if (in_array($file_to_clear, $log_files) && file_exists($file_to_clear)) {
        file_put_contents($file_to_clear, "");
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>InfinityFree Monitor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --bg: #121212; --card: #1e1e1e; --text: #e0e0e0; --accent: #00d4ff; --border: #333; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; font-size: 14px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 15px; }
        .header h1 { margin: 0; font-size: 1.5rem; color: var(--accent); }
        .btn { padding: 6px 12px; background: #333; color: white; border: 1px solid var(--border); border-radius: 4px; text-decoration: none; cursor: pointer; font-size: 0.85rem; }
        .btn:hover { background: #444; }
        .btn-danger { background: #cf3030; border-color: #b02020; }
        .btn-danger:hover { background: #b02020; }
        
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .card { background: var(--card); padding: 20px; border-radius: 8px; border: 1px solid var(--border); }
        .card h3 { margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 10px; font-size: 1rem; color: #aaa; }
        
        .stat-row { display: flex; justify-content: space-between; margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid #2a2a2a; }
        .stat-row:last-child { border: none; }
        .val { font-weight: bold; font-family: monospace; color: var(--accent); }
        
        .progress-bar { background: #333; height: 10px; border-radius: 5px; overflow: hidden; margin-top: 5px; }
        .progress-fill { height: 100%; background: var(--accent); }
        
        .log-box { background: #000; color: #0f0; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; white-space: pre-wrap; height: 200px; overflow-y: auto; border: 1px solid #333; margin-bottom: 10px; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-green { background: rgba(0, 255, 0, 0.1); color: #0f0; border: 1px solid #0f0; }
        .badge-red { background: rgba(255, 0, 0, 0.1); color: #f44; border: 1px solid #f44; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fas fa-microchip"></i> System Monitor</h1>
        <a href="?logout=1" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="grid">
        <!-- CARD 1: DISK USAGE -->
        <div class="card">
            <h3><i class="fas fa-hdd"></i> Disk Storage</h3>
            <div class="stat-row">
                <span>Total Space</span>
                <span class="val"><?= format_size($dt) ?></span>
            </div>
            <div class="stat-row">
                <span>Used Space</span>
                <span class="val"><?= format_size($du) ?></span>
            </div>
            <div class="stat-row">
                <span>Free Space</span>
                <span class="val"><?= format_size($df) ?></span>
            </div>
            <div style="margin-top:15px;">
                <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:2px;">
                    <span>Usage</span>
                    <span><?= $dp ?>%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= $dp ?>%; background: <?= $dp > 90 ? '#ff4444' : '#00d4ff' ?>;"></div>
                </div>
            </div>
        </div>

        <!-- CARD 2: PHP CONFIG -->
        <div class="card">
            <h3><i class="fab fa-php"></i> PHP Environment</h3>
            <div class="stat-row">
                <span>PHP Version</span>
                <span class="val"><?= phpversion() ?></span>
            </div>
            <div class="stat-row">
                <span>Memory Limit</span>
                <span class="val"><?= ini_get('memory_limit') ?></span>
            </div>
            <div class="stat-row">
                <span>Max Upload</span>
                <span class="val"><?= ini_get('upload_max_filesize') ?></span>
            </div>
            <div class="stat-row">
                <span>Post Max Size</span>
                <span class="val"><?= ini_get('post_max_size') ?></span>
            </div>
            <div class="stat-row">
                <span>Max Execution</span>
                <span class="val"><?= ini_get('max_execution_time') ?>s</span>
            </div>
            <div class="stat-row">
                <span>Shell Exec</span>
                <span><?= shell_exec_enabled() ? '<span class="badge badge-green">ON</span>' : '<span class="badge badge-red">OFF</span>' ?></span>
            </div>
        </div>
    </div>

    <!-- LOG SECTION -->
    <div class="card" style="margin-top: 20px;">
        <h3><i class="fas fa-clipboard-list"></i> Error Log Detector</h3>
        <?php if(empty($found_logs)): ?>
            <div style="padding: 20px; text-align: center; color: #666;">
                <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                No standard error logs (error_log, error.txt) found in this directory.
            </div>
        <?php else: ?>
            <?php foreach($found_logs as $file => $data): ?>
                <div style="margin-bottom: 20px; border-bottom: 1px solid #333; padding-bottom: 20px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                        <strong><i class="fas fa-file-alt"></i> <?= htmlspecialchars($file) ?> <small>(<?= $data['size'] ?>)</small></strong>
                        <form method="POST" onsubmit="return confirm('Clear this log file?');">
                            <input type="hidden" name="clear_log" value="<?= htmlspecialchars($file) ?>">
                            <button type="submit" class="btn btn-danger" style="font-size:11px; padding:2px 8px;">Clear Log</button>
                        </form>
                    </div>
                    <div class="log-box"><?= htmlspecialchars($data['content']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div style="text-align:center; margin-top:20px; color:#555; font-size:12px;">
        Server Software: <?= $_SERVER['SERVER_SOFTWARE'] ?> | Server IP: <?= $_SERVER['SERVER_ADDR'] ?>
    </div>
</div>

</body>
</html>
