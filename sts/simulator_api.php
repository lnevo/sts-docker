<?php
/**
 * HTTP API for session workflow simulator.
 * GET  ?action=run_options|status
 * POST ?action=run|run_section|status
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('html_errors', '0');
// Multi-session editor runs commonly exceed Apache's default 30s PHP limit.
@set_time_limit(0);
@ini_set('max_execution_time', '0');
ob_start();

header('Content-Type: application/json; charset=utf-8');

$sts_dir = __DIR__;
require_once $sts_dir . '/open_db.php';
require_once $sts_dir . '/session_simulator_helpers.php';

$session_dir = operational_steps_editor_dir();
$body = $_SERVER['REQUEST_METHOD'] === 'POST' ? session_simulator_body() : [];
$action = $_GET['action'] ?? $_POST['action'] ?? ($body['action'] ?? '');

register_shutdown_function(static function () {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode([
        'ok' => false,
        'error' => $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'],
    ], JSON_UNESCAPED_SLASHES);
});

function simulator_api_json($data, $code = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    switch ($action) {
        case 'run_options':
            $recipe = session_simulator_load_recipe($session_dir, $body);
            $dbc = open_db();
            $payload = session_simulator_run_options($dbc, $session_dir, $recipe);
            mysqli_close($dbc);
            simulator_api_json($payload);

        case 'status':
            $dbc = open_db();
            $config = $body['config'] ?? [];
            $payload = session_simulator_status($dbc, is_array($config) ? $config : []);
            mysqli_close($dbc);
            simulator_api_json($payload);

        case 'run':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                simulator_api_json(['ok' => false, 'error' => 'POST required'], 405);
            }
            session_simulator_maybe_save_recipe($session_dir, $body);
            $recipe = session_simulator_load_recipe($session_dir, $body);
            $dbc = open_db();
            $result = session_simulator_run($dbc, $recipe, [
                'start_step' => (int) ($body['start_step'] ?? 0),
                'stop_step' => (int) ($body['stop_step'] ?? 0),
                'skip_steps' => $body['skip_steps'] ?? '',
                'session_count' => (int) ($body['session_count'] ?? 1),
                'format' => $body['format'] ?? 'all',
                'config' => is_array($body['config'] ?? null) ? $body['config'] : [],
            ]);
            mysqli_close($dbc);
            simulator_api_json($result);

        case 'run_section':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                simulator_api_json(['ok' => false, 'error' => 'POST required'], 405);
            }
            $section_id = trim((string) ($body['section_id'] ?? $body['section'] ?? ''));
            if ($section_id === '') {
                simulator_api_json(['ok' => false, 'error' => 'Missing section_id'], 400);
            }
            session_simulator_maybe_save_recipe($session_dir, $body);
            $recipe = session_simulator_load_recipe($session_dir, $body);
            $dbc = open_db();
            $result = session_simulator_run_section($dbc, $recipe, $section_id, [
                'start_step' => (int) ($body['start_step'] ?? 0),
                'stop_step' => (int) ($body['stop_step'] ?? 0),
                'skip_steps' => $body['skip_steps'] ?? '',
                'session_count' => (int) ($body['session_count'] ?? 1),
                'format' => $body['format'] ?? 'all',
                'min_sessions' => (int) ($body['min_sessions'] ?? 3),
                'max_sessions' => (int) ($body['max_sessions'] ?? 12),
                'config' => is_array($body['config'] ?? null) ? $body['config'] : [],
            ]);
            mysqli_close($dbc);
            if (empty($result['ok'])) {
                simulator_api_json($result, 400);
            }
            simulator_api_json($result);

        default:
            simulator_api_json([
                'ok' => false,
                'error' => 'Unknown action',
                'actions' => ['run_options', 'status', 'run', 'run_section'],
            ], 400);
    }
} catch (Throwable $e) {
    simulator_api_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
