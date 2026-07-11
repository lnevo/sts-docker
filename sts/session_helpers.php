<?php
/**
 * Session output (/sts/), phase manifest, waybills, recipe control flow.
 */

require_once __DIR__ . '/session_runtime.php';
session_runtime_bootstrap();

function session_merge_runtime_config(array $config = [])
{
    if (function_exists('warm_start_merge_config')) {
        return warm_start_merge_config($config);
    }
    return $config;
}

function session_job_id($dbc, $job_name)
{
    if (function_exists('warm_start_job_id')) {
        return (int) warm_start_job_id($dbc, $job_name);
    }
    $job_name = trim((string) $job_name);
    if ($job_name === '') {
        return 0;
    }
    $rs = mysqli_query(
        $dbc,
        'SELECT id FROM jobs WHERE name = "' . mysqli_real_escape_string($dbc, $job_name) . '" LIMIT 1'
    );
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return 0;
    }
    return (int) mysqli_fetch_array($rs)['id'];
}

function session_location_id_by_code($dbc, $code)
{
    if (function_exists('operational_steps_location_id_by_code')) {
        return operational_steps_location_id_by_code($dbc, $code);
    }
    if (function_exists('warm_start_location_id_by_code')) {
        return (int) warm_start_location_id_by_code($dbc, $code);
    }
    $code = strtoupper(trim((string) $code));
    if ($code === '') {
        return 0;
    }
    $rs = mysqli_query(
        $dbc,
        'SELECT id FROM locations WHERE code = "' . mysqli_real_escape_string($dbc, $code) . '" LIMIT 1'
    );
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return 0;
    }
    return (int) mysqli_fetch_array($rs)['id'];
}

function session_staging_job_names($dbc, array $config = [])
{
    if (function_exists('operational_steps_staging_job_names')) {
        return operational_steps_staging_job_names($dbc, $config);
    }
    if (function_exists('warm_start_staging_job_names')) {
        return warm_start_staging_job_names($dbc, $config);
    }
    $jobs = [];
    $rs = mysqli_query($dbc, 'SELECT name FROM jobs WHERE name LIKE "STG-%" ORDER BY name');
    while ($rs && ($row = mysqli_fetch_array($rs))) {
        $name = (string) ($row['name'] ?? '');
        if ($name !== '') {
            $jobs[] = $name;
        }
    }
    return $jobs;
}

/** STS PHP application root (read-only code; not session output). */
function session_app_root()
{
    return __DIR__;
}

/**
 * Writable filesystem root for generated session output (switch lists, waybills,
 * manifests). Uses sts/temp/sessions; sts/temp is owned by www-data in the Docker
 * image, so this subdirectory is created on demand and stays writable.
 * Per-session output lives at sts/temp/sessions/session_N/... and phase output at
 * sts/temp/sessions/session_N/phase_PP/... .
 */
function session_web_root()
{
    return session_app_root() . '/temp/sessions';
}

/** Ensure the writable output root exists. */
function session_ensure_output_root($root = null)
{
    $root = $root ?? session_web_root();
    if (!is_dir($root)) {
        mkdir($root, 0755, true);
    }

    return $root;
}

/**
 * Public browser URL for a generated session output file. Files physically live
 * under sts/temp (writable by www-data), but are served to the browser through
 * so.php, so the temp/ path never appears in a URL. Example:
 *   session_output_url('session_3/phase_01/CK1/phase_01_mobile.html')
 *   => 'so.php?f=session_3/phase_01/CK1/phase_01_mobile.html'
 */
function session_output_url($relative_path = '')
{
    return 'so.php?f=' . ltrim(str_replace('\\', '/', (string) $relative_path), '/');
}

/** Public URL for a per-session overview page (a real PHP page, not static output). */
function session_session_index_href($session_nbr)
{
    return 'session_overview.php?session=' . (int) $session_nbr;
}

/** Strip a leading temp/ from a URL path to get the output-relative segment. */
function session_output_rel_strip($href)
{
    return ltrim(preg_replace('#^temp/#', '', (string) $href), '/');
}

/** Absolute filesystem path for session output (relative segment without temp/). */
function session_output_fs_path($relative_path, $root = null)
{
    $root = $root ?? session_web_root();

    return rtrim($root, '/') . '/' . session_output_rel_strip($relative_path);
}

/**
 * Relative ../ chain from a generated file's directory up to /sts/, computed from
 * the file's PUBLIC path (session_N/...), not its physical temp/ path, so nav
 * links in static output resolve correctly under the rewritten URLs.
 */
function session_relative_prefix_from_app($dir)
{
    $out = rtrim(str_replace('\\', '/', session_web_root()), '/');
    $app = rtrim(str_replace('\\', '/', session_app_root()), '/');
    $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');

    if ($dir === $out || $dir === $app) {
        return '';
    }
    if (strpos($dir, $out . '/') === 0) {
        $public_rel = substr($dir, strlen($out) + 1);
    } elseif (strpos($dir, $app . '/') === 0) {
        $public_rel = substr($dir, strlen($app) + 1);
    } else {
        return '../';
    }

    $depth = $public_rel === '' ? 0 : count(explode('/', $public_rel));

    return str_repeat('../', $depth);
}

function session_repo_root($helpers_root = null)
{
    if ($helpers_root === null) {
        $helpers_root = __DIR__;
    }
    return rtrim($helpers_root, '/');
}

function session_dir_for($session_nbr, $root = null)
{
    $root = session_ensure_output_root($root ?? session_web_root());

    return rtrim($root, '/') . '/session_' . (int) $session_nbr;
}

function session_manifest_path($session_nbr, $root = null)
{
    return session_dir_for($session_nbr, $root) . '/manifest.json';
}

function session_load_manifest($session_nbr, $root = null)
{
    $path = session_manifest_path($session_nbr, $root);
    if (!is_readable($path)) {
        return ['session' => (string) $session_nbr, 'phases' => [], 'jobs' => []];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : ['session' => (string) $session_nbr, 'phases' => [], 'jobs' => []];
}

function session_save_manifest($session_nbr, array $manifest, $root = null)
{
    $dir = session_dir_for($session_nbr, $root);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $manifest['session'] = (string) $session_nbr;
    $manifest['updated'] = date('c');
    file_put_contents(session_manifest_path($session_nbr, $root), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    session_write_session_index($session_nbr, $root);
}

function session_run_stats_has_data(array $run_stats)
{
    $operations = $run_stats['operations'] ?? [];
    if (is_array($operations)) {
        foreach ($operations as $count) {
            if ((int) $count > 0) {
                return true;
            }
        }
    }
    $move = $run_stats['move_summary'] ?? [];
    foreach (['picked_up', 'set_out'] as $kind) {
        if (!empty($move[$kind]) && is_array($move[$kind])) {
            foreach ($move[$kind] as $count) {
                if ((int) $count > 0) {
                    return true;
                }
            }
        }
    }
    if (!empty($run_stats['station_counts']) && is_array($run_stats['station_counts'])) {
        return true;
    }
    if ((int) ($run_stats['on_train_count'] ?? 0) > 0) {
        return true;
    }
    $generated = $run_stats['generated'] ?? [];
    if (is_array($generated)) {
        foreach (['waybills', 'switchlists', 'phases', 'trains'] as $key) {
            if ((int) ($generated[$key] ?? 0) > 0) {
                return true;
            }
        }
    }
    $dashboard = $run_stats['dashboard'] ?? [];
    if (is_array($dashboard)) {
        foreach ($dashboard as $count) {
            if ((int) $count > 0) {
                return true;
            }
        }
    }

    return false;
}

function session_manifest_merge_run_stats(array $manifest, array $run_stats, array $meta = [])
{
    return session_manifest_record_run_stats($manifest, $run_stats, $meta);
}

/**
 * Tally the output generated for a session from its manifest: waybills, switch
 * lists (one per train per phase), phases, and trains.
 *
 * @return array{waybills:int, switchlists:int, phases:int, trains:int}
 */
function session_count_generated_output(array $manifest)
{
    $phases = is_array($manifest['phases'] ?? null) ? $manifest['phases'] : [];
    $jobs_meta = is_array($manifest['jobs'] ?? null) ? $manifest['jobs'] : [];

    $switchlists = 0;
    foreach ($phases as $phase) {
        if (!is_array($phase)) {
            continue;
        }
        $phase_jobs = $phase['jobs'] ?? [];
        $switchlists += is_array($phase_jobs) ? count($phase_jobs) : 0;
    }

    $waybills = 0;
    if (isset($manifest['waybills']['count'])) {
        $waybills = (int) $manifest['waybills']['count'];
    } else {
        foreach ($phases as $phase) {
            if (is_array($phase) && isset($phase['waybills']['count'])) {
                $waybills += (int) $phase['waybills']['count'];
            }
        }
    }

    return [
        'waybills' => $waybills,
        'switchlists' => $switchlists,
        'phases' => count($phases),
        'trains' => count($jobs_meta),
    ];
}

function session_manifest_record_run_stats(array $manifest, array $run_stats, array $meta = [])
{
    $snapshot = array_merge([
        'at' => date('c'),
        'operations' => $run_stats['operations'] ?? [],
        'move_summary' => $run_stats['move_summary'] ?? ['picked_up' => [], 'set_out' => []],
        'station_counts' => $run_stats['station_counts'] ?? [],
        'on_train_count' => (int) ($run_stats['on_train_count'] ?? 0),
        'dashboard' => is_array($run_stats['dashboard'] ?? null) ? $run_stats['dashboard'] : [],
    ], $meta);

    foreach (['picked_up', 'set_out'] as $kind) {
        if (!isset($snapshot['move_summary'][$kind]) || !is_array($snapshot['move_summary'][$kind])) {
            $snapshot['move_summary'][$kind] = [];
        }
        ksort($snapshot['move_summary'][$kind], SORT_NATURAL | SORT_FLAG_CASE);
    }

    $history = $manifest['run_stats']['history'] ?? [];
    if (session_run_stats_has_data($run_stats)) {
        $history[] = $snapshot;
    }

    $manifest['run_stats'] = [
        'operations' => $snapshot['operations'],
        'move_summary' => $snapshot['move_summary'],
        'station_counts' => $snapshot['station_counts'] ?? [],
        'on_train_count' => (int) ($snapshot['on_train_count'] ?? 0),
        'dashboard' => $snapshot['dashboard'] ?? [],
        'updated' => date('c'),
        'last_run' => $snapshot,
        'history' => $history,
    ];

    return $manifest;
}

function session_aggregate_run_stats_by_session(array $log, $start_session)
{
    $start_session = (int) $start_session;
    $buckets = [];
    $current = $start_session > 0 ? $start_session : 0;

    $ensure_bucket = static function ($session_nbr) use (&$buckets) {
        $session_nbr = (int) $session_nbr;
        if ($session_nbr < 1) {
            return;
        }
        if (!isset($buckets[$session_nbr])) {
            $buckets[$session_nbr] = ['log' => []];
        }
    };

    if ($current > 0) {
        $ensure_bucket($current);
    }

    foreach ($log as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        if (!empty($entry['session'])) {
            $next_session = (int) $entry['session'];
            if ($next_session > 0) {
                $current = $next_session;
                $ensure_bucket($current);
            }
        }
        if ($current < 1) {
            continue;
        }
        $ensure_bucket($current);
        $buckets[$current]['log'][] = $entry;
    }

    $result = [];
    foreach ($buckets as $session_nbr => $bucket) {
        $stats = session_simulator_aggregate_run_stats([['log' => $bucket['log']]]);
        if (session_run_stats_has_data($stats)) {
            $result[(int) $session_nbr] = $stats;
        }
    }

    ksort($result);

    return $result;
}

function session_persist_recipe_run_stats($dbc, array $log, array $meta, $start_session, $root = null)
{
    $root = $root ?? session_web_root();
    require_once __DIR__ . '/operations_stats.php';
    $dashboard_snapshot = operations_get_stats($dbc);
    $stats_by_session = session_aggregate_run_stats_by_session($log, $start_session);
    if ($stats_by_session === []) {
        $final_session = function_exists('warm_start_get_session')
            ? (int) warm_start_get_session($dbc)
            : (int) session_get_db_session($dbc);
        if ($final_session > 0) {
            $stats = session_simulator_aggregate_run_stats([['log' => $log]]);
            if (session_run_stats_has_data($stats)) {
                $stats['station_counts'] = session_station_car_counts($dbc);
                $stats['on_train_count'] = session_on_train_car_count($dbc);
                $stats_by_session[$final_session] = $stats;
            }
        }
    }

    foreach ($stats_by_session as $session_nbr => $stats) {
        $stats['station_counts'] = session_station_car_counts($dbc);
        $stats['on_train_count'] = session_on_train_car_count($dbc);
        $stats['dashboard'] = $dashboard_snapshot;
        $manifest = session_load_manifest($session_nbr, $root);
        $manifest = session_manifest_record_run_stats($manifest, $stats, array_merge($meta, [
            'started_session' => (int) $start_session,
        ]));
        session_save_manifest($session_nbr, $manifest, $root);
    }

    return array_keys($stats_by_session);
}

function session_list_browser_sessions($current, $root = null)
{
    $current = max(1, (int) $current);
    $root = $root ?? session_web_root();
    $discovered = session_discover_sessions($root);
    // After a DB reset the current session number can be lower than sessions
    // already recorded on disk. Span up to the highest recorded session so the
    // count reflects every session, not just those <= the current DB session.
    $max_session = max(array_merge([$current], $discovered));
    $sessions = array_merge(range(1, $max_session), $discovered);
    $sessions = array_values(array_unique(array_map('intval', $sessions)));
    $sessions = array_values(array_filter($sessions, static function ($n) {
        return (int) $n >= 1;
    }));
    sort($sessions, SORT_NUMERIC);

    return $sessions;
}

/**
 * Cars physically placed at each station (current_location_id > 0), stations with count > 0 only.
 *
 * @return list<array{station_id: int, station_name: string, car_count: int}>
 */
function session_station_car_counts($dbc)
{
    $rs = mysqli_query(
        $dbc,
        'SELECT routing.id AS station_id,
                routing.station AS station_name,
                routing.sort_seq,
                COUNT(*) AS car_count
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         LEFT JOIN routing ON routing.id = loc.station
         WHERE cars.current_location_id > 0
         GROUP BY routing.id, routing.station, routing.sort_seq
         HAVING car_count > 0
         ORDER BY routing.sort_seq ASC, routing.station ASC'
    );
    if (!$rs) {
        return [];
    }

    $rows = [];
    while ($row = mysqli_fetch_array($rs)) {
        $name = trim((string) ($row['station_name'] ?? ''));
        if ($name === '') {
            $name = 'Unknown station';
        }
        $rows[] = [
            'station_id' => (int) ($row['station_id'] ?? 0),
            'station_name' => $name,
            'car_count' => (int) ($row['car_count'] ?? 0),
        ];
    }

    return $rows;
}

function session_on_train_car_count($dbc)
{
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         WHERE cars.current_location_id = 0
           AND cars.handled_by_job_id > 0'
    );
    if (!$rs) {
        return 0;
    }
    $row = mysqli_fetch_array($rs);

    return (int) ($row['c'] ?? 0);
}

function session_render_run_stats_block(array $run_stats, $empty_message = '')
{
    if (!session_run_stats_has_data($run_stats)) {
        if ($empty_message === '') {
            return '';
        }

        return '<div class="srs srs-empty">'
            . '<p class="muted">' . htmlspecialchars($empty_message) . '</p>'
            . '</div>';
    }

    $operations = $run_stats['operations'] ?? [];
    $move = $run_stats['move_summary'] ?? [];

    $html = '<div class="srs">';

    // --- Metric cards: generated output + session operations ---
    $generated = is_array($run_stats['generated'] ?? null) ? $run_stats['generated'] : [];
    $metric_defs = [
        ['switchlists', 'Switch lists', $generated],
        ['waybills', 'Waybills', $generated],
        ['phases', 'Phases', $generated],
        ['trains', 'Trains', $generated],
        ['generated', 'Orders generated', $operations],
        ['filled', 'Orders filled', $operations],
        ['repositioned', 'Empties repositioned', $operations],
        ['load_unload', 'Loads/unloads', $operations],
    ];
    $metric_cards = [];
    foreach ($metric_defs as [$key, $label, $source]) {
        if (!is_array($source) || !array_key_exists($key, $source)) {
            continue;
        }
        $value = (int) $source[$key];
        $metric_cards[] = '<div class="srs-card">'
            . '<span class="srs-card-num">' . $value . '</span>'
            . '<span class="srs-card-cap">' . htmlspecialchars($label) . '</span>'
            . '</div>';
    }
    if ($metric_cards !== []) {
        $html .= '<div class="srs-metrics">' . implode('', $metric_cards) . '</div>';
    }

    // --- Detail panels: moves, stations, dashboard ---
    $panels = [];

    $picked_up = $move['picked_up'] ?? [];
    $set_out = $move['set_out'] ?? [];
    $jobs = array_values(array_unique(array_merge(array_keys($picked_up), array_keys($set_out))));
    sort($jobs, SORT_NATURAL | SORT_FLAG_CASE);
    if ($jobs !== []) {
        $rows = '';
        foreach ($jobs as $job) {
            $picked = (int) ($picked_up[$job] ?? 0);
            $setout = (int) ($set_out[$job] ?? 0);
            if ($picked === 0 && $setout === 0) {
                continue;
            }
            $rows .= '<tr><td>' . htmlspecialchars($job) . '</td>'
                . '<td>' . ($picked > 0 ? $picked : '—') . '</td>'
                . '<td>' . ($setout > 0 ? $setout : '—') . '</td></tr>';
        }
        if ($rows !== '') {
            $panels[] = '<section class="srs-panel">'
                . '<h4 class="srs-panel-title">Cars moved by train</h4>'
                . '<table class="srs-table"><thead><tr>'
                . '<th>Train</th><th class="srs-num-col">Picked up</th><th class="srs-num-col">Set out</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table></section>';
        }
    }

    $station_counts = is_array($run_stats['station_counts'] ?? null) ? $run_stats['station_counts'] : [];
    $on_train_count = (int) ($run_stats['on_train_count'] ?? 0);
    if ($station_counts !== [] || $on_train_count > 0) {
        $rows = '';
        foreach ($station_counts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $count = (int) ($row['car_count'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $name = trim((string) ($row['station_name'] ?? '')) ?: 'Unknown station';
            $rows .= '<tr><td>' . htmlspecialchars($name) . '</td>'
                . '<td class="srs-num-col">' . $count . '</td></tr>';
        }
        if ($on_train_count > 0) {
            $rows .= '<tr><td>On trains (not at track)</td>'
                . '<td class="srs-num-col">' . $on_train_count . '</td></tr>';
        }
        if ($rows !== '') {
            $panels[] = '<section class="srs-panel">'
                . '<h4 class="srs-panel-title">Cars by station</h4>'
                . '<table class="srs-table"><thead><tr>'
                . '<th>Station</th><th class="srs-num-col">Cars</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table></section>';
        }
    }

    $dashboard = is_array($run_stats['dashboard'] ?? null) ? $run_stats['dashboard'] : [];
    if ($dashboard !== []) {
        require_once __DIR__ . '/operations_stats.php';
        $dash_rows = '';
        foreach (operations_dashboard_condition_variables() as $var) {
            $key = $var['key'] ?? '';
            if ($key === '' || $key === 'session_nbr' || $key === 'scale_to_weigh'
                || !array_key_exists($key, $dashboard)) {
                continue;
            }
            $dash_rows .= '<div class="srs-dash-row">'
                . '<dt>' . htmlspecialchars((string) ($var['label'] ?? $key)) . '</dt>'
                . '<dd>' . (int) $dashboard[$key] . '</dd></div>';
        }
        if ($dash_rows !== '') {
            $dash_live = !empty($run_stats['dashboard_live']);
            $badge = '<span class="srs-badge srs-badge-' . ($dash_live ? 'live' : 'end') . '">'
                . ($dash_live ? 'live' : 'end of session') . '</span>';
            $panels[] = '<section class="srs-panel srs-panel-wide">'
                . '<h4 class="srs-panel-title">Operations dashboard ' . $badge . '</h4>'
                . '<dl class="srs-dash">' . $dash_rows . '</dl></section>';
        }
    }

    if ($panels !== []) {
        $html .= '<div class="srs-panels">' . implode('', $panels) . '</div>';
    }

    if (!empty($run_stats['updated'])) {
        $when = strtotime((string) $run_stats['updated']);
        $when_label = $when ? date('M j, Y g:i A', $when) : (string) $run_stats['updated'];
        $html .= '<p class="srs-updated">Updated ' . htmlspecialchars($when_label);
        $history = $run_stats['history'] ?? [];
        if (count($history) > 1) {
            $html .= ' · ' . count($history) . ' workflow runs recorded';
        }
        $html .= '</p>';
    }

    $html .= '</div>';

    return $html;
}

function session_simulator_aggregate_train_moves(array $cycles)
{
    $picked_up = [];
    $set_out = [];

    $add_counts = static function (array &$totals, $job, $count) {
        $job = trim((string) $job);
        if ($job === '' || $count <= 0) {
            return;
        }
        $totals[$job] = ($totals[$job] ?? 0) + $count;
    };

    foreach ($cycles as $cycle) {
        foreach ($cycle['log'] ?? [] as $entry) {
            if (!is_array($entry) || !empty($entry['skipped'])) {
                continue;
            }

            if (isset($entry['stats']) && is_array($entry['stats'])) {
                $job = trim((string) ($entry['job'] ?? ''));
                if ($job !== '') {
                    $add_counts($picked_up, $job, (int) ($entry['stats']['picked_up'] ?? 0));
                    $add_counts($set_out, $job, (int) ($entry['stats']['set_out'] ?? 0));
                }
            }

            if (($entry['dispatch'] ?? '') === 'pick_up_cars' && array_key_exists('picked_up', $entry)) {
                if (!empty($entry['picked_up_by_job']) && is_array($entry['picked_up_by_job'])) {
                    foreach ($entry['picked_up_by_job'] as $job => $count) {
                        $add_counts($picked_up, $job, (int) $count);
                    }
                } else {
                    $add_counts($picked_up, $entry['job'] ?? '', (int) $entry['picked_up']);
                }
            }

            if (($entry['dispatch'] ?? '') === 'set_out_cars' && array_key_exists('set_out', $entry)) {
                if (!empty($entry['set_out_by_job']) && is_array($entry['set_out_by_job'])) {
                    foreach ($entry['set_out_by_job'] as $job => $count) {
                        $add_counts($set_out, $job, (int) $count);
                    }
                } else {
                    $add_counts($set_out, $entry['job'] ?? '', (int) $entry['set_out']);
                }
            }
        }
    }

    ksort($picked_up);
    ksort($set_out);

    return [
        'picked_up' => $picked_up,
        'set_out' => $set_out,
    ];
}

function session_simulator_aggregate_run_stats(array $cycles)
{
    $operations = [
        'generated' => 0,
        'filled' => 0,
        'repositioned' => 0,
        'load_unload' => 0,
    ];

    foreach ($cycles as $cycle) {
        foreach ($cycle['log'] ?? [] as $entry) {
            if (!is_array($entry) || !empty($entry['skipped'])) {
                continue;
            }
            if (array_key_exists('generated', $entry)) {
                $operations['generated'] += (int) $entry['generated'];
            }
            if (array_key_exists('filled', $entry)) {
                $operations['filled'] += (int) $entry['filled'];
            }
            if (array_key_exists('repositioned', $entry)) {
                $operations['repositioned'] += (int) $entry['repositioned'];
            }
            if (array_key_exists('load_unload', $entry)) {
                $operations['load_unload'] += (int) $entry['load_unload'];
            }
            if (isset($entry['stats']) && is_array($entry['stats']) && !empty($entry['stats']['load_unload'])) {
                $operations['load_unload'] += (int) $entry['stats']['load_unload'];
            }
        }
    }

    return [
        'operations' => $operations,
        'move_summary' => session_simulator_aggregate_train_moves($cycles),
    ];
}

function session_nav_stylesheet_path()
{
    return '/sts/session-nav.css';
}

function session_bootstrap_head_links()
{
    return '<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">'
        . '<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">';
}

function session_nav_stylesheet_link($href = null)
{
    $href = $href ?? session_nav_stylesheet_path();

    // Cache-bust local stylesheets by file mtime so browsers pick up CSS
    // changes immediately instead of serving a stale cached copy.
    if (is_string($href) && strpos($href, '//') === false && strpos($href, '?') === false) {
        $basename = basename(parse_url($href, PHP_URL_PATH) ?: $href);
        $fs_path = __DIR__ . '/' . $basename;
        if (is_file($fs_path)) {
            $href .= '?v=' . filemtime($fs_path);
        }
    }

    return '<link rel="stylesheet" href="' . htmlspecialchars((string) $href) . '">';
}

function session_static_head_assets($css_href = null)
{
    return session_bootstrap_head_links() . session_nav_stylesheet_link($css_href);
}

function session_nav_icon_for_label($label)
{
    $key = strtolower(trim((string) $label));
    static $map = [
        'sts main menu' => 'house',
        'session editor' => 'pencil-square',
        'all sessions' => 'collection',
        'sessions' => 'collection',
        'prev' => 'chevron-left',
        'next' => 'chevron-right',
        'mobile' => 'phone',
        'full sheet' => 'file-earmark',
        'dot matrix' => 'printer',
        'work order' => 'clipboard-check',
        'x2010' => 'file-earmark-spreadsheet',
        'waybills' => 'file-text',
        'waybill list' => 'file-text',
        'csv' => 'filetype-csv',
        'json' => 'filetype-json',
    ];
    if (preg_match('/^session \d+$/', $key)) {
        return 'calendar-event';
    }
    if (str_contains($key, ' index') || str_contains($key, 'print all')) {
        return 'list-ul';
    }

    return $map[$key] ?? '';
}

/** @param list<array{href: string, label: string, icon?: string, active?: bool}> $links */
function session_nav_bar_html(array $links, $trail = '', $extra_class = 'noprint')
{
    $class = 'navbar navbar-dark';
    if ($extra_class !== '') {
        $class .= ' ' . $extra_class;
    }
    $html = '<nav class="' . $class . '" style="background-color: #343a40;">';
    $html .= '<div class="container-fluid"><div class="d-flex flex-wrap align-items-center gap-2 w-100">';
    foreach ($links as $link) {
        $href = trim((string) ($link['href'] ?? ''));
        $label = trim((string) ($link['label'] ?? ''));
        if ($href === '' || $label === '') {
            continue;
        }
        $icon = trim((string) ($link['icon'] ?? session_nav_icon_for_label($label)));
        $btn_class = 'btn btn-outline-light btn-sm';
        if (!empty($link['active'])) {
            $btn_class .= ' active';
        }
        $html .= '<a href="' . htmlspecialchars($href) . '" class="' . $btn_class . '">';
        if ($icon !== '') {
            $html .= '<i class="bi bi-' . htmlspecialchars($icon) . '"></i> ';
        }
        $html .= htmlspecialchars($label) . '</a>';
    }
    if ($trail !== '') {
        $html .= '<span class="navbar-text ms-auto text-white-50 small">' . htmlspecialchars($trail) . '</span>';
    }
    $html .= '</div></div></nav>';

    return $html;
}

/** @param list<array{href: string, label: string, icon?: string, active?: bool}> $links */
function session_render_nav_bar(array $links, $trail = '')
{
    echo session_nav_bar_html($links, $trail);
}

function session_write_session_index($session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $dir = session_dir_for($session_nbr, $root);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $template = __DIR__ . '/session_index_template.php';
    if (is_readable($template)) {
        copy($template, $dir . '/index.php');
    }
    $manifest = session_load_manifest($session_nbr, $root);
    session_ensure_output_stubs($session_nbr, $manifest, $root);
}

/** Relative href from a waybill index to another session's waybill index (same phase when set). */
function session_waybill_index_rel_href($session_nbr, $phase_num = null)
{
    if ($phase_num !== null) {
        return '../../../session_' . (int) $session_nbr
            . '/phase_' . session_phase_pad($phase_num)
            . '/waybills/index.html';
    }

    return '../../session_' . (int) $session_nbr . '/waybills/index.html';
}

/** Prev/next session buttons for a waybill index page. */
function session_waybill_session_nav_html($session_nbr, $phase_num = null, $dbc = null, $root = null)
{
    $root = $root ?? session_web_root();
    $session_nbr = (int) $session_nbr;
    if ($session_nbr < 1) {
        return '';
    }
    if ($dbc === null) {
        require_once __DIR__ . '/open_db.php';
        // open_db() returns a shared static connection; never close it here or we
        // would break callers that are still using the same handle.
        $dbc = open_db();
    }
    $current_db = session_get_db_session($dbc);
    $sessions = session_list_browser_sessions($current_db, $root);
    $prev = session_adjacent_session($sessions, $session_nbr, 'prev');
    $next = session_adjacent_session($sessions, $session_nbr, 'next');
    if ($prev === null && $next === null) {
        return '';
    }

    $html = '<div class="session-nav-row session-nav-row-stats">';
    if ($prev !== null) {
        $html .= '<a class="btn btn-outline-dark btn-sm" href="'
            . htmlspecialchars(session_waybill_index_rel_href($prev, $phase_num))
            . '"><i class="bi bi-chevron-left"></i> Session ' . (int) $prev . '</a>';
    } else {
        $html .= '<span class="btn btn-outline-dark btn-sm disabled" aria-disabled="true"><i class="bi bi-chevron-left"></i> Previous</span>';
    }
    if ($next !== null) {
        $html .= '<a class="btn btn-outline-dark btn-sm" href="'
            . htmlspecialchars(session_waybill_index_rel_href($next, $phase_num))
            . '">Session ' . (int) $next . ' <i class="bi bi-chevron-right"></i></a>';
    } else {
        $html .= '<span class="btn btn-outline-dark btn-sm disabled" aria-disabled="true">Next <i class="bi bi-chevron-right"></i></span>';
    }
    $html .= '</div>';

    return $html;
}

function session_write_empty_waybill_index($out_dir, array $options = [])
{
    if (is_file(rtrim($out_dir, '/') . '/index.html')) {
        return ['path' => rtrim($out_dir, '/') . '/index.html', 'count' => 0, 'skipped' => true];
    }
    if (!is_dir($out_dir)) {
        mkdir($out_dir, 0755, true);
    }
    $title = $options['title'] ?? 'Waybills';
    $back = $options['back_href'] ?? '../session.php';
    $back_label = $options['back_label'] ?? 'All Sessions';
    $message = $options['message'] ?? 'No waybills available yet. Run Generate Waybill List in the workflow after switch lists.';
    $index_html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . session_static_head_assets()
        . '</head><body>';
    $index_html .= session_nav_bar_html([
        ['href' => $back, 'label' => $back_label],
    ], $title);
    $session_nbr = isset($options['session_nbr']) ? (int) $options['session_nbr'] : 0;
    $phase_num = array_key_exists('phase_num', $options) ? $options['phase_num'] : null;
    if ($session_nbr >= 1) {
        $index_html .= session_waybill_session_nav_html(
            $session_nbr,
            $phase_num,
            $options['dbc'] ?? null,
            $options['root'] ?? null
        );
    }
    $index_html .= '<main><h1>' . htmlspecialchars($title) . '</h1>'
        . '<div class="card"><p>' . htmlspecialchars($message) . '</p>'
        . '<ul><li>No waybills available.</li></ul></div></main></body></html>';
    file_put_contents($out_dir . '/index.html', $index_html);
    $print_all = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . ' — print all</title>'
        . session_static_head_assets()
        . '</head><body>';
    $print_all .= session_nav_bar_html([
        ['href' => 'index.html', 'label' => 'Waybill list', 'icon' => 'file-text'],
    ], 'Print all');
    $print_all .= '<main><h1>' . htmlspecialchars($title) . '</h1>'
        . '<div class="card"><p>No waybills to print.</p></div></main></body></html>';
    file_put_contents($out_dir . '/print_all.html', $print_all);
    return [
        'path' => $out_dir . '/index.html',
        'print_all' => $out_dir . '/print_all.html',
        'count' => 0,
    ];
}

function session_ensure_output_stubs($session_nbr, array $manifest, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $session_dir = session_dir_for($session_nbr, $root);
    if (!is_dir($session_dir)) {
        mkdir($session_dir, 0755, true);
    }

    // The canonical session page is index.php. A stale/legacy index.html (e.g. the
    // old "HART Switchlists" root stub) would otherwise be reached from links that
    // point at session_N/index.html. Replace it with a redirect to index.php.
    $session_index_html = $session_dir . '/index.html';
    $existing_index = is_file($session_index_html) ? (string) @file_get_contents($session_index_html) : '';
    if ($existing_index === '' || strpos($existing_index, 'url=index.php') === false) {
        @file_put_contents(
            $session_index_html,
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta http-equiv="refresh" content="0; url=index.php">'
            . '<link rel="canonical" href="index.php">'
            . '<title>Session ' . (int) $session_nbr . '</title></head>'
            . '<body><p><a href="index.php">Go to session ' . (int) $session_nbr . '</a></p></body></html>'
        );
    }

    session_write_empty_waybill_index(session_waybill_dir_for($session_nbr, null, $root), [
        'title' => 'Waybills — session ' . (int) $session_nbr,
        'back_href' => '../index.php',
        'back_label' => 'Session ' . (int) $session_nbr,
        'session_nbr' => $session_nbr,
        'phase_num' => null,
        'root' => $root,
    ]);

    foreach ($manifest['phases'] ?? [] as $phase) {
        $phase_num = (int) ($phase['phase'] ?? 0);
        if ($phase_num < 1) {
            continue;
        }
        $phase_dir = session_phase_output_dir($session_nbr, $phase_num, $root);
        if (!is_dir($phase_dir)) {
            mkdir($phase_dir, 0755, true);
        }
        session_write_empty_waybill_index(session_waybill_dir_for($session_nbr, $phase_num, $root), [
            'title' => 'Waybills — session ' . (int) $session_nbr . ', phase ' . $phase_num,
            'back_href' => '../../index.php',
            'back_label' => 'Session ' . (int) $session_nbr,
            'session_nbr' => $session_nbr,
            'phase_num' => $phase_num,
            'root' => $root,
        ]);
        foreach ($phase['jobs'] ?? [] as $job) {
            $job = trim((string) $job);
            if ($job === '') {
                continue;
            }
            $job_dir = master_sw_job_output_dir($phase_dir, $job);
            if (!is_file(master_sw_job_index_path($job_dir))) {
                master_sw_render_empty_job_index(
                    null,
                    $job,
                    $job_dir,
                    (string) $session_nbr,
                    'No switch list files found for this train and phase. Run Generate Switch Lists in the workflow.'
                );
            }
        }
    }

    foreach (array_keys($manifest['jobs'] ?? []) as $job) {
        $job = trim((string) $job);
        if ($job === '') {
            continue;
        }
        foreach ($manifest['jobs'][$job]['phases'] ?? [] as $phase_num) {
            $phase_dir = session_phase_output_dir($session_nbr, (int) $phase_num, $root);
            $job_dir = master_sw_job_output_dir($phase_dir, $job);
            if (!is_file(master_sw_job_index_path($job_dir))) {
                master_sw_render_empty_job_index(
                    null,
                    $job,
                    $job_dir,
                    (string) $session_nbr,
                    'No switch list files found for this train and phase. Run Generate Switch Lists in the workflow.'
                );
            }
        }
    }
}

function session_condition_variables()
{
    require_once __DIR__ . '/operations_stats.php';

    return operations_dashboard_condition_variables();
}

function session_condition_variable_label($key)
{
    require_once __DIR__ . '/operations_stats.php';

    return operations_dashboard_condition_label($key);
}

function session_condition_operators()
{
    return ['=', '!=', '<', '<=', '>', '>='];
}

function session_evaluate_context($dbc, array $config = [])
{
    require_once __DIR__ . '/operations_stats.php';

    $session = function_exists('warm_start_get_session')
        ? warm_start_get_session($dbc)
        : session_get_db_session($dbc);

    return array_merge(operations_get_stats($dbc), [
        'session_nbr' => (int) $session,
        '_dbc' => $dbc,
        '_config' => $config,
    ]);
}

function session_evaluate_condition(array $ctx, $variable, $operator, $value, array $extra = [])
{
    $aliases = [
        'unfilled_count' => 'unfilled_orders',
        'awaiting_assignment' => 'unassigned',
    ];
    if (isset($aliases[$variable])) {
        $variable = $aliases[$variable];
    }

    $allowed = array_column(session_condition_variables(), 'key');
    if (!in_array($variable, $allowed, true)) {
        return false;
    }

    $left = (float) ($ctx[$variable] ?? 0);
    $right = (float) $value;
    switch ($operator) {
        case '=': return $left == $right;
        case '!=': return $left != $right;
        case '<': return $left < $right;
        case '<=': return $left <= $right;
        case '>': return $left > $right;
        case '>=': return $left >= $right;
        default: return true;
    }
}

function session_manual_generate_shipment($dbc, $shipment_code)
{
    $session = function_exists('warm_start_get_session')
        ? warm_start_get_session($dbc)
        : session_get_db_session($dbc);
    $code_esc = mysqli_real_escape_string($dbc, $shipment_code);
    $rs = mysqli_query($dbc, 'SELECT id, min_amount, max_amount FROM shipments WHERE code = "' . $code_esc . '" LIMIT 1');
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return ['generated' => 0, 'error' => 'Shipment not found: ' . $shipment_code];
    }
    $ship = mysqli_fetch_array($rs);
    $shipment_id = (int) $ship['id'];
    mysqli_query($dbc, 'UPDATE shipments SET last_ship_date = ' . (int) $session . ' WHERE id = "' . $shipment_id . '"');

    $rs = mysqli_query($dbc, 'SELECT max(substr(waybill_number, 6, 2)) FROM car_orders WHERE waybill_number LIKE "' . str_pad($session, 3, '0', STR_PAD_LEFT) . '-M__"');
    $row = mysqli_fetch_row($rs);
    $order_counter = (int) ($row[0] ?? 0);

    $min_amount = (float) $ship['min_amount'];
    $max_amount = (float) $ship['max_amount'];
    $num_cars = max(1, (int) round(mt_rand((int) ($min_amount * 100), (int) ($max_amount * 100)) / 100));

    $generated = 0;
    for ($j = 0; $j < $num_cars; $j++) {
        $order_counter++;
        $wb = str_pad($session, 3, '0', STR_PAD_LEFT) . '-M' . str_pad($order_counter, 2, '0', STR_PAD_LEFT);
        if (!mysqli_query($dbc, 'INSERT INTO car_orders (waybill_number, shipment, car) VALUES ("' . mysqli_real_escape_string($dbc, $wb) . '", "' . $shipment_id . '", "0")')) {
            break;
        }
        $generated++;
    }
    return ['generated' => $generated, 'shipment' => $shipment_code, 'session' => $session];
}

function session_resolve_jobs_param($jobs_param, $dbc = null)
{
    $jobs_param = trim((string) $jobs_param);
    if ($jobs_param === '' || strtolower($jobs_param) === 'all') {
        if ($dbc === null) {
            return [];
        }
        return session_list_switchlist_job_names($dbc);
    }
    return array_values(array_filter(array_map('trim', explode(',', $jobs_param))));
}

function session_list_switchlist_job_names($dbc)
{
    $staging = session_staging_job_names($dbc, session_merge_runtime_config([]));
    $staging_set = array_flip($staging);
    $jobs = [];
    $rs = mysqli_query($dbc, 'SELECT name FROM jobs ORDER BY name');
    while ($rs && ($row = mysqli_fetch_array($rs))) {
        $name = (string) ($row['name'] ?? '');
        if ($name === '' || isset($staging_set[$name])) {
            continue;
        }
        $jobs[] = $name;
    }
    return $jobs;
}

function session_phase_output_dir($session_nbr, $phase_num, $root = null)
{
    return session_dir_for($session_nbr, $root) . '/phase_' . str_pad((int) $phase_num, 2, '0', STR_PAD_LEFT);
}

function session_register_phase(array &$manifest, $phase_num, array $meta)
{
    $manifest['phases'] = $manifest['phases'] ?? [];
    $manifest['phases'][] = array_merge(['phase' => (int) $phase_num], $meta);
    foreach ($meta['jobs'] ?? [] as $job) {
        if (!isset($manifest['jobs'][$job])) {
            $manifest['jobs'][$job] = ['phases' => []];
        }
        if (!in_array((int) $phase_num, $manifest['jobs'][$job]['phases'], true)) {
            $manifest['jobs'][$job]['phases'][] = (int) $phase_num;
        }
    }
}

function session_waybill_dir_for($session_nbr, $phase_num = null, $root = null)
{
    $root = $root ?? session_web_root();
    if ($phase_num === null) {
        return session_dir_for($session_nbr, $root) . '/waybills';
    }
    return session_phase_output_dir($session_nbr, $phase_num, $root) . '/waybills';
}

/** Recursively remove a file or directory. */
function session_rrmdir($path)
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        session_rrmdir($path . '/' . $entry);
    }
    @rmdir($path);
}

/**
 * Purge previously generated output for a session so a fresh generation does not
 * leave stale phase directories, waybills, caches, or index files behind. The
 * session directory itself and its index.php page are preserved.
 *
 * @return list<string> Names of the entries that were removed.
 */
function session_reset_output($session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $dir = session_dir_for($session_nbr, $root);
    if (!is_dir($dir)) {
        return [];
    }

    $removed = [];
    foreach (glob($dir . '/phase_*', GLOB_ONLYDIR) ?: [] as $phase_dir) {
        session_rrmdir($phase_dir);
        $removed[] = basename($phase_dir);
    }
    if (is_dir($dir . '/waybills')) {
        session_rrmdir($dir . '/waybills');
        $removed[] = 'waybills';
    }
    foreach (glob($dir . '/*_master.json') ?: [] as $cache) {
        @unlink($cache);
        $removed[] = basename($cache);
    }
    foreach (['index.html', 'print_all.html'] as $legacy) {
        if (is_file($dir . '/' . $legacy)) {
            @unlink($dir . '/' . $legacy);
            $removed[] = $legacy;
        }
    }

    return $removed;
}

function session_write_waybill_bundle($dbc, $out_dir, array $waybill_numbers, array $options = [])
{
    if (!is_dir($out_dir)) {
        mkdir($out_dir, 0755, true);
    }
    $settings = waybill_print_settings($dbc);
    $written = [];
    $list_items = '';
    $bundle_sheets = '';

    foreach ($waybill_numbers as $waybill_number) {
        $safe = waybill_print_safe_filename($waybill_number);
        $file = $safe . '.html';
        $page = waybill_print_render_page($dbc, $waybill_number, [
            'settings' => $settings,
            'show_controls' => true,
            'nav_html' => '<a href="index.html">Waybill list</a>',
        ]);
        if ($page === '') {
            continue;
        }
        file_put_contents($out_dir . '/' . $file, $page);
        $written[] = $waybill_number;
        $body = waybill_print_render_body($dbc, $waybill_number, $settings);
        $bundle_sheets .= '<div class="waybill-sheet">' . $body . '</div>';
        $list_items .= '<li><a href="' . htmlspecialchars($file) . '">' . htmlspecialchars($waybill_number) . '</a></li>';
    }

    $title = $options['title'] ?? 'Waybills';
    $back = $options['back_href'] ?? '../session.php';
    $back_label = $options['back_label'] ?? 'All Sessions';
    $index_html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . session_static_head_assets()
        . '</head><body>';
    $index_html .= session_nav_bar_html([
        ['href' => $back, 'label' => $back_label],
    ], $title);
    $session_nbr = isset($options['session_nbr']) ? (int) $options['session_nbr'] : 0;
    $phase_num = array_key_exists('phase_num', $options) ? $options['phase_num'] : null;
    if ($session_nbr >= 1) {
        $index_html .= session_waybill_session_nav_html($session_nbr, $phase_num, $dbc, $options['root'] ?? null);
    }
    $index_html .= '<main><h1>' . htmlspecialchars($title) . '</h1>'
        . '<div class="card"><p>' . count($written) . ' printable waybill(s) generated from the current database.</p>'
        . ($written ? '<p><a href="print_all.html"><strong>Print all waybills</strong></a></p>' : '')
        . '<ul>' . ($list_items !== '' ? $list_items : '<li>No waybills available.</li>') . '</ul></div>'
        . '</main></body></html>';
    file_put_contents($out_dir . '/index.html', $index_html);

    $print_all = waybill_print_render_bundle_page(
        $title . ' — print all',
        $bundle_sheets,
        ['back_href' => 'index.html']
    );
    file_put_contents($out_dir . '/print_all.html', $print_all);

    return [
        'path' => $out_dir . '/index.html',
        'print_all' => $out_dir . '/print_all.html',
        'count' => count($written),
        'waybills' => $written,
    ];
}

function session_refresh_session_waybills($dbc, $session_nbr, $root = null)
{
    require_once __DIR__ . '/waybill_print_helpers.php';
    $root = $root ?? session_web_root();
    $numbers = waybill_print_session_numbers($dbc, $session_nbr);
    return session_write_waybill_bundle($dbc, session_waybill_dir_for($session_nbr, null, $root), $numbers, [
        'title' => 'Waybills — session ' . (int) $session_nbr,
        'back_href' => '../index.php',
        'back_label' => 'Session ' . (int) $session_nbr,
        'session_nbr' => $session_nbr,
        'phase_num' => null,
        'root' => $root,
    ]);
}

function session_generate_waybills_for_phase($dbc, $session_nbr, $phase_num, $root = null)
{
    require_once __DIR__ . '/waybill_print_helpers.php';
    $root = $root ?? session_web_root();
    $out_dir = session_waybill_dir_for($session_nbr, $phase_num, $root);
    $numbers = waybill_print_session_numbers($dbc, $session_nbr);
    $phase_back = '../../index.php';
    $result = session_write_waybill_bundle($dbc, $out_dir, $numbers, [
        'title' => 'Waybills — session ' . (int) $session_nbr . ', phase ' . (int) $phase_num,
        'back_href' => $phase_back,
        'back_label' => 'Session ' . (int) $session_nbr,
        'session_nbr' => $session_nbr,
        'phase_num' => (int) $phase_num,
        'root' => $root,
    ]);
    $session_bundle = session_refresh_session_waybills($dbc, $session_nbr, $root);
    $result['session_print_all'] = $session_bundle['print_all'] ?? null;
    $result['session_count'] = $session_bundle['count'] ?? 0;
    return $result;
}

function session_run_recipe($dbc, array $recipe, array $options = [])
{
    require_once __DIR__ . '/operational_steps_catalog.php';
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $recipe = operational_steps_normalize_recipe($recipe);
    $config = session_merge_runtime_config($options['config'] ?? []);
    $format = $options['format'] ?? 'all';
    $root = $options['session_root'] ?? session_web_root();
    $from_step = max(1, (int) ($options['from_step'] ?? 1));
    $to_step = min(count($recipe['steps'] ?? []), (int) ($options['to_step'] ?? count($recipe['steps'] ?? [])));
    $session_nbr = function_exists('warm_start_get_session')
        ? warm_start_get_session($dbc)
        : session_get_db_session($dbc);
    $manifest = session_load_manifest($session_nbr, $root);
    // A run that begins at the first step (re)builds the session from scratch, so
    // purge any previously generated files/manifest entries first. This prevents
    // stale phase directories, waybills, and caches from accumulating under
    // session_N when the same session is generated more than once. Callers can
    // force or suppress this with the 'reset_output' option.
    $reset_output = array_key_exists('reset_output', $options)
        ? (bool) $options['reset_output']
        : ($from_step <= 1);
    if ($reset_output) {
        session_reset_output($session_nbr, $root);
        $manifest['phases'] = [];
        $manifest['jobs'] = [];
        unset($manifest['waybills']);
    }
    $phase_num = count($manifest['phases'] ?? []);
    $ctx = session_evaluate_context($dbc, $config);
    $log = [];
    $stopped = false;
    $loop_error = null;
    $step_span = max(1, $to_step - $from_step + 1);
    $max_iterations = max(500, $step_span * 100);
    $iterations = 0;
    $pc = $from_step - 1;

    while ($pc >= 0 && $pc < $to_step) {
        $iterations++;
        if ($iterations > $max_iterations) {
            $stopped = true;
            $loop_error = 'Possible infinite goto loop (exceeded ' . $max_iterations . ' step executions).';
            $log[] = ['step' => $pc + 1, 'action' => 'loop_guard', 'error' => $loop_error];
            break;
        }

        $step = $recipe['steps'][$pc] ?? null;
        if (!is_array($step)) {
            $pc++;
            continue;
        }
        $n = $pc + 1;
        $fid = $step['function'] ?? '';

        if ($fid === 'stop') {
            $stopped = true;
            $log[] = ['step' => $n, 'action' => 'stop'];
            break;
        }
        if ($fid === 'goto') {
            $target = operational_steps_goto_resolve_step($recipe, $step['params'] ?? []);
            $total = count($recipe['steps']);
            if (operational_steps_goto_target_allowed($n, $target, $total)) {
                $pc = $target - 1;
                $log[] = ['step' => $n, 'action' => 'goto', 'target' => $target];
            } else {
                $reason = ($target > 0 && $target <= $n)
                    ? 'backward goto not allowed (use Repeat to loop a section)'
                    : 'invalid target';
                $log[] = ['step' => $n, 'action' => 'goto', 'target' => $target, 'error' => $reason];
                $pc++;
            }
            continue;
        }
        if ($fid === 'if_then') {
            $p = $step['params'] ?? [];
            $ok = session_evaluate_condition(
                $ctx,
                $p['variable'] ?? 'session_nbr',
                $p['operator'] ?? '=',
                $p['value'] ?? '0',
                $p
            );
            $log[] = ['step' => $n, 'action' => 'if_then', 'result' => $ok];
            if ($ok && operational_steps_if_then_has_goto($step)) {
                $target = operational_steps_goto_resolve_step($recipe, $p);
                $total = count($recipe['steps']);
                if (operational_steps_goto_target_allowed($n, $target, $total)) {
                    $pc = $target - 1;
                    $log[] = ['step' => $n, 'action' => 'goto', 'target' => $target, 'from' => 'if_then'];
                    continue;
                }
                $reason = ($target > 0 && $target <= $n)
                    ? 'backward goto not allowed (use Repeat to loop a section)'
                    : 'invalid target';
                $log[] = ['step' => $n, 'action' => 'goto', 'target' => $target, 'error' => $reason, 'from' => 'if_then'];
            }
            $pc++;
            continue;
        }
        if ($fid === 'section_label') {
            $log[] = ['step' => $n, 'action' => 'section_label', 'label' => $step['params']['label'] ?? ''];
            $pc++;
            continue;
        }

        if ($fid === 'generate_switchlists') {
            $phase_num++;
            $jobs = session_resolve_jobs_param($step['params']['jobs'] ?? 'all', $dbc);
            $phase_dir = session_phase_output_dir($session_nbr, $phase_num, $root);
            $fmt = master_sw_normalize_switchlist_format($step['params']['format'] ?? $format);
            $written = master_sw_generate_for_jobs($dbc, $jobs, $phase_dir, $config, [
                'format' => $fmt,
                'recipe' => $recipe,
                'through_step' => $n - 1,
            ]);
            session_register_phase($manifest, $phase_num, [
                'step' => $n,
                'jobs' => $jobs,
                'format' => $fmt,
                'styles' => master_sw_styles_for_format($fmt),
                'label' => operational_steps_compile_recipe(['steps' => [$step]])[0]['instruction'] ?? 'Generate Switch Lists',
                'output' => $phase_dir,
            ]);
            $log[] = ['step' => $n, 'phase' => $phase_num, 'written' => $written];
            $pc++;
            continue;
        }
        if ($fid === 'generate_waybills') {
            if ($phase_num < 1) {
                $phase_num = 1;
            }
            $wb = session_generate_waybills_for_phase($dbc, $session_nbr, $phase_num, $root);
            foreach ($manifest['phases'] as &$phase_entry) {
                if ((int) ($phase_entry['phase'] ?? 0) === (int) $phase_num) {
                    $phase_entry['waybills'] = [
                        'count' => $wb['count'] ?? 0,
                        'index' => 'waybills/index.html',
                        'print_all' => 'waybills/print_all.html',
                    ];
                    break;
                }
            }
            unset($phase_entry);
            $manifest['waybills'] = [
                'count' => $wb['session_count'] ?? ($wb['count'] ?? 0),
                'index' => 'waybills/index.html',
                'print_all' => 'waybills/print_all.html',
                'updated' => date('c'),
            ];
            $log[] = ['step' => $n, 'phase' => $phase_num, 'waybills' => $wb];
            $pc++;
            continue;
        }

        $dispatch_opts = array_merge($config, [
            'session_root' => $root,
            'phase' => $phase_num,
            'recipe' => $recipe,
            'through_step' => $n - 1,
        ]);
        $result = operational_steps_dispatch_step($dbc, $step, $dispatch_opts);
        $log[] = array_merge(['step' => $n], $result);
        $ctx = session_evaluate_context($dbc, $config);
        $pc++;
    }

    session_persist_recipe_run_stats($dbc, $log, [
        'start_step' => $from_step,
        'stop_step' => $to_step,
    ], $session_nbr, $root);

    session_save_manifest($session_nbr, $manifest, $root);
    $final_session = function_exists('warm_start_get_session')
        ? (int) warm_start_get_session($dbc)
        : (int) session_get_db_session($dbc);
    return [
        'session' => (string) ($final_session > 0 ? $final_session : $session_nbr),
        'phases' => $phase_num,
        'stopped' => $stopped,
        'error' => $loop_error,
        'log' => $log,
        'manifest' => $manifest,
    ];
}

function session_discover_sessions($root = null)
{
    $root = $root ?? session_web_root();
    $sessions = [];
    if (!is_dir($root)) {
        return $sessions;
    }
    foreach (scandir($root) ?: [] as $entry) {
        if (preg_match('/^session_(\d+)$/', $entry, $m)) {
            $n = (int) $m[1];
            if ($n >= 1) {
                $sessions[] = $n;
            }
        }
    }
    sort($sessions);
    return $sessions;
}

function session_adjacent_session(array $sessions, $current, $direction)
{
    $current = (int) $current;
    $sessions = array_values(array_map('intval', $sessions));
    $idx = array_search($current, $sessions, true);
    if ($idx === false) {
        return null;
    }
    if ($direction === 'prev' && $idx > 0) {
        return $sessions[$idx - 1];
    }
    if ($direction === 'next' && $idx < count($sessions) - 1) {
        return $sessions[$idx + 1];
    }

    return null;
}

function session_phase_pad($phase_num)
{
    return str_pad((int) $phase_num, 2, '0', STR_PAD_LEFT);
}

function session_waybills_bundle_ready($session_nbr, $phase_num = null, $root = null)
{
    $dir = session_waybill_dir_for($session_nbr, $phase_num, $root);
    $print = $dir . '/print_all.html';

    return is_file($print) && filesize($print) > 800;
}

function session_switchlist_styles()
{
    return [
        'mobile' => 'Mobile',
        'half' => 'Half Sheet',
        'full' => 'Full Sheet',
        'dmp' => 'Dot Matrix',
        'wo' => 'Work Order',
        'x2010' => 'X2010',
    ];
}

function session_normalize_switchlist_style($style, $default = 'mobile')
{
    if (!function_exists('operational_steps_normalize_switchlist_format')) {
        require_once __DIR__ . '/operational_steps_catalog.php';
    }
    $style = operational_steps_normalize_switchlist_format($style, $default);
    if ($style === 'all') {
        return $default;
    }

    return $style;
}

function session_switchlist_work_phase_rel($session_nbr, $phase_num, $job, $work_phase, $style = 'mobile')
{
    $style = session_normalize_switchlist_style($style);
    $job_slug = rawurlencode((string) $job);

    return 'session_' . (int) $session_nbr
        . '/phase_' . session_phase_pad($phase_num)
        . '/' . $job_slug
        . '/phase_' . session_phase_pad($work_phase)
        . '_' . $style . '.html';
}

function session_switchlist_work_phase_href($session_nbr, $phase_num, $job, $work_phase, $style = 'mobile')
{
    return session_output_url(session_switchlist_work_phase_rel($session_nbr, $phase_num, $job, $work_phase, $style));
}

function session_switchlist_job_href($session_nbr, $phase_num, $job, $style = 'mobile', $work_phase = 1, $root = null)
{
    $style = session_normalize_switchlist_style($style);
    $root = $root ?? session_web_root();
    if (session_switchlist_style_file_exists($session_nbr, $phase_num, $job, $style, $root, $work_phase)) {
        return session_switchlist_work_phase_href($session_nbr, $phase_num, $job, $work_phase, $style);
    }

    return session_output_url('session_' . (int) $session_nbr
        . '/phase_' . session_phase_pad($phase_num)
        . '/' . rawurlencode((string) $job)
        . '/index.html?style=' . rawurlencode($style));
}

function session_switchlist_style_file_exists($session_nbr, $phase_num, $job, $style, $root = null, $work_phase = 1)
{
    $root = $root ?? session_web_root();
    $path = session_output_fs_path(
        session_switchlist_work_phase_rel($session_nbr, $phase_num, $job, $work_phase, $style),
        $root
    );

    return is_file($path) && filesize($path) > 200;
}

function session_session_style_available($session_nbr, $style, array $manifest, $root = null)
{
    $root = $root ?? session_web_root();
    $style = session_normalize_switchlist_style($style);
    foreach ($manifest['phases'] ?? [] as $phase) {
        $phase_num = (int) ($phase['phase'] ?? 0);
        if ($phase_num < 1) {
            continue;
        }
        foreach ($phase['jobs'] ?? [] as $job) {
            $job = trim((string) $job);
            if ($job === '') {
                continue;
            }
            if (session_switchlist_style_file_exists($session_nbr, $phase_num, $job, $style, $root)) {
                return true;
            }
        }
    }

    return false;
}

function session_rerender_session_style($dbc, $session_nbr, $style, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $style = session_normalize_switchlist_style($style);
    $manifest = session_load_manifest($session_nbr, $root);
    $config = session_merge_runtime_config([]);
    $results = [];

    foreach ($manifest['phases'] ?? [] as $phase) {
        $phase_num = (int) ($phase['phase'] ?? 0);
        if ($phase_num < 1) {
            continue;
        }
        $jobs = array_values(array_filter(array_map('trim', $phase['jobs'] ?? [])));
        if (!$jobs) {
            continue;
        }
        $phase_dir = session_phase_output_dir($session_nbr, $phase_num, $root);
        $all_exist = true;
        foreach ($jobs as $job) {
            if (!session_switchlist_style_file_exists($session_nbr, $phase_num, $job, $style, $root)) {
                $all_exist = false;
                break;
            }
        }
        if ($all_exist) {
            $results[] = ['phase' => $phase_num, 'skipped' => true, 'reason' => 'style already generated'];
            continue;
        }
        $written = master_sw_generate_for_jobs($dbc, $jobs, $phase_dir, $config, [
            'format' => $style,
            'render_only' => true,
            'session_override' => (string) $session_nbr,
        ]);
        foreach ($manifest['phases'] as &$phase_entry) {
            if ((int) ($phase_entry['phase'] ?? 0) === $phase_num) {
                $existing = $phase_entry['styles'] ?? [];
                if (!is_array($existing)) {
                    $existing = [];
                }
                $phase_entry['styles'] = array_values(array_unique(array_merge(
                    $existing,
                    master_sw_styles_for_format($style)
                )));
                break;
            }
        }
        unset($phase_entry);
        $results[] = ['phase' => $phase_num, 'written' => $written];
    }

    $manifest['preferred_switchlist_style'] = $style;
    session_save_manifest($session_nbr, $manifest, $root);

    return $results;
}

function session_switchlist_phase_print_href($session_nbr, $phase_num)
{
    return session_output_url('session_' . (int) $session_nbr
        . '/phase_' . session_phase_pad($phase_num)
        . '/print_all.html');
}

function session_waybill_href_for_number($waybill_number, $session_nbr, $phase_num = null, $root = null)
{
    require_once __DIR__ . '/waybill_print_helpers.php';
    $root = $root ?? session_web_root();
    $file = waybill_print_safe_filename($waybill_number) . '.html';
    if ($phase_num !== null) {
        $phase_path = session_waybill_dir_for($session_nbr, $phase_num, $root) . '/' . $file;
        if (is_file($phase_path)) {
            return session_output_url('session_' . (int) $session_nbr
                . '/phase_' . session_phase_pad($phase_num)
                . '/waybills/' . $file);
        }
    }
    $session_path = session_waybill_dir_for($session_nbr, null, $root) . '/' . $file;
    if (is_file($session_path)) {
        return session_output_url('session_' . (int) $session_nbr . '/waybills/' . $file);
    }

    return null;
}

function session_waybill_for_marks($dbc, $marks)
{
    $marks = trim((string) $marks);
    if ($marks === '') {
        return '';
    }
    $esc = mysqli_real_escape_string($dbc, $marks);
    $rs = mysqli_query(
        $dbc,
        'SELECT car_orders.waybill_number
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         WHERE cars.reporting_marks = "' . $esc . '"
           AND car_orders.waybill_number IS NOT NULL
           AND car_orders.waybill_number != ""
         ORDER BY car_orders.waybill_number DESC
         LIMIT 1'
    );
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return '';
    }

    return trim((string) mysqli_fetch_array($rs)['waybill_number']);
}

function session_train_phase_waybills($dbc, $session_nbr, $job, $phase_num, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $phase_dir = session_phase_output_dir($session_nbr, $phase_num, $root);
    $sections = master_sw_load_sections_cache($phase_dir, $job, $session_nbr);
    if (!is_array($sections)) {
        return [];
    }

    $numbers = [];
    foreach ($sections as $section) {
        foreach ($section['cars'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $wb = session_waybill_for_marks($dbc, $row['reporting_marks'] ?? '');
            if ($wb !== '' && !in_array($wb, $numbers, true)) {
                $numbers[] = $wb;
            }
        }
    }
    sort($numbers, SORT_NATURAL | SORT_FLAG_CASE);

    return $numbers;
}

/**
 * Enumerate a train's switch-list "legs" for a session. Each work-leg (a
 * section captured during the dry-run) is one printable switch list. Returns an
 * ordered list across every workflow phase the train runs, with the waybills for
 * the cars on that leg.
 *
 * @return list<array{workflow_phase:int, work_leg:int, work_leg_total:int, label:string, base_href:string, waybills:list<array{number:string, href:?string}>}>
 */
function session_train_switchlist_legs($dbc, $session_nbr, $job, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $manifest = session_load_manifest($session_nbr, $root);
    $job_meta = $manifest['jobs'][$job] ?? ['phases' => []];
    $legs = [];

    foreach ($job_meta['phases'] ?? [] as $p) {
        $p = (int) $p;
        if ($p < 1) {
            continue;
        }
        $phase_dir = session_phase_output_dir($session_nbr, $p, $root);
        $sections = master_sw_load_sections_cache($phase_dir, $job, $session_nbr);
        if (!is_array($sections) || count($sections) === 0) {
            continue;
        }
        $work_leg_total = count($sections);
        foreach ($sections as $idx => $section) {
            $work_leg = $idx + 1;
            $numbers = [];
            foreach ($section['cars'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $wb = session_waybill_for_marks($dbc, $row['reporting_marks'] ?? '');
                if ($wb !== '' && !in_array($wb, $numbers, true)) {
                    $numbers[] = $wb;
                }
            }
            sort($numbers, SORT_NATURAL | SORT_FLAG_CASE);
            $waybills = [];
            foreach ($numbers as $wb) {
                $waybills[] = [
                    'number' => $wb,
                    'href' => session_waybill_href_for_number($wb, $session_nbr, $p, $root),
                ];
            }
            $legs[] = [
                'workflow_phase' => $p,
                'work_leg' => $work_leg,
                'work_leg_total' => $work_leg_total,
                'label' => (string) ($section['label'] ?? ('Phase ' . $work_leg)),
                'base_href' => session_output_url('session_' . (int) $session_nbr
                    . '/phase_' . session_phase_pad($p)
                    . '/' . rawurlencode((string) $job)
                    . '/phase_' . session_phase_pad($work_leg)),
                'waybills' => $waybills,
            ];
        }
    }

    return $legs;
}

/** Workflow phases a train runs that have a generated job print_all.html. */
function session_train_switchlist_phase_links($session_nbr, $job, array $phase_nums, $root = null)
{
    $root = $root ?? session_web_root();
    $links = [];
    foreach ($phase_nums as $p) {
        $p = (int) $p;
        if ($p < 1) {
            continue;
        }
        $job_rel = 'session_' . (int) $session_nbr . '/phase_' . session_phase_pad($p) . '/' . rawurlencode((string) $job);
        $index_href = session_output_url($job_rel . '/index.html');
        $print_href = session_output_url($job_rel . '/print_all.html');
        $links[] = [
            'phase' => $p,
            'index_href' => $index_href,
            'has_index' => is_file(session_output_fs_path($job_rel . '/index.html', $root)),
            'print_href' => $print_href,
            'has_print' => is_file(session_output_fs_path($job_rel . '/print_all.html', $root)),
        ];
    }

    return $links;
}

function session_manifest_has_switchlists(array $manifest, $session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    foreach ($manifest['phases'] ?? [] as $phase) {
        $phase_num = (int) ($phase['phase'] ?? 0);
        if ($phase_num < 1) {
            continue;
        }
        foreach ($phase['jobs'] ?? [] as $job) {
            $job = trim((string) $job);
            if ($job === '') {
                continue;
            }
            $index = session_output_fs_path('session_' . (int) $session_nbr
                . '/phase_' . session_phase_pad($phase_num)
                . '/' . rawurlencode((string) $job)
                . '/index.html', $root);
            if (is_file($index) && filesize($index) > 400) {
                return true;
            }
        }
    }

    $legacy = session_dir_for($session_nbr, $root) . '/index.html';
    return is_file($legacy) && filesize($legacy) > 400;
}
