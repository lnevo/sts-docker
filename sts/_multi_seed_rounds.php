<?php
/**
 * Run multiple simulation rounds (restore + N sessions each) with different
 * generate_orders seeds at step 9.
 *
 * Usage: php _multi_seed_rounds.php [workflow] [rounds] [sessions_per_round] [seed1 seed2 ...]
 */

require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/session_helpers.php';
require_once __DIR__ . '/operational_steps_catalog.php';

$workflow_path = $argv[1] ?? '/tmp/start_session.workflow.json';
$rounds = max(1, (int) ($argv[2] ?? 9));
$sessions_per_round = max(1, (int) ($argv[3] ?? 10));
$seed_args = array_slice($argv, 4);

$recipe = json_decode(file_get_contents($workflow_path), true);
if (!is_array($recipe)) {
    fwrite(STDERR, "Cannot read workflow: {$workflow_path}\n");
    exit(1);
}

// Step 9 = index 8 (generate_orders with automatic shipment mix).
$step9_idx = 8;
$default_seeds = [54321, 11111, 22222, 33333, 44444, 55555, 66666, 77777, 88888, 99999, 424242];
$seeds = [];
for ($i = 0; $i < $rounds; $i++) {
    if (isset($seed_args[$i]) && $seed_args[$i] !== '') {
        $seeds[] = (int) $seed_args[$i];
    } else {
        $seeds[] = $default_seeds[$i % count($default_seeds)];
    }
}

function station_count($dbc, $st)
{
    $q = mysqli_query($dbc, 'SELECT COUNT(*) FROM cars ca JOIN locations l ON ca.current_location_id=l.Id WHERE l.station=' . (int) $st);
    return $q ? (int) mysqli_fetch_row($q)[0] : -1;
}

function count_switchlist_phases($dbc, $session_nbr, $root, array $jobs)
{
    $manifest = session_load_manifest($session_nbr, $root);
    $by_job = [];
    foreach ($jobs as $job) {
        $by_job[$job] = 0;
    }
    foreach ($manifest['phases'] ?? [] as $phase) {
        foreach ($phase['jobs'] ?? [] as $job) {
            $job = trim((string) $job);
            if (!isset($by_job[$job])) {
                continue;
            }
            $pn = (int) ($phase['phase'] ?? 0);
            $phase_dir = session_phase_output_dir($session_nbr, $pn, $root);
            $job_dir = $phase_dir . '/' . rawurlencode($job);
            $has = false;
            if (is_dir($job_dir)) {
                foreach (glob($job_dir . '/phase_*_*.html') ?: [] as $f) {
                    $has = true;
                    break;
                }
            }
            if ($has) {
                $by_job[$job]++;
            }
        }
    }
    return $by_job;
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
    ];
    foreach ($log as $e) {
        $step = (int) ($e['step'] ?? 0);
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
    }
    return $m;
}

echo "Workflow: {$workflow_path}\n";
echo "Rounds: {$rounds} x {$sessions_per_round} sessions\n";
echo str_repeat('=', 100) . "\n";
printf(
    "%-6s %-8s | %-5s %-5s %-5s | %-8s %-8s %-8s | %-4s %-4s %-4s %-4s | %s\n",
    'Round',
    'Seed',
    'Sc9',
    'Mc15',
    'Nv3',
    'NVL@Sc',
    'STG@Sc',
    'STGout',
    'CK1',
    'D749',
    'NVL',
    'STG',
    'Notes'
);
echo str_repeat('-', 100) . "\n";

$dbc = open_db();
$root = session_web_root();
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
    $last_sess = 0;
    for ($run = 1; $run <= $sessions_per_round; $run++) {
        $res = session_run_recipe($dbc, $recipe_round, [
            'from_step' => 1,
            'reset_output' => ($run === 1),
        ]);
        $last_sess = (int) ($res['session'] ?? session_get_db_session($dbc));
        $all_log = array_merge($all_log, $res['log'] ?? []);
    }

    $m = run_round_metrics($all_log);
    $nvl_scully = $m['nvl_scully_assign'];
    $stg_scully = $m['stg_scully_assign'];
    $stg_out = $m['stg_setout'];
    $nvl_avg = $nvl_scully !== [] ? round(array_sum($nvl_scully) / count($nvl_scully), 1) : 0;
    $stg_avg = $stg_scully !== [] ? round(array_sum($stg_scully) / count($stg_scully), 1) : 0;
    $stg_out_sum = array_sum($stg_out);

    $sc = station_count($dbc, 9);
    $mc = station_count($dbc, 15);
    $nv = station_count($dbc, 3);

    $lists = count_switchlist_phases($dbc, $last_sess, $root, ['CK1', 'D749', 'NVL', 'STG-SCULLY']);
    // Per-session list counts from log (cleaner than cumulative manifest)
    $ck1 = $m['ck1_lists'];
    $d749 = $m['d749_lists'];
    $nvl = $m['nvl_lists'];
    $stg = $m['stg_lists'];

    $notes = [];
    if ($mc > 15) {
        $notes[] = 'McK stuck';
    }
    if ($sc > 10) {
        $notes[] = 'Scully stuck';
    }
    if ($nv > 20) {
        $notes[] = 'Neville stuck';
    }
    if ($nvl_avg < 1.0) {
        $notes[] = 'NVL thin';
    }
    if ($ck1 < $sessions_per_round * 1.5) {
        $notes[] = 'CK1 thin';
    }
    if ($d749 < $sessions_per_round * 1.5) {
        $notes[] = 'D749 thin';
    }
    $note = $notes === [] ? 'ok' : implode(', ', $notes);

    printf(
        "%-6d %-8d | %-5d %-5d %-5d | %-8s %-8s %-8d | %-4d %-4d %-4d %-4d | %s\n",
        $round,
        $seed,
        $sc,
        $mc,
        $nv,
        (string) $nvl_avg . ' avg',
        (string) $stg_avg . ' avg',
        $stg_out_sum,
        $ck1,
        $d749,
        $nvl,
        $stg,
        $note
    );

    $round_summaries[] = [
        'round' => $round,
        'seed' => $seed,
        'scully' => $sc,
        'mckees' => $mc,
        'neville' => $nv,
        'nvl_scully_avg' => $nvl_avg,
        'stg_scully_avg' => $stg_avg,
        'ck1_lists' => $ck1,
        'd749_lists' => $d749,
        'nvl_lists' => $nvl,
        'notes' => $note,
    ];
}

echo str_repeat('=', 100) . "\n";
echo "Per-session NVL@Scully assign (last round):\n";
if (isset($nvl_scully)) {
    echo '  ' . implode(', ', $nvl_scully) . "\n";
}
echo "\nPer-session STG-SCULLY assign (last round):\n";
if (isset($stg_scully)) {
    echo '  ' . implode(', ', $stg_scully) . "\n";
}

$ok_rounds = count(array_filter($round_summaries, static function ($r) {
    return ($r['notes'] ?? '') === 'ok';
}));
echo "\nSummary: {$ok_rounds}/" . count($round_summaries) . " rounds with no pileup/thin flags.\n";

mysqli_close($dbc);
