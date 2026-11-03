<?php
/** Task status routes (role: operator, never opened by STS_API_READ_OPEN). Task creation lives with the routes that start them. */
if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/tasks.php';

api_route('GET', '/tasks', static function ($req) {
    [$limit, $offset] = api_pagination($req['query']);
    $filters = [
        'kind' => api_query_str($req['query'], 'kind'),
        'status' => api_query_enum($req['query'], 'status', ['queued', 'running', 'succeeded', 'failed']),
    ];
    if ($filters['kind'] !== '' && !api_task_kind_valid($filters['kind'])) {
        api_error(422, 'kind is not a valid task kind');
    }
    $all = api_task_list($filters);
    api_ok_list(array_slice($all, $offset, $limit), count($all), $limit, $offset);
}, 'operator', ['summary' => 'List tasks, newest first']);

api_route('GET', '/tasks/{id}', static function ($req) {
    $rec = api_task_get($req['params']['id']);
    if ($rec === null) {
        api_error(404, 'Task not found');
    }
    if (isset($req['query']['log'])) {
        if (!is_string($req['query']['log']) || !ctype_digit($req['query']['log'])) {
            api_error(422, 'log must be a non-negative integer');
        }
        $rec['log'] = api_task_log_tail($rec['id'], (int) $req['query']['log']);
    }
    return $rec;
}, 'operator', ['summary' => 'One task, optionally with the last N log lines']);

api_route('POST', '/admin/tasks/{id}/fail', static function ($req) {
    $id = $req['params']['id'];
    $rec = api_task_read($id);
    if ($rec === null) {
        api_error(404, 'Task not found');
    }
    $body = api_body();
    foreach (array_keys($body) as $k) {
        if ($k !== 'force') {
            api_error(422, "Unknown field '$k' (allowed: force)");
        }
    }
    if (isset($body['force']) && !is_bool($body['force'])) {
        api_error(422, 'force must be a boolean');
    }
    $force = !empty($body['force']);
    $who = $req['user']['name'] ?? 'admin';
    $err = null;
    $was_running = false;
    $updated = api_task_update($id, static function ($r) use ($force, $who, &$err, &$was_running) {
        if (!in_array($r['status'] ?? '', ['queued', 'running'], true)) {
            $err = ['Task is already ' . ($r['status'] ?? 'finished') . '; only queued or running tasks can be failed'];
            return $r;
        }
        if (!$force && ($r['status'] ?? '') === 'running' && api_task_alive($r)) {
            $err = ['Task process ' . (int) $r['pid'] . ' is still alive; pass force true to mark it failed anyway'];
            return $r;
        }
        $was_running = ($r['status'] ?? '') === 'running';
        $r['status'] = 'failed';
        $r['finished_at'] = api_task_now();
        $r['error'] = 'Marked failed by ' . $who;
        return $r;
    });
    if ($err !== null) {
        api_error(409, $err[0]);
    }
    if ($was_running && $updated !== null) {
        api_task_kill($updated); // the whole process group, not just the supervisor
    }
    unset($updated['result']);
    return $updated;
}, 'admin', ['summary' => 'Mark a stuck queued or running task failed', 'maint' => 'none']);
