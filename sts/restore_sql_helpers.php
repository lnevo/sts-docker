<?php
/**
 * Shared STS backup SQL restore (#-delimited dumps from backup_tables.php).
 *
 * MariaDB/MySQL with lower_case_table_names=2 (common in Docker Desktop on macOS)
 * stores table names case-preservingly but compares case-insensitively. A dump
 * that does DROP/CREATE `CK1` can leave an existing `ck1` in place, then fail
 * with: Table 'ck1' already exists.
 */

/**
 * Drop every table whose name matches $table case-insensitively, using the
 * exact stored TABLE_NAME from information_schema.
 *
 * Also issues DROP IF EXISTS for common case variants. On lower_case_table_names=2
 * (Docker Desktop / macOS), orphan .frm/.ibd pairs and dictionary ghosts can make
 * CREATE fail with "already exists" even when SHOW TABLES looks empty, and
 * DROP `CK1` may not remove a stored `ck1`.
 */
function sts_drop_table_case_insensitive($dbc, $table)
{
    $table = trim((string) $table, " \t\n\r\0\x0B`\"'");
    if ($table === '') {
        return true;
    }

    $candidates = [$table, strtolower($table), strtoupper($table)];

    $safe = mysqli_real_escape_string($dbc, $table);
    $rs = mysqli_query(
        $dbc,
        'SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND LOWER(TABLE_NAME) = LOWER(\'' . $safe . '\')'
    );
    if ($rs) {
        while ($row = mysqli_fetch_assoc($rs)) {
            $candidates[] = (string) $row['TABLE_NAME'];
        }
    }

    $ok = true;
    $seen = [];
    foreach ($candidates as $name) {
        $name = trim((string) $name, " \t\n\r\0\x0B`\"'");
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $exact = str_replace('`', '``', $name);
        if (!mysqli_query($dbc, 'DROP TABLE IF EXISTS `' . $exact . '`')) {
            $ok = false;
        }
    }
    // Clear any stale open handle after case-mismatched drops.
    mysqli_query($dbc, 'FLUSH TABLES');
    return $ok;
}

/**
 * Extract a table name from DROP TABLE / CREATE TABLE statement text.
 */
function sts_sql_statement_table_name($sql_cmd)
{
    if (preg_match(
        '/^\s*(?:DROP|CREATE)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`([^`]+)`/i',
        $sql_cmd,
        $m
    )) {
        return $m[1];
    }
    if (preg_match(
        '/^\s*(?:DROP|CREATE)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?([^\s(;]+)/i',
        $sql_cmd,
        $m
    )) {
        return trim($m[1], "\"'");
    }
    return '';
}

/**
 * Run one #-delimited backup statement. DROP/CREATE TABLE are made
 * case-safe for lower_case_table_names=2. DROP failures are ignored
 * (legacy behavior). Returns [ok, error_message].
 */
function sts_restore_sql_command($dbc, $sql_cmd)
{
    $sql_cmd = trim((string) $sql_cmd);
    if ($sql_cmd === '') {
        return [true, ''];
    }

    $is_drop = (bool) preg_match('/^\s*DROP\s+TABLE\b/i', $sql_cmd);
    $is_create = (bool) preg_match('/^\s*CREATE\s+TABLE\b/i', $sql_cmd);
    if ($is_drop || $is_create) {
        $table = sts_sql_statement_table_name($sql_cmd);
        if ($table !== '') {
            sts_drop_table_case_insensitive($dbc, $table);
        }
        if ($is_drop) {
            return [true, ''];
        }
    }

    if (mysqli_query($dbc, $sql_cmd)) {
        return [true, ''];
    }

    $err = mysqli_error($dbc);
    if ($is_drop || stripos($sql_cmd, 'drop') !== false) {
        return [true, ''];
    }
    return [false, $err];
}

/**
 * Restore a full #-delimited STS backup SQL string.
 * Returns [ok, message].
 */
function sts_restore_sql_string($dbc, $sql_string)
{
    mysqli_query($dbc, 'SET FOREIGN_KEY_CHECKS=0');
    foreach (explode('#', (string) $sql_string) as $sql_cmd) {
        list($ok, $err) = sts_restore_sql_command($dbc, $sql_cmd);
        if (!$ok) {
            mysqli_query($dbc, 'SET FOREIGN_KEY_CHECKS=1');
            return [false, 'SQL error while restoring: ' . $err];
        }
    }
    mysqli_query($dbc, 'SET FOREIGN_KEY_CHECKS=1');
    return [true, 'restored successfully.'];
}

/**
 * Restore from a backup file path.
 * Returns [ok, message].
 */
function sts_restore_sql_file($dbc, $sql_file_path, $display_name = null)
{
    if (!is_file($sql_file_path)) {
        return [false, 'Selected SQL file was not found.'];
    }
    $name = $display_name !== null ? (string) $display_name : basename($sql_file_path);
    list($ok, $msg) = sts_restore_sql_string($dbc, file_get_contents($sql_file_path));
    if (!$ok) {
        return [false, $msg];
    }
    return [true, $name . ' restored successfully.'];
}
