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
$waybills = fill_order_get_unfilled_waybills($dbc);

$filled = [];
$skipped = [];
$filtered_out = 0;

foreach ($waybills as $waybill_number) {
    if ($selected_waybills !== null && !in_array($waybill_number, $selected_waybills, true)) {
        continue;
    }

    $order_row = fill_order_get_details($dbc, $waybill_number);
    if ($order_row === null) {
        $skipped[] = [
            'waybill_number' => $waybill_number,
            'reason' => 'Order not found',
        ];
        continue;
    }

    if ($selected_waybills === null && !fill_order_matches_filters($order_row, $filters)) {
        $filtered_out++;
        continue;
    }

    $available_cars = fill_order_get_available_cars($dbc, $order_row);
    $selected_car = fill_order_pick_car_for_categories($available_cars, $categories);
    if ($selected_car === null) {
        $skipped[] = [
            'waybill_number' => $waybill_number,
            'reason' => 'No eligible cars in selected categories',
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

$remaining = fill_order_get_unfilled_waybills($dbc);
mysqli_close($dbc);

echo json_encode([
    'success' => true,
    'filled_count' => count($filled),
    'skipped_count' => count($skipped),
    'filtered_out_count' => $filtered_out,
    'remaining_count' => count($remaining),
    'all_filled' => count($remaining) === 0,
    'filled' => $filled,
    'skipped' => $skipped,
    'categories' => $categories,
    'filters' => $filters,
]);

?>
