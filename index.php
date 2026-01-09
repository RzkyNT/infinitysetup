<?php
session_start();

// ===== KONFIGURASI =====
// Password Global untuk masuk ke Portal ini
$PORTAL_PASSWORD = '12rizki3!'; 

// Daftar Tools (Nama File => Label)
$tools = [
    'filemanager.php' => ['icon' => 'fa-folder-open', 'label' => 'File Manager', 'color' => '#ffc107'],
    'adminer.php' => ['icon' => 'fa-database', 'label' => 'Database', 'color' => '#28a745'],
];

// ===== LOGOUT =====
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== LOGIN CHECK =====
if (!isset($_SESSION['portal_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['password']) && $_POST['password'] === $PORTAL_PASSWORD) {
            $_SESSION['portal_logged_in'] = true;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = "Access Denied";
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Infinity Portal</title>
    <style>
        body { background: #121212; color: #eee; font-family: system-ui, -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .login-box { background: #1e1e1e; padding: 2rem; border-radius: 12px; border: 1px solid #333; width: 100%; max-width: 320px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        h1 { margin: 0 0 1.5rem; font-size: 1.5rem; font-weight: 300; letter-spacing: 1px; }
        input { width: 100%; padding: 12px; margin-bottom: 1rem; background: #2d2d2d; border: 1px solid #444; color: #fff; border-radius: 6px; box-sizing: border-box; font-size: 1rem; outline: none; transition: 0.2s; }
        input:focus { border-color: #0d6efd; }
        button { width: 100%; padding: 12px; background: #0d6efd; color: white; border: none; border-radius: 6px; font-size: 1rem; cursor: pointer; font-weight: 600; transition: 0.2s; }
        button:hover { background: #0b5ed7; }
        .error { color: #ff6b6b; margin-bottom: 1rem; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>INFINITY SETUP</h1>
        <?php if(isset($error)) echo "<div class='error'>$error</div>"; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Enter Password" required autofocus>
            <button type="submit">Unlock</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root { --bg: #0f0f0f; --card: #1a1a1a; --text: #e0e0e0; --hover: #252525; }
        body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; flex-direction: column; }
        
        .navbar { background: var(--card); padding: 1rem 2rem; border-bottom: 1px solid #333; display: flex; justify-content: space-between; align-items: center; }
        .brand { font-size: 1.25rem; font-weight: bold; display: flex; align-items: center; gap: 10px; }
        .logout { color: #ff6b6b; text-decoration: none; font-size: 0.9rem; display: flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 4px; transition: 0.2s; }
        .logout:hover { background: rgba(255, 107, 107, 0.1); }

        .container { flex: 1; display: flex; align-items: center; justify-content: center; padding: 2rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem; width: 100%; max-width: 900px; }
        
        .card { background: var(--card); border: 1px solid #333; border-radius: 12px; padding: 2rem; text-align: center; text-decoration: none; color: var(--text); transition: transform 0.2s, background 0.2s; display: flex; flex-direction: column; align-items: center; gap: 1rem; }
        .card:hover { transform: translateY(-5px); background: var(--hover); border-color: #444; }
        .icon { font-size: 3rem; margin-bottom: 0.5rem; }
        .label { font-size: 1.2rem; font-weight: 600; }
        .status { font-size: 0.8rem; color: #888; margin-top: auto; }
        
        .badge-missing { color: #ff6b6b; font-size: 0.8rem; border: 1px solid #ff6b6b; padding: 2px 8px; border-radius: 10px; margin-top: 5px; }

        @media (max-width: 600px) {
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="brand"><i class="fas fa-infinity"></i> Infinity Portal</div>
        <a href="?logout=1" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>

    <div class="container">
        <div class="grid">
            <?php foreach($tools as $file => $data): 
                $exists = file_exists($file);
            ?>
                <a href="<?= $exists ? $file : '#' ?>" class="card" style="<?= !$exists ? 'opacity:0.5; cursor:not-allowed;' : '' ?>">
                    <div class="icon" style="color: <?= $data['color'] ?>">
                        <i class="fas <?= $data['icon'] ?>"></i>
                    </div>
                    <div class="label"><?= $data['label'] ?></div>
                    <?php if($exists): ?>
                        <div class="status"><i class="fas fa-check-circle" style="color:#28a745"></i> Ready</div>
                    <?php else: ?>
                        <div class="badge-missing">File Missing</div>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

</body>
</html>
