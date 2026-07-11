<?php
require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/session_helpers.php';
require_once __DIR__ . '/operational_steps_catalog.php';

$workflow_path = $argv[1] ?? '/tmp/start_session.workflow.json';
$sessions = (int) ($argv[2] ?? 10);
$recipe = json_decode(file_get_contents($workflow_path), true);
if (!is_array($recipe)) {
    fwrite(STDERR, "cannot read workflow\n");
    exit(1);
}
$dbc = open_db();
list($ok, $msg) = operational_steps_restore_backup($dbc, 'hart_seed', 'hart_seed');
fwrite(STDERR, 'restore: ' . ($ok ? 'ok' : $msg) . "\n");

function station_count($dbc, $st)
{
    $q = mysqli_query($dbc, 'SELECT COUNT(*) FROM cars ca JOIN locations l ON ca.current_location_id=l.Id WHERE l.station=' . (int) $st);
    return $q ? (int) mysqli_fetch_row($q)[0] : -1;
}
// loaded vs empty at a station
function station_load($dbc, $st)
{
    $q = mysqli_query($dbc, 'SELECT ca.status, COUNT(*) c FROM cars ca JOIN locations l ON ca.current_location_id=l.Id WHERE l.station=' . (int) $st . ' GROUP BY ca.status');
    $out = [];
    if ($q) {
        while ($r = mysqli_fetch_assoc($q)) {
            $out[(string) $r['status']] = (int) $r['c'];
        }
    }
    return $out;
}

printf("%-4s | %-7s %-7s %-7s %-7s | %-7s %-7s %-7s\n", 'sess', 'Scully9', 'McK15', 'Nev3', 'South8', 'SCassg', 'NVLassg', 'SCsetMcK');
echo str_repeat('-', 78) . "\n";

for ($run = 1; $run <= $sessions; $run++) {
    $res = session_run_recipe($dbc, $recipe, ['from_step' => 1, 'reset_output' => ($run === 1)]);
    $sess = (int) ($res['session'] ?? session_get_db_session($dbc));

    $sc_assign = 0;
    $nvl_assign = 0;
    $sc_pick = 0;
    $sc_setout = 0;
    $stg_scully_seen = false;
    foreach ($res['log'] ?? [] as $e) {
        $jobs = strtoupper(implode(',', (array) ($e['jobs'] ?? [])));
        $job = strtoupper((string) ($e['job'] ?? ''));
        if (array_key_exists('assigned', $e)) {
            if (strpos($jobs, 'STG-SCULLY') !== false) {
                $sc_assign = (int) $e['assigned'];
            }
            if (strpos($jobs, 'NVL') !== false && ($e['station'] ?? '') === 'Scully Yard') {
                $nvl_assign = (int) $e['assigned'];
            }
        }
        if ($job === 'STG-SCULLY' || strpos($jobs, 'STG-SCULLY') !== false) {
            if (isset($e['picked_up'])) {
                $sc_pick += (int) $e['picked_up'];
            }
            if (isset($e['set_out'])) {
                $sc_setout += (int) $e['set_out'];
            }
        }
    }

    printf(
        "%-4d | %-7d %-7d %-7d %-7d | %-7d %-7d %-7d\n",
        $sess,
        station_count($dbc, 9),
        station_count($dbc, 15),
        station_count($dbc, 3),
        station_count($dbc, 8),
        $sc_assign,
        $nvl_assign,
        $sc_setout
    );
}

echo "\n== Final McKees Rocks (15) by status ==\n";
foreach (station_load($dbc, 15) as $st => $c) {
    printf("  %-24s %d\n", $st === '' ? '(none)' : $st, $c);
}
echo "\n== Final Scully (9) by status ==\n";
foreach (station_load($dbc, 9) as $st => $c) {
    printf("  %-24s %d\n", $st === '' ? '(none)' : $st, $c);
}
mysqli_close($dbc);
