<?php
/**
 * Editor endpoints: the remaining actions of operational_steps_api.php,
 * simulator_api.php and save_operational_steps.php, on v1 (envelope responses;
 * the legacy `ok` flag is dropped). Runs still go through POST /workflows/{name}/run.
 *
 * Literal paths such as /workflows/compile are matched before /workflows/{name}
 * (the router prefers patterns with fewer {placeholders}).
 */
if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/operate_command.php';
require_once dirname(__DIR__) . '/lib/tasks.php';

/** Catalog plus session helpers (compile labels need both). */
function editor_load()
{
    read_load_catalog();
    read_session_helpers();
}

function editor_recipe_from_body(array $body)
{
    editor_load();
    if (array_key_exists('recipe', $body) && $body['recipe'] !== null && !is_array($body['recipe'])) {
        api_error(422, 'recipe must be an object');
    }
    return $body['recipe'] ?? null;
}

/** The workflow file a body names (workflow_file), else the active one; '' when none. */
function editor_body_workflow_file(array $body)
{
    editor_load();
    $requested = $body['workflow_file'] ?? null;
    if ($requested !== null && !is_string($requested)) {
        api_error(422, 'workflow_file must be a string');
    }
    try {
        return operational_steps_active_workflow(operational_steps_editor_dir(), $requested);
    } catch (InvalidArgumentException $e) {
        api_error(422, 'Invalid workflow name');
    }
}

api_route('POST', '/workflows/compile', static function ($req) {
    $recipe = editor_recipe_from_body(api_body());
    $recipe = $recipe ?? operational_steps_load_recipe(operational_steps_editor_dir());
    return ['compiled' => operational_steps_compile_recipe($recipe)];
}, 'reader', ['summary' => 'Compile a recipe (or the active workflow) into display rows']);

api_route('POST', '/workflows/normalize', static function ($req) {
    $body = api_body();
    editor_recipe_from_body($body);
    $dir = operational_steps_editor_dir();
    if (!empty($body['use_file'])) {
        $file = editor_body_workflow_file($body);
        if ($file === '') {
            api_error(422, 'No workflow file selected');
        }
        $recipe = operational_steps_load_recipe($dir, $file);
    } else {
        $recipe = operational_steps_normalize_recipe($body['recipe'] ?? operational_steps_load_recipe($dir));
    }
    return [
        'recipe' => $recipe,
        'compiled' => operational_steps_compile_recipe($recipe),
        'rows' => count($recipe['steps'] ?? []),
    ];
}, 'reader', ['summary' => 'Normalize a recipe (or reload a workflow file) without saving']);

api_route('POST', '/workflows/import', static function ($req) {
    $body = api_body();
    editor_recipe_from_body($body);
    $dir = operational_steps_editor_dir();
    if (!empty($body['recipe'])) {
        $recipe = operational_steps_normalize_recipe($body['recipe']);
    } elseif (!empty($body['use_file'])) {
        $file = editor_body_workflow_file($body);
        if ($file === '') {
            api_error(422, 'Choose a workflow file to load');
        }
        $recipe = operational_steps_load_recipe($dir, $file);
    } else {
        $raw = $body['workflow'] ?? $body['json'] ?? '';
        if ($raw === '') {
            api_error(422, 'No workflow JSON content');
        }
        $data = is_array($raw) ? $raw : operational_steps_decode_recipe_json($raw);
        if (!is_array($data)) {
            api_error(422, 'Invalid workflow JSON');
        }
        foreach ($data['steps'] ?? [] as $i => $step) {
            if (is_array($step)) {
                $data['steps'][$i]['structured_import'] = true;
            }
        }
        $recipe = operational_steps_normalize_recipe($data);
    }
    if (!empty($body['workflow_file'])) {
        operational_steps_set_active_workflow($dir, editor_body_workflow_file($body));
    }
    return [
        'recipe' => $recipe,
        'workflow_file' => operational_steps_active_workflow($dir),
        'compiled' => operational_steps_compile_recipe($recipe),
    ];
}, 'editor', ['summary' => 'Import workflow JSON (or a file) into the editor; sets the active workflow when workflow_file is given']);

api_route('GET', '/workflows/active', static function ($req) {
    editor_load();
    $dir = operational_steps_editor_dir();
    $files = operational_steps_list_workflow_files($dir);
    $requested = api_query_str($req['query'], 'workflow_file');
    return [
        'active_workflow' => operational_steps_resolve_active_workflow($dir, $requested !== '' ? $requested : null),
        'files' => $files,
        'auto_load' => count($files) === 1,
    ];
}, 'reader', ['summary' => 'The active workflow and the workflow files']);

api_route('PUT', '/workflows/active', static function ($req) {
    editor_load();
    $body = api_body();
    $file = trim((string) ($body['workflow_file'] ?? ''));
    if ($file === '') {
        api_error(422, 'Missing workflow_file');
    }
    try {
        $file = operational_steps_set_active_workflow(operational_steps_editor_dir(), $file);
    } catch (InvalidArgumentException $e) {
        api_error(422, 'Invalid workflow name');
    }
    return ['active_workflow' => $file];
}, 'editor', ['summary' => 'Set the active workflow']);

api_route('GET', '/workflows/{name}/run-options', static function ($req) {
    [$dir, $file] = read_workflow_name_file($req['params']['name']);
    require_once dirname(__DIR__, 2) . '/session_simulator_helpers.php';
    $recipe = operational_steps_load_recipe($dir, $file);
    $payload = session_simulator_run_options(api_db(), $dir, $recipe);
    unset($payload['ok']);
    $role = $req['user']['role'] ?? null;
    $payload['workflow'] = $file;
    $payload['caller_role'] = $role;
    $payload['role_warnings'] = api_recipe_role_warnings($recipe, $role);
    return $payload;
}, 'reader', ['summary' => 'Run options for a workflow: indices, sections, condition context, role warnings for the caller']);

api_route('GET', '/workflows/{name}/download', static function ($req) {
    [$dir, $file] = read_workflow_name_file($req['params']['name']);
    $path = operational_steps_recipe_paths_for_workflow($dir, $file)['workflow'];
    if (!is_file($path)) {
        read_not_found('Workflow');
    }
    $GLOBALS['api_responded'] = true;
    api_discard_output();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}, 'reader', ['summary' => 'Download the workflow file (raw attachment, not an envelope)']);

function editor_session_number($n)
{
    if (!ctype_digit($n) || (int) $n < 1) {
        read_not_found('Session');
    }
    read_session_helpers();
    return (int) $n;
}

function editor_style($body)
{
    if (isset($body['style']) && !is_string($body['style'])) {
        api_error(422, 'style must be a string');
    }
    return session_normalize_switchlist_style($body['style'] ?? 'mobile');
}

api_route('POST', '/sessions/{n}/rerender', static function ($req) {
    $n = editor_session_number($req['params']['n']);
    $body = api_body();
    $style = editor_style($body);
    @set_time_limit(0);
    $dbc = api_db();
    $root = session_web_root();
    $manifest = session_load_manifest($n, $root);
    $already = session_session_style_available($n, $style, $manifest, $root);
    $results = session_rerender_session_style($dbc, $n, $style, $root, !empty($body['force']));
    $skipped = count($results) > 0 && count(array_filter($results, static function ($r) {
        return !empty($r['skipped']);
    })) === count($results);
    return ['session' => $n, 'style' => $style, 'skipped' => $already || $skipped, 'results' => $results];
}, 'operator', ['summary' => 'Re-render a session\'s switch lists in another style']);

api_route('POST', '/sessions/{n}/print-all', static function ($req) {
    $n = editor_session_number($req['params']['n']);
    $body = api_body();
    $style = editor_style($body);
    $job = trim((string) ($body['job'] ?? ''));
    @set_time_limit(0);
    $dbc = api_db();
    $root = session_web_root();
    $rel = $job !== ''
        ? session_build_switchlist_train_print_all_style($dbc, $n, $job, $style, $root)
        : session_build_switchlist_print_all_style($dbc, $n, $style, $root);
    if ($rel === null) {
        api_error(404, 'No switch lists to render for this style');
    }
    return ['session' => $n, 'style' => $style, 'job' => $job, 'url' => session_output_url($rel)];
}, 'operator', ['summary' => 'Build the print-all bundle for a style (optionally one job)']);

api_route('DELETE', '/sessions/output', static function ($req) {
    read_session_helpers();
    $busy = array_merge(api_task_list(['status' => 'running']), api_task_list(['status' => 'queued']));
    if ($busy) {
        api_error(409, 'Task ' . $busy[0]['id'] . ' (' . $busy[0]['kind'] . ') is ' . $busy[0]['status'] . '; wait for it to finish');
    }
    $root = session_web_root();
    $removed = [];
    if (is_dir($root)) {
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            session_rrmdir($root . '/' . $entry);
            if (file_exists($root . '/' . $entry) || is_link($root . '/' . $entry)) {
                api_internal_error('could not remove ' . $root . '/' . $entry);
            }
            $removed[] = $entry;
        }
    }
    return ['removed' => $removed, 'count' => count($removed)];
}, 'operator', ['summary' => 'Delete all generated switch-list output (session directories)']);

api_route('GET', '/simulator/status', static function ($req) {
    require_once dirname(__DIR__, 2) . '/session_simulator_helpers.php';
    $payload = session_simulator_status(api_db(), []);
    unset($payload['ok']);
    return $payload;
}, 'reader', ['summary' => 'Simulator runtime status, session and condition context']);
