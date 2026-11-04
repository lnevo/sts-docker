<?php
require 'open_db.php';
require 'drop_down_list_functions.php';

function render_all_filled_message()
{
    return '<div class="alert alert-success d-flex align-items-center justify-content-between gap-3 flex-wrap">'
         . '<span><i class="bi bi-check-circle"></i> All car orders have been filled!</span>'
         . '<a class="btn btn-success" href="build_switchlists.php">Go to Build Switch Lists</a>'
         . '</div>';
}

$dbc = open_db();
require_once 'operations_stats.php';
$ops_session_nbr = operations_get_session_nbr($dbc);

// Pull in all open car orders
$sql = 'SELECT co.waybill_number as waybill_number,
               co.shipment as shipment_id,
               shipments.code as shipment,
               shipments.description as description,
               shipments.consignment as consignment_id,
               shipments.car_code as car_code_id,
               shipments.loading_location as loading_location_id,
               shipments.unloading_location as unloading_location_id,
               shipments.remarks,
               commodities.code as consignment,
               car_codes.code as car_code,
               loc01.code as loading_location,
               loc02.code as unloading_location,
               sta01.station as loading_station,
               sta02.station as unloading_station,
               (SELECT COUNT(*) FROM pool WHERE shipment_id = co.shipment) as pool_count
        FROM (
          SELECT DISTINCT waybill_number, shipment
          FROM car_orders
          WHERE car = "" OR car IS NULL OR car = "0"
        ) as co
        LEFT JOIN shipments ON shipments.id = co.shipment
        LEFT JOIN commodities ON commodities.id = shipments.consignment
        LEFT JOIN car_codes ON car_codes.id = shipments.car_code
        LEFT JOIN locations loc01 ON loc01.id = shipments.loading_location
        LEFT JOIN locations loc02 ON loc02.id = shipments.unloading_location
        LEFT JOIN routing sta01 ON sta01.id = loc01.station
        LEFT JOIN routing sta02 ON sta02.id = loc02.station
        ORDER BY co.waybill_number';

$rs = mysqli_query($dbc, $sql);
$open_orders = [];
while ($row = mysqli_fetch_array($rs)) {
    $open_orders[] = $row;
}

function fill_orders_unique_values($orders, $field)
{
    $values = [];
    foreach ($orders as $order) {
        $value = trim((string) ($order[$field] ?? ''));
        if ($value !== '') {
            $values[$value] = true;
        }
    }
    $keys = array_keys($values);
    sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
    return $keys;
}

$filter_loading_options = fill_orders_unique_values($open_orders, 'loading_location');
$filter_unloading_options = fill_orders_unique_values($open_orders, 'unloading_location');
$filter_consignment_options = fill_orders_unique_values($open_orders, 'consignment');
$filter_car_code_options = fill_orders_unique_values($open_orders, 'car_code');

$car_filter_station_options = [];
$car_filter_location_options = [];
$sql_car_filters = 'SELECT DISTINCT routing.station as current_station,
                           locations.code as current_location
                    FROM cars
                    LEFT JOIN locations ON locations.id = cars.current_location_id
                    LEFT JOIN routing ON routing.id = locations.station
                    WHERE cars.status = "Empty"
                      AND cars.id NOT IN (SELECT car FROM car_orders WHERE car != "" AND car IS NOT NULL AND car != "0")
                      AND locations.code IS NOT NULL
                    ORDER BY routing.station, locations.code';
$rs_car_filters = mysqli_query($dbc, $sql_car_filters);
while ($row = mysqli_fetch_array($rs_car_filters)) {
    $station = trim((string) ($row['current_station'] ?? ''));
    $location = trim((string) ($row['current_location'] ?? ''));
    if ($station !== '') {
        $car_filter_station_options[$station] = true;
    }
    if ($location !== '') {
        $car_filter_location_options[$location] = true;
    }
}
$car_filter_station_options = array_keys($car_filter_station_options);
$car_filter_location_options = array_keys($car_filter_location_options);
sort($car_filter_station_options, SORT_NATURAL | SORT_FLAG_CASE);
sort($car_filter_location_options, SORT_NATURAL | SORT_FLAG_CASE);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Fill Car Orders</title>
    <link href="vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="operations_ui.css?v=3" rel="stylesheet">
    <script src="/sts/js/sts-actions.js?v=1"></script>
</head>
<body class="bg-light ops-page-fill_orders">
    <?php
    require_once 'operations_nav.php';
    echo ops_render_navbar('fill_orders.php', 'Fill Car Orders', 'bi-box-seam');
    echo ops_render_step_bar('fill_orders.php', $ops_session_nbr);
    ?>
    <div class="container-fluid px-4">
        <h5 class="mb-3">Fill Car Orders</h5>

        <?php if (count($open_orders) > 0) { ?>
            <div class="fill-orders-page" id="fillOrdersPage">
                <div id="openOrdersSummary" class="fill-orders-summary">
                    <strong id="openOrdersCount"><?php echo count($open_orders); ?> open car orders</strong>
                    <span id="filteredOrdersNote" class="text-muted"></span>
                    <p>Expand an order to pick a car, or check orders and use Auto Assign.</p>
                </div>

                <div class="fill-orders-filters-row" id="fillOrdersFilters">
                    <div class="fill-filter-panel">
                        <div class="fill-filter-panel-title">Display</div>
                        <div class="fill-filter-panel-hint">Filter which orders appear in the list.</div>
                        <div class="fill-filter-grid">
                            <div>
                                <label class="form-label" for="filterLoading">Loading</label>
                                <select id="filterLoading" class="form-select form-select-sm order-filter">
                                    <option value="">All</option>
                                    <?php foreach ($filter_loading_options as $value) { ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($value); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="filterUnloading">Unloading</label>
                                <select id="filterUnloading" class="form-select form-select-sm order-filter">
                                    <option value="">All</option>
                                    <?php foreach ($filter_unloading_options as $value) { ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($value); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="filterConsignment">Commodity</label>
                                <select id="filterConsignment" class="form-select form-select-sm order-filter">
                                    <option value="">All</option>
                                    <?php foreach ($filter_consignment_options as $value) { ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($value); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="filterCarCode">Car type</label>
                                <select id="filterCarCode" class="form-select form-select-sm order-filter">
                                    <option value="">All</option>
                                    <?php foreach ($filter_car_code_options as $value) { ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($value); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="fill-filter-actions">
                            <button type="button" class="btn btn-outline-secondary btn-sm py-0" data-sts-click="clearOrderFilters">Clear</button>
                        </div>
                    </div>

                    <div class="fill-filter-panel auto-assign">
                        <div class="fill-filter-panel-title">Auto assign</div>

                        <div class="fill-auto-assign-sources">
                            <span class="fill-auto-assign-sources-label">Source</span>
                            <div class="fill-car-sources">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input car-filter-input" type="checkbox" value="pool" id="catPool" checked>
                                    <label class="form-check-label" for="catPool">Pool</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input car-filter-input" type="checkbox" value="priority" id="catPriority" checked>
                                    <label class="form-check-label" for="catPriority">Priority</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input car-filter-input" type="checkbox" value="station" id="catStation" checked>
                                    <label class="form-check-label" for="catStation">Station</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input car-filter-input" type="checkbox" value="system" id="catSystem" checked>
                                    <label class="form-check-label" for="catSystem">System</label>
                                </div>
                            </div>
                            <div class="fill-filter-actions">
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0" data-sts-click="clearCarFilters">Clear</button>
                            </div>
                        </div>

                        <div class="fill-car-filter-row">
                            <div class="fill-car-filter-field">
                                <label class="form-label" for="filterCarStation">Station</label>
                                <select id="filterCarStation" class="form-select form-select-sm car-filter">
                                    <option value="">All</option>
                                    <?php foreach ($car_filter_station_options as $value) { ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($value); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="fill-car-filter-field">
                                <label class="form-label" for="filterCarLocation">Location</label>
                                <select id="filterCarLocation" class="form-select form-select-sm car-filter">
                                    <option value="">All</option>
                                    <?php foreach ($car_filter_location_options as $value) { ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($value); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="fill-car-filter-field fill-car-filter-type">
                                <label class="form-label" for="filterCarType">Type</label>
                                <select id="filterCarType" class="form-select form-select-sm car-filter">
                                    <option value="">All</option>
                                </select>
                            </div>
                        </div>

                        <div class="fill-auto-assign-footer">
                            <button id="autoAssignBtn" type="button" class="btn btn-success" data-sts-click="autoAssignAll">
                                <i class="bi bi-lightning-charge"></i> Auto Assign
                            </button>
                        </div>
                    </div>
                </div>

                <div id="autoAssignStatus" class="alert d-none mb-0 py-2" role="alert"></div>

                <div class="fill-orders-bulk-toolbar">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="checkAllOrders" data-sts-change="checkAllOrders">
                        <label class="form-check-label fw-semibold" for="checkAllOrders">Check all</label>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-sts-click="expandCheckedOrders">
                        <i class="bi bi-arrows-expand"></i> Expand
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-sts-click="collapseCheckedOrders">
                        <i class="bi bi-arrows-collapse"></i> Collapse
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" data-sts-click="cancelCheckedOrders">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                </div>

                <div id="ordersContainer">
                <?php
                foreach ($open_orders as $row) {
                    $is_pool = $row['pool_count'] > 0 ? true : false;
                    $pool_class = $is_pool ? 'pool' : '';
                    ?>
                    <div class="order-card <?php echo $pool_class; ?>"
                         data-waybill="<?php echo htmlspecialchars($row['waybill_number']); ?>"
                         data-loading-location="<?php echo htmlspecialchars($row['loading_location']); ?>"
                         data-unloading-location="<?php echo htmlspecialchars($row['unloading_location']); ?>"
                         data-consignment="<?php echo htmlspecialchars($row['consignment']); ?>"
                         data-car-code="<?php echo htmlspecialchars($row['car_code']); ?>">
                        <div class="order-header" data-sts-click="toggleOrderFromHeader">
                            <div class="order-header-main">
                                <input class="form-check-input order-row-check" type="checkbox"
                                       aria-label="Select order <?php echo htmlspecialchars($row['waybill_number']); ?>"
                                       data-sts-click="orderRowCheck">
                                <div>
                                    <div style="font-weight: bold; font-size: 1.1rem;">
                                        <?php echo htmlspecialchars($row['waybill_number']); ?>
                                        <?php if ($is_pool) echo '<span class="badge bg-warning text-dark ms-2">Pool</span>'; ?>
                                    </div>
                                    <div style="font-size: 0.9rem; color: #666;">
                                        <?php echo htmlspecialchars($row['shipment']) . ' - ' . htmlspecialchars($row['description']); ?>
                                    </div>
                                </div>
                            </div>
                            <div style="font-size: 1.2rem; flex-shrink: 0;">
                                <i class="bi bi-chevron-down"></i>
                            </div>
                        </div>

                        <div class="order-details" style="display: none;">
                            <div class="order-info-grid">
                                <div class="order-info-item">
                                    <span class="order-info-label">Consignment</span>
                                    <span class="order-info-value"><?php echo htmlspecialchars($row['consignment'] ?: '(none)'); ?></span>
                                </div>
                                <div class="order-info-item">
                                    <span class="order-info-label">Car Code</span>
                                    <span class="order-info-value"><?php echo htmlspecialchars($row['car_code']); ?></span>
                                </div>
                                <div class="order-info-item">
                                    <span class="order-info-label">Loading</span>
                                    <span class="order-info-value">
                                        <u><?php echo htmlspecialchars($row['loading_station']); ?></u><br/>
                                        <?php echo htmlspecialchars($row['loading_location']); ?>
                                    </span>
                                </div>
                                <div class="order-info-item">
                                    <span class="order-info-label">Unloading</span>
                                    <span class="order-info-value">
                                        <u><?php echo htmlspecialchars($row['unloading_station']); ?></u><br/>
                                        <?php echo htmlspecialchars($row['unloading_location']); ?>
                                    </span>
                                </div>
                                <?php if ($row['remarks']) { ?>
                                    <div class="order-info-item" style="grid-column: 1/-1;">
                                        <span class="order-info-label">Remarks</span>
                                        <span class="order-info-value"><?php echo htmlspecialchars($row['remarks']); ?></span>
                                    </div>
                                <?php } ?>
                            </div>

                            <div class="mt-3">
                                <h6 class="mb-2">Available Cars:</h6>
                                <div class="cars-container">
                                    <div class="spinner-container">
                                        <div class="spinner-border" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2">Loading available cars...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                mysqli_close($dbc);
                ?>
                </div>
            </div>
        <?php } else { ?>
            <div id="allFilledMessage">
                <?php echo render_all_filled_message(); ?>
            </div>
        <?php } ?>
    </div>

    <script src="vendor/bootstrap/bootstrap.bundle.min.js"></script>
    <script src="vendor/jquery/jquery-3.6.0.min.js"></script>
    <script type="application/json" id="sts-data-fill-orders"><?php echo json_encode(['allFilledHtml' => render_all_filled_message()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script src="/sts/js/fill_orders.js?v=1"></script>

</body>
</html>
