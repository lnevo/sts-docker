<?php

require_once 'fill_order_helpers.php';

function db_cars_get_stats($dbc)
{
    $stats = [
        'total' => 0,
        'active' => 0,
        'loaded' => 0,
        'empty' => 0,
        'ordered' => 0,
        'unavailable' => 0,
        'in_train' => 0,
        'assigned_at_track' => 0,
        'unassigned_active' => 0,
        'repositioning' => 0,
        'in_pool' => 0,
        'with_orders' => 0,
        'available_for_orders' => 0,
    ];

    $queries = [
        'total' => 'SELECT COUNT(*) AS cnt FROM cars',
        'loaded' => 'SELECT COUNT(*) AS cnt FROM cars WHERE status = "Loaded"',
        'empty' => 'SELECT COUNT(*) AS cnt FROM cars WHERE status = "Empty"',
        'ordered' => 'SELECT COUNT(*) AS cnt FROM cars WHERE status = "Ordered"',
        'unavailable' => 'SELECT COUNT(*) AS cnt FROM cars WHERE status = "Unavailable"',
        'in_train' => 'SELECT COUNT(*) AS cnt
                       FROM cars
                       WHERE current_location_id = 0
                         AND status != "Unavailable"',
        'assigned_at_track' => 'SELECT COUNT(*) AS cnt
                                FROM cars
                                WHERE handled_by_job_id > 0
                                  AND current_location_id > 0
                                  AND status != "Unavailable"',
        'unassigned_active' => 'SELECT COUNT(*) AS cnt
                                FROM cars
                                WHERE handled_by_job_id = 0
                                  AND status IN ("Ordered", "Loaded")
                                  AND current_location_id > 0',
        'repositioning' => 'SELECT COUNT(*) AS cnt
                            FROM cars
                            LEFT JOIN car_orders ON car_orders.car = cars.id
                            WHERE (
                              (
                                cars.status = "Empty"
                                AND car_orders.car IS NULL
                                AND cars.home_location > 0
                                AND cars.current_location_id != cars.home_location
                              )
                              OR (
                                cars.status = "Ordered"
                                AND car_orders.waybill_number LIKE "%E%"
                              )
                            )',
        'in_pool' => 'SELECT COUNT(DISTINCT car_id) AS cnt FROM pool',
        'with_orders' => 'SELECT COUNT(DISTINCT car) AS cnt
                          FROM car_orders
                          WHERE car > 0',
    ];

    foreach ($queries as $key => $sql) {
        $rs = mysqli_query($dbc, $sql);
        if ($rs && ($row = mysqli_fetch_array($rs))) {
            $stats[$key] = (int)$row['cnt'];
        }
    }

    $stats['active'] = $stats['total'] - $stats['unavailable'];
    try {
        $stats['available_for_orders'] = fill_order_count_unique_available_cars($dbc);
    } catch (Throwable $e) {
        $stats['available_for_orders'] = 0;
    }

    return $stats;
}

function db_cars_render_stat_item($label, $value, $color_class = 'fleet-stat-value-default', $hint = '')
{
    $hint_attr = '';
    if (strlen($hint) > 0) {
        $hint_attr = ' title="' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '" data-bs-toggle="tooltip"';
    }

    return '<div class="fleet-stat-tile"' . $hint_attr . '>'
         . '<div class="fleet-stat-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
         . '<div class="fleet-stat-value ' . htmlspecialchars($color_class, ENT_QUOTES, 'UTF-8') . '">'
         . (int)$value
         . '</div>'
         . '</div>';
}

function db_cars_render_stat_group($title, $icon, $items)
{
    $count = count($items);
    $html = '<div class="col">'
          . '<div class="fleet-group">'
          . '<div class="fleet-section-header"><i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '"></i> '
          . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
          . '</div>'
          . '<div class="fleet-stat-row fleet-stat-row-' . (int)$count . '">';

    foreach ($items as $item) {
        $html .= db_cars_render_stat_item(
            $item['label'],
            $item['value'],
            $item['color'] ?? 'fleet-stat-value-default',
            $item['hint'] ?? ''
        );
    }

    $html .= '</div></div></div>';

    return $html;
}

function db_cars_render_stats_panel($stats)
{
    $sections = [
        [
            'title' => 'Status',
            'icon' => 'bi-circle-half',
            'items' => [
                ['label' => 'Loaded', 'value' => $stats['loaded'], 'color' => 'fleet-stat-value-success', 'hint' => 'Cars with a load in transit or spotted for unloading'],
                ['label' => 'Empty', 'value' => $stats['empty'], 'color' => 'fleet-stat-value-muted', 'hint' => 'Empty cars in the fleet'],
                ['label' => 'Ordered', 'value' => $stats['ordered'], 'color' => 'fleet-stat-value-warning', 'hint' => 'Cars with an active order (revenue load or reposition)'],
                ['label' => 'Unavailable', 'value' => $stats['unavailable'], 'color' => 'fleet-stat-value-danger', 'hint' => 'Out of service'],
            ],
        ],
        [
            'title' => 'Movement',
            'icon' => 'bi-arrows-move',
            'items' => [
                ['label' => 'In Train', 'value' => $stats['in_train'], 'color' => 'fleet-stat-value-info', 'hint' => 'Picked up — current location is on the train (location id 0)'],
                ['label' => 'On Job', 'value' => $stats['assigned_at_track'], 'color' => 'fleet-stat-value-primary', 'hint' => 'Assigned to a job/train but still at a track location'],
                ['label' => 'Unassigned', 'value' => $stats['unassigned_active'], 'color' => 'fleet-stat-value-default', 'hint' => 'Ordered or loaded cars not yet assigned to a job'],
                ['label' => 'Repositioning', 'value' => $stats['repositioning'], 'color' => 'fleet-stat-value-info', 'hint' => 'Empty cars away from home or E-waybill reposition orders'],
            ],
        ],
        [
            'title' => 'Cars',
            'icon' => 'bi-boxcar-front',
            'items' => [
                ['label' => 'Available', 'value' => $stats['available_for_orders'], 'color' => 'fleet-stat-value-success', 'hint' => 'Unique empty cars eligible for at least one unfilled car order (Fill Orders)'],
                ['label' => 'With Orders', 'value' => $stats['with_orders'], 'color' => 'fleet-stat-value-default', 'hint' => 'Cars linked to a car order / waybill'],
                ['label' => 'In Pool', 'value' => $stats['in_pool'], 'color' => 'fleet-stat-value-default', 'hint' => 'Cars in a pooling arrangement'],
            ],
        ],
    ];

    $html = '
  <div id="cars-stats-card" class="fleet-overview-wrap mb-4">
    <div class="fleet-overview-heading">
      <div class="fleet-overview-title">
        <i class="bi bi-speedometer2"></i>
        <span>Fleet Overview</span>
      </div>
      <div class="fleet-overview-chips">
        <span class="fleet-chip"><strong>' . (int)$stats['total'] . '</strong> total</span>
        <span class="fleet-chip"><strong>' . (int)$stats['active'] . '</strong> active</span>
        <span class="fleet-chip fleet-chip-available"><strong>' . (int)$stats['available_for_orders'] . '</strong> available</span>
      </div>
    </div>
    <div class="row g-4 fleet-columns row-cols-1 row-cols-md-3">';

    foreach ($sections as $section) {
        $html .= db_cars_render_stat_group($section['title'], $section['icon'], $section['items']);
    }

    $html .= '
    </div>
  </div>';

    return $html;
}

?>
