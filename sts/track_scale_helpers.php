<?php
// track_scale_helpers.php — South Yard track scale (self-contained helpers)
// Config, roster, seed, settings, and session.log live under sts-backups/track_scale.
// In Docker that directory is bind-mounted at sts/backups/track_scale.

function track_scale_data_dir()
{
    $dir = getenv('TRACK_SCALE_DATA_DIR');
    if ($dir !== false && $dir !== '') {
        return rtrim($dir, '/\\');
    }

    $mounted = __DIR__ . '/backups/track_scale';
    $repo = dirname(__DIR__, 2) . '/sts-backups/track_scale';
    if (is_dir($repo) && (
        is_readable($repo . '/track_scale_config.json')
        || is_readable($repo . '/car_card_roster.csv')
        || is_readable($repo . '/seed.json')
        || is_readable($repo . '/settings.json')
        || is_readable($repo . '/session.log')
    )) {
        return $repo;
    }

    return $mounted;
}

function track_scale_config_path()
{
    return track_scale_data_dir() . '/track_scale_config.json';
}

function track_scale_default_config()
{
    return [
        'units' => 'tons',
        'precision' => 2,
        'routing_tolerance_tons' => 5.0,
        'loading_location_code' => 'SOUTH-SCALE',
        'outbound_loading_location_code' => 'NORTH',
        'reload_loading_location_code' => 'SOUTH-SCALE',
        'api_base_url' => '/sts/api/index.php',
        'commodity_code' => 'COKE',
        'shipments' => [
            'outbound' => ['COKE-USS', 'COKE-CLEV', 'COKE-USS-BULK', 'COKE-CLEV-BULK'],
            'reload' => ['COKE-RELOAD-SHEN'],
        ],
        'default_profiles' => [
            '50ft_hopper' => [
                'length_ft' => 50,
                'tare_tons' => 24.75,
                'load_limit_tons' => 77.75,
            ],
            '45ft_hopper' => [
                'length_ft' => 45,
                'tare_tons' => 25.80,
                'load_limit_tons' => 84.20,
            ],
            '40ft_hopper' => [
                'length_ft' => 40,
                'tare_tons' => 27.70,
                'load_limit_tons' => 83.95,
            ],
            'fallback' => [
                'tare_tons' => 27.00,
                'load_limit_tons' => 80.00,
            ],
        ],
        'simulation' => [
            // AAR GIB No. 5 / Circular 42 grain balance rules applied to coke loads.
            // Failures are cross-side imbalance (left vs right sensors), not underweight net.
            'in_tolerance_percent' => 86,
            'in_tolerance_percent_before_oos' => 25,
            // Max left-right gross spread for a balanced load.
            'within_tolerance_spread_tons' => 3.0,
            // Left-right gross spread range for imbalanced loads routed to reload.
            'off_tolerance_min_tons' => 6.0,
            'off_tolerance_max_tons' => 10.0,
        ],
        'calibration' => [
            'adjust_step_tons' => 0.10,
            'fine_adjust_step_tons' => 0.01,
            'zero_offset_random_min_tons' => -1.9,
            'zero_offset_random_max_tons' => 1.9,
            'position_bias_tons' => [
                'left' => -0.50,
                'center' => 0.0,
                'right' => 0.50,
            ],
            'test_car_reporting_marks' => 'COST1',
            'test_car_tare_tons' => 40.0,
        ],
        'calibration_drift' => [
            'session_1' => [0.01, 0.1],
            'session_2' => [0.1, 0.2],
            'session_3' => [0.3, 0.5],
            'out_of_service_after_sessions' => 4,
            'out_of_service_drift_per_session' => 0.2,
        ],
    ];
}

function track_scale_roster_path()
{
    return track_scale_data_dir() . '/car_card_roster.csv';
}

function track_scale_logs_dir()
{
    return track_scale_data_dir();
}

function track_scale_seed_path()
{
    return track_scale_data_dir() . '/seed.json';
}

function track_scale_settings_path()
{
    return track_scale_data_dir() . '/settings.json';
}

function track_scale_session_log_path()
{
    return track_scale_data_dir() . '/session.log';
}

function track_scale_now_unix()
{
    return time();
}

function track_scale_normalize_unix_timestamp($value, $fallback = null)
{
    if ($value === null || $value === '') {
        return $fallback ?? track_scale_now_unix();
    }
    if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
        return (int) $value;
    }
    $parsed = strtotime((string) $value);
    if ($parsed !== false) {
        return (int) $parsed;
    }
    return $fallback ?? track_scale_now_unix();
}

function track_scale_ensure_data_dir($path = null)
{
    $dir = $path ?? track_scale_data_dir();
    if (is_dir($dir)) {
        return true;
    }
    return mkdir($dir, 0755, true) || is_dir($dir);
}

function track_scale_write_json_file($path, array $data)
{
    $dir = dirname($path);
    if (!track_scale_ensure_data_dir($dir)) {
        return false;
    }

    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        return false;
    }

    return rename($tmp, $path);
}

function track_scale_read_json_file($path, array $default)
{
    if (!is_readable($path)) {
        return $default;
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $default;
    }

    return array_merge($default, $decoded);
}

function track_scale_default_settings()
{
    return [
        'version' => 1,
    ];
}

function &track_scale_settings_ref()
{
    static $settings = null;
    return $settings;
}

function track_scale_load_settings()
{
    $settings = &track_scale_settings_ref();
    if ($settings !== null) {
        return $settings;
    }

    $settings = track_scale_read_json_file(track_scale_settings_path(), track_scale_default_settings());
    if (!is_readable(track_scale_settings_path())) {
        track_scale_write_json_file(track_scale_settings_path(), $settings);
    }

    return $settings;
}

function track_scale_save_settings(array $settings)
{
    $settings['version'] = (int) ($settings['version'] ?? 1);
    if (track_scale_write_json_file(track_scale_settings_path(), $settings)) {
        $cache = &track_scale_settings_ref();
        $cache = $settings;
        return $settings;
    }
    return null;
}

function track_scale_clear_last_calibration()
{
    $settings = track_scale_load_settings();
    unset($settings['last_calibration']);
    return track_scale_save_settings($settings);
}

function track_scale_default_seed_state($session_number)
{
    return [
        'version' => 2,
        'session_number' => (int) $session_number,
        'created_at' => track_scale_now_unix(),
        'car_weights' => [],
        'logged_cars' => [],
        'calibration' => track_scale_default_calibration_state(),
    ];
}

function track_scale_default_calibration_state()
{
    return [
        'locked' => false,
        'saved_at' => null,
        'sensor_errors' => [
            'left' => null,
            'center' => null,
            'right' => null,
        ],
        'sensor_adjustments' => [
            'left' => 0.0,
            'center' => 0.0,
            'right' => 0.0,
        ],
    ];
}

function track_scale_seed_created_at(array $seed_state, $path = null)
{
    if (array_key_exists('created_at', $seed_state)) {
        return track_scale_normalize_unix_timestamp($seed_state['created_at']);
    }

    $path = $path ?? track_scale_seed_path();
    if (is_readable($path)) {
        $mtime = filemtime($path);
        if ($mtime !== false) {
            return (int) $mtime;
        }
    }

    return track_scale_now_unix();
}

function track_scale_migrate_legacy_runtime_seed($session_number)
{
    $legacy_path = track_scale_data_dir() . '/runtime.json';
    if (!is_readable($legacy_path)) {
        return null;
    }

    $legacy = track_scale_read_json_file($legacy_path, []);
    $seed = track_scale_default_seed_state($session_number);

    if (is_array($legacy['car_weights'] ?? null)) {
        foreach ($legacy['car_weights'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $marks = strtoupper(trim((string) ($entry['reporting_marks'] ?? '')));
            if ($marks === '' || !array_key_exists('net_tons', $entry)) {
                continue;
            }
            $seed['car_weights'][$marks] = (float) $entry['net_tons'];
        }
        if (!empty($legacy['car_weights'])) {
            $first = reset($legacy['car_weights']);
            if (is_array($first) && !empty($first['first_weighed_at'])) {
                $seed['created_at'] = (string) $first['first_weighed_at'];
            }
        }
    } elseif (is_array($legacy['car_nets'] ?? null)) {
        foreach ($legacy['car_nets'] as $cache_key => $net_tons) {
            $parts = explode(':', (string) $cache_key, 2);
            $marks = strtoupper(trim($parts[1] ?? ''));
            if ($marks !== '') {
                $seed['car_weights'][$marks] = (float) $net_tons;
            }
        }
    }

    if (is_array($legacy['operating_session'] ?? null)) {
        $stored_session = (int) ($legacy['operating_session']['session_number'] ?? 0);
        if ($stored_session > 0) {
            $seed['session_number'] = $stored_session;
        }
    }

    track_scale_write_json_file(track_scale_seed_path(), $seed);
    return $seed;
}

function track_scale_last_calibration_from_settings()
{
    $settings = track_scale_load_settings();
    $last = $settings['last_calibration'] ?? null;
    return is_array($last) ? $last : null;
}

function track_scale_last_calibration_session_number($last = null)
{
    $last = $last ?? track_scale_last_calibration_from_settings();
    if (!is_array($last) || empty($last['saved_at'])) {
        return null;
    }

    return (int) ($last['session_number'] ?? 0);
}

function track_scale_session_before_last_calibration($session_number, $last = null)
{
    $session_number = (int) $session_number;
    $last_session = track_scale_last_calibration_session_number($last);
    if ($last_session === null) {
        return false;
    }

    return $session_number < $last_session;
}

function track_scale_calibration_history_valid_for_session($session_number)
{
    $session_number = (int) $session_number;
    $last = track_scale_last_calibration_from_settings();
    if (!is_array($last) || empty($last['saved_at'])) {
        return false;
    }

    if (track_scale_session_before_last_calibration($session_number, $last)) {
        return false;
    }

    $last_session = (int) ($last['session_number'] ?? 0);
    if ($last_session < 0) {
        return false;
    }

    return $session_number >= $last_session;
}

function track_scale_requires_calibration_init($dbc, $session_number = null)
{
    $session_number = $session_number ?? track_scale_get_session_number($dbc);
    if (track_scale_session_before_last_calibration($session_number)) {
        return true;
    }

    return !track_scale_calibration_history_valid_for_session($session_number);
}

function track_scale_unlock_seed_calibration(array $seed)
{
    $seed['calibration']['locked'] = false;
    $seed['calibration']['saved_at'] = null;
    return track_scale_strip_carried_calibration_from_seed($seed);
}

function track_scale_reset_uninitialized_calibration_state()
{
    track_scale_reset_calibration();
    track_scale_session_init();
    $_SESSION['track_scale']['drift_applied_sessions'] = -1;
}

function track_scale_calibration_history_is_valid($dbc)
{
    if (track_scale_is_calibration_locked($dbc)) {
        return true;
    }

    return track_scale_calibration_history_valid_for_session(
        track_scale_get_session_number($dbc)
    );
}

function track_scale_strip_carried_calibration_from_seed(array $seed)
{
    foreach (track_scale_sensor_positions() as $position) {
        $seed['calibration']['sensor_adjustments'][$position] = 0.0;
        $seed['calibration']['sensor_errors'][$position] = null;
    }

    return $seed;
}

function track_scale_apply_carried_calibration(array $seed)
{
    $session_number = (int) ($seed['session_number'] ?? 0);
    if (!track_scale_calibration_history_valid_for_session($session_number)) {
        return $seed;
    }

    $last = track_scale_last_calibration_from_settings();
    if ($last === null) {
        return $seed;
    }

    if (is_array($last['sensor_errors'] ?? null)) {
        foreach (track_scale_sensor_positions() as $position) {
            if (array_key_exists($position, $last['sensor_errors'])
                && $last['sensor_errors'][$position] !== null
                && $last['sensor_errors'][$position] !== '') {
                $seed['calibration']['sensor_errors'][$position] = (float) $last['sensor_errors'][$position];
            }
        }
    }

    if (is_array($last['sensor_adjustments'] ?? null)) {
        foreach (track_scale_sensor_positions() as $position) {
            if (array_key_exists($position, $last['sensor_adjustments'])) {
                $seed['calibration']['sensor_adjustments'][$position] = (float) $last['sensor_adjustments'][$position];
            }
        }
    }

    return $seed;
}

function track_scale_record_last_calibration($session_number, $saved_at, array $snapshot)
{
    $settings = track_scale_load_settings();
    $settings['last_calibration'] = [
        'session_number' => (int) $session_number,
        'saved_at' => track_scale_normalize_unix_timestamp($saved_at),
        'sensor_errors' => $snapshot['sensor_errors'],
        'sensor_adjustments' => $snapshot['sensor_adjustments'],
    ];
    track_scale_save_settings($settings);
}

function track_scale_get_last_calibration_display_info($dbc)
{
    $seed = track_scale_load_seed_state($dbc);
    $current_session = (int) ($seed['session_number'] ?? track_scale_get_session_number($dbc));

    if (track_scale_is_calibration_locked($dbc)) {
        return [
            'session_number' => $current_session,
            'saved_at' => track_scale_calibration_saved_at($dbc),
            'calibrated_this_session' => true,
            'calibration_unknown' => false,
        ];
    }

    $last = track_scale_last_calibration_from_settings();
    if (is_array($last) && !empty($last['saved_at'])) {
        $last_session = (int) ($last['session_number'] ?? 0);
        if ($current_session < $last_session) {
            return [
                'session_number' => null,
                'saved_at' => null,
                'calibrated_this_session' => false,
                'calibration_unknown' => true,
            ];
        }
        return [
            'session_number' => $last_session,
            'saved_at' => track_scale_normalize_unix_timestamp($last['saved_at']),
            'calibrated_this_session' => false,
            'calibration_unknown' => false,
        ];
    }

    return [
        'session_number' => null,
        'saved_at' => null,
        'calibrated_this_session' => false,
        'calibration_unknown' => true,
    ];
}

function track_scale_sessions_since_calibration($dbc)
{
    if (track_scale_is_calibration_locked($dbc)) {
        return 0;
    }

    $current = track_scale_get_session_number($dbc);
    $last = track_scale_last_calibration_from_settings();
    $last_session = (int) ($last['session_number'] ?? 0);
    if ($last_session < 0 || empty($last['saved_at'])) {
        return max(1, $current);
    }

    if ($current < $last_session) {
        return 0;
    }

    return max(0, $current - $last_session);
}

function track_scale_drift_range_for_sessions($sessions_since, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $drift = $config['calibration_drift'] ?? [];

    if ($sessions_since <= 0) {
        return null;
    }
    if ($sessions_since === 1) {
        return $drift['session_1'] ?? [0.01, 0.1];
    }
    if ($sessions_since === 2) {
        return $drift['session_2'] ?? [0.1, 0.2];
    }
    if ($sessions_since === 3 || $sessions_since === 4) {
        return $drift['session_3'] ?? [0.3, 0.5];
    }

    return null;
}

function track_scale_in_tolerance_percent_for_sessions($sessions_since, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $sim = $config['simulation'] ?? [];
    $base = (float) ($sim['in_tolerance_percent'] ?? 86.0);
    $floor = (float) ($sim['in_tolerance_percent_before_oos'] ?? 25.0);
    $threshold = track_scale_out_of_service_session_threshold($config);

    if ($sessions_since <= 0 || $threshold <= 0) {
        return $base;
    }
    if ($sessions_since >= $threshold) {
        return $floor;
    }

    $t = $sessions_since / $threshold;
    return $base + ($floor - $base) * $t;
}

function track_scale_out_of_service_session_threshold($config = null)
{
    $config = $config ?? track_scale_load_config();
    return (int) ($config['calibration_drift']['out_of_service_after_sessions'] ?? 4);
}

function track_scale_has_ever_been_calibrated($dbc)
{
    if (track_scale_is_calibration_locked($dbc)) {
        return true;
    }

    return track_scale_calibration_history_is_valid($dbc);
}

function track_scale_is_out_of_service($dbc, $config = null)
{
    $config = $config ?? track_scale_load_config();
    if (track_scale_is_calibration_locked($dbc)) {
        return false;
    }

    $current = track_scale_get_session_number($dbc);
    if (track_scale_session_before_last_calibration($current)) {
        return true;
    }

    if (!track_scale_has_ever_been_calibrated($dbc)) {
        return true;
    }

    $sessions = track_scale_sessions_since_calibration($dbc);
    return $sessions > track_scale_out_of_service_session_threshold($config);
}

function track_scale_out_of_service_drift_per_session($config = null)
{
    $config = $config ?? track_scale_load_config();
    return (float) ($config['calibration_drift']['out_of_service_drift_per_session'] ?? 0.2);
}

function track_scale_apply_error_drift($position, $base_error, $config = null)
{
    $config = $config ?? track_scale_load_config();
    track_scale_session_init();

    if (!empty($_SESSION['track_scale']['calibration_locked'])) {
        return track_scale_round((float) $base_error, $config);
    }

    if (empty($_SESSION['track_scale']['calibration_history_valid'])) {
        return track_scale_round((float) $base_error, $config);
    }

    $sessions = (int) ($_SESSION['track_scale']['sessions_since_calibration'] ?? 0);
    if ($sessions <= 0) {
        return track_scale_round((float) $base_error, $config);
    }

    $session_key = (int) ($_SESSION['track_scale']['drift_session_key'] ?? 0);
    $position = track_scale_normalize_position($position);
    $sign = track_scale_deterministic_unit($session_key . '|drift-sign|' . $position, 1) >= 0.5 ? 1 : -1;

    $threshold = track_scale_out_of_service_session_threshold($config);
    if ($sessions > $threshold) {
        $magnitude = track_scale_out_of_service_drift_per_session($config) * $sessions;
        return track_scale_round((float) $base_error + ($sign * $magnitude), $config);
    }

    $range = track_scale_drift_range_for_sessions($sessions, $config);
    if ($range === null) {
        return track_scale_round((float) $base_error, $config);
    }

    $min = (float) $range[0];
    $max = (float) $range[1];
    if ($max < $min) {
        $max = $min;
    }

    $unit = track_scale_deterministic_unit($session_key . '|drift|' . $position, 0);
    $magnitude = $min + $unit * ($max - $min);

    return track_scale_round((float) $base_error + ($sign * $magnitude), $config);
}

function track_scale_build_scale_status($dbc, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $sessions = track_scale_sessions_since_calibration($dbc);
    $out_of_service = track_scale_is_out_of_service($dbc, $config);

    $status = [
        'in_service' => !$out_of_service,
        'out_of_service' => $out_of_service,
        'sessions_since_calibration' => $sessions,
        'calibrated_this_session' => track_scale_is_calibration_locked($dbc),
        'message' => null,
    ];

    if ($out_of_service) {
        if (!track_scale_calibration_history_is_valid($dbc)
            || track_scale_session_before_last_calibration(track_scale_get_session_number($dbc))) {
            $status['needs_initial_calibration'] = true;
            $status['message'] = 'OUT OF SERVICE — initial calibration must be completed before weighing cars.';
        } else {
            $status['needs_initial_calibration'] = false;
            $last = track_scale_get_last_calibration_display_info($dbc);
            $last_session = (int) ($last['session_number'] ?? 0);
            $detail = $last_session > 0 ? ' (last calibrated Session ' . $last_session . ')' : '';
            $status['message'] = 'OUT OF SERVICE — more than '
                . track_scale_out_of_service_session_threshold($config)
                . ' sessions since calibration' . $detail . '. Calibrate before weighing cars.';
        }
    }

    return $status;
}

function track_scale_create_seed_state($session_number)
{
    $session_number = (int) $session_number;
    $seed = track_scale_default_seed_state($session_number);
    $seed = track_scale_apply_carried_calibration($seed);
    track_scale_write_json_file(track_scale_seed_path(), $seed);
    track_scale_init_session_log();
    return $seed;
}

function track_scale_load_seed_state($dbc)
{
    $session_number = track_scale_get_session_number($dbc);
    $path = track_scale_seed_path();

    if (!is_readable($path)) {
        $migrated = track_scale_migrate_legacy_runtime_seed($session_number);
        if ($migrated !== null) {
            if ((int) ($migrated['session_number'] ?? 0) !== $session_number) {
                return track_scale_create_seed_state($session_number);
            }
            track_scale_init_session_log();
            return $migrated;
        }
        return track_scale_create_seed_state($session_number);
    }

    $seed = track_scale_read_json_file($path, track_scale_default_seed_state($session_number));
    if (!is_array($seed['car_weights'] ?? null)) {
        $seed['car_weights'] = [];
    }
    if (!is_array($seed['logged_cars'] ?? null)) {
        $seed['logged_cars'] = [];
    }
    $seed['calibration'] = array_merge(
        track_scale_default_calibration_state(),
        is_array($seed['calibration'] ?? null) ? $seed['calibration'] : []
    );
    $seed['created_at'] = track_scale_seed_created_at($seed, $path);

    if ((int) ($seed['session_number'] ?? -1) !== $session_number) {
        return track_scale_create_seed_state($session_number);
    }

    $cal_locked = !empty($seed['calibration']['locked']);
    $history_valid = track_scale_calibration_history_valid_for_session($session_number);
    if (!$history_valid) {
        $seed = track_scale_unlock_seed_calibration($seed);
        track_scale_write_json_file(track_scale_seed_path(), $seed);
    } elseif ($cal_locked) {
        $normalized = track_scale_normalize_seed_calibration_lock($seed, $dbc);
        if ($normalized !== $seed) {
            track_scale_write_json_file(track_scale_seed_path(), $normalized);
            $seed = $normalized;
        }
    }

    track_scale_init_session_log();
    return $seed;
}

function track_scale_save_seed_state(array $seed)
{
    $seed['version'] = 2;
    if (!is_array($seed['logged_cars'] ?? null)) {
        $seed['logged_cars'] = [];
    }
    $seed['calibration'] = array_merge(
        track_scale_default_calibration_state(),
        is_array($seed['calibration'] ?? null) ? $seed['calibration'] : []
    );
    return track_scale_write_json_file(track_scale_seed_path(), $seed);
}

function track_scale_reset_cached_weights($dbc = null, $preserve_calibration = true)
{
    track_scale_ensure_data_dir();

    $session_number = 1;
    if ($dbc !== null) {
        $session_number = track_scale_get_session_number($dbc);
    }

    $calibration = null;
    $path = track_scale_seed_path();
    if (is_readable($path)) {
        $existing = track_scale_read_json_file($path, []);
        if ((int) ($existing['session_number'] ?? 0) > 0 && $dbc === null) {
            $session_number = (int) $existing['session_number'];
        }
        if ($preserve_calibration && !empty($existing['calibration']['locked'])) {
            $calibration = $existing['calibration'];
        }
    }

    $seed = track_scale_default_seed_state($session_number);
    if ($calibration !== null) {
        $seed['calibration'] = $calibration;
    }

    track_scale_write_json_file($path, $seed);
    return $seed;
}

function track_scale_is_calibration_locked($dbc, array $seed = null)
{
    $seed = $seed ?? track_scale_load_seed_state($dbc);
    if (empty($seed['calibration']['locked'])) {
        return false;
    }

    $current = track_scale_get_session_number($dbc);
    if (track_scale_session_before_last_calibration($current)) {
        return false;
    }

    if ((int) ($seed['session_number'] ?? -1) !== $current) {
        return false;
    }

    $last = track_scale_last_calibration_from_settings();
    if (!is_array($last) || empty($last['saved_at'])) {
        return false;
    }

    return (int) ($last['session_number'] ?? -1) === $current;
}

function track_scale_normalize_seed_calibration_lock(array $seed, $dbc)
{
    if (empty($seed['calibration']['locked'])) {
        return $seed;
    }

    if (track_scale_is_calibration_locked($dbc, $seed)) {
        return $seed;
    }

    $seed['calibration']['locked'] = false;
    $seed['calibration']['saved_at'] = null;

    return $seed;
}

function track_scale_require_calibration_unlocked($dbc)
{
    if (track_scale_is_calibration_locked($dbc)) {
        return 'Calibration is saved and locked for this operating session';
    }
    return null;
}

function track_scale_maybe_backfill_last_calibration($dbc)
{
    if (track_scale_last_calibration_from_settings() !== null) {
        return;
    }
    if (!track_scale_is_calibration_locked($dbc)) {
        return;
    }

    $seed = track_scale_load_seed_state($dbc);
    $cal = $seed['calibration'] ?? [];
    if (empty($cal['saved_at'])) {
        return;
    }

    track_scale_record_last_calibration(
        (int) ($seed['session_number'] ?? track_scale_get_session_number($dbc)),
        $cal['saved_at'],
        [
            'sensor_errors' => $cal['sensor_errors'] ?? [],
            'sensor_adjustments' => $cal['sensor_adjustments'] ?? [],
        ]
    );
}

function track_scale_sync_session_calibration($dbc)
{
    track_scale_maybe_backfill_last_calibration($dbc);
    track_scale_session_init();
    $seed = track_scale_load_seed_state($dbc);
    $session_number = (int) ($seed['session_number'] ?? track_scale_get_session_number($dbc));
    $cal = $seed['calibration'] ?? track_scale_default_calibration_state();
    $synced_session = (int) ($_SESSION['track_scale']['synced_session_number'] ?? -1);
    $session_changed = $synced_session !== $session_number;

    if (track_scale_requires_calibration_init($dbc, $session_number)) {
        $init_reset_for = (int) ($_SESSION['track_scale']['calibration_init_reset_for'] ?? -1);
        $needs_init_reset = $session_changed || $init_reset_for !== $session_number;

        if ($needs_init_reset) {
            $seed = track_scale_unlock_seed_calibration($seed);
            track_scale_save_seed_state($seed);
            track_scale_reset_calibration();
            $_SESSION['track_scale']['calibration_init_reset_for'] = $session_number;
            $_SESSION['track_scale']['synced_session_number'] = $session_number;
        } elseif (!empty($seed['calibration']['locked'])) {
            $seed = track_scale_unlock_seed_calibration($seed);
            track_scale_save_seed_state($seed);
        }

        $_SESSION['track_scale']['calibration_locked'] = false;
        $_SESSION['track_scale']['sessions_since_calibration'] = track_scale_sessions_since_calibration($dbc);
        $_SESSION['track_scale']['drift_session_key'] = $session_number;
        $_SESSION['track_scale']['out_of_service'] = track_scale_is_out_of_service($dbc);
        $_SESSION['track_scale']['calibration_history_valid'] = false;
        $_SESSION['track_scale']['drift_applied_sessions'] = -1;
        $_SESSION['track_scale']['synced_session_number'] = $session_number;
        return;
    }

    unset($_SESSION['track_scale']['calibration_init_reset_for']);

    if (track_scale_is_calibration_locked($dbc, $seed)) {
        $_SESSION['track_scale']['calibration_locked'] = true;
        $_SESSION['track_scale']['synced_session_number'] = $session_number;
        $_SESSION['track_scale']['sessions_since_calibration'] = 0;
        $_SESSION['track_scale']['drift_session_key'] = $session_number;
        $_SESSION['track_scale']['out_of_service'] = false;
        $_SESSION['track_scale']['calibration_history_valid'] = true;
        foreach (track_scale_sensor_positions() as $position) {
            if (array_key_exists($position, $cal['sensor_errors'] ?? [])
                && $cal['sensor_errors'][$position] !== null) {
                $_SESSION['track_scale']['sensor_errors'][$position] = (float) $cal['sensor_errors'][$position];
            }
            if (array_key_exists($position, $cal['sensor_adjustments'] ?? [])) {
                $_SESSION['track_scale']['sensor_adjustments'][$position] = (float) $cal['sensor_adjustments'][$position];
            }
            $_SESSION['track_scale']['sensor_weighed'][$position] = true;
        }
        return;
    }

    $cal_valid = track_scale_calibration_history_is_valid($dbc);

    // Stale lock flag in seed — only carry prior values when history is still valid.
    if (!empty($cal['locked']) && $cal_valid) {
        $last = track_scale_last_calibration_from_settings();
        if (is_array($last)) {
            foreach (track_scale_sensor_positions() as $position) {
                if (($cal['sensor_errors'][$position] ?? null) === null
                    && array_key_exists($position, $last['sensor_errors'] ?? [])
                    && $last['sensor_errors'][$position] !== null) {
                    $cal['sensor_errors'][$position] = (float) $last['sensor_errors'][$position];
                }
                if ((float) ($cal['sensor_adjustments'][$position] ?? 0.0) === 0.0
                    && array_key_exists($position, $last['sensor_adjustments'] ?? [])) {
                    $cal['sensor_adjustments'][$position] = (float) $last['sensor_adjustments'][$position];
                }
            }
        }
    }

    $_SESSION['track_scale']['calibration_locked'] = false;
    $_SESSION['track_scale']['sessions_since_calibration'] = track_scale_sessions_since_calibration($dbc);
    $_SESSION['track_scale']['drift_session_key'] = $session_number;
    $_SESSION['track_scale']['out_of_service'] = track_scale_is_out_of_service($dbc);
    $_SESSION['track_scale']['calibration_history_valid'] = $cal_valid;

    if (!$cal_valid) {
        track_scale_reset_uninitialized_calibration_state();
        if (!$session_changed) {
            return;
        }
    }

    $last = track_scale_last_calibration_from_settings();
    $last_session = is_array($last) ? (int) ($last['session_number'] ?? -1) : -1;
    $needs_drift = $cal_valid && $last_session >= 0 && $session_number > $last_session;
    $sessions_since = (int) ($_SESSION['track_scale']['sessions_since_calibration'] ?? 0);
    $stored_drift_sessions = (int) ($_SESSION['track_scale']['drift_applied_sessions'] ?? -1);
    $should_apply_drift = $needs_drift && (
        empty($_SESSION['track_scale']['sensor_errors'])
        || $stored_drift_sessions !== $sessions_since
    );

    if (!$session_changed) {
        if ($should_apply_drift) {
            $config = track_scale_load_config();
            foreach (track_scale_sensor_positions() as $position) {
                $err = $cal['sensor_errors'][$position] ?? null;
                if (($err === null || $err === '') && is_array($last)) {
                    $err = $last['sensor_errors'][$position] ?? null;
                }
                if ($err !== null && $err !== '') {
                    $_SESSION['track_scale']['sensor_errors'][$position] = track_scale_apply_error_drift(
                        $position,
                        (float) $err,
                        $config
                    );
                }
                if ((float) ($_SESSION['track_scale']['sensor_adjustments'][$position] ?? 0.0) === 0.0) {
                    $adj = $cal['sensor_adjustments'][$position] ?? null;
                    if ($adj === null && is_array($last)) {
                        $adj = $last['sensor_adjustments'][$position] ?? 0.0;
                    }
                    $_SESSION['track_scale']['sensor_adjustments'][$position] = (float) ($adj ?? 0.0);
                }
            }
            $_SESSION['track_scale']['drift_applied_sessions'] = $sessions_since;
        }
        $_SESSION['track_scale']['synced_session_number'] = $session_number;
        return;
    }

    $_SESSION['track_scale']['synced_session_number'] = $session_number;
    $_SESSION['track_scale']['sensor_weighed'] = [
        'left' => false,
        'center' => false,
        'right' => false,
    ];
    $_SESSION['track_scale']['sensor_errors'] = [];
    $config = track_scale_load_config();

    if (!$cal_valid) {
        foreach (track_scale_sensor_positions() as $position) {
            $_SESSION['track_scale']['sensor_adjustments'][$position] = 0.0;
        }
        $seed = track_scale_strip_carried_calibration_from_seed($seed);
        track_scale_save_seed_state($seed);
        return;
    }

    foreach (track_scale_sensor_positions() as $position) {
        $err = $cal['sensor_errors'][$position] ?? null;
        if ($err !== null && $err !== '') {
            $_SESSION['track_scale']['sensor_errors'][$position] = track_scale_apply_error_drift(
                $position,
                (float) $err,
                $config
            );
        }
        $_SESSION['track_scale']['sensor_adjustments'][$position] = (float) ($cal['sensor_adjustments'][$position] ?? 0.0);
    }
    $_SESSION['track_scale']['drift_applied_sessions'] = $sessions_since;
}

function track_scale_sync_locked_calibration($dbc)
{
    track_scale_sync_session_calibration($dbc);
}

function track_scale_sensor_values_from_readings(array $sensor_readings, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $values = [
        'left' => '',
        'center' => '',
        'right' => '',
    ];
    foreach ($sensor_readings as $reading) {
        $position = track_scale_normalize_position($reading['position'] ?? '');
        if (!array_key_exists($position, $values)) {
            continue;
        }
        $values[$position] = track_scale_round($reading['display_tons'] ?? 0.0, $config);
    }
    return $values;
}

function track_scale_collect_calibration_snapshot($config = null)
{
    $config = $config ?? track_scale_load_config();
    track_scale_session_init();
    $expected = track_scale_test_car_expected_gross($config);
    $snapshot = [
        'sensor_errors' => [],
        'sensor_adjustments' => [],
        'sensor_readings' => [],
    ];

    foreach (track_scale_sensor_positions() as $position) {
        $snapshot['sensor_errors'][$position] = track_scale_round(
            track_scale_get_sensor_error($position, $config),
            $config
        );
        $snapshot['sensor_adjustments'][$position] = track_scale_round(
            track_scale_get_sensor_adjustment($position),
            $config
        );
        $snapshot['sensor_readings'][] = [
            'position' => $position,
            'display_tons' => track_scale_sensor_display_tons($position, $expected, $config),
        ];
    }

    return $snapshot;
}

function track_scale_save_calibration($dbc, $config = null)
{
    $config = $config ?? track_scale_load_config();
    if (track_scale_is_calibration_locked($dbc)) {
        return ['success' => false, 'error' => 'Calibration is already saved for this operating session'];
    }

    $calibration = track_scale_build_calibration_readings($config, $dbc);
    if (!track_scale_calibration_ready_to_save($config)) {
        return ['success' => false, 'error' => 'All three sensors must read zero error before saving calibration'];
    }

    $snapshot = track_scale_collect_calibration_snapshot($config);
    $seed = track_scale_load_seed_state($dbc);
    $session_number = (int) ($seed['session_number'] ?? track_scale_get_session_number($dbc));
    $seed_created_at = track_scale_seed_created_at($seed);
    $saved_at = track_scale_now_unix();

    $seed['calibration'] = [
        'locked' => true,
        'saved_at' => $saved_at,
        'sensor_errors' => $snapshot['sensor_errors'],
        'sensor_adjustments' => $snapshot['sensor_adjustments'],
    ];
    track_scale_save_seed_state($seed);
    track_scale_record_last_calibration($session_number, $saved_at, $snapshot);
    track_scale_sync_session_calibration($dbc);

    $sensor_values = track_scale_sensor_values_from_readings($snapshot['sensor_readings'], $config);
    track_scale_append_session_log_row([
        'record_type' => 'calibration',
        'session_number' => $session_number,
        'seed_created_at' => $seed_created_at,
        'event_at' => track_scale_now_unix(),
        'left_tons' => $sensor_values['left'],
        'center_tons' => $sensor_values['center'],
        'right_tons' => $sensor_values['right'],
        'left_error' => $snapshot['sensor_errors']['left'],
        'center_error' => $snapshot['sensor_errors']['center'],
        'right_error' => $snapshot['sensor_errors']['right'],
        'left_adj' => $snapshot['sensor_adjustments']['left'],
        'center_adj' => $snapshot['sensor_adjustments']['center'],
        'right_adj' => $snapshot['sensor_adjustments']['right'],
    ], $config);

    return [
        'success' => true,
        'calibration' => track_scale_build_calibration_readings($config, $dbc),
    ];
}

function track_scale_init_session_log()
{
    if (!track_scale_ensure_data_dir()) {
        return false;
    }

    $path = track_scale_session_log_path();
    if (is_readable($path)) {
        return true;
    }

    $handle = fopen($path, 'wb');
    if ($handle === false) {
        return false;
    }

    fputcsv($handle, [
        'record_type',
        'session_number',
        'seed_created_at',
        'event_at',
        'reporting_marks',
        'true_net_tons',
        'display_net_tons',
        'left_tons',
        'center_tons',
        'right_tons',
        'scale_calibrated',
        'left_error',
        'center_error',
        'right_error',
        'left_adj',
        'center_adj',
        'right_adj',
    ]);
    fclose($handle);
    return true;
}

function track_scale_append_session_log_row(array $row, $config = null)
{
    $config = $config ?? track_scale_load_config();
    track_scale_init_session_log();

    $path = track_scale_session_log_path();
    $handle = fopen($path, 'ab');
    if ($handle === false) {
        return false;
    }

    fputcsv($handle, [
        (string) ($row['record_type'] ?? ''),
        (int) ($row['session_number'] ?? 0),
        track_scale_normalize_unix_timestamp($row['seed_created_at'] ?? null),
        track_scale_normalize_unix_timestamp($row['event_at'] ?? null),
        (string) ($row['reporting_marks'] ?? ''),
        $row['true_net_tons'] ?? '',
        $row['display_net_tons'] ?? '',
        $row['left_tons'] ?? '',
        $row['center_tons'] ?? '',
        $row['right_tons'] ?? '',
        (string) ($row['scale_calibrated'] ?? ''),
        $row['left_error'] ?? '',
        $row['center_error'] ?? '',
        $row['right_error'] ?? '',
        $row['left_adj'] ?? '',
        $row['center_adj'] ?? '',
        $row['right_adj'] ?? '',
    ]);
    fclose($handle);
    return true;
}

function track_scale_load_config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = track_scale_default_config();
    $path = track_scale_config_path();
    if (!is_readable($path)) {
        $config = $defaults;
        return $config;
    }

    $config = track_scale_read_json_file($path, $defaults);
    return $config;
}

function track_scale_round($value, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $precision = (int) ($config['precision'] ?? 2);
    return round((float) $value, $precision);
}

function track_scale_load_roster()
{
    static $roster = null;
    if ($roster !== null) {
        return $roster;
    }

    $roster = [];
    $path = track_scale_roster_path();
    if (!is_readable($path)) {
        return $roster;
    }

    $handle = fopen($path, 'r');
    if ($handle === false) {
        return $roster;
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return $roster;
    }

    while (($row = fgetcsv($handle)) !== false) {
        $entry = array_combine($headers, $row);
        if (!$entry) {
            continue;
        }
        $marks = strtoupper(trim($entry['reporting_marks'] ?? ''));
        if ($marks === '') {
            continue;
        }
        $roster[$marks] = $entry;
    }
    fclose($handle);

    return $roster;
}

function track_scale_default_profile_for_length($length_ft, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $defaults = $config['default_profiles'] ?? [];
    $length_ft = (int) $length_ft;

    if ($length_ft >= 50 && isset($defaults['50ft_hopper'])) {
        return $defaults['50ft_hopper'];
    }
    if ($length_ft >= 45 && $length_ft < 50 && isset($defaults['45ft_hopper'])) {
        return $defaults['45ft_hopper'];
    }
    if ($length_ft >= 40 && $length_ft < 45 && isset($defaults['40ft_hopper'])) {
        return $defaults['40ft_hopper'];
    }
    if ($length_ft > 0 && $length_ft < 40 && isset($defaults['40ft_hopper'])) {
        return $defaults['40ft_hopper'];
    }

    return $defaults['fallback'] ?? ['tare_tons' => 27.0, 'load_limit_tons' => 80.0];
}

function track_scale_is_tare_only_car($reporting_marks, $row, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $marks = strtoupper(trim($reporting_marks));
    $test_marks = strtoupper(trim((string) (($config['calibration'] ?? [])['test_car_reporting_marks'] ?? '')));
    if ($test_marks !== '' && $marks === $test_marks) {
        return true;
    }
    if (is_array($row)) {
        $car_type = strtoupper(trim((string) ($row['car_type'] ?? '')));
        $has_tare = ($row['tare_tons'] ?? '') !== '';
        $has_load = ($row['load_limit_tons'] ?? '') !== '';
        if ($car_type === 'MOW' && $has_tare && !$has_load) {
            return true;
        }
    }
    return false;
}

function track_scale_profile_for_marks($reporting_marks, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $marks = strtoupper(trim($reporting_marks));
    $roster = track_scale_load_roster();
    $row = $roster[$marks] ?? null;

    if (track_scale_is_tare_only_car($marks, $row, $config)) {
        $tare = ($row !== null && ($row['tare_tons'] ?? '') !== '')
            ? (float) $row['tare_tons']
            : track_scale_test_car_expected_gross($config);
        return [
            'reporting_marks' => $marks,
            'car_type' => $row['car_type'] ?? '',
            'length_ft' => (int) ($row['length_ft'] ?? 0),
            'tare_tons' => track_scale_round($tare, $config),
            'load_limit_tons' => null,
            'capy_tons' => null,
            'target_net_tons' => null,
            'tare_only' => true,
            'profile_source' => 'roster',
        ];
    }

    $length_ft = (int) ($row['length_ft'] ?? 0);
    $default = track_scale_default_profile_for_length($length_ft, $config);

    $tare = ($row['tare_tons'] ?? '') !== '' ? (float) $row['tare_tons'] : (float) ($default['tare_tons'] ?? 27.0);
    $load_limit = ($row['load_limit_tons'] ?? '') !== '' ? (float) $row['load_limit_tons'] : (float) ($default['load_limit_tons'] ?? 80.0);
    $capy = ($row['capy_tons'] ?? '') !== '' ? (float) $row['capy_tons'] : null;

    return [
        'reporting_marks' => $marks,
        'car_type' => $row['car_type'] ?? '',
        'length_ft' => $length_ft,
        'tare_tons' => track_scale_round($tare, $config),
        'load_limit_tons' => track_scale_round($load_limit, $config),
        'capy_tons' => $capy !== null ? track_scale_round($capy, $config) : null,
        'target_net_tons' => track_scale_round($load_limit, $config),
        'tare_only' => false,
        'profile_source' => ($row['tare_tons'] ?? '') !== '' ? 'roster' : 'default',
    ];
}

function track_scale_session_init()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['track_scale'])) {
        $_SESSION['track_scale'] = [
            'sensor_errors' => [],
            'sensor_adjustments' => [
                'left' => 0.0,
                'center' => 0.0,
                'right' => 0.0,
            ],
            'scale_car_position' => 'left',
            'sensor_weighed' => [
                'left' => false,
                'center' => false,
                'right' => false,
            ],
        ];
    }
    if (!isset($_SESSION['track_scale']['sensor_adjustments'])) {
        $_SESSION['track_scale']['sensor_adjustments'] = [
            'left' => 0.0,
            'center' => 0.0,
            'right' => 0.0,
        ];
    }
    if (!array_key_exists('scale_car_position', $_SESSION['track_scale'])) {
        $_SESSION['track_scale']['scale_car_position'] = 'left';
    }
    if (!isset($_SESSION['track_scale']['sensor_weighed'])) {
        $_SESSION['track_scale']['sensor_weighed'] = [
            'left' => false,
            'center' => false,
            'right' => false,
        ];
    }
    if (!isset($_SESSION['track_scale']['sensor_fine_tune'])) {
        $_SESSION['track_scale']['sensor_fine_tune'] = [
            'left' => false,
            'center' => false,
            'right' => false,
        ];
    }
}

function track_scale_sensor_positions()
{
    return ['left', 'center', 'right'];
}

function track_scale_get_sensor_adjustment($position)
{
    track_scale_session_init();
    $position = track_scale_normalize_position($position);
    return (float) ($_SESSION['track_scale']['sensor_adjustments'][$position] ?? 0.0);
}

function track_scale_set_sensor_adjustment($position, $value)
{
    track_scale_session_init();
    $position = track_scale_normalize_position($position);
    $_SESSION['track_scale']['sensor_adjustments'][$position] = (float) $value;
}

function track_scale_get_sensor_fine_tune($position)
{
    track_scale_session_init();
    $position = track_scale_normalize_position($position);
    return !empty($_SESSION['track_scale']['sensor_fine_tune'][$position]);
}

function track_scale_set_sensor_fine_tune($position, $enabled)
{
    track_scale_session_init();
    $position = track_scale_normalize_position($position);
    $_SESSION['track_scale']['sensor_fine_tune'][$position] = (bool) $enabled;
}

function track_scale_get_adjust_step($position, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $cal = $config['calibration'] ?? [];
    if (track_scale_get_sensor_fine_tune($position)) {
        return (float) ($cal['fine_adjust_step_tons'] ?? 0.01);
    }
    return (float) ($cal['adjust_step_tons'] ?? 0.1);
}

function track_scale_adjust_sensor($position, $direction, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $step = track_scale_get_adjust_step($position, $config);
    $current = track_scale_get_sensor_adjustment($position);
    if ($direction === 'down') {
        $current -= $step;
    } else {
        $current += $step;
    }
    track_scale_set_sensor_adjustment($position, $current);
    return track_scale_round($current, $config);
}

function track_scale_reset_sensor_adjustment($position, $config = null)
{
    $config = $config ?? track_scale_load_config();
    track_scale_set_sensor_adjustment($position, 0.0);
    return track_scale_round(0.0, $config);
}

function track_scale_generate_sensor_error($position, $config = null)
{
    $config = $config ?? track_scale_load_config();
    track_scale_session_init();
    $position = track_scale_normalize_position($position);
    $cal = $config['calibration'] ?? [];
    $min = (float) ($cal['zero_offset_random_min_tons'] ?? -3.0);
    $max = (float) ($cal['zero_offset_random_max_tons'] ?? 3.0);
    $bias = (float) (($cal['position_bias_tons'][$position] ?? 0.0));
    $session_key = (int) ($_SESSION['track_scale']['drift_session_key'] ?? 0);
    $unit = track_scale_deterministic_unit($session_key . '|sensor-error|' . $position, 0);
    $random = $min + $unit * ($max - $min);

    return track_scale_round($random + $bias, $config);
}

function track_scale_get_sensor_error($position, $config = null)
{
    $config = $config ?? track_scale_load_config();
    track_scale_session_init();

    $position = track_scale_normalize_position($position);
    if (array_key_exists($position, $_SESSION['track_scale']['sensor_errors'] ?? [])) {
        return (float) $_SESSION['track_scale']['sensor_errors'][$position];
    }

    $base = track_scale_generate_sensor_error($position, $config);
    $_SESSION['track_scale']['sensor_errors'][$position] = track_scale_apply_error_drift($position, $base, $config);

    return (float) $_SESSION['track_scale']['sensor_errors'][$position];
}

function track_scale_reset_calibration()
{
    track_scale_session_init();
    $_SESSION['track_scale']['sensor_errors'] = [];
    $_SESSION['track_scale']['sensor_adjustments'] = [
        'left' => 0.0,
        'center' => 0.0,
        'right' => 0.0,
    ];
    $_SESSION['track_scale']['scale_car_position'] = 'left';
    $_SESSION['track_scale']['sensor_weighed'] = [
        'left' => false,
        'center' => false,
        'right' => false,
    ];
    $_SESSION['track_scale']['sensor_fine_tune'] = [
        'left' => false,
        'center' => false,
        'right' => false,
    ];
}

function track_scale_clear_saved_calibration_lock($dbc)
{
    $seed = track_scale_load_seed_state($dbc);
    $seed = track_scale_unlock_seed_calibration($seed);
    track_scale_save_seed_state($seed);
    track_scale_clear_last_calibration();
    track_scale_reset_calibration();
    track_scale_session_init();

    $session_number = (int) ($seed['session_number'] ?? track_scale_get_session_number($dbc));
    $_SESSION['track_scale']['calibration_locked'] = false;
    $_SESSION['track_scale']['calibration_history_valid'] = false;
    $_SESSION['track_scale']['sessions_since_calibration'] = track_scale_sessions_since_calibration($dbc);
    $_SESSION['track_scale']['out_of_service'] = true;
    $_SESSION['track_scale']['drift_session_key'] = $session_number;
    $_SESSION['track_scale']['drift_applied_sessions'] = -1;
    $_SESSION['track_scale']['synced_session_number'] = $session_number;
    unset($_SESSION['track_scale']['calibration_init_reset_for']);
}

function track_scale_calibration_ready_to_save($config = null)
{
    $config = $config ?? track_scale_load_config();
    track_scale_session_init();

    foreach (track_scale_sensor_positions() as $position) {
        if (!track_scale_sensor_has_reading($position)) {
            return false;
        }

        $error = track_scale_get_sensor_error($position, $config);
        $adjustment = track_scale_get_sensor_adjustment($position);
        $residual = track_scale_round($error + $adjustment, $config);
        if (abs($residual) >= 0.005) {
            return false;
        }
    }

    return true;
}

function track_scale_get_scale_car_position()
{
    track_scale_session_init();
    $position = $_SESSION['track_scale']['scale_car_position'] ?? null;
    if ($position === null || $position === '') {
        $_SESSION['track_scale']['scale_car_position'] = 'left';
        return 'left';
    }
    return track_scale_normalize_position($position);
}

function track_scale_set_scale_car_position($position)
{
    track_scale_session_init();
    if ($position === null || $position === '') {
        $_SESSION['track_scale']['scale_car_position'] = 'left';
        return;
    }
    $_SESSION['track_scale']['scale_car_position'] = track_scale_normalize_position($position);
}

function track_scale_sensor_has_reading($position)
{
    track_scale_session_init();
    $position = track_scale_normalize_position($position);
    return !empty($_SESSION['track_scale']['sensor_weighed'][$position]);
}

function track_scale_mark_sensor_weighed($position)
{
    track_scale_session_init();
    $position = track_scale_normalize_position($position);
    $_SESSION['track_scale']['sensor_weighed'][$position] = true;
}

function track_scale_is_test_car_marks($reporting_marks, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $marks = strtoupper(trim((string) $reporting_marks));
    $test_marks = strtoupper(trim((string) (($config['calibration'] ?? [])['test_car_reporting_marks'] ?? '')));
    return $test_marks !== '' && $marks === $test_marks;
}

function track_scale_sensor_display_tons($position, $true_gross, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $error = track_scale_get_sensor_error($position, $config);
    $adjustment = track_scale_get_sensor_adjustment($position);
    $raw = (float) $true_gross + $error;
    return track_scale_round($raw + $adjustment, $config);
}

function track_scale_average_sensor_display_tons($true_gross, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $values = [];
    foreach (track_scale_sensor_positions() as $position) {
        $values[] = track_scale_sensor_display_tons($position, $true_gross, $config);
    }
    if (count($values) === 0) {
        return track_scale_round($true_gross, $config);
    }
    return track_scale_round(array_sum($values) / count($values), $config);
}

function track_scale_build_calibration_sensor_reading($position, $expected_gross, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $position = track_scale_normalize_position($position);
    $error = track_scale_get_sensor_error($position, $config);
    $adjustment = track_scale_get_sensor_adjustment($position);
    $raw_display = track_scale_round((float) $expected_gross + $error, $config);
    $residual_error = track_scale_round($error + $adjustment, $config);

    return [
        'position' => $position,
        'display_tons' => $raw_display,
        'expected_tons' => track_scale_round($expected_gross, $config),
        'error_tons' => $residual_error,
        'sensor_error_tons' => track_scale_round($error, $config),
        'adjustment_tons' => track_scale_round($adjustment, $config),
        'is_zero' => abs($residual_error) < 0.005,
    ];
}

function track_scale_build_sensor_reading($position, $true_gross, $expected_gross, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $position = track_scale_normalize_position($position);
    $error = track_scale_get_sensor_error($position, $config);
    $adjustment = track_scale_get_sensor_adjustment($position);
    $display = track_scale_sensor_display_tons($position, $true_gross, $config);
    $error_from_expected = track_scale_round($display - (float) $expected_gross, $config);

    return [
        'position' => $position,
        'display_tons' => $display,
        'expected_tons' => track_scale_round($expected_gross, $config),
        'error_tons' => $error_from_expected,
        'sensor_error_tons' => track_scale_round($error, $config),
        'adjustment_tons' => track_scale_round($adjustment, $config),
        'is_zero' => abs($error_from_expected) < 0.005,
    ];
}

function track_scale_test_car_expected_gross($config = null)
{
    $config = $config ?? track_scale_load_config();
    $cal = $config['calibration'] ?? [];
    $marks = trim((string) ($cal['test_car_reporting_marks'] ?? ''));
    if ($marks !== '') {
        $profile = track_scale_profile_for_marks($marks, $config);
        if ((float) ($profile['tare_tons'] ?? 0) > 0) {
            return (float) $profile['tare_tons'];
        }
    }
    return (float) ($cal['test_car_tare_tons'] ?? 40.0);
}

function track_scale_test_car_info($config = null)
{
    $config = $config ?? track_scale_load_config();
    $cal = $config['calibration'] ?? [];
    $marks = trim((string) ($cal['test_car_reporting_marks'] ?? ''));
    $profile = $marks !== '' ? track_scale_profile_for_marks($marks, $config) : null;
    $tare = track_scale_test_car_expected_gross($config);

    $info = [
        'reporting_marks' => $marks,
        'tare_tons' => track_scale_round($tare, $config),
        'tare_lbs' => (int) round($tare * 2000),
        'car_type' => $profile['car_type'] ?? '',
        'length_ft' => $profile['length_ft'] ?? 0,
        'image_url' => null,
        'car_id' => null,
    ];

    return $info;
}

function track_scale_test_car_info_with_db($dbc, $config = null)
{
    $info = track_scale_test_car_info($config);
    if ($info['reporting_marks'] === '') {
        return $info;
    }
    $car = track_scale_lookup_car($dbc, $info['reporting_marks']);
    if ($car !== null) {
        $info['car_id'] = $car['id'];
        $info['image_url'] = './ImageStore/DB_Images/RollingStock/' . $car['id'] . '.jpg';
        $info['status'] = $car['status'];
        $info['current_location'] = $car['current_location'] ?? '';
        $info['at_scale'] = track_scale_car_at_scale($car, $config);
    }
    return $info;
}

function track_scale_test_car_at_scale($dbc, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $marks = trim((string) (($config['calibration'] ?? [])['test_car_reporting_marks'] ?? ''));
    if ($marks === '') {
        return false;
    }
    $car = track_scale_lookup_car($dbc, $marks);
    return $car !== null && track_scale_car_at_scale($car, $config);
}

function track_scale_build_calibration_readings($config = null, $dbc = null)
{
    $config = $config ?? track_scale_load_config();
    $expected = track_scale_test_car_expected_gross($config);
    $active_position = track_scale_get_scale_car_position();
    $test_car_at_scale = ($dbc !== null) && track_scale_test_car_at_scale($dbc, $config);

    $calibration_locked = ($dbc !== null) && track_scale_is_calibration_locked($dbc);

    $sensors = [];
    $average_adjustment_values = [];
    $average_corrected_values = [];
    foreach (track_scale_sensor_positions() as $position) {
        $car_at_position = $test_car_at_scale && ($active_position === $position);
        $has_reading = track_scale_sensor_has_reading($position) || $calibration_locked;
        $adjustment = track_scale_round(track_scale_get_sensor_adjustment($position), $config);

        $fine_tune = track_scale_get_sensor_fine_tune($position);
        $adjust_step = track_scale_get_adjust_step($position, $config);

        if (!$has_reading) {
            $sensor_error = track_scale_round(track_scale_get_sensor_error($position, $config), $config);
            $sensors[] = [
                'position' => $position,
                'display_tons' => $calibration_locked ? null : track_scale_round(0.0, $config),
                'expected_tons' => track_scale_round($expected, $config),
                'error_tons' => null,
                'sensor_error_tons' => $sensor_error,
                'adjustment_tons' => $adjustment,
                'fine_tune' => $fine_tune,
                'adjust_step_tons' => track_scale_round($adjust_step, $config),
                'is_zero' => false,
                'has_reading' => false,
                'car_at_position' => $car_at_position,
                'adjustment_locked' => true,
            ];
            continue;
        }

        $reading = track_scale_build_calibration_sensor_reading($position, $expected, $config);
        $reading['has_reading'] = true;
        $reading['car_at_position'] = $car_at_position;
        $reading['adjustment_locked'] = $calibration_locked || !$car_at_position;
        $reading['fine_tune'] = $fine_tune;
        $reading['adjust_step_tons'] = track_scale_round($adjust_step, $config);
        $sensors[] = $reading;
        $average_adjustment_values[] = $reading['adjustment_tons'];
        $average_corrected_values[] = track_scale_round(
            $reading['display_tons'] + $reading['adjustment_tons'],
            $config
        );
    }

    $average = null;
    if (count($average_adjustment_values) > 0) {
        $average_adjustment = track_scale_round(
            array_sum($average_adjustment_values) / count($average_adjustment_values),
            $config
        );
        $average_display = track_scale_round(
            array_sum($average_corrected_values) / count($average_corrected_values),
            $config
        );
        $average_residual_error = track_scale_round($average_display - (float) $expected, $config);
        $average = [
            'display_tons' => $average_display,
            'expected_tons' => track_scale_round($expected, $config),
            'adjustment_tons' => $average_adjustment,
            'error_tons' => $average_residual_error,
            'is_zero' => abs($average_residual_error) < 0.005,
            'sensor_count' => count($average_adjustment_values),
        ];
    } elseif (!$calibration_locked) {
        $average = [
            'display_tons' => track_scale_round(0.0, $config),
            'expected_tons' => track_scale_round($expected, $config),
            'adjustment_tons' => null,
            'error_tons' => null,
            'is_zero' => false,
            'sensor_count' => 0,
        ];
    }

    $calibrated_sensors = array_filter($sensors, function ($sensor) {
        return !empty($sensor['has_reading']);
    });

    return [
        'expected_tons' => track_scale_round($expected, $config),
        'scale_car_position' => $active_position,
        'scale_location' => track_scale_loading_location_code($config),
        'test_car_at_scale' => $test_car_at_scale,
        'test_car' => $dbc !== null
            ? track_scale_test_car_info_with_db($dbc, $config)
            : track_scale_test_car_info($config),
        'sensors' => $sensors,
        'average' => $average,
        'all_calibrated' => count($calibrated_sensors) === 3
            && !in_array(false, array_column($calibrated_sensors, 'is_zero'), true),
        'calibration_locked' => $calibration_locked,
        'calibrated_this_session' => $calibration_locked,
        'calibration_saved_at' => $dbc !== null
            ? track_scale_calibration_saved_at($dbc)
            : null,
        'last_calibration' => $dbc !== null
            ? track_scale_get_last_calibration_display_info($dbc)
            : null,
        'scale_status' => $dbc !== null
            ? track_scale_build_scale_status($dbc, $config)
            : null,
    ];
}

function track_scale_calibration_saved_at($dbc)
{
    $saved = track_scale_load_seed_state($dbc)['calibration']['saved_at'] ?? null;
    if ($saved === null || $saved === '') {
        return null;
    }
    return track_scale_normalize_unix_timestamp($saved);
}

function track_scale_normalize_position($position)
{
    $position = strtolower(trim((string) $position));
    if (!in_array($position, ['left', 'center', 'right'], true)) {
        return 'center';
    }
    return $position;
}

function track_scale_routing_tolerance_tons($config = null)
{
    $config = $config ?? track_scale_load_config();
    if (isset($config['routing_tolerance_tons'])) {
        return (float) $config['routing_tolerance_tons'];
    }
    // Legacy key before routing/calibration tolerances were split.
    if (isset($config['reload_tolerance_tons'])) {
        return (float) $config['reload_tolerance_tons'];
    }
    return 5.0;
}

function track_scale_normalize_car_load_entry($entry)
{
    if (is_array($entry)) {
        return [
            'true_net_tons' => (float) ($entry['true_net_tons'] ?? $entry['net_tons'] ?? 0.0),
            'balance_shift_tons' => (float) ($entry['balance_shift_tons'] ?? 0.0),
        ];
    }

    return [
        'true_net_tons' => (float) $entry,
        'balance_shift_tons' => 0.0,
    ];
}

function track_scale_simulate_car_load($target_net, $config = null, $seed_key = '', $sessions_since = 0)
{
    $config = $config ?? track_scale_load_config();
    $sim = $config['simulation'] ?? [];
    $target = (float) $target_net;
    $balance_tolerance = track_scale_routing_tolerance_tons($config);

    $in_tolerance_pct = track_scale_in_tolerance_percent_for_sessions((int) $sessions_since, $config);
    $within_spread = (float) ($sim['within_tolerance_spread_tons'] ?? min(4.5, $balance_tolerance));
    $off_min = (float) ($sim['off_tolerance_min_tons'] ?? ($balance_tolerance + 0.5));
    $off_max = (float) ($sim['off_tolerance_max_tons'] ?? ($balance_tolerance + 9.0));

    if ($within_spread > $balance_tolerance) {
        $within_spread = $balance_tolerance;
    }
    if ($off_min <= $balance_tolerance) {
        $off_min = $balance_tolerance + 0.1;
    }
    if ($off_max < $off_min) {
        $off_max = $off_min;
    }

    if ($seed_key === '') {
        $seed_key = '0';
    }

    // Net stays near target; reload routing is driven by cross-side balance only.
    $net_jitter = track_scale_deterministic_unit($seed_key, 4) * 2.0 - 1.0;
    $true_net = max(0.0, min($target, $target + $net_jitter));

    $roll = track_scale_deterministic_unit($seed_key, 0) * 100.0;
    if ($roll < $in_tolerance_pct) {
        $lr_spread = track_scale_deterministic_unit($seed_key, 1) * $within_spread;
    } else {
        $lr_spread = $off_min + track_scale_deterministic_unit($seed_key, 2) * ($off_max - $off_min);
    }
    $sign = track_scale_deterministic_unit($seed_key, 3) >= 0.5 ? 1 : -1;
    $balance_shift = $sign * ($lr_spread / 2.0);

    return [
        'true_net_tons' => track_scale_round($true_net, $config),
        'balance_shift_tons' => track_scale_round($balance_shift, $config),
    ];
}

function track_scale_simulate_net_tons($target_net, $config = null, $seed_key = '', $sessions_since = 0)
{
    $load = track_scale_simulate_car_load($target_net, $config, $seed_key, $sessions_since);
    return (float) $load['true_net_tons'];
}

function track_scale_get_car_load_state($dbc, $reporting_marks, $target_net, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $seed = track_scale_load_seed_state($dbc);
    $session_number = (int) ($seed['session_number'] ?? track_scale_get_session_number($dbc));
    $marks = strtoupper(trim((string) $reporting_marks));
    $seed_created_at = track_scale_seed_created_at($seed);

    if (array_key_exists($marks, $seed['car_weights'])) {
        return track_scale_normalize_car_load_entry($seed['car_weights'][$marks]);
    }

    $seed_key = $session_number . '|' . $marks . '|' . $seed_created_at;
    $sessions_since = track_scale_sessions_since_calibration($dbc);
    $load = track_scale_simulate_car_load($target_net, $config, $seed_key, $sessions_since);

    $seed['car_weights'][$marks] = [
        'true_net_tons' => $load['true_net_tons'],
        'balance_shift_tons' => $load['balance_shift_tons'],
    ];
    track_scale_save_seed_state($seed);

    return $load;
}

function track_scale_get_car_true_net($dbc, $reporting_marks, $target_net, $config = null)
{
    $load = track_scale_get_car_load_state($dbc, $reporting_marks, $target_net, $config);
    return (float) $load['true_net_tons'];
}

function track_scale_sensor_true_gross_values($tare, $true_net, $balance_shift)
{
    $base = (float) $tare + (float) $true_net;
    $shift = (float) $balance_shift;

    return [
        'left' => $base + $shift,
        'center' => $base,
        'right' => $base - $shift,
    ];
}

function track_scale_build_sensor_readings($true_gross, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $readings = [];
    foreach (track_scale_sensor_positions() as $position) {
        $readings[] = [
            'position' => $position,
            'display_tons' => track_scale_sensor_display_tons($position, $true_gross, $config),
        ];
    }
    return $readings;
}

function track_scale_build_sensor_readings_for_load($tare, $true_net, $balance_shift, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $gross_by_position = track_scale_sensor_true_gross_values($tare, $true_net, $balance_shift);
    $readings = [];
    foreach (track_scale_sensor_positions() as $position) {
        $readings[] = [
            'position' => $position,
            'display_tons' => track_scale_sensor_display_tons(
                $position,
                $gross_by_position[$position],
                $config
            ),
        ];
    }
    return $readings;
}

function track_scale_classify_load_balance(array $sensor_readings, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $tolerance = track_scale_routing_tolerance_tons($config);
    $left = 0.0;
    $right = 0.0;

    foreach ($sensor_readings as $reading) {
        $position = strtolower(trim((string) ($reading['position'] ?? '')));
        if ($position === 'left') {
            $left = (float) ($reading['display_tons'] ?? 0.0);
        } elseif ($position === 'right') {
            $right = (float) ($reading['display_tons'] ?? 0.0);
        }
    }

    $delta = abs($left - $right);
    $in_tolerance = $delta <= $tolerance;

    return [
        'in_tolerance' => $in_tolerance,
        'balance_delta_tons' => track_scale_round($delta, $config),
        'tolerance_tons' => track_scale_round($tolerance, $config),
        'routing' => $in_tolerance ? 'outbound' : 'reload',
        'failure_reason' => $in_tolerance ? null : 'imbalanced',
    ];
}

function track_scale_build_display_weighing($true_net, $tare, $target_net, $config = null, $balance_shift = 0.0)
{
    $config = $config ?? track_scale_load_config();
    $true_net = (float) $true_net;
    $tare = (float) $tare;
    $balance_shift = (float) $balance_shift;
    $true_gross = $true_net + $tare;
    $sensor_readings = track_scale_build_sensor_readings_for_load($tare, $true_net, $balance_shift, $config);
    $display_values = array_column($sensor_readings, 'display_tons');
    $display_gross = track_scale_round(
        count($display_values) > 0 ? array_sum($display_values) / count($display_values) : $true_gross,
        $config
    );
    $display_net = track_scale_round($display_gross - $tare, $config);
    $classification = track_scale_classify_load_balance($sensor_readings, $config);

    return [
        'true_net_tons' => track_scale_round($true_net, $config),
        'true_gross_tons' => track_scale_round($true_gross, $config),
        'gross_tons' => $display_gross,
        'net_tons' => $display_net,
        'tare_tons' => track_scale_round($tare, $config),
        'target_net_tons' => track_scale_round($target_net, $config),
        'delta_tons' => $classification['balance_delta_tons'],
        'net_delta_tons' => track_scale_round(abs($display_net - (float) $target_net), $config),
        'balance_shift_tons' => track_scale_round($balance_shift, $config),
        'tolerance_tons' => $classification['tolerance_tons'],
        'in_tolerance' => $classification['in_tolerance'],
        'routing' => $classification['routing'],
        'failure_reason' => $classification['failure_reason'],
        'sensor_readings' => $sensor_readings,
    ];
}

function track_scale_record_weigh_log($dbc, $reporting_marks, array $reading, $config = null)
{
    $config = $config ?? track_scale_load_config();
    if (!empty($reading['test_car_weigh']) || !empty($reading['unloaded_weigh'])) {
        return true;
    }

    $seed = track_scale_load_seed_state($dbc);
    $session_number = (int) ($seed['session_number'] ?? track_scale_get_session_number($dbc));
    $marks = strtoupper(trim((string) $reporting_marks));
    $seed_created_at = track_scale_seed_created_at($seed);

    if (!is_array($seed['logged_cars'] ?? null)) {
        $seed['logged_cars'] = [];
    }
    if (in_array($marks, $seed['logged_cars'], true)) {
        return true;
    }

    $sensor_values = track_scale_sensor_values_from_readings($reading['sensor_readings'] ?? [], $config);
    track_scale_append_session_log_row([
        'record_type' => 'weigh',
        'session_number' => $session_number,
        'seed_created_at' => $seed_created_at,
        'event_at' => track_scale_now_unix(),
        'reporting_marks' => $marks,
        'true_net_tons' => track_scale_round($reading['true_net_tons'] ?? 0.0, $config),
        'display_net_tons' => track_scale_round($reading['net_tons'] ?? 0.0, $config),
        'left_tons' => $sensor_values['left'],
        'center_tons' => $sensor_values['center'],
        'right_tons' => $sensor_values['right'],
        'scale_calibrated' => track_scale_is_calibration_locked($dbc) ? 'yes' : 'no',
    ], $config);

    $seed['logged_cars'][] = $marks;
    track_scale_save_seed_state($seed);
    return true;
}

function track_scale_loading_location_code($config = null)
{
    $config = $config ?? track_scale_load_config();
    return (string) ($config['loading_location_code'] ?? 'SOUTH-SCALE');
}

function track_scale_south_yard_routing_id($dbc, $config = null)
{
    static $cache = [];
    $config = $config ?? track_scale_load_config();
    $key = track_scale_loading_location_code($config);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $code = mysqli_real_escape_string($dbc, $key);
    $sql = 'SELECT station FROM locations WHERE code = "' . $code . '" LIMIT 1';
    $rs = mysqli_query($dbc, $sql);
    $row = $rs ? mysqli_fetch_assoc($rs) : null;
    $cache[$key] = $row ? (int) ($row['station'] ?? 0) : 0;

    return $cache[$key];
}

function track_scale_job_ids_with_scale_destinations($dbc, $config = null)
{
    static $cache = [];
    $config = $config ?? track_scale_load_config();
    $location_code = track_scale_loading_location_code($config);
    if (array_key_exists($location_code, $cache)) {
        return $cache[$location_code];
    }

    $escaped = mysqli_real_escape_string($dbc, $location_code);
    $sql = 'SELECT DISTINCT cars.handled_by_job_id AS job_id
            FROM cars
            INNER JOIN car_orders co ON co.car = cars.id
            INNER JOIN shipments ON shipments.id = co.shipment
            INNER JOIN locations loc ON loc.id = shipments.unloading_location
            WHERE cars.current_location_id = 0
              AND cars.handled_by_job_id > 0
              AND cars.status != "Unavailable"
              AND loc.code = "' . $escaped . '"';

    $job_ids = [];
    $rs = mysqli_query($dbc, $sql);
    while ($rs && ($row = mysqli_fetch_assoc($rs))) {
        $job_id = (int) ($row['job_id'] ?? 0);
        if ($job_id > 0) {
            $job_ids[] = $job_id;
        }
    }

    $cache[$location_code] = $job_ids;
    return $job_ids;
}

function track_scale_job_ids_for_scale_trains($dbc, $config = null)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $routing_ids = track_scale_job_ids_for_south_yard_routing($dbc, $config);
    if (count($routing_ids) === 0) {
        $cache = [];
        return $cache;
    }

    $ids_sql = implode(', ', array_map('intval', $routing_ids));
    $sql = 'SELECT DISTINCT cars.handled_by_job_id AS job_id
            FROM cars
            WHERE cars.current_location_id = 0
              AND cars.handled_by_job_id IN (' . $ids_sql . ')
              AND cars.status != "Unavailable"';

    $job_ids = [];
    $rs = mysqli_query($dbc, $sql);
    while ($rs && ($row = mysqli_fetch_assoc($rs))) {
        $job_id = (int) ($row['job_id'] ?? 0);
        if ($job_id > 0) {
            $job_ids[] = $job_id;
        }
    }

    $cache = $job_ids;
    return $cache;
}

function track_scale_job_ids_for_south_yard_routing($dbc, $config = null)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $routing_id = track_scale_south_yard_routing_id($dbc, $config);
    if ($routing_id <= 0) {
        $cache = [];
        return $cache;
    }

    $job_ids = [];
    $rs = mysqli_query($dbc, 'SELECT Id, name FROM jobs ORDER BY Id');
    while ($rs && ($job = mysqli_fetch_assoc($rs))) {
        $table = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($job['name'] ?? ''));
        if ($table === '') {
            continue;
        }

        $step_rs = mysqli_query(
            $dbc,
            'SELECT COUNT(*) AS cnt FROM `' . $table . '` WHERE station = ' . (int) $routing_id
        );
        if (!$step_rs) {
            continue;
        }
        $row = mysqli_fetch_assoc($step_rs);
        if ((int) ($row['cnt'] ?? 0) > 0) {
            $job_ids[] = (int) $job['Id'];
        }
    }

    $cache = $job_ids;
    return $cache;
}

function track_scale_car_in_south_yard_train($car, $dbc, $config = null)
{
    if (!is_array($car)) {
        return false;
    }

    $location_id = (int) ($car['current_location_id'] ?? -1);
    $job_id = (int) ($car['handled_by_job_id'] ?? 0);
    if ($location_id !== 0 || $job_id <= 0) {
        return false;
    }

    $status = strtoupper(trim((string) ($car['status'] ?? '')));
    if ($status === 'UNAVAILABLE') {
        return false;
    }

    return in_array($job_id, track_scale_job_ids_for_south_yard_routing($dbc, $config), true);
}

function track_scale_car_weighable($car, $dbc, $config = null)
{
    return track_scale_car_at_scale($car, $config)
        || track_scale_car_in_south_yard_train($car, $dbc, $config);
}

function track_scale_weighable_car_error($car, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $scale_location = track_scale_loading_location_code($config);
    $current = trim((string) ($car['current_location'] ?? ''));
    $train_job = trim((string) ($car['train_job'] ?? ''));
    if ($current === '' || strcasecmp($current, 'In train') === 0) {
        if ($train_job !== '') {
            $current = 'in train (' . $train_job . ')';
        } elseif ((int) ($car['handled_by_job_id'] ?? 0) > 0) {
            $current = 'in train (job ' . (int) $car['handled_by_job_id'] . ')';
        } elseif ($current === '') {
            $current = 'unknown';
        }
    }

    return 'Car must be at ' . $scale_location
        . ' or in a train routed to South Yard to weigh (currently at ' . $current . ')';
}

function track_scale_car_at_scale($car, $config = null)
{
    if (!is_array($car)) {
        return false;
    }
    $required = track_scale_loading_location_code($config);
    $current = strtoupper(trim((string) ($car['current_location'] ?? '')));
    return $current !== '' && $current === strtoupper($required);
}

function track_scale_car_has_load($car)
{
    if (!is_array($car)) {
        return false;
    }
    $status = strtoupper(trim((string) ($car['status'] ?? '')));
    return in_array($status, ['LOADED', 'LOADING', 'UNLOADING'], true);
}

function track_scale_display_status($status)
{
    return trim((string) $status);
}

function track_scale_car_weighs_unloaded($car)
{
    return !track_scale_car_has_load($car);
}

function track_scale_classify_net($net_tons, $target_net, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $tolerance = track_scale_routing_tolerance_tons($config);
    $delta = abs((float) $net_tons - (float) $target_net);
    $in_tolerance = $delta <= $tolerance;

    return [
        'in_tolerance' => $in_tolerance,
        'delta_tons' => track_scale_round($delta, $config),
        'tolerance_tons' => track_scale_round($tolerance, $config),
        'routing' => $in_tolerance ? 'outbound' : 'reload',
        'failure_reason' => $in_tolerance ? null : 'underweight',
    ];
}

function track_scale_lookup_car($dbc, $scan_code)
{
    $scan_code = trim((string) $scan_code);
    if ($scan_code === '') {
        return null;
    }

    $escaped = mysqli_real_escape_string($dbc, $scan_code);
    $upper = mysqli_real_escape_string($dbc, strtoupper($scan_code));

    $sql = 'SELECT cars.id as id,
                   cars.reporting_marks as reporting_marks,
                   cars.status as status,
                   cars.position as position,
                   cars.current_location_id as current_location_id,
                   cars.handled_by_job_id as handled_by_job_id,
                   cars.car_code_id as car_code_id,
                   car_codes.code as car_code,
                   loc.code as current_location,
                   jobs.name as train_job
            FROM cars
            LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
            LEFT JOIN locations loc ON loc.id = cars.current_location_id
            LEFT JOIN jobs ON jobs.Id = cars.handled_by_job_id
            WHERE cars.reporting_marks = "' . $upper . '"
               OR cars.RFID_code = "' . $escaped . '"';

    if ((substr($scan_code, 0, 1) === '-') && (substr($scan_code, -1) === '-')) {
        $car_id = substr($scan_code, 1, strlen($scan_code) - 2);
        $car_id = mysqli_real_escape_string($dbc, $car_id);
        $sql .= ' OR cars.id = "' . $car_id . '"';
    }

    $rs = mysqli_query($dbc, $sql);
    if (!$rs || mysqli_num_rows($rs) < 1) {
        return null;
    }

    return mysqli_fetch_assoc($rs);
}

function track_scale_get_car_by_id($dbc, $car_id)
{
    $car_id = trim((string) $car_id);
    if ($car_id === '') {
        return null;
    }
    return track_scale_lookup_car($dbc, '-' . $car_id . '-');
}

function track_scale_is_weigh_list_car($car, $config = null)
{
    if (!is_array($car)) {
        return false;
    }
    if (track_scale_is_test_car_marks($car['reporting_marks'] ?? '', $config)) {
        return false;
    }
    $profile = track_scale_profile_for_marks($car['reporting_marks'] ?? '', $config);
    return empty($profile['tare_only']);
}

function track_scale_counts_toward_weigh_stat($car, $dbc, $config = null)
{
    $config = $config ?? track_scale_load_config();
    if (!is_array($car) || $dbc === null) {
        return false;
    }
    if (track_scale_is_test_car_marks($car['reporting_marks'] ?? '', $config)) {
        return false;
    }
    $profile = track_scale_profile_for_marks($car['reporting_marks'] ?? '', $config);
    if (!empty($profile['tare_only'])) {
        return false;
    }
    if (!track_scale_car_weighable($car, $dbc, $config)) {
        return false;
    }
    // Match the weigh UI: empty/tare-only weighs do not offer order assignment.
    if (track_scale_car_weighs_unloaded($car)) {
        return false;
    }
    if (track_scale_car_needs_assignment($car, $dbc, $config)) {
        return true;
    }
    return track_scale_car_allows_scale_reassign($car, $dbc, $config);
}

function track_scale_get_south_yard_train_jobs($dbc, $config = null)
{
    $job_ids = track_scale_job_ids_for_scale_trains($dbc, $config);
    if (count($job_ids) === 0) {
        return [];
    }

    $jobs = [];
    $rs = mysqli_query(
        $dbc,
        'SELECT Id, name FROM jobs WHERE Id IN (' . implode(', ', array_map('intval', $job_ids)) . ') ORDER BY name'
    );
    while ($rs && ($row = mysqli_fetch_assoc($rs))) {
        $jobs[] = [
            'id' => (int) $row['Id'],
            'name' => (string) $row['name'],
        ];
    }

    return $jobs;
}

function track_scale_sort_weigh_cars(array $cars, $train_only = false)
{
    usort($cars, function ($a, $b) use ($train_only) {
        if (!$train_only) {
            $a_scale = (($a['weigh_source'] ?? '') === 'at_scale') ? 0 : 1;
            $b_scale = (($b['weigh_source'] ?? '') === 'at_scale') ? 0 : 1;
            if ($a_scale !== $b_scale) {
                return $a_scale <=> $b_scale;
            }
            $train_cmp = strcmp((string) ($a['train_job'] ?? ''), (string) ($b['train_job'] ?? ''));
            if ($train_cmp !== 0) {
                return $train_cmp;
            }
        }

        $pos_a = (int) ($a['position'] ?? 0);
        $pos_b = (int) ($b['position'] ?? 0);
        if ($pos_a !== $pos_b) {
            return $pos_a <=> $pos_b;
        }

        return strcmp((string) ($a['reporting_marks'] ?? ''), (string) ($b['reporting_marks'] ?? ''));
    });

    return $cars;
}

function track_scale_get_cars_at_scale($dbc, $config = null, $filter = null)
{
    $config = $config ?? track_scale_load_config();
    $filter = trim((string) ($filter ?? ''));
    $location_code = mysqli_real_escape_string($dbc, track_scale_loading_location_code($config));
    $job_ids = track_scale_job_ids_for_south_yard_routing($dbc, $config);
    $job_filter = count($job_ids) > 0
        ? ' AND cars.handled_by_job_id IN (' . implode(', ', array_map('intval', $job_ids)) . ')'
        : ' AND 1 = 0';

    $cars = [];

    if ($filter === '' || $filter === 'scale') {
        $sql = 'SELECT cars.id as id,
                       cars.reporting_marks as reporting_marks,
                       cars.status as status,
                       cars.position as position,
                       cars.current_location_id as current_location_id,
                       cars.handled_by_job_id as handled_by_job_id,
                       car_codes.code as car_code,
                       loc.code as current_location,
                       "at_scale" as weigh_source,
                       NULL as train_job
                FROM cars
                INNER JOIN locations loc ON loc.id = cars.current_location_id
                LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
                WHERE loc.code = "' . $location_code . '"';

        $rs = mysqli_query($dbc, $sql);
        while ($rs && ($row = mysqli_fetch_assoc($rs))) {
            $cars[] = $row;
        }
    }

    if ($filter === '' || ($filter !== 'scale' && ctype_digit($filter))) {
        $train_filter = $job_filter;
        if ($filter !== '' && $filter !== 'scale') {
            $train_filter = ' AND cars.handled_by_job_id = ' . (int) $filter;
        }

        $sql = 'SELECT cars.id as id,
                       cars.reporting_marks as reporting_marks,
                       cars.status as status,
                       cars.position as position,
                       cars.current_location_id as current_location_id,
                       cars.handled_by_job_id as handled_by_job_id,
                       car_codes.code as car_code,
                       "In train" as current_location,
                       "in_train" as weigh_source,
                       jobs.name as train_job
                FROM cars
                INNER JOIN jobs ON jobs.Id = cars.handled_by_job_id
                LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
                WHERE cars.current_location_id = 0
                  AND cars.status != "Unavailable"'
            . $train_filter;

        $rs = mysqli_query($dbc, $sql);
        while ($rs && ($row = mysqli_fetch_assoc($rs))) {
            $cars[] = $row;
        }
    }

    $train_only = ($filter !== '' && $filter !== 'scale');
    $cars = track_scale_sort_weigh_cars($cars, $train_only);

    return $cars;
}

function track_scale_car_weigh_source($car, $dbc, $config = null)
{
    if (track_scale_car_at_scale($car, $config)) {
        return 'at_scale';
    }
    if (track_scale_car_in_south_yard_train($car, $dbc, $config)) {
        return 'in_train';
    }

    return null;
}

function track_scale_get_next_car_in_train($dbc, $car, $config = null)
{
    $config = $config ?? track_scale_load_config();
    if (!is_array($car) || track_scale_car_weigh_source($car, $dbc, $config) !== 'in_train') {
        return null;
    }

    $job_id = (int) ($car['handled_by_job_id'] ?? 0);
    if ($job_id <= 0) {
        return null;
    }

    $train_cars = track_scale_get_cars_at_scale($dbc, $config, (string) $job_id);
    $found_current = false;
    foreach ($train_cars as $candidate) {
        if (!track_scale_is_weigh_list_car($candidate, $config)) {
            continue;
        }
        if (!$found_current) {
            if ((int) $candidate['id'] === (int) $car['id']) {
                $found_current = true;
            }
            continue;
        }

        return $candidate;
    }

    return null;
}

function track_scale_count_weighable_cars($dbc, $config = null)
{
    $count = 0;
    foreach (track_scale_get_cars_at_scale($dbc, $config) as $car) {
        if (track_scale_counts_toward_weigh_stat($car, $dbc, $config)) {
            $count++;
        }
    }
    return $count;
}

function track_scale_get_car_active_order($dbc, $car_id)
{
    $car_id = mysqli_real_escape_string($dbc, (string) $car_id);
    $sql = 'SELECT co.waybill_number AS waybill_number,
                   shipments.code AS shipment_code,
                   loc_unload.code AS unloading_location
            FROM car_orders co
            INNER JOIN shipments ON shipments.id = co.shipment
            LEFT JOIN locations loc_unload ON loc_unload.id = shipments.unloading_location
            WHERE co.car = "' . $car_id . '"
            LIMIT 1';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs || mysqli_num_rows($rs) < 1) {
        return null;
    }
    return mysqli_fetch_assoc($rs);
}

function track_scale_car_active_order_unloads_at_scale($dbc, $car_id, $config = null)
{
    $order = track_scale_get_car_active_order($dbc, $car_id);
    if ($order === null) {
        return false;
    }
    $scale_code = strtoupper(track_scale_loading_location_code($config));
    $unload_code = strtoupper(trim((string) ($order['unloading_location'] ?? '')));
    return $unload_code !== '' && $unload_code === $scale_code;
}

function track_scale_car_requires_train_reassign_confirm($car, $dbc, $config = null)
{
    if ($dbc === null || !is_array($car)) {
        return false;
    }
    if (!track_scale_car_in_south_yard_train($car, $dbc, $config)) {
        return false;
    }
    return track_scale_car_active_order_unloads_at_scale($dbc, $car['id'], $config);
}

function track_scale_car_in_coke_fleet($dbc, $car_id, $config = null)
{
    if ($dbc === null) {
        return false;
    }
    $config = $config ?? track_scale_load_config();
    $car_id = mysqli_real_escape_string($dbc, (string) $car_id);
    $commodity_code = mysqli_real_escape_string($dbc, $config['commodity_code'] ?? 'COKE');

    $sql = 'SELECT COUNT(*) AS cnt
            FROM pool
            INNER JOIN shipments ON shipments.id = pool.shipment_id
            INNER JOIN commodities ON commodities.id = shipments.consignment
            WHERE pool.car_id = "' . $car_id . '"
              AND commodities.code = "' . $commodity_code . '"';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs) {
        return false;
    }
    $row = mysqli_fetch_assoc($rs);
    return ((int) ($row['cnt'] ?? 0)) > 0;
}

function track_scale_car_allows_scale_reassign($car, $dbc = null, $config = null)
{
    if ($dbc === null || !is_array($car)) {
        return false;
    }
    $config = $config ?? track_scale_load_config();
    if (!track_scale_car_weighable($car, $dbc, $config)) {
        return false;
    }
    if (!track_scale_car_has_load($car)) {
        return false;
    }
    return track_scale_car_in_coke_fleet($dbc, $car['id'], $config);
}

function track_scale_car_needs_assignment($car, $dbc = null, $config = null)
{
    if (!is_array($car)) {
        return false;
    }
    $config = $config ?? track_scale_load_config();
    $profile = track_scale_profile_for_marks($car['reporting_marks'], $config);
    if (!empty($profile['tare_only'])) {
        return false;
    }
    $status = strtoupper(trim((string) ($car['status'] ?? '')));
    if ($status === 'UNLOADING') {
        return true;
    }
    if ($dbc !== null && track_scale_car_requires_train_reassign_confirm($car, $dbc, $config)) {
        return true;
    }
    if ($dbc === null) {
        return false;
    }
    return track_scale_get_car_active_order($dbc, $car['id']) === null;
}

function track_scale_build_car_response($car, $config = null, $dbc = null)
{
    $config = $config ?? track_scale_load_config();
    $profile = track_scale_profile_for_marks($car['reporting_marks'], $config);
    $scale_location = track_scale_loading_location_code($config);
    $active_order = ($dbc !== null) ? track_scale_get_car_active_order($dbc, $car['id']) : null;
    $at_scale = track_scale_car_at_scale($car, $config);
    $in_train = ($dbc !== null) ? track_scale_car_in_south_yard_train($car, $dbc, $config) : false;
    $requires_train_reassign = ($dbc !== null)
        ? track_scale_car_requires_train_reassign_confirm($car, $dbc, $config)
        : false;
    $allows_scale_reassign = ($dbc !== null)
        ? track_scale_car_allows_scale_reassign($car, $dbc, $config)
        : false;
    $weigh_source = $car['weigh_source'] ?? null;
    if ($weigh_source === null) {
        if ($in_train) {
            $weigh_source = 'in_train';
        } elseif ($at_scale) {
            $weigh_source = 'at_scale';
        }
    }

    return [
        'success' => true,
        'at_scale' => $at_scale,
        'in_train' => $in_train,
        'weighable' => track_scale_car_weighable($car, $dbc, $config),
        'required_location' => $scale_location,
        'scale_status' => $dbc !== null ? track_scale_build_scale_status($dbc, $config) : null,
        'car' => [
            'id' => $car['id'],
            'reporting_marks' => $car['reporting_marks'],
            'status' => $car['status'],
            'display_status' => track_scale_display_status($car['status'] ?? ''),
            'has_load' => track_scale_car_has_load($car),
            'has_active_order' => $active_order !== null,
            'needs_assignment' => track_scale_car_needs_assignment($car, $dbc, $config),
            'allows_scale_reassign' => $allows_scale_reassign,
            'requires_train_reassign_confirm' => $requires_train_reassign,
            'inbound_to_scale' => $requires_train_reassign,
            'active_waybill' => $active_order['waybill_number'] ?? null,
            'active_shipment_code' => $active_order['shipment_code'] ?? null,
            'active_unloading_location' => $active_order['unloading_location'] ?? null,
            'position' => $car['position'] ?? null,
            'car_code' => $car['car_code'],
            'current_location' => $car['current_location'],
            'weigh_source' => $weigh_source,
            'train_job' => $car['train_job'] ?? null,
            'image_url' => './ImageStore/DB_Images/RollingStock/' . $car['id'] . '.jpg',
        ],
        'profile' => $profile,
    ];
}

function track_scale_shipment_codes_for_routing($routing, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $shipments = $config['shipments'] ?? [];
    if ($routing === 'reload') {
        return $shipments['reload'] ?? ['COKE-RELOAD-SHEN'];
    }
    return $shipments['outbound'] ?? ['COKE-USS', 'COKE-CLEV', 'COKE-USS-BULK', 'COKE-CLEV-BULK'];
}

function track_scale_loading_location_for_routing($routing, $config = null)
{
    $config = $config ?? track_scale_load_config();
    if ($routing === 'reload') {
        return $config['reload_loading_location_code']
            ?? $config['loading_location_code']
            ?? 'SOUTH-SCALE';
    }
    return $config['outbound_loading_location_code'] ?? 'NORTH';
}

function track_scale_orders_to_create($min_amount, $max_amount)
{
    $min_amount = max(0, (int) $min_amount);
    $max_amount = max(0, (int) $max_amount);
    if ($max_amount < $min_amount) {
        $max_amount = $min_amount;
    }
    if ($min_amount === $max_amount) {
        return max(1, $min_amount);
    }

    return max(1, (int) round(mt_rand($min_amount * 100, $max_amount * 100) / 100));
}

function track_scale_get_open_orders($dbc, $car_id, $routing, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $car_id = mysqli_real_escape_string($dbc, $car_id);
    $loading_code = mysqli_real_escape_string(
        $dbc,
        track_scale_loading_location_for_routing($routing, $config)
    );
    $commodity_code = mysqli_real_escape_string($dbc, $config['commodity_code'] ?? 'COKE');
    $shipment_codes = track_scale_shipment_codes_for_routing($routing, $config);

    if (count($shipment_codes) === 0) {
        return [];
    }

    $code_list = [];
    foreach ($shipment_codes as $code) {
        $code_list[] = '"' . mysqli_real_escape_string($dbc, $code) . '"';
    }
    $code_sql = implode(', ', $code_list);

    $sql = 'SELECT co.waybill_number as waybill_number,
                   shipments.id as shipment_id,
                   shipments.code as shipment_code,
                   shipments.description as description,
                   shipments.special_instructions as special_instructions,
                   loc_load.code as loading_location,
                   loc_unload.code as unloading_location
            FROM (
                SELECT DISTINCT waybill_number, shipment
                FROM car_orders
                WHERE car = "" OR car IS NULL OR car = "0"
            ) AS co
            INNER JOIN shipments ON shipments.id = co.shipment
            INNER JOIN commodities ON commodities.id = shipments.consignment
            INNER JOIN locations loc_load ON loc_load.id = shipments.loading_location
            INNER JOIN locations loc_unload ON loc_unload.id = shipments.unloading_location
            INNER JOIN pool ON pool.shipment_id = shipments.id AND pool.car_id = "' . $car_id . '"
            WHERE commodities.code = "' . $commodity_code . '"
              AND loc_load.code = "' . $loading_code . '"
              AND shipments.code IN (' . $code_sql . ')
            ORDER BY co.waybill_number';

    $rs = mysqli_query($dbc, $sql);
    if (!$rs) {
        return [];
    }

    $orders = [];
    while ($row = mysqli_fetch_assoc($rs)) {
        $orders[] = $row;
    }
    return $orders;
}

function track_scale_car_in_pool_for_shipment($dbc, $car_id, $shipment_code, $config = null)
{
    $config = $config ?? track_scale_load_config();
    $car_id = mysqli_real_escape_string($dbc, $car_id);
    $shipment_code = mysqli_real_escape_string($dbc, $shipment_code);

    $sql = 'SELECT COUNT(*) AS cnt
            FROM pool
            INNER JOIN shipments ON shipments.id = pool.shipment_id
            WHERE pool.car_id = "' . $car_id . '"
              AND shipments.code = "' . $shipment_code . '"';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs) {
        return false;
    }
    $row = mysqli_fetch_assoc($rs);
    return ((int) ($row['cnt'] ?? 0)) > 0;
}

function track_scale_get_session_number($dbc)
{
    $sql = 'SELECT setting_value FROM settings WHERE setting_name = "session_nbr"';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs || mysqli_num_rows($rs) < 1) {
        return 0;
    }
    $row = mysqli_fetch_assoc($rs);
    return (int) $row['setting_value'];
}

function track_scale_deterministic_unit($key, $slot = 0)
{
    $hash = hash('crc32b', $key . '|' . (string) $slot);
    return hexdec($hash) / 4294967295.0;
}

function track_scale_next_manual_waybill($dbc, $session_number)
{
    $session_number = (int) $session_number;
    $prefix = str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-M';
    $prefix_esc = mysqli_real_escape_string($dbc, $prefix);
    $sql = 'SELECT MAX(CAST(SUBSTR(waybill_number, 6, 2) AS UNSIGNED)) AS max_counter
            FROM car_orders
            WHERE waybill_number LIKE "' . $prefix_esc . '__"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_assoc($rs);
    $counter = (int) ($row['max_counter'] ?? 0) + 1;
    return str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-M' . str_pad($counter, 2, '0', STR_PAD_LEFT);
}

function track_scale_generate_order($dbc, $shipment_code, $car_id = null, $config = null, $routing = 'outbound')
{
    $config = $config ?? track_scale_load_config();
    $shipment_code = trim((string) $shipment_code);
    $shipment_esc = mysqli_real_escape_string($dbc, $shipment_code);
    $routing = in_array($routing, ['outbound', 'reload'], true) ? $routing : 'outbound';

    if ($car_id !== null && !track_scale_car_in_pool_for_shipment($dbc, $car_id, $shipment_code, $config)) {
        return ['success' => false, 'error' => 'Car is not in the pool for shipment ' . $shipment_code];
    }

    $session_number = track_scale_get_session_number($dbc);
    if ($session_number <= 0) {
        return ['success' => false, 'error' => 'No operating session. Generate a session first.'];
    }

    $sql = 'SELECT id, code, min_amount, max_amount FROM shipments WHERE code = "' . $shipment_esc . '" LIMIT 1';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs || mysqli_num_rows($rs) < 1) {
        return ['success' => false, 'error' => 'Shipment not found: ' . $shipment_code];
    }
    $shipment = mysqli_fetch_assoc($rs);
    $shipment_id = $shipment['id'];

    $expected_loading_code = mysqli_real_escape_string(
        $dbc,
        track_scale_loading_location_for_routing($routing, $config)
    );
    $sql = 'SELECT shipments.id
            FROM shipments
            INNER JOIN locations ON locations.id = shipments.loading_location
            WHERE shipments.id = "' . mysqli_real_escape_string($dbc, $shipment_id) . '"
              AND locations.code = "' . $expected_loading_code . '"';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs || mysqli_num_rows($rs) < 1) {
        return ['success' => false, 'error' => 'Shipment does not load from ' . track_scale_loading_location_for_routing($routing, $config)];
    }

    $sql = 'UPDATE shipments SET last_ship_date = ' . (int) $session_number . ' WHERE id = "' . mysqli_real_escape_string($dbc, $shipment_id) . '"';
    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Update error: ' . mysqli_error($dbc)];
    }

    $num_cars = track_scale_orders_to_create($shipment['min_amount'], $shipment['max_amount']);
    $waybill_numbers = [];
    for ($i = 0; $i < $num_cars; $i++) {
        $waybill_number = track_scale_next_manual_waybill($dbc, $session_number);
        $sql = 'INSERT INTO car_orders (waybill_number, shipment, car)
                VALUES ("' . mysqli_real_escape_string($dbc, $waybill_number) . '",
                        "' . mysqli_real_escape_string($dbc, $shipment_id) . '",
                        "0")';
        if (!mysqli_query($dbc, $sql)) {
            return ['success' => false, 'error' => 'Insert error: ' . mysqli_error($dbc)];
        }
        $waybill_numbers[] = $waybill_number;
    }

    return [
        'success' => true,
        'waybill_number' => $waybill_numbers[0],
        'waybill_numbers' => $waybill_numbers,
        'orders_created' => count($waybill_numbers),
        'shipment_code' => $shipment['code'],
    ];
}

function track_scale_car_has_active_order($dbc, $car_id)
{
    $car_id = mysqli_real_escape_string($dbc, (string) $car_id);
    $sql = 'SELECT waybill_number FROM car_orders WHERE car = "' . $car_id . '" LIMIT 1';
    $rs = mysqli_query($dbc, $sql);
    if ($rs && mysqli_num_rows($rs) > 0) {
        return mysqli_fetch_assoc($rs);
    }
    return null;
}

function track_scale_loading_location_id($dbc, $config = null)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $config = $config ?? track_scale_load_config();
    $code = mysqli_real_escape_string($dbc, track_scale_loading_location_code($config));
    $sql = 'SELECT id FROM locations WHERE code = "' . $code . '" LIMIT 1';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs || mysqli_num_rows($rs) < 1) {
        return null;
    }

    $row = mysqli_fetch_assoc($rs);
    $cache = (int) $row['id'];
    return $cache;
}

/**
 * Mirrors set_out.php finish_btn logic for a single car at a chosen location.
 */
function track_scale_set_out_car($dbc, $car_id, $location_id)
{
    $car_id_esc = mysqli_real_escape_string($dbc, (string) $car_id);
    $location_id_esc = mysqli_real_escape_string($dbc, (string) $location_id);

    $sql = 'SELECT jobs.name AS job_name
            FROM jobs, cars
            WHERE cars.id = "' . $car_id_esc . '"
              AND jobs.id = cars.handled_by_job_id';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $job_name = $row['job_name'] ?? '';

    $sql = 'SELECT setting_value FROM settings WHERE setting_name = "session_nbr"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $current_session = $row[0];

    $sql = 'UPDATE cars
            SET current_location_id = "' . $location_id_esc . '",
                handled_by_job_id = 0,
                position = "0"
            WHERE id = "' . $car_id_esc . '"';
    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Set out update error: ' . mysqli_error($dbc)];
    }

    $session_nbr = $current_session;

    $sql = 'SELECT current_location_id FROM cars WHERE id = "' . $car_id_esc . '"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $location = $row['current_location_id'];

    $job_name_esc = mysqli_real_escape_string($dbc, (string) $job_name);
    $sql = 'INSERT INTO history(car_id, session_nbr, event_date, event, location)
            VALUES ("' . $car_id_esc . '",
                    "' . mysqli_real_escape_string($dbc, (string) $session_nbr) . '",
                    "' . date('Y-m-d H:i:s') . '",
                    "Set out by Job ' . $job_name_esc . '",
                    "' . mysqli_real_escape_string($dbc, (string) $location) . '")';
    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Set out history insert error: ' . mysqli_error($dbc)];
    }

    $sql = 'UPDATE cars,
                   car_orders,
                   shipments
            SET cars.status = "Loading",
                cars.last_spotted = "' . mysqli_real_escape_string($dbc, (string) $current_session) . '"
            WHERE cars.id = "' . $car_id_esc . '"
              AND cars.status = "Ordered"
              AND car_orders.car = cars.id
              AND car_orders.shipment = shipments.id
              AND cars.current_location_id = shipments.loading_location';
    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Set out loading status error: ' . mysqli_error($dbc)];
    }

    $sql = 'UPDATE cars,
                   car_orders,
                   shipments
            SET cars.status = "Unloading",
                cars.last_spotted = "' . mysqli_real_escape_string($dbc, (string) $current_session) . '"
            WHERE cars.id = "' . $car_id_esc . '"
              AND cars.status = "Loaded"
              AND car_orders.car = cars.id
              AND car_orders.shipment = shipments.id
              AND cars.current_location_id = shipments.unloading_location';
    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Set out unloading status error: ' . mysqli_error($dbc)];
    }

    $sql = 'UPDATE cars,
                   car_orders
            SET cars.status = "Empty"
            WHERE car_orders.car = cars.id
              AND car_orders.waybill_number LIKE "___-E__"
              AND cars.status = "Ordered"
              AND cars.current_location_id = car_orders.shipment
              AND cars.id = "' . $car_id_esc . '"';
    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Set out empty reposition error: ' . mysqli_error($dbc)];
    }
    if (mysqli_affected_rows($dbc) > 0) {
        $sql = 'DELETE FROM car_orders WHERE car = "' . $car_id_esc . '"';
        if (!mysqli_query($dbc, $sql)) {
            return ['success' => false, 'error' => 'Set out car order delete error: ' . mysqli_error($dbc)];
        }
    }

    $sql = 'SELECT shipments.min_load_time AS min_load_time,
                   shipments.max_load_time AS max_load_time,
                   shipments.min_unload_time AS min_unload_time,
                   shipments.max_unload_time AS max_unload_time,
                   cars.status AS status
              FROM shipments, car_orders, cars
             WHERE car_orders.shipment = shipments.id
               AND car_orders.car = "' . $car_id_esc . '"
               AND cars.id = "' . $car_id_esc . '"';
    $rs = mysqli_query($dbc, $sql);
    if ($rs && mysqli_num_rows($rs) > 0) {
        $row = mysqli_fetch_array($rs);
        $min_load_time = (int) $row['min_load_time'];
        $max_load_time = (int) $row['max_load_time'];
        $min_unload_time = (int) $row['min_unload_time'];
        $max_unload_time = (int) $row['max_unload_time'];

        if (($row['status'] === 'Loading') && (($min_load_time < 0) || ($max_load_time < 0))) {
            $sql2 = 'UPDATE cars SET status = "Loaded", last_spotted = "0" WHERE id = "' . $car_id_esc . '"';
            if (!mysqli_query($dbc, $sql2)) {
                return ['success' => false, 'error' => 'Set out instant load error: ' . mysqli_error($dbc)];
            }
        }

        if (($row['status'] === 'Unloading') && (($min_unload_time < 0) || ($max_unload_time < 0))) {
            $sql2 = 'UPDATE cars SET status = "Empty", last_spotted = "0" WHERE id = "' . $car_id_esc . '"';
            if (!mysqli_query($dbc, $sql2)) {
                return ['success' => false, 'error' => 'Set out instant unload error: ' . mysqli_error($dbc)];
            }
            $sql2 = 'DELETE FROM car_orders WHERE car = "' . $car_id_esc . '"';
            if (!mysqli_query($dbc, $sql2)) {
                return ['success' => false, 'error' => 'Set out instant unload order delete error: ' . mysqli_error($dbc)];
            }
        }
    }

    return [
        'success' => true,
        'set_out' => true,
        'job_name' => $job_name,
    ];
}

/**
 * Mirrors build_switchlists.php build_btn logic for a single car.
 */
function track_scale_assign_car_to_job($dbc, $car_id, $job_id)
{
    $car_id_esc = mysqli_real_escape_string($dbc, (string) $car_id);
    $job_id_esc = mysqli_real_escape_string($dbc, (string) $job_id);

    $sql = 'UPDATE cars SET handled_by_job_id = "' . $job_id_esc . '" WHERE id = "' . $car_id_esc . '"';
    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Assign to job update error: ' . mysqli_error($dbc)];
    }

    $sql = 'SELECT setting_value FROM settings WHERE setting_name = "session_nbr"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $session_nbr = $row['setting_value'];

    $sql = 'SELECT current_location_id FROM cars WHERE id = "' . $car_id_esc . '"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $location = $row['current_location_id'];

    $sql = 'SELECT name FROM jobs WHERE id = ' . (int) $job_id;
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $job_name = $row['name'] ?? '';

    $job_name_esc = mysqli_real_escape_string($dbc, (string) $job_name);
    $sql = 'INSERT INTO history(car_id, session_nbr, event_date, event, location)
            VALUES ("' . $car_id_esc . '",
                    "' . mysqli_real_escape_string($dbc, (string) $session_nbr) . '",
                    "' . date('Y-m-d H:i:s') . '",
                    "Assigned to Job ' . $job_name_esc . '",
                    "' . mysqli_real_escape_string($dbc, (string) $location) . '")';
    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Assign to job history insert error: ' . mysqli_error($dbc)];
    }

    return [
        'success' => true,
        'assigned_to_job' => true,
        'job_id' => (int) $job_id,
        'job_name' => $job_name,
    ];
}

/**
 * Mirrors pick_up.php finish_btn logic for a single car.
 */
function track_scale_pick_up_car($dbc, $car_id)
{
    $car_id_esc = mysqli_real_escape_string($dbc, (string) $car_id);

    $sql = 'SELECT current_location_id FROM cars WHERE id = "' . $car_id_esc . '"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $location = $row['current_location_id'];

    $sql = 'UPDATE cars SET current_location_id = "0" WHERE id = "' . $car_id_esc . '"';
    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Pick up update error: ' . mysqli_error($dbc)];
    }

    $sql = 'SELECT setting_value FROM settings WHERE setting_name = "session_nbr"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $session_nbr = $row['setting_value'];

    $sql = 'SELECT jobs.name AS job_name
              FROM jobs, cars
             WHERE cars.id = "' . $car_id_esc . '"
               AND jobs.id = cars.handled_by_job_id';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $job_name = $row['job_name'] ?? '';

    $job_name_esc = mysqli_real_escape_string($dbc, (string) $job_name);
    $sql = 'INSERT INTO history(car_id, session_nbr, event_date, event, location)
            VALUES ("' . $car_id_esc . '",
                    "' . mysqli_real_escape_string($dbc, (string) $session_nbr) . '",
                    "' . date('Y-m-d H:i:s') . '",
                    "Picked up by Job ' . $job_name_esc . '",
                    "' . mysqli_real_escape_string($dbc, (string) $location) . '")';
    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Pick up history insert error: ' . mysqli_error($dbc)];
    }

    return [
        'success' => true,
        'picked_up' => true,
        'job_name' => $job_name,
    ];
}

function track_scale_stage_in_train_car_at_scale($dbc, $car, $config = null)
{
    $config = $config ?? track_scale_load_config();
    if (!track_scale_car_in_south_yard_train($car, $dbc, $config)) {
        return ['success' => true, 'skipped' => true];
    }

    $location_id = track_scale_loading_location_id($dbc, $config);
    if ($location_id === null) {
        return ['success' => false, 'error' => 'Scale location not found in database'];
    }

    $source_job_id = (int) ($car['handled_by_job_id'] ?? 0);
    $result = track_scale_set_out_car($dbc, $car['id'], $location_id);
    if (!$result['success']) {
        return $result;
    }

    $result['source_job_id'] = $source_job_id;
    return $result;
}

function track_scale_return_car_to_train($dbc, $car_id, $job_id)
{
    $job_id = (int) $job_id;
    if ($job_id <= 0) {
        return ['success' => false, 'error' => 'Missing source job for return to train'];
    }

    $assign = track_scale_assign_car_to_job($dbc, $car_id, $job_id);
    if (!$assign['success']) {
        return $assign;
    }

    $pickup = track_scale_pick_up_car($dbc, $car_id);
    if (!$pickup['success']) {
        return $pickup;
    }

    return [
        'success' => true,
        'returned_to_train' => true,
        'job_id' => $job_id,
        'job_name' => $assign['job_name'] !== '' ? $assign['job_name'] : ($pickup['job_name'] ?? ''),
    ];
}

function track_scale_complete_wagon_unload($dbc, $car)
{
    $car_id = (int) ($car['id'] ?? 0);
    $marks = trim((string) ($car['reporting_marks'] ?? ''));
    if ($car_id <= 0 || $marks === '') {
        return ['success' => false, 'error' => 'Invalid car for unload'];
    }

    $car_id_esc = mysqli_real_escape_string($dbc, (string) $car_id);
    $marks_esc = mysqli_real_escape_string($dbc, $marks);
    $sql = 'SELECT id, status, reporting_marks FROM cars
            WHERE id = "' . $car_id_esc . '"
              AND reporting_marks = "' . $marks_esc . '"
              AND (status = "Loading" OR status = "Unloading")';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs || mysqli_num_rows($rs) < 1) {
        return ['success' => false, 'error' => 'Car not found or does not have Loading/Unloading status'];
    }

    $row = mysqli_fetch_assoc($rs);
    if ($row['status'] === 'Loading') {
        $new_status = 'Loaded';
    } else {
        $new_status = 'Empty';
        $del = 'DELETE FROM car_orders WHERE car = "' . $car_id_esc . '"';
        if (!mysqli_query($dbc, $del)) {
            return ['success' => false, 'error' => 'Failed to delete car orders: ' . mysqli_error($dbc)];
        }
    }

    $upd = 'UPDATE cars SET status = "' . mysqli_real_escape_string($dbc, $new_status) . '",
            last_spotted = 0
            WHERE id = "' . $car_id_esc . '"';
    if (!mysqli_query($dbc, $upd)) {
        return ['success' => false, 'error' => 'Failed to update car status: ' . mysqli_error($dbc)];
    }

    return [
        'success' => true,
        'unloaded' => true,
        'message' => 'Wagon unload completed',
        'new_status' => $new_status,
    ];
}

function track_scale_clear_active_car_orders($dbc, $car_id, $preserve_loaded = false)
{
    $car_id_esc = mysqli_real_escape_string($dbc, (string) $car_id);
    $del = 'DELETE FROM car_orders WHERE car = "' . $car_id_esc . '"';
    if (!mysqli_query($dbc, $del)) {
        return ['success' => false, 'error' => 'Failed to delete car orders: ' . mysqli_error($dbc)];
    }

    $new_status = $preserve_loaded ? 'Loaded' : 'Empty';
    $upd = 'UPDATE cars SET status = "' . mysqli_real_escape_string($dbc, $new_status) . '",
            last_spotted = 0
            WHERE id = "' . $car_id_esc . '"';
    if (!mysqli_query($dbc, $upd)) {
        return ['success' => false, 'error' => 'Failed to update car status: ' . mysqli_error($dbc)];
    }

    return [
        'success' => true,
        'unloaded' => !$preserve_loaded,
        'message' => $preserve_loaded ? 'Prior car order cleared (load retained)' : 'Prior car order cleared',
        'new_status' => $new_status,
    ];
}

function track_scale_prepare_car_for_assign($dbc, $car, $config = null)
{
    if (!is_array($car)) {
        return ['success' => false, 'error' => 'Invalid car'];
    }

    $status = strtoupper(trim((string) ($car['status'] ?? '')));
    $car_id = $car['id'] ?? '';
    $active_order = track_scale_car_has_active_order($dbc, $car_id);
    $previous_status = $car['status'] ?? '';

    if (in_array($status, ['UNLOADING', 'LOADING'], true)) {
        $result = track_scale_complete_wagon_unload($dbc, $car);
        if (!$result['success']) {
            return $result;
        }
        $result['closed_prior_order'] = !empty($result['unloaded']);
        $result['previous_status'] = $previous_status;
        return $result;
    }

    if ($active_order !== null) {
        $preserve_loaded = track_scale_car_has_load($car);
        $result = track_scale_clear_active_car_orders($dbc, $car_id, $preserve_loaded);
        if (!$result['success']) {
            return $result;
        }
        $result['closed_prior_order'] = true;
        $result['preserved_load'] = $preserve_loaded;
        $result['previous_status'] = $previous_status;
        return $result;
    }

    return ['success' => true, 'skipped' => true];
}

function track_scale_assign_car($dbc, $waybill_number, $car_id, $config = null)
{
    require_once __DIR__ . '/fill_order_helpers.php';

    $config = $config ?? track_scale_load_config();
    $waybill_number = trim((string) $waybill_number);
    $car_id = trim((string) $car_id);

    if ($waybill_number === '' || $car_id === '') {
        return ['success' => false, 'error' => 'Missing waybill or car'];
    }

    $car = track_scale_get_car_by_id($dbc, $car_id);
    if ($car === null) {
        return ['success' => false, 'error' => 'Car not found'];
    }

    $source_job_id = 0;
    $train_job_name = null;
    $in_train_workflow = [];
    if (track_scale_car_in_south_yard_train($car, $dbc, $config)) {
        $source_job_id = (int) ($car['handled_by_job_id'] ?? 0);
        $train_job_name = trim((string) ($car['train_job'] ?? ''));
        $stage = track_scale_stage_in_train_car_at_scale($dbc, $car, $config);
        if (!$stage['success']) {
            return $stage;
        }
        if (empty($stage['skipped'])) {
            $in_train_workflow[] = 'set_out';
        }
        $car = track_scale_get_car_by_id($dbc, $car_id);
        if ($car === null) {
            return ['success' => false, 'error' => 'Car not found after set out at scale'];
        }
    }

    $closed_prior_order = false;
    $previous_status = null;
    $preserved_load = false;
    $had_load_before_assign = track_scale_car_has_load($car);
    $prepare = track_scale_prepare_car_for_assign($dbc, $car, $config);
    if (!$prepare['success']) {
        if ($in_train_workflow !== []) {
            $prepare['in_train_workflow'] = $in_train_workflow;
            $prepare['partial'] = true;
        }
        return $prepare;
    }
    if (!empty($prepare['closed_prior_order'])) {
        $closed_prior_order = true;
        $previous_status = $prepare['previous_status'] ?? null;
        $preserved_load = !empty($prepare['preserved_load']);
        if (!$preserved_load) {
            $in_train_workflow[] = 'unloaded';
        }
    }
    $car = track_scale_get_car_by_id($dbc, $car_id);
    if ($car === null) {
        return ['success' => false, 'error' => 'Car not found after closing prior order'];
    }

    $waybill_esc = mysqli_real_escape_string($dbc, $waybill_number);

    $sql = 'SELECT car_orders.shipment AS shipment_id, shipments.code AS shipment_code
            FROM car_orders
            INNER JOIN shipments ON shipments.id = car_orders.shipment
            WHERE car_orders.waybill_number = "' . $waybill_esc . '"';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs || mysqli_num_rows($rs) < 1) {
        return ['success' => false, 'error' => 'Car order not found'];
    }
    $order = mysqli_fetch_assoc($rs);

    if (!track_scale_car_in_pool_for_shipment($dbc, $car_id, $order['shipment_code'], $config)) {
        return ['success' => false, 'error' => 'Car is not in the pool for this shipment'];
    }

    $sql = 'SELECT car FROM car_orders WHERE waybill_number = "' . $waybill_esc . '"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_assoc($rs);
    if (!fill_order_is_unfilled($row['car'] ?? '')) {
        return ['success' => false, 'error' => 'Car order already has a car assigned'];
    }

    $result = fill_order_assign_car($dbc, $waybill_number, $car_id);
    if (!$result['success']) {
        if ($in_train_workflow !== []) {
            $result['in_train_workflow'] = $in_train_workflow;
            $result['partial'] = true;
        }
        return $result;
    }

    if ($had_load_before_assign) {
        $car_id_esc = mysqli_real_escape_string($dbc, $car_id);
        $upd = 'UPDATE cars SET status = "Loaded" WHERE id = "' . $car_id_esc . '"';
        if (!mysqli_query($dbc, $upd)) {
            return ['success' => false, 'error' => 'Failed to retain loaded status after reassignment'];
        }
    }
    $in_train_workflow[] = 'assigned';

    if ($closed_prior_order) {
        $result['closed_prior_order'] = true;
        $result['previous_status'] = $previous_status;
        $result['preserved_load'] = $preserved_load || $had_load_before_assign;
        if (!$result['preserved_load']) {
            $result['unloaded_first'] = true;
        }
    }

    if ($source_job_id > 0) {
        $return = track_scale_return_car_to_train($dbc, $car_id, $source_job_id);
        if (!$return['success']) {
            $return['in_train_workflow'] = $in_train_workflow;
            $return['partial'] = true;
            return $return;
        }
        $in_train_workflow[] = 'returned_to_train';
        $result['returned_to_train'] = true;
        $result['train_job'] = $return['job_name'] !== ''
            ? $return['job_name']
            : $train_job_name;
        $result['source_job_id'] = $source_job_id;
    }

    if ($in_train_workflow !== []) {
        $result['in_train_workflow'] = $in_train_workflow;
    }

    return $result;
}

?>
