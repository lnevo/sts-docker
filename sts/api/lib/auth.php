<?php
/**
 * Bearer-token auth with hierarchical roles: reader < operator < editor < admin.
 *
 * Env: STS_API_READ_OPEN (default 1), STS_API_LEGACY_OPEN (default 1),
 * STS_API_ADMIN_TOKEN (accepted as admin, never stored).
 */

if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/tokens.php';

function api_env_flag($name, $default = true)
{
    $v = getenv($name);
    if ($v === false || $v === '') {
        return $default;
    }
    return !in_array(strtolower($v), ['0', 'false', 'no', 'off'], true);
}

function api_role_rank($role)
{
    return STS_API_ROLES[$role] ?? 0;
}

function api_bearer_token()
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)\s*$/i', $h, $m) ? $m[1] : '';
}

/**
 * Identify the caller. Returns ['id','name','role','source'] or null when no
 * (valid) credential was presented.
 */
function api_authenticate()
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    $token = api_bearer_token();
    if ($token === '') {
        return $cached = null;
    }
    $env = getenv('STS_API_ADMIN_TOKEN');
    if ($env !== false && $env !== '' && hash_equals($env, $token)) {
        return $cached = ['id' => 'env', 'name' => 'STS_API_ADMIN_TOKEN', 'role' => 'admin', 'source' => 'env'];
    }
    $rec = api_token_lookup($token);
    if ($rec === null) {
        return $cached = null;
    }
    api_token_touch($rec['id']);
    return $cached = ['id' => $rec['id'], 'name' => $rec['name'], 'role' => $rec['role'], 'source' => 'file'];
}

/**
 * Pure policy decision.
 * @param string      $required route role: open|reader|operator|editor|admin
 * @param bool        $legacy   legacy wagon route
 * @param string|null $role     caller role (null = anonymous)
 * @return int 200 allow, 401 need a token, 403 role too low
 */
function api_policy_decision($required, $legacy, $role, $read_open, $legacy_open)
{
    if ($required === 'open') {
        return 200;
    }
    if ($legacy && $legacy_open) {
        return 200;
    }
    if (!$legacy && $required === 'reader' && $read_open) {
        return 200;
    }
    if ($role === null) {
        return 401;
    }
    return api_role_rank($role) >= api_role_rank($required) ? 200 : 403;
}

/** Enforce the policy for a route; sends the error and exits on failure. */
function api_require_role($required, $legacy = false)
{
    $user = api_authenticate();
    $code = api_policy_decision(
        $required,
        $legacy,
        $user['role'] ?? null,
        api_env_flag('STS_API_READ_OPEN', true),
        api_env_flag('STS_API_LEGACY_OPEN', true)
    );
    if ($code === 200) {
        return $user;
    }
    if ($legacy) {
        api_legacy_error($code, $code === 401 ? 'Unauthorized' : 'Forbidden');
    }
    if ($code === 401) {
        header('WWW-Authenticate: Bearer');
        api_error(401, 'A valid bearer token is required');
    }
    api_error(403, "Role '$required' or higher is required");
}

/** Legacy-shaped error: {"error": "message"}. */
function api_legacy_error($status, $message)
{
    $GLOBALS['api_responded'] = true;
    api_discard_output();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
    exit;
}
