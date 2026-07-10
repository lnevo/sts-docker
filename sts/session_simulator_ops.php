<?php
/**
 * Catalog extensions for filtered fill and load/unload dispatch.
 * Loaded by session_runtime.php after warm_start_helpers when present.
 */

function session_sim_auto_fill($dbc, $fraction = 1.0, array $options = [])
{
    require_once __DIR__ . '/fill_order_helpers.php';

    $filled = 0;
    $order_filters = fill_order_parse_filters($options['order_filters'] ?? null);
    $car_filters = $options['car_filters'] ?? null;
    if ($car_filters !== null && !is_array($car_filters)) {
        $car_filters = null;
    }

    $waybills = fill_order_get_unfilled_waybills($dbc);
    if (!empty($order_filters)) {
        $waybills = array_values(array_filter($waybills, function ($waybill_number) use ($dbc, $order_filters) {
            $order_row = fill_order_get_details($dbc, $waybill_number);
            return $order_row !== null && fill_order_matches_filters($order_row, $order_filters);
        }));
    }

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
        $selected_car = fill_order_pick_car_for_categories(
            $available_cars,
            fill_order_valid_categories(),
            $car_filters
        );
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

function session_sim_load_unload_filter_token_match($needle, $station, $code)
{
    if (function_exists('warm_start_load_unload_filter_token_match')) {
        return warm_start_load_unload_filter_token_match($needle, $station, $code);
    }

    $needle = trim((string) $needle);
    if ($needle === '') {
        return true;
    }
    $station = trim((string) $station);
    $code = trim((string) $code);
    foreach ([$code, $station, $station . ' / ' . $code] as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && strcasecmp($candidate, $needle) === 0) {
            return true;
        }
    }
    return false;
}

function session_sim_load_unload_row_matches(array $row, array $filters)
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
        if (!session_sim_load_unload_filter_token_match(
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

function session_sim_load_unload($dbc, $fraction = 1.0, array $filters = [])
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
            if (empty($filters) || session_sim_load_unload_row_matches($row, $filters)) {
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
            if ($row['status'] === 'Unloading'
                && function_exists('warm_start_is_outbound_coke_shipment')
                && function_exists('warm_start_coke_stats')) {
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
