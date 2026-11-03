<?php
/**
 * Simulate several operating sessions to produce a balanced warm-start state.
 * Uses the same fill / reposition / assign / pickup / setout / load-unload rules as STS ops pages.
 *
 * This file is layout-neutral. Session orchestration (config defaults, staging job names,
 * location aliases, the session loop, summary and begin-session report) dispatches to the
 * warm-start scenario a plugin registers (manifest key 'warm_start_scenario', see
 * plugins_warm_start_scenario() in plugin_registry.php) and falls back to the generic
 * defaults below when none is registered.
 */

require_once __DIR__ . '/plugin_registry.php';
plugins_bootstrap_all();
require_once __DIR__ . '/fill_order_helpers.php';
require_once __DIR__ . '/drop_down_list_functions.php';
require_once __DIR__ . '/backup_tables.php';
require_once __DIR__ . '/generate_order_helpers.php';
require_once __DIR__ . '/warm_start_session_stats.php';

/**
 * Named statistics buckets: generic accumulators that scenarios and plugins use
 * for their own counters (e.g. the HART layout keeps a "coke" bucket).
 */
function &warm_start_stats_store()
{
    static $store = [];
    return $store;
}

/** Bucket by name, created empty on first use. Returned by reference. */
function &warm_start_stats_bucket($name)
{
    $store = &warm_start_stats_store();
    $name = (string) $name;
    if (!isset($store[$name]) || !is_array($store[$name])) {
        $store[$name] = [];
    }
    return $store[$name];
}

/** Empty every bucket (in place, so references held elsewhere stay valid). */
function warm_start_stats_reset_all()
{
    $store = &warm_start_stats_store();
    foreach (array_keys($store) as $name) {
        $store[$name] = [];
    }
}

/** Integer snapshot of every bucket: name => key => int. */
function warm_start_stats_copy_all()
{
    $copy = [];
    foreach (warm_start_stats_store() as $name => $bucket) {
        $copy[$name] = [];
        foreach ((array) $bucket as $key => $value) {
            if (is_numeric($value)) {
                $copy[$name][$key] = (int) $value;
            }
        }
    }
    return $copy;
}

/** Per-bucket numeric delta (never negative): name => key => int. */
function warm_start_stats_delta_all(array $before, array $after)
{
    $delta = [];
    foreach ($after as $name => $bucket) {
        $delta[$name] = [];
        foreach ((array) $bucket as $key => $value) {
            $delta[$name][$key] = max(0, (int) $value - (int) ($before[$name][$key] ?? 0));
        }
    }
    return $delta;
}

function warm_start_default_config()
{
    $config = [
        'seed' => 42,
        'completed_sessions' => 3,
        'min_sessions' => 3,
        'max_sessions' => 12,
        'stop_when_staging_ready' => true,
        'max_unfilled_before_generate' => 30,
        'staging_jobs' => [],
        'partial' => [
            'fill_orders' => 0.88,
            'reposition' => 0.65,
            'auto_assign' => 0.75,
            'pickup' => 0.55,
            'setout' => 0.65,
            'load_unload' => 0.80,
        ],
        'reposition_fraction' => 0.65,
    ];
    // A registered scenario (see plugins_warm_start_scenario) adds or overrides keys.
    $extra = plugins_scenario_call('default_config', [], []);

    return is_array($extra) ? array_merge($config, $extra) : $config;
}

function warm_start_merge_config($overrides)
{
    $defaults = warm_start_default_config();
    $config = $defaults;
    // Keys with their own clamping or coupling rules.
    $special = [
        'completed_sessions' => true, 'min_sessions' => true, 'max_sessions' => true,
        'max_unfilled_before_generate' => true, 'staging_jobs' => true, 'partial' => true,
        'reposition_fraction' => true,
    ];
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
    // Every other default key (core or scenario): take the override, cast to the default's type.
    foreach ($defaults as $key => $default) {
        if (isset($special[$key]) || !isset($overrides[$key])) {
            continue;
        }
        $value = $overrides[$key];
        if (is_bool($default)) {
            $config[$key] = (bool) $value;
        } elseif (is_int($default)) {
            $config[$key] = (int) $value;
        } elseif (is_float($default)) {
            $config[$key] = (float) $value;
        } elseif (is_array($default)) {
            if (is_array($value)) {
                $config[$key] = array_merge($default, $value);
            }
        } elseif (is_string($default)) {
            $config[$key] = (string) $value;
        }
    }
    return $config;
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

/**
 * Release job assignment for cars still sitting in a yard (not on the train).
 * Used after a selective pick-up so a leave-yard switch list only shows cars
 * actually lifted — leftovers (e.g. McKees Rocks-bound for STG) do not appear.
 */
function warm_start_release_job_cars_at_station($dbc, $job_name, $station_id)
{
    $job_id = warm_start_job_id($dbc, $job_name);
    $station_id = (int) $station_id;
    if ($job_id <= 0 || $station_id <= 0) {
        return 0;
    }
    if (!mysqli_query(
        $dbc,
        'UPDATE cars
         INNER JOIN locations loc ON loc.id = cars.current_location_id
         SET cars.handled_by_job_id = 0,
             cars.position = 0
         WHERE cars.handled_by_job_id = "' . (int) $job_id . '"
           AND cars.current_location_id > 0
           AND loc.station = "' . $station_id . '"'
    )) {
        return 0;
    }
    return (int) mysqli_affected_rows($dbc);
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

function warm_start_pickup_job_at_station($dbc, $job_name, $pickup_station, array $filters = [])
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
        if (!operational_steps_train_car_passes_filters($dbc, $car_id, $job_name, $filters)) {
            continue;
        }
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

function warm_start_setout_all_job_train($dbc, $job_name, array $filters = [])
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
        if (!operational_steps_train_car_passes_filters($dbc, $car_id, $job_name, $filters)) {
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

function warm_start_default_fractions(array $config = [])
{
    $repo_fraction = (float) ($config['reposition_fraction'] ?? 0.65);

    return [
        'fill_orders' => 1.0,
        'reposition' => $repo_fraction,
        'auto_assign' => 1.0,
        'pickup' => 1.0,
        'setout' => 1.0,
        'load_unload' => 1.0,
    ];
}

/**
 * Run local/staging work for the current operating session (no session increment).
 * Call after begin_session has opened the session. A registered scenario may replace this.
 */
function warm_start_play_operating_session($dbc, array $config = [], ?array $fractions = null, $label = '')
{
    return plugins_scenario_call(
        'play_session',
        [$dbc, $config, $fractions, $label],
        'warm_start_core_play_operating_session'
    );
}

function warm_start_core_play_operating_session($dbc, array $config = [], ?array $fractions = null, $label = '')
{
    $config = warm_start_merge_config(array_merge(['play_operating_session' => true], $config));
    $fractions = $fractions ?? warm_start_default_fractions($config);
    $max_unfilled = (int) ($config['max_unfilled_before_generate'] ?? 30);

    return warm_start_run_session_work(
        $dbc,
        $fractions,
        $label,
        $max_unfilled,
        false,
        $config,
        false,
        warm_start_get_session($dbc),
        null
    );
}

/**
 * Advance one operating session: increment session, then run its work.
 * Skips generation when too many unfilled orders. A registered scenario may replace this.
 */
function warm_start_advance_session($dbc, $fractions, $label, $max_unfilled, $finish_non_staging_only = false, $config = [], $run_staging = true)
{
    return plugins_scenario_call(
        'advance_session',
        [$dbc, $fractions, $label, $max_unfilled, $finish_non_staging_only, $config, $run_staging],
        'warm_start_core_advance_session'
    );
}

function warm_start_core_advance_session($dbc, $fractions, $label, $max_unfilled, $finish_non_staging_only = false, $config = [], $run_staging = true)
{
    $session = warm_start_get_session($dbc) + 1;
    warm_start_set_session($dbc, $session);

    return warm_start_run_session_work(
        $dbc,
        $fractions,
        $label,
        $max_unfilled,
        $finish_non_staging_only,
        $config,
        $run_staging,
        $session,
        null
    );
}

/**
 * Shared session body: generate orders, one ops cycle, finish non-staging jobs,
 * bookend staging. A registered scenario may replace this.
 */
function warm_start_run_session_work(
    $dbc,
    $fractions,
    $label,
    $max_unfilled,
    $finish_non_staging_only = false,
    $config = [],
    $run_staging = true,
    $session = null,
    $pending_staging = null
) {
    return plugins_scenario_call(
        'session_work',
        [$dbc, $fractions, $label, $max_unfilled, $finish_non_staging_only, $config, $run_staging, $session, $pending_staging],
        'warm_start_core_run_session_work'
    );
}

function warm_start_core_run_session_work(
    $dbc,
    $fractions,
    $label,
    $max_unfilled,
    $finish_non_staging_only = false,
    $config = [],
    $run_staging = true,
    $session = null,
    $pending_staging = null
) {
    $stats_before = warm_start_stats_copy_all();
    warm_start_session_stats_reset();
    if ($session === null) {
        $session = warm_start_get_session($dbc);
    }
    $generated = 0;
    $unfilled = warm_start_count_unfilled($dbc);
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

    $stats = warm_start_run_ops_cycle($dbc, $fractions, false, $config, true);
    warm_start_log(
        "Session {$session}{$suffix}: filled={$stats['filled']} repo={$stats['repositioned']} assigned={$stats['assigned']} "
        . "pickup={$stats['picked_up']} setout={$stats['set_out']} load/unload={$stats['load_unload']}"
    );

    warm_start_finish_non_staging_jobs($dbc, $config, $fractions);

    $staging = ['assigned' => 0, 'picked_up' => 0, 'set_out' => 0, 'load_unload' => 0];
    if ($run_staging) {
        $load_unload_fraction = $fractions['load_unload'] ?? 1.0;
        $staging = warm_start_complete_staging($dbc, $config, $load_unload_fraction);
        $stats['load_unload'] = ($stats['load_unload'] ?? 0) + (int) ($staging['load_unload'] ?? 0);
        warm_start_log(
            "Session {$session}{$suffix} staging: assigned={$staging['assigned']} pickup={$staging['picked_up']} "
            . "setout={$staging['set_out']} load/unload={$staging['load_unload']}"
        );
    } else {
        warm_start_log("Session {$session}{$suffix}: staging deferred");
    }

    $snapshot = warm_start_session_stats_commit(
        $session,
        $label,
        $stats_before,
        array_merge($stats, ['generated' => (int) $generated])
    );

    return array_merge($stats, [
        'staging' => $staging,
        'session_stats' => $snapshot,
        'pending_staging' => $pending_staging,
    ]);
}

/** Staging job names: from the scenario when one is registered, else config 'staging_jobs'. */
function warm_start_staging_job_names($dbc, $config)
{
    return plugins_scenario_call('staging_jobs', [$dbc, $config], static function ($dbc, $config) {
        $configured = $config['staging_jobs'] ?? [];
        return array_values(array_filter(array_map('strval', $configured)));
    });
}

function warm_start_is_staging_job($job_name, $staging_jobs)
{
    return in_array($job_name, $staging_jobs, true);
}

/** Location code aliases (alias => canonical code); supplied by the scenario, none by default. */
function warm_start_location_code_aliases()
{
    $aliases = plugins_scenario_call('location_aliases', [], []);

    return is_array($aliases) ? $aliases : [];
}

function warm_start_normalize_location_code($code)
{
    $code = strtoupper(trim((string) $code));
    $aliases = warm_start_location_code_aliases();
    $seen = [];
    while ($code !== '' && isset($aliases[$code]) && !isset($seen[$code])) {
        $seen[$code] = true;
        $code = strtoupper(trim((string) $aliases[$code]));
    }
    return $code;
}

function warm_start_location_id_by_code($dbc, $code)
{
    $code = warm_start_normalize_location_code($code);
    if ($code === '') {
        return 0;
    }
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
    // Read-only backlog probe — do not move cars here. Yard prep runs only when
    // executing staging work (warm_start_complete_staging_jobs / prep_staging_yards).
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
        plugins_scenario_call('prep_staging', [$dbc, $config], 0);

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

function warm_start_pickup_job($dbc, $job_name, array $filters = [])
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
        if (!operational_steps_train_car_passes_filters($dbc, $car_id, $job_name, $filters)) {
            continue;
        }
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

function warm_start_setout_job_at_location($dbc, $job_name, $location_id, array $filters = [])
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
        if (!operational_steps_train_car_passes_filters($dbc, $car_id, $job_name, $filters)) {
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
    $helpers = __DIR__ . '/generate_order_helpers.php';
    if (is_readable($helpers)) {
        require_once $helpers;
        return generate_orders_get_next_auto_waybill_counter($dbc, $session_number);
    }
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
    $helpers = __DIR__ . '/generate_order_helpers.php';
    if (is_readable($helpers)) {
        require_once $helpers;
        return generate_orders_run_automatic($dbc, $session_number, $waybill_counter);
    }
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

function warm_start_auto_fill($dbc, $fraction = 1.0, array $options = [])
{
    require_once __DIR__ . '/fill_order_helpers.php';

    $order_filters = fill_order_parse_filters($options['order_filters'] ?? null);
    $car_filters = $options['car_filters'] ?? null;
    if ($car_filters !== null && !is_array($car_filters)) {
        $car_filters = null;
    }

    $result = fill_order_auto_assign($dbc, [
        'order_filters' => $order_filters,
        'car_filters' => $car_filters,
        'fraction' => max(0.0, min(1.0, (float) $fraction)),
        'shuffle' => true,
    ]);

    return (int) $result['filled'];
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

function warm_start_reposition_row_matches(array $row, array $filters)
{
    if (!empty($filters['car_code'])
        && (string) ($row['car_code'] ?? '') !== (string) $filters['car_code']) {
        return false;
    }
    if (!empty($filters['current_station'])
        && (string) ($row['current_station'] ?? '') !== (string) $filters['current_station']) {
        return false;
    }
    if (!empty($filters['current_location'])
        && (string) ($row['current_location'] ?? '') !== (string) $filters['current_location']) {
        return false;
    }
    if (!empty($filters['home_station'])
        && (string) ($row['home_station'] ?? '') !== (string) $filters['home_station']) {
        return false;
    }
    if (!empty($filters['home_location'])
        && (string) ($row['home_location'] ?? '') !== (string) $filters['home_location']) {
        return false;
    }
    if (!empty($filters['off_home_only'])
        && (string) $filters['off_home_only'] !== '0'
        && empty($row['off_home'])) {
        return false;
    }
    return true;
}

function warm_start_reposition_candidates($dbc)
{
    $sql = 'SELECT cars.id AS car_id,
                   cars.current_location_id,
                   cars.home_location AS home_location_id,
                   car_codes.code AS car_code,
                   loc.code AS current_code,
                   home.code AS home_code,
                   loc01.code AS current_location,
                   loc02.code AS home_location,
                   sta01.station AS current_station,
                   sta02.station AS home_station,
                   (cars.current_location_id != cars.home_location) AS off_home,
                   CASE
                     WHEN cars.current_location_id IN (
                       SELECT unloading_location FROM shipments WHERE unloading_location > 0
                     ) THEN 0
                     ELSE 1
                   END AS sort_key
            FROM cars
            LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
            LEFT JOIN locations loc ON loc.id = cars.current_location_id
            LEFT JOIN locations home ON home.id = cars.home_location
            LEFT JOIN locations loc01 ON loc01.id = cars.current_location_id
            LEFT JOIN locations loc02 ON loc02.id = cars.home_location
            LEFT JOIN routing sta01 ON sta01.id = loc01.station
            LEFT JOIN routing sta02 ON sta02.id = loc02.station
            WHERE cars.status = "Empty"
              AND cars.current_location_id > 0
              AND cars.home_location > 0
              AND cars.id NOT IN (
                    SELECT car FROM car_orders WHERE car IS NOT NULL AND car != "" AND car != "0"
              )
            ORDER BY sort_key, cars.id';

    $rs = mysqli_query($dbc, $sql);
    $candidates = [];
    while ($row = mysqli_fetch_array($rs)) {
        $candidates[] = $row;
    }
    return $candidates;
}

function warm_start_reposition_create_order($dbc, $session_number, &$waybill_counter, array $row, $destination_id, $destination_code)
{
    $car_id = (int) $row['car_id'];
    $destination_id = (int) $destination_id;
    if ($car_id <= 0 || $destination_id <= 0) {
        return false;
    }

    $wb_nbr = str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-E' . str_pad($waybill_counter, 2, '0', STR_PAD_LEFT);
    if (!mysqli_query(
        $dbc,
        'INSERT INTO car_orders (waybill_number, shipment, car) VALUES ("' . $wb_nbr . '", "' . $destination_id . '", "' . $car_id . '")'
    )) {
        return false;
    }

    mysqli_query($dbc, 'UPDATE cars SET status = "Ordered" WHERE id = "' . $car_id . '"');
    mysqli_query(
        $dbc,
        'INSERT INTO history(car_id, session_nbr, event_date, event, location)
         VALUES ("' . $car_id . '",
                 "' . $session_number . '",
                 "' . date('Y-m-d H:i:s') . '",
                 "Repositioned to ' . mysqli_real_escape_string($dbc, $destination_code) . '",
                 "' . (int) $row['current_location_id'] . '")'
    );

    $waybill_counter++;
    return true;
}

function warm_start_reposition_to_home($dbc, $fraction = 1.0, array $filters = [])
{
    $session_number = warm_start_get_session($dbc);
    $waybill_counter = warm_start_next_e_waybill_counter($dbc, $session_number);
    $created = 0;

    $candidates = array_values(array_filter(warm_start_reposition_candidates($dbc), function ($row) use ($filters) {
        if (empty($row['off_home'])) {
            return false;
        }
        return warm_start_reposition_row_matches($row, $filters);
    }));

    shuffle($candidates);
    $limit = (int) ceil(count($candidates) * max(0.0, min(1.0, $fraction)));

    for ($i = 0; $i < $limit; $i++) {
        $row = $candidates[$i];
        if (warm_start_reposition_create_order(
            $dbc,
            $session_number,
            $waybill_counter,
            $row,
            (int) $row['home_location_id'],
            (string) $row['home_code']
        )) {
            $created++;
        }
    }

    return $created;
}

function warm_start_reposition_update($dbc, $fraction = 1.0, array $filters = [], $destination = '')
{
    $destination = trim((string) $destination);
    if ($destination === '') {
        return 0;
    }
    $destination_id = warm_start_location_id_by_code($dbc, strtoupper(str_replace(' ', '-', $destination)));
    if ($destination_id <= 0) {
        return 0;
    }

    $destination_code = '';
    $rs = mysqli_query($dbc, 'SELECT code FROM locations WHERE id = "' . (int) $destination_id . '" LIMIT 1');
    if ($rs && ($row = mysqli_fetch_array($rs))) {
        $destination_code = (string) ($row['code'] ?? '');
    }
    if ($destination_code === '') {
        return 0;
    }

    $session_number = warm_start_get_session($dbc);
    $waybill_counter = warm_start_next_e_waybill_counter($dbc, $session_number);
    $created = 0;

    $candidates = array_values(array_filter(warm_start_reposition_candidates($dbc), function ($row) use ($filters) {
        return warm_start_reposition_row_matches($row, $filters);
    }));

    shuffle($candidates);
    $limit = (int) ceil(count($candidates) * max(0.0, min(1.0, $fraction)));

    for ($i = 0; $i < $limit; $i++) {
        $row = $candidates[$i];
        if ((int) $row['current_location_id'] === $destination_id) {
            continue;
        }
        if (warm_start_reposition_create_order(
            $dbc,
            $session_number,
            $waybill_counter,
            $row,
            $destination_id,
            $destination_code
        )) {
            $created++;
        }
    }

    return $created;
}

function warm_start_create_reposition_orders($dbc, $fraction = 1.0, array $options = [])
{
    $mode = trim((string) ($options['mode'] ?? 'reposition_to_home'));
    if ($mode === '') {
        $mode = 'reposition_to_home';
    }
    $filters = is_array($options['filters'] ?? null) ? $options['filters'] : [];

    if ($mode === 'update') {
        return warm_start_reposition_update(
            $dbc,
            $fraction,
            $filters,
            trim((string) ($options['destination'] ?? ''))
        );
    }

    return warm_start_reposition_to_home($dbc, $fraction, $filters);
}

function warm_start_auto_assign_jobs($dbc, array $job_names)
{
    $assigned = 0;
    foreach ($job_names as $job_name) {
        $job_name = trim((string) $job_name);
        if ($job_name === '') {
            continue;
        }
        $ids = array_keys(auto_assign_eligible_car_ids_for_job($dbc, $job_name, true));
        $assigned += warm_start_assign_cars_to_job($dbc, $job_name, $ids);
    }
    return $assigned;
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

function warm_start_pickup_cars($dbc, $fraction = 1.0, $staging_jobs = [], $skip_staging = false, $skip_jobs = [], &$by_job = null)
{
    $session_number = warm_start_get_session($dbc);
    $picked_up = 0;
    $skip_jobs = array_flip(array_map('strval', $skip_jobs));
    $track_by_job = is_array($by_job);

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
        if ($track_by_job) {
            $by_job[$job_name] = ($by_job[$job_name] ?? 0) + 1;
        }
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

    // Match set_out.php Assign / get_cars_in_job.php final-destination-id rules.
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

    // Empty reposition complete: status → Empty, then delete the E order.
    // Must not rely on joining shipments — for E waybills car_orders.shipment
    // is a location id, not shipments.id (mirrors set_out.php).
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
    if (mysqli_affected_rows($dbc) > 0) {
        mysqli_query($dbc, 'DELETE FROM car_orders WHERE car = "' . (int) $car_id . '"');
    }

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
    if ($row) {
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
    }

    // Any remaining Empty+E order (e.g. prior early-return bug) — drop it.
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

    // Defensive: this car Ordered with no attached order row.
    mysqli_query(
        $dbc,
        'UPDATE cars SET status = "Empty"
         WHERE id = "' . (int) $car_id . '"
           AND status = "Ordered"
           AND id NOT IN (
                 SELECT car FROM (
                     SELECT car FROM car_orders
                     WHERE car IS NOT NULL AND car != "" AND car != "0"
                 ) AS active_cars
           )'
    );
}

function warm_start_setout_cars($dbc, $fraction = 1.0, $staging_jobs = [], $skip_staging = false, $skip_jobs = [], &$by_job = null)
{
    $session_number = warm_start_get_session($dbc);
    $set_out = 0;
    $skip_jobs = array_flip(array_map('strval', $skip_jobs));
    $track_by_job = is_array($by_job);

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
        if ($track_by_job) {
            $by_job[$job_name] = ($by_job[$job_name] ?? 0) + 1;
        }
    }

    if (function_exists('fill_order_heal_orphan_ordered_cars')) {
        fill_order_heal_orphan_ordered_cars($dbc);
    }

    return $set_out;
}

function warm_start_load_unload_filter_token_match($needle, $station, $code)
{
    $needle = trim((string) $needle);
    if ($needle === '') {
        return true;
    }
    $needles = [$needle];
    $hyphen = strpos($needle, ' - ');
    if ($hyphen !== false) {
        $needles[] = substr($needle, 0, $hyphen) . "\n" . substr($needle, $hyphen + 3);
    }
    $station = trim((string) $station);
    $code = trim((string) $code);
    $candidates = array_values(array_unique(array_filter([
        $code,
        $station,
        ($station !== '' && $code !== '') ? $station . ' / ' . $code : '',
        ($station !== '' && $code !== '') ? $station . "\n" . $code : '',
    ])));
    foreach ($needles as $n) {
        $n = trim((string) $n);
        if ($n === '') {
            continue;
        }
        foreach ($candidates as $candidate) {
            if (strcasecmp($candidate, $n) === 0) {
                return true;
            }
            if ($code !== '' && strcasecmp($code, $n) === 0) {
                return true;
            }
            $haystack_len = strlen($candidate);
            $needle_len = strlen($n);
            if ($needle_len > 0 && $haystack_len >= $needle_len
                && substr($candidate, $haystack_len - $needle_len) === $n) {
                return true;
            }
            if (stripos($candidate, $n) !== false) {
                return true;
            }
        }
    }
    return false;
}

function warm_start_load_unload_row_matches(array $row, array $filters)
{
    $checks = [
        'current_location' => ['current_station', 'current_location'],
        'loading_location' => ['loading_station', 'loading_location'],
        'unloading_location' => ['unloading_station', 'unloading_location'],
    ];
    foreach ($checks as $filter_key => $cols) {
        $needle = trim((string) ($filters[$filter_key] ?? ''));
        if ($needle === '') {
            continue;
        }
        if (!warm_start_load_unload_filter_token_match(
            $needle,
            $row[$cols[0]] ?? '',
            $row[$cols[1]] ?? ''
        )) {
            return false;
        }
    }
    if (($filters['car_code'] ?? '') !== ''
        && strcasecmp(trim((string) ($row['car_code'] ?? '')), trim((string) $filters['car_code'])) !== 0) {
        return false;
    }
    if (($filters['status'] ?? '') !== ''
        && strcasecmp(trim((string) ($row['status'] ?? '')), trim((string) $filters['status'])) !== 0) {
        return false;
    }
    if (($filters['commodity'] ?? '') !== '') {
        $commodity = trim((string) ($row['consignment'] ?? $row['commodity'] ?? ''));
        $needle = trim((string) $filters['commodity']);
        if ($commodity === '' && substr((string) ($row['waybill_number'] ?? ''), 4, 1) === 'E') {
            $commodity = 'Non-Revenue';
        }
        if (strcasecmp($commodity, $needle) !== 0 && stripos($commodity, $needle) === false) {
            return false;
        }
    }
    return true;
}

function warm_start_load_unload($dbc, $fraction = 1.0, array $filters = [])
{
    $session_number = warm_start_get_session($dbc);
    $updated = 0;
    $filters = array_filter($filters, static function ($value) {
        return trim((string) $value) !== '';
    });

    $sql = 'SELECT cars.id AS car_id,
                   cars.status,
                   cars.last_spotted,
                   car_orders.shipment,
                   car_orders.waybill_number,
                   shipments.min_load_time,
                   shipments.max_load_time,
                   shipments.min_unload_time,
                   shipments.max_unload_time,
                   car_codes.code AS car_code,
                   sta01.station AS current_station,
                   loc01.code AS current_location,
                   sta02.station AS loading_station,
                   loc02.code AS loading_location,
                   sta03.station AS unloading_station,
                   loc03.code AS unloading_location,
                   commodities.code AS consignment
            FROM cars
            LEFT JOIN car_orders ON car_orders.car = cars.id
            LEFT JOIN shipments ON shipments.id = car_orders.shipment
            LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
            LEFT JOIN locations loc01 ON loc01.id = cars.current_location_id
            LEFT JOIN locations loc02 ON loc02.id = shipments.loading_location
            LEFT JOIN locations loc03 ON loc03.id = shipments.unloading_location
            LEFT JOIN routing sta01 ON sta01.id = loc01.station
            LEFT JOIN routing sta02 ON sta02.id = loc02.station
            LEFT JOIN routing sta03 ON sta03.id = loc03.station
            LEFT JOIN commodities ON commodities.id = shipments.consignment
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
            if (empty($filters) || warm_start_load_unload_row_matches($row, $filters)) {
                $cars[] = $row;
            }
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
                if ($detail) {
                    plugins_run_delivery_complete_hooks($dbc, $car_id, (string) $detail['shipment_code']);
                }
            }
            mysqli_query($dbc, 'UPDATE cars SET status = "Empty", last_spotted = 0 WHERE id = "' . $car_id . '"');
            mysqli_query($dbc, 'DELETE FROM car_orders WHERE car = "' . $car_id . '"');
            $updated++;
        }
    }

    return $updated;
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

/**
 * Warm-start run: seed, then advance sessions. A registered scenario may replace this
 * (extra stop conditions, test sessions).
 */
function warm_start_run($dbc, $config)
{
    return plugins_scenario_call('run', [$dbc, $config], 'warm_start_core_run');
}

function warm_start_core_run($dbc, $config)
{
    $reseed = $config['reseed'] ?? !($config['continue_from_current'] ?? false);
    if ($reseed) {
        mt_srand((int) $config['seed']);
    }
    $continue = (bool) ($config['continue_from_current'] ?? false);
    if (!$continue) {
        warm_start_stats_reset_all();
    }
    warm_start_reset_session_stats_log();

    $repo_fraction = (float) ($config['reposition_fraction'] ?? 0.65);
    $full = warm_start_default_fractions($config);

    $max_unfilled = (int) ($config['max_unfilled_before_generate'] ?? 30);
    $min_sessions = (int) ($config['min_sessions'] ?? $config['completed_sessions'] ?? 3);
    $max_sessions = (int) ($config['max_sessions'] ?? 12);
    $stop_at_staging = (bool) ($config['stop_when_staging_ready'] ?? true);
    $stopped_at_staging = false;
    $staging_backlog = ['eligible' => 0, 'on_jobs' => 0, 'ready' => false];
    $staging_names = implode(' / ', warm_start_staging_job_names($dbc, $config));

    if ($stop_at_staging) {
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
                    warm_start_log('STOP: Staging jobs ready' . ($staging_names !== '' ? ' — ' . $staging_names . ' can run.' : '.'));
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
    }

    warm_start_print_session_stats_report();

    if ($stopped_at_staging) {
        warm_start_log('');
        warm_start_log(
            'Ready for staging — session ' . warm_start_get_session($dbc)
            . ' with ' . $staging_backlog['eligible'] . ' car(s) eligible for ' . ($staging_names !== '' ? $staging_names : 'staging') . '.'
        );
    } elseif ($continue && $stop_at_staging) {
        warm_start_log('');
        warm_start_log('Advanced to session ' . warm_start_get_session($dbc) . '.');
    }

    $summary = warm_start_summarize($dbc);
    $summary['stopped_at_staging'] = $stopped_at_staging;
    $summary['staging_backlog'] = $staging_backlog;
    $summary['session_stats'] = warm_start_session_stats_log();

    return $summary;
}

/** Order/car counts for the current state. A registered scenario may replace this (extra keys). */
function warm_start_summarize($dbc)
{
    return plugins_scenario_call('summary', [$dbc], 'warm_start_core_summarize');
}

function warm_start_core_summarize($dbc)
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

    $staging_jobs = warm_start_staging_job_names($dbc, warm_start_merge_config([]));
    if (count($staging_jobs) > 0) {
        $staging_quoted = implode(', ', array_map(function ($name) {
            return '"' . addslashes($name) . '"';
        }, $staging_jobs));
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
    } else {
        $summary['staging_pending_pickup'] = 0;
    }

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c
         FROM cars
         INNER JOIN jobs ON jobs.id = cars.handled_by_job_id
         WHERE 1 = 1' . warm_start_non_staging_job_filter_sql($staging_jobs) . '
           AND cars.handled_by_job_id > 0'
    );
    $summary['non_staging_on_jobs'] = (int) mysqli_fetch_array($rs)['c'];

    $rs = mysqli_query(
        $dbc,
        'SELECT COUNT(*) AS c FROM car_orders WHERE waybill_number LIKE "%E%"'
    );
    $summary['reposition_orders'] = (int) mysqli_fetch_array($rs)['c'];

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
 * A registered scenario may replace this (extra checks).
 */
function warm_start_evaluate_session_prep($dbc, $config = [])
{
    return plugins_scenario_call('prep_checks', [$dbc, $config], 'warm_start_core_evaluate_session_prep');
}

function warm_start_core_evaluate_session_prep($dbc, $config = [])
{
    $config = array_merge(warm_start_default_config(), $config);
    $staging_jobs = warm_start_staging_job_names($dbc, $config);

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

    $summary = warm_start_summarize($dbc);

    return [
        'session' => warm_start_get_session($dbc),
        'unfilled_count' => count($unfilled_waybills),
        'unfilled_preview' => $unfilled_preview,
        'empties_off_home_count' => (int) ($summary['empties_off_home'] ?? 0),
        'empties_off_home_preview' => $empties_off_home,
        'reposition_orders' => (int) ($summary['reposition_orders'] ?? 0),
        'staging_backlog' => warm_start_staging_backlog($dbc, $config),
        'awaiting_assignment' => (int) ($summary['awaiting_assignment'] ?? 0),
        'job_eligible' => $job_eligible,
        'summary' => $summary,
    ];
}

/**
 * Begin a live operating session from warm-start end state:
 * load/unload, increment session, fill unfilled orders, reposition empties, auto-assign.
 * A registered scenario may replace this (pre-session staging gates).
 */
function warm_start_begin_operating_session($dbc, array $options = [])
{
    return plugins_scenario_call('begin_session', [$dbc, $options], 'warm_start_core_begin_operating_session');
}

function warm_start_core_begin_operating_session($dbc, array $options = [])
{
    $config = warm_start_merge_config($options['config'] ?? []);
    $load_unload = !isset($options['load_unload']) || $options['load_unload'];
    $increment = !isset($options['increment']) || $options['increment'];
    $reposition = !isset($options['reposition']) || $options['reposition'];
    $assign = !isset($options['assign']) || $options['assign'];
    $fill = !isset($options['fill']) || $options['fill'];
    $generate = !empty($options['generate']);
    $repo_fraction = (float) ($options['reposition_fraction'] ?? $config['reposition_fraction'] ?? 0.65);
    $fill_fraction = (float) ($options['fill_fraction'] ?? $config['fill_orders'] ?? 1.0);
    $staging_jobs = warm_start_staging_job_names($dbc, $config);

    $result = [
        'previous_session' => warm_start_get_session($dbc),
        'session' => warm_start_get_session($dbc),
        'load_unload' => 0,
        'filled' => 0,
        'repositioned' => 0,
        'generated' => 0,
        'assigned' => 0,
        'assigned_by_job' => [],
        'warnings' => [],
        'errors' => [],
        'blocked' => false,
        'evaluation' => null,
    ];

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

    if ($fill) {
        $result['filled'] = warm_start_auto_fill($dbc, $fill_fraction);
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

/** Text report for a begin-session result. A registered scenario may replace this. */
function warm_start_format_begin_session_report(array $result)
{
    return plugins_scenario_call('report_lines', [$result], 'warm_start_core_format_begin_session_report');
}

function warm_start_core_format_begin_session_report(array $result)
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
        $lines[] = 'Session: ' . ($result['session'] ?? '?') . ' (unchanged)';
        $lines[] = '';
        $lines[] = 'No session prep was run.';
        return implode(PHP_EOL, $lines);
    }

    if (($result['previous_session'] ?? 0) !== ($result['session'] ?? 0)) {
        $lines[] = 'Session: ' . ($result['previous_session'] ?? '?')
            . ' → ' . ($result['session'] ?? '?');
    } else {
        $lines[] = 'Session: ' . ($result['session'] ?? '?') . ' (not incremented)';
    }
    $lines[] = 'Load/unload transitions: ' . (int) ($result['load_unload'] ?? 0);
    $lines[] = 'Orders filled: ' . (int) ($result['filled'] ?? 0);
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
    $lines[] = 'Awaiting job assignment: ' . (int) ($eval['awaiting_assignment'] ?? 0);
    $backlog = $eval['staging_backlog'] ?? [];
    $lines[] = 'Staging eligible: ' . (int) ($backlog['eligible'] ?? 0);

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
    $filled = (int) ($result['filled'] ?? 0);
    $unfilled = (int) ($eval['unfilled_count'] ?? 0);
    $assigned = (int) ($result['assigned'] ?? 0);
    if ($filled > 0 && $unfilled === 0) {
        $lines[] = 'All eligible orders filled and auto-assigned. Begin operating session per switch lists.';
    } elseif ($filled > 0) {
        $lines[] = "Filled {$filled} order(s); {$unfilled} unfilled remain. Review Orders in STS if needed.";
    } elseif ($unfilled > 0) {
        $lines[] = "{$unfilled} unfilled order(s) remain — no eligible cars matched auto-fill.";
    } else {
        $lines[] = 'No unfilled orders. Begin operating session per switch lists.';
    }
    if ($assigned > 0) {
        $lines[] = "Auto-assigned {$assigned} car(s) to jobs.";
    }

    return implode(PHP_EOL, $lines);
}

if (is_readable(__DIR__ . '/operations_train_car_filters.php')) {
    require_once __DIR__ . '/operations_train_car_filters.php';
}
