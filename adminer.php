<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
// ===== API HASH PHP (Bcrypt) =====
// Paste tepat di sini, setelah session_start
if (isset($_GET['api']) && $_GET['api'] === 'generate_php_hash') {
    header('Content-Type: application/json');
    
    // Cek apakah user sudah login
    if (!isset($_SESSION['db_user'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $password = $_POST['password'] ?? '';
    
    if (empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Password cannot be empty']);
        exit;
    }

    // Menggunakan PASSWORD_BCRYPT agar format sesuai ($2y$10$)
    $hash = password_hash($password, PASSWORD_BCRYPT);

    echo json_encode([
        'success' => true, 
        'hash' => $hash,
        'algo' => 'Bcrypt ($2y$)'
    ]);
    exit;
}

// ... kode function get_asset_url dan seterusnya tetap ada di bawah sini ...
$configFile = __DIR__ . '/adminer.config.json';
function get_asset_url($localPath, $cdnUrl) {
    if (file_exists(__DIR__ . '/' . $localPath)) {
        return $localPath;
    }
    return $cdnUrl;
}

function load_config($path)
{
    if (!file_exists($path)) {
        return ['host' => '', 'user' => '', 'pass' => '', 'databases' => []];
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['host' => '', 'user' => '', 'pass' => '', 'databases' => []];
    }
    return array_merge(
        ['host' => '', 'user' => '', 'pass' => '', 'databases' => []],
        $data
    );
}

function save_config($path, $data)
{
    $existing = load_config($path);
    $new = array_merge($existing, $data);
    // Ensure databases is always an array unique
    if (isset($new['databases']) && is_array($new['databases'])) {
        $new['databases'] = array_values(array_unique($new['databases']));
    } else {
        $new['databases'] = [];
    }
    
    $payload = json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents($path, $payload) !== false;
}

function is_valid_db_name($name)
{
    return (bool) preg_match('/^[A-Za-z0-9_\\$\\-\\.]+$/', $name);
}

// Deprecated wrapper functions for compatibility if needed, but we use load_config directly now
function load_db_config($path) { return load_config($path); }
function save_db_config($path, $data) { return save_config($path, $data); }
function load_db_list($path) { 
    $cfg = load_config($path); 
    return $cfg['databases'];
}
function save_db_list($path, $names) {
    return save_config($path, ['databases' => $names]);
}

function normalize_db_host($host) {
    $normalized = strtolower(trim((string) $host));
    if (strpos($normalized, ':') !== false) {
        $normalized = explode(':', $normalized, 2)[0];
    }
    return $normalized;
}

function detect_host_profile($host) {
    $normalized = normalize_db_host($host);
    if ($normalized === 'localhost' || $normalized === '127.0.0.1') {
        return 'local';
    }
    if ($normalized !== '' && preg_match('/(infinityfree|epizy|ezyro)/', $normalized)) {
        return 'infinityfree';
    }
    return 'remote';
}

function should_prefix_database_names($hostProfile) {
    return $hostProfile === 'infinityfree';
}

function should_show_managed_database_list($hostProfile) {
    return $hostProfile !== 'local';
}

function should_show_server_database_panel($hostProfile) {
    return $hostProfile !== 'infinityfree';
}

function apply_database_prefix($dbname, $dbUser, $hostProfile) {
    if (!$dbname || !$dbUser) {
        return $dbname;
    }
    if (!should_prefix_database_names($hostProfile)) {
        return $dbname;
    }
    return (strpos($dbname, $dbUser . '_') === 0) ? $dbname : $dbUser . '_' . $dbname;
}

function render_db_setup($defaults = [], $error = '', $success = '')
{
    $host = htmlspecialchars($defaults['host'] ?? '');
    $user = htmlspecialchars($defaults['user'] ?? '');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Setup</title>
        <link rel="icon" type="image/svg+xml" href="<?= get_asset_url('assets/vendor/icon.svg', 'https://am.ct.ws/icon.svg') ?>">
        <link rel="shortcut icon" href="<?= get_asset_url('assets/vendor/icon.svg', 'https://am.ct.ws/icon.svg') ?>">
        <link rel="stylesheet" href="<?= get_asset_url('assets/vendor/fontawesome6/fontawesome-free-6.5.1-web/css/all.min.css', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css') ?>">
        <style>
            body { background:#0b0b0b; color:#f0f0f0; font-family: 'Segoe UI', system-ui, sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
            .setup-card { background:#161616; padding:2rem; border:1px solid #333; border-radius:12px; width:100%; max-width:420px; box-shadow:0 10px 40px rgba(0,0,0,0.5); }
            h1 { margin:0 0 1.5rem; font-size:1.5rem; text-align:center; }
            label { display:block; margin-bottom:0.35rem; font-weight:600; color:#bbb; }
            input { width:100%; padding:0.65rem 0.75rem; border-radius:6px; border:1px solid #333; background:#1f1f1f; color:#fff; margin-bottom:1rem; }
            button { width:100%; padding:0.75rem; border:none; border-radius:6px; background:#0d6efd; color:#fff; font-weight:600; cursor:pointer; }
            button:hover { background:#0b5ed7; }
            .alert { padding:0.75rem; border-radius:6px; margin-bottom:1rem; font-size:0.9rem; }
            .alert-error { background:rgba(255,107,107,0.15); border:1px solid #ff6b6b; color:#ffb3b3; }
            .alert-success { background:rgba(40,167,69,0.15); border:1px solid #28a745; color:#b8f5cd; }
        </style>    
    </head>
    <body>
        <div class="setup-card">
            <h1><i class="fas fa-database"></i> Adminer Setup</h1>
            <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="db_setup_action" value="1">
                <label for="host">DB Host</label>
                <input type="text" id="host" name="db_host" value="<?php echo $host; ?>" required>
                <label for="user">DB User</label>
                <input type="text" id="user" name="db_user" value="<?php echo $user; ?>" required>
                <label for="pass">DB Password</label>
                <input type="password" id="pass" name="db_pass" value="" placeholder="Leave blank to keep existing">
                <button type="submit"><i class="fas fa-save"></i> Save Configuration</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Migration from old files
if (!file_exists($configFile)) {
    $oldDbConfig = __DIR__ . '/adminer.db.json';
    $oldDbList = __DIR__ . '/adminer.databases.json';
    if (file_exists($oldDbConfig)) {
        $oldData = json_decode(file_get_contents($oldDbConfig), true) ?? [];
        $oldList = file_exists($oldDbList) ? (json_decode(file_get_contents($oldDbList), true) ?? []) : [];
        $migrated = [
            'host' => $oldData['host'] ?? '',
            'user' => $oldData['user'] ?? '',
            'pass' => $oldData['pass'] ?? '',
            'databases' => $oldList
        ];
        file_put_contents($configFile, json_encode($migrated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

$dbConfig = load_config($configFile);
$user_defined_databases = $dbConfig['databases'] ?? [];

if (isset($_POST['db_setup_action'])) {
    $host = trim($_POST['db_host'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';

    $existing = $dbConfig ?? ['host' => '', 'user' => '', 'pass' => '', 'databases' => []];
    if ($host === '' || $user === '') {
        render_db_setup(
            ['host' => $host, 'user' => $user],
            'Host dan user wajib diisi.'
        );
    }
    if ($pass === '' && isset($existing['pass'])) {
        $pass = $existing['pass'];
    }
    $payload = ['host' => $host, 'user' => $user, 'pass' => $pass]; // databases maintained by merge in save_config
    if (!save_config($configFile, $payload)) {
        render_db_setup($payload, 'Failed to save configuration. Check file permissions.');
    }
    $dbConfig = load_config($configFile); // Reload to get full config
    render_db_setup($dbConfig, '', 'Configuration saved. You can refresh to continue.');
}

if (empty($dbConfig['host']) || isset($_GET['setup'])) {
    render_db_setup($dbConfig ?? []);
}

// ===== SSO LOGIC =====
if (isset($_SESSION['portal_logged_in']) && $_SESSION['portal_logged_in'] === true) {
    if (!isset($_SESSION['db_host'])) {
        $_SESSION['db_host'] = $dbConfig['host'];
        $_SESSION['db_user'] = $dbConfig['user'];
        $_SESSION['db_pass'] = $dbConfig['pass'];
        $_SESSION['db_name'] = $_SESSION['db_name'] ?? '';
    }
}
$DB_NAME = $_SESSION['db_name'] ?? '';
$hasSelectedDatabase = $DB_NAME !== '';
$currentTable = isset($_GET['table']) ? preg_replace('/[^a-zA-Z0-9_\$\- ]/', '', $_GET['table']) : null;
$view = isset($_GET['view']) ? $_GET['view'] : 'structure';


// ===== LOGOUT LOGIC =====
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// ===== DATABASE SWITCHING =====
if (isset($_GET['select_db'])) {
    $_SESSION['db_name'] = $_GET['select_db'];
    header("Location: ?");
    exit;
}

// ===== AUTHENTICATION CHECK =====
$is_logged_in = isset($_SESSION['db_host']) && isset($_SESSION['db_user']);
$error = '';
$msg = '';

if (!$is_logged_in) {
    // Force Redirect to Portal
    header("Location: index.php");
    exit;
}

// ===== DB CONNECTION (IF LOGGED IN) =====// ===== DB CONNECTION (IF LOGGED IN) =====
// ===== DB CONNECTION (IF LOGGED IN) =====
$pdo = null;
$databases = []; // List of databases
$sqlResults = [];
$lastResultSet = null;
$hostProfile = detect_host_profile($dbConfig['host'] ?? $_SESSION['db_host'] ?? '');

// Debug log
error_log("Adminer: is_logged_in=$is_logged_in, hasSelectedDatabase=$hasSelectedDatabase, DB_NAME=" . ($_SESSION['db_name'] ?? 'empty'));

if ($is_logged_in) {
    try {
        // Selalu buat koneksi dasar (tanpa database) dulu
        $dsn = "mysql:host={$_SESSION['db_host']};charset=utf8mb4";
        
        $pdo = new PDO(
            $dsn,
            $_SESSION['db_user'],
            $_SESSION['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5
            ]
        );
        
        // Fetch semua database yang tersedia
        try {
            $stmt = $pdo->query("SHOW DATABASES");
            $databases_from_server = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Prioritaskan database dari server
            if (!empty($databases_from_server)) {
                $databases = $databases_from_server;
            } elseif ($hostProfile === 'local') {
                // Jika di localhost, kita asumsikan full akses.
                // Jika SHOW DATABASES kosong, berarti memang tidak ada database (atau error koneksi),
                // jadi jangan fallback ke JSON (sesuai request user).
                $databases = $databases_from_server; 
            } elseif (!empty($user_defined_databases)) {
                // Fallback ke user defined databases
                $databases = $user_defined_databases;
            }
            
            // Jika ada database yang dipilih di session, validasi
            if (!empty($_SESSION['db_name']) && !in_array($_SESSION['db_name'], $databases)) {
                // Database tidak valid, reset
                $_SESSION['db_name'] = '';
                $hasSelectedDatabase = false;
                $DB_NAME = '';
            }
            
        } catch (Exception $e) {
            // Silently fail if SHOW DATABASES is denied (common on shared hosting)
            // error_log("Adminer SHOW DATABASES error: " . $e->getMessage());
            $databases = $user_defined_databases;
        }
        
        // Jika sudah ada database yang dipilih, reconnect dengan database tersebut
        if ($hasSelectedDatabase && !empty($_SESSION['db_name'])) {
            try {
                $dsn_with_db = "mysql:host={$_SESSION['db_host']};dbname={$_SESSION['db_name']};charset=utf8mb4";
                $pdo = new PDO(
                    $dsn_with_db,
                    $_SESSION['db_user'],
                    $_SESSION['db_pass'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_TIMEOUT => 5
                    ]
                );
            } catch (Exception $e) {
                error_log("Adminer reconnect with DB error: " . $e->getMessage());
                // Tetap gunakan koneksi tanpa database
            }
        }
        
    } catch (Exception $e) {
        session_destroy();
        die("Database Connection Error: " . htmlspecialchars($e->getMessage()) . 
            ". Check your credentials in adminer.db.json or contact administrator.");
    }
}

// ===== HELPER FUNCTIONS =====
function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

function getPrimaryKey($pdo, $table) {
    try {
        $stmt = $pdo->prepare("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
        $stmt->execute();
        $res = $stmt->fetch();
        return $res ? $res['Column_name'] : null;
    } catch (Exception $e) { return null; }
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function sanitizeDiagramId($name) {
    $sanitized = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    if ($sanitized === '' || is_numeric($sanitized[0])) {
        $sanitized = 'tbl_' . substr(md5($name), 0, 6);
    }
    return $sanitized;
}

function mermaid_identifier($name) {
    $id = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    if ($id === '' || is_numeric($id[0])) {
        $id = 'tbl_' . substr(md5($name), 0, 6);
    }
    return $id;
}

function mermaid_datatype($type) {
    $upper = strtoupper($type);
    $upper = preg_replace('/[^A-Z0-9]/', '_', $upper);
    return $upper ?: 'TEXT';
}

function mermaid_column_name($name) {
    $col = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    if ($col === '' || is_numeric($col[0])) {
        $col = 'col_' . substr(md5($name), 0, 6);
    }
    return $col;
}

function mermaid_column_suffix($col) {
    $suffix = [];
    if (!empty($col['Key'])) {
        if ($col['Key'] === 'PRI') $suffix[] = 'PK';
        elseif ($col['Key'] === 'UNI') $suffix[] = 'UQ';
        elseif ($col['Key'] === 'MUL') $suffix[] = 'IDX';
    }
    if (!empty($col['Null']) && $col['Null'] === 'NO') {
        $suffix[] = 'NOT_NULL';
    }
    if (!empty($col['Extra'])) {
        $suffix[] = strtoupper(str_replace(' ', '_', $col['Extra']));
    }
    return $suffix ? ' ' . implode(' ', $suffix) : '';
}

function mermaid_relation_label($fromCol, $toCol) {
    $label = $fromCol . '_to_' . $toCol;
    return preg_replace('/[^A-Za-z0-9_]/', '_', $label);
}

function plantuml_encode($text) {
    $data = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    $compressed = gzdeflate($data);
    return plantuml_encode64($compressed);
}

function plantuml_encode64($data) {
    $alphabet = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz-_";
    $out = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i += 3) {
        if ($i + 2 < $len) {
            $b1 = ord($data[$i]);
            $b2 = ord($data[$i + 1]);
            $b3 = ord($data[$i + 2]);
            $out .= $alphabet[$b1 >> 2];
            $out .= $alphabet[(($b1 & 0x3) << 4) | ($b2 >> 4)];
            $out .= $alphabet[(($b2 & 0xF) << 2) | ($b3 >> 6)];
            $out .= $alphabet[$b3 & 0x3F];
        } elseif ($i + 1 < $len) {
            $b1 = ord($data[$i]);
            $b2 = ord($data[$i + 1]);
            $out .= $alphabet[$b1 >> 2];
            $out .= $alphabet[(($b1 & 0x3) << 4) | ($b2 >> 4)];
            $out .= $alphabet[(($b2 & 0xF) << 2)];
        } else {
            $b1 = ord($data[$i]);
            $out .= $alphabet[$b1 >> 2];
            $out .= $alphabet[(($b1 & 0x3) << 4)];
        }
    }
    return $out;
}

function split_sql_statements($sql) {
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $inLineComment = false;
    $inBlockComment = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inString) {
            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($char === '#') {
                $inLineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($char === '\\' && $inString) {
            $current .= $char;
            if ($next !== '') {
                $current .= $next;
                $i++;
            }
            continue;
        }

        if ($char === '\'' || $char === '"' || $char === '`') {
            if ($inString && $char === $stringChar) {
                $inString = false;
                $stringChar = '';
            } elseif (!$inString) {
                $inString = true;
                $stringChar = $char;
            }
            $current .= $char;
            continue;
        }

        if ($char === ';' && !$inString) {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    if (trim($current) !== '') {
        $statements[] = trim($current);
    }

    return $statements;
}

function is_resultset_statement($statement) {
    return (bool) preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\\b/i', ltrim($statement));
}

// ===== ACTION HANDLER (POST) =====
if ($is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $table = $_POST['table'] ?? '';
    
    // --- SQL QUERY ---
    if ($action === 'sql_query') {
        $sql = $_POST['query'] ?? '';
        $statements = split_sql_statements($sql);
        $affectedTotal = 0;
        $resultSets = [];

        if (empty($statements)) {
            $error = 'No SQL statements provided.';
        } else {
            try {
                foreach ($statements as $statement) {
                    if (stripos($statement, 'DROP DATABASE') === 0) {
                        throw new Exception('DROP DATABASE statements are blocked.');
                    }

                    if (is_resultset_statement($statement)) {
                        $stmt = $pdo->query($statement);
                        $fetched = $stmt->fetchAll();
                        $resultSets[] = [
                            'query' => $statement,
                            'rows' => $fetched,
                            'columns' => !empty($fetched) ? array_keys($fetched[0]) : []
                        ];
                    } else {
                        $affected = $pdo->exec($statement);
                        $affectedTotal += $affected !== false ? $affected : 0;
                    }
                }
                $sqlResults = $resultSets;
                $lastResultSet = !empty($resultSets) ? end($resultSets) : null;
                $msg = count($statements) . " statement(s) executed. Rows affected: $affectedTotal.";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
    // --- SAVE ROW ---
    elseif ($action === 'save_row') {
        $data = $_POST['data'] ?? [];
        $pk = $_POST['pk'] ?? null;
        $pkVal = $_POST['pk_val'] ?? null;
        
        try {
            if ($pkVal) {
                // UPDATE
                $set = [];
                $params = [];
                foreach ($data as $col => $val) {
                    $set[] = "`$col` = ?";
                    $params[] = $val;
                }
                $params[] = $pkVal;
                $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE `$pk` = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $msg = "Row updated successfully.";
            } else {
                // INSERT
                $cols = array_keys($data);
                $vals = array_values($data);
                $placeholders = array_fill(0, count($vals), '?');
                $sql = "INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($vals);
                $msg = "Row inserted successfully.";
            }
            redirect("?table=$table&view=data&msg=" . urlencode($msg));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    // --- DELETE TABLE ---
    elseif ($action === 'delete_table') {
        try {
            $pdo->exec("DROP TABLE `$table`");
            redirect("?msg=" . urlencode("Table $table deleted."));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    // --- TRUNCATE TABLE ---
    elseif ($action === 'truncate_table') {
        try {
            $pdo->exec("TRUNCATE TABLE `$table`");
            redirect("?table=$table&view=structure&msg=" . urlencode("Table $table truncated."));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    // --- DROP COLUMN ---
    elseif ($action === 'drop_column') {
        $col = $_POST['col'];
        try {
            $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$col`");
            redirect("?table=$table&view=structure&msg=" . urlencode("Column $col dropped."));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    // --- SAVE COLUMN (ADD/EDIT) ---
    elseif ($action === 'save_column') {
        $orig = $_POST['orig_field'] ?? '';
        $name = $_POST['field'];
        $type = $_POST['type'];
        $length = $_POST['length'];
        $default = $_POST['default']; 
        $default_val = $_POST['default_val'] ?? '';
        $null = isset($_POST['null']) ? 'NULL' : 'NOT NULL';
        $ai = isset($_POST['ai']) ? 'AUTO_INCREMENT' : '';
        $collation = $_POST['collation'] ?? '';
        
        // Build definition
        $def = "`$name` $type";
        if ($length !== '') $def .= "($length)";
        if ($collation) $def .= " COLLATE $collation";
        $def .= " $null";
        
        if ($default === 'NULL') {
            $def .= " DEFAULT NULL";
        } elseif ($default === 'USER_DEFINED') {
            $def .= " DEFAULT " . $pdo->quote($default_val);
        } elseif ($default === 'CURRENT_TIMESTAMP') {
            $def .= " DEFAULT CURRENT_TIMESTAMP";
        }
        
        $def .= " $ai";
        
        try {
            if ($orig) {
                $sql = "ALTER TABLE `$table` CHANGE COLUMN `$orig` $def";
            } else {
                $after = $_POST['after'] ?? '';
                $pos = $after ? "AFTER `$after`" : "FIRST"; 
                if ($after === '') $pos = ""; 
                $sql = "ALTER TABLE `$table` ADD COLUMN $def $pos";
            }
            $pdo->exec($sql);
            redirect("?table=$table&view=structure&msg=" . urlencode("Column saved."));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    // --- EXPORT ---
    elseif ($action === 'export') {
        $exportTable = $_POST['table'] ?? null; // If null, export all
        $format = $_POST['format'] ?? 'sql';
        $filename = ($exportTable ? $exportTable : $_SESSION['db_name']) . "_" . date("Y-m-d_H-i-s");

        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header("Content-disposition: attachment; filename=\"$filename.csv\"");
            $out = fopen('php://output', 'w');
            
            // Only support single table export for CSV for simplicity
            if ($exportTable) {
                $stmt = $pdo->query("SELECT * FROM `$exportTable`");
                $first = true;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($first) {
                        fputcsv($out, array_keys($row));
                        $first = false;
                    }
                    fputcsv($out, $row);
                }
            }
            fclose($out);
        } elseif ($format === 'json') {
            header('Content-Type: application/json');
            header("Content-disposition: attachment; filename=\"$filename.json\"");
            
            $data = [];
            if ($exportTable) {
                $stmt = $pdo->query("SELECT * FROM `$exportTable`");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode($data, JSON_PRETTY_PRINT);
        } else {
            // SQL EXPORT (Default)
            header('Content-Type: application/octet-stream');
            header("Content-Transfer-Encoding: Binary"); 
            header("Content-disposition: attachment; filename=\"$filename.sql\""); 
            
            $tablesToExport = [];
            if ($exportTable) {
                $tablesToExport[] = $exportTable;
            } else {
                $stmt = $pdo->query("SHOW TABLES");
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $tablesToExport[] = $row[0];
                }
            }
            
            echo "-- Adminer Export: " . date("Y-m-d H:i:s") . "\n";
            echo "-- Database: " . $_SESSION['db_name'] . "\n\n";
            
            foreach ($tablesToExport as $t) {
                echo "-- --------------------------------------------------------\n";
                echo "-- Structure for table `$t`\n";
                echo "--\n\n";
                
                $stmt = $pdo->query("SHOW CREATE TABLE `$t`");
                $row = $stmt->fetch(PDO::FETCH_NUM);
                echo "DROP TABLE IF EXISTS `$t`;\n";
                echo $row[1] . ";\n\n";
                
                echo "-- Data for table `$t`\n\n";
                $stmt = $pdo->query("SELECT * FROM `$t`");
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $keys = array_keys($r);
                    $vals = array_values($r);
                    
                    $vals = array_map(function($v) use ($pdo) {
                        return $v === null ? "NULL" : $pdo->quote($v);
                    }, $vals);
                    
                    echo "INSERT INTO `$t` (`" . implode('`, `', $keys) . "`) VALUES (" . implode(', ', $vals) . ");\n";
                }
                echo "\n";
            }
        }
        exit;
    }
    // --- BULK TABLE OPERATIONS ---
    elseif ($action === 'bulk_tables') {
        $operation = $_POST['bulk_operation'] ?? '';
        $selected = $_POST['tables'] ?? [];
        if (!$selected || !$operation) {
            $error = 'Select at least one table and an operation.';
        } else {
            $cleanTables = [];
            foreach ($selected as $tbl) {
                $clean = preg_replace('/[^A-Za-z0-9_]/', '', $tbl);
                if ($clean !== '') {
                    $cleanTables[] = $clean;
                }
            }
            if (empty($cleanTables)) {
                $error = 'No valid tables selected.';
            } else {
                try {
                    if ($operation === 'drop') {
                        foreach ($cleanTables as $tbl) {
                            $pdo->exec("DROP TABLE `$tbl`");
                        }
                        redirect("?msg=" . urlencode(count($cleanTables) . " table(s) dropped."));
                    } elseif ($operation === 'truncate') {
                        foreach ($cleanTables as $tbl) {
                            $pdo->exec("TRUNCATE TABLE `$tbl`");
                        }
                        redirect("?msg=" . urlencode(count($cleanTables) . " table(s) truncated."));
                    } elseif ($operation === 'optimize') {
                        foreach ($cleanTables as $tbl) {
                            $pdo->exec("OPTIMIZE TABLE `$tbl`");
                        }
                        redirect("?msg=" . urlencode(count($cleanTables) . " table(s) optimized."));
                    } elseif ($operation === 'export') {
                        $filename = "tables_" . date("Y-m-d_H-i-s") . ".sql";
                        header('Content-Type: application/octet-stream');
                        header("Content-Transfer-Encoding: Binary"); 
                        header("Content-disposition: attachment; filename=\"$filename\"");
                        echo "-- Adminer Bulk Export: " . date("Y-m-d H:i:s") . "\n";
                        echo "-- Database: " . $_SESSION['db_name'] . "\n";
                        echo "-- Tables: " . implode(', ', $cleanTables) . "\n\n";
                        foreach ($cleanTables as $t) {
                            $stmt = $pdo->query("SHOW CREATE TABLE `$t`");
                            $row = $stmt->fetch(PDO::FETCH_NUM);
                            echo "DROP TABLE IF EXISTS `$t`;\n";
                            echo $row[1] . ";\n\n";
                            $stmt = $pdo->query("SELECT * FROM `$t`");
                            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                $keys = array_keys($r);
                                $vals = array_values($r);
                                $vals = array_map(function($v) use ($pdo) {
                                    return $v === null ? "NULL" : $pdo->quote($v);
                                }, $vals);
                                echo "INSERT INTO `$t` (`" . implode('`, `', $keys) . "`) VALUES (" . implode(', ', $vals) . ");\n";
                            }
                            echo "\n";
                        }
                        exit;
                    } else {
                        $error = 'Unsupported bulk operation.';
                    }
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            }
        }
    }
    // --- ADD INDEX ---
    elseif ($action === 'add_index') {
        $type = $_POST['type']; // INDEX, UNIQUE, PRIMARY
        $cols = $_POST['cols'] ?? [];
        $name = $_POST['name'] ?? '';
        
        if (!empty($cols)) {
            $colsStr = '`' . implode('`, `', $cols) . '`';
            $sql = "ALTER TABLE `$table` ADD $type ";
            if ($name && $type !== 'PRIMARY') $sql .= "`$name` ";
            $sql .= "($colsStr)";
            
            try {
                $pdo->exec($sql);
                redirect("?table=$table&view=structure&msg=" . urlencode("Index added."));
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
    // --- DROP INDEX ---
    elseif ($action === 'drop_index') {
        $name = $_POST['name'];
        $type = $_POST['type']; // PRIMARY or name
        
        try {
            if ($name === 'PRIMARY') {
                $pdo->exec("ALTER TABLE `$table` DROP PRIMARY KEY");
            } else {
                $pdo->exec("ALTER TABLE `$table` DROP INDEX `$name`");
            }
            redirect("?table=$table&view=structure&msg=" . urlencode("Index dropped."));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    // --- ADD FOREIGN KEY ---
    elseif ($action === 'add_fk') {
        $name = $_POST['name'] ?? '';
        $col = $_POST['col'];
        $refTable = $_POST['ref_table'];
        $refCol = $_POST['ref_col'];
        $onDelete = $_POST['on_delete'];
        $onUpdate = $_POST['on_update'];
        
        $sql = "ALTER TABLE `$table` ADD ";
        if ($name) $sql .= "CONSTRAINT `$name` ";
        $sql .= "FOREIGN KEY (`$col`) REFERENCES `$refTable` (`$refCol`)";
        if ($onDelete) $sql .= " ON DELETE $onDelete";
        if ($onUpdate) $sql .= " ON UPDATE $onUpdate";
        
        try {
            $pdo->exec($sql);
            redirect("?table=$table&view=structure&msg=" . urlencode("Foreign Key added."));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    // --- DROP FOREIGN KEY ---
    elseif ($action === 'drop_fk') {
        $name = $_POST['name'];
        try {
            $pdo->exec("ALTER TABLE `$table` DROP FOREIGN KEY `$name`");
            redirect("?table=$table&view=structure&msg=" . urlencode("Foreign Key dropped."));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    // --- BULK DELETE ---
    elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        $pk = $_POST['pk'] ?? null;
        
        if ($table && $pk && !empty($ids)) {
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql = "DELETE FROM `$table` WHERE `$pk` IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($ids);
                $count = $stmt->rowCount();
                redirect("?table=$table&view=data&msg=" . urlencode("$count rows deleted."));
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } else {
             $error = "No rows selected or primary key missing.";
        }
    }
    // --- MANAGE DATABASE LIST (JSON) ---
    elseif ($action === 'add_database_list') {
        $dbName = trim($_POST['name'] ?? '');
        
        $config = load_config($configFile);
        $dbUser = $config['user'] ?? '';
        $dbName = apply_database_prefix($dbName, $dbUser, $hostProfile);

        if ($dbName && is_valid_db_name($dbName)) {
            $currentList = $config['databases'] ?? [];
            if (!in_array($dbName, $currentList)) {
                $currentList[] = $dbName;
                save_config($configFile, ['databases' => $currentList]);
                $msg = "Database '$dbName' added to list.";
            } else {
                $error = "Database already in list.";
            }
        } else {
            $error = "Invalid database name.";
        }
        redirect("?view=manage_dbs&msg=" . urlencode($msg ?? '') . "&error=" . urlencode($error ?? ''));
    }
    elseif ($action === 'remove_database_list') {
        $dbName = $_POST['name'] ?? '';
        $currentList = load_config($configFile)['databases'] ?? [];
        if (($key = array_search($dbName, $currentList)) !== false) {
            unset($currentList[$key]);
            save_config($configFile, ['databases' => array_values($currentList)]);
            $msg = "Database '$dbName' removed from list.";
        }
        redirect("?view=manage_dbs&msg=" . urlencode($msg ?? ''));
    }
    // --- MANAGE DATABASE SERVER (SQL) ---
    elseif ($action === 'create_database_server') {
        $dbName = trim($_POST['name'] ?? '');
        
        $config = load_config($configFile);
        $dbUser = $config['user'] ?? '';
        $dbName = apply_database_prefix($dbName, $dbUser, $hostProfile);

        if ($dbName && is_valid_db_name($dbName)) {
            try {
                $pdo->exec("CREATE DATABASE `$dbName`");
                $msg = "Database '$dbName' created on server.";
                // Auto add to list
                $currentList = $config['databases'] ?? [];
                if (!in_array($dbName, $currentList)) {
                    $currentList[] = $dbName;
                    save_config($configFile, ['databases' => $currentList]);
                }
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = "Invalid database name.";
        }
        redirect("?view=manage_dbs&msg=" . urlencode($msg ?? '') . "&error=" . urlencode($error ?? ''));
    }
    elseif ($action === 'drop_database_server') {
        $dbName = $_POST['name'] ?? '';
        if ($dbName) {
            try {
                $pdo->exec("DROP DATABASE `$dbName`");
                $msg = "Database '$dbName' dropped from server.";
                // Remove from list too
                $currentList = load_config($configFile)['databases'] ?? [];
                if (($key = array_search($dbName, $currentList)) !== false) {
                    unset($currentList[$key]);
                    save_config($configFile, ['databases' => array_values($currentList)]);
                }
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
        redirect("?view=manage_dbs&msg=" . urlencode($msg ?? '') . "&error=" . urlencode($error ?? ''));
    }
}

// ===== GET HANDLERS =====
if ($is_logged_in) {
    if (isset($_GET['action']) && $_GET['action'] === 'delete_row') {
        $table = $_GET['table'];
        $pk = $_GET['pk'];
        $val = $_GET['val'];
        try {
            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?");
            $stmt->execute([$val]);
            redirect("?table=$table&view=data&msg=" . urlencode("Row deleted."));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    if (isset($_GET['msg'])) $msg = $_GET['msg'];
}

// // Debug: Tampilkan status variabel
// echo "<pre style='background:#000; color:#0f0; padding:10px;'>";
// echo "DEBUG INFO:\n";
// echo "is_logged_in: " . ($is_logged_in ? 'TRUE' : 'FALSE') . "\n";
// echo "hasSelectedDatabase: " . ($hasSelectedDatabase ? 'TRUE' : 'FALSE') . "\n";
// echo "DB_NAME: " . htmlspecialchars($DB_NAME) . "\n";
// echo "pdo is null? " . (is_null($pdo) ? 'YES' : 'NO') . "\n";
// echo "Session db_name: " . ($_SESSION['db_name'] ?? 'NOT SET') . "\n";
// echo "</pre>";

// ===== DATA PREPARATION =====
$tables = [];
$totalRows = 0;
$totalSize = 0;
$relationshipDiagram = '';
$erdDiagram = '';
$relationshipPlantumlEncoded = null;
$plantumlDiagramEncoded = null;
$erdPlantumlEncoded = null;

// Hanya jalankan jika ada koneksi PDO yang valid DAN ada database yang dipilih
if ($is_logged_in && isset($pdo) && $pdo !== null && $hasSelectedDatabase) {
    // Tables list
    try {
        $stmt = $pdo->query("SHOW TABLE STATUS");
        $tables = $stmt->fetchAll();
    } catch (Exception $e) {
        // Jika error SHOW TABLE STATUS, coba SHOW TABLES
        try {
            $stmt = $pdo->query("SHOW TABLES");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = ['Name' => $row[0], 'Rows' => 0, 'Data_length' => 0, 'Index_length' => 0, 'Collation' => ''];
            }
        } catch (Exception $ex) {
            // Biarkan tables kosong jika masih error
            $tables = [];
            error_log("Adminer DATA PREPARATION error: " . $ex->getMessage());
        }
    }

    // Hitung total rows dan size hanya jika ada tables
    foreach ($tables as $t) {
        $totalRows += $t['Rows'] ?? 0;
        $totalSize += ($t['Data_length'] ?? 0) + ($t['Index_length'] ?? 0);
    }
}

if ($is_logged_in && $hasSelectedDatabase && !$currentTable && isset($pdo)) {
    try {
        $schemaFkStmt = $pdo->prepare("
            SELECT 
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME,
                CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE 
                TABLE_SCHEMA = :schema AND
                REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $schemaFkStmt->execute(['schema' => $DB_NAME]);
        $fkRows = $schemaFkStmt->fetchAll();
        if ($fkRows) {
            $edgeMap = [];
            foreach ($fkRows as $fk) {
                $fromTable = $fk['TABLE_NAME'];
                $toTable = $fk['REFERENCED_TABLE_NAME'];
                $fromId = preg_replace('/[^a-zA-Z0-9_]/', '_', $fromTable);
                $toId = preg_replace('/[^a-zA-Z0-9_]/', '_', $toTable);
                $label = str_replace('"', "'", $fk['COLUMN_NAME'] . ' ⇒ ' . $fk['REFERENCED_COLUMN_NAME']);
                $fromLabel = str_replace('"', "'", $fromTable);
                $toLabel = str_replace('"', "'", $toTable);
                $edge = sprintf('%s["%s"] -->|%s| %s["%s"];', $fromId, $fromLabel, $label, $toId, $toLabel);
                $edgeMap[$edge] = true;
            }
            if (!empty($edgeMap)) {
                $relationshipDiagram = "graph LR;\n" . implode("\n", array_keys($edgeMap));
            }
            $relPlantuml = [
                "@startuml",
                "!theme cyborg",
                "hide circle",
                "skinparam BackgroundColor #000000",
                "skinparam defaultFontColor #f5f5f5",
                "skinparam Shadowing false",
                "skinparam entity {",
                "  BackgroundColor #1a1a1a",
                "  BorderColor #555555",
                "  FontColor #f5f5f5",
                "}",
                "skinparam note {",
                "  BackgroundColor #111111",
                "  FontColor #f5f5f5",
                "}"
            ];
            foreach ($fkRows as $fk) {
                $from = mermaid_identifier($fk['TABLE_NAME']);
                $to = mermaid_identifier($fk['REFERENCED_TABLE_NAME']);
                $relLabel = mermaid_relation_label($fk['COLUMN_NAME'], $fk['REFERENCED_COLUMN_NAME']);
                $relPlantuml[] = "entity \"{$fk['TABLE_NAME']}\" as {$from}";
                $relPlantuml[] = "entity \"{$fk['REFERENCED_TABLE_NAME']}\" as {$to}";
                $relPlantuml[] = "{$from}::{$fk['COLUMN_NAME']} --> {$to}::{$fk['REFERENCED_COLUMN_NAME']} : {$relLabel}";
            }
            $relPlantuml[] = "@enduml";
            $relationshipPlantumlEncoded = plantuml_encode(implode("\n", $relPlantuml));
        }
    } catch (Exception $e) {
        $relationshipDiagram = '';
        $relationshipPlantumlEncoded = null;
    }

    try {
        $tablesStmt = $pdo->query("SHOW TABLES");
        $diagramParts = ["erDiagram"];
        $relations = [];
        $fkStmt = $pdo->prepare("
            SELECT 
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE 
                TABLE_SCHEMA = :schema AND
                REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $fkStmt->execute(['schema' => $DB_NAME]);
        $schemaFks = $fkStmt->fetchAll();
        $tableColumnsMap = [];

        while ($tblRow = $tablesStmt->fetch(PDO::FETCH_NUM)) {
            $tableName = $tblRow[0];
            $tableId = mermaid_identifier($tableName);
            $describeStmt = $pdo->query("DESCRIBE `$tableName`");
            $columns = $describeStmt->fetchAll(PDO::FETCH_ASSOC);
            $tableColumnsMap[$tableName] = $columns;
            $columnLines = [];
            foreach ($columns as $col) {
                $type = mermaid_datatype($col['Type']);
                $colName = mermaid_column_name($col['Field']);
                $suffix = mermaid_column_suffix($col);
                $columnLines[] = "    {$type} {$colName}{$suffix}";
            }
            if (!empty($columnLines)) {
                $diagramParts[] = "{$tableId} {";
                $diagramParts = array_merge($diagramParts, $columnLines);
                $diagramParts[] = "}";
            }
        }
        if (!empty($schemaFks)) {
            foreach ($schemaFks as $fk) {
                $from = mermaid_identifier($fk['TABLE_NAME']);
                $to = mermaid_identifier($fk['REFERENCED_TABLE_NAME']);
                $fromCol = mermaid_column_name($fk['COLUMN_NAME']);
                $relationLabel = mermaid_relation_label($fk['COLUMN_NAME'], $fk['REFERENCED_COLUMN_NAME']);
                $diagramParts[] = "{$from} }o--|| {$to} : {$relationLabel}";
            }
        }
        if (!empty($diagramParts)) {
            $erdDiagram = implode("\n", $diagramParts);
            $plantumlLines = [
                "@startuml",
                "!theme cyborg",
                "hide circle",
                "skinparam BackgroundColor #000000",
                "skinparam defaultFontColor #f5f5f5",
                "skinparam Shadowing false",
                "skinparam entity {",
                "  BackgroundColor #1a1a1a",
                "  BorderColor #555555",
                "  FontColor #f5f5f5",
                "}",
                "skinparam note {",
                "  BackgroundColor #111111",
                "  FontColor #f5f5f5",
                "}"
            ];
            foreach ($tableColumnsMap as $tName => $columns) {
                $tAlias = mermaid_identifier($tName);
                $plantumlLines[] = "entity \"{$tName}\" as {$tAlias} {";
                foreach ($columns as $col) {
                    $colLabel = $col['Field'];
                    $colType = strtoupper($col['Type']);
                    $flags = [];
                    if ($col['Key'] === 'PRI') $flags[] = 'PK';
                    if ($col['Key'] === 'UNI') $flags[] = 'UQ';
                    if ($col['Key'] === 'MUL') $flags[] = 'IDX';
                    if ($col['Null'] === 'NO') $flags[] = 'NOT_NULL';
                    if (!empty($col['Extra'])) $flags[] = strtoupper(str_replace(' ', '_', $col['Extra']));
                    $flagStr = $flags ? ' <<' . implode(',', $flags) . '>>' : '';
                    $plantumlLines[] = "  {$colLabel} : {$colType}{$flagStr}";
                }
                $plantumlLines[] = "}";
            }
            if (!empty($schemaFks)) {
                foreach ($schemaFks as $fk) {
                    $from = mermaid_identifier($fk['TABLE_NAME']);
                    $to = mermaid_identifier($fk['REFERENCED_TABLE_NAME']);
                    $fromCol = $fk['COLUMN_NAME'];
                    $toCol = $fk['REFERENCED_COLUMN_NAME'];
                    $plantumlLines[] = "{$from}::{$fromCol} --> {$to}::{$toCol}";
                }
            }
            $plantumlLines[] = "@enduml";
            $plantumlDiagramEncoded = plantuml_encode(implode("\n", $plantumlLines));
        } else {
            $plantumlDiagramEncoded = null;
        }
    } catch (Exception $e) {
        $erdDiagram = '';
        $plantumlDiagramEncoded = null;
    }
}

$currentTable = isset($_GET['table']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table']) : null;
$view = isset($_GET['view']) ? $_GET['view'] : 'structure'; 

$tableData = [];
$tableStructure = [];
$tableColumns = [];
$limit = 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$primaryKey = $currentTable ? getPrimaryKey($pdo, $currentTable) : null;

// Search Params
$searchColumn = $_GET['search_col'] ?? '';
$searchOp = $_GET['search_op'] ?? 'LIKE';
$searchVal = $_GET['search_val'] ?? '';

// Sort Params
$orderBy = $_GET['order_by'] ?? null;
$orderDir = $_GET['order_dir'] ?? 'ASC';

if ($is_logged_in && $currentTable && isset($pdo)) {
    $stmt = $pdo->query("DESCRIBE `$currentTable`");
    $tableStructure = $stmt->fetchAll();
    $tableColumns = array_column($tableStructure, 'Field');

    if ($view === 'data') {
        $sql = "SELECT * FROM `$currentTable`";
        $params = [];
        
        if ($searchVal !== '') {
            $op = '=';
            $val = $searchVal;
            
            if ($searchOp === 'LIKE') {
                $op = 'LIKE';
                $val = "%$searchVal%";
            } elseif (in_array($searchOp, ['=', '!=', '>', '<', '>=', '<='])) {
                $op = $searchOp;
            }
            
            if ($searchColumn && in_array($searchColumn, $tableColumns)) {
                $sql .= " WHERE `$searchColumn` $op ?";
                $params[] = $val;
            } else {
                // Global search
                $where = [];
                foreach ($tableColumns as $col) {
                    $where[] = "`$col` LIKE ?";
                    $params[] = "%$searchVal%";
                }
                if ($where) $sql .= " WHERE " . implode(" OR ", $where);
            }
        }
        
        // Add sorting
        if ($orderBy && in_array($orderBy, $tableColumns)) {
            $orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
            $sql .= " ORDER BY `$orderBy` $orderDir";
        }
        
        $sql .= " LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tableData = $stmt->fetchAll();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB Manager <?= $is_logged_in ? '- ' . htmlspecialchars($_SESSION['db_name']) : '' ?></title>
    <link rel="stylesheet" href="<?= get_asset_url('assets/vendor/fontawesome6/fontawesome-free-6.5.1-web/css/all.min.css', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= get_asset_url('assets/vendor/sweetalert2/sweetalert2-dark.min.css', 'https://cdn.jsdelivr.net/npm/@sweetalert2/theme-dark@5/dark.css') ?>"> <!-- SweetAlert2 Dark Theme -->
    <link href="<?= get_asset_url('assets/vendor/tom-select/tom-select.bootstrap5.min.css', 'https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.min.css') ?>" rel="stylesheet">
    <script src="<?= get_asset_url('assets/vendor/sweetalert2/sweetalert2.all.min.js', 'https://cdn.jsdelivr.net/npm/sweetalert2@11') ?>"></script>
    <script src="<?= get_asset_url('assets/vendor/mermaid/mermaid.min.js', 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js') ?>"></script>
    <script src="<?= get_asset_url('assets/vendor/tom-select/tom-select.complete.min.js', 'https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js') ?>"></script>
    <style>
        :root {
            --bg-body: #050505;
            --bg-sidebar: #0f0f0f;
            --bg-card: #141414;
            --bg-hover: #1f1f1f;
            --bg-input: #1a1a1a;
            --border-color: #333333;
            --text-primary: #e0e0e0;
            --text-secondary: #888888;
            --accent: #3b82f6;
            --danger: #ef4444;
            --success: #10b981;
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 50px; /* New variable for collapsed width */
        }
        /* TomSelect Dark Mode Fixes */
        .ts-control { background-color: var(--bg-input) !important; color: var(--text-primary) !important; border-color: var(--border-color) !important; border-radius: 4px; }
        .ts-control input { color: var(--text-primary) !important; }
        .ts-dropdown { background-color: var(--bg-card) !important; color: var(--text-primary) !important; border-color: var(--border-color) !important; }
        .ts-dropdown .option { color: var(--text-primary) !important; }
        .ts-dropdown .active { background-color: var(--accent) !important; color: white !important; }
        .ts-wrapper.single .ts-control:after { border-color: #888 transparent transparent transparent !important; }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', system-ui, sans-serif; 
            background: var(--bg-body); 
            color: var(--text-primary); 
            height: 100vh; 
            display: flex; 
            font-size: 14px; 
            overflow: hidden; /* Prevent body scroll when sidebar is open */
        }
        a { text-decoration: none; color: inherit; transition: 0.2s; }
        
        /* SCROLLBAR */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-body); }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }

        /* LOGIN SCREEN */
        .login-wrapper { position: fixed; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: var(--bg-body); z-index: 1000; }
        .login-box { width: 100%; max-width: 400px; padding: 40px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .login-header { text-align: center; margin-bottom: 30px; font-size: 1.5rem; font-weight: bold; }
        .login-btn { width: 100%; padding: 12px; background: var(--accent); color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; margin-top: 10px; }
        .login-btn:hover { opacity: 0.9; }

        /* LAYOUT */
        .sidebar { 
            width: var(--sidebar-width); 
            background: var(--bg-sidebar); 
            border-right: 1px solid var(--border-color); 
            display: flex; 
            flex-direction: column; 
            height: 100%; 
            flex-shrink: 0; 
            transition: width 0.3s ease; 
            position: relative; 
            z-index: 10; 
        }
        .sidebar.collapsed { 
            width: var(--sidebar-collapsed-width); 
        }
        .sidebar.collapsed .brand span, 
        .sidebar.collapsed .db-info small span, 
        .sidebar.collapsed .nav-header span, 
        .sidebar.collapsed .nav-item span,
        .sidebar.collapsed #tableSearch { /* Hide search input as well */
            display: none; 
        }
        .sidebar.collapsed .nav-item { 
            justify-content: center; 
            padding: 8px 0; 
        }
        .sidebar.collapsed .sidebar-toggle {
            right: -33px;
            margin-top: 260px;
        }

        .main-content { 
            flex: 1; 
            display: flex; 
            flex-direction: column; 
            overflow-y: auto; /* Allow main content to scroll */
            width: 100%; /* Take full available width */
            transition: margin-left 0.3s ease; 
        }        
        /* SIDEBAR COMPONENTS */
        .brand { padding: 20px; font-size: 1.1rem; font-weight: 700; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 10px; color: var(--accent); }
        .db-info { padding: 15px 20px; font-size: 0.85rem; color: var(--text-secondary); border-bottom: 1px solid var(--border-color); background: #0a0a0a; }
        .nav-list { flex: 1; overflow-y: auto; padding: 10px 0; }
        .nav-item { padding: 8px 20px; display: flex; align-items: center; gap: 10px; color: var(--text-secondary); cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .nav-item:hover, .nav-item.active { background: var(--bg-hover); color: var(--text-primary); border-left: 3px solid var(--accent); }
        .nav-header { padding: 15px 20px 5px; font-size: 0.75rem; text-transform: uppercase; color: #555; font-weight: bold; margin-top: 10px; }
        .sidebar-toggle { 
            cursor: pointer; 
            font-size: 1.2rem; 
            color: var(--text-secondary); 
            padding: 10px; 
            position: absolute; 
            top: 10px; 
            right: -33px; 
            background: var(--bg-sidebar); 
            border: 1px solid var(--border-color); 
            border-left: none; 
            border-radius: 0 4px 4px 0; 
            z-index: 10; 
            display: flex; /* Ensure it's always displayed */
            align-items: center;
            justify-content: center;
            margin-top:260px;
        }
        .sidebar-toggle:hover { color: var(--text-primary); }

        /* TOP BAR */
        .top-bar { height: 50px; background: var(--bg-card); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; padding: 0 20px; justify-content: space-between; }
        .breadcrumb { display: flex; gap: 8px; color: var(--text-secondary); font-size: 0.9rem; align-items: center; }
        .breadcrumb span { color: var(--text-primary); font-weight: 500; }
        .logout-link { font-size: 0.85rem; color: var(--danger); }
        .logout-link:hover { text-decoration: underline; }

        /* CONTENT */
        .content-area { flex: 1; overflow-y: auto; padding: 20px; }
        
        /* GENERIC COMPONENTS */
        .card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 6px; padding: 20px; margin-bottom: 20px; }
        .btn { padding: 6px 12px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--bg-hover); color: var(--text-primary); cursor: pointer; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px; }
        .btn:hover { background: #333; border-color: #555; }
        .btn-primary { background: var(--accent); border-color: var(--accent); color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-danger { background: rgba(239, 68, 68, 0.1); border-color: var(--danger); color: var(--danger); }
        .btn-danger:hover { background: var(--danger); color: white; }
        
        .form-control, .form-select { width: 100%; padding: 8px 10px; background: var(--bg-input); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 4px; font-size: 0.9rem; }
        .form-control:focus, .form-select:focus { outline: none; border-color: var(--accent); }
        
        .alert { padding: 12px 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid transparent; font-size: 0.9rem; }
        .alert-success { background: rgba(16, 185, 129, 0.1); border-color: var(--success); color: var(--success); }
        .alert-danger { background: rgba(239, 68, 68, 0.1); border-color: var(--danger); color: var(--danger); }

        /* DATA TABLES */
        .table-wrapper { border: 1px solid var(--border-color); border-radius: 6px; overflow-x: auto; background: var(--bg-card); max-height: 80vh; }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th, td { padding: 10px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background: #1a1a1a; font-weight: 600; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase; white-space: nowrap; position: sticky; top: 0; z-index: 5; box-shadow: 0 2px 5px rgba(0,0,0,0.5); }
        tr:hover td { background: var(--bg-hover); }
        td { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* TABS */
        .tabs { display: flex; gap: 2px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); }
        .tab { padding: 10px 20px; cursor: pointer; border-bottom: 2px solid transparent; color: var(--text-secondary); font-weight: 500; }
        .tab:hover { color: var(--text-primary); background: var(--bg-hover); border-radius: 4px 4px 0 0; }
        .tab.active { border-bottom-color: var(--accent); color: var(--accent); }

        /* ADVANCED SEARCH */
        .search-bar { display: flex; gap: 10px; flex-wrap: wrap; background: var(--bg-hover); padding: 10px; border-radius: 6px; margin-bottom: 15px; border: 1px solid var(--border-color); align-items: center; }
        .search-group { display: flex; gap: 0; }
        .search-group .form-control, .search-group .form-select { border-radius: 0; }
        .search-group *:first-child { border-radius: 4px 0 0 4px; }
        .search-group *:last-child { border-radius: 0 4px 4px 0; border-left: none; }

        .dashboard-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .stat-card { background: var(--bg-card); padding: 20px; border: 1px solid var(--border-color); border-radius: 6px; }
        .stat-val { font-size: 1.8rem; font-weight: bold; margin: 10px 0 0; }
        .stat-label { color: var(--text-secondary); font-size: 0.85rem; text-transform: uppercase; }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .sidebar.collapsed {
                width: var(--sidebar-collapsed-width); /* Ensure it stays collapsed */
            }
            /* Adjust sidebar toggle position for mobile if needed, or rely on existing */
            .sidebar-toggle {
                right: -33px;
                margin-top: 260px;
            }
            .search-bar { flex-direction: column; align-items: stretch; }
            .search-group { width: 100%; }
            body {
                flex-direction: row; /* Ensure sidebar and main content stay side-by-side */
            }
        }
        /* --- TOOL MODAL STYLES --- */
.swal2-tabs {
    display: flex;
    border-bottom: 1px solid #333;
    margin-bottom: 15px;
}
.swal2-tabs button {
    background: transparent;
    border: none;
    color: var(--text-secondary);
    padding: 10px 15px;
    cursor: pointer;
    font-weight: 600;
    border-bottom: 2px solid transparent;
    transition: 0.2s;
}
.swal2-tabs button:hover { color: var(--text-primary); }
.swal2-tabs button.active {
    color: var(--accent);
    border-bottom-color: var(--accent);
}
.swal2-tab-content { display: none; }
.swal2-tab-content.active { display: block; }

/* Custom Inputs inside SweetAlert */
.swal2-input, .swal2-textarea, .swal2-select, .swal2-range {
    background-color: var(--bg-input) !important;
    border: 1px solid var(--border-color) !important;
    color: var(--text-primary) !important;
    border-radius: 4px !important;
    font-size: 14px;
}
.swal2-checkbox label {
    color: var(--text-primary) !important;
    font-size: 14px;
    margin-left: 5px;
    cursor: pointer;
}
.tool-row {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
    justify-content: center;
}
.tool-label {
    width: 100px;
    color: var(--text-secondary);
    font-size: 13px;
}
.tool-result {
    background: #000;
    border: 1px solid #333;
    padding: 10px;
    color: var(--accent);
    font-family: monospace;
    font-size: 13px;
    word-break: break-all;
    position: relative;
}
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <i class="fas fa-database"></i> <span>Adminer Lite</span>
        </div>
        <div class="db-info">
            <form method="GET" style="margin-bottom: 5px;">
                <select name="select_db" onchange="this.form.submit()" class="form-select" style="padding: 2px 5px; font-size: 0.8rem; background: #222; color: white; border: 1px solid #444; width: 100%;">
                    <option value="">-- Pilih Database --</option>
                    <?php foreach ($databases as $db): ?>
                        <option value="<?=htmlspecialchars($db)?>" <?=$db === $_SESSION['db_name'] ? 'selected' : ''?>>
                            <?=htmlspecialchars($db)?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <small><i class="fas fa-server"></i> <span><?=htmlspecialchars($_SESSION['db_host'])?></span></small>
        </div>
        <div class="nav-list">
            <div style="padding: 0 20px 10px;">
                <input type="text" id="tableSearch" class="form-control" placeholder="Search tables..." style="width: 100%;">
            </div>
            <a href="?" class="nav-item <?=!$currentTable ? 'active' : ''?>">
                <i class="fas fa-tachometer-alt" style="width:20px; text-align:center;"></i> <span>Dashboard</span>
            </a>
            <div class="nav-header"><span>Tables (<?=count($tables)?>)</span></div>
            <?php foreach ($tables as $t): ?>
                <a href="?table=<?=htmlspecialchars($t['Name'])?>" class="nav-item <?=$currentTable === $t['Name'] ? 'active' : ''?>">
                    <i class="fas fa-table" style="width:20px; text-align:center;"></i> 
                    <span><?=htmlspecialchars($t['Name'])?> <small>(<?=formatSize($t['Data_length'] + $t['Index_length'])?>)</small></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="sidebar-toggle" id="sidebarToggle">
            <i class="fas fa-angle-left"></i>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb">
                <a href="?" style="text-decoration:none;"><i class="fas fa-home"></i> <span>Dashboard</span></a>
                <?php if ($currentTable): ?>
                    <span style="color:var(--text-secondary);">/</span>
                    <span><?=htmlspecialchars($currentTable)?></span>
                <?php endif; ?>
            </div>
            <div style="display:flex; align-items:center; gap:15px;">
                <div style="display:flex; gap:10px; margin-right:10px; border-right:1px solid #333; padding-right:15px;">
                    <a href="#" onclick="openToolsModal()" title="Generator Tools" style="color:var(--text-secondary); font-size:1.1rem;">
                       <i class="fas fa-key"></i>
                   </a>
                    <a href="index.php" title="Dashboard"><i class="fas fa-th"></i></a>
                    <a href="filemanager.php" title="File Manager"><i class="fas fa-folder"></i></a>
                </div>
                <span style="color:var(--text-secondary); font-size:0.85rem;"><i class="fas fa-user"></i> <?=htmlspecialchars($_SESSION['db_user'])?></span>
                <a href="?logout=1" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <div class="content-area">
            <?php if ($msg): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?=htmlspecialchars($msg)?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?=htmlspecialchars($error)?></div>
            <?php endif; ?>

            <?php if (!$hasSelectedDatabase): ?>
                <div class="card">
                    <h3>Pilih Database</h3>
                    <p style="color:var(--text-secondary); line-height:1.6;">
                        Kredensial sudah disimpan. Silakan pilih database dari dropdown di sidebar atau kelola daftar database
                        melalui modul manajemen di bawah.
                    </p>
                </div>

                <?php 
                $configList = load_config($configFile)['databases'] ?? [];
                if (should_show_managed_database_list($hostProfile)): ?>
                <!-- MANAGEMENT UI -->
                <div class="card">
                    <h3><i class="fas fa-list"></i> Managed Database List (JSON)</h3>
                    <p style="color:var(--text-secondary); margin-bottom:15px;">List of databases stored in <code>adminer.config.json</code>. These appear in the sidebar dropdown.</p>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Database Name</th>
                                    <th style="width:100px; text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $configList = load_config($configFile)['databases'] ?? [];
                                foreach ($configList as $dbItem): 
                                    $isActive = ($dbItem === ($_SESSION['db_name'] ?? ''));
                                ?>
                                <tr style="<?= $isActive ? 'background:rgba(16, 185, 129, 0.1); color: var(--success)' : '' ?>">
                                    <td>
                                        <i class="fas fa-database" style="color:<?=$isActive ? 'var(--success)' : 'var(--accent)'?>; margin-right:8px;"></i> 
                                        <?=htmlspecialchars($dbItem)?>
                                        <?php if($isActive): ?>
                                            <span style="font-size:0.75rem; background:var(--success); color:white; padding:2px 6px; border-radius:4px; margin-left:8px;">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php if(!$isActive): ?>
                                            <a href="?select_db=<?=urlencode($dbItem)?>" class="btn btn-primary" style="padding:4px 8px; font-size:0.8rem; margin-right:5px;" title="Use Database"><i class="fas fa-gear"></i></a>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="saConfirmForm(event, 'Remove <?=htmlspecialchars($dbItem)?> from list?')" style="display:inline;">
                                            <input type="hidden" name="action" value="remove_database_list">
                                            <input type="hidden" name="name" value="<?=htmlspecialchars($dbItem)?>">
                                            <button type="submit" class="btn btn-danger" style="padding:4px 8px; font-size:0.8rem;" title="Remove from list"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($configList)): ?>
                                    <tr><td colspan="2" style="text-align:center; color:var(--text-secondary);">No databases in list.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <form method="POST" style="margin-top:15px; display:flex; gap:10px;">
                        <input type="hidden" name="action" value="add_database_list">
                        <input type="text" name="name" class="form-control" placeholder="Database Name" required pattern="[A-Za-z0-9_$-]+" style="max-width:300px;">
                        <button type="submit" class="btn btn-primary">Add to List</button>
                    </form>
                </div>
                <?php endif; ?>
                <?php if (should_show_server_database_panel($hostProfile)): ?>
                <div class="card">
                    <h3><i class="fas fa-server"></i> Server Databases</h3>
                    <p style="color:var(--text-secondary); margin-bottom:15px;">Databases actually existing on the connected server.</p>
                    
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Database Name</th>
                                    <th style="width:150px; text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $serverDbs = [];
                                try {
                                    $stmt = $pdo->query("SHOW DATABASES");
                                    $serverDbs = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                } catch (Exception $e) {
                                    if ($e->getCode() == 1227 || stripos($e->getMessage(), '1227') !== false) {
                                        echo "<tr><td colspan='2' style='color:var(--text-secondary); font-style:italic;'>Listing databases is disabled on this server (Access Denied). Use the 'Managed Database List' above to add your database manually.</td></tr>";
                                    } else {
                                        echo "<tr><td colspan='2' style='color:var(--danger);'>Error fetching databases: ".htmlspecialchars($e->getMessage())."</td></tr>";
                                    }
                                }

                                $configList = load_config($configFile)['databases'] ?? [];
                                foreach ($serverDbs as $dbItem): 
                                    $inList = in_array($dbItem, $configList);
                                ?>
                                <tr>
                                    <td><?=htmlspecialchars($dbItem)?></td>
                                    <td style="text-align:right;">
                                        <?php if($dbItem !== ($_SESSION['db_name'] ?? '')): ?>
                                            <a href="?select_db=<?=urlencode($dbItem)?>" class="btn btn-primary" style="padding:4px 8px; font-size:0.8rem;" title="Use Database"><i class="fas fa-gear"></i></a>
                                        <?php else: ?>
                                            <span style="font-size:0.75rem; background:var(--success); color:white; padding:4px 8px; border-radius:4px; margin-right:5px; display:inline-block;">Active</span>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="saConfirmForm(event, 'DROP DATABASE <?=htmlspecialchars($dbItem)?>? THIS DESTROYS ALL DATA!')" style="display:inline;">
                                            <input type="hidden" name="action" value="drop_database_server">
                                            <input type="hidden" name="name" value="<?=htmlspecialchars($dbItem)?>">
                                            <button type="submit" class="btn btn-danger" style="padding:4px 8px; font-size:0.8rem;" title="Drop Database"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form method="POST" style="margin-top:15px; display:flex; gap:10px; align-items:center;">
                        <input type="hidden" name="action" value="create_database_server">
                        <input type="text" name="name" class="form-control" placeholder="New Database Name" required pattern="[A-Za-z0-9_$-]+" style="max-width:300px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Create Database</button>
                    </form>
                </div>
                <?php endif; ?>
            <?php elseif ($currentTable):
                ?>
                <!-- TABLE VIEW -->
                <div class="tabs">
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=structure" class="tab <?=$view==='structure'?'active':''?>">Structure</a>
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=data" class="tab <?=$view==='data'?'active':''?>">Data</a>
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=sql" class="tab <?=$view==='sql'?'active':''?>">SQL</a>
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=import" class="tab <?=$view==='import'?'active':''?>">Import</a>
                    <div style="flex:1;"></div>
                    <!-- Export Button -->
                     <form method="POST" style="margin:0; display:flex;">
                        <input type="hidden" name="action" value="export">
                        <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                        <select name="format" class="form-select" style="border-radius:4px 0 0 4px; border-right:none; width:auto; padding:5px 10px; font-size:0.85rem;">
                            <option value="sql">SQL</option>
                            <option value="json">JSON</option>
                            <option value="csv">CSV</option>
                        </select>
                        <button type="submit" class="btn" style="border-radius:0 4px 4px 0;"><i class="fas fa-download"></i> Export</button>
                    </form>
                </div>

                <?php if ($view === 'data'): 
                    ?>
                    <!-- ADVANCED SEARCH -->
                    <div style="margin-bottom:15px;">
                        <form class="search-bar" method="GET" style="margin-bottom:5px;">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <input type="hidden" name="view" value="data">
                            
                            <div class="search-group" style="flex:1;">
                                <select name="search_col" class="form-select" style="width: 150px; background: var(--bg-card);">
                                    <option value="">- All Cols -</option>
                                    <?php foreach($tableColumns as $col):
                                        ?><option value="<?=htmlspecialchars($col)?>" <?=$searchColumn===$col?'selected':''?>><?=htmlspecialchars($col)?></option><?php 
                                    endforeach; ?>
                                </select>
                                <select name="search_op" class="form-select" style="width: 100px; background: var(--bg-card); border-left:1px solid var(--border-color);">
                                    <option value="LIKE" <?=$searchOp==='LIKE'?'selected':''?>>LIKE</option>
                                    <option value="=" <?=$searchOp==='='?'selected':''?>>=</option>
                                    <option value="!=" <?=$searchOp==='!='?'selected':''?>>!=</option>
                                    <option value=">" <?=$searchOp==='>'?'selected':''?>>&gt;</option>
                                    <option value="<" <?=$searchOp==='<'?'selected':''?>>&lt;</option>
                                </select>
                                <input type="text" name="search_val" class="form-control" placeholder="Server-side Search..." value="<?=htmlspecialchars($searchVal)?>" style="width: 100%;">
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                            <?php if($searchVal):
                                ?><a href="?table=<?=htmlspecialchars($currentTable)?>&view=data" class="btn btn-danger"><i class="fas fa-times"></i></a><?php 
                            endif; ?>
                        </form>
                        
                        <!-- Client-side Controls -->
                        <div style="display:flex; gap:10px; align-items:center; background:var(--bg-hover); padding:10px; border-radius:6px; border:1px solid var(--border-color);">
                            <div style="flex:1; display:flex; gap:10px; align-items:center;">
                                <i class="fas fa-filter" style="color:var(--text-secondary);"></i>
                                <input type="text" id="pageFilterInput" class="form-control" placeholder="Realtime Filter (Displayed Rows)..." style="max-width:300px;">
                            </div>
                            
                            <div style="position:relative;">
                                <button type="button" class="btn" onclick="document.getElementById('colToggleDropdown').classList.toggle('show')">
                                    <i class="fas fa-columns"></i> Columns <i class="fas fa-caret-down" style="margin-left:5px;"></i>
                                </button>
                                <div id="colToggleDropdown" style="display:none; position:absolute; right:0; top:100%; background:var(--bg-card); border:1px solid var(--border-color); border-radius:6px; padding:10px; z-index:100; min-width:200px; box-shadow:0 10px 20px rgba(0,0,0,0.5); max-height:300px; overflow-y:auto;">
                                    <div style="margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid #333; font-weight:bold; font-size:0.85rem;">Toggle Columns</div>
                                    <!-- Populated by JS -->
                                </div>
                            </div>

                            <div style="margin-left:auto; display:flex; gap:10px;">
                                <button type="button" onclick="submitBulkDelete()" class="btn btn-danger" id="bulkDeleteBtn" style="display:none;"><i class="fas fa-trash"></i> Delete Selected</button>
                                <a href="?table=<?=htmlspecialchars($currentTable)?>&view=form" class="btn btn-primary"><i class="fas fa-plus"></i> New Row</a>
                            </div>
                        </div>
                    </div>

                    <form method="POST" id="bulkForm">
                        <input type="hidden" name="action" value="bulk_delete">
                        <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                        <input type="hidden" name="pk" value="<?=htmlspecialchars($primaryKey)?>">
                        
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width: 40px; text-align:center;"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)"></th>
                                        <th style="width: 80px;">Action</th>
                                    <?php foreach ($tableColumns as $col):
                                        $newOrderDir = 'ASC';
                                        $sortIcon = '';
                                        if ($orderBy === $col) {
                                            $newOrderDir = ($orderDir === 'ASC') ? 'DESC' : 'ASC';
                                            $sortIcon = ($orderDir === 'ASC') ? ' <i class="fas fa-sort-up"></i>' : ' <i class="fas fa-sort-down"></i>';
                                        }
                                        $sortLink = "?table=" . htmlspecialchars($currentTable) . "&view=data"
                                                    . "&search_col=" . urlencode($searchColumn)
                                                    . "&search_op=" . urlencode($searchOp)
                                                    . "&search_val=" . urlencode($searchVal)
                                                    . "&order_by=" . urlencode($col)
                                                    . "&order_dir=" . urlencode($newOrderDir);
                                        ?><th data-col="<?=htmlspecialchars($col)?>"><a href="<?=$sortLink?>" style="color:inherit; text-decoration:none; display:flex; align-items:center; justify-content:space-between;"><?=$sortIcon?><?=htmlspecialchars($col)?></a></th><?php 
                                    endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tableData as $row):
                                    ?>
                                    <tr>
                                        <td style="text-align:center;">
                                            <?php if($primaryKey): ?>
                                                <input type="checkbox" name="ids[]" value="<?=htmlspecialchars($row[$primaryKey])?>" class="row-checkbox" onclick="updateBulkBtn()">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($primaryKey):
                                                ?><a href="?table=<?=htmlspecialchars($currentTable)?>&view=form&pk=<?=urlencode($primaryKey)?>&val=<?=urlencode($row[$primaryKey])?>" style="margin-right:5px; color:var(--accent);" title="Edit Row"><i class="fas fa-edit"></i></a><?php 
                                                ?><a href="?table=<?=htmlspecialchars($currentTable)?>&view=form&pk=<?=urlencode($primaryKey)?>&val=<?=urlencode($row[$primaryKey])?>&mode=copy" style="margin-right:5px; color:#fbbf24;" title="Copy Row"><i class="fas fa-copy"></i></a><?php 
                                                ?><a href="?table=<?=htmlspecialchars($currentTable)?>&action=delete_row&pk=<?=urlencode($primaryKey)?>&val=<?=urlencode($row[$primaryKey])?>" onclick="saConfirmLink(event, 'Delete this row permanently?')" style="color:var(--danger);" title="Delete Row"><i class="fas fa-trash"></i></a><?php 
                                            else:
                                                ?><span style="opacity:0.3">-</span><?php 
                                            endif; ?>
                                        </td>
                                        <?php foreach ($row as $key => $val):
                                            $displayVal = $val !== null ? htmlspecialchars((string)$val) : '<span style="color:#666">NULL</span>';
                                            // Clickable Foreign Keys Logic
                                            if ($val !== null && substr($key, -3) === '_id') {
                                                $targetTable = substr($key, 0, -3) . 's'; // simple pluralization
                                                // Check if table exists (optional, skipping for speed)
                                                $displayVal = "<a href='?table=$targetTable&view=data&search_col=id&search_op==&search_val=" . urlencode($val) . "' style='color:var(--accent); text-decoration:underline;'>$displayVal</a>";
                                            }
                                            ?><td data-col="<?=htmlspecialchars($key)?>" title="<?=htmlspecialchars((string)$val)?>"><?=$displayVal?></td><?php 
                                        endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if(empty($tableData)):
                                    ?><td colspan="<?=count($tableColumns)+1?>" style="text-align:center; padding:30px; color:var(--text-secondary);">No data found</td><?php 
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination Simple -->
                    <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: flex-end;">
                        <?php 
                        $pagination_params = "&search_col=" . urlencode($searchColumn)
                                            . "&search_op=" . urlencode($searchOp)
                                            . "&search_val=" . urlencode($searchVal)
                                            . "&order_by=" . urlencode($orderBy ?? '')
                                            . "&order_dir=" . urlencode($orderDir);
                        if($offset > 0):
                            ?><a href="?table=<?=htmlspecialchars($currentTable)?>&view=data&offset=<?=max(0, $offset-$limit)?><?=$pagination_params?>" class="btn">Previous</a><?php 
                        endif; ?>
                        <?php if(count($tableData) >= $limit):
                            ?><a href="?table=<?=htmlspecialchars($currentTable)?>&view=data&offset=<?=$offset+$limit?><?=$pagination_params?>" class="btn">Next</a><?php 
                        endif; ?>
                    </div>
                    </form>

                    <script>
                        // ===== GENERATOR TOOLS LOGIC =====
function openToolsModal() {
    Swal.fire({
        title: '<span style="color:var(--text-primary)">Generator Tools</span>',
        html: `
            <div class="swal2-tabs">
                <button class="active" onclick="switchToolTab(this, 'tool-php-hash')" style="font-weight:bold; color:#0d6efd;">PHP Bcrypt</button>
                <button onclick="switchToolTab(this, 'tool-hash')">Hash</button>
                <button onclick="switchToolTab(this, 'tool-uuid')">UUID</button>
                <button onclick="switchToolTab(this, 'tool-base64')">Base64</button>
            </div>

            <div id="tool-php-hash" class="swal2-tab-content">
                <p style="color:var(--text-secondary); font-size:13px; margin-bottom:10px;">
                    Generate hash PHP (<b>Bcrypt</b>) sesuai format <code>$2y$10$...</code>. Cocok untuk database MySQL Native PHP atau Laravel.
                </p>
                
                <div class="tool-row">
                    <input type="text" id="phpHashInput" class="swal2-input" placeholder="Masukkan password plain text..." autocomplete="off">
                </div>
                
                <div class="tool-row">
                    <span class="tool-label">Result:</span>
                    <div id="phpHashResult" class="tool-result" style="flex:1;">Hash will appear here...</div>
                </div>

                <div style="margin-top:15px; text-align:right;">
                    <button class="swal2-confirm swal2-styled" id="btnGenPhpHash" style="background-color:var(--accent); margin-right:5px;" onclick="generatePhpHash()">Generate Hash</button>
                    <button class="swal2-styled" style="background-color:#444; border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius);" onclick="copyToClipboard(document.getElementById('phpHashResult').innerText)">Copy</button>
                </div>
            </div>

            <!-- HASH GENERATOR -->
            <div id="tool-hash" class="swal2-tab-content">
                <div class="tool-row">
                    <select id="hashAlgo" class="swal2-select">
                        <option value="SHA-1">SHA-1</option>
                        <option value="SHA-256" selected>SHA-256</option>
                        <option value="SHA-384">SHA-384</option>
                        <option value="SHA-512">SHA-512</option>
                    </select>
                </div>
                <textarea id="hashInput" class="swal2-textarea" placeholder="Enter text to hash..." rows="3"></textarea>
                <div class="tool-result" id="hashResult">Hash will appear here...</div>
                <div style="margin-top:10px; text-align:right;">
                    <button class="swal2-confirm swal2-styled" style="background-color:var(--accent); margin-right:5px;" onclick="generateHash()">Hash It</button>
                    <button class="swal2-styled" style="background-color:#444; border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius);" onclick="copyToClipboard(document.getElementById('hashResult').innerText)">Copy</button>
                </div>
            </div>

            <!-- UUID GENERATOR -->
            <div id="tool-uuid" class="swal2-tab-content">
                <p style="color:var(--text-secondary); font-size:13px; margin-bottom:10px;">Generate v4 Random UUIDs.</p>
                <div class="tool-result" id="uuidResult">Click Generate</div>
                <div style="margin-top:10px; text-align:right;">
                    <button class="swal2-confirm swal2-styled" style="background-color:var(--accent); margin-right:5px;" onclick="generateUUID()">Generate</button>
                    <button class="swal2-styled" style="background-color:#444; border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius);" onclick="copyToClipboard(document.getElementById('uuidResult').innerText)">Copy</button>
                </div>
            </div>

            <!-- BASE64 ENCODER -->
            <div id="tool-base64" class="swal2-tab-content">
                <textarea id="b64Input" class="swal2-textarea" placeholder="Enter string to encode/decode..." rows="3"></textarea>
                <div class="tool-row" style="margin-top:10px;">
                    <button class="swal2-styled" style="background-color:#444; border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius);" onclick="doBase64('encode')">Encode</button>
                    <button class="swal2-styled" style="background-color:#444; border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius);" onclick="doBase64('decode')">Decode</button>
                </div>
                <div class="tool-result" id="b64Result" style="margin-top:10px;">Result...</div>
                <div style="text-align:right; margin-top:5px;">
                     <button class="swal2-styled" style="background-color:#444; border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius); border-radius: var(--swal2-confirm-button-border-radius);" onclick="copyToClipboard(document.getElementById('b64Result').innerText)">Copy</button>
                </div>
            </div>
        `,
        showConfirmButton: false,
        showCloseButton: true,
        background: 'var(--bg-card)',
        customClass: {
            popup: 'dark-modal'
        }
    });
}

// Tab Switching Logic
function switchToolTab(btn, tabId) {
    // Remove active class from buttons
    document.querySelectorAll('.swal2-tabs button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    // Hide all contents
    document.querySelectorAll('.swal2-tab-content').forEach(c => c.classList.remove('active'));
    // Show target
    document.getElementById(tabId).classList.add('active');
}

// Password Logic
function generatePassword() {
    const length = document.getElementById('passLen').value;
    const useUpper = document.getElementById('chkUpper').checked;
    const useLower = document.getElementById('chkLower').checked;
    const useNumbers = document.getElementById('chkNumbers').checked;
    const useSymbols = document.getElementById('chkSymbols').checked;

    const upper = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    const lower = "abcdefghijklmnopqrstuvwxyz";
    const numbers = "0123456789";
    const symbols = "!@#$%^&*()_+~`|}{[]:;?><,./-=";

    let chars = "";
    if (useUpper) chars += upper;
    if (useLower) chars += lower;
    if (useNumbers) chars += numbers;
    if (useSymbols) chars += symbols;

    if (chars === "") {
        Swal.fire('Error', 'Please select at least one character type.', 'error');
        return;
    }

    let password = "";
    for (let i = 0; i < length; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }

    document.getElementById('genPassResult').value = password;
}

// Hash Logic (Async Web Crypto API)
async function generateHash() {
    const text = document.getElementById('hashInput').value;
    const algo = document.getElementById('hashAlgo').value;
    
    if(!text) {
        document.getElementById('hashResult').innerText = "Please enter text.";
        return;
    }

    try {
        const msgBuffer = new TextEncoder().encode(text);
        const hashBuffer = await crypto.subtle.digest(algo, msgBuffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        document.getElementById('hashResult').innerText = hashHex;
    } catch (e) {
        document.getElementById('hashResult').innerText = "Error: " + e.message;
    }
}

// UUID Logic
function generateUUID() {
    // RFC4122 version 4 UUID
    const uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
    document.getElementById('uuidResult').innerText = uuid;
}

// Base64 Logic
function doBase64(action) {
    const input = document.getElementById('b64Input').value;
    const resultBox = document.getElementById('b64Result');
    try {
        if (action === 'encode') {
            // Handle UTF-8 strings correctly
            resultBox.innerText = btoa(encodeURIComponent(input).replace(/%([0-9A-F]{2})/g,
                function toSolidBytes(match, p1) {
                    return String.fromCharCode('0x' + p1);
            }));
        } else {
            resultBox.innerText = decodeURIComponent(atob(input).split('').map(function(c) {
                return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
            }).join(''));
        }
    } catch (e) {
        resultBox.innerText = "Error: Invalid Input for " + action;
    }
}

// Clipboard Utility
function copyToClipboard(text) {
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 1500,
            background: '#28a745',
            color: '#fff'
        });
        Toast.fire({
            icon: 'success',
            title: 'Copied to clipboard'
        });
    }, (err) => {
        console.error('Async: Could not copy text: ', err);
    });
}
// Fungsi untuk mengambil hash dari API PHP
async function generatePhpHash() {
    const pass = document.getElementById('phpHashInput').value;
    const resultBox = document.getElementById('phpHashResult');
    const btn = document.getElementById('btnGenPhpHash');

    if(!pass) {
        resultBox.innerText = "Please enter a password.";
        return;
    }

    // Loading state
    const originalText = btn.innerText;
    btn.innerText = "Processing...";
    btn.disabled = true;
    resultBox.innerText = "Generating via PHP...";

    try {
        const response = await fetch('?api=generate_php_hash', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'password=' + encodeURIComponent(pass)
        });

        const data = await response.json();

        if (data.success) {
            resultBox.innerText = data.hash;
            resultBox.style.color = "var(--success)";
        } else {
            resultBox.innerText = "Error: " + (data.message || "Unknown error");
            resultBox.style.color = "var(--danger)";
        }

    } catch (error) {
        console.error(error);
        resultBox.innerText = "Connection Error: Check PHP configuration.";
        resultBox.style.color = "var(--danger)";
    } finally {
        // Restore button state
        btn.innerText = originalText;
        btn.disabled = false;
    }
}
                        function toggleSelectAll(source) {
                            const checkboxes = document.querySelectorAll('.row-checkbox');
                            for(let i=0; i<checkboxes.length; i++) {
                                checkboxes[i].checked = source.checked;
                            }
                            updateBulkBtn();
                        }
                        
                        function updateBulkBtn() {
                            const checkboxes = document.querySelectorAll('.row-checkbox:checked');
                            const btn = document.getElementById('bulkDeleteBtn');
                            if(btn) btn.style.display = checkboxes.length > 0 ? 'inline-flex' : 'none';
                        }
                        
                        function submitBulkDelete() {
                            const count = document.querySelectorAll('.row-checkbox:checked').length;
                            Swal.fire({
                                title: 'Are you sure?',
                                text: "You are about to delete " + count + " rows. This cannot be undone!",
                                icon: 'warning',
                                showCancelButton: true,
                                confirmButtonColor: '#d33',
                                cancelButtonColor: '#3085d6',
                                confirmButtonText: 'Yes, delete them!'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    document.getElementById('bulkForm').submit();
                                }
                            });
                        }
                    </script>

                <?php elseif ($view === 'structure'): 
                    ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:50px;">Action</th>
                                    <th>Field</th>
                                    <th>Type</th>
                                    <th>Null</th>
                                    <th>Key</th>
                                    <th>Default</th>
                                    <th>Extra</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tableStructure as $col):
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="?table=<?=htmlspecialchars($currentTable)?>&view=structure_edit&col=<?=urlencode($col['Field'])?>" title="Change" style="margin-right:5px;"><i class="fas fa-pencil-alt" style="color:var(--accent);"></i></a>
                                            <form method="POST" onsubmit="saConfirmForm(event, 'Drop column <?=htmlspecialchars($col['Field'])?>?')" style="display:inline;">
                                                <input type="hidden" name="action" value="drop_column">
                                                <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                                                <input type="hidden" name="col" value="<?=htmlspecialchars($col['Field'])?>">
                                                <button type="submit" style="background:none; border:none; cursor:pointer; color:var(--danger); padding:0;"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        </td>
                                        <td style="font-weight:bold; color:var(--accent); display:flex; align-items:center; gap:8px;">
                                            <?=htmlspecialchars($col['Field'])?>
                                            <a href="?table=<?=htmlspecialchars($currentTable)?>&view=structure_edit&col=<?=urlencode($col['Field'])?>&mode=copy" title="Copy Column" style="color:#fbbf24;">
                                                <i class="fas fa-copy"></i>
                                            </a>
                                        </td>
                                        <td><?=htmlspecialchars($col['Type'])?></td>
                                        <td><?=htmlspecialchars($col['Null'])?></td>
                                        <td><?=htmlspecialchars($col['Key'])?></td>
                                        <td><?=htmlspecialchars($col['Default'] ?? 'NULL')?></td>
                                        <td><?=htmlspecialchars($col['Extra'])?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 20px; display:flex; justify-content:space-between; align-items:center; background:var(--bg-card); padding:15px; border:1px solid var(--border-color); border-radius:6px;">
                        <!-- Add Column Form -->
                        <form method="GET" style="display:flex; gap:10px; align-items:center;">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <input type="hidden" name="view" value="structure_edit">
                            <label>Add 1 column(s)</label>
                            <button type="submit" class="btn btn-primary">Go</button>
                        </form>
                        
                        <a href="#foreign-keys-section" class="btn" style="margin-left:10px;"><i class="fas fa-link"></i> Add Relation</a>

                        <div style="display: flex; gap: 10px; margin-left:auto;">
                             <form method="POST" onsubmit="saConfirmForm(event, 'TRUNCATE this table? All data will be lost!')" style="display:inline;">
                                <input type="hidden" name="action" value="truncate_table">
                                <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                                <button type="submit" class="btn btn-danger"><i class="fas fa-eraser"></i> Truncate</button>
                            </form>
                            <form method="POST" onsubmit="saConfirmForm(event, 'DROP this table? This cannot be undone!')" style="display:inline;">
                                <input type="hidden" name="action" value="delete_table">
                                <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Drop</button>
                            </form>
                        </div>
                    </div>

                    <!-- INDEXES SECTION -->
                    <?php
                        $indexes = [];
                        try {
                            $stmt = $pdo->query("SHOW INDEX FROM `$currentTable`");
                            while ($row = $stmt->fetch()) {
                                $name = $row['Key_name'];
                                $indexes[$name]['type'] = ($name == 'PRIMARY') ? 'PRIMARY' : (($row['Non_unique'] == 0) ? 'UNIQUE' : 'INDEX');
                                $indexes[$name]['columns'][] = $row['Column_name'];
                            }
                        } catch(Exception $e) {}
                    ?>
                    <div class="card" style="margin-top: 20px;">
                        <h3>Indexes</h3>
                        <?php if($indexes): ?>
                            <div class="table-wrapper">
                                <table>
                                    <thead><tr><th>Action</th><th>Name</th><th>Type</th><th>Columns</th></tr></thead>
                                    <tbody>
                                        <?php foreach($indexes as $name => $idx): ?>
                                            <tr>
                                                <td>
                                                    <form method="POST" onsubmit="saConfirmForm(event, 'Drop index <?=htmlspecialchars($name)?>?')" style="display:inline;">
                                                        <input type="hidden" name="action" value="drop_index">
                                                        <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                                                        <input type="hidden" name="name" value="<?=htmlspecialchars($name)?>">
                                                        <input type="hidden" name="type" value="<?=htmlspecialchars($idx['type'])?>">
                                                        <button type="submit" style="background:none; border:none; cursor:pointer; color:var(--danger);"><i class="fas fa-trash-alt"></i></button>
                                                    </form>
                                                </td>
                                                <td><?=htmlspecialchars($name)?></td>
                                                <td><?=htmlspecialchars($idx['type'])?></td>
                                                <td><?=htmlspecialchars(implode(', ', $idx['columns']))?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" style="margin-top:15px; display:flex; gap:10px; align-items:center;">
                            <input type="hidden" name="action" value="add_index">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <select name="type" class="form-select" style="width:100px;">
                                <option value="INDEX">INDEX</option>
                                <option value="UNIQUE">UNIQUE</option>
                                <option value="PRIMARY KEY">PRIMARY</option>
                            </select>
                            <input type="text" name="name" class="form-control" placeholder="Index Name (Optional)" style="width:150px;">
                            <select name="cols[]" class="form-select" multiple style="height:38px; width:200px;" required>
                                <?php foreach($tableStructure as $col): ?>
                                    <option value="<?=htmlspecialchars($col['Field'])?>"><?=htmlspecialchars($col['Field'])?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-primary">Add Index</button>
                        </form>
                    </div>

                    <!-- FOREIGN KEYS SECTION -->
                    <?php
                        $fks = [];
                        try {
                            $stmt = $pdo->query("
                                SELECT 
                                    CONSTRAINT_NAME, 
                                    COLUMN_NAME, 
                                    REFERENCED_TABLE_NAME, 
                                    REFERENCED_COLUMN_NAME
                                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                                WHERE 
                                    TABLE_SCHEMA = '$DB_NAME' AND 
                                    TABLE_NAME = '$currentTable' AND 
                                    REFERENCED_TABLE_NAME IS NOT NULL
                            ");
                            $fks = $stmt->fetchAll();
                        } catch(Exception $e) {}
                        
                        // Get all tables for dropdown
                        $allTables = [];
                        $stmt = $pdo->query("SHOW TABLES");
                        while ($r = $stmt->fetch(PDO::FETCH_NUM)) $allTables[] = $r[0];
                    ?>
                    <div class="card" style="margin-top: 20px;" id="foreign-keys-section">
                        <h3>Foreign Keys</h3>
                        <?php if($fks): ?>
                            <div class="table-wrapper">
                                <table>
                                    <thead><tr><th>Action</th><th>Name</th><th>Column</th><th>Ref Table</th><th>Ref Column</th></tr></thead>
                                    <tbody>
                                        <?php foreach($fks as $fk): ?>
                                            <tr>
                                                <td>
                                                    <form method="POST" onsubmit="saConfirmForm(event, 'Drop Foreign Key <?=htmlspecialchars($fk['CONSTRAINT_NAME'])?>?')" style="display:inline;">
                                                        <input type="hidden" name="action" value="drop_fk">
                                                        <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                                                        <input type="hidden" name="name" value="<?=htmlspecialchars($fk['CONSTRAINT_NAME'])?>">
                                                        <button type="submit" style="background:none; border:none; cursor:pointer; color:var(--danger);"><i class="fas fa-trash-alt"></i></button>
                                                    </form>
                                                </td>
                                                <td><?=htmlspecialchars($fk['CONSTRAINT_NAME'])?></td>
                                                <td><?=htmlspecialchars($fk['COLUMN_NAME'])?></td>
                                                <td><a href="?table=<?=htmlspecialchars($fk['REFERENCED_TABLE_NAME'])?>" style="color:var(--accent);"><?=htmlspecialchars($fk['REFERENCED_TABLE_NAME'])?></a></td>
                                                <td><?=htmlspecialchars($fk['REFERENCED_COLUMN_NAME'])?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" style="margin-top:15px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                            <input type="hidden" name="action" value="add_fk">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <input type="text" name="name" class="form-control" placeholder="FK Name (Optional)" style="width:150px;">
                            
                            <select name="col" class="form-select" style="width:150px;" required>
                                <option value="">- Column -</option>
                                <?php foreach($tableStructure as $col): ?>
                                    <option value="<?=htmlspecialchars($col['Field'])?>"><?=htmlspecialchars($col['Field'])?></option>
                                <?php endforeach; ?>
                            </select>
                            
                            <span>-></span>
                            
                            <select name="ref_table" class="form-select" style="width:150px;" required onchange="this.form.ref_col.focus()">
                                <option value="">- Target Table -</option>
                                <?php foreach($allTables as $t): ?>
                                    <option value="<?=htmlspecialchars($t)?>"><?=htmlspecialchars($t)?></option>
                                <?php endforeach; ?>
                            </select>
                            
                            <input type="text" name="ref_col" class="form-control" placeholder="Target Col (id)" value="id" style="width:100px;" required>
                            
                            <select name="on_delete" class="form-select" style="width:120px;">
                                <option value="RESTRICT">ON DEL RESTRICT</option>
                                <option value="CASCADE">ON DEL CASCADE</option>
                                <option value="SET NULL">ON DEL SET NULL</option>
                                <option value="NO ACTION">ON DEL NO ACTION</option>
                            </select>
                            
                            <select name="on_update" class="form-select" style="width:120px;">
                                <option value="RESTRICT">ON UPD RESTRICT</option>
                                <option value="CASCADE">ON UPD CASCADE</option>
                                <option value="SET NULL">ON UPD SET NULL</option>
                                <option value="NO ACTION">ON UPD NO ACTION</option>
                            </select>
                            
                            <button type="submit" class="btn btn-primary">Add FK</button>
                        </form>
                    </div>

<?php elseif ($view === 'structure_edit'):
                    $editCol = $_GET['col'] ?? null;
                    $colData = [];
                    $copyMode = (isset($_GET['mode']) && $_GET['mode'] === 'copy');
                    if ($editCol) {
                        foreach ($tableStructure as $col) {
                            if ($col['Field'] === $editCol) {
                                $colData = $col;
                                break;
                            }
                        }
                    }
                    if ($copyMode && isset($colData['Field'])) {
                        $colData['Field'] = $colData['Field'] . '_copy';
                        $colData['Extra'] = str_replace('auto_increment', '', $colData['Extra']);
                    }
                    
                    // Parse Type and Length
                    $curType = 'VARCHAR';
                    $curLen = '';
                    $curExtra = $colData['Extra'] ?? '';
                    $curKey = $colData['Key'] ?? '';
                    
                    if (isset($colData['Type'])) {
                        if (preg_match('/^(\w+)(?:\(([^)]+)\))?(.*)$/', $colData['Type'], $matches)) {
                            $curType = strtoupper($matches[1]);
                            $curLen = $matches[2] ?? '';
                        } else {
                            $curType = strtoupper($colData['Type']);
                        }
                    }
                    
                    $types = ['INT', 'VARCHAR', 'TEXT', 'DATE', 'DATETIME', 'TIMESTAMP', 'DECIMAL', 'FLOAT', 'DOUBLE', 'BOOLEAN', 'JSON', 'BLOB', 'ENUM', 'SET', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT', 'CHAR', 'MEDIUMTEXT', 'LONGTEXT'];
                    ?>
                    <div class="card">
                        <h3>
                            <?php
                                if ($copyMode) {
                                    echo 'Copy Column';
                                } elseif ($editCol) {
                                    echo 'Change Column';
                                } else {
                                    echo 'Add Column';
                                }
                            ?>
                        </h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_column">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <?php if($editCol && !$copyMode): ?>
                                <input type="hidden" name="orig_field" value="<?=htmlspecialchars($editCol)?>">
                            <?php else: ?>
                                <div class="form-group" style="margin-bottom:15px;">
                                    <select name="after" class="form-select">
                                        <option value="">At End of Table</option>
                                        <option value="">At Beginning of Table</option>
                                        <?php foreach ($tableStructure as $c): ?>
                                            <option value="<?=htmlspecialchars($c['Field'])?>">After <?=htmlspecialchars($c['Field'])?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="table-wrapper" style="overflow:visible;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Type</th>
                                            <th>Length/Values</th>
                                            <th>Default</th>
                                            <th>Collation</th>
                                            <th>Attributes</th>
                                            <th>Null</th>
                                            <th>A_I</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><input type="text" name="field" class="form-control" value="<?=htmlspecialchars($colData['Field']??'')?>" required></td>
                                            <td>
                                                <select name="type" class="form-select">
                                                    <?php foreach($types as $t): ?>
                                                        <option value="<?=$t?>" <?=$curType===$t?'selected':''?>><?=$t?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td><input type="text" name="length" class="form-control" value="<?=htmlspecialchars($curLen)?>"></td>
                                            <td>
                                                <select name="default" class="form-select" onchange="this.nextElementSibling.style.display = (this.value=='USER_DEFINED'?'block':'none')">
                                                    <option value="NONE" <?=(!isset($colData['Default']) && ($colData['Null']??'')==='NO') ? 'selected':''?>>None</option>
                                                    <option value="NULL" <?=(isset($colData['Default']) && $colData['Default']===null) ? 'selected':''?>>NULL</option>
                                                    <option value="USER_DEFINED" <?=(isset($colData['Default']) && $colData['Default']!==null && $colData['Default']!=='CURRENT_TIMESTAMP') ? 'selected':''?>>As defined:</option>
                                                    <option value="CURRENT_TIMESTAMP" <?=(($colData['Default']??'')==='CURRENT_TIMESTAMP') ? 'selected':''?>>CURRENT_TIMESTAMP</option>
                                                </select>
                                                <input type="text" name="default_val" class="form-control" style="display:<?=(isset($colData['Default']) && $colData['Default']!==null && $colData['Default']!=='CURRENT_TIMESTAMP') ? 'block':'none'?>; margin-top:5px;" value="<?=htmlspecialchars($colData['Default']??'')?>">
                                            </td>
                                            <td>
                                                <input type="text" name="collation" class="form-control" placeholder="utf8mb4_general_ci" value="">
                                            </td>
                                            <td>
                                                <select name="attributes" class="form-select">
                                                    <option value=""></option>
                                                    <option value="UNSIGNED" <?=(stripos($colData['Type']??'', 'unsigned')!==false)?'selected':''?>>UNSIGNED</option>
                                                    <option value="UNSIGNED ZEROFILL" <?=(stripos($colData['Type']??'', 'zerofill')!==false)?'selected':''?>>UNSIGNED ZEROFILL</option>
                                                    <option value="ON UPDATE CURRENT_TIMESTAMP" <?=(stripos($colData['Extra']??'', 'on update')!==false)?'selected':''?>>ON UPDATE CURRENT_TIMESTAMP</option>
                                                </select>
                                            </td>
                                            <td style="text-align:center;"><input type="checkbox" name="null" value="1" <?=(($colData['Null']??'')==='YES')?'checked':''?>></td>
                                            <td style="text-align:center;"><input type="checkbox" name="ai" value="1" <?=(stripos($colData['Extra']??'', 'auto_increment')!==false)?'checked':''?>></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div style="margin-top:20px;">
                                <button type="submit" class="btn btn-primary">Save</button>
                                <a href="?table=<?=htmlspecialchars($currentTable)?>&view=structure" class="btn">Cancel</a>
                            </div>
                        </form>
                    </div>

                <?php elseif ($view === 'sql'): 
                    ?>
                    <div class="card">
                        <form method="POST" id="sqlForm">
                            <input type="hidden" name="action" value="sql_query">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <div style="display:flex; gap:10px;">
                                <div style="flex:1;">
                                    <textarea name="query" id="queryInput" class="form-control" rows="10" style="font-family:monospace; background: #000; border:1px solid #444;max-width: 100%; color:#0f0;" placeholder="SELECT * FROM..."><?=isset($_POST['query']) ? htmlspecialchars($_POST['query']) : "SELECT * FROM `$currentTable` LIMIT 100"?></textarea>
                                </div>
                                <div style="width: 250px; display: flex; flex-direction: column;">
                                    <div style="font-weight:bold; margin-bottom:5px; color:var(--text-secondary);">History</div>
                                    <div id="queryHistory" style="flex:1; border:1px solid var(--border-color); background:var(--bg-input); border-radius:4px; overflow-y:auto; font-size:0.8rem;">
                                        <!-- JS populates this -->
                                    </div>
                                    <button type="button" class="btn" onclick="clearHistory()" style="margin-top:5px; font-size:0.75rem;">Clear History</button>
                                </div>
                            </div>
                            <div style="margin-top: 15px; text-align: right;">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-play"></i> Execute</button>
                            </div>
                        </form>
                        
                        <script>
                            const historyKey = 'adminer_query_history';
                            const input = document.getElementById('queryInput');
                            const list = document.getElementById('queryHistory');
                            
                            function renderHistory() {
                                let history = JSON.parse(localStorage.getItem(historyKey) || '[]');
                                list.innerHTML = '';
                                history.forEach(q => {
                                    let item = document.createElement('div');
                                    item.style.padding = '8px';
                                    item.style.borderBottom = '1px solid #333';
                                    item.style.cursor = 'pointer';
                                    item.style.whiteSpace = 'nowrap';
                                    item.style.overflow = 'hidden';
                                    item.style.textOverflow = 'ellipsis';
                                    item.title = q;
                                    item.textContent = q;
                                    item.onmouseover = () => item.style.background = '#333';
                                    item.onmouseout = () => item.style.background = 'transparent';
                                    item.onclick = () => { input.value = q; };
                                    list.appendChild(item);
                                });
                                if(history.length === 0) list.innerHTML = '<div style="padding:10px; color:#666;">No history</div>';
                            }
                            
                            function clearHistory() {
                                Swal.fire({
                                    title: 'Clear History?',
                                    text: "This will remove all saved queries from this browser.",
                                    icon: 'warning',
                                    showCancelButton: true,
                                    confirmButtonColor: '#3085d6',
                                    cancelButtonColor: '#d33',
                                    confirmButtonText: 'Yes, clear it!'
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        localStorage.removeItem(historyKey);
                                        renderHistory();
                                        Swal.fire('Cleared!', 'Your history has been deleted.', 'success');
                                    }
                                });
                            }

                            document.getElementById('sqlForm').addEventListener('submit', function() {
                                let q = input.value.trim();
                                if(q) {
                                    let history = JSON.parse(localStorage.getItem(historyKey) || '[]');
                                    // Remove if exists (to move to top)
                                    history = history.filter(item => item !== q);
                                    history.unshift(q);
                                    if(history.length > 20) history.pop();
                                    localStorage.setItem(historyKey, JSON.stringify(history));
                                }
                            });
                            
                            renderHistory();
                        </script>

                        <?php if(isset($sqlStmt) && $sqlStmt && $action === 'sql_query'): 
                            ?><h4 style="margin: 20px 0 10px;">Results:</h4>
                            <div class="table-wrapper">
                                <table>
                                    <?php 
                                    $results = $sqlStmt->fetchAll();
                                    if ($results):
                                        $keys = array_keys($results[0]);
                                    ?><?php 
                                    ?><?= "<thead><tr>" ?><?php foreach ($keys as $k):
                                        ?><?= "<th>" ?><?=htmlspecialchars($k)?><?= "</th>" ?><?php 
                                    endforeach; ?><?= "</tr></thead>" ?><?php 
                                    ?><tbody>
                                        <?php foreach ($results as $r):
                                            ?>
                                            <tr><?php foreach ($r as $v):
                                                ?><td><?=htmlspecialchars((string)$v)?></td><?php 
                                            endforeach; ?></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                <?php else:
                                    ?><tbody><tr><td style="padding: 20px;">Empty result set.</td></tr></tbody><?php 
                                endif; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($view === 'import'): ?>
                    <div class="card">
                        <h3>Import Database</h3>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="import">
                            <div class="form-group" style="margin-bottom: 20px;">
                                <label style="display:block; margin-bottom:10px;">Select SQL File:</label>
                                <input type="file" name="file" class="form-control" required accept=".sql" onchange="previewSql(this)">
                            </div>
                            
                            <div id="preview-container" style="display:none; margin-bottom:20px;">
                                <label style="font-weight:bold; color:var(--text-secondary);">Preview:</label>
                                <pre id="sql-preview" style="background:#000; color:#0f0; padding:15px; border:1px solid #333; overflow-x:auto; max-height:300px; font-size:12px; margin-top:5px;"></pre>
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Execute</button>
                        </form>
                    </div>
                    <script>
                    function previewSql(input) {
    const file = input.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
        if (!confirm("File besar terdeteksi (>5MB). Preview penuh dapat memperlambat browser. Lanjutkan?")) {
            return;
        }
    }

    const reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById('sql-preview').textContent = e.target.result;
        document.getElementById('preview-container').style.display = 'block';
    };
    reader.readAsText(file);
}

                    </script>

                <?php elseif ($view === 'form'): 
                    ?>
                    <!-- EDIT/INSERT FORM -->
                    <?php
                        $formData = [];
                        $mode = $_GET['mode'] ?? '';
                        $isCopyMode = ($mode === 'copy');
                        if (isset($_GET['pk']) && isset($_GET['val'])) {
                            $stmt = $pdo->prepare("SELECT * FROM `$currentTable` WHERE `".$_GET['pk']."` = ?");
                            $stmt->execute([$_GET['val']]);
                            $formData = $stmt->fetch();
                        }
                        if ($isCopyMode && isset($primaryKey) && $primaryKey && isset($formData[$primaryKey])) {
                            unset($formData[$primaryKey]);
                        }

                        // --- FETCH FOREIGN KEYS (Smart Dropdown Logic) ---
                        $fks = [];
                        try {
                            $fkStmt = $pdo->prepare("
                                SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
                                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                                WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND REFERENCED_TABLE_NAME IS NOT NULL
                            ");
                            $fkStmt->execute(['db' => $DB_NAME, 'table' => $currentTable]);
                            while ($row = $fkStmt->fetch()) {
                                $fks[$row['COLUMN_NAME']] = [
                                    'table' => $row['REFERENCED_TABLE_NAME'],
                                    'col' => $row['REFERENCED_COLUMN_NAME'],
                                    'data' => []
                                ];
                            }
                            
                            // Fetch Data for Dropdowns
                            foreach ($fks as $colName => &$fkInfo) {
                                $refTable = $fkInfo['table'];
                                $refCol = $fkInfo['col'];
                                
                                // Get columns of ref table to find a display column
                                $refColsStmt = $pdo->query("DESCRIBE `$refTable`");
                                $refCols = $refColsStmt->fetchAll(PDO::FETCH_COLUMN);
                                
                                $displayCol = $refCol; // Default to ID
                                foreach ($refCols as $rc) {
                                    if ($rc !== $refCol && !preg_match('/id$/i', $rc) && !preg_match('/password/i', $rc)) {
                                        $displayCol = $rc;
                                        break;
                                    }
                                }
                                
                                // Fetch Limit 1000
                                $dataStmt = $pdo->query("SELECT `$refCol`, `$displayCol` FROM `$refTable` LIMIT 1000");
                                $fkInfo['data'] = $dataStmt->fetchAll();
                                $fkInfo['display'] = $displayCol;
                            }
                        } catch (Exception $e) { /* Ignore FK errors */ }
                    ?>
                    <div class="card">
                        <h3 style="margin-bottom: 20px; color:var(--accent);">
                            <?= $isCopyMode ? 'Copy Row' : ($formData && !$isCopyMode ? 'Edit Row' : 'New Row') ?>
                        </h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_row">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <?php if ($primaryKey && isset($_GET['pk']) && isset($_GET['val']) && !$isCopyMode):
                                ?><input type="hidden" name="pk" value="<?=htmlspecialchars($primaryKey)?>">
                                <input type="hidden" name="pk_val" value="<?=htmlspecialchars($formData[$primaryKey])?>"><?php 
                            endif; ?>

                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px;">
                                <?php foreach ($tableStructure as $col):
                                    $field = $col['Field'];
                                    $type = strtolower($col['Type']);
                                    $val = $formData[$field] ?? null;
                                    $inputHtml = '';

                                    if ($col['Extra'] == 'auto_increment') {
                                        $inputHtml = '<input type="text" class="form-control" disabled value="(Auto Increment)" style="opacity:0.5;">';
                                    } 
                                    // FOREIGN KEY DROPDOWN
                                    elseif (isset($fks[$field])) {
                                        $fk = $fks[$field];
                                        $inputHtml = '<select name="data['.htmlspecialchars($field).']" class="form-select">';
                                        if ($col['Null'] === 'YES') {
                                            $inputHtml .= '<option value="" '.(is_null($val)?'selected':'').'>NULL</option>';
                                        }
                                        foreach ($fk['data'] as $item) {
                                            $pkVal = $item[$fk['col']];
                                            $dispVal = $item[$fk['display']];
                                            $label = $pkVal;
                                            if ($pkVal != $dispVal) $label .= " - " . substr($dispVal, 0, 50);
                                            
                                            $selected = ((string)$val === (string)$pkVal) ? 'selected' : '';
                                            $inputHtml .= '<option value="'.htmlspecialchars($pkVal).'" '.$selected.'>'.htmlspecialchars($label).'</option>';
                                        }
                                        $inputHtml .= '</select>';
                                        $inputHtml .= '<div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">Ref: '.$fk['table'].' (Limit 1000)</div>';
                                    }
                                    // ENUM / SET
                                    elseif (preg_match("/^(enum|set)\((.*)\)$/i", $type, $matches)) {
                                        $options = str_getcsv($matches[2], ",", "'", "\\");
                                        $inputHtml = '<select name="data['.htmlspecialchars($field).']" class="form-select">';
                                        if ($col['Null'] === 'YES') {
                                            $inputHtml .= '<option value="" '.(is_null($val)?'selected':'').'>NULL</option>';
                                        }
                                        foreach($options as $opt) {
                                            $selected = ((string)$val === (string)$opt) ? 'selected' : '';
                                            $inputHtml .= '<option value="'.htmlspecialchars($opt).'" '.$selected.'>'.htmlspecialchars($opt).'</option>';
                                        }
                                        $inputHtml .= '</select>';
                                    }
                                    // DATE
                                    elseif ($type === 'date') {
                                        $inputHtml = '<input type="date" name="data['.htmlspecialchars($field).']" class="form-control" value="'.htmlspecialchars($val ?? '').'">';
                                    }
                                    // DATETIME / TIMESTAMP
                                    elseif (strpos($type, 'datetime') !== false || strpos($type, 'timestamp') !== false) {
                                        $dtVal = $val ? date('Y-m-d\TH:i', strtotime($val)) : '';
                                        $inputHtml = '<input type="datetime-local" name="data['.htmlspecialchars($field).']" class="form-control" value="'.htmlspecialchars($dtVal).'">';
                                    }
                                    // NUMBERS
                                    elseif (preg_match('/(int|decimal|float|double|numeric|real)/', $type)) {
                                        $step = (strpos($type, 'int') !== false) ? '1' : 'any';
                                        $inputHtml = '<input type="number" step="'.$step.'" name="data['.htmlspecialchars($field).']" class="form-control" value="'.htmlspecialchars($val ?? '').'">';
                                    }
                                    // LONG TEXT
                                    elseif (strpos($type, 'text') !== false || strpos($type, 'blob') !== false || strpos($type, 'json') !== false) {
                                        $inputHtml = '<textarea name="data['.htmlspecialchars($field).']" class="form-control" rows="4">'.htmlspecialchars($val ?? '').'</textarea>';
                                    }
                                    // DEFAULT
                                    else {
                                        $inputHtml = '<input type="text" name="data['.htmlspecialchars($field).']" class="form-control" value="'.htmlspecialchars($val ?? '').'">';
                                    }
                                    ?>
                                    <div class="form-group">
                                        <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size:0.85rem;">
                                            <?=htmlspecialchars($field)?> 
                                            <span style="font-size: 0.8em; color: var(--text-secondary); font-weight:normal;">(<?=htmlspecialchars($col['Type'])?>)</span>
                                        </label>
                                        <?=$inputHtml?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top: 30px; display:flex; gap:10px;">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Row</button>
                                <a href="?table=<?=htmlspecialchars($currentTable)?>&view=data" class="btn">Cancel</a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

            <?php elseif ($view === 'import'): ?>
                <!-- DASHBOARD IMPORT -->
                <div class="card">
                    <h3>Import Database</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="import">
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label style="display:block; margin-bottom:10px;">Select SQL File:</label>
                            <input type="file" name="file" class="form-control" required accept=".sql">
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Execute</button>
                    </form>
                </div>

            <?php else:
                ?>
                <!-- DASHBOARD -->
                <?php if (should_show_managed_database_list($hostProfile)): ?>
                <div class="card">
                    <h3><i class="fas fa-list"></i> Managed Database List (JSON)</h3>
                    <p style="color:var(--text-secondary); margin-bottom:15px;">List of databases stored in <code>adminer.config.json</code>. These appear in the sidebar dropdown.</p>
                    
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Database Name</th>
                                    <th style="width:100px; text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $configList = load_config($configFile)['databases'] ?? [];
                                foreach ($configList as $dbItem): 
                                     $isActive = ($dbItem === ($_SESSION['db_name'] ?? ''));
                                ?>
                                <tr style="<?= $isActive ? 'background:rgba(16, 185, 129, 0.1); color: var(--success)' : '' ?>">
                                    <td>
                                        <i class="fas fa-database" style="color:<?=$isActive ? 'var(--success)' : 'var(--accent)'?>; margin-right:8px;"></i> 
                                        <?=htmlspecialchars($dbItem)?>
                                        <?php if($isActive): ?>
                                            <span style="font-size:0.75rem; background:var(--success); color:white; padding:2px 6px; border-radius:4px; margin-left:8px;">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php if(!$isActive): ?>
                                            <a href="?select_db=<?=urlencode($dbItem)?>" class="btn btn-primary" style="padding:4px 8px; font-size:0.8rem; margin-right:5px;" title="Use Database"><i class="fas fa-gear"></i></a>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="saConfirmForm(event, 'Remove <?=htmlspecialchars($dbItem)?> from list?')" style="display:inline;">
                                            <input type="hidden" name="action" value="remove_database_list">
                                            <input type="hidden" name="name" value="<?=htmlspecialchars($dbItem)?>">
                                            <button type="submit" class="btn btn-danger" style="padding:4px 8px; font-size:0.8rem;" title="Remove from list"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($configList)): ?>
                                    <tr><td colspan="2" style="text-align:center; color:var(--text-secondary);">No databases in list.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <form method="POST" style="margin-top:15px; display:flex; gap:10px;">
                        <input type="hidden" name="action" value="add_database_list">
                        <input type="text" name="name" class="form-control" placeholder="Database Name" required pattern="[A-Za-z0-9_$-]+" style="max-width:300px;">
                        <button type="submit" class="btn btn-primary">Add to List</button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="card">
                    <h3><i class="fas fa-server"></i> Server Databases</h3>
                    <p style="color:var(--text-secondary); margin-bottom:15px;">Databases actually existing on the connected server.</p>
                    
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Database Name</th>
                                    <th style="width:150px; text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $serverDbs = [];
                                try {
                                    $stmt = $pdo->query("SHOW DATABASES");
                                    $serverDbs = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                } catch (Exception $e) {
                                    if ($e->getCode() == 1227 || stripos($e->getMessage(), '1227') !== false) {
                                        echo "<tr><td colspan='2' style='color:var(--text-secondary); font-style:italic;'>Listing databases is disabled on this server (Access Denied). Use the 'Managed Database List' above to add your database manually.</td></tr>";
                                    } else {
                                        echo "<tr><td colspan='2' style='color:var(--danger);'>Error fetching databases: ".htmlspecialchars($e->getMessage())."</td></tr>";
                                    }
                                }

                                $configList = load_config($configFile)['databases'] ?? [];
                                foreach ($serverDbs as $dbItem): 
                                    $inList = in_array($dbItem, $configList);
                                ?>
                                <tr>
                                    <td><?=htmlspecialchars($dbItem)?></td>
                                    <td style="text-align:right;">
                                        <?php if($dbItem !== ($_SESSION['db_name'] ?? '')): ?>
                                            <a href="?select_db=<?=urlencode($dbItem)?>" class="btn btn-primary" style="padding:4px 8px; font-size:0.8rem;" title="Use Database"><i class="fas fa-gear"></i></a>
                                        <?php else: ?>
                                            <span style="font-size:0.75rem; background:var(--success); color:white; padding:4px 8px; border-radius:4px; margin-right:5px; display:inline-block;">Active</span>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="saConfirmForm(event, 'DROP DATABASE <?=htmlspecialchars($dbItem)?>? THIS DESTROYS ALL DATA!')" style="display:inline;">
                                            <input type="hidden" name="action" value="drop_database_server">
                                            <input type="hidden" name="name" value="<?=htmlspecialchars($dbItem)?>">
                                            <button type="submit" class="btn btn-danger" style="padding:4px 8px; font-size:0.8rem;" title="Drop Database"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form method="POST" style="margin-top:15px; display:flex; gap:10px; align-items:center;">
                        <input type="hidden" name="action" value="create_database_server">
                        <input type="text" name="name" class="form-control" placeholder="New Database Name" required pattern="[A-Za-z0-9_$-]+" style="max-width:300px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Create Database</button>
                    </form>
                </div>
                <div class="card" style="margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <h3 style="margin:0;">Quick SQL</h3>
                        <small style="color:var(--text-secondary);">Execute statements directly from dashboard</small>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="sql_query">
                        <textarea name="query" rows="6" class="form-control" style="font-family:monospace; background:#000; color:#0f0; margin-bottom:10px; max-width: 100%;" placeholder="Enter SQL here..."></textarea>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-play"></i> Run SQL</button>
                            <span style="font-size:0.8rem; color:var(--text-secondary);">Multiple statements separated by ';'</span>
                        </div>
                    </form>
                    <?php if(!empty($sqlResults)): ?>
                        <div style="margin-top:20px;">
                            <?php foreach($sqlResults as $blockIndex => $result): ?>
                                <div style="margin-bottom:15px;">
                                    <div style="font-size:0.85rem; color:var(--accent); margin-bottom:5px;">Result for: <code><?=htmlspecialchars($result['query'])?></code></div>
                                    <div class="table-wrapper">
                                        <table>
                                            <?php if(!empty($result['columns'])): ?>
                                                <thead>
                                                    <tr>
                                                        <?php foreach($result['columns'] as $col): ?>
                                                            <th><?=htmlspecialchars($col)?></th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                            <?php endif; ?>
                                            <tbody>
                                                <?php if(!empty($result['rows'])): ?>
                                                    <?php foreach($result['rows'] as $row): ?>
                                                        <tr>
                                                            <?php foreach($row as $val): ?>
                                                                <td><?=htmlspecialchars(is_null($val) ? 'NULL' : (string)$val)?></td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr><td><?=isset($result['columns'][0]) ? 'No rows' : 'Empty result'?></td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="dashboard-stats">
                    <div class="stat-card">
                        <div class="stat-label">Total Tables</div>
                        <div class="stat-val"><?=count($tables)?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Total Rows</div>
                        <div class="stat-val"><?=number_format($totalRows)?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Database Size</div>
                        <div class="stat-val"><?=formatSize($totalSize)?></div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">
                    <!-- System Health -->
                    <div class="card" style="margin-bottom:0;">
                        <h3><i class="fas fa-heartbeat"></i> System Health</h3>
                        <ul style="list-style:none; padding:0; margin:0; font-size:0.9rem;">
                            <li style="padding:8px 0; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between;">
                                <span>PHP Version</span>
                                <span style="font-weight:bold; color:var(--accent);"><?=phpversion()?></span>
                            </li>
                            <li style="padding:8px 0; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between;">
                                <span>MySQL Version</span>
                                <span style="font-weight:bold; color:var(--accent);"><?=$pdo->getAttribute(PDO::ATTR_SERVER_VERSION)?></span>
                            </li>
                            <li style="padding:8px 0; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between;">
                                <span>Memory Usage</span>
                                <span style="font-weight:bold; color:var(--success);"><?=formatSize(memory_get_usage())?></span>
                            </li>
                            <li style="padding:8px 0; display:flex; justify-content:space-between;">
                                <span>Server OS</span>
                                <span style="font-weight:bold;"><?=PHP_OS?></span>
                            </li>
                        </ul>
                    </div>

                    <!-- Recent Tables -->
                    <div class="card" style="margin-bottom:0;">
                        <h3><i class="fas fa-history"></i> Recently Modified</h3>
                        <?php
                            try {
                                $recentStmt = $pdo->query("SELECT TABLE_NAME, UPDATE_TIME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '$DB_NAME' AND UPDATE_TIME IS NOT NULL ORDER BY UPDATE_TIME DESC LIMIT 5");
                                $recentTables = $recentStmt->fetchAll();
                            } catch (Exception $e) { $recentTables = []; }
                        ?>
                        <ul style="list-style:none; padding:0; margin:0; font-size:0.9rem;">
                            <?php if ($recentTables): foreach ($recentTables as $rt): ?>
                                <li style="padding:8px 0; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                                    <a href="?table=<?=htmlspecialchars($rt['TABLE_NAME'])?>" style="color:var(--text-primary); text-decoration:none; display:flex; align-items:center; gap:8px;">
                                        <i class="fas fa-table" style="color:var(--text-secondary);"></i> <?=htmlspecialchars($rt['TABLE_NAME'])?>
                                    </a>
                                    <small style="color:var(--text-secondary);"><?= date('M d H:i', strtotime($rt['UPDATE_TIME'])) ?></small>
                                </li>
                            <?php endforeach; else: ?>
                                <li style="padding:10px 0; color:var(--text-secondary);">No recent activity recorded.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <?php if ($relationshipDiagram): ?>
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <h3 style="margin:0;">Relationship Map</h3>
                        <span style="font-size:0.85rem; color:var(--text-secondary);">Mermaid diagram of foreign keys</span>
                    </div>
                    <pre id="mermaid-graph" class="mermaid" style="background:#080808; border:1px solid #222; border-radius:6px; padding:15px; overflow:auto; max-height:500px;"><?= htmlspecialchars($relationshipDiagram) ?></pre>
                </div>
                <?php endif; ?>

                <?php if ($plantumlDiagramEncoded || $erdDiagram): ?>
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <h3 style="margin:0;">Schema ERD</h3>
                        <span style="font-size:0.85rem; color:var(--text-secondary);">PlantUML (primary) with Mermaid fallback</span>
                    </div>
                    <?php if ($plantumlDiagramEncoded): ?>
                        <div style="background:#080808; border:1px solid #222; border-radius:6px; padding:10px; text-align:center;">
                            <img src="https://www.plantuml.com/plantuml/svg/<?= htmlspecialchars($plantumlDiagramEncoded) ?>" alt="PlantUML ERD" style="width:100%; max-height:600px; object-fit:contain; background:#fff;">
                        </div>
                        <?php if ($erdDiagram): ?>
                            <details style="margin-top:10px;">
                                <summary style="cursor:pointer; color:#0d6efd;">Show Mermaid fallback</summary>
                                <pre class="mermaid" style="margin-top:10px; background:#080808; border:1px solid #222; border-radius:6px; padding:15px; overflow:auto; max-height:600px;"><?= htmlspecialchars($erdDiagram) ?></pre>
                            </details>
                        <?php endif; ?>
                    <?php elseif ($erdDiagram): ?>
                        <pre class="mermaid" style="background:#080808; border:1px solid #222; border-radius:6px; padding:15px; overflow:auto; max-height:600px;"><?= htmlspecialchars($erdDiagram) ?></pre>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3>Database Tables</h3>
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <a href="?view=import" class="btn"><i class="fas fa-upload"></i> Import Database</a>
                            <form method="POST" style="margin:0; display:flex;">
                                <input type="hidden" name="action" value="export">
                                <select name="format" class="form-select" style="border-radius:4px 0 0 4px; border-right:none; width:auto;">
                                    <option value="sql">SQL</option>
                                    <option value="json">JSON</option>
                                    <option value="csv">CSV</option>
                                </select>
                                <button type="submit" class="btn btn-primary" style="border-radius:0 4px 4px 0;"><i class="fas fa-download"></i> Export</button>
                            </form>
                        </div>
                    </div>
                    <form method="POST" id="bulkTablesForm">
                        <input type="hidden" name="action" value="bulk_tables">
                        <div style="display:flex; gap:10px; margin-bottom:10px; flex-wrap:wrap; align-items:center;">
                            <select name="bulk_operation" class="form-select" style="width:200px;">
                                <option value="">Bulk Action</option>
                                <option value="drop">Drop Tables</option>
                                <option value="truncate">Truncate Tables</option>
                                <option value="optimize">Optimize Tables</option>
                                <option value="export">Export Tables</option>
                            </select>
                            <button type="button" class="btn btn-danger" onclick="confirmBulkTables()" style="display:flex; align-items:center; gap:6px;"><i class="fas fa-check"></i> Apply</button>
                            <span style="font-size:0.8rem; color:var(--text-secondary);">Select tables below to run bulk action.</span>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:40px;"><input type="checkbox" id="selectAllTables"></th>
                                        <th>Name</th>
                                        <th>Rows</th>
                                        <th>Size</th>
                                        <th>Collation</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tables as $t):
                                        ?>
                                        <tr>
                                            <td style="text-align:center;">
                                                <input type="checkbox" name="tables[]" value="<?=htmlspecialchars($t['Name'])?>" class="table-checkbox">
                                            </td>
                                            <td><a href="?table=<?=htmlspecialchars($t['Name'])?>" style="font-weight: bold; color: var(--accent);"><?=htmlspecialchars($t['Name'])?></a></td>
                                            <td><?=number_format($t['Rows'] ?? 0)?></td>
                                            <td><?=formatSize(($t['Data_length'] ?? 0) + ($t['Index_length'] ?? 0))?></td>
                                            <td><?=$t['Collation']?></td>
                                            <td>
                                                <a href="?table=<?=htmlspecialchars($t['Name'])?>&view=structure" class="btn" style="padding:2px 6px; font-size:0.75rem;">Struct</a>
                                                <a href="?table=<?=htmlspecialchars($t['Name'])?>&view=data" class="btn" style="padding:2px 6px; font-size:0.75rem;">Data</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
        </div>
    </div>

<script>
    // Global SweetAlert Helpers
    function saConfirmLink(e, text) {
        e.preventDefault();
        const href = e.currentTarget.getAttribute('href');
        Swal.fire({
            title: 'Are you sure?',
            text: text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, proceed!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = href;
            }
        });
    }

    function saConfirmForm(e, text) {
        e.preventDefault();
        const form = e.target;
        Swal.fire({
            title: 'Are you sure?',
            text: text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33', // Red for destructive actions
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, do it!'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }

    // --- SIDEBAR TOGGLE & PERSISTENCE ---
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const toggleIcon = sidebarToggle.querySelector('i');
    const SIDEBAR_STORAGE_KEY = 'adminer_sidebar_collapsed';

    function setSidebarState(collapsed) {
        if (collapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
            toggleIcon.classList.remove('fa-angle-left');
            toggleIcon.classList.add('fa-angle-right');
        } else {
            sidebar.classList.remove('collapsed');
            mainContent.classList.remove('sidebar-collapsed');
            toggleIcon.classList.remove('fa-angle-right');
            toggleIcon.classList.add('fa-angle-left');
        }
        localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed);
    }

    // Initialize Sidebar State
    const storedState = localStorage.getItem(SIDEBAR_STORAGE_KEY);
    const isSmallScreen = window.innerWidth <= 768;
    // Default: Collapsed on small screens, Expanded on large (unless stored)
    if (storedState === 'true' || (storedState === null && isSmallScreen)) {
        setSidebarState(true);
    } else {
        setSidebarState(false);
    }

    sidebarToggle.addEventListener('click', () => {
        setSidebarState(!sidebar.classList.contains('collapsed'));
    });

    // --- TABLE SEARCH (Sidebar) ---
    const tableSearchInput = document.getElementById('tableSearch');
    if (tableSearchInput) {
        const navList = document.querySelector('.nav-list');
        const tableItems = navList.querySelectorAll('.nav-item');
        tableSearchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            tableItems.forEach(item => {
                // Skip dashboard link
                if (item.getAttribute('href') === '?') return;
                const tableName = item.textContent.toLowerCase();
                item.style.display = tableName.includes(searchTerm) ? 'flex' : 'none';
            });
        });
    }

    // --- REALTIME PAGE FILTER ---
    const pageFilterInput = document.getElementById('pageFilterInput');
    if (pageFilterInput) {
        pageFilterInput.addEventListener('keyup', function() {
            const term = this.value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                // Ignore rows that are just 'No data' messages
                if (row.cells.length === 1 && row.textContent.trim() === 'No data found') return;
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(term) ? '' : 'none';
            });
        });
    }

    // --- COLUMN VISIBILITY ---
    // Inject CSS for the show class
    const style = document.createElement('style');
    style.innerHTML = '#colToggleDropdown.show { display: block !important; }';
    document.head.appendChild(style);

    function initColumnVisibility() {
        const dropdown = document.getElementById('colToggleDropdown');
        if (!dropdown) return;

        const urlParams = new URLSearchParams(window.location.search);
        const tableName = urlParams.get('table');
        if (!tableName) return;
        
        const storageKey = 'adminer_hidecols_' + tableName;
        let hiddenCols = JSON.parse(localStorage.getItem(storageKey) || '[]');

        // Get all headers that have data-col attribute
        const headers = document.querySelectorAll('th[data-col]');
        
        headers.forEach(th => {
            const colName = th.getAttribute('data-col');
            const isHidden = hiddenCols.includes(colName);
            
            // Create Checkbox UI
            const div = document.createElement('div');
            div.style.padding = '4px 0';
            div.innerHTML = `
                <label style="cursor:pointer; display:flex; align-items:center; gap:8px; white-space:nowrap; color:var(--text-primary);">
                    <input type="checkbox" value="${colName}" ${isHidden ? '' : 'checked'} style="width:auto; margin:0;"> 
                    <span style="font-size:0.9rem;">${colName}</span>
                </label>
            `;
            dropdown.appendChild(div);
            
            const checkbox = div.querySelector('input');
            checkbox.addEventListener('change', (e) => {
                toggleColumn(colName, e.target.checked);
            });

            // Apply initial state
            if (isHidden) {
                toggleColumn(colName, false);
            }
        });

        function toggleColumn(colName, show) {
            // Toggle Header
            const th = document.querySelector(`th[data-col="${CSS.escape(colName)}"]`);
            if (th) th.style.display = show ? '' : 'none';

            // Toggle Cells
            const cells = document.querySelectorAll(`td[data-col="${CSS.escape(colName)}"]`);
            cells.forEach(td => td.style.display = show ? '' : 'none');
            
            // Update Storage
            if (show) {
                hiddenCols = hiddenCols.filter(c => c !== colName);
            } else {
                if (!hiddenCols.includes(colName)) hiddenCols.push(colName);
            }
            localStorage.setItem(storageKey, JSON.stringify(hiddenCols));
        }
    }
    initColumnVisibility();

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('colToggleDropdown');
        const button = document.querySelector('button[onclick*="colToggleDropdown"]');
        if (dropdown && button && !dropdown.contains(event.target) && !button.contains(event.target)) {
            dropdown.classList.remove('show');
        }
    });

    if (typeof mermaid !== 'undefined') {
        mermaid.initialize({ startOnLoad: true, theme: 'dark', securityLevel: 'loose' });
    }

    // Bulk table selection
    const selectAllTables = document.getElementById('selectAllTables');
    if (selectAllTables) {
        selectAllTables.addEventListener('change', function() {
            document.querySelectorAll('.table-checkbox').forEach(cb => cb.checked = selectAllTables.checked);
        });
    }

    function getSelectedTablesCount() {
        return Array.from(document.querySelectorAll('.table-checkbox')).filter(cb => cb.checked).length;
    }

    window.confirmBulkTables = function() {
        const form = document.getElementById('bulkTablesForm');
        if (!form) return;
        const action = form.querySelector('select[name=\"bulk_operation\"]').value;
        const selectedCount = getSelectedTablesCount();
        if (!action) {
            Swal.fire('Missing', 'Pilih aksi bulk terlebih dahulu.', 'info');
            return;
        }
        if (selectedCount === 0) {
            Swal.fire('No tables', 'Pilih minimal satu tabel.', 'info');
            return;
        }
        const actionLabel = {
            drop: 'Drop',
            truncate: 'Truncate',
            optimize: 'Optimize',
            export: 'Export'
        }[action] || action;
        Swal.fire({
            title: `Confirm ${actionLabel}?`,
            text: `Action will run on ${selectedCount} table(s).`,
            icon: action === 'drop' || action === 'truncate' ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonColor: action === 'drop' ? '#d33' : '#3085d6',
            cancelButtonColor: '#666',
            confirmButtonText: 'Yes, run it'
        }).then(result => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }

    // Initialize TomSelect for Searchable Dropdowns
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form select.form-select').forEach((el) => {
            // Apply only if not in the export form or bulk action form (optional check, but safe to apply generally in edit forms)
            // We specifically want this for the Row Editor
            if (el.closest('.card') && !el.closest('#bulkTablesForm')) {
                new TomSelect(el, {
                    plugins: ['clear_button'],
                    maxOptions: 50,
                    sortField: {
                        field: "text",
                        direction: "asc"
                    }
                });
            }
        });
    });
</script>
</body>
</html>
