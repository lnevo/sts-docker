<?php
/**
 * Sign-in lock for the Database Maintenance pages.
 *
 *   require_once __DIR__ . '/maint_lock.php';
 *   sts_maint_require('admin');   // first thing in the page, before output or DB work
 *
 * The caller needs the role through the sign-in cookie or a bearer token (same
 * auth as the API). Without it the page answers 401 (not signed in) or 403
 * (role too low) with a notice and a link to API Access, and nothing else
 * runs. The policy flag maint_open turns the lock off.
 */
require_once __DIR__ . '/api/lib/web_auth.php';

function sts_maint_require($role)
{
    if (api_policy_flag('maint_open')) {
        return;
    }
    $user = api_web_user();
    if (api_role_allows($user, $role)) {
        return;
    }
    http_response_code(is_array($user) ? 403 : 401);
    require_once __DIR__ . '/page_chrome.php';
    sts_page_start(['title' => 'Sign In Required', 'icon' => 'bi-lock', 'section' => 'maint']);
    $have = is_array($user) ? ' You are signed in as <b>' . htmlspecialchars((string) ($user['role'] ?? '')) . '</b>.' : '';
    echo '<div class="sts-card"><div class="alert alert-warning" id="maint-lock-notice">'
        . 'This Database Maintenance page needs the <b>' . htmlspecialchars($role) . '</b> role.' . $have
        . '</div><p><a class="btn btn-primary" href="/sts/api_admin.php">Sign in on API Access</a></p></div>';
    sts_page_end();
    exit;
}
