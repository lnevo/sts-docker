<?php
// cancel_car_order_ajax.php — cancel an unfilled car order

require 'open_db.php';
require 'fill_order_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input) || !isset($input['waybill_number']) || !is_scalar($input['waybill_number']) || trim((string) $input['waybill_number']) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing waybill_number']);
    exit;
}

$dbc = open_db();
$result = fill_order_cancel_order($dbc, $input['waybill_number']);
$remaining = fill_order_get_unfilled_waybills($dbc);
mysqli_close($dbc);

if (!$result['success']) {
    http_response_code(500);
    echo json_encode(['error' => $result['error']]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Car order canceled',
    'waybill_number' => $result['waybill_number'],
    'remaining_count' => count($remaining),
    'all_filled' => count($remaining) === 0,
]);

?>
