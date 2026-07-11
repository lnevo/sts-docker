<?php

require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/session_helpers.php';
require_once __DIR__ . '/operational_steps_catalog.php';
require_once __DIR__ . '/master_switchlist_helpers.php';

$workflow_path = $argv[1] ?? '/tmp/start_session.workflow.json';
$target_session = (int) ($argv[2] ?? 3);

$recipe = json_decode(file_get_contents($workflow_path), true);
if (!is_array($recipe)) {
    fwrite(STDERR, "Cannot read workflow: {$workflow_path}\n");
    exit(1);
}

$dbc = open_db();
$root = session_web_root();

list($ok, $msg) = operational_steps_restore_backup($dbc, 'hart_seed', 'hart_seed');
fwrite(STDERR, 'restore hart_seed: ' . ($ok ? 'ok' : "FAILED: {$msg}") . "\n");

// Expected generate_switchlists from workflow (per train).
$expected = [];
foreach ($recipe['steps'] ?? [] as $i => $step) {
    if (($step['function'] ?? '') !== 'generate_switchlists') {
        continue;
    }
    $jobs = trim((string) ($step['params']['jobs'] ?? 'all'));
    if ($jobs === '' || strcasecmp($jobs, 'all') === 0) {
        $jobs = 'ALL';
    }
    foreach (preg_split('/\s*,\s*/', $jobs) as $job) {
        $job = trim($job);
        if ($job === '') {
            continue;
        }
        $expected[$job] = ($expected[$job] ?? 0) + 1;
    }
}

echo "Expected generate_switchlists per train (workflow):\n";
foreach ($expected as $job => $count) {
    echo "  {$job}: {$count}\n";
}
echo str_repeat('=', 72) . "\n";

for ($run = 1; $run <= $target_session; $run++) {
    $before = (int) session_get_db_session($dbc);
    $res = session_run_recipe($dbc, $recipe, [
        'reset_output' => ($run === 1),
        'from_step' => 1,
    ]);
    $after = (int) ($res['session'] ?? session_get_db_session($dbc));
    echo "\nSESSION {$after} (run {$run}, was {$before})\n";
    echo str_repeat('-', 72) . "\n";

    $manifest = session_load_manifest($after, $root);
    $by_job = [];
    $empty_phases = [];

    foreach ($manifest['phases'] ?? [] as $phase) {
        $pn = (int) ($phase['phase'] ?? 0);
        $jobs = array_values(array_filter(array_map('trim', $phase['jobs'] ?? [])));
        $phase_dir = session_phase_output_dir($after, $pn, $root);

        foreach ($jobs as $job) {
            $job_dir = $phase_dir . '/' . rawurlencode($job);
            $style_files = 0;
            if (is_dir($job_dir)) {
                foreach (glob($job_dir . '/phase_*_*.html') ?: [] as $f) {
                    $style_files++;
                }
            }
            $has_content = $style_files > 0;
            $by_job[$job] = $by_job[$job] ?? ['phases' => 0, 'with_lists' => 0, 'empty' => 0];
            $by_job[$job]['phases']++;
            if ($has_content) {
                $by_job[$job]['with_lists']++;
            } else {
                $by_job[$job]['empty']++;
                $empty_phases[] = "phase_{$pn}/{$job}";
            }

            // Log auto_assign before this phase if we can find it in run log.
        }
    }

    // Pull auto_assign + generate_switchlists from log.
    foreach ($res['log'] ?? [] as $e) {
        if (array_key_exists('assigned', $e)) {
            $jobs = implode(',', (array) ($e['jobs'] ?? []));
            $station = $e['station'] ?? '';
            echo sprintf(
                "  step %-3s auto_assign %-12s station=%-14s assigned=%s\n",
                $e['step'] ?? '?',
                $jobs,
                $station,
                $e['assigned'] ?? 0
            );
        }
        if (array_key_exists('written', $e) && array_key_exists('phase', $e)) {
            $written = $e['written'];
            $count = is_array($written) ? count($written) : (int) $written;
            echo sprintf(
                "  step %-3s generate_switchlists phase=%-2s styles_written=%s\n",
                $e['step'] ?? '?',
                $e['phase'] ?? '?',
                $count
            );
        }
    }

    echo "\n  Actual switchlists per train:\n";
    foreach ($expected as $job => $exp) {
        $actual = $by_job[$job] ?? ['phases' => 0, 'with_lists' => 0, 'empty' => 0];
        $match = ($actual['with_lists'] === $exp) ? 'MATCH' : 'MISMATCH';
        printf(
            "    %-12s expected=%d  phases=%d  with_lists=%d  empty=%d  %s\n",
            $job,
            $exp,
            $actual['phases'],
            $actual['with_lists'],
            $actual['empty'],
            $match
        );
    }
    if ($empty_phases !== []) {
        echo '  Empty phases: ' . implode(', ', $empty_phases) . "\n";
    }
}

mysqli_close($dbc);
