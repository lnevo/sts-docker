<?php
/**
 * Read routes (role: reader) plus the open ones (health, openapi.json).
 * All v1 fields are snake_case; ids are named `id`, foreign keys `<thing>_id`,
 * and the resolved display value sits next to it (`location_id` + `location`).
 */
if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

define('STS_API_VERSION', '1.0.0');

/* ---------------------------------------------------------------- helpers */

/** Append "(idcol = ? OR textcol = ?)" (or text only) for an id-or-text identifier. */
function read_id_or_text_clause($id_col, $text_col, $value, array &$where, &$types, array &$params)
{
    if (ctype_digit($value)) {
        $where[] = "($id_col = ? OR $text_col = ?)";
        $types .= 'is';
        $params[] = (int) $value;
        $params[] = $value;
    } else {
        $where[] = "$text_col = ?";
        $types .= 's';
        $params[] = $value;
    }
}

function read_like_prefix($s)
{
    return addcslashes($s, '%_\\') . '%';
}

function read_int_cols(array $row, array $cols)
{
    foreach ($cols as $c) {
        if (array_key_exists($c, $row) && $row[$c] !== null) {
            $row[$c] = (int) $row[$c];
        }
    }
    return $row;
}

function read_not_found($what)
{
    api_error(404, "$what not found");
}

/* ------------------------------------------------------------------- cars */

const READ_CAR_SELECT = 'cars.Id AS id, cars.reporting_marks, cars.car_code_id, car_codes.code AS car_code,
    cars.current_location_id AS location_id, loc.code AS location, rt.station AS station,
    cars.position, cars.status, cars.handled_by_job_id AS job_id, jobs.name AS job,
    cars.remarks, cars.load_count, cars.home_location AS home_location_id, home.code AS home_location,
    cars.RFID_code AS rfid_code, cars.block_id, cars.last_spotted';

const READ_CAR_FROM = 'FROM cars
    LEFT JOIN car_codes ON car_codes.Id = cars.car_code_id
    LEFT JOIN locations loc ON loc.Id = cars.current_location_id
    LEFT JOIN routing rt ON rt.id = loc.station
    LEFT JOIN jobs ON jobs.Id = cars.handled_by_job_id
    LEFT JOIN locations home ON home.Id = cars.home_location';

function read_car_row(array $r)
{
    return read_int_cols($r, ['id', 'car_code_id', 'location_id', 'position', 'job_id', 'load_count', 'home_location_id', 'block_id', 'last_spotted']);
}

/** Resolve {car}: id, reporting marks, or RFID code. Returns the car row or 404. */
function read_find_car($ident)
{
    $attempts = [];
    if (ctype_digit($ident)) {
        $attempts[] = ['cars.Id = ?', 'i', (int) $ident];
    }
    $attempts[] = ['cars.reporting_marks = ?', 's', strtoupper($ident)];
    $attempts[] = ['cars.RFID_code = ?', 's', $ident];
    foreach ($attempts as [$cond, $t, $v]) {
        $row = api_db_one('SELECT ' . READ_CAR_SELECT . ' ' . READ_CAR_FROM . " WHERE $cond LIMIT 1", $t, [$v]);
        if ($row) {
            return read_car_row($row);
        }
    }
    read_not_found('Car');
}

function read_cars_list(array $req)
{
    $q = $req['query'];
    [$limit, $offset] = api_pagination($q);
    $where = [];
    $types = '';
    $params = [];
    if (($v = api_query_str($q, 'status')) !== '') {
        $where[] = 'cars.status = ?';
        $types .= 's';
        $params[] = $v;
    }
    if (($v = api_query_str($q, 'location')) !== '') {
        read_id_or_text_clause('loc.Id', 'loc.code', $v, $where, $types, $params);
    }
    if (($v = api_query_str($q, 'job')) !== '') {
        read_id_or_text_clause('jobs.Id', 'jobs.name', $v, $where, $types, $params);
    }
    if (($v = api_query_str($q, 'car_code')) !== '') {
        read_id_or_text_clause('car_codes.Id', 'car_codes.code', $v, $where, $types, $params);
    }
    if (($v = api_query_str($q, 'marks')) !== '') {
        $where[] = 'cars.reporting_marks LIKE ?';
        $types .= 's';
        $params[] = read_like_prefix(strtoupper($v));
    }
    $sql_where = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    [$rows, $total] = api_db_page(READ_CAR_SELECT, READ_CAR_FROM . $sql_where, 'cars.reporting_marks', $types, $params, $limit, $offset);
    api_ok_list(array_map('read_car_row', $rows), $total, $limit, $offset);
}

function read_car_detail(array $req)
{
    $car = read_find_car($req['params']['car']);
    $car['orders'] = read_orders_for_car($car['id']);
    return $car;
}

function read_car_history(array $req)
{
    $car = read_find_car($req['params']['car']);
    [$limit, $offset] = api_pagination($req['query']);
    [$rows, $total] = api_db_page(
        'history.session_nbr, history.event_date, history.event, history.location AS location_id, locations.code AS location',
        'FROM history LEFT JOIN locations ON locations.Id = history.location WHERE history.car_id = ?',
        'history.event_date DESC, history.session_nbr DESC',
        'i', [$car['id']], $limit, $offset
    );
    $rows = array_map(static function ($r) {
        return read_int_cols($r, ['session_nbr', 'location_id']);
    }, $rows);
    api_ok_list($rows, $total, $limit, $offset);
}

/* ------------------------------------------------------------- car orders */

/*
 * car_orders.shipment is overloaded: for normal waybills it is shipments.Id; for
 * empty-car (E) waybills ("NNN-E##", created by reposition) it holds the
 * destination LOCATION id. The joins below split the two cases.
 */
const READ_ORDER_SELECT = "car_orders.waybill_number, car_orders.car AS car_id, cars.reporting_marks AS car,
    (car_orders.waybill_number LIKE '%-E%') AS is_empty_move,
    car_orders.shipment AS shipment_ref,
    shipments.Id AS shipment_id, shipments.code AS shipment, commodities.Code AS commodity,
    shipments.car_code AS car_code_id, car_codes.code AS car_code,
    IF(car_orders.waybill_number LIKE '%-E%', NULL, shipments.loading_location) AS loading_location_id,
    ll.code AS loading_location,
    IF(car_orders.waybill_number LIKE '%-E%', car_orders.shipment, shipments.unloading_location) AS unloading_location_id,
    IF(car_orders.waybill_number LIKE '%-E%', el.code, ul.code) AS unloading_location";

const READ_ORDER_FROM = "FROM car_orders
    LEFT JOIN cars ON cars.Id = car_orders.car
    LEFT JOIN shipments ON shipments.Id = car_orders.shipment AND car_orders.waybill_number NOT LIKE '%-E%'
    LEFT JOIN commodities ON commodities.Id = shipments.consignment
    LEFT JOIN car_codes ON car_codes.Id = shipments.car_code
    LEFT JOIN locations ll ON ll.Id = shipments.loading_location
    LEFT JOIN locations ul ON ul.Id = shipments.unloading_location
    LEFT JOIN locations el ON el.Id = car_orders.shipment AND car_orders.waybill_number LIKE '%-E%'";

function read_order_row(array $r)
{
    $r = read_int_cols($r, ['car_id', 'shipment_ref', 'shipment_id', 'car_code_id', 'loading_location_id', 'unloading_location_id']);
    $r['is_empty_move'] = (bool) $r['is_empty_move'];
    $r['filled'] = !empty($r['car_id']);
    if (!$r['filled']) {
        $r['car_id'] = null;
    }
    if ($r['is_empty_move']) {
        unset($r['shipment_id'], $r['shipment'], $r['commodity'], $r['car_code_id'], $r['car_code'], $r['loading_location_id'], $r['loading_location']);
    }
    unset($r['shipment_ref']);
    return $r;
}

function read_orders_for_car($car_id)
{
    $rows = api_db_all('SELECT ' . READ_ORDER_SELECT . ' ' . READ_ORDER_FROM . ' WHERE car_orders.car = ? ORDER BY car_orders.waybill_number', 'i', [$car_id]);
    return array_map('read_order_row', $rows);
}

function read_orders_list(array $req)
{
    $q = $req['query'];
    [$limit, $offset] = api_pagination($q);
    $status = api_query_enum($q, 'status', ['unfilled', 'filled', 'all'], 'all');
    $where = [];
    $types = '';
    $params = [];
    if ($status === 'unfilled') {
        $where[] = '(car_orders.car IS NULL OR car_orders.car = 0)';
    } elseif ($status === 'filled') {
        $where[] = 'car_orders.car > 0';
    }
    if (($v = api_query_str($q, 'shipment')) !== '') {
        $where[] = 'shipments.code = ?';
        $types .= 's';
        $params[] = $v;
    }
    $sql_where = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    [$rows, $total] = api_db_page(READ_ORDER_SELECT, READ_ORDER_FROM . $sql_where, 'car_orders.waybill_number', $types, $params, $limit, $offset);
    api_ok_list(array_map('read_order_row', $rows), $total, $limit, $offset);
}

function read_order_detail(array $req)
{
    $row = api_db_one('SELECT ' . READ_ORDER_SELECT . ' ' . READ_ORDER_FROM . ' WHERE car_orders.waybill_number = ? LIMIT 1', 's', [$req['params']['waybill']]);
    if (!$row) {
        read_not_found('Car order');
    }
    return read_order_row($row);
}

/* -------------------------------------------------------------- locations */

const READ_LOC_SELECT = 'locations.Id AS id, locations.code, locations.station AS station_id, routing.station AS station,
    locations.track, locations.spot, locations.rpt_station, locations.remarks, locations.color';
const READ_LOC_FROM = 'FROM locations LEFT JOIN routing ON routing.id = locations.station';

function read_loc_row(array $r)
{
    return read_int_cols($r, ['id', 'station_id']);
}

function read_locations_list(array $req)
{
    $q = $req['query'];
    [$limit, $offset] = api_pagination($q);
    $where = [];
    $types = '';
    $params = [];
    if (($v = api_query_str($q, 'station')) !== '') {
        read_id_or_text_clause('locations.station', 'routing.station', $v, $where, $types, $params);
    }
    $sql_where = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    [$rows, $total] = api_db_page(READ_LOC_SELECT, READ_LOC_FROM . $sql_where, 'routing.sort_seq, routing.station, locations.code', $types, $params, $limit, $offset);
    api_ok_list(array_map('read_loc_row', $rows), $total, $limit, $offset);
}

function read_location_detail(array $req)
{
    $where = [];
    $types = '';
    $params = [];
    read_id_or_text_clause('locations.Id', 'locations.code', $req['params']['location'], $where, $types, $params);
    $row = api_db_one('SELECT ' . READ_LOC_SELECT . ' ' . READ_LOC_FROM . ' WHERE ' . $where[0] . ' ORDER BY (locations.Id = ' . (int) $req['params']['location'] . ') DESC LIMIT 1', $types, $params);
    if (!$row) {
        read_not_found('Location');
    }
    $loc = read_loc_row($row);
    $cars = api_db_all(
        'SELECT ' . READ_CAR_SELECT . ' ' . READ_CAR_FROM . ' WHERE cars.current_location_id = ? ORDER BY cars.position, cars.reporting_marks LIMIT 1000',
        'i', [$loc['id']]
    );
    $loc['cars'] = array_map('read_car_row', $cars);
    return $loc;
}

/* --------------------------------------------------------------- stations */

const READ_STATION_SELECT = 'routing.id, routing.station AS name, routing.station_nbr, routing.instructions, routing.sort_seq, routing.color1, routing.color2';

function read_station_row(array $r)
{
    return read_int_cols($r, ['id', 'station_nbr', 'sort_seq', 'color1', 'color2']);
}

function read_stations_list(array $req)
{
    [$limit, $offset] = api_pagination($req['query']);
    [$rows, $total] = api_db_page(READ_STATION_SELECT, 'FROM routing', 'routing.sort_seq, routing.station', '', [], $limit, $offset);
    api_ok_list(array_map('read_station_row', $rows), $total, $limit, $offset);
}

function read_station_detail(array $req)
{
    if (!ctype_digit($req['params']['id'])) {
        read_not_found('Station');
    }
    $row = api_db_one('SELECT ' . READ_STATION_SELECT . ' FROM routing WHERE routing.id = ?', 'i', [(int) $req['params']['id']]);
    if (!$row) {
        read_not_found('Station');
    }
    $st = read_station_row($row);
    $st['locations'] = array_map('read_loc_row', api_db_all(
        'SELECT ' . READ_LOC_SELECT . ' ' . READ_LOC_FROM . ' WHERE locations.station = ? ORDER BY locations.code', 'i', [$st['id']]
    ));
    return $st;
}

/* ------------------------------------------------------------------- jobs */

function read_jobs_list(array $req)
{
    [$limit, $offset] = api_pagination($req['query']);
    [$rows, $total] = api_db_page(
        'jobs.Id AS id, jobs.name, jobs.description, (SELECT COUNT(*) FROM cars WHERE cars.handled_by_job_id = jobs.Id) AS car_count',
        'FROM jobs', 'jobs.name', '', [], $limit, $offset
    );
    $rows = array_map(static function ($r) {
        return read_int_cols($r, ['id', 'car_count']);
    }, $rows);
    api_ok_list($rows, $total, $limit, $offset);
}

function read_job_detail(array $req)
{
    $where = [];
    $types = '';
    $params = [];
    read_id_or_text_clause('jobs.Id', 'jobs.name', $req['params']['job'], $where, $types, $params);
    $job = api_db_one('SELECT jobs.Id AS id, jobs.name, jobs.description FROM jobs WHERE ' . $where[0] . ' LIMIT 1', $types, $params);
    if (!$job) {
        read_not_found('Job');
    }
    $job['id'] = (int) $job['id'];

    // Each job has its own step table named after the job (step_number, station,
    // pickup T/F, setout T/F, remarks). pu_criteria.job_id stores the job NAME.
    $steps = [];
    $has_table = (int) api_db_value(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        's', [$job['name']]
    );
    if ($has_table) {
        $tbl = '`' . str_replace('`', '``', $job['name']) . '`';
        $rows = api_db_all(
            "SELECT s.step_number, s.station AS station_id, routing.station AS station, s.pickup, s.setout, s.remarks
             FROM $tbl s LEFT JOIN routing ON routing.id = s.station ORDER BY s.step_number"
        );
        foreach ($rows as $r) {
            $steps[] = [
                'step_number' => (int) $r['step_number'],
                'station_id' => $r['station_id'] === null ? null : (int) $r['station_id'],
                'station' => $r['station'],
                'pickup' => $r['pickup'] === 'T',
                'setout' => $r['setout'] === 'T',
                'remarks' => $r['remarks'],
            ];
        }
    }
    $job['steps'] = $steps;

    $crit = api_db_all(
        'SELECT pu_criteria.id, pu_criteria.step_nbr AS step_number, pu_criteria.car_status,
                pu_criteria.commodity_id, commodities.Code AS commodity,
                pu_criteria.car_code_id, car_codes.code AS car_code,
                pu_criteria.dest_station_id, routing.station AS dest_station
         FROM pu_criteria
         LEFT JOIN commodities ON commodities.Id = pu_criteria.commodity_id
         LEFT JOIN car_codes ON car_codes.Id = pu_criteria.car_code_id
         LEFT JOIN routing ON routing.id = pu_criteria.dest_station_id
         WHERE pu_criteria.job_id = ? ORDER BY pu_criteria.step_nbr, pu_criteria.id',
        's', [$job['name']]
    );
    $job['pickup_criteria'] = array_map(static function ($r) {
        return read_int_cols($r, ['id', 'step_number', 'commodity_id', 'car_code_id', 'dest_station_id']);
    }, $crit);

    $cars = api_db_all('SELECT ' . READ_CAR_SELECT . ' ' . READ_CAR_FROM . ' WHERE cars.handled_by_job_id = ? ORDER BY cars.reporting_marks LIMIT 1000', 'i', [$job['id']]);
    $job['cars'] = array_map('read_car_row', $cars);
    return $job;
}

/* -------------------------------------------------------------- shipments */

const READ_SHIP_SELECT = 'shipments.Id AS id, shipments.code, shipments.description,
    shipments.consignment AS commodity_id, commodities.Code AS commodity,
    shipments.car_code AS car_code_id, car_codes.code AS car_code,
    shipments.loading_location AS loading_location_id, ll.code AS loading_location,
    shipments.unloading_location AS unloading_location_id, ul.code AS unloading_location,
    shipments.last_ship_date, shipments.min_interval, shipments.max_interval,
    shipments.min_amount, shipments.max_amount,
    shipments.min_load_time, shipments.max_load_time, shipments.min_unload_time, shipments.max_unload_time,
    shipments.special_instructions, shipments.remarks';
const READ_SHIP_FROM = 'FROM shipments
    LEFT JOIN commodities ON commodities.Id = shipments.consignment
    LEFT JOIN car_codes ON car_codes.Id = shipments.car_code
    LEFT JOIN locations ll ON ll.Id = shipments.loading_location
    LEFT JOIN locations ul ON ul.Id = shipments.unloading_location';

function read_ship_row(array $r)
{
    return read_int_cols($r, ['id', 'commodity_id', 'car_code_id', 'loading_location_id', 'unloading_location_id', 'last_ship_date',
        'min_interval', 'max_interval', 'min_amount', 'max_amount', 'min_load_time', 'max_load_time', 'min_unload_time', 'max_unload_time']);
}

function read_shipments_list(array $req)
{
    [$limit, $offset] = api_pagination($req['query']);
    [$rows, $total] = api_db_page(READ_SHIP_SELECT, READ_SHIP_FROM, 'shipments.code', '', [], $limit, $offset);
    api_ok_list(array_map('read_ship_row', $rows), $total, $limit, $offset);
}

function read_shipment_detail(array $req)
{
    if (!ctype_digit($req['params']['id'])) {
        read_not_found('Shipment');
    }
    $row = api_db_one('SELECT ' . READ_SHIP_SELECT . ' ' . READ_SHIP_FROM . ' WHERE shipments.Id = ?', 'i', [(int) $req['params']['id']]);
    if (!$row) {
        read_not_found('Shipment');
    }
    return read_ship_row($row);
}

/* ------------------------------------------------- simple lookup tables */

function read_simple_list($select, $from, $order, array $int_cols)
{
    return static function (array $req) use ($select, $from, $order, $int_cols) {
        [$limit, $offset] = api_pagination($req['query']);
        [$rows, $total] = api_db_page($select, $from, $order, '', [], $limit, $offset);
        $rows = array_map(static function ($r) use ($int_cols) {
            return read_int_cols($r, $int_cols);
        }, $rows);
        api_ok_list($rows, $total, $limit, $offset);
    };
}

/* --------------------------------------------------------------- settings */

function read_settings_row(array $r)
{
    return ['name' => $r['name'], 'description' => $r['description'], 'value' => $r['value']];
}

/* ------------------------------------------------- catalog / workflows etc. */

function read_load_catalog()
{
    require_once dirname(__DIR__, 2) . '/operational_steps_catalog.php';
}

function read_workflow_name_file($name)
{
    read_load_catalog();
    $dir = operational_steps_editor_dir();
    $file = operational_steps_resolve_workflow_filename($dir, $name);
    if ($file === '') {
        read_not_found('Workflow');
    }
    return [$dir, $file];
}

function read_session_helpers()
{
    require_once dirname(__DIR__, 2) . '/session_helpers.php';
}

function read_session_files($n)
{
    $dir = session_dir_for($n);
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (count($files) >= 1000) {
            break;
        }
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'html') {
            continue;
        }
        $rel = 'session_' . (int) $n . '/' . ltrim(substr($f->getPathname(), strlen($dir)), '/');
        $kind = stripos($rel, 'waybill') !== false ? 'waybill' : (preg_match('#phase_|switch|train#i', $rel) ? 'switchlist' : 'other');
        $files[] = ['path' => $rel, 'kind' => $kind, 'url' => '/sts/' . session_output_url($rel)];
    }
    usort($files, static function ($a, $b) {
        return strnatcmp($a['path'], $b['path']);
    });
    return $files;
}

/* ================================================================ routes */

api_route('GET', '/health', static function ($req) {
    $db = 'error';
    $session = null;
    $server = $user = $password = $db_name = '';
    require dirname(__DIR__, 2) . '/credentials.php';
    mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $link = @mysqli_connect($server_name, $user_name, $password, $db_name);
    } catch (Throwable $e) {
        $link = false;
    }
    if ($link) {
        mysqli_close($link);
        $db = 'ok';
        $session = api_session_nbr();
    }
    return [
        'status' => $db === 'ok' ? 'ok' : 'degraded',
        'version' => STS_API_VERSION,
        'stack' => getenv('STS_STACK_NAME') ?: 'live',
        'session_nbr' => $session,
        'db' => $db,
    ];
}, 'open', ['summary' => 'Health check']);

api_route('GET', '/openapi.json', static function ($req) {
    require_once dirname(__DIR__) . '/lib/openapi.php';
    api_emit(200, api_openapi_build());
}, 'open', ['summary' => 'Merged OpenAPI 3.1 spec']);

api_route('GET', '/session', static function ($req) {
    read_session_helpers();
    require_once dirname(__DIR__, 2) . '/operations_stats.php';
    $dbc = api_db();
    session_runtime_bootstrap();
    return ['session_nbr' => api_session_nbr(), 'stats' => operations_get_stats($dbc)];
});

api_route('GET', '/settings', static function ($req) {
    $rows = api_db_all('SELECT setting_name AS name, setting_desc AS description, setting_value AS value FROM settings ORDER BY setting_name');
    api_ok(array_map('read_settings_row', $rows), ['total' => count($rows)]);
});
api_route('GET', '/settings/{name}', static function ($req) {
    $row = api_db_one('SELECT setting_name AS name, setting_desc AS description, setting_value AS value FROM settings WHERE setting_name = ?', 's', [$req['params']['name']]);
    if (!$row) {
        read_not_found('Setting');
    }
    return read_settings_row($row);
});

api_route('GET', '/cars', 'read_cars_list');
api_route('GET', '/cars/{car}', 'read_car_detail');
api_route('GET', '/cars/{car}/history', 'read_car_history');

api_route('GET', '/locations', 'read_locations_list');
api_route('GET', '/locations/{location}', 'read_location_detail');
api_route('GET', '/stations', 'read_stations_list');
api_route('GET', '/stations/{id}', 'read_station_detail');
api_route('GET', '/jobs', 'read_jobs_list');
api_route('GET', '/jobs/{job}', 'read_job_detail');
api_route('GET', '/shipments', 'read_shipments_list');
api_route('GET', '/shipments/{id}', 'read_shipment_detail');

api_route('GET', '/commodities', read_simple_list('commodities.Id AS id, commodities.Code AS code, commodities.Description AS description, commodities.Remarks AS remarks', 'FROM commodities', 'commodities.Code', ['id']));
api_route('GET', '/car-codes', read_simple_list('car_codes.Id AS id, car_codes.code, car_codes.description, car_codes.remarks', 'FROM car_codes', 'car_codes.code', ['id']));
api_route('GET', '/owners', read_simple_list('owners.id, owners.name, owners.remarks', 'FROM owners', 'owners.name', ['id']));
api_route('GET', '/ownership', read_simple_list(
    'ownership.car_id, cars.reporting_marks, ownership.owner_id, owners.name AS owner, ownership.on_off_rr',
    'FROM ownership LEFT JOIN cars ON cars.Id = ownership.car_id LEFT JOIN owners ON owners.id = ownership.owner_id',
    'cars.reporting_marks', ['car_id', 'owner_id']
));

api_route('GET', '/car-orders', 'read_orders_list');
api_route('GET', '/car-orders/{waybill}', 'read_order_detail');

api_route('GET', '/catalog', static function ($req) {
    read_load_catalog();
    return operational_steps_catalog_payload(api_db());
});

api_route('GET', '/workflows', static function ($req) {
    read_load_catalog();
    $dir = operational_steps_editor_dir();
    $out = [];
    foreach (operational_steps_list_workflow_files($dir) as $file) {
        $out[] = ['name' => $file, 'size' => (int) @filesize($dir . '/' . $file), 'mtime' => gmdate('c', (int) @filemtime($dir . '/' . $file))];
    }
    $active = operational_steps_resolve_active_workflow($dir);
    api_ok(['workflows' => $out, 'active' => $active], ['total' => count($out)]);
});
api_route('GET', '/workflows/{name}', static function ($req) {
    [$dir, $file] = read_workflow_name_file($req['params']['name']);
    return ['name' => $file, 'recipe' => operational_steps_load_recipe($dir, $file)];
});

api_route('GET', '/sessions', static function ($req) {
    read_session_helpers();
    $current = session_get_db_session(api_db());
    $out = [];
    foreach (session_list_browser_sessions($current) as $n) {
        $mp = session_manifest_path($n);
        $out[] = [
            'session' => (int) $n,
            'current' => (int) $n === $current,
            'has_manifest' => is_readable($mp),
            'updated' => is_readable($mp) ? gmdate('c', (int) filemtime($mp)) : null,
            'overview_url' => '/sts/' . session_session_index_href($n),
        ];
    }
    api_ok($out, ['total' => count($out)]);
});
api_route('GET', '/sessions/{n}', static function ($req) {
    if (!ctype_digit($req['params']['n']) || (int) $req['params']['n'] < 1) {
        read_not_found('Session');
    }
    read_session_helpers();
    $n = (int) $req['params']['n'];
    $current = session_get_db_session(api_db());
    if (!is_readable(session_manifest_path($n)) && $n !== $current && !is_dir(session_dir_for($n))) {
        read_not_found('Session');
    }
    return [
        'session' => $n,
        'current' => $n === $current,
        'overview_url' => '/sts/' . session_session_index_href($n),
        'manifest' => session_load_manifest($n),
        'files' => read_session_files($n),
    ];
});

api_route('GET', '/backups', static function ($req) {
    read_load_catalog();
    $dir = operational_steps_backups_dir();
    $out = [];
    foreach (operational_steps_list_backup_files($dir) as $file) {
        $out[] = ['name' => $file, 'size' => (int) filesize($dir . '/' . $file), 'mtime' => gmdate('c', (int) filemtime($dir . '/' . $file))];
    }
    api_ok($out, ['total' => count($out)]);
});
