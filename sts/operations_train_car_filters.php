<?php
/**
 * Car filters for Pick Up / Set Out workflow steps — mirrors operations_station_filters.inc.php.
 */

function operational_steps_build_station_location_options(array $locations)
{
    $by_station = [];
    foreach ($locations as $loc) {
        $station = trim((string) ($loc['station'] ?? ''));
        $code = trim((string) ($loc['code'] ?? ''));
        if ($station === '' || $code === '') {
            continue;
        }
        if (!isset($by_station[$station])) {
            $by_station[$station] = [];
        }
        $by_station[$station][$code] = $station . ' - ' . $code;
    }
    ksort($by_station);
    $options = [];
    foreach ($by_station as $station => $codes) {
        $options[] = ['value' => 'station::' . $station, 'label' => $station];
        ksort($codes);
        foreach ($codes as $label) {
            $options[] = ['value' => 'location::' . $label, 'label' => $label];
        }
    }
    return $options;
}

function operational_steps_train_car_filter_fields()
{
    return [
        [
            'key' => 'pickup_location',
            'label' => 'Pickup',
            'type' => 'station_location',
            'options_from' => 'station_locations',
            'default' => '',
        ],
        [
            'key' => 'reporting_marks',
            'label' => 'Marks',
            'type' => 'text',
            'default' => '',
        ],
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
            'options' => ['', 'Ordered', 'Loaded', 'Loading', 'Unloading', 'Empty'],
            'default' => '',
        ],
        [
            'key' => 'consignment',
            'label' => 'Consignment',
            'type' => 'commodity',
            'options_from' => 'commodities',
            'allow_custom' => true,
            'default' => '',
        ],
        [
            'key' => 'final_destination',
            'label' => 'Final dest.',
            'type' => 'station_location',
            'options_from' => 'station_locations',
            'default' => '',
        ],
        [
            'key' => 'loading_location',
            'label' => 'Loading',
            'type' => 'station_location',
            'options_from' => 'station_locations',
            'default' => '',
        ],
        [
            'key' => 'unloading_location',
            'label' => 'Unloading',
            'type' => 'station_location',
            'options_from' => 'station_locations',
            'default' => '',
        ],
    ];
}

function operational_steps_train_car_default_filters()
{
    $filters = [];
    foreach (operational_steps_train_car_filter_fields() as $field) {
        $filters[$field['key']] = $field['default'] ?? '';
    }
    return $filters;
}

function operational_steps_normalize_train_car_filters(array $params)
{
    $filters = is_array($params['car_filters'] ?? null) ? $params['car_filters'] : [];
    return array_merge(operational_steps_train_car_default_filters(), $filters);
}

function operational_steps_train_car_filters_active(array $filters)
{
    foreach (operational_steps_train_car_default_filters() as $key => $default) {
        if (trim((string) ($filters[$key] ?? '')) !== trim((string) $default)) {
            return true;
        }
    }
    return false;
}

function operational_steps_compile_train_car_filters_gui(array $filters)
{
    $filters = array_filter(operational_steps_normalize_train_car_filters(['car_filters' => $filters]));
    if ($filters === []) {
        return '';
    }
    $labels = [
        'pickup_location' => 'pickup',
        'reporting_marks' => 'marks',
        'car_code' => 'car',
        'status' => 'status',
        'consignment' => 'consignment',
        'final_destination' => 'final',
        'loading_location' => 'load',
        'unloading_location' => 'unload',
    ];
    $parts = [];
    foreach ($filters as $key => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $parts[] = ($labels[$key] ?? $key) . '=' . $value;
    }
    return $parts === [] ? '' : implode('; ', $parts);
}

function operational_steps_train_car_station_location_match($needle, $station, $location_label)
{
    $needle = trim((string) $needle);
    if ($needle === '') {
        return true;
    }
    if (strpos($needle, 'station::') === 0) {
        return strcasecmp(trim((string) $station), substr($needle, 9)) === 0;
    }
    if (strpos($needle, 'location::') === 0) {
        return strcasecmp(trim((string) $location_label), substr($needle, 10)) === 0;
    }
    $station = trim((string) $station);
    $location_label = trim((string) $location_label);
    if ($station !== '' && strcasecmp($station, $needle) === 0) {
        return true;
    }
    if ($location_label !== '' && strcasecmp($location_label, $needle) === 0) {
        return true;
    }
    $code = $location_label;
    $hyphen = strpos($location_label, ' - ');
    if ($hyphen !== false) {
        $code = trim(substr($location_label, $hyphen + 3));
    }
    return warm_start_load_unload_filter_token_match($needle, $station, $code);
}

function operational_steps_train_car_row_matches(array $row, array $filters)
{
    $filters = operational_steps_normalize_train_car_filters(['car_filters' => $filters]);
    $checks = [
        'pickup_location' => ['pickup_station', 'pickup_location'],
        'final_destination' => ['final_destination_station', 'final_destination_location'],
        'loading_location' => ['loading_station', 'loading_location'],
        'unloading_location' => ['unloading_station', 'unloading_location'],
    ];
    foreach ($checks as $filter_key => $cols) {
        $needle = trim((string) ($filters[$filter_key] ?? ''));
        if ($needle === '') {
            continue;
        }
        if (!operational_steps_train_car_station_location_match(
            $needle,
            $row[$cols[0]] ?? '',
            $row[$cols[1]] ?? ''
        )) {
            return false;
        }
    }
    $marks = trim((string) ($filters['reporting_marks'] ?? ''));
    if ($marks !== '') {
        $hay = strtolower((string) ($row['reporting_marks'] ?? ''));
        if (strpos($hay, strtolower($marks)) === false) {
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
    if (($filters['consignment'] ?? '') !== '') {
        $consignment = trim((string) ($row['consignment'] ?? ''));
        $needle = trim((string) $filters['consignment']);
        if (strcasecmp($needle, 'Non-Revenue') === 0) {
            if (!$row['is_non_revenue']) {
                return false;
            }
        } elseif (strcasecmp($consignment, $needle) !== 0) {
            return false;
        }
    }
    return true;
}

function operational_steps_train_car_filter_row($dbc, $car_id, $job_name = '')
{
    $car_id = (int) $car_id;
    if ($car_id <= 0) {
        return [];
    }
    $job_name = trim((string) $job_name);
    $job_esc = mysqli_real_escape_string($dbc, $job_name);
    $pickup_station_sql = 'NULL';
    $pickup_location_sql = 'NULL';
    if ($job_name !== '') {
        $pickup_station_sql = '(SELECT pickup_sta.station
            FROM history pickup_history
            LEFT JOIN locations pickup_loc ON pickup_loc.id = pickup_history.location
            LEFT JOIN routing pickup_sta ON pickup_sta.id = pickup_loc.station
            WHERE pickup_history.car_id = cars.id
              AND pickup_history.event = "Picked up by Job ' . $job_esc . '"
            ORDER BY pickup_history.event_date DESC
            LIMIT 1)';
        $pickup_location_sql = '(SELECT pickup_loc.code
            FROM history pickup_history
            LEFT JOIN locations pickup_loc ON pickup_loc.id = pickup_history.location
            WHERE pickup_history.car_id = cars.id
              AND pickup_history.event = "Picked up by Job ' . $job_esc . '"
            ORDER BY pickup_history.event_date DESC
            LIMIT 1)';
    }

    $sql = 'SELECT cars.reporting_marks,
                   cars.status,
                   car_orders.waybill_number,
                   car_orders.shipment AS shipment_id,
                   sta01.station AS current_station,
                   loc01.code AS current_location,
                   sta02.station AS loading_station,
                   loc02.code AS loading_location_code,
                   sta03.station AS unloading_station,
                   loc03.code AS unloading_location_code,
                   commodities.code AS consignment,
                   car_codes.code AS car_code,
                   ' . $pickup_station_sql . ' AS pickup_station,
                   ' . $pickup_location_sql . ' AS pickup_location_code
            FROM cars
            LEFT JOIN car_orders ON car_orders.car = cars.id
            LEFT JOIN shipments ON shipments.id = car_orders.shipment
            LEFT JOIN locations loc01 ON loc01.id = cars.current_location_id
            LEFT JOIN locations loc02 ON loc02.id = shipments.loading_location
            LEFT JOIN locations loc03 ON loc03.id = shipments.unloading_location
            LEFT JOIN routing sta01 ON sta01.id = loc01.station
            LEFT JOIN routing sta02 ON sta02.id = loc02.station
            LEFT JOIN routing sta03 ON sta03.id = loc03.station
            LEFT JOIN commodities ON commodities.id = shipments.consignment
            LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
            WHERE cars.id = "' . $car_id . '"
            LIMIT 1';
    $rs = mysqli_query($dbc, $sql);
    $row = $rs ? mysqli_fetch_array($rs) : null;
    if (!$row) {
        return [];
    }

    $is_non_revenue = isset($row['waybill_number'][4]) && $row['waybill_number'][4] === 'E';
    $loading_station = $is_non_revenue ? '' : (string) ($row['loading_station'] ?? '');
    $loading_location = $loading_station !== '' && !empty($row['loading_location_code'])
        ? $loading_station . ' - ' . $row['loading_location_code']
        : '';
    $unloading_station = (string) ($row['unloading_station'] ?? '');
    $unloading_location = $unloading_station !== '' && !empty($row['unloading_location_code'])
        ? $unloading_station . ' - ' . $row['unloading_location_code']
        : '';

    $pickup_station = (string) ($row['pickup_station'] ?? '');
    if ($pickup_station === '' && !empty($row['current_station'])) {
        $pickup_station = (string) $row['current_station'];
    }
    $pickup_code = (string) ($row['pickup_location_code'] ?? '');
    if ($pickup_code === '' && !empty($row['current_location'])) {
        $pickup_code = (string) $row['current_location'];
    }
    $pickup_location = $pickup_station !== '' && $pickup_code !== ''
        ? $pickup_station . ' - ' . $pickup_code
        : '';

    if ($is_non_revenue && !empty($row['shipment_id'])) {
        $nr_rs = mysqli_query(
            $dbc,
            'SELECT loc.code, routing.station
             FROM locations loc
             LEFT JOIN routing ON routing.id = loc.station
             WHERE loc.id = "' . (int) $row['shipment_id'] . '"
             LIMIT 1'
        );
        $nr = $nr_rs ? mysqli_fetch_array($nr_rs) : null;
        if ($nr) {
            $unloading_station = (string) ($nr['station'] ?? '');
            $unloading_location = $unloading_station . ' - ' . ($nr['code'] ?? '');
        }
    }

    $final_dest_station = '';
    $final_dest_location = '';
    if ($is_non_revenue) {
        $final_dest_station = $unloading_station;
        $final_dest_location = $unloading_location;
    } elseif (($row['status'] ?? '') === 'Ordered') {
        $final_dest_station = $loading_station;
        $final_dest_location = $loading_location;
    } elseif (($row['status'] ?? '') === 'Loaded') {
        $final_dest_station = $unloading_station;
        $final_dest_location = $unloading_location;
    }

    return [
        'is_non_revenue' => $is_non_revenue,
        'reporting_marks' => (string) ($row['reporting_marks'] ?? ''),
        'car_code' => (string) ($row['car_code'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'consignment' => $is_non_revenue ? 'Non-Revenue' : (string) ($row['consignment'] ?? ''),
        'pickup_station' => $pickup_station,
        'pickup_location' => $pickup_location,
        'loading_station' => $loading_station,
        'loading_location' => $loading_location,
        'unloading_station' => $unloading_station,
        'unloading_location' => $unloading_location,
        'final_destination_station' => $final_dest_station,
        'final_destination_location' => $final_dest_location,
    ];
}

function operational_steps_train_car_passes_filters($dbc, $car_id, $job_name, array $filters)
{
    if (!operational_steps_train_car_filters_active($filters)) {
        return true;
    }
    $row = operational_steps_train_car_filter_row($dbc, (int) $car_id, $job_name);
    return $row !== [] && operational_steps_train_car_row_matches($row, $filters);
}
