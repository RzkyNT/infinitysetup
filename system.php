<?php
/**
 * System Administration, Terminal, Process Manager & Network Utilities
 */
ob_start();
// Prevent PHP warnings/deprecated notices from corrupting JSON responses
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/index.php';

// RBAC Check for System Tools
if (!has_permission('system', 'read')) {
    die("<div style='background:#1e293b; color:#f87171; padding:2rem; text-align:center; height:100vh; font-family:Inter, sans-serif; display:flex; flex-direction:column; align-items:center; justify-content:center;'>
            <i class='fas fa-lock' style='font-size:4rem; margin-bottom:1rem;'></i>
            <h1 style='margin:0'>Access Denied</h1>
            <p style='color:#94a3b8'>You do not have permission to access System Utilities.</p>
            <a href='?logout=1' style='color:#0dcaf0; text-decoration:none; margin-top:1rem; font-weight:600'>Switch Account</a>
         </div>");
}

// Set up Current Working Directory session
if (!isset($_SESSION['terminal_cwd']) || empty($_SESSION['terminal_cwd'])) {
    $_SESSION['terminal_cwd'] = str_replace('\\', '/', __DIR__);
}

$isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

// Helper to execute commands safely
function execute_command($cmd, $cwd) {
    global $isWindows;
    
    // Set up process descriptors
    $descriptors = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"]  // stderr
    ];
    
    $runCmd = $cmd;
    if ($isWindows) {
        // Alias ls to dir on Windows if command starts with ls
        if (preg_match('/^ls(\s+.*)?$/i', trim($cmd), $matches)) {
            $args = $matches[1] ?? '';
            // Strip -la, -l, -a flags commonly used in linux ls
            $args = preg_replace('/-[a-zA-Z]+/', '', $args);
            $runCmd = 'dir ' . trim($args);
        }
        $runCmd = 'cmd.exe /c ' . $runCmd;
    }
    
    $process = proc_open($runCmd, $descriptors, $pipes, $cwd);
    $output = "";
    
    if (is_resource($process)) {
        fclose($pipes[0]);
        
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        
        proc_close($process);
        
        $output = $stdout;
        if (!empty($stderr)) {
            $output .= ($output ? "\n" : "") . "[Error]\n" . $stderr;
        }
    } else {
        $output = "Error: Failed to open process execution.";
    }
    
    return $output;
}

// ----------------------------------------------------
// AJAX API ENDPOINTS
// ----------------------------------------------------
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    // 1. Run Terminal Command
    if ($_GET['api'] === 'terminal_execute') {
        $command = trim($_POST['command'] ?? '');
        $cwd = $_SESSION['terminal_cwd'];
        
        if (empty($command)) {
            echo json_encode(['output' => '', 'cwd' => $cwd]);
            exit;
        }
        
        // Handle "cd" command logic in PHP to persist path
        $isCd = preg_match('/^cd(\.[\.\\\/]*|[\\/].*|\s+.*)?$/i', $command, $cdMatch) ||
                ($isWindows && preg_match('/^[A-Za-z]:[\\/\\\\]?$/', $command));
        
        if ($isCd) {
            if ($isWindows && preg_match('/^[A-Za-z]:[\\/\\\\]?$/', $command)) {
                $targetDir = rtrim($command, '/\\') . '\\';
            } elseif (preg_match('/^cd\.\.(.*)$/i', $command, $m)) {
                $targetDir = '..' . $m[1];
            } elseif (preg_match('/^cd[\\/\\\\](.*)$/i', $command, $m)) {
                $targetDir = '/' . $m[1];
            } else {
                $parts = preg_split('/\s+/', $command, 2);
                $targetDir = isset($parts[1]) ? trim($parts[1]) : '';
            }
            
            if (empty($targetDir)) {
                // bare "cd" => go to project root
                $newCwd = str_replace('\\', '/', __DIR__);
                $_SESSION['terminal_cwd'] = $newCwd;
                echo json_encode(['output' => "Switched to: $newCwd\n", 'cwd' => $newCwd]);
                exit;
            }
            
            // Normalize: replace forward slashes with backslashes on Windows for realpath
            $targetDirNormalized = str_replace('/', '\\', $targetDir);
            
            if ($targetDir === '~') {
                $resolvedPath = getenv('USERPROFILE') ?: getenv('HOME') ?: __DIR__;
            } elseif ($isWindows && preg_match('/^[A-Za-z]:\\\\?$/', $targetDirNormalized)) {
                $resolvedPath = rtrim($targetDirNormalized, '\\') . '\\';
            } elseif ($isWindows && preg_match('/^[A-Za-z]:/', $targetDirNormalized)) {
                $resolvedPath = realpath($targetDirNormalized);
            } elseif (substr($targetDirNormalized, 0, 1) === '\\' || substr($targetDirNormalized, 0, 1) === '/') {
                $driveLetter = substr(str_replace('/', '\\', $cwd), 0, 2);
                $resolvedPath = realpath($driveLetter . $targetDirNormalized);
            } else {
                $cwdNative = str_replace('/', '\\', $cwd);
                $resolvedPath = realpath($cwdNative . '\\' . $targetDirNormalized);
            }
            
            if ($resolvedPath && is_dir($resolvedPath)) {
                $newCwd = str_replace('\\', '/', $resolvedPath);
                $_SESSION['terminal_cwd'] = $newCwd;
                echo json_encode(['output' => "Switched to: $newCwd\n", 'cwd' => $newCwd]);
            } else {
                echo json_encode(['output' => "cd: The system cannot find the path specified: '$targetDir'\n", 'cwd' => $cwd]);
            }
            exit;
        }
        
        // General command execution
        $output = execute_command($command, str_replace('/', '\\', $cwd));
        echo json_encode(['output' => $output, 'cwd' => $_SESSION['terminal_cwd']]);
        exit;
    }

    // 2. Get System Telemetry
    if ($_GET['api'] === 'get_telemetry') {
        // Disk Usage
        $totalDisk = disk_total_space(__DIR__);
        $freeDisk = disk_free_space(__DIR__);
        $usedDisk = $totalDisk - $freeDisk;
        $diskPercentage = $totalDisk > 0 ? round(($usedDisk / $totalDisk) * 100, 1) : 0;
        
        // RAM (Memory) usage of PHP
        $memLimit = ini_get('memory_limit');
        $memUsed = memory_get_usage(true);
        
        // System RAM & CPU (OS specific fallback)
        $ramTotal = 'Unknown';
        $ramUsed = 'Unknown';
        $ramFree = 'Unknown';
        $ramPercentage = 0;
        
        $cpuUsage = 0;
        
        if ($isWindows) {
            // RAM info
            $wmiRam = shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /Value');
            if ($wmiRam) {
                preg_match('/FreePhysicalMemory=(\d+)/', $wmiRam, $freeMatches);
                preg_match('/TotalVisibleMemorySize=(\d+)/', $wmiRam, $totalMatches);
                if (isset($freeMatches[1]) && isset($totalMatches[1])) {
                    $totalKb = (float)$totalMatches[1];
                    $freeKb = (float)$freeMatches[1];
                    $usedKb = $totalKb - $freeKb;
                    
                    $ramTotal = round($totalKb / (1024 * 1024), 2) . ' GB';
                    $ramFree = round($freeKb / (1024 * 1024), 2) . ' GB';
                    $ramUsed = round($usedKb / (1024 * 1024), 2) . ' GB';
                    $ramPercentage = round(($usedKb / $totalKb) * 100, 1);
                }
            }
            
            // CPU info
            $wmiCpu = shell_exec('wmic cpu get LoadPercentage /Value');
            if ($wmiCpu && preg_match('/LoadPercentage=(\d+)/', $wmiCpu, $matches)) {
                $cpuUsage = (int)$matches[1];
            }
        } else {
            // Linux RAM info
            if (file_exists('/proc/meminfo')) {
                $meminfo = file_get_contents('/proc/meminfo');
                preg_match('/MemTotal:\s+(\d+)/', $meminfo, $totalMatches);
                preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $availMatches);
                if (isset($totalMatches[1]) && isset($availMatches[1])) {
                    $totalKb = (float)$totalMatches[1];
                    $availKb = (float)$availMatches[1];
                    $usedKb = $totalKb - $availKb;
                    
                    $ramTotal = round($totalKb / (1024 * 1024), 2) . ' GB';
                    $ramFree = round($availKb / (1024 * 1024), 2) . ' GB';
                    $ramUsed = round($usedKb / (1024 * 1024), 2) . ' GB';
                    $ramPercentage = round(($usedKb / $totalKb) * 100, 1);
                }
            }
            
            // Linux CPU usage
            if (file_exists('/proc/stat')) {
                $stat1 = file_get_contents('/proc/stat');
                usleep(100000); // 100ms
                $stat2 = file_get_contents('/proc/stat');
                
                $info1 = explode("\n", $stat1)[0];
                $info2 = explode("\n", $stat2)[0];
                
                $cpu1 = array_slice(array_filter(explode(" ", $info1)), 1);
                $cpu2 = array_slice(array_filter(explode(" ", $info2)), 1);
                
                $total1 = array_sum($cpu1);
                $total2 = array_sum($cpu2);
                
                $idle1 = $cpu1[3] + ($cpu1[4] ?? 0);
                $idle2 = $cpu2[3] + ($cpu2[4] ?? 0);
                
                $diffTotal = $total2 - $total1;
                $diffIdle = $idle2 - $idle1;
                
                if ($diffTotal > 0) {
                    $cpuUsage = round((($diffTotal - $diffIdle) / $diffTotal) * 100, 1);
                }
            }
        }
        
        echo json_encode([
            'disk' => [
                'total' => round($totalDisk / (1024*1024*1024), 2) . ' GB',
                'used' => round($usedDisk / (1024*1024*1024), 2) . ' GB',
                'free' => round($freeDisk / (1024*1024*1024), 2) . ' GB',
                'percentage' => $diskPercentage
            ],
            'ram' => [
                'total' => $ramTotal,
                'used' => $ramUsed,
                'free' => $ramFree,
                'percentage' => $ramPercentage
            ],
            'cpu' => [
                'percentage' => $cpuUsage
            ],
            'php_memory' => [
                'used' => round($memUsed / (1024 * 1024), 2) . ' MB',
                'limit' => $memLimit
            ]
        ]);
        exit;
    }
    
    // 3. Process Manager List
    if ($_GET['api'] === 'process_list') {
        $processes = [];
        
        if ($isWindows) {
            // Parse tasklist output
            // Formatted as CSV: Image Name, PID, Session Name, Session#, Mem Usage
            $cmd = 'tasklist /FO CSV /NH';
            $raw = execute_command($cmd, __DIR__);
            $lines = explode("\n", trim($raw));
            
            foreach ($lines as $line) {
                // Supply all default arguments to avoid PHP 8.4+ deprecation warnings regarding escape char defaults
                $data = str_getcsv(trim($line), ',', '"', "\\");
                if (count($data) >= 5) {
                    $processes[] = [
                        'name' => $data[0],
                        'pid' => $data[1],
                        'session' => $data[2],
                        'mem' => $data[4],
                        'user' => 'N/A'
                    ];
                }
            }
        } else {
            // Linux ps aux output
            // USER PID %CPU %MEM VSZ RSS TTY STAT START TIME COMMAND
            $cmd = 'ps aux';
            $raw = execute_command($cmd, __DIR__);
            $lines = explode("\n", trim($raw));
            
            // Skip the header
            for ($i = 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if (empty($line)) continue;
                
                $parts = preg_split('/\s+/', $line, 11);
                if (count($parts) >= 11) {
                    $processes[] = [
                        'name' => basename($parts[10]),
                        'pid' => $parts[1],
                        'user' => $parts[0],
                        'mem' => round($parts[5] / 1024, 2) . ' MB',
                        'session' => 'CPU: ' . $parts[2] . '% | MEM: ' . $parts[3] . '%'
                    ];
                }
            }
        }
        
        echo json_encode(['processes' => $processes]);
        exit;
    }
    
    // 4. Kill Process
    if ($_GET['api'] === 'process_kill') {
        $pid = (int)($_POST['pid'] ?? 0);
        if ($pid <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid Process ID']);
            exit;
        }
        
        if ($isWindows) {
            $cmd = "taskkill /F /PID $pid";
        } else {
            $cmd = "kill -9 $pid";
        }
        
        $output = execute_command($cmd, __DIR__);
        echo json_encode(['success' => true, 'output' => trim($output)]);
        exit;
    }
    
    // 5. Network Utility Tool
    if ($_GET['api'] === 'network_tool') {
        $tool = $_POST['tool'] ?? '';
        $target = escapeshellarg($_POST['target'] ?? '');
        $output = '';
        
        if (empty($tool) || empty($target)) {
            echo json_encode(['output' => 'Invalid parameters.']);
            exit;
        }
        
        switch ($tool) {
            case 'ping':
                $countFlag = $isWindows ? '-n 4' : '-c 4';
                $cmd = "ping $countFlag $target";
                $output = execute_command($cmd, __DIR__);
                break;
                
            case 'nslookup':
                $cmd = "nslookup $target";
                $output = execute_command($cmd, __DIR__);
                break;
                
            case 'port_check':
                $port = (int)($_POST['port'] ?? 80);
                $cleanTarget = $_POST['target'] ?? '';
                
                $start = microtime(true);
                $fp = @fsockopen($cleanTarget, $port, $errno, $errstr, 4);
                $duration = round((microtime(true) - $start) * 1000, 2);
                
                if ($fp) {
                    fclose($fp);
                    $output = "Port $port is OPEN on $cleanTarget (Latency: {$duration}ms)";
                } else {
                    $output = "Port $port is CLOSED on $cleanTarget\nError details: [$errno] $errstr";
                }
                break;
                
            default:
                $output = 'Unknown utility.';
        }
        
        echo json_encode(['output' => trim($output)]);
        exit;
    }
    
    // 6. Active Network Connections
    if ($_GET['api'] === 'netstat_list') {
        $connections = [];
        $cmd = $isWindows ? 'netstat -an' : 'netstat -an';
        $raw = execute_command($cmd, __DIR__);
        $lines = explode("\n", trim($raw));
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Basic parsing of netstat output lines
            // Protocol  Local Address          Foreign Address        State
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 4) {
                // Check if the first column is TCP/UDP
                $proto = strtoupper($parts[0]);
                if (in_array($proto, ['TCP', 'UDP'])) {
                    $connections[] = [
                        'proto' => $proto,
                        'local' => $parts[1],
                        'foreign' => $parts[2],
                        'state' => $parts[3] ?? 'LISTEN'
                    ];
                }
            }
        }
        
        echo json_encode(['connections' => $connections, 'raw' => $raw]);
        exit;
    }
    
    // 7. Live Logs Reader
    if ($_GET['api'] === 'read_logs') {
        $logType = $_GET['log_type'] ?? '';
        $output = '';
        
        if ($logType === 'api') {
            $logFile = __DIR__ . '/api_logs.json';
        } elseif ($logType === 'scheduler') {
            $logFile = __DIR__ . '/scheduler_logs.json';
        } else {
            $logFile = ini_get('error_log');
        }
        
        if ($logFile && file_exists($logFile) && is_readable($logFile)) {
            if (pathinfo($logFile, PATHINFO_EXTENSION) === 'json') {
                $data = json_decode(file_get_contents($logFile), true);
                if (is_array($data)) {
                    $output = json_encode($data, JSON_PRETTY_PRINT);
                } else {
                    $output = file_get_contents($logFile);
                }
            } else {
                // Read last 100 lines for standard text logs
                $file = escapeshellarg($logFile);
                if ($isWindows) {
                    $output = execute_command("powershell -Command \"Get-Content $file -Tail 100\"", __DIR__);
                } else {
                    $output = execute_command("tail -n 100 $file", __DIR__);
                }
            }
        } else {
            $output = "Log file is empty or not readable: " . ($logFile ?: 'No path configured');
        }
        
        echo json_encode(['output' => $output]);
        exit;
    }
}

// Load static values
$os_version = php_uname();
$php_version = PHP_VERSION;
$server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System & Terminal - Infinity Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Local fallbacks used if available, otherwise CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg: #0f0f0f;
            --card: #161616;
            --card-border: #262626;
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --primary: #0dcaf0;
            --primary-hover: #0baccd;
            --dark-terminal: #0a0a0a;
        }
        
        body {
            background-color: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .navbar {
            background: var(--card);
            border-bottom: 1px solid var(--card-border);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar-brand {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .navbar-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .user-badge {
            font-size: 0.85rem;
            color: var(--text-muted);
            background: rgba(255, 255, 255, 0.05);
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-portal {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--card-border);
            color: var(--text);
            font-size: 0.9rem;
            padding: 0.4rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .btn-portal:hover {
            background: var(--primary);
            color: black;
            border-color: var(--primary);
        }
        
        .container-fluid {
            flex: 1;
            padding: 2rem;
        }
        
        /* Grid Telemetry Cards */
        .telemetry-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .telemetry-card {
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .telemetry-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
        }
        
        .telemetry-icon {
            font-size: 2.25rem;
            color: var(--primary);
            opacity: 0.8;
        }
        
        .telemetry-info {
            flex: 1;
        }
        
        .telemetry-title {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .telemetry-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .progress {
            height: 6px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            overflow: hidden;
        }
        
        .progress-bar {
            background-color: var(--primary);
            transition: width 0.5s ease-in-out;
        }
        
        /* Main Workspace & Tabs */
        .workspace-card {
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
        }
        
        .nav-tabs {
            border-bottom: 1px solid var(--card-border);
            background: rgba(255, 255, 255, 0.02);
            padding: 0.5rem 1.5rem 0;
        }
        
        .nav-link {
            color: var(--text-muted);
            border: none !important;
            padding: 0.75rem 1.25rem;
            font-weight: 500;
            border-radius: 8px 8px 0 0 !important;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .nav-link:hover {
            color: var(--text);
            background: rgba(255, 255, 255, 0.03);
        }
        
        .nav-link.active {
            color: var(--primary) !important;
            background: var(--card) !important;
            border-bottom: 2px solid var(--primary) !important;
        }
        
        .tab-content {
            padding: 2rem;
            min-height: 480px;
        }
        
        /* Retro Console Terminal */
        .terminal-container {
            background-color: var(--dark-terminal);
            border: 1px solid #333;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.8);
            font-family: 'Courier New', Courier, monospace;
        }
        
        .terminal-header {
            background: #1c1c1c;
            padding: 0.6rem 1rem;
            border-bottom: 1px solid #333;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .terminal-dots {
            display: flex;
            gap: 6px;
        }
        
        .terminal-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .dot-red { background: #ff5f56; }
        .dot-yellow { background: #ffbd2e; }
        .dot-green { background: #27c93f; }
        
        .terminal-title-text {
            color: var(--text-muted);
            font-size: 0.8rem;
        }
        
        .terminal-body {
            height: 380px;
            padding: 1rem;
            overflow-y: auto;
            color: #33ff33; /* Classic retro terminal color */
            font-size: 0.95rem;
            white-space: pre-wrap;
            scroll-behavior: smooth;
        }
        
        .terminal-input-row {
            display: flex;
            align-items: center;
            background-color: rgba(0, 0, 0, 0.5);
            padding: 0.5rem 1rem;
            border-top: 1px solid #222;
        }
        
        .terminal-prompt {
            color: #0dcaf0;
            margin-right: 8px;
            font-weight: bold;
        }
        
        .terminal-input {
            flex: 1;
            background: transparent;
            border: none;
            color: #fff;
            font-family: inherit;
            font-size: 0.95rem;
            outline: none;
        }
        
        /* Custom elements for lists/processes */
        .process-table th {
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            border-bottom-width: 1px;
        }
        
        .process-table td {
            vertical-align: middle;
        }
        
        .btn-kill {
            padding: 0.25rem 0.6rem;
            font-size: 0.8rem;
        }
        
        .network-result-card {
            background-color: var(--dark-terminal);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            padding: 1rem;
            font-family: monospace;
            color: #a8ffb2;
            min-height: 120px;
            white-space: pre-wrap;
        }
        
        /* Custom Scrollbars */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #111;
        }
        ::-webkit-scrollbar-thumb {
            background: #333;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #444;
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <a href="index.php" class="navbar-brand">
            <i class="fas fa-infinity" style="color: var(--primary)"></i> Infinity Setup
        </a>
        <div class="navbar-actions">
            <span class="user-badge">
                <i class="fas fa-user-circle"></i> 
                <?= htmlspecialchars($_SESSION['username'] ?? 'Guest') ?>
            </span>
            <a href="index.php" class="btn-portal"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
    </nav>

    <div class="container-fluid">
        <!-- Live Telemetry Row -->
        <div class="telemetry-row">
            <!-- CPU Card -->
            <div class="telemetry-card">
                <i class="fas fa-microchip telemetry-icon"></i>
                <div class="telemetry-info">
                    <div class="telemetry-title">CPU Load</div>
                    <div class="telemetry-value" id="cpu-val">0%</div>
                    <div class="progress">
                        <div class="progress-bar" id="cpu-progress" style="width: 0%"></div>
                    </div>
                </div>
            </div>
            
            <!-- RAM Card -->
            <div class="telemetry-card">
                <i class="fas fa-memory telemetry-icon" style="color: #a855f7"></i>
                <div class="telemetry-info">
                    <div class="telemetry-title">RAM Utilization</div>
                    <div class="telemetry-value" id="ram-val">0 GB / 0 GB</div>
                    <div class="progress">
                        <div class="progress-bar" id="ram-progress" style="width: 0%; background-color: #a855f7"></div>
                    </div>
                </div>
            </div>
            
            <!-- Storage Card -->
            <div class="telemetry-card">
                <i class="fas fa-hdd telemetry-icon" style="color: #10b981"></i>
                <div class="telemetry-info">
                    <div class="telemetry-title">Disk Storage</div>
                    <div class="telemetry-value" id="disk-val">0 GB / 0 GB</div>
                    <div class="progress">
                        <div class="progress-bar" id="disk-progress" style="width: 0%; background-color: #10b981"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workspaces -->
        <div class="workspace-card">
            <!-- Navigation tabs -->
            <ul class="nav nav-tabs" id="systemTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="terminal-tab" data-bs-toggle="tab" data-bs-target="#terminal-pane" type="button" role="tab"><i class="fas fa-terminal"></i> Terminal</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="process-tab" data-bs-toggle="tab" data-bs-target="#process-pane" type="button" role="tab"><i class="fas fa-tasks"></i> Process Manager</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="netstat-tab" data-bs-toggle="tab" data-bs-target="#netstat-pane" type="button" role="tab"><i class="fas fa-ethernet"></i> Connections</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="network-tab" data-bs-toggle="tab" data-bs-target="#network-pane" type="button" role="tab"><i class="fas fa-network-wired"></i> Network Tools</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="logs-tab" data-bs-toggle="tab" data-bs-target="#logs-pane" type="button" role="tab"><i class="fas fa-file-lines"></i> Realtime Logs</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="telemetry-tab-btn" data-bs-toggle="tab" data-bs-target="#telemetry-pane" type="button" role="tab"><i class="fas fa-info-circle"></i> Host Information</button>
                </li>
            </ul>
            
            <!-- Tab Panes -->
            <div class="tab-content">
                <!-- Terminal Pane -->
                <div class="tab-pane fade show active" id="terminal-pane" role="tabpanel" tabindex="0">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0 text-info"><i class="fas fa-terminal"></i> Shell Console</h4>
                        <button class="btn btn-sm btn-outline-secondary" onclick="clearConsole()"><i class="fas fa-trash"></i> Clear Console</button>
                    </div>
                    
                    <div class="terminal-container">
                        <div class="terminal-header">
                            <div class="terminal-dots">
                                <span class="terminal-dot dot-red"></span>
                                <span class="terminal-dot dot-yellow"></span>
                                <span class="terminal-dot dot-green"></span>
                            </div>
                            <div class="terminal-title-text" id="terminal-dir-header"><?= $_SESSION['terminal_cwd'] ?></div>
                        </div>
                        <div class="terminal-body" id="console-body">--- Web Shell Session Started ---
Type commands here. Use 'cd' to change folders.

</div>
                        <div class="terminal-input-row">
                            <span class="terminal-prompt" id="terminal-dir-prompt">> </span>
                            <input type="text" class="terminal-input" id="console-input" placeholder="Type a command..." autofocus autocomplete="off">
                        </div>
                    </div>
                </div>
                
                <!-- Process Manager Pane -->
                <div class="tab-pane fade" id="process-pane" role="tabpanel" tabindex="0">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4><i class="fas fa-tasks text-info"></i> Running Processes</h4>
                        <button class="btn btn-sm btn-outline-info" onclick="refreshProcessList()"><i class="fas fa-rotate"></i> Refresh List</button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-dark table-hover process-table">
                            <thead>
                                <tr>
                                    <th>Process Name</th>
                                    <th>PID</th>
                                    <th>User</th>
                                    <th>Memory / CPU</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="process-table-body">
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Loading processes...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Network Tools Pane -->
                <div class="tab-pane fade" id="network-pane" role="tabpanel" tabindex="0">
                    <h4 class="mb-4 text-info"><i class="fas fa-network-wired"></i> Network Utilities</h4>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card bg-dark text-light border-secondary p-3 mb-3">
                                <h5 class="card-title text-info"><i class="fas fa-toolbox"></i> Execute Utility</h5>
                                <hr class="border-secondary">
                                <form id="network-form">
                                    <div class="mb-3">
                                        <label class="form-label text-muted">Select Tool</label>
                                        <select class="form-select bg-black text-light border-secondary" id="net-tool">
                                            <option value="ping">Ping host</option>
                                            <option value="nslookup">DNS lookup (nslookup)</option>
                                            <option value="port_check">TCP Port Checker</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-muted" id="target-label">Target Host/IP</label>
                                        <input type="text" class="form-control bg-black text-light border-secondary" id="net-target" placeholder="e.g. google.com" required>
                                    </div>
                                    <div class="mb-3 d-none" id="port-group">
                                        <label class="form-label text-muted">Port Number</label>
                                        <input type="number" class="form-control bg-black text-light border-secondary" id="net-port" value="80">
                                    </div>
                                    <button type="submit" class="btn btn-info w-100 mt-2 text-dark font-weight-bold" id="net-submit-btn">Run Tool</button>
                                </form>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <h5 class="text-muted"><i class="fas fa-terminal"></i> Execution Output</h5>
                            <div class="network-result-card" id="network-output">Select a network tool from the left and run it to view output.</div>
                        </div>
                    </div>
                </div>
                
                <!-- Connections Pane -->
                <div class="tab-pane fade" id="netstat-pane" role="tabpanel" tabindex="0">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4><i class="fas fa-ethernet text-info"></i> Active Network Connections</h4>
                        <div>
                            <button class="btn btn-sm btn-outline-secondary me-2" id="btn-toggle-raw-netstat" onclick="toggleRawNetstat()">Show Raw Text</button>
                            <button class="btn btn-sm btn-outline-info" onclick="refreshNetstat()"><i class="fas fa-rotate"></i> Refresh Connections</button>
                        </div>
                    </div>
                    
                    <div id="netstat-parsed-view">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover">
                                <thead>
                                    <tr>
                                        <th>Protocol</th>
                                        <th>Local Address</th>
                                        <th>Foreign Address</th>
                                        <th>State</th>
                                    </tr>
                                </thead>
                                <tbody id="netstat-table-body">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Loading connections...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div id="netstat-raw-view" class="d-none">
                        <div class="network-result-card" id="netstat-raw-output" style="max-height: 400px; overflow-y: auto;"></div>
                    </div>
                </div>

                <!-- Realtime Logs Pane -->
                <div class="tab-pane fade" id="logs-pane" role="tabpanel" tabindex="0">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4><i class="fas fa-file-lines text-info"></i> System Logs Monitor</h4>
                        <div class="d-flex align-items-center gap-3">
                            <div class="form-check form-switch text-muted">
                                <input class="form-check-input" type="checkbox" role="switch" id="log-auto-refresh">
                                <label class="form-check-label" for="log-auto-refresh">Auto-Refresh (3s)</label>
                            </div>
                            <select class="form-select form-select-sm bg-black text-light border-secondary" id="log-select-type" style="width: 200px;" onchange="loadLogs()">
                                <option value="api">API Logs (api_logs.json)</option>
                                <option value="scheduler">Scheduler/Backup Logs (scheduler_logs.json)</option>
                                <option value="php_error">PHP Error Log</option>
                            </select>
                            <button class="btn btn-sm btn-outline-info" onclick="loadLogs()"><i class="fas fa-rotate"></i> Reload</button>
                        </div>
                    </div>
                    
                    <div class="network-result-card" id="logs-output-box" style="height: 380px; overflow-y: auto; background-color: #050505; color: #ffeb3b; font-family: monospace; font-size: 0.9rem;"></div>
                </div>
                
                <!-- Host Information Pane -->
                <div class="tab-pane fade" id="telemetry-pane" role="tabpanel" tabindex="0">
                    <h4 class="mb-4 text-info"><i class="fas fa-info-circle"></i> Host & Environment Telemetry</h4>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-dark border-secondary">
                                <tbody>
                                    <tr>
                                        <td class="text-info" style="width: 35%">Operating System</td>
                                        <td><?= htmlspecialchars($os_version) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-info">Host OS Type</td>
                                        <td><?= $isWindows ? 'Windows Server/Desktop' : 'Linux / Unix OS' ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-info">PHP Version</td>
                                        <td><?= htmlspecialchars($php_version) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-info">Server Software</td>
                                        <td><?= htmlspecialchars($server_software) ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-info">Max Execution Time</td>
                                        <td><?= ini_get('max_execution_time') ?> seconds</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-dark border-secondary">
                                <tbody>
                                    <tr>
                                        <td class="text-info" style="width: 35%">Disabled Functions</td>
                                        <td style="word-break: break-all; font-family: monospace; font-size: 0.85rem;">
                                            <?= ini_get('disable_functions') ?: '<span class="text-success">None</span>' ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="text-info">Safe Mode</td>
                                        <td><?= ini_get('safe_mode') ? 'ON' : 'OFF' ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-info">Upload Max Filesize</td>
                                        <td><?= ini_get('upload_max_filesize') ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-info">POST Max Size</td>
                                        <td><?= ini_get('post_max_size') ?></td>
                                    </tr>
                                    <tr>
                                        <td class="text-info">Active Session path</td>
                                        <td style="font-family: monospace; font-size: 0.85rem;"><?= htmlspecialchars(session_save_path() ?: '/tmp') ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Store session CWD
        let currentCwd = '<?= addslashes($_SESSION['terminal_cwd']) ?>';
        
        // Tab elements
        document.getElementById('terminal-tab').addEventListener('shown.bs.tab', function () {
            document.getElementById('console-input').focus();
        });
        
        document.getElementById('process-tab').addEventListener('shown.bs.tab', function () {
            refreshProcessList();
        });
        
        // Tool parameter changer
        document.getElementById('net-tool').addEventListener('change', function() {
            const tool = this.value;
            const targetLabel = document.getElementById('target-label');
            const portGroup = document.getElementById('port-group');
            
            if (tool === 'port_check') {
                targetLabel.textContent = 'Target IP/Domain';
                portGroup.classList.remove('d-none');
            } else {
                targetLabel.textContent = tool === 'ping' ? 'Target Host/IP' : 'Domain / Server';
                portGroup.classList.add('d-none');
            }
        });
        
        // ----------------------------------------------------
        // LIVE TELEMETRY UPDATE
        // ----------------------------------------------------
        function updateTelemetry() {
            fetch('?api=get_telemetry')
                .then(res => res.json())
                .then(data => {
                    // Update CPU
                    document.getElementById('cpu-val').innerText = data.cpu.percentage + '%';
                    document.getElementById('cpu-progress').style.width = data.cpu.percentage + '%';
                    
                    // Update RAM
                    if (data.ram.total !== 'Unknown') {
                        document.getElementById('ram-val').innerText = data.ram.used + ' / ' + data.ram.total;
                        document.getElementById('ram-progress').style.width = data.ram.percentage + '%';
                    } else {
                        document.getElementById('ram-val').innerText = 'PHP RAM: ' + data.php_memory.used;
                        document.getElementById('ram-progress').style.width = '10%';
                    }
                    
                    // Update Disk
                    document.getElementById('disk-val').innerText = data.disk.used + ' / ' + data.disk.total;
                    document.getElementById('disk-progress').style.width = data.disk.percentage + '%';
                })
                .catch(err => console.error("Telemetry error:", err));
        }
        
        // Run telemetry update every 5 seconds
        updateTelemetry();
        setInterval(updateTelemetry, 5000);
        
        // ----------------------------------------------------
        // WEB CONSOLE TERMINAL
        // ----------------------------------------------------
        const consoleBody = document.getElementById('console-body');
        const consoleInput = document.getElementById('console-input');
        const promptLabel = document.getElementById('terminal-dir-prompt');
        const headerDir = document.getElementById('terminal-dir-header');
        
        // Initialize CWD display
        function updateCwdDisplays(path) {
            currentCwd = path;
            headerDir.innerText = path;
            promptLabel.innerText = path + ' > ';
            consoleBody.scrollTop = consoleBody.scrollHeight;
        }
        updateCwdDisplays(currentCwd);
        
        function clearConsole() {
            consoleBody.innerHTML = '--- Console Cleared ---\n\n';
            consoleInput.focus();
        }
        
        consoleInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const command = this.value.trim();
                if (command === '') return;
                
                this.value = '';
                
                // Print command to body
                consoleBody.innerHTML += `<span style="color: #fff">${currentCwd} > ${command}</span>\n`;
                consoleBody.scrollTop = consoleBody.scrollHeight;
                
                // Clear console shortcode
                if (command === 'clear' || command === 'cls') {
                    clearConsole();
                    return;
                }
                
                // Post command to API
                const formData = new FormData();
                formData.append('command', command);
                
                fetch('?api=terminal_execute', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.output) {
                        consoleBody.innerHTML += data.output + '\n';
                    }
                    updateCwdDisplays(data.cwd);
                })
                .catch(err => {
                    consoleBody.innerHTML += '<span style="color: #ff5f56">System execution failed to respond.</span>\n';
                    consoleBody.scrollTop = consoleBody.scrollHeight;
                });
            }
        });
        
        // ----------------------------------------------------
        // PROCESS MANAGER
        // ----------------------------------------------------
        function refreshProcessList() {
            const tableBody = document.getElementById('process-table-body');
            tableBody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Refreshing process list...</td></tr>`;
            
            fetch('?api=process_list')
                .then(res => res.json())
                .then(data => {
                    if (data.processes.length === 0) {
                        tableBody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-4">No active processes discovered.</td></tr>`;
                        return;
                    }
                    
                    let html = '';
                    data.processes.forEach(proc => {
                        html += `
                            <tr>
                                <td><i class="fas fa-gear text-muted"></i> <strong>${escapeHtml(proc.name)}</strong></td>
                                <td class="font-monospace text-info">${proc.pid}</td>
                                <td>${escapeHtml(proc.user)}</td>
                                <td>${escapeHtml(proc.mem)} <span class="text-muted small">(${escapeHtml(proc.session)})</span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-danger btn-kill" onclick="killProcess(${proc.pid}, '${escapeHtml(proc.name)}')">
                                        <i class="fas fa-circle-xmark"></i> Kill
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    tableBody.innerHTML = html;
                })
                .catch(err => {
                    tableBody.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">Failed to pull processes. Is shell execution blocked on host?</td></tr>`;
                });
        }
        
        function killProcess(pid, name) {
            if (confirm(`Are you absolutely sure you want to KILL process ${name} (PID: ${pid})?`)) {
                const formData = new FormData();
                formData.append('pid', pid);
                
                fetch('?api=process_kill', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    alert(data.output || 'Kill command dispatched.');
                    refreshProcessList();
                    updateTelemetry();
                })
                .catch(err => alert('Failed to kill process: network error.'));
            }
        }
        
        // ----------------------------------------------------
        // NETWORK UTILITIES
        // ----------------------------------------------------
        document.getElementById('network-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const tool = document.getElementById('net-tool').value;
            const target = document.getElementById('net-target').value;
            const port = document.getElementById('net-port').value;
            const outputBox = document.getElementById('network-output');
            const submitBtn = document.getElementById('net-submit-btn');
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Executing...';
            outputBox.innerHTML = `Querying utility [${tool.toUpperCase()}] for target [${target}]... Please wait.`;
            
            const formData = new FormData();
            formData.append('tool', tool);
            formData.append('target', target);
            formData.append('port', port);
            
            fetch('?api=network_tool', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                outputBox.textContent = data.output || 'No output returned.';
                submitBtn.disabled = false;
                submitBtn.innerText = 'Run Tool';
            })
            .catch(err => {
                outputBox.textContent = 'Error: Failed to fetch query results.';
                submitBtn.disabled = false;
                submitBtn.innerText = 'Run Tool';
            });
        });
        
        // Helper
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
    </script>
</body>
</html>
<?php
ob_end_flush();
?>
