<?php
/**
 * Task runner: php run_task.php <task-id> [--child]
 *
 * Started detached by api_task_create(). Loads tasks/<kind>.php, calls
 * api_task_kind_<kind>(array $spec, callable $log): array and records the
 * outcome in the task record. stdout/stderr are already appended to the .log
 * by the spawn redirect; $log lines go there too. No time limit by default.
 *
 * With spec.timeout_seconds the runner is a supervisor: it re-executes itself
 * with --child (same log, same task) and kills the child when the time is up;
 * the task is then marked failed "timed out after N seconds". The recorded pid
 * is the supervisor's, which stays alive while the child runs.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/tasks.php';
require_once dirname(__DIR__) . '/lib/tokens.php';

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
    api_restore_marker_clear($id);
    api_task_update($id, static function ($r) use ($status, $result, $error) {
        $r['status'] = $status;
        $r['finished_at'] = api_task_now();
        $r['result'] = $result;
        $r['error'] = $error;
        return $r;
    });
}

/** Run this script as --child and kill it after $timeout seconds. */
function run_task_supervise($id, $timeout, callable $log)
{
    $php = is_executable('/usr/local/bin/php') ? '/usr/local/bin/php' : PHP_BINARY;
    $log('timeout ' . $timeout . 's: running the task in a child process');
    // The child gets its own session and process group (setsid), so a timeout or an
    // admin "fail" can kill everything it spawned, not just the child.
    $setsid = is_executable('/usr/bin/setsid') ? ['/usr/bin/setsid'] : [];
    $proc = proc_open(array_merge($setsid, [$php, __FILE__, $id, '--child']), [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($proc)) {
        run_task_finish($id, 'failed', null, 'Could not start the task child process');
        return;
    }
    $cst = proc_get_status($proc);
    $cpid = (int) ($cst['pid'] ?? 0);
    if ($cpid > 0) {
        $cstart = api_task_proc_start($cpid);
        api_task_update($id, static function ($r) use ($cpid, $cstart) {
            if (($r['status'] ?? '') === 'running') {
                $r['child_pid'] = $cpid;
                $r['child_pid_start'] = $cstart;
            }
            return $r;
        });
    }
    $deadline = microtime(true) + $timeout;
    $timed_out = false;
    $exit = null;
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            $exit = $st['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            $timed_out = true;
            if ($setsid && $cpid > 1 && function_exists('posix_kill')) {
                @posix_kill(-$cpid, 9); // the child's whole process group
            }
            proc_terminate($proc, 9);
            break;
        }
        usleep(200000);
    }
    proc_close($proc);
    $cur = api_task_read($id);
    if ($cur === null || $cur['status'] !== 'running') {
        return; // the child finished and recorded its own outcome
    }
    $after = run_task_session_nbr();
    if ($timed_out) {
        $log('task timed out after ' . $timeout . ' seconds; child killed');
        run_task_finish($id, 'failed', ['session_before' => null, 'session_after' => $after], 'timed out after ' . $timeout . ' seconds');
        return;
    }
    $log('task child ended without recording an outcome (exit ' . var_export($exit, true) . ')');
    run_task_finish($id, 'failed', ['session_before' => null, 'session_after' => $after], 'Task child exited unexpectedly (exit ' . var_export($exit, true) . ')');
}

/** Fail the task when its creator's token was revoked since it was queued (env admin is never revoked). */
function run_task_creator_revoked(array $rec)
{
    $tid = $rec['created_by_id'] ?? null;
    if (!is_string($tid) || $tid === '' || $tid === 'env') {
        return false;
    }
    foreach (api_tokens_read() as $t) {
        if (($t['id'] ?? '') === $tid) {
            return !empty($t['revoked_at']);
        }
    }
    return true; // token removed
}

$is_child = in_array('--child', $argv, true);
$timeout = isset($rec['spec']['timeout_seconds']) && is_numeric($rec['spec']['timeout_seconds'])
    ? (int) $rec['spec']['timeout_seconds'] : 0;

if (!$is_child && run_task_creator_revoked($rec)) {
    $log('task not started: token revoked');
    run_task_finish($id, 'failed', null, 'token revoked');
    exit(1);
}

if ($is_child) {
    // The supervisor already marked the task running and owns its pid.
    $started = api_task_read($id);
} else {
    $started = api_task_update($id, static function ($r) {
        $r['status'] = 'running';
        $r['pid'] = getmypid();
        $r['pid_start'] = api_task_proc_start(getmypid());
        $r['started_at'] = api_task_now();
        return $r;
    });
}
$GLOBALS['run_task_id'] = $id;

if (!$is_child && $timeout > 0) {
    run_task_supervise($id, $timeout, $log);
    exit(0);
}

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
