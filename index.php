<?php
/**
 * Unified Authentication and RBAC
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$dbFile = __DIR__ . '/adminer.sqlite';

function get_auth_db() {
    global $dbFile;
    static $db = null;
    if ($db === null) {
        $db = new PDO("sqlite:$dbFile");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Initialize Tables
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE,
            password TEXT,
            role TEXT,
            permissions TEXT,
            must_change_password INTEGER DEFAULT 1
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS settings (
            key_name TEXT PRIMARY KEY,
            value_data TEXT
        )");

        // Seed default admin if no users exist
        $stmt = $db->query("SELECT COUNT(*) FROM users");
        if ($stmt->fetchColumn() == 0) {
            $pass = password_hash('admin123', PASSWORD_DEFAULT);
            $perms = json_encode(['filemanager' => 'full', 'database' => 'full']);
            $db->prepare("INSERT INTO users (username, password, role, permissions, must_change_password) VALUES (?, ?, ?, ?, 1)")
               ->execute(['admin', $pass, 'admin', $perms]);
        }
    }
    return $db;
}

function is_authenticated() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['username']);
}

function get_user_role() {
    return $_SESSION['role'] ?? 'guest';
}

function has_permission($app, $min_level = 'read') {
    if (get_user_role() === 'admin') return true;
    $perms = $_SESSION['permissions'] ?? [];
    if (!isset($perms[$app])) return false;
    
    $levels = ['none' => 0, 'read' => 1, 'write' => 2, 'full' => 3];
    return ($levels[$perms[$app]] ?? 0) >= ($levels[$min_level] ?? 0);
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Handle Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_login'])) {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    
    try {
        $db = get_auth_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$user]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userData && password_verify($pass, $userData['password'])) {
            $_SESSION['user_id'] = $userData['id'];
            $_SESSION['username'] = $userData['username'];
            $_SESSION['role'] = $userData['role'];
            $_SESSION['permissions'] = json_decode($userData['permissions'], true);
            $_SESSION['must_change_password'] = (int)$userData['must_change_password'];
            $_SESSION['portal_logged_in'] = true; // Legacy support
            
            // Load Real DB Credentials from 'settings' table
            $res = $db->query("SELECT value_data FROM settings WHERE key_name = 'user'");
            $row = $res->fetch();
            $_SESSION['db_user'] = $row ? $row['value_data'] : 'root';
            
            $res = $db->query("SELECT value_data FROM settings WHERE key_name = 'pass'");
            $row = $res->fetch();
            $_SESSION['db_pass'] = $row ? $row['value_data'] : '';

            $res = $db->query("SELECT value_data FROM settings WHERE key_name = 'host'");
            $row = $res->fetch();
            $_SESSION['db_host'] = $row ? $row['value_data'] : 'localhost';
            
            // For FileManager Compatibility
            if (!defined('FM_SESSION_ID')) define('FM_SESSION_ID', 'filemanager');
            $_SESSION[FM_SESSION_ID]['logged'] = $userData['username'];
            
            header("Location: " . ($_POST['redirect'] ?? 'index.php'));
            exit;
        } else {
            $login_error = "Invalid username or password";
        }
    } catch (Exception $e) {
        $login_error = "Authentication error: " . $e->getMessage();
    }
}

// Only show login UI if strictly needed by the caller and not authenticated
function show_login_ui($error = null) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - Infinity Portal</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
         :root {
    --primary: #000000ff;          /* Putih bersih untuk aksi utama */
    --primary-hover: #e2e8f0;    /* Abu-abu sangat terang saat hover */
    --bg-dark: #ffffffff;         /* Hitam pekat untuk latar belakang */
    --bg-card: #ffffffff;         /* Hitam sedikit terang untuk kedalaman kartu */
    --text-main: #000000ff;       /* Teks utama putih */
    --text-dim: #737373;        /* Teks sekunder abu-abu sedang */
    --input-bg: #000000ff;        /* Latar input gelap agar tetap terlihat */
}
            body {
                font-family: 'Inter', sans-serif;
                background-color: var(--bg-dark);
                color: var(--text-main);
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                margin: 0;
                overflow: hidden;
            }
            .login-card {
                background: var(--bg-card);
                padding: 2.5rem;
                border-radius: 1.5rem;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
                width: 100%;
                max-width: 400px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                animation: slideIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            }
            @keyframes slideIn {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .header {
                text-align: center;
                margin-bottom: 2rem;
            }
            .header h1 {
                font-weight: 600;
                font-size: 1.5rem;
                margin-bottom: 0.5rem;
            }
            .header p {
                color: var(--text-dim);
                font-size: 0.875rem;
            }
            .form-group {
                margin-bottom: 1.5rem;
            }
            label {
                display: block;
                font-size: 0.875rem;
                font-weight: 500;
                margin-bottom: 0.5rem;
                color: var(--text-dim);
            }
            .input-wrapper {
                position: relative;
            }
            .input-wrapper i {
                position: absolute;
                left: 1rem;
                top: 50%;
                transform: translateY(-50%);
                color: var(--text-dim);
                font-size: 1rem;
            }
            input {
                width: 100%;
                background: var(--input-bg);
                border: 1px solid transparent;
                padding: 0.75rem 1rem 0.75rem 2.75rem;
                border-radius: 0.75rem;
                color: white;
                font-family: inherit;
                box-sizing: border-box;
                transition: all 0.2s;
            }
            input:focus {
                outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
            }
            .btn {
                width: 100%;
                background: var(--primary);
                color: white;
                border: none;
                padding: 0.75rem;
                border-radius: 0.75rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s;
                margin-top: 1rem;
            }
            .btn:hover {
                background: var(--primary-hover);
                transform: translateY(-1px);
            }
            .error-msg {
                background: rgba(239, 68, 68, 0.1);
                color: #f87171;
                padding: 0.75rem;
                border-radius: 0.75rem;
                font-size: 0.875rem;
                margin-bottom: 1.5rem;
                border: 1px solid rgba(239, 68, 68, 0.2);
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="login-card">
            <div class="header">
                <div style="font-size: 3rem; color: var(--primary); margin-bottom: 1rem;">
                    <i class="fas fa-infinity"></i>
                </div>
                <h1>System Login</h1>
                <p>Infinity Setup - Secure Access</p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-msg"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="auth_login" value="1">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" placeholder="Enter username" required autofocus>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" placeholder="••••••••" required>
                    </div>
                </div>
                
                <button type="submit" class="btn">Sign In</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

// Authentication Check and Routing
$current_page = basename($_SERVER['PHP_SELF']);
if (!is_authenticated()) {
    if ($current_page === 'index.php') {
        show_login_ui($login_error ?? null);
    } else {
        header("Location: index.php");
    }
    exit;
}

// Force password change redirect
if (isset($_SESSION['must_change_password']) && $_SESSION['must_change_password'] == 1 && $current_page !== 'index.php') {
    header("Location: index.php");
    exit;
}

// Only show dashboard tool logic if we are directly on index.php
if ($current_page === 'index.php') {
    // Load tools config
    $tools = [
        'filemanager.php' => ['icon' => 'fa-folder-open', 'label' => 'File Manager', 'color' => '#ffc107'],
        'adminer.php' => ['icon' => 'fa-database', 'label' => 'Database', 'color' => '#28a745'],
    ];

    $updateAlert = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_action'])) {
        $linkFileUrl = "https://raw.githubusercontent.com/RzkyNT/infinitysetup/refs/heads/main/link.txt";
        
        $context = stream_context_create([
            "http" => ["header" => "User-Agent: PHP\r\n"]
        ]);

        $links = @file_get_contents($linkFileUrl, false, $context);
        
        if ($links !== false) {
            $urls = array_filter(array_map('trim', explode("\n", $links)));
            $success_count = 0;
            $errors = [];

            foreach ($urls as $url) {
                if (empty($url)) continue;
                $filename = basename($url);
                $content = @file_get_contents($url, false, $context);
                
                if ($content !== false) {
                    if (@file_put_contents(__DIR__ . '/' . $filename, $content) !== false) {
                        $success_count++;
                    } else {
                        $errors[] = "Failed to write $filename";
                    }
                } else {
                    $errors[] = "Failed to download $url";
                }
            }
            
            if ($success_count > 0) {
                $_SESSION['update_msg'] = "Successfully updated $success_count files.";
                if (!empty($errors)) {
                    $_SESSION['update_msg'] .= " Errors: " . implode(", ", $errors);
                }
                header("Location: index.php?updated=1");
                exit;
            } else {
                $updateAlert = "Update failed: " . implode(", ", $errors);
            }
        } else {
            $updateAlert = "Failed to fetch update list from GitHub.";
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
        $new_pass = $_POST['new_password'] ?? '';
        if ($new_pass) {
            $db = get_auth_db();
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
            $stmt->execute([$hash, $_SESSION['user_id']]);
            $_SESSION['must_change_password'] = 0;
            header("Location: index.php?password_changed=1");
            exit;
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Infinity Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --bg: #0f0f0f; --card: #1a1a1a; --text: #e0e0e0; --hover: #252525; --primary: #6366f1; }
        body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; flex-direction: column; }
        .navbar { background: var(--card); padding: 1rem 2rem; border-bottom: 1px solid #333; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 1.25rem; font-weight: bold; display: flex; align-items: center; gap: 10px; }
        .actions { display: flex; gap: 0.75rem; align-items: center; }
        .logout { color: #ff6b6b; text-decoration: none; font-size: 0.9rem; display: flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 4px; transition: 0.2s; }
        .logout:hover { background: rgba(255, 107, 107, 0.1); }
        .btn-update { background: #0d6efd; color: #fff; border: none; border-radius: 4px; padding: 6px 14px; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: 0.2s; }
        .btn-update:hover { background: #0b5ed7; }
        .container { flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem; width: 100%; max-width: 900px; }
        .card { background: var(--card); border: 1px solid #333; border-radius: 12px; padding: 2rem; text-align: center; text-decoration: none; color: var(--text); transition: transform 0.2s, background 0.2s; display: flex; flex-direction: column; align-items: center; gap: 1rem; }
        .card:hover { transform: translateY(-5px); background: var(--hover); border-color: #444; }
        .icon { font-size: 3rem; margin-bottom: 0.5rem; }
        .label { font-size: 1.2rem; font-weight: 600; }
        .status { font-size: 0.8rem; color: #888; margin-top: auto; }
        .user-info { font-size: 0.85rem; color: #94a3b8; margin-right: 1rem; }

        /* Modal Styles */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.85); backdrop-filter: blur(8px);
            display: none; align-items: center; justify-content: center; z-index: 1000;
        }
        .modal-card {
            background: #1a1a1a; border: 1px solid #333; border-radius: 16px;
            padding: 2.5rem; width: 100%; max-width: 400px; box-shadow: 0 20px 40px rgba(0,0,0,0.4);
            animation: modalPop 0.4s cubic-bezier(0.18, 0.89, 0.32, 1.28);
        }
        @keyframes modalPop {
            from { opacity: 0; transform: scale(0.9) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-header { text-align: center; margin-bottom: 2rem; }
        .modal-header i { font-size: 2.5rem; color: #6366f1; margin-bottom: 1rem; }
        .modal-header h2 { margin: 0; font-size: 1.5rem; }
        .modal-header p { color: #888; font-size: 0.9rem; margin-top: 0.5rem; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; color: #aaa; font-size: 0.85rem; }
        .form-input { 
            width: 100%; background: #0f0f0f; border: 1px solid #333; border-radius: 8px; 
            padding: 0.8rem 1rem; color: white; font-size: 1rem; box-sizing: border-box;
            transition: 0.3s;
        }
        .form-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.2); }
        .btn-submit {
            width: 100%; background: #6366f1; color: white; border: none; border-radius: 8px;
            padding: 0.9rem; font-weight: 600; cursor: pointer; transition: 0.3s; margin-top: 0.5rem;
        }
        .btn-submit:hover { background: #4f46e5; transform: translateY(-1px); }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="brand"><i class="fas fa-infinity"></i> Infinity Portal</div>
        <div class="actions">
            <span class="user-info">
                <i class="fas fa-user-circle"></i> 
                <?= htmlspecialchars($_SESSION['username'] ?? 'Guest') ?> 
                (<?= ucfirst($_SESSION['role'] ?? 'guest') ?>)
            </span>
            <form method="POST">
                <input type="hidden" name="update_action" value="1">
                <button type="submit" class="btn-update"><i class="fas fa-rotate"></i> Update</button>
            </form>
            <a href="?logout=1" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </nav>
    <div class="container">
        <div class="grid">
            <?php foreach($tools as $file => $data): $exists = file_exists($file); ?>
                <a href="<?= $exists ? $file : '#' ?>" class="card" style="<?= !$exists ? 'opacity:0.5; cursor:not-allowed;' : '' ?>">
                    <div class="icon" style="color: <?= $data['color'] ?>">
                        <i class="fas <?= $data['icon'] ?>"></i>
                    </div>
                    <div class="label"><?= $data['label'] ?></div>
                    <div class="status">
                        <?php if($exists): ?>
                            <i class="fas fa-check-circle" style="color:#28a745"></i> Ready
                        <?php else: ?>
                            <span style="color:#ff6b6b">File Missing</span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Password Change Modal -->
    <div id="pwdModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <i class="fas fa-shield-halved"></i>
                <h2>Security Update</h2>
                <p>You must change your default password to continue.</p>
            </div>
            <form method="POST">
                <input type="hidden" name="change_password" value="1">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" class="form-input" value="<?= htmlspecialchars($_SESSION['username']) ?>" disabled>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-input" placeholder="Enter secure password" required minlength="6">
                </div>
                <button type="submit" class="btn-submit">Update Password</button>
            </form>
        </div>
    </div>

    <?php if ($updateAlert): ?>
    <div style="background: rgba(239, 68, 68, 0.1); color: #f87171; padding: 1rem; margin: 1rem 2rem; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.2); text-align: center;">
        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($updateAlert) ?>
    </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['must_change_password']) && $_SESSION['must_change_password'] == 1): ?>
    <script>
        document.getElementById('pwdModal').style.display = 'flex';
    </script>
    <?php endif; ?>

    <?php if (isset($_GET['password_changed'])): ?>
    <script>
        alert('Password updated successfully!');
        window.history.replaceState({}, document.title, window.location.pathname);
    </script>
    <?php endif; ?>

    <?php if (isset($_GET['updated']) && isset($_SESSION['update_msg'])): ?>
    <script>
        alert('<?= addslashes($_SESSION['update_msg']) ?>');
        window.history.replaceState({}, document.title, window.location.pathname);
    </script>
    <?php unset($_SESSION['update_msg']); endif; ?>
</body>
</html>
<?php 
    exit; 
} // End if index.php

