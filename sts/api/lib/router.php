<?php
/**
 * Route registry and dispatcher.
 *
 *   api_route('GET', '/cars/{car}', 'handler_fn_or_closure', 'reader', ['summary' => '...']);
 *
 * Patterns are relative to /v1 and use {name} segments ({name*} swallows the
 * rest of the path). Roles: open, reader, operator, editor, admin.
 * A handler receives $req = [method, params, query, path, user, legacy] and either
 * returns the `data` value (wrapped in the envelope) or calls api_ok()/api_error().
 * Legacy routes (opts['legacy'] => true) use an absolute pattern such as
 * '/wagon/{rest*}' and keep the old response shapes.
 */

if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

$GLOBALS['api_routes'] = [];

function api_route($method, $pattern, $handler, $role = 'reader', array $opts = [])
{
    $regex = preg_replace_callback('/\{(\w+)(\*?)\}/', static function ($m) {
        return $m[2] === '*' ? '(?P<' . $m[1] . '>.*)' : '(?P<' . $m[1] . '>[^/]+)';
    }, $pattern);
    $GLOBALS['api_routes'][] = [
        'method' => strtoupper($method),
        'pattern' => $pattern,
        'regex' => '#^' . $regex . '/?$#',
        'handler' => $handler,
        'role' => $role,
        'legacy' => !empty($opts['legacy']),
        'opts' => $opts,
    ];
}

/** Registered v1 routes (for docs/tests): [method, pattern, role]. */
function api_routes_list()
{
    $out = [];
    foreach ($GLOBALS['api_routes'] as $r) {
        if (!$r['legacy']) {
            $out[] = [$r['method'], $r['pattern'], $r['role']];
        }
    }
    return $out;
}

/** Request path relative to /sts/api with any leading /index.php removed. */
function api_request_path()
{
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = preg_replace('#^/sts/api(?=/|$)#', '', $path);
    $path = preg_replace('#^/index\.php(?=/|$)#', '', $path);
    return '/' . trim($path, '/');
}

function api_cors_headers()
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
}

function api_dispatch()
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = api_request_path();

    $is_v1 = ($path === '/v1' || strpos($path, '/v1/') === 0);
    $rel = $is_v1 ? (substr($path, 3) ?: '/') : $path;

    $allowed = [];
    foreach ($GLOBALS['api_routes'] as $r) {
        if ($r['legacy'] === $is_v1) {
            continue;
        }
        if (!preg_match($r['regex'], $rel, $m)) {
            continue;
        }
        if ($r['method'] !== '*' && $r['method'] !== $method && !($method === 'HEAD' && $r['method'] === 'GET')) {
            $allowed[] = $r['method'];
            continue;
        }
        $params = [];
        foreach ($m as $k => $v) {
            if (is_string($k)) {
                // {name*} captures stay raw: legacy handlers decode themselves.
                $params[$k] = strpos($r['pattern'], '{' . $k . '*}') !== false ? $v : rawurldecode($v);
            }
        }
        $user = api_require_role($r['role'], $r['legacy']);
        $req = [
            'method' => $method, 'params' => $params, 'query' => $_GET,
            'path' => $rel, 'user' => $user, 'legacy' => $r['legacy'],
        ];
        $result = call_user_func($r['handler'], $req);
        if ($r['legacy']) {
            return; // legacy handlers answer for themselves
        }
        api_ok($result);
    }

    if ($allowed) {
        header('Allow: ' . implode(', ', array_unique($allowed)));
        api_error(405, 'Method not allowed for this resource');
    }
    if ($is_v1) {
        api_error(404, 'No such API route');
    }
    api_legacy_error(404, $rel === '/' ? 'API endpoint not found' : 'Unknown endpoint');
}
