<?php
/**
 * Catalog command execution shared by POST /v1/commands/{command} (sync) and
 * the `command` task kind (async). One code path: api_operate_run_command().
 */

if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/policy.php';

/**
 * Command ids that touch the whole database (also used to find destructive
 * recipe steps). Who may run a command comes from the policy
 * (api_command_required_role()); a command that is in this list or needs admin
 * runs as an exclusive task.
 */
const OPERATE_DESTRUCTIVE_COMMANDS = STS_API_POLICY_EXCLUSIVE_COMMANDS;

/** Control-flow steps that only make sense inside a workflow run. */
const OPERATE_FLOW_COMMANDS = ['stop', 'goto', 'if_then', 'skip_steps', 'marker', 'section_label', 'text_instruction'];

/** Commands that render whole-session output; always run as a task. */
const OPERATE_SLOW_COMMANDS = [
    'generate_switchlists', 'render_switchlists', 'save_switchlist_cache',
    'generate_waybills', 'generate_station_report', 'generate_wheel_report',
    'rebuild_switchlists_index',
];

require_once __DIR__ . '/policy.php';

/**
 * before_dispatch guard for session_run_recipe(): fails the run at the first step
 * whose command needs a higher role than $role (the task creator's). Steps
 * skipped by conditions, ranges or disabled flags never reach it.
 */
function api_workflow_step_guard($role)
{
    api_operate_load();
    $catalog = operational_steps_catalog_by_id();
    return static function ($fid, $n) use ($role, $catalog) {
        $dispatch = (string) ($catalog[$fid]['dispatch'] ?? $fid);
        $need = 'operator';
        foreach (array_unique([(string) $fid, $dispatch]) as $id) {
            $r = api_command_required_role($id);
            if (api_role_rank($r) > api_role_rank($need)) {
                $need = $r;
            }
        }
        if (api_role_rank((string) $role) < api_role_rank($need)) {
            throw new ApiTaskFailure("step $n ($fid) needs $need; sign in with an admin token or change the command's role on /sts/api_admin.php"
                . " (the run was started by role '" . ($role === '' || $role === null ? 'none' : $role) . "')");
        }
    };
}

/**
 * Steps of a recipe whose command needs a higher role than $role (null or below
 * operator are compared as operator).
 * Rows: {step, command, label, required_role, conditional, note}. conditional is true
 * when a forward goto / if_then-goto earlier in the recipe may jump over the step
 * (so a run might never reach it); skip lists given at run time are not considered.
 * @return array<int,array>
 */
function api_recipe_role_warnings(array $recipe, $role)
{
    api_operate_load();
    // Below operator nobody can run a workflow at all; list what exceeds operator rights then.
    if (api_role_rank((string) $role) < api_role_rank('operator')) {
        $role = 'operator';
    }
    $catalog = operational_steps_catalog_by_id();
    $norm = operational_steps_normalize_recipe($recipe);
    $steps = $norm['steps'] ?? [];
    $spans = []; // [from, target] of every forward jump
    foreach ($steps as $i => $step) {
        if (!is_array($step)) {
            continue;
        }
        $fid = (string) ($step['function'] ?? '');
        if ($fid === 'goto' || ($fid === 'if_then' && operational_steps_if_then_has_goto($step))) {
            $target = operational_steps_goto_resolve_step($norm, $step['params'] ?? []);
            if ($target > $i + 1) {
                $spans[] = [$i + 1, $target, $fid];
            }
        }
    }
    $out = [];
    foreach ($steps as $i => $step) {
        if (!is_array($step) || (array_key_exists('enabled', $step) && !$step['enabled'])) {
            continue;
        }
        $n = $i + 1;
        $fid = (string) ($step['function'] ?? '');
        $dispatch = (string) ($catalog[$fid]['dispatch'] ?? $fid);
        $need = 'operator';
        foreach (array_unique([$fid, $dispatch]) as $id) {
            $r = api_command_required_role($id);
            if (api_role_rank($r) > api_role_rank($need)) {
                $need = $r;
            }
        }
        if (api_role_rank((string) $role) >= api_role_rank($need)) {
            continue;
        }
        $cond = false;
        foreach ($spans as [$from, $target, $kind]) {
            if ($from < $n && $n < $target) {
                $cond = $kind;
                break;
            }
        }
        $label = operational_steps_compile_recipe(['steps' => [$step]])[0]['instruction'] ?? $fid;
        $out[] = [
            'step' => $n,
            'command' => $fid,
            'label' => $label,
            'required_role' => $need,
            'conditional' => $cond !== false,
            'note' => $cond === false
                ? "Always runs unless skipped with a step range; needs $need."
                : "Behind a " . ($cond === 'goto' ? 'goto' : 'condition') . " at an earlier step that may skip it; "
                    . "it is only checked if the run reaches it. Whether it is reached depends on the database at run time.",
        ];
    }
    return $out;
}

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
