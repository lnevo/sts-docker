<?php
// track_scale_ajax.php — AJAX API for South Yard track scale

require 'open_db.php';
require 'track_scale_helpers.php';

header('Content-Type: application/json');

track_scale_session_init();
$config = track_scale_load_config();

function track_scale_json_error($message, $code = 400)
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function track_scale_read_json_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

$action = $_GET['action'] ?? '';
if ($action === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = track_scale_read_json_body();
    $action = $body['action'] ?? '';
}

$dbc = open_db();
track_scale_sync_session_calibration($dbc);

try {
    switch ($action) {
        case 'cars_at_scale':
            $filter = trim((string) ($_GET['job_id'] ?? ''));
            $cars = track_scale_get_cars_at_scale($dbc, $config, $filter);
            $list = [];
            foreach ($cars as $car) {
                $profile = track_scale_profile_for_marks($car['reporting_marks'], $config);
                $active_order = track_scale_get_car_active_order($dbc, $car['id']);
                $list[] = [
                    'id' => $car['id'],
                    'reporting_marks' => $car['reporting_marks'],
                    'status' => $car['status'],
                    'display_status' => track_scale_display_status($car['status'] ?? ''),
                    'has_load' => track_scale_car_has_load($car),
                    'has_active_order' => $active_order !== null,
                    'needs_assignment' => track_scale_car_needs_assignment($car, $dbc, $config),
                    'allows_scale_reassign' => track_scale_car_allows_scale_reassign($car, $dbc, $config),
                    'requires_train_reassign_confirm' => track_scale_car_requires_train_reassign_confirm(
                        $car,
                        $dbc,
                        $config
                    ),
                    'active_waybill' => $active_order['waybill_number'] ?? null,
                    'active_shipment_code' => $active_order['shipment_code'] ?? null,
                    'active_unloading_location' => $active_order['unloading_location'] ?? null,
                    'position' => (int) ($car['position'] ?? 0),
                    'car_code' => $car['car_code'],
                    'current_location' => $car['current_location'],
                    'weigh_source' => $car['weigh_source'] ?? null,
                    'train_job' => $car['train_job'] ?? null,
                    'load_limit_tons' => $profile['load_limit_tons'],
                    'tare_tons' => $profile['tare_tons'],
                    'tare_only' => !empty($profile['tare_only']),
                ];
            }
            echo json_encode([
                'success' => true,
                'location' => track_scale_loading_location_code($config),
                'count' => count($list),
                'cars' => $list,
                'trains' => track_scale_get_south_yard_train_jobs($dbc, $config),
                'filter_job_id' => $filter,
                'scale_status' => track_scale_build_scale_status($dbc, $config),
            ]);
            break;

        case 'get_car':
            $car_id = $_GET['car_id'] ?? '';
            $car = track_scale_get_car_by_id($dbc, $car_id);
            if ($car === null) {
                track_scale_json_error('Car not found', 404);
            }
            if (!track_scale_car_weighable($car, $dbc, $config)) {
                track_scale_json_error(track_scale_weighable_car_error($car, $config), 403);
            }
            echo json_encode(track_scale_build_car_response($car, $config, $dbc));
            break;

        case 'lookup_car':
            $scan_code = $_GET['scan_code'] ?? '';
            $car = track_scale_lookup_car($dbc, $scan_code);
            if ($car === null) {
                track_scale_json_error('Car not found', 404);
            }
            echo json_encode(track_scale_build_car_response($car, $config, $dbc));
            break;

        case 'weigh':
            $body = track_scale_read_json_body();
            if (track_scale_is_out_of_service($dbc, $config)) {
                track_scale_json_error(
                    track_scale_build_scale_status($dbc, $config)['message']
                        ?? 'Scale is out of service — calibrate before weighing cars.',
                    503
                );
            }
            $reporting_marks = strtoupper(trim($body['reporting_marks'] ?? ''));
            if ($reporting_marks === '') {
                track_scale_json_error('Missing reporting marks');
            }
            $car = track_scale_lookup_car($dbc, $reporting_marks);
            if ($car === null) {
                track_scale_json_error('Car not found', 404);
            }
            if (!track_scale_car_weighable($car, $dbc, $config)) {
                track_scale_json_error(track_scale_weighable_car_error($car, $config), 403);
            }
            $profile = track_scale_profile_for_marks($reporting_marks, $config);
            $is_test_car = !empty($profile['tare_only']) || track_scale_is_test_car_marks($reporting_marks, $config);
            if ($is_test_car) {
                $tare = (float) $profile['tare_tons'];
                $true_gross = $tare;
                $display_gross = track_scale_average_sensor_display_tons($true_gross, $config);
                $reading = [
                    'gross_tons' => $display_gross,
                    'net_tons' => 0.0,
                    'true_net_tons' => 0.0,
                    'tare_tons' => $tare,
                    'target_net_tons' => null,
                    'delta_tons' => null,
                    'tolerance_tons' => null,
                    'in_tolerance' => false,
                    'routing' => null,
                    'unloaded_weigh' => true,
                    'test_car_weigh' => true,
                    'sensor_readings' => track_scale_build_sensor_readings($true_gross, $config),
                ];
                track_scale_record_weigh_log($dbc, $reporting_marks, $reading, $config);
                echo json_encode([
                    'success' => true,
                    'reading' => $reading,
                    'next_car' => null,
                ]);
                break;
            }
            $target_net = (float) $profile['target_net_tons'];
            if ($target_net <= 0) {
                track_scale_json_error('No load limit on file for this car', 403);
            }
            $tare = (float) $profile['tare_tons'];
            $unloaded = track_scale_car_weighs_unloaded($car);
            if ($unloaded) {
                $true_gross = $tare;
                $display_gross = track_scale_average_sensor_display_tons($true_gross, $config);
                $reading = [
                    'gross_tons' => $display_gross,
                    'net_tons' => 0.0,
                    'true_net_tons' => 0.0,
                    'tare_tons' => $tare,
                    'target_net_tons' => $target_net,
                    'delta_tons' => null,
                    'tolerance_tons' => null,
                    'in_tolerance' => false,
                    'routing' => null,
                    'unloaded_weigh' => true,
                    'sensor_readings' => track_scale_build_sensor_readings($true_gross, $config),
                ];
            } else {
                $load = track_scale_get_car_load_state($dbc, $reporting_marks, $target_net, $config);
                $reading = track_scale_build_display_weighing(
                    (float) $load['true_net_tons'],
                    $tare,
                    $target_net,
                    $config,
                    (float) ($load['balance_shift_tons'] ?? 0.0)
                );
                $reading['unloaded_weigh'] = false;
            }
            track_scale_record_weigh_log($dbc, $reporting_marks, $reading, $config);
            $next_car = track_scale_get_next_car_in_train($dbc, $car, $config);
            $next_car_payload = null;
            if ($next_car !== null) {
                $next_car_payload = [
                    'id' => (int) $next_car['id'],
                    'reporting_marks' => $next_car['reporting_marks'],
                    'position' => (int) ($next_car['position'] ?? 0),
                    'train_job' => $next_car['train_job'] ?? null,
                ];
            }
            echo json_encode([
                'success' => true,
                'reading' => $reading,
                'next_car' => $next_car_payload,
            ]);
            break;

        case 'calibrate_read':
            $lock_error = track_scale_require_calibration_unlocked($dbc);
            if ($lock_error !== null) {
                track_scale_json_error($lock_error);
            }
            $body = track_scale_read_json_body();
            $raw_position = trim((string) ($body['position'] ?? ''));
            if ($raw_position === '') {
                track_scale_json_error('Missing sensor position');
            }
            $position = track_scale_normalize_position($raw_position);
            $active_position = track_scale_get_scale_car_position();
            if ($active_position === null) {
                track_scale_json_error('Mark which sensor the scale car is on before weighing');
            }
            if (!track_scale_test_car_at_scale($dbc, $config)) {
                $scale_location = track_scale_loading_location_code($config);
                track_scale_json_error(
                    'Scale test car must be at ' . $scale_location . ' to weigh sensors',
                    403
                );
            }
            if ($active_position !== $position) {
                track_scale_json_error(
                    'Scale car is marked at the ' . $active_position . ' sensor — move the car or update the position'
                );
            }
            track_scale_mark_sensor_weighed($position);
            echo json_encode([
                'success' => true,
                'calibration' => track_scale_build_calibration_readings($config, $dbc),
            ]);
            break;

        case 'calibrate_set_position':
            $lock_error = track_scale_require_calibration_unlocked($dbc);
            if ($lock_error !== null) {
                track_scale_json_error($lock_error);
            }
            $body = track_scale_read_json_body();
            $position = $body['position'] ?? null;
            if ($position !== null && $position !== '') {
                if (!track_scale_test_car_at_scale($dbc, $config)) {
                    $scale_location = track_scale_loading_location_code($config);
                    track_scale_json_error(
                        'Scale test car must be at ' . $scale_location . ' before marking sensor position',
                        403
                    );
                }
                track_scale_set_scale_car_position(track_scale_normalize_position($position));
            } else {
                track_scale_set_scale_car_position(null);
            }
            echo json_encode([
                'success' => true,
                'scale_car_position' => track_scale_get_scale_car_position(),
                'calibration' => track_scale_build_calibration_readings($config, $dbc),
            ]);
            break;

        case 'calibrate_adjust':
            $lock_error = track_scale_require_calibration_unlocked($dbc);
            if ($lock_error !== null) {
                track_scale_json_error($lock_error);
            }
            $body = track_scale_read_json_body();
            $sensor = track_scale_normalize_position($body['sensor'] ?? 'center');
            $direction = $body['direction'] ?? 'up';
            $active_position = track_scale_get_scale_car_position();
            if ($active_position === null || $active_position !== $sensor) {
                track_scale_json_error(
                    'Scale car must be at the ' . $sensor . ' sensor to adjust calibration'
                );
            }
            if (!track_scale_test_car_at_scale($dbc, $config)) {
                $scale_location = track_scale_loading_location_code($config);
                track_scale_json_error(
                    'Scale test car must be at ' . $scale_location . ' to adjust calibration',
                    403
                );
            }
            if (!track_scale_sensor_has_reading($sensor)) {
                track_scale_json_error('Weigh the scale car at this sensor before adjusting');
            }
            $adjustment = track_scale_adjust_sensor($sensor, $direction, $config);
            echo json_encode([
                'success' => true,
                'sensor' => $sensor,
                'adjustment_tons' => $adjustment,
                'calibration' => track_scale_build_calibration_readings($config, $dbc),
            ]);
            break;

        case 'calibrate_adjust_reset':
            $lock_error = track_scale_require_calibration_unlocked($dbc);
            if ($lock_error !== null) {
                track_scale_json_error($lock_error);
            }
            $body = track_scale_read_json_body();
            $sensor = track_scale_normalize_position($body['sensor'] ?? 'center');
            $active_position = track_scale_get_scale_car_position();
            if ($active_position === null || $active_position !== $sensor) {
                track_scale_json_error(
                    'Scale car must be at the ' . $sensor . ' sensor to reset adjustment'
                );
            }
            if (!track_scale_test_car_at_scale($dbc, $config)) {
                $scale_location = track_scale_loading_location_code($config);
                track_scale_json_error(
                    'Scale test car must be at ' . $scale_location . ' to reset adjustment',
                    403
                );
            }
            if (!track_scale_sensor_has_reading($sensor)) {
                track_scale_json_error('Weigh the scale car at this sensor before resetting adjustment');
            }
            $adjustment = track_scale_reset_sensor_adjustment($sensor, $config);
            echo json_encode([
                'success' => true,
                'sensor' => $sensor,
                'adjustment_tons' => $adjustment,
                'calibration' => track_scale_build_calibration_readings($config, $dbc),
            ]);
            break;

        case 'calibrate_set_fine_tune':
            $lock_error = track_scale_require_calibration_unlocked($dbc);
            if ($lock_error !== null) {
                track_scale_json_error($lock_error);
            }
            $body = track_scale_read_json_body();
            $sensor = track_scale_normalize_position($body['sensor'] ?? 'center');
            $enabled = !empty($body['enabled']);
            $active_position = track_scale_get_scale_car_position();
            if ($active_position === null || $active_position !== $sensor) {
                track_scale_json_error(
                    'Scale car must be at the ' . $sensor . ' sensor to change fine tune'
                );
            }
            if (!track_scale_test_car_at_scale($dbc, $config)) {
                $scale_location = track_scale_loading_location_code($config);
                track_scale_json_error(
                    'Scale test car must be at ' . $scale_location . ' to change fine tune',
                    403
                );
            }
            if (!track_scale_sensor_has_reading($sensor)) {
                track_scale_json_error('Weigh the scale car at this sensor before changing fine tune');
            }
            track_scale_set_sensor_fine_tune($sensor, $enabled);
            echo json_encode([
                'success' => true,
                'sensor' => $sensor,
                'fine_tune' => track_scale_get_sensor_fine_tune($sensor),
                'adjust_step_tons' => track_scale_round(track_scale_get_adjust_step($sensor, $config), $config),
                'calibration' => track_scale_build_calibration_readings($config, $dbc),
            ]);
            break;

        case 'calibrate_save':
            $result = track_scale_save_calibration($dbc, $config);
            if (!$result['success']) {
                track_scale_json_error($result['error'] ?? 'Could not save calibration');
            }
            echo json_encode([
                'success' => true,
                'calibration' => $result['calibration'],
            ]);
            break;

        case 'calibrate_reset':
            track_scale_clear_saved_calibration_lock($dbc);
            echo json_encode([
                'success' => true,
                'message' => 'Calibration reset',
                'calibration' => track_scale_build_calibration_readings($config, $dbc),
            ]);
            break;

        case 'calibration_state':
            echo json_encode([
                'success' => true,
                'calibration' => track_scale_build_calibration_readings($config, $dbc),
            ]);
            break;

        case 'open_orders':
            $car_id = $_GET['car_id'] ?? '';
            $routing = $_GET['routing'] ?? 'outbound';
            if ($car_id === '') {
                track_scale_json_error('Missing car_id');
            }
            if (!in_array($routing, ['outbound', 'reload'], true)) {
                track_scale_json_error('Invalid routing');
            }
            $orders = track_scale_get_open_orders($dbc, $car_id, $routing, $config);
            echo json_encode([
                'success' => true,
                'routing' => $routing,
                'orders' => $orders,
                'shipment_codes' => track_scale_shipment_codes_for_routing($routing, $config),
            ]);
            break;

        case 'generate_order':
            $body = track_scale_read_json_body();
            $shipment_code = trim($body['shipment_code'] ?? '');
            $car_id = $body['car_id'] ?? null;
            $routing = $body['routing'] ?? 'outbound';
            if ($shipment_code === '') {
                track_scale_json_error('Missing shipment_code');
            }
            if (!in_array($routing, ['outbound', 'reload'], true)) {
                track_scale_json_error('Invalid routing');
            }
            $result = track_scale_generate_order($dbc, $shipment_code, $car_id, $config, $routing);
            if (!$result['success']) {
                track_scale_json_error($result['error'], 500);
            }
            echo json_encode($result);
            break;

        case 'assign':
            $body = track_scale_read_json_body();
            $waybill = trim($body['waybill_number'] ?? '');
            $car_id = trim($body['car_id'] ?? '');
            if ($waybill === '' || $car_id === '') {
                track_scale_json_error('Missing waybill_number or car_id');
            }
            $car = track_scale_get_car_by_id($dbc, $car_id);
            if ($car === null) {
                track_scale_json_error('Car not found', 404);
            }
            $result = track_scale_assign_car($dbc, $waybill, $car_id, $config);
            if (!$result['success']) {
                track_scale_json_error($result['error'] ?? 'Assign failed', 500);
            }
            echo json_encode([
                'success' => true,
                'message' => 'Car assigned to ' . $waybill,
                'waybill_number' => $waybill,
                'car_reporting_marks' => $result['car_reporting_marks'] ?? '',
                'car_code' => $result['car_code'] ?? '',
                'unloaded_first' => !empty($result['unloaded_first']),
                'closed_prior_order' => !empty($result['closed_prior_order']),
                'preserved_load' => !empty($result['preserved_load']),
                'previous_status' => $result['previous_status'] ?? null,
                'returned_to_train' => !empty($result['returned_to_train']),
                'train_job' => $result['train_job'] ?? null,
                'in_train_workflow' => $result['in_train_workflow'] ?? [],
                'partial' => !empty($result['partial']),
            ]);
            break;

        default:
            track_scale_json_error('Unknown action');
    }
} finally {
    mysqli_close($dbc);
}

?>
