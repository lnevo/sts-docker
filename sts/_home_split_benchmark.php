<?php
/**
 * 10x10 home-split scenario benchmark (restore + N sessions per seed).
 *
 * Usage: php _home_split_benchmark.php [workflow] [rounds] [sessions_per_round]
 */

require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/session_helpers.php';
require_once __DIR__ . '/operational_steps_catalog.php';

$workflow_path = $argv[1] ?? '/tmp/start_session.workflow.json';
$rounds = max(1, (int) ($argv[2] ?? 10));
$sessions_per_round = max(1, (int) ($argv[3] ?? 10));
$scenario = $argv[4] ?? 'unknown';

$recipe = json_decode(file_get_contents($workflow_path), true);
if (!is_array($recipe)) {
    fwrite(STDERR, "Cannot read workflow: {$workflow_path}\n");
    exit(1);
}

$step9_idx = 8;
$default_seeds = [54321, 11111, 22222, 33333, 44444, 55555, 66666, 77777, 88888, 99999];
$seeds = [];
for ($i = 0; $i < $rounds; $i++) {
    $seeds[] = $default_seeds[$i % count($default_seeds)];
}

function station_count($dbc, $st)
{
    $q = mysqli_query($dbc, 'SELECT COUNT(*) FROM cars ca JOIN locations l ON ca.current_location_id=l.Id WHERE l.station=' . (int) $st);
    return $q ? (int) mysqli_fetch_row($q)[0] : -1;
}

function yard_code_count($dbc, $code)
{
    $code = mysqli_real_escape_string($dbc, $code);
    $q = mysqli_query(
        $dbc,
        "SELECT COUNT(*) FROM cars ca JOIN locations l ON ca.current_location_id=l.Id WHERE l.code='{$code}'"
    );
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
        'ck1_lists' => 0,
        'd749_lists' => 0,
        'nvl_lists' => 0,
        'stg_lists' => 0,
        'repositioned' => 0,
        'generated' => 0,
        'filled' => 0,
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
        if (array_key_exists('written', $e) && array_key_exists('phase', $e)) {
            $wj = strtoupper(implode(',', (array) ($e['jobs'] ?? [])));
            if (strpos($wj, 'CK1') !== false) {
                $m['ck1_lists']++;
            }
            if (strpos($wj, 'D749') !== false) {
                $m['d749_lists']++;
            }
            if (strpos($wj, 'NVL') !== false) {
                $m['nvl_lists']++;
            }
            if (strpos($wj, 'STG-SCULLY') !== false) {
                $m['stg_lists']++;
            }
        }
        foreach (['repositioned', 'generated', 'filled'] as $key) {
            if (array_key_exists($key, $e)) {
                $m[$key] += (int) $e[$key];
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
    if (($row['neville'] ?? 0) > 20) {
        $notes[] = 'Neville stuck';
    }
    if (($row['demmler'] ?? 0) > 12) {
        $notes[] = 'Demmler stuck';
    }
    if (($row['nvl_scully_avg'] ?? 0) < 1.0) {
        $notes[] = 'NVL thin';
    }
    if (($row['ck1_lists'] ?? 0) < $sessions_per_round * 1.5) {
        $notes[] = 'CK1 thin';
    }
    if (($row['d749_lists'] ?? 0) < $sessions_per_round * 1.5) {
        $notes[] = 'D749 thin';
    }
    if (($row['off_home'] ?? 0) > 25) {
        $notes[] = 'off-home high';
    }
    return $notes === [] ? 'ok' : implode(', ', $notes);
}

echo "SCENARIO: {$scenario}\n";
echo "Workflow: {$workflow_path}\n";
echo "Rounds: {$rounds} x {$sessions_per_round} sessions\n";
echo str_repeat('=', 120) . "\n";
printf(
    "%-6s %-8s | %-4s %-4s %-4s %-4s | %-5s %-5s | %-4s %-4s %-4s %-4s | %-5s %-5s %-5s | %s\n",
    'Round',
    'Seed',
    'Dem',
    'Scul',
    'Sth',
    'OffH',
    'Unfl',
    'Open',
    'NVL@',
    'STG@',
    'CK1',
    'D749',
    'NVL',
    'STG',
    'Repo',
    'Fill',
    'Gen',
    'Notes'
);
echo str_repeat('-', 120) . "\n";

$dbc = open_db();
$round_summaries = [];

foreach ($seeds as $ri => $seed) {
    $round = $ri + 1;
    $recipe_round = $recipe;
    if (!isset($recipe_round['steps'][$step9_idx]['params']) || !is_array($recipe_round['steps'][$step9_idx]['params'])) {
        $recipe_round['steps'][$step9_idx]['params'] = [];
    }
    $recipe_round['steps'][$step9_idx]['params']['seed'] = (string) $seed;

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
        'mckees' => station_count($dbc, 15),
        'mckeesport' => station_count($dbc, 14),
        'neville' => station_count($dbc, 3),
        'off_home' => db_scalar(
            $dbc,
            "SELECT COUNT(*) FROM cars WHERE status='Empty' AND current_location_id != home_location"
        ),
        'unfilled' => db_scalar(
            $dbc,
            "SELECT COUNT(*) FROM car_orders WHERE filled=0"
        ),
        'open_orders' => db_scalar(
            $dbc,
            "SELECT COUNT(*) FROM car_orders WHERE filled=0 AND shipment IS NOT NULL"
        ),
        'nvl_scully_avg' => $nvl_avg,
        'stg_scully_avg' => $stg_avg,
        'ck1_lists' => $m['ck1_lists'],
        'd749_lists' => $m['d749_lists'],
        'nvl_lists' => $m['nvl_lists'],
        'stg_lists' => $m['stg_lists'],
        'repositioned' => $m['repositioned'],
        'filled' => $m['filled'],
        'generated' => $m['generated'],
    ];
    $row['notes'] = round_notes($row, $sessions_per_round);

    printf(
        "%-6d %-8d | %-4d %-4d %-4d %-4d | %-5d %-5d | %-4s %-4s | %-4d %-4d %-4d %-4d | %-5d %-5d %-5d | %s\n",
        $row['round'],
        $row['seed'],
        $row['demmler'],
        $row['scully'],
        $row['south'],
        $row['off_home'],
        $row['unfilled'],
        $row['open_orders'],
        (string) $row['nvl_scully_avg'],
        (string) $row['stg_scully_avg'],
        $row['ck1_lists'],
        $row['d749_lists'],
        $row['nvl_lists'],
        $row['stg_lists'],
        $row['repositioned'],
        $row['filled'],
        $row['generated'],
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

echo str_repeat('=', 120) . "\n";
printf(
    "AVG    %-8s | %-4s %-4s %-4s %-4s | %-5s %-5s | %-4s %-4s | %-4s %-4s %-4s %-4s | %-5s %-5s %-5s | %d/%d ok\n",
    '',
    (string) avg_key($round_summaries, 'demmler'),
    (string) avg_key($round_summaries, 'scully'),
    (string) avg_key($round_summaries, 'south'),
    (string) avg_key($round_summaries, 'off_home'),
    (string) avg_key($round_summaries, 'unfilled'),
    (string) avg_key($round_summaries, 'open_orders'),
    (string) avg_key($round_summaries, 'nvl_scully_avg'),
    (string) avg_key($round_summaries, 'stg_scully_avg'),
    (string) round(avg_key($round_summaries, 'ck1_lists'), 0),
    (string) round(avg_key($round_summaries, 'd749_lists'), 0),
    (string) round(avg_key($round_summaries, 'nvl_lists'), 0),
    (string) round(avg_key($round_summaries, 'stg_lists'), 0),
    (string) round(avg_key($round_summaries, 'repositioned'), 0),
    (string) round(avg_key($round_summaries, 'filled'), 0),
    (string) round(avg_key($round_summaries, 'generated'), 0),
    $ok_rounds,
    count($round_summaries)
);

mysqli_close($dbc);
