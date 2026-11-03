<?php
/**
 * Admin routes: backup, restore, warm start, begin/play a session (role: admin).
 * Everything that changes the database in bulk runs as an exclusive task; the
 * workflow run (operator) lives here too because it shares the task plumbing.
 */
if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/tasks.php';
require_once dirname(__DIR__) . '/lib/operate_command.php';

/** Backup names: letters, digits, underscore, hyphen (the CLI's rule), max 64. */
function admin_backup_name($value, $field)
{
    if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value)) {
        api_error(422, "$field must be 1-64 characters of letters, digits, '_' or '-'");
    }
    return $value;
}

function admin_backups_dir()
{
    require_once dirname(__DIR__, 2) . '/operational_steps_catalog.php';
    return operational_steps_backups_dir();
}

function admin_opt_int(array $body, $key, $min, $max)
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_int($body[$key]) && !(is_string($body[$key]) && preg_match('/^-?\d+$/', $body[$key]))) {
        api_error(422, "$key must be an integer");
    }
    $v = (int) $body[$key];
    if ($v < $min || $v > $max) {
        api_error(422, "$key must be between $min and $max");
    }
    return $v;
}

function admin_opt_bool(array $body, $key)
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_bool($body[$key])) {
        api_error(422, "$key must be true or false");
    }
    return $body[$key];
}

function admin_opt_float(array $body, $key)
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_numeric($body[$key]) || (float) $body[$key] < 0 || (float) $body[$key] > 1) {
        api_error(422, "$key must be a number between 0 and 1");
    }
    return (float) $body[$key];
}

function admin_opt_object(array $body, $key)
{
    if (!array_key_exists($key, $body) || $body[$key] === null) {
        return null;
    }
    if (!is_array($body[$key]) || ($body[$key] !== [] && array_keys($body[$key]) === range(0, count($body[$key]) - 1))) {
        api_error(422, "$key must be an object");
    }
    return $body[$key];
}

/** Keep only the keys of $spec that were given (non-null). */
function admin_compact(array $spec)
{
    return array_filter($spec, static function ($v) {
        return $v !== null;
    });
}

api_route('POST', '/admin/backups', static function ($req) {
    $body = api_body();
    $name = admin_backup_name($body['name'] ?? null, 'name');
    $overwrite = admin_opt_bool($body, 'overwrite') ?? false;
    $dir = admin_backups_dir();
    if (is_file($dir . '/' . $name) && !$overwrite) {
        api_error(409, 'A backup with that name exists; pass overwrite true to replace it');
    }
    require_once dirname(__DIR__, 2) . '/backup_tables.php';
    $cwd = getcwd();
    chdir(dirname(__DIR__, 2));
    try {
        backup_tables(api_db(), $name);
    } finally {
        chdir($cwd);
    }
    $path = $dir . '/' . $name;
    if (!is_file($path)) {
        api_internal_error('backup file was not written: ' . $path);
    }
    api_ok(['name' => $name, 'size' => (int) filesize($path), 'mtime' => gmdate('c', (int) filemtime($path))], null, 201);
}, 'admin', ['summary' => 'Back up the database (synchronous)']);

api_route('POST', '/admin/restore', static function ($req) {
    $body = api_body();
    $backup = $body['backup'] ?? null;
    if (!is_string($backup) || $backup === '' || $backup !== basename($backup) || $backup[0] === '.'
        || !preg_match('/^[A-Za-z0-9._-]{1,128}$/', $backup)) {
        api_error(422, 'backup must be a file name in the backups directory');
    }
    if (!is_file(admin_backups_dir() . '/' . $backup)) {
        api_error(404, 'Backup not found');
    }
    $photos = admin_opt_bool($body, 'photos') ?? false;
    $full = admin_opt_bool($body, 'full') ?? true;
    $rec = api_task_create('restore', admin_compact(['backup' => $backup, 'photos' => $photos, 'full' => $full, 'timeout_seconds' => admin_opt_int($body, 'timeout_seconds', 1, 86400)]), $req['user'], ['exclusive' => true]);
    api_task_accepted($rec);
}, 'admin', ['summary' => 'Restore a backup (task)', 'maint' => 'self']);

api_route('POST', '/admin/warm-start', static function ($req) {
    $body = api_body();
    $config = admin_opt_object($body, 'config');
    $spec = admin_compact([
        'timeout_seconds' => admin_opt_int($body, 'timeout_seconds', 1, 86400),
        'sessions' => admin_opt_int($body, 'sessions', 1, 1000),
        'max_sessions' => admin_opt_int($body, 'max_sessions', 1, 1000),
        'seed' => admin_opt_int($body, 'seed', 0, 2147483647),
        'continue' => admin_opt_bool($body, 'continue'),
        'tracked' => admin_opt_bool($body, 'tracked'),
        'config' => $config,
        'backup' => array_key_exists('backup', $body) && $body['backup'] !== null ? admin_backup_name($body['backup'], 'backup') : null,
    ]);
    if (!empty($spec['tracked'])) {
        require_once dirname(__DIR__, 2) . '/plugin_registry.php';
        if (!is_array(plugins_scenario_call('tracked_overrides', [], null))) {
            api_error(422, 'tracked is not supported by the installed plugins');
        }
    }
    $rec = api_task_create('warm_start', $spec, $req['user'], ['exclusive' => true]);
    api_task_accepted($rec);
}, 'admin', ['summary' => 'Simulate sessions to a warm-start state (task)', 'maint' => 'self']);

api_route('POST', '/admin/sessions/begin', static function ($req) {
    $body = api_body();
    $spec = admin_compact([
        'timeout_seconds' => admin_opt_int($body, 'timeout_seconds', 1, 86400),
        'load_unload' => admin_opt_bool($body, 'load_unload'),
        'increment' => admin_opt_bool($body, 'increment'),
        'reposition' => admin_opt_bool($body, 'reposition'),
        'assign' => admin_opt_bool($body, 'assign'),
        'fill' => admin_opt_bool($body, 'fill'),
        'generate' => admin_opt_bool($body, 'generate'),
        'reposition_fraction' => admin_opt_float($body, 'reposition_fraction'),
        'fill_fraction' => admin_opt_float($body, 'fill_fraction'),
        'seed' => admin_opt_int($body, 'seed', 0, 2147483647),
        'config' => admin_opt_object($body, 'config'),
        'options' => admin_opt_object($body, 'options'),
        'backup' => array_key_exists('backup', $body) && $body['backup'] !== null ? admin_backup_name($body['backup'], 'backup') : null,
    ]);
    $rec = api_task_create('begin_session', $spec, $req['user'], ['exclusive' => true]);
    api_task_accepted($rec);
}, 'admin', ['summary' => 'Begin an operating session (task)', 'maint' => 'self']);

api_route('POST', '/admin/sessions/play', static function ($req) {
    $body = api_body();
    $spec = admin_compact([
        'timeout_seconds' => admin_opt_int($body, 'timeout_seconds', 1, 86400),
        'seed' => admin_opt_int($body, 'seed', 0, 2147483647),
        'config' => admin_opt_object($body, 'config'),
        'backup' => array_key_exists('backup', $body) && $body['backup'] !== null ? admin_backup_name($body['backup'], 'backup') : null,
    ]);
    $rec = api_task_create('play_session', $spec, $req['user'], ['exclusive' => true]);
    api_task_accepted($rec);
}, 'admin', ['summary' => 'Play the current operating session (task)', 'maint' => 'self']);

api_route('POST', '/workflows/{name}/run', static function ($req) {
    $body = api_body();
    [$dir, $file] = read_workflow_name_file($req['params']['name']);
    $recipe = operational_steps_load_recipe_from_json_file(operational_steps_workflow_path($dir, $file));
    $total = count($recipe['steps'] ?? []);
    $start = admin_opt_int($body, 'start_step', 1, max(1, $total));
    $stop = admin_opt_int($body, 'stop_step', 1, max(1, $total));
    if ($start !== null && $stop !== null && $stop < $start) {
        api_error(422, 'stop_step must not be before start_step');
    }
    $skip = $body['skip'] ?? null;
    if (is_array($skip)) {
        $skip = implode(',', array_map('strval', $skip));
    }
    if ($skip !== null && (!is_string($skip) || !preg_match('/^[0-9,\s-]*$/', $skip))) {
        api_error(422, "skip must be step numbers and ranges such as '1-2,5'");
    }
    $skip_steps = $skip !== null ? trim($skip) : '';
    if (array_key_exists('sections', $body) && $body['sections'] !== null) {
        if (!is_array($body['sections'])) {
            api_error(422, 'sections must be a list of section labels or ids');
        }
        $keep = [];
        foreach ($body['sections'] as $want) {
            $sec = is_string($want) ? operational_steps_find_workflow_section($recipe, $want, 0, $want) : null;
            if ($sec === null) {
                api_error(422, 'Unknown section: ' . (is_scalar($want) ? (string) $want : '?'));
            }
            for ($n = $sec['start']; $n <= $sec['stop']; $n++) {
                $keep[$n] = true;
            }
        }
        $drop = [];
        for ($n = 1; $n <= $total; $n++) {
            if (!isset($keep[$n])) {
                $drop[] = $n;
            }
        }
        $skip_steps = trim($skip_steps . ($skip_steps !== '' && $drop ? ',' : '') . implode(',', $drop), ',');
    }
    $render = admin_opt_bool($body, 'render');
    $spec = admin_compact([
        'timeout_seconds' => admin_opt_int($body, 'timeout_seconds', 1, 86400),
        'workflow' => $file,
        'creator_role' => $req['user']['role'] ?? null,
        'seed' => admin_opt_int($body, 'seed', 0, 2147483647),
        'start_step' => $start,
        'stop_step' => $stop,
        'skip_steps' => $skip_steps !== '' ? $skip_steps : null,
        'session_count' => admin_opt_int($body, 'session_count', 1, 50),
        'render' => $render,
    ]);
    $rec = api_task_create('workflow_run', $spec, $req['user'], ['exclusive' => true]);
    $role = $req['user']['role'] ?? null;
    api_ok([
        'task_id' => $rec['id'], 'status' => $rec['status'], 'kind' => $rec['kind'],
        'caller_role' => $role,
        'role_warnings' => api_recipe_role_warnings($recipe, $role),
    ], null, 202);
}, 'operator', ['summary' => 'Run a saved workflow (task)', 'maint' => 'self']);
