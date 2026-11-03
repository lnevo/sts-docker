<?php
/**
 * API policy: minimum role per catalog command plus the open flags.
 *
 * File: STS_API_POLICY_FILE or /var/lib/sts/api_policy.json (same bind mount as
 * the token store). Keys: command_roles {id: role}, read_open, legacy_open,
 * editor_open. A key the file does not set falls back to its default: the env
 * flags STS_API_READ_OPEN / STS_API_LEGACY_OPEN (default on) and editor_open
 * (default off). api_command_required_role() is the only role lookup.
 */

if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/tokens.php';

/** Commands that need admin unless the policy file lowers them. */
const STS_API_POLICY_ADMIN_COMMANDS = ['restore_database', 'wipe_database', 'reset_session'];

/** Ids that rewrite the whole database: always run as exclusive tasks, whatever their role. */
const STS_API_POLICY_EXCLUSIVE_COMMANDS = [
    'restore_database', 'wipe', 'reset', 'backup_database',
    'wipe_database', 'reset_session', 'restart_session',
];

const STS_API_POLICY_FLAGS = ['read_open', 'legacy_open', 'editor_open'];

function api_policy_path()
{
    return getenv('STS_API_POLICY_FILE') ?: api_tokens_dir() . '/api_policy.json';
}

/** Raw file content ({command_roles, flags}) or [] when missing or unreadable. */
function api_policy_read_raw()
{
    $path = api_policy_path();
    if (!is_readable($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function api_policy_default_role($id)
{
    return in_array($id, STS_API_POLICY_ADMIN_COMMANDS, true) ? 'admin' : 'operator';
}

/** Roles a command may require (a command is never below operator). */
function api_policy_command_role_names()
{
    return ['operator', 'editor', 'admin'];
}

/** Minimum role for a catalog command id. The single lookup. */
function api_command_required_role($id)
{
    static $cache = null;
    if ($cache === null) {
        $roles = api_policy_read_raw()['command_roles'] ?? [];
        $cache = is_array($roles) ? $roles : [];
    }
    $role = $cache[$id] ?? null;
    if (is_string($role) && in_array($role, api_policy_command_role_names(), true)) {
        return $role;
    }
    return api_policy_default_role($id);
}

/** Needs an exclusive (maintenance) task: required role admin or a whole-database command. */
function api_command_is_exclusive($id)
{
    return api_command_required_role($id) === 'admin' || in_array($id, STS_API_POLICY_EXCLUSIVE_COMMANDS, true);
}

/** Effective value of read_open / legacy_open / editor_open. */
function api_policy_flag($name)
{
    $raw = api_policy_read_raw();
    if (array_key_exists($name, $raw) && is_bool($raw[$name])) {
        return $raw[$name];
    }
    return api_policy_flag_default($name);
}

function api_policy_flag_default($name)
{
    switch ($name) {
        case 'read_open':
            return api_env_flag('STS_API_READ_OPEN', true);
        case 'legacy_open':
            return api_env_flag('STS_API_LEGACY_OPEN', true);
        default:
            return false;
    }
}

function api_policy_flag_source($name)
{
    $raw = api_policy_read_raw();
    return array_key_exists($name, $raw) && is_bool($raw[$name]) ? 'file' : 'default';
}

/**
 * Effective policy. $command_ids (catalog ids) fills command_roles for every
 * command; without it only the defaults and file overrides are listed.
 */
function api_policy_get(array $command_ids = null)
{
    $raw = api_policy_read_raw();
    $stored = is_array($raw['command_roles'] ?? null) ? $raw['command_roles'] : [];
    $ids = $command_ids ?? array_values(array_unique(array_merge(STS_API_POLICY_ADMIN_COMMANDS, array_keys($stored))));
    sort($ids);
    $roles = [];
    foreach ($ids as $id) {
        $roles[$id] = api_command_required_role($id);
    }
    $out = ['command_roles' => $roles];
    $sources = [];
    foreach (STS_API_POLICY_FLAGS as $f) {
        $out[$f] = api_policy_flag($f);
        $sources[$f] = api_policy_flag_source($f);
    }
    $out['flag_sources'] = $sources;
    $out['defaults'] = [
        'admin_commands' => STS_API_POLICY_ADMIN_COMMANDS,
        'read_open' => api_policy_flag_default('read_open'),
        'legacy_open' => api_policy_flag_default('legacy_open'),
        'editor_open' => false,
    ];
    return $out;
}

/**
 * Validate and store a policy change. $in may hold command_roles (replaces the
 * stored map; entries equal to the default are dropped) and any of the flags
 * (null removes the override). Returns the list of problems (empty = saved).
 * @return string[]
 */
function api_policy_put(array $in, array $command_ids)
{
    $errors = [];
    foreach (array_keys($in) as $k) {
        if ($k !== 'command_roles' && !in_array($k, STS_API_POLICY_FLAGS, true)) {
            $errors[] = "Unknown field '$k' (allowed: command_roles, " . implode(', ', STS_API_POLICY_FLAGS) . ')';
        }
    }
    $roles = null;
    if (array_key_exists('command_roles', $in)) {
        if (!is_array($in['command_roles']) || ($in['command_roles'] && array_keys($in['command_roles']) === range(0, count($in['command_roles']) - 1))) {
            $errors[] = 'command_roles must be an object of command id to role';
        } else {
            $roles = [];
            foreach ($in['command_roles'] as $id => $role) {
                $id = (string) $id;
                if (!in_array($id, $command_ids, true)) {
                    $errors[] = "Unknown command id '$id'";
                } elseif (!is_string($role) || !in_array($role, api_policy_command_role_names(), true)) {
                    $errors[] = "Role for '$id' must be one of: " . implode(', ', api_policy_command_role_names());
                } elseif ($role !== api_policy_default_role($id)) {
                    $roles[$id] = $role;
                }
            }
        }
    }
    foreach (STS_API_POLICY_FLAGS as $f) {
        if (array_key_exists($f, $in) && $in[$f] !== null && !is_bool($in[$f])) {
            $errors[] = "$f must be true, false or null";
        }
    }
    if ($errors) {
        return $errors;
    }
    $path = api_policy_path();
    $dir = dirname($path);
    $old = umask(0027);
    try {
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return ['Policy directory is not writable'];
        }
        $lock = @fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            return ['Cannot lock the policy file'];
        }
        api_tokens_chown_www($path . '.lock');
        try {
            $cur = api_policy_read_raw();
            if ($roles !== null) {
                $cur['command_roles'] = (object) $roles;
            }
            foreach (STS_API_POLICY_FLAGS as $f) {
                if (array_key_exists($f, $in)) {
                    if ($in[$f] === null) {
                        unset($cur[$f]);
                    } else {
                        $cur[$f] = $in[$f];
                    }
                }
            }
            $cur['command_roles'] = (object) ($cur['command_roles'] ?? []);
            $tmp = $path . '.tmp.' . getmypid();
            $json = json_encode(['version' => 1] + $cur, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            if (@file_put_contents($tmp, $json) === false) {
                return ['Cannot write the policy file'];
            }
            @chmod($tmp, 0640);
            api_tokens_chown_www($tmp);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return ['Cannot replace the policy file'];
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } finally {
        umask($old);
    }
    return [];
}
