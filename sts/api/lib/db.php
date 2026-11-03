<?php
/**
 * Database helpers: prepared statements only.
 * $types is a mysqli bind string ('' for none), $params the matching values.
 */

if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/open_db.php';

function api_db()
{
    static $dbc = null;
    if ($dbc === null) {
        $dbc = open_db();
        // open_db() lowers error_reporting to E_ERROR; restore the API setting.
        error_reporting(E_ALL);
    }
    return $dbc;
}

function api_db_prepare($sql, $types, array $params)
{
    $dbc = api_db();
    $stmt = $dbc->prepare($sql);
    if (!$stmt) {
        api_internal_error('prepare failed: ' . $dbc->error . ' | ' . $sql);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        api_internal_error('execute failed: ' . $stmt->error . ' | ' . $sql);
    }
    return $stmt;
}

/** All rows as associative arrays. */
function api_db_all($sql, $types = '', array $params = [])
{
    $stmt = api_db_prepare($sql, $types, $params);
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/** First row or null. */
function api_db_one($sql, $types = '', array $params = [])
{
    $rows = api_db_all($sql, $types, $params);
    return $rows[0] ?? null;
}

/** First column of the first row, or null. */
function api_db_value($sql, $types = '', array $params = [])
{
    $row = api_db_one($sql, $types, $params);
    return $row === null ? null : reset($row);
}

/** Run a write statement; returns affected rows. */
function api_db_exec($sql, $types = '', array $params = [])
{
    $stmt = api_db_prepare($sql, $types, $params);
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

/**
 * Run a list query with total count. $from_where is everything after SELECT
 * ("FROM ... WHERE ..."); $select the column list; $order a fixed ORDER BY.
 * Returns [rows, total].
 */
function api_db_page($select, $from_where, $order, $types, array $params, $limit, $offset)
{
    $total = (int) api_db_value('SELECT COUNT(*) ' . $from_where, $types, $params);
    $rows = api_db_all(
        "SELECT $select $from_where ORDER BY $order LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$limit, $offset])
    );
    return [$rows, $total];
}

/** Current session number. */
function api_session_nbr()
{
    return (int) api_db_value("SELECT setting_value FROM settings WHERE setting_name = 'session_nbr'");
}

/** True when the id-or-text identifier looks like a positive integer id. */
function api_is_id($v)
{
    return is_string($v) || is_int($v) ? ctype_digit((string) $v) && (int) $v > 0 : false;
}
