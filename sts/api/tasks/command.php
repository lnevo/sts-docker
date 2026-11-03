<?php
/** Task kind `command`: runs one catalog command (spec: command, params, seed). */

if (!defined('STS_API_BOOT')) {
    define('STS_API_BOOT', true);
}
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/response.php';

function api_task_kind_command(array $spec, callable $log): array
{
    require_once dirname(__DIR__) . '/lib/operate_command.php';
    $command = (string) ($spec['command'] ?? '');
    $params = is_array($spec['params'] ?? null) ? $spec['params'] : [];
    $seed = $spec['seed'] ?? null;
    $log("command $command");
    if ($seed !== null && $seed !== '') {
        $log('seed ' . (int) $seed);
    }
    $result = api_operate_run_command($command, $params, $seed);
    $log('done; session ' . $result['session_nbr_before'] . ' -> ' . $result['session_nbr_after']);
    return $result;
}
