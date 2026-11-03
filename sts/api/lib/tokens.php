<?php
/**
 * API token store.
 *
 * File: STS_API_TOKENS_FILE or /var/lib/sts/api_tokens.json (bind mount, outside
 * the web root and the database). Format: {"version":1,"tokens":[{id,name,role,
 * sha256,created_at,last_used_at,revoked_at}]}. Only the sha256 of a token is
 * stored; the token itself is shown once, at creation.
 */

const STS_API_ROLES = ['reader' => 1, 'operator' => 2, 'editor' => 3, 'admin' => 4];

function api_tokens_dir()
{
    return rtrim(getenv('STS_API_DATA_DIR') ?: '/var/lib/sts', '/');
}

function api_tokens_path()
{
    return getenv('STS_API_TOKENS_FILE') ?: api_tokens_dir() . '/api_tokens.json';
}

function api_token_hash($token)
{
    return hash('sha256', $token);
}

function api_token_valid_format($token)
{
    return is_string($token) && preg_match('/^sts_[0-9a-f]{40}$/', $token) === 1;
}

function api_token_generate()
{
    return 'sts_' . bin2hex(random_bytes(20));
}

function api_tokens_read()
{
    $path = api_tokens_path();
    if (!is_readable($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data['tokens'] ?? null) ? array_values($data['tokens']) : [];
}

/**
 * Read-modify-write under an exclusive flock. $fn receives the token list and
 * returns [newList, result]; the file is replaced atomically (tmp + rename).
 */
function api_tokens_mutate(callable $fn)
{
    $path = api_tokens_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        throw new RuntimeException("Token directory $dir is not writable");
    }
    $lock = fopen($path . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Cannot lock token store');
    }
    try {
        [$tokens, $result] = $fn(api_tokens_read());
        $tmp = $path . '.tmp.' . getmypid();
        $json = json_encode(['version' => 1, 'tokens' => array_values($tokens)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Cannot write token store');
        }
        @chmod($tmp, 0640);
        api_tokens_chown_www($tmp);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot replace token store');
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** When run as root (docker exec), hand files to www-data so Apache can read them. */
function api_tokens_chown_www($path)
{
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        @chown($path, 'www-data');
        @chgrp($path, 'www-data');
    }
}

function api_tokens_active(array $tokens = null, $role = null)
{
    $tokens = $tokens ?? api_tokens_read();
    return array_values(array_filter($tokens, static function ($t) use ($role) {
        return empty($t['revoked_at']) && ($role === null || ($t['role'] ?? '') === $role);
    }));
}

/** Create a token. Returns the public record plus the one-time 'token'. */
function api_token_create($name, $role)
{
    $name = trim((string) $name);
    if ($name === '' || strlen($name) > 100) {
        throw new InvalidArgumentException('name is required (max 100 chars)');
    }
    if (!isset(STS_API_ROLES[$role])) {
        throw new InvalidArgumentException('role must be one of: ' . implode(', ', array_keys(STS_API_ROLES)));
    }
    $token = api_token_generate();
    $record = [
        'id' => 'tok_' . bin2hex(random_bytes(4)),
        'name' => $name,
        'role' => $role,
        'sha256' => api_token_hash($token),
        'created_at' => gmdate('c'),
        'last_used_at' => null,
        'revoked_at' => null,
    ];
    api_tokens_mutate(static function ($tokens) use ($record) {
        $tokens[] = $record;
        return [$tokens, null];
    });
    unset($record['sha256']);
    $record['token'] = $token;
    return $record;
}

/** Revoke by id. Returns the public record, or null when unknown. */
function api_token_revoke($id)
{
    return api_tokens_mutate(static function ($tokens) use ($id) {
        $found = null;
        foreach ($tokens as &$t) {
            if (($t['id'] ?? '') === $id) {
                if (empty($t['revoked_at'])) {
                    $t['revoked_at'] = gmdate('c');
                }
                $found = api_token_public($t);
            }
        }
        unset($t);
        return [$tokens, $found];
    });
}

function api_token_public(array $t)
{
    unset($t['sha256']);
    return $t;
}

function api_tokens_public_list()
{
    return array_map('api_token_public', api_tokens_read());
}

/** Look up an active token record by plaintext token. */
function api_token_lookup($token)
{
    if (!api_token_valid_format($token)) {
        return null;
    }
    $hash = api_token_hash($token);
    foreach (api_tokens_read() as $t) {
        if (empty($t['revoked_at']) && hash_equals((string) ($t['sha256'] ?? ''), $hash)) {
            return $t;
        }
    }
    return null;
}

/** Record use; writes at most once a minute per token to keep the file quiet. */
function api_token_touch($id)
{
    foreach (api_tokens_read() as $t) {
        if (($t['id'] ?? '') === $id) {
            $last = isset($t['last_used_at']) ? strtotime($t['last_used_at']) : 0;
            if ($last && time() - $last < 60) {
                return;
            }
        }
    }
    try {
        api_tokens_mutate(static function ($tokens) use ($id) {
            foreach ($tokens as &$t) {
                if (($t['id'] ?? '') === $id) {
                    $t['last_used_at'] = gmdate('c');
                }
            }
            unset($t);
            return [$tokens, null];
        });
    } catch (Throwable $e) {
        error_log('[sts-api] last_used_at update failed: ' . $e->getMessage());
    }
}

function api_bootstrap_token_path()
{
    return api_tokens_dir() . '/bootstrap_admin.token';
}

/**
 * Ensure an admin token exists. Creates the "bootstrap" token and writes it to
 * bootstrap_admin.token (mode 640) only when there is no active admin token, or
 * when the only active admin is "bootstrap" and its file was lost.
 * Returns ['created' => bool, 'path' => string].
 */
function api_tokens_bootstrap()
{
    $path = api_bootstrap_token_path();
    $admins = api_tokens_active(null, 'admin');
    $need = count($admins) === 0;
    if (!$need && count($admins) === 1 && $admins[0]['name'] === 'bootstrap' && !is_file($path)) {
        api_token_revoke($admins[0]['id']);
        $need = true;
    }
    if (!$need) {
        return ['created' => false, 'path' => $path];
    }
    $rec = api_token_create('bootstrap', 'admin');
    if (file_put_contents($path, $rec['token'] . "\n") === false) {
        throw new RuntimeException("Cannot write $path");
    }
    @chmod($path, 0640);
    api_tokens_chown_www($path);
    return ['created' => true, 'path' => $path];
}
