<?php
/**
 * Diagnostic: restore hart_seed, run N operating sessions through the editor's
 * ACTIVE workflow, and print per-session traffic metrics so we can see where
 * order generation / car movement stalls.
 *
 * Usage: php _traffic_sweep.php [sessions] [workflow.json|name]
 * Default workflow: hart_session (editor active workflow if unset).
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}
chdir(__DIR__);
require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/session_helpers.php';
require_once __DIR__ . '/operational_steps_catalog.php';
require_once __DIR__ . '/generate_order_helpers.php';

$sessions = max(1, (int) ($argv[1] ?? 40));

$editorDir = operational_steps_editor_dir();
$arg = $argv[2] ?? 'hart_session';
$resolved = operational_steps_resolve_workflow_filename($editorDir, $arg);
if ($resolved !== '') {
    $jsonPath = operational_steps_workflow_path($editorDir, $resolved);
} elseif ($arg !== '' && is_file($arg)) {
    $jsonPath = $arg;
} elseif ($arg !== '') {
    $jsonPath = operational_steps_workflow_path($editorDir, $arg);
} else {
    $active = operational_steps_active_workflow($editorDir);
    $jsonPath = $active !== '' ? operational_steps_workflow_path($editorDir, $active) : '';
}
if ($jsonPath === '' || !is_file($jsonPath)) {
    fwrite(STDERR, "No workflow found (requested='{$arg}', path='{$jsonPath}')\n");
    exit(1);
}
$recipe = operational_steps_load_recipe_from_json_file($jsonPath);
$recipe['source_workflow'] = basename($jsonPath);

$dbc = open_db();

function q1($dbc, $sql)
{
    $r = mysqli_query($dbc, $sql);
    return $r ? (int) mysqli_fetch_row($r)[0] : -1;
}

function station_count($dbc, $st)
{
    return q1($dbc, 'SELECT COUNT(*) FROM cars ca JOIN locations l ON ca.current_location_id=l.Id WHERE l.station=' . (int) $st);
}

function status_count($dbc, $status)
{
    $s = mysqli_real_escape_string($dbc, $status);
    return q1($dbc, "SELECT COUNT(*) FROM cars WHERE status='{$s}'");
}

// Total car_orders still open (waybill assigned but shipment not completed).
function open_orders($dbc)
{
    return q1($dbc, 'SELECT COUNT(*) FROM car_orders');
}

echo "Workflow: {$jsonPath}\n";
echo "Sessions: {$sessions}\n";

list($ok, $msg) = operational_steps_restore_backup($dbc, 'hart_seed', 'hart_seed');
if (!$ok) {
    fwrite(STDERR, "RESTORE FAILED: {$msg}\n");
    exit(1);
}

echo str_repeat('=', 132) . "\n";
printf(
    "%-4s | %-4s %-6s %-4s %-4s | %-4s %-4s %-4s | %-5s %-5s %-5s | %-4s %-4s %-4s %-4s %-4s | %s\n",
    'Sess',
    'Gen',
    'Unfbef',
    'Fill',
    'Rep',
    'Asg',
    'Sout',
    'Wgh',
    'Load',
    'Empt',
    'Ordr',
    'NYd',
    'Shen',
    'SYd',
    'Scul',
    'Nev',
    'Notes'
);
echo str_repeat('-', 132) . "\n";

$prev_gen = null;
for ($run = 1; $run <= $sessions; $run++) {
    $res = session_run_recipe($dbc, $recipe, [
        'from_step' => 1,
        'reset_output' => ($run === 1),
        'format' => 'all',
    ]);
    $sess = (int) ($res['session'] ?? session_get_db_session($dbc));

    $gen = 0;
    $unf_before = null;
    $fill = 0;
    $rep = 0;
    $asg = 0;
    $sout = 0;
    $wgh = 0;
    $gen_skips = [];
    foreach ($res['log'] ?? [] as $e) {
        if (!is_array($e)) {
            continue;
        }
        if (array_key_exists('generated', $e)) {
            $gen += (int) $e['generated'];
            if (isset($e['unfilled_before'])) {
                $unf_before = (int) $e['unfilled_before'];
            }
            if (!empty($e['skipped']) && !empty($e['reason'])) {
                $gen_skips[] = (string) $e['reason'];
            }
        }
        if (array_key_exists('filled', $e)) {
            $fill += (int) $e['filled'];
        }
        if (array_key_exists('repositioned', $e)) {
            $rep += (int) $e['repositioned'];
        }
        if (array_key_exists('assigned', $e)) {
            $asg += (int) $e['assigned'];
        }
        if (array_key_exists('set_out', $e)) {
            $sout += (int) $e['set_out'];
        }
        if (isset($e['weigh']) && is_array($e['weigh'])) {
            $wgh += (int) ($e['weigh']['weighed'] ?? 0);
        }
    }

    $load = status_count($dbc, 'Loaded');
    $empt = status_count($dbc, 'Empty');
    $ordr = status_count($dbc, 'Ordered');
    $open = open_orders($dbc);
    $unfilled_now = generate_orders_count_unfilled($dbc);

    $notes = [];
    if (!empty($gen_skips)) {
        $notes[] = 'GEN SKIPPED (' . $gen_skips[0] . ')';
    } elseif ($gen === 0) {
        $notes[] = 'gen=0';
    }
    if ($unfilled_now > 0) {
        $notes[] = 'unfilled=' . $unfilled_now;
    }

    printf(
        "%-4d | %-4d %-6s %-4d %-4d | %-4d %-4d %-4d | %-5d %-5d %-5d | %-4d %-4d %-4d %-4d %-4d | %s\n",
        $sess,
        $gen,
        $unf_before === null ? '-' : (string) $unf_before,
        $fill,
        $rep,
        $asg,
        $sout,
        $wgh,
        $load,
        $empt,
        $ordr,
        station_count($dbc, 11),
        station_count($dbc, 12),
        station_count($dbc, 8),
        station_count($dbc, 9),
        station_count($dbc, 3),
        implode('; ', $notes)
    );
}

echo str_repeat('=', 132) . "\n";
echo "Legend: Gen=orders generated, Unfbef=unfilled before gen, Fill=orders filled, Rep=empties repositioned,\n";
echo "        Asg=cars assigned, Sout=cars set out, Wgh=weighed. Load/Empt/Ordr=car status totals.\n";
echo "        NYd=North Yard(11), Shen=Shenango(12), SYd=South Yard(8), Scul=Scully(9), Nev=Neville(3).\n";
echo "        open_orders(last)=" . open_orders($dbc) . "\n";

mysqli_close($dbc);
