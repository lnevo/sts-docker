<?php
// get_car_data_ajax.php - Fetch updated car data after status change

require 'open_db.php';
require_once 'sql_safe.php';

header('Content-Type: application/json');

if (!isset($_GET['car_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing car_id']);
    exit;
}

if (!is_scalar($_GET['car_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid car_id']);
    exit;
}

$car_id = (string) sts_id($_GET['car_id']);
$dbc = open_db();

$sql = 'SELECT cars.handled_by_job_id,
               commodities.code as consignment,
               shipments.loading_location as loading_location_id,
               shipments.unloading_location as unloading_location_id,
               jobs.name as job_name,
               loc02.code as loading_location,
               loc02.station as loading_station_id,
               loc03.code as unloading_location,
               loc03.station as unloading_station_id,
               sta02.station as loading_station,
               sta03.station as unloading_station,
               cars.status as status
        FROM cars
        LEFT JOIN car_orders ON cars.id = car_orders.car
        LEFT JOIN shipments ON car_orders.shipment = shipments.id
        LEFT JOIN commodities ON commodities.id = shipments.consignment
        LEFT JOIN jobs ON cars.handled_by_job_id = jobs.id
        LEFT JOIN locations loc02 ON shipments.loading_location = loc02.id
        LEFT JOIN locations loc03 ON shipments.unloading_location = loc03.id
        LEFT JOIN routing sta02 ON sta02.id = loc02.station
        LEFT JOIN routing sta03 ON sta03.id = loc03.station
        WHERE cars.id = ?';

$rs = sts_q($dbc, $sql, [$car_id]);
if ($rs instanceof mysqli_result && $row = mysqli_fetch_array($rs)) {
    foreach ($row as $k => $v) {
        if ($v !== null) {
            $row[$k] = (string) $v;
        }
    }
    $result = [
        'handled_by_job_id' => $row['handled_by_job_id'],
        'handled_by_job_name' => $row['job_name'] ?: '',
        'consignment' => $row['consignment'] ?: '',
        'loading_location' => $row['loading_location'] ?: '',
        'loading_station' => $row['loading_station'] ?: '',
        'unloading_location' => $row['unloading_location'] ?: '',
        'unloading_station' => $row['unloading_station'] ?: '',
        'status' => $row['status']
    ];

    echo json_encode($result);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Car not found']);
}

mysqli_close($dbc);
?>
