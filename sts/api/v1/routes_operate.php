<?php
/**
 * Operate routes (role: operator): catalog commands, car load/unload/reposition,
 * session advance. Destructive commands re-check for admin inside the handler.
 */
if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/operate_command.php';

/* ------------------------------------------------------------- commands */

function operate_command(array $req)
{
    $command = $req['params']['command'];
    $body = api_body();
    foreach (array_keys($body) as $k) {
        if (!in_array($k, ['params', 'async', 'seed'], true)) {
            api_error(422, "Unknown field '$k' (allowed: params, async, seed)");
        }
    }
    $params = $body['params'] ?? [];
    if (!is_array($params)) {
        api_error(422, 'params must be an object');
    }
    if (isset($body['async']) && !is_bool($body['async'])) {
        api_error(422, 'async must be a boolean');
    }
    $seed = $body['seed'] ?? null;
    if ($seed !== null && !(is_int($seed) || (is_string($seed) && preg_match('/^-?\d+$/', $seed)))) {
        api_error(422, 'seed must be an integer');
    }

    if (!in_array($command, api_catalog_command_ids(), true)) {
        api_error(404, 'Unknown command');
    }
    $needs = api_command_required_role($command);
    if (!api_role_allows($req['user'], $needs)) {
        api_error(403, "Command '$command' needs role '$needs'");
    }
    $destructive = api_command_is_exclusive($command);
    api_operate_validate($command, $params);

    // Destructive commands always run as an exclusive (maintenance) task. Any
    // other command waits behind the maintenance gate for the rest of the request.
    if (!$destructive) {
        api_maintenance_enter();
    }
    $async = $destructive || !empty($body['async']) || in_array($command, OPERATE_SLOW_COMMANDS, true);
    if ($async) {
        require_once dirname(__DIR__) . '/lib/tasks.php';
        $timeout = $body['timeout_seconds'] ?? null;
        $tmin = api_role_allows($req['user'], 'admin') ? 1 : 60;
        if ($timeout !== null && (!is_int($timeout) || $timeout < $tmin || $timeout > 86400)) {
            api_error(422, "timeout_seconds must be an integer between $tmin and 86400");
        }
        $task = api_task_create('command', array_filter([
            'command' => $command, 'params' => $params, 'seed' => $seed, 'timeout_seconds' => $timeout,
            'creator_role' => $req['user']['role'] ?? null,
        ], static function ($v) {
            return $v !== null;
        }) + ['seed' => $seed], $req['user'], ['exclusive' => $destructive]);
        api_task_accepted($task);
    }

    try {
        $result = api_operate_run_command($command, $params, $seed, $req['user']['role'] ?? null);
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
    return ['command' => $command, 'result' => $result];
}

/* ------------------------------------------------------------------ cars */

/** Lock-free car lookup row for operate actions. */
function operate_car(array $req)
{
    $car = read_find_car($req['params']['car']);
    return $car;
}

/** POST /cars/{car}/load and /unload: status transition per load_unload.php. */
function operate_load_unload($mode)
{
    return static function (array $req) use ($mode) {
        $car = operate_car($req);
        $need = $mode === 'load' ? 'Loading' : 'Unloading';
        if ($car['status'] !== $need) {
            api_error(409, "Car is '{$car['status']}'; $mode needs status '$need'");
        }
        $new = $mode === 'load' ? 'Loaded' : 'Empty';
        $dbc = api_db();
        $dbc->begin_transaction();
        // Re-read the status under a row lock: load_unload.php acts on whatever the car is then.
        $cur = api_db_value('SELECT status FROM cars WHERE Id = ? FOR UPDATE', 'i', [$car['id']]);
        if ($cur !== $need) {
            $dbc->rollback();
            api_error(409, "Car is '" . ($cur ?? 'gone') . "'; $mode needs status '$need'");
        }
        if ($mode === 'unload') {
            // Same as load_unload.php: the orders of an unloaded car are deleted. They may already be gone.
            api_db_exec('DELETE FROM car_orders WHERE car = ?', 'i', [$car['id']]);
        }
        $changed = api_db_exec('UPDATE cars SET status = ?, last_spotted = 0 WHERE Id = ? AND status = ?', 'sis', [$new, $car['id'], $need]);
        if ($changed !== 1) {
            $dbc->rollback();
            api_error(409, "Car changed state while $mode was running");
        }
        $dbc->commit();
        return read_find_car((string) $car['id']);
    };
}

function operate_reposition(array $req)
{
    $body = api_body();
    foreach (array_keys($body) as $k) {
        if ($k !== 'location') {
            api_error(422, "Unknown field '$k' (allowed: location)");
        }
    }
    $loc = $body['location'] ?? null;
    if ((!is_string($loc) && !is_int($loc)) || trim((string) $loc) === '') {
        api_error(422, 'location (id or code) is required');
    }
    $loc = trim((string) $loc);
    $car = operate_car($req);
    $where = [];
    $types = '';
    $params = [];
    read_id_or_text_clause('locations.Id', 'locations.code', $loc, $where, $types, $params);
    $dest = api_db_one('SELECT locations.Id AS id, locations.code FROM locations WHERE ' . $where[0] . ' LIMIT 1', $types, $params);
    if (!$dest) {
        api_error(422, 'Destination location not found');
    }

    $dbc = api_db();
    $dbc->begin_transaction();
    $fresh = api_db_one(
        "SELECT status, current_location_id FROM cars WHERE Id = ? AND NOT EXISTS (SELECT 1 FROM car_orders WHERE car = cars.Id) FOR UPDATE",
        'i', [$car['id']]
    );
    if (!$fresh || $fresh['status'] !== 'Empty') {
        $dbc->rollback();
        api_error(409, 'Car must be Empty with no orders to be repositioned');
    }
    $session = api_session_nbr();
    $prefix = str_pad((string) $session, 3, '0', STR_PAD_LEFT) . '-E';
    // Highest numeric suffix of NNN-E<digits>; two digits below 100, more after.
    $max = api_db_value(
        "SELECT MAX(CAST(SUBSTRING(waybill_number, ?) AS UNSIGNED)) FROM car_orders WHERE waybill_number LIKE ? AND SUBSTRING(waybill_number, ?) REGEXP '^[0-9]+\$' FOR UPDATE",
        'isi', [strlen($prefix) + 1, $prefix . '%', strlen($prefix) + 1]
    );
    $counter = $max === null ? 1 : ((int) $max) + 1;
    $waybill = $prefix . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
    // For E waybills car_orders.shipment holds the destination location id.
    api_db_exec('INSERT INTO car_orders (waybill_number, shipment, car) VALUES (?, ?, ?)', 'sii', [$waybill, (int) $dest['id'], $car['id']]);
    api_db_exec("UPDATE cars SET status = 'Ordered' WHERE Id = ?", 'i', [$car['id']]);
    api_db_exec(
        'INSERT INTO history (car_id, session_nbr, event_date, event, location) VALUES (?, ?, NOW(), ?, ?)',
        'iisi', [$car['id'], $session, 'Repositioned to ' . $dest['code'], (int) $fresh['current_location_id']]
    );
    $dbc->commit();
    $out = read_find_car((string) $car['id']);
    $out['waybill_number'] = $waybill;
    $out['destination'] = $dest['code'];
    return $out;
}

/* ------------------------------------------------------------ car orders */

/**
 * Fill one unfilled car order with a chosen car (the Fill Orders page's manual
 * assign). Body {car, loaded?}. The car must be Empty, hold no other order and
 * match the shipment's car code. The car becomes Loaded at the loading location,
 * else Ordered; loaded=true marks it Loaded wherever it is (a car that already
 * holds the load, e.g. one added to the railroad loaded).
 */
function operate_order_fill(array $req)
{
    $body = api_body();
    if ($body === []) {
        api_error(422, 'Request body must be a non-empty JSON object');
    }
    foreach (array_keys($body) as $k) {
        if (!in_array($k, ['car', 'loaded'], true)) {
            api_error(422, "Unknown field '$k' (allowed: car, loaded)");
        }
    }
    $car_ident = $body['car'] ?? null;
    if ((!is_string($car_ident) && !is_int($car_ident)) || trim((string) $car_ident) === '') {
        api_error(422, 'car (id, reporting marks or RFID) is required');
    }
    $loaded = $body['loaded'] ?? false;
    if (!is_bool($loaded)) {
        api_error(422, 'loaded must be a boolean');
    }
    // The batch fill (fill_orders) assigns cars without row locks and overwrites a
    // filled waybill; never run beside any queued or running task.
    foreach (api_task_list() as $rec) {
        if (in_array($rec['status'] ?? '', ['queued', 'running'], true)) {
            api_task_conflict($rec);
        }
    }
    $waybill = $req['params']['waybill'];
    $car = read_find_car(trim((string) $car_ident));

    $dbc = api_db();
    $dbc->begin_transaction();
    // Car row first, then car_orders: the same lock order as operate_reposition.
    $fresh = api_db_one(
        'SELECT status, car_code_id, current_location_id FROM cars WHERE Id = ? AND NOT EXISTS (SELECT 1 FROM car_orders WHERE car = cars.Id) FOR UPDATE',
        'i', [$car['id']]
    );
    $order = api_db_one(
        'SELECT car_orders.waybill_number, car_orders.car, shipments.car_code
         FROM car_orders JOIN shipments ON shipments.Id = car_orders.shipment
         WHERE car_orders.waybill_number = ? AND car_orders.waybill_number NOT LIKE "___-E%" FOR UPDATE',
        's', [$waybill]
    );
    if (!$order) {
        $dbc->rollback();
        read_not_found('Car order');
    }
    if ((string) $order['car'] !== '' && (string) $order['car'] !== '0') {
        $dbc->rollback();
        api_error(409, 'Car order is already filled');
    }
    if (!$fresh || $fresh['status'] !== 'Empty') {
        $dbc->rollback();
        api_error(409, 'Car must be Empty with no orders to fill an order');
    }
    if ((int) $fresh['car_code_id'] !== (int) $order['car_code']) {
        $dbc->rollback();
        api_error(422, "Car's car code does not match the shipment's");
    }
    require_once dirname(__DIR__, 2) . '/fill_order_helpers.php';
    $r = fill_order_assign_car($dbc, $waybill, $car['id']);
    if (empty($r['success'])) {
        $dbc->rollback();
        api_internal_error($r['error'] ?? 'fill failed');
    }
    if ($loaded) {
        // As POST /cars/{car}/load does: Loaded, last_spotted cleared, a history row.
        api_db_exec("UPDATE cars SET status = 'Loaded', last_spotted = 0 WHERE Id = ?", 'i', [$car['id']]);
        api_db_exec(
            'INSERT INTO history (car_id, session_nbr, event_date, event, location) VALUES (?, ?, NOW(), ?, ?)',
            'iisi', [$car['id'], api_session_nbr(), 'Loaded (joined loaded) on ' . $waybill, (int) $fresh['current_location_id']]
        );
    }
    $dbc->commit();
    $out = read_order_detail(['params' => ['waybill' => $waybill]]);
    $out['car_status'] = read_find_car((string) $car['id'])['status'];
    return $out;
}

/* --------------------------------------------------------------- session */

function operate_session_advance(array $req)
{
    if (api_body() !== []) {
        api_error(422, 'This route takes no body');
    }
    try {
        $r = api_operate_run_command('increment_session', []);
    } catch (RuntimeException $e) {
        api_error(422, $e->getMessage());
    }
    return ['session_nbr_before' => $r['session_nbr_before'], 'session_nbr' => $r['session_nbr_after']];
}

api_route('POST', '/commands/{command}', 'operate_command', 'operator', ['maint' => 'self']);
api_route('POST', '/cars/{car}/load', operate_load_unload('load'), 'operator');
api_route('POST', '/cars/{car}/unload', operate_load_unload('unload'), 'operator');
api_route('POST', '/cars/{car}/reposition', 'operate_reposition', 'operator');
api_route('POST', '/car-orders/{waybill}/fill', 'operate_order_fill', 'operator');
api_route('POST', '/session/advance', 'operate_session_advance', 'operator');
