<?php
error_reporting(0); // Matikan error display agar tidak merusak layout
session_start();

// ===== KONFIGURASI =====
$ACCESS_PASSWORD = 'admin'; // GANTI INI!

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
        body { background: #121212; color: #fff; font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: #1e1e1e; padding: 2rem; border-radius: 8px; border: 1px solid #333; width: 300px; text-align:center; }
        input { width: 100%; padding: 10px; margin: 10px 0; background: #333; border: 1px solid #444; color: #fff; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #0d6efd; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .error { color: #ff6b6b; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h3>System Monitor</h3>
        <?php if(isset($error)) echo "<div class='error'>$error</div>"; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Password" required autofocus>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
endif;

// ===== SYSTEM FUNCTIONS (SAFE MODE) =====

function format_size_safe($size) {
    if ($size === false || $size < 0) return 'N/A';
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    for ($i = 0; $size >= 1024 && $i < 4; $i++) $size /= 1024;
    return round($size, 2) . $units[$i];
}

// 1. Disk Usage (Safe)
$path = __DIR__;
$dt = @disk_total_space($path);
$df = @disk_free_space($path);
$du = ($dt && $df) ? $dt - $df : 0;
$dp = ($dt > 0) ? round(($du / $dt) * 100, 2) : 0;

// 2. Log Reading (No Shell Exec)
function tail_file($file, $lines = 20) {
    if (!file_exists($file)) return "File not found.";
    $f = @fopen($file, "rb");
    if ($f === false) return "Unable to open file.";
    
    // Adaptive buffer logic
    $buffer = 4096;
    fseek($f, -1, SEEK_END);
    if (ftell($f) <= 0) { fclose($f); return "Empty file."; }
    
    $out = '';
    $chunk = '';
    
    // Read from end
    while (ftell($f) > 0 && substr_count($out, "\n") < $lines + 1) {
        $seek = min(ftell($f), $buffer);
        fseek($f, -$seek, SEEK_CUR);
        $chunk = fread($f, $seek);
        $out = $chunk . $out;
        fseek($f, -strlen($chunk), SEEK_CUR);
    }
    fclose($f);
    return $out;
}

$log_files = ['error_log', 'error.txt', 'php_error.log', '.htaccess'];
$found_logs = [];
foreach ($log_files as $log) {
    if (file_exists($log)) {
        $found_logs[$log] = [
            'size' => format_size_safe(filesize($log)),
            'content' => tail_file($log, 20)
        ];
    }
}

// 3. Clear Log
if (isset($_POST['clear_log'])) {
    $file_to_clear = $_POST['clear_log'];
    if (in_array($file_to_clear, $log_files) && file_exists($file_to_clear)) {
        @file_put_contents($file_to_clear, "");
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
    <title>Infinity Monitor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --bg: #0f0f0f; --card: #1a1a1a; --text: #e0e0e0; --accent: #0dcaf0; --border: #333; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; font-size: 14px; }
        .container { max-width: 900px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border); padding-bottom: 15px; }
        .header h1 { margin: 0; font-size: 1.4rem; color: var(--accent); display:flex; align-items:center; gap:10px; }
        .btn { padding: 6px 12px; background: #333; color: white; border: 1px solid var(--border); border-radius: 4px; text-decoration: none; cursor: pointer; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 5px; }
        .btn:hover { background: #444; }
        .btn-danger { background: #dc3545; border-color: #dc3545; }
        .btn-danger:hover { background: #bb2d3b; }
        
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px; }
        .card { background: var(--card); padding: 15px; border-radius: 8px; border: 1px solid var(--border); }
        .card h3 { margin: 0 0 15px 0; border-bottom: 1px solid var(--border); padding-bottom: 10px; font-size: 1rem; color: #aaa; }
        
        .stat-row { display: flex; justify-content: space-between; margin-bottom: 8px; border-bottom: 1px solid #252525; padding-bottom: 5px; }
        .stat-row:last-child { border: none; }
        .val { font-family: monospace; font-weight: bold; color: var(--accent); }
        
        .progress { background: #333; height: 8px; border-radius: 4px; overflow: hidden; margin-top: 10px; }
        .bar { height: 100%; background: var(--accent); }
        
        .log-box { background: #000; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 11px; white-space: pre-wrap; height: 200px; overflow-y: auto; border: 1px solid #333; color: #0f0; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fas fa-chart-line"></i> System Monitor</h1>
        <div style="display:flex; gap:10px;">
            <a href="index.php" class="btn"><i class="fas fa-th"></i> Dashboard</a>
            <a href="filemanager.php" class="btn"><i class="fas fa-folder"></i> Files</a>
            <a href="adminer.php" class="btn"><i class="fas fa-database"></i> DB</a>
            <a href="?logout=1" class="btn btn-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="grid">
        <!-- DISK USAGE -->
        <div class="card">
            <h3><i class="fas fa-hdd"></i> Disk Storage</h3>
            <div class="stat-row"><span>Total</span> <span class="val"><?= format_size_safe($dt) ?></span></div>
            <div class="stat-row"><span>Used</span> <span class="val"><?= format_size_safe($du) ?></span></div>
            <div class="stat-row"><span>Free</span> <span class="val"><?= format_size_safe($df) ?></span></div>
            <div style="margin-top:10px; font-size:12px; text-align:right;"><?= $dp ?>% Used</div>
            <div class="progress"><div class="bar" style="width: <?= $dp ?>%; background: <?= $dp > 90 ? '#dc3545' : '#0dcaf0' ?>"></div></div>
        </div>

        <!-- PHP INFO -->
        <div class="card">
            <h3><i class="fab fa-php"></i> PHP Environment</h3>
            <div class="stat-row"><span>Version</span> <span class="val"><?= phpversion() ?></span></div>
            <div class="stat-row"><span>Memory Limit</span> <span class="val"><?= ini_get('memory_limit') ?></span></div>
            <div class="stat-row"><span>Max Upload</span> <span class="val"><?= ini_get('upload_max_filesize') ?></span></div>
            <div class="stat-row"><span>Max Exec Time</span> <span class="val"><?= ini_get('max_execution_time') ?>s</span></div>
            <div class="stat-row"><span>Post Max Size</span> <span class="val"><?= ini_get('post_max_size') ?></span></div>
        </div>
    </div>

    <!-- LOGS -->
    <div class="card" style="margin-top: 20px;">
        <h3><i class="fas fa-file-alt"></i> Log Detector</h3>
        <?php if(empty($found_logs)): ?>
            <div style="text-align:center; padding:20px; color:#666;">No error logs found in current directory.</div>
        <?php else: ?>
            <?php foreach($found_logs as $file => $data):
                // Ensure the file name is safe before using it in the form
                $safe_file = htmlspecialchars($file);
            ?>
                <div style="margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                        <strong><?= $safe_file ?> (<?= $data['size'] ?>)</strong>
                        <form method="POST" onsubmit="return confirm('Clear log?');" style="display:inline;">
                            <input type="hidden" name="clear_log" value="<?= $safe_file ?>">
                            <button class="btn btn-danger" style="padding:2px 8px; font-size:10px;">Clear</button>
                        </form>
                    </div>
                    <div class="log-box"><?= htmlspecialchars($data['content']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <div style="text-align:center; margin-top:20px; color:#444; font-size:11px;">
        Host: <?= $_SERVER['HTTP_HOST'] ?> | IP: <?= $_SERVER['SERVER_ADDR'] ?>
    </div>
</div>

</body>
</html>