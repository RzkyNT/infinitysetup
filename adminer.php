<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
/**
 * JsonDatabase - A simple JSON-based database system
 * Provides SQL-like operations on JSON files
 * Supports two formats:
 * 1. Table format: {"users": [{"id":1,"name":"John"}]}
 * 2. Flat format: {"maxQuota": 50, "formActive": true}
 */
class JsonDatabase {
    private $basePath;
    private $currentFile;
    private $data;
    private $isFlat = false; // Detect flat structure
    
    public function __construct($basePath = './json_db/') {
        $this->basePath = rtrim($basePath, '/') . '/';
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }
    
    /**
     * Select a JSON file (equivalent to selecting a database)
     */
    public function selectFile($filename) {
        $this->currentFile = $this->basePath . basename($filename);
        if (!file_exists($this->currentFile)) {
            file_put_contents($this->currentFile, json_encode([], JSON_PRETTY_PRINT));
        }
        $this->loadData();
        return true;
    }
    
    /**
     * Set file path directly (for external files)
     */
    public function setFilePath($fullPath) {
        if (!file_exists($fullPath)) {
            throw new Exception('File not found: ' . $fullPath);
        }
        if (pathinfo($fullPath, PATHINFO_EXTENSION) !== 'json') {
            throw new Exception('File must be a JSON file');
        }
        $this->currentFile = $fullPath;
        $this->loadData();
        return true;
    }
    
    /**
     * Load data from current JSON file
     */
    private function loadData() {
        if (!$this->currentFile) {
            throw new Exception('No file selected');
        }
        $content = file_get_contents($this->currentFile);
        $decoded = json_decode($content, true);
        
        if (!is_array($decoded)) {
            $this->data = [];
        } else {
            // Check if root is an array (not object)
            if (isset($decoded[0])) {
                // Root-level array: wrap it with default table name
                $this->data = ['data' => $decoded];
            } else {
                $this->data = $decoded;
            }
        }
        
        // Detect if this is a flat structure (key-value pairs)
        $this->isFlat = $this->detectFlatStructure();
    }
    
    /**
     * Detect if JSON is flat structure (config-style) or table structure
     */
    private function detectFlatStructure() {
        if (empty($this->data)) {
            return false;
        }
        
        // Check if all values are scalar or if any value is an array of objects
        $hasArrayOfObjects = false;
        foreach ($this->data as $key => $value) {
            if (is_array($value) && !empty($value)) {
                // Check if it's an array of objects (table format)
                // First element should be an associative array (object)
                $firstElement = reset($value);
                if (is_array($firstElement) && !isset($firstElement[0])) {
                    // It's an associative array (object), so this is table format
                    $hasArrayOfObjects = true;
                    break;
                }
            }
        }
        
        // If no array of objects found, it's a flat structure
        return !$hasArrayOfObjects;
    }
    
    /**
     * Save data to current JSON file
     */
    private function saveData() {
        if (!$this->currentFile) {
            throw new Exception('No file selected');
        }
        
        // Check if we need to unwrap the data (root-level array)
        $toSave = $this->data;
        if (isset($this->data['data']) && count($this->data) === 1) {
            // Check if original file was root-level array
            $content = file_get_contents($this->currentFile);
            $original = json_decode($content, true);
            if (isset($original[0])) {
                // Original was root-level array, unwrap it
                $toSave = $this->data['data'];
            }
        }
        
        $json = json_encode($toSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return file_put_contents($this->currentFile, $json) !== false;
    }
    
    /**
     * Get list of all JSON files (equivalent to SHOW DATABASES)
     */
    public function listFiles() {
        $files = glob($this->basePath . '*.json');
        return array_map('basename', $files);
    }
    
    /**
     * Get list of "tables" (root keys in JSON)
     * For flat structure, returns single table "config"
     */
    public function listTables() {
        if (!is_array($this->data)) {
            return [];
        }
        
        if ($this->isFlat) {
            // Flat structure: treat entire file as one table
            return ['config'];
        }
        
        return array_keys($this->data);
    }
    
    /**
     * Get table structure (columns from first row)
     * For flat structure, returns key-value structure
     */
    public function getTableStructure($table) {
        if ($this->isFlat) {
            // Flat structure: return columns as key, value, type
            return [
                ['Field' => 'key', 'Type' => 'string', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => ''],
                ['Field' => 'value', 'Type' => 'mixed', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''],
                ['Field' => 'type', 'Type' => 'string', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '']
            ];
        }
        
        if (!isset($this->data[$table]) || !is_array($this->data[$table]) || empty($this->data[$table])) {
            return [];
        }
        
        $firstRow = reset($this->data[$table]);
        if (!is_array($firstRow)) {
            return [];
        }
        
        $structure = [];
        foreach ($firstRow as $key => $value) {
            $structure[] = [
                'Field' => $key,
                'Type' => $this->guessType($value),
                'Null' => 'YES',
                'Key' => $key === 'id' ? 'PRI' : '',
                'Default' => null,
                'Extra' => ''
            ];
        }
        return $structure;
    }
    
    /**
     * Guess data type from value
     */
    private function guessType($value) {
        if (is_int($value)) return 'int';
        if (is_float($value)) return 'float';
        if (is_bool($value)) return 'boolean';
        if (is_array($value)) return 'json';
        if (is_null($value)) return 'null';
        return 'string';
    }
    
    /**
     * SELECT query
     * For flat structure, converts to key-value-type rows
     */
    public function select($table, $conditions = [], $orderBy = null, $orderDir = 'ASC', $limit = null, $offset = 0) {
        if ($this->isFlat) {
            // Convert flat structure to rows
            $results = [];
            foreach ($this->data as $key => $value) {
                $results[] = [
                    'key' => $key,
                    'value' => is_array($value) ? json_encode($value) : $value,
                    'type' => $this->guessType($value)
                ];
            }
            
            // Apply conditions
            if (!empty($conditions)) {
                $results = array_filter($results, function($row) use ($conditions) {
                    foreach ($conditions as $field => $condition) {
                        $operator = $condition['operator'] ?? '=';
                        $value = $condition['value'] ?? '';
                        
                        if (!isset($row[$field])) {
                            return false;
                        }
                        
                        $rowValue = $row[$field];
                        
                        switch ($operator) {
                            case '=':
                                if ($rowValue != $value) return false;
                                break;
                            case '!=':
                                if ($rowValue == $value) return false;
                                break;
                            case 'LIKE':
                                $pattern = str_replace('%', '.*', preg_quote($value, '/'));
                                if (!preg_match('/^' . $pattern . '$/i', $rowValue)) return false;
                                break;
                        }
                    }
                    return true;
                });
            }
            
            // Apply ordering
            if ($orderBy && !empty($results)) {
                usort($results, function($a, $b) use ($orderBy, $orderDir) {
                    $aVal = $a[$orderBy] ?? '';
                    $bVal = $b[$orderBy] ?? '';
                    
                    if ($aVal == $bVal) return 0;
                    
                    $comparison = $aVal < $bVal ? -1 : 1;
                    return $orderDir === 'DESC' ? -$comparison : $comparison;
                });
            }
            
            // Apply limit and offset
            if ($limit !== null) {
                $results = array_slice($results, $offset, $limit);
            }
            
            return array_values($results);
        }
        
        // Table structure handling (original code)
        if (!isset($this->data[$table]) || !is_array($this->data[$table])) {
            return [];
        }
        
        $results = $this->data[$table];
        
        // Apply conditions (WHERE clause)
        if (!empty($conditions)) {
            $results = array_filter($results, function($row) use ($conditions) {
                foreach ($conditions as $field => $condition) {
                    $operator = $condition['operator'] ?? '=';
                    $value = $condition['value'] ?? '';
                    
                    if (!isset($row[$field])) {
                        return false;
                    }
                    
                    $rowValue = $row[$field];
                    
                    switch ($operator) {
                        case '=':
                            if ($rowValue != $value) return false;
                            break;
                        case '!=':
                            if ($rowValue == $value) return false;
                            break;
                        case '>':
                            if ($rowValue <= $value) return false;
                            break;
                        case '<':
                            if ($rowValue >= $value) return false;
                            break;
                        case '>=':
                            if ($rowValue < $value) return false;
                            break;
                        case '<=':
                            if ($rowValue > $value) return false;
                            break;
                        case 'LIKE':
                            $pattern = str_replace('%', '.*', preg_quote($value, '/'));
                            if (!preg_match('/^' . $pattern . '$/i', $rowValue)) return false;
                            break;
                        case 'IN':
                            if (!in_array($rowValue, (array)$value)) return false;
                            break;
                    }
                }
                return true;
            });
        }
        
        // Apply ordering
        if ($orderBy && !empty($results)) {
            usort($results, function($a, $b) use ($orderBy, $orderDir) {
                $aVal = $a[$orderBy] ?? '';
                $bVal = $b[$orderBy] ?? '';
                
                if ($aVal == $bVal) return 0;
                
                $comparison = $aVal < $bVal ? -1 : 1;
                return $orderDir === 'DESC' ? -$comparison : $comparison;
            });
        }
        
        // Apply limit and offset
        if ($limit !== null) {
            $results = array_slice($results, $offset, $limit);
        }
        
        return array_values($results);
    }
    
    /**
     * Count rows
     */
    public function count($table, $conditions = []) {
        $results = $this->select($table, $conditions);
        return count($results);
    }
    
    /**
     * INSERT query
     * For flat structure, adds/updates key-value pair
     */
    public function insert($table, $data) {
        if ($this->isFlat) {
            // Flat structure: add/update key-value
            if (isset($data['key']) && isset($data['value'])) {
                $key = $data['key'];
                $value = $data['value'];
                
                // Try to decode JSON
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
                
                // Convert string booleans
                if ($value === 'true') $value = true;
                if ($value === 'false') $value = false;
                
                // Convert numeric strings
                if (is_numeric($value)) {
                    $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
                }
                
                $this->data[$key] = $value;
                return $this->saveData();
            }
            return false;
        }
        
        // Table structure handling
        if (!isset($this->data[$table])) {
            $this->data[$table] = [];
        }
        
        // Auto-generate ID if not provided
        if (!isset($data['id'])) {
            $maxId = 0;
            foreach ($this->data[$table] as $row) {
                if (isset($row['id']) && is_numeric($row['id']) && $row['id'] > $maxId) {
                    $maxId = $row['id'];
                }
            }
            $data['id'] = $maxId + 1;
        }
        
        $this->data[$table][] = $data;
        return $this->saveData();
    }
    
    /**
     * Duplicate row
     */
    public function duplicate_row($table, $pkVal) {
        if (!isset($this->data[$table])) return false;
        foreach ($this->data[$table] as $row) {
            if (isset($row['id']) && $row['id'] == $pkVal) {
                $newRow = $row;
                unset($newRow['id']); // Let insert re-generate it
                return $this->insert($table, $newRow);
            }
        }
        return false;
    }
    
    /**
     * UPDATE query
     * For flat structure, updates key-value pair
     */
    public function update($table, $data, $conditions) {
        if ($this->isFlat) {
            // Flat structure: update by key
            if (isset($conditions['key'])) {
                $key = $conditions['key']['value'];
                if (isset($this->data[$key]) && isset($data['value'])) {
                    $value = $data['value'];
                    
                    // Try to decode JSON
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $value = $decoded;
                    }
                    
                    // Convert string booleans
                    if ($value === 'true') $value = true;
                    if ($value === 'false') $value = false;
                    
                    // Convert numeric strings
                    if (is_numeric($value)) {
                        $value = strpos($value, '.') !== false ? (float)$value : (int)$value;
                    }
                    
                    $this->data[$key] = $value;
                    return $this->saveData() ? 1 : 0;
                }
            }
            return 0;
        }
        
        // Table structure handling
        if (!isset($this->data[$table])) {
            return false;
        }
        
        $updated = 0;
        foreach ($this->data[$table] as &$row) {
            $match = true;
            foreach ($conditions as $field => $condition) {
                $operator = $condition['operator'] ?? '=';
                $value = $condition['value'] ?? '';
                
                if (!isset($row[$field]) || $row[$field] != $value) {
                    $match = false;
                    break;
                }
            }
            
            if ($match) {
                foreach ($data as $key => $value) {
                    $row[$key] = $value;
                }
                $updated++;
            }
        }
        
        if ($updated > 0) {
            $this->saveData();
        }
        
        return $updated;
    }
    
    /**
     * DELETE query
     * For flat structure, removes key
     */
    public function delete($table, $conditions) {
        if ($this->isFlat) {
            // Flat structure: delete by key
            if (isset($conditions['key'])) {
                $key = $conditions['key']['value'];
                if (isset($this->data[$key])) {
                    unset($this->data[$key]);
                    return $this->saveData() ? 1 : 0;
                }
            }
            return 0;
        }
        
        // Table structure handling
        if (!isset($this->data[$table])) {
            return 0;
        }
        
        $originalCount = count($this->data[$table]);
        
        $this->data[$table] = array_filter($this->data[$table], function($row) use ($conditions) {
            foreach ($conditions as $field => $condition) {
                $operator = $condition['operator'] ?? '=';
                $value = $condition['value'] ?? '';
                
                if (isset($row[$field]) && $row[$field] == $value) {
                    return false; // Remove this row
                }
            }
            return true; // Keep this row
        });
        
        $this->data[$table] = array_values($this->data[$table]); // Re-index
        
        $deleted = $originalCount - count($this->data[$table]);
        
        if ($deleted > 0) {
            $this->saveData();
        }
        
        return $deleted;
    }
    
    /**
     * Create new table
     */
    public function createTable($table, $structure = []) {
        if (!isset($this->data[$table])) {
            $this->data[$table] = [];
            return $this->saveData();
        }
        return false;
    }
    
    /**
     * Drop table
     */
    public function dropTable($table) {
        if (isset($this->data[$table])) {
            unset($this->data[$table]);
            return $this->saveData();
        }
        return false;
    }
    
    /**
     * Truncate table
     */
    public function truncateTable($table) {
        if (isset($this->data[$table])) {
            $this->data[$table] = [];
            return $this->saveData();
        }
        return false;
    }
    
    /**
     * Get primary key field (assumes 'id' or first field)
     * For flat structure, returns 'key'
     */
    public function getPrimaryKey($table) {
        if ($this->isFlat) {
            return 'key';
        }
        
        $structure = $this->getTableStructure($table);
        if (empty($structure)) {
            return 'id';
        }
        
        foreach ($structure as $field) {
            if ($field['Key'] === 'PRI') {
                return $field['Field'];
            }
        }
        
        return $structure[0]['Field'] ?? 'id';
    }
    
    /**
     * Export table to array
     */
    public function exportTable($table) {
        return $this->data[$table] ?? [];
    }
    
    /**
     * Import data to table
     */
    public function importTable($table, $data) {
        if (!is_array($data)) {
            return false;
        }
        
        $this->data[$table] = $data;
        return $this->saveData();
    }
    
    /**
     * Get current file path
     */
    public function getCurrentFile() {
        return $this->currentFile;
    }
    
    /**
     * Get base path
     */
    public function getBasePath() {
        return $this->basePath;
    }
    
    /**
     * Create new JSON file
     */
    public function createFile($filename, $initialData = []) {
        $filepath = $this->basePath . basename($filename);
        if (file_exists($filepath)) {
            return false;
        }
        
        $json = json_encode($initialData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return file_put_contents($filepath, $json) !== false;
    }
    
    /**
     * Delete JSON file
     */
    public function deleteFile($filename) {
        $filepath = $this->basePath . basename($filename);
        if (file_exists($filepath)) {
            return unlink($filepath);
        }
        return false;
    }
}

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

if (isset($_GET['api']) && $_GET['api'] === 'generate_md5') {
    header('Content-Type: application/json');
    
    // Cek apakah user sudah login
    if (!isset($_SESSION['db_user'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $input = $_POST['input'] ?? '';
    
    // MD5 logic
    $hash = md5($input);

    echo json_encode([
        'success' => true, 
        'hash' => $hash,
        'algo' => 'MD5'
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
    // Only show for SQL mode
    $dbMode = $_SESSION['db_mode'] ?? 'sql';
    return $hostProfile !== 'local' && $dbMode === 'sql';
}

function should_show_server_database_panel($hostProfile) {
    // Show for all modes, but content will be different based on mode
    return true;
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
if (!is_array($user_defined_databases)) {
    if (is_string($user_defined_databases) && trim($user_defined_databases) !== '') {
        // Support legacy comma-delimited strings by normalizing to an array
        $user_defined_databases = array_filter(array_map('trim', preg_split('/[,\n]+/', $user_defined_databases)));
    } else {
        $user_defined_databases = [];
    }
}
$user_defined_databases = array_values(array_unique($user_defined_databases));

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

// ===== INITIALIZE JSONDATABASE EARLY (before mode switching) =====
$jsonDb = new JsonDatabase(__DIR__ . '/json_db/');

// ===== SQLITE FILE SELECTION =====
if (isset($_GET['select_sqlite_file'])) {
    $sqliteFile = $_GET['select_sqlite_file'];
    $sqlitePath = __DIR__ . '/sqlite_db/' . basename($sqliteFile);
    
    if (file_exists($sqlitePath)) {
        $_SESSION['sqlite_file'] = $sqliteFile;
        $_SESSION['sqlite_file_path'] = $sqlitePath;
        $_SESSION['db_mode'] = 'sqlite';
        
        // Reconnect to SQLite
        try {
            $pdo = new PDO('sqlite:' . $sqlitePath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (Exception $e) {
            error_log("SQLite connection error: " . $e->getMessage());
        }
    }
    header("Location: ?");
    exit;
}

// ===== DATABASE MODE SWITCHING (SQL/JSON/SQLITE) =====
if (isset($_GET['db_mode'])) {
    $mode = $_GET['db_mode'];
    if (in_array($mode, ['sql', 'json', 'sqlite'])) {
        $_SESSION['db_mode'] = $mode;
        
        // Don't clear mode-specific sessions - keep them for when user switches back
        // This allows users to switch between modes without losing their file selections
        
        // If switching to SQLite and there's a saved SQLite file, reconnect to it
        if ($mode === 'sqlite' && !empty($_SESSION['sqlite_file_path'])) {
            try {
                $pdo = new PDO('sqlite:' . $_SESSION['sqlite_file_path']);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (Exception $e) {
                error_log("SQLite reconnection error: " . $e->getMessage());
            }
        }
        
        // If switching to JSON and there's a saved JSON file, reload it
        if ($mode === 'json' && !empty($_SESSION['json_file'])) {
            try {
                if (!empty($_SESSION['json_file_external']) && !empty($_SESSION['json_file_full_path'])) {
                    $jsonDb->setFilePath($_SESSION['json_file_full_path']);
                } else {
                    $jsonDb->selectFile($_SESSION['json_file']);
                }
            } catch (Exception $e) {
                error_log("JSON reconnection error: " . $e->getMessage());
            }
        }
    }
    header("Location: ?");
    exit;
}

// ===== JSON FILE SELECTION =====
if (isset($_GET['select_json_file'])) {
    $jsonFile = $_GET['select_json_file'];
    $isExternal = isset($_GET['external']) && $_GET['external'] === '1';
    
    if ($isExternal) {
        // External file (full path from server root)
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($jsonFile, '/');
        
        // Security check: ensure file is within document root
        $realPath = realpath($fullPath);
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT']);
        
        if ($realPath && strpos($realPath, $docRoot) === 0 && file_exists($realPath) && pathinfo($realPath, PATHINFO_EXTENSION) === 'json') {
            $_SESSION['json_file'] = $jsonFile;
            $_SESSION['json_file_external'] = true;
            $_SESSION['json_file_full_path'] = $realPath;
        } else {
            error_log("Invalid external JSON file: $jsonFile");
        }
    } else {
        // Internal file (from json_db folder)
        $_SESSION['json_file'] = $jsonFile;
        $_SESSION['json_file_external'] = false;
        unset($_SESSION['json_file_full_path']);
    }
    
    $_SESSION['db_mode'] = 'json';
    header("Location: ?");
    exit;
}

// Initialize database mode (default to SQL)
$_SESSION['db_mode'] = $_SESSION['db_mode'] ?? 'sql';

// Create sqlite_db folder if not exists
if (!is_dir(__DIR__ . '/sqlite_db')) {
    mkdir(__DIR__ . '/sqlite_db', 0755, true);
}

// Load JSON file if in JSON mode (jsonDb already initialized above)
if (!empty($_SESSION['json_file'])) {
    try {
        if (!empty($_SESSION['json_file_external']) && !empty($_SESSION['json_file_full_path'])) {
            // External file - use full path directly
            $jsonDb->setFilePath($_SESSION['json_file_full_path']);
        } else {
            // Internal file - use relative path from json_db folder
            $jsonDb->selectFile($_SESSION['json_file']);
        }
    } catch (Exception $e) {
        error_log("JsonDatabase error: " . $e->getMessage());
    }
}

// Initialize SQLite connection if in SQLite mode
if (($_SESSION['db_mode'] ?? 'sql') === 'sqlite' && !empty($_SESSION['sqlite_file_path'])) {
    try {
        $pdo = new PDO('sqlite:' . $_SESSION['sqlite_file_path']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("SQLite connection error: " . $e->getMessage());
    }
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
                // Jika gagal konek ke DB, reset state agar tidak crash di query selanjutnya
                $_SESSION['db_name'] = '';
                $hasSelectedDatabase = false;
                $DB_NAME = '';
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

/**
 * Order tables based on foreign-key dependencies.
 * By default parents come first; when $childFirst is true result is reversed so children precede parents.
 */
function order_tables_by_dependencies(PDO $pdo, array $tables, $schema, $childFirst = false) {
    $tables = array_values(array_unique(array_filter($tables)));
    if (count($tables) <= 1) {
        return $tables;
    }

    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $params = array_merge([$schema], $tables);
    $sql = "
        SELECT TABLE_NAME, REFERENCED_TABLE_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = ?
          AND REFERENCED_TABLE_NAME IS NOT NULL
          AND TABLE_NAME IN ($placeholders)
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return $tables;
    }

    $set = array_fill_keys($tables, true);
    $graph = [];
    $inDegree = array_fill_keys($tables, 0);

    foreach ($rows as $row) {
        $child = $row['TABLE_NAME'];
        $parent = $row['REFERENCED_TABLE_NAME'];
        if (!isset($set[$child]) || !isset($set[$parent])) {
            continue;
        }
        $graph[$parent][] = $child;
        $inDegree[$child] += 1;
    }

    $queue = [];
    foreach ($inDegree as $tbl => $deg) {
        if ($deg === 0) {
            $queue[] = $tbl;
        }
    }

    $ordered = [];
    while ($queue) {
        $tbl = array_shift($queue);
        $ordered[] = $tbl;
        if (!empty($graph[$tbl])) {
            foreach ($graph[$tbl] as $child) {
                $inDegree[$child] -= 1;
                if ($inDegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }
    }

    if (count($ordered) !== count($tables)) {
        return $tables;
    }

    if ($childFirst) {
        $ordered = array_reverse($ordered);
    }
    return $ordered;
}

// ===== ACTION HANDLER (POST) =====
if ($is_logged_in && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle JSON payloads (e.g. for Excel Import)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (is_array($jsonInput)) {
            $_POST = array_merge($_POST, $jsonInput);
        }
    }

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
    // --- EXPORT FILTERED DATA ---
    elseif ($action === 'export_filtered') {
        $table = $_POST['table'] ?? '';
        $format = $_POST['format'] ?? 'csv';
        $whereClause = $_POST['where_clause'] ?? '';
        
        if (empty($table)) {
            fm_set_msg('Table name is required', 'error');
            fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
        }
        
        if (empty($whereClause)) {
            fm_set_msg('No filter conditions provided', 'error');
            fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
        }
        
        try {
            // Check if we're in JSON mode
            if (($_SESSION['db_mode'] ?? 'sql') === 'json' && !empty($_SESSION['json_file'])) {
                // JSON Mode - parse WHERE clause and fetch data
                // For simplicity, we'll fetch all data and filter in PHP
                $allData = $jsonDb->select($table);
                $rows = $allData; // TODO: Implement WHERE clause parsing for JSON
            } else {
                // SQL Mode
                $query = "SELECT * FROM `$table` WHERE $whereClause";
                $stmt = $pdo->query($query);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            if (empty($rows)) {
                fm_set_msg('No data to export', 'error');
                fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
            }
            
            $filename = $table . "_filtered_" . date("Y-m-d_H-i-s");
            
            if ($format === 'csv') {
                header('Content-Type: text/csv');
                header("Content-Disposition: attachment; filename=\"$filename.csv\"");
                $out = fopen('php://output', 'w');
                fputcsv($out, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
            } elseif ($format === 'json') {
                header('Content-Type: application/json');
                header("Content-Disposition: attachment; filename=\"$filename.json\"");
                echo json_encode($rows, JSON_PRETTY_PRINT);
            } elseif ($format === 'sql') {
                header('Content-Type: application/octet-stream');
                header("Content-Disposition: attachment; filename=\"$filename.sql\"");
                foreach ($rows as $row) {
                    $keys = array_keys($row);
                    $values = array_map(function($v) use ($pdo) {
                        return $v === null ? "NULL" : $pdo->quote($v);
                    }, array_values($row));
                    echo "INSERT INTO `$table` (`" . implode('`, `', $keys) . "`) VALUES (" . implode(', ', $values) . ");\n";
                }
            }
            exit;
        } catch (Exception $e) {
            fm_set_msg('Export error: ' . $e->getMessage(), 'error');
            fm_redirect(FM_SELF_URL . '?p=' . urlencode(FM_PATH));
        }
    }
    // --- CREATE JSON FILE ---
    elseif ($action === 'create_json_file') {
        header('Content-Type: application/json');
        $filename = $_POST['filename'] ?? '';
        
        if (empty($filename)) {
            echo json_encode(['success' => false, 'message' => 'Filename is required']);
            exit;
        }
        
        if (!preg_match('/^[a-zA-Z0-9_\-]+\.json$/', $filename)) {
            echo json_encode(['success' => false, 'message' => 'Invalid filename. Use only letters, numbers, underscore, and hyphen']);
            exit;
        }
        
        try {
            $jsonDb->selectFile($filename);
            // Create empty file with default structure
            $jsonDb->createTable('default_table');
            echo json_encode(['success' => true, 'message' => 'JSON file created successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    // --- CREATE SQLITE FILE ---
    elseif ($action === 'create_sqlite_file') {
        header('Content-Type: application/json');
        $filename = $_POST['filename'] ?? '';
        
        if (empty($filename)) {
            echo json_encode(['success' => false, 'message' => 'Filename is required']);
            exit;
        }
        
        if (!preg_match('/^[a-zA-Z0-9_\-]+\.(db|sqlite)$/', $filename)) {
            echo json_encode(['success' => false, 'message' => 'Invalid filename. Use only letters, numbers, underscore, and hyphen']);
            exit;
        }
        
        try {
            $sqlitePath = __DIR__ . '/sqlite_db/' . basename($filename);
            
            if (file_exists($sqlitePath)) {
                echo json_encode(['success' => false, 'message' => 'File already exists']);
                exit;
            }
            
            // Create new SQLite database
            $sqlitePdo = new PDO('sqlite:' . $sqlitePath);
            $sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Create a default table
            $sqlitePdo->exec("CREATE TABLE IF NOT EXISTS example (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
            
            echo json_encode(['success' => true, 'message' => 'SQLite database created successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    // --- DELETE SQLITE FILE ---
    elseif ($action === 'delete_sqlite_file') {
        header('Content-Type: application/json');
        $filename = $_POST['filename'] ?? '';
        
        if (empty($filename)) {
            echo json_encode(['success' => false, 'message' => 'Filename is required']);
            exit;
        }
        
        try {
            $filePath = __DIR__ . '/sqlite_db/' . basename($filename);
            if (file_exists($filePath)) {
                if (unlink($filePath)) {
                    // Clear session if deleting current file
                    if ($_SESSION['sqlite_file'] === $filename) {
                        unset($_SESSION['sqlite_file']);
                        unset($_SESSION['sqlite_file_path']);
                    }
                    echo json_encode(['success' => true, 'message' => 'SQLite database deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to delete file']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'File not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    // --- BROWSE SQLITE FILES ---
    elseif ($action === 'browse_sqlite_files') {
        header('Content-Type: application/json');
        $path = $_POST['path'] ?? '';
        
        // Security: clean path and prevent directory traversal
        $path = str_replace(['..', '\\'], ['', '/'], $path);
        $path = trim($path, '/');
        
        // Base path is document root
        $basePath = $_SERVER['DOCUMENT_ROOT'];
        $fullPath = $basePath . ($path ? '/' . $path : '');
        
        try {
            if (!is_dir($fullPath)) {
                throw new Exception('Invalid path');
            }
            
            $items = scandir($fullPath);
            $folders = [];
            $files = [];
            
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                
                // Skip hidden files/folders
                if (substr($item, 0, 1) === '.') continue;
                
                $itemPath = $fullPath . '/' . $item;
                
                if (is_dir($itemPath)) {
                    $folders[] = $item;
                } elseif (is_file($itemPath)) {
                    $ext = pathinfo($item, PATHINFO_EXTENSION);
                    if ($ext === 'db' || $ext === 'sqlite' || $ext === 'sqlite3') {
                        $files[] = $item;
                    }
                }
            }
            
            // Sort
            natcasesort($folders);
            natcasesort($files);
            
            echo json_encode([
                'success' => true,
                'current_path' => $path,
                'items' => [
                    'folders' => array_values($folders),
                    'files' => array_values($files)
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
    // --- DELETE JSON FILE ---
    elseif ($action === 'delete_json_file') {
        header('Content-Type: application/json');
        $filename = $_POST['filename'] ?? '';
        
        if (empty($filename)) {
            echo json_encode(['success' => false, 'message' => 'Filename is required']);
            exit;
        }
        
        try {
            $filePath = __DIR__ . '/json_db/' . basename($filename);
            if (file_exists($filePath)) {
                if (unlink($filePath)) {
                    // Clear session if deleting current file
                    if ($_SESSION['json_file'] === $filename) {
                        unset($_SESSION['json_file']);
                    }
                    echo json_encode(['success' => true, 'message' => 'JSON file deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to delete file']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'File not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    // --- BROWSE FILES (FOR JSON FILE SELECTION) ---
    elseif ($action === 'browse_files') {
        header('Content-Type: application/json');
        $path = $_POST['path'] ?? '';
        
        // Security: clean path and prevent directory traversal
        $path = str_replace(['..', '\\'], ['', '/'], $path);
        $path = trim($path, '/');
        
        // Base path is document root
        $basePath = $_SERVER['DOCUMENT_ROOT'];
        $fullPath = $basePath . ($path ? '/' . $path : '');
        
        try {
            if (!is_dir($fullPath)) {
                throw new Exception('Invalid path');
            }
            
            $items = scandir($fullPath);
            $folders = [];
            $files = [];
            
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                
                // Skip hidden files/folders
                if (substr($item, 0, 1) === '.') continue;
                
                $itemPath = $fullPath . '/' . $item;
                
                if (is_dir($itemPath)) {
                    $folders[] = $item;
                } elseif (is_file($itemPath) && pathinfo($item, PATHINFO_EXTENSION) === 'json') {
                    $files[] = $item;
                }
            }
            
            // Sort
            natcasesort($folders);
            natcasesort($files);
            
            echo json_encode([
                'success' => true,
                'current_path' => $path,
                'items' => [
                    'folders' => array_values($folders),
                    'files' => array_values($files)
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
    // --- DUPLICATE ROW ---
    elseif ($action === 'duplicate_row') {
        $pk = $_POST['pk'] ?? null;
        $val = $_POST['val'] ?? null;
        try {
            if ($pk && $val) {
                if (($_SESSION['db_mode'] ?? 'sql') === 'json') {
                    $jsonDb->duplicate_row($table, $val);
                } else {
                    // SQL Mode: Get columns excluding auto_increment
                    $stmt = $pdo->query("SHOW COLUMNS FROM `$table` WHERE Extra NOT LIKE '%auto_increment%'");
                    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $colStr = "`" . implode("`, `", $cols) . "`";
                    
                    $sql = "INSERT INTO `$table` ($colStr) SELECT $colStr FROM `$table` WHERE `$pk` = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$val]);
                }
                $msg = "Row duplicated successfully.";
            }
            redirect("?table=$table&view=data&msg=" . urlencode($msg));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    // --- SAVE ROW ---
    elseif ($action === 'save_row') {
        $data = $_POST['data'] ?? [];
        $pk = $_POST['pk'] ?? null;
        $pkVal = $_POST['pk_val'] ?? null;
        
        try {
            // Check if we're in JSON mode
            if (($_SESSION['db_mode'] ?? 'sql') === 'json' && !empty($_SESSION['json_file'])) {
                if ($pkVal) {
                    // UPDATE
                    $jsonDb->update($table, $data, [
                        'id' => ['operator' => '=', 'value' => $pkVal]
                    ]);
                    $msg = "Row updated successfully.";
                } else {
                    // INSERT
                    $jsonDb->insert($table, $data);
                    $msg = "Row inserted successfully.";
                }
            } else {
                // SQL Mode
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
        $dbMode = $_SESSION['db_mode'] ?? 'sql';
        
        // Determine filename
        if ($dbMode === 'json') {
            $filename = ($exportTable ? $exportTable : 'json_export') . "_" . date("Y-m-d_H-i-s");
        } elseif ($dbMode === 'sqlite') {
            $filename = ($exportTable ? $exportTable : 'sqlite_export') . "_" . date("Y-m-d_H-i-s");
        } else {
            $filename = ($exportTable ? $exportTable : $_SESSION['db_name']) . "_" . date("Y-m-d_H-i-s");
        }

        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header("Content-disposition: attachment; filename=\"$filename.csv\"");
            $out = fopen('php://output', 'w');
            
            // Support JSON, SQLite, and SQL modes
            if ($dbMode === 'json' && $exportTable) {
                $data = $jsonDb->select($exportTable);
                if (!empty($data)) {
                    fputcsv($out, array_keys($data[0]));
                    foreach ($data as $row) {
                        fputcsv($out, $row);
                    }
                }
            } elseif ($exportTable) {
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
            if ($dbMode === 'json' && $exportTable) {
                $data = $jsonDb->select($exportTable);
            } elseif ($exportTable) {
                $stmt = $pdo->query("SELECT * FROM `$exportTable`");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode($data, JSON_PRETTY_PRINT);
        } elseif ($format === 'xlsx') {
            // Excel export - support all modes
            // Using simple XML format compatible with Excel
            
            $data = [];
            if ($dbMode === 'json' && $exportTable) {
                $data = $jsonDb->select($exportTable);
            } elseif ($exportTable) {
                $stmt = $pdo->query("SELECT * FROM `$exportTable`");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            if (!empty($data)) {
                header('Content-Type: application/vnd.ms-excel');
                header("Content-Disposition: attachment; filename=\"$filename.xls\"");
                
                echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
                echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
                echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
                echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
                echo '<Worksheet ss:Name="' . htmlspecialchars($exportTable ?: 'Sheet1') . '">' . "\n";
                echo '<Table>' . "\n";
                
                // Header row
                $header = array_keys($data[0]);
                echo '<Row>' . "\n";
                foreach ($header as $col) {
                    echo '<Cell><Data ss:Type="String">' . htmlspecialchars($col) . '</Data></Cell>' . "\n";
                }
                echo '</Row>' . "\n";
                
                // Data rows
                foreach ($data as $row) {
                    echo '<Row>' . "\n";
                    foreach ($row as $val) {
                        $type = is_numeric($val) ? 'Number' : 'String';
                        $value = htmlspecialchars($val ?? '');
                        echo '<Cell><Data ss:Type="' . $type . '">' . $value . '</Data></Cell>' . "\n";
                    }
                    echo '</Row>' . "\n";
                }
                
                echo '</Table>' . "\n";
                echo '</Worksheet>' . "\n";
                echo '</Workbook>';
            }
            exit;
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

            // Collect CREATE statements and referenced tables for ordering
            $creates = [];
            $refs = [];
            foreach ($tablesToExport as $t) {
                $stmt = $pdo->query("SHOW CREATE TABLE `$t`");
                $row = $stmt->fetch(PDO::FETCH_NUM);
                $creates[$t] = $row ? $row[1] : '';
                $matches = [];
                preg_match_all("/REFERENCES\s+`([^`]+)`/i", $creates[$t], $matches);
                $refs[$t] = array_values(array_unique($matches[1] ?? []));
            }

            // Topological sort
            $inDegree = [];
            $dependents = [];
            foreach ($tablesToExport as $t) $inDegree[$t] = 0;
            foreach ($tablesToExport as $t) {
                foreach ($refs[$t] ?? [] as $r) {
                    if (isset($inDegree[$r])) {
                        $inDegree[$t]++;
                        $dependents[$r][] = $t;
                    }
                }
            }
            $queue = [];
            foreach ($inDegree as $t => $deg) if ($deg === 0) $queue[] = $t;
            $ordered = [];
            while (!empty($queue)) {
                $n = array_shift($queue);
                $ordered[] = $n;
                foreach ($dependents[$n] ?? [] as $m) {
                    $inDegree[$m]--;
                    if ($inDegree[$m] === 0) $queue[] = $m;
                }
            }

            echo "-- Adminer Export: " . date("Y-m-d H:i:s") . "\n";
            echo "-- Database: " . $_SESSION['db_name'] . "\n\n";

            $useFkChecks = true;
            if (count($ordered) !== count($tablesToExport)) {
                // Couldn't resolve dependencies (cycle) — fallback to disabling FK checks
                $useFkChecks = false;
                echo "SET FOREIGN_KEY_CHECKS=0;\n\n";
            }

            // Decide sequence: ordered if possible, else original list
            $sequence = count($ordered) === count($tablesToExport) ? $ordered : $tablesToExport;

            // Emit structure and data in sequence (parents before children)
            foreach ($sequence as $t) {
                echo "-- --------------------------------------------------------\n";
                echo "-- Structure for table `$t`\n";
                echo "--\n\n";
                echo "DROP TABLE IF EXISTS `$t`;\n";
                echo ($creates[$t] ?? '') . ";\n\n";

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

            if (!$useFkChecks) {
                echo "SET FOREIGN_KEY_CHECKS=1;\n";
            }
        }
        exit;
    }
    // --- UPDATE CELL (INLINE EDIT) ---
    elseif ($action === 'update_cell') {
        header('Content-Type: application/json');
        $tableName = $_POST['table'] ?? '';
        $col = $_POST['column'] ?? '';
        $id = $_POST['id'] ?? '';
        $val = $_POST['value'] ?? '';
        
        if (!$tableName || !$col || !$id) {
            echo json_encode(['success' => false, 'message' => 'Missing parameters']);
            exit;
        }
        
        // Check if we're in JSON mode
        if (($_SESSION['db_mode'] ?? 'sql') === 'json' && !empty($_SESSION['json_file'])) {
            try {
                // For flat structure, id is the key name
                if ($col === 'value') {
                    $jsonDb->update($tableName, [$col => $val], [
                        'key' => ['operator' => '=', 'value' => $id]
                    ]);
                } else {
                    $jsonDb->update($tableName, [$col => $val], [
                        'id' => ['operator' => '=', 'value' => $id]
                    ]);
                }
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            // SQL Mode
            $pkCol = getPrimaryKey($pdo, $tableName);
            if (!$pkCol) {
                echo json_encode(['success' => false, 'message' => 'No Primary Key found']);
                exit;
            }
            
            try {
                $stmt = $pdo->prepare("UPDATE `$tableName` SET `$col` = ? WHERE `$pkCol` = ?");
                $stmt->execute([$val, $id]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        }
        exit;
    }
    // --- GET TABLE STRUCTURE ---
    elseif ($action === 'get_table_structure') {
        header('Content-Type: application/json');
        try {
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch(PDO::FETCH_NUM);
            if ($row) {
                echo json_encode(['success' => true, 'sql' => $row[1] . ";"]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Table not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    // --- GET TABLE COLUMNS (Detailed with FKs) ---
    elseif ($action === 'get_table_columns') {
        header('Content-Type: application/json');
        try {
            // 1. Get Columns
            $stmt = $pdo->query("DESCRIBE `$table`");
            $rawCols = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Get Foreign Keys
            $dbName = $_SESSION['db_name'];
            $sqlFK = "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
                      FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                      WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL";
            $stmtFK = $pdo->prepare($sqlFK);
            $stmtFK->execute([$dbName, $table]);
            
            $fks = [];
            while($row = $stmtFK->fetch(PDO::FETCH_ASSOC)) {
                $fks[$row['COLUMN_NAME']] = [
                    'table' => $row['REFERENCED_TABLE_NAME'],
                    'col' => $row['REFERENCED_COLUMN_NAME']
                ];
            }

            $columns = [];
            $referencedTablesData = [];
            $uniqueRefTables = [];

            foreach ($rawCols as $col) {
                // Exclude auto_increment columns
                if (stripos($col['Extra'], 'auto_increment') !== false) {
                    continue;
                }

                $colName = $col['Field'];
                $isNullable = $col['Null'] === 'YES';
                $fkInfo = $fks[$colName] ?? null;
                $fkData = [];

                if ($fkInfo) {
                    $refTable = $fkInfo['table'];
                    $refCol = $fkInfo['col'];
                    $uniqueRefTables[$refTable] = $refCol;
                    
                    try {
                        // Fetch distinct values for the dropdown list (legacy support for simple dropdowns)
                        $stmtVal = $pdo->query("SELECT DISTINCT `$refCol` FROM `$refTable` ORDER BY `$refCol` LIMIT 1000");
                        $fkData = $stmtVal->fetchAll(PDO::FETCH_COLUMN);
                    } catch (Exception $e) { }
                }

                $columns[] = [
                    'name' => $colName,
                    'required' => !$isNullable,
                    'type' => $col['Type'],
                    'fk' => $fkInfo,
                    'fk_values' => $fkData
                ];
            }

            // Fetch full data for unique referenced tables
            foreach ($uniqueRefTables as $refTable => $refCol) {
                try {
                    $stmtFull = $pdo->query("SELECT * FROM `$refTable` LIMIT 500");
                    $referencedTablesData[$refTable] = $stmtFull->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $referencedTablesData[$refTable] = [];
                }
            }
            
            echo json_encode([
                'success' => true, 
                'columns' => $columns,
                'referenced_tables' => $referencedTablesData
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    // --- GET ALL TABLES STRUCTURE ---
    elseif ($action === 'get_all_tables_structure') {
        header('Content-Type: application/json');
        try {
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Collect CREATE statements and referenced tables
            $creates = [];
            $refs = [];
            foreach ($tables as $t) {
                $stmt = $pdo->query("SHOW CREATE TABLE `$t`");
                $row = $stmt->fetch(PDO::FETCH_NUM);
                if ($row) {
                    $create = $row[1];
                    $creates[$t] = $create;
                    // Find referenced table names from FOREIGN KEY clauses
                    $matches = [];
                    preg_match_all("/REFERENCES\s+`([^`]+)`/i", $create, $matches);
                    $refs[$t] = array_values(array_unique($matches[1] ?? []));
                }
            }

            // Topological sort based on dependencies (referenced -> dependent)
            $inDegree = [];
            $dependents = [];
            foreach ($tables as $t) {
                $inDegree[$t] = 0;
            }
            foreach ($tables as $t) {
                foreach ($refs[$t] ?? [] as $r) {
                    if (isset($inDegree[$r])) {
                        $inDegree[$t]++;
                        $dependents[$r][] = $t;
                    }
                }
            }

            $queue = [];
            foreach ($inDegree as $t => $deg) {
                if ($deg === 0) $queue[] = $t;
            }

            $ordered = [];
            while (!empty($queue)) {
                $n = array_shift($queue);
                $ordered[] = $n;
                foreach ($dependents[$n] ?? [] as $m) {
                    $inDegree[$m]--;
                    if ($inDegree[$m] === 0) $queue[] = $m;
                }
            }

            $sql = "";
            if (count($ordered) === count($tables)) {
                // Successfully sorted, emit DROP + CREATE in order
                foreach ($ordered as $t) {
                    $sql .= "DROP TABLE IF EXISTS `$t`;\n";
                    $sql .= ($creates[$t] ?? '') . ";\n\n";
                }
            } else {
                // Cyclic or unresolved dependencies detected: fallback to safe approach
                $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
                foreach ($tables as $t) {
                    $sql .= "DROP TABLE IF EXISTS `$t`;\n";
                    $sql .= ($creates[$t] ?? '') . ";\n\n";
                }
                $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
            }

            if ($sql) {
                echo json_encode(['success' => true, 'sql' => $sql]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No tables found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    // --- DUPLICATE TABLE ---
    elseif ($action === 'duplicate_table') {
        $source = $_POST['source'] ?? '';
        $target = trim($_POST['target'] ?? '');
        $copyData = isset($_POST['copy_data']);
        
        if ($source && $target) {
            try {
                $pdo->exec("CREATE TABLE `$target` LIKE `$source`");
                if ($copyData) {
                    $pdo->exec("INSERT INTO `$target` SELECT * FROM `$source`");
                }
                redirect("?table=$target&view=structure&msg=" . urlencode("Table duplicated as $target"));
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
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
                    if (in_array($operation, ['drop', 'truncate'])) {
                        $orderedTables = order_tables_by_dependencies(
                            $pdo,
                            $cleanTables,
                            $_SESSION['db_name'] ?? '',
                            true
                        );
                    } else {
                        $orderedTables = $cleanTables;
                    }
                    $tablesStr = implode('`, `', $orderedTables);
                    if ($operation === 'drop') {
                        foreach ($orderedTables as $tbl) $pdo->exec("DROP TABLE `$tbl`");
                        redirect("?msg=" . urlencode(count($orderedTables) . " table(s) dropped."));
                    } elseif ($operation === 'truncate') {
                        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                        try {
                            foreach ($orderedTables as $tbl) {
                                $pdo->exec("TRUNCATE TABLE `$tbl`");
                            }
                        } finally {
                            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                        }
                        redirect("?msg=" . urlencode(count($orderedTables) . " table(s) truncated."));
                    } elseif (in_array($operation, ['optimize', 'analyze', 'check', 'repair'])) {
                        $sql = strtoupper($operation) . " TABLE `$tablesStr`";
                        $stmt = $pdo->query($sql);
                        $_SESSION['maintenance_data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        redirect("?view=maintenance&msg=" . urlencode(ucfirst($operation) . " complete."));
                    } elseif ($operation === 'export') {
                        $filename = "tables_" . date("Y-m-d_H-i-s") . ".sql";
                        header('Content-Type: application/octet-stream');
                        header("Content-Transfer-Encoding: Binary"); 
                        header("Content-disposition: attachment; filename=\"$filename\"");
                        echo "-- Adminer Bulk Export: " . date("Y-m-d H:i:s") . "\n";
                        echo "-- Database: " . $_SESSION['db_name'] . "\n";
                        echo "-- Tables: " . implode(', ', $orderedTables) . "\n\n";
                        foreach ($orderedTables as $t) {
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
    // --- BULK ROW OPERATIONS (DELETE & EXPORT) ---
    elseif (in_array($action, ['bulk_delete', 'export_sql', 'export_csv', 'export_json'])) {
        $ids = $_POST['ids'] ?? [];
        $pk = $_POST['pk'] ?? null;
        
        if ($table && $pk && !empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            if ($action === 'bulk_delete') {
                try {
                    $sql = "DELETE FROM `$table` WHERE `$pk` IN ($placeholders)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($ids);
                    $count = $stmt->rowCount();
                    redirect("?table=$table&view=data&msg=" . urlencode("$count rows deleted."));
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            } else {
                // EXPORT SELECTED
                $sql = "SELECT * FROM `$table` WHERE `$pk` IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($ids);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $filename = $table . "_selected_" . date("Y-m-d_H-i-s");

                if ($action === 'export_json') {
                    header('Content-Type: application/json');
                    header("Content-disposition: attachment; filename=\"$filename.json\"");
                    echo json_encode($rows, JSON_PRETTY_PRINT);
                } elseif ($action === 'export_csv') {
                    header('Content-Type: text/csv');
                    header("Content-disposition: attachment; filename=\"$filename.csv\"");
                    $out = fopen('php://output', 'w');
                    if ($rows) {
                        fputcsv($out, array_keys($rows[0]));
                        foreach ($rows as $r) fputcsv($out, $r);
                    }
                    fclose($out);
                } elseif ($action === 'export_sql') {
                    header('Content-Type: application/octet-stream');
                    header("Content-disposition: attachment; filename=\"$filename.sql\"");
                    echo "-- Export Selected Rows from `$table`\n";
                    foreach ($rows as $r) {
                        $keys = array_keys($r);
                        $vals = array_map(function($v) use ($pdo) {
                            return $v === null ? "NULL" : $pdo->quote($v);
                        }, array_values($r));
                        echo "INSERT INTO `$table` (`" . implode('`, `', $keys) . "`) VALUES (" . implode(', ', $vals) . ");\n";
                    }
                }
                exit;
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
    // --- CREATE TABLE ---
    elseif ($action === 'create_table') {
        $name = trim($_POST['name'] ?? '');
        $engine = $_POST['engine'] ?? 'InnoDB';
        $collation = $_POST['collation'] ?? 'utf8mb4_general_ci';
        $cols = $_POST['fields'] ?? [];
        $comments = $_POST['comments'] ?? '';

        if (!$name || empty($cols)) {
            $error = "Table name and at least one column are required.";
        } else {
            $defs = [];
            $primary = [];
            
            foreach ($cols as $c) {
                $cName = trim($c['name']);
                if (!$cName) continue;
                
                $cType = $c['type'];
                $cLen = trim($c['length']);
                $cDefault = $c['default'];
                $cDefaultVal = $c['default_val'] ?? '';
                $cNull = isset($c['null']) ? 'NULL' : 'NOT NULL';
                $cAi = isset($c['ai']) ? 'AUTO_INCREMENT' : '';
                $cIndex = $c['index'] ?? ''; 
                
                $def = "`$cName` $cType";
                if ($cLen !== '') $def .= "($cLen)";
                
                $def .= " $cNull";
                
                if ($cDefault === 'NULL') {
                    $def .= " DEFAULT NULL";
                } elseif ($cDefault === 'CURRENT_TIMESTAMP') {
                    $def .= " DEFAULT CURRENT_TIMESTAMP";
                } elseif ($cDefault === 'USER_DEFINED') {
                    $def .= " DEFAULT " . $pdo->quote($cDefaultVal);
                }
                
                if ($cAi) $def .= " AUTO_INCREMENT";
                
                if ($cIndex === 'PRI') {
                    $primary[] = "`$cName`";
                } elseif ($cIndex === 'UNI') {
                    $def .= " UNIQUE";
                }
                
                $defs[] = $def;
                
                if ($cIndex === 'IDX') {
                    $defs[] = "KEY `idx_$cName` (`$cName`)";
                }
            }
            
            if ($primary) {
                $defs[] = "PRIMARY KEY (" . implode(', ', $primary) . ")";
            }
            
            $sql = "CREATE TABLE `$name` (\n" . implode(",\n", $defs) . "\n) ENGINE=$engine DEFAULT COLLATE=$collation";
            if ($comments) $sql .= " COMMENT=" . $pdo->quote($comments);
            
            try {
                $pdo->exec($sql);
                redirect("?table=" . urlencode($name) . "&view=structure&msg=" . urlencode("Table $name created."));
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
    // --- IMPORT EXCEL ---
    elseif ($action === 'import_excel') {
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true);

        $tableName = $input['table'] ?? '';
        $importType = $input['importType'] ?? 'insert';
        $primaryKeyCol = $input['primaryKeyCol'] ?? '';
        $truncateTable = $input['truncateTable'] ?? false;
        $headers = $input['headers'] ?? [];
        $data = $input['data'] ?? [];

        if (!$tableName || empty($headers) || empty($data)) {
            echo json_encode(['success' => false, 'message' => 'Missing table name, headers, or data.']);
            exit;
        }

        // Validate table name (simple alphanumeric and underscore check)
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            echo json_encode(['success' => false, 'message' => 'Invalid table name.']);
            exit;
        }

        if (($importType === 'update' || $importType === 'upsert') && !$primaryKeyCol) {
            echo json_encode(['success' => false, 'message' => 'Primary Key Column is required for Update/Upsert import types.']);
            exit;
        }
        
        $insertedRows = 0;
        $updatedRows = 0;

        try {
            if ($truncateTable) {
                // Disable FK checks to allow truncate
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $pdo->exec("TRUNCATE TABLE `$tableName`");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            }

            $pdo->beginTransaction();

            // Fetch actual column names and types from the database
            $stmt = $pdo->query("DESCRIBE `$tableName`");
            $dbColumnsInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $dbColumns = array_column($dbColumnsInfo, 'Field');
            $dbColumnTypes = [];
            foreach ($dbColumnsInfo as $colInfo) {
                $dbColumnTypes[$colInfo['Field']] = strtolower((string)$colInfo['Type']);
            }
            
            // Map Excel headers to database columns
            $mappedHeaders = [];
            foreach ($headers as $excelCol) {
                // Find a case-insensitive match in dbColumns
                $found = false;
                // Strip special characters like '*' often used in templates for required fields
                $cleanExcel = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$excelCol));
                
                foreach ($dbColumns as $dbCol) {
                    $cleanDb = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$dbCol));
                    if ($cleanExcel === $cleanDb || strcasecmp(trim((string)$excelCol), trim((string)$dbCol)) === 0) {
                        $mappedHeaders[] = $dbCol; // Use the actual DB column name
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $mappedHeaders[] = null; // Mark as unmappable
                }
            }

            // Filter out unmappable columns from data rows
            $filteredData = [];
            foreach ($data as $row) {
                $newRow = [];
                foreach ($mappedHeaders as $index => $dbCol) {
                    if ($dbCol !== null) {
                        $val = $row[$index] ?? null;

                        // Handle Excel Serial Date conversion
                        // Excel serials are numbers, usually between 10000 and 60000 for current ranges
                        if ($val !== null && is_numeric($val) && $val > 0) {
                            $type = $dbColumnTypes[$dbCol] ?? '';
                            if (strpos($type, 'date') !== false || strpos($type, 'timestamp') !== false) {
                                // Simple check: if it looks like a serial number (e.g. 39617)
                                // and the destination is a date/time column
                                if (preg_match('/^\d+(\.\d+)?$/', (string)$val)) {
                                    try {
                                        $fVal = (float)$val;
                                        $base = new DateTime('1899-12-30');
                                        $days = (int)$fVal;
                                        $seconds = round(($fVal - $days) * 86400);
                                        $base->modify("+$days days");
                                        if ($seconds > 0) {
                                            $base->modify("+$seconds seconds");
                                            $val = $base->format(strpos($type, 'datetime') !== false ? 'Y-m-d H:i:s' : 'Y-m-d');
                                        } else {
                                            $val = $base->format('Y-m-d');
                                        }
                                    } catch (Exception $e) {
                                        // Fallback to original value if conversion fails
                                    }
                                }
                            }
                        }

                        $newRow[$dbCol] = $val;
                    }
                }
                if (!empty($newRow)) {
                    $filteredData[] = $newRow;
                }
            }

            if (empty($filteredData)) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'No mappable data rows found after header matching.']);
                exit;
            }

            $validMappedHeaders = array_values(array_filter($mappedHeaders));
            $placeholders = implode(', ', array_fill(0, count($validMappedHeaders), '?'));
            $cols = implode('`, `', $validMappedHeaders);

            if ($importType === 'insert') {
                $sql = "INSERT INTO `$tableName` (`$cols`) VALUES ($placeholders)";
                $stmt = $pdo->prepare($sql);
            } elseif ($importType === 'update') {
                // This assumes primaryKeyCol is in headers and dbColumns
                if (!in_array($primaryKeyCol, $validMappedHeaders)) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => "Primary Key Column '$primaryKeyCol' not found in Excel headers or database table."]);
                    exit;
                }
                $setParts = [];
                foreach ($validMappedHeaders as $col) {
                    if ($col !== $primaryKeyCol) {
                        $setParts[] = "`$col` = ?";
                    }
                }
                if (empty($setParts)) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'No non-primary key columns found for update.']);
                    exit;
                }
                $sql = "UPDATE `$tableName` SET " . implode(', ', $setParts) . " WHERE `$primaryKeyCol` = ?";
                $stmt = $pdo->prepare($sql);
            } elseif ($importType === 'upsert') {
                $updateParts = [];
                foreach ($validMappedHeaders as $col) {
                    $updateParts[] = "`$col` = VALUES(`$col`)";
                }
                $sql = "INSERT INTO `$tableName` (`$cols`) VALUES ($placeholders) ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);
                $stmt = $pdo->prepare($sql);
            }

            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            foreach ($filteredData as $row) {
                // Skip rows that are entirely empty
                $hasContent = false;
                foreach ($row as $val) {
                    if ($val !== null && trim((string)$val) !== '') {
                        $hasContent = true;
                        break;
                    }
                }
                if (!$hasContent) continue;

                // Skip rows where username is empty (to avoid Duplicate Entry error for '')
                // We check the 'username' key (normalized mapping)
                $usernameVal = null;
                foreach ($row as $k => $v) {
                    $cleanKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$k));
                    if ($cleanKey === 'username') {
                        $usernameVal = $v;
                        break;
                    }
                }
                
                // If username is found but empty, skip this row
                if ($usernameVal !== null && trim((string)$usernameVal) === '') {
                    continue;
                }
                
                $values = array_values($row);
                
                if ($importType === 'update') {
                    // For update, primary key value should be last
                    $pkVal = $row[$primaryKeyCol];
                    unset($row[$primaryKeyCol]); // Remove PK from values to be set
                    $values = array_values($row);
                    $values[] = $pkVal;
                }
                
                $stmt->execute($values);
                if ($importType === 'insert') {
                    $insertedRows++;
                } elseif ($importType === 'update') {
                    $updatedRows += $stmt->rowCount(); // rowCount() is 0 if no change, 1 if updated
                } elseif ($importType === 'upsert') {
                    // For upsert, rowCount is 1 for insert, 2 for update (affecting 2 rows: old and new)
                    $affected = $stmt->rowCount();
                    if ($affected === 1) $insertedRows++;
                    elseif ($affected === 2) $updatedRows++;
                }
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Import completed successfully.',
                'insertedRows' => $insertedRows,
                'updatedRows' => $updatedRows
            ]);

        } catch (Exception $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Excel Import Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// ===== GET HANDLERS =====
if ($is_logged_in) {
    if (isset($_GET['action']) && $_GET['action'] === 'delete_row') {
        $table = $_GET['table'];
        $pk = $_GET['pk'];
        $val = $_GET['val'];
        try {
            // Check if we're in JSON mode
            if (($_SESSION['db_mode'] ?? 'sql') === 'json' && !empty($_SESSION['json_file'])) {
                $jsonDb->delete($table, [
                    'id' => ['operator' => '=', 'value' => $val]
                ]);
            } else {
                // SQL Mode
                $stmt = $pdo->prepare("DELETE FROM `$table` WHERE `$pk` = ?");
                $stmt->execute([$val]);
            }
            redirect("?table=$table&view=data&msg=" . urlencode("Row deleted."));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);
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

// Check if we're in JSON mode
if ($is_logged_in && ($_SESSION['db_mode'] ?? 'sql') === 'json' && !empty($_SESSION['json_file'])) {
    // JSON Mode - Get tables from JSON file
    try {
        $jsonTables = $jsonDb->listTables();
        foreach ($jsonTables as $tableName) {
            $tableData = $jsonDb->select($tableName);
            $rowCount = count($tableData);
            $dataSize = strlen(json_encode($tableData));
            
            $tables[] = [
                'Name' => $tableName,
                'Rows' => $rowCount,
                'Data_length' => $dataSize,
                'Index_length' => 0,
                'Collation' => 'JSON'
            ];
            
            $totalRows += $rowCount;
            $totalSize += $dataSize;
        }
    } catch (Exception $e) {
        error_log("JsonDatabase error: " . $e->getMessage());
        $tables = [];
    }
} elseif ($is_logged_in && ($_SESSION['db_mode'] ?? 'sql') === 'sqlite' && !empty($_SESSION['sqlite_file'])) {
    // SQLite Mode - Get tables from SQLite database
    try {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tableName = $row['name'];
            
            // Get row count
            $countStmt = $pdo->query("SELECT COUNT(*) as count FROM `$tableName`");
            $rowCount = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            $tables[] = [
                'Name' => $tableName,
                'Rows' => $rowCount,
                'Data_length' => 0,
                'Index_length' => 0,
                'Collation' => 'SQLite'
            ];
            
            $totalRows += $rowCount;
        }
    } catch (Exception $e) {
        error_log("SQLite error: " . $e->getMessage());
        $tables = [];
    }
} elseif ($is_logged_in && isset($pdo) && $pdo !== null && $hasSelectedDatabase) {
    // SQL Mode - Get tables from MySQL database
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
                "  BackgroundColor color: var(--text-primary)",
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
                "  BackgroundColor color: var(--text-primary)",
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

// Primary key detection based on database mode
$primaryKey = null;
if ($currentTable) {
    if (($_SESSION['db_mode'] ?? 'sql') === 'json' && !empty($_SESSION['json_file'])) {
        $primaryKey = $jsonDb->getPrimaryKey($currentTable);
    } else {
        $primaryKey = getPrimaryKey($pdo, $currentTable);
    }
}

// Search Params
$searchColumn = $_GET['search_col'] ?? '';
$searchOp = $_GET['search_op'] ?? 'LIKE';
$searchVal = $_GET['search_val'] ?? '';

// Sort Params
$orderBy = $_GET['order_by'] ?? null;
$orderDir = $_GET['order_dir'] ?? 'ASC';

if ($is_logged_in && $currentTable) {
    // Check if we're in JSON mode
    if (($_SESSION['db_mode'] ?? 'sql') === 'json' && !empty($_SESSION['json_file'])) {
        // JSON Mode
        try {
            $tableStructure = $jsonDb->getTableStructure($currentTable);
            $tableColumns = array_column($tableStructure, 'Field');
            
            if ($view === 'data') {
                // Build conditions for JSON database
                $conditions = [];
                
                if ($searchVal !== '') {
                    $op = '=';
                    $val = $searchVal;
                    
                    if ($searchOp === 'LIKE') {
                        $op = 'LIKE';
                    } elseif (in_array($searchOp, ['=', '!=', '>', '<', '>=', '<='])) {
                        $op = $searchOp;
                    }
                    
                    if ($searchColumn && in_array($searchColumn, $tableColumns)) {
                        $conditions[$searchColumn] = ['operator' => $op, 'value' => $val];
                    }
                    // Note: Global search across all columns is not supported in JSON mode yet
                }
                
                // Fetch data from JSON database
                $orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
                $tableData = $jsonDb->select($currentTable, $conditions, $orderBy, $orderDir, $limit, $offset);
            }
        } catch (Exception $e) {
            error_log("JsonDatabase error: " . $e->getMessage());
            $tableStructure = [];
            $tableColumns = [];
            $tableData = [];
        }
    } elseif (($_SESSION['db_mode'] ?? 'sql') === 'sqlite' && isset($pdo)) {
        // SQLite Mode
        $stmt = $pdo->query("PRAGMA table_info(`$currentTable`)");
        $pragmaResult = $stmt->fetchAll();
        
        // Convert SQLite PRAGMA result to MySQL DESCRIBE format
        $tableStructure = [];
        foreach ($pragmaResult as $col) {
            $tableStructure[] = [
                'Field' => $col['name'],
                'Type' => $col['type'],
                'Null' => $col['notnull'] ? 'NO' : 'YES',
                'Key' => $col['pk'] ? 'PRI' : '',
                'Default' => $col['dflt_value'],
                'Extra' => ''
            ];
        }
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
                }
            }
            
            if ($orderBy && in_array($orderBy, $tableColumns)) {
                $sql .= " ORDER BY `$orderBy` " . ($orderDir === 'DESC' ? 'DESC' : 'ASC');
            }
            
            $sql .= " LIMIT $limit OFFSET $offset";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $tableData = $stmt->fetchAll();
        }
    } elseif (($_SESSION['db_mode'] ?? 'sql') === 'sql' && isset($pdo)) {
        // SQL Mode (MySQL/MariaDB)
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
}

$colTypesMap = [];
if (!empty($tableStructure)) {
    foreach ($tableStructure as $cs) {
        $colTypesMap[$cs['Field']] = strtolower($cs['Type']);
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
    <script src="<?= get_asset_url('assets/vendor/sweetalert2/sweetalert2.all.min.js', 'https://cdn.jsdelivr.net/npm/sweetalert2@11') ?>"></script>
    <script type="text/javascript" src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
    <script src="<?= get_asset_url('assets/vendor/mermaid/mermaid.min.js', 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js') ?>"></script>
    <script src="<?= get_asset_url('assets/vendor/tom-select/tom-select.complete.min.js', 'https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js') ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        /**
 * Advanced Filters Component
 * Multiple column filters with searchable dropdowns
 */

class AdvancedFilters {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.table = options.table || '';
        this.columns = options.columns || [];
        this.columnTypes = options.columnTypes || {};
        this.filters = [];
        this.tomSelectInstances = [];
        
        this.init();
    }
    
    init() {
        this.render();
    }
    
    render() {
        this.container.innerHTML = `
            <div class="advanced-filters">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h4 style="margin:0;"><i class="fas fa-filter"></i> Advanced Filters</h4>
                    <div style="display:flex; gap:10px;">
                        <button type="button" class="btn btn-sm" onclick="advancedFilters.addFilter()">
                            <i class="fas fa-plus"></i> Add Filter
                        </button>
                        <button type="button" class="btn btn-sm" onclick="advancedFilters.clearAll()">
                            <i class="fas fa-times"></i> Clear All
                        </button>
                    </div>
                </div>
                <div id="af-filters-container"></div>
                <div style="margin-top:15px; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" class="btn btn-primary" onclick="advancedFilters.applyFilters()">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <button type="button" class="btn" onclick="advancedFilters.exportFilters()">
                        <i class="fas fa-download"></i> Export Filtered Data
                    </button>
                </div>
            </div>
        `;
        
        // Add initial filter
        if (this.filters.length === 0) {
            this.addFilter();
        }
    }
    
    addFilter() {
        const container = document.getElementById('af-filters-container');
        const index = this.filters.length;
        
        const filterHtml = `
            <div class="af-filter" data-index="${index}" style="display:flex; gap:10px; margin-bottom:12px; align-items:center; padding:12px; background:var(--bg-hover); border-radius:6px; flex-wrap:wrap;">
                ${index > 0 ? `
                    <select class="af-logic form-select" style="width:80px; background:var(--bg-input);">
                        <option value="AND">AND</option>
                        <option value="OR">OR</option>
                    </select>
                ` : '<div style="width:80px;"></div>'}
                
                <select class="af-column form-select" style="flex:1; min-width:150px; background:var(--bg-input);">
                    <option value="">-- Select Column --</option>
                    ${this.columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                </select>
                
                <select class="af-operator form-select" style="width:140px; background:var(--bg-input);">
                    <option value="=">=</option>
                    <option value="!=">!=</option>
                    <option value=">">></option>
                    <option value="<"><</option>
                    <option value=">=">>=</option>
                    <option value="<="><=</option>
                    <option value="LIKE">Contains (LIKE)</option>
                    <option value="NOT LIKE">Not Contains</option>
                    <option value="STARTS">Starts With</option>
                    <option value="ENDS">Ends With</option>
                    <option value="IN">In List</option>
                    <option value="NOT IN">Not In List</option>
                    <option value="IS NULL">Is Empty</option>
                    <option value="IS NOT NULL">Is Not Empty</option>
                    <option value="BETWEEN">Between</option>
                </select>
                
                <div class="af-value-container" style="flex:1; min-width:200px; display:flex; gap:8px;">
                    <input type="text" class="af-value form-control" placeholder="Value" style="flex:1; background:var(--bg-input);">
                </div>
                
                <button type="button" class="btn btn-danger btn-sm" onclick="advancedFilters.removeFilter(${index})" title="Remove Filter">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', filterHtml);
        
        const filterDiv = container.lastElementChild;
        
        // Initialize TomSelect for column dropdown
        const columnSelect = filterDiv.querySelector('.af-column');
        if (columnSelect && typeof TomSelect !== 'undefined') {
            const ts = new TomSelect(columnSelect, {
                plugins: ['clear_button'],
                placeholder: 'Select Column',
                maxOptions: 100,
                sortField: { field: "text", direction: "asc" }
            });
            this.tomSelectInstances.push(ts);
            
            // Handle column change
            ts.on('change', (value) => {
                this.updateValueInput(filterDiv, filterDiv.querySelector('.af-operator').value);
            });
        }
        
        // Handle operator change
        const operatorSelect = filterDiv.querySelector('.af-operator');
        operatorSelect.addEventListener('change', (e) => {
            this.updateValueInput(filterDiv, e.target.value);
        });
        
        this.filters.push({ index });
    }
    
    updateValueInput(filterDiv, operator) {
        const valueContainer = filterDiv.querySelector('.af-value-container');
        const column = filterDiv.querySelector('.af-column')?.value;
        const type = this.columnTypes[column] || '';
        
        if (operator === 'IS NULL' || operator === 'IS NOT NULL') {
            // No value needed
            valueContainer.innerHTML = '<span style="color:var(--text-secondary); font-style:italic;">No value required</span>';
        } else if (operator === 'BETWEEN') {
            // Two inputs for range
            valueContainer.innerHTML = `
                <input type="text" class="af-value form-control" placeholder="From" style="flex:1; background:var(--bg-input);">
                <span style="color:var(--text-secondary);">to</span>
                <input type="text" class="af-value-2 form-control" placeholder="To" style="flex:1; background:var(--bg-input);">
            `;
            if (type.includes('date') || type.includes('timestamp')) {
                this.initFlatpickrOn(valueContainer.querySelector('.af-value'), type);
                this.initFlatpickrOn(valueContainer.querySelector('.af-value-2'), type);
            }
        } else if (operator === 'IN' || operator === 'NOT IN') {
            // Textarea for list
            valueContainer.innerHTML = `
                <textarea class="af-value form-control" placeholder="Enter values separated by comma&#10;Example: value1, value2, value3" rows="2" style="flex:1; background:var(--bg-input); resize:vertical;"></textarea>
            `;
        } else {
            // Single input
            valueContainer.innerHTML = `
                <input type="text" class="af-value form-control" placeholder="Value" style="flex:1; background:var(--bg-input);">
            `;
            if (type.includes('date') || type.includes('timestamp')) {
                this.initFlatpickrOn(valueContainer.querySelector('.af-value'), type);
            }
        }
    }
    
    initFlatpickrOn(el, type) {
        if (!el) return;
        flatpickr(el, {
            theme: 'dark',
            enableTime: type.includes('datetime') || type.includes('timestamp'),
            enableSeconds: type.includes('datetime') || type.includes('timestamp'),
            dateFormat: type.includes('datetime') || type.includes('timestamp') ? "Y-m-d H:i:S" : "Y-m-d",
            allowInput: true
        });
    }
    
    removeFilter(index) {
        const filter = document.querySelector(`.af-filter[data-index="${index}"]`);
        if (filter) {
            filter.remove();
            this.filters = this.filters.filter(f => f.index !== index);
            
            // If no filters left, add one
            if (this.filters.length === 0) {
                this.addFilter();
            }
        }
    }
    
    clearAll() {
        document.getElementById('af-filters-container').innerHTML = '';
        this.filters = [];
        this.tomSelectInstances.forEach(ts => ts.destroy());
        this.tomSelectInstances = [];
        this.addFilter();
    }
    
    getFilters() {
        const filters = [];
        
        document.querySelectorAll('.af-filter').forEach((filterDiv, idx) => {
            const logic = filterDiv.querySelector('.af-logic')?.value || 'AND';
            const column = filterDiv.querySelector('.af-column')?.value;
            const operator = filterDiv.querySelector('.af-operator')?.value;
            const value = filterDiv.querySelector('.af-value')?.value;
            const value2 = filterDiv.querySelector('.af-value-2')?.value;
            
            if (column && operator) {
                const filter = {
                    logic: idx > 0 ? logic : null,
                    column,
                    operator,
                    value
                };
                
                if (operator === 'BETWEEN' && value2) {
                    filter.value2 = value2;
                }
                
                filters.push(filter);
            }
        });
        
        return filters;
    }
    
    buildWhereClause() {
        const filters = this.getFilters();
        if (filters.length === 0) return '';
        
        const conditions = filters.map((filter, idx) => {
            let condition = idx > 0 ? `${filter.logic} ` : '';
            condition += `\`${filter.column}\``;
            
            switch (filter.operator) {
                case 'IS NULL':
                case 'IS NOT NULL':
                    condition += ` ${filter.operator}`;
                    break;
                    
                case 'LIKE':
                    condition += ` LIKE '%${this.escapeValue(filter.value)}%'`;
                    break;
                    
                case 'NOT LIKE':
                    condition += ` NOT LIKE '%${this.escapeValue(filter.value)}%'`;
                    break;
                    
                case 'STARTS':
                    condition += ` LIKE '${this.escapeValue(filter.value)}%'`;
                    break;
                    
                case 'ENDS':
                    condition += ` LIKE '%${this.escapeValue(filter.value)}'`;
                    break;
                    
                case 'IN':
                case 'NOT IN':
                    const values = filter.value.split(',').map(v => `'${this.escapeValue(v.trim())}'`).join(', ');
                    condition += ` ${filter.operator} (${values})`;
                    break;
                    
                case 'BETWEEN':
                    condition += ` BETWEEN '${this.escapeValue(filter.value)}' AND '${this.escapeValue(filter.value2)}'`;
                    break;
                    
                default:
                    const isNumeric = !isNaN(filter.value) && filter.value !== '';
                    condition += ` ${filter.operator} ${isNumeric ? filter.value : `'${this.escapeValue(filter.value)}'`}`;
            }
            
            return condition;
        });
        
        return conditions.join(' ');
    }
    
    escapeValue(value) {
        return String(value).replace(/'/g, "''");
    }
    
    applyFilters() {
        const whereClause = this.buildWhereClause();
        
        if (!whereClause) {
            Swal.fire('No Filters', 'Please add at least one filter condition', 'info');
            return;
        }
        
        // Build full query
        const query = `SELECT * FROM \`${this.table}\` WHERE ${whereClause};`;
        
        // Show preview
        Swal.fire({
            title: 'Apply Filters?',
            html: `<div style="text-align:left;">
                <p style="margin-bottom:10px;">Generated WHERE clause:</p>
                <pre style="background:#080808; color:#a5d6ff; padding:12px; border-radius:6px; overflow-x:auto; font-size:13px;">${whereClause}</pre>
            </div>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Apply',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Submit query
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="sql_query">
                    <input type="hidden" name="table" value="${this.table}">
                    <textarea name="query" style="display:none;">${query}</textarea>
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    
    exportFilters() {
        const whereClause = this.buildWhereClause();
        
        if (!whereClause) {
            Swal.fire('No Filters', 'Please add at least one filter condition', 'info');
            return;
        }
        
        Swal.fire({
            title: 'Export Filtered Data',
            html: `
                <div style="text-align:left; margin-bottom:15px;">
                    <p style="margin-bottom:10px;">Select export format:</p>
                    <select id="export-format" class="form-select">
                        <option value="csv">CSV</option>
                        <option value="json">JSON</option>
                        <option value="sql">SQL</option>
                    </select>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Export',
            preConfirm: () => {
                return document.getElementById('export-format').value;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const format = result.value;
                const query = `SELECT * FROM \`${this.table}\` WHERE ${whereClause}`;
                
                // Create form to export
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="export_filtered">
                    <input type="hidden" name="table" value="${this.table}">
                    <input type="hidden" name="format" value="${format}">
                    <input type="hidden" name="where_clause" value="${this.escapeValue(whereClause)}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    
    saveFilters() {
        const filters = this.getFilters();
        const json = JSON.stringify(filters, null, 2);
        
        // Download as JSON
        const blob = new Blob([json], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${this.table}_filters_${Date.now()}.json`;
        a.click();
        URL.revokeObjectURL(url);
    }
    
    loadFilters(filtersData) {
        this.clearAll();
        
        filtersData.forEach((filter, idx) => {
            if (idx > 0) this.addFilter();
            
            const filterDiv = document.querySelectorAll('.af-filter')[idx];
            if (filterDiv) {
                if (filter.logic) {
                    const logicSelect = filterDiv.querySelector('.af-logic');
                    if (logicSelect) logicSelect.value = filter.logic;
                }
                
                filterDiv.querySelector('.af-column').value = filter.column;
                filterDiv.querySelector('.af-operator').value = filter.operator;
                
                this.updateValueInput(filterDiv, filter.operator);
                
                const valueInput = filterDiv.querySelector('.af-value');
                if (valueInput) valueInput.value = filter.value;
                
                if (filter.value2) {
                    const value2Input = filterDiv.querySelector('.af-value-2');
                    if (value2Input) value2Input.value = filter.value2;
                }
            }
        });
    }
}

// Global instance
let advancedFilters = null;

    </script>
    <script>
        // Functional stub: fetches all table structures and copies to clipboard immediately.
        if (typeof copyAllTablesStructure === 'undefined') {
            function copyAllTablesStructure() {
                const formData = new FormData();
                formData.append('action', 'get_all_tables_structure');

                fetch('?', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.success && data.sql) {
                            const text = data.sql;
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(text).then(() => {
                                    Swal.fire({ toast:true, position:'top-end', showConfirmButton:false, timer:1500, icon:'success', title:'All table structures copied' });
                                }).catch(err => {
                                    console.error(err);
                                    Swal.fire('Error', 'Could not copy to clipboard', 'error');
                                });
                            } else {
                                // Fallback: create temporary textarea
                                const ta = document.createElement('textarea');
                                ta.value = text;
                                document.body.appendChild(ta);
                                ta.select();
                                try { document.execCommand('copy'); Swal.fire({ toast:true, position:'top-end', showConfirmButton:false, timer:1500, icon:'success', title:'All table structures copied' }); }
                                catch(e){ console.error(e); Swal.fire('Error', 'Could not copy to clipboard', 'error'); }
                                ta.remove();
                            }
                        } else {
                            Swal.fire('Error', data && data.message ? data.message : 'Failed to fetch table structures', 'error');
                        }
                    }).catch(err => { console.error(err); Swal.fire('Error', 'Network error', 'error'); });
            }
        }
    </script>
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

    // --- THEME TOGGLE ---
    function toggleTheme() {
        const root = document.documentElement;
        const current = root.getAttribute('data-theme');
        const next = current === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        localStorage.setItem('adminer_theme', next);
        updateThemeColors(next);
    }

    function updateThemeColors(theme) {
        const root = document.documentElement;
        if (theme === 'light') {
            root.style.setProperty('--bg-body', '#f5f5f5');
            root.style.setProperty('--bg-sidebar', '#ffffff');
            root.style.setProperty('--bg-card', '#ffffff');
            root.style.setProperty('--bg-hover', '#f0f0f0');
            root.style.setProperty('--bg-input', '#ffffff');
            root.style.setProperty('--border-color', '#dddddd');
            root.style.setProperty('--text-primary', '#333333');
            root.style.setProperty('--text-secondary', '#666666');
            root.style.setProperty('--text-primary', '#333333');
            root.style.setProperty('--dark-gray', '#ccc');
        } else {
            // Revert to dark defaults
            root.style.setProperty('--bg-body', '#050505');
            root.style.setProperty('--bg-sidebar', '#0f0f0f');
            root.style.setProperty('--bg-card', '#141414');
            root.style.setProperty('--bg-hover', '#1f1f1f');
            root.style.setProperty('--bg-input', '#1a1a1a');
            root.style.setProperty('--border-color', '#333333');
            root.style.setProperty('--text-primary', '#e0e0e0');
            root.style.setProperty('--text-secondary', '#888888');
            root.style.setProperty('--dark-gray', '#222');
        }
    }
    
    // Init Theme
    const savedTheme = localStorage.getItem('adminer_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    updateThemeColors(savedTheme);

    // --- BULK ACTIONS (DATA VIEW) ---
    function updateBulkBtn() {
        const checked = document.querySelectorAll('.row-checkbox:checked').length > 0;
        const select = document.getElementById('bulkActionSelect');
        const btn = document.getElementById('bulkApplyBtn');
        if(select && btn) {
            select.style.display = checked ? 'block' : 'none';
            btn.style.display = checked ? 'block' : 'none';
        }
    }

    function toggleSelectAll(source) {
        document.querySelectorAll('.row-checkbox').forEach(cb => {
            cb.checked = source.checked;
        });
        updateBulkBtn();
    }

    function submitBulkAction() {
        const select = document.getElementById('bulkActionSelect');
        const form = document.getElementById('bulkForm');
        const action = select.value;
        
        if (!action) return;
        
        if (action === 'delete') {
            form.querySelector('input[name="action"]').value = 'bulk_delete';
            Swal.fire({
                title: 'Delete selected rows?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Yes, delete!'
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
        } else {
            // Export actions
            form.querySelector('input[name="action"]').value = action;
            form.submit(); // Direct submit for download
        }
    }

    // --- SIDEBAR TOGGLE & PERSISTENCE ---
    document.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        const sidebarToggle = document.getElementById('sidebarToggle');
        
        if (sidebar && mainContent && sidebarToggle) {
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
        }
    
        // --- SIDEBAR PINNING ---
        const PIN_STORAGE_KEY = 'adminer_pinned_tables';
        window.togglePin = function(tableName) {
            let pinned = JSON.parse(localStorage.getItem(PIN_STORAGE_KEY) || '[]');
            if (pinned.includes(tableName)) {
                pinned = pinned.filter(t => t !== tableName);
            } else {
                pinned.push(tableName);
            }
            localStorage.setItem(PIN_STORAGE_KEY, JSON.stringify(pinned));
            renderPinnedTables();
        };

        function renderPinnedTables() {
            const pinnedSection = document.getElementById('pinned-tables-section');
            const pinnedList = document.getElementById('pinned-tables-list');
            const pinned = JSON.parse(localStorage.getItem(PIN_STORAGE_KEY) || '[]');
            
            pinnedList.innerHTML = '';
            pinnedSection.style.display = pinned.length > 0 ? 'block' : 'none';

            // Reset visibility in main list
            document.querySelectorAll('.nav-item-wrapper').forEach(wrapper => {
                wrapper.classList.remove('pinned');
                const btn = wrapper.querySelector('.pin-btn');
                const table = wrapper.getAttribute('data-table');
                if (pinned.includes(table)) {
                    wrapper.classList.add('pinned');
                    btn.classList.add('active');
                    
                    // Clone to pinned list
                    const clone = wrapper.cloneNode(true);
                    clone.classList.remove('pinned');
                    clone.querySelector('.pin-btn').classList.add('active');
                    pinnedList.appendChild(clone);
                } else {
                    btn.classList.remove('active');
                }
            });
        }
        renderPinnedTables();

        // --- TABLE SEARCH (Sidebar) ---
        const tableSearchInput = document.getElementById('tableSearch');
        if (tableSearchInput) {
            tableSearchInput.addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                // Search in both main list and pinned list
                document.querySelectorAll('.nav-item-wrapper').forEach(wrapper => {
                    const tableName = wrapper.getAttribute('data-table').toLowerCase();
                    wrapper.style.display = tableName.includes(searchTerm) ? 'flex' : 'none';
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
    });

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

    // Bulk table selection (init after DOM ready)
    function updateSelectAllState() {
        const selectAll = document.getElementById('selectAllTables');
        if (!selectAll) return;
        const cbs = Array.from(document.querySelectorAll('.table-checkbox'));
        const checkedCount = cbs.filter(cb => cb.checked).length;
        if (checkedCount === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        } else if (checkedCount === cbs.length) {
            selectAll.checked = true;
            selectAll.indeterminate = false;
        } else {
            selectAll.checked = false;
            selectAll.indeterminate = true;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const selectAllTables = document.getElementById('selectAllTables');
        if (selectAllTables) {
            selectAllTables.addEventListener('change', function() {
                document.querySelectorAll('.table-checkbox').forEach(cb => cb.checked = selectAllTables.checked);
            });
        }
        // Ensure individual checkboxes update the master checkbox state
        document.querySelectorAll('.table-checkbox').forEach(cb => cb.addEventListener('change', updateSelectAllState));
        // initialize state
        updateSelectAllState();
    });

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

    // --- CREATE TABLE HELPER ---
    let colIndex = 0;
    function addColRow() {
        const tbody = document.getElementById('colList');
        if (!tbody) return;
        const index = colIndex++;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" name="fields[${index}][name]" class="form-control" required placeholder="Column Name"></td>
            <td>
                <select name="fields[${index}][type]" class="form-select" style="background:var(--bg-input);">
                    <option value="INT">INT</option>
                    <option value="VARCHAR">VARCHAR</option>
                    <option value="TEXT">TEXT</option>
                    <option value="DATE">DATE</option>
                    <option value="DATETIME">DATETIME</option>
                    <option value="BOOLEAN">BOOLEAN</option>
                    <option value="DECIMAL">DECIMAL</option>
                    <option value="FLOAT">FLOAT</option>
                    <option value="JSON">JSON</option>
                </select>
            </td>
            <td><input type="text" name="fields[${index}][length]" class="form-control" placeholder="Len/Val"></td>
            <td>
                <select name="fields[${index}][default]" class="form-select" style="background:var(--bg-input);" onchange="toggleDefaultVal(this)">
                    <option value="NONE">None</option>
                    <option value="NULL">NULL</option>
                    <option value="CURRENT_TIMESTAMP">Curr Timestamp</option>
                    <option value="USER_DEFINED">As Defined:</option>
                </select>
                <input type="text" name="fields[${index}][default_val]" class="form-control" style="display:none; margin-top:5px;" placeholder="Value">
            </td>
            <td>
                <div style="display:flex; gap:10px; align-items:center;">
                    <label style="color:var(--text-primary); cursor:pointer;"><input type="checkbox" name="fields[${index}][null]" value="1"> Null</label>
                    <label style="color:var(--text-primary); cursor:pointer;"><input type="checkbox" name="fields[${index}][ai]" value="1"> AI</label>
                    <select name="fields[${index}][index]" class="form-select" style="width:auto; padding:2px; background:var(--bg-input);">
                        <option value="">- Index -</option>
                        <option value="PRI">Primary</option>
                        <option value="UNI">Unique</option>
                        <option value="IDX">Index</option>
                    </select>
                </div>
            </td>
            <td><button type="button" class="btn btn-danger" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>
        `;
        tbody.appendChild(tr);
        
        // Initialize TomSelect on new selects
        tr.querySelectorAll('select.form-select').forEach(el => initTomSelect(el));
    }
    
    function toggleDefaultVal(select) {
        const input = select.nextElementSibling;
        if(input) input.style.display = select.value === 'USER_DEFINED' ? 'block' : 'none';
    }
    
    // Auto-add first row if table is empty on load
    document.addEventListener('DOMContentLoaded', () => {
        if(document.getElementById('colList') && document.getElementById('colList').children.length === 0) {
            addColRow();
        }
    });

    // Helper to init TomSelect with correct settings
    function initTomSelect(el) {
        if (el.tomselect) return; // Prevent double initialization
        if (el.closest('.card') && !el.closest('#bulkTablesForm')) {
            new TomSelect(el, {
                plugins: ['clear_button'],
                maxOptions: 50,
                sortField: { field: "text", direction: "asc" },
                dropdownParent: 'body', // Fixes overflow/clipping issues
                onDropdownOpen: function() {
                    // Ensure z-index is higher than anything else
                    const wrapper = this.dropdown;
                    if(wrapper) wrapper.style.zIndex = "99999";
                }
            });
        }
    }

    // --- COPY STRUCTURE FUNCTIONS ---
    function copyAllTablesStructure() {
        const formData = new FormData();
        formData.append('action', 'get_all_tables_structure');

        fetch('?', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                copyToClipboard(data.sql);
            } else {
                Swal.fire('Error', data.message || 'Failed to fetch structure', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire('Error', 'Network error', 'error');
        });
    }

    function copyTableStructure(tableName) {
        const formData = new FormData();
        formData.append('action', 'get_table_structure');
        formData.append('table', tableName);

        fetch('?', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                copyToClipboard(data.sql);
            } else {
                Swal.fire('Error', data.message || 'Failed to fetch structure', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire('Error', 'Network error', 'error');
        });
    }

    // Initialize TomSelect for Searchable Dropdowns (Global)
    // Initialize TomSelect and Flatpickr for Global Components
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form select.form-select').forEach((el) => {
            initTomSelect(el);
        });
        document.querySelectorAll('.flatpickr-date, .flatpickr-datetime').forEach((el) => {
            initFlatpickr(el);
        });
    });

    function initFlatpickr(el) {
        if (!el || el._flatpickr) return;
        const config = {
            theme: 'dark',
            dateFormat: "Y-m-d",
            allowInput: true
        };
        if (el.classList.contains('flatpickr-datetime')) {
            config.enableTime = true;
            config.enableSeconds = true;
            config.dateFormat = "Y-m-d H:i:S";
        }
        flatpickr(el, config);
    }

    // --- INLINE EDITING ---
    function makeCellEditable(td) {
        if (td.querySelector('input')) return; // Already editing
        
        const originalContent = td.innerText.trim();
        const pk = td.getAttribute('data-pk');
        const col = td.getAttribute('data-col');
        const type = td.getAttribute('data-type') || '';
        const table = td.closest('table').getAttribute('data-table');
        
        if(!pk || !col) return;

        td.classList.add('editing');
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.style.cssText = 'min-width:100px; padding:2px 5px; height:auto;';
        input.value = originalContent === 'NULL' ? '' : originalContent;
        
        td.innerHTML = '';
        td.appendChild(input);
        input.focus();

        const onSave = () => {
            saveCellData(input, table, col, pk, originalContent);
        };

        if (type.includes('date') || type.includes('timestamp')) {
            flatpickr(input, {
                enableTime: type.includes('datetime') || type.includes('timestamp'),
                enableSeconds: type.includes('datetime') || type.includes('timestamp'),
                dateFormat: type.includes('datetime') || type.includes('timestamp') ? "Y-m-d H:i:S" : "Y-m-d",
                defaultDate: originalContent === 'NULL' ? null : originalContent,
                theme: 'dark',
                onClose: () => {
                    setTimeout(onSave, 100);
                }
            }).open();
        } else {
            input.onblur = onSave;
            input.onkeydown = (e) => { 
                if(e.key === 'Enter') {
                    input.blur();
                } else if (e.key === 'Escape') {
                    td.innerHTML = originalContent;
                    td.classList.remove('editing');
                }
            };
        }
    }

    function saveCellData(input, table, col, pk, original) {
        const newVal = input.value;
        const td = input.parentElement;
        
        if (newVal === original) {
            td.innerText = original;
            td.classList.remove('editing');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_cell');
        formData.append('table', table);
        formData.append('column', col);
        formData.append('id', pk);
        formData.append('value', newVal);

        fetch('?', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                td.innerText = newVal;
                td.style.backgroundColor = 'rgba(16, 185, 129, 0.2)';
                setTimeout(() => td.style.backgroundColor = '', 1000);
                const toast = Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 1500});
                toast.fire({ icon: 'success', title: 'Saved' });
            } else {
                td.innerHTML = original; // Revert
                Swal.fire('Error', data.message || 'Update failed', 'error');
            }
        })
        .catch(err => {
            td.innerHTML = original;
            Swal.fire('Error', 'Network error', 'error');
        })
        .finally(() => {
            td.classList.remove('editing');
        });
    }

    // ===== GENERATOR TOOLS LOGIC =====
    function openToolsModal() {
        Swal.fire({
            title: '<span style="color:var(--text-primary)">Generator Tools</span>',
            html: `
                <div class="swal2-tabs">
                    <button class="active" onclick="switchToolTab(this, 'tool-php-hash')" style="font-weight:bold; color:#0d6efd;">PHP Bcrypt</button>
                    <button onclick="switchToolTab(this, 'tool-md5')">MD5</button>
                    <button onclick="switchToolTab(this, 'tool-hash')">Hash</button>
                    <button onclick="switchToolTab(this, 'tool-uuid')">UUID</button>
                    <button onclick="switchToolTab(this, 'tool-base64')">Base64</button>
                </div>

                <div id="tool-php-hash" class="swal2-tab-content active">
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
                        <button class="swal2-styled" style="background-color:#444;" onclick="copyToClipboard(document.getElementById('phpHashResult').innerText)">Copy</button>
                    </div>
                </div>

                <!-- MD5 GENERATOR -->
                <div id="tool-md5" class="swal2-tab-content">
                    <p style="color:var(--text-secondary); font-size:13px; margin-bottom:10px;">
                        Generate <b>MD5</b> Hash. (Not recommended for passwords).
                    </p>
                    
                    <div class="tool-row">
                        <input type="text" id="md5Input" class="swal2-input" placeholder="Enter text..." autocomplete="off">
                    </div>
                    
                    <div class="tool-row">
                        <span class="tool-label">Result:</span>
                        <div id="md5Result" class="tool-result" style="flex:1;">Hash will appear here...</div>
                    </div>

                    <div style="margin-top:15px; text-align:right;">
                        <button class="swal2-confirm swal2-styled" style="background-color:var(--accent); margin-right:5px;" onclick="generateMd5()">Generate MD5</button>
                        <button class="swal2-styled" style="background-color:#444;" onclick="copyToClipboard(document.getElementById('md5Result').innerText)">Copy</button>
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
                        <button class="swal2-styled" style="background-color:#444;" onclick="copyToClipboard(document.getElementById('hashResult').innerText)">Copy</button>
                    </div>
                </div>

                <!-- UUID GENERATOR -->
                <div id="tool-uuid" class="swal2-tab-content">
                    <p style="color:var(--text-secondary); font-size:13px; margin-bottom:10px;">Generate v4 Random UUIDs.</p>
                    <div class="tool-result" id="uuidResult">Click Generate</div>
                    <div style="margin-top:10px; text-align:right;">
                        <button class="swal2-confirm swal2-styled" style="background-color:var(--accent); margin-right:5px;" onclick="generateUUID()">Generate</button>
                        <button class="swal2-styled" style="background-color:#444;" onclick="copyToClipboard(document.getElementById('uuidResult').innerText)">Copy</button>
                    </div>
                </div>

                <!-- BASE64 ENCODER -->
                <div id="tool-base64" class="swal2-tab-content">
                    <textarea id="b64Input" class="swal2-textarea" placeholder="Enter string to encode/decode..." rows="3"></textarea>
                    <div class="tool-row" style="margin-top:10px;">
                        <button class="swal2-styled" style="background-color:#444; margin-right:5px;" onclick="doBase64('encode')">Encode</button>
                        <button class="swal2-styled" style="background-color:#444;" onclick="doBase64('decode')">Decode</button>
                    </div>
                    <div class="tool-result" id="b64Result" style="margin-top:10px;">Result...</div>
                    <div style="text-align:right; margin-top:5px;">
                         <button class="swal2-styled" style="background-color:#444;" onclick="copyToClipboard(document.getElementById('b64Result').innerText)">Copy</button>
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

    // --- Helper Functions for Tools ---
    function switchToolTab(btn, targetId) {
        // Update Buttons
        const buttons = btn.parentElement.querySelectorAll('button');
        buttons.forEach(b => b.classList.remove('active', 'active-tab'));
        btn.classList.add('active');
        btn.style.color = '#0d6efd';
        buttons.forEach(b => { if(b !== btn) b.style.color = 'var(--text-secondary)'; });

        // Update Content
        const contents = document.querySelectorAll('.swal2-tab-content');
        contents.forEach(c => c.style.display = 'none');
        document.getElementById(targetId).style.display = 'block';
    }

    function generatePhpHash() {
        const pass = document.getElementById('phpHashInput').value;
        if(!pass) return Swal.showValidationMessage('Password empty');
        
        // Use the API endpoint defined at the top of adminer.php
        fetch('?api=generate_php_hash', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'password=' + encodeURIComponent(pass)
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                document.getElementById('phpHashResult').innerText = data.hash;
            } else {
                document.getElementById('phpHashResult').innerText = 'Error: ' + data.message;
            }
        })
        .catch(err => {
            document.getElementById('phpHashResult').innerText = 'Request Failed';
        });
    }

    function generateMd5() {
        const input = document.getElementById('md5Input').value;
        if(!input) return;
        
        fetch('?api=generate_md5', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'input=' + encodeURIComponent(input)
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                document.getElementById('md5Result').innerText = data.hash;
            }
        });
    }

    async function generateHash() {
        const algo = document.getElementById('hashAlgo').value;
        const text = document.getElementById('hashInput').value;
        if(!text) return;
        
        const msgUint8 = new TextEncoder().encode(text);
        const hashBuffer = await crypto.subtle.digest(algo, msgUint8);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        document.getElementById('hashResult').innerText = hashHex;
    }

    function generateUUID() {
        const uuid = crypto.randomUUID();
        document.getElementById('uuidResult').innerText = uuid;
    }

    function doBase64(action) {
        const input = document.getElementById('b64Input').value;
        const resEl = document.getElementById('b64Result');
        try {
            if(action === 'encode') resEl.innerText = btoa(input);
            else resEl.innerText = atob(input);
        } catch(e) {
            resEl.innerText = 'Invalid Input';
        }
    }

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            const toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1500,
                timerProgressBar: true,
            });
            toast.fire({ icon: 'success', title: 'Copied!' });
        });
    }

    // Media Modal Functions
    function showImageModal(src) {
        Swal.fire({
            imageUrl: src,
            imageAlt: 'Image Preview',
            showCloseButton: true,
            showConfirmButton: false,
            width: 'auto',
            background: 'var(--bg-card)',
            customClass: {
                popup: 'dark-modal',
                image: 'swal-image-preview'
            }
        });
    }

    function showVideoModal(src) {
        Swal.fire({
            html: '<video controls autoplay style="max-width:100%; max-height:70vh; border-radius:8px;"><source src="' + src + '"></video>',
            showCloseButton: true,
            showConfirmButton: false,
            width: 'auto',
            background: 'var(--bg-card)',
            customClass: {
                popup: 'dark-modal'
            }
        });
    }

    // --- XLSX Export Logic ---
    function handleExport(event) {
        const formatSelect = document.getElementById('exportFormat');
        if (formatSelect.value === 'xlsx') {
            event.preventDefault(); // Prevent form submission
            exportTableAsXLSX();
            return false;
        }
        return true; // Allow other formats to submit normally
    }

        async function exportTableAsXLSX() {

            const currentTable = "<?= htmlspecialchars($currentTable ?? '') ?>";

            const filename = `${currentTable}_${new Date().toISOString().slice(0,10)}.xlsx`;

    

            try {

                // Fetch full data as JSON using the existing export action

                const formData = new FormData();

                formData.append('action', 'export');

                formData.append('table', currentTable);

                formData.append('format', 'json'); // Request JSON data from the server

            const response = await fetch('?', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const fetchedData = await response.json(); // Parse the JSON response
            if (fetchedData.length === 0) {
                Swal.fire('Info', 'No data to export for this table.', 'info');
                return;
            }
            
            // Create a new workbook
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.json_to_sheet(fetchedData); // json to sheet

            // Add the worksheet to the workbook
            XLSX.utils.book_append_sheet(wb, ws, currentTable);
            // Write the workbook to an XLSX file and trigger download
            XLSX.writeFile(wb, filename);

            const toast = Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 1500});
            toast.fire({ icon: 'success', title: 'Exported to XLSX!' });

        } catch (error) {
            console.error('Error exporting to XLSX:', error);
            Swal.fire('Error', 'Failed to export to XLSX: ' + error.message, 'error');
        }
    }

    async function downloadExcelTemplate() {
        const currentTable = "<?= htmlspecialchars($currentTable ?? '') ?>";
        const filename = `${currentTable}_template.xlsx`;

        try {
            // Fetch detailed column info
            const formData = new FormData();
            formData.append('action', 'get_table_columns');
            formData.append('table', currentTable);

            const response = await fetch('?', {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const result = await response.json();
            
            if (result.success) {
                const columns = result.columns;
                const refTables = result.referenced_tables || {};
                
                const headers = [];
                const colWidths = [];
                const fkDataMap = {};

                columns.forEach((col, index) => {
                    let headerName = col.name;
                    if (col.required) headerName += ' *';
                    headers.push(headerName);
                    colWidths.push({ wch: Math.max(headerName.length + 5, 15) });

                    if (col.fk_values && col.fk_values.length > 0) {
                        fkDataMap[index] = col.fk_values;
                    }
                });

                // Create Workbook
                const wb = XLSX.utils.book_new();

                // 1. Create Main Worksheet
                const ws = XLSX.utils.aoa_to_sheet([headers]);
                ws['!cols'] = colWidths;

                // Add Comments
                for (let i = 0; i < columns.length; i++) {
                    const col = columns[i];
                    const cellRef = XLSX.utils.encode_cell({c: i, r: 0});
                    
                    let note = `Type: ${col.type}\n`;
                    note += col.required ? "Required: YES\n" : "Required: NO\n";
                    if (col.fk) {
                        note += `Foreign Key: ${col.fk.table}.${col.fk.col}`;
                    }
                    
                    if(!ws[cellRef]) ws[cellRef] = { t: 's', v: headers[i] };
                    if(!ws[cellRef].c) ws[cellRef].c = [];
                    ws[cellRef].c.push({a: "Adminer", t: note});
                }

                // Append main table sheet
                XLSX.utils.book_append_sheet(wb, ws, currentTable || "Template");

                // 2. Create Reference Sheets (one for each referenced table)
                Object.keys(refTables).forEach(tableName => {
                    const data = refTables[tableName];
                    if (data && data.length > 0) {
                        const refWs = XLSX.utils.json_to_sheet(data);
                        // Add auto-width for ref sheets
                        const refCols = Object.keys(data[0]).map(key => ({ 
                            wch: Math.min(Math.max(key.length, 10), 30) 
                        }));
                        refWs['!cols'] = refCols;
                        
                        // Limit sheet name to 31 chars (Excel limit)
                        const sheetName = tableName.substring(0, 31);
                        XLSX.utils.book_append_sheet(wb, refWs, sheetName);
                    }
                });

                // 3. Validation Data sheet for legacy dropdowns
                const validationSheetName = "Validations";
                const dataVsData = [];
                const dataVsHeaders = [];
                Object.keys(fkDataMap).forEach(idx => {
                    dataVsHeaders.push(`${columns[idx].name} Values`);
                });

                if (dataVsHeaders.length > 0) {
                    dataVsData.push(dataVsHeaders);
                    let maxRows = 0;
                    Object.values(fkDataMap).forEach(arr => maxRows = Math.max(maxRows, arr.length));
                    
                    for(let r=0; r < maxRows; r++) {
                        const row = [];
                        Object.keys(fkDataMap).forEach(idx => {
                            row.push(fkDataMap[idx][r] || "");
                        });
                        dataVsData.push(row);
                    }
                    const vWs = XLSX.utils.aoa_to_sheet(dataVsData);
                    XLSX.utils.book_append_sheet(wb, vWs, validationSheetName);

                    // Add Data Validations to the main sheet
                    let refColIdx = 0;
                    Object.keys(fkDataMap).forEach(colIdx => {
                        const valuesCount = fkDataMap[colIdx].length;
                        if(valuesCount > 0) {
                            const dataColChar = XLSX.utils.encode_col(refColIdx); 
                            const range = `'${validationSheetName}'!$${dataColChar}$2:$${dataColChar}$${valuesCount + 1}`;
                            const templateColChar = XLSX.utils.encode_col(parseInt(colIdx));
                            
                            if (!ws['!dataValidation']) ws['!dataValidation'] = [];
                            ws['!dataValidation'].push({
                                type: 'list',
                                allowBlank: true,
                                operator: 'between', 
                                formula1: range,
                                sqref: `${templateColChar}2:${templateColChar}1000`,
                                showErrorMessage: true,
                                errorTitle: "Invalid Value",
                                error: "Please select a value from the list."
                            });
                           
                            refColIdx++;
                        }
                    });
                }

                // Write File
                XLSX.writeFile(wb, filename);

                const toast = Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 1500});
                toast.fire({ icon: 'success', title: 'Template downloaded!' });
            } else {
                throw new Error(result.message || 'Failed to fetch table columns.');
            }
        } catch (error) {
            console.error('Error downloading template:', error);
            Swal.fire('Error', 'Failed to download template: ' + error.message, 'error');
        }
    }

    // --- XLSX Import Logic ---
    let importDataPayload = null; // Store context for confirm

    document.addEventListener('DOMContentLoaded', () => {
        const excelImportForm = document.getElementById('excelImportForm');
        if (excelImportForm) {
            excelImportForm.addEventListener('submit', handleExcelImport);
        }
    });

    function updateImportStatus(message, type = 'info') {
        const statusDiv = document.getElementById('importStatus');
        if (statusDiv) {
            statusDiv.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
        }
    }

    async function handleExcelImport(event) {
        event.preventDefault();
        updateImportStatus('Reading Excel file...', 'info');

        const fileInput = document.getElementById('excelFile');
        const file = fileInput.files[0];
        if (!file) {
            updateImportStatus('Please select an Excel file.', 'danger');
            return;
        }

        const tableName = event.target.querySelector('input[name="table"]').value;
        const importType = event.target.querySelector('input[name="importType"]:checked').value;
        const primaryKeyCol = document.getElementById('primaryKeyCol').value;
        const truncateTable = document.getElementById('truncateTable').checked;

        if ((importType === 'update' || importType === 'upsert') && !primaryKeyCol) {
            updateImportStatus('Primary Key Column is required for Update/Upsert import types.', 'danger');
            return;
        }

        const reader = new FileReader();
        reader.onload = async (e) => {
            try {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, { type: 'array' });
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                
                // Convert sheet to JSON. header:1 means first row is header.
                const jsonData = XLSX.utils.sheet_to_json(worksheet, { header: 1 });

                if (jsonData.length < 2) {
                    updateImportStatus('Excel file is empty or only contains headers.', 'danger');
                    return;
                }

                // Assume first row is headers
                const headers = jsonData[0];
                const rowsToImport = jsonData.slice(1); // Data rows

                // Store basic payload info
                importDataPayload = {
                    action: 'import_excel',
                    table: tableName,
                    importType: importType,
                    primaryKeyCol: primaryKeyCol,
                    truncateTable: truncateTable,
                    headers: headers
                    // data will be grabbed from table on confirm
                };

                renderPreviewTable(headers, rowsToImport);
                updateImportStatus('Preview loaded. Review data below before confirming.', 'success');

            } catch (error) {
                console.error('Error during Excel import:', error);
                updateImportStatus(`An error occurred during import: ${error.message}`, 'danger');
            }
        };
        reader.readAsArrayBuffer(file);
    }

    function renderPreviewTable(headers, data) {
        const container = document.getElementById('importPreviewContainer');
        const table = document.getElementById('previewTable');
        const thead = table.querySelector('thead');
        const tbody = table.querySelector('tbody');

        container.style.display = 'block';
        thead.innerHTML = '';
        tbody.innerHTML = '';

        // Headers
        const trHead = document.createElement('tr');
        headers.forEach(h => {
            const th = document.createElement('th');
            th.textContent = h;
            trHead.appendChild(th);
        });
        thead.appendChild(trHead);

        // Body
        data.forEach(row => {
            const tr = document.createElement('tr');
            // Ensure row matches header length
            for(let i=0; i < headers.length; i++) {
                const td = document.createElement('td');
                const val = (row[i] !== undefined && row[i] !== null) ? row[i] : "";
                td.textContent = val;
                td.setAttribute('contenteditable', 'true');
                td.style.border = '1px solid #444'; // Visual cue
                td.addEventListener('blur', function() {
                    // Optional: Validation logic here
                });
                tr.appendChild(td);
            }
            tbody.appendChild(tr);
        });
    }

    function cancelImport() {
        document.getElementById('importPreviewContainer').style.display = 'none';
        updateImportStatus('Import cancelled.');
        importDataPayload = null;
    }

    async function confirmImport() {
        if(!importDataPayload) return;

        updateImportStatus('Sending data to server...', 'info');
        
        // Scrape data from table
        const table = document.getElementById('previewTable');
        const rows = table.querySelectorAll('tbody tr');
        const finalData = [];

        rows.forEach(tr => {
            const rowData = [];
            tr.querySelectorAll('td').forEach(td => {
                rowData.push(td.textContent); // Text content from contenteditable
            });
            finalData.push(rowData);
        });

        importDataPayload.data = finalData;

        try {
            const response = await fetch('?', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json' 
                },
                body: JSON.stringify(importDataPayload)
            });

            // Handle non-JSON responses (fatal errors)
            const text = await response.text();
            let result;
            try {
                result = JSON.parse(text);
            } catch (e) {
                throw new Error('Server returned invalid JSON: ' + text.substring(0, 100) + '...');
            }

            if (result.success) {
                updateImportStatus(`Import successful! ${result.insertedRows || 0} inserted, ${result.updatedRows || 0} updated.`, 'success');
                document.getElementById('importPreviewContainer').style.display = 'none';
            } else {
                updateImportStatus(`Import failed: ${result.message || 'Unknown error.'}`, 'danger');
            }

        } catch (error) {
            console.error('Error sending import data:', error);
            updateImportStatus(`An error occurred: ${error.message}`, 'danger');
        }
    }

    // --- DIAGRAM FULLSCREEN HELPER ---
    window.toggleFullscreenDiagram = function(el) {
        if (!document.fullscreenElement) {
            const requestMethod = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen || el.msRequestFullscreen;
            if (requestMethod) {
                requestMethod.call(el).catch(err => {
                    el.parentElement.requestFullscreen();
                });
            } else {
                el.parentElement.requestFullscreen();
            }
            el.style.maxHeight = 'none';
            if (el.tagName === 'IMG') {
                el.style.height = '100vh';
                el.style.width = '100vw';
                el.style.objectFit = 'contain';
                el.style.background = '#000';
            } else {
                el.style.background = '#000';
            }
        } else {
            document.exitFullscreen();
        }
    };

    document.addEventListener('fullscreenchange', () => {
        if (!document.fullscreenElement) {
            document.querySelectorAll('.mermaid, .erd-img').forEach(el => {
                el.style.maxHeight = '600px';
                el.style.background = el.tagName === 'IMG' ? '#fff' : '#080808';
                if (el.tagName === 'IMG') {
                    el.style.height = 'auto';
                    el.style.width = '100%';
                }
            });
        }
    });
</script>
    <style>
        :root {
            --bg-body: #050505;
            --bg-sidebar: #0f0f0f;
            --bg-card: #141414;
            --bg-hover: #1f1f1f;
            --bg-input: #1a1a1a;
            --dark-gray: #222;
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
        .ts-dropdown .active { background-color: var(--accent) !important; color: var(--text-primary) !important; }
        .ts-wrapper.single .ts-control:after { border-color: #888 transparent transparent transparent !important; }
        
        /* SweetAlert2 Custom Dark Theme */
        .swal2-popup {
            background: var(--bg-card) !important;
            border: 1px solid var(--border-color) !important;
            color: var(--text-primary) !important;
        }
        .swal2-title {
            color: var(--text-primary) !important;
        }
        .swal2-html-container {
            color: var(--text-secondary) !important;
        }
        .swal2-input, .swal2-textarea {
            background: var(--bg-input) !important;
            border: 1px solid var(--border-color) !important;
            color: var(--text-primary) !important;
        }
        .swal2-input:focus, .swal2-textarea:focus {
            border-color: var(--accent) !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
        }
        .swal2-confirm {
            background: var(--accent) !important;
            border: none !important;
        }
        .swal2-confirm:hover {
            background: #2563eb !important;
        }
        .swal2-cancel {
            background: var(--bg-hover) !important;
            border: 1px solid var(--border-color) !important;
            color: var(--text-primary) !important;
        }
        .swal2-cancel:hover {
            background: #2a2a2a !important;
        }
        .swal2-styled:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3) !important;
        }
        .swal2-icon.swal2-success [class^='swal2-success-line'] {
            background-color: var(--success) !important;
        }
        .swal2-icon.swal2-success .swal2-success-ring {
            border-color: rgba(16, 185, 129, 0.3) !important;
        }
        .swal2-icon.swal2-error [class^='swal2-x-mark-line'] {
            background-color: var(--danger) !important;
        }
        .swal2-icon.swal2-warning {
            border-color: #f59e0b !important;
            color: #f59e0b !important;
        }
        .swal2-icon.swal2-info {
            border-color: var(--accent) !important;
            color: var(--accent) !important;
        }
        .swal2-icon.swal2-question {
            border-color: var(--accent) !important;
            color: var(--accent) !important;
        }
        /* SweetAlert2 Toast */
        .swal2-toast {
            background: var(--bg-card) !important;
            border: 1px solid var(--border-color) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5) !important;
        }
        .swal2-toast .swal2-title {
            color: var(--text-primary) !important;
        }
        .swal2-toast .swal2-icon {
            border-width: 2px !important;
        }
        /* SweetAlert2 Validation Message */
        .swal2-validation-message {
            background: var(--bg-hover) !important;
            color: var(--danger) !important;
            border: 1px solid var(--danger) !important;
        }
        /* SweetAlert2 Close Button */
        .swal2-close {
            color: var(--text-secondary) !important;
        }
        .swal2-close:hover {
            color: var(--text-primary) !important;
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
        .login-btn { width: 100%; padding: 12px; background: var(--accent); color: var(--text-primary); border: none; border-radius: 4px; cursor: pointer; font-weight: bold; margin-top: 10px; }
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
        .db-info { padding: 15px 20px; font-size: 0.85rem; color: var(--text-secondary); border-bottom: 1px solid var(--border-color); background: var(--bg-sidebar); }
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
        .btn-primary { background: var(--accent); border-color: var(--accent); color: var(--text-primary); }
        .btn-primary:hover { background: #2563eb; }
        .btn-danger { background: rgba(239, 68, 68, 0.1); border-color: var(--danger); color: var(--danger); }
        .btn-danger:hover { background: var(--danger); color: var(--text-primary); }
        
        .form-control, .form-select { width: 100%; padding: 8px 10px; background: var(--bg-input); border: 1px solid var(--border-color); color: var(--text-primary); border-radius: 4px; font-size: 0.9rem; }
        .form-control:focus, .form-select:focus { outline: none; border-color: var(--accent); }
        
        .alert { padding: 12px 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid transparent; font-size: 0.9rem; }
        .alert-success { background: rgba(16, 185, 129, 0.1); border-color: var(--success); color: var(--success); }
        .alert-danger { background: rgba(239, 68, 68, 0.1); border-color: var(--danger); color: var(--danger); }

        /* DATA TABLES */
        .table-wrapper { border: 1px solid var(--border-color); border-radius: 6px; overflow-x: auto; background: var(--bg-card); max-height: 80vh; }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th, td { padding: 10px 15px; text-align: left; border-bottom: 1px solid var(--border-color); }
        th { background: var(--bg-input);color: var(--text-primary); font-weight: 600; font-size: 0.8rem; text-transform: uppercase; white-space: nowrap; position: sticky; top: 0; z-index: 5; box-shadow: 0 2px 5px rgba(0,0,0,0.5); }
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

/* Media Preview Styles */
.swal-image-preview {
    max-width: 90vw !important;
    max-height: 80vh !important;
    object-fit: contain;
    border-radius: 8px;
}

.dark-modal {
    background: var(--bg-card) !important;
    color: var(--text-primary) !important;
}
/* Query Builder & Advanced Filters Styles */

.query-builder,
.advanced-filters {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 20px;
}

.qb-section {
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-color);
}

.qb-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.qb-section h4 {
    margin: 0 0 15px 0;
    color: var(--text-primary);
    font-size: 1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.qb-section h4 i {
    color: var(--accent);
}

.qb-columns label {
    cursor: pointer;
    padding: 4px 0;
    transition: color 0.2s;
}

.qb-columns label:hover {
    color: var(--accent);
}

.qb-condition,
.qb-order,
.af-filter {
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.qb-condition select,
.qb-condition input,
.qb-order select,
.af-filter select,
.af-filter input,
.af-filter textarea {
    font-size: 0.9rem;
}

/* Advanced Filters specific */
.af-filter {
    position: relative;
    transition: all 0.2s;
}

.af-filter:hover {
    background: var(--bg-card) !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}

.af-value-container {
    display: flex;
    align-items: center;
}

/* Responsive */
@media (max-width: 768px) {
    .qb-condition,
    .qb-order,
    .af-filter {
        flex-direction: column;
        align-items: stretch !important;
    }
    
    .qb-condition > *,
    .qb-order > *,
    .af-filter > * {
        width: 100% !important;
        min-width: 100% !important;
    }
}

/* Button styles */
.btn-sm {
    padding: 6px 12px;
    font-size: 0.85rem;
}

/* TomSelect overrides for dark theme */
.ts-wrapper.form-select .ts-control {
    background-color: var(--bg-input) !important;
    border-color: var(--border-color) !important;
}

.ts-dropdown {
    background-color: var(--bg-card) !important;
    border-color: var(--border-color) !important;
}

.ts-dropdown .option {
    color: var(--text-primary) !important;
}

.ts-dropdown .option:hover,
.ts-dropdown .option.active {
    background-color: var(--bg-hover) !important;
    color: var(--accent) !important;
}

/* Query preview */
#qb-generated-query {
    font-family: 'Fira Code', 'Courier New', monospace;
    line-height: 1.6;
    tab-size: 4;
}

/* Loading state */
.qb-loading,
.af-loading {
    position: relative;
    pointer-events: none;
    opacity: 0.6;
}

.qb-loading::after,
.af-loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 30px;
    height: 30px;
    margin: -15px 0 0 -15px;
    border: 3px solid var(--border-color);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
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
            <!-- Database Mode Toggle -->
            <div style="margin-bottom: 10px; padding: 8px; background: var(--bg-hover); border-radius: 4px; border: 1px solid #444;">
                <div style="display: flex; gap: 5px; margin-bottom: 5px;">
                    <button type="button" onclick="switchDbMode('sql')" class="btn" style="flex: 1; padding: 4px 8px; font-size: 0.75rem; <?= ($_SESSION['db_mode'] ?? 'sql') === 'sql' ? 'background: var(--accent); color: white;' : '' ?>">
                        <i class="fas fa-database"></i> SQL
                    </button>
                    <button type="button" onclick="switchDbMode('sqlite')" class="btn" style="flex: 1; padding: 4px 8px; font-size: 0.75rem; <?= ($_SESSION['db_mode'] ?? 'sql') === 'sqlite' ? 'background: var(--accent); color: white;' : '' ?>">
                        <i class="fas fa-cube"></i> SQLite
                    </button>
                    <button type="button" onclick="switchDbMode('json')" class="btn" style="flex: 1; padding: 4px 8px; font-size: 0.75rem; <?= ($_SESSION['db_mode'] ?? 'sql') === 'json' ? 'background: var(--accent); color: white;' : '' ?>">
                        <i class="fas fa-file-code"></i> JSON
                    </button>
                </div>
                <small style="font-size: 0.7rem; color: var(--text-secondary); display: block; text-align: center;">
                    Mode: <?= strtoupper($_SESSION['db_mode'] ?? 'sql') ?>
                </small>
            </div>
            
            <?php if (($_SESSION['db_mode'] ?? 'sql') === 'sql'): ?>
                <form method="GET" style="margin-bottom: 5px;">
                    <select name="select_db" onchange="this.form.submit()" class="form-select" style="padding: 2px 5px; font-size: 0.8rem; background: var(--dark-gray); color: var(--text-primary); border: 1px solid #444; width: 100%;">
                        <option value="">-- Pilih Database --</option>
                        <?php foreach ($databases as $db): ?>
                            <option value="<?=htmlspecialchars($db)?>" <?=$db === $_SESSION['db_name'] ? 'selected' : ''?>>
                                <?=htmlspecialchars($db)?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <small><i class="fas fa-server"></i> <span><?=htmlspecialchars($_SESSION['db_host'])?></span></small>
            <?php elseif (($_SESSION['db_mode'] ?? 'sql') === 'sqlite'): ?>
                <form method="GET" style="margin-bottom: 5px;">
                    <select name="select_sqlite_file" onchange="this.form.submit()" class="form-select" style="padding: 2px 5px; font-size: 0.8rem; background: var(--dark-gray); color: var(--text-primary); border: 1px solid #444; width: 100%;">
                        <option value="">-- Pilih SQLite File --</option>
                        <?php 
                        $sqliteFiles = glob(__DIR__ . '/sqlite_db/*.db');
                        $sqliteFiles = array_merge($sqliteFiles, glob(__DIR__ . '/sqlite_db/*.sqlite'));
                        foreach ($sqliteFiles as $file): 
                            $basename = basename($file);
                        ?>
                            <option value="<?=htmlspecialchars($basename)?>" <?=$basename === ($_SESSION['sqlite_file'] ?? '') ? 'selected' : ''?>>
                                <?=htmlspecialchars($basename)?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div style="display: flex; gap: 5px; margin-bottom: 5px;">
                    <button type="button" onclick="createNewSqliteFile()" class="btn" style="flex: 1; padding: 4px 8px; font-size: 0.7rem; background: var(--success);">
                        <i class="fas fa-plus"></i> New
                    </button>
                    <button type="button" onclick="browseSqliteFile()" class="btn" style="flex: 1; padding: 4px 8px; font-size: 0.7rem; background: var(--accent);">
                        <i class="fas fa-folder-open"></i> Browse
                    </button>
                    <?php if (!empty($_SESSION['sqlite_file'])): ?>
                    <button type="button" onclick="deleteSqliteFile('<?=htmlspecialchars($_SESSION['sqlite_file'])?>')" class="btn" style="flex: 1; padding: 4px 8px; font-size: 0.7rem; background: var(--danger);">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                    <?php endif; ?>
                </div>
                <small><i class="fas fa-database"></i> <span>SQLite Database</span></small>
            <?php else: ?>
                <form method="GET" style="margin-bottom: 5px;">
                    <select name="select_json_file" onchange="this.form.submit()" class="form-select" style="padding: 2px 5px; font-size: 0.8rem; background: var(--dark-gray); color: var(--text-primary); border: 1px solid #444; width: 100%;">
                        <option value="">-- Pilih JSON File --</option>
                        <?php 
                        if (isset($jsonDb)) {
                            $jsonFiles = $jsonDb->listFiles();
                            foreach ($jsonFiles as $file): ?>
                                <option value="<?=htmlspecialchars($file)?>" <?=$file === ($_SESSION['json_file'] ?? '') ? 'selected' : ''?>>
                                    <?=htmlspecialchars($file)?>
                                </option>
                            <?php endforeach;
                        }
                        ?>
                    </select>
                </form>
                <div style="display: flex; gap: 5px; margin-bottom: 5px;">
                    <button type="button" onclick="createNewJsonFile()" class="btn" style="flex: 1; padding: 4px 8px; font-size: 0.7rem; background: var(--success);">
                        <i class="fas fa-plus"></i> New
                    </button>
                    <button type="button" onclick="browseJsonFile()" class="btn" style="flex: 1; padding: 4px 8px; font-size: 0.7rem; background: var(--accent);">
                        <i class="fas fa-folder-open"></i> Browse
                    </button>
                    <?php if (!empty($_SESSION['json_file'])): ?>
                    <button type="button" onclick="deleteJsonFile('<?=htmlspecialchars($_SESSION['json_file'])?>')" class="btn" style="flex: 1; padding: 4px 8px; font-size: 0.7rem; background: var(--danger);">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                    <?php endif; ?>
                </div>
                <small><i class="fas fa-folder"></i> <span>JSON Database</span></small>
            <?php endif; ?>
        </div>
        <div class="nav-list">
            <div style="padding: 0 20px 10px;">
                <input type="text" id="tableSearch" class="form-control" placeholder="Search tables..." style="width: 100%;">
            </div>
            <a href="?" class="nav-item <?=!$currentTable ? 'active' : ''?>">
                <i class="fas fa-tachometer-alt" style="width:20px; text-align:center;"></i> <span>Dashboard</span>
            </a>
            
            <div id="pinned-tables-section" style="display:none;">
                <div class="nav-header"><span><i class="fas fa-thumbtack"></i> Pinned Tables</span></div>
                <div id="pinned-tables-list"></div>
            </div>

            <div class="nav-header"><span>Tables (<?=count($tables)?>)</span></div>
            <div id="sidebar-tables-list">
                <?php foreach ($tables as $t): ?>
                    <div class="nav-item-wrapper" data-table="<?=htmlspecialchars($t['Name'])?>" style="display:flex; align-items:center;">
                        <a href="?table=<?=htmlspecialchars($t['Name'])?>" class="nav-item <?=$currentTable === $t['Name'] ? 'active' : ''?>" style="flex:1;">
                            <i class="fas fa-table" style="width:20px; text-align:center;"></i> 
                            <span><?=htmlspecialchars($t['Name'])?> <small>(<?=formatSize($t['Data_length'] + $t['Index_length'])?>)</small></span>
                        </a>
                        <button type="button" class="pin-btn" onclick="togglePin('<?=htmlspecialchars($t['Name'])?>')" title="Pin Table" style="background:none; border:none; color:var(--text-secondary); cursor:pointer; padding:10px; opacity:0; transition:opacity 0.2s;">
                            <i class="fas fa-thumbtack"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            .nav-item-wrapper:hover .pin-btn { opacity: 1 !important; }
            .pin-btn.active { color: var(--accent) !important; opacity: 1 !important; }
        </style>
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
                    <a href="#" onclick="toggleTheme()" title="Toggle Theme" style="color:var(--text-secondary); font-size:1.1rem;">
                        <i class="fas fa-adjust"></i>
                    </a>
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

            <?php 
            $dbMode = $_SESSION['db_mode'] ?? 'sql';
            if (!$currentTable): 
            ?>
                <!-- System Health Dashboard -->
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:20px;">
                    <div class="card" style="margin-bottom:0; display:flex; align-items:center; gap:15px; padding:20px;">
                        <div style="width:45px; height:45px; border-radius:10px; background:rgba(0, 123, 255, 0.1); color:#007bff; display:flex; align-items:center; justify-content:center; font-size:1.5rem;">
                            <i class="fab fa-php"></i>
                        </div>
                        <div>
                            <div style="font-size:0.75rem; color:var(--text-secondary); text-transform:uppercase; font-weight:bold;">PHP Version</div>
                            <div style="font-size:1.1rem; font-weight:bold;"><?= PHP_VERSION ?></div>
                        </div>
                    </div>
                    <div class="card" style="margin-bottom:0; display:flex; align-items:center; gap:15px; padding:20px;">
                        <div style="width:45px; height:45px; border-radius:10px; background:rgba(16, 185, 129, 0.1); color:var(--success); display:flex; align-items:center; justify-content:center; font-size:1.5rem;">
                            <i class="fas fa-upload"></i>
                        </div>
                        <div>
                            <div style="font-size:0.75rem; color:var(--text-secondary); text-transform:uppercase; font-weight:bold;">Max Upload</div>
                            <div style="font-size:1.1rem; font-weight:bold;"><?= ini_get('upload_max_filesize') ?></div>
                        </div>
                    </div>
                    <div class="card" style="margin-bottom:0; display:flex; align-items:center; gap:15px; padding:20px;">
                        <div style="width:45px; height:45px; border-radius:10px; background:rgba(251, 191, 36, 0.1); color:#fbbf24; display:flex; align-items:center; justify-content:center; font-size:1.5rem;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div>
                            <div style="font-size:0.75rem; color:var(--text-secondary); text-transform:uppercase; font-weight:bold;">Server Time</div>
                            <div style="font-size:1.1rem; font-weight:bold;"><?= date('H:i:s') ?></div>
                        </div>
                    </div>
                    <div class="card" style="margin-bottom:0; display:flex; align-items:center; gap:15px; padding:20px;">
                        <div style="width:45px; height:45px; border-radius:10px; background:<?=isset($pdo)?'rgba(16, 185, 129, 0.1)':'rgba(239, 68, 68, 0.1)'?>; color:<?=isset($pdo)?'var(--success)':'var(--danger)'?>; display:flex; align-items:center; justify-content:center; font-size:1.5rem;">
                            <i class="fas fa-plug"></i>
                        </div>
                        <div>
                            <div style="font-size:0.75rem; color:var(--text-secondary); text-transform:uppercase; font-weight:bold;">Status</div>
                            <div style="font-size:1.1rem; font-weight:bold;"><?= isset($pdo) ? 'Active' : 'Disconnected' ?></div>
                        </div>
                    </div>
                </div>

                <?php if ($dbMode === 'sql' && !$hasSelectedDatabase): ?>
                    <div class="card">
                        <h3>Pilih Database</h3>
                        <p style="color:var(--text-secondary); line-height:1.6;">
                            Kredensial sudah disimpan. Silakan pilih database dari dropdown di sidebar atau kelola daftar database
                            melalui modul manajemen di bawah.
                        </p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

                <?php 
                $configList = load_config($configFile)['databases'] ?? [];
                if (should_show_managed_database_list($hostProfile) && !$currentTable && $view === 'structure'): ?>
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
                                        <form method="POST" onsubmit='saConfirmForm(event, <?= json_encode('Remove ' . $dbItem . ' from list?') ?>)' style="display:inline;">
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
                
            <?php if ($currentTable):
                ?>
                <!-- TABLE VIEW -->
                <div class="tabs">
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=structure" class="tab <?=$view==='structure'?'active':''?>">Structure</a>
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=data" class="tab <?=$view==='data'?'active':''?>">Data</a>
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=sql" class="tab <?=$view==='sql'?'active':''?>">SQL</a>
                    <a href="?table=<?=htmlspecialchars($currentTable)?>&view=import" class="tab <?=$view==='import'?'active':''?>">Import</a>
                    <div style="flex:1;"></div>
                    <!-- Actions -->
                    <button type="button" class="btn" onclick="copyTableStructure('<?=htmlspecialchars($currentTable)?>')" style="margin-right:10px;"><i class="fas fa-copy"></i> Copy Structure</button>
                    <button type="button" class="btn" onclick="duplicateTablePrompt('<?=htmlspecialchars($currentTable)?>')" style="margin-right:10px;"><i class="fas fa-clone"></i> Duplicate</button>
                     <form method="POST" style="margin:0; display:flex;" onsubmit="return handleExport(event)">
                        <input type="hidden" name="action" value="export">
                        <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                        <select id="exportFormat" name="format" class="form-select" style="border-radius:4px 0 0 4px; border-right:none; width:auto; padding:5px 10px; font-size:0.85rem;">
                            <option value="sql">SQL</option>
                            <option value="json">JSON</option>
                            <option value="csv">CSV</option>
                            <option value="xlsx">XLSX</option>
                        </select>
                        <button type="submit" class="btn" style="border-radius:0 4px 4px 0;"><i class="fas fa-download"></i> Export</button>
                    </form>
                </div>

                <?php if ($view === 'import'): ?>
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px;">
                        <div>
                            <h3><i class="fas fa-file-excel"></i> Import Data from Excel (.xlsx)</h3>
                            <p style="color:var(--text-secondary); margin-bottom:0;">
                                Upload an Excel file to import data into the table `<?=htmlspecialchars($currentTable)?>`. 
                                The first row should contain column headers.
                            </p>
                        </div>
                        <button type="button" class="btn" onclick="downloadExcelTemplate()"><i class="fas fa-download"></i> Download Template</button>
                    </div>
                    
                    <form id="excelImportForm">
                        <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                        
                        <div style="margin-bottom:15px;">
                            <label for="excelFile" class="form-label">Select Excel File:</label>
                            <input type="file" id="excelFile" class="form-control" accept=".xlsx" required>
                        </div>

                        <div style="margin-bottom:15px;">
                            <label class="form-label">Import Type:</label>
                            <div style="display:flex; gap:15px;">
                                <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                                    <input type="radio" name="importType" value="insert" checked> Insert New Rows
                                </label>
                                <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                                    <input type="radio" name="importType" value="update"> Update Existing Rows (requires Primary Key)
                                </label>
                                <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                                    <input type="radio" name="importType" value="upsert"> Upsert (Insert or Update) (requires Primary Key)
                                </label>
                            </div>
                        </div>

                        <div style="margin-bottom:15px;">
                            <label for="primaryKeyCol" class="form-label">Primary Key Column (for Update/Upsert):</label>
                            <input type="text" id="primaryKeyCol" class="form-control" placeholder="e.g. id">
                        </div>
                        
                        <div style="margin-bottom:15px;">
                            <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                                <input type="checkbox" id="truncateTable" name="truncateTable"> Truncate table before import
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary" id="btnPreviewImport"><i class="fas fa-eye"></i> Preview & Import</button>
                    </form>

                    <!-- PREVIEW CONTAINER -->
                    <div id="importPreviewContainer" style="display:none; margin-top:30px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                            <h4 style="margin:0;"><i class="fas fa-table"></i> Data Preview</h4>
                            <div>
                                <small style="color:var(--text-secondary); margin-right:10px;">Double-click cells to edit. Headers must match DB columns.</small>
                                <button type="button" class="btn btn-success" onclick="confirmImport()"><i class="fas fa-check"></i> Confirm Import</button>
                                <button type="button" class="btn btn-danger" onclick="cancelImport()"><i class="fas fa-times"></i> Cancel</button>
                            </div>
                        </div>
                        <div class="table-wrapper" style="max-height:500px; overflow:auto;">
                            <table id="previewTable">
                                <thead></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <div id="importStatus" style="margin-top:20px; padding:15px; background:var(--bg-hover); border:1px solid var(--border-color); border-radius:6px; min-height:50px;">
                        Waiting for file selection...
                    </div>
                </div>
                <?php endif; ?>

                <script>
                function duplicateTablePrompt(sourceTable) {
                    Swal.fire({
                        title: 'Duplicate Table',
                        html: `
                            <input type="text" id="targetTable" class="swal2-input" placeholder="New Table Name" value="${sourceTable}_copy">
                            <div style="margin-top:10px; display:flex; align-items:center; justify-content:center;">
                                <input type="checkbox" id="copyData" class="swal2-checkbox" checked style="display:inline; width:auto; margin:0 5px 0 0;">
                                <label for="copyData" style="margin:0;">Copy Data</label>
                            </div>
                        `,
                        showCancelButton: true,
                        confirmButtonText: 'Duplicate',
                        preConfirm: () => {
                            const target = document.getElementById('targetTable').value;
                            if (!target) return Swal.showValidationMessage('Name required');
                            return { target: target, copy: document.getElementById('copyData').checked };
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.innerHTML = `
                                <input type="hidden" name="action" value="duplicate_table">
                                <input type="hidden" name="source" value="${sourceTable}">
                                <input type="hidden" name="target" value="${result.value.target}">
                                ${result.value.copy ? '<input type="hidden" name="copy_data" value="1">' : ''}
                            `;
                            document.body.appendChild(form);
                            form.submit();
                        }
                    });
                    }
                    </script>

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

                            <div style="margin-left:auto; display:flex; gap:10px; align-items:center;" id="bulkActionsContainer" style="display:none;">
                                <select id="bulkActionSelect" class="form-select" style="width:150px; display:none;">
                                    <option value="">With Selected:</option>
                                    <option value="delete">Delete</option>
                                    <option value="export_sql">Export SQL</option>
                                    <option value="export_csv">Export CSV</option>
                                    <option value="export_json">Export JSON</option>
                                </select>
                                <button type="button" onclick="submitBulkAction()" class="btn btn-primary" id="bulkApplyBtn" style="display:none;">Apply</button>
                                <a href="?table=<?=htmlspecialchars($currentTable)?>&view=form" class="btn btn-primary"><i class="fas fa-plus"></i> New Row</a>
                            </div>
                        </div>
                    </div>

                    <form method="POST" id="bulkForm">
                        <input type="hidden" name="action" value="bulk_delete"> <!-- Default, changed by JS -->
                        <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                        <input type="hidden" name="pk" value="<?=htmlspecialchars($primaryKey)?>">
                        
                        <div class="table-wrapper">
                            <table data-table="<?=htmlspecialchars($currentTable)?>">
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
                                                ?><a href="?table=<?=htmlspecialchars($currentTable)?>&view=form&pk=<?=urlencode($primaryKey)?>&val=<?=urlencode($row[$primaryKey])?>&mode=copy" style="margin-right:5px; color:#fbbf24;" title="Copy to Form"><i class="fas fa-copy"></i></a><?php 
                                                ?><form method="POST" style="display:inline; margin-right:5px;">
                                                    <input type="hidden" name="action" value="duplicate_row">
                                                    <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                                                    <input type="hidden" name="pk" value="<?=htmlspecialchars($primaryKey)?>">
                                                    <input type="hidden" name="val" value="<?=htmlspecialchars($row[$primaryKey])?>">
                                                    <button type="submit" style="background:none; border:none; cursor:pointer; color:#8b5cf6; padding:0;" title="Quick Duplicate"><i class="fas fa-clone"></i></button>
                                                </form><?php
                                                ?><a href="?table=<?=htmlspecialchars($currentTable)?>&action=delete_row&pk=<?=urlencode($primaryKey)?>&val=<?=urlencode($row[$primaryKey])?>" onclick="saConfirmLink(event, 'Delete this row permanently?')" style="color:var(--danger);" title="Delete Row"><i class="fas fa-trash"></i></a><?php 
                                            else:
                                                ?><span style="opacity:0.3">-</span><?php 
                                            endif; ?>
                                        </td>
                                        <?php foreach ($row as $key => $val):
                                            $displayVal = $val !== null ? htmlspecialchars((string)$val) : '<span style="color:#666">NULL</span>';
                                            
                                            // Media Display Logic (Images)
                                            $isMediaColumn = false;
                                            if ($val !== null) {
                                                $valStr = (string)$val;
                                                // Check if it's a base64 image
                                                if (preg_match('/^data:image\/(png|jpg|jpeg|gif|webp|svg\+xml);base64,/', $valStr)) {
                                                    $isMediaColumn = true;
                                                    $displayVal = '<div style="display:flex; align-items:center; gap:8px;">'
                                                        . '<img src="' . htmlspecialchars($valStr) . '" style="max-width:60px; max-height:60px; border-radius:4px; cursor:pointer; object-fit:cover;" onclick="showImageModal(this.src)" title="Click to enlarge">'
                                                        . '<span style="font-size:0.8em; color:var(--text-secondary);">[Base64]</span>'
                                                        . '</div>';
                                                }
                                                // Check if it's a file path to an image (only show if column name suggests it's an image)
                                                elseif (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|bmp|ico)$/i', $valStr) && 
                                                        (stripos($key, 'image') !== false || stripos($key, 'img') !== false || 
                                                         stripos($key, 'photo') !== false || stripos($key, 'picture') !== false ||
                                                         stripos($key, 'avatar') !== false || stripos($key, 'thumbnail') !== false ||
                                                         stripos($key, 'icon') !== false || stripos($key, 'logo') !== false)) {
                                                    $isMediaColumn = true;
                                                    // Try to construct a valid URL
                                                    $imgUrl = $valStr;
                                                    if (!preg_match('/^https?:\/\//', $valStr)) {
                                                        // Relative path - try to make it absolute
                                                        $imgUrl = (strpos($valStr, '/') === 0) ? $valStr : '/' . $valStr;
                                                    }
                                                    $displayVal = '<div style="display:flex; align-items:center; gap:8px;">'
                                                        . '<div style="position:relative; width:60px; height:60px; background:#1a1a1a; border-radius:4px; overflow:hidden;">'
                                                        . '<img src="' . htmlspecialchars($imgUrl) . '" style="width:100%; height:100%; object-fit:cover; cursor:pointer;" onclick="showImageModal(this.src)" title="Click to enlarge" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';" loading="lazy">'
                                                        . '<div style="display:none; width:100%; height:100%; align-items:center; justify-content:center; color:#666; font-size:0.7em; text-align:center; padding:5px;">No Image</div>'
                                                        . '</div>'
                                                        . '<span style="font-size:0.85em; color:var(--text-secondary); word-break:break-all; max-width:200px; overflow:hidden; text-overflow:ellipsis;" title="' . htmlspecialchars($valStr) . '">' . htmlspecialchars(basename($valStr)) . '</span>'
                                                        . '</div>';
                                                }
                                                // Check if it's a video file path
                                                elseif (preg_match('/\.(mp4|webm|ogg|mov|avi)$/i', $valStr) &&
                                                        (stripos($key, 'video') !== false || stripos($key, 'movie') !== false || 
                                                         stripos($key, 'media') !== false)) {
                                                    $isMediaColumn = true;
                                                    $videoUrl = $valStr;
                                                    if (!preg_match('/^https?:\/\//', $valStr)) {
                                                        $videoUrl = (strpos($valStr, '/') === 0) ? $valStr : '/' . $valStr;
                                                    }
                                                    $displayVal = '<div style="display:flex; align-items:center; gap:8px;">'
                                                        . '<div style="position:relative; width:80px; height:60px; background:#1a1a1a; border-radius:4px; overflow:hidden;">'
                                                        . '<video style="width:100%; height:100%; object-fit:cover; cursor:pointer;" onclick="showVideoModal(this.querySelector(\'source\').src)" title="Click to play" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';" muted>'
                                                        . '<source src="' . htmlspecialchars($videoUrl) . '">'
                                                        . '</video>'
                                                        . '<div style="display:none; width:100%; height:100%; align-items:center; justify-content:center; color:#666; font-size:0.7em; text-align:center; padding:5px;">No Video</div>'
                                                        . '</div>'
                                                        . '<span style="font-size:0.85em; color:var(--text-secondary); word-break:break-all; max-width:200px; overflow:hidden; text-overflow:ellipsis;" title="' . htmlspecialchars($valStr) . '">' . htmlspecialchars(basename($valStr)) . '</span>'
                                                        . '</div>';
                                                }
                                            }
                                            
                                            // Clickable Foreign Keys Logic (only if not media)
                                            if (!$isMediaColumn && $val !== null && substr($key, -3) === '_id') {
                                                $targetTable = substr($key, 0, -3) . 's'; // simple pluralization
                                                $displayVal = "<a href='?table=$targetTable&view=data&search_col=id&search_op==&search_val=" . urlencode($val) . "' style='color:var(--accent); text-decoration:underline;'>$displayVal</a>";
                                            }
                                            ?><td data-col="<?=htmlspecialchars($key)?>" data-type="<?=htmlspecialchars($colTypes[$key] ?? '')?>" <?php if($primaryKey): ?>data-pk="<?=htmlspecialchars($row[$primaryKey])?>" ondblclick="makeCellEditable(this)" title="Double click to edit"<?php endif; ?>><?=$displayVal?></td><?php 
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
        background: 'var(--bg-card)',
        color: 'var(--text-primary)',
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
                                            <form method="POST" onsubmit='saConfirmForm(event, <?= json_encode('Drop column ' . $col['Field'] . '?') ?>)' style="display:inline;">
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
                             <form method="POST" onsubmit='saConfirmForm(event, <?= json_encode('TRUNCATE this table? All data will be lost!') ?>)' style="display:inline;">
                                <input type="hidden" name="action" value="truncate_table">
                                <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                                <button type="submit" class="btn btn-danger"><i class="fas fa-eraser"></i> Truncate</button>
                            </form>
                            <form method="POST" onsubmit='saConfirmForm(event, <?= json_encode('DROP this table? This cannot be undone!') ?>)' style="display:inline;">
                                <input type="hidden" name="action" value="delete_table">
                                <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Drop</button>
                            </form>
                        </div>
                    </div>

                    <!-- INDEXES SECTION -->
                    <?php
                        $indexes = [];
                        $isSqlMode = (($_SESSION['db_mode'] ?? 'sql') === 'sql');
                        if ($isSqlMode && $hasSelectedDatabase) :
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
                                                    <form method="POST" onsubmit='saConfirmForm(event, <?= json_encode('Drop index ' . $name . '?') ?>)' style="display:inline;">
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
                    <?php endif; ?>

                    <!-- FOREIGN KEYS SECTION -->
                    <?php
                        $fks = [];
                        if ($isSqlMode && $hasSelectedDatabase) :
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
                            try {
                                $stmt = $pdo->query("SHOW TABLES");
                                while ($r = $stmt->fetch(PDO::FETCH_NUM)) $allTables[] = $r[0];
                            } catch(Exception $e) {}
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
                                                    <form method="POST" onsubmit='saConfirmForm(event, <?= json_encode('Drop Foreign Key ' . $fk['CONSTRAINT_NAME'] . '?') ?>)' style="display:inline;">
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
                    <?php endif; ?>

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
                    <!-- SQL View Tabs -->
                    <div style="margin-bottom:20px; border-bottom:2px solid var(--border-color);">
                        <div style="display:flex; gap:5px;">
                            <button type="button" class="sql-tab-btn active" onclick="switchSqlTab('sql-editor')" style="padding:10px 20px; background:transparent; border:none; border-bottom:3px solid transparent; color:var(--text-secondary); cursor:pointer; transition:all 0.2s;">
                                <i class="fas fa-terminal"></i> SQL Editor
                            </button>
                            <button type="button" class="sql-tab-btn" onclick="switchSqlTab('query-builder')" style="padding:10px 20px; background:transparent; border:none; border-bottom:3px solid transparent; color:var(--text-secondary); cursor:pointer; transition:all 0.2s;">
                                <i class="fas fa-tools"></i> Query Builder
                            </button>
                            <button type="button" class="sql-tab-btn" onclick="switchSqlTab('advanced-filters')" style="padding:10px 20px; background:transparent; border:none; border-bottom:3px solid transparent; color:var(--text-secondary); cursor:pointer; transition:all 0.2s;">
                                <i class="fas fa-filter"></i> Advanced Filters
                            </button>
                        </div>
                    </div>
                    
                    <style>
                        .sql-tab-btn.active {
                            color: var(--accent) !important;
                            border-bottom-color: var(--accent) !important;
                        }
                        .sql-tab-btn:hover {
                            color: var(--text-primary) !important;
                        }
                        .sql-tab-content {
                            display: none;
                        }
                        .sql-tab-content.active {
                            display: block;
                        }
                    </style>
                    
                    <!-- SQL Editor Tab -->
                    <div id="sql-editor" class="sql-tab-content active">
                        <div class="card">
                            <h3><i class="fas fa-terminal"></i> SQL Command</h3>
                            <form method="POST" id="sqlForm">
                                <input type="hidden" name="action" value="sql_query">
                                <input type="hidden" name="table" value="<?=htmlspecialchars($currentTable)?>">
                                
                                <div style="display:flex; gap:15px; flex-wrap:wrap;">
                                    <div style="flex:1; min-width:300px;">
                                        <textarea name="query" id="queryInput" class="form-control" rows="12" style="font-family: 'Fira Code', monospace; background: #080808; border:1px solid #333; color:#a5d6ff; line-height:1.5; padding:15px; font-size:14px;" placeholder="SELECT * FROM `<?=htmlspecialchars($currentTable ?: 'table')?>` WHERE 1"><?=isset($_POST['query']) ? htmlspecialchars($_POST['query']) : ($currentTable ? "SELECT * FROM `$currentTable` LIMIT 50" : "SHOW TABLES")?></textarea>
                                        <div style="margin-top: 10px; display:flex; gap:10px; justify-content:flex-end;">
                                            <button type="button" class="btn" onclick="document.getElementById('queryInput').value=''">Clear</button>
                                            <button type="submit" class="btn btn-primary" style="padding-left:20px; padding-right:20px;"><i class="fas fa-play"></i> Execute</button>
                                        </div>
                                    </div>
                                    
                                    <div style="width: 280px; display: flex; flex-direction: column; border-left:1px solid var(--border-color); padding-left:15px;">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                            <span style="font-weight:bold; color:var(--text-secondary); text-transform:uppercase; font-size:0.75rem;">Query History</span>
                                            <button type="button" class="btn btn-danger" onclick="clearHistory()" style="padding:2px 6px; font-size:0.7rem;" title="Clear History"><i class="fas fa-trash"></i></button>
                                        </div>
                                        <div id="queryHistory" style="flex:1; background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; overflow-y:auto; font-size:0.8rem; max-height:300px;">
                                            <!-- JS populates this -->
                                        </div>
                                    </div>
                                </div>
                            </form>
                            
                            <script>
                                const historyKey = 'adminer_query_history_<?=md5($_SESSION['db_host'].$_SESSION['db_user'])?>';
                                const input = document.getElementById('queryInput');
                                const list = document.getElementById('queryHistory');
                                
                                function renderHistory() {
                                    let history = JSON.parse(localStorage.getItem(historyKey) || '[]');
                                    list.innerHTML = '';
                                    history.forEach(q => {
                                        let item = document.createElement('div');
                                        item.style.padding = '10px';
                                        item.style.borderBottom = '1px solid #333';
                                        item.style.cursor = 'pointer';
                                        item.style.whiteSpace = 'pre-wrap';
                                        item.style.maxHeight = '60px';
                                        item.style.overflow = 'hidden';
                                        item.style.textOverflow = 'ellipsis';
                                        item.style.fontFamily = 'monospace';
                                        item.style.color = '#ccc';
                                        item.style.transition = '0.2s';
                                        item.title = q;
                                        item.textContent = q;
                                        item.onmouseover = () => { item.style.background = '#333'; item.style.color = '#fff'; };
                                        item.onmouseout = () => { item.style.background = 'transparent'; item.style.color = '#ccc'; };
                                        item.onclick = () => { 
                                            input.value = q; 
                                            input.scrollTop = 0;
                                        };
                                        list.appendChild(item);
                                    });
                                    if(history.length === 0) list.innerHTML = '<div style="padding:15px; color:#666; text-align:center; font-style:italic;">No history yet.<br>Execute a query to save it.</div>';
                                }
                                                        
                            function clearHistory() {
                                Swal.fire({
                                    title: 'Clear History?',
                                    text: "All query history will be permanently deleted!",
                                    icon: 'warning',
                                    showCancelButton: true,
                                    confirmButtonText: 'Yes, Clear It',
                                    cancelButtonText: 'Cancel',
                                    confirmButtonColor: '#d33',
                                    cancelButtonColor: '#3085d6'
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        localStorage.removeItem(historyKey);
                                        renderHistory();

                                        Swal.fire(
                                            'Cleared!',
                                            'Query history has been deleted.',
                                            'success'
                                        );
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

                        <?php if(!empty($sqlResults)): ?>
                            <div style="margin-top:30px;">
                                <?php foreach($sqlResults as $i => $res): ?>
                                    <div style="margin-bottom:20px; border:1px solid var(--border-color); border-radius:6px; overflow:hidden;">
                                        <div style="background:var(--dark-gray); padding:10px 15px; border-bottom:1px solid #333; font-family:monospace; font-size:0.9rem; color:#a5d6ff;">
                                            <?=htmlspecialchars($res['query'])?>
                                        </div>
                                        <div class="table-wrapper" style="border:none; border-radius:0; max-height:400px;">
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <?php if(!empty($res['columns'])): foreach($res['columns'] as $col): ?>
                                                            <th><?=htmlspecialchars($col)?></th>
                                                        <?php endforeach; else: ?>
                                                            <th>Result</th>
                                                        <?php endif; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if(!empty($res['rows'])): foreach($res['rows'] as $row): ?>
                                                        <tr>
                                                            <?php foreach($row as $cell): ?>
                                                                <td><?= $cell === null ? '<span style="color:#666">NULL</span>' : htmlspecialchars($cell) ?></td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; else: ?>
                                                        <tr><td colspan="<?=count($res['columns']?:[1])?>" style="padding:15px; color:#888; text-align:center;">
                                                            <?php if(stripos($res['query'], 'SELECT') === 0 || stripos($res['query'], 'SHOW') === 0): ?>
                                                                Empty result set.
                                                            <?php else: ?>
                                                                Query executed successfully.
                                                            <?php endif; ?>
                                                        </td></tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Query Builder Tab -->
                    <div id="query-builder" class="sql-tab-content">
                        <div class="card">
                            <h3><i class="fas fa-tools"></i> Query Builder</h3>
                            <div id="query-builder-container"></div>
                        </div>
                    </div>
                    
                    <!-- Advanced Filters Tab -->
                    <div id="advanced-filters" class="sql-tab-content">
                        <div class="card">
                            <h3><i class="fas fa-filter"></i> Advanced Filters</h3>
                            <div id="advanced-filters-container"></div>
                        </div>
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

                <?php elseif ($view === 'maintenance'): ?>
                    <div class="card">
                        <h3><i class="fas fa-tools"></i> Maintenance Results</h3>
                        <?php 
                        $mRows = $_SESSION['maintenance_data'] ?? [];
                        unset($_SESSION['maintenance_data']);
                        if ($mRows): 
                        ?>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <?php foreach (array_keys($mRows[0]) as $k): ?>
                                            <th><?=htmlspecialchars($k)?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mRows as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $val): ?>
                                                <td><?=htmlspecialchars((string)$val)?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="alert alert-info">No results available.</div>
                        <?php endif; ?>
                        <div style="margin-top:15px;">
                            <a href="?" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                        </div>
                    </div>

                <?php elseif ($view === 'form'): 
                    ?>
                    <!-- EDIT/INSERT FORM -->
                    <?php
                        $formData = [];
                        $mode = $_GET['mode'] ?? '';
                        $isCopyMode = ($mode === 'copy');
                        
                        // Check database mode
                        if (($_SESSION['db_mode'] ?? 'sql') === 'json' && !empty($_SESSION['json_file'])) {
                            // JSON Mode
                            if (isset($_GET['pk']) && isset($_GET['val'])) {
                                $pkField = $_GET['pk'];
                                $pkValue = $_GET['val'];
                                $conditions = [$pkField => ['operator' => '=', 'value' => $pkValue]];
                                $results = $jsonDb->select($currentTable, $conditions);
                                if (!empty($results)) {
                                    $formData = $results[0];
                                }
                            }
                            if ($isCopyMode && isset($primaryKey) && $primaryKey && isset($formData[$primaryKey])) {
                                unset($formData[$primaryKey]);
                            }
                            $fks = []; // JSON mode doesn't support foreign keys
                        } else {
                            // SQL Mode
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
                        } // End SQL Mode
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
                                        $inputHtml = '<input type="text" name="data['.htmlspecialchars($field).']" class="form-control flatpickr-date" value="'.htmlspecialchars($val ?? '').'" placeholder="YYYY-MM-DD">';
                                    }
                                    // DATETIME / TIMESTAMP
                                    elseif (strpos($type, 'datetime') !== false || strpos($type, 'timestamp') !== false) {
                                        // Use full format for flatpickr but keep internal value for display
                                        $inputHtml = '<input type="text" name="data['.htmlspecialchars($field).']" class="form-control flatpickr-datetime" value="'.htmlspecialchars($val ?? '').'" placeholder="YYYY-MM-DD HH:MM:SS">';
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
                <div class="card">
                    <div style="display:flex; align-items:center; justify-content:space-between;">
                        <?php 
                        $dbMode = $_SESSION['db_mode'] ?? 'sql';
                        if ($dbMode === 'json') {
                            echo '<h3 style="margin:0;"><i class="fas fa-file-code"></i> Available JSON Files</h3>';
                        } elseif ($dbMode === 'sqlite') {
                            echo '<h3 style="margin:0;"><i class="fas fa-database"></i> Available SQLite Files</h3>';
                        } else {
                            echo '<h3 style="margin:0;"><i class="fas fa-server"></i> Server Databases</h3>';
                        }
                        ?>
                        <div>
                            <button type="button" class="btn" id="btnToggleServerDbs" onclick="toggleServerDbs()" title="Collapse/Expand"><i class="fas fa-chevron-up"></i></button>
                        </div>
                    </div>
                    <?php 
                    if ($dbMode === 'json') {
                        echo '<p style="color:var(--text-secondary); margin-bottom:15px;">JSON files in the json_db folder.</p>';
                    } elseif ($dbMode === 'sqlite') {
                        echo '<p style="color:var(--text-secondary); margin-bottom:15px;">SQLite database files in the sqlite_db folder.</p>';
                    } else {
                        echo '<p style="color:var(--text-secondary); margin-bottom:15px;">Databases actually existing on the connected server.</p>';
                    }
                    ?>
                    
                    <div id="serverDbsPanel">
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <?php 
                                    $dbMode = $_SESSION['db_mode'] ?? 'sql';
                                    if ($dbMode === 'json') {
                                        echo '<th>File Name</th>';
                                    } elseif ($dbMode === 'sqlite') {
                                        echo '<th>File Name</th>';
                                    } else {
                                        echo '<th>Database Name</th>';
                                    }
                                    ?>
                                    <th style="width:150px; text-align:right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $dbMode = $_SESSION['db_mode'] ?? 'sql';
                                
                                if ($dbMode === 'json') {
                                    // List JSON files
                                    $jsonFiles = $jsonDb->listFiles();
                                    $currentJsonFile = $_SESSION['json_file'] ?? '';
                                    
                                    if (empty($jsonFiles)) {
                                        echo "<tr><td colspan='2' style='text-align:center; color:var(--text-secondary);'>No JSON files found. Click 'New' to create one.</td></tr>";
                                    } else {
                                        foreach ($jsonFiles as $file):
                                            $isActive = ($file === $currentJsonFile);
                                        ?>
                                        <tr>
                                            <td><?=htmlspecialchars($file)?></td>
                                            <td style="text-align:right;">
                                                <?php if(!$isActive): ?>
                                                    <a href="?select_json_file=<?=urlencode($file)?>" class="btn btn-primary" style="padding:4px 8px; font-size:0.8rem;" title="Use File"><i class="fas fa-folder-open"></i></a>
                                                <?php else: ?>
                                                    <span style="font-size:0.75rem; background:var(--success); color:white; padding:4px 8px; border-radius:4px; margin-right:5px; display:inline-block;">Active</span>
                                                <?php endif; ?>
                                                <button type="button" onclick="deleteJsonFile('<?=htmlspecialchars($file)?>')" class="btn btn-danger" style="padding:4px 8px; font-size:0.8rem;" title="Delete File"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                        <?php endforeach;
                                    }
                                    
                                } elseif ($dbMode === 'sqlite') {
                                    // List SQLite files
                                    $sqliteFiles = glob(__DIR__ . '/sqlite_db/*.sqlite');
                                    $sqliteFiles = array_map('basename', $sqliteFiles);
                                    $currentSqliteFile = $_SESSION['sqlite_file'] ?? '';
                                    
                                    if (empty($sqliteFiles)) {
                                        echo "<tr><td colspan='2' style='text-align:center; color:var(--text-secondary);'>No SQLite files found. Click 'New' to create one.</td></tr>";
                                    } else {
                                        foreach ($sqliteFiles as $file):
                                            $isActive = ($file === $currentSqliteFile);
                                        ?>
                                        <tr>
                                            <td><?=htmlspecialchars($file)?></td>
                                            <td style="text-align:right;">
                                                <?php if(!$isActive): ?>
                                                    <a href="?select_sqlite_file=<?=urlencode($file)?>" class="btn btn-primary" style="padding:4px 8px; font-size:0.8rem;" title="Use File"><i class="fas fa-database"></i></a>
                                                <?php else: ?>
                                                    <span style="font-size:0.75rem; background:var(--success); color:white; padding:4px 8px; border-radius:4px; margin-right:5px; display:inline-block;">Active</span>
                                                <?php endif; ?>
                                                <button type="button" onclick="deleteSqliteFile('<?=htmlspecialchars($file)?>')" class="btn btn-danger" style="padding:4px 8px; font-size:0.8rem;" title="Delete File"><i class="fas fa-trash"></i></button>
                                            </td>
                                        </tr>
                                        <?php endforeach;
                                    }
                                    
                                } else {
                                    // SQL Mode - List databases
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
                                            <form method="POST" onsubmit='saConfirmForm(event, <?= json_encode('DROP DATABASE ' . $dbItem . '? THIS DESTROYS ALL DATA!') ?>)' style="display:inline;">
                                                <input type="hidden" name="action" value="drop_database_server">
                                                <input type="hidden" name="name" value="<?=htmlspecialchars($dbItem)?>">
                                                <button type="submit" class="btn btn-danger" style="padding:4px 8px; font-size:0.8rem;" title="Drop Database"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach;
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($dbMode === 'sql'): ?>
                    <form method="POST" style="margin-top:15px; display:flex; gap:10px; align-items:center;">
                        <input type="hidden" name="action" value="create_database_server">
                        <input type="text" name="name" class="form-control" placeholder="New Database Name" required pattern="[A-Za-z0-9_$-]+" style="max-width:300px;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Create Database</button>
                    </form>
                    <?php elseif ($dbMode === 'json'): ?>
                    <div style="margin-top:15px; display:flex; gap:10px;">
                        <button type="button" onclick="createNewJsonFile()" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Create New JSON File</button>
                        <button type="button" onclick="browseJsonFile()" class="btn" style="background:var(--accent);"><i class="fas fa-folder-open"></i> Browse External File</button>
                    </div>
                    <?php elseif ($dbMode === 'sqlite'): ?>
                    <div style="margin-top:15px; display:flex; gap:10px;">
                        <button type="button" onclick="createNewSqliteFile()" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Create New SQLite File</button>
                        <button type="button" onclick="browseSqliteFile()" class="btn" style="background:var(--accent);"><i class="fas fa-folder-open"></i> Browse External File</button>
                    </div>
                    <?php endif; ?>
                    </div>
                </div>
                
                <?php 
                // Only show Quick SQL for SQL and SQLite modes
                $dbMode = $_SESSION['db_mode'] ?? 'sql';
                if ($dbMode === 'sql' || $dbMode === 'sqlite'): 
                ?>
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
                <?php endif; // End Quick SQL mode check ?>

                <?php 
                // Only show Create New Table for SQL mode
                $dbMode = $_SESSION['db_mode'] ?? 'sql';
                if ($hasSelectedDatabase && !$currentTable && $dbMode === 'sql'): 
                ?>
                <div class="card">
                    <h3><i class="fas fa-plus-square"></i> Create New Table</h3>
                    <form method="POST" id="createTableForm" style="margin-top: 20px;">
                        <input type="hidden" name="action" value="create_table">
                        
                        <div class="table-wrapper" style="margin-bottom:20px;">
                            <table class="table-input">
                                <thead>
                                    <tr>
                                        <th>Table Name</th>
                                        <th style="width:150px">Engine</th>
                                        <th style="width:200px">Collation</th>
                                        <th>Comment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><input type="text" name="name" class="form-control" required placeholder="e.g. users"></td>
                                        <td>
                                            <select name="engine" class="form-select no-ts">
                                                <option value="InnoDB">InnoDB</option>
                                                <option value="MyISAM">MyISAM</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select name="collation" class="form-select no-ts">
                                                <option value="utf8mb4_general_ci">utf8mb4_general_ci</option>
                                                <option value="utf8mb4_unicode_ci">utf8mb4_unicode_ci</option>
                                                <option value="utf8_general_ci">utf8_general_ci</option>
                                            </select>
                                        </td>
                                        <td><input type="text" name="comments" class="form-control" placeholder="Optional"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="table-wrapper" style="margin-bottom:15px;">
                            <table class="table-input">
                                <thead>
                                    <tr>
                                        <th>Column Name</th>
                                        <th style="width:120px">Type</th>
                                        <th style="width:80px">Length</th>
                                        <th style="width:120px">Default</th>
                                        <th style="width:250px">Options</th>
                                        <th style="width:40px"></th>
                                    </tr>
                                </thead>
                                <tbody id="colList">
                                    <!-- Rows injected by JS -->
                                </tbody>
                            </table>
                        </div>
                        
                        <button type="button" class="btn" onclick="addColRow()"><i class="fas fa-plus"></i> Add Column</button>
                        <button type="submit" class="btn btn-primary" style="margin-left:10px;">Create Table</button>
                    </form>
                </div>
                <?php endif; ?>

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
                    <div style="position:relative;">
                        <button type="button" class="btn btn-sm" onclick="toggleFullscreenDiagram(this.nextElementSibling)" style="position:absolute; right:10px; top:10px; z-index:10; background:rgba(0,0,0,0.5); border:1px solid #444; color:#fff;"><i class="fas fa-expand"></i> Fullscreen</button>
                        <pre id="mermaid-graph" class="mermaid" style="background:#080808; border:1px solid var(--dark-gray); border-radius:6px; padding:15px; overflow:auto; max-height:500px;"><?= htmlspecialchars($relationshipDiagram) ?></pre>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($plantumlDiagramEncoded || $erdDiagram): ?>
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <h3 style="margin:0;">Schema ERD</h3>
                        <span style="font-size:0.85rem; color:var(--text-secondary);">PlantUML (primary) with Mermaid fallback</span>
                    </div>
                    <?php if ($plantumlDiagramEncoded): ?>
                        <div style="background:#080808; border:1px solid var(--dark-gray); border-radius:6px; padding:10px; text-align:center; position:relative;">
                            <button type="button" class="btn btn-sm" onclick="toggleFullscreenDiagram(this.nextElementSibling)" style="position:absolute; right:15px; top:15px; z-index:10; background:rgba(0,0,0,0.5); border:1px solid #444; color:#fff;"><i class="fas fa-expand"></i> Fullscreen</button>
                            <img src="https://www.plantuml.com/plantuml/svg/<?= htmlspecialchars($plantumlDiagramEncoded) ?>" alt="PlantUML ERD" class="erd-img" style="width:100%; max-height:600px; object-fit:contain; background:#fff;">
                        </div>
                        <?php if ($erdDiagram): ?>
                            <details style="margin-top:10px;">
                                <summary style="cursor:pointer; color:#0d6efd;">Show Mermaid fallback</summary>
                                <div style="position:relative;">
                                    <button type="button" class="btn btn-sm" onclick="toggleFullscreenDiagram(this.nextElementSibling)" style="position:absolute; right:10px; top:10px; z-index:10; background:rgba(0,0,0,0.5); border:1px solid #444; color:#fff;"><i class="fas fa-expand"></i> Fullscreen</button>
                                    <pre class="mermaid" style="background:#080808; border:1px solid var(--dark-gray); border-radius:6px; padding:15px; overflow:auto; max-height:600px;"><?= htmlspecialchars($erdDiagram) ?></pre>
                                </div>
            </details>
                        <?php endif; ?>
                    <?php elseif ($erdDiagram): ?>
                        <pre class="mermaid" style="background:#080808; border:1px solid var(--dark-gray); border-radius:6px; padding:15px; overflow:auto; max-height:600px;"><?= htmlspecialchars($erdDiagram) ?></pre>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3>Database Tables</h3>
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <button type="button" class="btn" onclick="copyAllTablesStructure()"><i class="fas fa-copy"></i> Copy All Structure</button>
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
                                <option value="drop">Drop</option>
                                <option value="truncate">Truncate</option>
                                <option value="optimize">Optimize</option>
                                <option value="analyze">Analyze</option>
                                <option value="check">Check</option>
                                <option value="repair">Repair</option>
                                <option value="export">Export</option>
                            </select>
                            <button type="button" class="btn btn-danger" onclick="confirmBulkTables()" style="display:flex; align-items:center; gap:6px;"><i class="fas fa-check"></i> Apply</button>
                            <span style="font-size:0.8rem; color:var(--text-secondary);">Select tables below to run bulk action.</span>
                        </div>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:40px;"><input type="checkbox" id="selectAllTables" onclick="(function(cb){document.querySelectorAll('.table-checkbox').forEach(function(ch){ch.checked = cb.checked}); try{updateSelectAllState()}catch(e){} })(this)"></th>
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
             /**
 * Visual Query Builder
 * Allows users to build SQL queries using a GUI
 */

class QueryBuilder {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.table = options.table || '';
        this.columns = options.columns || [];
        this.conditions = [];
        this.orderBy = [];
        this.limit = null;
        this.offset = 0;
        
        this.init();
    }
    
    init() {
        this.render();
        this.attachEvents();
    }
    
    render() {
        this.container.innerHTML = `
            <div class="query-builder">
                <div class="qb-section">
                    <h4><i class="fas fa-table"></i> SELECT Columns</h4>
                    <div id="qb-columns" class="qb-columns">
                        <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                            <input type="checkbox" id="qb-select-all" checked>
                            <span>Select All (*)</span>
                        </label>
                        <div id="qb-column-list" style="display:none; max-height:200px; overflow-y:auto; padding-left:24px;">
                            ${this.columns.map(col => `
                                <label style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                    <input type="checkbox" class="qb-column-check" value="${col}" checked>
                                    <span>${col}</span>
                                </label>
                            `).join('')}
                        </div>
                    </div>
                </div>
                
                <div class="qb-section">
                    <h4><i class="fas fa-filter"></i> WHERE Conditions</h4>
                    <div id="qb-conditions"></div>
                    <button type="button" class="btn btn-sm" onclick="queryBuilder.addCondition()">
                        <i class="fas fa-plus"></i> Add Condition
                    </button>
                </div>
                
                <div class="qb-section">
                    <h4><i class="fas fa-sort"></i> ORDER BY</h4>
                    <div id="qb-orderby"></div>
                    <button type="button" class="btn btn-sm" onclick="queryBuilder.addOrderBy()">
                        <i class="fas fa-plus"></i> Add Order
                    </button>
                </div>
                
                <div class="qb-section">
                    <h4><i class="fas fa-list"></i> LIMIT & OFFSET</h4>
                    <div style="display:flex; gap:15px; align-items:center;">
                        <label style="display:flex; align-items:center; gap:8px;">
                            <span>LIMIT:</span>
                            <input type="number" id="qb-limit" class="form-control" style="width:100px;" placeholder="No limit" min="1">
                        </label>
                        <label style="display:flex; align-items:center; gap:8px;">
                            <span>OFFSET:</span>
                            <input type="number" id="qb-offset" class="form-control" style="width:100px;" value="0" min="0">
                        </label>
                    </div>
                </div>
                
                <div class="qb-section">
                    <h4><i class="fas fa-code"></i> Generated Query</h4>
                    <textarea id="qb-generated-query" class="form-control" rows="6" readonly style="font-family:monospace; background:#080808; color:#a5d6ff; border:1px solid #333;"></textarea>
                    <div style="margin-top:10px; display:flex; gap:10px;">
                        <button type="button" class="btn btn-primary" onclick="queryBuilder.executeQuery()">
                            <i class="fas fa-play"></i> Execute Query
                        </button>
                        <button type="button" class="btn" onclick="queryBuilder.copyQuery()">
                            <i class="fas fa-copy"></i> Copy Query
                        </button>
                        <button type="button" class="btn" onclick="queryBuilder.reset()">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        this.updateQuery();
    }
    
    attachEvents() {
        // Select all checkbox
        const selectAll = document.getElementById('qb-select-all');
        const columnList = document.getElementById('qb-column-list');
        
        if (selectAll) {
            selectAll.addEventListener('change', (e) => {
                if (e.target.checked) {
                    columnList.style.display = 'none';
                    document.querySelectorAll('.qb-column-check').forEach(cb => cb.checked = true);
                } else {
                    columnList.style.display = 'block';
                }
                this.updateQuery();
            });
        }
        
        // Column checkboxes
        document.querySelectorAll('.qb-column-check').forEach(cb => {
            cb.addEventListener('change', () => this.updateQuery());
        });
        
        // Limit and offset
        document.getElementById('qb-limit')?.addEventListener('input', () => this.updateQuery());
        document.getElementById('qb-offset')?.addEventListener('input', () => this.updateQuery());
    }
    
    addCondition() {
        const conditionsDiv = document.getElementById('qb-conditions');
        const index = this.conditions.length;
        
        const conditionHtml = `
            <div class="qb-condition" data-index="${index}" style="display:flex; gap:10px; margin-bottom:10px; align-items:center; flex-wrap:wrap;">
                ${index > 0 ? `
                    <select class="form-select qb-logic" style="width:80px;">
                        <option value="AND">AND</option>
                        <option value="OR">OR</option>
                    </select>
                ` : ''}
                <select class="form-select qb-column" style="width:150px;">
                    <option value="">-- Column --</option>
                    ${this.columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                </select>
                <select class="form-select qb-operator" style="width:120px;">
                    <option value="=">=</option>
                    <option value="!=">!=</option>
                    <option value=">">></option>
                    <option value="<"><</option>
                    <option value=">=">>=</option>
                    <option value="<="><=</option>
                    <option value="LIKE">LIKE</option>
                    <option value="NOT LIKE">NOT LIKE</option>
                    <option value="IN">IN</option>
                    <option value="NOT IN">NOT IN</option>
                    <option value="IS NULL">IS NULL</option>
                    <option value="IS NOT NULL">IS NOT NULL</option>
                </select>
                <input type="text" class="form-control qb-value" placeholder="Value" style="flex:1; min-width:150px;">
                <button type="button" class="btn btn-danger btn-sm" onclick="queryBuilder.removeCondition(${index})">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        conditionsDiv.insertAdjacentHTML('beforeend', conditionHtml);
        
        // Attach change events
        const condition = conditionsDiv.lastElementChild;
        condition.querySelectorAll('select, input').forEach(el => {
            el.addEventListener('change', () => this.updateQuery());
            el.addEventListener('input', () => this.updateQuery());
        });
        
        // Handle operator change (hide value input for IS NULL/IS NOT NULL)
        condition.querySelector('.qb-operator').addEventListener('change', (e) => {
            const valueInput = condition.querySelector('.qb-value');
            if (e.target.value === 'IS NULL' || e.target.value === 'IS NOT NULL') {
                valueInput.style.display = 'none';
                valueInput.value = '';
            } else {
                valueInput.style.display = 'block';
            }
            this.updateQuery();
        });
        
        this.conditions.push({});
        this.updateQuery();
    }
    
    removeCondition(index) {
        const condition = document.querySelector(`.qb-condition[data-index="${index}"]`);
        if (condition) {
            condition.remove();
            this.conditions.splice(index, 1);
            this.updateQuery();
        }
    }
    
    addOrderBy() {
        const orderByDiv = document.getElementById('qb-orderby');
        const index = this.orderBy.length;
        
        const orderHtml = `
            <div class="qb-order" data-index="${index}" style="display:flex; gap:10px; margin-bottom:10px; align-items:center;">
                <select class="form-select qb-order-column" style="width:200px;">
                    <option value="">-- Column --</option>
                    ${this.columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                </select>
                <select class="form-select qb-order-dir" style="width:100px;">
                    <option value="ASC">ASC</option>
                    <option value="DESC">DESC</option>
                </select>
                <button type="button" class="btn btn-danger btn-sm" onclick="queryBuilder.removeOrderBy(${index})">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        orderByDiv.insertAdjacentHTML('beforeend', orderHtml);
        
        // Attach change events
        const order = orderByDiv.lastElementChild;
        order.querySelectorAll('select').forEach(el => {
            el.addEventListener('change', () => this.updateQuery());
        });
        
        this.orderBy.push({});
        this.updateQuery();
    }
    
    removeOrderBy(index) {
        const order = document.querySelector(`.qb-order[data-index="${index}"]`);
        if (order) {
            order.remove();
            this.orderBy.splice(index, 1);
            this.updateQuery();
        }
    }
    
    updateQuery() {
        const query = this.buildQuery();
        document.getElementById('qb-generated-query').value = query;
    }
    
    buildQuery() {
        // SELECT clause
        let query = 'SELECT ';
        
        const selectAll = document.getElementById('qb-select-all')?.checked;
        if (selectAll) {
            query += '*';
        } else {
            const selectedColumns = Array.from(document.querySelectorAll('.qb-column-check:checked'))
                .map(cb => `\`${cb.value}\``);
            query += selectedColumns.length > 0 ? selectedColumns.join(', ') : '*';
        }
        
        query += `\nFROM \`${this.table}\``;
        
        // WHERE clause
        const conditions = [];
        document.querySelectorAll('.qb-condition').forEach((condDiv, idx) => {
            const logic = condDiv.querySelector('.qb-logic')?.value || '';
            const column = condDiv.querySelector('.qb-column')?.value;
            const operator = condDiv.querySelector('.qb-operator')?.value;
            const value = condDiv.querySelector('.qb-value')?.value;
            
            if (column && operator) {
                let condStr = idx > 0 ? `${logic} ` : '';
                condStr += `\`${column}\` ${operator}`;
                
                if (operator !== 'IS NULL' && operator !== 'IS NOT NULL') {
                    if (operator === 'IN' || operator === 'NOT IN') {
                        condStr += ` (${value})`;
                    } else if (operator === 'LIKE' || operator === 'NOT LIKE') {
                        condStr += ` '${value.replace(/'/g, "''")}'`;
                    } else {
                        // Try to detect if value is numeric
                        condStr += isNaN(value) ? ` '${value.replace(/'/g, "''")}'` : ` ${value}`;
                    }
                }
                
                conditions.push(condStr);
            }
        });
        
        if (conditions.length > 0) {
            query += '\nWHERE ' + conditions.join('\n  ');
        }
        
        // ORDER BY clause
        const orders = [];
        document.querySelectorAll('.qb-order').forEach(orderDiv => {
            const column = orderDiv.querySelector('.qb-order-column')?.value;
            const dir = orderDiv.querySelector('.qb-order-dir')?.value;
            
            if (column) {
                orders.push(`\`${column}\` ${dir}`);
            }
        });
        
        if (orders.length > 0) {
            query += '\nORDER BY ' + orders.join(', ');
        }
        
        // LIMIT clause
        const limit = document.getElementById('qb-limit')?.value;
        const offset = document.getElementById('qb-offset')?.value;
        
        if (limit) {
            query += `\nLIMIT ${limit}`;
            if (offset && offset > 0) {
                query += ` OFFSET ${offset}`;
            }
        }
        
        return query + ';';
    }
    
    executeQuery() {
        const query = document.getElementById('qb-generated-query').value;
        
        // Submit to SQL form
        const sqlForm = document.getElementById('sqlForm');
        if (sqlForm) {
            const queryInput = sqlForm.querySelector('textarea[name="query"]');
            if (queryInput) {
                queryInput.value = query;
                sqlForm.submit();
            }
        } else {
            // Alternative: create and submit form
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="sql_query">
                <textarea name="query" style="display:none;">${query}</textarea>
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    copyQuery() {
        const query = document.getElementById('qb-generated-query').value;
        navigator.clipboard.writeText(query).then(() => {
            Swal.fire({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1500,
                icon: 'success',
                title: 'Query copied!'
            });
        });
    }
    
    reset() {
        this.conditions = [];
        this.orderBy = [];
        this.render();
    }
}

// Global instance
let queryBuilder = null;

                    </script>

    <script>
        (function() {
            if (typeof window.confirmBulkTables === 'function') {
                return;
            }

            function updateSelectAllState() {
                const selectAll = document.getElementById('selectAllTables');
                if (!selectAll) return;
                const checkboxes = Array.from(document.querySelectorAll('.table-checkbox'));
                const checked = checkboxes.filter(cb => cb.checked).length;
                if (!checkboxes.length || checked === 0) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                } else if (checked === checkboxes.length) {
                    selectAll.checked = true;
                    selectAll.indeterminate = false;
                } else {
                    selectAll.checked = false;
                    selectAll.indeterminate = true;
                }
            }

            function getSelectedTablesCount() {
                return Array.from(document.querySelectorAll('.table-checkbox')).filter(cb => cb.checked).length;
            }

            document.addEventListener('DOMContentLoaded', function() {
                const selectAll = document.getElementById('selectAllTables');
                if (selectAll) {
                    selectAll.addEventListener('change', function() {
                        document.querySelectorAll('.table-checkbox').forEach(cb => {
                            cb.checked = selectAll.checked;
                        });
                        updateSelectAllState();
                    });
                }
                document.querySelectorAll('.table-checkbox').forEach(cb => {
                    cb.addEventListener('change', updateSelectAllState);
                });
                updateSelectAllState();
            });

            window.confirmBulkTables = function confirmBulkTables() {
                const form = document.getElementById('bulkTablesForm');
                if (!form) return;
                const actionField = form.querySelector('select[name="bulk_operation"]');
                const action = actionField ? actionField.value : '';
                const selectedCount = getSelectedTablesCount();

                if (!action) {
                    Swal.fire('Missing', 'Pilih aksi bulk terlebih dahulu.', 'info');
                    return;
                }
                if (selectedCount === 0) {
                    Swal.fire('No tables', 'Pilih minimal satu tabel.', 'info');
                    return;
                }

                const actionLabelMap = {
                    drop: 'Drop',
                    truncate: 'Truncate',
                    optimize: 'Optimize',
                    export: 'Export',
                    analyze: 'Analyze',
                    check: 'Check',
                    repair: 'Repair'
                };
                const label = actionLabelMap[action] || action;
                const isDestructive = action === 'drop' || action === 'truncate';

                Swal.fire({
                    title: `Confirm ${label}?`,
                    text: `Action will run on ${selectedCount} table(s).`,
                    icon: isDestructive ? 'warning' : 'question',
                    showCancelButton: true,
                    confirmButtonColor: isDestructive ? '#d33' : '#3085d6',
                    cancelButtonColor: '#666',
                    confirmButtonText: 'Yes, run it'
                }).then(result => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            };
        })();

        // ===== GLOBAL FUNCTIONS (MUST BE AVAILABLE ON ALL PAGES) =====
        
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
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, do it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        }

        // --- INLINE EDITING ---
        function makeCellEditable(td) {
            if (td.querySelector('input')) return; // Already editing
            
            const originalContent = td.innerText;
            const originalHtml = td.innerHTML;
            const pk = td.getAttribute('data-pk');
            const col = td.getAttribute('data-col');
            const table = td.closest('table').getAttribute('data-table');
            
            if(!pk || !col) return;

            td.classList.add('editing');
            td.innerHTML = `<input type="text" class="form-control" style="min-width:100px; padding:2px 5px; height:auto;" value="${originalContent.replace(/"/g, '&quot;')}" onblur="saveCellData(this, '${table}', '${col}', '${pk}', '${originalContent.replace(/'/g, "\\'")}')" onkeydown="if(event.key === 'Enter') this.blur()">`;
            td.querySelector('input').focus();
        }

        function saveCellData(input, table, col, pk, original) {
            const newVal = input.value;
            const td = input.parentElement;
            
            if (newVal === original) {
                td.innerText = original;
                td.classList.remove('editing');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_cell');
            formData.append('table', table);
            formData.append('column', col);
            formData.append('id', pk);
            formData.append('value', newVal);

            fetch('?', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    td.innerText = newVal;
                    td.style.backgroundColor = 'rgba(16, 185, 129, 0.2)';
                    setTimeout(() => td.style.backgroundColor = '', 1000);
                    const toast = Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 1500});
                    toast.fire({ icon: 'success', title: 'Saved' });
                } else {
                    td.innerHTML = original;
                    Swal.fire('Error', data.message || 'Update failed', 'error');
                }
            })
            .catch(err => {
                td.innerHTML = original;
                Swal.fire('Error', 'Network error', 'error');
            })
            .finally(() => {
                td.classList.remove('editing');
            });
        }

        // Helper to init TomSelect with correct settings
        function initTomSelect(el) {
            if (el.tomselect) return; // Prevent double initialization
            if (el.closest('.card') && !el.closest('#bulkTablesForm')) {
                new TomSelect(el, {
                    plugins: ['clear_button'],
                    maxOptions: 50,
                    sortField: { field: "text", direction: "asc" },
                    dropdownParent: 'body',
                    onDropdownOpen: function() {
                        const wrapper = this.dropdown;
                        if(wrapper) wrapper.style.zIndex = "99999";
                    }
                });
            }
        }

        // Initialize TomSelect for Searchable Dropdowns (Global)
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('form select.form-select').forEach((el) => {
                initTomSelect(el);
            });
        });

        // Bulk Actions
        function updateBulkBtn() {
            const checked = document.querySelectorAll('.row-checkbox:checked').length > 0;
            const select = document.getElementById('bulkActionSelect');
            const btn = document.getElementById('bulkApplyBtn');
            if(select && btn) {
                select.style.display = checked ? 'block' : 'none';
                btn.style.display = checked ? 'block' : 'none';
            }
        }

        function toggleSelectAll(source) {
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                cb.checked = source.checked;
            });
            updateBulkBtn();
        }

        function submitBulkAction() {
            const select = document.getElementById('bulkActionSelect');
            const form = document.getElementById('bulkForm');
            const action = select.value;
            
            if (!action) return;
            
            if (action === 'delete') {
                form.querySelector('input[name="action"]').value = 'bulk_delete';
                Swal.fire({
                    title: 'Delete selected rows?',
                    text: "You won't be able to revert this!",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    confirmButtonText: 'Yes, delete!'
                }).then((result) => {
                    if (result.isConfirmed) form.submit();
                });
            } else {
                form.querySelector('input[name="action"]').value = action;
                form.submit();
            }
        }

        // Media Modal Functions
        function showImageModal(src) {
            Swal.fire({
                imageUrl: src,
                imageAlt: 'Image Preview',
                showCloseButton: true,
                showConfirmButton: false,
                width: 'auto',
                background: 'var(--bg-card)',
                customClass: {
                    popup: 'dark-modal',
                    image: 'swal-image-preview'
                }
            });
        }

        function showVideoModal(src) {
            Swal.fire({
                html: '<video controls autoplay style="max-width:100%; max-height:70vh; border-radius:8px;"><source src="' + src + '"></video>',
                showCloseButton: true,
                showConfirmButton: false,
                width: 'auto',
                background: 'var(--bg-card)',
                customClass: {
                    popup: 'dark-modal'
                }
            });
        }
        
    // --- DATABASE MODE TOGGLE (SQL/JSON/SQLITE) ---
    function switchDbMode(mode) {
        window.location.href = '?db_mode=' + mode;
    }

    // --- JSON FILE MANAGEMENT ---
    function createNewJsonFile() {
        Swal.fire({
            title: 'Create New JSON File',
            html: `
                <input type="text" id="jsonFileName" class="swal2-input" placeholder="Enter filename (e.g., mydata.json)" style="width: 80%;">
                <small style="display: block; margin-top: 10px; color: var(--text-secondary);">
                    File akan dibuat di folder json_db/
                </small>
            `,
            showCancelButton: true,
            confirmButtonText: 'Create',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                const fileName = document.getElementById('jsonFileName').value;
                if (!fileName) {
                    Swal.showValidationMessage('Please enter a filename');
                    return false;
                }
                if (!fileName.endsWith('.json')) {
                    Swal.showValidationMessage('Filename must end with .json');
                    return false;
                }
                return fileName;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'create_json_file');
                formData.append('filename', result.value);
                
                fetch('?', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: 'JSON file created!',
                            timer: 1500
                        }).then(() => {
                            window.location.href = '?select_json_file=' + encodeURIComponent(result.value);
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Failed to create file', 'error');
                    }
                })
                .catch(err => {
                    Swal.fire('Error', 'Network error', 'error');
                });
            }
        });
    }

    // --- SQLITE FILE MANAGEMENT ---
    function createNewSqliteFile() {
        Swal.fire({
            title: 'Create New SQLite Database',
            html: `
                <input type="text" id="sqliteFileName" class="swal2-input" placeholder="Enter filename (e.g., mydata.db)" style="width: 80%;">
                <small style="display: block; margin-top: 10px; color: var(--text-secondary);">
                    File akan dibuat di folder sqlite_db/
                </small>
            `,
            showCancelButton: true,
            confirmButtonText: 'Create',
            cancelButtonText: 'Cancel',
            preConfirm: () => {
                const fileName = document.getElementById('sqliteFileName').value;
                if (!fileName) {
                    Swal.showValidationMessage('Please enter a filename');
                    return false;
                }
                if (!fileName.endsWith('.db') && !fileName.endsWith('.sqlite')) {
                    Swal.showValidationMessage('Filename must end with .db or .sqlite');
                    return false;
                }
                return fileName;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'create_sqlite_file');
                formData.append('filename', result.value);
                
                fetch('?', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success',
                            text: 'SQLite database created!',
                            timer: 1500
                        }).then(() => {
                            window.location.href = '?select_sqlite_file=' + encodeURIComponent(result.value);
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Failed to create database', 'error');
                    }
                })
                .catch(err => {
                    Swal.fire('Error', 'Network error', 'error');
                });
            }
        });
    }

    function deleteSqliteFile(filename) {
        Swal.fire({
            title: 'Delete SQLite Database?',
            text: 'File: ' + filename + ' - This cannot be undone!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'delete_sqlite_file');
                formData.append('filename', filename);
                
                fetch('?', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'Database deleted successfully',
                            timer: 1500
                        }).then(() => {
                            window.location.href = '?db_mode=sqlite';
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Failed to delete database', 'error');
                    }
                })
                .catch(err => {
                    Swal.fire('Error', 'Network error', 'error');
                });
            }
        });
    }

    function browseSqliteFile() {
        // Similar to browseJsonFile but for SQLite
        Swal.fire({
            title: 'Browse SQLite Database',
            html: `
                <div style="text-align: left;">
                    <div style="margin-bottom: 10px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Current Path:</label>
                        <div style="display: flex; gap: 5px;">
                            <input type="text" id="browsePath" class="swal2-input" value="" placeholder="/" style="flex: 1; margin: 0;">
                            <button onclick="loadBrowsePathSqlite()" class="swal2-confirm swal2-styled" style="margin: 0; padding: 8px 15px;">Go</button>
                        </div>
                    </div>
                    <div id="browseContent" style="max-height: 400px; overflow-y: auto; border: 1px solid #444; border-radius: 4px; padding: 10px; background: rgba(0,0,0,0.2);">
                        <div style="text-align: center; padding: 20px; color: #888;">
                            <i class="fas fa-spinner fa-spin"></i> Loading...
                        </div>
                    </div>
                </div>
            `,
            width: '600px',
            showCancelButton: true,
            showConfirmButton: false,
            cancelButtonText: 'Close',
            didOpen: () => {
                loadBrowsePathSqlite('');
            }
        });
    }

    function loadBrowsePathSqlite(path) {
        if (path === undefined) {
            path = document.getElementById('browsePath').value;
        } else {
            document.getElementById('browsePath').value = path;
        }
        
        const formData = new FormData();
        formData.append('action', 'browse_sqlite_files');
        formData.append('path', path);
        
        fetch('?', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderBrowseContentSqlite(data.items, data.current_path);
            } else {
                document.getElementById('browseContent').innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #ff6b6b;">
                        <i class="fas fa-exclamation-triangle"></i> ${data.message || 'Error loading path'}
                    </div>
                `;
            }
        })
        .catch(err => {
            document.getElementById('browseContent').innerHTML = `
                <div style="text-align: center; padding: 20px; color: #ff6b6b;">
                    <i class="fas fa-exclamation-triangle"></i> Network error
                </div>
            `;
        });
    }

    function renderBrowseContentSqlite(items, currentPath) {
        let html = '';
        
        // Parent directory link
        if (currentPath !== '') {
            const parentPath = currentPath.split('/').slice(0, -1).join('/');
            html += `
                <div style="padding: 8px; margin-bottom: 5px; background: rgba(255,255,255,0.05); border-radius: 4px; cursor: pointer;" onclick="loadBrowsePathSqlite('${parentPath}')">
                    <i class="fas fa-level-up-alt" style="color: #888;"></i> <span style="color: #888;">..</span>
                </div>
            `;
        }
        
        // Folders
        items.folders.forEach(folder => {
            const fullPath = currentPath ? currentPath + '/' + folder : folder;
            html += `
                <div style="padding: 8px; margin-bottom: 5px; background: rgba(255,255,255,0.05); border-radius: 4px; cursor: pointer;" onclick="loadBrowsePathSqlite('${fullPath}')">
                    <i class="fas fa-folder" style="color: #ffd700;"></i> ${folder}
                </div>
            `;
        });
        
        // SQLite Files
        items.files.forEach(file => {
            const fullPath = currentPath ? currentPath + '/' + file : file;
            html += `
                <div style="padding: 8px; margin-bottom: 5px; background: rgba(255,255,255,0.05); border-radius: 4px; cursor: pointer; display: flex; justify-content: space-between; align-items: center;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'">
                    <div>
                        <i class="fas fa-database" style="color: #2196F3;"></i> ${file}
                    </div>
                    <button onclick="selectSqliteFile('${fullPath}'); event.stopPropagation();" class="swal2-confirm swal2-styled" style="margin: 0; padding: 4px 12px; font-size: 0.85rem;">
                        Select
                    </button>
                </div>
            `;
        });
        
        if (items.folders.length === 0 && items.files.length === 0) {
            html = `
                <div style="text-align: center; padding: 20px; color: #888;">
                    <i class="fas fa-folder-open"></i> Empty folder
                </div>
            `;
        }
        
        document.getElementById('browseContent').innerHTML = html;
    }

    function selectSqliteFile(filePath) {
        Swal.fire({
            title: 'Use this database?',
            text: filePath,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, use it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '?select_sqlite_file=' + encodeURIComponent(filePath) + '&external=1';
            }
        });
    }

    function deleteJsonFile(filename) {
        Swal.fire({
            title: 'Delete JSON File?',
            text: 'File: ' + filename + ' - This cannot be undone!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'delete_json_file');
                formData.append('filename', filename);
                
                fetch('?', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'File deleted successfully',
                            timer: 1500
                        }).then(() => {
                            window.location.href = '?db_mode=json';
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Failed to delete file', 'error');
                    }
                })
                .catch(err => {
                    Swal.fire('Error', 'Network error', 'error');
                });
            }
        });
    }

    // --- BROWSE JSON FILE FROM SERVER ---
    function browseJsonFile() {
        Swal.fire({
            title: 'Browse JSON File',
            html: `
                <div style="text-align: left;">
                    <div style="margin-bottom: 10px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Current Path:</label>
                        <div style="display: flex; gap: 5px;">
                            <input type="text" id="browsePath" class="swal2-input" value="" placeholder="/" style="flex: 1; margin: 0;">
                            <button onclick="loadBrowsePath()" class="swal2-confirm swal2-styled" style="margin: 0; padding: 8px 15px;">Go</button>
                        </div>
                    </div>
                    <div id="browseContent" style="max-height: 400px; overflow-y: auto; border: 1px solid #444; border-radius: 4px; padding: 10px; background: rgba(0,0,0,0.2);">
                        <div style="text-align: center; padding: 20px; color: #888;">
                            <i class="fas fa-spinner fa-spin"></i> Loading...
                        </div>
                    </div>
                </div>
            `,
            width: '600px',
            showCancelButton: true,
            showConfirmButton: false,
            cancelButtonText: 'Close',
            didOpen: () => {
                loadBrowsePath('');
            }
        });
    }

    function loadBrowsePath(path) {
        if (path === undefined) {
            path = document.getElementById('browsePath').value;
        } else {
            document.getElementById('browsePath').value = path;
        }
        
        const formData = new FormData();
        formData.append('action', 'browse_files');
        formData.append('path', path);
        
        fetch('?', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderBrowseContent(data.items, data.current_path);
            } else {
                document.getElementById('browseContent').innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #ff6b6b;">
                        <i class="fas fa-exclamation-triangle"></i> ${data.message || 'Error loading path'}
                    </div>
                `;
            }
        })
        .catch(err => {
            document.getElementById('browseContent').innerHTML = `
                <div style="text-align: center; padding: 20px; color: #ff6b6b;">
                    <i class="fas fa-exclamation-triangle"></i> Network error
                </div>
            `;
        });
    }

    function renderBrowseContent(items, currentPath) {
        let html = '';
        
        // Parent directory link
        if (currentPath !== '') {
            const parentPath = currentPath.split('/').slice(0, -1).join('/');
            html += `
                <div style="padding: 8px; margin-bottom: 5px; background: rgba(255,255,255,0.05); border-radius: 4px; cursor: pointer;" onclick="loadBrowsePath('${parentPath}')">
                    <i class="fas fa-level-up-alt" style="color: #888;"></i> <span style="color: #888;">..</span>
                </div>
            `;
        }
        
        // Folders
        items.folders.forEach(folder => {
            const fullPath = currentPath ? currentPath + '/' + folder : folder;
            html += `
                <div style="padding: 8px; margin-bottom: 5px; background: rgba(255,255,255,0.05); border-radius: 4px; cursor: pointer;" onclick="loadBrowsePath('${fullPath}')">
                    <i class="fas fa-folder" style="color: #ffd700;"></i> ${folder}
                </div>
            `;
        });
        
        // JSON Files
        items.files.forEach(file => {
            const fullPath = currentPath ? currentPath + '/' + file : file;
            html += `
                <div style="padding: 8px; margin-bottom: 5px; background: rgba(255,255,255,0.05); border-radius: 4px; cursor: pointer; display: flex; justify-content: space-between; align-items: center;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'">
                    <div>
                        <i class="fas fa-file-code" style="color: #4CAF50;"></i> ${file}
                    </div>
                    <button onclick="selectJsonFile('${fullPath}'); event.stopPropagation();" class="swal2-confirm swal2-styled" style="margin: 0; padding: 4px 12px; font-size: 0.85rem;">
                        Select
                    </button>
                </div>
            `;
        });
        
        if (items.folders.length === 0 && items.files.length === 0) {
            html = `
                <div style="text-align: center; padding: 20px; color: #888;">
                    <i class="fas fa-folder-open"></i> Empty folder
                </div>
            `;
        }
        
        document.getElementById('browseContent').innerHTML = html;
    }

    function selectJsonFile(filePath) {
        Swal.fire({
            title: 'Use this file?',
            text: filePath,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, use it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '?select_json_file=' + encodeURIComponent(filePath) + '&external=1';
            }
        });
    }
                    // Toggle Server Databases panel collapse/expand with state persisted in localStorage
                    function toggleServerDbs() {
                        const panel = document.getElementById('serverDbsPanel');
                        const btn = document.getElementById('btnToggleServerDbs');
                        if (!panel || !btn) return;
                        const isHidden = panel.style.display === 'none';
                        if (isHidden) {
                            panel.style.display = '';
                            btn.innerHTML = '<i class="fas fa-chevron-up"></i>';
                            localStorage.setItem('serverDbsCollapsed', '0');
                        } else {
                            panel.style.display = 'none';
                            btn.innerHTML = '<i class="fas fa-chevron-down"></i>';
                            localStorage.setItem('serverDbsCollapsed', '1');
                        }
                    }
                    document.addEventListener('DOMContentLoaded', function() {
                        const panel = document.getElementById('serverDbsPanel');
                        const btn = document.getElementById('btnToggleServerDbs');
                        if (panel && btn) {
                            const collapsed = localStorage.getItem('serverDbsCollapsed') === '1';
                            if (collapsed) {
                                panel.style.display = 'none';
                                btn.innerHTML = '<i class="fas fa-chevron-down"></i>';
                            } else {
                                btn.innerHTML = '<i class="fas fa-chevron-up"></i>';
                            }
                        }

                        // Initialize Query Builder & Advanced Filters if in SQL view
                        <?php if ($view === 'sql' && $currentTable && !empty($tableColumns)): ?>
                        if (document.getElementById('advanced-filters-container')) {
                            advancedFilters = new AdvancedFilters('advanced-filters-container', {
                                table: <?= json_encode($currentTable) ?>,
                                columns: <?= json_encode($tableColumns) ?>,
                                columnTypes: <?= json_encode($colTypesMap) ?>
                            });
                        }
                        
                        if (document.getElementById('query-builder-container')) {
                            queryBuilder = new QueryBuilder('query-builder-container', {
                                table: <?= json_encode($currentTable) ?>,
                                columns: <?= json_encode($tableColumns) ?>
                            });
                        }
                        <?php endif; ?>
                    });

                    function switchSqlTab(tabId) {
                        document.querySelectorAll('.sql-tab-content').forEach(el => el.classList.remove('active'));
                        document.querySelectorAll('.sql-tab-btn').forEach(el => el.classList.remove('active'));
                        
                        document.getElementById(tabId).classList.add('active');
                        const btn = document.querySelector(`button[onclick*="${tabId}"]`);
                        if(btn) btn.classList.add('active');
                    }
                    </script>
</body>
</html>
