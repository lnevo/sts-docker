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
    if (api_policy_flag('editor_open')) {
        return api_web_user();
    }
    $user = api_web_user();
    if ($user === null) {
        $respond(401, "Sign in required (role '$role' or higher): open /sts/api_admin.php and sign in with an API token, or send a Bearer token.");
    }
    if (!api_role_allows($user, $role)) {
        $respond(403, "Role '$role' or higher is required; you are signed in as '" . ($user['role'] ?? '?') . "'. Sign in with another token at /sts/api_admin.php.");
    }
    if (($user['source'] ?? '') === 'session' && !api_web_write_origin_ok()) {
        $respond(403, 'Cross-site write rejected: Origin does not match Host');
    }
    if ($is_run) {
        $GLOBALS['sts_before_dispatch'] = api_workflow_step_guard((string) $user['role']);
    }
    return $user;
}
