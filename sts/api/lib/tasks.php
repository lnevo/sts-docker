<?php
/**
 * Background tasks: a JSON record plus a .log under <data dir>/tasks, run by
 * sts/api/bin/run_task.php in a detached PHP process.
 *
 *   $rec = api_task_create('warm_start', $spec, $user, ['exclusive' => true]);
 *
 * Statuses: queued -> running -> succeeded | failed. A queued or running task
 * whose process is gone is turned into 'failed' the next time it is read.
 * Usable from the web (errors via api_error) and from the runner CLI.
 */

if (!defined('STS_API_BOOT') && PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** A task kind throws this to fail the task while still recording a result. */
class ApiTaskFailure extends RuntimeException
{
    /** @var array */
    public $result = [];

    public function __construct($message, array $result = [])
    {
        parent::__construct($message);
        $this->result = $result;
    }
}

/** Newest task records (and logs) kept per stack. */
const STS_API_TASKS_KEEP = 200;

/** When running as root (docker exec), hand a file to www-data so Apache keeps access. */
function api_tasks_chown_www($path)
{
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @chown($path, 'www-data');
        @chgrp($path, 'www-data');
    }
}

function api_tasks_dir()
{
    $dir = rtrim(getenv('STS_API_DATA_DIR') ?: '/var/lib/sts', '/') . '/tasks';
    if (!is_dir($dir)) {
        $old = umask(0027);
        @mkdir($dir, 0750, true);
        umask($old);
        api_tasks_chown_www($dir);
    }
    return $dir;
}

/** Failure of the task store: an API 500 on the web, a RuntimeException in the CLI. */
function api_tasks_fail($detail)
{
    if (function_exists('api_internal_error')) {
        api_internal_error($detail);
    }
    throw new RuntimeException($detail);
}

/**
 * Open (creating if needed) and exclusively lock a lock file in the tasks dir.
 * $name '.lock' guards the records; '.maint.lock' orders writes against
 * exclusive tasks. Returns the open handle.
 */
function api_tasks_lock_open($name, $op = LOCK_EX)
{
    $path = api_tasks_dir() . '/' . $name;
    $old = umask(0027);
    $fh = @fopen($path, 'c');
    umask($old);
    if (!$fh) {
        api_tasks_fail("Cannot open task lock $path");
    }
    api_tasks_chown_www($path);
    if (!flock($fh, $op)) {
        fclose($fh);
        api_tasks_fail("Cannot lock $path");
    }
    return $fh;
}

function api_task_id_valid($id)
{
    return is_string($id) && preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $id) === 1;
}

function api_task_kind_valid($kind)
{
    return is_string($kind) && preg_match('/^[a-z][a-z0-9_]{0,40}$/', $kind) === 1;
}

function api_task_path($id, $ext = 'json')
{
    return api_tasks_dir() . '/' . $id . '.' . $ext;
}

function api_task_now()
{
    return gmdate('c');
}

/** Marker file that tells open_db() a restore owns the schema (content: the task id). */
function api_restore_marker_path()
{
    return rtrim(getenv('STS_API_DATA_DIR') ?: '/var/lib/sts', '/') . '/restore_in_progress';
}

function api_restore_marker_set($id)
{
    $old = umask(0027);
    @file_put_contents(api_restore_marker_path(), $id);
    umask($old);
    api_tasks_chown_www(api_restore_marker_path());
}

/** Remove the marker; with $id only when it belongs to that task. */
function api_restore_marker_clear($id = null)
{
    $p = api_restore_marker_path();
    if ($id === null || trim((string) @file_get_contents($p)) === $id) {
        @unlink($p);
    }
}

/** Task id of an active restore, or null. A marker whose task is no longer running is removed. */
function api_restore_in_progress()
{
    $p = api_restore_marker_path();
    if (!file_exists($p)) {
        return null;
    }
    $id = trim((string) @file_get_contents($p));
    $rec = $id !== '' ? api_task_get($id) : null;
    if ($rec !== null && in_array($rec['status'] ?? '', ['queued', 'running'], true)) {
        return $id;
    }
    @unlink($p);
    return null;
}

/** Raw record by id, or null. No stale check. */
function api_task_read($id)
{
    if (!api_task_id_valid($id)) {
        return null;
    }
    $raw = @file_get_contents(api_task_path($id));
    $rec = $raw === false ? null : json_decode($raw, true);
    return is_array($rec) ? $rec : null;
}

/** Atomically replace a record (write temp file, rename). */
function api_task_write(array $rec)
{
    $path = api_task_path($rec['id']);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT);
    $old = umask(0027);
    $ok = $json !== false && file_put_contents($tmp, $json) !== false;
    umask($old);
    if (!$ok) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write task record ' . $rec['id']);
    }
    api_tasks_chown_www($tmp);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write task record ' . $rec['id']);
    }
}

/** Read-modify-write under the tasks lock. $fn(array $rec): array. */
function api_task_update($id, callable $fn)
{
    if (!empty($GLOBALS['api_task_lock_held'])) {
        $rec = api_task_read($id);
        if ($rec === null) {
            return null;
        }
        $rec = $fn($rec);
        api_task_write($rec);
        return $rec;
    }
    $lock = api_tasks_lock_open('.lock');
    $GLOBALS['api_task_lock_held'] = true;
    try {
        $rec = api_task_read($id);
        if ($rec === null) {
            return null;
        }
        $rec = $fn($rec);
        api_task_write($rec);
        return $rec;
    } finally {
        $GLOBALS['api_task_lock_held'] = false;
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Process start time (clock ticks since boot, /proc/<pid>/stat field 22), or null. */
function api_task_proc_start($pid)
{
    $stat = @file_get_contents('/proc/' . (int) $pid . '/stat');
    if ($stat === false) {
        return null;
    }
    $close = strrpos($stat, ')');
    if ($close === false) {
        return null;
    }
    // After "pid (comm) " the fields run from field 3 (state); starttime is field 22.
    $f = explode(' ', substr($stat, $close + 2));
    return isset($f[19]) && ctype_digit($f[19]) ? $f[19] : null;
}

/**
 * Is the process alive and still the one that was recorded? $start is the
 * recorded start time: a reused pid has a different one.
 */
function api_task_pid_alive($pid, $start = null)
{
    $pid = (int) $pid;
    if ($pid <= 0) {
        return false;
    }
    $stat = @file_get_contents('/proc/' . $pid . '/stat');
    if ($stat !== false) {
        // "pid (comm) S ..." - state Z is a zombie, i.e. finished.
        $close = strrpos($stat, ')');
        $state = $close === false ? '' : substr($stat, $close + 2, 1);
        if ($state === 'Z' || $state === 'X') {
            return false;
        }
        if ($start !== null && $start !== '' && api_task_proc_start($pid) !== (string) $start) {
            return false;
        }
        return true;
    }
    if (is_dir('/proc/self')) {
        return false; // procfs is mounted and has no such pid
    }
    return function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
}

/**
 * Is the task's work still going? The runner/supervisor (pid) or, for a task
 * with a timeout, its worker child (child_pid, own process group) counts.
 */
function api_task_alive(array $rec)
{
    return api_task_pid_alive($rec['pid'] ?? 0, $rec['pid_start'] ?? null)
        || (!empty($rec['child_pid']) && api_task_pid_alive($rec['child_pid'], $rec['child_pid_start'] ?? null));
}

/**
 * Kill a task's processes: the worker child's whole process group (it is a
 * group leader, started with setsid), the child, and the supervisor/runner.
 * Only pids whose recorded start time still matches are signalled.
 */
function api_task_kill(array $rec)
{
    if (!function_exists('posix_kill')) {
        return;
    }
    $targets = [[$rec['child_pid'] ?? 0, $rec['child_pid_start'] ?? null], [$rec['pid'] ?? 0, $rec['pid_start'] ?? null]];
    foreach ($targets as [$pid, $start]) {
        $pid = (int) $pid;
        if ($pid > 1 && $pid !== getmypid() && api_task_pid_alive($pid, $start)) {
            @posix_kill(-$pid, 9);
            @posix_kill($pid, 9);
        }
    }
}

/** Turn a queued/running task whose process is gone into 'failed'. Returns the (possibly updated) record. */
function api_task_reconcile(array $rec)
{
    $status = $rec['status'] ?? '';
    if ($status !== 'running' && $status !== 'queued') {
        return $rec;
    }
    $pid = (int) ($rec['pid'] ?? 0);
    $dead = false;
    $why = '';
    if ($status === 'running') {
        $dead = !api_task_alive($rec);
        $why = 'Task process ' . $pid . ' is gone without finishing';
    } else {
        $age = time() - (int) strtotime($rec['created_at'] ?? 'now');
        $dead = $age > 120;
        $why = 'Task runner never started';
    }
    if (!$dead) {
        return $rec;
    }
    api_restore_marker_clear($rec['id']);
    $updated = api_task_update($rec['id'], static function ($r) use ($why) {
        if (in_array($r['status'] ?? '', ['running', 'queued'], true)) {
            $r['status'] = 'failed';
            $r['finished_at'] = api_task_now();
            $r['error'] = $why;
        }
        return $r;
    });
    return $updated ?? $rec;
}

/** One task (stale-checked) or null. */
function api_task_get($id)
{
    $rec = api_task_read($id);
    return $rec === null ? null : api_task_reconcile($rec);
}

/**
 * Tasks, newest first. $filters: kind, status. Records come without 'result'.
 * @return array<int,array>
 */
function api_task_list(array $filters = [])
{
    $out = [];
    foreach (glob(api_tasks_dir() . '/*.json') ?: [] as $file) {
        $id = basename($file, '.json');
        if (!api_task_id_valid($id)) {
            continue;
        }
        $rec = api_task_read($id);
        if ($rec === null) {
            continue;
        }
        $rec = api_task_reconcile($rec);
        if (!empty($filters['kind']) && ($rec['kind'] ?? '') !== $filters['kind']) {
            continue;
        }
        if (!empty($filters['status']) && ($rec['status'] ?? '') !== $filters['status']) {
            continue;
        }
        unset($rec['result']);
        $out[] = $rec;
    }
    usort($out, static function ($a, $b) {
        return strcmp($b['id'], $a['id']);
    });
    return $out;
}

/** The first queued/running exclusive task, or null. */
function api_task_exclusive_busy()
{
    foreach (api_task_list() as $rec) {
        if (!empty($rec['exclusive']) && in_array($rec['status'], ['queued', 'running'], true)) {
            return $rec;
        }
    }
    return null;
}

/** Fail the request (409) or throw (CLI) when an exclusive task is active. */
function api_task_require_idle()
{
    $busy = api_task_exclusive_busy();
    if ($busy !== null) {
        api_task_conflict($busy);
    }
}

function api_task_conflict(array $busy)
{
    $msg = 'Another exclusive task is ' . $busy['status'] . ': ' . $busy['kind'] . ' ' . $busy['id'];
    if (function_exists('api_error')) {
        api_error(409, $msg);
    }
    throw new RuntimeException($msg);
}

/** Last $n lines of a task's log. */
function api_task_log_tail($id, $n)
{
    $path = api_task_path($id, 'log');
    $n = max(0, min(5000, (int) $n));
    if ($n === 0 || !is_file($path)) {
        return [];
    }
    $size = filesize($path);
    $read = min($size, 512 * 1024);
    $fh = fopen($path, 'rb');
    fseek($fh, $size - $read);
    $chunk = (string) fread($fh, $read);
    fclose($fh);
    $lines = preg_split('/\r?\n/', rtrim($chunk, "\r\n"));
    if ($read < $size) {
        array_shift($lines); // first line is probably cut
    }
    return array_slice($lines, -$n);
}

/** Start the runner detached from this process. */
function api_task_spawn($id)
{
    $php = is_executable('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
    $runner = dirname(__DIR__) . '/bin/run_task.php';
    $log = api_task_path($id, 'log');
    $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '';
    $cmd = sprintf(
        'nohup %s%s %s %s >> %s 2>&1 < /dev/null &',
        $setsid,
        escapeshellarg($php),
        escapeshellarg($runner),
        escapeshellarg($id),
        escapeshellarg($log)
    );
    exec($cmd);
}

/**
 * Maintenance gate for write routes. Takes a shared lock on .maint.lock for the
 * rest of the request (exclusive task creation takes it exclusively, so it
 * waits for writes in flight), then answers 409 when an exclusive task is
 * queued or running. Idempotent within a request.
 */
function api_maintenance_enter($legacy = false)
{
    if (!empty($GLOBALS['api_maint_fh'])) {
        return;
    }
    $GLOBALS['api_maint_fh'] = api_tasks_lock_open('.maint.lock', LOCK_SH);
    $busy = api_task_exclusive_busy();
    if ($busy !== null) {
        $msg = 'maintenance task ' . $busy['id'] . ' ' . $busy['status'];
        if ($legacy) {
            api_legacy_error(409, $msg);
        }
        api_error(409, $msg, 'conflict');
    }
}

/** Delete all but the newest STS_API_TASKS_KEEP finished task records and their logs. */
function api_task_prune()
{
    $ids = [];
    foreach (glob(api_tasks_dir() . '/*.json') ?: [] as $file) {
        $id = basename($file, '.json');
        if (api_task_id_valid($id)) {
            $ids[] = $id;
        }
    }
    if (count($ids) <= STS_API_TASKS_KEEP) {
        return;
    }
    rsort($ids);
    foreach (array_slice($ids, STS_API_TASKS_KEEP) as $id) {
        $rec = api_task_read($id);
        if ($rec !== null && in_array($rec['status'] ?? '', ['queued', 'running'], true)) {
            continue; // never drop a live task
        }
        @unlink(api_task_path($id));
        @unlink(api_task_path($id, 'log'));
    }
}

/**
 * Create and start a task.
 * @param array $opts exclusive (bool): 409 when another exclusive task is queued/running;
 *                    the check and the create happen under one lock, after in-flight writes drain.
 * @return array the queued record
 */
function api_task_create($kind, array $spec, ?array $user, array $opts = [])
{
    if (!api_task_kind_valid($kind) || !is_file(dirname(__DIR__) . '/tasks/' . $kind . '.php')) {
        throw new InvalidArgumentException('Unknown task kind');
    }
    $exclusive = !empty($opts['exclusive']);
    $maint = $exclusive ? api_tasks_lock_open('.maint.lock', LOCK_EX) : null;
    $lock = api_tasks_lock_open('.lock');
    $GLOBALS['api_task_lock_held'] = true;
    try {
        if ($exclusive) {
            api_task_require_idle();
        }
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $rec = [
            'id' => $id,
            'kind' => $kind,
            'status' => 'queued',
            'created_at' => api_task_now(),
            'created_by' => $user['name'] ?? null,
            'created_by_id' => $user['id'] ?? null,
            'exclusive' => $exclusive,
            'spec' => $spec,
            'pid' => null,
            'pid_start' => null,
            'child_pid' => null,
            'child_pid_start' => null,
            'started_at' => null,
            'finished_at' => null,
            'result' => null,
            'error' => null,
        ];
        api_task_write($rec);
        $old = umask(0027);
        @touch(api_task_path($id, 'log'));
        umask($old);
        api_tasks_chown_www(api_task_path($id, 'log'));
        api_task_prune();
    } finally {
        $GLOBALS['api_task_lock_held'] = false;
        flock($lock, LOCK_UN);
        fclose($lock);
        if ($maint) {
            flock($maint, LOCK_UN);
            fclose($maint);
        }
    }
    api_task_spawn($id);
    return $rec;
}

/** 202 response for a created task (web only). */
function api_task_accepted(array $rec)
{
    api_ok(['task_id' => $rec['id'], 'status' => $rec['status'], 'kind' => $rec['kind']], null, 202);
}
