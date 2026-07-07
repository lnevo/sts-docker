<?php
/**
 * Simulate several operating sessions to produce a balanced warm-start state.
 * Uses the same fill / reposition / assign / pickup / setout / load-unload rules as STS ops pages.
 */

require_once __DIR__ . '/fill_order_helpers.php';
require_once __DIR__ . '/drop_down_list_functions.php';
require_once __DIR__ . '/backup_tables.php';

function warm_start_coke_stats_reset()
{
    return [
        'reloads' => 0,
        'outbound_assignments' => 0,
        'complete_deliveries' => 0,
        'weighed' => 0,
    ];
}

function warm_start_coke_stats_init()
{
    $stats = &warm_start_coke_stats();
    foreach (warm_start_coke_stats_reset() as $key => $value) {
        $stats[$key] = $value;
    }
}

function warm_start_outbound_coke_shipment_codes()
{
    return ['COKE-USS', 'COKE-CLEV', 'COKE-USS-BULK', 'COKE-CLEV-BULK'];
}

function warm_start_is_outbound_coke_shipment($shipment_code)
{
    return in_array((string) $shipment_code, warm_start_outbound_coke_shipment_codes(), true);
}

function &warm_start_coke_stats()
{
    static $stats = null;
    if ($stats === null) {
        $stats = warm_start_coke_stats_reset();
    }
    return $stats;
}

function warm_start_default_config()
{
    return [
        'seed' => 42,
        'completed_sessions' => 3,
        'min_sessions' => 3,
        'max_sessions' => 12,
        'stop_when_staging_ready' => true,
        'stop_when_locals_secured' => false,
        'stop_when_stg_scully_ready' => false,
        'run_ck1_test' => false,
        'run_ck1_each_session' => false,
        'secure_locals_each_session' => false,
        'run_phased_locals' => false,
        'staging_after_locals' => false,
        'max_unfilled_before_generate' => 30,
        'staging_jobs' => ['STG-SCULLY', 'STG-DEMMLER'],
        'partial' => [
            'fill_orders' => 0.88,
            'reposition' => 0.65,
            'auto_assign' => 0.75,
            'pickup' => 0.55,
            'setout' => 0.65,
            'load_unload' => 0.80,
        ],
        'reposition_fraction' => 0.65,
        'scale_calibrate_every_sessions' => 3,
        'ck1_test' => [
            'weigh_required' => true,
            'min_outbound_on_train' => 1,
        ],
    ];
}

function warm_start_merge_config($overrides)
{
    $config = warm_start_default_config();
    if (isset($overrides['seed'])) {
        $config['seed'] = (int) $overrides['seed'];
    }
    if (isset($overrides['completed_sessions'])) {
        $config['completed_sessions'] = max(1, (int) $overrides['completed_sessions']);
        if (!isset($overrides['min_sessions'])) {
            $config['min_sessions'] = $config['completed_sessions'];
        }
    }
    if (isset($overrides['min_sessions'])) {
        $config['min_sessions'] = max(1, (int) $overrides['min_sessions']);
    }
    if (isset($overrides['max_sessions'])) {
        $config['max_sessions'] = max(1, (int) $overrides['max_sessions']);
    }
    if (isset($overrides['stop_when_staging_ready'])) {
        $config['stop_when_staging_ready'] = (bool) $overrides['stop_when_staging_ready'];
    }
    if (isset($overrides['stop_when_locals_secured'])) {
        $config['stop_when_locals_secured'] = (bool) $overrides['stop_when_locals_secured'];
    }
    if (isset($overrides['stop_when_stg_scully_ready'])) {
        $config['stop_when_stg_scully_ready'] = (bool) $overrides['stop_when_stg_scully_ready'];
    }
    if (isset($overrides['run_ck1_each_session'])) {
        $config['run_ck1_each_session'] = (bool) $overrides['run_ck1_each_session'];
    }
    if (isset($overrides['secure_locals_each_session'])) {
        $config['secure_locals_each_session'] = (bool) $overrides['secure_locals_each_session'];
    }
    if (isset($overrides['run_phased_locals'])) {
        $config['run_phased_locals'] = (bool) $overrides['run_phased_locals'];
    }
    if (isset($overrides['staging_after_locals'])) {
        $config['staging_after_locals'] = (bool) $overrides['staging_after_locals'];
    }
    if (isset($overrides['run_ck1_test'])) {
        $config['run_ck1_test'] = (bool) $overrides['run_ck1_test'];
    }
    if (isset($overrides['max_unfilled_before_generate'])) {
        $config['max_unfilled_before_generate'] = max(0, (int) $overrides['max_unfilled_before_generate']);
    }
    if (isset($overrides['staging_jobs']) && is_array($overrides['staging_jobs'])) {
        $config['staging_jobs'] = $overrides['staging_jobs'];
    }
    if (isset($overrides['partial']) && is_array($overrides['partial'])) {
        $config['partial'] = array_merge($config['partial'], $overrides['partial']);
    }
    if (isset($overrides['continue_from_current'])) {
        $config['continue_from_current'] = (bool) $overrides['continue_from_current'];
    }
    if (isset($overrides['reseed'])) {
        $config['reseed'] = (bool) $overrides['reseed'];
    }
    if (isset($overrides['reposition_fraction'])) {
        $config['reposition_fraction'] = max(0.0, min(1.0, (float) $overrides['reposition_fraction']));
    }
    if (isset($overrides['ck1_test']) && is_array($overrides['ck1_test'])) {
        $config['ck1_test'] = array_merge($config['ck1_test'] ?? [], $overrides['ck1_test']);
    }
    return $config;
}

function warm_start_coke_stats_copy()
{
    $stats = warm_start_coke_stats();
    return [
        'reloads' => (int) ($stats['reloads'] ?? 0),
        'outbound_assignments' => (int) ($stats['outbound_assignments'] ?? 0),
        'complete_deliveries' => (int) ($stats['complete_deliveries'] ?? 0),
        'weighed' => (int) ($stats['weighed'] ?? 0),
    ];
}

function warm_start_coke_stats_delta(array $before, array $after)
{
    return [
        'deliveries' => max(0, $after['complete_deliveries'] - $before['complete_deliveries']),
        'reloads' => max(0, $after['reloads'] - $before['reloads']),
        'weighed' => max(0, $after['weighed'] - $before['weighed']),
        'outbound' => max(0, $after['outbound_assignments'] - $before['outbound_assignments']),
    ];
}

function warm_start_location_station($dbc, $location_id)
{
    $location_id = (int) $location_id;
    if ($location_id <= 0) {
        return 0;
    }
    $rs = mysqli_query($dbc, 'SELECT station FROM locations WHERE id = "' . $location_id . '" LIMIT 1');
    $row = mysqli_fetch_array($rs);
    return $row ? (int) $row['station'] : 0;
}

require_once __DIR__ . '/warm_start_session_stats.php';

function warm_start_pending_pickup_ids($dbc, $job_name)
{
    return array_keys(auto_assign_eligible_car_ids_for_job($dbc, $job_name, true));
}

function warm_start_car_order_targets_station($dbc, $car_id, $station_id)
{
    $car_id = (int) $car_id;
    $station_id = (int) $station_id;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.status,
                car_orders.waybill_number,
                car_orders.shipment,
                load_loc.station AS load_station,
                unload_loc.station AS unload_station,
                repo_loc.station AS repo_station
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         LEFT JOIN shipments ON shipments.id = car_orders.shipment
         LEFT JOIN locations load_loc ON load_loc.id = shipments.loading_location
         LEFT JOIN locations unload_loc ON unload_loc.id = shipments.unloading_location
         LEFT JOIN locations repo_loc ON repo_loc.id = car_orders.shipment
         WHERE cars.id = "' . $car_id . '"
         LIMIT 1'
    );
    $row = mysqli_fetch_array($rs);
    if (!$row) {
        return false;
    }

    if (strpos((string) $row['waybill_number'], 'E') !== false) {
        return (int) ($row['repo_station'] ?? 0) === $station_id;
    }
    if ($row['status'] === 'Ordered') {
        return (int) ($row['load_station'] ?? 0) === $station_id;
    }
    if ($row['status'] === 'Loaded') {
        return (int) ($row['unload_station'] ?? 0) === $station_id;
    }

    return false;
}

function warm_start_count_shenango_pickup_for_ck1($dbc)
{
    $shenango_station = 12;
    $seen = [];
    foreach (['NVL', 'D749'] as $job_name) {
        foreach (warm_start_pending_pickup_ids($dbc, $job_name) as $car_id) {
            if (warm_start_car_order_targets_station($dbc, $car_id, $shenango_station)) {
                $seen[(int) $car_id] = true;
            }
        }
    }
    return count($seen);
}

function warm_start_count_loaded_coke_at_shenango($dbc)
{
    if (!is_readable(__DIR__ . '/track_scale_helpers.php')) {
        return 0;
    }
    require_once __DIR__ . '/track_scale_helpers.php';
    $config = track_scale_load_config();

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = 12
           AND cars.current_location_id > 0
           AND cars.handled_by_job_id = 0
           AND cars.status = "Loaded"
         ORDER BY cars.id'
    );
    $count = 0;
    while ($row = mysqli_fetch_array($rs)) {
        if (track_scale_car_in_coke_fleet($dbc, (int) $row['car_id'], $config)) {
            $count++;
        }
    }
    return $count;
}

function warm_start_count_ck1_on_train($dbc)
{
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return 0;
    }
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0
           AND cars.status = "Loaded"
           AND shipments.code IN ("COKE-USS", "COKE-CLEV", "COKE-USS-BULK", "COKE-CLEV-BULK")'
    );
    return (int) mysqli_fetch_array($rs)['c'];
}

function warm_start_count_unfilled($dbc)
{
    $rs = mysqli_query($dbc, 'SELECT COUNT(*) AS c FROM car_orders WHERE car = "" OR car IS NULL OR car = "0"');
    $row = mysqli_fetch_array($rs);
    return (int) $row['c'];
}

function warm_start_cars_at_station_count($dbc, $station_id, $job_id = 0)
{
    $job_filter = $job_id > 0 ? ' AND cars.handled_by_job_id = "' . (int) $job_id . '"' : '';
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = "' . (int) $station_id . '"
           AND cars.current_location_id > 0' . $job_filter
    );
    return (int) mysqli_fetch_array($rs)['c'];
}

function warm_start_terminate_job_at_yard($dbc, $job_name, $yard_location_id)
{
    if ($yard_location_id <= 0) {
        return 0;
    }
    warm_start_pickup_job($dbc, $job_name);
    return warm_start_setout_job_at_location($dbc, $job_name, $yard_location_id);
}

function warm_start_phased_local_job_names()
{
    return ['NVL', 'D749', 'CK1'];
}

function warm_start_car_targets_any_station($dbc, $car_id, array $station_ids)
{
    foreach ($station_ids as $station_id) {
        if (warm_start_car_order_targets_station($dbc, $car_id, (int) $station_id)) {
            return true;
        }
    }
    return false;
}

function warm_start_eligible_car_ids_for_criterion($dbc, $job_name, $step_nbr)
{
    $car_ids = [];
    $job_name_esc = mysqli_real_escape_string($dbc, $job_name);
    $step_nbr = (int) $step_nbr;

    $crit_rs = mysqli_query(
        $dbc,
        'SELECT dest_station_id, car_status
         FROM pu_criteria
         WHERE job_id = "' . $job_name_esc . '"
           AND step_nbr = ' . $step_nbr
    );
    if (!$crit_rs) {
        return $car_ids;
    }

    $step_rs = mysqli_query(
        $dbc,
        'SELECT station FROM `' . $job_name . '` WHERE step_number = ' . $step_nbr
    );
    if (!$step_rs || mysqli_num_rows($step_rs) === 0) {
        return $car_ids;
    }
    $pickup_station_id = (int) mysqli_fetch_array($step_rs)['station'];

    $pickup_location_ids = [];
    $pickup_rs = mysqli_query($dbc, 'SELECT id FROM locations WHERE station = ' . $pickup_station_id);
    while ($pickup_row = mysqli_fetch_array($pickup_rs)) {
        $pickup_location_ids[] = (int) $pickup_row['id'];
    }
    if (count($pickup_location_ids) === 0) {
        return $car_ids;
    }
    $pickup_location_string = implode(', ', $pickup_location_ids);

    while ($crit = mysqli_fetch_array($crit_rs)) {
        $dest_station_id = (int) $crit['dest_station_id'];
        $dest_location_ids = [];
        $dest_rs = mysqli_query($dbc, 'SELECT id FROM locations WHERE station = ' . $dest_station_id);
        while ($dest_row = mysqli_fetch_array($dest_rs)) {
            $dest_location_ids[] = (int) $dest_row['id'];
        }
        if (count($dest_location_ids) === 0) {
            continue;
        }
        $dest_location_string = implode(', ', $dest_location_ids);

        $sql_revenue = 'SELECT DISTINCT cars.id
                          FROM cars
                          INNER JOIN car_orders ON cars.id = car_orders.car
                          INNER JOIN shipments ON shipments.id = car_orders.shipment
                         WHERE cars.current_location_id IN (' . $pickup_location_string . ')
                           AND cars.handled_by_job_id = 0
                           AND (
                                 (cars.status = "Ordered"
                                  AND car_orders.waybill_number NOT LIKE "%E%"
                                  AND shipments.loading_location IN (' . $dest_location_string . '))
                              OR (cars.status = "Loaded"
                                  AND shipments.unloading_location IN (' . $dest_location_string . '))
                           )';
        $car_rs = mysqli_query($dbc, $sql_revenue);
        if ($car_rs) {
            while ($car_row = mysqli_fetch_array($car_rs)) {
                $car_ids[(int) $car_row['id']] = true;
            }
        }

        $sql_reposition = 'SELECT DISTINCT cars.id
                             FROM cars
                             INNER JOIN car_orders ON cars.id = car_orders.car
                            WHERE cars.current_location_id IN (' . $pickup_location_string . ')
                              AND cars.handled_by_job_id = 0
                              AND cars.status = "Ordered"
                              AND car_orders.waybill_number LIKE "%E%"
                              AND car_orders.shipment IN (' . $dest_location_string . ')';
        $car_rs = mysqli_query($dbc, $sql_reposition);
        if ($car_rs) {
            while ($car_row = mysqli_fetch_array($car_rs)) {
                $car_ids[(int) $car_row['id']] = true;
            }
        }
    }

    return $car_ids;
}

function warm_start_assign_cars_to_job($dbc, $job_name, array $car_ids)
{
    $job_id = warm_start_job_id($dbc, $job_name);
    if ($job_id <= 0) {
        return 0;
    }
    $assigned = 0;
    foreach ($car_ids as $car_id) {
        $car_id = (int) $car_id;
        if ($car_id <= 0) {
            continue;
        }
        if (mysqli_query(
            $dbc,
            'UPDATE cars SET handled_by_job_id = "' . (int) $job_id . '"
             WHERE id = "' . $car_id . '"
               AND handled_by_job_id = 0'
        ) && mysqli_affected_rows($dbc) > 0) {
            $assigned++;
        }
    }
    return $assigned;
}

function warm_start_assign_eligible_at_pickup_station($dbc, $job_name, $pickup_station)
{
    $job_name_esc = mysqli_real_escape_string($dbc, $job_name);
    $pickup_station = (int) $pickup_station;
    $car_ids = [];

    $crit_rs = mysqli_query(
        $dbc,
        'SELECT step_nbr FROM pu_criteria WHERE job_id = "' . $job_name_esc . '"'
    );
    while ($crit_rs && ($crit = mysqli_fetch_array($crit_rs))) {
        $step_nbr = (int) $crit['step_nbr'];
        $step_rs = mysqli_query(
            $dbc,
            'SELECT station FROM `' . $job_name . '` WHERE step_number = ' . $step_nbr
        );
        if (!$step_rs || mysqli_num_rows($step_rs) === 0) {
            continue;
        }
        if ((int) mysqli_fetch_array($step_rs)['station'] !== $pickup_station) {
            continue;
        }
        foreach (array_keys(warm_start_eligible_car_ids_for_criterion($dbc, $job_name, $step_nbr)) as $car_id) {
            $car_ids[$car_id] = true;
        }
    }

    return warm_start_assign_cars_to_job($dbc, $job_name, array_keys($car_ids));
}

function warm_start_pickup_job_at_station($dbc, $job_name, $pickup_station)
{
    $session_number = warm_start_get_session($dbc);
    $job_id = warm_start_job_id($dbc, $job_name);
    if ($job_id <= 0) {
        return 0;
    }
    $pickup_station = (int) $pickup_station;

    $picked_up = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id, cars.current_location_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE cars.handled_by_job_id = "' . (int) $job_id . '"
           AND cars.current_location_id > 0
           AND loc.station = "' . $pickup_station . '"'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        $location = (int) $row['current_location_id'];
        if (!mysqli_query($dbc, 'UPDATE cars SET current_location_id = 0 WHERE id = "' . $car_id . '"')) {
            continue;
        }
        mysqli_query(
            $dbc,
            'INSERT INTO history(car_id, session_nbr, event_date, event, location)
             VALUES ("' . $car_id . '",
                     "' . $session_number . '",
                     "' . date('Y-m-d H:i:s') . '",
                     "Picked up by Job ' . mysqli_real_escape_string($dbc, $job_name) . '",
                     "' . $location . '")'
        );
        warm_start_record_job_pickup($dbc, $job_name, $location);
        $picked_up++;
    }
    return $picked_up;
}

function warm_start_setout_single_car($dbc, $car_id, $job_name, $location_id)
{
    $session_number = warm_start_get_session($dbc);
    $car_id = (int) $car_id;
    $location_id = (int) $location_id;
    if ($car_id <= 0 || $location_id <= 0) {
        return false;
    }
    if (!mysqli_query(
        $dbc,
        'UPDATE cars
         SET current_location_id = "' . $location_id . '",
             handled_by_job_id = 0,
             position = 0
         WHERE id = "' . $car_id . '"
           AND handled_by_job_id > 0
           AND current_location_id = 0'
    ) || mysqli_affected_rows($dbc) === 0) {
        return false;
    }
    mysqli_query(
        $dbc,
        'INSERT INTO history(car_id, session_nbr, event_date, event, location)
         VALUES ("' . $car_id . '",
                 "' . $session_number . '",
                 "' . date('Y-m-d H:i:s') . '",
                 "Set out by Job ' . mysqli_real_escape_string($dbc, $job_name) . '",
                 "' . $location_id . '")'
    );
    warm_start_apply_setout_transitions($dbc, $car_id, $session_number);
    warm_start_record_job_setout($dbc, $job_name, $location_id);
    return true;
}

function warm_start_setout_job_cars_for_destinations($dbc, $job_name, array $dest_stations)
{
    $job_id = warm_start_job_id($dbc, $job_name);
    if ($job_id <= 0) {
        return 0;
    }
    $dest_stations = array_map('intval', $dest_stations);

    $set_out = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         WHERE cars.handled_by_job_id = "' . (int) $job_id . '"
           AND cars.current_location_id = 0'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!warm_start_car_targets_any_station($dbc, $car_id, $dest_stations)) {
            continue;
        }
        $target = warm_start_target_setout_location($dbc, $car_id);
        if ($target <= 0) {
            continue;
        }
        if (warm_start_setout_single_car($dbc, $car_id, $job_name, $target)) {
            $set_out++;
        }
    }
    return $set_out;
}

function warm_start_setout_all_job_train($dbc, $job_name)
{
    $job_id = warm_start_job_id($dbc, $job_name);
    if ($job_id <= 0) {
        return 0;
    }
    $set_out = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         WHERE cars.handled_by_job_id = "' . (int) $job_id . '"
           AND cars.current_location_id = 0'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        $target = warm_start_target_setout_location($dbc, $car_id);
        if ($target <= 0) {
            continue;
        }
        if (warm_start_setout_single_car($dbc, $car_id, $job_name, $target)) {
            $set_out++;
        }
    }
    return $set_out;
}

function warm_start_run_job_criterion($dbc, $job_name, $step_nbr)
{
    $step_nbr = (int) $step_nbr;
    $job_name_esc = mysqli_real_escape_string($dbc, $job_name);
    $eligible = array_keys(warm_start_eligible_car_ids_for_criterion($dbc, $job_name, $step_nbr));
    $assigned = warm_start_assign_cars_to_job($dbc, $job_name, $eligible);

    $pickup_station = 0;
    $step_rs = mysqli_query(
        $dbc,
        'SELECT station FROM `' . $job_name . '` WHERE step_number = ' . $step_nbr
    );
    if ($step_rs && mysqli_num_rows($step_rs) > 0) {
        $pickup_station = (int) mysqli_fetch_array($step_rs)['station'];
    }
    $picked_up = $pickup_station > 0
        ? warm_start_pickup_job_at_station($dbc, $job_name, $pickup_station)
        : warm_start_pickup_job($dbc, $job_name);

    $dest_stations = [];
    $crit_rs = mysqli_query(
        $dbc,
        'SELECT dest_station_id FROM pu_criteria
         WHERE job_id = "' . $job_name_esc . '" AND step_nbr = ' . $step_nbr
    );
    while ($crit_rs && ($crit = mysqli_fetch_array($crit_rs))) {
        $dest_stations[] = (int) $crit['dest_station_id'];
    }
    $set_out = count($dest_stations) > 0
        ? warm_start_setout_job_cars_for_destinations($dbc, $job_name, $dest_stations)
        : warm_start_setout_all_job_train($dbc, $job_name);

    return compact('assigned', 'picked_up', 'set_out');
}

function warm_start_assign_cars_at_station_for_destinations($dbc, $job_name, $pickup_station, array $dest_stations)
{
    $pickup_station = (int) $pickup_station;
    $dest_stations = array_map('intval', $dest_stations);
    $job_id = warm_start_job_id($dbc, $job_name);
    if ($job_id <= 0) {
        return 0;
    }

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = "' . $pickup_station . '"
           AND cars.current_location_id > 0
           AND cars.handled_by_job_id = 0'
    );
    $to_assign = [];
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (warm_start_car_targets_any_station($dbc, $car_id, $dest_stations)) {
            $to_assign[] = $car_id;
        }
    }
    return warm_start_assign_cars_to_job($dbc, $job_name, $to_assign);
}

function warm_start_nvl_pickup_scully_from_ck1($dbc)
{
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    $scully_dests = [9, 15];
    $south_station = 8;

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id, cars.handled_by_job_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = "' . $south_station . '"
           AND cars.current_location_id > 0
           AND (cars.handled_by_job_id = 0'
        . ($ck1_id > 0 ? ' OR cars.handled_by_job_id = "' . (int) $ck1_id . '"' : '')
        . ')'
    );

    $car_ids = [];
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!warm_start_car_targets_any_station($dbc, $car_id, $scully_dests)) {
            continue;
        }
        $car_ids[] = $car_id;
    }

    $assigned = warm_start_assign_cars_to_job($dbc, 'NVL', $car_ids);
    $picked_up = warm_start_pickup_job_at_station($dbc, 'NVL', $south_station);
    return compact('assigned', 'picked_up');
}

function warm_start_d749_pickup_island_demmler_outbound($dbc)
{
    $demmler_dests = [10, 14];
    $island_station = 3;

    $assigned = warm_start_assign_cars_at_station_for_destinations(
        $dbc,
        'D749',
        $island_station,
        $demmler_dests
    );
    $picked_up = warm_start_pickup_job_at_station($dbc, 'D749', $island_station);
    $set_out = warm_start_setout_job_cars_for_destinations($dbc, 'D749', $demmler_dests);
    return compact('assigned', 'picked_up', 'set_out');
}

function warm_start_run_nvl_pre_ck1($dbc)
{
    $demmler_dests = [10, 14];

    $assigned = warm_start_assign_eligible_at_pickup_station($dbc, 'NVL', 9);
    $picked_up = warm_start_pickup_job_at_station($dbc, 'NVL', 9);
    $demmler_setout = warm_start_setout_job_cars_for_destinations($dbc, 'NVL', $demmler_dests);

    return compact('assigned', 'picked_up', 'demmler_setout');
}

function warm_start_run_nvl_post_ck1($dbc)
{
    $island_dests = [3, 12];
    $scully_dests = [9, 15];
    $demmler_dests = [10, 14];

    $ck1_handoff = warm_start_nvl_pickup_scully_from_ck1($dbc);
    $island_setout = warm_start_setout_job_cars_for_destinations($dbc, 'NVL', $island_dests);

    $criterion_steps = [50, 60, 70, 75, 80, 85, 90, 95, 100, 110];
    $criterion_moves = 0;
    foreach ($criterion_steps as $step_nbr) {
        $move = warm_start_run_job_criterion($dbc, 'NVL', $step_nbr);
        $criterion_moves += (int) $move['set_out'];
    }

    $demmler_setout = warm_start_setout_job_cars_for_destinations($dbc, 'NVL', $demmler_dests);
    $scully_setout = warm_start_setout_job_cars_for_destinations($dbc, 'NVL', $scully_dests);
    $remaining = warm_start_setout_all_job_train($dbc, 'NVL');

    return array_merge(
        compact('island_setout', 'criterion_moves', 'demmler_setout', 'scully_setout', 'remaining'),
        ['ck1_handoff' => $ck1_handoff]
    );
}

function warm_start_run_d749_phased_ops($dbc)
{
    $stats = ['south' => 0, 'demmler' => 0, 'island_demmler' => []];

    foreach ([10, 15, 20] as $step_nbr) {
        $move = warm_start_run_job_criterion($dbc, 'D749', $step_nbr);
        $stats['south'] += (int) $move['set_out'];
    }
    foreach ([30, 35, 40, 50, 60] as $step_nbr) {
        $move = warm_start_run_job_criterion($dbc, 'D749', $step_nbr);
        $stats['demmler'] += (int) $move['set_out'];
    }
    $stats['island_demmler'] = warm_start_d749_pickup_island_demmler_outbound($dbc);
    $stats['remaining'] = warm_start_setout_all_job_train($dbc, 'D749');

    return $stats;
}

function warm_start_run_pending_stg_scully($dbc, $config, $load_unload_fraction = 1.0)
{
    if (empty($config['stop_when_stg_scully_ready']) && empty($config['secure_locals_each_session'])) {
        return ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0, 'cleared' => 0];
    }

    $backlog = warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
    if (empty($backlog['ready'])) {
        return ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0, 'cleared' => 0];
    }

    $stats = warm_start_complete_staging_jobs($dbc, ['STG-SCULLY'], $config, $load_unload_fraction);
    $stats['cleared'] = (int) ($stats['set_out'] ?? 0);
    return $stats;
}

function warm_start_secure_locals_at_session_end($dbc, $config = [], $load_unload_fraction = 1.0, $run_stg_scully = true)
{
    $scl_id = warm_start_location_id_by_code($dbc, 'SCL');

    $stg_demmler = warm_start_complete_staging_jobs($dbc, ['STG-DEMMLER'], $config, $load_unload_fraction);

    warm_start_assign_all_ordered_cars_at_station($dbc, 'D749', 10);
    $d749 = warm_start_pickup_job_at_station($dbc, 'D749', 10);

    warm_start_assign_eligible_at_pickup_station($dbc, 'NVL', 9);
    warm_start_pickup_job_at_station($dbc, 'NVL', 9);
    warm_start_setout_all_job_train($dbc, 'NVL');
    $nvl = $scl_id > 0 ? warm_start_setout_job_at_location($dbc, 'NVL', $scl_id) : 0;

    $stg_scully = ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0, 'deferred' => false];
    if ($run_stg_scully) {
        $stg_scully = array_merge(
            warm_start_complete_staging_jobs($dbc, ['STG-SCULLY'], $config, $load_unload_fraction),
            ['deferred' => false]
        );
    } else {
        $stg_scully['deferred'] = true;
    }

    return [
        'd749' => $d749,
        'nvl' => $nvl,
        'stg_demmler' => $stg_demmler,
        'stg_scully' => $stg_scully,
    ];
}

function warm_start_locals_secured($dbc, $config = [], $session_snapshot = null)
{
    unset($session_snapshot);
    if (warm_start_non_staging_work_remaining($dbc, $config) > 0) {
        return false;
    }

    foreach (['NVL'] as $job_name) {
        $job_id = warm_start_job_id($dbc, $job_name);
        if ($job_id <= 0) {
            continue;
        }
        $rs = mysqli_query(
            $dbc,
            'SELECT COUNT(*) AS c FROM cars WHERE handled_by_job_id = "' . (int) $job_id . '" AND current_location_id = 0'
        );
        if ((int) mysqli_fetch_array($rs)['c'] > 0) {
            return false;
        }
    }

    return true;
}

function warm_start_ck1_reload_shipment_codes($dbc)
{
    if (!is_readable(__DIR__ . '/track_scale_helpers.php')) {
        return ['COKE-RELOAD-SHEN'];
    }
    require_once __DIR__ . '/track_scale_helpers.php';
    $config = track_scale_load_config();
    return track_scale_shipment_codes_for_routing('reload', $config);
}

function warm_start_ck1_assign_reload_cars_on_train($dbc)
{
    if (!is_readable(__DIR__ . '/track_scale_helpers.php')) {
        return 0;
    }
    require_once __DIR__ . '/track_scale_helpers.php';

    $config = track_scale_load_config();
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return 0;
    }

    $reload_codes = warm_start_ck1_reload_shipment_codes($dbc);
    $quoted = implode(', ', array_map(function ($code) {
        return '"' . addslashes($code) . '"';
    }, $reload_codes));
    if ($quoted === '') {
        return 0;
    }

    $assigned = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0
           AND shipments.code IN (' . $quoted . ')'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            continue;
        }
        $assigned++;
    }
    return $assigned;
}

function warm_start_ck1_setout_train_cars_for_shipments($dbc, $location_id, array $shipment_codes)
{
    if ($location_id <= 0 || count($shipment_codes) === 0) {
        return 0;
    }
    require_once __DIR__ . '/track_scale_helpers.php';
    $config = track_scale_load_config();
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return 0;
    }

    $quoted = implode(', ', array_map(function ($code) {
        return '"' . addslashes($code) . '"';
    }, $shipment_codes));

    $set_out = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0
           AND shipments.code IN (' . $quoted . ')'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            continue;
        }
        if (warm_start_setout_single_car($dbc, $car_id, 'CK1', $location_id)) {
            $set_out++;
        }
    }
    return $set_out;
}

function warm_start_ck1_assign_reload_at_south($dbc)
{
    if (!is_readable(__DIR__ . '/track_scale_helpers.php')) {
        return 0;
    }
    require_once __DIR__ . '/track_scale_helpers.php';

    $config = track_scale_load_config();
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return 0;
    }

    $reload_codes = warm_start_ck1_reload_shipment_codes($dbc);
    if (count($reload_codes) === 0) {
        return 0;
    }
    $quoted = implode(', ', array_map(function ($code) {
        return '"' . addslashes($code) . '"';
    }, $reload_codes));

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE loc.station = 8
           AND cars.current_location_id > 0
           AND cars.handled_by_job_id = 0
           AND shipments.code IN (' . $quoted . ')'
    );

    $assigned = 0;
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            continue;
        }
        if (mysqli_query(
            $dbc,
            'UPDATE cars SET handled_by_job_id = "' . (int) $ck1_id . '" WHERE id = "' . $car_id . '"'
        )) {
            $assigned++;
        }
    }
    return $assigned;
}

function warm_start_ck1_assign_outbound_at_south($dbc)
{
    if (!is_readable(__DIR__ . '/track_scale_helpers.php')) {
        return 0;
    }
    require_once __DIR__ . '/track_scale_helpers.php';

    $config = track_scale_load_config();
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return 0;
    }

    $outbound_codes = warm_start_outbound_coke_shipment_codes();
    if (count($outbound_codes) === 0) {
        return 0;
    }
    $quoted = implode(', ', array_map(function ($code) {
        return '"' . addslashes($code) . '"';
    }, $outbound_codes));

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE loc.station = 8
           AND cars.current_location_id > 0
           AND cars.handled_by_job_id = 0
           AND shipments.code IN (' . $quoted . ')'
    );

    $assigned = 0;
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            continue;
        }
        if (mysqli_query(
            $dbc,
            'UPDATE cars SET handled_by_job_id = "' . (int) $ck1_id . '" WHERE id = "' . $car_id . '"'
        )) {
            $assigned++;
        }
    }
    return $assigned;
}

function warm_start_ck1_setout_train_to_location($dbc, $location_id)
{
    $session_number = warm_start_get_session($dbc);
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0 || $location_id <= 0) {
        return 0;
    }

    $set_out = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!mysqli_query(
            $dbc,
            'UPDATE cars
             SET current_location_id = "' . (int) $location_id . '",
                 handled_by_job_id = 0,
                 position = 0
             WHERE id = "' . $car_id . '"'
        )) {
            continue;
        }
        mysqli_query(
            $dbc,
            'INSERT INTO history(car_id, session_nbr, event_date, event, location)
             VALUES ("' . $car_id . '",
                     "' . $session_number . '",
                     "' . date('Y-m-d H:i:s') . '",
                     "Set out by Job CK1",
                     "' . (int) $location_id . '")'
        );
        warm_start_record_job_setout($dbc, 'CK1', $location_id);
        warm_start_apply_setout_transitions($dbc, $car_id, $session_number);
        $set_out++;
    }
    return $set_out;
}

function warm_start_run_ck1_session_ops($dbc, $config = [])
{
    $shenango_id = warm_start_location_id_by_code($dbc, 'NIL-SHEN-COKE');
    $south_id = warm_start_location_id_by_code($dbc, 'SOUTH');
    $scale_id = warm_start_location_id_by_code($dbc, 'SOUTH-SCALE');
    $spot_id = $scale_id > 0 ? $scale_id : $south_id;
    $stats = [
        'reload_assigned' => 0,
        'reload_picked' => 0,
        'reload_setout' => 0,
        'loaded_assigned' => 0,
        'loaded_picked' => 0,
        'weighed' => 0,
        'reloads' => 0,
        'outbound' => 0,
        'south_setout' => 0,
    ];

    warm_start_load_unload($dbc, 1.0);

    $stats['loaded_assigned'] = warm_start_auto_assign_job_at_station($dbc, 'CK1', 12, 1.0);
    $stats['loaded_picked'] = warm_start_pickup_job_at_station($dbc, 'CK1', 12);

    if ($spot_id > 0) {
        warm_start_pickup_job($dbc, 'CK1');
        warm_start_ck1_setout_train_to_location($dbc, $spot_id);
    }

    warm_start_maybe_calibrate_scale($dbc, $config);

    $weigh = warm_start_run_ck1_scale_ops($dbc);
    $stats['weighed'] = (int) ($weigh['weighed'] ?? 0);
    $stats['reloads'] = (int) ($weigh['reloads'] ?? 0);
    $stats['outbound'] = (int) ($weigh['outbound_assignments'] ?? 0);

    $s = &warm_start_session_stats_current();
    $s['CK1']['weighed'] = $stats['weighed'];
    $s['CK1']['reloads'] = $stats['reloads'];
    $s['CK1']['outbound'] = $stats['outbound'];

    $stats['reload_assigned'] = warm_start_ck1_assign_reload_cars_on_train($dbc);
    $stats['reload_assigned'] += warm_start_ck1_assign_reload_at_south($dbc);
    $stats['reload_assigned'] += warm_start_ck1_assign_outbound_at_south($dbc);
    $stats['reload_picked'] = warm_start_pickup_job($dbc, 'CK1');
    if ($shenango_id > 0) {
        $stats['reload_setout'] = warm_start_ck1_setout_train_cars_for_shipments(
            $dbc,
            $shenango_id,
            warm_start_ck1_reload_shipment_codes($dbc)
        );
        warm_start_load_unload($dbc, 1.0);
    }

    if ($south_id > 0) {
        warm_start_pickup_job($dbc, 'CK1');
        $stats['south_setout'] = warm_start_ck1_setout_train_cars_for_shipments(
            $dbc,
            $south_id,
            warm_start_outbound_coke_shipment_codes()
        );
        $remaining = warm_start_setout_all_job_train($dbc, 'CK1');
        $stats['south_setout'] += $remaining;
    }

    return array_merge($stats, ['weigh' => $weigh]);
}

/**
 * Advance one operating session: optionally generate orders, run ops.
 * Skips generation when more than max_unfilled_before_generate orders are open.
 */
function warm_start_advance_session($dbc, $fractions, $label, $max_unfilled, $finish_non_staging_only = false, $config = [], $run_staging = true)
{
    $coke_before = warm_start_coke_stats_copy();
    warm_start_session_stats_reset();
    $unfilled = warm_start_count_unfilled($dbc);
    $session = warm_start_get_session($dbc) + 1;
    warm_start_set_session($dbc, $session);
    $suffix = $label !== '' ? " ({$label})" : '';
    $load_unload_fraction = $fractions['load_unload'] ?? 1.0;
    $pending_stg_scully = null;

    if (!empty($config['stop_when_stg_scully_ready']) || !empty($config['secure_locals_each_session'])) {
        $pending_stg_scully = warm_start_run_pending_stg_scully($dbc, $config, $load_unload_fraction);
        if (($pending_stg_scully['set_out'] ?? 0) > 0) {
            warm_start_log(
                "Session {$session}{$suffix} start: pending STG-SCULLY moves="
                . ($pending_stg_scully['set_out'] ?? 0)
            );
        }
    }

    if ($unfilled > $max_unfilled) {
        warm_start_log(
            "Session {$session}{$suffix}: skipping generate ({$unfilled} unfilled > {$max_unfilled})"
        );
    } else {
        $waybill_counter = warm_start_get_next_auto_waybill_counter($dbc, $session);
        $generated = warm_start_generate_orders($dbc, $session, $waybill_counter);
        warm_start_log("Session {$session}{$suffix}: generated {$generated} orders");
    }

    if (!empty($config['secure_locals_each_session'])) {
        $d749_start = warm_start_run_d749_session_start($dbc);
        warm_start_log(
            "Session {$session}{$suffix} start: D749 Demmler pu={$d749_start['picked_up']} South so={$d749_start['set_out']}"
        );
    }

    $phased_locals = !empty($config['run_phased_locals']);
    $local_jobs = $phased_locals ? warm_start_phased_local_job_names() : [];

    if ($phased_locals) {
        $stats = warm_start_run_ops_cycle($dbc, $fractions, false, $config, true, $local_jobs);
        $stats['load_unload'] += warm_start_load_unload($dbc, $fractions['load_unload']);

        $nvl_pre = warm_start_run_nvl_pre_ck1($dbc);
        $stats['nvl_pre'] = $nvl_pre;
        warm_start_log(
            "Session {$session}{$suffix} NVL pre-CK1: scully_pu={$nvl_pre['picked_up']} demmler_so={$nvl_pre['demmler_setout']}"
        );
    } else {
        $stats = warm_start_run_ops_cycle($dbc, $fractions, false, $config, true);
    }

    warm_start_log(
        "Session {$session}{$suffix}: filled={$stats['filled']} repo={$stats['repositioned']} assigned={$stats['assigned']} "
        . "pickup={$stats['picked_up']} setout={$stats['set_out']} load/unload={$stats['load_unload']}"
    );

    if (!empty($config['run_ck1_each_session'])) {
        $ck1 = warm_start_run_ck1_session_ops($dbc, $config);
        $stats['ck1'] = $ck1;
        warm_start_log(
            "Session {$session}{$suffix} CK1: reload_pu={$ck1['reload_picked']} shenango_so={$ck1['reload_setout']} "
            . "loaded_pu={$ck1['loaded_picked']} weighed={$ck1['weighed']} reloads={$ck1['reloads']} south_so={$ck1['south_setout']}"
        );
    }

    if ($phased_locals) {
        $nvl_post = warm_start_run_nvl_post_ck1($dbc);
        $stats['nvl_post'] = $nvl_post;
        warm_start_log(
            "Session {$session}{$suffix} NVL post-CK1: ck1_scully_pu={$nvl_post['ck1_handoff']['picked_up']} "
            . "island_so={$nvl_post['island_setout']} scully_so={$nvl_post['scully_setout']}"
        );

        $d749_ops = warm_start_run_d749_phased_ops($dbc);
        $stats['d749_phased'] = $d749_ops;
        warm_start_log(
            "Session {$session}{$suffix} D749: island→Demmler pu={$d749_ops['island_demmler']['picked_up']} "
            . "so={$d749_ops['island_demmler']['set_out']} remaining_so={$d749_ops['remaining']}"
        );

        $stats['load_unload'] += warm_start_load_unload($dbc, $fractions['load_unload']);
    }

    warm_start_finish_non_staging_jobs($dbc, $config, $fractions);

    if (!empty($config['secure_locals_each_session'])) {
        $load_unload_fraction = $fractions['load_unload'] ?? 1.0;
        $stats['stg_scully_stop_ready'] = false;
        $stats['stg_scully_backlog'] = ['eligible' => 0, 'on_jobs' => 0, 'ready' => false];

        if (!empty($config['stop_when_stg_scully_ready'])) {
            $secured = warm_start_secure_locals_at_session_end($dbc, $config, $load_unload_fraction, false);
            $scully_backlog = warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
            $min_sessions = (int) ($config['min_sessions'] ?? 3);
            $stop_from_session = (int) ($config['stg_scully_stop_from_session'] ?? $min_sessions);
            if ($session >= $stop_from_session && !empty($scully_backlog['ready'])) {
                $stats['stg_scully_stop_ready'] = true;
                $stats['stg_scully_backlog'] = $scully_backlog;
            } else {
                $stg_scully = warm_start_complete_staging_jobs(
                    $dbc,
                    ['STG-SCULLY'],
                    $config,
                    $load_unload_fraction
                );
                $secured['stg_scully'] = array_merge($stg_scully, ['deferred' => false]);
            }
        } else {
            $secured = warm_start_secure_locals_at_session_end($dbc, $config, $load_unload_fraction, true);
        }

        $stats['load_unload'] = ($stats['load_unload'] ?? 0)
            + (int) ($secured['stg_demmler']['load_unload'] ?? 0)
            + (int) ($secured['stg_scully']['load_unload'] ?? 0);
        $stg_scully_note = !empty($secured['stg_scully']['deferred'])
            ? 'STG-SCULLY deferred (pending)'
            : 'STG-SCULLY moves=' . ($secured['stg_scully']['set_out'] ?? 0);
        warm_start_log(
            "Session {$session}{$suffix}: bookend STG-DEMMLER moves="
            . ($secured['stg_demmler']['set_out'] ?? 0)
            . " D749 Demmler pu={$secured['d749']} (on train) NVL→Scully={$secured['nvl']} "
            . $stg_scully_note
        );
        if (!empty($stats['stg_scully_stop_ready'])) {
            warm_start_log(
                "Session {$session}{$suffix}: STG-SCULLY ready — "
                . ($stats['stg_scully_backlog']['eligible'] ?? 0)
                . ' car(s) at Scully awaiting assignment'
            );
        }
    }

    $staging = ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0];
    if ($run_staging && empty($config['secure_locals_each_session'])) {
        $load_unload_fraction = $fractions['load_unload'] ?? 1.0;
        $staging = warm_start_complete_staging($dbc, $config, $load_unload_fraction);
        $stats['load_unload'] = ($stats['load_unload'] ?? 0) + (int) ($staging['load_unload'] ?? 0);
        warm_start_log(
            "Session {$session}{$suffix} staging: assigned={$staging['assigned']} pickup={$staging['picked_up']} "
            . "setout={$staging['set_out']} load/unload={$staging['load_unload']}"
        );
    } elseif (empty($config['secure_locals_each_session']) && empty($config['run_ck1_each_session'])) {
        warm_start_log("Session {$session}{$suffix}: staging deferred");
    }

    $snapshot = warm_start_session_stats_commit($session, $label, $coke_before);
    $n = $snapshot['NVL'];
    $d = $snapshot['D749'];
    $c = $snapshot['CK1'];
    warm_start_log(
        "Session {$session}{$suffix} stats: NVL PuS={$n['pu_scully']} D749 PuDEM={$d['pu_demmler']} "
        . "CK1 PuSh={$c['pu_shenango']} Rld={$c['reloads']} Del={$snapshot['coke_deliveries']}"
    );

    return array_merge($stats, [
        'staging' => $staging,
        'session_stats' => $snapshot,
        'locals_secured' => warm_start_locals_secured($dbc, $config, $snapshot),
        'stg_scully_stop_ready' => !empty($stats['stg_scully_stop_ready']),
        'stg_scully_backlog' => $stats['stg_scully_backlog'] ?? ['eligible' => 0, 'on_jobs' => 0, 'ready' => false],
        'pending_stg_scully' => $pending_stg_scully,
    ]);
}

function warm_start_ck1_outbound_cars_detail($dbc)
{
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return [];
    }

    $codes = warm_start_outbound_coke_shipment_codes();
    $quoted = implode(', ', array_map(function ($code) {
        return '"' . addslashes($code) . '"';
    }, $codes));

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id,
                cars.reporting_marks,
                cars.status,
                cars.current_location_id,
                shipments.code AS shipment_code,
                car_orders.waybill_number
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND shipments.code IN (' . $quoted . ')
         ORDER BY cars.id'
    );

    $cars = [];
    while ($row = mysqli_fetch_array($rs)) {
        $on_train = ((int) $row['current_location_id'] === 0);
        $cars[] = [
            'car_id' => (int) $row['car_id'],
            'reporting_marks' => $row['reporting_marks'],
            'status' => $row['status'],
            'on_train' => $on_train,
            'shipment_code' => $row['shipment_code'],
            'waybill_number' => $row['waybill_number'],
        ];
    }
    return $cars;
}

function warm_start_log_ck1_outbound_cars($dbc, $session)
{
    $cars = warm_start_ck1_outbound_cars_detail($dbc);
    if (count($cars) === 0) {
        warm_start_log("Session {$session}: no CK1 outbound coke cars assigned");
        return;
    }

    warm_start_log("Session {$session}: CK1 outbound coke cars");
    foreach ($cars as $car) {
        $where = $car['on_train'] ? 'on train' : 'spotted';
        warm_start_log(sprintf(
            '  %s id=%d %s %s wb=%s (%s)',
            $car['reporting_marks'],
            $car['car_id'],
            $car['status'],
            $car['shipment_code'],
            $car['waybill_number'],
            $where
        ));
    }
}

function warm_start_staging_job_names($dbc, $config)
{
    $configured = $config['staging_jobs'] ?? ['STG-SCULLY', 'STG-DEMMLER'];
    return array_values(array_filter(array_map('strval', $configured)));
}

function warm_start_is_staging_job($job_name, $staging_jobs)
{
    return in_array($job_name, $staging_jobs, true);
}

function warm_start_location_id_by_code($dbc, $code)
{
    $code = mysqli_real_escape_string($dbc, $code);
    $rs = mysqli_query($dbc, 'SELECT id FROM locations WHERE code = "' . $code . '" LIMIT 1');
    $row = mysqli_fetch_array($rs);
    return $row ? (int) $row['id'] : 0;
}

function warm_start_non_staging_job_filter_sql($staging_jobs)
{
    if (count($staging_jobs) === 0) {
        return '';
    }
    $quoted = array_map(function ($name) {
        return '"' . addslashes($name) . '"';
    }, $staging_jobs);
    return ' AND jobs.name NOT IN (' . implode(', ', $quoted) . ')';
}

function warm_start_non_staging_work_remaining($dbc, $config)
{
    $staging_jobs = warm_start_staging_job_names($dbc, $config);
    $filter = warm_start_non_staging_job_filter_sql($staging_jobs);

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN jobs ON jobs.id = cars.handled_by_job_id
         WHERE cars.handled_by_job_id > 0' . $filter
    );
    return (int) mysqli_fetch_array($rs)['c'];
}

/**
 * Move interchange-bound cars onto home yards so staging jobs can pick them up.
 */
function warm_start_prep_staging_yards($dbc, $config)
{
    $moved = 0;
    $scl_id = warm_start_location_id_by_code($dbc, 'SCL');
    $dem_id = warm_start_location_id_by_code($dbc, 'DEM');
    if ($scl_id <= 0 || $dem_id <= 0) {
        return 0;
    }

    $yard_by_offline_station = [
        15 => $scl_id,
        14 => $dem_id,
    ];

    foreach ($yard_by_offline_station as $offline_station => $yard_id) {
        $sql = 'UPDATE cars
                INNER JOIN car_orders ON car_orders.car = cars.id
                INNER JOIN shipments ON shipments.id = car_orders.shipment
                INNER JOIN locations unload_loc ON unload_loc.id = shipments.unloading_location
                INNER JOIN locations cur_loc ON cur_loc.id = cars.current_location_id
                SET cars.current_location_id = "' . (int) $yard_id . '",
                    cars.handled_by_job_id = 0,
                    cars.position = 0
                WHERE unload_loc.station = ' . (int) $offline_station . '
                  AND cur_loc.station != ' . (int) ($offline_station === 15 ? 9 : 10) . '
                  AND cars.current_location_id > 0
                  AND cars.status IN ("Loaded", "Ordered")
                  AND car_orders.waybill_number NOT LIKE "%E%"';
        mysqli_query($dbc, $sql);
        $moved += mysqli_affected_rows($dbc);
    }

    $staging_jobs = warm_start_staging_job_names($dbc, $config);
    foreach ($staging_jobs as $job_name) {
        $yard_code = $job_name === 'STG-SCULLY' ? 'SCL' : 'DEM';
        $yard_id = warm_start_location_id_by_code($dbc, $yard_code);
        $pickup_station = $job_name === 'STG-SCULLY' ? 9 : 10;
        if ($yard_id <= 0) {
            continue;
        }

        // Only move offline-bound interchange cars onto the yard — never pull island industry cars.
        $eligible = array_keys(auto_assign_eligible_car_ids_for_job($dbc, $job_name, false));
        foreach ($eligible as $car_id) {
            $rs = mysqli_query(
                $dbc,
                'SELECT cars.current_location_id, cur.station, unload_loc.station AS unload_station
                 FROM cars
                 LEFT JOIN locations cur ON cur.id = cars.current_location_id
                 LEFT JOIN car_orders ON car_orders.car = cars.id
                 LEFT JOIN shipments ON shipments.id = car_orders.shipment
                 LEFT JOIN locations unload_loc ON unload_loc.id = shipments.unloading_location
                 WHERE cars.id = "' . (int) $car_id . '"
                 LIMIT 1'
            );
            $row = mysqli_fetch_array($rs);
            if (!$row || (int) $row['station'] === $pickup_station) {
                continue;
            }
            if ((int) $row['unload_station'] !== ($job_name === 'STG-SCULLY' ? 15 : 14)) {
                continue;
            }
            if ((int) $row['station'] === 3) {
                continue;
            }
            if (mysqli_query(
                $dbc,
                'UPDATE cars
                 SET current_location_id = "' . (int) $yard_id . '",
                     handled_by_job_id = 0,
                     position = 0
                 WHERE id = "' . (int) $car_id . '"'
            )) {
                $moved++;
            }
        }
    }

    return $moved;
}

function warm_start_auto_assign_staging_only($dbc, $staging_jobs, $fraction = 1.0)
{
    $assigned = 0;
    foreach ($staging_jobs as $job_name) {
        $job_name_esc = mysqli_real_escape_string($dbc, $job_name);
        $rs = mysqli_query($dbc, 'SELECT id FROM jobs WHERE name = "' . $job_name_esc . '" LIMIT 1');
        $job = mysqli_fetch_array($rs);
        if (!$job) {
            continue;
        }

        $eligible = array_keys(auto_assign_eligible_car_ids_for_job($dbc, $job_name, true));
        shuffle($eligible);
        $limit = (int) ceil(count($eligible) * max(0.0, min(1.0, $fraction)));

        for ($i = 0; $i < $limit; $i++) {
            $car_id = (int) $eligible[$i];
            if (mysqli_query(
                $dbc,
                'UPDATE cars SET handled_by_job_id = "' . (int) $job['id'] . '" WHERE id = "' . $car_id . '"'
            )) {
                $assigned++;
            }
        }
    }

    return $assigned;
}

function warm_start_finish_non_staging_jobs($dbc, $config, $fractions)
{
    $pass = 0;
    $max_passes = 12;
    while ($pass < $max_passes) {
        $remaining = warm_start_non_staging_work_remaining($dbc, $config);
        if ($remaining <= 0) {
            break;
        }

        $pass++;
        $cycle_fractions = array_merge($fractions, [
            'auto_assign' => 1.0,
            'pickup' => 1.0,
            'setout' => 1.0,
        ]);
        $stats = warm_start_run_ops_cycle($dbc, $cycle_fractions, true, $config, true);
        warm_start_log(
            "Finish non-staging pass {$pass} ({$remaining} remaining): assigned={$stats['assigned']} "
            . "pickup={$stats['picked_up']} setout={$stats['set_out']}"
        );
    }
}

function warm_start_cars_on_staging_jobs($dbc, $staging_jobs)
{
    if (count($staging_jobs) === 0) {
        return 0;
    }
    $quoted = implode(', ', array_map(function ($name) {
        return '"' . addslashes($name) . '"';
    }, $staging_jobs));
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN jobs ON jobs.id = cars.handled_by_job_id
         WHERE jobs.name IN (' . $quoted . ')
           AND cars.handled_by_job_id > 0'
    );
    return (int) mysqli_fetch_array($rs)['c'];
}

function warm_start_staging_backlog_for_job($dbc, $job_name, $config)
{
    warm_start_prep_staging_yards($dbc, $config);
    $eligible = count(auto_assign_eligible_car_ids_for_job($dbc, $job_name, true));
    $on_jobs = warm_start_cars_on_staging_jobs($dbc, [$job_name]);

    return [
        'job' => $job_name,
        'eligible' => $eligible,
        'on_jobs' => $on_jobs,
        'ready' => ($eligible > 0 || $on_jobs > 0),
    ];
}

function warm_start_staging_backlog($dbc, $config)
{
    warm_start_prep_staging_yards($dbc, $config);
    $staging_jobs = warm_start_staging_job_names($dbc, $config);
    $eligible = 0;
    foreach ($staging_jobs as $job_name) {
        $eligible += count(auto_assign_eligible_car_ids_for_job($dbc, $job_name, true));
    }
    $on_jobs = warm_start_cars_on_staging_jobs($dbc, $staging_jobs);

    return [
        'eligible' => $eligible,
        'on_jobs' => $on_jobs,
        'ready' => ($eligible > 0 || $on_jobs > 0),
    ];
}

function warm_start_staging_setout_location($dbc, $car_id)
{
    $sql = 'SELECT cars.status,
                   car_orders.waybill_number,
                   car_orders.shipment,
                   shipments.loading_location,
                   shipments.unloading_location
            FROM cars
            LEFT JOIN car_orders ON car_orders.car = cars.id
            LEFT JOIN shipments ON shipments.id = car_orders.shipment
            WHERE cars.id = "' . (int) $car_id . '"
            LIMIT 1';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    if (!$row || !$row['waybill_number']) {
        return null;
    }

    if (strpos($row['waybill_number'], 'E') !== false) {
        return (int) $row['shipment'];
    }
    if ($row['status'] === 'Ordered') {
        return (int) $row['loading_location'];
    }
    if ($row['status'] === 'Loaded') {
        return (int) $row['unloading_location'];
    }

    return null;
}

function warm_start_pickup_staging_only($dbc, $staging_jobs)
{
    $session_number = warm_start_get_session($dbc);
    if (count($staging_jobs) === 0) {
        return 0;
    }

    $quoted = implode(', ', array_map(function ($name) {
        return '"' . addslashes($name) . '"';
    }, $staging_jobs));
    $picked_up = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id, cars.current_location_id, jobs.name AS job_name
         FROM cars
         INNER JOIN jobs ON jobs.id = cars.handled_by_job_id
         WHERE jobs.name IN (' . $quoted . ')
           AND cars.current_location_id > 0'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        $location = (int) $row['current_location_id'];
        if (!mysqli_query($dbc, 'UPDATE cars SET current_location_id = 0 WHERE id = "' . $car_id . '"')) {
            continue;
        }
        mysqli_query(
            $dbc,
            'INSERT INTO history(car_id, session_nbr, event_date, event, location)
             VALUES ("' . $car_id . '",
                     "' . $session_number . '",
                     "' . date('Y-m-d H:i:s') . '",
                     "Picked up by Job ' . mysqli_real_escape_string($dbc, $row['job_name']) . '",
                     "' . $location . '")'
        );
        $picked_up++;
    }

    return $picked_up;
}

function warm_start_setout_staging_only($dbc, $staging_jobs)
{
    $session_number = warm_start_get_session($dbc);
    if (count($staging_jobs) === 0) {
        return 0;
    }

    $quoted = implode(', ', array_map(function ($name) {
        return '"' . addslashes($name) . '"';
    }, $staging_jobs));
    $set_out = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id, jobs.name AS job_name
         FROM cars
         INNER JOIN jobs ON jobs.id = cars.handled_by_job_id
         WHERE jobs.name IN (' . $quoted . ')
           AND cars.current_location_id = 0'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        $location_id = warm_start_staging_setout_location($dbc, $car_id);
        if ($location_id === null || $location_id <= 0) {
            continue;
        }
        if (!mysqli_query(
            $dbc,
            'UPDATE cars
             SET current_location_id = "' . (int) $location_id . '",
                 handled_by_job_id = 0,
                 position = 0
             WHERE id = "' . $car_id . '"'
        )) {
            continue;
        }
        mysqli_query(
            $dbc,
            'INSERT INTO history(car_id, session_nbr, event_date, event, location)
             VALUES ("' . $car_id . '",
                     "' . $session_number . '",
                     "' . date('Y-m-d H:i:s') . '",
                     "Set out by Job ' . mysqli_real_escape_string($dbc, $row['job_name']) . '",
                     "' . (int) $location_id . '")'
        );
        warm_start_apply_setout_transitions($dbc, $car_id, $session_number);
        $set_out++;
    }

    return $set_out;
}

/**
 * Run end-of-session staging until interchange yards are clear and cars are on offline tracks.
 */
function warm_start_complete_staging_jobs($dbc, array $staging_jobs, $config, $load_unload_fraction = 1.0)
{
    $staging_jobs = array_values(array_filter(array_map('strval', $staging_jobs)));
    $stats = ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0];
    if (count($staging_jobs) === 0) {
        return $stats;
    }

    $max_passes = 20;
    for ($pass = 1; $pass <= $max_passes; $pass++) {
        warm_start_prep_staging_yards($dbc, $config);

        $eligible = 0;
        foreach ($staging_jobs as $job_name) {
            $eligible += count(auto_assign_eligible_car_ids_for_job($dbc, $job_name, true));
        }
        $on_jobs = warm_start_cars_on_staging_jobs($dbc, $staging_jobs);
        if ($eligible === 0 && $on_jobs === 0) {
            break;
        }

        $stats['assigned'] += warm_start_auto_assign_staging_only($dbc, $staging_jobs, 1.0);
        $stats['picked_up'] += warm_start_pickup_staging_only($dbc, $staging_jobs);
        $stats['set_out'] += warm_start_setout_staging_only($dbc, $staging_jobs);
        $stats['load_unload'] += warm_start_load_unload($dbc, $load_unload_fraction);
    }

    $stats['load_unload'] += warm_start_load_unload($dbc, $load_unload_fraction);

    return $stats;
}

function warm_start_complete_staging($dbc, $config, $load_unload_fraction = 1.0)
{
    return warm_start_complete_staging_jobs(
        $dbc,
        warm_start_staging_job_names($dbc, $config),
        $config,
        $load_unload_fraction
    );
}

function warm_start_job_id($dbc, $job_name)
{
    $job_name_esc = mysqli_real_escape_string($dbc, $job_name);
    $rs = mysqli_query($dbc, 'SELECT id FROM jobs WHERE name = "' . $job_name_esc . '" LIMIT 1');
    $row = mysqli_fetch_array($rs);
    return $row ? (int) $row['id'] : 0;
}

function warm_start_car_at_station($dbc, $car_id, $station_id)
{
    $rs = mysqli_query(
        $dbc,
        'SELECT loc.station
         FROM cars
         LEFT JOIN locations loc ON loc.id = cars.current_location_id
         WHERE cars.id = "' . (int) $car_id . '"'
    );
    $row = mysqli_fetch_array($rs);
    return $row && (int) $row['station'] === (int) $station_id;
}

function warm_start_auto_assign_job_at_station($dbc, $job_name, $station_id, $fraction = 1.0)
{
    $job_id = warm_start_job_id($dbc, $job_name);
    if ($job_id <= 0) {
        return 0;
    }

    $eligible = array_keys(auto_assign_eligible_car_ids_for_job($dbc, $job_name, true));
    shuffle($eligible);
    $limit = (int) ceil(count($eligible) * max(0.0, min(1.0, $fraction)));
    $assigned = 0;

    foreach ($eligible as $index => $car_id) {
        if ($index >= $limit) {
            break;
        }
        if (!warm_start_car_at_station($dbc, $car_id, $station_id)) {
            continue;
        }
        if (mysqli_query(
            $dbc,
            'UPDATE cars SET handled_by_job_id = "' . $job_id . '" WHERE id = "' . (int) $car_id . '"'
        )) {
            $assigned++;
        }
    }

    return $assigned;
}

function warm_start_pickup_job($dbc, $job_name)
{
    $session_number = warm_start_get_session($dbc);
    $job_id = warm_start_job_id($dbc, $job_name);
    if ($job_id <= 0) {
        return 0;
    }

    $picked_up = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id, cars.current_location_id
         FROM cars
         WHERE cars.handled_by_job_id = "' . $job_id . '"
           AND cars.current_location_id > 0'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        $location = (int) $row['current_location_id'];
        if (!mysqli_query($dbc, 'UPDATE cars SET current_location_id = 0 WHERE id = "' . $car_id . '"')) {
            continue;
        }
        mysqli_query(
            $dbc,
            'INSERT INTO history(car_id, session_nbr, event_date, event, location)
             VALUES ("' . $car_id . '",
                     "' . $session_number . '",
                     "' . date('Y-m-d H:i:s') . '",
                     "Picked up by Job ' . mysqli_real_escape_string($dbc, $job_name) . '",
                     "' . $location . '")'
        );
        warm_start_record_job_pickup($dbc, $job_name, $location);
        $picked_up++;
    }

    return $picked_up;
}

function warm_start_setout_job_at_location($dbc, $job_name, $location_id)
{
    $session_number = warm_start_get_session($dbc);
    $job_id = warm_start_job_id($dbc, $job_name);
    if ($job_id <= 0 || $location_id <= 0) {
        return 0;
    }

    $set_out = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         WHERE cars.handled_by_job_id = "' . $job_id . '"
           AND cars.current_location_id = 0'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!mysqli_query(
            $dbc,
            'UPDATE cars
             SET current_location_id = "' . (int) $location_id . '",
                 handled_by_job_id = 0,
                 position = 0
             WHERE id = "' . $car_id . '"'
        )) {
            continue;
        }
        mysqli_query(
            $dbc,
            'INSERT INTO history(car_id, session_nbr, event_date, event, location)
             VALUES ("' . $car_id . '",
                     "' . $session_number . '",
                     "' . date('Y-m-d H:i:s') . '",
                     "Set out by Job ' . mysqli_real_escape_string($dbc, $job_name) . '",
                     "' . (int) $location_id . '")'
        );
        warm_start_apply_setout_transitions($dbc, $car_id, $session_number);
        warm_start_record_job_setout($dbc, $job_name, $location_id);
        $set_out++;
    }

    return $set_out;
}

function warm_start_assign_all_ordered_cars_at_station($dbc, $job_name, $station_id)
{
    $job_id = warm_start_job_id($dbc, $job_name);
    if ($job_id <= 0) {
        return 0;
    }

    $assigned = 0;
    $rs = mysqli_query(
        $dbc,
        'SELECT DISTINCT cars.id AS car_id
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = "' . (int) $station_id . '"
           AND cars.current_location_id > 0
           AND cars.handled_by_job_id = 0
           AND car_orders.waybill_number IS NOT NULL
           AND car_orders.waybill_number != ""'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (mysqli_query(
            $dbc,
            'UPDATE cars SET handled_by_job_id = "' . $job_id . '" WHERE id = "' . $car_id . '"'
        )) {
            $assigned++;
        }
    }

    return $assigned;
}

function warm_start_seed_island_spotting($dbc, $target_count)
{
    if ($target_count <= 0) {
        return 0;
    }

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         LEFT JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = 3
           AND cars.current_location_id > 0'
    );
    $have = (int) mysqli_fetch_array($rs)['c'];
    if ($have >= $target_count) {
        return 0;
    }

    $need = $target_count - $have;
    $sql = 'SELECT cars.id AS car_id,
                   cars.status,
                   shipments.loading_location,
                   shipments.unloading_location,
                   load_loc.station AS load_station,
                   unload_loc.station AS unload_station
            FROM cars
            INNER JOIN car_orders ON car_orders.car = cars.id
            INNER JOIN shipments ON shipments.id = car_orders.shipment
            LEFT JOIN locations load_loc ON load_loc.id = shipments.loading_location
            LEFT JOIN locations unload_loc ON unload_loc.id = shipments.unloading_location
            LEFT JOIN locations cur_loc ON cur_loc.id = cars.current_location_id
            WHERE (load_loc.station IN (3, 12) OR unload_loc.station IN (3, 12))
              AND (cur_loc.station IS NULL OR cur_loc.station NOT IN (3, 12))
              AND cars.current_location_id > 0
              AND car_orders.waybill_number NOT LIKE "%E%"
            ORDER BY
              CASE cars.status
                WHEN "Unloading" THEN 0
                WHEN "Loaded" THEN 1
                WHEN "Loading" THEN 2
                WHEN "Ordered" THEN 3
                ELSE 4
              END,
              cars.id';

    $rs = mysqli_query($dbc, $sql);
    $candidates = [];
    while ($row = mysqli_fetch_array($rs)) {
        $candidates[] = $row;
    }
    shuffle($candidates);

    $placed = 0;
    for ($i = 0; $i < min($need, count($candidates)); $i++) {
        $row = $candidates[$i];
        $car_id = (int) $row['car_id'];
        $unload_station = (int) $row['unload_station'];
        $load_station = (int) $row['load_station'];

        if (in_array($row['status'], ['Loaded', 'Unloading'], true) && in_array($unload_station, [3, 12], true)) {
            $spot_id = (int) $row['unloading_location'];
            $new_status = 'Unloading';
        } elseif (in_array($load_station, [3, 12], true)) {
            $spot_id = (int) $row['loading_location'];
            $new_status = $row['status'] === 'Ordered' ? 'Ordered' : $row['status'];
        } else {
            continue;
        }
        if ($spot_id <= 0) {
            continue;
        }
        if (mysqli_query(
            $dbc,
            'UPDATE cars
             SET current_location_id = "' . $spot_id . '",
                 handled_by_job_id = 0,
                 position = 0,
                 status = "' . mysqli_real_escape_string($dbc, $new_status) . '"
             WHERE id = "' . $car_id . '"'
        )) {
            $placed++;
        }
    }

    return $placed;
}

function warm_start_run_d749_session_start($dbc)
{
    $south_id = warm_start_location_id_by_code($dbc, 'SOUTH');
    $assigned = warm_start_assign_all_ordered_cars_at_station($dbc, 'D749', 10);
    $picked_up = warm_start_pickup_job($dbc, 'D749');
    $set_out = warm_start_setout_job_at_location($dbc, 'D749', $south_id);
    return compact('assigned', 'picked_up', 'set_out');
}

function warm_start_assign_nvl_scully_block($dbc)
{
    return warm_start_assign_all_ordered_cars_at_station($dbc, 'NVL', 9);
}

/**
 * Final session: CK1 picks up loaded outbound coke, calibrates if needed, weighs — ready for manual follow-up.
 */
function warm_start_begin_ck1_test_session($dbc, $fractions, $label, $max_unfilled, $config)
{
    $coke_before = warm_start_coke_stats_copy();
    warm_start_session_stats_reset();
    $unfilled = warm_start_count_unfilled($dbc);
    $session = warm_start_get_session($dbc) + 1;
    warm_start_set_session($dbc, $session);
    $suffix = $label !== '' ? " ({$label})" : '';

    if ($unfilled > $max_unfilled) {
        warm_start_log(
            "Session {$session}{$suffix}: skipping generate ({$unfilled} unfilled > {$max_unfilled})"
        );
    } else {
        $waybill_counter = warm_start_get_next_auto_waybill_counter($dbc, $session);
        $generated = warm_start_generate_orders($dbc, $session, $waybill_counter);
        warm_start_log("Session {$session}{$suffix}: generated {$generated} orders");
    }

    $reposition_fraction = (float) ($fractions['reposition'] ?? $config['reposition_fraction'] ?? 0.65);
    $filled = warm_start_auto_fill($dbc, $fractions['fill_orders']);
    $repositioned = warm_start_create_reposition_orders($dbc, $reposition_fraction);
    $load_unload = warm_start_load_unload($dbc, $fractions['load_unload']);
    $load_unload += warm_start_load_unload($dbc, $fractions['load_unload']);

    $ck1_assigned = warm_start_auto_assign_job_at_station($dbc, 'CK1', 12, 1.0);
    $ck1_picked = warm_start_pickup_job($dbc, 'CK1');
    $calibration = warm_start_maybe_calibrate_scale($dbc, $config);

    $ck1_id = warm_start_job_id($dbc, 'CK1');
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0
           AND cars.status = "Loaded"
           AND shipments.code IN ("COKE-USS", "COKE-CLEV", "COKE-USS-BULK", "COKE-CLEV-BULK")'
    );
    $on_train = (int) mysqli_fetch_array($rs)['c'];

    warm_start_log(
        "Session {$session}{$suffix}: filled={$filled} repo={$repositioned} load/unload={$load_unload}; "
        . "CK1 outbound assigned={$ck1_assigned} picked up={$ck1_picked} on train={$on_train}"
    );
    if (!empty($calibration['calibrated'])) {
        warm_start_log("Session {$session}{$suffix}: track scale calibrated");
    }

    warm_start_log_ck1_outbound_cars($dbc, $session);

    $ck1_test = $config['ck1_test'] ?? [];
    $min_on_train = (int) ($ck1_test['min_outbound_on_train'] ?? 1);
    $weigh_required = (bool) ($ck1_test['weigh_required'] ?? true);
    $weigh = ['success' => true, 'weighed' => 0, 'reloads' => 0, 'outbound_assignments' => 0, 'errors' => []];

    if ($on_train < $min_on_train) {
        $weigh['success'] = false;
        $weigh['errors'][] = "CK1 has {$on_train} outbound coke on train (need >= {$min_on_train})";
    } elseif ($weigh_required) {
        $weigh = warm_start_run_ck1_scale_ops($dbc);
        warm_start_log(
            "Session {$session}{$suffix}: weighed={$weigh['weighed']} outbound={$weigh['outbound_assignments']} "
            . "reloads={$weigh['reloads']}"
        );
        if (!empty($weigh['errors'])) {
            foreach ($weigh['errors'] as $error) {
                warm_start_log("Session {$session}{$suffix}: WEIGH ERROR — {$error}");
            }
        }
    }

    warm_start_session_stats_commit($session, $label, $coke_before);
    warm_start_log_ck1_outbound_cars($dbc, $session);

    return [
        'filled' => $filled,
        'repositioned' => $repositioned,
        'load_unload' => $load_unload,
        'ck1_assigned' => $ck1_assigned,
        'ck1_picked_up' => $ck1_picked,
        'ck1_on_train' => $on_train,
        'scale_calibrated' => !empty($calibration['calibrated']),
        'weigh' => $weigh,
        'weigh_failed' => empty($weigh['success']),
    ];
}

function warm_start_log($message)
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}

function warm_start_get_session($dbc)
{
    $rs = mysqli_query($dbc, 'SELECT setting_value FROM settings WHERE setting_name = "session_nbr"');
    $row = mysqli_fetch_array($rs);
    return (int) ($row['setting_value'] ?? 0);
}

function warm_start_set_session($dbc, $session_number)
{
    $session_number = (int) $session_number;
    mysqli_query(
        $dbc,
        'UPDATE settings SET setting_value = ' . $session_number . ' WHERE setting_name = "session_nbr"'
    );
    return $session_number;
}

function warm_start_get_next_auto_waybill_counter($dbc, $session_number)
{
    $session_prefix = str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-';
    $session_prefix = mysqli_real_escape_string($dbc, $session_prefix);
    $sql = 'SELECT MAX(CAST(SUBSTR(waybill_number, 5, 3) AS UNSIGNED)) AS max_counter
            FROM car_orders
            WHERE waybill_number LIKE "' . $session_prefix . '___"
              AND SUBSTR(waybill_number, 5, 1) != "M"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    if (!$row || $row['max_counter'] === null) {
        return 0;
    }
    return (int) $row['max_counter'];
}

function warm_start_generate_orders($dbc, $session_number, $waybill_counter = 0)
{
    $orders_created = 0;
    $rs_shipments = mysqli_query(
        $dbc,
        'SELECT id, last_ship_date, min_interval, max_interval, min_amount, max_amount FROM shipments'
    );
    if (!$rs_shipments) {
        return 0;
    }

    while ($row = mysqli_fetch_array($rs_shipments)) {
        $interval = round(mt_rand($row['min_interval'] * 100, $row['max_interval'] * 100) / 100);
        $ship_date = (int) $row['last_ship_date'] + $interval;
        if ($ship_date > $session_number) {
            continue;
        }

        mysqli_query(
            $dbc,
            'UPDATE shipments SET last_ship_date = ' . (int) $session_number . ' WHERE id = "' . (int) $row['id'] . '"'
        );

        $num_cars = round(mt_rand($row['min_amount'] * 100, $row['max_amount'] * 100) / 100);
        for ($i = 0; $i < $num_cars; $i++) {
            $waybill_counter++;
            $wb_nbr = str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-' . str_pad($waybill_counter, 3, '0', STR_PAD_LEFT);
            if (mysqli_query(
                $dbc,
                'INSERT INTO car_orders (waybill_number, shipment, car) VALUES ("' . $wb_nbr . '", "' . (int) $row['id'] . '", "0")'
            )) {
                $orders_created++;
            }
        }
    }

    return $orders_created;
}

function warm_start_auto_fill($dbc, $fraction = 1.0)
{
    $filled = 0;
    $waybills = fill_order_get_unfilled_waybills($dbc);
    shuffle($waybills);
    $limit = (int) ceil(count($waybills) * max(0.0, min(1.0, $fraction)));

    foreach ($waybills as $index => $waybill_number) {
        if ($index >= $limit) {
            break;
        }

        $order_row = fill_order_get_details($dbc, $waybill_number);
        if ($order_row === null) {
            continue;
        }

        $available_cars = fill_order_get_available_cars($dbc, $order_row);
        $selected_car = fill_order_pick_car_for_categories($available_cars, fill_order_valid_categories());
        if ($selected_car === null) {
            continue;
        }

        $result = fill_order_assign_car($dbc, $waybill_number, $selected_car['car_id']);
        if ($result['success']) {
            $filled++;
        }
    }

    return $filled;
}

function warm_start_next_e_waybill_counter($dbc, $session_number)
{
    $prefix = str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-E';
    $prefix = mysqli_real_escape_string($dbc, $prefix);
    $rs = mysqli_query(
        $dbc,
        'SELECT waybill_number FROM car_orders WHERE waybill_number LIKE "' . $prefix . '__" ORDER BY waybill_number DESC LIMIT 1'
    );
    if ($rs && mysqli_num_rows($rs) > 0) {
        $row = mysqli_fetch_row($rs);
        return (int) substr($row[0], -2, 2) + 1;
    }
    return 1;
}

function warm_start_create_reposition_orders($dbc, $fraction = 1.0)
{
    $session_number = warm_start_get_session($dbc);
    $waybill_counter = warm_start_next_e_waybill_counter($dbc, $session_number);
    $created = 0;

    $sql = 'SELECT cars.id AS car_id,
                   cars.current_location_id,
                   cars.home_location,
                   loc.code AS current_code,
                   home.code AS home_code,
                   CASE
                     WHEN cars.current_location_id IN (
                       SELECT unloading_location FROM shipments WHERE unloading_location > 0
                     ) THEN 0
                     ELSE 1
                   END AS sort_key
            FROM cars
            LEFT JOIN locations loc ON loc.id = cars.current_location_id
            LEFT JOIN locations home ON home.id = cars.home_location
            WHERE cars.status = "Empty"
              AND cars.current_location_id > 0
              AND cars.home_location > 0
              AND cars.current_location_id != cars.home_location
              AND cars.id NOT IN (
                    SELECT car FROM car_orders WHERE car IS NOT NULL AND car != "" AND car != "0"
              )
            ORDER BY sort_key, cars.id';

    $rs = mysqli_query($dbc, $sql);
    $candidates = [];
    while ($row = mysqli_fetch_array($rs)) {
        $candidates[] = $row;
    }
    shuffle($candidates);
    $limit = (int) ceil(count($candidates) * max(0.0, min(1.0, $fraction)));

    for ($i = 0; $i < $limit; $i++) {
        $row = $candidates[$i];
        $car_id = (int) $row['car_id'];
        $destination_id = (int) $row['home_location'];
        $wb_nbr = str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-E' . str_pad($waybill_counter, 2, '0', STR_PAD_LEFT);

        if (!mysqli_query(
            $dbc,
            'INSERT INTO car_orders (waybill_number, shipment, car) VALUES ("' . $wb_nbr . '", "' . $destination_id . '", "' . $car_id . '")'
        )) {
            continue;
        }

        mysqli_query($dbc, 'UPDATE cars SET status = "Ordered" WHERE id = "' . $car_id . '"');

        mysqli_query(
            $dbc,
            'INSERT INTO history(car_id, session_nbr, event_date, event, location)
             VALUES ("' . $car_id . '",
                     "' . $session_number . '",
                     "' . date('Y-m-d H:i:s') . '",
                     "Repositioned to ' . mysqli_real_escape_string($dbc, $row['home_code']) . '",
                     "' . (int) $row['current_location_id'] . '")'
        );

        $waybill_counter++;
        $created++;
    }

    return $created;
}

function warm_start_auto_assign_all($dbc, $fraction = 1.0, $staging_jobs = [], $skip_staging = false, $skip_jobs = [])
{
    $assigned = 0;
    $skip_jobs = array_flip(array_map('strval', $skip_jobs));
    $jobs_rs = mysqli_query($dbc, 'SELECT id, name FROM jobs ORDER BY name');
    while ($job = mysqli_fetch_array($jobs_rs)) {
        if (isset($skip_jobs[$job['name']])) {
            continue;
        }
        if ($skip_staging && warm_start_is_staging_job($job['name'], $staging_jobs)) {
            continue;
        }

        $eligible = array_keys(auto_assign_eligible_car_ids_for_job($dbc, $job['name'], true));
        shuffle($eligible);
        $limit = (int) ceil(count($eligible) * max(0.0, min(1.0, $fraction)));

        for ($i = 0; $i < $limit; $i++) {
            $car_id = (int) $eligible[$i];
            if (mysqli_query(
                $dbc,
                'UPDATE cars SET handled_by_job_id = "' . (int) $job['id'] . '" WHERE id = "' . $car_id . '"'
            )) {
                $assigned++;
            }
        }
    }
    return $assigned;
}

function warm_start_pickup_cars($dbc, $fraction = 1.0, $staging_jobs = [], $skip_staging = false, $skip_jobs = [])
{
    $session_number = warm_start_get_session($dbc);
    $picked_up = 0;
    $skip_jobs = array_flip(array_map('strval', $skip_jobs));

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id,
                cars.current_location_id,
                cars.handled_by_job_id,
                jobs.name AS job_name
         FROM cars
         LEFT JOIN jobs ON jobs.id = cars.handled_by_job_id
         WHERE cars.handled_by_job_id > 0
           AND cars.current_location_id > 0
         ORDER BY cars.id'
    );

    $cars = [];
    while ($row = mysqli_fetch_array($rs)) {
        if (isset($skip_jobs[$row['job_name'] ?? ''])) {
            continue;
        }
        if ($skip_staging && warm_start_is_staging_job($row['job_name'] ?? '', $staging_jobs)) {
            continue;
        }
        $cars[] = $row;
    }
    shuffle($cars);
    $limit = (int) ceil(count($cars) * max(0.0, min(1.0, $fraction)));

    for ($i = 0; $i < $limit; $i++) {
        $row = $cars[$i];
        $car_id = (int) $row['car_id'];
        $location = (int) $row['current_location_id'];
        $job_name = $row['job_name'] ?? 'Unknown';

        if (!mysqli_query($dbc, 'UPDATE cars SET current_location_id = 0 WHERE id = "' . $car_id . '"')) {
            continue;
        }

        mysqli_query(
            $dbc,
            'INSERT INTO history(car_id, session_nbr, event_date, event, location)
             VALUES ("' . $car_id . '",
                     "' . $session_number . '",
                     "' . date('Y-m-d H:i:s') . '",
                     "Picked up by Job ' . mysqli_real_escape_string($dbc, $job_name) . '",
                     "' . $location . '")'
        );
        warm_start_record_job_pickup($dbc, $job_name, $location);
        $picked_up++;
    }

    return $picked_up;
}

function warm_start_target_setout_location($dbc, $car_id)
{
    $sql = 'SELECT cars.status,
                   car_orders.waybill_number,
                   car_orders.shipment,
                   shipments.loading_location,
                   shipments.unloading_location
            FROM cars
            LEFT JOIN car_orders ON car_orders.car = cars.id
            LEFT JOIN shipments ON shipments.id = car_orders.shipment
            WHERE cars.id = "' . (int) $car_id . '"
            LIMIT 1';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    if (!$row || !$row['waybill_number']) {
        return null;
    }

    if (strpos($row['waybill_number'], 'E') !== false) {
        return (int) $row['shipment'];
    }
    if ($row['status'] === 'Ordered') {
        return (int) $row['loading_location'];
    }
    if ($row['status'] === 'Loaded') {
        $detail_rs = mysqli_query(
            $dbc,
            'SELECT unload_loc.station AS unload_station
             FROM shipments
             LEFT JOIN locations unload_loc ON unload_loc.id = shipments.unloading_location
             WHERE shipments.id = "' . (int) $row['shipment'] . '"'
        );
        $detail = mysqli_fetch_array($detail_rs);
        $unload_station = (int) ($detail['unload_station'] ?? 0);
        if ($unload_station === 15) {
            return warm_start_location_id_by_code($dbc, 'SCL');
        }
        if ($unload_station === 14) {
            return warm_start_location_id_by_code($dbc, 'DEM');
        }
        return (int) $row['unloading_location'];
    }
    return null;
}

function warm_start_apply_setout_transitions($dbc, $car_id, $session_number)
{
    mysqli_query(
        $dbc,
        'UPDATE cars, car_orders, shipments
         SET cars.status = "Loading",
             cars.last_spotted = "' . (int) $session_number . '"
         WHERE cars.id = "' . (int) $car_id . '"
           AND cars.status = "Ordered"
           AND car_orders.car = cars.id
           AND car_orders.shipment = shipments.id
           AND car_orders.waybill_number NOT LIKE "%E%"
           AND cars.current_location_id = shipments.loading_location'
    );

    mysqli_query(
        $dbc,
        'UPDATE cars, car_orders, shipments
         SET cars.status = "Unloading",
             cars.last_spotted = "' . (int) $session_number . '"
         WHERE cars.id = "' . (int) $car_id . '"
           AND cars.status = "Loaded"
           AND car_orders.car = cars.id
           AND car_orders.shipment = shipments.id
           AND cars.current_location_id = shipments.unloading_location'
    );

    mysqli_query(
        $dbc,
        'UPDATE cars, car_orders
         SET cars.status = "Empty"
         WHERE car_orders.car = cars.id
           AND car_orders.waybill_number LIKE "___-E__"
           AND cars.status = "Ordered"
           AND cars.current_location_id = car_orders.shipment
           AND cars.id = "' . (int) $car_id . '"'
    );

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.status,
                shipments.min_load_time,
                shipments.max_load_time,
                shipments.min_unload_time,
                shipments.max_unload_time
         FROM shipments, car_orders, cars
         WHERE car_orders.shipment = shipments.id
           AND car_orders.car = "' . (int) $car_id . '"
           AND cars.id = "' . (int) $car_id . '"'
    );
    $row = mysqli_fetch_array($rs);
    if (!$row) {
        return;
    }

    $min_load_time = (int) $row['min_load_time'];
    $max_load_time = (int) $row['max_load_time'];
    $min_unload_time = (int) $row['min_unload_time'];
    $max_unload_time = (int) $row['max_unload_time'];

    if ($row['status'] === 'Loading' && ($min_load_time < 0 || $max_load_time < 0)) {
        mysqli_query($dbc, 'UPDATE cars SET status = "Loaded", last_spotted = 0 WHERE id = "' . (int) $car_id . '"');
    }
    if ($row['status'] === 'Unloading' && ($min_unload_time < 0 || $max_unload_time < 0)) {
        mysqli_query($dbc, 'UPDATE cars SET status = "Empty", last_spotted = 0 WHERE id = "' . (int) $car_id . '"');
        mysqli_query($dbc, 'DELETE FROM car_orders WHERE car = "' . (int) $car_id . '"');
    }

    $rs2 = mysqli_query(
        $dbc,
        'SELECT status FROM cars WHERE id = "' . (int) $car_id . '"'
    );
    $status_row = mysqli_fetch_array($rs2);
    if ($status_row && $status_row['status'] === 'Empty') {
        $rs3 = mysqli_query(
            $dbc,
            'SELECT waybill_number FROM car_orders WHERE car = "' . (int) $car_id . '" AND waybill_number LIKE "%E%" LIMIT 1'
        );
        if ($rs3 && mysqli_num_rows($rs3) > 0) {
            mysqli_query($dbc, 'DELETE FROM car_orders WHERE car = "' . (int) $car_id . '"');
        }
    }
}

function warm_start_setout_cars($dbc, $fraction = 1.0, $staging_jobs = [], $skip_staging = false, $skip_jobs = [])
{
    $session_number = warm_start_get_session($dbc);
    $set_out = 0;
    $skip_jobs = array_flip(array_map('strval', $skip_jobs));

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id,
                cars.handled_by_job_id,
                jobs.name AS job_name
         FROM cars
         LEFT JOIN jobs ON jobs.id = cars.handled_by_job_id
         WHERE cars.current_location_id = 0
           AND cars.handled_by_job_id > 0
         ORDER BY cars.id'
    );

    $cars = [];
    while ($row = mysqli_fetch_array($rs)) {
        if (isset($skip_jobs[$row['job_name'] ?? ''])) {
            continue;
        }
        if ($skip_staging && warm_start_is_staging_job($row['job_name'] ?? '', $staging_jobs)) {
            continue;
        }
        $target = warm_start_target_setout_location($dbc, (int) $row['car_id']);
        if ($target > 0) {
            $row['target_location_id'] = $target;
            $cars[] = $row;
        }
    }
    shuffle($cars);
    $limit = (int) ceil(count($cars) * max(0.0, min(1.0, $fraction)));

    for ($i = 0; $i < $limit; $i++) {
        $row = $cars[$i];
        $car_id = (int) $row['car_id'];
        $location_id = (int) $row['target_location_id'];
        $job_name = $row['job_name'] ?? 'Unknown';

        if (!mysqli_query(
            $dbc,
            'UPDATE cars
             SET current_location_id = "' . $location_id . '",
                 handled_by_job_id = 0,
                 position = 0
             WHERE id = "' . $car_id . '"'
        )) {
            continue;
        }

        mysqli_query(
            $dbc,
            'INSERT INTO history(car_id, session_nbr, event_date, event, location)
             VALUES ("' . $car_id . '",
                     "' . $session_number . '",
                     "' . date('Y-m-d H:i:s') . '",
                     "Set out by Job ' . mysqli_real_escape_string($dbc, $job_name) . '",
                     "' . $location_id . '")'
        );

        warm_start_apply_setout_transitions($dbc, $car_id, $session_number);
        warm_start_record_job_setout($dbc, $job_name, $location_id);
        $set_out++;
    }

    return $set_out;
}

function warm_start_load_unload($dbc, $fraction = 1.0)
{
    $session_number = warm_start_get_session($dbc);
    $updated = 0;

    $sql = 'SELECT cars.id AS car_id,
                   cars.status,
                   cars.last_spotted,
                   car_orders.shipment,
                   shipments.min_load_time,
                   shipments.max_load_time,
                   shipments.min_unload_time,
                   shipments.max_unload_time
            FROM cars
            LEFT JOIN car_orders ON car_orders.car = cars.id
            LEFT JOIN shipments ON shipments.id = car_orders.shipment
            WHERE cars.status IN ("Loading", "Unloading")
               OR (cars.status = "Empty"
                   AND car_orders.waybill_number LIKE "%E%"
                   AND cars.current_location_id = car_orders.shipment)
            ORDER BY cars.id';

    $rs = mysqli_query($dbc, $sql);
    $cars = [];
    while ($row = mysqli_fetch_array($rs)) {
        $ready = false;
        if ($row['status'] === 'Loading') {
            $min = (int) $row['min_load_time'];
            $max = (int) $row['max_load_time'];
            $wait = $max > 0 ? mt_rand(max(0, $min), $max) : 0;
            $ready = ((int) $row['last_spotted'] + $wait) <= $session_number;
        } elseif ($row['status'] === 'Unloading') {
            $min = (int) $row['min_unload_time'];
            $max = (int) $row['max_unload_time'];
            $wait = $max > 0 ? mt_rand(max(0, $min), $max) : 0;
            $ready = ((int) $row['last_spotted'] + $wait) <= $session_number;
        } elseif ($row['status'] === 'Empty') {
            $ready = true;
        }

        if ($ready) {
            $cars[] = $row;
        }
    }

    shuffle($cars);
    $limit = (int) ceil(count($cars) * max(0.0, min(1.0, $fraction)));

    for ($i = 0; $i < $limit; $i++) {
        $row = $cars[$i];
        $car_id = (int) $row['car_id'];

        if ($row['status'] === 'Loading') {
            mysqli_query($dbc, 'UPDATE cars SET status = "Loaded", last_spotted = 0 WHERE id = "' . $car_id . '"');
            $updated++;
        } elseif ($row['status'] === 'Unloading' || $row['status'] === 'Empty') {
            if ($row['status'] === 'Unloading') {
                $detail_rs = mysqli_query(
                    $dbc,
                    'SELECT shipments.code AS shipment_code
                     FROM car_orders
                     INNER JOIN shipments ON shipments.id = car_orders.shipment
                     WHERE car_orders.car = "' . $car_id . '"
                     LIMIT 1'
                );
                $detail = mysqli_fetch_array($detail_rs);
                if ($detail && warm_start_is_outbound_coke_shipment($detail['shipment_code'])) {
                    warm_start_coke_stats()['complete_deliveries']++;
                }
            }
            mysqli_query($dbc, 'UPDATE cars SET status = "Empty", last_spotted = 0 WHERE id = "' . $car_id . '"');
            mysqli_query($dbc, 'DELETE FROM car_orders WHERE car = "' . $car_id . '"');
            $updated++;
        }
    }

    return $updated;
}

/**
 * Assign and pick up CK1 coke cars at Shenango so they are on the train for weighing.
 */
function warm_start_ck1_ensure_coke_on_train($dbc, $fraction = 1.0)
{
    if (!is_readable(__DIR__ . '/track_scale_helpers.php')) {
        $assigned = warm_start_auto_assign_job_at_station($dbc, 'CK1', 12, $fraction);
        $picked = warm_start_pickup_job($dbc, 'CK1');
        return ['assigned' => $assigned, 'picked_up' => $picked];
    }
    require_once __DIR__ . '/track_scale_helpers.php';

    $config = track_scale_load_config();
    $job_id = warm_start_job_id($dbc, 'CK1');
    if ($job_id <= 0) {
        return ['assigned' => 0, 'picked_up' => 0];
    }

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = 12
           AND cars.current_location_id > 0
           AND cars.handled_by_job_id = 0
           AND cars.status IN ("Loaded", "Loading")
         ORDER BY cars.id'
    );
    $candidates = [];
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            $candidates[] = $car_id;
        }
    }
    shuffle($candidates);
    $limit = (int) ceil(count($candidates) * max(0.0, min(1.0, $fraction)));

    $assigned = 0;
    for ($i = 0; $i < $limit; $i++) {
        $car_id = $candidates[$i];
        if (mysqli_query(
            $dbc,
            'UPDATE cars SET handled_by_job_id = "' . (int) $job_id . '" WHERE id = "' . $car_id . '"'
        )) {
            $assigned++;
        }
    }

    $assigned += warm_start_auto_assign_job_at_station($dbc, 'CK1', 12, $fraction);
    $picked = warm_start_pickup_job($dbc, 'CK1');

    return ['assigned' => $assigned, 'picked_up' => $picked];
}

/**
 * Simulate track-scale calibration (test car at zero on all sensors).
 */
function warm_start_simulate_scale_calibration($dbc)
{
    if (!is_readable(__DIR__ . '/track_scale_helpers.php')) {
        return false;
    }
    require_once __DIR__ . '/track_scale_helpers.php';

    track_scale_sync_session_calibration($dbc);
    if (track_scale_is_calibration_locked($dbc)) {
        return false;
    }

    track_scale_session_init();
    track_scale_reset_calibration();
    foreach (track_scale_sensor_positions() as $position) {
        $_SESSION['track_scale']['sensor_errors'][$position] = 0.0;
        $_SESSION['track_scale']['sensor_adjustments'][$position] = 0.0;
        track_scale_mark_sensor_weighed($position);
    }

    $result = track_scale_save_calibration($dbc);
    return !empty($result['success']);
}

/**
 * Calibrate when required (first use / OOS) or when sessions since last cal exceed threshold.
 */
function warm_start_maybe_calibrate_scale($dbc, $config = [])
{
    if (!is_readable(__DIR__ . '/track_scale_helpers.php')) {
        return ['calibrated' => false, 'skipped' => true];
    }
    require_once __DIR__ . '/track_scale_helpers.php';

    track_scale_sync_session_calibration($dbc);
    if (track_scale_is_calibration_locked($dbc)) {
        return ['calibrated' => false, 'skipped' => true, 'reason' => 'already_locked'];
    }

    $every = (int) ($config['scale_calibrate_every_sessions'] ?? 3);
    $sessions_since = track_scale_sessions_since_calibration($dbc);
    $needs = track_scale_requires_calibration_init($dbc)
        || track_scale_is_out_of_service($dbc)
        || $sessions_since >= $every;

    if (!$needs) {
        return ['calibrated' => false, 'skipped' => true, 'sessions_since' => $sessions_since];
    }

    $ok = warm_start_simulate_scale_calibration($dbc);
    return [
        'calibrated' => $ok,
        'sessions_since' => $sessions_since,
    ];
}

/**
 * CK1 step 95: weigh coke cars on the train after pickup, before destination setouts.
 */
function warm_start_run_ck1_scale_ops($dbc)
{
    $fail = function (array $stats, string $error) {
        $stats['success'] = false;
        $stats['errors'][] = $error;
        return $stats;
    };

    $stats = [
        'weighed' => 0,
        'reloads' => 0,
        'outbound_assignments' => 0,
        'candidates' => 0,
        'errors' => [],
        'success' => true,
    ];

    if (!is_readable(__DIR__ . '/track_scale_helpers.php')) {
        return $fail($stats, 'track_scale_helpers.php not found');
    }
    require_once __DIR__ . '/track_scale_helpers.php';

    $config = track_scale_load_config();
    $ck1_id = warm_start_job_id($dbc, 'CK1');
    if ($ck1_id <= 0) {
        return $fail($stats, 'CK1 job not found');
    }

    if (track_scale_is_out_of_service($dbc, $config) && !track_scale_is_calibration_locked($dbc)) {
        return $fail($stats, 'track scale out of service');
    }

    $coke_stats = &warm_start_coke_stats();
    $scale_loc_id = track_scale_loading_location_id($dbc, $config);

    $rs = mysqli_query(
        $dbc,
        'SELECT cars.id AS car_id
         FROM cars
         WHERE cars.status IN ("Loaded", "Loading", "Ordered")
           AND (
             (cars.handled_by_job_id = "' . (int) $ck1_id . '" AND cars.current_location_id = 0)
             OR cars.current_location_id = "' . (int) $scale_loc_id . '"
           )
         ORDER BY cars.id'
    );
    $candidates = [];
    while ($row = mysqli_fetch_array($rs)) {
        $car_id = (int) $row['car_id'];
        if (!track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            continue;
        }
        $car = track_scale_get_car_by_id($dbc, $car_id);
        if ($car === null) {
            continue;
        }
        if ($car['status'] === 'Loading') {
            mysqli_query($dbc, 'UPDATE cars SET status = "Loaded" WHERE id = "' . $car_id . '"');
            $car['status'] = 'Loaded';
        }
        if (strcasecmp((string) ($car['status'] ?? ''), 'Ordered') === 0
            && track_scale_car_in_coke_fleet($dbc, $car_id, $config)) {
            mysqli_query($dbc, 'UPDATE cars SET status = "Loaded" WHERE id = "' . $car_id . '"');
            $car['status'] = 'Loaded';
        }
        if (!track_scale_car_has_load($car)) {
            continue;
        }
        $candidates[] = $car_id;
    }
    $stats['candidates'] = count($candidates);

    foreach ($candidates as $car_id) {
        $car = track_scale_get_car_by_id($dbc, $car_id);
        if ($car === null) {
            continue;
        }
        $on_ck1_train = (int) ($car['handled_by_job_id'] ?? 0) === $ck1_id
            && (int) ($car['current_location_id'] ?? 0) === 0;
        $at_scale = (int) ($car['current_location_id'] ?? 0) === (int) $scale_loc_id;
        if (!$on_ck1_train && !$at_scale) {
            continue;
        }

        $marks = $car['reporting_marks'] ?? '';
        $profile = track_scale_profile_for_marks($marks, $config);
        if (!empty($profile['tare_only'])) {
            continue;
        }

        if (!track_scale_car_weighable($car, $dbc, $config)) {
            $stats = $fail($stats, track_scale_weighable_car_error($car, $config));
            continue;
        }

        $target_net = (float) ($profile['target_net_tons'] ?? $profile['load_limit_tons'] ?? 80.0);
        $tare = (float) ($profile['tare_tons'] ?? 27.0);
        $true_net = track_scale_get_car_true_net($dbc, $marks, $target_net, $config);
        $weighing = track_scale_build_display_weighing($true_net, $tare, $target_net, $config);
        track_scale_record_weigh_log($dbc, $marks, $weighing, $config);

        $routing = $weighing['routing'] ?? 'outbound';
        if (warm_start_car_has_routing_order($dbc, $car_id, $routing, $config)) {
            $stats['weighed']++;
            $coke_stats['weighed']++;
            if ($routing === 'reload') {
                $stats['reloads']++;
                $coke_stats['reloads']++;
            } else {
                $stats['outbound_assignments']++;
                $coke_stats['outbound_assignments']++;
            }
            continue;
        }

        $waybill = warm_start_pick_coke_waybill($dbc, $car_id, $routing, $config);
        if ($waybill === null) {
            $stats = $fail($stats, "No waybill for {$marks} (routing={$routing})");
            continue;
        }

        $assign = track_scale_assign_car($dbc, $waybill, (string) $car_id, $config);
        if (empty($assign['success'])) {
            $message = $assign['message'] ?? 'assign failed';
            $stats = $fail($stats, "Assign failed for {$marks}: {$message}");
            continue;
        }

        $stats['weighed']++;
        $coke_stats['weighed']++;
        if ($routing === 'reload') {
            $stats['reloads']++;
            $coke_stats['reloads']++;
        } else {
            $stats['outbound_assignments']++;
            $coke_stats['outbound_assignments']++;
        }
    }

    if ($stats['candidates'] > 0 && $stats['weighed'] === 0) {
        $stats = $fail($stats, $stats['candidates'] . ' coke car(s) on CK1 but none weighed/assigned');
    }

    return $stats;
}

function warm_start_car_has_routing_order($dbc, $car_id, $routing, $config)
{
    if (!is_readable(__DIR__ . '/track_scale_helpers.php')) {
        return false;
    }
    require_once __DIR__ . '/track_scale_helpers.php';

    $active = track_scale_get_car_active_order($dbc, $car_id);
    if ($active === null) {
        return false;
    }
    $codes = track_scale_shipment_codes_for_routing($routing, $config);
    return in_array($active['shipment_code'], $codes, true);
}

function warm_start_pick_coke_waybill($dbc, $car_id, $routing, $config)
{
    $orders = track_scale_get_open_orders($dbc, $car_id, $routing, $config);
    if (count($orders) > 0) {
        return $orders[0]['waybill_number'];
    }

    $codes = track_scale_shipment_codes_for_routing($routing, $config);
    shuffle($codes);
    foreach ($codes as $code) {
        if (!track_scale_car_in_pool_for_shipment($dbc, $car_id, $code, $config)) {
            continue;
        }
        $generated = track_scale_generate_order($dbc, $code, $car_id, $config, $routing);
        if (!empty($generated['success'])) {
            return $generated['waybill_number'];
        }
    }

    return null;
}

function warm_start_run_ops_cycle($dbc, $fractions, $finish_non_staging_only = false, $config = [], $skip_staging = false, $skip_jobs = [])
{
    $staging_jobs = warm_start_staging_job_names($dbc, $config);
    $skip_staging = $skip_staging || $finish_non_staging_only;
    $switch_fraction = $finish_non_staging_only ? 1.0 : ($fractions['auto_assign'] ?? 1.0);
    $pickup_fraction = $finish_non_staging_only ? 1.0 : ($fractions['pickup'] ?? 1.0);
    $setout_fraction = $finish_non_staging_only ? 1.0 : ($fractions['setout'] ?? 1.0);

    $stats = [
        'filled' => warm_start_auto_fill($dbc, $fractions['fill_orders']),
        'repositioned' => warm_start_create_reposition_orders($dbc, $fractions['reposition']),
        'assigned' => warm_start_auto_assign_all($dbc, $switch_fraction, $staging_jobs, $skip_staging, $skip_jobs),
        'picked_up' => warm_start_pickup_cars($dbc, $pickup_fraction, $staging_jobs, $skip_staging, $skip_jobs),
        'set_out' => warm_start_setout_cars($dbc, $setout_fraction, $staging_jobs, $skip_staging, $skip_jobs),
        'load_unload' => warm_start_load_unload($dbc, $fractions['load_unload']),
    ];

    // Second pass: after setouts create Loading/Unloading, complete instant and ready cars.
    $stats['load_unload'] += warm_start_load_unload($dbc, $fractions['load_unload']);

    return $stats;
}

function warm_start_run($dbc, $config)
{
    $reseed = $config['reseed'] ?? !($config['continue_from_current'] ?? false);
    if ($reseed) {
        mt_srand((int) $config['seed']);
    }
    $continue = (bool) ($config['continue_from_current'] ?? false);
    if (!$continue) {
        warm_start_coke_stats_init();
    }
    warm_start_reset_session_stats_log();

    $repo_fraction = (float) ($config['reposition_fraction'] ?? 0.65);
    $full = [
        'fill_orders' => 1.0,
        'reposition' => $repo_fraction,
        'auto_assign' => 1.0,
        'pickup' => 1.0,
        'setout' => 1.0,
        'load_unload' => 1.0,
    ];

    $max_unfilled = (int) ($config['max_unfilled_before_generate'] ?? 30);
    $min_sessions = (int) ($config['min_sessions'] ?? $config['completed_sessions'] ?? 3);
    $max_sessions = (int) ($config['max_sessions'] ?? 12);
    $stop_at_stg_scully = (bool) ($config['stop_when_stg_scully_ready'] ?? false);
    $stop_at_locals = !$stop_at_stg_scully && (bool) ($config['stop_when_locals_secured'] ?? false);
    $stop_at_staging = !$stop_at_locals && !$stop_at_stg_scully && (bool) ($config['stop_when_staging_ready'] ?? true);
    $run_ck1_test = (bool) ($config['run_ck1_test'] ?? false);
    $weigh_failed = false;
    $stopped_at_staging = false;
    $stopped_at_locals = false;
    $stopped_at_stg_scully = false;
    $staging_backlog = ['eligible' => 0, 'on_jobs' => 0, 'ready' => false];

    if ($stop_at_stg_scully) {
        $start_session = warm_start_get_session($dbc);
        if ($continue) {
            $advance = max(1, (int) ($config['min_sessions'] ?? 1));
            $config['stg_scully_stop_from_session'] = $start_session + $advance;
            warm_start_log(
                'Continuing warm start from session ' . $start_session
                . ' (up to ' . $max_sessions . ' session(s), stop after session '
                . $config['stg_scully_stop_from_session']
                . ', no re-seed)'
            );
        }
        if (!$continue) {
            warm_start_log(
                'Warm start simulation (seed=' . $config['seed']
                . ', min_sessions=' . $min_sessions
                . ', max_sessions=' . $max_sessions
                . ', stop=STG-SCULLY ready at Scully (NVL secured, D749 on train)'
                . ', CK1 each session'
                . ', skip_generate_if_unfilled>' . $max_unfilled
                . ', reposition_fraction=' . $repo_fraction . ')'
            );
        }

        for ($i = 0; $i < $max_sessions; $i++) {
            $result = warm_start_advance_session($dbc, $full, '', $max_unfilled, false, $config, false);
            $session = warm_start_get_session($dbc);
            $staging_backlog = $result['stg_scully_backlog'] ?? warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
            if (!empty($result['stg_scully_stop_ready'])) {
                $stopped_at_stg_scully = true;
                warm_start_log(
                    "Session {$session}: STOP — STG-SCULLY ready with "
                    . ($staging_backlog['eligible'] ?? 0)
                    . ' car(s) at Scully awaiting assignment.'
                );
                break;
            }
        }

        if (!$stopped_at_stg_scully) {
            warm_start_log('STOP: max_sessions (' . $max_sessions . ') reached without STG-SCULLY-ready state.');
            $staging_backlog = warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
        }
    } elseif ($stop_at_locals) {
        warm_start_log(
            'Warm start simulation (seed=' . $config['seed']
            . ', max_sessions=' . $max_sessions
            . ', stop=NVL@Scully + D749 on train (Demmler block)'
            . ', CK1 each session'
            . ', skip_generate_if_unfilled>' . $max_unfilled
            . ', reposition_fraction=' . $repo_fraction . ')'
        );

        for ($i = 0; $i < $max_sessions; $i++) {
            $result = warm_start_advance_session($dbc, $full, '', $max_unfilled, false, $config, false);
            $session = warm_start_get_session($dbc);
            if (!empty($result['locals_secured'])) {
                $stopped_at_locals = true;
                warm_start_log("Session {$session}: locals secured (NVL@Scully, D749 on train with Demmler load).");
            }
        }

        if ($stopped_at_locals) {
            warm_start_log('Completed ' . $max_sessions . ' session(s); locals secured during run.');
        } else {
            warm_start_log('Completed ' . $max_sessions . ' session(s); locals not secured at end.');
        }
    } elseif ($stop_at_staging) {
        if ($continue) {
            $start_session = warm_start_get_session($dbc);
            warm_start_log(
                'Continuing warm start from session ' . $start_session
                . ' (+' . $min_sessions . ' session(s), staging deferred, no re-seed)'
            );
            for ($i = 0; $i < $min_sessions; $i++) {
                warm_start_advance_session($dbc, $full, '', $max_unfilled, false, $config, false);
                $staging_backlog = warm_start_staging_backlog($dbc, $config);
                $session = warm_start_get_session($dbc);
                warm_start_log(
                    "Session {$session}: staging backlog eligible={$staging_backlog['eligible']} "
                    . "on_jobs={$staging_backlog['on_jobs']}"
                );
            }
            $stopped_at_staging = !empty($staging_backlog['ready']);
        } else {
            warm_start_log(
                'Warm start simulation (seed=' . $config['seed']
                . ', min_sessions=' . $min_sessions
                . ', max_sessions=' . $max_sessions
                . ', stop=staging ready'
                . ', skip_generate_if_unfilled>' . $max_unfilled
                . ', reposition_fraction=' . $repo_fraction . ')'
            );

            for ($i = 0; $i < $max_sessions; $i++) {
                warm_start_advance_session($dbc, $full, '', $max_unfilled, false, $config, false);
                $staging_backlog = warm_start_staging_backlog($dbc, $config);
                $session = warm_start_get_session($dbc);
                warm_start_log(
                    "Session {$session}: staging backlog eligible={$staging_backlog['eligible']} "
                    . "on_jobs={$staging_backlog['on_jobs']}"
                );

                if ($session >= $min_sessions && !empty($staging_backlog['ready'])) {
                    warm_start_log('STOP: Staging jobs ready — STG-SCULLY / STG-DEMMLER can run.');
                    $stopped_at_staging = true;
                    break;
                }
            }

            if (!$stopped_at_staging) {
                warm_start_log('STOP: max_sessions (' . $max_sessions . ') reached without staging-ready state.');
            }
        }
    } else {
        $completed = (int) ($config['completed_sessions'] ?? 3);
        warm_start_log(
            'Warm start simulation (seed=' . $config['seed']
            . ', completed_sessions=' . $completed
            . ', skip_generate_if_unfilled>' . $max_unfilled
            . ', reposition_fraction=' . $repo_fraction . ')'
        );
        for ($i = 0; $i < $completed; $i++) {
            warm_start_advance_session($dbc, $full, '', $max_unfilled, false, $config, true);
        }
        if ($run_ck1_test) {
            $ck1_result = warm_start_begin_ck1_test_session($dbc, $config['partial'], 'CK1 test', $max_unfilled, $config);
            $weigh_failed = !empty($ck1_result['weigh_failed']);
        }
    }

    warm_start_print_session_stats_report();

    if ($weigh_failed) {
        warm_start_log('');
        warm_start_log('STOP: CK1 weigh failed at session ' . warm_start_get_session($dbc) . ' — troubleshoot before continuing.');
    } elseif ($stopped_at_stg_scully) {
        warm_start_log('');
        warm_start_log(
            'Ready for STG-SCULLY — session ' . warm_start_get_session($dbc)
            . ' with ' . ($staging_backlog['eligible'] ?? 0)
            . ' car(s) at Scully awaiting assignment'
            . ' (NVL secured, D749 on train with Demmler block).'
        );
    } elseif ($stopped_at_locals) {
        warm_start_log('');
        warm_start_log(
            'Locals secured — session ' . warm_start_get_session($dbc)
            . ' (NVL at Scully, D749 on train with Demmler inbound block).'
        );
    } elseif ($stopped_at_staging) {
        warm_start_log('');
        warm_start_log(
            'Ready for staging — session ' . warm_start_get_session($dbc)
            . ' with ' . $staging_backlog['eligible'] . ' car(s) eligible for STG-SCULLY / STG-DEMMLER.'
        );
    } elseif ($continue && $stop_at_staging) {
        warm_start_log('');
        warm_start_log('Advanced to session ' . warm_start_get_session($dbc) . '.');
    }

    $summary = warm_start_summarize($dbc);
    $summary['weigh_failed'] = $weigh_failed;
    $summary['stopped_at_staging'] = $stopped_at_staging;
    $summary['stopped_at_locals'] = $stopped_at_locals;
    $summary['stopped_at_stg_scully'] = $stopped_at_stg_scully;
    $summary['staging_backlog'] = $staging_backlog;
    $summary['stg_scully_backlog'] = $stopped_at_stg_scully
        ? $staging_backlog
        : warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
    $summary['session_stats'] = warm_start_session_stats_log();

    return $summary;
}

function warm_start_summarize($dbc)
{
    $summary = ['session' => warm_start_get_session($dbc)];

    $rs = mysqli_query($dbc, 'SELECT COUNT(*) AS c FROM car_orders');
    $summary['orders_total'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query($dbc, 'SELECT COUNT(*) AS c FROM car_orders WHERE car = "" OR car IS NULL OR car = "0"');
    $summary['orders_unfilled'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         WHERE cars.handled_by_job_id > 0
           AND cars.current_location_id > 0'
    );
    $summary['pending_pickup'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         WHERE cars.handled_by_job_id = 0
           AND cars.current_location_id > 0'
    );
    $summary['awaiting_assignment'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query($dbc, 'SELECT COUNT(*) AS c FROM cars WHERE current_location_id = 0 AND handled_by_job_id > 0');
    $summary['in_train_assigned'] = (int) mysqli_fetch_array($rs)['c'];

    $staging_quoted = implode(', ', array_map(function ($name) {
        return '"' . addslashes($name) . '"';
    }, ['STG-SCULLY', 'STG-DEMMLER']));
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN jobs ON jobs.id = cars.handled_by_job_id
         WHERE jobs.name IN (' . $staging_quoted . ')
           AND cars.handled_by_job_id > 0
           AND cars.current_location_id > 0'
    );
    $summary['staging_pending_pickup'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN jobs ON jobs.id = cars.handled_by_job_id
         WHERE jobs.name NOT IN (' . $staging_quoted . ')
           AND cars.handled_by_job_id > 0'
    );
    $summary['non_staging_on_jobs'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         LEFT JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station = 3
           AND cars.current_location_id > 0'
    );
    $summary['island_cars'] = (int) mysqli_fetch_array($rs)['c'];

    $south_id = warm_start_location_id_by_code($dbc, 'SOUTH');
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c FROM cars WHERE current_location_id = "' . (int) $south_id . '"'
    );
    $summary['south_yard_cars'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         WHERE loc.station IN (14, 15)
           AND cars.status IN ("Loading", "Unloading", "Ordered")
           AND cars.current_location_id > 0'
    );
    $summary['offline_ready'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c FROM car_orders WHERE waybill_number LIKE "%E%"'
    );
    $summary['reposition_orders'] = (int) mysqli_fetch_array($rs)['c'];

    $coke = warm_start_coke_stats();
    $summary['coke_reloads'] = (int) ($coke['reloads'] ?? 0);
    $summary['coke_outbound_assignments'] = (int) ($coke['outbound_assignments'] ?? 0);
    $summary['coke_complete_deliveries'] = (int) ($coke['complete_deliveries'] ?? 0);
    $summary['coke_weighed'] = (int) ($coke['weighed'] ?? 0);

    $ck1_id = warm_start_job_id($dbc, 'CK1');
    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN car_orders ON car_orders.car = cars.id
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         WHERE cars.handled_by_job_id = "' . (int) $ck1_id . '"
           AND cars.current_location_id = 0
           AND cars.status = "Loaded"
           AND shipments.code IN ("COKE-USS", "COKE-CLEV", "COKE-USS-BULK", "COKE-CLEV-BULK")'
    );
    $summary['ck1_outbound_on_train'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         WHERE status = "Empty"
           AND current_location_id > 0
           AND home_location > 0
           AND current_location_id != home_location
           AND id NOT IN (SELECT car FROM car_orders WHERE car IS NOT NULL AND car != "" AND car != "0")'
    );
    $summary['empties_off_home'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query($dbc, 'SELECT status, COUNT(*) AS c FROM cars GROUP BY status');
    $summary['cars_by_status'] = [];
    while ($row = mysqli_fetch_array($rs)) {
        $summary['cars_by_status'][$row['status']] = (int) $row['c'];
    }

    return $summary;
}

function warm_start_backup($dbc, $backup_name)
{
    chdir(__DIR__);
    backup_tables($dbc, $backup_name);
    return __DIR__ . '/backups/' . $backup_name;
}

/**
 * Evaluate unfilled orders, empties, staging backlog, and per-job assign eligibility.
 */
function warm_start_evaluate_session_prep($dbc, $config = [])
{
    $config = array_merge(warm_start_default_config(), $config);
    $staging_jobs = warm_start_staging_job_names($dbc, $config);
    $scully_backlog = warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);

    $unfilled_waybills = fill_order_get_unfilled_waybills($dbc);
    $unfilled_preview = [];
    foreach (array_slice($unfilled_waybills, 0, 12) as $waybill) {
        $detail = fill_order_get_details($dbc, $waybill);
        if (!$detail) {
            continue;
        }
        $unfilled_preview[] = [
            'waybill' => $waybill,
            'shipment' => $detail['shipment_code'] ?? '',
            'commodity' => $detail['commodity'] ?? '',
        ];
    }

    $empties_off_home = [];
    $rs = mysqli_query(
        $dbc,
        'SELECT cars.reporting_marks,
                loc.code AS current_code,
                home.code AS home_code
         FROM cars
         LEFT JOIN locations loc ON loc.id = cars.current_location_id
         LEFT JOIN locations home ON home.id = cars.home_location
         WHERE cars.status = "Empty"
           AND cars.current_location_id > 0
           AND cars.home_location > 0
           AND cars.current_location_id != cars.home_location
           AND cars.id NOT IN (
                 SELECT car FROM car_orders WHERE car IS NOT NULL AND car != "" AND car != "0"
           )
         ORDER BY cars.id
         LIMIT 15'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $empties_off_home[] = $row;
    }

    $job_eligible = [];
    $jobs_rs = mysqli_query($dbc, 'SELECT name FROM jobs ORDER BY name');
    while ($job = mysqli_fetch_array($jobs_rs)) {
        $name = $job['name'];
        if (warm_start_is_staging_job($name, $staging_jobs)) {
            continue;
        }
        $job_eligible[$name] = count(auto_assign_eligible_car_ids_for_job($dbc, $name, true));
    }

    $d749_id = warm_start_job_id($dbc, 'D749');
    $d749_on_train = 0;
    if ($d749_id > 0) {
        $rs = mysqli_query(
            $dbc,
            'SELECT COUNT(*) AS c FROM cars
             WHERE handled_by_job_id = "' . (int) $d749_id . '"
               AND current_location_id = 0'
        );
        $d749_on_train = (int) mysqli_fetch_array($rs)['c'];
    }

    $summary = warm_start_summarize($dbc);

    return [
        'session' => warm_start_get_session($dbc),
        'unfilled_count' => count($unfilled_waybills),
        'unfilled_preview' => $unfilled_preview,
        'empties_off_home_count' => (int) ($summary['empties_off_home'] ?? 0),
        'empties_off_home_preview' => $empties_off_home,
        'reposition_orders' => (int) ($summary['reposition_orders'] ?? 0),
        'stg_scully_backlog' => $scully_backlog,
        'awaiting_assignment' => (int) ($summary['awaiting_assignment'] ?? 0),
        'd749_on_train' => $d749_on_train,
        'offline_ready' => (int) ($summary['offline_ready'] ?? 0),
        'job_eligible' => $job_eligible,
        'summary' => $summary,
    ];
}

/**
 * Begin a live operating session from warm-start end state:
 * load/unload, increment session, reposition empties, auto-assign (no fill).
 */
function warm_start_begin_operating_session($dbc, array $options = [])
{
    $config = warm_start_merge_config($options['config'] ?? []);
    $load_unload = !isset($options['load_unload']) || $options['load_unload'];
    $increment = !isset($options['increment']) || $options['increment'];
    $reposition = !isset($options['reposition']) || $options['reposition'];
    $assign = !isset($options['assign']) || $options['assign'];
    $generate = !empty($options['generate']);
    $run_stg_scully = !empty($options['run_stg_scully']);
    $repo_fraction = (float) ($options['reposition_fraction'] ?? $config['reposition_fraction'] ?? 0.65);
    $staging_jobs = warm_start_staging_job_names($dbc, $config);

    $result = [
        'previous_session' => warm_start_get_session($dbc),
        'session' => warm_start_get_session($dbc),
        'stg_scully' => ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0, 'skipped' => true],
        'load_unload' => 0,
        'repositioned' => 0,
        'generated' => 0,
        'assigned' => 0,
        'assigned_by_job' => [],
        'warnings' => [],
        'errors' => [],
        'blocked' => false,
        'evaluation' => null,
    ];

    $before = warm_start_evaluate_session_prep($dbc, $config);
    if ($run_stg_scully) {
        $result['stg_scully'] = array_merge(
            warm_start_complete_staging_jobs($dbc, ['STG-SCULLY'], $config, 1.0),
            ['skipped' => false]
        );
        $before = warm_start_evaluate_session_prep($dbc, $config);
    }

    if (!empty($before['stg_scully_backlog']['ready'])) {
        $eligible = (int) ($before['stg_scully_backlog']['eligible'] ?? 0);
        $on_jobs = (int) ($before['stg_scully_backlog']['on_jobs'] ?? 0);
        if ($run_stg_scully) {
            $result['errors'][] = 'STG-SCULLY still has backlog after automated run ('
                . $eligible . ' eligible at Scully, ' . $on_jobs . ' on job).';
        } else {
            $result['errors'][] = 'STG-SCULLY has not run — '
                . $eligible . ' car(s) eligible at Scully, ' . $on_jobs . ' on job. '
                . 'Complete STG-SCULLY in STS or pass --run-stg-scully.';
        }
        $result['blocked'] = true;
        $result['evaluation'] = $before;
        return $result;
    }

    if ($load_unload) {
        $result['load_unload'] = warm_start_load_unload($dbc, 1.0);
        $result['load_unload'] += warm_start_load_unload($dbc, 1.0);
    }

    if ($increment) {
        $result['previous_session'] = warm_start_get_session($dbc);
        $result['session'] = warm_start_set_session($dbc, $result['previous_session'] + 1);
    }

    if ($generate) {
        $waybill_counter = warm_start_get_next_auto_waybill_counter($dbc, $result['session']);
        $result['generated'] = warm_start_generate_orders($dbc, $result['session'], $waybill_counter);
    }

    if ($reposition) {
        $result['repositioned'] = warm_start_create_reposition_orders($dbc, $repo_fraction);
    }

    if ($assign) {
        $jobs_rs = mysqli_query($dbc, 'SELECT id, name FROM jobs ORDER BY name');
        while ($job = mysqli_fetch_array($jobs_rs)) {
            $job_name = $job['name'];
            if (warm_start_is_staging_job($job_name, $staging_jobs)) {
                continue;
            }
            $eligible = array_keys(auto_assign_eligible_car_ids_for_job($dbc, $job_name, true));
            $assigned = warm_start_assign_cars_to_job($dbc, $job_name, $eligible);
            if ($assigned > 0) {
                $result['assigned_by_job'][$job_name] = $assigned;
                $result['assigned'] += $assigned;
            }
        }
    }

    $result['evaluation'] = warm_start_evaluate_session_prep($dbc, $config);
    return $result;
}

function warm_start_format_begin_session_report(array $result)
{
    $lines = [];
    $blocked = !empty($result['blocked']);
    $lines[] = $blocked ? '=== Begin operating session (blocked) ===' : '=== Begin operating session ===';

    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $error) {
            $lines[] = 'ERROR: ' . $error;
        }
        $lines[] = '';
    }
    if (!empty($result['warnings'])) {
        foreach ($result['warnings'] as $warning) {
            $lines[] = 'WARNING: ' . $warning;
        }
        $lines[] = '';
    }

    if ($blocked) {
        $eval = $result['evaluation'] ?? [];
        $scully = $eval['stg_scully_backlog'] ?? [];
        $lines[] = 'Session: ' . ($result['session'] ?? '?') . ' (unchanged)';
        $stg = $result['stg_scully'] ?? [];
        if (empty($stg['skipped'])) {
            $lines[] = 'STG-SCULLY attempted: assigned=' . (int) ($stg['assigned'] ?? 0)
                . ' pickup=' . (int) ($stg['picked_up'] ?? 0)
                . ' setout=' . (int) ($stg['set_out'] ?? 0)
                . ' load/unload=' . (int) ($stg['load_unload'] ?? 0);
        }
        $lines[] = 'STG-SCULLY eligible at Scully: ' . (int) ($scully['eligible'] ?? 0);
        $lines[] = 'STG-SCULLY on job: ' . (int) ($scully['on_jobs'] ?? 0);
        $lines[] = '';
        $lines[] = 'No session prep was run. Clear STG-SCULLY, then retry.';
        return implode(PHP_EOL, $lines);
    }

    if (($result['previous_session'] ?? 0) !== ($result['session'] ?? 0)) {
        $lines[] = 'Session: ' . ($result['previous_session'] ?? '?')
            . ' → ' . ($result['session'] ?? '?');
    } else {
        $lines[] = 'Session: ' . ($result['session'] ?? '?') . ' (not incremented)';
    }
    $stg = $result['stg_scully'] ?? [];
    if (empty($stg['skipped'])) {
        $lines[] = 'STG-SCULLY: assigned=' . (int) ($stg['assigned'] ?? 0)
            . ' pickup=' . (int) ($stg['picked_up'] ?? 0)
            . ' setout=' . (int) ($stg['set_out'] ?? 0)
            . ' load/unload=' . (int) ($stg['load_unload'] ?? 0);
    }
    $lines[] = 'Load/unload transitions: ' . (int) ($result['load_unload'] ?? 0);
    $lines[] = 'Reposition orders created: ' . (int) ($result['repositioned'] ?? 0);
    if (!empty($result['generated'])) {
        $lines[] = 'Orders generated: ' . (int) $result['generated'];
    }
    $lines[] = 'Cars assigned to jobs: ' . (int) ($result['assigned'] ?? 0);
    $lines[] = '';

    $eval = $result['evaluation'] ?? [];
    $lines[] = '--- Orders (not filled) ---';
    $lines[] = 'Unfilled waybills: ' . (int) ($eval['unfilled_count'] ?? 0);
    foreach ($eval['unfilled_preview'] ?? [] as $row) {
        $lines[] = '  ' . $row['waybill'] . '  ' . $row['shipment'] . '  ' . $row['commodity'];
    }
    if (($eval['unfilled_count'] ?? 0) > count($eval['unfilled_preview'] ?? [])) {
        $lines[] = '  ...';
    }

    $lines[] = '';
    $lines[] = '--- Empties ---';
    $lines[] = 'Off-home without order: ' . (int) ($eval['empties_off_home_count'] ?? 0);
    $lines[] = 'Open reposition (E) orders: ' . (int) ($eval['reposition_orders'] ?? 0);
    foreach ($eval['empties_off_home_preview'] ?? [] as $row) {
        $lines[] = '  ' . $row['reporting_marks'] . '  at ' . $row['current_code'] . '  home ' . $row['home_code'];
    }
    if (($eval['empties_off_home_count'] ?? 0) > count($eval['empties_off_home_preview'] ?? [])) {
        $lines[] = '  ...';
    }

    $lines[] = '';
    $lines[] = '--- Yard / train ---';
    $lines[] = 'D749 on train: ' . (int) ($eval['d749_on_train'] ?? 0)
        . ' (set out at South Yard to open the session)';
    $lines[] = 'Awaiting job assignment: ' . (int) ($eval['awaiting_assignment'] ?? 0);
    $lines[] = 'Offline ready for load/unload: ' . (int) ($eval['offline_ready'] ?? 0);
    $scully = $eval['stg_scully_backlog'] ?? [];
    $lines[] = 'STG-SCULLY eligible at Scully: ' . (int) ($scully['eligible'] ?? 0);

    $lines[] = '';
    $lines[] = '--- Job assign eligibility (after setup) ---';
    foreach ($eval['job_eligible'] ?? [] as $job => $count) {
        if ($count <= 0) {
            continue;
        }
        $assigned = (int) ($result['assigned_by_job'][$job] ?? 0);
        $suffix = $assigned > 0 ? " (assigned {$assigned})" : '';
        $lines[] = sprintf('  %-12s %3d eligible%s', $job, $count, $suffix);
    }

    $lines[] = '';
    $lines[] = 'Orders were NOT filled. Run D749 South setout, then pickups/setouts in Operations.';

    return implode(PHP_EOL, $lines);
}
