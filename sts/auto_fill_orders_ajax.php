<?php
// auto_fill_orders_ajax.php — assign eligible cars to open car orders

require 'open_db.php';
require 'fill_order_helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

$categories = fill_order_parse_categories($_POST['categories'] ?? null);
$filters = fill_order_parse_filters($_POST['filters'] ?? null);
$car_filters = fill_order_parse_car_filters($_POST['car_filters'] ?? null);
$car_filters['categories'] = $categories;

$selected_waybills = null;
if (isset($_POST['waybills']) && is_array($_POST['waybills'])) {
    $selected_waybills = array_values(array_filter(array_map('trim', $_POST['waybills'])));
    if (count($selected_waybills) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'No orders selected']);
        exit;
    }
}

$dbc = open_db();
$result = fill_order_auto_assign($dbc, [
    'waybills' => $selected_waybills,
    'order_filters' => $filters,
    'car_filters' => $car_filters,
    'fraction' => 1.0,
    'shuffle' => false,
]);

$remaining = fill_order_get_unfilled_waybills($dbc);
mysqli_close($dbc);

echo json_encode([
    'success' => true,
    'filled_count' => $result['filled'],
    'skipped_count' => count($result['skipped']),
    'filtered_out_count' => $result['filtered_out'],
    'remaining_count' => count($remaining),
    'all_filled' => count($remaining) === 0,
    'filled' => $result['filled_items'],
    'skipped' => $result['skipped'],
    'categories' => $categories,
    'filters' => $filters,
    'car_filters' => $car_filters,
]);

?>
