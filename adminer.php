<?php
// ===== KONFIGURASI =====
$DB_HOST = 'sql110.infinityfree.com';
$DB_USER = 'if0_40199145';
$DB_PASS = '12rizqi3';
$DB_NAME = 'if0_40199145_masjid';

// ===== KONEKSI =====
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Exception $e) {
    die("DB Connection Failed: " . $e->getMessage());
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

// ===== ACTION HANDLER =====
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $table = $_POST['table'] ?? '';
    
    if ($action === 'sql_query') {
        $sql = $_POST['query'] ?? '';
        try {
            $stmt = $pdo->query($sql);
            $msg = "Query executed successfully.";
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
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
    elseif ($action === 'delete_table') {
        try {
            $pdo->exec("DROP TABLE `$table`");
            redirect("?msg=" . urlencode("Table $table deleted."));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    elseif ($action === 'truncate_table') {
        try {
            $pdo->exec("TRUNCATE TABLE `$table`");
            redirect("?table=$table&view=structure&msg=" . urlencode("Table $table truncated."));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

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

// ===== DATA FETCHING =====
// Tables list
$tables = [];
try {
    $stmt = $pdo->query("SHOW TABLE STATUS");
    $tables = $stmt->fetchAll();
} catch (Exception $e) {
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = ['Name' => $row[0], 'Rows' => 0, 'Data_length' => 0, 'Index_length' => 0, 'Collation' => ''];
    }
}

// Total DB Stats
$totalTables = count($tables);
$totalRows = 0;
$totalSize = 0;
foreach ($tables as $t) {
    $totalRows += $t['Rows'];
    $totalSize += $t['Data_length'] + $t['Index_length'];
}

// Current View Data
$currentTable = isset($_GET['table']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table']) : null;
$view = isset($_GET['view']) ? $_GET['view'] : 'structure'; // structure, data, sql, form

$tableData = [];
$tableColumns = [];
$tableStructure = [];
$limit = 50;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$primaryKey = $currentTable ? getPrimaryKey($pdo, $currentTable) : null;

if ($currentTable) {
    // Get Structure
    $stmt = $pdo->query("DESCRIBE `$currentTable`");
    $tableStructure = $stmt->fetchAll();
    $tableColumns = array_column($tableStructure, 'Field');

    if ($view === 'data') {
        // Build Query with Search
        $sql = "SELECT * FROM `$currentTable`";
        $params = [];
        
        if ($search) {
            $where = [];
            foreach ($tableColumns as $col) {
                $where[] = "`$col` LIKE ?";
                $params[] = "%$search%";
            }
            if ($where) {
                $sql .= " WHERE " . implode(" OR ", $where);
            }
        }
        
        // Add Limit
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
    <title>DB Manager - <?=htmlspecialchars($DB_NAME)?></title>
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-body: #000000;
            --bg-sidebar: #0a0a0a;
            --bg-card: #111111;
            --bg-hover: #222222;
            --bg-input: #1a1a1a;
            --border-color: #333333;
            --text-primary: #ffffff;
            --text-secondary: #aaaaaa;
            --accent: #ffffff;
            --sidebar-width: 280px;
            --danger: #ff4444;
            --success: #00C851;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            height: 100vh;
            overflow: hidden;
            display: flex;
        }

        a { text-decoration: none; color: inherit; }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg-body); }
        ::-webkit-scrollbar-thumb { background: #444; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #666; }

        /* SIDEBAR */
        .sidebar {
            width: var(--sidebar-width);
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            height: 100%;
            transition: transform 0.3s ease;
            z-index: 100;
        }

        .brand {
            padding: 20px;
            font-size: 1.2rem;
            font-weight: bold;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .db-info {
            padding: 15px 20px;
            font-size: 0.9rem;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-color);
        }
        .db-info strong { color: var(--text-primary); display: block; margin-bottom: 5px; }

        .nav-list { flex: 1; overflow-y: auto; padding: 10px 0; list-style: none; }
        .nav-item {
            padding: 8px 20px;
            display: flex; align-items: center; gap: 10px;
            color: var(--text-secondary); font-size: 0.9rem;
            cursor: pointer; transition: background 0.2s;
        }
        .nav-item:hover, .nav-item.active { background: var(--bg-hover); color: var(--text-primary); }
        .nav-item i { width: 16px; text-align: center; }
        .nav-header { padding: 15px 20px 5px; font-size: 0.75rem; text-transform: uppercase; color: #666; font-weight: bold; }

        /* MAIN CONTENT */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; position: relative; }

        .top-bar {
            height: 60px;
            background: var(--bg-body);
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; padding: 0 20px;
            justify-content: space-between;
        }

        .toggle-sidebar { display: none; font-size: 1.2rem; cursor: pointer; padding: 5px; }
        .breadcrumb { display: flex; gap: 10px; color: var(--text-secondary); font-size: 0.9rem; }
        .breadcrumb span { color: var(--text-primary); }

        .content-area { flex: 1; overflow-y: auto; padding: 20px; }

        /* COMPONENTS */
        .dashboard-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px; margin-bottom: 30px;
        }

        .card {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 8px; padding: 20px;
        }
        .card-label { font-size: 0.85rem; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 10px; }
        .card-value { font-size: 2rem; font-weight: bold; }
        .card-icon { float: right; font-size: 2rem; opacity: 0.2; }

        .data-table-wrapper {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 8px; overflow: hidden; margin-bottom: 20px;
        }
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .data-table th, .data-table td {
            padding: 12px 15px; text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        .data-table th { background: var(--bg-hover); font-weight: 600; color: var(--text-secondary); text-transform: uppercase; font-size: 0.8rem; }
        .data-table tr:hover { background: var(--bg-hover); }

        .actions { display: flex; gap: 15px; }
        .btn-action { color: var(--text-secondary); cursor: pointer; border: none; background: none; font-size: 1rem; }
        .btn-action:hover { color: var(--text-primary); }
        .text-danger:hover { color: var(--danger); }

        .tabs { display: flex; gap: 5px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); }
        .tab {
            padding: 10px 20px; cursor: pointer; border-bottom: 2px solid transparent;
            color: var(--text-secondary);
        }
        .tab:hover { color: var(--text-primary); }
        .tab.active { border-bottom-color: var(--accent); color: var(--text-primary); }

        .btn {
            padding: 8px 15px; border-radius: 4px; border: 1px solid var(--border-color);
            background: var(--bg-hover); color: var(--text-primary); cursor: pointer;
            font-size: 0.9rem; display: inline-flex; align-items: center; gap: 5px;
        }
        .btn:hover { background: #333; }
        .btn-primary { background: var(--text-primary); color: var(--bg-body); border: none; font-weight: bold; }
        .btn-primary:hover { background: #ddd; }
        .btn-danger { background: var(--danger); color: white; border: none; }
        .btn-danger:hover { background: #cc0000; }

        .form-control {
            width: 100%; padding: 10px; background: var(--bg-input);
            border: 1px solid var(--border-color); color: var(--text-primary);
            border-radius: 4px; font-family: monospace;
        }
        .form-control:focus { outline: 1px solid var(--accent); border-color: var(--accent); }

        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: rgba(0, 200, 81, 0.1); border: 1px solid var(--success); color: var(--success); }
        .alert-danger { background: rgba(255, 68, 68, 0.1); border: 1px solid var(--danger); color: var(--danger); }

        .toolbar { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
        .search-box { display: flex; gap: 0; flex: 1; max-width: 400px; }
        .search-input { border-radius: 4px 0 0 4px; border-right: none; }
        .search-btn { border-radius: 0 4px 4px 0; }

        .badge {
            padding: 2px 6px; border-radius: 4px;
            background: var(--bg-hover); font-size: 0.75rem; margin-left: auto;
        }

        @media (max-width: 768px) {
            .sidebar { position: absolute; left: -100%; height: 100%; width: 100%; background: var(--bg-body); }
            .sidebar.open { transform: translateX(100%); }
            .toggle-sidebar { display: block; }
            .dashboard-grid { grid-template-columns: 1fr; }
            .data-table-wrapper { overflow-x: auto; }
            .search-box { max-width: 100%; }
        }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <i class="fas fa-database"></i>
            <span>DB Manager</span>
            <div style="margin-left: auto; cursor: pointer;" class="toggle-sidebar" onclick="toggleSidebar()">
                <i class="fas fa-times"></i>
            </div>
        </div>
        
        <div class="db-info">
            <strong><i class="fas fa-server"></i> <?=htmlspecialchars($DB_HOST)?></strong>
            <small><?=htmlspecialchars($DB_NAME)?></small>
        </div>

        <div class="nav-list">
            <a href="?" class="nav-item <?=!$currentTable ? 'active' : ''?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <div class="nav-header">Tables (<?=count($tables)?>)</div>
            <?php foreach ($tables as $t): ?>
                <a href="?table=<?=htmlspecialchars($t['Name'])?>" class="nav-item <?=$currentTable === $t['Name'] ? 'active' : ''?>">
                    <i class="fas fa-table"></i> 
                    <?=htmlspecialchars($t['Name'])?>
                    <span class="badge"><?=$t['Rows']?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="top-bar">
            <div style="display: flex; align-items: center; gap: 15px;">
                <div class="toggle-sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </div>
                <div class="breadcrumb">
                    <i class="fas fa-home"></i> / 
                    <?php if ($currentTable): ?>
                        <span><?=htmlspecialchars($currentTable)?></span>
                    <?php else: ?>
                        <span>Dashboard</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="user-info">
                <i class="fas fa-user-circle"></i> <?=htmlspecialchars($DB_USER)?>
            </div>
        </div>

        <div class="content-area">
            <?php if ($msg): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?=htmlspecialchars($msg)?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?=htmlspecialchars($error)?></div>
            <?php endif; ?>

            <?php if ($currentTable): ?>
                <!-- TABLE VIEW -->
                <div class="tabs">
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=structure" class="tab <?=$view==='structure'?'active':''?>">Structure</a>
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=data" class="tab <?=$view==='data'?'active':''?>">Data</a>
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=sql" class="tab <?=$view==='sql'?'active':''?>">SQL</a>
                </div>

                <?php if ($view === 'data'): ?>
                    <div class="toolbar">
                        <a href="?table=<?=htmlspecialchars($currentTable)?>&view=form" class="btn btn-primary"><i class="fas fa-plus"></i> Insert Row</a>
                        <form class="search-box" method="GET">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <input type="hidden" name="view" value="data">
                            <input type="text" name="search" class="form-control search-input" placeholder="Search..." value="<?=htmlspecialchars($search)?>">
                            <button type="submit" class="btn search-btn"><i class="fas fa-search"></i></button>
                        </form>
                    </div>

                    <div class="data-table-wrapper">
                        <div style="padding: 15px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between;">
                            <strong>Showing rows <?=$offset?> - <?=$offset + count($tableData)?></strong>
                            <div class="pagination">
                                <?php if($offset > 0): ?>
                                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=data&offset=<?=max(0, $offset-$limit)?>&search=<?=urlencode($search)?>" class="btn-action"><i class="fas fa-chevron-left"></i> Prev</a>
                                <?php endif; ?>
                                <?php if(count($tableData) >= $limit): ?>
                                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=data&offset=<?=$offset+$limit?>&search=<?=urlencode($search)?>" class="btn-action" style="margin-left: 10px;">Next <i class="fas fa-chevron-right"></i></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="overflow-x: auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 100px;">Actions</th>
                                        <?php foreach ($tableColumns as $col): ?>
                                            <th><?=htmlspecialchars($col)?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tableData as $row): ?>
                                        <tr>
                                            <td class="actions">
                                                <?php if($primaryKey): ?>
                                                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=form&pk=<?=urlencode($primaryKey)?>&val=<?=urlencode($row[$primaryKey])?>" title="Edit"><i class="fas fa-edit btn-action"></i></a>
                                                    <a href="?table=<?=htmlspecialchars($currentTable)?>&action=delete_row&pk=<?=urlencode($primaryKey)?>&val=<?=urlencode($row[$primaryKey])?>" title="Delete" onclick="return confirm('Are you sure?')"><i class="fas fa-trash-alt btn-action text-danger"></i></a>
                                                <?php else: ?>
                                                    <span title="No PK" style="opacity:0.5; cursor:not-allowed;"><i class="fas fa-ban"></i></span>
                                                <?php endif; ?>
                                            </td>
                                            <?php foreach ($row as $val): ?>
                                                <td><?=htmlspecialchars($val !== null ? (string)$val : 'NULL')?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($tableData)): ?>
                                        <tr><td colspan="<?=count($tableColumns)+1?>" style="text-align: center; padding: 30px;">No data found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($view === 'structure'): ?>
                    <div class="toolbar">
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to TRUNCATE this table? All data will be lost!')">
                            <input type="hidden" name="action" value="truncate_table">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <button type="submit" class="btn btn-danger"><i class="fas fa-eraser"></i> Truncate Table</button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to DROP this table?')">
                            <input type="hidden" name="action" value="delete_table">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Drop Table</button>
                        </form>
                    </div>
                    <div class="data-table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>Type</th>
                                    <th>Null</th>
                                    <th>Key</th>
                                    <th>Default</th>
                                    <th>Extra</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tableStructure as $col): ?>
                                    <tr>
                                        <td><b><?=htmlspecialchars($col['Field'])?></b></td>
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

                <?php elseif ($view === 'sql'): ?>
                    <div class="card">
                        <h3 style="margin-bottom: 15px;">Run SQL Query</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="sql_query">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <textarea name="query" class="form-control" rows="10" placeholder="SELECT * FROM `<?=htmlspecialchars($currentTable)?>` WHERE 1"><?=isset($_POST['query']) ? htmlspecialchars($_POST['query']) : "SELECT * FROM `$currentTable` LIMIT 100"?></textarea>
                            <div style="margin-top: 15px; text-align: right;">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-play"></i> Execute</button>
                            </div>
                        </form>
                        <?php if(isset($stmt) && $stmt && $view === 'sql' && $action === 'sql_query'): ?>
                            <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 20px 0;">
                            <h4>Result:</h4>
                            <div class="data-table-wrapper" style="margin-top: 15px;">
                                <div style="overflow-x: auto;">
                                    <table class="data-table">
                                        <?php 
                                        $results = $stmt->fetchAll();
                                        if ($results): 
                                            $keys = array_keys($results[0]);
                                        ?>
                                            <thead>
                                                <tr>
                                                    <?php foreach ($keys as $k): ?><th><?=htmlspecialchars($k)?></th><?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($results as $r): ?>
                                                    <tr>
                                                        <?php foreach ($r as $v): ?><td><?=htmlspecialchars((string)$v)?></td><?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        <?php else: ?>
                                            <tbody><tr><td style="padding: 20px;">No rows returned or empty result.</td></tr></tbody>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($view === 'form'): ?>
                    <?php
                        $formData = [];
                        if (isset($_GET['pk']) && isset($_GET['val'])) {
                            $stmt = $pdo->prepare("SELECT * FROM `$currentTable` WHERE `".$_GET['pk']."` = ?");
                            $stmt->execute([$_GET['val']]);
                            $formData = $stmt->fetch();
                        }
                    ?>
                    <div class="card">
                        <h3 style="margin-bottom: 20px;"><?= $formData ? 'Edit Row' : 'Insert New Row' ?></h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_row">
                            <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                            <?php if ($primaryKey && isset($formData[$primaryKey])): ?>
                                <input type="hidden" name="pk" value="<?=htmlspecialchars($primaryKey)?>">
                                <input type="hidden" name="pk_val" value="<?=htmlspecialchars($formData[$primaryKey])?>">
                            <?php endif; ?>

                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                                <?php foreach ($tableStructure as $col): ?>
                                    <div class="form-group">
                                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                                            <?=htmlspecialchars($col['Field'])?> 
                                            <span style="font-size: 0.8em; color: var(--text-secondary);">
                                                (<?=htmlspecialchars($col['Type'])?>)
                                            </span>
                                        </label>
                                        <?php if ($col['Extra'] == 'auto_increment'): ?>
                                            <input type="text" class="form-control" disabled value="(Auto Increment)">
                                        <?php else: ?>
                                            <input type="text" name="data[<?=htmlspecialchars($col['Field'])?>]" class="form-control" value="<?=isset($formData[$col['Field']]) ? htmlspecialchars($formData[$col['Field']]) : ''?>">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="margin-top: 20px;">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                                <a href="?table=<?=htmlspecialchars($currentTable)?>&view=data" class="btn"><i class="fas fa-times"></i> Cancel</a>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- DASHBOARD VIEW -->
                <div class="dashboard-grid">
                    <div class="card">
                        <i class="fas fa-table card-icon"></i>
                        <div class="card-label">Total Tables</div>
                        <div class="card-value"><?=$totalTables?></div>
                    </div>
                    <div class="card">
                        <i class="fas fa-list-ol card-icon"></i>
                        <div class="card-label">Total Rows</div>
                        <div class="card-value"><?=number_format($totalRows)?></div>
                    </div>
                    <div class="card">
                        <i class="fas fa-hdd card-icon"></i>
                        <div class="card-label">Size</div>
                        <div class="card-value"><?=formatSize($totalSize)?></div>
                    </div>
                </div>

                <h3 style="margin-bottom: 15px;">Database Tables</h3>
                <div class="data-table-wrapper">
                    <table class="data-table">
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
                            <?php foreach ($tables as $t): ?>
                                <tr>
                                    <td><a href="?table=<?=htmlspecialchars($t['Name'])?>" style="font-weight: bold; color: #fff;"><?=htmlspecialchars($t['Name'])?></a></td>
                                    <td><?=number_format($t['Rows'])?></td>
                                    <td><?=formatSize($t['Data_length'] + $t['Index_length'])?></td>
                                    <td><?=$t['Collation']?></td>
                                    <td class="actions">
                                        <a href="?table=<?=htmlspecialchars($t['Name'])?>&view=structure" title="Structure"><i class="fas fa-wrench btn-action"></i></a>
                                        <a href="?table=<?=htmlspecialchars($t['Name'])?>&view=data" title="Browse"><i class="fas fa-table btn-action"></i></a>
                                        <a href="?table=<?=htmlspecialchars($t['Name'])?>&view=sql" title="SQL"><i class="fas fa-terminal btn-action"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
    </script>
</body>
</html>
