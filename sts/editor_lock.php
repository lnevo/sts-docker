<?php
/**
 * Role lock for the legacy editor endpoints (operational_steps_api.php,
 * simulator_api.php, save_operational_steps.php).
 *
 * Reads stay open. A write or run calls sts_editor_require($role, $respond):
 * with the policy flag editor_open true it passes as before; otherwise the caller
 * must hold $role or higher through api_web_user() (bearer token or the sts_api
 * cookie from /sts/api_admin.php). For runs ($is_run) the per-step role guard is
 * installed so a recipe step needing a higher role than the caller's stops the run.
 *
 * $respond(int $status, string $message) answers in the page's own JSON error
 * shape and exits.
 */

require_once __DIR__ . '/api/lib/web_auth.php';
require_once __DIR__ . '/api/lib/tasks.php';
require_once __DIR__ . '/api/lib/operate_command.php';

function sts_editor_require($role, callable $respond, $is_run = false)
{
    $user = api_web_user();
    if (api_policy_flag('editor_open')) {
        // Open editor: anyone may write, but a run still acts as operator at most
        // (an anonymous caller is never admin). Cookie writes keep the Origin check.
        if ($user !== null && ($user['source'] ?? '') === 'session' && !api_web_write_origin_ok()) {
            $respond(403, 'Cross-site write rejected: Origin does not match Host');
        }
        $run_role = $user !== null ? (string) $user['role'] : 'operator';
    } else {
        if ($user === null) {
            $respond(401, "Sign in required (role '$role' or higher): open /sts/api_admin.php and sign in with an API token, or send a Bearer token.");
        }
        if (!api_role_allows($user, $role)) {
            $respond(403, "Role '$role' or higher is required; you are signed in as '" . ($user['role'] ?? '?') . "'. Sign in with another token at /sts/api_admin.php.");
        }
        if (($user['source'] ?? '') === 'session' && !api_web_write_origin_ok()) {
            $respond(403, 'Cross-site write rejected: Origin does not match Host');
        }
        $run_role = (string) $user['role'];
    }
    if ($is_run) {
        $GLOBALS['sts_editor_run_role'] = $run_role;
        $GLOBALS['sts_before_dispatch'] = api_workflow_step_guard($run_role);
        sts_editor_maintenance($respond);
    }
    return $user;
}

/**
 * Runs hold a shared maintenance lock for the rest of the request (an exclusive
 * task waits for them) and are refused with 409 while an exclusive task is
 * queued or running, like v1 writes.
 */
function sts_editor_maintenance(callable $respond)
{
    if (!empty($GLOBALS['api_maint_fh'])) {
        return;
    }
    $GLOBALS['api_maint_fh'] = api_tasks_lock_open('.maint.lock', LOCK_SH);
    $busy = api_task_exclusive_busy();
    if ($busy !== null) {
        $respond(409, 'maintenance task ' . $busy['id'] . ' ' . $busy['status']);
    }
}

/**
 * Refuse a run before anything is dispatched when an enabled, in-range,
 * not-skipped, non-conditional step needs more than the caller's role.
 * Call after sts_editor_require(..., true). $to 0 = to the end.
 */
function sts_editor_precheck(array $recipe, $from, $to, $skip, callable $respond)
{
    $role = $GLOBALS['sts_editor_run_role'] ?? 'operator';
    $msg = api_workflow_precheck($recipe, $role, $from, $to, $skip);
    if ($msg !== null) {
        $respond(403, $msg);
    }
}
