<?php
require_once __DIR__ . '/plugins/plugins.php';
require_once __DIR__ . '/drop_down_list_functions.php';

/**
 * Count open or unfilled car orders, optionally filtered by commodity /
 * shipment / car code / loading / unloading / final destination.
 * Location filters use the same station:: / location:: tokens as Pickup.
 *
 * @param 'open_orders'|'unfilled_orders' $kind
 * @param array{
 *   commodity?:string,
 *   shipment?:string,
 *   car_code?:string,
 *   loading_location?:string,
 *   unloading_location?:string,
 *   final_destination?:string
 * } $filters
 */
function operations_count_orders($dbc, $kind, array $filters = [])
{
    $kind = ($kind === 'unfilled_orders') ? 'unfilled_orders' : 'open_orders';
    $commodity = trim((string) ($filters['commodity'] ?? $filters['consignment'] ?? ''));
    $shipment = trim((string) ($filters['shipment'] ?? ''));
    $car_code = trim((string) ($filters['car_code'] ?? ''));
    $loading = trim((string) ($filters['loading_location'] ?? ''));
    $unloading = trim((string) ($filters['unloading_location'] ?? ''));
    $final_dest = trim((string) ($filters['final_destination'] ?? ''));
    $needs_loc = ($loading !== '' || $unloading !== '' || $final_dest !== '');
    $needs_join = ($commodity !== '' || $shipment !== '' || $car_code !== '' || $needs_loc);

    if ($needs_loc) {
        require_once __DIR__ . '/operations_train_car_filters.php';
        if (!function_exists('warm_start_load_unload_filter_token_match')) {
            $warm = __DIR__ . '/warm_start_helpers.php';
            if (is_file($warm)) {
                require_once $warm;
            }
        }
        $sql = 'SELECT DISTINCT car_orders.waybill_number AS waybill_number,
                       load_st.station AS loading_station,
                       load_loc.code AS loading_location,
                       unload_st.station AS unloading_station,
                       unload_loc.code AS unloading_location
                FROM car_orders
                INNER JOIN shipments ON shipments.id = car_orders.shipment
                LEFT JOIN locations load_loc ON load_loc.id = shipments.loading_location
                LEFT JOIN routing load_st ON load_st.id = load_loc.station
                LEFT JOIN locations unload_loc ON unload_loc.id = shipments.unloading_location
                LEFT JOIN routing unload_st ON unload_st.id = unload_loc.station';
        if ($commodity !== '') {
            $sql .= ' INNER JOIN commodities ON commodities.id = shipments.consignment';
        }
        if ($car_code !== '') {
            $sql .= ' INNER JOIN car_codes ON car_codes.id = shipments.car_code';
        }
    } else {
        $sql = 'SELECT COUNT(DISTINCT car_orders.waybill_number) AS cnt FROM car_orders';
        if ($needs_join) {
            $sql .= ' INNER JOIN shipments ON shipments.id = car_orders.shipment';
            if ($commodity !== '') {
                $sql .= ' INNER JOIN commodities ON commodities.id = shipments.consignment';
            }
            if ($car_code !== '') {
                $sql .= ' INNER JOIN car_codes ON car_codes.id = shipments.car_code';
            }
        }
    }

    $where = [];
    if ($kind === 'unfilled_orders') {
        $where[] = '(car_orders.car = "" OR car_orders.car IS NULL OR car_orders.car = "0")';
    }
    if ($commodity !== '') {
        $where[] = 'commodities.code = "' . mysqli_real_escape_string($dbc, $commodity) . '"';
    }
    if ($shipment !== '') {
        $where[] = 'shipments.code = "' . mysqli_real_escape_string($dbc, $shipment) . '"';
    }
    if ($car_code !== '') {
        // Allow trailing * wildcards (HC* → HC%).
        $like = str_replace('*', '%', $car_code);
        $where[] = 'car_codes.code LIKE "' . mysqli_real_escape_string($dbc, $like) . '"';
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    if (!$needs_loc) {
        $rs = mysqli_query($dbc, $sql);
        if ($rs && ($row = mysqli_fetch_array($rs))) {
            return (int) $row['cnt'];
        }

        return 0;
    }

    $rs = mysqli_query($dbc, $sql);
    if (!$rs) {
        return 0;
    }

    $match_side = static function ($raw, $station, $code) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return true;
        }
        $station = trim((string) $station);
        $code = trim((string) $code);
        $label = ($station !== '' && $code !== '') ? ($station . ' - ' . $code) : $code;
        if (function_exists('operational_steps_normalize_destination_filters')) {
            $needles = operational_steps_normalize_destination_filters($raw);
        } else {
            $needles = array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
        if ($needles === []) {
            return true;
        }
        foreach ($needles as $needle) {
            if (operational_steps_train_car_station_location_match($needle, $station, $label)) {
                return true;
            }
        }

        return false;
    };

    $count = 0;
    $seen = [];
    while ($row = mysqli_fetch_assoc($rs)) {
        $wb = (string) ($row['waybill_number'] ?? '');
        if ($wb === '' || isset($seen[$wb])) {
            continue;
        }
        if (!$match_side($loading, $row['loading_station'] ?? '', $row['loading_location'] ?? '')) {
            continue;
        }
        if (!$match_side($unloading, $row['unloading_station'] ?? '', $row['unloading_location'] ?? '')) {
            continue;
        }
        // Final destination on orders = unloading end (delivery station/spot).
        if (!$match_side($final_dest, $row['unloading_station'] ?? '', $row['unloading_location'] ?? '')) {
            continue;
        }
        $seen[$wb] = true;
        $count++;
    }

    return $count;
}

function operations_get_stats($dbc)
{
    $stats = [
        'open_orders' => 0,
        'unfilled_orders' => 0,
        'unassigned' => 0,
        'pending_pickup' => 0,
        'pending_setout' => 0,
        'load_unload_pending' => 0,
        'reposition_off_home' => 0,
        'organize_by_job' => 0,
        'organize_at_station' => 0,
        'organize_unique' => 0,
    ];

    $stats['open_orders'] = operations_count_orders($dbc, 'open_orders');
    $stats['unfilled_orders'] = operations_count_orders($dbc, 'unfilled_orders');

    $queries = [
        'unassigned' => 'SELECT COUNT(*) AS cnt
                         FROM cars
                         WHERE handled_by_job_id = 0
                           AND status IN ("Ordered", "Loaded")
                           AND current_location_id > 0',
        'pending_pickup' => 'SELECT COUNT(*) AS cnt
                             FROM cars
                             WHERE handled_by_job_id > 0
                               AND current_location_id > 0
                               AND status != "Unavailable"',
        'pending_setout' => 'SELECT COUNT(*) AS cnt
                             FROM cars
                             WHERE handled_by_job_id > 0
                               AND current_location_id = 0
                               AND status != "Unavailable"',
        'reposition_off_home' => 'SELECT COUNT(*) AS cnt
                                  FROM cars
                                  WHERE status = "Empty"
                                    AND NOT EXISTS (
                                      SELECT car_orders.car
                                      FROM car_orders
                                      WHERE cars.id = car_orders.car
                                    )
                                    AND current_location_id != home_location',
    ];

    foreach ($queries as $key => $sql) {
        $rs = mysqli_query($dbc, $sql);
        if ($rs && ($row = mysqli_fetch_array($rs))) {
            $stats[$key] = (int)$row['cnt'];
        }
    }

    $sql = 'SELECT COUNT(*) AS cnt
            FROM cars
            LEFT JOIN car_orders ON car_orders.car = cars.id
            WHERE (cars.status = "Loading")
               OR (cars.status = "Unloading")
               OR ((cars.status = "Empty") AND (cars.current_location_id = car_orders.shipment))';

    $rs = mysqli_query($dbc, $sql);
    if ($rs && ($row = mysqli_fetch_array($rs))) {
        $stats['load_unload_pending'] = (int)$row['cnt'];
    }

    $stats['organize_by_job'] = organize_total_cars_by_job($dbc);
    $stats['organize_at_station'] = organize_total_cars_at_locations($dbc);
    $stats['organize_unique'] = organize_total_unique_cars($dbc);
    plugins_apply_stats($dbc, $stats);

    return $stats;
}

/**
 * Workflow if/then variables — matches counts shown on operations.php.
 */
function operations_dashboard_condition_variables()
{
    return array_merge([
        ['key' => 'session_nbr', 'label' => 'Session number'],
        ['key' => 'session_is_odd', 'label' => 'Session is odd (1=yes)'],
        ['key' => 'session_is_even', 'label' => 'Session is even (1=yes)'],
        ['key' => 'open_orders', 'label' => 'Open orders'],
        ['key' => 'unfilled_orders', 'label' => 'Unfilled orders'],
        ['key' => 'filled_this_run', 'label' => 'Filled this run'],
        ['key' => 'generated_this_run', 'label' => 'Generated this run'],
        ['key' => 'repositioned_this_run', 'label' => 'Repositioned this run'],
        ['key' => 'reposition_off_home', 'label' => 'Empty cars not at home'],
        ['key' => 'unassigned', 'label' => 'Unassigned cars'],
        ['key' => 'pending_pickup', 'label' => 'Pending pickup'],
        ['key' => 'organize_unique', 'label' => 'Organize cars'],
        ['key' => 'pending_setout', 'label' => 'Pending set-out'],
        ['key' => 'load_unload_pending', 'label' => 'Load/unload pending'],
    ], plugins_condition_variables());
}

function operations_dashboard_condition_label($key)
{
    foreach (operations_dashboard_condition_variables() as $var) {
        if (($var['key'] ?? '') === $key) {
            return (string) ($var['label'] ?? $key);
        }
    }

    return (string) $key;
}

function operations_get_session_nbr($dbc)
{
    $sql = 'SELECT setting_value FROM settings WHERE setting_name = "session_nbr"';
    $rs = mysqli_query($dbc, $sql);
    if ($rs && ($row = mysqli_fetch_array($rs))) {
        return (int)$row['setting_value'];
    }

    return 0;
}

function operations_render_stat_column($label, $value)
{
    return '<div class="op-stat-col">'
         . '<div class="op-stat-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
         . '<div class="op-stat-value">' . (int)$value . '</div>'
         . '</div>';
}

function operations_render_stat_columns($columns)
{
    $count = count($columns);
    $class = 'op-btn-stat-cols' . ($count > 1 ? ' op-btn-stat-cols-multi' : '');

    $html = '<div class="' . $class . '">';
    foreach ($columns as $index => $column) {
        if ($index > 0) {
            $html .= '<div class="op-stat-sep" aria-hidden="true">|</div>';
        }
        $html .= operations_render_stat_column($column['label'], $column['value']);
    }
    $html .= '</div>';

    return $html;
}

?>
