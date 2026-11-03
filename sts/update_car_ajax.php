<?php
// update_car_ajax.php - Handles AJAX updates for car attributes

require 'open_db.php';

header('Content-Type: application/json');

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

// Get the JSON data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['car_id']) || !isset($input['field']) || !isset($input['value'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

if (!is_scalar($input['car_id']) || !is_scalar($input['field']) || !is_scalar($input['value'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

$car_id = (string) $input['car_id'];
$field = (string) $input['field'];
$value = (string) $input['value'];

// Whitelist of allowed fields to update
$allowed_fields = [
    'reporting_marks',
    'car_code_id',
    'current_location_id',
    'position',
    'status',
    'handled_by_job_id',
    'remarks',
    'home_location',
    'RFID_code'
];

// Validate field name
if (!in_array($field, $allowed_fields, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid field']);
    exit;
}

$dbc = open_db();

// Build and execute update query. $field comes from the whitelist above (a
// column name cannot be a bound parameter); values are bound.
$car_id_int = sts_id($car_id);

if (sts_q($dbc, "UPDATE cars SET `$field` = ? WHERE id = ?", [$value, $car_id_int])) {
    // If status changed to Empty or Unavailable, apply db_edit logic
    if ($field === 'status' && ($value === 'Empty' || $value === 'Unavailable')) {
        // Remove from any job/train
        sts_q($dbc, "UPDATE cars SET handled_by_job_id = 0 WHERE id = ?", [$car_id_int]);

        // Delete any car orders linked to this car
        sts_q($dbc, "DELETE FROM car_orders WHERE car = ?", [$car_id_int]);
    }

    // Get the display value for the field
    $displayValue = $value;
    $formatAsHtml = false;
    if ($field === 'car_code_id') {
        $rs_display = sts_q($dbc, "SELECT code FROM car_codes WHERE id = ?", [sts_id($value)]);
        if ($rs_display && ($row_display = mysqli_fetch_array($rs_display))) {
            $displayValue = $row_display['code'];
        }
    } elseif ($field === 'current_location_id') {
        if (sts_id($value) > 0) {
            $rs_display = sts_q($dbc, "SELECT locations.code, routing.station FROM locations LEFT JOIN routing ON locations.station = routing.id WHERE locations.id = ?", [sts_id($value)]);
            if ($rs_display && ($row_display = mysqli_fetch_array($rs_display))) {
                $station = $row_display['station'] ?: 'Unknown';
                $displayValue = "<u>" . sts_html($station) . "</u><br />" . sts_html($row_display['code']);
                $formatAsHtml = true;
            }
        }
    } elseif ($field === 'home_location') {
        $rs_display = sts_q($dbc, "SELECT locations.code, routing.station FROM locations LEFT JOIN routing ON locations.station = routing.id WHERE locations.id = ?", [sts_id($value)]);
        if ($rs_display && ($row_display = mysqli_fetch_array($rs_display))) {
            $station = $row_display['station'] ?: 'Unknown';
            $displayValue = "<u>" . sts_html($station) . "</u><br />" . sts_html($row_display['code']);
            $formatAsHtml = true;
        }
    } elseif ($field === 'handled_by_job_id') {
        if (sts_id($value) > 0) {
            $rs_display = sts_q($dbc, "SELECT name FROM jobs WHERE id = ?", [sts_id($value)]);
            if ($rs_display && ($row_display = mysqli_fetch_array($rs_display))) {
                $displayValue = $row_display['name'];
            }
        } else {
            $displayValue = '';
        }
    }

    // Return the updated value with display text
    echo json_encode([
        'success' => true,
        'field' => $field,
        'value' => $value,
        'displayValue' => $displayValue,
        'formatAsHtml' => $formatAsHtml,
        'message' => 'Field updated successfully'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . sts_err($dbc)
    ]);
}

mysqli_close($dbc);
?>
