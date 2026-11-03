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

function api_tasks_dir()
{
    $dir = rtrim(getenv('STS_API_DATA_DIR') ?: '/var/lib/sts', '/') . '/tasks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
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
    if ($json === false || file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write task record ' . $rec['id']);
    }
    @chmod($path, 0664);
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
    $lock = fopen(api_tasks_dir() . '/.lock', 'c');
    flock($lock, LOCK_EX);
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

function api_task_pid_alive($pid)
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
        return $state !== 'Z' && $state !== 'X';
    }
    if (is_dir('/proc/self')) {
        return false; // procfs is mounted and has no such pid
    }
    return function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
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
        $dead = !api_task_pid_alive($pid);
        $why = 'Task process ' . $pid . ' is gone without finishing';
    } else {
        $age = time() - (int) strtotime($rec['created_at'] ?? 'now');
        $dead = $age > 120;
        $why = 'Task runner never started';
    }
    if (!$dead) {
        return $rec;
    }
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
 * Create and start a task.
 * @param array $opts exclusive (bool): 409 when another exclusive task is queued/running
 * @return array the queued record
 */
function api_task_create($kind, array $spec, ?array $user, array $opts = [])
{
    if (!api_task_kind_valid($kind) || !is_file(dirname(__DIR__) . '/tasks/' . $kind . '.php')) {
        throw new InvalidArgumentException('Unknown task kind');
    }
    $exclusive = !empty($opts['exclusive']);
    $lock = fopen(api_tasks_dir() . '/.lock', 'c');
    flock($lock, LOCK_EX);
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
            'exclusive' => $exclusive,
            'spec' => $spec,
            'pid' => null,
            'started_at' => null,
            'finished_at' => null,
            'result' => null,
            'error' => null,
        ];
        api_task_write($rec);
        @touch(api_task_path($id, 'log'));
        @chmod(api_task_path($id, 'log'), 0664);
    } finally {
        $GLOBALS['api_task_lock_held'] = false;
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    api_task_spawn($id);
    return $rec;
}

/** 202 response for a created task (web only). */
function api_task_accepted(array $rec)
{
    api_ok(['task_id' => $rec['id'], 'status' => $rec['status'], 'kind' => $rec['kind']], null, 202);
}
