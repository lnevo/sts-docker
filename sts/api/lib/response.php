<?php
/**
 * v1 response helpers: JSON envelope, error format, output discipline.
 *
 * Success: {"data": ..., "meta": {...}}   Error: {"error": {"code", "message"}}
 * Both helpers discard any stray buffered output (logged to stderr) and exit.
 */

if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

const STS_API_ERROR_CODES = [
    400 => 'bad_request', 401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found',
    405 => 'method_not_allowed', 409 => 'conflict', 422 => 'invalid', 500 => 'internal',
];

/** Drop everything buffered so far; log it if it was not empty. */
function api_discard_output()
{
    $stray = '';
    while (ob_get_level() > 0) {
        $stray .= (string) ob_get_clean();
    }
    $stray = trim($stray);
    if ($stray !== '') {
        error_log('[sts-api] discarded stray output: ' . substr($stray, 0, 500));
    }
}

function api_json_encode($payload)
{
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
    $json = json_encode($payload, $flags);
    if ($json === false) {
        // The legacy schema is latin1; re-encode strings that are not valid UTF-8.
        array_walk_recursive($payload, static function (&$v) {
            if (is_string($v) && !mb_check_encoding($v, 'UTF-8')) {
                $v = iconv('CP1252', 'UTF-8//IGNORE', $v);
            }
        });
        $json = json_encode($payload, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    return $json === false ? '{"error":{"code":"internal","message":"Response encoding failed"}}' : $json;
}

function api_emit($status, $payload)
{
    $GLOBALS['api_responded'] = true;
    api_discard_output();
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo api_json_encode($payload);
    exit;
}

/** Success envelope. $meta (optional) carries total/limit/offset for lists. */
function api_ok($data, ?array $meta = null, $status = 200)
{
    $payload = ['data' => $data];
    if ($meta !== null) {
        $payload['meta'] = $meta;
    }
    api_emit($status, $payload);
}

/** Paginated list: data = rows, meta = {total, limit, offset}. */
function api_ok_list(array $rows, $total, $limit, $offset)
{
    api_ok($rows, ['total' => (int) $total, 'limit' => (int) $limit, 'offset' => (int) $offset]);
}

/** Error envelope. $code defaults from the status. A 500 never carries internals. */
function api_error($status, $message, $code = null)
{
    $code = $code ?? (STS_API_ERROR_CODES[$status] ?? 'bad_request');
    api_emit($status, ['error' => ['code' => $code, 'message' => (string) $message]]);
}

/** Log internals, answer a generic 500. */
function api_internal_error($detail = '')
{
    if ($detail !== '') {
        error_log('[sts-api] internal error: ' . $detail);
    }
    api_error(500, 'Internal server error');
}
