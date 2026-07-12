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

    $rs_shipments = mysqli_query(
        $dbc,
        'SELECT id, last_ship_date, min_interval, max_interval, min_amount, max_amount
         FROM shipments
         ORDER BY id'
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

/** Outbound coke shipment codes (singles + bulk lanes). */
function generate_orders_outbound_coke_shipment_codes()
{
    return ['COKE-USS', 'COKE-CLEV', 'COKE-USS-BULK', 'COKE-CLEV-BULK'];
}

/** Count unfilled car orders for outbound coke shipments. */
function generate_orders_count_unfilled_outbound_coke($dbc)
{
    $codes = generate_orders_outbound_coke_shipment_codes();
    $code_list = [];
    foreach ($codes as $code) {
        $code_list[] = '"' . mysqli_real_escape_string($dbc, $code) . '"';
    }
    $sql = 'SELECT COUNT(DISTINCT co.waybill_number) AS c
            FROM car_orders co
            INNER JOIN shipments s ON s.id = co.shipment
            WHERE (co.car = "" OR co.car IS NULL OR co.car = "0")
              AND s.code IN (' . implode(', ', $code_list) . ')';
    $rs = mysqli_query($dbc, $sql);
    if (!$rs) {
        return 0;
    }
    $row = mysqli_fetch_array($rs);
    return (int) ($row['c'] ?? 0);
}

/**
 * Top up unfilled outbound coke orders using single-car shipments when the open
 * pool falls below target_min. Alternates USS/CLEV lanes until target_min is met
 * or target_max would be exceeded.
 */
function generate_orders_replenish_coke_orders($dbc, $target_min = 6, $target_max = 8)
{
    require_once __DIR__ . '/session_helpers.php';

    $target_min = max(1, (int) $target_min);
    $target_max = max($target_min, (int) $target_max);
    $before = generate_orders_count_unfilled_outbound_coke($dbc);

    if ($before >= $target_min) {
        return [
            'generated' => 0,
            'before' => $before,
            'after' => $before,
            'target_min' => $target_min,
            'target_max' => $target_max,
            'skipped' => true,
            'reason' => 'Already at or above target minimum (' . $before . ' open)',
        ];
    }

    $alternate = ['COKE-USS', 'COKE-CLEV'];
    $generated = 0;
    $shipments_used = [];

    while (true) {
        $current = generate_orders_count_unfilled_outbound_coke($dbc);
        if ($current >= $target_min || $current >= $target_max) {
            break;
        }
        $code = $alternate[$generated % 2];
        $res = session_manual_generate_shipment($dbc, $code);
        $added = (int) ($res['generated'] ?? 0);
        if ($added < 1) {
            break;
        }
        $generated += $added;
        $shipments_used[] = $code;
    }

    $after = generate_orders_count_unfilled_outbound_coke($dbc);

    return [
        'generated' => $generated,
        'before' => $before,
        'after' => $after,
        'target_min' => $target_min,
        'target_max' => $target_max,
        'shipments' => $shipments_used,
    ];
}
