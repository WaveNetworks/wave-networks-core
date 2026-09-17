<?php
/**
 * schemaAuditFunctions.php — compare every database's LIVE schema against the
 * schema its migration files say it should have. STRICTLY READ-ONLY.
 *
 * WHY: the db_version ledger records that a migration file RAN, not that its
 * statements took. ContactSwipe shard 2.1 stamped the ledger while
 * cs_reminder.origin stayed ENUM('typed','cadence'), and shard 2.0 stamped it
 * without creating cs_import_batch. Nothing noticed until users hit errors.
 *
 * HOW:
 *   expected — replay the migration files (<= the ledger version) through a
 *              conservative parser: CREATE TABLE (columns, types, ENUM/SET
 *              values, indexes), ALTER TABLE ADD/MODIFY/CHANGE/DROP COLUMN,
 *              ADD/DROP INDEX, RENAME, DROP TABLE, CREATE/DROP INDEX.
 *              Anything it cannot parse is reported as 'unparsed' and the table
 *              it touches is marked uncertain — it never guesses.
 *   actual   — information_schema.TABLES / COLUMNS / STATISTICS of that DB.
 *   diff     — drift (missing tables/columns/indexes, narrower or different
 *              types, missing ENUM values) vs info (extra tables/columns, wider
 *              types). Findings on uncertain tables go to 'uncertain'.
 *
 * The statement splitter mirrors run_migration() exactly. Before core 5.0 the
 * runner used a substring skip: a statement whose text (comments included)
 * contained COMMIT / ROLLBACK / START TRANSACTION was silently skipped while the
 * ledger was still bumped. Those are reported as 'legacy_runner_skipped' (and a
 * drift row they explain carries legacy_runner_skipped: true). 'runner_skipped'
 * is what the CURRENT runner would skip beyond transaction control — always
 * expected to be empty.
 *
 * Scope: core main + core shards + every sibling child app (public_html/<dir>/
 * with db_migrations/) main + shards. Credentials are read from config the same
 * way dbSizeFunctions.php does and never leave this file.
 */

// ─── LEXING ─────────────────────────────────────────────────────────────────

/** Strip SQL comments (--, #, block) outside quotes. */
function schema_audit_strip_comments($sql) {
    $out = '';
    $n = strlen($sql);
    $q = null;
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q !== null) {
            $out .= $c;
            if ($c === '\\' && $q !== '`' && $i + 1 < $n) { $out .= $sql[++$i]; continue; }
            if ($c === $q) {
                if ($i + 1 < $n && $sql[$i + 1] === $q) { $out .= $sql[++$i]; continue; }
                $q = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $out .= $c; continue; }
        if ($c === '-' && $i + 1 < $n && $sql[$i + 1] === '-'
            && ($i + 2 >= $n || ctype_space($sql[$i + 2]))) {
            while ($i < $n && $sql[$i] !== "\n") $i++;
            $out .= "\n";
            continue;
        }
        if ($c === '#') {
            while ($i < $n && $sql[$i] !== "\n") $i++;
            $out .= "\n";
            continue;
        }
        if ($c === '/' && $i + 1 < $n && $sql[$i + 1] === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = ($end === false) ? $n : $end + 1;
            $out .= ' ';
            continue;
        }
        $out .= $c;
    }
    return $out;
}

/** Split on a separator at paren depth 0, outside quotes. */
function schema_audit_split_top($s, $sep = ',') {
    $parts = [];
    $cur = '';
    $depth = 0;
    $q = null;
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($q !== null) {
            $cur .= $c;
            if ($c === '\\' && $q !== '`' && $i + 1 < $n) { $cur .= $s[++$i]; continue; }
            if ($c === $q) {
                if ($i + 1 < $n && $s[$i + 1] === $q) { $cur .= $s[++$i]; continue; }
                $q = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $cur .= $c; continue; }
        if ($c === '(') $depth++;
        if ($c === ')') $depth--;
        if ($c === $sep && $depth === 0) { $parts[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    if (trim($cur) !== '') $parts[] = trim($cur);
    return $parts;
}

/**
 * Read a balanced (...) group at the start of $s. Returns [inner, rest] or null.
 */
function schema_audit_paren($s) {
    $s = ltrim($s);
    if ($s === '' || $s[0] !== '(') return null;
    $depth = 0;
    $q = null;
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($q !== null) {
            if ($c === '\\' && $q !== '`') { $i++; continue; }
            if ($c === $q) {
                if ($i + 1 < $n && $s[$i + 1] === $q) { $i++; continue; }
                $q = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; continue; }
        if ($c === '(') $depth++;
        if ($c === ')') {
            $depth--;
            if ($depth === 0) return [substr($s, 1, $i - 1), substr($s, $i + 1)];
        }
    }
    return null;
}

/**
 * Read an identifier (optionally db-qualified) at the start of $s.
 * Returns [name, rest] or null.
 */
function schema_audit_ident($s) {
    $s = ltrim($s);
    $name = null;
    if ($s !== '' && $s[0] === '`') {
        if (!preg_match('/^`((?:[^`]|``)+)`/', $s, $m)) return null;
        $name = str_replace('``', '`', $m[1]);
        $s = substr($s, strlen($m[0]));
    } elseif (preg_match('/^[A-Za-z0-9_$]+/', $s, $m)) {
        $name = $m[0];
        $s = substr($s, strlen($m[0]));
    } else {
        return null;
    }
    if ($s !== '' && $s[0] === '.') {           // db.table → table
        $next = schema_audit_ident(substr($s, 1));
        if ($next !== null) return $next;
    }
    return [$name, $s];
}

/** Replace quoted string contents so keyword regexes cannot match inside them. */
function schema_audit_blank_strings($s) {
    return preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.|\"\")*\"/s", "''", $s);
}

/** Parse the quoted value list of an ENUM/SET body: 'a','b''c' → ['a', "b'c"]. */
function schema_audit_enum_values($inner) {
    $vals = [];
    foreach (schema_audit_split_top($inner) as $part) {
        $part = trim($part);
        if (!preg_match("/^(['\"])(.*)\\1$/s", $part, $m)) return null;
        $v = $m[2];
        $v = str_replace([$m[1] . $m[1], '\\' . $m[1], '\\\\'], [$m[1], $m[1], '\\'], $v);
        $vals[] = rtrim($v, ' ');   // MySQL strips trailing spaces from ENUM members
    }
    return $vals;
}

function schema_audit_snippet($s, $len = 200) {
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    if (strlen($s) <= $len) return $s;
    // Cut on a character boundary: a split multibyte char makes json_encode() fail.
    $cut = function_exists('mb_strcut') ? mb_strcut($s, 0, $len, 'UTF-8') : substr($s, 0, $len);
    return $cut . '…';
}

// ─── TYPES ──────────────────────────────────────────────────────────────────

function schema_audit_type_families() {
    return [
        'int'  => ['tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'bigint' => 5],
        'text' => ['tinytext' => 1, 'text' => 2, 'mediumtext' => 3, 'longtext' => 4],
        'blob' => ['tinyblob' => 1, 'blob' => 2, 'mediumblob' => 3, 'longblob' => 4],
        'real' => ['float' => 1, 'double' => 2],
    ];
}

function schema_audit_known_bases() {
    return ['tinyint','smallint','mediumint','int','bigint','decimal','float','double',
            'bit','char','varchar','binary','varbinary','tinytext','text','mediumtext',
            'longtext','tinyblob','blob','mediumblob','longblob','enum','set','date',
            'datetime','timestamp','time','year','json','geometry','point','linestring',
            'polygon','multipoint','multilinestring','multipolygon','geometrycollection',
            'uuid','inet4','inet6','vector'];
}

/**
 * Parse a column type — either a migration's type text or a live COLUMN_TYPE —
 * into a comparable struct. Returns null when the base type is unrecognised.
 */
function schema_audit_parse_type($str) {
    $s = trim((string)$str);
    if (!preg_match('/^([A-Za-z0-9_]+(?:\s+precision|\s+varying)?)\s*/i', $s, $m)) return null;
    $base = strtolower(preg_replace('/\s+/', ' ', $m[1]));
    $rest = substr($s, strlen($m[0]));
    $args = null;
    if ($rest !== '' && $rest[0] === '(') {
        $p = schema_audit_paren($rest);
        if ($p === null) return null;
        $args = $p[0];
        $rest = $p[1];
    }
    $flags = strtolower($rest);
    $t = ['base' => $base, 'len' => null, 'scale' => null, 'values' => null,
          'unsigned' => (bool)preg_match('/\bunsigned\b/', $flags), 'raw' => $s];

    $alias = ['integer' => 'int', 'int4' => 'int', 'int8' => 'bigint', 'int1' => 'tinyint',
              'int2' => 'smallint', 'int3' => 'mediumint', 'middleint' => 'mediumint',
              'dec' => 'decimal', 'numeric' => 'decimal', 'fixed' => 'decimal',
              'real' => 'double', 'double precision' => 'double', 'float8' => 'double',
              'float4' => 'float', 'character varying' => 'varchar', 'nvarchar' => 'varchar',
              'nchar' => 'char', 'bool' => 'tinyint', 'boolean' => 'tinyint'];
    if (isset($alias[$base])) {
        $t['base'] = $alias[$base];
        if ($base === 'bool' || $base === 'boolean') $args = '1';
    }
    if ($base === 'serial') { $t['base'] = 'bigint'; $t['unsigned'] = true; }
    if (!in_array($t['base'], schema_audit_known_bases(), true)) return null;

    $b = $t['base'];
    if ($b === 'enum' || $b === 'set') {
        if ($args === null) return null;
        $t['values'] = schema_audit_enum_values($args);
        if ($t['values'] === null) return null;
    } elseif ($b === 'decimal') {
        $nums = array_map('trim', explode(',', (string)$args));
        $t['len']   = ($args !== null && $nums[0] !== '') ? (int)$nums[0] : 10;
        $t['scale'] = isset($nums[1]) ? (int)$nums[1] : 0;
    } elseif (in_array($b, ['char', 'binary', 'bit'], true)) {
        $t['len'] = ($args !== null) ? (int)$args : 1;
    } elseif (in_array($b, ['varchar', 'varbinary'], true)) {
        if ($args === null) return null;
        $t['len'] = (int)$args;
    } elseif (in_array($b, ['datetime', 'timestamp', 'time'], true)) {
        $t['len'] = ($args !== null) ? (int)$args : 0;          // fractional seconds
    }
    return $t;
}

/**
 * Compare expected vs live type. null = equivalent; else
 * ['severity' => 'drift'|'info', 'kind' => ..., (missing_values|extra_values)].
 */
function schema_audit_compare_types($expected_raw, $live_raw) {
    $e = schema_audit_parse_type($expected_raw);
    $l = schema_audit_parse_type($live_raw);
    if ($e === null || $l === null) {
        $norm = function ($x) { return strtolower(preg_replace('/\s+/', '', (string)$x)); };
        return ($norm($expected_raw) === $norm($live_raw)) ? null
            : ['severity' => 'info', 'kind' => 'unrecognized_type'];
    }
    $eb = $e['base']; $lb = $l['base'];

    // MariaDB stores JSON as LONGTEXT (+ a CHECK); they are the same column.
    if (($eb === 'json' && $lb === 'longtext') || ($eb === 'longtext' && $lb === 'json')) return null;

    foreach (schema_audit_type_families() as $fam) {
        if (isset($fam[$eb]) && isset($fam[$lb])) {
            if ($e['unsigned'] !== $l['unsigned'] && isset(schema_audit_type_families()['int'][$eb])) {
                return ['severity' => 'drift', 'kind' => 'signedness_differs'];
            }
            if ($fam[$eb] === $fam[$lb]) return null;
            return $fam[$lb] > $fam[$eb]
                ? ['severity' => 'info',  'kind' => 'live_type_wider']
                : ['severity' => 'drift', 'kind' => 'live_type_narrower'];
        }
    }
    if ($eb !== $lb) return ['severity' => 'drift', 'kind' => 'base_type_differs'];

    if ($eb === 'enum' || $eb === 'set') {
        $missing = array_values(array_diff($e['values'], $l['values']));
        $extra   = array_values(array_diff($l['values'], $e['values']));
        if ($missing) return ['severity' => 'drift', 'kind' => $eb . '_values_missing',
                              'missing_values' => $missing];
        if ($extra)   return ['severity' => 'info', 'kind' => $eb . '_extra_values',
                              'extra_values' => $extra];
        return null;
    }
    if ($eb === 'decimal') {
        if ($e['unsigned'] !== $l['unsigned']) return ['severity' => 'drift', 'kind' => 'signedness_differs'];
        if ($e['len'] === $l['len'] && $e['scale'] === $l['scale']) return null;
        return ($l['len'] >= $e['len'] && $l['scale'] >= $e['scale'])
            ? ['severity' => 'info',  'kind' => 'live_type_wider']
            : ['severity' => 'drift', 'kind' => 'precision_differs'];
    }
    if ($e['len'] !== null && $l['len'] !== null && $e['len'] !== $l['len']) {
        if (in_array($eb, ['datetime', 'timestamp', 'time'], true)) {
            return ['severity' => 'drift', 'kind' => 'fractional_seconds_differ'];
        }
        return $l['len'] > $e['len']
            ? ['severity' => 'info',  'kind' => 'live_type_wider']
            : ['severity' => 'drift', 'kind' => 'live_type_narrower'];
    }
    return null;
}

// ─── EXPECTED MODEL ─────────────────────────────────────────────────────────

function schema_audit_new_model() {
    return ['tables' => [], 'dropped_tables' => [], 'unparsed' => [], 'runner_skipped' => [], 'legacy_runner_skipped' => []];
}

function &schema_audit_table(&$model, $name, $partial_if_new = true) {
    $k = strtolower($name);
    if (!isset($model['tables'][$k])) {
        $model['tables'][$k] = ['name' => $name, 'columns' => [], 'indexes' => [],
            'dropped_columns' => [], 'created_in' => null, 'partial' => $partial_if_new,
            'uncertain' => []];
    }
    return $model['tables'][$k];
}

/**
 * Parse "name type [attrs]" (a column definition). Returns
 * ['name','type','primary'=>bool,'unique'=>bool] or null.
 */
function schema_audit_parse_column_def($def) {
    $id = schema_audit_ident($def);
    if ($id === null) return null;
    $rest = ltrim($id[1]);
    if (!preg_match('/^([A-Za-z0-9_]+(?:\s+precision|\s+varying)?)\s*/i', $rest, $m)) return null;
    $type = trim($m[0]);
    $after = substr($rest, strlen($m[0]));
    if ($after !== '' && $after[0] === '(') {
        $p = schema_audit_paren($after);
        if ($p === null) return null;
        $type .= '(' . $p[0] . ')';
        $after = $p[1];
    }
    while (preg_match('/^\s*(unsigned|signed|zerofill)\b/i', $after, $fm)) {
        $type .= ' ' . strtolower($fm[1]);
        $after = substr($after, strlen($fm[0]));
    }
    if (schema_audit_parse_type($type) === null) return null;
    $attrs = strtoupper(schema_audit_blank_strings($after));
    return [
        'name'    => $id[0],
        'type'    => trim($type),
        'primary' => (bool)preg_match('/\bPRIMARY\s+KEY\b/', $attrs),
        'unique'  => (bool)preg_match('/\bUNIQUE\b/', $attrs),
    ];
}

/** Parse an index column list "(`a`, b(10) DESC)" → ['a','b'] or null. */
function schema_audit_index_cols($inner) {
    $cols = [];
    foreach (schema_audit_split_top($inner) as $part) {
        $id = schema_audit_ident($part);
        if ($id === null) return null;                     // functional index etc.
        $rest = trim($id[1]);
        if ($rest !== '' && $rest[0] === '(') {
            $p = schema_audit_paren($rest);
            if ($p === null) return null;
            $rest = trim($p[1]);
        }
        if ($rest !== '' && !preg_match('/^(ASC|DESC)$/i', $rest)) return null;
        $cols[] = strtolower($id[0]);
    }
    return $cols ?: null;
}

function schema_audit_add_index(&$t, $name, $cols, $kind, $ver) {
    if ($name === null || $name === '') {
        $base = $cols[0];
        $name = $base;
        for ($i = 2; isset($t['indexes'][strtolower($name)]); $i++) $name = $base . '_' . $i;
    }
    $k = strtolower($name);
    if (isset($t['indexes'][$k])) return;                   // 1061 → runner skips
    $t['indexes'][$k] = ['name' => $name, 'cols' => $cols, 'kind' => $kind, 'set_in' => $ver];
}

/**
 * Parse an index definition that starts after ADD (or a CREATE TABLE item).
 * Returns ['name','cols','kind'] | 'ignore' | null (unparsed).
 */
function schema_audit_parse_index_def($s) {
    $s = trim($s);
    $sym = null;
    if (preg_match('/^CONSTRAINT\b/i', $s)) {
        $s = trim(substr($s, 10));
        if (!preg_match('/^(PRIMARY|UNIQUE|FOREIGN|CHECK)\b/i', $s)) {
            $id = schema_audit_ident($s);
            if ($id === null) return null;
            $sym = $id[0];
            $s = trim($id[1]);
        }
    }
    if (preg_match('/^(FOREIGN\s+KEY|CHECK)\b/i', $s)) return 'ignore';
    if (preg_match('/^PRIMARY\s+KEY\s*(USING\s+\w+\s*)?/i', $s, $m)) {
        $p = schema_audit_paren(substr($s, strlen($m[0])));
        $cols = $p ? schema_audit_index_cols($p[0]) : null;
        return $cols ? ['name' => 'PRIMARY', 'cols' => $cols, 'kind' => 'primary'] : null;
    }
    if (!preg_match('/^(UNIQUE|FULLTEXT|SPATIAL)?\s*(KEY|INDEX)?\s*(IF\s+NOT\s+EXISTS\s+)?/i', $s, $m)
        || trim($m[0]) === '') {
        return null;
    }
    $kind = $m[1] ? strtolower($m[1]) : 'index';
    $s = trim(substr($s, strlen($m[0])));
    $name = $sym;
    if ($s !== '' && $s[0] !== '(' && !preg_match('/^USING\b/i', $s)) {
        $id = schema_audit_ident($s);
        if ($id === null) return null;
        $name = $id[0];
        $s = trim($id[1]);
    }
    $s = preg_replace('/^USING\s+\w+\s*/i', '', $s);
    $p = schema_audit_paren($s);
    $cols = $p ? schema_audit_index_cols($p[0]) : null;
    return $cols ? ['name' => $name, 'cols' => $cols, 'kind' => $kind] : null;
}

function schema_audit_note_unparsed(&$model, $src, $stmt, $why, $table = null) {
    $model['unparsed'][] = ['migration' => $src, 'reason' => $why,
                            'table' => $table, 'statement' => schema_audit_snippet($stmt)];
    if ($table !== null) {
        $t = &schema_audit_table($model, $table, true);
        $t['uncertain'][] = "$src: $why";
    }
}

/**
 * Apply one ALTER TABLE spec to a table. Returns true, or a reason string.
 */
function schema_audit_apply_alter_spec(&$model, &$t, $spec, $src) {
    $s = trim($spec);
    if ($s === '') return true;

    if (preg_match('/^ADD\s+/i', $s, $m)) {
        $body = substr($s, strlen($m[0]));
        if (preg_match('/^(CONSTRAINT|PRIMARY\s+KEY|UNIQUE|INDEX|KEY|FULLTEXT|SPATIAL|FOREIGN\s+KEY|CHECK)\b/i', $body)) {
            $ix = schema_audit_parse_index_def($body);
            if ($ix === 'ignore') return true;
            if ($ix === null) return 'unparsed index definition';
            schema_audit_add_index($t, $ix['name'], $ix['cols'], $ix['kind'], $src);
            return true;
        }
        $body = preg_replace('/^(COLUMN\s+)?(IF\s+NOT\s+EXISTS\s+)?/i', '', $body);
        $defs = [$body];
        if (ltrim($body) !== '' && ltrim($body)[0] === '(') {
            $p = schema_audit_paren($body);
            if ($p === null) return 'unparsed ADD COLUMN list';
            $defs = schema_audit_split_top($p[0]);
        }
        foreach ($defs as $d) {
            $col = schema_audit_parse_column_def($d);
            if ($col === null) return 'unparsed column definition';
            $k = strtolower($col['name']);
            if (isset($t['columns'][$k])) continue;          // 1060 → runner skips
            $t['columns'][$k] = ['name' => $col['name'], 'type' => $col['type'], 'set_in' => $src];
            unset($t['dropped_columns'][$k]);
            if ($col['primary']) schema_audit_add_index($t, 'PRIMARY', [$k], 'primary', $src);
            if ($col['unique'])  schema_audit_add_index($t, null, [$k], 'unique', $src);
        }
        return true;
    }

    if (preg_match('/^(MODIFY|CHANGE)\s+(COLUMN\s+)?(IF\s+EXISTS\s+)?/i', $s, $m)) {
        $body = substr($s, strlen($m[0]));
        $old = null;
        if (strtoupper($m[1]) === 'CHANGE') {
            $id = schema_audit_ident($body);
            if ($id === null) return 'unparsed CHANGE';
            $old = strtolower($id[0]);
            $body = $id[1];
        }
        $col = schema_audit_parse_column_def($body);
        if ($col === null) return 'unparsed column definition';
        $from = $old !== null ? $old : strtolower($col['name']);
        if (!isset($t['columns'][$from]) && !$t['partial']) {
            return "$m[1] of column '$from' the migrations never created";
        }
        $new = strtolower($col['name']);
        unset($t['columns'][$from]);
        $t['columns'][$new] = ['name' => $col['name'], 'type' => $col['type'], 'set_in' => $src];
        if ($from !== $new) {
            foreach ($t['indexes'] as &$ix) {
                foreach ($ix['cols'] as &$c) { if ($c === $from) $c = $new; }
                unset($c);
            }
            unset($ix);
        }
        return true;
    }

    if (preg_match('/^DROP\s+(PRIMARY\s+KEY)\b/i', $s)) {
        unset($t['indexes']['primary']);
        return true;
    }
    if (preg_match('/^DROP\s+(INDEX|KEY)\s+(IF\s+EXISTS\s+)?/i', $s, $m)) {
        $id = schema_audit_ident(substr($s, strlen($m[0])));
        if ($id === null) return 'unparsed DROP INDEX';
        unset($t['indexes'][strtolower($id[0])]);
        return true;
    }
    if (preg_match('/^DROP\s+(FOREIGN\s+KEY|CHECK)\b/i', $s)) return true;
    if (preg_match('/^DROP\s+CONSTRAINT\b/i', $s)) return 'DROP CONSTRAINT (may drop a unique index)';
    if (preg_match('/^DROP\s+(COLUMN\s+)?(IF\s+EXISTS\s+)?/i', $s, $m)) {
        $id = schema_audit_ident(substr($s, strlen($m[0])));
        if ($id === null) return 'unparsed DROP COLUMN';
        $k = strtolower($id[0]);
        unset($t['columns'][$k]);
        $t['dropped_columns'][$k] = $src;
        foreach ($t['indexes'] as $ik => &$ix) {
            $ix['cols'] = array_values(array_diff($ix['cols'], [$k]));
            if (!$ix['cols']) unset($t['indexes'][$ik]);
        }
        unset($ix);
        return true;
    }

    if (preg_match('/^RENAME\s+(COLUMN|INDEX|KEY)\s+/i', $s, $m)) {
        $a = schema_audit_ident(substr($s, strlen($m[0])));
        if ($a === null || !preg_match('/^\s*TO\s+/i', $a[1], $tm)) return 'unparsed RENAME';
        $b = schema_audit_ident(substr($a[1], strlen($tm[0])));
        if ($b === null) return 'unparsed RENAME';
        $from = strtolower($a[0]); $to = strtolower($b[0]);
        if (strtoupper($m[1]) === 'COLUMN') {
            if (!isset($t['columns'][$from])) return "RENAME of column '$from' the migrations never created";
            $t['columns'][$to] = $t['columns'][$from];
            $t['columns'][$to]['name'] = $b[0];
            unset($t['columns'][$from]);
            foreach ($t['indexes'] as &$ix) {
                foreach ($ix['cols'] as &$c) { if ($c === $from) $c = $to; }
                unset($c);
            }
            unset($ix);
        } elseif (isset($t['indexes'][$from])) {
            $t['indexes'][$to] = $t['indexes'][$from];
            $t['indexes'][$to]['name'] = $b[0];
            unset($t['indexes'][$from]);
        }
        return true;
    }
    if (preg_match('/^RENAME\s+(TO\s+|AS\s+)?/i', $s, $m)) {
        return 'RENAME TABLE inside ALTER';     // handled by caller (needs model access)
    }

    if (preg_match('/^ALTER\s+(COLUMN\s+)?/i', $s)) return true;             // SET/DROP DEFAULT, visibility
    if (preg_match('/^(CONVERT\s+TO\s+CHARACTER\s+SET|(DEFAULT\s+)?(CHARACTER\s+SET|CHARSET|COLLATE)|ENGINE|AUTO_INCREMENT|COMMENT|ROW_FORMAT|ALGORITHM|LOCK|FORCE|KEY_BLOCK_SIZE|STATS_\w+|PACK_KEYS|CHECKSUM|ORDER\s+BY)\b/i', $s)) {
        return true;
    }
    return 'unrecognised ALTER spec';
}

/**
 * Apply one statement (comments already stripped) to the expected model.
 * $src is a label like "shard/2.1".
 */
function schema_audit_apply_statement(&$model, $stmt, $src) {
    $s = trim($stmt);
    $s = rtrim($s, "; \t\r\n");
    if ($s === '') return 'empty';

    // Data / session statements — no schema effect. PREPARE/EXECUTE/CALL can
    // hide DDL, so they are NOT in this list.
    if (preg_match('/^(SET|INSERT|REPLACE|UPDATE|DELETE|SELECT|START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK|LOCK\s+TABLES|UNLOCK\s+TABLES|TRUNCATE|ANALYZE|OPTIMIZE|DO|SAVEPOINT|RELEASE)\b/i', $s)) {
        // A SET @sql = '... ALTER TABLE x ...' is the dynamic-DDL idiom; its table is uncertain.
        if (preg_match('/^SET\b/i', $s) && preg_match('/(ALTER|CREATE|DROP)\s+TABLE\s+(IF\s+(NOT\s+)?EXISTS\s+)?`?(\w+)`?/i', $s, $dm)) {
            schema_audit_note_unparsed($model, $src, $s, 'dynamic DDL in a variable', $dm[4]);
            return 'unparsed';
        }
        return 'ignored';
    }

    // CREATE TABLE
    if (preg_match('/^CREATE\s+(OR\s+REPLACE\s+)?(TEMPORARY\s+)?TABLE\s+(IF\s+NOT\s+EXISTS\s+)?/i', $s, $m)) {
        if (!empty($m[2])) return 'ignored';
        $id = schema_audit_ident(substr($s, strlen($m[0])));
        if ($id === null) { schema_audit_note_unparsed($model, $src, $s, 'unparsed CREATE TABLE name'); return 'unparsed'; }
        $name = $id[0];
        $p = schema_audit_paren($id[1]);
        if ($p === null) {
            schema_audit_note_unparsed($model, $src, $s, 'CREATE TABLE without a column list (LIKE / AS SELECT)', $name);
            return 'unparsed';
        }
        $k = strtolower($name);
        $existing = $model['tables'][$k] ?? null;
        if ($existing !== null && !$existing['partial'] && empty($m[1])) {
            return 'noop';                          // IF NOT EXISTS, or 1050 skipped by the runner
        }
        $t = ['name' => $name, 'columns' => [], 'indexes' => [], 'dropped_columns' => [],
              'created_in' => $src, 'partial' => false,
              'uncertain' => $existing ? $existing['uncertain'] : []];
        foreach (schema_audit_split_top($p[0]) as $item) {
            if (preg_match('/^(CONSTRAINT|PRIMARY\s+KEY|UNIQUE|INDEX|KEY|FULLTEXT|SPATIAL|FOREIGN\s+KEY|CHECK)\b/i', $item)) {
                $ix = schema_audit_parse_index_def($item);
                if ($ix === 'ignore') continue;
                if ($ix === null) { $t['uncertain'][] = "$src: unparsed index definition";
                    $model['unparsed'][] = ['migration' => $src, 'reason' => 'unparsed index definition',
                                            'table' => $name, 'statement' => schema_audit_snippet($item)];
                    continue; }
                schema_audit_add_index($t, $ix['name'], $ix['cols'], $ix['kind'], $src);
                continue;
            }
            $col = schema_audit_parse_column_def($item);
            if ($col === null) {
                $t['uncertain'][] = "$src: unparsed column definition";
                $model['unparsed'][] = ['migration' => $src, 'reason' => 'unparsed column definition',
                                        'table' => $name, 'statement' => schema_audit_snippet($item)];
                continue;
            }
            $ck = strtolower($col['name']);
            $t['columns'][$ck] = ['name' => $col['name'], 'type' => $col['type'], 'set_in' => $src];
            if ($col['primary']) schema_audit_add_index($t, 'PRIMARY', [$ck], 'primary', $src);
            if ($col['unique'])  schema_audit_add_index($t, null, [$ck], 'unique', $src);
        }
        // An ALTER that ran before this CREATE (table made by code) keeps its columns.
        if ($existing !== null && $existing['partial']) {
            $t['columns'] += $existing['columns'];
            $t['indexes'] += $existing['indexes'];
        }
        $model['tables'][$k] = $t;
        unset($model['dropped_tables'][$k]);
        return 'applied';
    }

    // ALTER TABLE
    if (preg_match('/^ALTER\s+(ONLINE\s+|IGNORE\s+)*TABLE\s+(IF\s+EXISTS\s+)?/i', $s, $m)) {
        $id = schema_audit_ident(substr($s, strlen($m[0])));
        if ($id === null) { schema_audit_note_unparsed($model, $src, $s, 'unparsed ALTER TABLE name'); return 'unparsed'; }
        $name = $id[0];
        $k = strtolower($name);
        if (!isset($model['tables'][$k])) {
            // Table not created by any migration (made by code, or by an unparsed
            // statement). Track what the ALTER adds, but never judge the rest.
            $t = &schema_audit_table($model, $name, true);
            $t['uncertain'][] = "$src: altered, but no migration creates this table";
            unset($t);
        }
        $result = 'applied';
        foreach (schema_audit_split_top($id[1]) as $spec) {
            if (preg_match('/^RENAME\s+(?!(COLUMN|INDEX|KEY)\b)(TO\s+|AS\s+)?/i', trim($spec), $rm)) {
                $to = schema_audit_ident(substr(trim($spec), strlen($rm[0])));
                if ($to === null) { schema_audit_note_unparsed($model, $src, $s, 'unparsed RENAME', $name); $result = 'unparsed'; continue; }
                $tk = strtolower($to[0]);
                $model['tables'][$tk] = $model['tables'][$k];
                $model['tables'][$tk]['name'] = $to[0];
                unset($model['tables'][$k]);
                $model['dropped_tables'][$k] = $src;
                $k = $tk; $name = $to[0];
                continue;
            }
            $t = &$model['tables'][$k];
            $r = schema_audit_apply_alter_spec($model, $t, $spec, $src);
            unset($t);
            if ($r !== true) {
                schema_audit_note_unparsed($model, $src, $spec, $r, $name);
                $result = 'unparsed';
            }
        }
        return $result;
    }

    // DROP TABLE
    if (preg_match('/^DROP\s+(TEMPORARY\s+)?TABLES?\s+(IF\s+EXISTS\s+)?/i', $s, $m)) {
        if (!empty($m[1])) return 'ignored';
        $list = preg_replace('/\s+(CASCADE|RESTRICT)\s*$/i', '', substr($s, strlen($m[0])));
        foreach (schema_audit_split_top($list) as $part) {
            $id = schema_audit_ident($part);
            if ($id === null) { schema_audit_note_unparsed($model, $src, $s, 'unparsed DROP TABLE'); return 'unparsed'; }
            unset($model['tables'][strtolower($id[0])]);
            $model['dropped_tables'][strtolower($id[0])] = $src;
        }
        return 'applied';
    }

    // RENAME TABLE a TO b, c TO d
    if (preg_match('/^RENAME\s+TABLES?\s+/i', $s, $m)) {
        foreach (schema_audit_split_top(substr($s, strlen($m[0]))) as $pair) {
            $a = schema_audit_ident($pair);
            if ($a === null || !preg_match('/^\s*TO\s+/i', $a[1], $tm)) { schema_audit_note_unparsed($model, $src, $s, 'unparsed RENAME TABLE'); return 'unparsed'; }
            $b = schema_audit_ident(substr($a[1], strlen($tm[0])));
            if ($b === null) { schema_audit_note_unparsed($model, $src, $s, 'unparsed RENAME TABLE'); return 'unparsed'; }
            $ak = strtolower($a[0]); $bk = strtolower($b[0]);
            if (isset($model['tables'][$ak])) {
                $model['tables'][$bk] = $model['tables'][$ak];
                $model['tables'][$bk]['name'] = $b[0];
                unset($model['tables'][$ak]);
                $model['dropped_tables'][$ak] = $src;
            } else {
                schema_audit_note_unparsed($model, $src, $s, 'RENAME of a table the migrations never created', $b[0]);
            }
        }
        return 'applied';
    }

    // CREATE [UNIQUE|FULLTEXT|SPATIAL] INDEX name ON tbl (cols)
    if (preg_match('/^CREATE\s+(OR\s+REPLACE\s+)?(UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX\s+(IF\s+NOT\s+EXISTS\s+)?/i', $s, $m)) {
        $n = schema_audit_ident(substr($s, strlen($m[0])));
        if ($n !== null && preg_match('/^\s*(USING\s+\w+\s+)?ON\s+/i', $n[1], $om)) {
            $tb = schema_audit_ident(substr($n[1], strlen($om[0])));
            $p = $tb ? schema_audit_paren($tb[1]) : null;
            $cols = $p ? schema_audit_index_cols($p[0]) : null;
            if ($cols) {
                $t = &schema_audit_table($model, $tb[0], true);
                if ($t['partial'] && !$t['columns'] && !$t['uncertain']) $t['uncertain'][] = "$src: indexed, but no migration creates this table";
                schema_audit_add_index($t, $n[0], $cols, $m[2] ? strtolower(trim($m[2])) : 'index', $src);
                unset($t);
                return 'applied';
            }
        }
        schema_audit_note_unparsed($model, $src, $s, 'unparsed CREATE INDEX');
        return 'unparsed';
    }
    if (preg_match('/^DROP\s+INDEX\s+(IF\s+EXISTS\s+)?/i', $s, $m)) {
        $n = schema_audit_ident(substr($s, strlen($m[0])));
        if ($n !== null && preg_match('/^\s*ON\s+/i', $n[1], $om)) {
            $tb = schema_audit_ident(substr($n[1], strlen($om[0])));
            if ($tb !== null) {
                unset($model['tables'][strtolower($tb[0])]['indexes'][strtolower($n[0])]);
                return 'applied';
            }
        }
        schema_audit_note_unparsed($model, $src, $s, 'unparsed DROP INDEX');
        return 'unparsed';
    }

    $table = null;
    if (preg_match('/\b(ALTER|CREATE|DROP)\s+TABLE\s+(IF\s+(NOT\s+)?EXISTS\s+)?`?(\w+)`?/i', $s, $tm)) $table = $tm[4];
    schema_audit_note_unparsed($model, $src, $s, 'statement type not audited', $table);
    return 'unparsed';
}

/**
 * The runner's own statement split + skip rule (run_migration()). Returns a list
 * of ['raw' => fragment, 'sql' => comment-stripped,
 *     'runner_skips' => bool   (the CURRENT runner skips it — only exact
 *                               transaction control / comment-only fragments),
 *     'legacy_skips' => bool   (the pre-5.0 runner skipped it: raw text,
 *                               comments included, contained COMMIT / ROLLBACK /
 *                               START TRANSACTION)].
 * Databases migrated before core 5.0 may be missing what legacy_skips dropped.
 */
function schema_audit_split_like_runner($sql) {
    if (!function_exists('wn_migration_skip_reason')) {
        require_once __DIR__ . '/migrationFunctions.php';
    }
    $out = [];
    foreach (preg_split('/;\s*[\r\n]+/', (string)$sql) as $frag) {
        $raw = trim($frag);
        if ($raw === '') continue;
        $upper = strtoupper($raw);
        $legacy = strpos($upper, 'START TRANSACTION') !== false
               || strpos($upper, 'COMMIT') !== false
               || strpos($upper, 'ROLLBACK') !== false;
        $out[] = ['raw' => $raw, 'sql' => trim(schema_audit_strip_comments($raw)),
                  'runner_skips' => wn_migration_skip_reason($raw) !== null, 'legacy_skips' => $legacy];
    }
    return $out;
}

/**
 * Build the expected model from $files [version(float) => path], applying only
 * versions <= $through.
 */
function schema_audit_build_expected($files, $through, $label_prefix) {
    $model = schema_audit_new_model();
    ksort($files, SORT_NUMERIC);
    foreach ($files as $ver => $path) {
        if ((float)$ver > (float)$through + 0.00001) break;
        $src = $label_prefix . number_format((float)$ver, 1, '.', '');
        $sql = @file_get_contents($path);
        if ($sql === false) {
            $model['unparsed'][] = ['migration' => $src, 'reason' => 'file unreadable', 'table' => null, 'statement' => ''];
            continue;
        }
        foreach (schema_audit_split_like_runner($sql) as $st) {
            if ($st['sql'] === '') continue;
            $plain = rtrim($st['sql'], "; \t\r\n");
            $is_txn = (bool)preg_match('/^(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK)\s*(WORK)?$/i', $plain);
            $is_session = (bool)preg_match('/^SET\s+(SQL_MODE|TIME_ZONE|NAMES|FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|CHARACTER_SET\w*|COLLATION\w*|@@|SESSION\b|AUTOCOMMIT)/i', $plain);
            if ($st['runner_skips'] && !$is_txn) {
                // Current runner skips only exact transaction control; anything
                // else here would be a runner bug.
                $model['runner_skipped'][] = [
                    'migration' => $src,
                    'statement' => schema_audit_snippet($plain),
                ];
            }
            if ($st['legacy_skips'] && !$is_txn && !$is_session) {
                preg_match('/(START TRANSACTION|COMMIT|ROLLBACK)/', strtoupper($st['raw']), $km);
                $model['legacy_runner_skipped'][] = [
                    'migration' => $src,
                    'matched'   => $km[1] ?? '',
                    'statement' => schema_audit_snippet($plain),
                ];
            }
            // Expected = what the migration INTENDS, runner skip or not.
            $prev = $model['tables'];
            schema_audit_apply_statement($model, $st['sql'], $src);
            if ($st['legacy_skips'] && !$is_txn) {
                schema_audit_mark_skipped($model, $prev);
            }
        }
    }
    return $model;
}

/**
 * Tag what a runner-skipped statement changed (vs the model before it) so a
 * finding can name the cause.
 */
function schema_audit_mark_skipped(&$model, $prev) {
    foreach ($model['tables'] as $tk => &$t) {
        $p = $prev[$tk] ?? null;
        if ($p === null || $p['created_in'] !== $t['created_in']) $t['created_skipped'] = true;
        foreach ($t['columns'] as $ck => &$c) {
            if (!isset($p['columns'][$ck]) || $p['columns'][$ck] != $c) $c['skipped'] = true;
        }
        unset($c);
        foreach ($t['indexes'] as $ik => &$ix) {
            if (!isset($p['indexes'][$ik]) || $p['indexes'][$ik] != $ix) $ix['skipped'] = true;
        }
        unset($ix);
    }
    unset($t);
}

// ─── ACTUAL SCHEMA ──────────────────────────────────────────────────────────

/** Read tables/columns/indexes + ledger from a PDO. Read-only, prepared. */
function schema_audit_read_live(PDO $pdo) {
    $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $live = ['schema' => $schema, 'tables' => [], 'ledger' => null];

    $st = $pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?');
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $live['tables'][strtolower($r['TABLE_NAME'])] = ['name' => $r['TABLE_NAME'],
            'view' => stripos($r['TABLE_TYPE'], 'VIEW') !== false, 'columns' => [], 'indexes' => []];
    }
    $st = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION');
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tk = strtolower($r['TABLE_NAME']);
        if (!isset($live['tables'][$tk])) continue;
        $live['tables'][$tk]['columns'][strtolower($r['COLUMN_NAME'])] = ['name' => $r['COLUMN_NAME'], 'type' => $r['COLUMN_TYPE']];
    }
    $st = $pdo->prepare('SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS
                          WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX');
    $st->execute([$schema]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tk = strtolower($r['TABLE_NAME']);
        if (!isset($live['tables'][$tk])) continue;
        $live['tables'][$tk]['indexes'][strtolower($r['INDEX_NAME'])][] = strtolower((string)$r['COLUMN_NAME']);
    }
    if (isset($live['tables']['db_version'])) {
        try {
            $v = $pdo->query('SELECT version FROM db_version WHERE version_id = 1')->fetchColumn();
            $live['ledger'] = ($v === false) ? null : (float)$v;
        } catch (PDOException $e) { /* unreadable → null */ }
    }
    return $live;
}

// ─── DIFF ───────────────────────────────────────────────────────────────────

function schema_audit_diff($model, $live, $include_info = true) {
    $drift = ['missing_tables' => [], 'missing_columns' => [], 'type_mismatches' => [], 'missing_indexes' => []];
    $info  = ['extra_tables' => [], 'extra_columns' => [], 'type_differences' => []];
    $uncertain = [];

    foreach ($model['tables'] as $tk => $t) {
        $unsure = $t['partial'] || !empty($t['uncertain']);
        $push = function ($bucket, $row) use (&$drift, &$uncertain, $unsure, $t) {
            if ($unsure) {
                $row['finding'] = $bucket;
                $row['why_uncertain'] = $t['uncertain'] ?: ['table not created by any parsed migration'];
                $uncertain[] = $row;
            } else {
                $drift[$bucket][] = $row;
            }
        };
        if (!isset($live['tables'][$tk])) {
            $row = ['table' => $t['name'], 'created_in' => $t['created_in']];
            if (!empty($t['created_skipped'])) $row['legacy_runner_skipped'] = true;
            $push('missing_tables', $row);
            continue;
        }
        $lt = $live['tables'][$tk];
        foreach ($t['columns'] as $ck => $c) {
            if (!isset($lt['columns'][$ck])) {
                $row = ['table' => $t['name'], 'column' => $c['name'], 'expected_type' => $c['type'], 'defined_in' => $c['set_in']];
                if (!empty($c['skipped'])) $row['legacy_runner_skipped'] = true;
                $push('missing_columns', $row);
                continue;
            }
            $cmp = schema_audit_compare_types($c['type'], $lt['columns'][$ck]['type']);
            if ($cmp === null) continue;
            $row = ['table' => $t['name'], 'column' => $c['name'], 'expected' => $c['type'],
                    'actual' => $lt['columns'][$ck]['type'], 'defined_in' => $c['set_in']] + $cmp;
            unset($row['severity']);
            if (!empty($c['skipped'])) $row['legacy_runner_skipped'] = true;
            if ($cmp['severity'] === 'drift') {
                $push('type_mismatches', $row);
            } elseif ($include_info) {
                $info['type_differences'][] = $row;
            }
        }
        foreach ($t['indexes'] as $ik => $ix) {
            $found = isset($lt['indexes'][$ik]);
            if (!$found) {
                foreach ($lt['indexes'] as $lcols) { if ($lcols === $ix['cols']) { $found = true; break; } }
            }
            if ($found) continue;
            // Every column of the index must exist live, or the missing column is the finding.
            $cols_live = !array_diff($ix['cols'], array_keys($lt['columns']));
            if (!$cols_live) continue;
            $row = ['table' => $t['name'], 'index' => $ix['name'], 'columns' => $ix['cols'],
                    'kind' => $ix['kind'], 'defined_in' => $ix['set_in']];
            if (!empty($ix['skipped'])) $row['legacy_runner_skipped'] = true;
            $push('missing_indexes', $row);
        }
        if ($include_info && !$t['partial']) {
            foreach ($lt['columns'] as $ck => $lc) {
                if (isset($t['columns'][$ck])) continue;
                $row = ['table' => $t['name'], 'column' => $lc['name'], 'type' => $lc['type']];
                if (isset($t['dropped_columns'][$ck])) $row['dropped_in'] = $t['dropped_columns'][$ck];
                $info['extra_columns'][] = $row;
            }
        }
    }
    if ($include_info) {
        foreach ($live['tables'] as $tk => $lt) {
            if (isset($model['tables'][$tk]) || $lt['view']) continue;
            $row = ['table' => $lt['name']];
            if (isset($model['dropped_tables'][$tk])) $row['dropped_in'] = $model['dropped_tables'][$tk];
            $info['extra_tables'][] = $row;
        }
    }
    return ['drift' => $drift, 'info' => $info, 'uncertain' => $uncertain];
}

// ─── DISCOVERY ──────────────────────────────────────────────────────────────

/** [version(float string) => path] for one migration directory. */
function schema_audit_migration_files($base_dir, $db_type) {
    $out = [];
    $dir = rtrim($base_dir, '/') . '/' . $db_type . '/';
    foreach (glob($dir . '*.sql') ?: [] as $f) {
        if (preg_match('/^(\d+\.\d+)\.sql$/', basename($f), $m)) {
            $out[number_format((float)$m[1], 1, '.', '')] = $f;
        }
    }
    uksort($out, function ($a, $b) { return (float)$a <=> (float)$b; });
    return $out;
}

/**
 * A child app's DB targets from its config.php, included in function scope so
 * nothing reaches globals. Returns ['main' => conn|null, 'shards' => [id => conn]].
 */
function schema_audit_child_config_targets($file) {
    if (!is_readable($file)) return null;
    try {
        ob_start();
        include $file;
        ob_end_clean();
    } catch (Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        return null;
    }
    $user = $child_db_user ?? '';
    $pass = $child_db_pass ?? '';
    $host = ($child_db_host ?? '') ?: 'localhost';
    $out = ['main' => null, 'shards' => []];
    if (!empty($child_db_name)) {
        $out['main'] = ['host' => $host, 'name' => $child_db_name, 'user' => $user, 'pass' => $pass];
    }
    foreach ((array)($childShardConfigs ?? []) as $id => $sc) {
        if (empty($sc['name'])) continue;
        $out['shards'][(string)$id] = ['host' => ($sc['host'] ?? '') ?: $host, 'name' => $sc['name'],
            'user' => $sc['user'] ?? $user, 'pass' => $sc['pass'] ?? $pass];
    }
    foreach (get_defined_vars() as $k => $v) {
        if (is_string($v) && $v !== '' && preg_match('/^child_db_name_?shard(\d*)$/i', $k, $m)) {
            $id = 'shard' . $m[1];
            if (!isset($out['shards'][$id])) {
                $out['shards'][$id] = ['host' => $host, 'name' => $v, 'user' => $user, 'pass' => $pass];
            }
        }
    }
    return $out;
}

/**
 * Read `$child_db_version = X;` style constants. include/migration_versions.php is
 * the single declaration every bootstrap includes; older apps still declare in
 * common.php / common_api.php, and any copy left there is reported if it differs.
 */
function schema_audit_child_version($dir, $var) {
    $vals = [];
    foreach (['include/migration_versions.php', 'include/common.php', 'include/common_api.php'] as $rel) {
        $src = @file_get_contents(rtrim($dir, '/') . '/' . $rel);
        if ($src !== false && preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*([0-9]+\.[0-9]+)\s*;/', $src, $m)) {
            $vals[$rel] = (float)$m[1];
        }
    }
    return $vals;
}

/**
 * Every database this deployment owns, grouped for the audit.
 * Each target: label, layer, app, role, shard_id, conn (null = $db), dir, db_type, target.
 */
function schema_audit_targets() {
    global $db_version, $shard_version, $shardConfigs, $db;
    $targets = [];
    $notes = [];
    $core_dir = defined('APP_MIGRATION_DIR') ? APP_MIGRATION_DIR : (__DIR__ . '/../../db_migrations/');

    $targets[] = ['label' => 'core/main', 'layer' => 'core', 'app' => 'admin', 'role' => 'main',
        'shard_id' => null, 'conn' => null, 'dir' => $core_dir, 'db_type' => 'main',
        'target' => isset($db_version) ? (float)$db_version : null, 'fail_scope' => 'main'];
    foreach ((array)($shardConfigs ?? []) as $id => $cfg) {
        if (empty($cfg['name'])) continue;
        $targets[] = ['label' => "core/shard/$id", 'layer' => 'core', 'app' => 'admin', 'role' => 'shard',
            'shard_id' => (string)$id, 'conn' => ['host' => ($cfg['host'] ?? '') ?: 'localhost', 'name' => $cfg['name'],
                'user' => $cfg['user'] ?? '', 'pass' => $cfg['pass'] ?? ''], 'dir' => $core_dir,
            'db_type' => 'shard', 'target' => isset($shard_version) ? (float)$shard_version : null,
            'fail_scope' => function_exists('_wn_shard_flag_scope') ? _wn_shard_flag_scope($id) : null];
    }

    $webroot = dirname(__DIR__, 3);   // public_html/ (admin/ is a child)
    foreach (glob($webroot . '/*/db_migrations', GLOB_ONLYDIR) ?: [] as $mdir) {
        $app_dir = dirname($mdir);
        $app = basename($app_dir);
        if ($app === 'admin') continue;
        $cfg = schema_audit_child_config_targets($app_dir . '/config/config.php');
        if ($cfg === null) { $notes[] = "$app: db_migrations/ present but config/config.php unreadable — not audited"; continue; }

        $mainv  = schema_audit_child_version($app_dir, 'child_db_version');
        $shardv = schema_audit_child_version($app_dir, 'child_shard_version');
        foreach (['child_db_version' => $mainv, 'child_shard_version' => $shardv] as $var => $vals) {
            if (count(array_unique(array_map('strval', $vals))) > 1) {
                $notes[] = "$app: \$$var differs between " . implode(' and ', array_map(
                    function ($f, $v) { return "$f ($v)"; }, array_keys($vals), $vals));
            }
        }
        $mt = $mainv  ? max($mainv)  : null;
        $st = $shardv ? max($shardv) : null;

        if ($cfg['main']) {
            $targets[] = ['label' => "$app/main", 'layer' => 'child', 'app' => $app, 'role' => 'main',
                'shard_id' => null, 'conn' => $cfg['main'], 'dir' => $mdir, 'db_type' => 'main', 'target' => $mt,
                'fail_scope' => function_exists('wn_child_migration_scope') ? wn_child_migration_scope($mdir, 'main') : null];
        }
        $shards = $cfg['shards'];
        // Registry rows for this child (shard console). The child runtime migrates
        // only config shards, so registry-only shards are flagged as such.
        if ($db instanceof PDO && function_exists('shard_config_rows') && function_exists('wn_secret_decrypt')) {
            $names = array_column($shards, 'name');
            foreach (shard_config_rows('child', $app, ['active', 'draining']) as $r) {
                if (in_array($r['db_name'], $names, true)) continue;
                $pass = wn_secret_decrypt($r['db_pass_enc']);
                if ($pass === false) { $notes[] = "$app: registry shard {$r['shard_id']} password undecryptable — not audited"; continue; }
                $shards['registry:' . $r['shard_id']] = ['host' => $r['db_host'] ?: 'localhost',
                    'name' => $r['db_name'], 'user' => $r['db_user'], 'pass' => $pass];
            }
        }
        foreach ($shards as $id => $conn) {
            $targets[] = ['label' => "$app/shard/$id", 'layer' => 'child', 'app' => $app, 'role' => 'shard',
                'shard_id' => (string)$id, 'conn' => $conn, 'dir' => $mdir, 'db_type' => 'shard', 'target' => $st,
                // Registry-only shards are never migrated by the child runtime, so they carry no failure record.
                'fail_scope' => (function_exists('wn_child_migration_scope') && strpos((string)$id, 'registry:') !== 0)
                    ? wn_child_migration_scope($mdir, 'shard', $id) : null];
        }
    }
    return [$targets, $notes];
}

// ─── RUN ────────────────────────────────────────────────────────────────────

/**
 * Audit every discovered database. $only: optional substring filter on label.
 */
function schema_audit_run($only = '', $include_info = true) {
    global $db;
    list($targets, $notes) = schema_audit_targets();
    $sets = [];
    $models = [];
    $dbs = [];
    $summary = ['databases' => 0, 'ok' => 0, 'drift' => 0, 'uncertain_only' => 0, 'errors' => 0, 'migration_failures' => 0];

    foreach ($targets as $tg) {
        if ($only !== '' && stripos($tg['label'], $only) === false) continue;
        $summary['databases']++;
        $set_key = $tg['app'] . '/' . $tg['db_type'];  // + '@ledger' once known
        $files = schema_audit_migration_files($tg['dir'], $tg['db_type']);
        $row = ['label' => $tg['label'], 'layer' => $tg['layer'], 'app' => $tg['app'], 'role' => $tg['role'],
                'shard_id' => $tg['shard_id'], 'db_name' => null, 'migration_set' => $set_key,
                'ledger_version' => null, 'target_version' => $tg['target'],
                'latest_migration_file' => $files ? (float)schema_audit_last_key($files) : null];
        // The migration loop stops at a failed file and records it (migrationFunctions.php).
        if (!empty($tg['fail_scope']) && function_exists('wn_migration_failure')
            && ($fail = wn_migration_failure($tg['fail_scope'])) !== null) {
            $row['migration_failure'] = $fail;
            $summary['migration_failures']++;
        }

        $pdo = null;
        try {
            if ($tg['conn'] === null) {
                $pdo = $db;
            } else {
                $c = $tg['conn'];
                $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
                    $c['user'], $c['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            }
            $live = schema_audit_read_live($pdo);
        } catch (Throwable $e) {
            $code = ($e instanceof PDOException && isset($e->errorInfo[1])) ? $e->errorInfo[1] : $e->getCode();
            $row['status'] = 'error';
            $row['error'] = 'could not read schema (code ' . $code . ')';   // never the message: it can carry user@host
            $summary['errors']++;
            $dbs[] = $row;
            if ($tg['conn'] !== null) $pdo = null;
            continue;
        }
        if ($tg['conn'] !== null) $pdo = null;

        $row['db_name'] = $live['schema'];
        $row['ledger_version'] = $live['ledger'];
        if ($live['ledger'] === null) {
            $row['status'] = 'no_ledger';
            $summary['errors']++;
            $dbs[] = $row;
            continue;
        }
        $row['pending_migrations'] = [];
        foreach (array_keys($files) as $v) {
            if ((float)$v > $live['ledger'] + 0.00001 && ($tg['target'] === null || (float)$v <= $tg['target'] + 0.00001)) {
                $row['pending_migrations'][] = $v;
            }
        }

        $mkey = $tg['dir'] . '|' . $tg['db_type'] . '|' . $live['ledger'];
        if (!isset($models[$mkey])) {
            $models[$mkey] = schema_audit_build_expected($files, $live['ledger'], $tg['db_type'] . '/');
        }
        $model = $models[$mkey];
        $set_key .= '@' . number_format($live['ledger'], 1, '.', '');
        $row['migration_set'] = $set_key;
        if (!isset($sets[$set_key])) {
            $sets[$set_key] = ['files' => count($files), 'unparsed' => $model['unparsed'],
                               'runner_skipped' => $model['runner_skipped'],
                               'legacy_runner_skipped' => $model['legacy_runner_skipped']];
        }

        $d = schema_audit_diff($model, $live, $include_info);
        $n_drift = array_sum(array_map('count', $d['drift']));
        $row['status'] = $n_drift ? 'drift' : ($d['uncertain'] ? 'uncertain' : 'ok');
        $summary[$n_drift ? 'drift' : ($d['uncertain'] ? 'uncertain_only' : 'ok')]++;
        $row['drift'] = array_filter($d['drift']);
        if ($d['uncertain']) $row['uncertain'] = $d['uncertain'];
        if ($include_info) $row['info'] = array_filter($d['info']);
        $dbs[] = $row;
    }
    return ['summary' => $summary, 'databases' => $dbs, 'migration_sets' => $sets, 'notes' => $notes];
}

function schema_audit_last_key($arr) {
    $keys = array_keys($arr);
    return $keys ? end($keys) : null;
}
