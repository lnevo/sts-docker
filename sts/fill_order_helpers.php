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

    if (!empty($input['current_station'])) {
        $filters['current_station'] = trim((string) $input['current_station']);
    }

    if (!empty($input['current_location'])) {
        $filters['current_location'] = trim((string) $input['current_location']);
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

function fill_order_assign_car($dbc, $waybill_number, $car_id)
{
    $waybill_number = mysqli_real_escape_string($dbc, $waybill_number);
    $car_id = mysqli_real_escape_string($dbc, $car_id);

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
