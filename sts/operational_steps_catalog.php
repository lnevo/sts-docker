<?php
/**
 * STS operational step catalog, recipe compile, and CSV import/export.
 */

require_once __DIR__ . '/warm_start_helpers.php';

function operational_steps_catalog_categories()
{
    return [
        'session' => 'Session flow',
        'operations' => 'Operations',
        'switchlists' => 'Switch lists',
        'reports' => 'Reports',
        'database' => 'Database',
        'workflow' => 'Workflow notes',
    ];
}

/** Groupings for the step-adder dropdown (generic STS commands). */
function operational_steps_catalog_adder_categories()
{
    return [
        'before' => 'Before Operations',
        'during' => 'During Operations',
        'after' => 'After Operations',
        'session' => 'Session',
        'switchlists' => 'Switch Lists',
        'waybills' => 'Waybills',
        'reports' => 'Reports',
        'database' => 'Database',
        'workflow' => 'Notes',
    ];
}

function operational_steps_catalog_adder_order()
{
    return [
        'before' => ['generate_orders', 'fill_orders', 'reposition_empties'],
        'during' => [
            'build_switchlists_sts', 'auto_assign_locals', 'pick_up_cars', 'set_out_cars',
            'run_job_criterion', 'track_scale',
        ],
        'after' => ['load_unload'],
        'session' => ['increment_session'],
        'switchlists' => [
            'generate_switchlists', 'generate_waybills',
        ],
        'waybills' => [
            'report_waybill_list', 'report_waybill_cars_print', 'report_waybill_shipments_print',
        ],
        'reports' => [
            'report_station_car', 'report_wheel', 'report_fleet',
            'report_shipment_forecast', 'report_car_forecast',
            'report_car_qr', 'report_location_qr',
        ],
        'database' => [
            'restore_database', 'backup_database', 'validate_database',
            'restart_session', 'reset_session', 'import_data', 'remove_backup', 'wipe_database',
        ],
        'workflow' => ['section_label', 'text_instruction', 'if_then', 'goto', 'stop'],
    ];
}

function operational_steps_catalog_text_param($key, $label, $default = '', $required = false, $placeholder = '')
{
    return [
        'key' => $key,
        'label' => $label,
        'type' => 'text',
        'default' => $default,
        'required' => $required,
        'placeholder' => $placeholder,
    ];
}

function operational_steps_catalog_job_param($required = true, $optional_label = 'Job')
{
    return [
        'key' => 'job',
        'label' => $optional_label,
        'type' => 'job',
        'options_from' => 'jobs',
        'allow_custom' => true,
        'required' => $required,
        'default' => '',
    ];
}

function operational_steps_catalog_location_param($required = true, $label = 'Location', $optional = false)
{
    return [
        'key' => 'location',
        'label' => $label,
        'type' => 'location',
        'options_from' => 'locations',
        'allow_custom' => true,
        'required' => $required && !$optional,
        'default' => '',
    ];
}

function operational_steps_catalog_station_param($required = false, $label = 'Station')
{
    return [
        'key' => 'station',
        'label' => $label,
        'type' => 'station',
        'options_from' => 'stations',
        'allow_custom' => true,
        'required' => $required,
        'default' => 'all',
    ];
}

function operational_steps_catalog_job_or_all_param($key = 'jobs', $label = 'Job / train')
{
    return [
        'key' => $key,
        'label' => $label,
        'type' => 'job_or_all',
        'default' => 'all',
        'required' => false,
    ];
}

function operational_steps_catalog_backup_param($required = true, $default = '')
{
    return [
        'key' => 'backup',
        'label' => 'Backup file',
        'type' => 'backup',
        'options_from' => 'backups',
        'allow_custom' => true,
        'required' => $required,
        'default' => $default,
    ];
}

/**
 * Directory for STS SQL backups (Docker: sts/backups bind-mount; dev: Car Cards sts-backups).
 */
function operational_steps_backups_dir()
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $candidates = [__DIR__ . '/backups'];
    $home = getenv('HOME') ?: '';
    if ($home !== '') {
        $candidates[] = $home . '/sts/sts-backups';
        $candidates[] = $home . '/sts-backups';
    }
    $repo = dirname(__DIR__, 2);
    if ($repo !== false && $repo !== '') {
        $candidates[] = $repo . '/sts-backups';
    }

    foreach ($candidates as $path) {
        $real = @realpath($path);
        if ($real !== false && is_dir($real)) {
            $dir = $real;
            return $dir;
        }
    }

    $dir = __DIR__ . '/backups';
    return $dir;
}

/** Session editor recipe/CSV storage (under sts-backups; hidden from restore_db.php). */
function operational_steps_editor_dir()
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $dir = operational_steps_backups_dir() . '/session_editor';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $dir = operational_steps_backups_dir() . '/session_editor';
    }
    return $dir;
}

/** Legacy locations to read when migrating editor files into session_editor/. */
function operational_steps_legacy_editor_dirs()
{
    $dirs = [__DIR__];
    $backups = operational_steps_backups_dir();
    if ($backups !== operational_steps_editor_dir()) {
        $dirs[] = $backups;
    }
    return array_values(array_unique($dirs));
}

/** Same file list as sts/restore_db.php (sorted scandir entries that are regular files). */
function operational_steps_list_backup_files($backup_dir = null)
{
    $backup_dir = $backup_dir ?? operational_steps_backups_dir();
    if (!is_dir($backup_dir)) {
        return [];
    }

    $entries = array_slice(scandir($backup_dir) ?: [], 2);
    sort($entries);
    $files = [];
    foreach ($entries as $file_name) {
        if (is_file($backup_dir . '/' . $file_name)) {
            $files[] = $file_name;
        }
    }

    return $files;
}

function operational_steps_catalog_scope_param()
{
    return [
        'key' => 'scope',
        'label' => 'Scope',
        'type' => 'scope',
        'options_from' => 'scopes',
        'allow_custom' => true,
        'required' => false,
        'default' => 'locals',
    ];
}

function operational_steps_catalog_auto_assign_jobs_param()
{
    return [
        'key' => 'jobs',
        'label' => 'Jobs',
        'type' => 'jobs_multiselect',
        'options_from' => 'jobs',
        'required' => false,
        'default' => '',
        'visible_label' => true,
    ];
}

function operational_steps_non_staging_job_names($dbc, array $config = [])
{
    $staging = warm_start_staging_job_names($dbc, $config);
    $jobs = [];
    $rs = mysqli_query($dbc, 'SELECT name FROM jobs ORDER BY name');
    while ($row = mysqli_fetch_array($rs)) {
        $name = (string) ($row['name'] ?? '');
        if ($name !== '' && !warm_start_is_staging_job($name, $staging)) {
            $jobs[] = $name;
        }
    }
    return $jobs;
}

function operational_steps_resolve_auto_assign_jobs($dbc, array $params, array $config = [])
{
    if (array_key_exists('jobs', $params)) {
        $jobs_text = trim((string) ($params['jobs'] ?? ''));
        if ($jobs_text === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $jobs_text))));
    }
    if (!empty($params['scope'])) {
        if ((string) $params['scope'] === 'locals') {
            return operational_steps_non_staging_job_names($dbc, $config);
        }
        return [trim((string) $params['scope'])];
    }
    return operational_steps_non_staging_job_names($dbc, $config);
}

function operational_steps_normalize_auto_assign_jobs(array $params)
{
    if (!empty($params['jobs'])) {
        $jobs = array_values(array_filter(array_map('trim', explode(',', (string) $params['jobs']))));
        return implode(',', $jobs);
    }
    if (!empty($params['scope'])) {
        if ((string) $params['scope'] === 'locals') {
            return '';
        }
        return trim((string) $params['scope']);
    }
    return '';
}

function operational_steps_compile_auto_assign_gui(array $params)
{
    $jobs = operational_steps_normalize_auto_assign_jobs($params);
    if ($jobs === '') {
        return 'Auto-Assign Cars locals';
    }
    return 'Auto-Assign Cars ' . str_replace(',', ', ', $jobs);
}

function operational_steps_load_unload_filter_fields()
{
    return [
        [
            'key' => 'car_code',
            'label' => 'Car code',
            'type' => 'car_code',
            'options_from' => 'car_codes',
            'allow_custom' => true,
            'default' => '',
        ],
        [
            'key' => 'status',
            'label' => 'Status',
            'type' => 'select',
            'options' => ['', 'Loading', 'Unloading', 'Empty'],
            'default' => '',
        ],
        [
            'key' => 'commodity',
            'label' => 'Consignment',
            'type' => 'commodity',
            'options_from' => 'commodities',
            'allow_custom' => true,
            'default' => '',
        ],
        [
            'key' => 'current_location',
            'label' => 'Current location',
            'type' => 'location',
            'options_from' => 'locations',
            'allow_custom' => true,
            'default' => '',
            'group' => 'locations',
        ],
        [
            'key' => 'loading_location',
            'label' => 'Loading location',
            'type' => 'location',
            'options_from' => 'locations',
            'allow_custom' => true,
            'default' => '',
            'group' => 'locations',
        ],
        [
            'key' => 'unloading_location',
            'label' => 'Unloading location',
            'type' => 'location',
            'options_from' => 'locations',
            'allow_custom' => true,
            'default' => '',
            'group' => 'locations',
        ],
    ];
}

function operational_steps_load_unload_default_filters()
{
    $filters = [];
    foreach (operational_steps_load_unload_filter_fields() as $field) {
        $filters[$field['key']] = $field['default'] ?? '';
    }
    return $filters;
}

function operational_steps_normalize_load_unload_filters(array $params)
{
    $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
    $legacy = [
        'location' => 'current_location',
        'car_code' => 'car_code',
        'commodity' => 'commodity',
    ];
    foreach ($legacy as $old => $new) {
        if (!empty($params[$old]) && empty($filters[$new])) {
            $filters[$new] = $params[$old];
        }
    }
    return array_merge(operational_steps_load_unload_default_filters(), $filters);
}

function operational_steps_load_unload_filter_token_match($needle, $station, $code)
{
    return warm_start_load_unload_filter_token_match($needle, $station, $code);
}

function operational_steps_load_unload_row_matches(array $row, array $filters)
{
    return warm_start_load_unload_row_matches($row, $filters);
}

function operational_steps_parse_load_unload_filters($text)
{
    $filters = operational_steps_load_unload_default_filters();
    $text = trim((string) $text);
    if ($text === '' || stripos($text, 'offline') === 0) {
        return $filters;
    }
    $aliases = [
        'current' => 'current_location',
        'current_location' => 'current_location',
        'car' => 'car_code',
        'car_code' => 'car_code',
        'status' => 'status',
        'consignment' => 'commodity',
        'commodity' => 'commodity',
        'load' => 'loading_location',
        'loading' => 'loading_location',
        'loading_location' => 'loading_location',
        'unload' => 'unloading_location',
        'unloading' => 'unloading_location',
        'unloading_location' => 'unloading_location',
    ];
    foreach (preg_split('/\s*;\s*/', $text) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (strpos($part, '=') === false) {
            if (empty($filters['current_location'])) {
                $filters['current_location'] = $part;
            }
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $part, 2));
        $key = strtolower($key);
        if (isset($aliases[$key])) {
            $filters[$aliases[$key]] = $value;
        }
    }
    return $filters;
}

function operational_steps_compile_load_unload_gui(array $filters)
{
    $filters = array_filter(operational_steps_normalize_load_unload_filters(['filters' => $filters]));
    if (empty($filters)) {
        return 'Load/Unload offline';
    }
    $labels = [
        'current_location' => 'current',
        'car_code' => 'car',
        'status' => 'status',
        'commodity' => 'consignment',
        'loading_location' => 'load',
        'unloading_location' => 'unload',
    ];
    $parts = [];
    foreach ($filters as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $parts[] = ($labels[$key] ?? $key) . '=' . $value;
    }
    if (empty($parts)) {
        return 'Load/Unload offline';
    }
    return 'Load/Unload ' . implode('; ', $parts);
}

function operational_steps_fill_order_valid_sources()
{
    return ['pool', 'station', 'priority', 'system'];
}

function operational_steps_fill_order_filter_fields()
{
    return [
        [
            'key' => 'loading_location',
            'label' => 'Loading',
            'type' => 'location',
            'options_from' => 'locations',
            'allow_custom' => true,
            'default' => '',
        ],
        [
            'key' => 'unloading_location',
            'label' => 'Unloading',
            'type' => 'location',
            'options_from' => 'locations',
            'allow_custom' => true,
            'default' => '',
        ],
        [
            'key' => 'consignment',
            'label' => 'Commodity',
            'type' => 'commodity',
            'options_from' => 'commodities',
            'allow_custom' => true,
            'default' => '',
        ],
        [
            'key' => 'car_code',
            'label' => 'Car type',
            'type' => 'car_code',
            'options_from' => 'car_codes',
            'allow_custom' => true,
            'default' => '',
        ],
    ];
}

function operational_steps_fill_order_car_filter_fields()
{
    return [
        [
            'key' => 'categories',
            'label' => 'Source',
            'type' => 'fill_sources',
            'options' => operational_steps_fill_order_valid_sources(),
            'default' => 'pool,station,priority,system',
        ],
        [
            'key' => 'current_station',
            'label' => 'Car station',
            'type' => 'station',
            'options_from' => 'stations',
            'allow_custom' => true,
            'default' => '',
        ],
        [
            'key' => 'current_location',
            'label' => 'Car location',
            'type' => 'location',
            'options_from' => 'locations',
            'allow_custom' => true,
            'default' => '',
        ],
        [
            'key' => 'car_code',
            'label' => 'Eligible car type',
            'type' => 'car_code',
            'options_from' => 'car_codes',
            'allow_custom' => true,
            'default' => '',
        ],
    ];
}

function operational_steps_fill_order_default_filters()
{
    $filters = [];
    foreach (operational_steps_fill_order_filter_fields() as $field) {
        $filters[$field['key']] = $field['default'] ?? '';
    }
    return $filters;
}

function operational_steps_fill_order_car_default_filters()
{
    $filters = [];
    foreach (operational_steps_fill_order_car_filter_fields() as $field) {
        $filters[$field['key']] = $field['default'] ?? '';
    }
    return $filters;
}

function operational_steps_normalize_fill_order_filters(array $params)
{
    $filters = is_array($params['order_filters'] ?? null) ? $params['order_filters'] : [];
    return array_merge(operational_steps_fill_order_default_filters(), $filters);
}

function operational_steps_normalize_fill_car_filters(array $params)
{
    $filters = is_array($params['car_filters'] ?? null) ? $params['car_filters'] : [];
    $merged = array_merge(operational_steps_fill_order_car_default_filters(), $filters);
    if (is_array($merged['categories'] ?? null)) {
        $merged['categories'] = implode(',', array_filter(array_map('trim', $merged['categories'])));
    }
    if (($merged['categories'] ?? '') === '') {
        $merged['categories'] = operational_steps_fill_order_car_default_filters()['categories'];
    }
    return $merged;
}

function operational_steps_fill_car_filters_runtime(array $storage)
{
    require_once __DIR__ . '/fill_order_helpers.php';
    $categories = $storage['categories'] ?? '';
    if (is_array($categories)) {
        $categories = array_values(array_filter(array_map('trim', $categories)));
    } else {
        $categories = array_values(array_filter(array_map('trim', explode(',', (string) $categories))));
    }
    return fill_order_parse_car_filters([
        'categories' => $categories,
        'current_station' => $storage['current_station'] ?? '',
        'current_location' => $storage['current_location'] ?? '',
        'car_code' => $storage['car_code'] ?? '',
    ]);
}

function operational_steps_parse_fill_orders_suffix($text)
{
    $order = operational_steps_fill_order_default_filters();
    $car = operational_steps_fill_order_car_default_filters();
    $text = trim((string) $text);
    if ($text === '') {
        return ['order_filters' => $order, 'car_filters' => $car];
    }
    $order_aliases = [
        'load' => 'loading_location',
        'loading' => 'loading_location',
        'loading_location' => 'loading_location',
        'unload' => 'unloading_location',
        'unloading' => 'unloading_location',
        'unloading_location' => 'unloading_location',
        'commodity' => 'consignment',
        'consignment' => 'consignment',
        'car' => 'car_code',
        'car_code' => 'car_code',
        'car_type' => 'car_code',
    ];
    $car_aliases = [
        'src' => 'categories',
        'sources' => 'categories',
        'categories' => 'categories',
        'car_station' => 'current_station',
        'current_station' => 'current_station',
        'car_loc' => 'current_location',
        'car_location' => 'current_location',
        'current_location' => 'current_location',
        'eligible_car' => 'car_code',
        'car_type' => 'car_code',
    ];
    foreach (preg_split('/\s*;\s*/', $text) as $part) {
        $part = trim($part);
        if ($part === '' || strpos($part, '=') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $part, 2));
        $key = strtolower($key);
        if (isset($order_aliases[$key])) {
            $order[$order_aliases[$key]] = $value;
        } elseif (isset($car_aliases[$key])) {
            $car[$car_aliases[$key]] = $value;
        }
    }
    return ['order_filters' => $order, 'car_filters' => $car];
}

function operational_steps_compile_fill_orders_gui(array $params)
{
    $parts = [];
    $order = array_filter(operational_steps_normalize_fill_order_filters($params));
    $order_labels = [
        'loading_location' => 'load',
        'unloading_location' => 'unload',
        'consignment' => 'commodity',
        'car_code' => 'car',
    ];
    foreach ($order as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $parts[] = ($order_labels[$key] ?? $key) . '=' . $value;
    }
    $car = operational_steps_normalize_fill_car_filters($params);
    $default_sources = operational_steps_fill_order_car_default_filters()['categories'];
    if (($car['categories'] ?? '') !== '' && ($car['categories'] ?? '') !== $default_sources) {
        $parts[] = 'src=' . $car['categories'];
    }
    if (!empty($car['current_station'])) {
        $parts[] = 'car_station=' . $car['current_station'];
    }
    if (!empty($car['current_location'])) {
        $parts[] = 'car_loc=' . $car['current_location'];
    }
    if (!empty($car['car_code'])) {
        $parts[] = 'car_type=' . $car['car_code'];
    }
    if (empty($parts)) {
        return 'Fill Orders';
    }
    return 'Fill Orders ' . implode('; ', $parts);
}

function operational_steps_reposition_filter_fields()
{
    return [
        [
            'key' => 'car_code',
            'label' => 'Car code',
            'type' => 'car_code',
            'options_from' => 'car_codes',
            'allow_custom' => true,
            'default' => '',
        ],
        [
            'key' => 'current_station',
            'label' => 'Current station',
            'type' => 'station',
            'options_from' => 'stations',
            'allow_custom' => true,
            'default' => '',
            'group' => 'current',
        ],
        [
            'key' => 'current_location',
            'label' => 'Current location',
            'type' => 'location',
            'options_from' => 'locations',
            'allow_custom' => true,
            'default' => '',
            'group' => 'current',
        ],
        [
            'key' => 'home_station',
            'label' => 'Home station',
            'type' => 'station',
            'options_from' => 'stations',
            'allow_custom' => true,
            'default' => '',
            'group' => 'home',
        ],
        [
            'key' => 'home_location',
            'label' => 'Home location',
            'type' => 'location',
            'options_from' => 'locations',
            'allow_custom' => true,
            'default' => '',
            'group' => 'home',
        ],
        [
            'key' => 'off_home_only',
            'label' => 'Not at home',
            'type' => 'select',
            'options' => ['', '1'],
            'default' => '',
        ],
    ];
}

function operational_steps_reposition_default_filters()
{
    $filters = [];
    foreach (operational_steps_reposition_filter_fields() as $field) {
        $filters[$field['key']] = $field['default'] ?? '';
    }
    return $filters;
}

function operational_steps_normalize_reposition_filters(array $params)
{
    $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
    return array_merge(operational_steps_reposition_default_filters(), $filters);
}

function operational_steps_parse_reposition_filters($text)
{
    $filters = operational_steps_reposition_default_filters();
    $text = trim((string) $text);
    if ($text === '') {
        return $filters;
    }
    $aliases = [
        'car' => 'car_code',
        'car_code' => 'car_code',
        'current' => 'current_station',
        'current_station' => 'current_station',
        'current_loc' => 'current_location',
        'current_location' => 'current_location',
        'home' => 'home_station',
        'home_station' => 'home_station',
        'home_loc' => 'home_location',
        'home_location' => 'home_location',
        'off_home' => 'off_home_only',
        'not_at_home' => 'off_home_only',
    ];
    foreach (preg_split('/\s*;\s*/', $text) as $part) {
        $part = trim($part);
        if ($part === '' || strpos($part, '=') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $part, 2));
        $key = strtolower($key);
        if ($key === 'dest' || $key === 'destination') {
            continue;
        }
        if (isset($aliases[$key])) {
            $filters[$aliases[$key]] = $value;
        }
    }
    return $filters;
}

function operational_steps_compile_reposition_gui(array $params)
{
    $mode = trim((string) ($params['mode'] ?? 'reposition_to_home'));
    if ($mode === '') {
        $mode = 'reposition_to_home';
    }
    $title = $mode === 'update' ? 'Reposition Empties update' : 'Reposition Empties to home';
    $parts = [];
    if ($mode === 'update') {
        $dest = trim((string) ($params['destination'] ?? ''));
        if ($dest !== '') {
            $parts[] = 'dest=' . $dest;
        }
    }
    $filters = array_filter(operational_steps_normalize_reposition_filters($params), static function ($value, $key) {
        if ($key === 'off_home_only') {
            return $value === '1' || $value === 1 || $value === true;
        }
        return $value !== '' && $value !== null;
    }, ARRAY_FILTER_USE_BOTH);
    $labels = [
        'car_code' => 'car',
        'current_station' => 'current',
        'current_location' => 'current_loc',
        'home_station' => 'home',
        'home_location' => 'home_loc',
        'off_home_only' => 'off_home',
    ];
    foreach ($filters as $key => $value) {
        if ($key === 'off_home_only') {
            $parts[] = 'off_home=1';
            continue;
        }
        $parts[] = ($labels[$key] ?? $key) . '=' . $value;
    }
    if (empty($parts)) {
        return $title;
    }
    return $title . ' ' . implode('; ', $parts);
}

function operational_steps_normalize_percent(array $params, $default_percent = 100)
{
    if (isset($params['percent']) && $params['percent'] !== '' && $params['percent'] !== null) {
        return max(0, min(100, (float) $params['percent']));
    }
    if (isset($params['fraction']) && $params['fraction'] !== '' && $params['fraction'] !== null) {
        $fraction = (float) $params['fraction'];
        return max(0, min(100, $fraction <= 1 ? $fraction * 100 : $fraction));
    }
    return max(0, min(100, (float) $default_percent));
}

function operational_steps_percent_to_fraction($percent)
{
    return max(0.0, min(1.0, (float) $percent / 100.0));
}

function operational_steps_normalize_generate_orders_params(array $params)
{
    $normalized = [
        'shipment' => trim((string) ($params['shipment'] ?? '')),
        'increment_session' => trim((string) ($params['increment_session'] ?? '')),
        'max_unfilled' => trim((string) ($params['max_unfilled'] ?? '')),
    ];
    if ($normalized['increment_session'] !== '1') {
        $normalized['increment_session'] = '';
    }
    if ($normalized['max_unfilled'] !== '' && !ctype_digit($normalized['max_unfilled'])) {
        $normalized['max_unfilled'] = '';
    }
    return $normalized;
}

function operational_steps_compile_generate_orders_gui(array $params)
{
    $params = operational_steps_normalize_generate_orders_params($params);
    $parts = [];
    if ($params['shipment'] !== '') {
        $parts[] = $params['shipment'];
    }
    if ($params['increment_session'] === '1') {
        $parts[] = 'increment session';
    }
    if ($params['max_unfilled'] !== '') {
        $parts[] = 'max_unfilled=' . $params['max_unfilled'];
    }
    if (empty($parts)) {
        return 'Generate Orders';
    }
    return 'Generate Orders ' . implode('; ', $parts);
}

function operational_steps_fetch_dynamic_options($dbc)
{
    $jobs = [];
    $rs = mysqli_query($dbc, 'SELECT id, name FROM jobs ORDER BY name');
    while ($row = mysqli_fetch_array($rs)) {
        $jobs[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    $locations = [];
    $rs = mysqli_query(
        $dbc,
        'SELECT locations.id, locations.code, routing.station AS station_name
         FROM locations
         LEFT JOIN routing ON locations.station = routing.id
         ORDER BY routing.station, locations.code'
    );
    while ($row = mysqli_fetch_array($rs)) {
        $code = (string) ($row['code'] ?? '');
        $station = (string) ($row['station_name'] ?? '');
        $locations[] = [
            'id' => (int) $row['id'],
            'code' => $code,
            'station' => $station,
            'label' => ($station !== '' ? $station . ' / ' : '') . $code,
        ];
    }

    $location_aliases = array_keys(operational_steps_catalog_locations());
    foreach ($location_aliases as $alias) {
        $locations[] = ['id' => 0, 'code' => $alias, 'station' => '', 'label' => $alias];
    }

    $scopes = [['value' => 'locals', 'label' => 'All locals (non-staging)']];
    foreach ($jobs as $job) {
        $scopes[] = ['value' => $job['name'], 'label' => $job['name']];
    }

    $backups = operational_steps_list_backup_files();

    $setout_extras = [
        ['value' => 'remainder', 'label' => 'remainder (clear train)'],
        ['value' => 'Demmler/Scully', 'label' => 'Demmler/Scully'],
        ['value' => 'Island/Shenango', 'label' => 'Island/Shenango'],
    ];
    $setout_locations = [];
    foreach ($location_aliases as $alias) {
        $setout_locations[] = ['value' => $alias, 'label' => $alias];
    }
    foreach ($locations as $loc) {
        if ($loc['code'] !== '' && !in_array($loc['code'], $location_aliases, true)) {
            $setout_locations[] = ['value' => $loc['code'], 'label' => $loc['label']];
        }
    }
    foreach ($setout_extras as $extra) {
        $setout_locations[] = $extra;
    }

    $shipments = [];
    $rs = mysqli_query($dbc, 'SELECT id, code, description FROM shipments ORDER BY code');
    while ($row = mysqli_fetch_array($rs)) {
        $shipments[] = [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['code'] . ' — ' . (string) $row['description'],
        ];
    }

    $car_codes = [];
    $rs = mysqli_query($dbc, 'SELECT id, code, description FROM car_codes ORDER BY code');
    while ($row = mysqli_fetch_array($rs)) {
        $car_codes[] = [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['code'] . ' — ' . (string) $row['description'],
        ];
    }

    $commodities = [];
    $rs = mysqli_query($dbc, 'SELECT id, code, description FROM commodities ORDER BY code');
    while ($row = mysqli_fetch_array($rs)) {
        $commodities[] = [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['code'] . ' — ' . (string) $row['description'],
        ];
    }

    $stations = [['id' => 0, 'name' => 'all', 'label' => 'All Stations']];
    $rs = mysqli_query($dbc, 'SELECT id, station FROM routing ORDER BY sort_seq, station');
    while ($row = mysqli_fetch_array($rs)) {
        $name = (string) ($row['station'] ?? '');
        if ($name === '') {
            continue;
        }
        $stations[] = [
            'id' => (int) $row['id'],
            'name' => $name,
            'label' => $name,
        ];
    }

    require_once __DIR__ . '/session_helpers.php';

    $config = warm_start_default_config();
    $staging_jobs = warm_start_staging_job_names($dbc, $config);

    return [
        'jobs' => $jobs,
        'locations' => $locations,
        'stations' => $stations,
        'scopes' => $scopes,
        'staging_jobs' => $staging_jobs,
        'backups' => $backups,
        'setout_extras' => $setout_extras,
        'setout_locations' => $setout_locations,
        'shipments' => $shipments,
        'car_codes' => $car_codes,
        'commodities' => $commodities,
        'condition_variables' => session_condition_variables(),
        'condition_operators' => session_condition_operators(),
    ];
}

function operational_steps_catalog_jobs()
{
    return ['D749', 'NVL', 'CK1', 'STG-SCULLY', 'STG-DEMMLER'];
}

function operational_steps_catalog_locations()
{
    return [
        'Demmler' => 'Demmler Yard / offline (station 10)',
        'South-Yard' => 'South Yard (SOUTH)',
        'Scully' => 'Scully yard (station 9)',
        'Scully-Offline' => 'Scully offline / McKees Rock',
        'Shenango' => 'Shenango Coke Works (station 12)',
        'South-Scale' => 'South Yard scale track',
        'Island' => 'Neville Island (station 3)',
    ];
}

function operational_steps_catalog_definitions()
{
    $jobs = operational_steps_catalog_jobs();
    $locs = array_keys(operational_steps_catalog_locations());

    return [
        [
            'id' => 'restore_database',
            'category' => 'database',
            'adder' => true,
            'adder_group' => 'database',
            'label' => 'Restore Database',
            'gui_template' => 'Restore Database {backup}',
            'description' => 'Restore STS from a backup file in sts/backups/. Shell: apply_hart_seed.sh for hart_seed.',
            'runnable' => true,
            'dispatch' => 'restore_database',
            'gui_path' => '/sts/restore_db.php',
            'params' => [
                operational_steps_catalog_backup_param(true, 'hart_seed'),
            ],
        ],
        [
            'id' => 'backup_database',
            'category' => 'database',
            'adder' => true,
            'adder_group' => 'database',
            'label' => 'Create Backup',
            'gui_template' => 'Create Backup {backup}',
            'description' => 'Export current database to sts/backups/. GUI: backup_db.php.',
            'runnable' => true,
            'dispatch' => 'backup_database',
            'gui_path' => '/sts/backup_db.php',
            'params' => [
                operational_steps_catalog_backup_param(true, 'manual_backup'),
            ],
        ],
        [
            'id' => 'validate_database',
            'category' => 'database',
            'adder' => true,
            'adder_group' => 'database',
            'label' => 'Validate Database',
            'gui_template' => 'Validate Database',
            'description' => 'Check STS for broken links and data integrity. GUI: validate_db.php.',
            'runnable' => false,
            'gui_path' => '/sts/validate_db.php',
            'params' => [],
        ],
        [
            'id' => 'remove_backup',
            'category' => 'database',
            'adder' => true,
            'adder_group' => 'database',
            'label' => 'Remove Backup',
            'gui_template' => 'Remove Backup {backup}',
            'description' => 'Delete a backup file from sts/backups/. GUI: remove_backup.php.',
            'runnable' => false,
            'gui_path' => '/sts/remove_backup.php',
            'params' => [
                operational_steps_catalog_backup_param(true),
            ],
        ],
        [
            'id' => 'import_data',
            'category' => 'database',
            'adder' => true,
            'adder_group' => 'database',
            'label' => 'Import Data',
            'gui_template' => 'Import Data {table} ({add_replace})',
            'description' => 'Import tables from CSV. GUI: import_tables.php.',
            'runnable' => false,
            'gui_path' => '/sts/import_tables.php',
            'params' => [
                [
                    'key' => 'table',
                    'label' => 'Table',
                    'type' => 'select',
                    'options' => ['commodities', 'car_codes', 'routing', 'locations', 'shipments', 'cars'],
                    'default' => 'shipments',
                ],
                [
                    'key' => 'add_replace',
                    'label' => 'Mode',
                    'type' => 'select',
                    'options' => ['append', 'replace'],
                    'default' => 'append',
                ],
                operational_steps_catalog_text_param('file', 'CSV file path', '', false, 'uploads/myfile.csv'),
            ],
        ],
        [
            'id' => 'restart_session',
            'category' => 'database',
            'adder' => true,
            'adder_group' => 'database',
            'label' => 'Restart Session',
            'gui_template' => 'Restart Session',
            'description' => 'Restart shippers, cancel waybills, release all cars. GUI: restart.php.',
            'runnable' => false,
            'gui_path' => '/sts/restart.php',
            'params' => [],
        ],
        [
            'id' => 'reset_session',
            'category' => 'database',
            'adder' => true,
            'adder_group' => 'database',
            'label' => 'Reset Session',
            'gui_template' => 'Reset Session',
            'description' => 'Restart session and reset all cars. GUI: reset.php.',
            'runnable' => false,
            'gui_path' => '/sts/reset.php',
            'params' => [],
        ],
        [
            'id' => 'wipe_database',
            'category' => 'database',
            'adder' => true,
            'adder_group' => 'database',
            'label' => 'Wipe Database',
            'gui_template' => 'Wipe Database',
            'description' => 'Erase all STS data — use only when rebuilding. GUI: wipe.php.',
            'runnable' => false,
            'gui_path' => '/sts/wipe.php',
            'params' => [],
        ],
        [
            'id' => 'warm_start_tracked',
            'category' => 'session',
            'label' => 'Warm Start (tracked)',
            'gui_template' => 'Warm Start tracked simulation',
            'description' => 'Simulate prior operating days until STG-SCULLY backlog is ready. CLI: apply_warm_start.sh.',
            'runnable' => true,
            'dispatch' => 'warm_start_tracked',
            'params' => [
                ['key' => 'min_sessions', 'label' => 'Min sessions', 'type' => 'number', 'default' => '3', 'min' => 1, 'max' => 30],
                ['key' => 'max_sessions', 'label' => 'Max sessions', 'type' => 'number', 'default' => '12', 'min' => 1, 'max' => 30],
            ],
        ],
        [
            'id' => 'begin_operating_session',
            'category' => 'session',
            'adder' => false,
            'label' => 'Begin Operating Session (composite)',
            'gui_template' => 'Begin Operating Session',
            'description' => 'STG-SCULLY (optional), load/unload, increment session, fill, reposition, auto-assign.',
            'runnable' => true,
            'dispatch' => 'begin_operating_session',
            'params' => [
                ['key' => 'run_stg_scully', 'label' => 'Run STG-SCULLY', 'type' => 'select', 'options' => ['yes', 'no'], 'default' => 'yes'],
            ],
        ],
        [
            'id' => 'play_operating_session',
            'category' => 'session',
            'adder' => false,
            'label' => 'Play Operating Session (composite)',
            'gui_template' => 'Play Operating Session',
            'description' => 'Run dispatch through session end; defer STG-SCULLY for next begin. CLI: play_operating_session.sh.',
            'runnable' => true,
            'dispatch' => 'play_operating_session',
            'params' => [],
        ],
        [
            'id' => 'evaluate_session_prep',
            'category' => 'session',
            'adder' => false,
            'label' => 'Evaluate Session Prep',
            'gui_template' => 'Evaluate Session Prep',
            'description' => 'Report unfilled orders, empties, staging backlog, and per-job assign eligibility.',
            'runnable' => true,
            'dispatch' => 'evaluate_session_prep',
            'params' => [],
        ],
        [
            'id' => 'section_label',
            'category' => 'workflow',
            'adder' => true,
            'adder_group' => 'workflow',
            'label' => 'Section label',
            'gui_template' => '{label}',
            'description' => 'Section heading with optional remarks (non-operational).',
            'runnable' => false,
            'params' => [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => '', 'required' => true],
            ],
        ],
        [
            'id' => 'text_instruction',
            'category' => 'workflow',
            'adder' => true,
            'adder_group' => 'workflow',
            'label' => 'Text instruction',
            'gui_template' => '{instruction}',
            'description' => 'Free-text STS GUI instruction (imported or legacy steps). Not runnable.',
            'runnable' => false,
            'params' => [
                operational_steps_catalog_text_param('instruction', 'Instruction', '', true, 'STS GUI instruction text'),
            ],
        ],
        [
            'id' => 'stop',
            'category' => 'workflow',
            'adder' => true,
            'adder_group' => 'workflow',
            'label' => 'Stop',
            'gui_template' => 'Stop execution',
            'description' => 'Halt recipe execution at this step.',
            'runnable' => true,
            'dispatch' => 'stop',
            'params' => [],
        ],
        [
            'id' => 'goto',
            'category' => 'workflow',
            'adder' => true,
            'adder_group' => 'workflow',
            'label' => 'Goto section',
            'gui_template' => 'Goto {section_label}',
            'description' => 'Skip forward to a later section (steps between here and the section are not run). Use simulator Repeat to run a section multiple times — backward gotos are not allowed.',
            'runnable' => true,
            'dispatch' => 'goto',
            'params' => [
                ['key' => 'section', 'label' => 'Section', 'type' => 'workflow_section', 'default' => '', 'required' => true],
                ['key' => 'section_label', 'label' => 'Section label', 'type' => 'text', 'default' => ''],
                ['key' => 'step', 'label' => 'Step #', 'type' => 'number', 'default' => ''],
            ],
        ],
        [
            'id' => 'if_then',
            'category' => 'workflow',
            'adder' => true,
            'adder_group' => 'workflow',
            'label' => 'If … then',
            'gui_template' => 'If {variable} {operator} {value}',
            'description' => 'When false, skips the next step. When true, runs it (e.g. Goto section). Variables: session #, unfilled count, STG backlog, cars on job, cars at location.',
            'runnable' => true,
            'dispatch' => 'if_then',
            'params' => [
                [
                    'key' => 'variable',
                    'label' => 'Variable',
                    'type' => 'select',
                    'options' => [
                        'session_nbr', 'unfilled_count', 'stg_backlog_eligible', 'stg_backlog_on_jobs',
                        'cars_on_job', 'cars_at_location', 'awaiting_assignment',
                    ],
                    'default' => 'session_nbr',
                ],
                [
                    'key' => 'operator',
                    'label' => 'Operator',
                    'type' => 'select',
                    'options' => ['=', '!=', '<', '<=', '>', '>='],
                    'default' => '>=',
                ],
                ['key' => 'value', 'label' => 'Value', 'type' => 'text', 'default' => '1', 'required' => true],
            ],
        ],
        [
            'id' => 'marker',
            'category' => 'workflow',
            'adder' => false,
            'label' => 'Section note (legacy)',
            'gui_template' => '{note}',
            'description' => 'Legacy marker — use Section label instead.',
            'runnable' => false,
            'params' => [
                ['key' => 'note', 'label' => 'Note', 'type' => 'text', 'default' => '', 'required' => true],
            ],
        ],
        [
            'id' => 'run_stg_scully',
            'category' => 'operations',
            'adder' => false,
            'label' => 'Run STG-SCULLY',
            'gui_template' => 'Run STG-SCULLY {context}',
            'description' => 'Assign, pick up, set out at Scully offline. Clears staging backlog.',
            'runnable' => true,
            'dispatch' => 'staging_job',
            'params' => [
                ['key' => 'context', 'label' => 'Context', 'type' => 'select', 'options' => ['pending backlog', 'Scully', 'Scully-Offline'], 'default' => 'Scully-Offline'],
            ],
        ],
        [
            'id' => 'run_stg_demmler',
            'category' => 'operations',
            'label' => 'Run STG-DEMMLER',
            'gui_template' => 'Run STG-DEMMLER',
            'description' => 'Session-end Demmler offline staging swap.',
            'runnable' => true,
            'dispatch' => 'staging_job',
            'dispatch_job' => 'STG-DEMMLER',
            'params' => [],
        ],
        [
            'id' => 'generate_orders',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'before',
            'label' => 'Generate Car Orders',
            'gui_template' => 'Generate Orders {shipment}',
            'description' => 'Auto-generate car orders for due shipments, or one shipment when Shipment is set.',
            'runnable' => true,
            'dispatch' => 'generate_orders',
            'params' => [
                [
                    'key' => 'shipment',
                    'label' => 'Shipment',
                    'type' => 'shipment',
                    'options_from' => 'shipments',
                    'allow_custom' => true,
                    'required' => false,
                    'default' => '',
                ],
                [
                    'key' => 'increment_session',
                    'label' => 'Increment session',
                    'type' => 'select',
                    'options' => [
                        ['value' => '', 'label' => 'No'],
                        ['value' => '1', 'label' => 'Yes'],
                    ],
                    'default' => '',
                ],
                [
                    'key' => 'max_unfilled',
                    'label' => 'Max unfilled orders',
                    'type' => 'number',
                    'default' => '',
                    'required' => false,
                    'min' => 0,
                    'step' => 1,
                    'visible_label' => true,
                ],
            ],
        ],
        [
            'id' => 'increment_session',
            'category' => 'session',
            'adder' => true,
            'adder_group' => 'session',
            'label' => 'Increment Session Number',
            'gui_template' => 'Increment Session Number',
            'description' => 'Settings → advance session number by 1.',
            'runnable' => true,
            'dispatch' => 'increment_session',
            'params' => [],
        ],
        [
            'id' => 'fill_orders',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'before',
            'label' => 'Fill Car Orders',
            'gui_template' => 'Fill Orders',
            'description' => 'Orders → fill unfilled car orders.',
            'runnable' => true,
            'dispatch' => 'fill_orders',
            'params' => [
                ['key' => 'percent', 'label' => 'Percent', 'type' => 'percent', 'default' => '100', 'required' => false, 'min' => 0, 'max' => 100, 'step' => 1],
                [
                    'key' => 'order_filters',
                    'label' => 'Order filters',
                    'type' => 'filter_group',
                    'layout' => 'fill_order',
                    'fields' => operational_steps_fill_order_filter_fields(),
                ],
                [
                    'key' => 'car_filters',
                    'label' => 'Auto assign',
                    'type' => 'filter_group',
                    'layout' => 'fill_car',
                    'fields' => operational_steps_fill_order_car_filter_fields(),
                ],
            ],
        ],
        [
            'id' => 'reposition_empties',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'before',
            'label' => 'Reposition Empty Cars',
            'gui_template' => 'Reposition Empties',
            'description' => 'Reposition empty cars: send off-home cars home, or update with a chosen destination.',
            'runnable' => true,
            'dispatch' => 'reposition_empties',
            'gui_path' => '/sts/reposition.php',
            'params' => [
                [
                    'key' => 'mode',
                    'label' => 'Action',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'reposition_to_home', 'label' => 'Reposition to Home'],
                        ['value' => 'update', 'label' => 'Update'],
                    ],
                    'default' => 'reposition_to_home',
                ],
                ['key' => 'percent', 'label' => 'Percent', 'type' => 'percent', 'default' => '65', 'required' => false, 'min' => 0, 'max' => 100, 'step' => 1],
                [
                    'key' => 'destination',
                    'label' => 'Destination',
                    'type' => 'location',
                    'options_from' => 'locations',
                    'allow_custom' => true,
                    'default' => '',
                ],
                [
                    'key' => 'filters',
                    'label' => 'Filters',
                    'type' => 'filter_group',
                    'layout' => 'reposition',
                    'fields' => operational_steps_reposition_filter_fields(),
                ],
            ],
        ],
        [
            'id' => 'auto_assign_locals',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Auto-Assign Cars',
            'gui_template' => 'Auto-Assign Cars {jobs}',
            'description' => 'Auto-assign eligible cars to one or more jobs (Ctrl/Cmd+click to select multiple).',
            'runnable' => true,
            'dispatch' => 'auto_assign_locals',
            'params' => [
                operational_steps_catalog_auto_assign_jobs_param(),
            ],
        ],
        [
            'id' => 'pick_up_cars',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Pick Up Cars',
            'gui_template' => 'Pick Up Cars {job} {location_suffix}',
            'description' => 'Pick up assigned cars onto a job train. Leave job blank for all locals (staging excluded).',
            'runnable' => true,
            'dispatch' => 'pick_up_cars',
            'params' => [
                operational_steps_catalog_job_param(false),
                operational_steps_catalog_location_param(false, 'Location (optional)', true),
            ],
        ],
        [
            'id' => 'set_out_cars',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Set Out Cars',
            'gui_template' => 'Set Out Cars {job} {location}',
            'description' => 'Set out cars from a job train at a location or destination. Leave job and location blank for all locals.',
            'runnable' => true,
            'dispatch' => 'set_out_cars',
            'params' => [
                operational_steps_catalog_job_param(false),
                [
                    'key' => 'location',
                    'label' => 'Location',
                    'type' => 'setout_location',
                    'options_from' => 'setout_locations',
                    'allow_custom' => true,
                    'required' => false,
                    'default' => '',
                ],
            ],
        ],
        [
            'id' => 'load_unload',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'after',
            'label' => 'Load / Unload Cars',
            'gui_template' => 'Load/Unload offline',
            'description' => 'Complete offline load/unload transitions. Optional filters mirror the STS load/unload page.',
            'runnable' => true,
            'dispatch' => 'load_unload',
            'gui_path' => '/sts/load_unload.php',
            'params' => [
                [
                    'key' => 'filters',
                    'label' => 'Filters',
                    'type' => 'filter_group',
                    'fields' => operational_steps_load_unload_filter_fields(),
                ],
            ],
        ],
        [
            'id' => 'track_scale',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Track Scale',
            'gui_template' => 'Weigh Cars {job}',
            'description' => 'Run track scale / weigh operations for a job (job-specific logic if configured).',
            'runnable' => true,
            'dispatch' => 'track_scale',
            'params' => [
                operational_steps_catalog_job_param(false),
            ],
        ],
        [
            'id' => 'weigh_ck1',
            'category' => 'operations',
            'adder' => false,
            'label' => 'Weigh Cars CK1',
            'gui_template' => 'Weigh Cars CK1',
            'description' => 'Track scale weigh, reloads, outbound assignments.',
            'runnable' => true,
            'dispatch' => 'weigh_ck1',
            'params' => [],
        ],
        [
            'id' => 'assign_ck1_reload',
            'category' => 'operations',
            'adder' => false,
            'label' => 'Assign CK1 reload/outbound',
            'gui_template' => 'Assign Cars CK1 reload/outbound',
            'description' => 'Assign reload and outbound coke after weigh.',
            'runnable' => true,
            'dispatch' => 'assign_ck1_reload',
            'params' => [],
        ],
        [
            'id' => 'run_job_criterion',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Run Job Criterion Steps',
            'gui_template' => 'Set Out Cars {job} criterion {steps}',
            'description' => 'Run numbered criterion steps defined on a job.',
            'runnable' => true,
            'dispatch' => 'run_job_criterion',
            'params' => [
                operational_steps_catalog_job_param(true),
                operational_steps_catalog_text_param('steps', 'Criterion step #s', '10,15,20', true, 'Comma-separated'),
            ],
        ],
        [
            'id' => 'run_staging_job',
            'category' => 'operations',
            'adder' => false,
            'label' => 'Run Staging Job',
            'gui_template' => 'Run {job}',
            'description' => 'Complete a staging job cycle (assign, pick up, set out).',
            'runnable' => true,
            'dispatch' => 'staging_job',
            'params' => [
                operational_steps_catalog_job_param(true, 'Staging job'),
            ],
        ],
        [
            'id' => 'composite_nvl_pre_ck1',
            'category' => 'operations',
            'adder' => false,
            'label' => 'NVL pre-CK1 (composite)',
            'gui_template' => 'NVL pre-CK1 block',
            'description' => 'Assign/pick Scully, set out Demmler on NVL.',
            'runnable' => true,
            'dispatch' => 'composite_nvl_pre_ck1',
            'params' => [],
        ],
        [
            'id' => 'composite_ck1_session',
            'category' => 'operations',
            'adder' => false,
            'label' => 'CK1 session (composite)',
            'gui_template' => 'CK1 session block',
            'description' => 'Full CK1 weigh cycle (Shenango → scale → setouts).',
            'runnable' => true,
            'dispatch' => 'composite_ck1_session',
            'params' => [],
        ],
        [
            'id' => 'composite_nvl_post_ck1',
            'category' => 'operations',
            'adder' => false,
            'label' => 'NVL post-CK1 (composite)',
            'gui_template' => 'NVL post-CK1 block',
            'description' => 'CK1 handoff, island/Shenango/Demmler/Scully setouts.',
            'runnable' => true,
            'dispatch' => 'composite_nvl_post_ck1',
            'params' => [],
        ],
        [
            'id' => 'composite_d749_session_start',
            'category' => 'operations',
            'adder' => false,
            'label' => 'D749 session start (composite)',
            'gui_template' => 'D749 session start Demmler→South',
            'description' => 'Assign Demmler, pick up, set out South Yard.',
            'runnable' => true,
            'dispatch' => 'composite_d749_session_start',
            'params' => [],
        ],
        [
            'id' => 'composite_d749_phased',
            'category' => 'operations',
            'adder' => false,
            'label' => 'D749 phased ops (composite)',
            'gui_template' => 'D749 phased remainder',
            'description' => 'South/Demmler setouts, island→Demmler, clear train.',
            'runnable' => true,
            'dispatch' => 'composite_d749_phased',
            'params' => [],
        ],
        [
            'id' => 'finish_local_jobs',
            'category' => 'operations',
            'adder' => false,
            'label' => 'Finish Open Jobs',
            'gui_template' => 'Finish open local jobs',
            'description' => 'Mop up non-staging jobs still holding cars.',
            'runnable' => true,
            'dispatch' => 'finish_local_jobs',
            'params' => [],
        ],
        [
            'id' => 'secure_d749_demmler',
            'category' => 'session',
            'adder' => false,
            'label' => 'Secure D749 at Demmler',
            'gui_template' => 'Assign/Pick Up Cars D749 Demmler',
            'description' => 'Bookend: D749 on train with Demmler block.',
            'runnable' => true,
            'dispatch' => 'secure_d749_demmler',
            'params' => [],
        ],
        [
            'id' => 'secure_nvl_scully',
            'category' => 'session',
            'adder' => false,
            'label' => 'Secure NVL at Scully',
            'gui_template' => 'Assign/Pick Up/Set Out Cars NVL Scully',
            'description' => 'Bookend: NVL secured at Scully yard.',
            'runnable' => true,
            'dispatch' => 'secure_nvl_scully',
            'params' => [],
        ],
        [
            'id' => 'generate_switchlists',
            'category' => 'switchlists',
            'adder' => true,
            'adder_group' => 'switchlists',
            'label' => 'Generate Switch Lists',
            'gui_template' => 'Generate Switch Lists {jobs}',
            'description' => 'Dry-run and write switch list HTML for one job/train or all (D749, NVL, CK1). Format: phased.',
            'runnable' => true,
            'dispatch' => 'generate_switchlists',
            'params' => [
                operational_steps_catalog_job_or_all_param('jobs', 'Job / train'),
            ],
        ],
        [
            'id' => 'generate_waybills',
            'category' => 'switchlists',
            'adder' => true,
            'adder_group' => 'switchlists',
            'label' => 'Generate Waybill List',
            'gui_template' => 'Generate Waybill List',
            'description' => 'Render printable waybill HTML (same layout as STS printable_waybill.php) for every open waybill in the current session.',
            'runnable' => true,
            'dispatch' => 'generate_waybills',
            'params' => [],
        ],
        [
            'id' => 'render_switchlists',
            'category' => 'switchlists',
            'adder' => false,
            'label' => 'Render Switch Lists (cache)',
            'gui_template' => 'Render Switch Lists from cache',
            'description' => 'Re-render HTML from saved phase JSON cache (no DB dry-run).',
            'runnable' => true,
            'dispatch' => 'render_switchlists',
            'params' => [
                ['key' => 'format', 'label' => 'Format', 'type' => 'select', 'options' => ['phased', 'halfsheet', 'mobile'], 'default' => 'phased'],
                ['key' => 'jobs', 'label' => 'Jobs', 'type' => 'text', 'default' => 'D749,NVL,CK1', 'required' => false],
                ['key' => 'session', 'label' => 'Session # (optional)', 'type' => 'text', 'default' => '', 'required' => false],
            ],
        ],
        [
            'id' => 'save_switchlist_cache',
            'category' => 'switchlists',
            'label' => 'Save Switch List Cache',
            'gui_template' => 'Save Switch List Cache',
            'description' => 'Dry-run and save phase JSON cache only (no HTML render).',
            'runnable' => true,
            'dispatch' => 'save_switchlist_cache',
            'params' => [
                ['key' => 'jobs', 'label' => 'Jobs', 'type' => 'text', 'default' => 'D749,NVL,CK1', 'required' => false],
            ],
        ],
        [
            'id' => 'rebuild_switchlists_index',
            'category' => 'switchlists',
            'adder' => false,
            'label' => 'Rebuild Switchlists Index',
            'gui_template' => 'Rebuild Switchlists Index',
            'description' => 'Regenerate switchlists/index.html from session folders.',
            'runnable' => true,
            'dispatch' => 'rebuild_switchlists_index',
            'params' => [],
        ],
        [
            'id' => 'build_switchlists_sts',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Build Switch Lists',
            'gui_template' => 'Build Switch Lists {station} {job}',
            'description' => 'Assign eligible/ordered cars at a station to a job (STS Build Switch Lists).',
            'runnable' => true,
            'dispatch' => 'build_switchlists_sts',
            'gui_path' => '/sts/build_switchlists.php',
            'params' => [
                operational_steps_catalog_station_param(false),
                operational_steps_catalog_job_param(false, 'Job / train'),
            ],
        ],
        [
            'id' => 'display_switchlists_sts',
            'category' => 'switchlists',
            'adder' => false,
            'label' => 'Display Switch Lists',
            'gui_template' => 'Display Switch Lists',
            'description' => 'Reports → Switch Lists for current job assignments.',
            'runnable' => false,
            'gui_path' => '/sts/display_switchlist.php',
            'params' => [],
        ],
        [
            'id' => 'track_scale_gui',
            'category' => 'operations',
            'adder' => false,
            'label' => 'Track Scale (STS GUI)',
            'gui_template' => 'Track Scale',
            'description' => 'Weigh cars at track scale. GUI: track_scale.php.',
            'runnable' => false,
            'gui_path' => '/sts/track_scale.php',
            'params' => [],
        ],
        [
            'id' => 'report_station_car',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'reports',
            'label' => 'Station Car Report',
            'gui_template' => 'Station Car Report',
            'description' => 'Cars currently at each station.',
            'runnable' => false,
            'gui_path' => '/sts/display_station_report.php',
            'params' => [],
        ],
        [
            'id' => 'report_wheel',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'reports',
            'label' => 'Wheel Report',
            'gui_template' => 'Wheel Report',
            'description' => 'Car cycle and movement status.',
            'runnable' => false,
            'gui_path' => '/sts/wheel_report.php',
            'params' => [],
        ],
        [
            'id' => 'report_waybill_list',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'waybills',
            'label' => 'Waybill List',
            'gui_template' => 'Waybill List',
            'description' => 'Waybills and fulfillment status.',
            'runnable' => false,
            'gui_path' => '/sts/display_waybill.php',
            'params' => [],
        ],
        [
            'id' => 'report_waybill_cars_print',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'waybills',
            'disabled' => true,
            'label' => 'Waybill Sheets for Cars',
            'gui_template' => 'Waybill Sheets for Cars',
            'description' => 'Printable car-card waybill sheets.',
            'runnable' => false,
            'gui_path' => '/sts/printable_ccwaybill2.php',
            'params' => [],
        ],
        [
            'id' => 'report_waybill_shipments_print',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'waybills',
            'disabled' => true,
            'label' => 'Waybill Sheets for Shipments',
            'gui_template' => 'Waybill Sheets for Shipments',
            'description' => 'Printable shipment waybill sheets.',
            'runnable' => false,
            'gui_path' => '/sts/printable_ccwaybill.php',
            'params' => [],
        ],
        [
            'id' => 'report_fleet',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'reports',
            'label' => 'Car Fleet Report',
            'gui_template' => 'Car Fleet Report',
            'description' => 'Summarize the active car fleet.',
            'runnable' => false,
            'gui_path' => '/sts/display_fleet_report.php',
            'params' => [],
        ],
        [
            'id' => 'report_fleet_print',
            'category' => 'reports',
            'label' => 'Printable Fleet Report',
            'gui_template' => 'Printable Fleet Report',
            'description' => 'Printable fleet summary.',
            'runnable' => false,
            'gui_path' => '/sts/printable_fleet_report.php',
            'params' => [],
        ],
        [
            'id' => 'report_shipment_forecast',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'reports',
            'label' => 'Shipment Forecast',
            'gui_template' => 'Shipment Forecast',
            'description' => 'Forecast upcoming shipment demand.',
            'runnable' => false,
            'gui_path' => '/sts/shipment_forecast.php',
            'params' => [],
        ],
        [
            'id' => 'report_car_forecast',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'reports',
            'label' => 'Car Forecast',
            'gui_template' => 'Car Forecast',
            'description' => 'Forecast car requirements and availability.',
            'runnable' => false,
            'gui_path' => '/sts/car_forecast.php',
            'params' => [],
        ],
        [
            'id' => 'report_car_qr',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'reports',
            'label' => 'Car QR Codes',
            'gui_template' => 'Car QR Codes',
            'description' => 'Printable QR sheets for cars.',
            'runnable' => false,
            'gui_path' => '/sts/display_car_qr_report.php',
            'params' => [],
        ],
        [
            'id' => 'report_car_qr_print',
            'category' => 'reports',
            'label' => 'Printable Car QR Report',
            'gui_template' => 'Printable Car QR Report',
            'description' => 'Printable car QR code sheets.',
            'runnable' => false,
            'gui_path' => '/sts/printable_car_qr_report.php',
            'params' => [],
        ],
        [
            'id' => 'report_location_qr',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'reports',
            'label' => 'Location QR Codes',
            'gui_template' => 'Location QR Codes',
            'description' => 'Printable QR sheets for stations and locations.',
            'runnable' => false,
            'gui_path' => '/sts/display_station_qr_code_report.php',
            'params' => [],
        ],
        [
            'id' => 'report_location_qr_print',
            'category' => 'reports',
            'label' => 'Printable Location QR Report',
            'gui_template' => 'Printable Location QR Report',
            'description' => 'Printable location QR code sheets.',
            'runnable' => false,
            'gui_path' => '/sts/printable_station_qr_code_report.php',
            'params' => [],
        ],
        [
            'id' => 'report_station_qr_print',
            'category' => 'reports',
            'label' => 'Printable Station QR Code Report',
            'gui_template' => 'Printable Station QR Code Report',
            'description' => 'Alternate printable station QR layout.',
            'runnable' => false,
            'gui_path' => '/sts/printable_station_qr_code_report.php',
            'params' => [],
        ],
    ];
}

function operational_steps_restore_backup($dbc, $backup_name)
{
    $name = basename((string) $backup_name);
    $path = operational_steps_backups_dir() . '/' . $name;
    if (!is_file($path)) {
        return [false, 'Backup not found: ' . $name];
    }
    $sql = explode('#', file_get_contents($path));
    foreach ($sql as $sql_cmd) {
        if (trim($sql_cmd) === '') {
            continue;
        }
        if (!mysqli_query($dbc, $sql_cmd)) {
            if (stripos($sql_cmd, 'drop') === false) {
                return [false, 'SQL error while restoring: ' . mysqli_error($dbc)];
            }
        }
    }
    return [true, $name . ' restored successfully.'];
}

function operational_steps_catalog_by_id()
{
    $map = [];
    foreach (operational_steps_catalog_definitions() as $def) {
        $map[$def['id']] = $def;
    }
    return $map;
}

function operational_steps_catalog_adder_definitions()
{
    $by_id = operational_steps_catalog_by_id();
    $order = operational_steps_catalog_adder_order();
    $ordered = [];
    foreach ($order as $group => $ids) {
        foreach ($ids as $id) {
            if (!isset($by_id[$id])) {
                continue;
            }
            $def = $by_id[$id];
            if (array_key_exists('adder', $def) && $def['adder'] === false) {
                continue;
            }
            $def['adder_group'] = $def['adder_group'] ?? $group;
            if ($group === 'reports' && !isset($def['disabled'])) {
                $def['disabled'] = true;
            }
            $ordered[] = $def;
        }
    }
    return $ordered;
}

function operational_steps_resolve_location_id($dbc, $location_key)
{
    $location_key = trim((string) $location_key);
    if ($location_key === '') {
        return 0;
    }
    if ($location_key === 'remainder') {
        return 0;
    }
    $id = operational_steps_location_station_id($dbc, $location_key);
    if ($id > 0) {
        return $id;
    }
    $code = strtoupper(str_replace(' ', '-', $location_key));
    return warm_start_location_id_by_code($dbc, $code);
}

function operational_steps_location_station_id($dbc, $location_key)
{
    static $map = [
        'Demmler' => 10,
        'South-Yard' => 8,
        'Scully' => 9,
        'Shenango' => 12,
        'South-Scale' => null,
        'Island' => 3,
    ];
    if ($location_key === 'South-Scale') {
        $id = warm_start_location_id_by_code($dbc, 'SOUTH-SCALE');
        return $id > 0 ? $id : warm_start_location_id_by_code($dbc, 'SOUTH');
    }
    if ($location_key === 'Scully-Offline') {
        return 9;
    }
    if (isset($map[$location_key])) {
        $sid = $map[$location_key];
        return $sid === null ? 0 : (int) $sid;
    }
    return 0;
}

function operational_steps_workflow_sections(array $recipe)
{
    $steps = $recipe['steps'] ?? [];
    $total = count($steps);
    $sections = [];
    foreach ($steps as $i => $step) {
        if (!is_array($step) || ($step['function'] ?? '') !== 'section_label') {
            continue;
        }
        $label = trim($step['params']['label'] ?? '');
        if ($label === '') {
            $label = 'Section at step ' . ($i + 1);
        }
        $start = $i + 1;
        $stop = $total;
        for ($j = $i + 1; $j < $total; $j++) {
            $fid = $steps[$j]['function'] ?? '';
            if ($fid === 'section_label') {
                $stop = $j;
                break;
            }
            if ($fid === 'goto') {
                $stop = $j + 1;
                break;
            }
        }
        $sections[] = [
            'id' => 'step-' . $start,
            'label' => $label,
            'start' => $start,
            'stop' => $stop,
        ];
    }
    return $sections;
}

function operational_steps_find_workflow_section(array $recipe, $id = '', $start = 0, $label = '')
{
    foreach (operational_steps_workflow_sections($recipe) as $sec) {
        if ($id !== '' && $sec['id'] === $id) {
            return $sec;
        }
        if ($start > 0 && (int) $sec['start'] === (int) $start) {
            return $sec;
        }
        if ($label !== '') {
            $want = trim($label);
            if ($sec['label'] === $want || stripos($sec['label'], $want) !== false || stripos($want, $sec['label']) !== false) {
                return $sec;
            }
        }
    }
    return null;
}

function operational_steps_goto_resolve_step(array $recipe, array $params)
{
    $section = trim((string) ($params['section'] ?? ''));
    if ($section !== '') {
        $sec = operational_steps_find_workflow_section($recipe, $section);
        if ($sec) {
            return (int) $sec['start'];
        }
    }
    $section_label = trim((string) ($params['section_label'] ?? ''));
    if ($section_label !== '') {
        $sec = operational_steps_find_workflow_section($recipe, '', 0, $section_label);
        if ($sec) {
            return (int) $sec['start'];
        }
    }
    return (int) ($params['step'] ?? 0);
}

/** Goto may only skip forward (target step must be after the goto step). */
function operational_steps_goto_target_allowed($from_step, $target, $total_steps)
{
    $from_step = (int) $from_step;
    $target = (int) $target;
    $total_steps = (int) $total_steps;
    return $target > $from_step && $target >= 1 && $target <= $total_steps;
}

function operational_steps_normalize_goto_sections(array $recipe)
{
    $steps = $recipe['steps'] ?? [];
    foreach ($steps as $i => $step) {
        if (!is_array($step) || ($step['function'] ?? '') !== 'goto') {
            continue;
        }
        $params = is_array($step['params'] ?? null) ? $step['params'] : [];
        $sec = null;
        if (!empty($params['section'])) {
            $sec = operational_steps_find_workflow_section($recipe, (string) $params['section']);
        } elseif (!empty($params['section_label'])) {
            $sec = operational_steps_find_workflow_section($recipe, '', 0, (string) $params['section_label']);
        } elseif (!empty($params['step'])) {
            $sec = operational_steps_find_workflow_section($recipe, '', (int) $params['step']);
        }
        if ($sec) {
            $params['section'] = $sec['id'];
            $params['section_label'] = $sec['label'];
            $params['step'] = (string) $sec['start'];
        }
        $steps[$i]['params'] = $params;
    }
    $recipe['steps'] = $steps;
    return $recipe;
}

function operational_steps_compile_gui(array $def, array $params)
{
    if (($def['id'] ?? '') === 'goto') {
        if (!empty($params['section_label'])) {
            return 'Goto ' . trim((string) $params['section_label']);
        }
        if (!empty($params['step'])) {
            return 'Goto step ' . trim((string) $params['step']);
        }
        return 'Goto';
    }
    if (($def['id'] ?? '') === 'if_then') {
        $var = (string) ($params['variable'] ?? 'session_nbr');
        if ($var === 'session_nbr') {
            $var = 'session #';
        }
        return trim('If ' . $var . ' ' . ($params['operator'] ?? '') . ' ' . ($params['value'] ?? ''));
    }
    if (($def['id'] ?? '') === 'text_instruction') {
        return trim((string) ($params['instruction'] ?? ''));
    }
    if (($def['id'] ?? '') === 'load_unload') {
        return operational_steps_compile_load_unload_gui($params['filters'] ?? []);
    }
    if (($def['id'] ?? '') === 'fill_orders') {
        return operational_steps_compile_fill_orders_gui($params);
    }
    if (($def['id'] ?? '') === 'reposition_empties') {
        return operational_steps_compile_reposition_gui($params);
    }
    if (($def['id'] ?? '') === 'generate_orders') {
        return operational_steps_compile_generate_orders_gui($params);
    }
    if (($def['id'] ?? '') === 'auto_assign_locals') {
        return operational_steps_compile_auto_assign_gui($params);
    }
    if (($def['id'] ?? '') === 'pick_up_cars' && trim((string) ($params['job'] ?? '')) === '') {
        return 'Pick Up Cars locals';
    }
    if (($def['id'] ?? '') === 'set_out_cars'
        && trim((string) ($params['job'] ?? '')) === ''
        && trim((string) ($params['location'] ?? '')) === '') {
        return 'Set Out Cars locals';
    }
    $template = $def['gui_template'] ?? $def['label'];
    $merged = $params;
    if (($def['id'] ?? '') === 'pick_up_cars' && empty($merged['location'])) {
        $merged['location_suffix'] = '';
    } else {
        $merged['location_suffix'] = !empty($merged['location']) ? $merged['location'] : '';
    }
    if (($def['id'] ?? '') === 'track_scale') {
        $job = trim($params['job'] ?? '');
        $merged['job'] = $job !== '' ? $job : 'train';
    }
    if (($def['id'] ?? '') === 'generate_switchlists') {
        $jobs = trim((string) ($params['jobs'] ?? 'all'));
        if ($jobs === '') {
            $jobs = 'all';
        }
        $merged['jobs'] = $jobs;
    }
    if (($def['id'] ?? '') === 'run_staging_job') {
        $merged['job'] = $params['job'] ?? '';
    }
    return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($merged) {
        $key = $m[1];
        if (!isset($merged[$key]) || $merged[$key] === '') {
            return '';
        }
        return trim((string) $merged[$key]);
    }, $template);
}

function operational_steps_compile_description(array $def, array $params, $custom = '')
{
    if ($custom !== '') {
        return $custom;
    }
    $base = $def['description'] ?? '';
    if (empty($params)) {
        return $base;
    }
    $parts = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        if (is_array($v)) {
            $flat = array_filter($v, static function ($item) {
                return $item !== '' && $item !== null;
            });
            if (!empty($flat)) {
                $parts[] = $k . '=' . json_encode($flat, JSON_UNESCAPED_SLASHES);
            }
            continue;
        }
        $parts[] = $k . '=' . $v;
    }
    if (empty($parts)) {
        return $base;
    }
    return $base . ' Params: ' . implode(', ', $parts) . '.';
}

function operational_steps_compile_recipe(array $recipe)
{
    $catalog = operational_steps_catalog_by_id();
    $rows = [];
    foreach ($recipe['steps'] ?? [] as $step) {
        if (!is_array($step)) {
            continue;
        }
        $fid = $step['function'] ?? $step['id'] ?? '';
        if ($fid === '' || !isset($catalog[$fid])) {
            $rows[] = [
                'function' => $fid,
                'instruction' => $step['instruction'] ?? '(unknown)',
                'description' => $step['description'] ?? '',
                'params' => $step['params'] ?? [],
            ];
            continue;
        }
        $def = $catalog[$fid];
        $params = is_array($step['params'] ?? null) ? $step['params'] : [];
        $rows[] = [
            'function' => $fid,
            'instruction' => operational_steps_compile_gui($def, $params),
            'description' => operational_steps_compile_description($def, $params, $step['description'] ?? ''),
            'params' => $params,
        ];
    }
    return $rows;
}

function operational_steps_recipe_to_csv(array $recipe)
{
    $compiled = operational_steps_compile_recipe($recipe);
    $lines = ['Step #,STS GUI Instruction,Full Description'];
    $n = 0;
    foreach ($compiled as $row) {
        $n++;
        $lines[] = operational_steps_csv_escape((string) $n)
            . ',' . operational_steps_csv_escape($row['instruction'])
            . ',' . operational_steps_csv_escape($row['description']);
    }
    return implode("\n", $lines) . "\n";
}

function operational_steps_csv_escape($value)
{
    $value = str_replace(["\r\n", "\r", "\n"], ' ', (string) $value);
    if (preg_match('/[",\n\r]/', $value)) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

function operational_steps_parse_csv($text)
{
    $rows = [];
    $parsed = [];
    $i = 0;
    $field = '';
    $row = [];
    $inQuotes = false;
    $len = strlen($text);
    while ($i < $len) {
        $c = $text[$i];
        if ($inQuotes) {
            if ($c === '"') {
                if ($i + 1 < $len && $text[$i + 1] === '"') {
                    $field .= '"';
                    $i += 2;
                    continue;
                }
                $inQuotes = false;
                $i++;
                continue;
            }
            $field .= $c;
            $i++;
            continue;
        }
        if ($c === '"') {
            $inQuotes = true;
            $i++;
            continue;
        }
        if ($c === ',') {
            $row[] = $field;
            $field = '';
            $i++;
            continue;
        }
        if ($c === "\r") {
            $i++;
            continue;
        }
        if ($c === "\n") {
            $row[] = $field;
            $field = '';
            if (count($row) > 1 || $row[0] !== '') {
                $parsed[] = $row;
            }
            $row = [];
            $i++;
            continue;
        }
        $field .= $c;
        $i++;
    }
    $row[] = $field;
    if (count($row) > 1 || $row[0] !== '') {
        $parsed[] = $row;
    }
    if (count($parsed) < 2) {
        return [];
    }
    for ($r = 1; $r < count($parsed); $r++) {
        $cols = $parsed[$r];
        $instruction = $cols[1] ?? '';
        $description = $cols[2] ?? '';
        $rows[] = [
            'function' => operational_steps_guess_function($instruction),
            'params' => operational_steps_guess_params($instruction),
            'instruction' => $instruction,
            'description' => $description,
        ];
    }
    $steps = array_map('operational_steps_normalize_step', $rows);
    return $steps;
}

function operational_steps_gui_template_to_regex($template)
{
    $out = '/^';
    $len = strlen($template);
    $i = 0;
    while ($i < $len) {
        if ($template[$i] === '{') {
            $end = strpos($template, '}', $i);
            if ($end === false) {
                break;
            }
            $key = substr($template, $i + 1, $end - $i - 1);
            switch ($key) {
                case 'steps':
                    $out .= '([\d,\s]+)';
                    break;
                case 'add_replace':
                    $out .= '(append|replace)';
                    break;
                case 'scope':
                    $out .= '(locals|\S+)';
                    break;
                case 'context':
                    $out .= '(?:\S+(?:\s+\S+)*)?';
                    break;
                case 'location':
                case 'location_suffix':
                    $out .= '(?:\s+(.+))?';
                    break;
                case 'shipment':
                case 'jobs':
                    $out .= '(?:.*)?';
                    break;
                default:
                    $out .= '(?:\S+(?:-\S+)?(?:\s+\S+)*)?';
            }
            $i = $end + 1;
            continue;
        }
        $next = strpos($template, '{', $i);
        if ($next === false) {
            $next = $len;
        }
        $out .= preg_quote(substr($template, $i, $next - $i), '/');
        $i = $next;
    }
    $out .= '\s*$/i';
    return $out;
}

function operational_steps_guess_catalog_function($instruction)
{
    static $patterns = null;
    if ($patterns === null) {
        $patterns = [];
        $defs = operational_steps_catalog_definitions();
        usort($defs, static function ($a, $b) {
            $la = strlen($a['gui_template'] ?? $a['label'] ?? '');
            $lb = strlen($b['gui_template'] ?? $b['label'] ?? '');
            return $lb <=> $la;
        });
        foreach ($defs as $def) {
            $id = $def['id'] ?? '';
            if (in_array($id, ['section_label', 'marker', 'text_instruction', 'goto', 'if_then'], true)) {
                continue;
            }
            $template = trim($def['gui_template'] ?? '');
            if ($template === '' || $template === '{label}' || $template === '{note}') {
                continue;
            }
            $patterns[] = [
                'id' => $id,
                'regex' => operational_steps_gui_template_to_regex($template),
            ];
        }
    }
    $s = trim($instruction);
    foreach ($patterns as $p) {
        if (preg_match($p['regex'], $s)) {
            return $p['id'];
        }
    }
    return null;
}

function operational_steps_guess_function($instruction)
{
    $s = trim($instruction);
    if ($s === '') {
        return 'text_instruction';
    }
    if (preg_match('/^Goto step\s+\d+/i', $s) || preg_match('/^Goto\s+\[/i', $s)) {
        return 'goto';
    }
    if (preg_match('/^If\s+/i', $s)) {
        return 'if_then';
    }
    if (stripos($s, 'Restore Database') !== false) {
        return 'restore_database';
    }
    if (preg_match('/^\[Setup once\]\s*$/i', $s)) {
        return 'marker';
    }
    if (stripos($s, 'repeat steps') !== false && stripos($s, 'Assign Cars') === false && stripos($s, 'Warm start') !== false) {
        return 'marker';
    }
    if (preg_match('/^\[[^\]]+\]\s*$/', $s)) {
        return 'marker';
    }
    if (preg_match('/^\[(Warm start end|Session end|Setup end)/i', $s)) {
        return 'marker';
    }
    if (preg_match('/^\[(Warm start|Each operating session|Setup once|Session end)/i', $s) && stripos($s, 'Assign Cars') === false && stripos($s, 'Run STG') === false) {
        return 'marker';
    }
    if (stripos($s, 'Generate Switch Lists') !== false) {
        return 'generate_switchlists';
    }
    if (stripos($s, 'Increment Session') !== false) {
        return 'increment_session';
    }
    if (stripos($s, 'Generate Orders') !== false) {
        return 'generate_orders';
    }
    if (stripos($s, 'Fill Orders') !== false) {
        return 'fill_orders';
    }
    if (stripos($s, 'Reposition Empties') !== false) {
        return 'reposition_empties';
    }
    if (stripos($s, 'Auto-Assign') !== false) {
        return 'auto_assign_locals';
    }
    if (stripos($s, 'Load/Unload') !== false) {
        return 'load_unload';
    }
    if (stripos($s, 'Weigh Cars CK1') !== false) {
        return 'weigh_ck1';
    }
    if (stripos($s, 'reload/outbound') !== false) {
        return 'assign_ck1_reload';
    }
    if (stripos($s, 'Run STG-DEMMLER') !== false) {
        return 'run_stg_demmler';
    }
    if (stripos($s, 'Run STG-SCULLY') !== false) {
        return 'run_stg_scully';
    }
    if (preg_match('/^Run (\S+)/i', $s)) {
        return 'run_staging_job';
    }
    if (stripos($s, 'Finish open local') !== false) {
        return 'finish_local_jobs';
    }
    if (stripos($s, 'NVL pre-CK1') !== false) {
        return 'composite_nvl_pre_ck1';
    }
    if (stripos($s, 'CK1 session') !== false) {
        return 'composite_ck1_session';
    }
    if (stripos($s, 'NVL post-CK1') !== false) {
        return 'composite_nvl_post_ck1';
    }
    if (stripos($s, 'D749 session start') !== false) {
        return 'composite_d749_session_start';
    }
    if (stripos($s, 'D749 phased') !== false) {
        return 'composite_d749_phased';
    }
    if (preg_match('/Assign.*D749.*Demmler/i', $s) && preg_match('/Pick Up/i', $s)) {
        return 'secure_d749_demmler';
    }
    if (preg_match('/Assign.*NVL.*Scully/i', $s) && preg_match('/Set Out/i', $s)) {
        return 'secure_nvl_scully';
    }
    if (preg_match('/Assign Cars/i', $s, $m)) {
        return 'build_switchlists_sts';
    }
    if (stripos($s, 'Pick Up Cars locals') !== false) {
        return 'pick_up_cars';
    }
    if (stripos($s, 'Set Out Cars locals') !== false) {
        return 'set_out_cars';
    }
    if (stripos($s, 'Pick Up Cars') !== false) {
        return 'pick_up_cars';
    }
    if (stripos($s, 'criterion') !== false) {
        return 'run_job_criterion';
    }
    if (stripos($s, 'Set Out Cars') !== false) {
        return 'set_out_cars';
    }
    $fromCatalog = operational_steps_guess_catalog_function($s);
    if ($fromCatalog !== null) {
        return $fromCatalog;
    }
    return 'text_instruction';
}

function operational_steps_guess_params($instruction)
{
    $params = [];
    $s = trim($instruction);

    if (preg_match('/^Goto step\s+(\d+)/i', $s, $m)) {
        $params['step'] = $m[1];
    }
    if (preg_match('/^Goto\s+(\[.+\])\s*$/i', $s, $m)) {
        $params['section_label'] = trim($m[1]);
    }
    if (preg_match('/^If\s+session(?:\s*#|_nbr)?\s*(>=|<=|!=|>|<|=)\s*(.+)$/i', $s, $m)) {
        $params['variable'] = 'session_nbr';
        $params['operator'] = trim($m[1]);
        $params['value'] = trim($m[2]);
    } elseif (preg_match('/^If\s+(\w+)\s*(>=|<=|!=|>|<|=)\s*(.+)$/i', $s, $m)) {
        $params['variable'] = $m[1];
        $params['operator'] = trim($m[2]);
        $params['value'] = trim($m[3]);
    }
    if (preg_match('/Restore Database (\S+)/i', $s, $m)) {
        $params['backup'] = $m[1];
    }
    if (preg_match('/^Build Switch Lists(?:\s+(.+))?$/i', $s, $m)) {
        $rest = trim($m[1] ?? '');
        if ($rest !== '') {
            $parts = preg_split('/\s+/', $rest, 2);
            $params['station'] = trim($parts[0] ?? '');
            if (!empty($parts[1])) {
                $params['job'] = trim($parts[1]);
            }
        }
    }
    if (preg_match('/^Generate Switch Lists(?:\s+(.+))?$/i', $s, $m)) {
        $job = trim($m[1] ?? '');
        if ($job !== '') {
            $params['jobs'] = $job;
        }
    }
    if (preg_match('/^Run (\S+(?:-\S+)?)/i', $s, $m)) {
        $params['job'] = $m[1];
    }
    if (preg_match('/Assign Cars (\S+(?:-\S+)?)\s+(.+)$/i', $s, $m)) {
        $params['job'] = trim($m[1]);
        $location = trim(preg_replace('/^\[.+?\]\s*/', '', $m[2]));
        if (stripos($location, 'reload/outbound') === false) {
            $params['station'] = $location;
        }
    } elseif (preg_match('/Assign Cars (\S+(?:-\S+)?)/i', $s, $m)) {
        $params['job'] = $m[1];
    }
    if (preg_match('/^Pick Up Cars\s+locals\s*$/i', $s)) {
        $params['job'] = '';
        $params['location'] = '';
    } elseif (preg_match('/Pick Up Cars (\S+(?:-\S+)?)(?:\s+(.+))?$/i', $s, $m)) {
        $params['job'] = trim($m[1]);
        if (!empty(trim($m[2] ?? ''))) {
            $params['location'] = trim($m[2]);
        }
    }
    if (preg_match('/^Set Out Cars\s+locals\s*$/i', $s)) {
        $params['job'] = '';
        $params['location'] = '';
    } elseif (preg_match('/Set Out Cars (\S+(?:-\S+)?)\s+(.+)$/i', $s, $m)) {
        $params['job'] = trim($m[1]);
        $params['location'] = trim($m[2]);
    }
    if (preg_match('/Defer (\S+(?:-\S+)?)(?:\s+leave backlog\s+(.+))?/i', $s, $m)) {
        $params['job'] = trim($m[1]);
        if (!empty(trim($m[2] ?? ''))) {
            $params['location'] = trim($m[2]);
        }
    }
    if (preg_match('/criterion\s+([\d,\s]+)/i', $s, $m)) {
        $params['steps'] = preg_replace('/\s+/', '', $m[1]);
    }
    if (preg_match('/(Demmler|South-Yard|Scully-Offline|Scully|Shenango|South-Scale|Island|CK1-handoff)/i', $s, $m)
        && empty($params['location'])) {
        $params['location'] = $m[1];
    }
    if (stripos($s, 'Generate Orders') !== false) {
        if (preg_match('/increment session/i', $s)) {
            $params['increment_session'] = '1';
        }
        if (preg_match('/max_unfilled=(\d+)/i', $s, $m)) {
            $params['max_unfilled'] = $m[1];
        }
    }
    if (stripos($s, 'Load/Unload') !== false) {
        if (preg_match('/^Load\/Unload\s+offline\s*$/i', $s)) {
            $params['filters'] = operational_steps_load_unload_default_filters();
        } elseif (preg_match('/^Load\/Unload\s+(.+)$/i', $s, $m)) {
            $params['filters'] = operational_steps_parse_load_unload_filters($m[1]);
        } else {
            $params['filters'] = operational_steps_load_unload_default_filters();
        }
    }
    if (stripos($s, 'Fill Orders') !== false) {
        if (preg_match('/^Fill Orders\s*$/i', $s)) {
            $params['order_filters'] = operational_steps_fill_order_default_filters();
            $params['car_filters'] = operational_steps_fill_order_car_default_filters();
        } elseif (preg_match('/^Fill Orders\s+(.+)$/i', $s, $m)) {
            $parsed = operational_steps_parse_fill_orders_suffix($m[1]);
            $params['order_filters'] = $parsed['order_filters'];
            $params['car_filters'] = $parsed['car_filters'];
        } else {
            $params['order_filters'] = operational_steps_fill_order_default_filters();
            $params['car_filters'] = operational_steps_fill_order_car_default_filters();
        }
    }
    if (stripos($s, 'Reposition Empties') !== false) {
        $params['mode'] = 'reposition_to_home';
        $params['filters'] = operational_steps_reposition_default_filters();
        if (preg_match('/\bupdate\b/i', $s)) {
            $params['mode'] = 'update';
        } elseif (preg_match('/\bto home\b/i', $s)) {
            $params['mode'] = 'reposition_to_home';
        }
        if (preg_match('/(?:^|[;\s])dest(?:ination)?=([^;]+)/i', $s, $m)) {
            $params['destination'] = trim($m[1]);
            $params['mode'] = 'update';
        }
        $suffix = preg_replace('/^Reposition Empties/i', '', $s);
        $suffix = preg_replace('/^\s*(?:to home|update)\s*/i', '', $suffix);
        if (preg_match('/(?:^|[;\s])dest(?:ination)?=([^;]+)/i', $suffix, $m)) {
            $suffix = trim(str_replace($m[0], '', $suffix), " \t;");
        }
        $suffix = trim($suffix, " \t;");
        if ($suffix !== '') {
            $params['filters'] = operational_steps_parse_reposition_filters($suffix);
        }
    }
    if (preg_match('/^\[(Warm start end|Session end|Setup end)\]/i', $s, $m)) {
        $params['note'] = '[' . $m[1] . ']';
    } elseif (preg_match('/^\[(.+)\]$/', $s) || (preg_match('/^\[/', $s) && stripos($s, 'Assign Cars') === false && stripos($s, 'Restore Database') === false)) {
        $params['note'] = $s;
    }
    if (stripos($s, 'locals') !== false && stripos($s, 'Auto-Assign') !== false) {
        $params['jobs'] = '';
    } elseif (preg_match('/^Auto-Assign Cars\s+(.+)$/i', $s, $m)) {
        $rest = trim($m[1]);
        if (strtolower($rest) === 'locals') {
            $params['jobs'] = '';
        } else {
            $params['jobs'] = preg_replace('/\s*,\s*/', ',', $rest);
        }
    }
    if (stripos($s, 'Weigh Cars') !== false && preg_match('/Weigh Cars (\S+)/i', $s, $m)) {
        $params['job'] = $m[1];
    }
    return $params;
}

function operational_steps_normalize_step(array $step)
{
    $catalog = operational_steps_catalog_by_id();
    $fid = $step['function'] ?? '';
    $instruction = trim($step['instruction'] ?? '');
    $description = trim($step['description'] ?? '');

    if ($fid === '' || $fid === 'marker' || $fid === 'section_label' || !isset($catalog[$fid])) {
        if ($instruction !== '') {
            $fid = operational_steps_guess_function($instruction);
        }
    }
    if ($fid === 'marker') {
        $isSectionLabel = preg_match('/^\[[^\]]+\]\s*$/', $instruction)
            || (preg_match('/^\[(Warm start|Each operating session|Setup once|Session end)/i', $instruction)
                && stripos($instruction, 'Assign Cars') === false
                && stripos($instruction, 'Run STG') === false)
            || (stripos($instruction, 'repeat steps') !== false && stripos($instruction, 'Warm start') !== false);
        if ($isSectionLabel) {
            if (empty($step['params']['label']) && !empty($step['params']['note'])) {
                $step['params']['label'] = $step['params']['note'];
            } elseif ($instruction !== '' && empty($step['params']['label'])) {
                $step['params']['label'] = $instruction;
            }
            $fid = 'section_label';
        } else {
            $fid = 'text_instruction';
            if ($instruction !== '') {
                $step['params']['instruction'] = $instruction;
            } elseif (!empty($step['params']['note'])) {
                $step['params']['instruction'] = $step['params']['note'];
            }
        }
    }

    if ($fid === 'restore_database' && empty($step['params']['backup'])) {
        $step['params']['backup'] = 'hart_seed';
    }
    if ($fid === 'assign_cars') {
        $fid = 'build_switchlists_sts';
        if (!empty($step['params']['location']) && empty($step['params']['station'])) {
            $step['params']['station'] = $step['params']['location'];
        }
        unset($step['params']['location']);
    }
    if ($fid === 'pick_up_locals') {
        $fid = 'pick_up_cars';
        $step['params']['job'] = '';
        $step['params']['location'] = '';
    }
    if ($fid === 'set_out_locals') {
        $fid = 'set_out_cars';
        $step['params']['job'] = '';
        $step['params']['location'] = '';
    }
    if ($fid === 'defer_stg_scully' || $fid === 'defer_staging') {
        $fid = 'section_label';
        if (stripos($instruction, 'Warm start end') !== false) {
            $step['params']['label'] = '[Warm start end]';
        } elseif (stripos($instruction, 'Session end') !== false) {
            $step['params']['label'] = '[Session end]';
        } elseif ($instruction !== '') {
            $step['params']['label'] = trim(preg_replace('/\s*Defer\b.*/i', '', $instruction));
        }
    }
    if ($fid === 'run_stg_scully' || $fid === 'run_stg_demmler') {
        if ($fid === 'run_stg_demmler') {
            $step['params']['job'] = 'STG-DEMMLER';
        } elseif (empty($step['params']['job'])) {
            $step['params']['job'] = 'STG-SCULLY';
        }
        $fid = 'run_staging_job';
    }
    if ($fid === 'weigh_ck1') {
        $fid = 'track_scale';
        $step['params']['job'] = 'CK1';
    }
    if (in_array($fid, ['secure_d749_demmler', 'secure_nvl_scully'], true)) {
        // Keep composite ids for legacy dispatch; params filled from instruction if missing
    }

    $params = is_array($step['params'] ?? null) ? $step['params'] : [];
    if ($fid === 'text_instruction' && empty($params['instruction'])) {
        if ($instruction !== '') {
            $params['instruction'] = $instruction;
        } elseif (!empty($params['note'])) {
            $params['instruction'] = $params['note'];
        }
    }
    if ($fid === 'load_unload') {
        $params['filters'] = operational_steps_normalize_load_unload_filters($params);
    }
    if ($fid === 'fill_orders') {
        $params['order_filters'] = operational_steps_normalize_fill_order_filters($params);
        $params['car_filters'] = operational_steps_normalize_fill_car_filters($params);
    }
    if ($fid === 'reposition_empties') {
        $params['mode'] = trim((string) ($params['mode'] ?? 'reposition_to_home'));
        if ($params['mode'] === '') {
            $params['mode'] = 'reposition_to_home';
        }
        $params['filters'] = operational_steps_normalize_reposition_filters($params);
    }
    $guessed = $instruction !== '' ? operational_steps_guess_params($instruction) : [];
    foreach ($guessed as $k => $v) {
        if ($k === 'filters' && is_array($v)) {
            if ($fid === 'reposition_empties') {
                $params['filters'] = operational_steps_normalize_reposition_filters(array_merge($params, ['filters' => $v]));
            } else {
                $params['filters'] = operational_steps_normalize_load_unload_filters(array_merge($params, ['filters' => $v]));
            }
            continue;
        }
        if ($k === 'order_filters' && is_array($v)) {
            $params['order_filters'] = operational_steps_normalize_fill_order_filters(array_merge($params, ['order_filters' => $v]));
            continue;
        }
        if ($k === 'car_filters' && is_array($v)) {
            $params['car_filters'] = operational_steps_normalize_fill_car_filters(array_merge($params, ['car_filters' => $v]));
            continue;
        }
        if ($v !== '' && (empty($params[$k]) || $params[$k] === 'note')) {
            $params[$k] = $v;
        }
    }
    if ($fid === 'load_unload') {
        $params['filters'] = operational_steps_normalize_load_unload_filters($params);
    }
    if ($fid === 'fill_orders') {
        $params['order_filters'] = operational_steps_normalize_fill_order_filters($params);
        $params['car_filters'] = operational_steps_normalize_fill_car_filters($params);
        $params['percent'] = (string) (int) operational_steps_normalize_percent($params, 100);
    }
    if ($fid === 'reposition_empties') {
        $params['mode'] = trim((string) ($params['mode'] ?? 'reposition_to_home'));
        if ($params['mode'] === '') {
            $params['mode'] = 'reposition_to_home';
        }
        $params['filters'] = operational_steps_normalize_reposition_filters($params);
        $params['percent'] = (string) (int) operational_steps_normalize_percent($params, 65);
    }
    if ($fid === 'generate_orders') {
        $params = array_merge($params, operational_steps_normalize_generate_orders_params($params));
    }
    if ($fid === 'auto_assign_locals') {
        $params['jobs'] = operational_steps_normalize_auto_assign_jobs($params);
    }

    if (isset($catalog[$fid])) {
        $allowed = [];
        foreach ($catalog[$fid]['params'] ?? [] as $pdef) {
            if (!empty($pdef['key'])) {
                $allowed[$pdef['key']] = true;
            }
        }
        foreach (array_keys($params) as $key) {
            if (!isset($allowed[$key])) {
                unset($params[$key]);
            }
        }
        foreach ($catalog[$fid]['params'] ?? [] as $pdef) {
            $key = $pdef['key'] ?? '';
            if ($key === '' || isset($params[$key])) {
                continue;
            }
            if (isset($pdef['default']) && $pdef['default'] !== '') {
                $params[$key] = $pdef['default'];
            }
        }
    }

    $normalized = [
        'function' => $fid,
        'params' => $params,
    ];
    if ($description !== '') {
        $normalized['description'] = $description;
    }
    if ($instruction !== '' && ($normalized['function'] ?? '') === 'marker') {
        $normalized['params']['note'] = $instruction;
    }
    if (($normalized['function'] ?? '') === 'text_instruction' && empty($normalized['params']['instruction']) && $instruction !== '') {
        $normalized['params']['instruction'] = $instruction;
    }
    return $normalized;
}

function operational_steps_normalize_recipe(array $recipe)
{
    $steps = [];
    foreach ($recipe['steps'] ?? [] as $step) {
        if (!is_array($step)) {
            continue;
        }
        $steps[] = operational_steps_normalize_step($step);
    }
    $recipe['steps'] = $steps;
    if (!isset($recipe['version'])) {
        $recipe['version'] = 1;
    }
    return operational_steps_normalize_goto_sections($recipe);
}

function operational_steps_default_recipe_from_csv_file($path)
{
    if (!is_file($path)) {
        return ['version' => 1, 'name' => 'default', 'steps' => []];
    }
    $text = file_get_contents($path);
    $steps = operational_steps_parse_csv($text);
    return operational_steps_normalize_recipe(['version' => 1, 'name' => 'imported', 'steps' => $steps]);
}

function operational_steps_recipe_paths($switchlists_dir)
{
    return [
        'recipe' => rtrim($switchlists_dir, '/') . '/STS_OPERATIONAL_RECIPE.json',
        'csv' => rtrim($switchlists_dir, '/') . '/STS_OPERATIONAL_STEPS.csv',
    ];
}

function operational_steps_sanitize_csv_name($name)
{
    $name = trim((string) $name);
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return 'STS_OPERATIONAL_STEPS.csv';
    }
    if (!preg_match('/\.csv$/i', $name)) {
        $name .= '.csv';
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]*\.csv$/', $name)) {
        throw new InvalidArgumentException('Invalid CSV filename');
    }
    return $name;
}

function operational_steps_recipe_paths_for_csv($switchlists_dir, $csv_name = null)
{
    $switchlists_dir = rtrim($switchlists_dir, '/');
    if ($csv_name === null || $csv_name === '') {
        return operational_steps_recipe_paths($switchlists_dir);
    }
    $csv_name = operational_steps_sanitize_csv_name($csv_name);
    $csv_path = $switchlists_dir . '/' . $csv_name;
    if (strcasecmp($csv_name, 'STS_OPERATIONAL_STEPS.csv') === 0) {
        return [
            'csv' => $csv_path,
            'recipe' => $switchlists_dir . '/STS_OPERATIONAL_RECIPE.json',
        ];
    }
    $stem = preg_replace('/\.csv$/i', '', $csv_name);
    return [
        'csv' => $csv_path,
        'recipe' => $switchlists_dir . '/' . $stem . '.recipe.json',
    ];
}

function operational_steps_editor_state_path($switchlists_dir)
{
    return rtrim(operational_steps_editor_dir(), '/') . '/.session_editor.json';
}

function operational_steps_load_editor_state($switchlists_dir)
{
    $path = operational_steps_editor_state_path($switchlists_dir);
    if (is_file($path)) {
        $data = json_decode(file_get_contents($path), true);
        if (is_array($data)) {
            return $data;
        }
    }
    return ['active_csv' => ''];
}

function operational_steps_save_editor_state($switchlists_dir, array $state)
{
    $path = operational_steps_editor_state_path($switchlists_dir);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    return @file_put_contents($path, $json) !== false;
}

function operational_steps_active_csv($switchlists_dir, $requested = null)
{
    if ($requested !== null && $requested !== '') {
        return operational_steps_sanitize_csv_name($requested);
    }
    return operational_steps_resolve_active_csv($switchlists_dir);
}

function operational_steps_list_csv_files($switchlists_dir = null)
{
    $switchlists_dir = rtrim($switchlists_dir ?? operational_steps_editor_dir(), '/');
    $files = [];
    if (is_dir($switchlists_dir)) {
        foreach (glob($switchlists_dir . '/*.csv') ?: [] as $path) {
            if (is_file($path)) {
                $files[] = basename($path);
            }
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values(array_unique($files));
}

function operational_steps_resolve_active_csv($switchlists_dir, $requested = null)
{
    $editor_dir = operational_steps_editor_dir();
    $files = operational_steps_list_csv_files($editor_dir);
    if ($requested !== null && $requested !== '') {
        $name = operational_steps_sanitize_csv_name($requested);
        return in_array($name, $files, true) ? $name : '';
    }
    if (count($files) === 1) {
        return $files[0];
    }
    return '';
}

function operational_steps_set_active_csv($switchlists_dir, $csv_name)
{
    $csv_name = operational_steps_sanitize_csv_name($csv_name);
    operational_steps_save_editor_state(operational_steps_editor_dir(), ['active_csv' => $csv_name]);
    return $csv_name;
}

function operational_steps_load_recipe($switchlists_dir, $csv_name = null)
{
    $editor_dir = operational_steps_editor_dir();
    if ($csv_name === null || $csv_name === '') {
        $csv_name = operational_steps_resolve_active_csv($editor_dir);
        if ($csv_name === '') {
            return ['version' => 1, 'name' => 'empty', 'steps' => [], 'source_csv' => ''];
        }
    } else {
        $csv_name = operational_steps_sanitize_csv_name($csv_name);
        if (!in_array($csv_name, operational_steps_list_csv_files($editor_dir), true)) {
            return ['version' => 1, 'name' => 'empty', 'steps' => [], 'source_csv' => ''];
        }
    }

    $paths = operational_steps_recipe_paths_for_csv($editor_dir, $csv_name);
    if (!is_file($paths['csv'])) {
        return ['version' => 1, 'name' => 'empty', 'steps' => [], 'source_csv' => $csv_name];
    }
    $recipe = operational_steps_default_recipe_from_csv_file($paths['csv']);
    $recipe['source_csv'] = basename($paths['csv']);
    return $recipe;
}

function operational_steps_save_recipe($switchlists_dir, array $recipe, $csv_name = null)
{
    $editor_dir = operational_steps_editor_dir();
    $paths = operational_steps_recipe_paths_for_csv($editor_dir, $csv_name);
    $json = json_encode($recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $csv = operational_steps_recipe_to_csv($recipe);
    $written = [];
    $errors = [];
    $targets = [
        'recipe' => $paths['recipe'],
        'csv' => $paths['csv'],
    ];
    foreach ($targets as $label => $path) {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $errors[] = "mkdir failed: {$dir}";
            continue;
        }
        $body = strpos($label, 'recipe') !== false ? $json : $csv;
        if (@file_put_contents($path, $body) === false) {
            $errors[] = "write failed: {$path}";
            continue;
        }
        $written[$label] = $path;
    }
    operational_steps_set_active_csv($editor_dir, basename($paths['csv']));
    return ['written' => $written, 'errors' => $errors, 'compiled' => operational_steps_compile_recipe($recipe), 'csv_file' => basename($paths['csv'])];
}

function operational_steps_dispatch_step($dbc, array $step, array $config = [])
{
    $catalog = operational_steps_catalog_by_id();
    $fid = $step['function'] ?? '';
    if ($fid === '' || !isset($catalog[$fid])) {
        return ['skipped' => true, 'reason' => 'unknown function'];
    }
    $def = $catalog[$fid];
    if (empty($def['runnable'])) {
        return ['skipped' => true, 'reason' => 'not runnable'];
    }
    $params = is_array($step['params'] ?? null) ? $step['params'] : [];
    $dispatch = $def['dispatch'] ?? $fid;
    $fractions = warm_start_default_fractions($config);
    $result = ['function' => $fid, 'dispatch' => $dispatch];

    switch ($dispatch) {
        case 'staging_job':
            $job = trim($params['job'] ?? $def['dispatch_job'] ?? 'STG-SCULLY');
            if ($job === '') {
                $job = 'STG-SCULLY';
            }
            $stats = warm_start_complete_staging_jobs($dbc, [$job], $config, 1.0);
            $result['stats'] = $stats;
            $result['job'] = $job;
            break;
        case 'generate_orders':
            $shipment = trim($params['shipment'] ?? '');
            $gen_params = operational_steps_normalize_generate_orders_params($params);
            if ($shipment !== '') {
                require_once __DIR__ . '/session_helpers.php';
                $result = array_merge($result, session_manual_generate_shipment($dbc, $shipment));
            } else {
                $session = warm_start_get_session($dbc);
                if ($gen_params['increment_session'] === '1') {
                    $session = warm_start_set_session($dbc, $session + 1);
                    $result['session'] = $session;
                }
                $unfilled = warm_start_count_unfilled($dbc);
                $result['unfilled_before'] = $unfilled;
                if ($gen_params['max_unfilled'] !== '' && $unfilled > (int) $gen_params['max_unfilled']) {
                    $result['generated'] = 0;
                    $result['skipped'] = true;
                    $result['reason'] = 'Unfilled count ' . $unfilled . ' exceeds max ' . $gen_params['max_unfilled'];
                } else {
                    $counter = warm_start_get_next_auto_waybill_counter($dbc, $session);
                    $result['generated'] = warm_start_generate_orders($dbc, $session, $counter);
                }
            }
            break;
        case 'increment_session':
            $prev = warm_start_get_session($dbc);
            $result['session'] = warm_start_set_session($dbc, $prev + 1);
            break;
        case 'fill_orders':
            $frac = operational_steps_percent_to_fraction(
                operational_steps_normalize_percent($params, 100)
            );
            require_once __DIR__ . '/fill_order_helpers.php';
            $result['filled'] = warm_start_auto_fill($dbc, $frac, [
                'order_filters' => fill_order_parse_filters(
                    operational_steps_normalize_fill_order_filters($params)
                ),
                'car_filters' => operational_steps_fill_car_filters_runtime(
                    operational_steps_normalize_fill_car_filters($params)
                ),
            ]);
            break;
        case 'reposition_empties':
            $frac = operational_steps_percent_to_fraction(
                operational_steps_normalize_percent($params, 65)
            );
            $mode = trim((string) ($params['mode'] ?? 'reposition_to_home'));
            if ($mode === '') {
                $mode = 'reposition_to_home';
            }
            $destination = trim((string) ($params['destination'] ?? ''));
            $result['repositioned'] = warm_start_create_reposition_orders($dbc, $frac, [
                'mode' => $mode,
                'destination' => $destination,
                'filters' => operational_steps_normalize_reposition_filters($params),
            ]);
            break;
        case 'auto_assign_locals':
            $job_names = operational_steps_resolve_auto_assign_jobs($dbc, $params, $config);
            $result['jobs'] = $job_names;
            $result['assigned'] = warm_start_auto_assign_jobs($dbc, $job_names);
            break;
        case 'build_switchlists_sts':
            $job = trim($params['job'] ?? '');
            $station_key = trim($params['station'] ?? '');
            if ($job !== '' && $station_key !== '' && $station_key !== 'all') {
                $station = operational_steps_location_station_id($dbc, $station_key);
                if ($station > 0) {
                    $result['assigned'] = warm_start_assign_all_ordered_cars_at_station($dbc, $job, $station);
                }
            }
            break;
        case 'pick_up_cars':
            $job = trim($params['job'] ?? '');
            if ($job === '') {
                $staging = warm_start_staging_job_names($dbc, $config);
                $result['picked_up'] = warm_start_pickup_cars($dbc, 1.0, $staging, true);
            } else {
                $station = operational_steps_location_station_id($dbc, $params['location'] ?? '');
                if ($station > 0) {
                    $result['picked_up'] = warm_start_pickup_job_at_station($dbc, $job, $station);
                } else {
                    $result['picked_up'] = warm_start_pickup_job($dbc, $job);
                }
            }
            break;
        case 'set_out_cars':
            $job = trim($params['job'] ?? '');
            $loc = trim($params['location'] ?? '');
            if ($job === '' && $loc === '') {
                $staging = warm_start_staging_job_names($dbc, $config);
                $result['set_out'] = warm_start_setout_cars($dbc, 1.0, $staging, true);
            } elseif ($loc === 'remainder') {
                $result['set_out'] = warm_start_setout_all_job_train($dbc, $job);
            } elseif ($loc === 'Demmler/Scully') {
                $result['set_out'] = warm_start_setout_job_cars_for_destinations($dbc, $job, [10, 14])
                    + warm_start_setout_job_cars_for_destinations($dbc, $job, [9, 15]);
            } elseif ($loc === 'Island/Shenango') {
                $result['set_out'] = warm_start_setout_job_cars_for_destinations($dbc, $job, [3, 12]);
            } elseif ($loc !== '') {
                $loc_id = operational_steps_resolve_location_id($dbc, $loc);
                if ($loc_id > 0) {
                    $result['set_out'] = warm_start_setout_job_at_location($dbc, $job, $loc_id);
                }
            }
            break;
        case 'track_scale':
            $job = strtoupper(trim($params['job'] ?? ''));
            if ($job === '' || $job === 'CK1') {
                $result['weigh'] = warm_start_run_ck1_scale_ops($dbc);
            } else {
                $result['skipped'] = true;
                $result['reason'] = 'No track-scale handler for job ' . $job;
            }
            break;
        case 'load_unload':
            $filters = operational_steps_normalize_load_unload_filters($params);
            $result['load_unload'] = warm_start_load_unload($dbc, 1.0, array_filter($filters));
            $result['filters'] = array_filter($filters);
            break;
        case 'weigh_ck1':
            $result['weigh'] = warm_start_run_ck1_scale_ops($dbc);
            break;
        case 'assign_ck1_reload':
            $result['assigned'] = warm_start_ck1_assign_reload_cars_on_train($dbc)
                + warm_start_ck1_assign_reload_at_south($dbc)
                + warm_start_ck1_assign_outbound_at_south($dbc);
            break;
        case 'run_job_criterion':
            $job = $params['job'] ?? 'D749';
            $steps = array_map('intval', array_filter(array_map('trim', explode(',', $params['steps'] ?? ''))));
            $moves = 0;
            foreach ($steps as $step_nbr) {
                $move = warm_start_run_job_criterion($dbc, $job, $step_nbr);
                $moves += (int) ($move['set_out'] ?? 0) + (int) ($move['picked_up'] ?? 0);
            }
            $result['moves'] = $moves;
            break;
        case 'composite_nvl_pre_ck1':
            $result['stats'] = warm_start_run_nvl_pre_ck1($dbc);
            break;
        case 'composite_ck1_session':
            $result['stats'] = warm_start_run_ck1_session_ops($dbc, $config);
            break;
        case 'composite_nvl_post_ck1':
            $result['stats'] = warm_start_run_nvl_post_ck1($dbc);
            break;
        case 'composite_d749_session_start':
            $result['stats'] = warm_start_run_d749_session_start($dbc);
            break;
        case 'composite_d749_phased':
            $result['stats'] = warm_start_run_d749_phased_ops($dbc);
            break;
        case 'finish_local_jobs':
            warm_start_finish_non_staging_jobs($dbc, $config, $fractions);
            $result['finished'] = true;
            break;
        case 'secure_d749_demmler':
            warm_start_assign_all_ordered_cars_at_station($dbc, 'D749', 10);
            $result['picked_up'] = warm_start_pickup_job_at_station($dbc, 'D749', 10);
            break;
        case 'secure_nvl_scully':
            warm_start_assign_eligible_at_pickup_station($dbc, 'NVL', 9);
            warm_start_pickup_job_at_station($dbc, 'NVL', 9);
            $scl = warm_start_location_id_by_code($dbc, 'SCL');
            $result['set_out'] = $scl > 0 ? warm_start_setout_job_at_location($dbc, 'NVL', $scl) : 0;
            break;
        case 'generate_switchlists':
            require_once __DIR__ . '/session_helpers.php';
            require_once __DIR__ . '/master_switchlist_helpers.php';
            $format = $params['format'] ?? 'phased';
            $jobs = session_resolve_jobs_param($params['jobs'] ?? 'all');
            $session = master_sw_get_setting($dbc, 'session_nbr');
            $root = $config['session_root'] ?? session_web_root();
            $manifest = session_load_manifest($session, $root);
            $phase_num = (int) ($config['phase'] ?? 0);
            if ($phase_num < 1) {
                $phase_num = count($manifest['phases'] ?? []) + 1;
            }
            $phase_dir = session_phase_output_dir($session, $phase_num, $root);
            $result['switchlists'] = master_sw_generate_for_jobs($dbc, $jobs, $phase_dir, $config, [
                'format' => $format,
            ]);
            session_register_phase($manifest, $phase_num, [
                'jobs' => $jobs,
                'format' => $format,
                'output' => $phase_dir,
            ]);
            session_save_manifest($session, $manifest, $root);
            $result['phase'] = $phase_num;
            $result['output'] = $phase_dir;
            break;
        case 'generate_waybills':
            require_once __DIR__ . '/session_helpers.php';
            $session = warm_start_get_session($dbc);
            $root = $config['session_root'] ?? session_web_root();
            $phase_num = (int) ($config['phase'] ?? 0);
            if ($phase_num < 1) {
                $manifest = session_load_manifest($session, $root);
                $phase_num = max(1, count($manifest['phases'] ?? []));
            }
            $result['waybills'] = session_generate_waybills_for_phase($dbc, $session, $phase_num, $root);
            break;
        case 'render_switchlists':
            require_once __DIR__ . '/session_helpers.php';
            require_once __DIR__ . '/master_switchlist_helpers.php';
            $format = $params['format'] ?? 'phased';
            $jobs = array_values(array_filter(array_map('trim', explode(',', $params['jobs'] ?? 'D749,NVL,CK1'))));
            $session = trim($params['session'] ?? '') !== ''
                ? trim($params['session'])
                : master_sw_get_setting($dbc, 'session_nbr');
            $out = session_web_root() . '/session_' . $session;
            $result['switchlists'] = master_sw_generate_for_jobs($dbc, $jobs, $out, $config, [
                'format' => $format,
                'render_only' => true,
                'session_override' => $session,
            ]);
            master_sw_render_switchlists_root_index(session_web_root(), $session);
            break;
        case 'save_switchlist_cache':
            require_once __DIR__ . '/master_switchlist_helpers.php';
            $jobs = array_values(array_filter(array_map('trim', explode(',', $params['jobs'] ?? 'D749,NVL,CK1'))));
            $session = master_sw_get_setting($dbc, 'session_nbr');
            $out = session_web_root() . '/session_' . $session;
            $result['switchlists'] = master_sw_generate_for_jobs($dbc, $jobs, $out, $config, [
                'save_cache_only' => true,
            ]);
            break;
        case 'rebuild_switchlists_index':
            require_once __DIR__ . '/session_helpers.php';
            require_once __DIR__ . '/master_switchlist_helpers.php';
            $session = master_sw_get_setting($dbc, 'session_nbr');
            $result['index'] = master_sw_render_switchlists_root_index(session_web_root(), $session);
            break;
        case 'backup_database':
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '', $params['backup'] ?? 'manual_backup');
            if ($name === '') {
                return ['skipped' => true, 'reason' => 'invalid backup name'];
            }
            $result['path'] = warm_start_backup($dbc, $name);
            break;
        case 'restore_database':
            $name = basename($params['backup'] ?? 'hart_seed');
            list($ok, $msg) = operational_steps_restore_backup($dbc, $name);
            $result['restored'] = $ok;
            $result['message'] = $msg;
            if (!$ok) {
                return ['error' => $msg, 'function' => $fid];
            }
            break;
        case 'warm_start_tracked':
            $overrides = warm_start_tracked_sim_overrides([
                'min_sessions' => (int) ($params['min_sessions'] ?? 3),
                'max_sessions' => (int) ($params['max_sessions'] ?? 12),
            ]);
            $result['summary'] = warm_start_run($dbc, warm_start_merge_config($overrides));
            break;
        case 'begin_operating_session':
            $result['begin'] = warm_start_begin_operating_session($dbc, [
                'run_stg_scully' => ($params['run_stg_scully'] ?? 'yes') !== 'no',
                'config' => $config,
            ]);
            break;
        case 'play_operating_session':
            $result['play'] = warm_start_play_operating_session($dbc, $config);
            break;
        case 'evaluate_session_prep':
            $result['evaluation'] = warm_start_evaluate_session_prep($dbc, $config);
            break;
        default:
            return ['skipped' => true, 'reason' => 'no handler'];
    }
    return $result;
}

function operational_steps_recipe_indices(array $recipe)
{
    $steps = $recipe['steps'] ?? [];
    $total = count($steps);
    $indices = [
        'total' => $total,
        'operating_start' => null,
        'generate_step' => null,
        'session_end' => null,
        'breakpoints' => [],
    ];
    foreach ($steps as $i => $step) {
        $n = $i + 1;
        $fid = $step['function'] ?? '';
        $instr = $step['instruction'] ?? '';
        if ($fid === 'section_label') {
            $label = $step['params']['label'] ?? $instr;
            if (stripos($label, 'Session end') !== false) {
                $indices['session_end'] = $n;
            }
            if (stripos($label, 'Each operating session') !== false) {
                $indices['operating_start'] = $n;
            }
        }
        if ($indices['operating_start'] === null) {
            $desc = $step['description'] ?? '';
            if (stripos($desc, 'Begin session') !== false || stripos($instr, 'Begin session') !== false) {
                $indices['operating_start'] = $n;
            } elseif ($fid === 'build_switchlists_sts'
                && stripos($instr, 'STG-SCULLY') !== false
                && $n >= 40) {
                $indices['operating_start'] = $n;
            }
        }
        if ($fid === 'generate_switchlists') {
            $indices['generate_step'] = $n;
            $indices['breakpoints'][] = [
                'step' => $n,
                'label' => 'Generate Switch Lists (capture)',
                'function' => $fid,
            ];
        } elseif ($fid !== 'section_label' && $fid !== 'text_instruction' && $fid !== 'marker' && $fid !== 'restore_database') {
            $compiled = operational_steps_compile_recipe(['steps' => [$step]]);
            $label = $compiled[0]['instruction'] ?? $fid;
            $indices['breakpoints'][] = [
                'step' => $n,
                'label' => $label,
                'function' => $fid,
            ];
        }
        if ($fid === 'goto' && $indices['session_end'] !== null && $n === $indices['session_end'] + 1) {
            $indices['session_loop_goto'] = $n;
        }
    }
    if ($indices['operating_start'] === null) {
        $indices['operating_start'] = 45;
    }
    if ($indices['generate_step'] === null) {
        $indices['generate_step'] = 54;
    }
    if ($indices['session_end'] === null) {
        $indices['session_end'] = $total;
    }
    return $indices;
}

function operational_steps_run_recipe_steps($dbc, array $recipe, $from_step, $to_step, array $config = [])
{
    $steps = $recipe['steps'] ?? [];
    $from = max(1, (int) $from_step);
    $to = min(count($steps), (int) $to_step);
    $log = [];
    for ($n = $from; $n <= $to; $n++) {
        $step = $steps[$n - 1] ?? null;
        if (!is_array($step)) {
            continue;
        }
        $fid = $step['function'] ?? '';
        if (in_array($fid, ['generate_switchlists', 'section_label', 'text_instruction', 'marker', 'stop', 'goto', 'if_then', 'generate_waybills'], true)) {
            continue;
        }
        $log[] = array_merge(
            ['step' => $n],
            operational_steps_dispatch_step($dbc, $step, $config)
        );
    }
    return $log;
}

function operational_steps_discover_switchlist_sessions($session_root = null)
{
    require_once __DIR__ . '/session_helpers.php';
    if ($session_root === null) {
        $session_root = session_web_root();
    }
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $dirs = master_sw_discover_session_dirs($session_root);
    $sessions = [];
    foreach ($dirs as $entry) {
        $sessions[] = (int) $entry['number'];
    }
    sort($sessions);
    return $sessions;
}

function operational_steps_run_switchlists_web($dbc, $format = 'phased', array $jobs = ['D749', 'NVL', 'CK1'], array $options = [])
{
    require_once __DIR__ . '/session_helpers.php';
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $config = warm_start_merge_config($options['config'] ?? []);
    $session = isset($options['session_override']) && $options['session_override'] !== ''
        ? (string) $options['session_override']
        : master_sw_get_setting($dbc, 'session_nbr');
    $root = session_web_root();
    $manifest = session_load_manifest($session, $root);
    $phase_num = count($manifest['phases'] ?? []) + 1;
    $out = session_phase_output_dir($session, $phase_num, $root);
    $gen_opts = [
        'format' => $format,
        'session_override' => $session,
    ];
    if (!empty($options['render_only'])) {
        $gen_opts['render_only'] = true;
    }
    $written = master_sw_generate_for_jobs($dbc, $jobs, $out, $config, $gen_opts);
    session_register_phase($manifest, $phase_num, ['jobs' => $jobs, 'format' => $format, 'output' => $out]);
    session_save_manifest($session, $manifest, $root);
    return [
        'session' => $session,
        'phase' => $phase_num,
        'written' => $written,
        'output' => $out,
    ];
}

function operational_steps_run_generator_web($dbc, array $options = [])
{
    require_once __DIR__ . '/session_helpers.php';
    $recipe = $options['recipe'] ?? ['steps' => []];
    $format = $options['format'] ?? 'phased';
    $jobs = $options['jobs'] ?? ['D749', 'NVL', 'CK1'];
    $mode = $options['mode'] ?? 'current';
    $breakpoint = (int) ($options['breakpoint_step'] ?? 0);
    $session_count = max(1, (int) ($options['session_count'] ?? 1));
    $run_prep = array_key_exists('run_prep', $options) ? (bool) $options['run_prep'] : true;
    $play_after = !array_key_exists('play_after', $options) || (bool) $options['play_after'];
    $render_sessions = $options['render_sessions'] ?? [];
    $config = warm_start_merge_config($options['config'] ?? []);
    $config['session_root'] = session_web_root();
    $indices = operational_steps_recipe_indices($recipe);
    $total_steps = count($recipe['steps'] ?? []);

    if ($breakpoint <= 0) {
        $breakpoint = (int) ($indices['session_end'] ?: $total_steps);
    }

    $start_step = (int) ($options['start_step'] ?? 0);
    $stop_step = (int) ($options['stop_step'] ?? 0);
    if ($stop_step <= 0) {
        $stop_step = $total_steps > 0 ? $total_steps : $breakpoint;
    }
    if ($start_step <= 0) {
        $start_step = 1;
    }
    $from_step = max(1, min($start_step, $total_steps));
    $to_step = max($from_step, min($stop_step, $total_steps));
    $breakpoint = $to_step;

    $cycles = [];
    $warnings = [];

    if ($mode === 'rerender' && count($render_sessions) > 0) {
        foreach ($render_sessions as $sess) {
            $sess = (int) $sess;
            if ($sess <= 0) {
                continue;
            }
            $manifest = session_load_manifest($sess, session_web_root());
            foreach ($manifest['phases'] ?? [] as $phase) {
                $phase_jobs = $phase['jobs'] ?? $jobs;
                $gen = operational_steps_run_switchlists_web($dbc, $phase['format'] ?? $format, $phase_jobs, [
                    'render_only' => true,
                    'session_override' => (string) $sess,
                    'config' => $config,
                ]);
                $cycles[] = [
                    'cycle' => count($cycles) + 1,
                    'session' => $gen['session'],
                    'mode' => 'rerender',
                    'phase' => $gen['phase'] ?? null,
                    'written' => $gen['written'],
                ];
            }
        }
        return [
            'mode' => 'rerender',
            'breakpoint_step' => $breakpoint,
            'cycles' => $cycles,
            'warnings' => $warnings,
            'sessions' => array_values(array_unique(array_column($cycles, 'session'))),
        ];
    }

    $op_start = (int) $indices['operating_start'];
    $session_end = (int) $indices['session_end'];
    $loops = max(1, $session_count);
    if ($mode === 'current' && $loops > 1) {
        $mode = 'simulate';
    }

    for ($cycle = 0; $cycle < $loops; $cycle++) {
        if ($mode === 'simulate' && $cycle > 0) {
            $warnings[] = 'Cycle ' . ($cycle + 1) . ': steps ' . $from_step . '–' . $to_step . '.';
        }

        $run_result = session_run_recipe($dbc, $recipe, [
            'from_step' => $from_step,
            'to_step' => $to_step,
            'format' => $format,
            'config' => $config,
        ]);

        $written = [];
        foreach ($run_result['log'] ?? [] as $entry) {
            if (!empty($entry['written']) && is_array($entry['written'])) {
                $written = array_merge($written, $entry['written']);
            }
        }

        $cycle_result = [
            'cycle' => $cycle + 1,
            'session' => $run_result['session'],
            'mode' => $mode,
            'breakpoint_step' => $breakpoint,
            'start_step' => $from_step,
            'stop_step' => $to_step,
            'prep_range' => [$from_step, $to_step],
            'phases' => $run_result['phases'] ?? 0,
            'written' => $written,
            'log' => $run_result['log'] ?? [],
            'stopped' => $run_result['stopped'] ?? false,
        ];

        if ($play_after && $cycle < $loops - 1) {
            $play_start = $to_step + 1;
            if ($play_start <= $session_end) {
                $cycle_result['play'] = operational_steps_run_recipe_steps(
                    $dbc,
                    $recipe,
                    $play_start,
                    $session_end,
                    $config
                );
            } else {
                $cycle_result['play'] = warm_start_play_operating_session($dbc, $config);
            }
        }

        $cycles[] = $cycle_result;
    }

    return [
        'mode' => $mode,
        'breakpoint_step' => $breakpoint,
        'start_step' => $from_step,
        'stop_step' => $to_step,
        'session_count' => $loops,
        'cycles' => $cycles,
        'warnings' => $warnings,
        'sessions' => array_column($cycles, 'session'),
        'indices' => $indices,
    ];
}
