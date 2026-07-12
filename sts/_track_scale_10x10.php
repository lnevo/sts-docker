<?php
/**
 * 10 seeds x N sessions — workflow benchmark with track-scale weigh/reload stats.
 *
 * Usage: php _track_scale_10x10.php [workflow] [rounds] [sessions_per_round] [seed1 ...]
 */

require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/session_helpers.php';
require_once __DIR__ . '/operational_steps_catalog.php';

$workflow_path = $argv[1] ?? '/var/www/html/sts/backups/session_editor/start_session.workflow.json';
$rounds = max(1, (int) ($argv[2] ?? 10));
$sessions_per_round = max(1, (int) ($argv[3] ?? 10));
$seed_args = array_slice($argv, 4);

$recipe = json_decode(file_get_contents($workflow_path), true);
if (!is_array($recipe)) {
    fwrite(STDERR, "Cannot read workflow: {$workflow_path}\n");
    exit(1);
}

$default_seeds = [54321, 11111, 22222, 33333, 44444, 55555, 66666, 77777, 88888, 99999];
$seeds = [];
for ($i = 0; $i < $rounds; $i++) {
    $seeds[] = isset($seed_args[$i]) && $seed_args[$i] !== ''
        ? (int) $seed_args[$i]
        : $default_seeds[$i % count($default_seeds)];
}

function find_revenue_seed_step_idx(array $recipe)
{
    foreach ($recipe['steps'] ?? [] as $idx => $step) {
        if (($step['function'] ?? '') !== 'generate_orders') {
            continue;
        }
        $params = $step['params'] ?? [];
        $shipment = trim((string) ($params['shipment'] ?? ''));
        $seed = trim((string) ($params['seed'] ?? ''));
        if ($shipment === '' && $seed !== '') {
            return $idx;
        }
    }
    foreach ($recipe['steps'] ?? [] as $idx => $step) {
        if (($step['function'] ?? '') === 'generate_orders') {
            $shipment = trim((string) ($step['params']['shipment'] ?? ''));
            if ($shipment === '') {
                return $idx;
            }
        }
    }
    return 9;
}

function station_count($dbc, $st)
{
    $q = mysqli_query($dbc, 'SELECT COUNT(*) FROM cars ca JOIN locations l ON ca.current_location_id=l.Id WHERE l.station=' . (int) $st);
    return $q ? (int) mysqli_fetch_row($q)[0] : -1;
}

function db_scalar($dbc, $sql)
{
    $q = mysqli_query($dbc, $sql);
    return $q ? (int) mysqli_fetch_row($q)[0] : -1;
}

function run_round_metrics(array $log)
{
    $m = [
        'nvl_scully_assign' => [],
        'stg_scully_assign' => [],
        'stg_setout' => [],
        'weighed' => 0,
        'reloads' => 0,
        'weigh_candidates' => 0,
        'weigh_errors' => 0,
        'generated' => 0,
        'filled' => 0,
        'repositioned' => 0,
        // Per-job pickup/setout totals — the movements we tuned and must protect.
        'ck1_pickup' => 0,
        'ck1_setout' => 0,
        'd749_pickup' => 0,
        'd749_setout' => 0,
        'nvl_pickup' => 0,
        'nvl_setout' => 0,
        'stg_dem_setout' => 0,
        // Switch-list phases written per job (>=1/session expected for CK1/D749/NVL).
        'ck1_lists' => 0,
        'd749_lists' => 0,
        'nvl_lists' => 0,
        'stg_lists' => 0,
    ];
    foreach ($log as $e) {
        $jobs = strtoupper(implode(',', (array) ($e['jobs'] ?? [])));
        $job = strtoupper((string) ($e['job'] ?? ''));
        if (array_key_exists('assigned', $e)) {
            if (strpos($jobs, 'NVL') !== false && ($e['station'] ?? '') === 'Scully Yard') {
                $m['nvl_scully_assign'][] = (int) $e['assigned'];
            }
            if (strpos($jobs, 'STG-SCULLY') !== false) {
                $m['stg_scully_assign'][] = (int) $e['assigned'];
            }
        }
        if (isset($e['set_out']) && ($job === 'STG-SCULLY' || strpos($jobs, 'STG-SCULLY') !== false)) {
            $m['stg_setout'][] = (int) $e['set_out'];
        }
        if (array_key_exists('picked_up', $e)) {
            if ($job === 'CK1') {
                $m['ck1_pickup'] += (int) $e['picked_up'];
            } elseif ($job === 'D749') {
                $m['d749_pickup'] += (int) $e['picked_up'];
            } elseif ($job === 'NVL') {
                $m['nvl_pickup'] += (int) $e['picked_up'];
            }
        }
        if (array_key_exists('set_out', $e)) {
            if ($job === 'CK1') {
                $m['ck1_setout'] += (int) $e['set_out'];
            } elseif ($job === 'D749') {
                $m['d749_setout'] += (int) $e['set_out'];
            } elseif ($job === 'NVL') {
                $m['nvl_setout'] += (int) $e['set_out'];
            } elseif ($job === 'STG-DEMMLER') {
                $m['stg_dem_setout'] += (int) $e['set_out'];
            }
        }
        // generate_switchlists log entry carries a `written` list, one entry
        // per job (each has job/phases/cars). Count a "list" only when the
        // train actually produced switch-list phases (non-empty work).
        if (array_key_exists('written', $e) && is_array($e['written'])) {
            foreach ($e['written'] as $w) {
                if (!is_array($w)) {
                    continue;
                }
                if ((int) ($w['phases'] ?? 0) < 1 || !empty($w['empty'])) {
                    continue;
                }
                $wj = strtoupper((string) ($w['job'] ?? ''));
                if ($wj === 'CK1') {
                    $m['ck1_lists']++;
                } elseif ($wj === 'D749') {
                    $m['d749_lists']++;
                } elseif ($wj === 'NVL') {
                    $m['nvl_lists']++;
                } elseif ($wj === 'STG-SCULLY') {
                    $m['stg_lists']++;
                }
            }
        }
        foreach (['generated', 'filled', 'repositioned'] as $key) {
            if (array_key_exists($key, $e)) {
                $m[$key] += (int) $e[$key];
            }
        }
        if (isset($e['weigh']) && is_array($e['weigh'])) {
            $w = $e['weigh'];
            $m['weighed'] += (int) ($w['weighed'] ?? 0);
            $m['reloads'] += (int) ($w['reloads'] ?? 0);
            $m['weigh_candidates'] += (int) ($w['candidates'] ?? 0);
            if (!empty($w['errors'])) {
                $m['weigh_errors'] += count((array) $w['errors']);
            }
        }
    }
    return $m;
}

function round_notes(array $row, $sessions_per_round)
{
    $notes = [];
    if (($row['mckees'] ?? 0) > 15) {
        $notes[] = 'McK stuck';
    }
    if (($row['scully'] ?? 0) > 10) {
        $notes[] = 'Scully stuck';
    }
    if (($row['demmler'] ?? 0) > 12) {
        $notes[] = 'Demmler stuck';
    }
    if (($row['neville'] ?? 0) > 20) {
        $notes[] = 'Neville stuck';
    }
    if (($row['nvl_scully_avg'] ?? 0) < 1.0) {
        $notes[] = 'NVL thin';
    }
    if (($row['weighed'] ?? 0) < $sessions_per_round * 3) {
        $notes[] = 'weigh thin';
    }
    if (($row['unfilled'] ?? 0) > 30) {
        $notes[] = 'orders backlog';
    }
    // Regression guards for the movements we tuned previously.
    if (($row['ck1_lists'] ?? 0) < $sessions_per_round) {
        $notes[] = 'CK1 thin';
    }
    if (($row['d749_lists'] ?? 0) < $sessions_per_round) {
        $notes[] = 'D749 thin';
    }
    if (($row['ck1_pickup'] ?? 0) < $sessions_per_round * 5) {
        $notes[] = 'coke thin';
    }
    if (($row['d749_pickup'] ?? 0) < $sessions_per_round * 2) {
        $notes[] = 'D749 pickups thin';
    }
    return $notes === [] ? 'ok' : implode(', ', $notes);
}

$seed_step_idx = find_revenue_seed_step_idx($recipe);

echo "Workflow: {$workflow_path}\n";
echo "Revenue seed step index: {$seed_step_idx}\n";
echo "Rounds: {$rounds} x {$sessions_per_round} sessions\n";
echo str_repeat('=', 170) . "\n";
printf(
    "%-5s %-7s | %-4s %-4s %-4s %-4s | %-4s %-4s %-4s | %-4s %-4s | %-4s %-4s %-4s | %-6s %-6s %-6s | %-4s %-4s | %s\n",
    'Rnd',
    'Seed',
    'Dem',
    'Scul',
    'Sth',
    'Nv',
    'Unfl',
    'Open',
    'OffH',
    'NVL@',
    'STG@',
    'Wgh',
    'Rld',
    'WgE',
    'CK1pu',
    'D749pu',
    'NVLpu',
    'CKls',
    'D7ls',
    'Notes'
);
echo str_repeat('-', 170) . "\n";

$dbc = open_db();
$round_summaries = [];

foreach ($seeds as $ri => $seed) {
    $round = $ri + 1;
    $recipe_round = $recipe;
    if (!isset($recipe_round['steps'][$seed_step_idx]['params']) || !is_array($recipe_round['steps'][$seed_step_idx]['params'])) {
        $recipe_round['steps'][$seed_step_idx]['params'] = [];
    }
    $recipe_round['steps'][$seed_step_idx]['params']['seed'] = (string) $seed;

    list($ok, $msg) = operational_steps_restore_backup($dbc, 'hart_seed', 'hart_seed');
    if (!$ok) {
        echo "Round {$round} seed {$seed}: RESTORE FAILED: {$msg}\n";
        continue;
    }

    $all_log = [];
    for ($run = 1; $run <= $sessions_per_round; $run++) {
        $res = session_run_recipe($dbc, $recipe_round, [
            'from_step' => 1,
            'reset_output' => ($run === 1),
        ]);
        $all_log = array_merge($all_log, $res['log'] ?? []);
    }

    $m = run_round_metrics($all_log);
    $nvl_avg = $m['nvl_scully_assign'] !== []
        ? round(array_sum($m['nvl_scully_assign']) / count($m['nvl_scully_assign']), 1)
        : 0;
    $stg_avg = $m['stg_scully_assign'] !== []
        ? round(array_sum($m['stg_scully_assign']) / count($m['stg_scully_assign']), 1)
        : 0;

    $row = [
        'round' => $round,
        'seed' => $seed,
        'demmler' => station_count($dbc, 10),
        'scully' => station_count($dbc, 9),
        'south' => station_count($dbc, 8),
        'neville' => station_count($dbc, 3),
        'unfilled' => db_scalar(
            $dbc,
            'SELECT COUNT(DISTINCT waybill_number) FROM car_orders WHERE car = "" OR car IS NULL OR car = "0"'
        ),
        'open_orders' => db_scalar($dbc, 'SELECT COUNT(DISTINCT waybill_number) FROM car_orders'),
        'off_home' => db_scalar(
            $dbc,
            "SELECT COUNT(*) FROM cars WHERE status='Empty' AND current_location_id != home_location"
        ),
        'nvl_scully_avg' => $nvl_avg,
        'stg_scully_avg' => $stg_avg,
        'weighed' => $m['weighed'],
        'reloads' => $m['reloads'],
        'weigh_candidates' => $m['weigh_candidates'],
        'weigh_errors' => $m['weigh_errors'],
        'ck1_pickup' => $m['ck1_pickup'],
        'ck1_setout' => $m['ck1_setout'],
        'd749_pickup' => $m['d749_pickup'],
        'd749_setout' => $m['d749_setout'],
        'nvl_pickup' => $m['nvl_pickup'],
        'nvl_setout' => $m['nvl_setout'],
        'stg_dem_setout' => $m['stg_dem_setout'],
        'ck1_lists' => $m['ck1_lists'],
        'd749_lists' => $m['d749_lists'],
        'nvl_lists' => $m['nvl_lists'],
        'stg_lists' => $m['stg_lists'],
        'generated' => $m['generated'],
        'filled' => $m['filled'],
        'repositioned' => $m['repositioned'],
    ];
    $row['notes'] = round_notes($row, $sessions_per_round);

    printf(
        "%-5d %-7d | %-4d %-4d %-4d %-4d | %-4d %-4d %-4d | %-4s %-4s | %-4d %-4d %-4d | %-6d %-6d %-6d | %-4d %-4d | %s\n",
        $row['round'],
        $row['seed'],
        $row['demmler'],
        $row['scully'],
        $row['south'],
        $row['neville'],
        $row['unfilled'],
        $row['open_orders'],
        $row['off_home'],
        (string) $row['nvl_scully_avg'],
        (string) $row['stg_scully_avg'],
        $row['weighed'],
        $row['reloads'],
        $row['weigh_errors'],
        $row['ck1_pickup'],
        $row['d749_pickup'],
        $row['nvl_pickup'],
        $row['ck1_lists'],
        $row['d749_lists'],
        $row['notes']
    );

    $round_summaries[] = $row;
}

function avg_key(array $rows, $key)
{
    if ($rows === []) {
        return 0;
    }
    return round(array_sum(array_column($rows, $key)) / count($rows), 1);
}

$ok_rounds = count(array_filter($round_summaries, static function ($r) {
    return ($r['notes'] ?? '') === 'ok';
}));

echo str_repeat('=', 170) . "\n";
printf(
    "AVG   %-7s | %-4s %-4s %-4s %-4s | %-4s %-4s %-4s | %-4s %-4s | %-4s %-4s %-4s | %-6s %-6s %-6s | %-4s %-4s | %d/%d ok\n",
    '',
    (string) avg_key($round_summaries, 'demmler'),
    (string) avg_key($round_summaries, 'scully'),
    (string) avg_key($round_summaries, 'south'),
    (string) avg_key($round_summaries, 'neville'),
    (string) avg_key($round_summaries, 'unfilled'),
    (string) avg_key($round_summaries, 'open_orders'),
    (string) avg_key($round_summaries, 'off_home'),
    (string) avg_key($round_summaries, 'nvl_scully_avg'),
    (string) avg_key($round_summaries, 'stg_scully_avg'),
    (string) round(avg_key($round_summaries, 'weighed'), 0),
    (string) round(avg_key($round_summaries, 'reloads'), 0),
    (string) round(avg_key($round_summaries, 'weigh_errors'), 0),
    (string) round(avg_key($round_summaries, 'ck1_pickup'), 0),
    (string) round(avg_key($round_summaries, 'd749_pickup'), 0),
    (string) round(avg_key($round_summaries, 'nvl_pickup'), 0),
    (string) round(avg_key($round_summaries, 'ck1_lists'), 0),
    (string) round(avg_key($round_summaries, 'd749_lists'), 0),
    $ok_rounds,
    count($round_summaries)
);

$tot_weighed = (int) array_sum(array_column($round_summaries, 'weighed'));
$tot_reloads = (int) array_sum(array_column($round_summaries, 'reloads'));
$reload_pct = $tot_weighed > 0 ? round(100.0 * $tot_reloads / $tot_weighed, 1) : 0.0;
$per_round_pct = array_map(static function ($r) {
    $w = (int) ($r['weighed'] ?? 0);
    return $w > 0 ? round(100.0 * (int) ($r['reloads'] ?? 0) / $w, 1) : 0.0;
}, $round_summaries);
echo "\n== Track scale reload routing ==\n";
printf("  Overall reload rate: %s%% (%d reload / %d weighed) — target 10-15%%\n", (string) $reload_pct, $tot_reloads, $tot_weighed);
printf("  Per-round reload %%: %s\n", implode(', ', array_map(static function ($p) {
    return $p . '%';
}, $per_round_pct)));

echo "\n== Movement protection summary (10x10 averages) ==\n";
printf("  CK1 coke: pickups=%s setout=%s lists=%s\n",
    avg_key($round_summaries, 'ck1_pickup'),
    avg_key($round_summaries, 'ck1_setout'),
    avg_key($round_summaries, 'ck1_lists')
);
printf("  D749: pickups=%s setout=%s lists=%s\n",
    avg_key($round_summaries, 'd749_pickup'),
    avg_key($round_summaries, 'd749_setout'),
    avg_key($round_summaries, 'd749_lists')
);
printf("  NVL: pickups=%s setout=%s lists=%s  scully_assign_avg=%s\n",
    avg_key($round_summaries, 'nvl_pickup'),
    avg_key($round_summaries, 'nvl_setout'),
    avg_key($round_summaries, 'nvl_lists'),
    avg_key($round_summaries, 'nvl_scully_avg')
);
printf("  STG: SCULLY assign_avg=%s setout_lists=%s  DEMMLER setout=%s\n",
    avg_key($round_summaries, 'stg_scully_avg'),
    avg_key($round_summaries, 'stg_lists'),
    avg_key($round_summaries, 'stg_dem_setout')
);
printf("  Track scale: weighed=%s reloads=%s\n",
    avg_key($round_summaries, 'weighed'),
    avg_key($round_summaries, 'reloads')
);

if ($round_summaries !== []) {
    $last = end($round_summaries);
    echo "\nLast round weigh detail (log totals over {$sessions_per_round} sessions):\n";
    echo "  weighed={$last['weighed']} reloads={$last['reloads']} candidates={$last['weigh_candidates']} weigh_errors={$last['weigh_errors']}\n";
}

mysqli_close($dbc);
