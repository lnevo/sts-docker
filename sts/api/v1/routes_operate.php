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
    $destructive = in_array($command, OPERATE_DESTRUCTIVE_COMMANDS, true);
    if ($destructive && api_role_rank($req['user']['role'] ?? '') < api_role_rank('admin')) {
        api_error(403, "Command '$command' is destructive and needs role 'admin'");
    }
    api_operate_validate($command, $params);

    $async = !empty($body['async']) || in_array($command, OPERATE_SLOW_COMMANDS, true);
    if ($async) {
        require_once dirname(__DIR__) . '/lib/tasks.php';
        $task = api_task_create('command', [
            'command' => $command, 'params' => $params, 'seed' => $seed,
        ], $req['user'], ['exclusive' => $destructive]);
        api_task_accepted($task);
    }

    try {
        $result = api_operate_run_command($command, $params, $seed);
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
        if ($mode === 'unload') {
            api_db_exec('DELETE FROM car_orders WHERE car = ?', 'i', [$car['id']]);
        }
        api_db_exec('UPDATE cars SET status = ?, last_spotted = 0 WHERE Id = ? AND status = ?', 'sis', [$new, $car['id'], $need]);
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
    $like = str_pad((string) $session, 3, '0', STR_PAD_LEFT) . '-E__';
    $last = api_db_value('SELECT waybill_number FROM car_orders WHERE waybill_number LIKE ? ORDER BY waybill_number DESC LIMIT 1 FOR UPDATE', 's', [$like]);
    $counter = $last === null ? 1 : ((int) substr($last, -2)) + 1;
    $waybill = str_pad((string) $session, 3, '0', STR_PAD_LEFT) . '-E' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
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

api_route('POST', '/commands/{command}', 'operate_command', 'operator');
api_route('POST', '/cars/{car}/load', operate_load_unload('load'), 'operator');
api_route('POST', '/cars/{car}/unload', operate_load_unload('unload'), 'operator');
api_route('POST', '/cars/{car}/reposition', 'operate_reposition', 'operator');
api_route('POST', '/session/advance', 'operate_session_advance', 'operator');
