<?php
require_once __DIR__ . '/track_scale_helpers.php';

function operations_get_stats($dbc)
{
    $stats = [
        'open_orders' => 0,
        'unfilled_orders' => 0,
        'unassigned' => 0,
        'pending_pickup' => 0,
        'pending_setout' => 0,
        'load_unload_pending' => 0,
        'reposition_off_home' => 0,
        'in_train' => 0,
        'scale_to_weigh' => 0,
    ];

    $queries = [
        'open_orders' => 'SELECT COUNT(DISTINCT waybill_number) AS cnt FROM car_orders',
        'unfilled_orders' => 'SELECT COUNT(DISTINCT waybill_number) AS cnt
                              FROM car_orders
                              WHERE car = "" OR car IS NULL',
        'unassigned' => 'SELECT COUNT(*) AS cnt
                         FROM cars
                         WHERE handled_by_job_id = 0
                           AND status IN ("Ordered", "Loaded")
                           AND current_location_id > 0',
        'pending_pickup' => 'SELECT COUNT(*) AS cnt
                             FROM cars
                             WHERE handled_by_job_id > 0
                               AND current_location_id > 0
                               AND status != "Unavailable"',
        'pending_setout' => 'SELECT COUNT(*) AS cnt
                             FROM cars
                             WHERE handled_by_job_id > 0
                               AND current_location_id = 0
                               AND status != "Unavailable"',
        'reposition_off_home' => 'SELECT COUNT(*) AS cnt
                                  FROM cars
                                  WHERE status = "Empty"
                                    AND NOT EXISTS (
                                      SELECT car_orders.car
                                      FROM car_orders
                                      WHERE cars.id = car_orders.car
                                    )
                                    AND current_location_id != home_location',
        'in_train' => 'SELECT COUNT(*) AS cnt
                       FROM cars
                       WHERE handled_by_job_id > 0
                         AND current_location_id = 0
                         AND status != "Unavailable"',
    ];

    foreach ($queries as $key => $sql) {
        $rs = mysqli_query($dbc, $sql);
        if ($rs && ($row = mysqli_fetch_array($rs))) {
            $stats[$key] = (int)$row['cnt'];
        }
    }

    $sql = 'SELECT COUNT(*) AS cnt
            FROM cars
            LEFT JOIN car_orders ON car_orders.car = cars.id
            WHERE (cars.status = "Loading")
               OR (cars.status = "Unloading")
               OR ((cars.status = "Empty") AND (cars.current_location_id = car_orders.shipment))';

    $rs = mysqli_query($dbc, $sql);
    if ($rs && ($row = mysqli_fetch_array($rs))) {
        $stats['load_unload_pending'] = (int)$row['cnt'];
    }

    $stats['scale_to_weigh'] = track_scale_count_weighable_cars($dbc);

    return $stats;
}

function operations_render_stat_column($label, $value)
{
    return '<div class="op-stat-col">'
         . '<div class="op-stat-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
         . '<div class="op-stat-value">' . (int)$value . '</div>'
         . '</div>';
}

function operations_render_stat_columns($columns)
{
    $count = count($columns);
    $class = 'op-btn-stat-cols' . ($count > 1 ? ' op-btn-stat-cols-multi' : '');

    $html = '<div class="' . $class . '">';
    foreach ($columns as $column) {
        $html .= operations_render_stat_column($column['label'], $column['value']);
    }
    $html .= '</div>';

    return $html;
}

?>
