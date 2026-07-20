<?php
/**
 * Track-scale warm-start hooks (calibrate + CK1 weigh choreography).
 * Loaded by the track_scale plugin; core warm_start calls these via function_exists().
 */

/**
 * Simulate a completed UI calibration: zero residual on all pads, lock them, save.
 * Previously only marked pads "weighed" — save requires locks + ~0 residual, so the
 * workflow calibrate step silently failed and the scale went OOS after 4 sessions.
 *
 * @return array{success:bool,error?:string}
 */
function track_scale_simulate_calibration($dbc)
{
    track_scale_sync_session_calibration($dbc);
    if (track_scale_is_calibration_locked($dbc)) {
        return ['success' => false, 'error' => 'already_locked'];
    }

    track_scale_session_init();
    track_scale_reset_calibration();
    foreach (track_scale_sensor_positions() as $position) {
        // Perfect zero: error cancelled by adjustment (same end-state as the UI).
        $_SESSION['track_scale']['sensor_errors'][$position] = 0.0;
        $_SESSION['track_scale']['sensor_adjustments'][$position] = 0.0;
        track_scale_mark_sensor_locked($position);
    }

    $result = track_scale_save_calibration($dbc);
    if (empty($result['success'])) {
        return [
            'success' => false,
            'error' => (string) ($result['error'] ?? 'save_calibration failed'),
        ];
    }

    return ['success' => true];
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

    $sim = track_scale_simulate_calibration($dbc);
    $ok = !empty($sim['success']);

    $out = [
        'calibrated' => $ok,
        'sessions_since' => $sessions_since,
    ];
    if (!$ok && !empty($sim['error'])) {
        $out['error'] = $sim['error'];
    }
    if ($ok) {
        $out['sessions_since_after'] = track_scale_sessions_since_calibration($dbc);
        $out['out_of_service'] = track_scale_is_out_of_service($dbc);
    }

    return $out;
}

/**
 * Job-specific weigh path used when warm-start hooks register a named local
 * (historically CK1). Uses the same min-reload batch floor as generic job weigh.
 *
 * @param array|null $config Optional track-scale config (recipe overrides applied).
 */
function track_scale_run_ck1_scale_ops($dbc, $config = null)
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
        'forced_reloads' => 0,
        'errors' => [],
        'success' => true,
    ];

    $config = is_array($config) ? $config : track_scale_load_config();
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

    $batch = [];
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
        $batch[] = [
            'car_id' => $car_id,
            'marks' => $marks,
            'tare' => $tare,
            'target_net' => $target_net,
            'load_state' => $load_state,
            'weighing' => $weighing,
            'routing' => $weighing['routing'] ?? 'outbound',
        ];
    }

    $stats['forced_reloads'] = track_scale_ensure_min_batch_reloads($dbc, $batch, $config);

    foreach ($batch as $item) {
        $car_id = (int) $item['car_id'];
        $marks = (string) $item['marks'];
        $weighing = $item['weighing'];
        $routing = $item['routing'] ?? 'outbound';
        track_scale_record_weigh_log($dbc, $marks, $weighing, $config);

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
        return track_scale_run_ck1_scale_ops($dbc, $ts_config);
    }

    return track_scale_run_job_weigh($dbc, $job, $ts_config);
}
