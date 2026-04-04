<?php
// ============================================================
// FUNGSI PARSING SQL DUMP
// ============================================================

function splitByComma($str) {
    $parts = [];
    $depth = 0;
    $inString = false;
    $stringChar = '';
    $current = '';
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $ch = $str[$i];
        $prev = $i > 0 ? $str[$i - 1] : '';
        if ($inString) {
            $current .= $ch;
            if ($ch === $stringChar && $prev !== '\\') $inString = false;
            continue;
        }
        if ($ch === "'" || $ch === '"') {
            $inString = true;
            $stringChar = $ch;
            $current .= $ch;
            continue;
        }
        if ($ch === '(') $depth++;
        elseif ($ch === ')') $depth--;
        elseif ($ch === ',' && $depth === 0) {
            $parts[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    if (trim($current)) $parts[] = trim($current);
    return $parts;
}

function normalizeType($type) {
    $type = preg_replace('/\b(int|tinyint|smallint|mediumint|bigint)\(\d+\)/i', '$1', $type);
    $type = preg_replace('/\b(float|double)\(\d+,\d+\)/i', '$1', $type);
    return strtolower(trim($type));
}

function normalizeDefault($default, $type) {
    if ($default === null) return null;
    $default = trim($default);
    if (strtoupper($default) === 'NULL') return null;
    if (preg_match('/^current_timestamp\s*\(\s*\)$/i', $default)) return 'CURRENT_TIMESTAMP';
    if (preg_match('/^(int|tinyint|smallint|mediumint|bigint|float|double|decimal)/i', $type)) {
        $unquoted = trim($default, "'");
        if (is_numeric($unquoted)) return $unquoted;
    }
    return $default;
}

function parseColumn($def) {
    if (preg_match('/^\s*(PRIMARY\s+KEY|UNIQUE\s+(?:KEY|INDEX)?\s|KEY\s|INDEX\s|CONSTRAINT\s|FOREIGN\s+KEY)/i', $def)) {
        return null;
    }
    if (preg_match('/^`([^`]+)`/', $def, $m)) {
        $name = $m[1];
        $rest = substr($def, strlen($m[0]));
    } elseif (preg_match('/^(\w+)/', $def, $m)) {
        $name = $m[1];
        $rest = substr($def, strlen($m[0]));
    } else {
        return null;
    }
    $rest = trim($rest);

    if (preg_match('/^(\w+(?:\s*\([^)]*\))?)/i', $rest, $m)) {
        $rawType = trim($m[1]);
        $rest = trim(substr($rest, strlen($m[0])));
    } else return null;

    $normType = normalizeType($rawType);

    $unsigned = '';
    while (preg_match('/^(UNSIGNED|ZEROFILL)\s*/i', $rest, $m)) {
        $unsigned .= ' ' . strtoupper($m[1]);
        $rest = trim(substr($rest, strlen($m[0])));
    }
    $normType .= $unsigned;

    if (preg_match('/^(?:CHARACTER\s+SET|CHARSET)\s+\S+\s*/i', $rest, $m)) {
        $rest = trim(substr($rest, strlen($m[0])));
    }

    $collate = null;
    if (preg_match('/^COLLATE\s+(\S+)\s*/i', $rest, $m)) {
        $collate = $m[1];
        $rest = trim(substr($rest, strlen($m[0])));
    }

    $nullable = true;
    if (preg_match('/^NOT\s+NULL\s*/i', $rest, $m)) {
        $nullable = false;
        $rest = trim(substr($rest, strlen($m[0])));
    } elseif (preg_match('/^NULL\s*/i', $rest, $m)) {
        $nullable = true;
        $rest = trim(substr($rest, strlen($m[0])));
    }

    $default = null;
    $hasDefault = false;
    $onUpdate = '';
    if (preg_match('/^DEFAULT\s+/i', $rest)) {
        $rest = trim(substr($rest, 8));
        $hasDefault = true;
        if (preg_match('/^(CURRENT_TIMESTAMP(?:\s*\(\s*\))?)\s*(ON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\s*\(\s*\))?)?\s*(.*)$/is', $rest, $m)) {
            $default = 'CURRENT_TIMESTAMP';
            $rest = trim($m[3]);
            if (!empty($m[2])) $onUpdate = 'ON UPDATE CURRENT_TIMESTAMP';
        } elseif (preg_match('/^NULL\s*(.*)$/is', $rest, $m)) {
            $default = null;
            $rest = trim($m[1]);
        } elseif (preg_match("/^'((?:[^'\\\\]|\\\\.)*?)'\s*(.*)$/s", $rest, $m)) {
            $default = "'" . $m[1] . "'";
            $rest = trim($m[2]);
        } elseif (preg_match('/^(-?\d+(?:\.\d+)?)\s*(.*)$/s', $rest, $m)) {
            $default = $m[1];
            $rest = trim($m[2]);
        }
    }

    if (empty($onUpdate) && preg_match('/^ON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\s*\(\s*\))?\s*(.*)$/is', $rest, $m)) {
        $onUpdate = 'ON UPDATE CURRENT_TIMESTAMP';
        $rest = trim($m[1]);
    }

    $autoIncrement = false;
    if (preg_match('/^AUTO_INCREMENT\s*(.*)$/is', $rest, $m)) {
        $autoIncrement = true;
        $rest = trim($m[1]);
    }

    if (!$collate && preg_match('/^COLLATE\s+(\S+)\s*/i', $rest, $m)) {
        $collate = $m[1];
        $rest = trim(substr($rest, strlen($m[0])));
    }

    if (preg_match("/^COMMENT\s+'(?:[^'\\\\]|\\\\.)*'\s*(.*)$/is", $rest, $m)) {
        $rest = trim($m[1]);
    }

    $extra = '';
    if ($autoIncrement) $extra .= 'AUTO_INCREMENT';
    if ($onUpdate) $extra .= ($extra ? ' ' : '') . $onUpdate;

    return [
        'name' => $name, 'type' => $normType, 'nullable' => $nullable,
        'hasDefault' => $hasDefault, 'default' => normalizeDefault($default, $normType),
        'extra' => $extra, 'collate' => $collate,
    ];
}

function parseIndexColumns($str) {
    $cols = [];
    foreach (explode(',', $str) as $p) {
        $p = trim($p);
        if (preg_match('/`([^`]+)`/', $p, $m)) $cols[] = $m[1];
        elseif (preg_match('/(\w+)/', $p, $m)) $cols[] = $m[1];
    }
    return $cols;
}

function parseIndexOrConstraint($def) {
    $def = trim($def);
    if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]+)\)/i', $def, $m)) {
        return ['type' => 'PRIMARY KEY', 'name' => 'PRIMARY', 'columns' => parseIndexColumns($m[1])];
    }
    if (preg_match('/^UNIQUE\s+(?:KEY|INDEX)\s+`?(\w+)`?\s*\(([^)]+)\)/i', $def, $m)) {
        return ['type' => 'UNIQUE', 'name' => $m[1], 'columns' => parseIndexColumns($m[2])];
    }
    if (preg_match('/^(?:KEY|INDEX)\s+`?(\w+)`?\s*\(([^)]+)\)/i', $def, $m)) {
        return ['type' => 'INDEX', 'name' => $m[1], 'columns' => parseIndexColumns($m[2])];
    }
    if (preg_match('/^CONSTRAINT\s+`?(\w+)`?\s+FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+`?(\w+)`?\s*\(([^)]+)\)(?:\s+ON\s+DELETE\s+(CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION))?(?:\s+ON\s+UPDATE\s+(CASCADE|SET\s+NULL|RESTRICT|NO\s+ACTION))?/i', $def, $m)) {
        return [
            'type' => 'FOREIGN KEY', 'name' => $m[1],
            'columns' => parseIndexColumns($m[2]),
            'refTable' => $m[3], 'refColumns' => parseIndexColumns($m[4]),
            'onDelete' => strtoupper($m[5] ?? 'RESTRICT'),
            'onUpdate' => strtoupper($m[6] ?? 'RESTRICT'),
        ];
    }
    if (preg_match('/^FOREIGN\s+KEY\s*\(([^)]+)\)\s+REFERENCES\s+`?(\w+)`?\s*\(([^)]+)\)/i', $def, $m)) {
        return [
            'type' => 'FOREIGN KEY', 'name' => '',
            'columns' => parseIndexColumns($m[1]),
            'refTable' => $m[2], 'refColumns' => parseIndexColumns($m[3]),
            'onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT',
        ];
    }
    return null;
}

function parseSqlDump($sql) {
    $tables = [];
    if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(/is', $sql, $matches, PREG_OFFSET_CAPTURE)) {
        return $tables;
    }
    foreach ($matches[0] as $idx => $fullMatch) {
        $tableName = $matches[1][$idx][0];
        $startPos = $matches[0][$idx][1];
        $parenStart = strpos($sql, '(', $startPos);
        if ($parenStart === false) continue;

        $depth = 0;
        $parenEnd = false;
        for ($i = $parenStart; $i < strlen($sql); $i++) {
            if ($sql[$i] === '(') $depth++;
            elseif ($sql[$i] === ')') { $depth--; if ($depth === 0) { $parenEnd = $i; break; } }
        }
        if ($parenEnd === false) continue;

        $body = substr($sql, $parenStart + 1, $parenEnd - $parenStart - 1);
        $afterParen = substr($sql, $parenEnd + 1);
        preg_match('/ENGINE\s*=\s*(\w+).*?DEFAULT\s+CHARSET\s*=\s*(\w+)(?:\s+COLLATE\s*=\s*(\S+))?(?:\s*;|\s*$)/is', $afterParen, $optMatch);

        $table = [
            'name' => $tableName, 'columns' => [], 'indexes' => [], 'constraints' => [],
            'engine' => $optMatch[1] ?? 'InnoDB',
            'charset' => strtolower($optMatch[2] ?? 'utf8mb4'),
            'collate' => isset($optMatch[3]) ? strtolower($optMatch[3]) : null,
        ];

        foreach (splitByComma($body) as $part) {
            $part = trim($part);
            if (empty($part)) continue;
            $col = parseColumn($part);
            if ($col) { $table['columns'][] = $col; continue; }
            $idx = parseIndexOrConstraint($part);
            if ($idx) {
                if ($idx['type'] === 'FOREIGN KEY') $table['constraints'][] = $idx;
                else $table['indexes'][] = $idx;
            }
        }
        $tables[strtolower($tableName)] = $table;
    }
    return $tables;
}

// ============================================================
// FUNGSI PERBANDINGAN & GENERASI SQL
// ============================================================

function compareColumns($local, $prod) {
    if ($local['type'] !== $prod['type']) return 'type';
    if ($local['nullable'] !== $prod['nullable']) return 'nullable';
    $lEff = ($local['nullable'] && ($local['default'] === null || !$local['hasDefault'])) ? '__IMPLICIT_NULL__' : $local['default'];
    $pEff = ($prod['nullable'] && ($prod['default'] === null || !$prod['hasDefault'])) ? '__IMPLICIT_NULL__' : $prod['default'];
    if ($lEff !== $pEff) return 'default';
    if ($local['extra'] !== $prod['extra']) return 'extra';
    return null;
}

function buildColumnDef($col) {
    $def = "`{$col['name']}` {$col['type']}";
    if (!$col['nullable']) $def .= " NOT NULL";
    if ($col['hasDefault']) {
        $def .= ($col['default'] === null) ? " DEFAULT NULL" : " DEFAULT {$col['default']}";
    }
    if ($col['extra']) $def .= " {$col['extra']}";
    return $def;
}

function buildIndexDef($idx) {
    $cols = '`' . implode('`,`', $idx['columns']) . '`';
    if ($idx['type'] === 'PRIMARY KEY') return "ADD PRIMARY KEY ({$cols})";
    if ($idx['type'] === 'UNIQUE') return "ADD UNIQUE KEY `{$idx['name']}` ({$cols})";
    return "ADD KEY `{$idx['name']}` ({$cols})";
}

function buildFkDef($fk) {
    $cols = '`' . implode('`,`', $fk['columns']) . '`';
    $refCols = '`' . implode('`,`', $fk['refColumns']) . '`';
    $name = $fk['name'] ? "`{$fk['name']}` " : '';
    return "ADD CONSTRAINT {$name}FOREIGN KEY ({$cols}) REFERENCES `{$fk['refTable']}` ({$refCols}) ON DELETE {$fk['onDelete']} ON UPDATE {$fk['onUpdate']}";
}

function buildCreateTable($table) {
    $lines = [];
    foreach ($table['columns'] as $col) $lines[] = "  " . buildColumnDef($col);
    foreach ($table['indexes'] as $idx) {
        $cols = '`' . implode('`,`', $idx['columns']) . '`';
        if ($idx['type'] === 'PRIMARY KEY') $lines[] = "  PRIMARY KEY ({$cols})";
        elseif ($idx['type'] === 'UNIQUE') $lines[] = "  UNIQUE KEY `{$idx['name']}` ({$cols})";
        else $lines[] = "  KEY `{$idx['name']}` ({$cols})";
    }
    foreach ($table['constraints'] as $fk) $lines[] = "  " . buildFkDef($fk);
    $charset = $table['charset'] ?? 'utf8mb4';
    $collate = $table['collate'] ? " COLLATE={$table['collate']}" : '';
    $engine = $table['engine'] ?? 'InnoDB';
    return "CREATE TABLE IF NOT EXISTS `{$table['name']}` (\n" . implode(",\n", $lines) . "\n) ENGINE={$engine} DEFAULT CHARSET={$charset}{$collate};";
}

function generateMigration($local, $prod, &$summary) {
    $sql = [];
    $sql[] = "-- =============================================";
    $sql[] = "-- MIGRASI PRODUCTION -> LOCALHOST";
    $sql[] = "-- Generated: " . date('Y-m-d H:i:s');
    $sql[] = "-- =============================================";
    $sql[] = "";

    $newTableCount = 0; $newColCount = 0; $modColCount = 0;
    $dropColCount = 0; $newIdxCount = 0; $dropIdxCount = 0;
    $newFkCount = 0; $charsetChanges = 0; $onlyInProd = [];

    // 1. Tabel baru
    foreach ($local as $name => $lTable) {
        if (!isset($prod[$name])) {
            $sql[] = "-- [NEW TABLE] `{$lTable['name']}`";
            $sql[] = buildCreateTable($lTable);
            $sql[] = "";
            $newTableCount++;
        }
    }

    // 2. Bandingkan tabel yang ada di kedua sisi
    foreach ($local as $name => $lTable) {
        if (!isset($prod[$name])) continue;
        $pTable = $prod[$name];

        $pColumns = [];
        foreach ($pTable['columns'] as $col) $pColumns[strtolower($col['name'])] = $col;
        $lColNames = [];
        foreach ($lTable['columns'] as $col) $lColNames[strtolower($col['name'])] = true;

        $addParts = []; $modifyParts = []; $indexParts = []; $fkParts = [];
        $lastKnownCol = null;

        foreach ($lTable['columns'] as $lCol) {
            $colKey = strtolower($lCol['name']);
            if (!isset($pColumns[$colKey])) {
                $afterClause = $lastKnownCol ? " AFTER `{$lastKnownCol}`" : ' FIRST';
                $addParts[] = "  ADD COLUMN " . buildColumnDef($lCol) . $afterClause;
                $lastKnownCol = $lCol['name'];
                $newColCount++;
            } else {
                $diff = compareColumns($lCol, $pColumns[$colKey]);
                if ($diff !== null) {
                    $modifyParts[] = "  MODIFY COLUMN " . buildColumnDef($lCol);
                    $modColCount++;
                }
                $lastKnownCol = $lCol['name'];
            }
        }

        foreach ($pTable['columns'] as $pCol) {
            if (!isset($lColNames[strtolower($pCol['name'])])) $dropColCount++;
        }

        $pIdxMap = [];
        foreach ($pTable['indexes'] as $idx) $pIdxMap[strtolower($idx['type'] . ':' . $idx['name'] . ':' . implode(',', $idx['columns']))] = true;
        foreach ($lTable['indexes'] as $lIdx) {
            $key = strtolower($lIdx['type'] . ':' . $lIdx['name'] . ':' . implode(',', $lIdx['columns']));
            if (!isset($pIdxMap[$key])) { $indexParts[] = "  " . buildIndexDef($lIdx); $newIdxCount++; }
        }

        $lIdxMap = [];
        foreach ($lTable['indexes'] as $idx) $lIdxMap[strtolower($idx['type'] . ':' . $idx['name'] . ':' . implode(',', $idx['columns']))] = true;
        foreach ($pTable['indexes'] as $pIdx) {
            $key = strtolower($pIdx['type'] . ':' . $pIdx['name'] . ':' . implode(',', $pIdx['columns']));
            if (!isset($lIdxMap[$key])) $dropIdxCount++;
        }

        $pFkMap = [];
        foreach ($pTable['constraints'] as $fk) $pFkMap[strtolower($fk['name'])] = true;
        foreach ($lTable['constraints'] as $lFk) {
            if (!isset($pFkMap[strtolower($lFk['name'])])) { $fkParts[] = "  " . buildFkDef($lFk); $newFkCount++; }
        }

        if (strtolower($lTable['charset']) !== strtolower($pTable['charset']) ||
            strtolower($lTable['collate'] ?? '') !== strtolower($pTable['collate'] ?? '')) {
            $charsetChanges++;
        }

        $alterParts = array_merge($addParts, $modifyParts, $indexParts, $fkParts);
        if (!empty($alterParts)) {
            $sql[] = "-- [ALTER] `{$lTable['name']}`";
            $sql[] = "ALTER TABLE `{$lTable['name']}`";
            $sql[] = implode(",\n", $alterParts) . ";";
            $sql[] = "";
        }
    }

    // 3. Charset/Collate
    if ($charsetChanges > 0) {
        $sql[] = "-- =============================================";
        $sql[] = "-- PERUBAHAN CHARSET/COLLATE";
        $sql[] = "-- Opsional. Dapat memakan waktu pada tabel besar.";
        $sql[] = "-- =============================================";
        $sql[] = "";
        foreach ($local as $name => $lTable) {
            if (!isset($prod[$name])) continue;
            $pTable = $prod[$name];
            if (strtolower($lTable['charset']) !== strtolower($pTable['charset']) ||
                strtolower($lTable['collate'] ?? '') !== strtolower($pTable['collate'] ?? '')) {
                $c = $lTable['charset'];
                $co = $lTable['collate'] ? " COLLATE {$lTable['collate']}" : '';
                $sql[] = "ALTER TABLE `{$lTable['name']}` CONVERT TO CHARACTER SET {$c}{$co};";
            }
        }
        $sql[] = "";
    }

    // 4. Kolom hilang di local
    if ($dropColCount > 0) {
        $sql[] = "-- =============================================";
        $sql[] = "-- KOLOM HILANG DI LOCALHOST (ada di production)";
        $sql[] = "-- Review sebelum menjalankan DROP COLUMN";
        $sql[] = "-- =============================================";
        $sql[] = "";
        foreach ($local as $name => $lTable) {
            if (!isset($prod[$name])) continue;
            $pTable = $prod[$name];
            $lColNames = [];
            foreach ($lTable['columns'] as $c) $lColNames[strtolower($c['name'])] = true;
            foreach ($pTable['columns'] as $pCol) {
                if (!isset($lColNames[strtolower($pCol['name'])])) {
                    $sql[] = "-- `{$pTable['name']}`.`{$pCol['name']}` ({$pCol['type']})";
                    $sql[] = "-- ALTER TABLE `{$pTable['name']}` DROP COLUMN `{$pCol['name']}`;";
                }
            }
        }
        $sql[] = "";
    }

    // 5. Index hilang di local
    if ($dropIdxCount > 0) {
        $sql[] = "-- =============================================";
        $sql[] = "-- INDEX HILANG DI LOCALHOST (ada di production)";
        $sql[] = "-- =============================================";
        $sql[] = "";
        foreach ($local as $name => $lTable) {
            if (!isset($prod[$name])) continue;
            $pTable = $prod[$name];
            $lIdxMap = [];
            foreach ($lTable['indexes'] as $idx) $lIdxMap[strtolower($idx['type'] . ':' . $idx['name'] . ':' . implode(',', $idx['columns']))] = true;
            foreach ($pTable['indexes'] as $pIdx) {
                $key = strtolower($pIdx['type'] . ':' . $pIdx['name'] . ':' . implode(',', $pIdx['columns']));
                if (!isset($lIdxMap[$key])) {
                    $sql[] = "-- `{$pTable['name']}` {$pIdx['type']} `{$pIdx['name']}` (" . implode(', ', $pIdx['columns']) . ")";
                    $sql[] = ($pIdx['type'] === 'PRIMARY KEY')
                        ? "-- ALTER TABLE `{$pTable['name']}` DROP PRIMARY KEY;"
                        : "-- ALTER TABLE `{$pTable['name']}` DROP INDEX `{$pIdx['name']}`;";
                }
            }
        }
        $sql[] = "";
    }

    // 6. Tabel hanya di production
    foreach ($prod as $name => $pTable) {
        if (!isset($local[$name])) $onlyInProd[] = $pTable['name'];
    }
    if (!empty($onlyInProd)) {
        $sql[] = "-- =============================================";
        $sql[] = "-- TABEL HANYA ADA DI PRODUCTION";
        $sql[] = "-- Pertimbangkan apakah perlu dihapus";
        $sql[] = "-- =============================================";
        $sql[] = "";
        foreach ($onlyInProd as $tName) $sql[] = "-- DROP TABLE IF EXISTS `{$tName}`;";
        $sql[] = "";
    }

    $summary = [
        'newTables' => $newTableCount, 'newColumns' => $newColCount,
        'modColumns' => $modColCount, 'dropColumns' => $dropColCount,
        'newIndexes' => $newIdxCount, 'dropIndexes' => $dropIdxCount,
        'newFks' => $newFkCount, 'charsetChanges' => $charsetChanges,
        'onlyInProd' => count($onlyInProd),
    ];

    $hasChanges = $newTableCount + $newColCount + $modColCount + $newIdxCount + $newFkCount + $charsetChanges;
    if ($hasChanges === 0 && empty($onlyInProd) && $dropColCount === 0 && $dropIdxCount === 0) {
        return "__NONE__";
    }
    return implode("\n", $sql);
}

// ============================================================
// PROSES REQUEST
// ============================================================
 $result = '';
 $error = '';
 $summary = [
    'newTables' => 0, 'newColumns' => 0, 'modColumns' => 0,
    'dropColumns' => 0, 'newIndexes' => 0, 'dropIndexes' => 0,
    'newFks' => 0, 'charsetChanges' => 0, 'onlyInProd' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $localhost = trim($_POST['localhost'] ?? '');
    $production = trim($_POST['production'] ?? '');
    if (empty($localhost) || empty($production)) {
        $error = 'Kedua input struktur harus diisi.';
    } else {
        $localTables = parseSqlDump($localhost);
        $prodTables = parseSqlDump($production);
        if (empty($localTables) || empty($prodTables)) {
            $error = 'Gagal mem-parse SQL. Pastikan formatnya benar (CREATE TABLE ...).';
        } else {
            $result = generateMigration($localTables, $prodTables, $summary);
            if ($result === '__NONE__') $result = '-- Tidak ada perbedaan struktur yang terdeteksi.';
        }
    }
}

// ============================================================
// HIGHLIGHT SQL
// ============================================================
function highlightSql($text) {
    $text = htmlspecialchars($text);
    $text = preg_replace('/(#[^\n]*|--[^\n]*)/', '<span class="sql-cmt">$1</span>', $text);
    $text = preg_replace('/(`[^`]+`)/', '<span class="sql-str">$1</span>', $text);
    $text = preg_replace("/('[^']*')/", '<span class="sql-str">$1</span>', $text);
    $types = implode('|', [
        'int','bigint','tinyint','smallint','mediumint','unsigned',
        'varchar','char','text','mediumtext','longtext',
        'blob','mediumblob','longblob','decimal','float','double',
        'date','datetime','timestamp','time','year','enum','set','json','boolean','bool',
    ]);
    $text = preg_replace('/\b(' . $types . ')\b/i', '<span class="sql-type">$1</span>', $text);
    $keywords = implode('|', [
        'ALTER','TABLE','ADD','COLUMN','DROP','MODIFY','CHANGE',
        'CREATE','IF','NOT','EXISTS','INDEX','UNIQUE','KEY','PRIMARY','FOREIGN','REFERENCES',
        'CONSTRAINT','AFTER','FIRST','NULL','DEFAULT','AUTO_INCREMENT',
        'ON','UPDATE','DELETE','SET','CASCADE','RESTRICT','NO','ACTION',
        'ENGINE','CHARSET','COLLATE','CONVERT','CHARACTER','TO',
        'RENAME','INTO','VALUES','INSERT','SELECT','FROM','WHERE',
        'AND','OR','ORDER','BY','GROUP','HAVING','LIMIT','AS','JOIN',
        'LEFT','RIGHT','INNER','OUTER','CROSS','INNODB','MYISAM','ZEROFILL',
        'CURRENT_TIMESTAMP','NOW',
    ]);
    $text = preg_replace('/\b(' . $keywords . ')\b/i', '<span class="sql-kw">$1</span>', $text);
    $text = preg_replace('/\b(\d+)\b/', '<span class="sql-num">$1</span>', $text);
    return $text;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB Structure Compare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --bg:#09090b;--bg2:#111114;--bg3:#16161a;--bg4:#0e0e11;
            --bdr:#23232a;--bdr2:#3a3a44;
            --fg:#e4e4e7;--fg2:#6b6b76;--fg3:#44444f;
            --accent:#22c55e;--accentD:rgba(34,197,94,.1);--accentB:rgba(34,197,94,.25);
            --warn:#f59e0b;--warnD:rgba(245,158,11,.1);--warnB:rgba(245,158,11,.25);
            --danger:#ef4444;--dangerD:rgba(239,68,68,.1);
            --r:10px;--rs:6px;
            --ui:'Space Grotesk',sans-serif;--mono:'JetBrains Mono',monospace;
        }
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        html{font-size:15px;-webkit-font-smoothing:antialiased}
        body{font-family:var(--ui);background:var(--bg);color:var(--fg);min-height:100vh;line-height:1.6}
        body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 20% 10%,rgba(34,197,94,.03) 0%,transparent 60%),radial-gradient(ellipse 60% 50% at 80% 90%,rgba(34,197,94,.02) 0%,transparent 60%);pointer-events:none;z-index:0}
        .wrap{position:relative;z-index:1;max-width:1360px;margin:0 auto;padding:2rem 1.5rem 4rem}

        /* Header */
        header{text-align:center;margin-bottom:2rem;padding-top:.5rem}
        .logo{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:var(--rs);background:var(--accentD);border:1px solid var(--accentB);margin-bottom:.75rem;color:var(--accent);font-size:1.1rem}
        header h1{font-size:1.7rem;font-weight:700;letter-spacing:-.03em}
        header p{color:var(--fg2);font-size:.85rem;max-width:500px;margin:.25rem auto 0}
        .tagline{display:flex;align-items:center;justify-content:center;gap:.5rem;margin-top:.75rem;flex-wrap:wrap}
        .tag{font-size:.68rem;color:var(--fg3);display:flex;align-items:center;gap:.3rem}
        .tag i{font-size:.62rem}
        .tag .dot{width:5px;height:5px;border-radius:50%;background:var(--bdr)}

        /* Panels */
        .panels{display:grid;grid-template-columns:1fr 1fr;gap:.875rem;margin-bottom:1rem}
        @media(max-width:800px){.panels{grid-template-columns:1fr}}
        .panel{background:var(--bg3);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;display:flex;flex-direction:column;transition:border-color .2s}
        .panel:focus-within{border-color:var(--bdr2)}
        .ph{display:flex;align-items:center;justify-content:space-between;padding:.6rem .9rem;border-bottom:1px solid var(--bdr);background:var(--bg2)}
        .ph-label{display:flex;align-items:center;gap:.45rem;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--fg2)}
        .ph-label .d{width:7px;height:7px;border-radius:50%}
        .d-g{background:var(--accent)}.d-y{background:var(--warn)}
        .ph-hint{font-size:.65rem;color:var(--fg3)}
        textarea{flex:1;min-height:340px;width:100%;padding:.9rem;background:var(--bg4);border:none;outline:none;color:var(--fg);font-family:var(--mono);font-size:.75rem;line-height:1.7;resize:vertical;tab-size:2}
        textarea::placeholder{color:var(--fg3)}
        textarea::-webkit-scrollbar{width:5px}
        textarea::-webkit-scrollbar-track{background:transparent}
        textarea::-webkit-scrollbar-thumb{background:var(--bdr);border-radius:3px}

        /* Actions */
        .acts{display:flex;align-items:center;justify-content:center;gap:.6rem;margin-bottom:1.25rem;flex-wrap:wrap}
        .btn{display:inline-flex;align-items:center;gap:.45rem;padding:.6rem 1.3rem;border:1px solid var(--bdr);border-radius:var(--rs);font-family:var(--ui);font-size:.82rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;background:transparent;color:var(--fg2)}
        .btn:hover{color:var(--fg);border-color:var(--bdr2);background:var(--bg2)}
        .btn-p{background:var(--accent);color:#000;border-color:var(--accent)}
        .btn-p:hover{background:#16a34a;border-color:#16a34a;transform:translateY(-1px);box-shadow:0 4px 20px rgba(34,197,94,.2);color:#000}
        .btn-p:active{transform:translateY(0)}
        .btn-p:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}
        .btn-s{padding:.4rem .75rem;font-size:.72rem}

        /* Spinner */
        .spin{width:14px;height:14px;border:2px solid transparent;border-top-color:#000;border-radius:50%;animation:sp .6s linear infinite}
        @keyframes sp{to{transform:rotate(360deg)}}

        /* Error */
        .err{background:var(--dangerD);border:1px solid rgba(239,68,68,.2);border-radius:var(--rs);padding:.65rem .9rem;margin-bottom:1rem;color:#fca5a5;font-size:.8rem;display:flex;align-items:center;gap:.45rem}

        /* Summary Cards */
        .summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:.5rem;margin-bottom:1rem}
        .scard{background:var(--bg3);border:1px solid var(--bdr);border-radius:var(--rs);padding:.6rem .75rem;text-align:center;transition:border-color .2s}
        .scard:hover{border-color:var(--bdr2)}
        .scard-val{font-size:1.35rem;font-weight:700;font-family:var(--mono);line-height:1.2}
        .scard-label{font-size:.62rem;color:var(--fg3);text-transform:uppercase;letter-spacing:.05em;margin-top:.15rem}
        .v-green{color:var(--accent)}.v-blue{color:#60a5fa}.v-yellow{color:var(--warn)}.v-pink{color:#f472b6}.v-red{color:var(--danger)}

        /* Result */
        .result{background:var(--bg3);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;animation:fu .3s ease}
        @keyframes fu{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
        .rh{display:flex;align-items:center;justify-content:space-between;padding:.6rem .9rem;border-bottom:1px solid var(--bdr);background:var(--bg2);flex-wrap:wrap;gap:.4rem}
        .rh-label{display:flex;align-items:center;gap:.45rem;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--accent)}
        .rh-meta{display:flex;align-items:center;gap:.6rem}
        .rh-meta span{font-size:.65rem;color:var(--fg3)}
        .rb{padding:1rem;max-height:600px;overflow-y:auto}
        .rb::-webkit-scrollbar{width:5px}
        .rb::-webkit-scrollbar-track{background:transparent}
        .rb::-webkit-scrollbar-thumb{background:var(--bdr);border-radius:3px}
        .rb pre{font-family:var(--mono);font-size:.75rem;line-height:1.75;white-space:pre-wrap;word-break:break-word}

        /* SQL highlight */
        .sql-kw{color:#60a5fa;font-weight:500}
        .sql-type{color:#fbbf24}
        .sql-cmt{color:var(--fg3);font-style:italic}
        .sql-str{color:#34d399}
        .sql-num{color:#f472b6}

        /* Toast */
        .toast{position:fixed;bottom:2rem;left:50%;transform:translateX(-50%) translateY(20px);background:var(--bg3);border:1px solid var(--bdr);border-radius:var(--rs);padding:.55rem 1.1rem;font-size:.78rem;color:var(--accent);display:flex;align-items:center;gap:.4rem;opacity:0;transition:all .25s;pointer-events:none;z-index:999;box-shadow:0 8px 30px rgba(0,0,0,.4)}
        .toast.show{opacity:1;transform:translateX(-50%) translateY(0)}

        /* Badge */
        .badge{display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .5rem;border-radius:99px;font-size:.6rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
        .badge-green{background:var(--accentD);color:var(--accent);border:1px solid var(--accentB)}
        .badge-yellow{background:var(--warnD);color:var(--warn);border:1px solid var(--warnB)}

        footer{text-align:center;margin-top:2.5rem;padding-top:1.25rem;border-top:1px solid var(--bdr);font-size:.68rem;color:var(--fg3)}

        @media(max-width:600px){
            .wrap{padding:1.25rem 1rem 3rem}
            header h1{font-size:1.3rem}
            textarea{min-height:220px;font-size:.7rem}
            .summary{grid-template-columns:repeat(3,1fr)}
        }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="logo"><i class="fas fa-code-compare"></i></div>
        <h1>DB Structure Compare</h1>
        <p>Bandingkan struktur database localhost vs production, generate SQL migrasi secara instan.</p>
        <div class="tagline">
            <span class="tag"><i class="fas fa-microchip"></i> Pure PHP Parser</span>
            <span class="dot"></span>
            <span class="tag"><i class="fas fa-bolt"></i> Tanpa AI</span>
            <span class="dot"></span>
            <span class="tag"><i class="fas fa-lock"></i> 100% Lokal</span>
        </div>
    </header>

    <form method="POST" id="form">
        <div class="panels">
            <div class="panel">
                <div class="ph">
                    <div class="ph-label"><span class="d d-g"></span> Localhost</div>
                    <span class="ph-hint">Sumber kebenaran</span>
                </div>
                <textarea name="localhost" id="inL" placeholder="-- Paste SHOW CREATE TABLE atau mysqldump --no-data" spellcheck="false"><?php echo htmlspecialchars($_POST['localhost'] ?? ''); ?></textarea>
            </div>
            <div class="panel">
                <div class="ph">
                    <div class="ph-label"><span class="d d-y"></span> Production</div>
                    <span class="ph-hint">Yang akan diubah</span>
                </div>
                <textarea name="production" id="inP" placeholder="-- Paste struktur dari server production" spellcheck="false"><?php echo htmlspecialchars($_POST['production'] ?? ''); ?></textarea>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="err"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="acts">
            <button type="button" class="btn" onclick="loadSample()"><i class="fas fa-flask"></i> Contoh Data</button>
            <button type="button" class="btn" onclick="clearAll()"><i class="fas fa-eraser"></i> Bersihkan</button>
            <button type="submit" class="btn btn-p" id="btnGo"><i class="fas fa-arrows-rotate"></i> Compare & Generate SQL</button>
        </div>
    </form>

    <?php if ($result): ?>
        <div class="summary">
            <?php
            $items = [
                ['val' => $summary['newTables'], 'label' => 'Tabel Baru', 'cls' => 'v-green'],
                ['val' => $summary['newColumns'], 'label' => 'Kolom Baru', 'cls' => 'v-green'],
                ['val' => $summary['modColumns'], 'label' => 'Kolom Diubah', 'cls' => 'v-blue'],
                ['val' => $summary['dropColumns'], 'label' => 'Kolom Hilang', 'cls' => 'v-red'],
                ['val' => $summary['newIndexes'], 'label' => 'Index Baru', 'cls' => 'v-yellow'],
                ['val' => $summary['dropIndexes'], 'label' => 'Index Hilang', 'cls' => 'v-red'],
                ['val' => $summary['newFks'], 'label' => 'FK Baru', 'cls' => 'v-yellow'],
                ['val' => $summary['charsetChanges'], 'label' => 'Charset', 'cls' => 'v-pink'],
                ['val' => $summary['onlyInProd'], 'label' => 'Tabel Hanya Prod', 'cls' => 'v-red'],
            ];
            foreach ($items as $it):
            ?>
            <div class="scard">
                <div class="scard-val <?php echo $it['cls']; ?>"><?php echo $it['val']; ?></div>
                <div class="scard-label"><?php echo $it['label']; ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="result">
            <div class="rh">
                <div class="rh-label">
                    <i class="fas fa-terminal"></i> SQL Migrasi
                    <?php if ($summary['dropColumns'] > 0 || $summary['onlyInProd'] > 0): ?>
                        <span class="badge badge-yellow"><i class="fas fa-triangle-exclamation"></i> Review diperlukan</span>
                    <?php endif; ?>
                </div>
                <div class="rh-meta">
                    <span id="lc"></span>
                    <button class="btn btn-s" onclick="copyR()"><i class="fas fa-copy"></i> Salin</button>
                    <button class="btn btn-s" onclick="dlR()"><i class="fas fa-download"></i> .sql</button>
                </div>
            </div>
            <div class="rb">
                <pre id="rp"><?php echo highlightSql($result); ?></pre>
            </div>
        </div>
    <?php endif; ?>

    <footer>Pure PHP SQL Parser &mdash; Tidak ada data yang dikirim ke server eksternal &mdash; Selalu review SQL sebelum eksekusi di production</footer>
</div>

<div class="toast" id="toast"><i class="fas fa-check"></i><span id="tmsg"></span></div>

<script>
<?php if ($result): ?>
document.addEventListener('DOMContentLoaded',function(){
    var p=document.getElementById('rp');
    if(p){var n=p.innerText.split('\n').filter(function(l){return l.trim()}).length;document.getElementById('lc').textContent=n+' baris';}
});
<?php endif; ?>

function copyR(){
    var p=document.getElementById('rp');if(!p)return;
    var t=p.innerText;
    navigator.clipboard.writeText(t).then(function(){toast('Tersalin ke clipboard')}).catch(function(){
        var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();document.execCommand('copy');document.body.removeChild(a);toast('Tersalin ke clipboard');
    });
}
function dlR(){
    var p=document.getElementById('rp');if(!p)return;
    var b=new Blob([p.innerText],{type:'text/sql'}),u=URL.createObjectURL(b),a=document.createElement('a');
    a.href=u;a.download='migration_'+new Date().toISOString().slice(0,10)+'.sql';a.click();URL.revokeObjectURL(u);toast('File .sql diunduh');
}
function toast(m){var t=document.getElementById('toast');document.getElementById('tmsg').textContent=m;t.classList.add('show');setTimeout(function(){t.classList.remove('show')},2200)}

function loadSample(){
    document.getElementById('inL').value=
"DROP TABLE IF EXISTS `users`;\nCREATE TABLE `users` (\n  `id` int NOT NULL AUTO_INCREMENT,\n  `username` varchar(50) NOT NULL,\n  `email` varchar(100) DEFAULT NULL,\n  `phone` varchar(20) DEFAULT NULL,\n  `role` enum('admin','user') DEFAULT 'user',\n  `avatar` text DEFAULT NULL,\n  `is_active` tinyint(1) DEFAULT '1',\n  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,\n  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `username` (`username`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\nDROP TABLE IF EXISTS `audit_logs`;\nCREATE TABLE `audit_logs` (\n  `id` bigint NOT NULL AUTO_INCREMENT,\n  `user_id` int NOT NULL,\n  `action` varchar(100) NOT NULL,\n  `ip_address` varchar(45) DEFAULT NULL,\n  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,\n  PRIMARY KEY (`id`),\n  KEY `idx_user` (`user_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    document.getElementById('inP').value=
"DROP TABLE IF EXISTS `users`;\nCREATE TABLE `users` (\n  `id` int(11) NOT NULL AUTO_INCREMENT,\n  `username` varchar(50) NOT NULL,\n  `email` varchar(100) DEFAULT NULL,\n  `created_at` timestamp NULL DEFAULT current_timestamp(),\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `username` (`username`)\n) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;";
    toast('Contoh data dimuat');
}

function clearAll(){
    document.getElementById('inL').value='';
    document.getElementById('inP').value='';
    var r=document.querySelector('.result');if(r)r.remove();
    var s=document.querySelector('.summary');if(s)s.remove();
    var e=document.querySelector('.err');if(e)e.remove();
    toast('Form dibersihkan');
}

document.getElementById('form').addEventListener('submit',function(){
    var b=document.getElementById('btnGo');b.disabled=true;b.innerHTML='<div class="spin"></div> Memproses...';
});
</script>
</body>
</html>