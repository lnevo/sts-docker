<?php
/**
 * Per-session move statistics. The counters themselves are scenario-defined: a
 * registered warm-start scenario supplies the blank structure ('stats_blank'),
 * records pickups/setouts ('stats_record_pickup' / 'stats_record_setout'), adds
 * row columns from the named-stats deltas ('stats_row_extra') and describes how to
 * print them ('stats_labels'). Without a scenario nothing is counted or printed.
 */

function warm_start_session_stats_blank()
{
    $blank = plugins_scenario_call('stats_blank', [], []);

    return is_array($blank) ? $blank : [];
}

function &warm_start_session_stats_current()
{
    static $stats = null;
    if ($stats === null) {
        $stats = warm_start_session_stats_blank();
    }
    return $stats;
}

function warm_start_session_stats_reset()
{
    $stats = &warm_start_session_stats_current();
    $stats = warm_start_session_stats_blank();
}

function &warm_start_session_stats_log()
{
    static $log = [];
    return $log;
}

function warm_start_reset_session_stats_log()
{
    $log = &warm_start_session_stats_log();
    $log = [];
}

function warm_start_record_job_pickup($dbc, $job_name, $location_id)
{
    plugins_scenario_call('stats_record_pickup', [$dbc, $job_name, $location_id], null);
}

function warm_start_record_job_setout($dbc, $job_name, $location_id)
{
    plugins_scenario_call('stats_record_setout', [$dbc, $job_name, $location_id], null);
}

/**
 * Snapshot the current session's counters (every bucket the scenario defines) plus
 * scenario columns derived from the named-stats deltas, append it to the log, and reset.
 */
function warm_start_session_stats_commit($session, $label, ?array $stats_before = null, array $core_counts = [])
{
    $s = warm_start_session_stats_current();
    $delta = $stats_before === null
        ? []
        : warm_start_stats_delta_all($stats_before, warm_start_stats_copy_all());

    $row = [
        'session' => (int) $session,
        'label' => $label,
    ];
    foreach ($s as $key => $value) {
        $row[$key] = $value;
    }
    if ($core_counts) {
        $row['core'] = [];
        foreach (['generated', 'filled', 'repositioned', 'assigned', 'picked_up', 'set_out', 'load_unload'] as $key) {
            $row['core'][$key] = (int) ($core_counts[$key] ?? 0);
        }
    }
    $extra = plugins_scenario_call('stats_row_extra', [$delta], []);
    if (is_array($extra)) {
        foreach ($extra as $key => $value) {
            $row[$key] = $value;
        }
    }

    $log = &warm_start_session_stats_log();
    $log[] = $row;
    warm_start_session_stats_reset();

    return $row;
}

/** Generic report used when the scenario supplies no 'stats_labels' (e.g. no plugin). */
function warm_start_core_session_stats_labels()
{
    return [
        [
            'title' => 'Session activity',
            'row_key' => 'core',
            'rule' => 48,
            'columns' => [
                ['key' => 'generated', 'label' => 'Gen', 'width' => 4],
                ['key' => 'filled', 'label' => 'Fill', 'width' => 4],
                ['key' => 'repositioned', 'label' => 'Repo', 'width' => 4],
                ['key' => 'assigned', 'label' => 'Asgn', 'width' => 4],
                ['key' => 'picked_up', 'label' => 'Pick', 'width' => 4],
                ['key' => 'set_out', 'label' => 'SOut', 'width' => 4],
                ['key' => 'load_unload', 'label' => 'L/U', 'width' => 4],
            ],
            'note' => '  Gen=orders generated  Fill=orders filled  Repo=repositioned  Asgn=assigned  Pick/SOut=pickups/setouts  L/U=loads+unloads',
        ],
    ];
}

/**
 * Report sections from the scenario's 'stats_labels' callable. Each section:
 * title, row_key (key of the per-session array holding the columns, or null for
 * top-level row keys), columns [[key, label, width], ...], rule (width of the
 * dashed line), note (optional legend line).
 *
 * @return list<array>
 */
function warm_start_session_stats_labels()
{
    $sections = plugins_scenario_call('stats_labels', [], 'warm_start_core_session_stats_labels');

    return is_array($sections) ? $sections : [];
}

function warm_start_format_session_stats_report()
{
    $log = warm_start_session_stats_log();
    $sections = warm_start_session_stats_labels();
    if (count($log) === 0 || count($sections) === 0) {
        return '';
    }

    $lines = [];
    foreach ($sections as $n => $section) {
        if ($n > 0) {
            $lines[] = '';
        }
        $columns = $section['columns'] ?? [];
        $row_key = $section['row_key'] ?? null;
        $head_fmt = '%-7s';
        $row_fmt = '%-7d';
        $head = ['Sess'];
        foreach ($columns as $col) {
            $width = (int) ($col['width'] ?? 3);
            $head_fmt .= ' %' . $width . 's';
            $row_fmt .= ' %' . $width . 'd';
            $head[] = (string) $col['label'];
        }
        $total_fmt = str_replace('%-7d', '%-7s', $row_fmt);

        $lines[] = '=== ' . $section['title'] . ' ===';
        $lines[] = vsprintf($head_fmt, $head);
        $lines[] = str_repeat('-', (int) ($section['rule'] ?? 40));
        $totals = [];
        foreach ($columns as $col) {
            $totals[$col['key']] = 0;
        }
        foreach ($log as $row) {
            $source = $row_key === null ? $row : ($row[$row_key] ?? []);
            $values = [$row['session']];
            foreach ($columns as $col) {
                $values[] = (int) ($source[$col['key']] ?? 0);
                $totals[$col['key']] += (int) ($source[$col['key']] ?? 0);
            }
            $lines[] = vsprintf($row_fmt, $values);
        }
        $lines[] = vsprintf($total_fmt, array_merge(['Total'], array_values($totals)));
        if (!empty($section['note'])) {
            $lines[] = $section['note'];
        }
    }

    return implode(PHP_EOL, $lines);
}

function warm_start_print_session_stats_report()
{
    $report = warm_start_format_session_stats_report();
    if ($report !== '') {
        warm_start_log('');
        warm_start_log($report);
    }
}
