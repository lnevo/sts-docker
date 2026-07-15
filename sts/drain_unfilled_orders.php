<?php
/**
 * Cancel unfilled revenue orders so the generate max_unfilled gate can reopen.
 *
 * When unfilled (non-E) count exceeds threshold, cancel orders until count <=
 * target. Optional keep-coke. Order: oldest_first (ASC waybill) or newest_first.
 *
 * Backward-compatible aliases: drain_unfilled_orders* functions.
 */

function cancel_orders_count_unfilled($dbc)
{
    $sql = 'SELECT COUNT(*) AS c FROM car_orders
            WHERE (car = "" OR car IS NULL OR car = "0")
              AND INSTR(waybill_number, "E") = 0';
    $rs = mysqli_query($dbc, $sql);
    $row = $rs ? mysqli_fetch_assoc($rs) : null;
    return (int) ($row['c'] ?? 0);
}

/**
 * @param array{
 *   threshold?:int|string,
 *   target?:int|string,
 *   keep_coke?:bool|string,
 *   order?:string
 * } $opts
 * @return array{
 *   before:int,after:int,canceled:int,waybills:list<string>,
 *   skipped:bool,reason?:string,order:string
 * }
 */
function cancel_orders($dbc, array $opts = [])
{
    $threshold = max(0, (int) ($opts['threshold'] ?? 40));
    $target = max(0, (int) ($opts['target'] ?? 30));
    if ($target > $threshold) {
        $target = $threshold;
    }

    $keep_coke_raw = $opts['keep_coke'] ?? true;
    if (is_string($keep_coke_raw)) {
        $keep_coke = !in_array(strtolower(trim($keep_coke_raw)), ['0', 'no', 'false', ''], true);
    } else {
        $keep_coke = (bool) $keep_coke_raw;
    }

    $order_raw = strtolower(trim((string) ($opts['order'] ?? 'oldest_first')));
    if (in_array($order_raw, ['newest', 'newest_first', 'desc', 'new'], true)) {
        $order = 'newest_first';
        $sql_order = 'DESC';
    } else {
        $order = 'oldest_first';
        $sql_order = 'ASC';
    }

    if (!function_exists('fill_order_cancel_order')) {
        require_once __DIR__ . '/fill_order_helpers.php';
    }

    $before = cancel_orders_count_unfilled($dbc);
    $result = [
        'before' => $before,
        'after' => $before,
        'canceled' => 0,
        'waybills' => [],
        'skipped' => false,
        'order' => $order,
    ];
    if ($before <= $threshold) {
        $result['skipped'] = true;
        $result['reason'] = 'Unfilled ' . $before . ' within threshold ' . $threshold;
        return $result;
    }

    $need = $before - $target;
    if ($need <= 0) {
        $result['skipped'] = true;
        $result['reason'] = 'Already at or below target ' . $target;
        return $result;
    }

    $coke_sql = $keep_coke ? ' AND s.code NOT LIKE "COKE-%"' : '';

    $sql = 'SELECT co.waybill_number
            FROM car_orders co
            INNER JOIN shipments s ON s.Id = co.shipment
            WHERE (co.car = "" OR co.car IS NULL OR co.car = "0")
              AND INSTR(co.waybill_number, "E") = 0'
        . $coke_sql . '
            ORDER BY co.waybill_number ' . $sql_order . '
            LIMIT ' . (int) $need;
    $rs = mysqli_query($dbc, $sql);
    if (!$rs) {
        $result['skipped'] = true;
        $result['reason'] = 'Query failed: ' . mysqli_error($dbc);
        return $result;
    }

    while ($row = mysqli_fetch_assoc($rs)) {
        $wb = (string) $row['waybill_number'];
        $cancel = fill_order_cancel_order($dbc, $wb);
        if (!empty($cancel['success'])) {
            $result['canceled']++;
            $result['waybills'][] = $wb;
        }
    }

    $result['after'] = cancel_orders_count_unfilled($dbc);
    return $result;
}

/** @deprecated Use cancel_orders_count_unfilled */
function drain_unfilled_orders_count($dbc)
{
    return cancel_orders_count_unfilled($dbc);
}

/** @deprecated Use cancel_orders */
function drain_unfilled_orders($dbc, array $opts = [])
{
    return cancel_orders($dbc, $opts);
}
