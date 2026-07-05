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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Fill Car Orders</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        .order-card {
            margin-bottom: 1rem;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
        }
        .order-card.pool {
            background-color: #ffff80;
        }
        .order-header {
            padding: 1rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #f8f9fa;
            border-radius: 0.375rem 0.375rem 0 0;
            gap: 0.75rem;
        }
        .order-header-main {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            min-width: 0;
            flex: 1 1 auto;
        }
        .order-row-check {
            margin-top: 0.2rem;
            flex-shrink: 0;
        }
        .order-header:hover {
            background-color: #e9ecef;
        }
        .order-details {
            padding: 1rem;
            border-top: 1px solid #dee2e6;
        }
        .car-row {
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .car-row:hover {
            border-color: #0d6efd;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
        }
        .car-row.pool {
            background-color: rgba(128, 128, 128, 0.3);
            color: white;
            background-color: gray;
        }
        .car-row.station {
            background-color: rgba(169, 169, 169, 0.3);
            background-color: darkgray;
            color: white;
        }
        .car-row.priority {
            background-color: rgba(211, 211, 211, 0.3);
            background-color: lightgray;
        }
        .car-row.system {
            background-color: white;
            border: 1px solid #dee2e6;
        }
        .car-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 0.5rem;
        }
        .load-count {
            background-color: #e9ecef;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.85rem;
            font-weight: bold;
            color: black;
        }
        .badge-category {
            font-size: 0.75rem;
            margin-left: 0.25rem;
        }
        .cars-container {
            max-height: 600px;
            overflow-y: auto;
        }
        .order-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .order-info-item {
            display: flex;
            flex-direction: column;
        }
        .order-info-label {
            font-weight: bold;
            color: #666;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        .order-info-value {
            font-size: 1rem;
            margin-top: 0.25rem;
        }
        .spinner-container {
            text-align: center;
            padding: 2rem;
        }
        .auto-assign-panel {
            min-width: 280px;
        }
        .auto-assign-options {
            border-top: 1px solid #dee2e6;
            padding-top: 0.75rem;
            margin-top: 0.75rem;
        }
        .order-card.filtered-out {
            display: none;
        }
        .filter-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #666;
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark noprint mb-3" style="background-color: #2e7d32;">
  <div class="container-fluid">
    <span class="navbar-brand"><i class="bi bi-box-seam"></i> Fill Car Orders</span>
    <div>
      <a href="operations.html" class="btn btn-outline-light btn-sm me-2">
        <i class="bi bi-arrow-left"></i> Operations
      </a>
      <a href="index.html" class="btn btn-outline-light btn-sm me-2">
        <i class="bi bi-house"></i> Home
      </a>
      <a href="index-t.html" class="btn btn-outline-light btn-sm">
        <i class="bi bi-diagram-3"></i> Site Map
      </a>
    </div>
  </div>
</nav>
    <div class="container-fluid px-4">
        <h5 class="mb-1">Fill Car Orders</h5>
        <p class="text-muted mb-3">Check orders to select them, expand to pick a car manually, or use Auto Assign on the selection.</p>

        <?php if (count($open_orders) > 0) { ?>
            <div id="openOrdersSummary" class="mb-4 p-3 bg-light border rounded">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                    <p class="mb-0">
                        <strong id="openOrdersCount"><?php echo count($open_orders); ?> open car orders</strong>
                        <span id="filteredOrdersNote" class="text-muted"></span><br/>
                        Click on any order below to see available cars. Check orders for bulk actions, or click a car to assign it.
                    </p>
                    <div class="auto-assign-panel">
                        <button id="autoAssignBtn" type="button" class="btn btn-success btn-lg w-100" onclick="autoAssignAll()">
                            <i class="bi bi-lightning-charge"></i> Auto Assign
                        </button>
                        <div class="auto-assign-options">
                            <div class="filter-label mb-1">Car source</div>
                            <div class="d-flex flex-wrap gap-3 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input auto-category" type="checkbox" value="pool" id="catPool" checked>
                                    <label class="form-check-label" for="catPool">Pool</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input auto-category" type="checkbox" value="priority" id="catPriority" checked>
                                    <label class="form-check-label" for="catPriority">Priority</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input auto-category" type="checkbox" value="station" id="catStation" checked>
                                    <label class="form-check-label" for="catStation">Station</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input auto-category" type="checkbox" value="system" id="catSystem">
                                    <label class="form-check-label" for="catSystem">System</label>
                                </div>
                            </div>
                            <div class="filter-label mb-1">Auto-assign order filters</div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small mb-1" for="filterLoading">Loading</label>
                                    <select id="filterLoading" class="form-select form-select-sm order-filter">
                                        <option value="">All loading locations</option>
                                        <?php foreach ($filter_loading_options as $value) { ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($value); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small mb-1" for="filterUnloading">Unloading</label>
                                    <select id="filterUnloading" class="form-select form-select-sm order-filter">
                                        <option value="">All unloading locations</option>
                                        <?php foreach ($filter_unloading_options as $value) { ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($value); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small mb-1" for="filterConsignment">Commodity</label>
                                    <select id="filterConsignment" class="form-select form-select-sm order-filter">
                                        <option value="">All commodities</option>
                                        <?php foreach ($filter_consignment_options as $value) { ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($value); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small mb-1" for="filterCarCode">Car type</label>
                                    <select id="filterCarCode" class="form-select form-select-sm order-filter">
                                        <option value="">All car types</option>
                                        <?php foreach ($filter_car_code_options as $value) { ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($value); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end mt-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearOrderFilters()">Clear filters</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="autoAssignStatus" class="alert d-none mb-3" role="alert"></div>

            <div class="d-flex flex-wrap align-items-center gap-3 mb-3 p-3 bg-white border rounded">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="checkAllOrders" onchange="checkAllOrders()">
                    <label class="form-check-label fw-semibold" for="checkAllOrders">Check all</label>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="expandCheckedOrders()">
                    <i class="bi bi-arrows-expand"></i> Expand selected
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="collapseCheckedOrders()">
                    <i class="bi bi-arrows-collapse"></i> Collapse selected
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="cancelCheckedOrders()">
                    <i class="bi bi-x-circle"></i> Cancel selected
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
                        <div class="order-header" onclick="toggleOrderFromHeader(event, this)">
                            <div class="order-header-main">
                                <input class="form-check-input order-row-check" type="checkbox"
                                       aria-label="Select order <?php echo htmlspecialchars($row['waybill_number']); ?>"
                                       onclick="event.stopPropagation(); updateCheckAllOrdersState();">
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
        <?php } else { ?>
            <div id="allFilledMessage">
                <?php echo render_all_filled_message(); ?>
            </div>
        <?php } ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function renderAllFilledMessageHtml()
        {
            return <?php echo json_encode(render_all_filled_message()); ?>;
        }

        function showAllFilledState()
        {
            const ordersContainer = document.getElementById('ordersContainer');
            const openOrdersSummary = document.getElementById('openOrdersSummary');
            const autoAssignStatus = document.getElementById('autoAssignStatus');

            if (openOrdersSummary) {
                openOrdersSummary.classList.add('d-none');
            }
            if (ordersContainer) {
                ordersContainer.innerHTML = renderAllFilledMessageHtml();
            }
            if (autoAssignStatus) {
                autoAssignStatus.classList.add('d-none');
            }
        }

        function updateOpenOrdersCount()
        {
            const remainingCount = document.querySelectorAll('.order-card').length;
            const visibleCount = document.querySelectorAll('.order-card:not(.filtered-out)').length;
            const countEl = document.getElementById('openOrdersCount');
            if (countEl) {
                countEl.textContent = remainingCount + ' open car orders';
            }
            updateFilteredOrdersNote(visibleCount, remainingCount);
            if (remainingCount === 0) {
                showAllFilledState();
            }
        }

        function getSelectedCategories()
        {
            const categories = [];
            document.querySelectorAll('.auto-category:checked').forEach(function(input) {
                categories.push(input.value);
            });
            return categories;
        }

        function getOrderFilters()
        {
            return {
                loading_location: document.getElementById('filterLoading').value,
                unloading_location: document.getElementById('filterUnloading').value,
                consignment: document.getElementById('filterConsignment').value,
                car_code: document.getElementById('filterCarCode').value
            };
        }

        function orderMatchesFilters(card, filters)
        {
            return (!filters.loading_location || card.dataset.loadingLocation === filters.loading_location)
                && (!filters.unloading_location || card.dataset.unloadingLocation === filters.unloading_location)
                && (!filters.consignment || card.dataset.consignment === filters.consignment)
                && (!filters.car_code || card.dataset.carCode === filters.car_code);
        }

        function getVisibleOrderCards()
        {
            return Array.from(document.querySelectorAll('.order-card:not(.filtered-out)'));
        }

        function getCheckedOrderCards()
        {
            return getVisibleOrderCards().filter(function(card) {
                const checkbox = card.querySelector('.order-row-check');
                return checkbox && checkbox.checked && !checkbox.disabled;
            });
        }

        function getSelectedWaybills()
        {
            return getCheckedOrderCards().map(function(card) {
                return card.dataset.waybill;
            });
        }

        function filtersAreActive()
        {
            const filters = getOrderFilters();
            return !!(filters.loading_location || filters.unloading_location || filters.consignment || filters.car_code);
        }

        function updateCheckAllOrdersState()
        {
            const checkAll = document.getElementById('checkAllOrders');
            if (!checkAll) return;

            const visibleChecks = getVisibleOrderCards()
                .map(function(card) { return card.querySelector('.order-row-check'); })
                .filter(function(checkbox) { return checkbox && !checkbox.disabled; });

            checkAll.checked = visibleChecks.length > 0 && visibleChecks.every(function(checkbox) { return checkbox.checked; });
            checkAll.indeterminate = visibleChecks.some(function(checkbox) { return checkbox.checked; })
                && !visibleChecks.every(function(checkbox) { return checkbox.checked; });
        }

        function checkAllOrders()
        {
            const checked = document.getElementById('checkAllOrders').checked;
            getVisibleOrderCards().forEach(function(card) {
                const checkbox = card.querySelector('.order-row-check');
                if (checkbox && !checkbox.disabled) {
                    checkbox.checked = checked;
                }
            });
            updateCheckAllOrdersState();
        }

        function syncFilteredOrderChecks()
        {
            const filtersActive = filtersAreActive();

            document.querySelectorAll('.order-card').forEach(function(card) {
                const checkbox = card.querySelector('.order-row-check');
                if (!checkbox) return;

                if (card.classList.contains('filtered-out')) {
                    checkbox.checked = false;
                    checkbox.disabled = true;
                    return;
                }

                checkbox.disabled = false;
                if (filtersActive) {
                    checkbox.checked = true;
                }
            });

            updateCheckAllOrdersState();
        }

        function applyOrderFilters()
        {
            const filters = getOrderFilters();
            let visibleCount = 0;

            document.querySelectorAll('.order-card').forEach(function(card) {
                const matches = orderMatchesFilters(card, filters);
                card.classList.toggle('filtered-out', !matches);
                if (matches) {
                    visibleCount++;
                }
            });

            const totalCount = document.querySelectorAll('.order-card').length;
            updateFilteredOrdersNote(visibleCount, totalCount);
            syncFilteredOrderChecks();
        }

        function clearOrderFilters()
        {
            document.getElementById('filterLoading').value = '';
            document.getElementById('filterUnloading').value = '';
            document.getElementById('filterConsignment').value = '';
            document.getElementById('filterCarCode').value = '';
            applyOrderFilters();
            document.querySelectorAll('.order-row-check').forEach(function(checkbox) {
                checkbox.checked = false;
                checkbox.disabled = false;
            });
            updateCheckAllOrdersState();
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.order-filter').forEach(function(select) {
                select.addEventListener('change', applyOrderFilters);
            });
            document.querySelectorAll('.order-row-check').forEach(function(checkbox) {
                checkbox.addEventListener('change', updateCheckAllOrdersState);
            });
        });

        function removeOrderCard(waybill)
        {
            const card = document.querySelector('[data-waybill="' + waybill + '"]');
            if (!card) {
                return;
            }

            card.style.transition = 'opacity 0.3s';
            card.style.opacity = '0';

            setTimeout(function() {
                card.remove();
                updateOpenOrdersCount();
                updateCheckAllOrdersState();
            }, 300);
        }

        function updateFilteredOrdersNote(visibleCount, totalCount)
        {
            const note = document.getElementById('filteredOrdersNote');
            if (!note) {
                return;
            }

            if (!filtersAreActive()) {
                note.textContent = '';
                return;
            }

            note.textContent = ' (' + visibleCount + ' match current filters)';
        }

        function autoAssignAll()
        {
            const autoAssignBtn = document.getElementById('autoAssignBtn');
            const autoAssignStatus = document.getElementById('autoAssignStatus');
            const categories = getSelectedCategories();
            const selectedWaybills = getSelectedWaybills();

            if (selectedWaybills.length === 0) {
                autoAssignStatus.className = 'alert alert-danger mb-3';
                autoAssignStatus.classList.remove('d-none');
                autoAssignStatus.innerHTML = 'Check one or more orders to auto assign.';
                return;
            }

            if (categories.length === 0) {
                autoAssignStatus.className = 'alert alert-danger mb-3';
                autoAssignStatus.classList.remove('d-none');
                autoAssignStatus.innerHTML = 'Select at least one car source: Pool, Priority, Station, or System.';
                return;
            }

            autoAssignBtn.disabled = true;
            autoAssignStatus.className = 'alert alert-info mb-3';
            autoAssignStatus.classList.remove('d-none');
            autoAssignStatus.innerHTML = '<div class="d-flex align-items-center gap-2">'
                + '<div class="spinner-border spinner-border-sm" role="status"></div>'
                + '<span>Auto assigning ' + selectedWaybills.length + ' selected order(s)...</span>'
                + '</div>';

            $.ajax({
                url: 'auto_fill_orders_ajax.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    categories: categories,
                    waybills: selectedWaybills
                },
                success: function(response) {
                    if (response.all_filled) {
                        showAllFilledState();
                        return;
                    }

                    let message = response.filled_count + ' car order(s) auto assigned.';
                    if (response.skipped_count > 0) {
                        message += ' ' + response.skipped_count + ' order(s) still need manual attention.';
                    }
                    autoAssignStatus.className = 'alert alert-warning mb-3';
                    autoAssignStatus.innerHTML = message;
                    autoAssignBtn.disabled = false;

                    response.filled.forEach(function(item) {
                        const card = document.querySelector('[data-waybill="' + item.waybill_number + '"]');
                        if (card) {
                            card.remove();
                        }
                    });

                    updateOpenOrdersCount();
                },
                error: function(error) {
                    autoAssignStatus.className = 'alert alert-danger mb-3';
                    autoAssignStatus.innerHTML = 'Error auto assigning cars. Please try again.';
                    autoAssignBtn.disabled = false;
                    console.error('Error:', error);
                }
            });
        }

        function toggleOrderFromHeader(event, headerElement)
        {
            if (event.target.closest('.order-row-check')) {
                return;
            }
            toggleOrder(headerElement);
        }

        function isOrderExpanded(card)
        {
            const details = card.querySelector('.order-details');
            return details && details.style.display !== 'none';
        }

        function expandOrderCard(card)
        {
            const header = card.querySelector('.order-header');
            const details = card.querySelector('.order-details');
            const chevron = header.querySelector('i');
            if (!details || isOrderExpanded(card)) {
                return;
            }

            details.style.display = 'block';
            chevron.classList.remove('bi-chevron-down');
            chevron.classList.add('bi-chevron-up');
            loadAvailableCars(card, card.getAttribute('data-waybill'));
        }

        function collapseOrderCard(card)
        {
            const header = card.querySelector('.order-header');
            const details = card.querySelector('.order-details');
            const chevron = header.querySelector('i');
            if (!details || !isOrderExpanded(card)) {
                return;
            }

            details.style.display = 'none';
            chevron.classList.add('bi-chevron-down');
            chevron.classList.remove('bi-chevron-up');
        }

        function expandCheckedOrders()
        {
            const checked = getCheckedOrderCards();
            if (checked.length === 0) {
                alert('Check one or more orders to expand.');
                return;
            }
            checked.forEach(expandOrderCard);
        }

        function collapseCheckedOrders()
        {
            const checked = getCheckedOrderCards();
            if (checked.length === 0) {
                alert('Check one or more orders to collapse.');
                return;
            }
            checked.forEach(collapseOrderCard);
        }

        function toggleOrder(headerElement) {
            const card = headerElement.closest('.order-card');
            if (isOrderExpanded(card)) {
                collapseOrderCard(card);
            } else {
                expandOrderCard(card);
            }
        }

        function loadAvailableCars(card, waybill) {
            const carsContainer = card.querySelector('.cars-container');

            $.ajax({
                url: 'get_available_cars_ajax.php',
                type: 'GET',
                data: { waybill_number: waybill },
                dataType: 'json',
                success: function(data) {
                    let html = '';

                    if (data.total_cars_found === 0) {
                        html = '<div class="alert alert-warning mb-0">No eligible cars found on the system</div>';
                    } else {
                        html = `<div class="mb-3 text-muted">
                            <small>
                                <strong>${data.total_cars_found} eligible cars found:</strong><br/>
                                <span class="badge" style="background-color: gray; color: white;">Pool: ${data.pool_count}</span>
                                <span class="badge" style="background-color: darkgray; color: white;">Station: ${data.station_count}</span>
                                <span class="badge" style="background-color: lightgray; color: black;">Priority: ${data.priority_count}</span>
                                <span class="badge bg-secondary">System: ${data.system_count}</span>
                            </small>
                        </div>`;

                        html += '<div class="car-grid">';
                        data.cars.forEach(car => {
                            const categoryClass = car.category;
                            const categoryLabel = {
                                'pool': 'Pool',
                                'station': 'Station',
                                'priority': 'Priority',
                                'system': 'System'
                            }[car.category] || 'System';

                            html += `<div class="car-row ${categoryClass}" onclick="assignCar('${waybill}', ${car.car_id}, this)">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong>${car.reporting_marks}</strong>
                                        <small class="badge-category badge bg-secondary">${categoryLabel}</small>
                                    </div>
                                    <div class="text-end">
                                        <div class="load-count">Load: ${car.load_count}</div>
                                    </div>
                                </div>
                                <small style="display: block; margin-top: 0.5rem;">
                                    <strong>Code:</strong> ${car.car_code}<br/>
                                    <strong>Location:</strong> <u>${car.current_station}</u> / ${car.current_location}
                                </small>
                                ${car.remarks ? `<small style="display: block; margin-top: 0.25rem; color: #666;"><strong>Remarks:</strong> ${car.remarks}</small>` : ''}
                            </div>`;
                        });
                        html += '</div>';
                    }

                    carsContainer.innerHTML = html;
                },
                error: function(error) {
                    carsContainer.innerHTML = '<div class="alert alert-danger">Error loading available cars. Please try again.</div>';
                    console.error('Error:', error);
                }
            });
        }

        function assignCar(waybill, carId, clickedElement) {
            // Show loading indicator
            const originalHtml = clickedElement.innerHTML;
            clickedElement.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Assigning...</span></div>';
            clickedElement.style.pointerEvents = 'none';

            $.ajax({
                url: 'assign_car_ajax.php',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    waybill_number: waybill,
                    car_id: carId
                }),
                dataType: 'json',
                success: function(response) {
                    removeOrderCard(waybill);
                },
                error: function(error) {
                    clickedElement.innerHTML = originalHtml;
                    clickedElement.style.pointerEvents = 'auto';
                    alert('Error assigning car. Please try again.');
                    console.error('Error:', error);
                }
            });
        }

        function cancelOrder(waybill)
        {
            return $.ajax({
                url: 'cancel_car_order_ajax.php',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    waybill_number: waybill
                }),
                dataType: 'json'
            }).done(function() {
                removeOrderCard(waybill);
            });
        }

        function cancelCheckedOrders()
        {
            const checked = getCheckedOrderCards();
            if (checked.length === 0) {
                alert('Check one or more orders to cancel.');
                return;
            }

            const waybills = checked.map(function(card) { return card.dataset.waybill; });
            if (!confirm('Cancel ' + waybills.length + ' selected car order(s)?')) {
                return;
            }

            const cancelBtn = document.querySelector('button[onclick="cancelCheckedOrders()"]');
            if (cancelBtn) {
                cancelBtn.disabled = true;
            }

            const requests = waybills.map(function(waybill) {
                return cancelOrder(waybill).fail(function(xhr) {
                    let message = 'Error canceling order ' + waybill + '.';
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        message = xhr.responseJSON.error;
                    }
                    alert(message);
                });
            });

            $.when.apply($, requests).always(function() {
                if (cancelBtn) {
                    cancelBtn.disabled = false;
                }
                updateCheckAllOrdersState();
            });
        }
    </script>

    <style>
        .bi-chevron-down::before, .bi-chevron-up::before {
            font-size: 1.5rem;
        }
    </style>
</body>
</html>
