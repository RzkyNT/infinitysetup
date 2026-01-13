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

$updateAlert = null;

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

if (
    isset($_SESSION['portal_logged_in']) &&
    $_SESSION['portal_logged_in'] === true &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['update_action'])
) {
    $contextFile = __DIR__ . DIRECTORY_SEPARATOR . 'link.txt';
    if (!file_exists($contextFile)) {
        $updateAlert = [
            'type' => 'error',
            'message' => 'link.txt tidak ditemukan. Pastikan file tersedia sebelum melakukan update.'
        ];
    } else {
        $sources = array_filter(array_map('trim', file($contextFile)));
        if (empty($sources)) {
            $updateAlert = [
                'type' => 'error',
                'message' => 'link.txt kosong. Tambahkan daftar URL file yang akan diupdate.'
            ];
        } else {
            $updatedFiles = [];
            $failedFiles = [];

            foreach ($sources as $url) {
                $filename = basename(parse_url($url, PHP_URL_PATH));
                if (!$filename) {
                    $failedFiles[] = "Tidak dapat menentukan nama file untuk URL: $url";
                    continue;
                }

                $remoteContent = @file_get_contents($url);
                if ($remoteContent === false) {
                    $failedFiles[] = "Gagal mengunduh $filename dari $url";
                    continue;
                }

                $targetPath = __DIR__ . DIRECTORY_SEPARATOR . $filename;
                if (@file_put_contents($targetPath, $remoteContent) === false) {
                    $failedFiles[] = "Gagal menyimpan file $filename";
                    continue;
                }

                $updatedFiles[] = $filename;
            }

            if (!empty($updatedFiles) && empty($failedFiles)) {
                $updateAlert = [
                    'type' => 'success',
                    'message' => 'Update selesai. File yang diperbarui: ' . implode(', ', $updatedFiles)
                ];
            } elseif (!empty($updatedFiles) && !empty($failedFiles)) {
                $updateAlert = [
                    'type' => 'warning',
                    'message' => 'Sebagian file berhasil diupdate (' . implode(', ', $updatedFiles) . '), namun ada error: ' . implode(' | ', $failedFiles)
                ];
            } else {
                $updateAlert = [
                    'type' => 'error',
                    'message' => 'Update gagal. Detail: ' . implode(' | ', $failedFiles)
                ];
            }
        }
    }
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
        .actions { display: flex; gap: 0.75rem; align-items: center; }
        .logout { color: #ff6b6b; text-decoration: none; font-size: 0.9rem; display: flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 4px; transition: 0.2s; }
        .logout:hover { background: rgba(255, 107, 107, 0.1); }
        .update-form button { background: #0d6efd; color: #fff; border: none; border-radius: 4px; padding: 6px 14px; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: 0.2s; }
        .update-form button:hover { background: #0b5ed7; }
        .update-form button:disabled { opacity: 0.6; cursor: not-allowed; }

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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

    <nav class="navbar">
        <div class="brand"><i class="fas fa-infinity"></i> Infinity Portal</div>
        <div class="actions">
            <form method="POST" class="update-form" id="update-form">
                <input type="hidden" name="update_action" value="1">
                <button type="submit"><i class="fas fa-rotate"></i> Update</button>
            </form>
            <a href="?logout=1" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
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

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const updateForm = document.getElementById('update-form');
            if (updateForm) {
                updateForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    Swal.fire({
                        title: 'Update File?',
                        text: 'Portal akan mengambil file terbaru dari daftar URL.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Lanjutkan',
                        cancelButtonText: 'Batal',
                    }).then((result) => {
                        if (result.isConfirmed) {
                            updateForm.submit();
                        }
                    });
                });
            }
            <?php if($updateAlert): ?>
            Swal.fire({
                icon: '<?= $updateAlert['type'] === 'success' ? 'success' : ($updateAlert['type'] === 'warning' ? 'warning' : 'error') ?>',
                title: '<?= $updateAlert['type'] === 'success' ? 'Berhasil' : ($updateAlert['type'] === 'warning' ? 'Sebagian Berhasil' : 'Gagal') ?>',
                text: <?= json_encode($updateAlert['message']) ?>
            });
            <?php endif; ?>
        });
    </script>

</body>
</html>
