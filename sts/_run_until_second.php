<?php

require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/session_helpers.php';
require_once __DIR__ . '/operational_steps_catalog.php';
require_once __DIR__ . '/master_switchlist_helpers.php';

$workflow_path = $argv[1] ?? '/tmp/start_session.workflow.json';
$to_step = (int) ($argv[2] ?? 32); // stop BEFORE step 33 (2nd STG-DEMMLER auto_assign)

$recipe = json_decode(file_get_contents($workflow_path), true);
if (!is_array($recipe)) {
    fwrite(STDERR, "Cannot read workflow: {$workflow_path}\n");
    exit(1);
}

$dbc = open_db();
$root = session_web_root();

$before = (int) session_get_db_session($dbc);

$res = session_run_recipe($dbc, $recipe, [
    'from_step' => 1,
    'to_step'   => $to_step,
]);

$after = (int) ($res['session'] ?? session_get_db_session($dbc));

echo "Ran steps 1..{$to_step} (stopped before step " . ($to_step + 1) . ")\n";
echo "DB session: {$before} -> {$after}\n";
echo str_repeat('=', 66) . "\n";

foreach ($res['log'] ?? [] as $e) {
    if (array_key_exists('assigned', $e)) {
        echo sprintf(
            "  step %-3s auto_assign %-12s station=%-14s assigned=%s\n",
            $e['step'] ?? '?',
            implode(',', (array) ($e['jobs'] ?? [])),
            $e['station'] ?? '',
            $e['assigned'] ?? 0
        );
    } elseif (array_key_exists('written', $e) && array_key_exists('phase', $e)) {
        $w = $e['written'];
        echo sprintf(
            "  step %-3s generate_switchlists phase=%-2s styles=%s\n",
            $e['step'] ?? '?',
            $e['phase'] ?? '?',
            is_array($w) ? count($w) : (int) $w
        );
    }
}

echo str_repeat('-', 66) . "\n";
$next = $recipe['steps'][$to_step] ?? null;      // step (to_step+1), 0-based index = to_step
$next2 = $recipe['steps'][$to_step + 1] ?? null; // the generate step after
echo "NEXT step " . ($to_step + 1) . ": " . ($next['function'] ?? '?')
    . " jobs=" . (is_array($next['params'] ?? null) ? ($next['params']['jobs'] ?? '') : '') . "\n";
echo "THEN step " . ($to_step + 2) . ": " . ($next2['function'] ?? '?')
    . " jobs=" . (is_array($next2['params'] ?? null) ? ($next2['params']['jobs'] ?? '') : '') . "\n";

mysqli_close($dbc);
