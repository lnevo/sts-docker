<?php
// get_available_cars_ajax.php - Fetch available cars for a car order (waybill)

require 'open_db.php';
require 'fill_order_helpers.php';

header('Content-Type: application/json');

if (!isset($_GET['waybill_number'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing waybill_number']);
    exit;
}

$waybill_number = $_GET['waybill_number'];
$dbc = open_db();

$order_row = fill_order_get_details($dbc, $waybill_number);
if ($order_row === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Waybill not found']);
    exit;
}

$all_cars = fill_order_get_available_cars($dbc, $order_row);
$car_filters = fill_order_parse_car_filters($_GET);
$filtered_cars = fill_order_filter_cars($all_cars, $car_filters);
$counts = fill_order_count_cars_by_category($filtered_cars);

mysqli_close($dbc);

echo json_encode([
    'shipment' => $order_row['shipment'],
    'description' => $order_row['description'],
    'consignment' => $order_row['consignment'],
    'car_code' => $order_row['car_code'],
    'loading_station' => $order_row['loading_station'],
    'loading_location' => $order_row['loading_location'],
    'unloading_station' => $order_row['unloading_station'],
    'unloading_location' => $order_row['unloading_location'],
    'remarks' => $order_row['remarks'],
    'total_cars_found' => count($filtered_cars),
    'total_cars_unfiltered' => count($all_cars),
    'pool_count' => $counts['pool'],
    'station_count' => $counts['station'],
    'priority_count' => $counts['priority'],
    'system_count' => $counts['system'],
    'cars' => $filtered_cars,
    'car_filters' => $car_filters,
]);

?>
