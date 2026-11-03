<?php
/**
 * STS REST API front controller.
 *
 * Apache sends every non-file path under /sts/api/ here (FallbackResource, see
 * docker/apache-sts-api.conf). Routes live in v1/routes_*.php and register with
 * api_route(). Everything runs inside output buffering: stray output (open_db()
 * migration prints, notices) is discarded and logged, never mixed into JSON.
 */

define('STS_API_BOOT', 1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('html_errors', '0');
@set_time_limit(60);

ob_start();
$GLOBALS['api_responded'] = false;

require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/input.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/router.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/tasks.php';

// Last line of defence: any fatal or unanswered request becomes a JSON 500.
register_shutdown_function(static function () {
    if ($GLOBALS['api_responded']) {
        return;
    }
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        error_log('[sts-api] fatal: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    }
    $GLOBALS['api_responded'] = true;
    $v1 = strpos(api_request_path(), '/v1') === 0;
    while (ob_get_level() > 0) {
        $stray = trim((string) ob_get_clean());
        if ($stray !== '') {
            error_log('[sts-api] discarded stray output: ' . substr($stray, 0, 500));
        }
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo $v1
        ? '{"error":{"code":"internal","message":"Internal server error"}}'
        : '{"error":"Internal server error"}';
});

api_cors_headers();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    $GLOBALS['api_responded'] = true;
    api_discard_output();
    http_response_code(204);
    exit;
}

// Register routes: every v1/routes_*.php that exists (read, operate, edit, admin, ...).
$route_files = glob(__DIR__ . '/v1/routes_*.php') ?: [];
sort($route_files);
foreach ($route_files as $file) {
    require_once $file;
}

try {
    api_dispatch();
} catch (Throwable $e) {
    error_log('[sts-api] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (strpos(api_request_path(), '/v1') === 0) {
        api_error(500, 'Internal server error');
    }
    api_legacy_error(500, 'Internal server error');
}
