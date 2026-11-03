<?php
/**
 * Task runner: php run_task.php <task-id>
 *
 * Started detached by api_task_create(). Loads tasks/<kind>.php, calls
 * api_task_kind_<kind>(array $spec, callable $log): array and records the
 * outcome in the task record. stdout/stderr are already appended to the .log
 * by the spawn redirect; $log lines go there too. No time limit.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/tasks.php';

$id = $argv[1] ?? '';
$rec = api_task_id_valid($id) ? api_task_read($id) : null;
if ($rec === null) {
    fwrite(STDERR, "run_task: no such task\n");
    exit(1);
}

set_time_limit(0);
ignore_user_abort(true);
ini_set('display_errors', '1');
ini_set('log_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$stsDir = dirname(__DIR__, 2);
chdir($stsDir);

$log = static function ($message) {
    fwrite(STDOUT, '[' . gmdate('H:i:s') . '] ' . $message . PHP_EOL);
};

/** Current session_nbr via a short-lived connection, or null. */
function run_task_session_nbr()
{
    try {
        require_once dirname(__DIR__, 2) . '/open_db.php';
        $dbc = open_db();
        $rs = mysqli_query($dbc, 'SELECT setting_value FROM settings WHERE setting_name = "session_nbr"');
        $row = $rs ? mysqli_fetch_array($rs) : null;
        mysqli_close($dbc);
        return $row ? (int) $row['setting_value'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function run_task_finish($id, $status, $result, $error)
{
    api_task_update($id, static function ($r) use ($status, $result, $error) {
        $r['status'] = $status;
        $r['finished_at'] = api_task_now();
        $r['result'] = $result;
        $r['error'] = $error;
        return $r;
    });
}

$started = api_task_update($id, static function ($r) {
    $r['status'] = 'running';
    $r['pid'] = getmypid();
    $r['started_at'] = api_task_now();
    return $r;
});
$GLOBALS['run_task_id'] = $id;

// A fatal error (or exit) that skips the normal finish still ends the task.
register_shutdown_function(static function () {
    $cur = api_task_read($GLOBALS['run_task_id']);
    if ($cur !== null && $cur['status'] === 'running') {
        $err = error_get_last();
        run_task_finish($GLOBALS['run_task_id'], 'failed', null, $err ? 'Fatal error: ' . $err['message'] : 'Task exited unexpectedly');
    }
});

$kind = $started['kind'];
$spec = is_array($started['spec']) ? $started['spec'] : [];
$result = ['session_before' => null, 'session_after' => null];
try {
    $file = __DIR__ . '/../tasks/' . $kind . '.php';
    if (!api_task_kind_valid($kind) || !is_file($file)) {
        throw new ApiTaskFailure('Unknown task kind');
    }
    require_once $file;
    $fn = 'api_task_kind_' . $kind;
    if (!function_exists($fn)) {
        throw new ApiTaskFailure('Task kind has no handler');
    }
    $log('task ' . $id . ' (' . $kind . ') started');
    $result['session_before'] = run_task_session_nbr();
    if (isset($spec['seed']) && is_numeric($spec['seed'])) {
        putenv('STS_SIM_SEED=' . (int) $spec['seed']);
        mt_srand((int) $spec['seed']);
        $log('seed ' . (int) $spec['seed']);
    }
    $out = $fn($spec, $log);
    $result['session_after'] = run_task_session_nbr();
    $result['output'] = $out;
    $log('task finished');
    run_task_finish($id, 'succeeded', $result, null);
    exit(0);
} catch (ApiTaskFailure $e) {
    $result['session_after'] = run_task_session_nbr();
    $result['output'] = $e->result ?: null;
    $log('task failed: ' . $e->getMessage());
    run_task_finish($id, 'failed', $result, $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    $log('task failed: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    $result['session_after'] = run_task_session_nbr();
    run_task_finish($id, 'failed', $result, 'Unexpected error: ' . substr($e->getMessage(), 0, 300));
    exit(1);
}
