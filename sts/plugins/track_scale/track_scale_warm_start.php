<?php
/**
 * Track-scale warm-start hooks (calibrate + CK1 weigh choreography).
 * Loaded by the track_scale plugin; core warm_start calls these via function_exists().
 */

function track_scale_simulate_calibration($dbc)
{
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
function track_scale_maybe_calibrate_scale($dbc, $config = [])
{
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

    $ok = track_scale_simulate_calibration($dbc);

    return [
        'calibrated' => $ok,
        'sessions_since' => $sessions_since,
    ];
}

/**
 * CK1 outbound coke weigh after pickup, before destination setouts.
 */
function track_scale_run_ck1_scale_ops($dbc)
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
        $load_state = track_scale_get_car_load_state($dbc, $marks, $target_net, $config);
        $true_net = (float) $load_state['true_net_tons'];
        $weighing = track_scale_build_display_weighing(
            $true_net,
            $tare,
            $target_net,
            $config,
            (float) ($load_state['balance_shift_tons'] ?? 0.0)
        );
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

function track_scale_run_job_weigh_dispatch($dbc, $job, array $config, array $ts_config)
{
    if (strcasecmp($job, 'CK1') === 0 && function_exists('track_scale_run_ck1_scale_ops')) {
        return track_scale_run_ck1_scale_ops($dbc);
    }

    return track_scale_run_job_weigh($dbc, $job, $ts_config);
}
