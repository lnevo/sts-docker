<?php

function fill_order_get_details($dbc, $waybill_number)
{
    $waybill_number = mysqli_real_escape_string($dbc, $waybill_number);
    $sql = 'SELECT car_orders.shipment as shipment_id,
                   shipments.code as shipment,
                   shipments.description as description,
                   shipments.consignment as consignment_id,
                   shipments.car_code as car_code_id,
                   shipments.loading_location as loading_location_id,
                   shipments.unloading_location as unloading_location_id,
                   shipments.remarks as remarks,
                   commodities.code as consignment,
                   car_codes.code as car_code,
                   sta01.station as loading_station,
                   loc01.code as loading_location,
                   sta02.station as unloading_station,
                   loc02.code as unloading_location
            FROM car_orders
            LEFT JOIN shipments ON shipments.id = car_orders.shipment
            LEFT JOIN commodities ON commodities.id = shipments.consignment
            LEFT JOIN car_codes ON car_codes.id = shipments.car_code
            LEFT JOIN locations loc01 ON loc01.id = shipments.loading_location
            LEFT JOIN locations loc02 ON loc02.id = shipments.unloading_location
            LEFT JOIN routing sta01 ON sta01.id = loc01.station
            LEFT JOIN routing sta02 ON sta02.id = loc02.station
            WHERE car_orders.waybill_number = "' . $waybill_number . '"';

    $rs = mysqli_query($dbc, $sql);
    if (!$rs || mysqli_num_rows($rs) <= 0) {
        return null;
    }

    return mysqli_fetch_array($rs);
}

function fill_order_get_available_cars($dbc, $order_row)
{
    $shipment_id = $order_row['shipment_id'];
    $car_code = mysqli_real_escape_string($dbc, $order_row['car_code']);
    $loading_station = mysqli_real_escape_string($dbc, $order_row['loading_station']);
    $shipment = mysqli_real_escape_string($dbc, $order_row['shipment']);

    $sql_pool = 'SELECT cars.reporting_marks as reporting_marks,
                        car_codes.code as car_code,
                        cars.id as car_id,
                        routing.station as current_station,
                        locations.code as current_location,
                        0 as priority,
                        cars.load_count as load_count,
                        cars.remarks as remarks
                 FROM cars
                 LEFT JOIN pool ON cars.id = pool.car_id
                 LEFT JOIN locations ON locations.id = cars.current_location_id
                 LEFT JOIN routing ON routing.id = locations.station
                 LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
                 WHERE cars.status = "Empty"
                   AND cars.id NOT IN (SELECT car FROM car_orders)
                   AND car_codes.code LIKE REPLACE("' . $car_code . '", "*", "%")
                   AND pool.car_id = cars.id
                   AND pool.shipment_id = "' . mysqli_real_escape_string($dbc, $shipment_id) . '"
                 ORDER BY cars.load_count';

    $pool_cars = [];
    $rs_pool = mysqli_query($dbc, $sql_pool);
    while ($row = mysqli_fetch_array($rs_pool)) {
        $pool_cars[] = array_merge($row, ['category' => 'pool']);
    }

    $sql_station = 'SELECT cars.reporting_marks as reporting_marks,
                           car_codes.code as car_code,
                           cars.id as car_id,
                           routing.station as current_station,
                           locations.code as current_location,
                           0 as priority,
                           cars.load_count as load_count,
                           cars.remarks as remarks
                    FROM cars
                    LEFT JOIN locations ON locations.id = cars.current_location_id
                    LEFT JOIN routing ON routing.id = locations.station
                    LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
                    WHERE cars.status = "Empty"
                      AND cars.id NOT IN (SELECT car FROM car_orders)
                      AND cars.id NOT IN (SELECT car_id FROM pool)
                      AND car_codes.code LIKE REPLACE("' . $car_code . '", "*", "%")
                      AND cars.current_location_id IN (SELECT locations.id
                                                       FROM locations, routing
                                                       WHERE locations.station = routing.id AND routing.station = "' . $loading_station . '")
                    ORDER BY priority, cars.load_count';

    $station_cars = [];
    $rs_station = mysqli_query($dbc, $sql_station);
    while ($row = mysqli_fetch_array($rs_station)) {
        $station_cars[] = array_merge($row, ['category' => 'station']);
    }

    $sql_priority = 'SELECT cars.reporting_marks as reporting_marks,
                            cars.id as car_id,
                            car_codes.code as car_code,
                            routing.station as current_station,
                            locations.code as current_location,
                            empty_locations.priority as priority,
                            cars.load_count as load_count,
                            cars.remarks as remarks
                     FROM (cars, empty_locations, shipments)
                     LEFT JOIN locations ON locations.id = cars.current_location_id
                     LEFT JOIN routing ON routing.id = locations.station
                     LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
                     WHERE cars.status = "Empty"
                       AND cars.id NOT IN (SELECT car FROM car_orders)
                       AND cars.id NOT IN (SELECT car_id FROM pool)
                       AND car_codes.code LIKE REPLACE("' . $car_code . '", "*", "%")
                       AND cars.current_location_id = empty_locations.location
                       AND empty_locations.shipment = shipments.id
                       AND shipments.code = "' . $shipment . '"
                       AND cars.current_location_id NOT IN (SELECT locations.id
                                                        FROM locations, routing
                                                        WHERE locations.station = routing.id AND routing.station = "' . $loading_station . '")
                     ORDER BY priority, cars.load_count';

    $priority_cars = [];
    $rs_priority = mysqli_query($dbc, $sql_priority);
    while ($row = mysqli_fetch_array($rs_priority)) {
        $priority_cars[] = array_merge($row, ['category' => 'priority']);
    }

    $sql_system = 'SELECT DISTINCT cars.reporting_marks as reporting_marks,
                                  cars.id as car_id,
                                  car_codes.code as car_code,
                                  routing.station as current_station,
                                  locations.code as current_location,
                                  0 as priority,
                                  cars.load_count as load_count,
                                  cars.remarks as remarks
                   FROM cars
                   LEFT JOIN locations ON locations.id = cars.current_location_id
                   LEFT JOIN routing ON routing.id = locations.station
                   LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
                   WHERE cars.status = "Empty"
                     AND cars.id NOT IN (SELECT car FROM car_orders)
                     AND cars.id NOT IN (SELECT car_id FROM pool)
                     AND car_codes.code LIKE REPLACE("' . $car_code . '", "*", "%")
                     AND cars.reporting_marks NOT IN
                     (SELECT cars.reporting_marks
                      FROM cars
                      WHERE cars.status = "Empty"
                        AND car_codes.code LIKE REPLACE("' . $car_code . '", "*", "%")
                        AND cars.current_location_id IN (SELECT locations.id
                                                         FROM locations, routing
                                                         WHERE locations.station = routing.id AND routing.station = "' . $loading_station . '")
                                                     UNION
                                                     SELECT cars.reporting_marks
                                                     FROM (cars, empty_locations)
                                                     WHERE cars.status = "Empty"
                                                       AND car_codes.code LIKE REPLACE("' . $car_code . '", "*", "%")
                                                       AND cars.current_location_id = empty_locations.location
                                                       AND empty_locations.shipment = "' . $shipment . '"
                                                       AND cars.current_location_id NOT IN (SELECT locations.id
                                                                                      FROM locations, routing
                                                                                      WHERE locations.station = routing.id AND routing.station = "' . $loading_station . '"))
                   ORDER BY priority, cars.load_count';

    $system_cars = [];
    $rs_system = mysqli_query($dbc, $sql_system);
    while ($row = mysqli_fetch_array($rs_system)) {
        $system_cars[] = array_merge($row, ['category' => 'system']);
    }

    return array_merge($pool_cars, $station_cars, $priority_cars, $system_cars);
}

function fill_order_valid_categories()
{
    return ['pool', 'station', 'priority', 'system'];
}

function fill_order_parse_categories($input)
{
    $valid = fill_order_valid_categories();
    if (!is_array($input)) {
        return $valid;
    }

    $selected = [];
    foreach ($input as $category) {
        $category = strtolower(trim((string) $category));
        if (in_array($category, $valid, true)) {
            $selected[] = $category;
        }
    }

    return count($selected) > 0 ? $selected : $valid;
}

/** Treat UI "All/Any" sentinel values as no filter (matches fill_orders.php empty select). */
function fill_order_normalize_scope_filter($value)
{
    $value = trim((string) $value);
    if ($value === '' || strcasecmp($value, 'all') === 0 || strcasecmp($value, 'any') === 0) {
        return '';
    }
    return $value;
}

function fill_order_car_filter_skip_reason(array $car_filters)
{
    $parts = [];
    if (!empty($car_filters['current_station'])) {
        $parts[] = 'station=' . $car_filters['current_station'];
    }
    if (!empty($car_filters['current_location'])) {
        $parts[] = 'location=' . $car_filters['current_location'];
    }
    if (!empty($car_filters['car_code'])) {
        $parts[] = 'car_type=' . $car_filters['car_code'];
    }
    $categories = $car_filters['categories'] ?? fill_order_valid_categories();
    if (is_array($categories) && count($categories) < count(fill_order_valid_categories())) {
        $parts[] = 'sources=' . implode(',', $categories);
    }
    if (empty($parts)) {
        return 'No cars match car filters';
    }
    return 'No cars match car filters (' . implode('; ', $parts) . ')';
}

function fill_order_parse_car_filters($input)
{
    $filters = [
        'categories' => fill_order_valid_categories(),
    ];

    if (!is_array($input)) {
        return $filters;
    }

    if (isset($input['categories'])) {
        $filters['categories'] = fill_order_parse_categories($input['categories']);
    }

    $station = fill_order_normalize_scope_filter($input['current_station'] ?? '');
    if ($station !== '') {
        $filters['current_station'] = $station;
    }

    $location = fill_order_normalize_scope_filter($input['current_location'] ?? '');
    if ($location !== '') {
        $filters['current_location'] = $location;
    }

    if (!empty($input['car_code'])) {
        $filters['car_code'] = trim((string) $input['car_code']);
    }

    return $filters;
}

function fill_order_car_code_matches_filter($car_code, $filter_code)
{
    $car_code = (string) $car_code;
    $filter_code = trim((string) $filter_code);
    if ($filter_code === '') {
        return true;
    }
    if (strpos($filter_code, '*') !== false) {
        $prefix = str_replace('*', '', $filter_code);
        if ($prefix === '') {
            return true;
        }

        return stripos($car_code, $prefix) === 0;
    }

    return $car_code === $filter_code;
}

function fill_order_filter_cars($cars, $car_filters)
{
    if (empty($car_filters)) {
        return $cars;
    }

    $categories = $car_filters['categories'] ?? fill_order_valid_categories();

    return array_values(array_filter($cars, function ($car) use ($car_filters, $categories) {
        if (!in_array($car['category'], $categories, true)) {
            return false;
        }

        if (!empty($car_filters['current_station'])
            && (string) ($car['current_station'] ?? '') !== (string) $car_filters['current_station']) {
            return false;
        }

        if (!empty($car_filters['current_location'])
            && (string) ($car['current_location'] ?? '') !== (string) $car_filters['current_location']) {
            return false;
        }

        if (!empty($car_filters['car_code'])
            && !fill_order_car_code_matches_filter($car['car_code'] ?? '', $car_filters['car_code'])) {
            return false;
        }

        return true;
    }));
}

function fill_order_count_cars_by_category($cars)
{
    $counts = [
        'pool' => 0,
        'station' => 0,
        'priority' => 0,
        'system' => 0,
    ];

    foreach ($cars as $car) {
        $category = $car['category'] ?? 'system';
        if (isset($counts[$category])) {
            $counts[$category]++;
        } else {
            $counts['system']++;
        }
    }

    return $counts;
}

function fill_order_parse_filters($input)
{
    if (!is_array($input)) {
        return [];
    }

    $filters = [];
    $fields = [
        'loading_location',
        'unloading_location',
        'consignment',
        'car_code',
    ];

    foreach ($fields as $field) {
        if (!empty($input[$field])) {
            $filters[$field] = trim((string) $input[$field]);
        }
    }

    return $filters;
}

function fill_order_matches_filters($order_row, $filters)
{
    if (empty($filters)) {
        return true;
    }

    foreach ($filters as $field => $value) {
        if ($value === '') {
            continue;
        }
        if (!isset($order_row[$field]) || (string) $order_row[$field] !== (string) $value) {
            return false;
        }
    }

    return true;
}

function fill_order_pick_car_for_categories($available_cars, $categories, $car_filters = null)
{
    if ($car_filters !== null) {
        $available_cars = fill_order_filter_cars($available_cars, $car_filters);
        $categories = $car_filters['categories'] ?? $categories;
    }

    $tier_order = [
        ['tier' => 'pool', 'key' => 'pool'],
        ['tier' => 'station', 'key' => 'station'],
        ['tier' => 'priority', 'key' => 'priority'],
        ['tier' => 'system', 'key' => 'system'],
    ];

    foreach ($tier_order as $entry) {
        if (!in_array($entry['key'], $categories, true)) {
            continue;
        }

        foreach ($available_cars as $car) {
            if ($car['category'] === $entry['tier']) {
                return $car;
            }
        }
    }

    return null;
}

/**
 * Assign eligible cars to open orders (same rules as fill_orders.php Auto Assign / auto_fill_orders_ajax.php).
 *
 * Options:
 *   waybills       — if set, only these waybills; if null, all unfilled (optionally filtered by order_filters)
 *   order_filters  — from fill_order_parse_filters()
 *   car_filters    — from fill_order_parse_car_filters(); null = all sources, no car location/type filters
 *   fraction       — 0–1, max share of eligible orders to attempt (default 1.0)
 *   shuffle        — randomize order before applying fraction (warm-start uses true; GUI uses false)
 */
function fill_order_auto_assign($dbc, array $options = [])
{
    // Heal Ordered-without-order ghosts before pool selection so they can fill.
    fill_order_heal_orphan_ordered_cars($dbc);

    $selected_waybills = $options['waybills'] ?? null;
    if ($selected_waybills !== null) {
        $selected_waybills = array_values(array_filter(array_map('trim', (array) $selected_waybills)));
        if (count($selected_waybills) === 0) {
            return [
                'filled' => 0,
                'filled_items' => [],
                'skipped' => [],
                'filtered_out' => 0,
            ];
        }
    }

    $order_filters = $options['order_filters'] ?? [];
    if (!is_array($order_filters)) {
        $order_filters = [];
    }

    $car_filters = $options['car_filters'] ?? null;
    if ($car_filters !== null && !is_array($car_filters)) {
        $car_filters = null;
    }

    $categories = fill_order_valid_categories();
    if ($car_filters !== null) {
        $categories = $car_filters['categories'] ?? $categories;
    }
    if (!is_array($categories)) {
        $categories = fill_order_parse_categories($categories);
    }

    $fraction = max(0.0, min(1.0, (float) ($options['fraction'] ?? 1.0)));
    $shuffle = !empty($options['shuffle']);

    $waybills = fill_order_get_unfilled_waybills($dbc);
    $filtered_out = 0;
    $eligible = [];

    foreach ($waybills as $waybill_number) {
        if ($selected_waybills !== null && !in_array($waybill_number, $selected_waybills, true)) {
            continue;
        }

        $order_row = fill_order_get_details($dbc, $waybill_number);
        if ($order_row === null) {
            continue;
        }

        if ($selected_waybills === null && !fill_order_matches_filters($order_row, $order_filters)) {
            $filtered_out++;
            continue;
        }

        $eligible[] = $waybill_number;
    }

    if ($shuffle) {
        shuffle($eligible);
    }

    $limit = (int) ceil(count($eligible) * $fraction);
    $filled = [];
    $skipped = [];

    foreach ($eligible as $index => $waybill_number) {
        if ($index >= $limit) {
            break;
        }

        $order_row = fill_order_get_details($dbc, $waybill_number);
        if ($order_row === null) {
            $skipped[] = [
                'waybill_number' => $waybill_number,
                'reason' => 'Order not found',
            ];
            continue;
        }

        $available_cars = fill_order_get_available_cars($dbc, $order_row);
        if ($car_filters !== null) {
            $filtered_cars = fill_order_filter_cars($available_cars, $car_filters);
            if (count($available_cars) > 0 && count($filtered_cars) === 0) {
                $skipped[] = [
                    'waybill_number' => $waybill_number,
                    'reason' => fill_order_car_filter_skip_reason($car_filters),
                ];
                continue;
            }
        }
        $selected_car = fill_order_pick_car_for_categories($available_cars, $categories, $car_filters);
        if ($selected_car === null) {
            $skipped[] = [
                'waybill_number' => $waybill_number,
                'reason' => count($available_cars) === 0
                    ? 'No cars available for this order'
                    : 'No eligible cars in selected source categories',
            ];
            continue;
        }

        $result = fill_order_assign_car($dbc, $waybill_number, $selected_car['car_id']);
        if (!$result['success']) {
            $skipped[] = [
                'waybill_number' => $waybill_number,
                'reason' => $result['error'],
            ];
            continue;
        }

        $filled[] = [
            'waybill_number' => $waybill_number,
            'car_id' => $selected_car['car_id'],
            'reporting_marks' => $result['car_reporting_marks'],
            'car_code' => $result['car_code'],
            'category' => $selected_car['category'],
        ];
    }

    return [
        'filled' => count($filled),
        'filled_items' => $filled,
        'skipped' => $skipped,
        'filtered_out' => $filtered_out,
    ];
}

/**
 * Ordered cars with no car_orders row pointing at them are undeliverable
 * ghosts (inflate yard counts, block pool math). Reset them to Empty.
 *
 * @return int number of cars healed
 */
function fill_order_heal_orphan_ordered_cars($dbc)
{
    if (!($dbc instanceof mysqli)) {
        return 0;
    }

    $sql = 'UPDATE cars
            SET status = "Empty"
            WHERE status = "Ordered"
              AND id NOT IN (
                    SELECT car FROM (
                        SELECT car FROM car_orders
                        WHERE car IS NOT NULL AND car != "" AND car != "0"
                    ) AS active_cars
              )';
    if (!mysqli_query($dbc, $sql)) {
        return 0;
    }

    return (int) mysqli_affected_rows($dbc);
}

function fill_order_assign_car($dbc, $waybill_number, $car_id)
{
    $waybill_number = mysqli_real_escape_string($dbc, $waybill_number);
    $car_id = mysqli_real_escape_string($dbc, $car_id);

    // If this waybill already points at another car, clear that car's Ordered
    // status before stealing the slot — otherwise the prior car is left Ordered
    // with no order row (orphan Ordered).
    $prev_rs = mysqli_query(
        $dbc,
        'SELECT car FROM car_orders WHERE waybill_number = "' . $waybill_number . '" LIMIT 1'
    );
    $prev_row = $prev_rs ? mysqli_fetch_assoc($prev_rs) : null;
    $prev_car = (string) ($prev_row['car'] ?? '');
    if ($prev_car !== '' && $prev_car !== '0' && $prev_car !== (string) $car_id) {
        $prev_esc = mysqli_real_escape_string($dbc, $prev_car);
        mysqli_query(
            $dbc,
            'UPDATE cars SET status = "Empty", last_spotted = 0
             WHERE id = "' . $prev_esc . '"
               AND status = "Ordered"'
        );
    }

    $sql = 'UPDATE car_orders SET car = "' . $car_id . '"
            WHERE waybill_number = "' . $waybill_number . '"';

    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Update error: ' . mysqli_error($dbc)];
    }

    $sql = 'SELECT setting_value FROM settings WHERE setting_name = "session_nbr"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $session_nbr = $row['setting_value'];

    $sql = 'SELECT current_location_id FROM cars WHERE id = "' . $car_id . '"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $location = $row['current_location_id'];

    $sql = 'INSERT INTO history(car_id, session_nbr, event_date, event, location)
            VALUES ("' . $car_id . '",
                    "' . $session_nbr . '",
                    "' . date("Y-m-d H:i:s") . '",
                    "Filled car order ' . $waybill_number . '",
                    "' . $location . '")';

    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'History insert error: ' . mysqli_error($dbc)];
    }

    $sql = 'SELECT COUNT(*) as count_at_loading
            FROM cars, shipments, car_orders
            WHERE car_orders.waybill_number = "' . $waybill_number . '"
              AND shipments.id = car_orders.shipment
              AND cars.id = "' . $car_id . '"
              AND cars.current_location_id = shipments.loading_location
              AND cars.status = "Empty"';

    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);

    if ($row['count_at_loading'] > 0) {
        $sql = 'UPDATE cars
                SET status = "Loaded",
                    load_count = load_count + 1
                WHERE id = "' . $car_id . '"';
    } else {
        $sql = 'UPDATE cars
                SET status = "Ordered",
                    load_count = load_count + 1
                WHERE id = "' . $car_id . '"';
    }

    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Car status update error: ' . mysqli_error($dbc)];
    }

    $sql = 'SELECT cars.reporting_marks, car_codes.code as car_code
            FROM cars
            LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
            WHERE cars.id = "' . $car_id . '"';
    $rs = mysqli_query($dbc, $sql);
    $car_row = mysqli_fetch_array($rs);

    return [
        'success' => true,
        'car_reporting_marks' => $car_row['reporting_marks'],
        'car_code' => $car_row['car_code'],
    ];
}

function fill_order_get_unfilled_waybills($dbc)
{
    $sql = 'SELECT DISTINCT waybill_number
            FROM car_orders
            WHERE car = "" OR car IS NULL OR car = "0"
            ORDER BY waybill_number';
    $rs = mysqli_query($dbc, $sql);
    $waybills = [];
    if (!$rs) {
        return $waybills;
    }
    while ($row = mysqli_fetch_array($rs)) {
        $waybills[] = $row['waybill_number'];
    }
    return $waybills;
}

function fill_order_count_unique_available_cars($dbc)
{
    if (!($dbc instanceof mysqli)) {
        return 0;
    }

    $unique_car_ids = [];
    $waybills = fill_order_get_unfilled_waybills($dbc);

    foreach ($waybills as $waybill_number) {
        $order_row = fill_order_get_details($dbc, $waybill_number);
        if ($order_row === null) {
            continue;
        }

        $cars = fill_order_get_available_cars($dbc, $order_row);
        if (!is_array($cars)) {
            continue;
        }
        foreach ($cars as $car) {
            $car_id = (int) ($car['car_id'] ?? 0);
            if ($car_id > 0) {
                $unique_car_ids[$car_id] = true;
            }
        }
    }

    return count($unique_car_ids);
}

function fill_order_is_unfilled($car_value)
{
    return $car_value === '' || $car_value === null || $car_value === '0' || $car_value == 0;
}

function fill_order_cancel_order($dbc, $waybill_number)
{
    $waybill_number = mysqli_real_escape_string($dbc, $waybill_number);

    $sql = 'SELECT car FROM car_orders WHERE waybill_number = "' . $waybill_number . '"';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs || mysqli_num_rows($rs) <= 0) {
        return ['success' => false, 'error' => 'Order not found'];
    }

    $row = mysqli_fetch_array($rs);
    if (!fill_order_is_unfilled($row['car'])) {
        return ['success' => false, 'error' => 'Order already has a car assigned'];
    }

    $sql = 'DELETE FROM car_orders WHERE waybill_number = "' . $waybill_number . '"';
    if (!mysqli_query($dbc, $sql)) {
        return ['success' => false, 'error' => 'Delete error: ' . mysqli_error($dbc)];
    }

    return [
        'success' => true,
        'waybill_number' => $waybill_number,
    ];
}

?>
