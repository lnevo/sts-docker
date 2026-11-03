<?php
/** Task status routes (role: reader). Task creation lives with the routes that start them. */
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
}, 'reader', ['summary' => 'List tasks, newest first']);

api_route('GET', '/tasks/{id}', static function ($req) {
    $rec = api_task_get($req['params']['id']);
    if ($rec === null) {
        api_error(404, 'Task not found');
    }
    if (isset($req['query']['log'])) {
        if (!ctype_digit((string) $req['query']['log'])) {
            api_error(422, 'log must be a non-negative integer');
        }
        $rec['log'] = api_task_log_tail($rec['id'], (int) $req['query']['log']);
    }
    return $rec;
}, 'reader', ['summary' => 'One task, optionally with the last N log lines']);
