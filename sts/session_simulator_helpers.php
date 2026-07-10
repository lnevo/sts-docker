<?php
/**
 * Session workflow simulator — run recipe steps against the live STS database.
 */

require_once __DIR__ . '/operational_steps_catalog.php';
require_once __DIR__ . '/session_helpers.php';

function session_simulator_body()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $cached = is_array($data) ? $data : [];
    return $cached;
}

function session_simulator_load_recipe($session_dir, array $body = [])
{
    if (!empty($body['recipe']) && is_array($body['recipe'])) {
        return $body['recipe'];
    }
    $csv = $body['csv_file'] ?? null;
    return operational_steps_load_recipe($session_dir, $csv);
}

function session_simulator_maybe_save_recipe($session_dir, array $body)
{
    if (empty($body['recipe']) || !is_array($body['recipe']) || empty($body['save_recipe'])) {
        return null;
    }
    $recipe = $body['recipe'];
    if (!isset($recipe['version'])) {
        $recipe['version'] = 1;
    }
    return operational_steps_save_recipe(
        $session_dir,
        operational_steps_normalize_recipe($recipe),
        $body['csv_file'] ?? null
    );
}

function session_simulator_run_options($dbc, $session_dir, array $recipe)
{
    $compiled = operational_steps_compile_recipe($recipe);
    $indices = operational_steps_recipe_indices($recipe);
    $sections = operational_steps_workflow_sections($recipe);
    $current_session = warm_start_get_session($dbc);
    $existing = session_discover_sessions(session_web_root());

    $ctx = session_evaluate_context($dbc, warm_start_merge_config([]));
    $condition_context = $ctx;
    unset($condition_context['_dbc'], $condition_context['_config']);

    return [
        'ok' => true,
        'current_session' => $current_session,
        'existing_sessions' => $existing,
        'indices' => $indices,
        'sections' => $sections,
        'default_start' => 1,
        'default_stop' => $indices['total'] ?: count($recipe['steps'] ?? []),
        'breakpoints' => $indices['breakpoints'],
        'compiled' => $compiled,
        'condition_context' => $condition_context,
        'composites' => [
            ['id' => 'warm_start_tracked', 'label' => 'Warm start (tracked composite)'],
            ['id' => 'begin_operating_session', 'label' => 'Begin operating session'],
            ['id' => 'play_operating_session', 'label' => 'Play operating session'],
        ],
    ];
}

function session_simulator_status($dbc, array $config = [])
{
    $config = warm_start_merge_config($config);
    $evaluation = warm_start_evaluate_session_prep($dbc, $config);
    $ctx = session_evaluate_context($dbc, $config);
    $scully = $evaluation['stg_scully_backlog'] ?? ['eligible' => 0, 'on_jobs' => 0, 'ready' => false];

    return [
        'ok' => true,
        'session' => warm_start_get_session($dbc),
        'evaluation' => $evaluation,
        'context' => [
            'session_nbr' => (int) ($ctx['session_nbr'] ?? 0),
            'unfilled_count' => (int) ($ctx['unfilled_count'] ?? 0),
            'stg_backlog_eligible' => (int) ($ctx['stg_backlog_eligible'] ?? 0),
            'stg_backlog_on_jobs' => (int) ($ctx['stg_backlog_on_jobs'] ?? 0),
            'awaiting_assignment' => (int) ($ctx['awaiting_assignment'] ?? 0),
        ],
        'stg_scully_backlog_ready' => !empty($scully['ready']),
        'summary' => warm_start_summarize($dbc),
    ];
}

function session_simulator_is_loop_section($label)
{
    $label = trim((string) $label);
    return stripos($label, 'repeat steps') !== false
        && (stripos($label, 'warm start') !== false || stripos($label, 'STG-SCULLY') !== false);
}

function session_simulator_parse_repeat_range($label, array $section, $total_steps)
{
    if (preg_match('/repeat steps\s+(\d+)\s*[-–]\s*(\d+)/i', $label, $m)) {
        return [
            'from' => max(1, (int) $m[1]),
            'to' => min($total_steps, (int) $m[2]),
        ];
    }
    $from = (int) $section['start'] + 1;
    $to = (int) $section['stop'];
    if ($to > $from && ($section['stop'] ?? 0) < $total_steps) {
        $to--;
    }
    return ['from' => $from, 'to' => max($from, $to)];
}

function session_simulator_run_recipe_range($dbc, array $recipe, $from_step, $to_step, array $options = [])
{
    $config = warm_start_merge_config($options['config'] ?? []);
    return session_run_recipe($dbc, $recipe, [
        'from_step' => (int) $from_step,
        'to_step' => (int) $to_step,
        'format' => $options['format'] ?? 'phased',
        'config' => $config,
        'session_root' => $options['session_root'] ?? session_web_root(),
    ]);
}

function session_simulator_run_composite($dbc, $composite_id, array $options = [])
{
    $config = warm_start_merge_config($options['config'] ?? []);
    $step = ['function' => $composite_id, 'params' => $options['params'] ?? []];
    $dispatch_opts = array_merge($config, [
        'session_root' => $options['session_root'] ?? session_web_root(),
    ]);

    switch ($composite_id) {
        case 'warm_start_tracked':
            $step['params'] = array_merge([
                'min_sessions' => (int) ($options['min_sessions'] ?? 3),
                'max_sessions' => (int) ($options['max_sessions'] ?? 12),
            ], $step['params']);
            break;
        case 'begin_operating_session':
            $step['params'] = array_merge([
                'run_stg_scully' => ($options['run_stg_scully'] ?? 'yes') !== 'no' ? 'yes' : 'no',
            ], $step['params']);
            break;
        default:
            break;
    }

    $result = operational_steps_dispatch_step($dbc, $step, $dispatch_opts);
    return [
        'composite' => $composite_id,
        'session' => (string) warm_start_get_session($dbc),
        'result' => $result,
        'log' => [['composite' => $composite_id, 'result' => $result]],
    ];
}

function session_simulator_run_warm_start_loop($dbc, array $recipe, array $section, array $options = [])
{
    $config = warm_start_merge_config($options['config'] ?? []);
    $total = count($recipe['steps'] ?? []);
    $range = session_simulator_parse_repeat_range($section['label'] ?? '', $section, $total);
    $min_sessions = max(1, (int) ($options['min_sessions'] ?? 3));
    $max_iterations = max(1, (int) ($options['max_sessions'] ?? 12));
    $iterations = [];
    $warnings = [];

    for ($i = 0; $i < $max_iterations; $i++) {
        $run = session_simulator_run_recipe_range($dbc, $recipe, $range['from'], $range['to'], $options);
        $eval = warm_start_evaluate_session_prep($dbc, $config);
        $session = warm_start_get_session($dbc);
        $ready = !empty($eval['stg_scully_backlog']['ready']);
        $iterations[] = [
            'iteration' => $i + 1,
            'session' => (string) $session,
            'range' => $range,
            'stg_scully_backlog_ready' => $ready,
            'phases' => $run['phases'] ?? 0,
            'log' => $run['log'] ?? [],
            'stopped' => $run['stopped'] ?? false,
        ];
        if ($ready && $session >= $min_sessions) {
            break;
        }
        if ($i === $max_iterations - 1) {
            $warnings[] = 'Warm start loop reached max iterations (' . $max_iterations . ') before backlog ready at min session ' . $min_sessions . '.';
        }
    }

    $last = end($iterations) ?: [];
    return [
        'mode' => 'warm_start_loop',
        'section' => $section,
        'iterations' => $iterations,
        'session' => $last['session'] ?? (string) warm_start_get_session($dbc),
        'warnings' => $warnings,
        'cycles' => $iterations,
    ];
}

function session_simulator_run($dbc, array $recipe, array $options = [])
{
    $total = count($recipe['steps'] ?? []);
    $start = max(1, (int) ($options['start_step'] ?? 1));
    $stop = max($start, min((int) ($options['stop_step'] ?? $total), $total));
    $repeat = max(1, (int) ($options['session_count'] ?? 1));
    $cycles = [];
    $warnings = [];

    for ($cycle = 0; $cycle < $repeat; $cycle++) {
        if ($repeat > 1 && $cycle > 0) {
            $warnings[] = 'Cycle ' . ($cycle + 1) . ': steps ' . $start . '–' . $stop . '.';
        }
        $run = session_simulator_run_recipe_range($dbc, $recipe, $start, $stop, $options);
        if (!empty($run['error'])) {
            $warnings[] = $run['error'];
        }
        $cycles[] = [
            'cycle' => $cycle + 1,
            'session' => $run['session'] ?? (string) warm_start_get_session($dbc),
            'start_step' => $start,
            'stop_step' => $stop,
            'phases' => $run['phases'] ?? 0,
            'stopped' => $run['stopped'] ?? false,
            'error' => $run['error'] ?? null,
            'log' => $run['log'] ?? [],
        ];
    }

    $last = end($cycles) ?: [];
    return [
        'ok' => true,
        'mode' => $repeat > 1 ? 'repeat' : 'run',
        'start_step' => $start,
        'stop_step' => $stop,
        'session_count' => $repeat,
        'session' => $last['session'] ?? '',
        'sessions' => array_values(array_unique(array_column($cycles, 'session'))),
        'cycles' => $cycles,
        'warnings' => $warnings,
        'summary' => session_simulator_format_summary($cycles, $warnings),
        'index_url' => '/sts/session.php',
        'session_url' => !empty($last['session'])
            ? '/sts/session_' . $last['session'] . '/index.php'
            : '/sts/session.php',
    ];
}

function session_simulator_run_section($dbc, array $recipe, $section_id, array $options = [])
{
    if (preg_match('/^composite:(.+)$/', (string) $section_id, $m)) {
        $result = session_simulator_run_composite($dbc, $m[1], $options);
        $result['ok'] = true;
        $result['mode'] = 'composite';
        $result['summary'] = ['Composite: ' . $m[1], 'Session ' . ($result['session'] ?? '')];
        $result['cycles'] = [['cycle' => 1, 'session' => $result['session'] ?? '', 'log' => $result['log'] ?? []]];
        $result['index_url'] = '/sts/session.php';
        $result['session_url'] = !empty($result['session'])
            ? '/sts/session_' . $result['session'] . '/index.php'
            : '/sts/session.php';
        return $result;
    }

    $section = operational_steps_find_workflow_section($recipe, (string) $section_id);
    if (!$section && preg_match('/^step-(\d+)$/', (string) $section_id, $m)) {
        $section = operational_steps_find_workflow_section($recipe, '', (int) $m[1]);
    }
    if (!$section) {
        return ['ok' => false, 'error' => 'Unknown section: ' . $section_id];
    }

    $start = isset($options['start_step']) && (int) $options['start_step'] > 0
        ? (int) $options['start_step']
        : (int) $section['start'];
    $stop = isset($options['stop_step']) && (int) $options['stop_step'] > 0
        ? (int) $options['stop_step']
        : (int) $section['stop'];
    return session_simulator_run($dbc, $recipe, array_merge($options, [
        'start_step' => $start,
        'stop_step' => $stop,
        'session_count' => (int) ($options['session_count'] ?? 1),
    ]));
}

function session_simulator_format_summary(array $cycles, array $warnings = [])
{
    $lines = [];
    foreach ($cycles as $cycle) {
        $label = isset($cycle['iteration']) ? 'Iteration' : 'Cycle';
        $n = $cycle['iteration'] ?? $cycle['cycle'] ?? '?';
        $lines[] = sprintf(
            '%s %s — session %s, %d phase(s)%s%s',
            $label,
            $n,
            $cycle['session'] ?? '?',
            (int) ($cycle['phases'] ?? 0),
            !empty($cycle['stopped']) ? ' (stopped)' : '',
            !empty($cycle['error']) ? ' — ' . $cycle['error'] : ''
        );
        foreach ($cycle['log'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!empty($entry['written']) && is_array($entry['written'])) {
                foreach ($entry['written'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $lines[] = sprintf(
                        '  step %s %s: %d phase(s), %d car(s)',
                        $entry['step'] ?? '?',
                        $item['job'] ?? '?',
                        $item['phases'] ?? 0,
                        $item['cars'] ?? 0
                    );
                }
            } elseif (!empty($entry['phase']) && !empty($entry['written'])) {
                foreach ($entry['written'] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $lines[] = sprintf(
                        '  step %s phase %s %s: %d car(s)',
                        $entry['step'] ?? '?',
                        $entry['phase'] ?? '?',
                        $item['job'] ?? '?',
                        $item['cars'] ?? 0
                    );
                }
            } elseif (!empty($entry['action'])) {
                $detail = $entry['action']
                    . (isset($entry['target']) ? ' → ' . $entry['target'] : '');
                if (!empty($entry['error'])) {
                    $detail .= ' — ' . $entry['error'];
                }
                $lines[] = '  step ' . ($entry['step'] ?? '?') . ': ' . $detail;
            } elseif (!empty($entry['function']) && empty($entry['skipped'])) {
                $lines[] = '  step ' . ($entry['step'] ?? '?') . ': ' . ($entry['function'] ?? 'dispatch');
            }
        }
    }
    if (count($warnings) > 0) {
        $lines[] = '';
        foreach ($warnings as $w) {
            $lines[] = $w;
        }
    }
    return $lines;
}
