<?php
/**
 * Auth for PHP pages outside the API router (editor, operational_steps_api.php,
 * simulator_api.php, api_admin.php, ...). Include-only: no output buffering, no
 * exit behaviour, no routes.
 *
 *   require_once __DIR__ . '/api/lib/web_auth.php';
 *   $user = api_web_user();                       // bearer token or sts_api cookie, or null
 *   if (!api_role_allows($user, 'operator')) { http_response_code(403); ... }
 *   if (!api_policy_flag('editor_open')) { ... } // lib/policy.php
 *
 * Write requests authenticated by the cookie should also pass
 * api_web_write_origin_ok() (the router applies the same check to every write).
 */

if (!defined('STS_API_BOOT')) {
    define('STS_API_BOOT', 1);
}

require_once __DIR__ . '/tokens.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth.php';

/**
 * False when the request carries an Origin header whose host:port differs from
 * Host (cross-site write). True for no Origin header or a same-host one.
 */
function api_web_write_origin_ok()
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return true;
    }
    $o = parse_url($origin);
    if (!is_array($o) || empty($o['host']) || empty($o['scheme'])) {
        return false;
    }
    $scheme = strtolower($o['scheme']);
    $port = $o['port'] ?? ($scheme === 'https' ? 443 : 80);
    $h = parse_url('//' . trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if (!is_array($h) || empty($h['host'])) {
        return false;
    }
    $hport = $h['port'] ?? ($scheme === 'https' ? 443 : 80);
    return strtolower($o['host']) . ':' . (int) $port === strtolower($h['host']) . ':' . (int) $hport;
}
