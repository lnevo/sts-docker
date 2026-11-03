<?php
/** Request input: JSON body, list pagination, whitelisted filters. */

if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

/** Decoded JSON body as an array ([] when empty). Invalid JSON is a 400. */
function api_body()
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $body = [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error(400, 'Request body must be a JSON object');
    }
    return $body = $data;
}

/** [limit, offset] from the query string: default 100, max 1000. */
function api_pagination(array $query)
{
    $limit = 100;
    $offset = 0;
    foreach (['limit', 'offset'] as $k) {
        if (isset($query[$k]) && !is_string($query[$k])) {
            api_error(422, "$k must be a single value", 'invalid');
        }
    }
    if (isset($query['limit'])) {
        if (!ctype_digit((string) $query['limit']) || (int) $query['limit'] < 1) {
            api_error(422, 'limit must be a positive integer', 'invalid');
        }
        $limit = min(1000, (int) $query['limit']);
    }
    if (isset($query['offset'])) {
        if (!ctype_digit((string) $query['offset'])) {
            api_error(422, 'offset must be a non-negative integer', 'invalid');
        }
        $offset = (int) $query['offset'];
    }
    return [$limit, $offset];
}

/** String query filter ('' when absent). */
function api_query_str(array $query, $key)
{
    $v = $query[$key] ?? '';
    if (!is_string($v)) {
        api_error(422, "$key must be a single value", 'invalid');
    }
    return trim($v);
}

/** Query filter that must be one of $allowed (or absent -> $default). */
function api_query_enum(array $query, $key, array $allowed, $default = '')
{
    $v = api_query_str($query, $key);
    if ($v === '') {
        return $default;
    }
    if (!in_array($v, $allowed, true)) {
        api_error(422, "$key must be one of: " . implode(', ', $allowed), 'invalid');
    }
    return $v;
}
