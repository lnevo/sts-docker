<?php
/**
 * Browser sign-in: POST /auth/login, GET /auth/me, POST /auth/logout.
 * The cookie holds only a random session id (lib/session.php); the token is
 * rechecked on every request.
 */
if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

function auth_user_view(array $u)
{
    return [
        'name' => $u['name'],
        'role' => $u['role'],
        'source' => $u['source'] ?? 'session',
        'expires_at' => $u['expires_at'] ?? null,
    ];
}

api_route('POST', '/auth/login', static function ($req) {
    $body = api_body();
    foreach (array_keys($body) as $k) {
        if ($k !== 'token') {
            api_error(422, "Unknown field '$k' (allowed: token)");
        }
    }
    $wait = api_login_retry_after();
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        api_error(429, "Too many failed sign-in attempts; try again in $wait seconds", 'rate_limited');
    }
    $token = isset($body['token']) && is_string($body['token']) ? trim($body['token']) : '';
    $principal = null;
    if ($token !== '') {
        $env = getenv('STS_API_ADMIN_TOKEN');
        if ($env !== false && $env !== '' && hash_equals($env, $token)) {
            $principal = ['id' => 'env', 'name' => 'STS_API_ADMIN_TOKEN', 'role' => 'admin', 'source' => 'env'];
        } elseif (($rec = api_token_lookup($token)) !== null) {
            $principal = ['id' => $rec['id'], 'name' => $rec['name'], 'role' => $rec['role'], 'source' => 'file'];
            api_token_touch($rec['id']);
        }
    }
    if ($principal === null) {
        api_login_record_failure();
        api_error(401, 'Invalid or revoked token');
    }
    try {
        $s = api_session_create($principal, api_token_hash($token));
    } catch (RuntimeException $e) {
        api_internal_error($e->getMessage());
    }
    $principal['source'] = 'session';
    $principal['expires_at'] = $s['expires_at'];
    return auth_user_view($principal);
}, 'open', ['summary' => 'Sign in with a token (sets the sts_api cookie)', 'maint' => 'none']);

api_route('GET', '/auth/me', static function ($req) {
    $u = $req['user'];
    if ($u === null) {
        header('WWW-Authenticate: Bearer');
        api_error(401, 'Not signed in');
    }
    return auth_user_view($u);
}, 'open', ['summary' => 'The signed-in caller']);

api_route('POST', '/auth/logout', static function ($req) {
    api_session_destroy();
    return ['signed_out' => true];
}, 'open', ['summary' => 'End the browser session', 'maint' => 'none']);
