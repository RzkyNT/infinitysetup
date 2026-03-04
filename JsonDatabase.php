<?php
/**
 * JsonDatabase - A simple JSON-based database system
 * Provides SQL-like operations on JSON files
 */
class JsonDatabase {
    private $basePath;
    private $currentFile;
    private $data;
    
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
        $this->data = json_decode($content, true);
        if (!is_array($this->data)) {
            $this->data = [];
        }
    }
    
    /**
     * Save data to current JSON file
     */
    private function saveData() {
        if (!$this->currentFile) {
            throw new Exception('No file selected');
        }
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
     */
    public function listTables() {
        if (!is_array($this->data)) {
            return [];
        }
        return array_keys($this->data);
    }
    
    /**
     * Get table structure (columns from first row)
     */
    public function getTableStructure($table) {
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
     */
    public function select($table, $conditions = [], $orderBy = null, $orderDir = 'ASC', $limit = null, $offset = 0) {
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
     */
    public function insert($table, $data) {
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
     * UPDATE query
     */
    public function update($table, $data, $conditions) {
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
     */
    public function delete($table, $conditions) {
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
     */
    public function getPrimaryKey($table) {
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
