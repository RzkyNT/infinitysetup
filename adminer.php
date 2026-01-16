<?php
session_start();

$dbConfigPath = __DIR__ . '/adminer.db.json';

function load_db_config($path)
{
    if (!file_exists($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    return array_merge(
        ['host' => '', 'user' => '', 'pass' => '', 'name' => ''],
        $data
    );
}

function save_db_config($path, $data)
{
    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents($path, $payload) !== false;
}

function render_db_setup($defaults = [], $error = '', $success = '')
{
    $host = htmlspecialchars($defaults['host'] ?? '');
    $user = htmlspecialchars($defaults['user'] ?? '');
    $name = htmlspecialchars($defaults['name'] ?? '');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Setup</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
                <label for="name">Database Name</label>
                <input type="text" id="name" name="db_name" value="<?php echo $name; ?>" required>
                <button type="submit"><i class="fas fa-save"></i> Save Configuration</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$dbConfig = load_db_config($dbConfigPath);

if (isset($_POST['db_setup_action'])) {
    $host = trim($_POST['db_host'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = $_POST['db_pass'] ?? '';
    $name = trim($_POST['db_name'] ?? '');

    $existing = $dbConfig ?? ['host' => '', 'user' => '', 'pass' => '', 'name' => ''];
    if ($host === '' || $user === '' || $name === '') {
        render_db_setup(
            ['host' => $host, 'user' => $user, 'name' => $name],
            'Host, user, and database name are required.'
        );
    }
    if ($pass === '' && isset($existing['pass'])) {
        $pass = $existing['pass'];
    }
    $payload = ['host' => $host, 'user' => $user, 'pass' => $pass, 'name' => $name];
    if (!save_db_config($dbConfigPath, $payload)) {
        render_db_setup($payload, 'Failed to save configuration. Check file permissions.');
    }
    $dbConfig = $payload;
    render_db_setup($payload, '', 'Configuration saved. You can refresh to continue.');
}

if (!$dbConfig || isset($_GET['setup'])) {
    render_db_setup($dbConfig ?? []);
}

// ===== SSO LOGIC =====
if (isset($_SESSION['portal_logged_in']) && $_SESSION['portal_logged_in'] === true) {
    if (!isset($_SESSION['db_host'])) {
        $_SESSION['db_host'] = $dbConfig['host'];
        $_SESSION['db_user'] = $dbConfig['user'];
        $_SESSION['db_pass'] = $dbConfig['pass'];
        $_SESSION['db_name'] = $dbConfig['name'];
    }
}
$DB_NAME = $_SESSION['db_name'] ?? '';


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

// ===== DB CONNECTION (IF LOGGED IN) =====
$pdo = null;
$databases = []; // List of databases
$sqlResults = [];
$lastResultSet = null;

// --- Configurable List of User Databases ---
// IMPORTANT: If 'SHOW DATABASES' is restricted by your hosting,
// populate this array with the names of databases you have access to.
// These will be used to populate the dropdown for selection.
$user_defined_databases = [
];
// ------------------------------------------

if ($is_logged_in) {
    try {
        // Try connecting with selected DB
        $dsn = "mysql:host={$_SESSION['db_host']};charset=utf8mb4";
        if (!empty($_SESSION['db_name'])) {
            $dsn .= ";dbname={$_SESSION['db_name']}";
        }
        
        $pdo = new PDO(
            $dsn,
            $_SESSION['db_user'],
            $_SESSION['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        
        // Fetch Databases
        try {
            $stmt = $pdo->query("SHOW DATABASES");
            $databases_from_server = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // If server only returns one database (likely due to restriction), 
            // and we have user-defined ones, use them. Otherwise, use server's list.
            if (count($databases_from_server) <= 1 && !empty($user_defined_databases)) {
                $databases = $user_defined_databases;
            } else {
                $databases = $databases_from_server;
            }
        } catch (Exception $e) {
            // If SHOW DATABASES fails, use user-defined list or just the current one if no user-defined
            if (!empty($user_defined_databases)) {
                $databases = $user_defined_databases;
            } else {
                $databases = [$_SESSION['db_name']];
            }
        }

    } catch (Exception $e) {
        // If initial connection failed (e.g. DB doesn't exist anymore or invalid credentials), 
        // try connecting without DB name to list valid ones.
        // Or, if user-defined databases are present, use those.
        if (!empty($user_defined_databases)) {
            $databases = $user_defined_databases;
            // If current selected DB is no longer valid, switch to first available user-defined DB
            if (!in_array($_SESSION['db_name'], $databases) && !empty($databases)) {
                $_SESSION['db_name'] = $databases[0];
                header("Location: ?");
                exit;
            }
        } else {
            // Fallback to the original logic (try connecting without dbname)
            try {
                 $pdo = new PDO(
                    "mysql:host={$_SESSION['db_host']};charset=utf8mb4",
                    $_SESSION['db_user'],
                    $_SESSION['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $stmt = $pdo->query("SHOW DATABASES");
                $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // If this also returns limited, and we have user-defined (should be empty here if this path is taken without explicit list)
                // This condition might be redundant here if user_defined_databases is empty.
                // It ensures that if 'SHOW DATABASES' itself fails to return a comprehensive list even after falling back to a connection without dbname,
                // and if there were user-defined databases (which in this else branch implies they were empty), it wouldn't use them.
                // The intent here is to use whatever SHOW DATABASES returns in this fallback scenario.
                
                // Reset DB name if invalid
                if (!in_array($_SESSION['db_name'], $databases) && !empty($databases)) {
                     $_SESSION['db_name'] = $databases[0];
                     header("Location: ?");
                     exit;
                }
            } catch(Exception $ex) {
                session_destroy();
                die("Session Expired or DB Connection Failed. <a href='?'>Login again</a>");
            }
        }
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
    $data = utf8_encode($text);
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
        $filename = ($exportTable ? $exportTable : $_SESSION['db_name']) . "_" . date("Y-m-d_H-i-s") . ".sql";
        
        header('Content-Type: application/octet-stream');
        header("Content-Transfer-Encoding: Binary"); 
        header("Content-disposition: attachment; filename=\"$filename\""); 
        
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
            echo $row[1] . "\n\n";
            
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
        exit;
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

// ===== DATA PREPARATION =====
$tables = [];
$totalRows = 0;
$totalSize = 0;
$relationshipDiagram = '';
$erdDiagram = '';
$relationshipPlantumlEncoded = null;
$erdPlantumlEncoded = null;

if ($is_logged_in) {
    // Tables list
    try {
        $stmt = $pdo->query("SHOW TABLE STATUS");
        $tables = $stmt->fetchAll();
    } catch (Exception $e) {
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = ['Name' => $row[0], 'Rows' => 0, 'Data_length' => 0, 'Index_length' => 0, 'Collation' => ''];
        }
    }

    foreach ($tables as $t) {
        $totalRows += $t['Rows'];
        $totalSize += $t['Data_length'] + $t['Index_length'];
    }
}

if ($is_logged_in && !$currentTable) {
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

if ($is_logged_in && $currentTable) {
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-dark@5/dark.css"> <!-- SweetAlert2 Dark Theme -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
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
            position: relative; 
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
        .table-wrapper { border: 1px solid var(--border-color); border-radius: 6px; overflow-x: auto; background: var(--bg-card); }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th, td { padding: 10px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background: var(--bg-hover); font-weight: 600; color: var(--text-secondary); font-size: 0.8rem; text-transform: uppercase; white-space: nowrap; }
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

            <?php if ($currentTable):
                ?>
                <!-- TABLE VIEW -->
                <div class="tabs">
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=structure" class="tab <?=$view==='structure'?'active':''?>">Structure</a>
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=data" class="tab <?=$view==='data'?'active':''?>">Data</a>
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=sql" class="tab <?=$view==='sql'?'active':''?>">SQL</a>
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=import" class="tab <?=$view==='import'?'active':''?>">Import</a>
                    <div style="flex:1;"></div>
                    <!-- Export Button -->
                     <form method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="export">
                        <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                        <button type="submit" class="btn"><i class="fas fa-download"></i> Export Table</button>
                    </form>
                </div>

                <?php if ($view === 'data'): 
                    ?>
                    <!-- ADVANCED SEARCH -->
                    <form class="search-bar" method="GET">
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
                            <input type="text" name="search_val" class="form-control" placeholder="Search..." value="<?=htmlspecialchars($searchVal)?>" style="width: 100%;">
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                        <?php if($searchVal):
                            ?><a href="?table=<?=htmlspecialchars($currentTable)?>&view=data" class="btn btn-danger"><i class="fas fa-times"></i></a><?php 
                        endif; ?>
                        
                        <div style="margin-left:auto; display:flex; gap:10px;">
                            <button type="button" onclick="submitBulkDelete()" class="btn btn-danger" id="bulkDeleteBtn" style="display:none;"><i class="fas fa-trash"></i> Delete Selected</button>
                            <a href="?table=<?=htmlspecialchars($currentTable)?>&view=form" class="btn btn-primary"><i class="fas fa-plus"></i> New Row</a>
                        </div>
                    </form>

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
                                        ?><th><a href="<?=$sortLink?>" style="color:inherit; text-decoration:none; display:flex; align-items:center; justify-content:space-between;"><?=$sortIcon?><?=htmlspecialchars($col)?></a></th><?php 
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
                                            ?><td title="<?=htmlspecialchars((string)$val)?>"><?=$displayVal?></td><?php 
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
                                            . "&order_by=" . urlencode($orderBy)
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

                        <div style="display: flex; gap: 10px;">
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
                    <div class="card" style="margin-top: 20px;">
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
                                    <textarea name="query" id="queryInput" class="form-control" rows="10" style="font-family:monospace; background: #000; border:1px solid #444;max-width: 680px; color:#0f0;" placeholder="SELECT * FROM..."><?=isset($_POST['query']) ? htmlspecialchars($_POST['query']) : "SELECT * FROM `$currentTable` LIMIT 100"?></textarea>
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
                                    ?><div class="form-group">
                                        <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size:0.85rem;">
                                            <?=htmlspecialchars($col['Field'])?> 
                                            <span style="font-size: 0.8em; color: var(--text-secondary); font-weight:normal;">(<?=htmlspecialchars($col['Type'])?>)</span>
                                        </label>
                                        <?php if ($col['Extra'] == 'auto_increment'): 
                                            ?><input type="text" class="form-control" disabled value="(Auto Increment)" style="opacity:0.5;"><?php 
                                        else:
                                            ?><?php if(strpos($col['Type'], 'text') !== false):
                                                 ?><textarea name="data[<?=htmlspecialchars($col['Field'])?>]" class="form-control" rows="3"><?=isset($formData[$col['Field']]) ? htmlspecialchars($formData[$col['Field']]) : ''?></textarea><?php 
                                            else:
                                                ?><input type="text" name="data[<?=htmlspecialchars($col['Field'])?>]" class="form-control" value="<?=isset($formData[$col['Field']]) ? htmlspecialchars($formData[$col['Field']]) : ''?>"><?php 
                                            endif; ?><?php 
                                        endif; ?>
                                    </div><?php 
                                endforeach; ?>
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
                <div class="card" style="margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <h3 style="margin:0;">Quick SQL</h3>
                        <small style="color:var(--text-secondary);">Execute statements directly from dashboard</small>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="sql_query">
                        <textarea name="query" rows="6" class="form-control" style="font-family:monospace; background:#000; color:#0f0; margin-bottom:10px;" placeholder="Enter SQL here..."></textarea>
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
                        <div style="display:flex; gap:10px;">
                            <a href="?view=import" class="btn"><i class="fas fa-upload"></i> Import Database</a>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="action" value="export">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Export Database</button>
                            </form>
                        </div>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
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
                                        <td><a href="?table=<?=htmlspecialchars($t['Name'])?>" style="font-weight: bold; color: var(--accent);"><?=htmlspecialchars($t['Name'])?></a></td>
                                        <td><?=number_format($t['Rows'])?></td>
                                        <td><?=formatSize($t['Data_length'] + $t['Index_length'])?></td>
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

    // Sidebar Toggle Functionality
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content'); // Get main content
    const sidebarToggle = document.getElementById('sidebarToggle');
    const toggleIcon = sidebarToggle.querySelector('i');
    
    // Set initial state based on window width
    if (window.innerWidth <= 768) {
        sidebar.classList.add('collapsed');
        mainContent.classList.add('sidebar-collapsed');
        toggleIcon.classList.remove('fa-angle-left');
        toggleIcon.classList.add('fa-angle-right');
    }

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        mainContent.classList.toggle('sidebar-collapsed'); // Toggle class on main content
        if (sidebar.classList.contains('collapsed')) {
            toggleIcon.classList.remove('fa-angle-left');
            toggleIcon.classList.add('fa-angle-right');
        } else {
            toggleIcon.classList.remove('fa-angle-right');
            toggleIcon.classList.add('fa-angle-left');
        }
    });

    // Table Search Functionality
    const tableSearchInput = document.getElementById('tableSearch');
    const navList = document.querySelector('.nav-list');
    const tableItems = navList.querySelectorAll('.nav-item'); // Get all nav items

    tableSearchInput.addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        tableItems.forEach(item => {
            const tableName = item.textContent.toLowerCase();
            if (tableName.includes(searchTerm)) {
                item.style.display = 'flex'; // Show matching items
            } else {
                item.style.display = 'none'; // Hide non-matching items
            }
        });
    });

    if (typeof mermaid !== 'undefined') {
        mermaid.initialize({ startOnLoad: true, theme: 'dark', securityLevel: 'loose' });
    }
</script>
</body>
</html>
