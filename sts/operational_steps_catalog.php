<?php
/**
 * STS operational step catalog, recipe compile, and workflow JSON persistence.
 */

require_once __DIR__ . '/session_runtime.php';
session_runtime_bootstrap();
require_once __DIR__ . '/operations_train_car_filters.php';
require_once __DIR__ . '/plugins/plugins.php';

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
        'reports' => 'Reports',
        'database' => 'Database',
        'workflow' => 'Notes',
    ];
}

function operational_steps_catalog_adder_order()
{
    $base = [
        'before' => ['cancel_orders', 'generate_orders', 'fill_orders', 'reposition_empties'],
        'during' => [
            'auto_assign_locals', 'release_yard_cars', 'pick_up_cars', 'set_out_cars',
        ],
        'after' => ['load_unload'],
        'reports' => ['generate_switchlists', 'generate_waybills'],
        'database' => [
            'restore_database', 'backup_database', 'validate_database',
            'increment_session',
            'restart_session', 'reset_session',
            'import_data', 'remove_backup', 'wipe_database',
        ],
        'workflow' => ['section_label', 'text_instruction', 'if_then', 'stop'],
    ];
    if (defined('STS_CATALOG_CORE_ONLY') && STS_CATALOG_CORE_ONLY) {
        return $base;
    }

    return plugins_catalog_adder_order($base);
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
        'visible_label' => true,
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
        'visible_label' => true,
    ];
}

function operational_steps_catalog_commodity_param($required = false, $label = 'Commodity')
{
    return [
        'key' => 'commodity',
        'label' => $label,
        'type' => 'commodity',
        'options_from' => 'commodities',
        'allow_custom' => true,
        'required' => $required,
        'default' => '',
        'visible_label' => true,
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
        'visible_label' => true,
    ];
}

function operational_steps_catalog_station_param($required = false, $label = 'Station', $default = 'all')
{
    return [
        'key' => 'station',
        'label' => $label,
        'type' => 'station',
        'options_from' => 'stations',
        'allow_custom' => true,
        'required' => $required,
        'default' => $default,
        'visible_label' => true,
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
        'visible_label' => true,
    ];
}

function operational_steps_catalog_switchlist_format_options()
{
    return [
        ['value' => 'all', 'label' => 'All styles'],
        ['value' => 'mobile', 'label' => 'Mobile'],
        ['value' => 'half', 'label' => 'Half Sheet'],
        ['value' => 'full', 'label' => 'Full Sheet'],
        ['value' => 'dmp', 'label' => 'Dot Matrix'],
        ['value' => 'wo', 'label' => 'Work Order'],
        ['value' => 'x2010', 'label' => 'X2010'],
    ];
}

function operational_steps_catalog_switchlist_format_param()
{
    return [
        'key' => 'format',
        'label' => 'Style',
        'type' => 'select',
        'options' => operational_steps_catalog_switchlist_format_options(),
        'default' => 'all',
        'visible_label' => true,
    ];
}

function operational_steps_switchlist_format_values()
{
    return array_map(static function ($opt) {
        return $opt['value'];
    }, operational_steps_catalog_switchlist_format_options());
}

function operational_steps_normalize_switchlist_format($format, $default = 'all')
{
    $format = strtolower(trim((string) $format));
    $aliases = [
        'phased' => 'all',
        'phased-mobile' => 'mobile',
        'phased_mobile' => 'mobile',
        'halfsheet' => 'half',
        'master' => 'all',
    ];
    if (isset($aliases[$format])) {
        $format = $aliases[$format];
    }

    return in_array($format, operational_steps_switchlist_format_values(), true) ? $format : $default;
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

/** First backup file in sts/backups/ (for catalog defaults). */
function operational_steps_default_backup_name()
{
    $files = operational_steps_list_backup_files();

    return $files[0] ?? '';
}

/** Resolve backup filename; empty/missing values fall back to $default or the first listed backup. */
function operational_steps_resolve_backup_name($backup_name, $default = null)
{
    $name = basename(trim((string) $backup_name));
    if ($name === '' || $name === '.' || $name === '..') {
        if ($default === null) {
            $default = operational_steps_default_backup_name();
        }
        $name = basename(trim((string) $default));
    }
    if ($name === '' || $name === '.' || $name === '..') {
        return '';
    }

    return $name;
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

/** Session editor recipe storage (under sts-backups; hidden from restore_db.php). */
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
    return [operational_steps_editor_dir()];
}

/** Obsolete function ids from older saved recipes; migrated on import. */
function operational_steps_legacy_layout_function_ids()
{
    return [
        'weigh_ck1', 'assign_ck1_reload', 'run_stg_scully', 'run_stg_demmler',
        'run_staging_job', 'run_job_criterion', 'assign_cars', 'finish_local_jobs',
        'composite_nvl_pre_ck1', 'composite_ck1_session', 'composite_nvl_post_ck1',
        'composite_d749_session_start', 'composite_d749_phased',
        'secure_d749_demmler', 'secure_nvl_scully',
        'warm_start_tracked', 'run_until_ready',
        'begin_operating_session', 'begin_session',
        'play_operating_session', 'play_session',
        'evaluate_session_prep',
    ];
}

function operational_steps_migrate_legacy_function_id($fid, $instruction = '')
{
    $plugin_fid = plugins_migrate_legacy_function_id($fid);
    if ($plugin_fid !== null) {
        return $plugin_fid;
    }
    if ($fid === 'assign_ck1_reload') {
        return 'auto_assign_locals';
    }
    if (in_array($fid, operational_steps_legacy_layout_function_ids(), true)) {
        return 'text_instruction';
    }
    return $fid;
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
        'type' => 'checkbox_dropdown',
        'options_from' => 'jobs',
        'required' => false,
        'default' => '',
        'visible_label' => true,
        'empty_label' => 'Any',
        'summary_label' => 'jobs',
    ];
}

function operational_steps_staging_job_names($dbc, array $config = [])
{
    if (function_exists('warm_start_staging_job_names')) {
        return warm_start_staging_job_names($dbc, $config);
    }
    $jobs = [];
    $rs = mysqli_query($dbc, 'SELECT name FROM jobs WHERE name LIKE "STG-%" ORDER BY name');
    while ($rs && ($row = mysqli_fetch_array($rs))) {
        $name = (string) ($row['name'] ?? '');
        if ($name !== '') {
            $jobs[] = $name;
        }
    }
    return $jobs;
}

function operational_steps_is_staging_job($job_name, array $staging_jobs)
{
    if (function_exists('warm_start_is_staging_job')) {
        return warm_start_is_staging_job($job_name, $staging_jobs);
    }
    return in_array((string) $job_name, $staging_jobs, true);
}

function operational_steps_non_staging_job_names($dbc, array $config = [])
{
    $staging = operational_steps_staging_job_names($dbc, $config);
    $jobs = [];
    $rs = mysqli_query($dbc, 'SELECT name FROM jobs ORDER BY name');
    while ($row = mysqli_fetch_array($rs)) {
        $name = (string) ($row['name'] ?? '');
        if ($name !== '' && !operational_steps_is_staging_job($name, $staging)) {
            $jobs[] = $name;
        }
    }
    return $jobs;
}

function operational_steps_resolve_auto_assign_jobs($dbc, array $params, array $config = [])
{
    unset($dbc, $config);
    $jobs_text = trim((string) ($params['jobs'] ?? ''));
    if ($jobs_text === '' && !empty($params['scope']) && (string) $params['scope'] !== 'locals') {
        return [trim((string) $params['scope'])];
    }
    if ($jobs_text === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $jobs_text))));
}

function operational_steps_normalize_auto_assign_jobs(array $params)
{
    if (array_key_exists('jobs', $params)) {
        $jobs = array_values(array_filter(array_map('trim', explode(',', (string) ($params['jobs'] ?? '')))));
        return implode(',', $jobs);
    }
    if (!empty($params['scope']) && (string) $params['scope'] !== 'locals') {
        return trim((string) $params['scope']);
    }
    return '';
}

function operational_steps_normalize_destination_filter($destination)
{
    $destination = trim((string) $destination);
    if ($destination === '' || strcasecmp($destination, 'all') === 0 || strcasecmp($destination, 'any') === 0) {
        return '';
    }
    return $destination;
}

/**
 * Parse one or more destination filter tokens (comma-separated or array).
 * Tokens stay as station::Name / location::Name (same as Pickup final dest).
 *
 * @return list<string>
 */
function operational_steps_normalize_destination_filters($destination)
{
    if (is_array($destination)) {
        $parts = $destination;
    } else {
        $raw = trim((string) $destination);
        if ($raw === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
    }
    $out = [];
    $seen = [];
    foreach ($parts as $part) {
        $normalized = operational_steps_normalize_destination_filter($part);
        if ($normalized === '' || isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;
        $out[] = $normalized;
    }
    return $out;
}

/** Compact storage form: comma-joined tokens, or '' when unrestricted. */
function operational_steps_normalize_destination_filter_list($destination)
{
    return implode(',', operational_steps_normalize_destination_filters($destination));
}

/**
 * Generic comma-list normalizer (jobs, stations, locations).
 * Drops blanks and all/any sentinels.
 *
 * @return list<string>
 */
function operational_steps_normalize_csv_list($value)
{
    if (is_array($value)) {
        $parts = $value;
    } else {
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
    }
    $out = [];
    $seen = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '' || strcasecmp($part, 'all') === 0 || strcasecmp($part, 'any') === 0) {
            continue;
        }
        $key = strtolower($part);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $part;
    }
    return $out;
}

function operational_steps_normalize_csv_list_string($value)
{
    return implode(',', operational_steps_normalize_csv_list($value));
}

/** @return list<string> station names */
function operational_steps_normalize_station_filters($station)
{
    return operational_steps_normalize_csv_list($station);
}

function operational_steps_normalize_station_filter_list($station)
{
    return operational_steps_normalize_csv_list_string($station);
}

/** @return list<int> */
function operational_steps_resolve_station_ids($dbc, $station)
{
    $ids = [];
    $seen = [];
    foreach (operational_steps_normalize_station_filters($station) as $name) {
        $id = operational_steps_location_station_id($dbc, $name);
        if ($id > 0 && !isset($seen[$id])) {
            $seen[$id] = true;
            $ids[] = $id;
        }
    }
    return $ids;
}

function operational_steps_normalize_job_list($job)
{
    return operational_steps_normalize_csv_list($job);
}

function operational_steps_normalize_job_list_string($job)
{
    return operational_steps_normalize_csv_list_string($job);
}

function operational_steps_normalize_setout_locations($location)
{
    if (is_array($location)) {
        $parts = $location;
    } else {
        $raw = trim((string) $location);
        if ($raw === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
    }
    $out = [];
    $seen = [];
    foreach ($parts as $part) {
        $loc = operational_steps_normalize_setout_location($part);
        // Keep remnant "remainder" / Final Destination as a real token.
        if ($loc === '' && trim((string) $part) === '') {
            continue;
        }
        if ($loc === '' && strcasecmp(trim((string) $part), 'any') === 0) {
            continue;
        }
        if ($loc === '' && strcasecmp(trim((string) $part), 'auto') === 0) {
            continue;
        }
        // Empty after normalize only from any/auto; remainder stays "remainder".
        $token = $loc;
        if ($token === '' && strcasecmp(trim((string) $part), 'remainder') === 0) {
            $token = 'remainder';
        }
        if ($token === '') {
            continue;
        }
        if (isset($seen[strtolower($token)])) {
            continue;
        }
        $seen[strtolower($token)] = true;
        $out[] = $token;
    }
    return $out;
}

function operational_steps_normalize_setout_location_list($location)
{
    return implode(',', operational_steps_normalize_setout_locations($location));
}

function operational_steps_destination_filter_label($destination)
{
    $destinations = operational_steps_normalize_destination_filters($destination);
    if ($destinations === []) {
        return '';
    }
    $labels = [];
    foreach ($destinations as $destination) {
        if (strpos($destination, 'station::') === 0) {
            $labels[] = substr($destination, 9);
        } elseif (strpos($destination, 'location::') === 0) {
            $labels[] = substr($destination, 10);
        } else {
            $labels[] = $destination;
        }
    }
    return implode(', ', $labels);
}

function operational_steps_compile_auto_assign_gui(array $params)
{
    $jobs = operational_steps_normalize_auto_assign_jobs($params);
    $stations = operational_steps_normalize_station_filters($params['station'] ?? '');
    $destination = operational_steps_normalize_destination_filter_list($params['destination'] ?? '');
    if ($jobs === '') {
        $line = 'Assign Cars';
    } else {
        $line = 'Assign Cars ' . str_replace(',', ', ', $jobs);
    }
    if ($stations !== []) {
        $line .= ' at ' . implode(', ', $stations);
    }
    $dest_label = operational_steps_destination_filter_label($destination);
    if ($dest_label !== '') {
        $line .= ' → ' . $dest_label;
    }
    return $line;
}

/** Filter car ids to those currently at locations on one or more routing stations (OR). */
function operational_steps_filter_car_ids_at_station($dbc, array $car_ids, $station_id)
{
    if ($car_ids === []) {
        return $car_ids;
    }
    if (is_array($station_id)) {
        $station_ids = array_values(array_unique(array_filter(array_map('intval', $station_id))));
    } else {
        $station_ids = [(int) $station_id];
        $station_ids = array_values(array_filter($station_ids));
    }
    if ($station_ids === []) {
        return $car_ids;
    }
    $location_ids = [];
    $rs = mysqli_query(
        $dbc,
        'SELECT id FROM locations WHERE station IN (' . implode(',', $station_ids) . ')'
    );
    while ($rs && ($row = mysqli_fetch_array($rs))) {
        $location_ids[] = (int) $row['id'];
    }
    if ($location_ids === []) {
        return [];
    }
    $loc_sql = implode(',', $location_ids);
    $id_sql = implode(',', array_map('intval', $car_ids));
    $filtered = [];
    $car_rs = mysqli_query(
        $dbc,
        'SELECT id FROM cars WHERE id IN (' . $id_sql . ') AND current_location_id IN (' . $loc_sql . ')'
    );
    while ($car_rs && ($row = mysqli_fetch_array($car_rs))) {
        $filtered[] = (int) $row['id'];
    }
    return $filtered;
}

/** Filter car ids by final-destination station/location (same tokens as Pickup car filters). */
function operational_steps_filter_car_ids_by_destination($dbc, array $car_ids, $destination, $job_name = '')
{
    require_once __DIR__ . '/operations_train_car_filters.php';
    $destinations = operational_steps_normalize_destination_filters($destination);
    if ($destinations === [] || $car_ids === []) {
        return $car_ids;
    }
    $filtered = [];
    $seen = [];
    foreach ($car_ids as $car_id) {
        $car_id = (int) $car_id;
        if ($car_id <= 0 || isset($seen[$car_id])) {
            continue;
        }
        foreach ($destinations as $token) {
            if (operational_steps_train_car_passes_filters($dbc, $car_id, $job_name, [
                'final_destination' => $token,
            ])) {
                $seen[$car_id] = true;
                $filtered[] = $car_id;
                break;
            }
        }
    }
    return $filtered;
}

/** Auto-assign eligible cars to job(s), optional current-station and final-destination filters. */
function operational_steps_auto_assign_jobs($dbc, array $job_names, $station_id = 0, $destination = '')
{
    require_once __DIR__ . '/drop_down_list_functions.php';
    $assigned = 0;
    $destination = operational_steps_normalize_destination_filter_list($destination);
    if (is_array($station_id)) {
        $station_ids = array_values(array_unique(array_filter(array_map('intval', $station_id))));
    } else {
        $sid = (int) $station_id;
        $station_ids = $sid > 0 ? [$sid] : [];
    }
    foreach ($job_names as $job_name) {
        $job_name = trim((string) $job_name);
        if ($job_name === '') {
            continue;
        }
        $eligible = array_keys(auto_assign_eligible_car_ids_for_job($dbc, $job_name, true));
        if ($station_ids !== []) {
            $eligible = operational_steps_filter_car_ids_at_station($dbc, $eligible, $station_ids);
        }
        if ($destination !== '') {
            $eligible = operational_steps_filter_car_ids_by_destination($dbc, $eligible, $destination, $job_name);
        }
        if ($eligible === []) {
            continue;
        }
        if (function_exists('warm_start_assign_cars_to_job')) {
            $assigned += warm_start_assign_cars_to_job($dbc, $job_name, $eligible);
            continue;
        }
        $job_rs = mysqli_query(
            $dbc,
            'SELECT id FROM jobs WHERE name = "' . mysqli_real_escape_string($dbc, $job_name) . '" LIMIT 1'
        );
        $job_row = $job_rs ? mysqli_fetch_array($job_rs) : null;
        $job_id = (int) ($job_row['id'] ?? 0);
        if ($job_id <= 0) {
            continue;
        }
        foreach ($eligible as $car_id) {
            $car_id = (int) $car_id;
            if ($car_id <= 0) {
                continue;
            }
            if (mysqli_query(
                $dbc,
                'UPDATE cars SET handled_by_job_id = "' . $job_id . '"
                 WHERE id = "' . $car_id . '" AND handled_by_job_id = 0'
            ) && mysqli_affected_rows($dbc) > 0) {
                $assigned++;
            }
        }
    }
    return $assigned;
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
    require_once __DIR__ . '/fill_order_helpers.php';
    $merged['current_station'] = fill_order_normalize_scope_filter($merged['current_station'] ?? '');
    $merged['current_location'] = fill_order_normalize_scope_filter($merged['current_location'] ?? '');
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
        'current_station' => fill_order_normalize_scope_filter($storage['current_station'] ?? ''),
        'current_location' => fill_order_normalize_scope_filter($storage['current_location'] ?? ''),
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

function operational_steps_normalize_setout_location($location)
{
    $loc = trim((string) $location);
    if (strcasecmp($loc, 'any') === 0 || strcasecmp($loc, 'auto') === 0) {
        return '';
    }
    return $loc;
}

function operational_steps_setout_auto_assign_destinations($location)
{
    $loc = operational_steps_normalize_setout_location($location);
    return $loc === '' || $loc === 'remainder';
}

function operational_steps_normalize_generate_orders_params(array $params)
{
    $shipments = operational_steps_normalize_csv_list($params['shipment'] ?? '');
    $normalized = [
        'shipment' => implode(',', $shipments),
        'max_unfilled' => trim((string) ($params['max_unfilled'] ?? '')),
        'max_new' => trim((string) ($params['max_new'] ?? '')),
        'seed' => trim((string) ($params['seed'] ?? '')),
    ];
    if (array_key_exists('increment_session', $params)) {
        $increment = trim((string) $params['increment_session']);
        $normalized['increment_session'] = $increment === '1' ? '1' : '';
    }
    if ($normalized['max_unfilled'] !== '' && !ctype_digit($normalized['max_unfilled'])) {
        $normalized['max_unfilled'] = '';
    }
    if ($normalized['max_new'] !== '' && !ctype_digit($normalized['max_new'])) {
        $normalized['max_new'] = '';
    }
    if ($normalized['seed'] !== '' && !ctype_digit($normalized['seed'])) {
        $normalized['seed'] = '';
    }
    return $normalized;
}

function operational_steps_compile_generate_orders_gui(array $params)
{
    $params = operational_steps_normalize_generate_orders_params($params);
    $parts = [];
    if ($params['shipment'] !== '') {
        $parts[] = str_replace(',', ', ', $params['shipment']);
    }
    if ($params['increment_session'] === '1') {
        $parts[] = 'increment session';
    }
    if ($params['max_unfilled'] !== '') {
        $parts[] = 'max_unfilled=' . $params['max_unfilled'];
    }
    if ($params['max_new'] !== '') {
        $parts[] = 'max_new=' . $params['max_new'];
    }
    if ($params['seed'] !== '') {
        $parts[] = 'seed=' . $params['seed'];
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

    $location_aliases = [];
    foreach ($location_aliases as $alias) {
        $locations[] = ['id' => 0, 'code' => $alias, 'station' => '', 'label' => $alias];
    }

    $scopes = [['value' => 'locals', 'label' => 'All locals (non-staging)']];
    foreach ($jobs as $job) {
        $scopes[] = ['value' => $job['name'], 'label' => $job['name']];
    }

    $backups = operational_steps_list_backup_files();

    $setout_extras = [
        ['value' => 'remainder', 'label' => 'Final Destination'],
    ];
    // Station / station-location tokens (same as Pickup filters), plus legacy bare codes.
    $setout_locations = $setout_extras;
    foreach ($location_aliases as $alias) {
        $setout_locations[] = ['value' => $alias, 'label' => $alias];
    }
    require_once __DIR__ . '/operations_train_car_filters.php';
    foreach (operational_steps_build_station_location_options($locations) as $opt) {
        $setout_locations[] = $opt;
    }
    foreach ($locations as $loc) {
        $code = (string) ($loc['code'] ?? '');
        if ($code === '' || in_array($code, $location_aliases, true)) {
            continue;
        }
        // Keep bare location codes so older recipes (SOUTH-YARD) still resolve.
        $setout_locations[] = ['value' => $code, 'label' => $code . ' (code)'];
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

    return [
        'jobs' => $jobs,
        'locations' => $locations,
        'stations' => $stations,
        'station_locations' => operational_steps_build_station_location_options($locations),
        'scopes' => $scopes,
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

function operational_steps_catalog_definitions()
{
    $definitions = [
        [
            'id' => 'restore_database',
            'category' => 'database',
            'adder' => true,
            'adder_group' => 'database',
            'label' => 'Restore Database',
            'gui_template' => 'Restore Database {backup}',
            'description' => 'Restore STS from a backup file in sts/backups/.',
            'runnable' => true,
            'dispatch' => 'restore_database',
            'gui_path' => '/sts/restore_db.php',
            'params' => [
                operational_steps_catalog_backup_param(true, operational_steps_default_backup_name()),
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
            'adder' => false,
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
            'label' => 'If … then goto …',
            'gui_template' => 'If {variable} {operator} {value} then goto {section_label}',
            'description' => 'When the condition is true, skip forward to a later section. When false, continue to the next step. Variables match the Operations dashboard counts (session #, open/unfilled orders, unassigned, pickup/set-out pending, organize, scale, load/unload).',
            'runnable' => true,
            'dispatch' => 'if_then',
            'params' => [
                [
                    'key' => 'variable',
                    'label' => 'Variable',
                    'type' => 'select',
                    'options_from' => 'condition_variables',
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
                ['key' => 'section', 'label' => 'Section', 'type' => 'workflow_section', 'default' => '', 'required' => true],
                ['key' => 'section_label', 'label' => 'Section label', 'type' => 'text', 'default' => ''],
                ['key' => 'step', 'label' => 'Step #', 'type' => 'number', 'default' => ''],
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
            'id' => 'generate_orders',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'before',
            'label' => 'Generate Car Orders',
            'gui_template' => 'Generate Orders {shipment}',
            'description' => 'Auto-generate car orders for due shipments. Default matches generate.php AUTOMATIC (increment session + generate). Set Increment session=No to generate for the current session only. Or check one or more Shipments for manual orders (multi-select). Leave unchecked for AUTOMATIC generation. Max unfilled orders skips generation entirely when the unfilled backlog is above the limit (hard gate). Max new orders/session is a soft cap: it still generates every session but stops after that many new orders, serving due shipments in random order and leaving the rest due for later — this spreads demand smoothly and avoids the burst-then-starve pattern a hard gate causes. Optional Random seed (e.g. 42) reproduces the same due-shipment mix after each restore.',
            'runnable' => true,
            'dispatch' => 'generate_orders',
            'params' => [
                [
                    'key' => 'shipment',
                    'label' => 'Shipment (manual)',
                    'type' => 'checkbox_dropdown',
                    'options_from' => 'shipments',
                    'required' => false,
                    'default' => '',
                    'visible_label' => true,
                    'empty_label' => 'Automatic',
                    'summary_label' => 'shipments',
                ],
                [
                    'key' => 'increment_session',
                    'label' => 'Increment session',
                    'type' => 'select',
                    'options' => [
                        ['value' => '', 'label' => 'No'],
                        ['value' => '1', 'label' => 'Yes'],
                    ],
                    'default' => '1',
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
                [
                    'key' => 'max_new',
                    'label' => 'Max new orders/session',
                    'type' => 'number',
                    'default' => '',
                    'required' => false,
                    'min' => 0,
                    'step' => 1,
                    'visible_label' => true,
                ],
                [
                    'key' => 'seed',
                    'label' => 'Random seed',
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
            'category' => 'database',
            'adder' => true,
            'adder_group' => 'database',
            'label' => 'Increment Session Number',
            'gui_template' => 'Increment Session Number',
            'description' => 'Advance session number by 1. GUI: generate.php (Next Session).',
            'runnable' => true,
            'dispatch' => 'increment_session',
            'gui_path' => '/sts/generate.php',
            'params' => [],
        ],
        [
            'id' => 'cancel_orders',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'before',
            'label' => 'Cancel Orders',
            'gui_template' => 'Cancel Orders when unfilled > {threshold} down to {target} ({order})',
            'description' => 'When unfilled (non-E) orders exceed Threshold, cancel unfilled orders down to Target so the generate max_unfilled gate can reopen. Keeps COKE-* orders by default. Choose Oldest first (stale backlog) or Newest first (drop recent surge). Does not invent capacity — pairs with fill/reposition and fleet balance.',
            'runnable' => true,
            'dispatch' => 'cancel_orders',
            'params' => [
                ['key' => 'threshold', 'label' => 'Threshold', 'type' => 'number', 'default' => '40', 'required' => true, 'min' => 0],
                ['key' => 'target', 'label' => 'Target', 'type' => 'number', 'default' => '30', 'required' => true, 'min' => 0],
                [
                    'key' => 'order',
                    'label' => 'Cancel order',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'oldest_first', 'label' => 'Oldest first'],
                        ['value' => 'newest_first', 'label' => 'Newest first'],
                    ],
                    'default' => 'oldest_first',
                ],
                [
                    'key' => 'keep_coke',
                    'label' => 'Keep coke orders',
                    'type' => 'select',
                    'options' => [
                        ['value' => '1', 'label' => 'Yes'],
                        ['value' => '0', 'label' => 'No'],
                    ],
                    'default' => '1',
                ],
            ],
        ],
        [
            'id' => 'drain_unfilled_orders',
            'category' => 'operations',
            'adder' => false,
            'adder_group' => 'before',
            'label' => 'Cancel Orders (legacy)',
            'gui_template' => 'Cancel Orders when unfilled > {threshold} down to {target} ({order})',
            'description' => 'Legacy recipe id for Cancel Orders. Prefer cancel_orders in the editor.',
            'runnable' => true,
            'dispatch' => 'cancel_orders',
            'params' => [
                ['key' => 'threshold', 'label' => 'Threshold', 'type' => 'number', 'default' => '40', 'required' => true, 'min' => 0],
                ['key' => 'target', 'label' => 'Target', 'type' => 'number', 'default' => '30', 'required' => true, 'min' => 0],
                [
                    'key' => 'order',
                    'label' => 'Cancel order',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'oldest_first', 'label' => 'Oldest first'],
                        ['value' => 'newest_first', 'label' => 'Newest first'],
                    ],
                    'default' => 'oldest_first',
                ],
                [
                    'key' => 'keep_coke',
                    'label' => 'Keep coke orders',
                    'type' => 'select',
                    'options' => [
                        ['value' => '1', 'label' => 'Yes'],
                        ['value' => '0', 'label' => 'No'],
                    ],
                    'default' => '1',
                ],
            ],
        ],
        [
            'id' => 'fill_orders',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'before',
            'label' => 'Fill Car Orders',
            'gui_template' => 'Fill Orders',
            'description' => 'Auto-assign eligible cars to open orders (same as Fill Car Orders → Auto Assign). Uses car source checkboxes and optional order/car filters. Set Percent to limit how many orders are filled.',
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
            'label' => 'Assign Cars',
            'gui_template' => 'Assign Cars {jobs} {station} {destination}',
            'description' => 'Assign eligible cars to selected job(s)/train(s). Jobs and destinations use checkbox dropdowns (multi-select). Optional station filters limit to cars currently at those yards (OR); destination filters limit to cars bound for those stations/locations (OR — same tokens as Pickup final dest). Prefer multi-destination assigns + Pick Up all over many single-destination steps.',
            'runnable' => true,
            'dispatch' => 'auto_assign_locals',
            'gui_path' => '/sts/auto_assign.php',
            'params' => [
                operational_steps_catalog_auto_assign_jobs_param(),
                [
                    'key' => 'station',
                    'label' => 'Station filter',
                    'type' => 'checkbox_dropdown',
                    'options_from' => 'stations',
                    'required' => false,
                    'default' => '',
                    'visible_label' => true,
                    'suppress_all_station_option' => true,
                    'empty_label' => 'Any',
                    'summary_label' => 'stations',
                ],
                [
                    'key' => 'destination',
                    'label' => 'Destination filter',
                    'type' => 'checkbox_dropdown',
                    'options_from' => 'station_locations',
                    'required' => false,
                    'default' => '',
                    'visible_label' => true,
                    'empty_label' => 'Any',
                    'summary_label' => 'destinations',
                ],
            ],
        ],
        [
            'id' => 'release_yard_cars',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Release Yard Assignments',
            'gui_template' => 'Release Yard Assignments {job} {station}',
            'description' => 'Clear job assignment for cars still sitting in a yard (not on the train). Use after a selective pick-up so the leave-yard switch list only shows lifted cars.',
            'runnable' => true,
            'dispatch' => 'release_yard_cars',
            'params' => [
                operational_steps_catalog_job_param(true),
                array_merge(operational_steps_catalog_station_param(true, 'Yard station', ''), [
                    'suppress_all_station_option' => true,
                ]),
            ],
        ],
        [
            'id' => 'pick_up_cars',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Pick Up Cars',
            'gui_template' => 'Pick Up Cars {job} {location_suffix}',
            'description' => 'Pick up assigned cars onto a job train. Job and location use checkbox dropdowns (multi-select, OR). Optional car filters match the Pick Up Cars page. Leave jobs unchecked for all locals (staging excluded).',
            'runnable' => true,
            'dispatch' => 'pick_up_cars',
            'params' => [
                [
                    'key' => 'job',
                    'label' => 'Job',
                    'type' => 'checkbox_dropdown',
                    'options_from' => 'jobs',
                    'required' => false,
                    'default' => '',
                    'visible_label' => true,
                    'empty_label' => 'Locals (all)',
                    'summary_label' => 'jobs',
                ],
                [
                    'key' => 'location',
                    'label' => 'Location (optional)',
                    'type' => 'checkbox_dropdown',
                    'options_from' => 'locations',
                    'required' => false,
                    'default' => '',
                    'visible_label' => true,
                    'empty_label' => 'Any',
                    'summary_label' => 'locations',
                ],
                [
                    'key' => 'car_filters',
                    'label' => 'Car filters',
                    'type' => 'filter_group',
                    'layout' => 'train_car',
                    'fields' => operational_steps_train_car_filter_fields(),
                ],
            ],
        ],
        [
            'id' => 'set_out_cars',
            'category' => 'operations',
            'adder' => true,
            'adder_group' => 'during',
            'label' => 'Set Out Cars',
            'gui_template' => 'Set Out Cars {job} {location}',
            'description' => 'Set out cars from a job train. Job and set-out location use checkbox dropdowns (multi-select, OR). Choose Final Destination to spot each car at its loading or unloading location, or pick station/location codes. Optional car filters (including multi Final dest.) match the Set Out Cars page. Leave jobs and locations unchecked for all locals.',
            'runnable' => true,
            'dispatch' => 'set_out_cars',
            'params' => [
                [
                    'key' => 'job',
                    'label' => 'Job',
                    'type' => 'checkbox_dropdown',
                    'options_from' => 'jobs',
                    'required' => false,
                    'default' => '',
                    'visible_label' => true,
                    'empty_label' => 'Locals (all)',
                    'summary_label' => 'jobs',
                ],
                [
                    'key' => 'location',
                    'label' => 'Set out at',
                    'type' => 'checkbox_dropdown',
                    'options_from' => 'setout_locations',
                    'required' => false,
                    'default' => '',
                    'visible_label' => true,
                    'empty_label' => 'Any',
                    'summary_label' => 'locations',
                ],
                [
                    'key' => 'car_filters',
                    'label' => 'Car filters',
                    'type' => 'filter_group',
                    'layout' => 'train_car',
                    'fields' => operational_steps_train_car_filter_fields(),
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
            'id' => 'generate_switchlists',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'reports',
            'label' => 'Generate Switch Lists',
            'gui_template' => 'Generate Switch Lists {jobs} ({format}){title_suffix}',
            'description' => 'Write switch list HTML for the current simulator state for selected job(s) or all.',
            'runnable' => true,
            'dispatch' => 'generate_switchlists',
            'params' => [
                operational_steps_catalog_job_or_all_param('jobs', 'Job / train'),
                operational_steps_catalog_switchlist_format_param(),
                operational_steps_catalog_text_param(
                    'title',
                    'Train',
                    '',
                    false,
                    'Optional train name override (consolidates phases with the same value).'
                ),
                operational_steps_catalog_text_param(
                    'info',
                    'Info',
                    '',
                    false,
                    'Note beside the train name (e.g. Inbound, Outbound, Next Day).'
                ),
            ],
        ],
        [
            'id' => 'generate_waybills',
            'category' => 'reports',
            'adder' => true,
            'adder_group' => 'reports',
            'label' => 'Generate Waybill List',
            'gui_template' => 'Generate Waybill List',
            'description' => 'Render printable waybill HTML for open waybills in the current session.',
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
                operational_steps_catalog_switchlist_format_param(),
                operational_steps_catalog_job_or_all_param('jobs', 'Jobs'),
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
                operational_steps_catalog_job_or_all_param('jobs', 'Jobs'),
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
            'adder' => false,
            'adder_group' => 'during',
            'label' => 'Build Switch Lists (legacy)',
            'gui_template' => 'Build Switch Lists {station} {job}',
            'description' => 'Legacy — use Assign Cars instead. Assign ordered cars at a station to a job/train (by-station switch list build).',
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
            'id' => 'report_station_car',
            'category' => 'reports',
            'adder' => false,
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
            'adder' => false,
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
            'adder' => false,
            'adder_group' => 'reports',
            'disabled' => true,
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
            'adder' => false,
            'adder_group' => 'reports',
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
            'adder' => false,
            'adder_group' => 'reports',
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
            'adder' => false,
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
            'adder' => false,
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
            'adder' => false,
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
            'adder' => false,
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
            'adder' => false,
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
    if (defined('STS_CATALOG_CORE_ONLY') && STS_CATALOG_CORE_ONLY) {
        return $definitions;
    }

    return array_merge($definitions, plugins_catalog_definitions());
}

function operational_steps_restore_backup($dbc, $backup_name, $default = null)
{
    $name = operational_steps_resolve_backup_name($backup_name, $default);
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
            $ordered[] = $def;
        }
    }
    return $ordered;
}

function operational_steps_location_id_by_code($dbc, $code)
{
    $code = strtoupper(trim((string) $code));
    if ($code === '') {
        return 0;
    }
    if (function_exists('warm_start_location_id_by_code')) {
        return (int) warm_start_location_id_by_code($dbc, $code);
    }
    $rs = mysqli_query(
        $dbc,
        'SELECT id FROM locations WHERE code = "' . mysqli_real_escape_string($dbc, $code) . '" LIMIT 1'
    );
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return 0;
    }
    return (int) mysqli_fetch_array($rs)['id'];
}

function operational_steps_resolve_location_id($dbc, $location_key)
{
    $location_key = trim((string) $location_key);
    if ($location_key === '' || strcasecmp($location_key, 'remainder') === 0) {
        return 0;
    }

    // station_location tokens from setout / pickup dropdowns
    if (strpos($location_key, 'location::') === 0) {
        $label = trim(substr($location_key, 10));
        $hyphen = strpos($label, ' - ');
        if ($hyphen !== false) {
            $location_key = trim(substr($label, $hyphen + 3));
        } else {
            $location_key = $label;
        }
    } elseif (strpos($location_key, 'station::') === 0) {
        $location_key = trim(substr($location_key, 9));
    }

    $code = strtoupper(str_replace([' ', '_'], '-', $location_key));
    $id = operational_steps_location_id_by_code($dbc, $code);
    if ($id > 0) {
        return $id;
    }

    $esc = mysqli_real_escape_string($dbc, $location_key);
    $normalized = strtolower(str_replace([' ', '_'], '-', $location_key));
    $rs = mysqli_query(
        $dbc,
        'SELECT locations.id
         FROM locations
         WHERE LOWER(code) = LOWER("' . $esc . '")
            OR LOWER(REPLACE(code, "_", "-")) = "' . mysqli_real_escape_string($dbc, $normalized) . '"
         LIMIT 1'
    );
    if ($rs && mysqli_num_rows($rs) > 0) {
        return (int) mysqli_fetch_array($rs)['id'];
    }

    // Station name (e.g. "South Yard" / station::South Yard) — first track at that station, stable by code.
    $rs = mysqli_query(
        $dbc,
        'SELECT locations.id
         FROM locations
         INNER JOIN routing ON routing.id = locations.station
         WHERE LOWER(routing.station) = LOWER("' . $esc . '")
            OR LOWER(REPLACE(routing.station, " ", "-")) = "' . mysqli_real_escape_string($dbc, $normalized) . '"
         ORDER BY locations.code
         LIMIT 1'
    );
    if ($rs && mysqli_num_rows($rs) > 0) {
        return (int) mysqli_fetch_array($rs)['id'];
    }

    return 0;
}

function operational_steps_location_station_id($dbc, $location_key)
{
    static $cache = [];
    $location_key = trim((string) $location_key);
    if ($location_key === '' || strcasecmp($location_key, 'remainder') === 0) {
        return 0;
    }
    if (strpos($location_key, 'station::') === 0) {
        $location_key = trim(substr($location_key, 9));
    } elseif (strpos($location_key, 'location::') === 0) {
        $label = trim(substr($location_key, 10));
        $hyphen = strpos($label, ' - ');
        $location_key = ($hyphen !== false) ? trim(substr($label, $hyphen + 3)) : $label;
    }
    $cache_key = strtolower($location_key);
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $esc = mysqli_real_escape_string($dbc, $location_key);
    $normalized = strtolower(str_replace([' ', '_'], '-', $location_key));
    $rs = mysqli_query(
        $dbc,
        'SELECT routing.id
         FROM routing
         WHERE LOWER(station) = LOWER("' . $esc . '")
            OR LOWER(REPLACE(station, " ", "-")) = "' . mysqli_real_escape_string($dbc, $normalized) . '"
         LIMIT 1'
    );
    if ($rs && mysqli_num_rows($rs) > 0) {
        return $cache[$cache_key] = (int) mysqli_fetch_array($rs)['id'];
    }

    $code = strtoupper(str_replace(' ', '-', $location_key));
    $rs = mysqli_query(
        $dbc,
        'SELECT routing.id
         FROM locations
         INNER JOIN routing ON routing.id = locations.station
         WHERE locations.code = "' . mysqli_real_escape_string($dbc, $code) . '"
         LIMIT 1'
    );
    if ($rs && mysqli_num_rows($rs) > 0) {
        return $cache[$cache_key] = (int) mysqli_fetch_array($rs)['id'];
    }

    $loc_id = operational_steps_location_id_by_code($dbc, $code);
    if ($loc_id <= 0) {
        return $cache[$cache_key] = 0;
    }
    $rs = mysqli_query(
        $dbc,
        'SELECT station FROM locations WHERE id = "' . (int) $loc_id . '" LIMIT 1'
    );
    if ($rs && mysqli_num_rows($rs) > 0) {
        return $cache[$cache_key] = (int) mysqli_fetch_array($rs)['station'];
    }
    return $cache[$cache_key] = 0;
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
            if ($fid === 'goto' || operational_steps_if_then_has_goto($steps[$j])) {
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
    $sections = operational_steps_workflow_sections($recipe);
    foreach ($sections as $sec) {
        if ($id !== '' && $sec['id'] === $id) {
            return $sec;
        }
        if ($start > 0 && (int) $sec['start'] === (int) $start) {
            return $sec;
        }
    }
    if ($label !== '') {
        $want = trim($label);
        // Prefer an exact label match before falling back to a substring match
        // so similar names (e.g. "Setup" vs "Setup Session") don't collide.
        foreach ($sections as $sec) {
            if ($sec['label'] === $want) {
                return $sec;
            }
        }
        foreach ($sections as $sec) {
            if (stripos($sec['label'], $want) !== false || stripos($want, $sec['label']) !== false) {
                return $sec;
            }
        }
    }
    return null;
}

function operational_steps_if_then_has_goto(array $step)
{
    if (($step['function'] ?? '') !== 'if_then') {
        return false;
    }
    $p = is_array($step['params'] ?? null) ? $step['params'] : [];
    return trim((string) ($p['section'] ?? '')) !== ''
        || trim((string) ($p['section_label'] ?? '')) !== ''
        || (int) ($p['step'] ?? 0) > 0;
}

function operational_steps_merge_if_then_goto_steps(array $recipe)
{
    $steps = $recipe['steps'] ?? [];
    if (!$steps) {
        return $recipe;
    }
    $merged = [];
    $count = count($steps);
    for ($i = 0; $i < $count; $i++) {
        $step = $steps[$i];
        if (!is_array($step)) {
            continue;
        }
        if (($step['function'] ?? '') === 'if_then'
            && $i + 1 < $count
            && is_array($steps[$i + 1])
            && ($steps[$i + 1]['function'] ?? '') === 'goto') {
            $goto = $steps[$i + 1];
            $params = is_array($step['params'] ?? null) ? $step['params'] : [];
            $gotoParams = is_array($goto['params'] ?? null) ? $goto['params'] : [];
            foreach (['section', 'section_label', 'step'] as $key) {
                if (($gotoParams[$key] ?? '') !== '' && ($params[$key] ?? '') === '') {
                    $params[$key] = $gotoParams[$key];
                }
            }
            $step['params'] = $params;
            $i++;
        }
        $merged[] = $step;
    }
    $recipe['steps'] = $merged;
    return $recipe;
}

function operational_steps_goto_resolve_step(array $recipe, array $params)
{
    // Section label is the stable identifier. The section id ("step-N") encodes
    // a position and goes stale when steps shift, so resolve by label first.
    $section_label = trim((string) ($params['section_label'] ?? ''));
    if ($section_label !== '') {
        $sec = operational_steps_find_workflow_section($recipe, '', 0, $section_label);
        if ($sec) {
            return (int) $sec['start'];
        }
    }
    $section = trim((string) ($params['section'] ?? ''));
    if ($section !== '') {
        $sec = operational_steps_find_workflow_section($recipe, $section);
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
        if (!is_array($step)) {
            continue;
        }
        $fid = $step['function'] ?? '';
        if ($fid !== 'goto' && $fid !== 'if_then') {
            continue;
        }
        $params = is_array($step['params'] ?? null) ? $step['params'] : [];
        if ($fid === 'if_then' && !operational_steps_if_then_has_goto($step)) {
            $steps[$i]['params'] = $params;
            continue;
        }
        // Resolve by label first (stable), then the position-encoded id, then
        // the raw step number, so the stored id/step follow the named section
        // when step numbers change.
        $sec = null;
        if (!empty($params['section_label'])) {
            $sec = operational_steps_find_workflow_section($recipe, '', 0, (string) $params['section_label']);
        }
        if (!$sec && !empty($params['section'])) {
            $sec = operational_steps_find_workflow_section($recipe, (string) $params['section']);
        }
        if (!$sec && !empty($params['step'])) {
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
        $var_key = (string) ($params['variable'] ?? 'session_nbr');
        $var = session_condition_variable_label($var_key);
        $line = trim('If ' . $var . ' ' . ($params['operator'] ?? '') . ' ' . ($params['value'] ?? ''));
        if (!empty($params['section_label'])) {
            $line .= ' then Goto ' . trim((string) $params['section_label']);
        } elseif (!empty($params['step'])) {
            $line .= ' then Goto step ' . trim((string) $params['step']);
        }
        return $line;
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
    if (($def['id'] ?? '') === 'set_out_cars') {
        $jobs = operational_steps_normalize_job_list($params['job'] ?? '');
        $locs = operational_steps_normalize_setout_locations($params['location'] ?? '');
        if ($jobs !== [] && $locs === []) {
            return 'Set Out Cars ' . implode(', ', $jobs) . ' Final Destination';
        }
        if ($jobs !== [] && $locs === ['remainder']) {
            return 'Set Out Cars ' . implode(', ', $jobs) . ' Final Destination';
        }
    }
    $template = $def['gui_template'] ?? $def['label'];
    $merged = $params;
    if (($def['id'] ?? '') === 'pick_up_cars' && empty($merged['location'])) {
        $merged['location_suffix'] = '';
    } else {
        $merged['location_suffix'] = !empty($merged['location']) ? $merged['location'] : '';
    }
    plugins_apply_gui_label_merge($def, $params, $merged);
    if (($def['id'] ?? '') === 'generate_switchlists') {
        $jobs = trim((string) ($params['jobs'] ?? 'all'));
        if ($jobs === '') {
            $jobs = 'all';
        }
        $merged['jobs'] = $jobs;
        $merged['format'] = operational_steps_normalize_switchlist_format($params['format'] ?? 'all');
        $title = trim((string) ($params['title'] ?? ''));
        $info = trim((string) ($params['info'] ?? ''));
        $suffix = $title !== '' ? ' — ' . $title : '';
        if ($info !== '') {
            $suffix .= ' · ' . $info;
        }
        $merged['title_suffix'] = $suffix;
    }
    if (($def['id'] ?? '') === 'auto_assign_locals') {
        $merged['jobs'] = operational_steps_normalize_auto_assign_jobs($params);
        $stations = operational_steps_normalize_station_filters($params['station'] ?? '');
        if ($stations !== []) {
            $merged['station'] = implode(', ', $stations);
        }
        $dest_label = operational_steps_destination_filter_label($params['destination'] ?? '');
        if ($dest_label !== '') {
            $merged['destination'] = '→ ' . $dest_label;
        }
    }
    if (($def['id'] ?? '') === 'pick_up_cars') {
        $jobs = operational_steps_normalize_job_list($params['job'] ?? '');
        $locs = operational_steps_normalize_csv_list($params['location'] ?? '');
        $merged['job'] = $jobs !== [] ? implode(', ', $jobs) : '';
        $merged['location'] = $locs !== [] ? implode(', ', $locs) : '';
        $merged['location_suffix'] = $merged['location'];
    }
    if (($def['id'] ?? '') === 'set_out_cars') {
        $jobs = operational_steps_normalize_job_list($params['job'] ?? '');
        $locs = operational_steps_normalize_setout_locations($params['location'] ?? '');
        $merged['job'] = $jobs !== [] ? implode(', ', $jobs) : '';
        $loc_labels = [];
        foreach ($locs as $loc) {
            $loc_labels[] = ($loc === 'remainder') ? 'Final Destination' : $loc;
        }
        $merged['location'] = $loc_labels !== [] ? implode(', ', $loc_labels) : '';
    }
    return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($merged) {
        $key = $m[1];
        if (!isset($merged[$key]) || $merged[$key] === '') {
            return '';
        }
        return trim((string) $merged[$key]);
    }, $template);
}

function operational_steps_step_catalog_description(array $def, array $step)
{
    $stored = trim((string) ($step['catalog_description'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }
    return trim((string) ($def['description'] ?? ''));
}

function operational_steps_legacy_catalog_descriptions()
{
    return [
        'set_out_cars' => [
            'Set out cars from a job train at a location or destination. Leave job and location blank for all locals.',
        ],
    ];
}

function operational_steps_is_stale_catalog_description($fid, $text)
{
    $text = trim((string) $text);
    if ($text === '') {
        return false;
    }
    if (operational_steps_function_id_from_catalog_description($text) === $fid) {
        return true;
    }
    foreach (operational_steps_legacy_catalog_descriptions()[$fid] ?? [] as $legacy) {
        if ($text === $legacy) {
            return true;
        }
    }
    return false;
}

function operational_steps_migrate_step_descriptions(array $step, array $def, array $params)
{
    $catalog_description = trim((string) ($step['catalog_description'] ?? ''));
    $user_description = trim((string) ($step['description'] ?? ''));
    if ($catalog_description !== '' || $user_description === '') {
        return [$catalog_description, $user_description];
    }
    $base = trim((string) ($def['description'] ?? ''));
    $fid = (string) ($def['id'] ?? '');
    if ($base === '') {
        return ['', $user_description];
    }
    if ($user_description === $base) {
        return [$base, ''];
    }
    if ($fid !== '' && operational_steps_is_stale_catalog_description($fid, $user_description)) {
        return [$base, ''];
    }
    if (strpos($user_description, $base) === 0) {
        $rest = trim(substr($user_description, strlen($base)));
        if ($rest === '' || preg_match('/^Params:\s/i', $rest)) {
            return [$base, ''];
        }
    }
    return ['', $user_description];
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
            'description' => trim((string) ($step['description'] ?? '')),
            'catalog_description' => operational_steps_step_catalog_description($def, $step),
            'params' => $params,
        ];
    }
    return $rows;
}

function operational_steps_csv_header_labels()
{
    return ['Step #', 'Function', 'Params', 'STS GUI Instruction', 'Full Description', 'Remarks'];
}

function operational_steps_csv_header_line()
{
    return implode(',', operational_steps_csv_header_labels());
}

/** Map normalized header cell text → column key. */
function operational_steps_csv_header_key($label)
{
    $label = strtolower(trim(preg_replace('/\s+/', ' ', (string) $label)));
    static $aliases = [
        'step' => ['step #', 'step', 'step number', 'n'],
        'function' => ['function', 'command', 'cmd'],
        'params' => ['params', 'parameters', 'args'],
        'instruction' => ['sts gui instruction', 'instruction', 'gui instruction'],
        'catalog_description' => ['full description', 'catalog description', 'description'],
        'description' => ['remarks', 'user remarks', 'notes', 'note'],
    ];
    foreach ($aliases as $key => $names) {
        if (in_array($label, $names, true)) {
            return $key;
        }
    }
    return null;
}

function operational_steps_csv_column_indexes(array $header_row)
{
    $indexes = [];
    foreach ($header_row as $i => $cell) {
        $key = operational_steps_csv_header_key($cell);
        if ($key !== null && !isset($indexes[$key])) {
            $indexes[$key] = (int) $i;
        }
    }
    return $indexes;
}

function operational_steps_csv_cell(array $cols, array $indexes, $key)
{
    if (!isset($indexes[$key])) {
        return '';
    }
    return trim((string) ($cols[$indexes[$key]] ?? ''));
}

function operational_steps_encode_csv_params(array $params)
{
    if ($params === []) {
        return '';
    }
    return json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function operational_steps_decode_csv_params($text)
{
    $text = trim((string) $text);
    if ($text === '' || $text === '{}') {
        return [];
    }
    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : [];
}

function operational_steps_parse_csv_row(array $cols, array $indexes)
{
    $instruction = operational_steps_csv_cell($cols, $indexes, 'instruction');
    $catalog_description = operational_steps_csv_cell($cols, $indexes, 'catalog_description');
    $user_description = operational_steps_csv_cell($cols, $indexes, 'description');
    $fid = operational_steps_csv_cell($cols, $indexes, 'function');
    $params_json = operational_steps_csv_cell($cols, $indexes, 'params');

    if ($fid !== '') {
        return [
            'function' => $fid,
            'params' => operational_steps_decode_csv_params($params_json),
            'catalog_description' => $catalog_description,
            'description' => $user_description,
            'structured_import' => true,
        ];
    }

    // Legacy rows: infer type and params from instruction text.
    if ($instruction === '' && $params_json !== '') {
        $instruction = $params_json;
    }
    return [
        'function' => operational_steps_guess_function($instruction),
        'params' => operational_steps_guess_params($instruction),
        'instruction' => $instruction,
        'catalog_description' => $catalog_description,
        'description' => $user_description,
    ];
}

function operational_steps_recipe_to_csv(array $recipe)
{
    $catalog = operational_steps_catalog_by_id();
    $compiled = operational_steps_compile_recipe($recipe);
    $lines = [operational_steps_csv_header_line()];
    $n = 0;
    foreach ($compiled as $i => $row) {
        $n++;
        $fid = $row['function'] ?? '';
        $def = $catalog[$fid] ?? [];
        $step = $recipe['steps'][$i] ?? [];
        $params = is_array($step['params'] ?? null) ? $step['params'] : [];
        $catalog_desc = operational_steps_step_catalog_description($def, is_array($step) ? $step : []);
        if ($catalog_desc === '' && !empty($row['catalog_description'])) {
            $catalog_desc = (string) $row['catalog_description'];
        }
        $user_desc = trim((string) ($row['description'] ?? ''));
        $lines[] = operational_steps_csv_escape((string) $n)
            . ',' . operational_steps_csv_escape($fid)
            . ',' . operational_steps_csv_escape(operational_steps_encode_csv_params($params))
            . ',' . operational_steps_csv_escape($row['instruction'])
            . ',' . operational_steps_csv_escape($catalog_desc)
            . ',' . operational_steps_csv_escape($user_desc);
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
    $indexes = operational_steps_csv_column_indexes($parsed[0]);
    // Legacy 4-column files without a header map: Step #, Instruction, Full Description, Remarks.
    if (!isset($indexes['instruction']) && count($parsed[0]) >= 2) {
        $indexes = [
            'step' => 0,
            'instruction' => 1,
            'catalog_description' => 2,
            'description' => 3,
        ];
    }
    $rows = [];
    for ($r = 1; $r < count($parsed); $r++) {
        $rows[] = operational_steps_parse_csv_row($parsed[$r], $indexes);
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
    if (stripos($s, 'Generate Orders') !== false) {
        return 'generate_orders';
    }
    if (preg_match('/^Increment Session Number\s*$/i', $s)) {
        return 'increment_session';
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
    $plugin_guess = plugins_guess_function_id_from_text($s);
    if ($plugin_guess !== null) {
        return $plugin_guess;
    }
    if (stripos($s, 'reload/outbound') !== false) {
        return 'auto_assign_locals';
    }
    if (preg_match('/^Run (\S+)/i', $s)) {
        return 'text_instruction';
    }
    if (stripos($s, 'Finish open local') !== false) {
        return 'text_instruction';
    }
    if (preg_match('/^Build Switch Lists/i', $s)) {
        return 'build_switchlists_sts';
    }
    if (preg_match('/Assign Cars/i', $s)) {
        return 'auto_assign_locals';
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
        return 'text_instruction';
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
        $rest = trim($m[1] ?? '');
        if ($rest !== '') {
            if (preg_match('/\((all|mobile|half|halfsheet|full|dmp|wo|x2010|phased-mobile|phased)\)\s*$/i', $rest, $fm)) {
                $params['format'] = strtolower(str_replace('_', '-', $fm[1]));
                $rest = trim(preg_replace('/\s*\([^)]+\)\s*$/', '', $rest));
            }
            if ($rest !== '') {
                $params['jobs'] = $rest;
            }
        }
    }
    if (preg_match('/^Run (\S+(?:-\S+)?)/i', $s, $m)) {
        $params['job'] = $m[1];
    }
    if (preg_match('/Assign Cars (\S+(?:-\S+)?)\s+reload\/outbound/i', $s, $m)) {
        $params['job'] = trim($m[1]);
        $params['mode'] = 'reload_outbound';
    } elseif (preg_match('/Assign Cars (\S+(?:-\S+)?)\s+(.+)$/i', $s, $m)) {
        $params['job'] = trim($m[1]);
        $rest = trim(preg_replace('/^\[.+?\]\s*/', '', $m[2]));
        if (stripos($rest, 'reload/outbound') === false) {
            $params['station'] = $rest;
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
    if (stripos($s, 'Generate Orders') !== false) {
        if (preg_match('/increment session/i', $s)) {
            $params['increment_session'] = '1';
        }
        if (preg_match('/max_unfilled=(\d+)/i', $s, $m)) {
            $params['max_unfilled'] = $m[1];
        }
        if (preg_match('/max_new=(\d+)/i', $s, $m)) {
            $params['max_new'] = $m[1];
        }
        if (preg_match('/seed=(\d+)/i', $s, $m)) {
            $params['seed'] = $m[1];
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
    if (stripos($s, 'locals') !== false
        && (stripos($s, 'Auto-Assign') !== false || preg_match('/\bAssign Cars\b/i', $s))) {
        $params['jobs'] = '';
    } elseif (preg_match('/^(?:Auto-Assign|Assign) Cars(?:\s+(.+))?$/i', $s, $m)) {
        $rest = trim($m[1] ?? '');
        if ($rest === '' || strtolower($rest) === 'locals') {
            $params['jobs'] = '';
        } elseif (preg_match('/^(.+?)\s+at\s+(.+)$/i', $rest, $sm)) {
            $params['jobs'] = preg_replace('/\s*,\s*/', ',', trim($sm[1]));
            $params['station'] = trim($sm[2]);
        } else {
            $params['jobs'] = preg_replace('/\s*,\s*/', ',', $rest);
        }
    }
    if (stripos($s, 'Weigh Cars') !== false && preg_match('/Weigh Cars (\S+)(?:\s+\(([A-Za-z0-9_-]+)\))?/i', $s, $m)) {
        $params['job'] = $m[1];
        if (!empty($m[2])) {
            $params['commodity'] = $m[2];
        }
    }
    return $params;
}

function operational_steps_function_id_from_catalog_description($catalog_description)
{
    static $by_description = null;
    if ($by_description === null) {
        $by_description = [];
        foreach (operational_steps_catalog_definitions() as $def) {
            $desc = trim((string) ($def['description'] ?? ''));
            if ($desc !== '') {
                $by_description[$desc] = $def['id'];
            }
        }
    }
    $desc = trim((string) $catalog_description);
    return $by_description[$desc] ?? null;
}

/**
 * CSV stores instruction text only; Full Description identifies the catalog command.
 * Prefer catalog_description over instruction guessing when they disagree.
 */
function operational_steps_apply_catalog_description_function_id($fid, $catalog_description)
{
    $implied = operational_steps_function_id_from_catalog_description($catalog_description);
    if ($implied === null) {
        return $fid;
    }
    if ($fid === '' || $fid === 'text_instruction' || $fid === 'marker' || $fid !== $implied) {
        return $implied;
    }
    return $fid;
}

function operational_steps_should_skip_guess_params($fid)
{
    return in_array($fid, ['section_label', 'text_instruction', 'stop'], true);
}

function operational_steps_normalize_step(array $step)
{
    $catalog = operational_steps_catalog_by_id();
    $fid = $step['function'] ?? '';
    $instruction = trim($step['instruction'] ?? '');
    $catalog_description = trim($step['catalog_description'] ?? '');
    $description = trim($step['description'] ?? '');
    $structured_import = !empty($step['structured_import']);
    $step_disabled = array_key_exists('enabled', $step)
        && filter_var($step['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false;

    // Editor / structured CSV rows keep catalog function + params. Re-guessing from
    // instruction text is only for legacy CSV rows (no Function column).
    if ($structured_import && $fid !== '' && isset($catalog[$fid])) {
        // use stored function and params as-is
    } else {
        if ($fid === 'section_label') {
            if ($instruction !== '' && trim((string) ($step['params']['label'] ?? '')) === '') {
                $step['params']['label'] = $instruction;
            }
        } elseif ($fid === '' || $fid === 'marker' || !isset($catalog[$fid])) {
            if ($instruction !== '') {
                $fid = operational_steps_guess_function($instruction);
            }
        }
        $fid = operational_steps_apply_catalog_description_function_id($fid, $catalog_description);
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
    }

    if ($fid === 'restore_database' && empty($step['params']['backup'])) {
        $default_backup = operational_steps_default_backup_name();
        if ($default_backup !== '') {
            $step['params']['backup'] = $default_backup;
        }
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
    if ($fid === 'defer_staging') {
        $fid = 'section_label';
        if (stripos($instruction, 'Session end') !== false) {
            $step['params']['label'] = '[Session end]';
        } elseif ($instruction !== '') {
            $step['params']['label'] = trim(preg_replace('/\s*Defer\b.*/i', '', $instruction));
        }
    }
    $migrated = operational_steps_migrate_legacy_function_id($fid, $instruction);
    if ($migrated !== $fid) {
        $fid = $migrated;
        if ($fid === 'text_instruction' && $instruction !== '') {
            $step['params']['instruction'] = $instruction;
        }
    }

    $params = is_array($step['params'] ?? null) ? $step['params'] : [];
    if ($fid === 'section_label') {
        if (trim((string) ($params['label'] ?? '')) === '') {
            if ($instruction !== '') {
                $params['label'] = $instruction;
            } elseif (!empty($params['note'])) {
                $params['label'] = $params['note'];
            }
        }
    }
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
    $guessed = (
        !$structured_import
        && $instruction !== ''
        && !operational_steps_should_skip_guess_params($fid)
    )
        ? operational_steps_guess_params($instruction)
        : [];
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
            if ($fid === 'fill_orders') {
                $params['car_filters'] = operational_steps_normalize_fill_car_filters(array_merge($params, ['car_filters' => $v]));
            } elseif (in_array($fid, ['pick_up_cars', 'set_out_cars'], true)) {
                $params['car_filters'] = operational_steps_normalize_train_car_filters(array_merge($params, ['car_filters' => $v]));
            }
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
    if ($fid === 'cancel_orders' || $fid === 'drain_unfilled_orders') {
        $threshold = max(0, (int) ($params['threshold'] ?? 40));
        $target = max(0, (int) ($params['target'] ?? 30));
        if ($target > $threshold) {
            $target = $threshold;
        }
        $params['threshold'] = (string) $threshold;
        $params['target'] = (string) $target;
        $keep = (string) ($params['keep_coke'] ?? '1');
        $params['keep_coke'] = ($keep === '' || $keep === '1') ? '1' : '0';
        $order = strtolower(trim((string) ($params['order'] ?? 'oldest_first')));
        $params['order'] = in_array($order, ['newest_first', 'newest', 'desc', 'new'], true)
            ? 'newest_first'
            : 'oldest_first';
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
        $station = operational_steps_normalize_station_filter_list($params['station'] ?? '');
        if ($station === '') {
            unset($params['station']);
        } else {
            $params['station'] = $station;
        }
        $destination = operational_steps_normalize_destination_filter_list($params['destination'] ?? '');
        if ($destination === '') {
            unset($params['destination']);
        } else {
            $params['destination'] = $destination;
        }
    }
    if ($fid === 'generate_switchlists') {
        $params['format'] = operational_steps_normalize_switchlist_format($params['format'] ?? 'all');
        $jobs = trim((string) ($params['jobs'] ?? 'all'));
        $params['jobs'] = $jobs !== '' ? $jobs : 'all';
        $params['title'] = trim((string) ($params['title'] ?? ''));
        $params['info'] = trim((string) ($params['info'] ?? ''));
    }
    plugins_normalize_step_params($fid, $params);
    if ($fid === 'set_out_cars') {
        $job = operational_steps_normalize_job_list_string($params['job'] ?? '');
        if ($job === '') {
            unset($params['job']);
        } else {
            $params['job'] = $job;
        }
        $loc = operational_steps_normalize_setout_location_list($params['location'] ?? '');
        if ($loc === '') {
            unset($params['location']);
        } else {
            $params['location'] = $loc;
        }
        $params['car_filters'] = operational_steps_normalize_train_car_filters($params);
    }
    if ($fid === 'pick_up_cars') {
        $job = operational_steps_normalize_job_list_string($params['job'] ?? '');
        if ($job === '') {
            unset($params['job']);
        } else {
            $params['job'] = $job;
        }
        $loc = operational_steps_normalize_csv_list_string($params['location'] ?? '');
        if ($loc === '') {
            unset($params['location']);
        } else {
            $params['location'] = $loc;
        }
        $params['car_filters'] = operational_steps_normalize_train_car_filters($params);
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
            if ($key === '' || array_key_exists($key, $params)) {
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
    if ($step_disabled) {
        $normalized['enabled'] = false;
    }
    $def = $catalog[$fid] ?? [];
    if (!empty($def)) {
        [$catalog_description, $description] = operational_steps_migrate_step_descriptions(
            [
                'catalog_description' => $catalog_description,
                'description' => $description,
            ],
            $def,
            $params
        );
    }
    if ($catalog_description === '' && !empty($def['description'])) {
        $catalog_description = trim((string) $def['description']);
    }
    if ($catalog_description !== '') {
        $normalized['catalog_description'] = $catalog_description;
    }
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
    $recipe = operational_steps_merge_if_then_goto_steps($recipe);
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

function operational_steps_workflow_file_suffix()
{
    return '.workflow.json';
}

function operational_steps_is_workflow_filename($name)
{
    $name = strtolower(basename((string) $name));
    if ($name === '' || str_starts_with($name, '.')) {
        return false;
    }
    return str_ends_with($name, '.workflow.json')
        || str_ends_with($name, '.recipe.json')
        || str_ends_with($name, '.json');
}

function operational_steps_sanitize_workflow_filename($name)
{
    $name = trim((string) $name);
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $suffix = operational_steps_workflow_file_suffix();
    if (preg_match('/\.workflow\.json$/i', $name)) {
        $suffix = '.workflow.json';
        $name = preg_replace('/\.workflow\.json$/i', '', $name);
    } elseif (preg_match('/\.recipe\.json$/i', $name)) {
        $suffix = '.recipe.json';
        $name = preg_replace('/\.recipe\.json$/i', '', $name);
    } elseif (preg_match('/\.csv$/i', $name)) {
        $name = preg_replace('/\.csv$/i', '', $name);
    } elseif (preg_match('/\.json$/i', $name)) {
        $suffix = '.json';
        $name = preg_replace('/\.json$/i', '', $name);
    }
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'workflow';
    }
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]*$/', $name)) {
        throw new InvalidArgumentException('Invalid workflow filename');
    }
    return $name . $suffix;
}

function operational_steps_resolve_workflow_filename($editor_dir, $requested)
{
    $requested = trim((string) $requested);
    if ($requested === '') {
        return '';
    }
    $files = operational_steps_list_workflow_files($editor_dir);
    $base = basename(str_replace('\\', '/', $requested));
    if (in_array($base, $files, true)) {
        return $base;
    }
    $sanitized = operational_steps_sanitize_workflow_filename($requested);
    if (in_array($sanitized, $files, true)) {
        return $sanitized;
    }
    return '';
}

function operational_steps_workflow_path($editor_dir, $workflow_file)
{
    $editor_dir = rtrim($editor_dir, '/');
    $workflow_file = operational_steps_sanitize_workflow_filename($workflow_file);
    return $editor_dir . '/' . $workflow_file;
}

function operational_steps_encode_recipe_json(array $recipe)
{
    return json_encode($recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function operational_steps_decode_recipe_json($text)
{
    $data = json_decode((string) $text, true);
    return is_array($data) ? $data : null;
}

function operational_steps_load_recipe_from_json_file($path)
{
    if (!is_file($path)) {
        return ['version' => 1, 'name' => 'empty', 'steps' => []];
    }
    $data = operational_steps_decode_recipe_json(file_get_contents($path));
    if (!is_array($data)) {
        throw new RuntimeException('Invalid workflow JSON: ' . basename($path));
    }
    if (!isset($data['steps']) || !is_array($data['steps'])) {
        $data['steps'] = [];
    }
    foreach ($data['steps'] as $i => $step) {
        if (is_array($step)) {
            $data['steps'][$i]['structured_import'] = true;
        }
    }
    if (!isset($data['version'])) {
        $data['version'] = 1;
    }
    $base = preg_replace('/\.(workflow|recipe)\.json$/i', '', basename($path));
    if (trim((string) ($data['name'] ?? '')) === '') {
        $data['name'] = $base !== '' ? $base : 'workflow';
    }
    return operational_steps_normalize_recipe($data);
}

function operational_steps_recipe_paths($switchlists_dir)
{
    return [
        'workflow' => rtrim($switchlists_dir, '/') . '/workflow.workflow.json',
    ];
}

/** @deprecated Legacy CSV naming — use operational_steps_sanitize_workflow_filename */
function operational_steps_sanitize_csv_name($name)
{
    return operational_steps_sanitize_workflow_filename($name);
}

function operational_steps_recipe_paths_for_workflow($switchlists_dir, $workflow_file = null)
{
    $editor_dir = operational_steps_editor_dir();
    if ($workflow_file === null || $workflow_file === '') {
        return operational_steps_recipe_paths($editor_dir);
    }
    return [
        'workflow' => operational_steps_workflow_path($editor_dir, $workflow_file),
    ];
}

/** @deprecated Use operational_steps_recipe_paths_for_workflow */
function operational_steps_recipe_paths_for_csv($switchlists_dir, $csv_name = null)
{
    return operational_steps_recipe_paths_for_workflow($switchlists_dir, $csv_name);
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
            if (!empty($data['active_workflow'])) {
                return $data;
            }
            if (!empty($data['active_csv'])) {
                $legacy = operational_steps_sanitize_workflow_filename(
                    preg_replace('/\.csv$/i', '', (string) $data['active_csv'])
                );
                return ['active_workflow' => $legacy];
            }
        }
    }
    return ['active_workflow' => ''];
}

function operational_steps_save_editor_state($switchlists_dir, array $state)
{
    $path = operational_steps_editor_state_path($switchlists_dir);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $payload = [
        'active_workflow' => (string) ($state['active_workflow'] ?? ''),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    return @file_put_contents($path, $json) !== false;
}

function operational_steps_active_workflow($switchlists_dir, $requested = null)
{
    if ($requested !== null && $requested !== '') {
        $resolved = operational_steps_resolve_workflow_filename(operational_steps_editor_dir(), $requested);
        return $resolved !== '' ? $resolved : operational_steps_sanitize_workflow_filename($requested);
    }
    return operational_steps_resolve_active_workflow($switchlists_dir);
}

/** @deprecated Use operational_steps_active_workflow */
function operational_steps_active_csv($switchlists_dir, $requested = null)
{
    return operational_steps_active_workflow($switchlists_dir, $requested);
}

function operational_steps_list_workflow_files($switchlists_dir = null)
{
    $switchlists_dir = rtrim($switchlists_dir ?? operational_steps_editor_dir(), '/');
    $files = [];
    $skip = ['.session_editor.json'];
    if (is_dir($switchlists_dir)) {
        foreach (['*.workflow.json', '*.recipe.json', '*.json'] as $pattern) {
            foreach (glob($switchlists_dir . '/' . $pattern) ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $base = basename($path);
                if (in_array($base, $skip, true) || str_starts_with($base, '.')) {
                    continue;
                }
                $files[] = $base;
            }
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values(array_unique($files));
}

/** @deprecated Use operational_steps_list_workflow_files */
function operational_steps_list_csv_files($switchlists_dir = null)
{
    return operational_steps_list_workflow_files($switchlists_dir);
}

function operational_steps_resolve_active_workflow($switchlists_dir, $requested = null)
{
    $editor_dir = operational_steps_editor_dir();
    $files = operational_steps_list_workflow_files($editor_dir);
    if ($requested !== null && $requested !== '') {
        return operational_steps_resolve_workflow_filename($editor_dir, $requested);
    }
    $state = operational_steps_load_editor_state($switchlists_dir);
    $active = trim((string) ($state['active_workflow'] ?? ''));
    if ($active !== '' && in_array($active, $files, true)) {
        return $active;
    }
    if (count($files) === 1) {
        return $files[0];
    }
    return '';
}

/** @deprecated Use operational_steps_resolve_active_workflow */
function operational_steps_resolve_active_csv($switchlists_dir, $requested = null)
{
    return operational_steps_resolve_active_workflow($switchlists_dir, $requested);
}

function operational_steps_set_active_workflow($switchlists_dir, $workflow_file)
{
    $workflow_file = operational_steps_sanitize_workflow_filename($workflow_file);
    operational_steps_save_editor_state(operational_steps_editor_dir(), ['active_workflow' => $workflow_file]);
    return $workflow_file;
}

function operational_steps_delete_workflow($switchlists_dir, $workflow_file)
{
    $editor_dir = rtrim(operational_steps_editor_dir(), '/');
    $workflow_file = operational_steps_resolve_workflow_filename($editor_dir, $workflow_file);
    if ($workflow_file === '') {
        throw new RuntimeException('Workflow file not found');
    }
    $path = $editor_dir . '/' . $workflow_file;
    $real_editor = realpath($editor_dir);
    $real_path = realpath($path);
    if ($real_editor === false || $real_path === false || strpos($real_path, $real_editor) !== 0) {
        throw new RuntimeException('Invalid workflow path');
    }
    if (!@unlink($real_path)) {
        throw new RuntimeException('Failed to delete workflow file');
    }
    $state = operational_steps_load_editor_state($switchlists_dir);
    if (($state['active_workflow'] ?? '') === $workflow_file) {
        operational_steps_save_editor_state($editor_dir, ['active_workflow' => '']);
    }
    return [
        'deleted' => $workflow_file,
        'files' => operational_steps_list_workflow_files($editor_dir),
        'active_workflow' => operational_steps_resolve_active_workflow($switchlists_dir),
    ];
}

/** @deprecated Use operational_steps_set_active_workflow */
function operational_steps_set_active_csv($switchlists_dir, $csv_name)
{
    return operational_steps_set_active_workflow($switchlists_dir, $csv_name);
}

function operational_steps_load_recipe($switchlists_dir, $workflow_file = null)
{
    $editor_dir = operational_steps_editor_dir();
    if ($workflow_file === null || $workflow_file === '') {
        $workflow_file = operational_steps_resolve_active_workflow($switchlists_dir);
        if ($workflow_file === '') {
            return ['version' => 1, 'name' => 'empty', 'steps' => [], 'source_workflow' => ''];
        }
    } else {
        $workflow_file = operational_steps_resolve_workflow_filename($editor_dir, $workflow_file);
        if ($workflow_file === '') {
            return ['version' => 1, 'name' => 'empty', 'steps' => [], 'source_workflow' => ''];
        }
    }

    $paths = operational_steps_recipe_paths_for_workflow($editor_dir, $workflow_file);
    if (!is_file($paths['workflow'])) {
        return ['version' => 1, 'name' => 'empty', 'steps' => [], 'source_workflow' => $workflow_file];
    }
    $recipe = operational_steps_load_recipe_from_json_file($paths['workflow']);
    $recipe['source_workflow'] = basename($paths['workflow']);
    return $recipe;
}

function operational_steps_save_recipe($switchlists_dir, array $recipe, $workflow_file = null)
{
    $editor_dir = operational_steps_editor_dir();
    $paths = operational_steps_recipe_paths_for_workflow($editor_dir, $workflow_file);
    $written = [];
    $errors = [];
    $path = $paths['workflow'];
    $recipe = operational_steps_normalize_recipe($recipe);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $errors[] = "mkdir failed: {$dir}";
    } elseif (@file_put_contents($path, operational_steps_encode_recipe_json($recipe)) === false) {
        $errors[] = "write failed: {$path}";
    } else {
        $written['workflow'] = $path;
    }
    $basename = basename($path);
    operational_steps_set_active_workflow($editor_dir, $basename);
    return [
        'written' => $written,
        'errors' => $errors,
        'compiled' => operational_steps_compile_recipe($recipe),
        'workflow_file' => $basename,
    ];
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
    $dispatch = $def['dispatch'] ?? $fid;
    $no_warm_start = array_merge(
        ['restore_database', 'backup_database', 'generate_orders', 'increment_session', 'fill_orders'],
        plugins_runtime_without_warm_start(),
        plugins_no_warm_start_dispatches()
    );
    if (!function_exists('warm_start_get_session') && !in_array($dispatch, $no_warm_start, true)) {
        return ['skipped' => true, 'reason' => 'session runtime unavailable (rebuild sts-docker image; see sts/RUNTIME.md)'];
    }

    $params = is_array($step['params'] ?? null) ? $step['params'] : [];
    $fractions = function_exists('warm_start_default_fractions')
        ? warm_start_default_fractions($config)
        : [];
    $result = ['function' => $fid, 'dispatch' => $dispatch];

    $plugin_result = plugins_try_dispatch($dbc, $dispatch, $step, $def, $params, $config, $result);
    if (is_array($plugin_result)) {
        return $plugin_result;
    }

    switch ($dispatch) {
        case 'staging_job':
            $job = trim($params['job'] ?? $def['dispatch_job'] ?? '');
            if ($job === '') {
                return array_merge($result, ['skipped' => true, 'reason' => 'missing job param']);
            }
            $stats = warm_start_complete_staging_jobs($dbc, [$job], $config, 1.0);
            $result['stats'] = $stats;
            $result['job'] = $job;
            break;
        case 'generate_orders':
            require_once __DIR__ . '/generate_order_helpers.php';
            $gen_params = operational_steps_normalize_generate_orders_params($params);
            $shipments = operational_steps_normalize_csv_list($gen_params['shipment'] ?? '');
            if ($shipments !== []) {
                require_once __DIR__ . '/session_helpers.php';
                $generated = 0;
                $by_shipment = [];
                $errors = [];
                foreach ($shipments as $shipment) {
                    $one = session_manual_generate_shipment($dbc, $shipment);
                    $generated += (int) ($one['generated'] ?? 0);
                    $by_shipment[$shipment] = (int) ($one['generated'] ?? 0);
                    if (!empty($one['error'])) {
                        $errors[] = $one['error'];
                    }
                }
                $result['generated'] = $generated;
                $result['shipment'] = implode(',', $shipments);
                $result['generated_by_shipment'] = $by_shipment;
                if ($errors !== []) {
                    $result['error'] = implode('; ', $errors);
                }
            } else {
                $run = generate_orders_resolve_automatic_run($dbc, $gen_params);
                $session = (int) $run['session'];
                if (!empty($run['incremented'])) {
                    $result['session'] = $session;
                }
                $unfilled = generate_orders_count_unfilled($dbc);
                $result['unfilled_before'] = $unfilled;
                if ($gen_params['max_unfilled'] !== '' && $unfilled > (int) $gen_params['max_unfilled']) {
                    $result['generated'] = 0;
                    $result['skipped'] = true;
                    $result['reason'] = 'Unfilled count ' . $unfilled . ' exceeds max ' . $gen_params['max_unfilled'];
                } else {
                    $seed = $gen_params['seed'] ?? '';
                    $max_new = ($gen_params['max_new'] ?? '') !== '' ? (int) $gen_params['max_new'] : 0;
                    $result['generated'] = generate_orders_run_automatic(
                        $dbc,
                        $session,
                        (int) $run['counter'],
                        $seed,
                        $max_new
                    );
                    if ($seed !== '') {
                        $result['seed'] = (int) $seed;
                    }
                    if ($max_new > 0) {
                        $result['max_new'] = $max_new;
                        $result['capped'] = ($result['generated'] >= $max_new);
                    }
                }
            }
            break;
        case 'increment_session':
            require_once __DIR__ . '/generate_order_helpers.php';
            $prev = generate_orders_get_session($dbc);
            $result['session'] = generate_orders_set_session($dbc, $prev + 1);
            break;
        case 'cancel_orders':
        case 'drain_unfilled_orders':
            require_once __DIR__ . '/drain_unfilled_orders.php';
            $threshold = max(0, (int) ($params['threshold'] ?? 40));
            $target = max(0, (int) ($params['target'] ?? 30));
            $keep_coke = (($params['keep_coke'] ?? '1') === '' || (string) ($params['keep_coke'] ?? '1') === '1');
            $order = strtolower(trim((string) ($params['order'] ?? 'oldest_first')));
            if (!in_array($order, ['newest_first', 'oldest_first'], true)) {
                $order = in_array($order, ['newest', 'desc', 'new'], true) ? 'newest_first' : 'oldest_first';
            }
            $drain = cancel_orders($dbc, [
                'threshold' => $threshold,
                'target' => $target,
                'keep_coke' => $keep_coke,
                'order' => $order,
            ]);
            $result['threshold'] = $threshold;
            $result['target'] = $target;
            $result['keep_coke'] = $keep_coke ? 1 : 0;
            $result['order'] = (string) ($drain['order'] ?? $order);
            $result['unfilled_before'] = (int) ($drain['before'] ?? 0);
            $result['unfilled_after'] = (int) ($drain['after'] ?? 0);
            $result['canceled'] = (int) ($drain['canceled'] ?? 0);
            if (!empty($drain['skipped'])) {
                $result['skipped'] = true;
                $result['reason'] = (string) ($drain['reason'] ?? '');
            }
            break;
        case 'fill_orders':
            require_once __DIR__ . '/fill_order_helpers.php';
            $frac = operational_steps_percent_to_fraction(
                operational_steps_normalize_percent($params, 100)
            );
            $fill = fill_order_auto_assign($dbc, [
                'order_filters' => fill_order_parse_filters(
                    operational_steps_normalize_fill_order_filters($params)
                ),
                'car_filters' => operational_steps_fill_car_filters_runtime(
                    operational_steps_normalize_fill_car_filters($params)
                ),
                'fraction' => $frac,
                'shuffle' => false,
            ]);
            $result['filled'] = $fill['filled'];
            // "unfilled" (not "skipped"): the count of orders that could not be
            // filled. The control-flow "skipped" key is a boolean meaning the step
            // itself did not run; the stats aggregator and run summary drop any
            // entry whose "skipped" is truthy, so overloading it as an int count
            // here silently discarded this step's "filled" tally.
            $result['unfilled'] = count($fill['skipped']);
            $result['filtered_out'] = $fill['filtered_out'];
            if ($fill['filled'] === 0 && count($fill['skipped']) > 0) {
                $result['unfilled_sample'] = array_slice($fill['skipped'], 0, 3);
            }
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
            $station_key = operational_steps_normalize_station_filter_list($params['station'] ?? '');
            $station_ids = operational_steps_resolve_station_ids($dbc, $station_key);
            $destination = operational_steps_normalize_destination_filter_list($params['destination'] ?? '');
            $result['jobs'] = $job_names;
            $result['station'] = $station_key;
            if ($destination !== '') {
                $result['destination'] = $destination;
            }
            $result['assigned'] = operational_steps_auto_assign_jobs($dbc, $job_names, $station_ids, $destination);
            break;
        case 'release_yard_cars':
            $job = trim((string) ($params['job'] ?? ''));
            $station_key = trim((string) ($params['station'] ?? ''));
            $station_id = ($station_key !== '' && strcasecmp($station_key, 'all') !== 0)
                ? operational_steps_location_station_id($dbc, $station_key)
                : 0;
            $result['job'] = $job;
            $result['station'] = $station_key;
            if ($job === '' || $station_id <= 0) {
                $result['skipped'] = true;
                $result['reason'] = 'job and yard station required';
            } else {
                $result['released'] = warm_start_release_job_cars_at_station($dbc, $job, $station_id);
            }
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
            $jobs = operational_steps_normalize_job_list($params['job'] ?? '');
            $locations = operational_steps_normalize_csv_list($params['location'] ?? '');
            $filters = operational_steps_normalize_train_car_filters($params);
            if ($jobs === []) {
                $staging = warm_start_staging_job_names($dbc, $config);
                $picked_up_by_job = [];
                $result['picked_up'] = warm_start_pickup_cars($dbc, 1.0, $staging, true, [], $picked_up_by_job);
                if ($picked_up_by_job !== []) {
                    $result['picked_up_by_job'] = $picked_up_by_job;
                }
            } else {
                $result['job'] = implode(',', $jobs);
                if ($locations !== []) {
                    $result['location'] = implode(',', $locations);
                }
                $picked = 0;
                foreach ($jobs as $job) {
                    if ($locations === []) {
                        $picked += warm_start_pickup_job($dbc, $job, $filters);
                        continue;
                    }
                    $resolved_any = false;
                    foreach ($locations as $loc) {
                        $station = operational_steps_location_station_id($dbc, $loc);
                        if ($station > 0) {
                            $picked += warm_start_pickup_job_at_station($dbc, $job, $station, $filters);
                            $resolved_any = true;
                        }
                    }
                    if (!$resolved_any) {
                        $picked += warm_start_pickup_job($dbc, $job, $filters);
                    }
                }
                $result['picked_up'] = $picked;
            }
            if (operational_steps_train_car_filters_active($filters)) {
                $result['car_filters'] = array_filter($filters);
            }
            break;
        case 'set_out_cars':
            $jobs = operational_steps_normalize_job_list($params['job'] ?? '');
            $locs = operational_steps_normalize_setout_locations($params['location'] ?? '');
            $filters = operational_steps_normalize_train_car_filters($params);
            if ($jobs === [] && $locs === []) {
                $staging = warm_start_staging_job_names($dbc, $config);
                $set_out_by_job = [];
                $result['set_out'] = warm_start_setout_cars($dbc, 1.0, $staging, true, [], $set_out_by_job);
                if ($set_out_by_job !== []) {
                    $result['set_out_by_job'] = $set_out_by_job;
                }
            } elseif ($jobs === []) {
                $result['skipped'] = true;
                $result['reason'] = 'missing job param';
            } else {
                $result['job'] = implode(',', $jobs);
                if ($locs !== []) {
                    $result['location'] = implode(',', $locs);
                }
                $set_out = 0;
                $unknown = [];
                foreach ($jobs as $job) {
                    $did_final = false;
                    $concrete = [];
                    if ($locs === []) {
                        // Preserve prior "job + blank location" = Final Destination.
                        $set_out += warm_start_setout_all_job_train($dbc, $job, $filters);
                        $result['assign_destinations'] = true;
                        continue;
                    }
                    foreach ($locs as $loc) {
                        if (operational_steps_setout_auto_assign_destinations($loc)) {
                            if (!$did_final) {
                                $set_out += warm_start_setout_all_job_train($dbc, $job, $filters);
                                $result['assign_destinations'] = true;
                                $did_final = true;
                            }
                            continue;
                        }
                        $concrete[] = $loc;
                    }
                    foreach ($concrete as $loc) {
                        $loc_id = operational_steps_resolve_location_id($dbc, $loc);
                        if ($loc_id > 0) {
                            $set_out += warm_start_setout_job_at_location($dbc, $job, $loc_id, $filters);
                        } else {
                            $unknown[] = $loc;
                        }
                    }
                }
                $result['set_out'] = $set_out;
                if ($unknown !== [] && $set_out === 0) {
                    $result['skipped'] = true;
                    $result['reason'] = 'unknown setout location: ' . implode(', ', array_unique($unknown));
                }
            }
            if (operational_steps_train_car_filters_active($filters)) {
                $result['car_filters'] = array_filter($filters);
            }
            break;
        case 'load_unload':
            $filters = operational_steps_normalize_load_unload_filters($params);
            $filtered = array_filter($filters);
            if (function_exists('session_sim_load_unload') && $filtered !== []) {
                $result['load_unload'] = session_sim_load_unload($dbc, 1.0, $filtered);
            } else {
                $result['load_unload'] = warm_start_load_unload($dbc, 1.0);
            }
            $result['filters'] = $filtered;
            break;
        case 'generate_switchlists':
            require_once __DIR__ . '/session_helpers.php';
            require_once __DIR__ . '/master_switchlist_helpers.php';
            $format = operational_steps_normalize_switchlist_format($params['format'] ?? 'all');
            $jobs = session_resolve_jobs_param($params['jobs'] ?? 'all', $dbc);
            $session = master_sw_get_setting($dbc, 'session_nbr');
            $root = $config['session_root'] ?? session_web_root();
            $manifest = session_load_manifest($session, $root);
            $phase_num = (int) ($config['phase'] ?? 0);
            if ($phase_num < 1) {
                $phase_num = count($manifest['phases'] ?? []) + 1;
            }
            $phase_dir = session_phase_output_dir($session, $phase_num, $root);
            $recipe = $config['recipe'] ?? null;
            $through_step = (int) ($config['through_step'] ?? 0);
            $title = trim((string) ($params['title'] ?? ''));
            $info = trim((string) ($params['info'] ?? ''));
            $result['switchlists'] = master_sw_generate_for_jobs($dbc, $jobs, $phase_dir, $config, [
                'format' => $format,
                'recipe' => is_array($recipe) ? $recipe : null,
                'through_step' => $through_step,
                'title' => $title,
                'info' => $info,
            ]);
            session_register_phase($manifest, $phase_num, [
                'jobs' => $jobs,
                'format' => $format,
                'styles' => master_sw_styles_for_format($format),
                'title' => $title,
                'info' => $info,
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
            // Same normalized path as the recipe runner: capture every
            // switch-list phase (idempotent) and rebuild the session bundle.
            $result['waybills'] = session_capture_and_refresh_waybills($dbc, $session, $root);
            break;
        case 'render_switchlists':
            require_once __DIR__ . '/session_helpers.php';
            require_once __DIR__ . '/master_switchlist_helpers.php';
            $format = operational_steps_normalize_switchlist_format($params['format'] ?? 'all');
            $jobs = session_resolve_jobs_param($params['jobs'] ?? 'all', $dbc);
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
            $jobs = session_resolve_jobs_param($params['jobs'] ?? 'all', $dbc);
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
            $name = preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '',
                operational_steps_resolve_backup_name($params['backup'] ?? '', 'manual_backup')
            );
            if ($name === '') {
                return ['skipped' => true, 'reason' => 'invalid backup name'];
            }
            $result['path'] = warm_start_backup($dbc, $name);
            break;
        case 'restore_database':
            $name = operational_steps_resolve_backup_name($params['backup'] ?? '');
            list($ok, $msg) = operational_steps_restore_backup($dbc, $name);
            $result['restored'] = $ok;
            $result['message'] = $msg;
            if (!$ok) {
                return ['error' => $msg, 'function' => $fid];
            }
            // A restore resets the DB session counter (session_nbr -> 0). Purge
            // stale per-session output so the cumulative statistics on
            // session.php start clean for the new campaign instead of rolling up
            // run_stats left over from a previous one.
            if (function_exists('session_reset_all_output')) {
                $result['cleared_sessions'] = session_reset_all_output();
            }
            break;
        default:
            return ['skipped' => true, 'reason' => 'no handler'];
    }
    return $result;
}

function operational_steps_dispatch_step_label(array $entry)
{
    $fid = (string) ($entry['function'] ?? '');
    if ($fid !== '') {
        $cat = operational_steps_catalog_by_id();
        if (isset($cat[$fid]['label'])) {
            return (string) $cat[$fid]['label'];
        }
    }
    return $fid !== '' ? $fid : 'dispatch';
}

/**
 * Human-readable simulator log line for a dispatch step (matches manual GUI popups where possible).
 */
function operational_steps_format_dispatch_log_line(array $entry)
{
    $step = $entry['step'] ?? '?';
    $label = operational_steps_dispatch_step_label($entry);

    if (!empty($entry['error'])) {
        return sprintf('  step %s (%s): error — %s', $step, $label, $entry['error']);
    }

    if (isset($entry['skipped']) && $entry['skipped'] === true) {
        $reason = (string) ($entry['reason'] ?? 'skipped');
        return sprintf('  step %s (%s): skipped — %s', $step, $label, $reason);
    }

    $messages = [];
    $dispatch = (string) ($entry['dispatch'] ?? '');

    if (array_key_exists('generated', $entry)) {
        $n = (int) $entry['generated'];
        if (!empty($entry['shipment'])) {
            $messages[] = sprintf('%d car order(s) generated for shipment %s.', $n, $entry['shipment']);
        } else {
            $messages[] = sprintf('%d car order(s) generated.', $n);
        }
        if (!empty($entry['session'])) {
            $messages[] = sprintf('Session %s.', $entry['session']);
        }
    }

    plugins_append_dispatch_log_messages($dispatch, $entry, $messages);

    if ($dispatch === 'increment_session' && isset($entry['session'])) {
        $messages[] = sprintf('Session incremented to %s.', $entry['session']);
    }

    if (array_key_exists('filled', $entry)) {
        $messages[] = sprintf('%d car order(s) auto assigned.', (int) $entry['filled']);
        $unfilled = (int) ($entry['unfilled'] ?? 0);
        if ($unfilled > 0) {
            $messages[] = sprintf('%d order(s) still need manual attention.', $unfilled);
        }
    }

    if (($dispatch === 'cancel_orders' || $dispatch === 'drain_unfilled_orders')
        && array_key_exists('canceled', $entry)
    ) {
        $messages[] = sprintf(
            'Canceled %d unfilled order(s) %s (%d → %d).',
            (int) $entry['canceled'],
            (string) ($entry['order'] ?? 'oldest_first'),
            (int) ($entry['unfilled_before'] ?? 0),
            (int) ($entry['unfilled_after'] ?? 0)
        );
    }

    if (array_key_exists('repositioned', $entry)) {
        $messages[] = sprintf('%d empty car(s) repositioned.', (int) $entry['repositioned']);
    }

    if (array_key_exists('assigned', $entry) && !isset($entry['stats'])) {
        $n = (int) $entry['assigned'];
        $jobs = $entry['jobs'] ?? [];
        if (is_string($jobs)) {
            $jobs = array_values(array_filter(array_map('trim', explode(',', $jobs))));
        } elseif (!is_array($jobs)) {
            $jobs = [];
        }
        $job = trim((string) ($entry['job'] ?? ''));
        if ($n > 0) {
            if (count($jobs) === 1) {
                $messages[] = sprintf('%d car(s) assigned to Job/Train %s.', $n, $jobs[0]);
            } elseif (count($jobs) > 1) {
                $messages[] = sprintf('%d car(s) assigned to jobs (%s).', $n, implode(', ', $jobs));
            } elseif ($job !== '') {
                $messages[] = sprintf('%d car(s) assigned to Job/Train %s.', $n, $job);
            } else {
                $messages[] = sprintf('%d car(s) assigned.', $n);
            }
        } elseif ($job !== '') {
            $messages[] = sprintf('No cars assigned to Job/Train %s.', $job);
        } else {
            $messages[] = 'No cars assigned.';
        }
    }

    if (array_key_exists('picked_up', $entry)) {
        $job = trim((string) ($entry['job'] ?? ''));
        $prefix = $job !== '' ? $job . ': ' : '';
        $messages[] = sprintf('%s%d car(s) picked up.', $prefix, (int) $entry['picked_up']);
    }

    if (array_key_exists('set_out', $entry)) {
        $job = trim((string) ($entry['job'] ?? ''));
        $prefix = $job !== '' ? $job . ': ' : '';
        $messages[] = sprintf('%s%d car(s) set out.', $prefix, (int) $entry['set_out']);
    }

    if (array_key_exists('load_unload', $entry) && !isset($entry['stats'])) {
        $messages[] = sprintf('%d load/unload operation(s) completed.', (int) $entry['load_unload']);
    }

    if (isset($entry['weigh']) && is_array($entry['weigh'])) {
        $w = $entry['weigh'];
        $weighed = (int) ($w['weighed'] ?? 0);
        if ($weighed > 0) {
            $messages[] = sprintf('%d car(s) weighed.', $weighed);
        } elseif (empty($w['success'])) {
            $err = !empty($w['errors']) ? implode(' ', $w['errors']) : 'No cars weighed.';
            $messages[] = $err;
        }
        $reloads = (int) ($w['reloads'] ?? 0);
        if ($reloads > 0) {
            $messages[] = sprintf('%d reload(s).', $reloads);
        }
    }

    if (isset($entry['stats']) && is_array($entry['stats'])) {
        $s = $entry['stats'];
        $job = trim((string) ($entry['job'] ?? ''));
        $prefix = $job !== '' ? $job . ': ' : '';
        foreach (
            [
                'assigned' => 'assigned',
                'picked_up' => 'picked up',
                'set_out' => 'set out',
                'load_unload' => 'load/unload completed for',
            ] as $key => $verb
        ) {
            if (!empty($s[$key])) {
                $messages[] = sprintf('%s%d car(s) %s.', $prefix, (int) $s[$key], $verb);
            }
        }
    }

    if ($dispatch === 'restore_database') {
        $msg = trim((string) ($entry['message'] ?? ''));
        if ($msg !== '') {
            $messages[] = $msg;
        } elseif (!empty($entry['restored'])) {
            $messages[] = 'Database restored.';
        }
    }

    if (!empty($entry['path'])) {
        $messages[] = 'Backup written: ' . basename((string) $entry['path']);
    }

    if (array_key_exists('switchlists', $entry)) {
        $messages[] = sprintf('%d switch list(s) generated.', (int) $entry['switchlists']);
    }

    if ($messages === []) {
        return sprintf('  step %s (%s): ok', $step, $label);
    }

    return sprintf('  step %s (%s): %s', $step, $label, implode(' ', $messages));
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
                && stripos($instr, 'Generate Switch Lists') === false
                && $indices['operating_start'] === null
                && $n >= 20) {
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
    if ($indices['operating_start'] === null && $total > 0) {
        $indices['operating_start'] = min($total, max(1, (int) ceil($total / 2)));
    }
    if ($indices['generate_step'] === null && $total > 0) {
        $indices['generate_step'] = $total;
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

function operational_steps_run_switchlists_web($dbc, $format = 'all', array $jobs = [], array $options = [])
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
    session_register_phase($manifest, $phase_num, [
        'jobs' => $jobs,
        'format' => $format,
        'styles' => master_sw_styles_for_format($format),
        'output' => $out,
    ]);
    // Collapse accumulated duplicate phases (same slot regenerated) and purge the
    // orphaned phase dirs + stale bundles so this direct path can't inflate the
    // manifest either.
    if (function_exists('session_compact_session_output')) {
        session_compact_session_output($manifest, $session, $root);
    }
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
    $format = $options['format'] ?? 'all';
    $jobs = $options['jobs'] ?? [];
    if ($jobs === []) {
        $jobs = session_resolve_jobs_param('all', $dbc);
    }
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
                $indices = operational_steps_recipe_indices($recipe);
                $from = max(1, (int) ($indices['operating_start'] ?? ($to_step + 1)));
                $to = (int) ($indices['session_end'] ?? count($recipe['steps'] ?? []));
                if ($from <= $to) {
                    $cycle_result['play'] = session_run_recipe($dbc, $recipe, [
                        'from_step' => $from,
                        'to_step' => $to,
                        'format' => $config['format'] ?? 'all',
                        'config' => $config,
                    ]);
                } else {
                    $cycle_result['play'] = ['skipped' => true, 'reason' => 'no operating session range in recipe'];
                }
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
