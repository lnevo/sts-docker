<?php
/**
 * Catalog command execution shared by POST /v1/commands/{command} (sync) and
 * the `command` task kind (async). One code path: api_operate_run_command().
 */

if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

/** Destructive command ids: admin only. */
const OPERATE_DESTRUCTIVE_COMMANDS = [
    'restore_database', 'wipe', 'reset', 'backup_database',
    'wipe_database', 'reset_session', 'restart_session',
];

/** Control-flow steps that only make sense inside a workflow run. */
const OPERATE_FLOW_COMMANDS = ['stop', 'goto', 'if_then', 'skip_steps', 'marker', 'section_label', 'text_instruction'];

/** Commands that render whole-session output; always run as a task. */
const OPERATE_SLOW_COMMANDS = [
    'generate_switchlists', 'render_switchlists', 'save_switchlist_cache',
    'generate_waybills', 'generate_station_report', 'generate_wheel_report',
    'rebuild_switchlists_index',
];

require_once __DIR__ . '/openapi.php';

function api_operate_load()
{
    require_once dirname(__DIR__, 2) . '/operational_steps_catalog.php';
    require_once dirname(__DIR__, 2) . '/session_helpers.php';
}

/**
 * Validate a command id and params. Returns the catalog definition.
 * Errors are API errors (404 unknown, 422 not runnable / bad params).
 */
function api_operate_validate($command, $params)
{
    api_operate_load();
    if (!in_array($command, api_catalog_command_ids(), true)) {
        api_error(404, 'Unknown command');
    }
    $by_id = operational_steps_catalog_by_id();
    $def = $by_id[$command] ?? null;
    if ($def === null || empty($def['runnable'])) {
        api_error(422, 'Command is not runnable through the API');
    }
    if (in_array($command, OPERATE_FLOW_COMMANDS, true)) {
        api_error(422, 'Flow-control step; it only has meaning inside a workflow');
    }
    $known = [];
    foreach ($def['params'] ?? [] as $p) {
        if (isset($p['key'])) {
            $known[$p['key']] = $p;
        }
    }
    foreach (array_keys($params) as $k) {
        if (!isset($known[$k])) {
            api_error(422, "Unknown param '$k' for $command (allowed: " . implode(', ', array_keys($known)) . ')');
        }
    }
    foreach ($known as $k => $p) {
        if (!empty($p['required']) && ($params[$k] ?? '') === '' && ($p['default'] ?? '') === '') {
            api_error(422, "Missing required param '$k'");
        }
    }
    return $def;
}

/**
 * Run one catalog command. Returns [dispatch result + session_nbr_before/after].
 * Throws RuntimeException on a dispatch error.
 */
function api_operate_run_command($command, array $params, $seed = null)
{
    api_operate_load();
    if ($seed !== null && $seed !== '') {
        mt_srand((int) $seed);
        putenv('STS_SIM_SEED=' . (int) $seed);
    }
    $dbc = api_db();
    $before = api_session_nbr();
    $config = session_merge_runtime_config([]);
    $step = ['function' => $command, 'params' => $params];
    $result = operational_steps_dispatch_step($dbc, $step, $config);
    if (!is_array($result)) {
        $result = ['result' => $result];
    }
    if (!empty($result['error']) && is_string($result['error'])) {
        throw new RuntimeException($result['error']);
    }
    $result['session_nbr_before'] = $before;
    $result['session_nbr_after'] = api_session_nbr();
    return $result;
}
