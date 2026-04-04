<?php
/**
 * DB Structure Comparison Library
 * Extracted from compare_struct.php for integration into Adminer Lite
 */

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
    $sql[] = "-- MIGRASI PRODUCTION -> LOCALHOST";
    $sql[] = "-- Generated: " . date('Y-m-d H:i:s');
    $sql[] = "";

    $newTableCount = 0; $newColCount = 0; $modColCount = 0;
    $dropColCount = 0; $newIdxCount = 0; $dropIdxCount = 0;
    $newFkCount = 0; $charsetChanges = 0; $onlyInProd = [];

    foreach ($local as $name => $lTable) {
        if (!isset($prod[$name])) {
            $sql[] = "-- [NEW TABLE] `{$lTable['name']}`";
            $sql[] = buildCreateTable($lTable);
            $sql[] = "";
            $newTableCount++;
        }
    }

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
        
        // FKs
        $pFkMap = [];
        foreach ($pTable['constraints'] as $fk) $pFkMap[strtolower($fk['name'])] = true;
        foreach ($lTable['constraints'] as $lFk) {
            if (!isset($pFkMap[strtolower($lFk['name'])])) { $fkParts[] = "  " . buildFkDef($lFk); $newFkCount++; }
        }

        if (strtolower($lTable['charset'] ?? '') !== strtolower($pTable['charset'] ?? '') ||
            strtolower($lTable['collate'] ?? '') !== strtolower($pTable['collate'] ?? '')) {
            $charsetChanges++;
        }

        $alterParts = array_merge($addParts, $modifyParts, $indexParts, $fkParts);
        if (!empty($alterParts)) {
            $sql[] = "-- [ALTER] `{$lTable['name']}`";
            $sql[] = "ALTER TABLE `{$lTable['name']}`\n" . implode(",\n", $alterParts) . ";\n";
        }
    }

    $summary = [
        'newTables' => $newTableCount, 'newColumns' => $newColCount,
        'modColumns' => $modColCount, 'dropColumns' => $dropColCount,
        'newIndexes' => $newIdxCount, 'dropIndexes' => $dropIdxCount,
        'newFks' => $newFkCount, 'charsetChanges' => $charsetChanges,
        'onlyInProd' => count($onlyInProd),
    ];
    return count($sql) > 2 ? implode("\n", $sql) : "__NONE__";
}

function highlightSql($text) {
    $text = htmlspecialchars($text);
    $text = preg_replace('/(#[^\n]*|--[^\n]*)/', '<span class="sql-cmt">$1</span>', $text);
    $text = preg_replace('/(`[^`]+`)/', '<span class="sql-str">$1</span>', $text);
    $text = preg_replace("/('[^']*')/", '<span class="sql-str">$1</span>', $text);
    $keywords = '/\b(ALTER|TABLE|ADD|COLUMN|DROP|MODIFY|CREATE|IF|NOT|EXISTS|INDEX|UNIQUE|KEY|PRIMARY|FOREIGN|REFERENCES|CONSTRAINT|AFTER|FIRST|NULL|DEFAULT|AUTO_INCREMENT|ENGINE|CHARSET|COLLATE|CONVERT|CHARACTER|TO|INT|VARCHAR|TEXT|TIMESTAMP|DATETIME|DECIMAL|FLOAT)\b/i';
    $text = preg_replace($keywords, '<span class="sql-kw">$1</span>', $text);
    return $text;
}
