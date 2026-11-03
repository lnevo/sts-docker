<?php
/**
 * Wagon Endpoint Handler
 * Routes wagon-related requests to specific operations
 */

require_once dirname(dirname(__DIR__)) . '/open_db.php';
require_once dirname(__DIR__) . '/helpers/response.php';
require_once dirname(__DIR__) . '/helpers/validation.php';

function handleWagonEndpoint($method, $pathParts) {
    global $connection;
    $connection = open_db();

    if (empty($pathParts)) {
        return jsonError('Invalid wagon endpoint', 400);
    }

    $operation = array_shift($pathParts);

    switch ($operation) {
        case 'cargo':
            if ($method !== 'GET') {
                return jsonError('Method not allowed', 405);
            }
            handleCargoLookup($pathParts);
            break;

        case 'location':
            if ($method !== 'GET') {
                return jsonError('Method not allowed', 405);
            }
            handleLocationLookup($pathParts);
            break;

        case 'unload':
            if ($method !== 'POST') {
                return jsonError('Method not allowed', 405);
            }
            handleWagonUnload();
            break;

        case 'load':
            if ($method !== 'POST') {
                return jsonError('Method not allowed', 405);
            }
            handleWagonLoad();
            break;

        case 'reposition':
            if ($method !== 'POST') {
                return jsonError('Method not allowed', 405);
            }
            handleWagonReposition();
            break;

        default:
            return jsonError('Unknown wagon operation', 400);
    }
}

/**
 * GET /wagon/cargo/id/:tag_name
 * Returns detailed cargo information for a car (replicates scan_car.php)
 * Searches by reporting marks or RFID code
 */
function handleCargoLookup($pathParts) {
    global $connection;

    if (count($pathParts) < 2 || $pathParts[0] !== 'id') {
        return jsonError('Invalid cargo lookup format', 400);
    }

    $tagName = $pathParts[1];

    // Check if incoming code is a car ID delimited by "-" (convert to reporting marks)
    if (substr($tagName, 0, 1) === '-' && substr($tagName, -1, 1)) {
        $carId = substr($tagName, 1, strlen($tagName) - 2);
        $query = 'SELECT reporting_marks FROM cars WHERE id = ?';
        $stmt = $connection->prepare($query);
        $stmt->bind_param('i', $carId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $tagName = $row['reporting_marks'];
        } else {
            return jsonError('Car not found', 404);
        }
    }

    // Replicate scan_car.php query exactly
    $query = "SELECT cars.id, cars.reporting_marks, cars.car_code_id, cars.current_location_id,
                     cars.status, cars.RFID_code, cars.remarks, cars.load_count,
                     car_orders.waybill_number,
                     shipments.code as shipment, shipments.consignment as consignment_id,
                     shipments.loading_location as loading_location_id,
                     shipments.unloading_location as unloading_location_id,
                     car_codes.code as car_code,
                     commodities.code as consignment,
                     loc01.code as current_location,
                     loc02.code as loading_location,
                     loc03.code as unloading_location,
                     sta01.station as current_station,
                     sta02.station as loading_station,
                     sta03.station as unloading_station
              FROM cars
              LEFT JOIN car_orders ON cars.id = car_orders.car
              LEFT JOIN shipments ON shipments.id = car_orders.shipment
              LEFT JOIN car_codes ON cars.car_code_id = car_codes.id
              LEFT JOIN commodities ON shipments.consignment = commodities.id
              LEFT JOIN locations loc01 ON cars.current_location_id = loc01.id
              LEFT JOIN locations loc02 ON shipments.loading_location = loc02.id
              LEFT JOIN locations loc03 ON shipments.unloading_location = loc03.id
              LEFT JOIN routing sta01 ON sta01.id = loc01.station
              LEFT JOIN routing sta02 ON sta02.id = loc02.station
              LEFT JOIN routing sta03 ON sta03.id = loc03.station
              WHERE (cars.reporting_marks = ? OR cars.RFID_code = ?)
              LIMIT 1";

    $stmt = $connection->prepare($query);
    if (!$stmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $tagNameUpper = strtoupper($tagName);
    $stmt->bind_param('ss', $tagNameUpper, $tagName);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return jsonError('Car not found', 404);
    }

    $car = $result->fetch_assoc();

    return jsonResponse([
        'reportingMarks' => $car['reporting_marks'],
        'id' => strval($car['id']),
        'carCode' => $car['car_code'],
        'status' => $car['status'],
        'shipmentCode' => $car['shipment'] ?? '',
        'loadingStation' => $car['loading_station'] ?? '',
        'loadingLocation' => $car['loading_location'] ?? '',
        'unloadingStation' => $car['unloading_station'] ?? '',
        'unloadingLocation' => $car['unloading_location'] ?? ''
    ], 200);
}

/**
 * GET /wagon/location/:location_name
 * Returns location details and all cars at that location (replicates scan_location.php)
 */
function handleLocationLookup($pathParts) {
    global $connection;

    $locationName = !empty($pathParts) ? urldecode($pathParts[0]) : '';

    if (empty($locationName)) {
        return jsonError('Location name is required', 400);
    }

    // Check if incoming code is a location ID delimited by "%" (convert to code)
    if (substr($locationName, 0, 1) === '%' && substr($locationName, -1, 1) === '%') {
        $locationId = substr($locationName, 1, strlen($locationName) - 2);
        $query = 'SELECT code FROM locations WHERE id = ?';
        $stmt = $connection->prepare($query);
        $stmt->bind_param('i', $locationId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $locationName = $row['code'];
        } else {
            return jsonError('Location not found', 404);
        }
    }

    // Get location details (replicates scan_location.php)
    $query = "SELECT locations.id, locations.code, locations.station,
                     locations.track, locations.spot, locations.remarks,
                     routing.station
              FROM locations
              LEFT JOIN routing ON locations.station = routing.id
              WHERE locations.code = ?
              LIMIT 1";

    $stmt = $connection->prepare($query);
    if (!$stmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $stmt->bind_param('s', $locationName);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return jsonError('Location not found', 404);
    }

    $location = $result->fetch_assoc();
    $locationId = $location['id'];

    // Find all cars at this location
    $carsQuery = "SELECT cars.reporting_marks, cars.car_code_id,
                         cars.status, car_codes.code as car_code
                  FROM cars
                  LEFT JOIN car_codes ON cars.car_code_id = car_codes.id
                  WHERE cars.current_location_id = ?
                  ORDER BY cars.position, cars.reporting_marks";

    $carsStmt = $connection->prepare($carsQuery);
    if (!$carsStmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $carsStmt->bind_param('i', $locationId);
    $carsStmt->execute();
    $carsResult = $carsStmt->get_result();

    $cars = [];
    while ($row = $carsResult->fetch_assoc()) {
        $cars[] = [
            'reportingMarks' => $row['reporting_marks'],
            'carType' => $row['car_code'],
            'status' => $row['status']
        ];
    }

    return jsonResponse($cars, 200);
}

/**
 * POST /wagon/unload
 * Complete unloading of a wagon/car (replicates load_unload.php POST)
 * Car must have status Loading or Unloading to appear on load_unload page
 * Unloading: status becomes Empty, car_orders are deleted
 * Required fields: wagonId, reportingMarks, status (Loading or Unloading)
 */
function handleWagonUnload() {
    global $connection;

    $payload = getJsonPayload();

    // Validate required fields
    $validation = validateRequired($payload, ['wagonId', 'reportingMarks']);
    if (!$validation['valid']) {
        return jsonError($validation['message'], 400);
    }

    $wagonId = intval($payload['wagonId']);
    $reportingMarks = $payload['reportingMarks'];

    // Verify car exists with correct reporting marks AND has valid status for load_unload page
    $query = "SELECT id, status FROM cars WHERE id = ? AND reporting_marks = ?
              AND (status = 'Loading' OR status = 'Unloading')";
    $stmt = $connection->prepare($query);
    if (!$stmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $stmt->bind_param('is', $wagonId, $reportingMarks);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return jsonError('Car not found or does not have Loading/Unloading status', 404);
    }

    $car = $result->fetch_assoc();

    // Determine new status based on current status
    if ($car['status'] === 'Loading') {
        $newStatus = 'Loaded';
    } else { // Unloading
        $newStatus = 'Empty';

        // Delete car orders for unloading cars
        $deleteQuery = "DELETE FROM car_orders WHERE car = ?";
        $deleteStmt = $connection->prepare($deleteQuery);
        if (!$deleteStmt) {
            return jsonError('Database error: ' . $connection->error, 500);
        }

        $deleteStmt->bind_param('i', $wagonId);
        if (!$deleteStmt->execute()) {
            return jsonError('Failed to delete car orders', 500);
        }
    }

    // Update car status and reset last_spotted
    $updateQuery = "UPDATE cars SET status = ?, last_spotted = 0 WHERE id = ?";
    $updateStmt = $connection->prepare($updateQuery);
    if (!$updateStmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $updateStmt->bind_param('si', $newStatus, $wagonId);
    if (!$updateStmt->execute()) {
        return jsonError('Failed to update car status', 500);
    }

    return jsonResponse([
        'message' => 'Wagon unload completed',
        'carId' => $wagonId,
        'reportingMarks' => $reportingMarks,
        'newStatus' => $newStatus
    ], 200);
}

/**
 * POST /wagon/load
 * Complete loading of a wagon/car (same validation as unload)
 * Same business logic as unload - status transitions apply
 * Required fields: wagonId, reportingMarks, status (Loading or Unloading)
 */
function handleWagonLoad() {
    global $connection;

    $payload = getJsonPayload();

    // Validate required fields
    $validation = validateRequired($payload, ['wagonId', 'reportingMarks']);
    if (!$validation['valid']) {
        return jsonError($validation['message'], 400);
    }

    $wagonId = intval($payload['wagonId']);
    $reportingMarks = $payload['reportingMarks'];

    // Verify car exists with correct reporting marks AND has valid status for load_unload page
    $query = "SELECT id, status FROM cars WHERE id = ? AND reporting_marks = ?
              AND (status = 'Loading' OR status = 'Unloading')";
    $stmt = $connection->prepare($query);
    if (!$stmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $stmt->bind_param('is', $wagonId, $reportingMarks);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return jsonError('Car not found or does not have Loading/Unloading status', 404);
    }

    $car = $result->fetch_assoc();

    // Determine new status based on current status
    if ($car['status'] === 'Loading') {
        $newStatus = 'Loaded';
    } else { // Unloading
        $newStatus = 'Empty';

        // Delete car orders for unloading cars
        $deleteQuery = "DELETE FROM car_orders WHERE car = ?";
        $deleteStmt = $connection->prepare($deleteQuery);
        if (!$deleteStmt) {
            return jsonError('Database error: ' . $connection->error, 500);
        }

        $deleteStmt->bind_param('i', $wagonId);
        if (!$deleteStmt->execute()) {
            return jsonError('Failed to delete car orders', 500);
        }
    }

    // Update car status and reset last_spotted
    $updateQuery = "UPDATE cars SET status = ?, last_spotted = 0 WHERE id = ?";
    $updateStmt = $connection->prepare($updateQuery);
    if (!$updateStmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $updateStmt->bind_param('si', $newStatus, $wagonId);
    if (!$updateStmt->execute()) {
        return jsonError('Failed to update car status', 500);
    }

    return jsonResponse([
        'message' => 'Wagon load completed',
        'carId' => $wagonId,
        'reportingMarks' => $reportingMarks,
        'newStatus' => $newStatus
    ], 200);
}

/**
 * POST /wagon/reposition
 * Reposition an empty car to a new location (replicates reposition.php POST)
 * Car must be status=Empty AND have no existing car_orders to appear on reposition page
 * Creates E-series empty car waybill, sets status to Ordered, inserts history
 * Required fields: wagonId, reportingMarks, locationId
 */
function handleWagonReposition() {
    global $connection;

    $payload = getJsonPayload();

    // Validate required fields
    $validation = validateRequired($payload, ['wagonId', 'reportingMarks', 'locationId']);
    if (!$validation['valid']) {
        return jsonError($validation['message'], 400);
    }

    $wagonId = intval($payload['wagonId']);
    $reportingMarks = $payload['reportingMarks'];
    $locationId = intval($payload['locationId']);

    // Verify car exists with correct reporting marks AND is Empty with no existing orders
    $query = "SELECT c.id, c.current_location_id FROM cars c
              WHERE c.id = ? AND c.reporting_marks = ? AND c.status = 'Empty'
              AND NOT EXISTS (SELECT 1 FROM car_orders WHERE car = c.id)";
    $stmt = $connection->prepare($query);
    if (!$stmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $stmt->bind_param('is', $wagonId, $reportingMarks);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return jsonError('Car not available for repositioning (must be Empty with no orders)', 404);
    }

    $car = $result->fetch_assoc();
    $currentLocationId = $car['current_location_id'];

    // Verify destination location exists
    $locQuery = "SELECT id, code FROM locations WHERE id = ?";
    $locStmt = $connection->prepare($locQuery);
    if (!$locStmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $locStmt->bind_param('i', $locationId);
    $locStmt->execute();
    $locResult = $locStmt->get_result();

    if ($locResult->num_rows === 0) {
        return jsonError('Destination location not found', 404);
    }

    $destLocation = $locResult->fetch_assoc();
    $destinationCode = $destLocation['code'];

    // Get current session number
    $sessionQuery = "SELECT setting_value FROM settings WHERE setting_name = 'session_nbr'";
    $sessionStmt = $connection->prepare($sessionQuery);
    if (!$sessionStmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $sessionStmt->execute();
    $sessionResult = $sessionStmt->get_result();
    $sessionNumber = 0;
    if ($sessionResult->num_rows > 0) {
        $row = $sessionResult->fetch_assoc();
        $sessionNumber = intval($row['setting_value']);
    }

    // Get next E-series waybill number
    $waybillPrefix = str_pad($sessionNumber, 3, '0', STR_PAD_LEFT) . '-E';
    $waybillLike = $waybillPrefix . '%';
    $waybillQuery = "SELECT MAX(CAST(SUBSTRING(waybill_number, " . (strlen($waybillPrefix) + 1) . ") AS UNSIGNED)) AS n
                     FROM car_orders
                     WHERE waybill_number LIKE ?
                     AND SUBSTRING(waybill_number, " . (strlen($waybillPrefix) + 1) . ") REGEXP '^[0-9]+$'";
    $waybillStmt = $connection->prepare($waybillQuery);
    if (!$waybillStmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $waybillStmt->bind_param('s', $waybillLike);
    $waybillStmt->execute();
    $waybillResult = $waybillStmt->get_result();

    $waybillCounter = 1;
    if ($waybillResult->num_rows > 0) {
        $row = $waybillResult->fetch_assoc();
        $waybillCounter = intval($row['n']) + 1;
    }

    $waybillNumber = str_pad($sessionNumber, 3, '0', STR_PAD_LEFT) . '-E' . str_pad($waybillCounter, 2, '0', STR_PAD_LEFT);

    // Insert empty car waybill (shipment field holds destination location ID for reposition orders)
    $insertQuery = "INSERT INTO car_orders (waybill_number, shipment, car) VALUES (?, ?, ?)";
    $insertStmt = $connection->prepare($insertQuery);
    if (!$insertStmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $insertStmt->bind_param('sii', $waybillNumber, $locationId, $wagonId);
    if (!$insertStmt->execute()) {
        return jsonError('Failed to create reposition order', 500);
    }

    // Update car status to Ordered
    $updateQuery = "UPDATE cars SET status = 'Ordered' WHERE id = ?";
    $updateStmt = $connection->prepare($updateQuery);
    if (!$updateStmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $updateStmt->bind_param('i', $wagonId);
    if (!$updateStmt->execute()) {
        return jsonError('Failed to update car status', 500);
    }

    // Insert history record
    $historyQuery = "INSERT INTO history (car_id, session_nbr, event_date, event, location)
                     VALUES (?, ?, NOW(), ?, ?)";
    $historyStmt = $connection->prepare($historyQuery);
    if (!$historyStmt) {
        return jsonError('Database error: ' . $connection->error, 500);
    }

    $event = 'Repositioned to ' . $destinationCode;
    $historyStmt->bind_param('iisi', $wagonId, $sessionNumber, $event, $currentLocationId);
    if (!$historyStmt->execute()) {
        return jsonError('Failed to insert history record', 500);
    }

    return jsonResponse([
        'message' => 'Wagon repositioned successfully',
        'carId' => $wagonId,
        'reportingMarks' => $reportingMarks,
        'waybillNumber' => $waybillNumber,
        'destinationLocation' => $destinationCode,
        'newStatus' => 'Ordered'
    ], 200);
}

/**
 * Get JSON payload from request body
 */
function getJsonPayload() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? $_POST ?? [];
}
?>
