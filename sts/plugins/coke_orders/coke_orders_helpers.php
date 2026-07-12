<?php

/** Outbound coke shipment codes (singles + bulk lanes). */
function coke_orders_outbound_shipment_codes()
{
    return ['COKE-USS', 'COKE-CLEV', 'COKE-USS-BULK', 'COKE-CLEV-BULK'];
}

/** Single-car outbound coke lanes used by replenish (non-BULK codes). */
function coke_orders_outbound_single_lane_codes()
{
    return array_values(array_filter(
        coke_orders_outbound_shipment_codes(),
        static function ($code) {
            return stripos((string) $code, '-BULK') === false;
        }
    ));
}

/** Count unfilled car orders for outbound coke shipments. */
function coke_orders_count_unfilled_outbound($dbc)
{
    $codes = coke_orders_outbound_shipment_codes();
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
 * pool falls below target_min. Alternates lanes until target_min is met or
 * target_max would be exceeded.
 */
function coke_orders_replenish($dbc, $target_min = 6, $target_max = 8)
{
    require_once dirname(__DIR__, 2) . '/session_helpers.php';

    $target_min = max(1, (int) $target_min);
    $target_max = max($target_min, (int) $target_max);
    $before = coke_orders_count_unfilled_outbound($dbc);

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

    $alternate = coke_orders_outbound_single_lane_codes();
    if ($alternate === []) {
        $alternate = coke_orders_outbound_shipment_codes();
    }
    $generated = 0;
    $shipments_used = [];

    while (true) {
        $current = coke_orders_count_unfilled_outbound($dbc);
        if ($current >= $target_min || $current >= $target_max) {
            break;
        }
        $code = $alternate[$generated % max(1, count($alternate))];
        $res = session_manual_generate_shipment($dbc, $code);
        $added = (int) ($res['generated'] ?? 0);
        if ($added < 1) {
            break;
        }
        $generated += $added;
        $shipments_used[] = $code;
    }

    $after = coke_orders_count_unfilled_outbound($dbc);

    return [
        'generated' => $generated,
        'before' => $before,
        'after' => $after,
        'target_min' => $target_min,
        'target_max' => $target_max,
        'shipments' => $shipments_used,
    ];
}
