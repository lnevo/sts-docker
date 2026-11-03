<?php
/**
 * Task kind workflow_run: session_run_recipe(), modelled on run_catalog_workflow.php.
 * Spec: workflow (saved file name), seed, start_step, stop_step, skip_steps
 * (range text), session_count, render.
 * With a seed the global STS_SIM_SEED_ACTIVE flag is set, as the CLI does.
 */
function api_task_kind_workflow_run(array $spec, callable $log)
{
    $sts = dirname(__DIR__, 2);
    require_once $sts . '/open_db.php';
    require_once $sts . '/session_helpers.php';
    require_once $sts . '/operational_steps_catalog.php';
    chdir($sts);

    if (isset($spec['seed']) && is_numeric($spec['seed'])) {
        mt_srand((int) $spec['seed']);
        $GLOBALS['STS_SIM_SEED_ACTIVE'] = true;
    }
    $editor = operational_steps_editor_dir();
    $file = operational_steps_resolve_workflow_filename($editor, (string) ($spec['workflow'] ?? ''));
    if ($file === '') {
        throw new ApiTaskFailure('Workflow not found');
    }
    $recipe = operational_steps_load_recipe_from_json_file(operational_steps_workflow_path($editor, $file));
    $recipe['source_workflow'] = basename($file);

    $options = ['format' => 'all'];
    if (!empty($spec['start_step'])) {
        $options['from_step'] = (int) $spec['start_step'];
    }
    if (!empty($spec['stop_step'])) {
        $options['to_step'] = (int) $spec['stop_step'];
    }
    if (!empty($spec['skip_steps'])) {
        $options['skip_steps'] = (string) $spec['skip_steps'];
    }
    $runs = max(1, min(50, (int) ($spec['session_count'] ?? 1)));

    $dbc = open_db();
    $errors = [];
    $cycles = [];
    $dispatched = 0;
    $skipped = 0;
    $control = 0;
    for ($i = 1; $i <= $runs; $i++) {
        $log('workflow ' . $file . ' run ' . $i . ' of ' . $runs);
        $res = session_run_recipe($dbc, $recipe, $options);
        if (!empty($res['error'])) {
            $errors[] = (string) $res['error'];
        }
        foreach ($res['log'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!empty($entry['error'])) {
                $errors[] = 'Step ' . ($entry['step'] ?? '?') . ': ' . $entry['error'];
            }
            if (!empty($entry['skipped'])) {
                $skipped++;
                continue;
            }
            $action = $entry['action'] ?? ($entry['function'] ?? '');
            if (in_array($action, ['section_label', 'if_then', 'goto', 'stop'], true)) {
                $control++;
            } elseif ($action !== '') {
                $dispatched++;
            }
        }
        $cycles[] = ['session' => $res['session'] ?? null, 'phases' => $res['phases'] ?? 0];
        $log('session ' . ($res['session'] ?? '?') . ', phases ' . ($res['phases'] ?? 0));
        if ($errors) {
            break;
        }
    }
    mysqli_close($dbc);
    $out = ['workflow' => $file, 'steps' => count($recipe['steps'] ?? []), 'cycles' => $cycles,
        'dispatched' => $dispatched, 'control' => $control, 'skipped' => $skipped, 'errors' => $errors];
    foreach ($errors as $e) {
        $log('error: ' . $e);
    }
    if ($errors) {
        throw new ApiTaskFailure(count($errors) . ' workflow error(s); first: ' . $errors[0], $out);
    }
    return $out;
}
