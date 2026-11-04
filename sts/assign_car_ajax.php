<?php
// assign_car_ajax.php - Assign a car to a waybill via AJAX

require 'open_db.php';
require 'fill_order_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || !isset($input['waybill_number']) || !isset($input['car_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

if (!is_array($input) || !is_scalar($input['waybill_number']) || !is_scalar($input['car_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

if (sts_id($input['car_id']) <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid car_id']);
    exit;
}

$dbc = open_db();
$result = fill_order_assign_car($dbc, $input['waybill_number'], $input['car_id']);
mysqli_close($dbc);

if (!$result['success']) {
    http_response_code(500);
    echo json_encode(['error' => $result['error']]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Car assigned successfully',
    'car_reporting_marks' => $result['car_reporting_marks'],
    'car_code' => $result['car_code']
]);

?>
