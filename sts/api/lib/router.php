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
 * Write routes take part in the maintenance lock (409 while an exclusive task is
 * queued or running) unless opts['maint'] is 'none' (never blocked) or 'self'
 * (the handler checks, atomically, when it creates the exclusive task).
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

/** host:port of an Origin header value, or null when it is not a usable origin. */
function api_origin_hostport($origin)
{
    $p = parse_url((string) $origin);
    if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) {
        return null;
    }
    $scheme = strtolower($p['scheme']);
    $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
    return strtolower($p['host']) . ':' . (int) $port;
}

/** host:port of the request's Host header, defaulting the port from the origin's scheme. */
function api_host_hostport($host, $origin)
{
    $scheme = strtolower((string) parse_url((string) $origin, PHP_URL_SCHEME));
    $p = parse_url('//' . trim((string) $host));
    if (!is_array($p) || empty($p['host'])) {
        return null;
    }
    $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
    return strtolower($p['host']) . ':' . (int) $port;
}

/**
 * Cross-site guard. Returns null when no Origin header was sent (devices, curl,
 * helpers), true when the Origin's host:port equals the request Host, else false.
 */
function api_origin_same_host()
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return null;
    }
    $o = api_origin_hostport($origin);
    $h = api_host_hostport($_SERVER['HTTP_HOST'] ?? '', $origin);
    return $o !== null && $h !== null && $o === $h;
}

function api_method_is_safe($method)
{
    return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
}

/**
 * CORS: `*` for GET/HEAD. Writes (and preflights asking for a write method) get
 * Access-Control-Allow-Origin only when the Origin is the same host.
 */
function api_cors_headers()
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    $wanted = $method === 'OPTIONS'
        ? strtoupper($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? 'GET')
        : $method;
    if (in_array($wanted, ['GET', 'HEAD'], true)) {
        header('Access-Control-Allow-Origin: *');
    } elseif (api_origin_same_host() === true) {
        header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    }
}

function api_dispatch()
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = api_request_path();

    $is_v1 = ($path === '/v1' || strpos($path, '/v1/') === 0);
    if (!api_method_is_safe($method) && api_origin_same_host() === false) {
        $msg = 'Cross-site write rejected: Origin does not match Host';
        if ($is_v1) {
            api_error(403, $msg);
        }
        api_legacy_error(403, $msg);
    }
    $rel = $is_v1 ? (substr($path, 3) ?: '/') : $path;

    // Literal paths win over {name} patterns (PUT /workflows/active before PUT /workflows/{name}),
    // whatever order the route files register in; the sort is stable.
    $routes = $GLOBALS['api_routes'];
    usort($routes, static function ($a, $b) {
        return substr_count($a['pattern'], '{') <=> substr_count($b['pattern'], '{');
    });
    $allowed = [];
    foreach ($routes as $r) {
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
        if (!api_method_is_safe($method) && ($r['opts']['maint'] ?? 'guard') === 'guard') {
            api_maintenance_enter($r['legacy']);
        }
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
