#!/usr/bin/env php
<?php
/**
 * Weigh CK1 outbound coke cars on the train (same logic as track_scale_ajax weigh).
 *
 * Usage: php simulate_ck1_weigh.php [--marks=BO31000,WLE621]
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the command line only.\n");
    exit(1);
}

chdir(__DIR__);
require_once __DIR__ . '/open_db.php';
require_once __DIR__ . '/track_scale_helpers.php';

$options = getopt('', ['marks::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php simulate_ck1_weigh.php [--marks=BO31000,WLE621]\n");
    exit(0);
}

$dbc = open_db();
$config = track_scale_load_config();
track_scale_sync_session_calibration($dbc);

if (track_scale_is_out_of_service($dbc, $config)) {
    fwrite(STDERR, "Scale out of service — calibrate first.\n");
    exit(1);
}

$marks_list = [];
if (!empty($options['marks'])) {
    $marks_list = array_filter(array_map('trim', explode(',', strtoupper($options['marks']))));
} else {
    $ck1_rs = mysqli_query($dbc, 'SELECT id FROM jobs WHERE name = "CK1" LIMIT 1');
    $ck1 = mysqli_fetch_array($ck1_rs);
    $ck1_id = $ck1 ? (int) $ck1['id'] : 0;
    if ($ck1_id <= 0) {
        fwrite(STDERR, "CK1 job not found.\n");
        exit(1);
    }
    $sql = 'SELECT cars.reporting_marks
            FROM cars
            INNER JOIN car_orders ON car_orders.car = cars.id
            INNER JOIN shipments ON shipments.id = car_orders.shipment
            WHERE cars.handled_by_job_id = "' . $ck1_id . '"
              AND cars.current_location_id = 0
              AND cars.status = "Loaded"
              AND shipments.code IN ("COKE-USS", "COKE-CLEV", "COKE-USS-BULK", "COKE-CLEV-BULK")
            ORDER BY cars.id';
    $rs = mysqli_query($dbc, $sql);
    while ($row = mysqli_fetch_array($rs)) {
        $marks_list[] = strtoupper(trim($row['reporting_marks']));
    }
}

if (count($marks_list) === 0) {
    fwrite(STDERR, "No CK1 outbound coke cars on train to weigh.\n");
    exit(1);
}

$weighed = 0;
foreach ($marks_list as $reporting_marks) {
    $car = track_scale_lookup_car($dbc, $reporting_marks);
    if ($car === null) {
        fwrite(STDERR, "Car not found: {$reporting_marks}\n");
        continue;
    }
    if (!track_scale_car_weighable($car, $dbc, $config)) {
        fwrite(STDERR, track_scale_weighable_car_error($car, $config) . "\n");
        continue;
    }

    $profile = track_scale_profile_for_marks($reporting_marks, $config);
    if (!empty($profile['tare_only'])) {
        fwrite(STDERR, "Skipping test/tare car: {$reporting_marks}\n");
        continue;
    }

    $target_net = (float) $profile['target_net_tons'];
    $tare = (float) $profile['tare_tons'];
    $load = track_scale_get_car_load_state($dbc, $reporting_marks, $target_net, $config);
    $reading = track_scale_build_display_weighing(
        (float) $load['true_net_tons'],
        $tare,
        $target_net,
        $config,
        (float) ($load['balance_shift_tons'] ?? 0.0)
    );
    $reading['unloaded_weigh'] = false;
    track_scale_record_weigh_log($dbc, $reporting_marks, $reading, $config);

    fwrite(STDOUT, sprintf(
        "%s: true=%.2f display=%.2f target=%.2f balance_delta=%.2f shift=%.2f routing=%s\n",
        $reporting_marks,
        $reading['true_net_tons'],
        $reading['net_tons'],
        $reading['target_net_tons'],
        $reading['delta_tons'],
        $reading['balance_shift_tons'] ?? 0.0,
        $reading['routing']
    ));
    $weighed++;
}

fwrite(STDOUT, "Weighed {$weighed} car(s). Session log updated.\n");
mysqli_close($dbc);
exit($weighed > 0 ? 0 : 1);
