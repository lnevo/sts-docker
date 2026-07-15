<?php
/**
 * Shared automatic car-order generation (generate.php AUTOMATIC button + workflow API).
 */

function generate_orders_get_session($dbc)
{
    if (!function_exists('session_get_db_session')) {
        require_once __DIR__ . '/session_runtime.php';
    }
    return (int) session_get_db_session($dbc);
}

function generate_orders_set_session($dbc, $session_number)
{
    $session_number = (int) $session_number;
    mysqli_query(
        $dbc,
        'UPDATE settings SET setting_value = ' . $session_number . ' WHERE setting_name = "session_nbr"'
    );
    return $session_number;
}

function generate_orders_get_next_auto_waybill_counter($dbc, $session_number)
{
    $session_prefix = str_pad((int) $session_number, 3, '0', STR_PAD_LEFT) . '-';
    $session_prefix = mysqli_real_escape_string($dbc, $session_prefix);
    $sql = 'SELECT MAX(CAST(SUBSTR(waybill_number, 5, 3) AS UNSIGNED)) AS max_counter
            FROM car_orders
            WHERE waybill_number LIKE "' . $session_prefix . '___"
              AND SUBSTR(waybill_number, 5, 1) != "M"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    if (!$row || $row['max_counter'] === null) {
        return 0;
    }
    return (int) $row['max_counter'];
}

/** Optional RNG seed for reproducible automatic generation (empty = PHP default). */
function generate_orders_parse_seed($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (!ctype_digit($value)) {
        return null;
    }
    return (int) $value;
}

function generate_orders_apply_seed($seed)
{
    $parsed = generate_orders_parse_seed($seed);
    if ($parsed === null) {
        return null;
    }
    mt_srand($parsed);
    return $parsed;
}

/**
 * Optional scarce-lane pause (env, for traffic experiments):
 *   STS_SCARCE_PAUSE_UNFILLED=35
 *   STS_SCARCE_PAUSE_CODES=HC,XM,FM,GA,GD,FC   (comma list; empty = default set)
 * When unfilled exceeds the threshold, due shipments of those car codes are
 * skipped without advancing last_ship_date (true pause). Coke lanes exempt.
 */
function generate_orders_scarce_pause_codes()
{
    $raw = getenv('STS_SCARCE_PAUSE_CODES');
    if ($raw === false || trim((string) $raw) === '') {
        return ['HC', 'XM', 'FM', 'GA', 'GD', 'FC'];
    }
    $codes = [];
    foreach (explode(',', (string) $raw) as $c) {
        $c = strtoupper(trim($c));
        if ($c !== '') {
            $codes[$c] = true;
        }
    }
    return array_keys($codes);
}

function generate_orders_scarce_pause_active($dbc)
{
    $threshold = getenv('STS_SCARCE_PAUSE_UNFILLED');
    if ($threshold === false || $threshold === '' || !ctype_digit((string) $threshold)) {
        return false;
    }
    $threshold = (int) $threshold;
    if ($threshold <= 0) {
        return false;
    }
    return generate_orders_count_unfilled($dbc) > $threshold;
}

/**
 * Core automatic generation loop (matches generate.php).
 *
 * $max_new (soft cap): when > 0, generate at most this many new car orders this
 * session. Due shipments are processed in randomized order and generation stops
 * once the cap is reached; shipments not reached keep their last_ship_date so they
 * stay due and fire in a later session. This spreads full demand smoothly across
 * sessions (bounded per-session intake) instead of the all-or-nothing skip that a
 * gate / max_unfilled performs, which avoids the burst-then-starve sawtooth.
 * $max_new = 0 (default) preserves the original uncapped, id-ordered behavior.
 */
function generate_orders_run_automatic($dbc, $session_number, $waybill_counter = 0, $seed = null, $max_new = 0)
{
    $applied_seed = generate_orders_apply_seed($seed);

    $orders_created = 0;
    $session_number = (int) $session_number;
    $max_new = max(0, (int) $max_new);
    $scarce_pause = generate_orders_scarce_pause_active($dbc);
    $scarce_codes = $scarce_pause ? array_fill_keys(generate_orders_scarce_pause_codes(), true) : [];

    $rs_shipments = mysqli_query(
        $dbc,
        'SELECT s.id, s.last_ship_date, s.min_interval, s.max_interval, s.min_amount, s.max_amount,
                s.code AS shipment_code, cc.code AS car_code
         FROM shipments s
         LEFT JOIN car_codes cc ON cc.id = s.car_code
         ORDER BY s.id'
    );
    if (!$rs_shipments) {
        return 0;
    }

    $shipments = [];
    while ($row = mysqli_fetch_array($rs_shipments, MYSQLI_ASSOC)) {
        $shipments[] = $row;
    }

    // With a soft cap, randomize which due shipments get served first so no single
    // lane is perpetually starved when the cap bites.
    if ($max_new > 0) {
        for ($i = count($shipments) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $shipments[$i];
            $shipments[$i] = $shipments[$j];
            $shipments[$j] = $tmp;
        }
    }

    foreach ($shipments as $row) {
        if ($max_new > 0 && $orders_created >= $max_new) {
            break;
        }

        $interval = round(mt_rand($row['min_interval'] * 100, $row['max_interval'] * 100) / 100);
        $ship_date = (int) $row['last_ship_date'] + $interval;
        if ($ship_date > $session_number) {
            continue;
        }

        if ($scarce_pause) {
            $ship_code = (string) ($row['shipment_code'] ?? '');
            $car_code = strtoupper((string) ($row['car_code'] ?? ''));
            if (stripos($ship_code, 'COKE-') !== 0 && isset($scarce_codes[$car_code])) {
                // Leave last_ship_date alone so the lane stays due when backlog eases.
                continue;
            }
        }

        mysqli_query(
            $dbc,
            'UPDATE shipments SET last_ship_date = ' . $session_number . ' WHERE id = "' . (int) $row['id'] . '"'
        );

        $num_cars = round(mt_rand($row['min_amount'] * 100, $row['max_amount'] * 100) / 100);
        for ($i = 0; $i < $num_cars; $i++) {
            $waybill_counter++;
            $wb_nbr = str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-'
                . str_pad($waybill_counter, 3, '0', STR_PAD_LEFT);
            if (mysqli_query(
                $dbc,
                'INSERT INTO car_orders (waybill_number, shipment, car) VALUES ("'
                . mysqli_real_escape_string($dbc, $wb_nbr) . '", "' . (int) $row['id'] . '", "0")'
            )) {
                $orders_created++;
            }
        }
    }

    if ($applied_seed !== null) {
        // Avoid leaking seeded RNG state to later steps in the same request.
        mt_srand();
    }

    return $orders_created;
}

/**
 * Resolve session + waybill counter for automatic generation.
 *
 * increment_session=Yes (default): same as generate.php "Generate Session" — advance session, counter 0.
 * increment_session=No: same as "Generate Orders" — keep session, continue waybill counter.
 * Session 0 with No: still opens session 1 (nothing to generate at session 0).
 */
function generate_orders_resolve_automatic_run($dbc, array $gen_params)
{
    $session = generate_orders_get_session($dbc);
    $increment = ($gen_params['increment_session'] ?? '') === '1';

    if ($increment) {
        $session = generate_orders_set_session($dbc, $session + 1);
        return ['session' => $session, 'counter' => 0, 'incremented' => true];
    }

    if ($session <= 0) {
        $session = generate_orders_set_session($dbc, 1);
        return ['session' => $session, 'counter' => 0, 'incremented' => true];
    }

    return [
        'session' => $session,
        'counter' => generate_orders_get_next_auto_waybill_counter($dbc, $session),
        'incremented' => false,
    ];
}

function generate_orders_count_unfilled($dbc)
{
    $rs = mysqli_query($dbc, 'SELECT COUNT(*) AS c FROM car_orders WHERE car = "" OR car IS NULL OR car = "0"');
    if (!$rs) {
        return 0;
    }
    $row = mysqli_fetch_array($rs);
    return (int) ($row['c'] ?? 0);
}
