#!/usr/bin/env php
<?php
/**
 * One-shot: calibrate scale and stage BO31000 + WLE621 on CK1 for manual weigh testing.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

chdir(__DIR__);
require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/warm_start_helpers.php';
require_once __DIR__ . '/track_scale_helpers.php';

$dbc = open_db();
$session = 10;
warm_start_set_session($dbc, $session);

$seed_path = track_scale_seed_path();
if (is_readable($seed_path)) {
    $seed = json_decode(file_get_contents($seed_path), true);
    if (is_array($seed)) {
        $seed['session_number'] = $session;
        $seed['car_weights'] = [];
        $seed['logged_cars'] = [];
        file_put_contents($seed_path, json_encode($seed, JSON_PRETTY_PRINT) . "\n");
    }
}

track_scale_sync_session_calibration($dbc);
if (!track_scale_is_calibration_locked($dbc)) {
    warm_start_simulate_scale_calibration($dbc);
}

$ck1_id = warm_start_job_id($dbc, 'CK1');
if ($ck1_id <= 0) {
    fwrite(STDERR, "CK1 job not found.\n");
    exit(1);
}

$assignments = [
    'BO31000' => ['waybill' => '009-037', 'position' => 1],
    'WLE621' => ['waybill' => '009-038', 'position' => 2],
];

foreach ($assignments as $marks => $info) {
    $car = track_scale_lookup_car($dbc, $marks);
    if ($car === null) {
        fwrite(STDERR, "Car not found: {$marks}\n");
        exit(1);
    }
    $car_id = (int) $car['id'];
    $waybill = mysqli_real_escape_string($dbc, $info['waybill']);
    $position = (int) $info['position'];

    mysqli_query($dbc, 'UPDATE car_orders SET car = 0 WHERE car = "' . $car_id . '"');
    mysqli_query(
        $dbc,
        'UPDATE car_orders SET car = "' . $car_id . '" WHERE waybill_number = "' . $waybill . '"'
    );
    mysqli_query(
        $dbc,
        'UPDATE cars SET status = "Loaded", handled_by_job_id = "' . (int) $ck1_id . '", '
        . 'current_location_id = 0, position = "' . $position . '" WHERE id = "' . $car_id . '"'
    );
    fwrite(STDOUT, "Staged {$marks} on CK1 pos {$position} ({$info['waybill']})\n");
}

fwrite(STDOUT, "Session {$session}, scale calibrated, CK1 ready for weigh testing.\n");
mysqli_close($dbc);
