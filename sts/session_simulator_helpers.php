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

function session_simulator_merge_config(array $config = [])
{
    if (function_exists('warm_start_merge_config')) {
        return warm_start_merge_config($config);
    }
    return $config;
}

function session_simulator_run_options($dbc, $session_dir, array $recipe)
{
    $compiled = operational_steps_compile_recipe($recipe);
    $indices = operational_steps_recipe_indices($recipe);
    $sections = operational_steps_workflow_sections($recipe);
    $current_session = session_get_db_session($dbc);
    $existing = session_discover_sessions(session_web_root());
    $runtime_ok = session_runtime_available();

    $ctx = session_evaluate_context($dbc, session_simulator_merge_config([]));
    $condition_context = $ctx;
    unset($condition_context['_dbc'], $condition_context['_config'], $condition_context['_runtime_limited']);

    return [
        'ok' => true,
        'runtime_available' => $runtime_ok,
        'runtime_notice' => $runtime_ok ? null : session_runtime_notice(),
        'current_session' => $current_session,
        'existing_sessions' => $existing,
        'indices' => $indices,
        'sections' => $sections,
        'default_start' => 1,
        'default_stop' => $indices['total'] ?: count($recipe['steps'] ?? []),
        'breakpoints' => $indices['breakpoints'],
        'compiled' => $compiled,
        'condition_context' => $condition_context,
    ];
}

function session_simulator_status($dbc, array $config = [])
{
    $runtime_ok = session_runtime_available();
    $payload = [
        'ok' => true,
        'runtime_available' => $runtime_ok,
        'session' => session_get_db_session($dbc),
    ];
    if (!$runtime_ok) {
        return array_merge($payload, ['runtime_notice' => session_runtime_notice()]);
    }

    $config = session_simulator_merge_config($config);
    $ctx = session_evaluate_context($dbc, $config);
    unset($ctx['_dbc'], $ctx['_config'], $ctx['_runtime_limited']);
    $payload['context'] = $ctx;
    if (function_exists('warm_start_summarize')) {
        $payload['summary'] = warm_start_summarize($dbc);
    }
    return $payload;
}

function session_simulator_run_recipe_range($dbc, array $recipe, $from_step, $to_step, array $options = [])
{
    return session_run_recipe($dbc, $recipe, [
        'from_step' => (int) $from_step,
        'to_step' => (int) $to_step,
        'format' => $options['format'] ?? 'phased',
        'config' => array_merge(session_simulator_merge_config($options['config'] ?? []), [
            'recipe' => $recipe,
        ]),
        'session_root' => $options['session_root'] ?? session_web_root(),
    ]);
}

function session_simulator_run($dbc, array $recipe, array $options = [])
{
    if (!session_runtime_available()) {
        return [
            'ok' => false,
            'error' => session_runtime_notice(),
            'runtime_available' => false,
        ];
    }

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
            'session' => $run['session'] ?? (string) session_get_db_session($dbc),
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
    if (!session_runtime_available()) {
        return [
            'ok' => false,
            'error' => session_runtime_notice(),
            'runtime_available' => false,
        ];
    }

    if (preg_match('/^composite:(.+)$/', (string) $section_id)) {
        return [
            'ok' => false,
            'error' => 'Composite session commands are no longer supported. Use workflow steps instead.',
        ];
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
