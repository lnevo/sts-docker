<?php
/**
 * Edit routes (role: editor): whitelisted column updates for cars, jobs,
 * shipments, ownership and settings, plus saving/deleting workflows.
 * Field specs map API field name => [column, kind(, lookup)]. Kinds: int,
 * nint (nullable), str (max length), text, enum, ref (lookup by id or text).
 * Every query is a prepared statement; unknown fields are a 422.
 */
if (!defined('STS_API_BOOT')) {
    http_response_code(404);
    exit;
}

const EDIT_CAR_STATUSES = ['Empty', 'Ordered', 'Loading', 'Loaded', 'Unloading', 'Unavailable'];

/** Lookup targets for `ref` fields: [table, id column, text column, label]. */
const EDIT_LOOKUPS = [
    'location' => ['locations', 'Id', 'code', 'Location'],
    'car_code' => ['car_codes', 'Id', 'code', 'Car code'],
    'commodity' => ['commodities', 'Id', 'code', 'Commodity'],
    'job' => ['jobs', 'Id', 'name', 'Job'],
    'owner' => ['owners', 'id', 'name', 'Owner'],
];

function edit_car_fields()
{
    return [
        'reporting_marks' => ['reporting_marks', 'str', 16],
        'car_code_id' => ['car_code_id', 'ref', 'car_code'],
        'car_code' => ['car_code_id', 'ref', 'car_code'],
        'location_id' => ['current_location_id', 'ref', 'location'],
        'location' => ['current_location_id', 'ref', 'location'],
        'position' => ['position', 'nint'],
        'status' => ['status', 'enum', EDIT_CAR_STATUSES],
        'job_id' => ['handled_by_job_id', 'nref', 'job'],
        'job' => ['handled_by_job_id', 'nref', 'job'],
        'remarks' => ['remarks', 'text'],
        'load_count' => ['load_count', 'int'],
        'home_location_id' => ['home_location', 'nref', 'location'],
        'home_location' => ['home_location', 'nref', 'location'],
        'rfid_code' => ['RFID_code', 'nstr', 255],
        'block_id' => ['block_id', 'nint'],
        'last_spotted' => ['last_spotted', 'nint'],
    ];
}

function edit_shipment_fields()
{
    return [
        'code' => ['code', 'str', 255],
        'description' => ['description', 'str', 255],
        'commodity_id' => ['consignment', 'ref', 'commodity'],
        'commodity' => ['consignment', 'ref', 'commodity'],
        'car_code_id' => ['car_code', 'ref', 'car_code'],
        'car_code' => ['car_code', 'ref', 'car_code'],
        'loading_location_id' => ['loading_location', 'ref', 'location'],
        'loading_location' => ['loading_location', 'ref', 'location'],
        'unloading_location_id' => ['unloading_location', 'ref', 'location'],
        'unloading_location' => ['unloading_location', 'ref', 'location'],
        'last_ship_date' => ['last_ship_date', 'int'],
        'min_interval' => ['min_interval', 'int'],
        'max_interval' => ['max_interval', 'int'],
        'min_amount' => ['min_amount', 'int'],
        'max_amount' => ['max_amount', 'int'],
        'special_instructions' => ['special_instructions', 'nstr', 255],
        'remarks' => ['remarks', 'text'],
        'min_load_time' => ['min_load_time', 'nint'],
        'max_load_time' => ['max_load_time', 'nint'],
        'min_unload_time' => ['min_unload_time', 'nint'],
        'max_unload_time' => ['max_unload_time', 'nint'],
    ];
}

function edit_job_fields()
{
    return ['description' => ['description', 'text']];
}

function edit_is_int($v)
{
    return is_int($v) || (is_string($v) && preg_match('/^-?\d{1,10}$/', $v) === 1);
}

/** Resolve an id-or-text value against a lookup table; 422 when not found. */
function edit_resolve_ref($lookup, $v, $field)
{
    [$table, $idc, $txtc, $label] = EDIT_LOOKUPS[$lookup];
    if (is_bool($v) || (!is_int($v) && !is_string($v)) || trim((string) $v) === '') {
        api_error(422, "$field must be an id or $txtc");
    }
    $s = trim((string) $v);
    if (ctype_digit($s)) {
        $row = api_db_one("SELECT $idc AS id FROM $table WHERE $idc = ? OR $txtc = ? LIMIT 1", 'is', [(int) $s, $s]);
    } else {
        $row = api_db_one("SELECT $idc AS id FROM $table WHERE $txtc = ? LIMIT 1", 's', [$s]);
    }
    if (!$row) {
        api_error(422, "$label '$s' not found (field $field)");
    }
    return (int) $row['id'];
}

/**
 * Validate $body against $spec. Returns [column => [type, value]] ready to bind.
 * Two aliases for one column (location + location_id) is a 422.
 */
function edit_build_changes(array $spec, array $body)
{
    $cols = [];
    foreach ($body as $field => $v) {
        if (!isset($spec[$field])) {
            api_error(422, "Unknown or read-only field '$field' (allowed: " . implode(', ', array_keys($spec)) . ')');
        }
        $def = $spec[$field];
        [$col, $kind] = $def;
        if (isset($cols[$col])) {
            api_error(422, "Field '$field' repeats column '$col' (give one of its aliases)");
        }
        switch ($kind) {
            case 'int':
            case 'nint':
                if ($v === null && $kind === 'nint') {
                    $cols[$col] = ['i', null];
                    break;
                }
                if (!edit_is_int($v)) {
                    api_error(422, "$field must be an integer");
                }
                $cols[$col] = ['i', (int) $v];
                break;
            case 'str':
            case 'nstr':
                if ($v === null && $kind === 'nstr') {
                    $cols[$col] = ['s', null];
                    break;
                }
                if (!is_string($v) || ($kind === 'str' && trim($v) === '') || strlen($v) > $def[2]) {
                    api_error(422, "$field must be a " . ($kind === 'str' ? 'non-empty ' : '') . "string up to {$def[2]} characters");
                }
                $cols[$col] = ['s', $v];
                break;
            case 'text':
                if ($v !== null && !is_string($v)) {
                    api_error(422, "$field must be a string or null");
                }
                $cols[$col] = ['s', $v];
                break;
            case 'enum':
                if (!is_string($v) || !in_array($v, $def[2], true)) {
                    api_error(422, "$field must be one of: " . implode(', ', $def[2]));
                }
                $cols[$col] = ['s', $v];
                break;
            case 'ref':
                $cols[$col] = ['i', edit_resolve_ref($def[2], $v, $field)];
                break;
            case 'nref':
                $cols[$col] = ['i', $v === null ? null : edit_resolve_ref($def[2], $v, $field)];
                break;
        }
    }
    // Legacy convention: "none" is 0 / '' for these columns, never NULL.
    foreach (['handled_by_job_id' => ['i', 0], 'block_id' => ['i', 0], 'RFID_code' => ['s', '']] as $c => $none) {
        if (isset($cols[$c]) && $cols[$c][1] === null) {
            $cols[$c] = $none;
        }
    }
    return $cols;
}

/** UPDATE one row by key. Returns nothing; 409 on a duplicate-key failure. */
function edit_update($table, $key_col, $key, array $cols)
{
    $sets = [];
    $types = '';
    $params = [];
    foreach ($cols as $col => [$t, $val]) {
        $sets[] = "`$col` = ?";
        $types .= $t;
        $params[] = $val;
    }
    $types .= 'i';
    $params[] = $key;
    api_db_exec("UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `$key_col` = ?", $types, $params);
}

function edit_nonempty_body()
{
    $body = api_body();
    if ($body === []) {
        api_error(422, 'Request body must be a non-empty JSON object');
    }
    return $body;
}

function edit_marks_taken($marks, $except_id = 0)
{
    return api_db_value('SELECT Id FROM cars WHERE reporting_marks = ? AND Id <> ? LIMIT 1', 'si', [$marks, $except_id]) !== null;
}

/* ------------------------------------------------------------------ cars */

function edit_car_patch(array $req)
{
    $car = read_find_car($req['params']['car']);
    $cols = edit_build_changes(edit_car_fields(), edit_nonempty_body());
    if (isset($cols['reporting_marks'])) {
        $cols['reporting_marks'][1] = strtoupper(trim($cols['reporting_marks'][1]));
        if ($cols['reporting_marks'][1] === '') {
            api_error(422, 'reporting_marks must not be empty');
        }
        if (edit_marks_taken($cols['reporting_marks'][1], $car['id'])) {
            api_error(409, 'Another car already has those reporting marks');
        }
    }
    edit_update('cars', 'Id', $car['id'], $cols);
    return read_find_car((string) $car['id']);
}

function edit_car_create(array $req)
{
    $body = api_body();
    $extra = ['owner_id' => 1, 'owner' => 1, 'on_off_rr' => 1, 'pools' => 1];
    $car_body = array_diff_key($body, $extra);
    foreach (['reporting_marks'] as $need) {
        if (!isset($car_body[$need])) {
            api_error(422, "$need is required");
        }
    }
    if (!isset($car_body['car_code']) && !isset($car_body['car_code_id'])) {
        api_error(422, 'car_code (or car_code_id) is required');
    }
    if (!isset($car_body['location']) && !isset($car_body['location_id'])) {
        api_error(422, 'location (or location_id) is required');
    }
    $cols = edit_build_changes(edit_car_fields(), $car_body);
    $cols['reporting_marks'][1] = strtoupper(trim($cols['reporting_marks'][1]));
    if ($cols['reporting_marks'][1] === '') {
        api_error(422, 'reporting_marks must not be empty');
    }
    if (edit_marks_taken($cols['reporting_marks'][1])) {
        api_error(409, 'A car with those reporting marks already exists');
    }
    $defaults = [
        'status' => ['s', 'Empty'], 'load_count' => ['i', 0], 'last_spotted' => ['i', 0], 'position' => ['i', 0],
        'handled_by_job_id' => ['i', 0], 'block_id' => ['i', 0], 'RFID_code' => ['s', ''],
    ];
    foreach ($defaults as $c => $d) {
        $cols += [$c => $d];
    }
    // New cars start at their home location unless told otherwise.
    if (!array_key_exists('home_location', $cols)) {
        $cols['home_location'] = ['i', $cols['current_location_id'][1]];
    }
    $owner = null;
    if (isset($body['owner_id']) || isset($body['owner'])) {
        $owner = edit_resolve_ref('owner', $body['owner_id'] ?? $body['owner'], 'owner');
    }
    $onoff = $body['on_off_rr'] ?? 'on';
    if (!in_array($onoff, ['on', 'off'], true)) {
        api_error(422, "on_off_rr must be 'on' or 'off'");
    }
    $pools = $body['pools'] ?? [];
    if (!is_array($pools)) {
        api_error(422, 'pools must be a list of shipment ids');
    }
    foreach ($pools as $p) {
        if (!edit_is_int($p) || api_db_value('SELECT Id FROM shipments WHERE Id = ?', 'i', [(int) $p]) === null) {
            api_error(422, 'pools contains an unknown shipment id');
        }
    }

    $dbc = api_db();
    $dbc->begin_transaction();
    $names = [];
    $types = '';
    $params = [];
    foreach ($cols as $c => [$t, $v]) {
        $names[] = "`$c`";
        $types .= $t;
        $params[] = $v;
    }
    api_db_exec('INSERT INTO cars (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')', $types, $params);
    $id = (int) $dbc->insert_id;
    if ($owner !== null) {
        api_db_exec('INSERT INTO ownership (car_id, owner_id, on_off_rr) VALUES (?, ?, ?)', 'iis', [$id, $owner, $onoff]);
    }
    foreach (array_unique(array_map('intval', $pools)) as $p) {
        api_db_exec('INSERT INTO pool (car_id, shipment_id) VALUES (?, ?)', 'ii', [$id, $p]);
    }
    $dbc->commit();
    api_ok(read_find_car((string) $id), null, 201);
}

/* ------------------------------------------------------ jobs / shipments */

function edit_find_job($ident)
{
    $where = [];
    $types = '';
    $params = [];
    read_id_or_text_clause('jobs.Id', 'jobs.name', $ident, $where, $types, $params);
    $row = api_db_one('SELECT jobs.Id AS id FROM jobs WHERE ' . $where[0] . ' LIMIT 1', $types, $params);
    if (!$row) {
        read_not_found('Job');
    }
    return (int) $row['id'];
}

function edit_job_patch(array $req)
{
    $id = edit_find_job($req['params']['job']);
    $cols = edit_build_changes(edit_job_fields(), edit_nonempty_body());
    edit_update('jobs', 'Id', $id, $cols);
    return read_job_detail(['params' => ['job' => (string) $id]]);
}

function edit_shipment_patch(array $req)
{
    if (!ctype_digit($req['params']['id']) || api_db_value('SELECT Id FROM shipments WHERE Id = ?', 'i', [(int) $req['params']['id']]) === null) {
        read_not_found('Shipment');
    }
    $id = (int) $req['params']['id'];
    $cols = edit_build_changes(edit_shipment_fields(), edit_nonempty_body());
    edit_update('shipments', 'Id', $id, $cols);
    return read_shipment_detail(['params' => ['id' => (string) $id]]);
}

/* ------------------------------------------------- ownership / settings */

function edit_ownership_put(array $req)
{
    $car = read_find_car($req['params']['car']);
    $body = edit_nonempty_body();
    foreach (array_keys($body) as $k) {
        if (!in_array($k, ['owner', 'owner_id', 'on_off_rr'], true)) {
            api_error(422, "Unknown field '$k' (allowed: owner, owner_id, on_off_rr)");
        }
    }
    if (isset($body['owner']) && isset($body['owner_id'])) {
        api_error(422, 'Give owner or owner_id, not both');
    }
    $exists = api_db_one('SELECT owner_id, on_off_rr FROM ownership WHERE car_id = ? LIMIT 1', 'i', [$car['id']]);
    $owner = isset($body['owner_id']) || isset($body['owner'])
        ? edit_resolve_ref('owner', $body['owner_id'] ?? $body['owner'], 'owner')
        : ($exists ? (int) $exists['owner_id'] : null);
    $onoff = $body['on_off_rr'] ?? ($exists ? $exists['on_off_rr'] : 'on');
    if (!in_array($onoff, ['on', 'off'], true)) {
        api_error(422, "on_off_rr must be 'on' or 'off'");
    }
    if ($owner === null) {
        api_error(422, 'owner (or owner_id) is required for a car with no ownership row');
    }
    $dbc = api_db();
    $dbc->begin_transaction();
    if ($exists) {
        api_db_exec('UPDATE ownership SET owner_id = ?, on_off_rr = ? WHERE car_id = ?', 'isi', [$owner, $onoff, $car['id']]);
    } else {
        api_db_exec('INSERT INTO ownership (car_id, owner_id, on_off_rr) VALUES (?, ?, ?)', 'iis', [$car['id'], $owner, $onoff]);
    }
    $dbc->commit();
    $row = api_db_one(
        'SELECT ownership.car_id, cars.reporting_marks, ownership.owner_id, owners.name AS owner, ownership.on_off_rr
         FROM ownership LEFT JOIN cars ON cars.Id = ownership.car_id LEFT JOIN owners ON owners.id = ownership.owner_id
         WHERE ownership.car_id = ? LIMIT 1', 'i', [$car['id']]
    );
    return read_int_cols($row, ['car_id', 'owner_id']);
}

function edit_setting_put(array $req)
{
    $name = $req['params']['name'];
    if (!preg_match('/^[A-Za-z0-9_.\-]{1,200}$/', $name)) {
        api_error(422, 'Setting names are 1-200 characters of letters, digits, _ . -');
    }
    if ($name === 'session_nbr') {
        api_error(422, 'session_nbr is changed with POST /session/advance, not here');
    }
    $body = edit_nonempty_body();
    foreach (array_keys($body) as $k) {
        if (!in_array($k, ['value', 'description'], true)) {
            api_error(422, "Unknown field '$k' (allowed: value, description)");
        }
    }
    $existing = api_db_one('SELECT setting_desc FROM settings WHERE setting_name = ?', 's', [$name]);
    if (!array_key_exists('value', $body) || (!is_string($body['value']) && !is_int($body['value']) && !is_float($body['value'])) || strlen((string) $body['value']) > 256) {
        api_error(422, 'value is required: a string or number up to 256 characters');
    }
    $desc = $body['description'] ?? ($existing ? $existing['setting_desc'] : $name);
    if (!is_string($desc) || strlen($desc) > 256) {
        api_error(422, 'description must be a string up to 256 characters');
    }
    api_db_exec(
        'INSERT INTO settings (setting_name, setting_desc, setting_value) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_desc = VALUES(setting_desc), setting_value = VALUES(setting_value)',
        'sss', [$name, $desc, (string) $body['value']]
    );
    $row = api_db_one('SELECT setting_name AS name, setting_desc AS description, setting_value AS value FROM settings WHERE setting_name = ?', 's', [$name]);
    return read_settings_row($row);
}

/* ------------------------------------------------------------- workflows */

function edit_workflow_put(array $req)
{
    read_load_catalog();
    $body = api_body();
    foreach (array_keys($body) as $k) {
        if ($k !== 'recipe') {
            api_error(422, "Unknown field '$k' (allowed: recipe)");
        }
    }
    $recipe = $body['recipe'] ?? null;
    if (!is_array($recipe) || !isset($recipe['steps']) || !is_array($recipe['steps'])) {
        api_error(422, 'recipe must be an object with a steps list');
    }
    if (!isset($recipe['version'])) {
        $recipe['version'] = 1;
    }
    try {
        $file = operational_steps_sanitize_workflow_filename($req['params']['name']);
    } catch (InvalidArgumentException $e) {
        api_error(422, 'Invalid workflow name');
    }
    $dir = operational_steps_editor_dir();
    $path = operational_steps_workflow_path($dir, $file);
    $created = !is_file($path);
    $recipe = operational_steps_normalize_recipe($recipe);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        api_internal_error("mkdir failed: $dir");
    }
    // Saved without touching the active-workflow state (unlike the web editor).
    if (@file_put_contents($path, operational_steps_encode_recipe_json($recipe)) === false) {
        api_internal_error("workflow write failed: $path");
    }
    api_ok([
        'name' => $file,
        'created' => $created,
        'rows' => count(operational_steps_compile_recipe($recipe)),
        'recipe' => operational_steps_load_recipe($dir, $file),
    ], null, $created ? 201 : 200);
}

function edit_workflow_delete(array $req)
{
    [$dir, $file] = read_workflow_name_file($req['params']['name']);
    try {
        $r = operational_steps_delete_workflow($dir, $file);
    } catch (RuntimeException $e) {
        api_internal_error($e->getMessage());
    }
    return ['deleted' => $r['deleted'], 'active_workflow' => $r['active_workflow']];
}

api_route('PATCH', '/cars/{car}', 'edit_car_patch', 'editor');
api_route('POST', '/cars', 'edit_car_create', 'editor');
api_route('PATCH', '/jobs/{job}', 'edit_job_patch', 'editor');
api_route('PATCH', '/shipments/{id}', 'edit_shipment_patch', 'editor');
api_route('PUT', '/ownership/{car}', 'edit_ownership_put', 'editor');
api_route('PUT', '/settings/{name}', 'edit_setting_put', 'editor');
api_route('PUT', '/workflows/{name}', 'edit_workflow_put', 'editor');
api_route('DELETE', '/workflows/{name}', 'edit_workflow_delete', 'editor');
