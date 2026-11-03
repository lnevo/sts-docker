<?php
/**
 * Browser sessions for API sign-in.
 *
 * POST /v1/auth/login swaps a token for a random session id that lives in an
 * HttpOnly cookie (`sts_api_<port>`, SameSite=Strict, Path=/sts/). The server keeps
 * the session as a file under <data dir>/sessions/ (mode 0640, www-data), named
 * by the SHA-256 of the id so a directory listing never reveals a usable id.
 * The file records which token the session came from; every request rechecks
 * that token (see api_session_user()), so revoking it ends its sessions.
 * Sessions expire after 12 hours without use.
 */

if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/tokens.php';

/** Fallback cookie name; the real one is per stack, see api_session_cookie_name(). */
const STS_API_SESSION_COOKIE = 'sts_api';
const STS_API_SESSION_MAX_AGE = 604800; // 7 days absolute, whatever the activity
const STS_API_RL_MAX_FILES = 2000;
const STS_API_SESSION_IDLE = 43200; // 12 hours
const STS_API_LOGIN_MAX_FAILS = 10;
const STS_API_LOGIN_WINDOW = 60;

/**
 * Cookie name per stack so two stacks on one host (cookies ignore ports) do not
 * overwrite each other: sts_api_<STS_STACK_NAME> or sts_api_<SERVER_PORT>.
 */
function api_session_cookie_name()
{
    $stack = getenv('STS_STACK_NAME');
    if ($stack === false || $stack === '') {
        // The browser-visible port: the container always listens on 80 whatever the published port.
        $hp = parse_url('//' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_PORT);
        $stack = (string) ($hp ?: ($_SERVER['SERVER_PORT'] ?? ''));
    }
    $stack = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $stack);
    return $stack === '' ? STS_API_SESSION_COOKIE : STS_API_SESSION_COOKIE . '_' . $stack;
}

function api_sessions_dir()
{
    return api_tokens_dir() . '/sessions';
}

function api_session_valid_id($id)
{
    return is_string($id) && preg_match('/^[0-9a-f]{64}$/', $id) === 1;
}

function api_session_path($id)
{
    return api_sessions_dir() . '/' . hash('sha256', $id) . '.json';
}

/** Create the sessions directory (0750). Returns false when it cannot be made. */
function api_sessions_ensure_dir()
{
    $dir = api_sessions_dir();
    if (is_dir($dir)) {
        return true;
    }
    $old = umask(0027);
    $ok = @mkdir($dir, 0750, true) || is_dir($dir);
    umask($old);
    if ($ok) {
        api_tokens_chown_www($dir);
    }
    return $ok;
}

function api_request_is_https()
{
    $https = $_SERVER['HTTPS'] ?? '';
    if ($https !== '' && strtolower($https) !== 'off') {
        return true;
    }
    return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function api_session_set_cookie($id, $expires)
{
    if (headers_sent()) {
        return;
    }
    setcookie(api_session_cookie_name(), $id, [
        'expires' => $expires,
        'path' => '/sts/',
        'secure' => api_request_is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function api_session_cookie_id()
{
    $id = $_COOKIE[api_session_cookie_name()] ?? '';
    return api_session_valid_id($id) ? $id : '';
}

/**
 * Start a session for an authenticated principal ['id','name','role','source']
 * plus 'sha256' (hash of the token that proved it). Sets the cookie.
 * Returns ['id' => session id, 'expires_at' => ISO time].
 */
function api_session_create(array $principal, $sha256)
{
    if (!api_sessions_ensure_dir()) {
        throw new RuntimeException('Cannot create the sessions directory');
    }
    api_sessions_prune();
    // Rotate the id for this browser: its previous session goes, other sessions stay.
    $prev = api_session_cookie_id();
    if ($prev !== '') {
        @unlink(api_session_path($prev));
    }
    $id = bin2hex(random_bytes(32));
    $now = time();
    $rec = [
        'token_id' => $principal['id'],
        'source' => $principal['source'],
        'sha256' => $sha256,
        'name' => $principal['name'],
        'created_at' => gmdate('c', $now),
        'created' => $now,
        'last_seen' => $now,
    ];
    $old = umask(0027);
    $path = api_session_path($id);
    $ok = @file_put_contents($path, json_encode($rec), LOCK_EX);
    umask($old);
    if ($ok === false) {
        throw new RuntimeException('Cannot write the session file');
    }
    @chmod($path, 0640);
    api_tokens_chown_www($path);
    api_session_set_cookie($id, $now + STS_API_SESSION_IDLE);
    return ['id' => $id, 'expires_at' => gmdate('c', $now + STS_API_SESSION_IDLE)];
}

/** Delete the session files that have been idle past the timeout. */
function api_sessions_prune()
{
    $limit = time() - STS_API_SESSION_IDLE;
    $born = time() - STS_API_SESSION_MAX_AGE;
    foreach (glob(api_sessions_dir() . '/*.json') ?: [] as $f) {
        $rec = json_decode((string) @file_get_contents($f), true);
        $seen = is_array($rec) ? (int) ($rec['last_seen'] ?? 0) : 0;
        $made = is_array($rec) ? (int) ($rec['created'] ?? $seen) : 0;
        if ($seen < $limit || $made < $born) {
            @unlink($f);
        }
    }
    api_login_prune_rl();
}

/** Drop stale rate-limit files and keep at most STS_API_RL_MAX_FILES (oldest go first). */
function api_login_prune_rl()
{
    $files = [];
    foreach (glob(api_sessions_dir() . '/rl_*') ?: [] as $f) {
        $m = (int) @filemtime($f);
        if ($m < time() - STS_API_LOGIN_WINDOW * 2) {
            @unlink($f);
        } else {
            $files[$f] = $m;
        }
    }
    if (count($files) > STS_API_RL_MAX_FILES) {
        asort($files);
        foreach (array_slice(array_keys($files), 0, count($files) - STS_API_RL_MAX_FILES) as $f) {
            @unlink($f);
        }
    }
}

/**
 * The principal behind the request's session cookie, or null: unknown or idle
 * session, or the underlying token was revoked or removed. A valid use renews
 * the idle timer (written at most once a minute) and the cookie's expiry.
 * Returns ['id','name','role','source'=>'session','expires_at','session_expires'].
 */
function api_session_user()
{
    $id = api_session_cookie_id();
    if ($id === '') {
        return null;
    }
    $path = api_session_path($id);
    $rec = json_decode((string) @file_get_contents($path), true);
    if (!is_array($rec)) {
        return null;
    }
    $seen = (int) ($rec['last_seen'] ?? 0);
    if ($seen < time() - STS_API_SESSION_IDLE || (int) ($rec['created'] ?? $seen) < time() - STS_API_SESSION_MAX_AGE) {
        @unlink($path);
        return null;
    }
    $principal = null;
    if (($rec['source'] ?? '') === 'env') {
        $env = getenv('STS_API_ADMIN_TOKEN');
        if ($env !== false && $env !== '' && hash_equals(api_token_hash($env), (string) ($rec['sha256'] ?? ''))) {
            $principal = ['id' => 'env', 'name' => 'STS_API_ADMIN_TOKEN', 'role' => 'admin'];
        }
    } else {
        foreach (api_tokens_read() as $t) {
            if (($t['id'] ?? '') === ($rec['token_id'] ?? null) && empty($t['revoked_at'])
                && hash_equals((string) ($t['sha256'] ?? ''), (string) ($rec['sha256'] ?? ''))) {
                $principal = ['id' => $t['id'], 'name' => $t['name'], 'role' => $t['role']];
                api_token_touch($t['id']);
                break;
            }
        }
    }
    if ($principal === null) {
        @unlink($path);
        return null;
    }
    if (time() - $seen >= 60) {
        $rec['last_seen'] = time();
        $old = umask(0027);
        @file_put_contents($path, json_encode($rec), LOCK_EX);
        umask($old);
        api_session_set_cookie($id, time() + STS_API_SESSION_IDLE);
        if (mt_rand(1, 50) === 1) {
            api_sessions_prune();
        }
        $seen = $rec['last_seen'];
    }
    $principal['source'] = 'session';
    $principal['expires_at'] = gmdate('c', $seen + STS_API_SESSION_IDLE);
    return $principal;
}

/** End the request's session (file and cookie). */
function api_session_destroy()
{
    $id = api_session_cookie_id();
    if ($id !== '') {
        @unlink(api_session_path($id));
    }
    api_session_set_cookie('', 1);
}

/* -------------------------------------------------- failed-login limiter */

function api_login_client_ip()
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function api_login_rl_path()
{
    return api_sessions_dir() . '/rl_' . hash('sha256', api_login_client_ip());
}

/**
 * Atomic check-and-record: under one flock, refuse when this IP already has
 * STS_API_LOGIN_MAX_FAILS attempts in the window, else record this attempt.
 * Returns ['wait' => seconds (0 = allowed), 'ts' => recorded timestamp]. A
 * successful sign-in calls api_login_release() to take its attempt back.
 */
function api_login_reserve()
{
    if (!api_sessions_ensure_dir()) {
        return ['wait' => 0, 'ts' => null];
    }
    api_login_prune_rl();
    $path = api_login_rl_path();
    $old = umask(0027);
    $fh = @fopen($path, 'c+');
    umask($old);
    if (!$fh) {
        return ['wait' => 0, 'ts' => null];
    }
    $out = ['wait' => 0, 'ts' => null];
    if (flock($fh, LOCK_EX)) {
        $times = api_login_filter(json_decode((string) stream_get_contents($fh), true));
        if (count($times) >= STS_API_LOGIN_MAX_FAILS) {
            $out['wait'] = max(1, (int) min($times) + STS_API_LOGIN_WINDOW - time());
        } else {
            $out['ts'] = microtime(true);
            $times[] = $out['ts'];
            api_login_store($fh, $times);
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    api_tokens_chown_www($path);
    return $out;
}

/** Take back the attempt api_login_reserve() recorded (the sign-in succeeded). */
function api_login_release($ts)
{
    if ($ts === null) {
        return;
    }
    $fh = @fopen(api_login_rl_path(), 'c+');
    if (!$fh) {
        return;
    }
    if (flock($fh, LOCK_EX)) {
        $times = api_login_filter(json_decode((string) stream_get_contents($fh), true));
        $times = array_values(array_filter($times, static function ($t) use ($ts) {
            return $t !== $ts;
        }));
        api_login_store($fh, $times);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

function api_login_filter($raw)
{
    $cut = microtime(true) - STS_API_LOGIN_WINDOW;
    return array_values(array_filter(is_array($raw) ? $raw : [], static function ($t) use ($cut) {
        return (is_int($t) || is_float($t)) && $t > $cut;
    }));
}

function api_login_store($fh, array $times)
{
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($times));
    fflush($fh);
}
