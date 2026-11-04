(function () {
        function renderAllFilledMessageHtml()
        {
            return (STS.data('sts-data-fill-orders') || {}).allFilledHtml || '';
        }

        function showAllFilledState()
        {
            const page = document.getElementById('fillOrdersPage');
            const autoAssignStatus = document.getElementById('autoAssignStatus');

            if (page) {
                page.outerHTML = '<div id="allFilledMessage">' + renderAllFilledMessageHtml() + '</div>';
            } else if (!document.getElementById('allFilledMessage')) {
                const container = document.querySelector('.container-fluid.px-4');
                if (container) {
                    const message = document.createElement('div');
                    message.id = 'allFilledMessage';
                    message.innerHTML = renderAllFilledMessageHtml();
                    container.appendChild(message);
                }
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
            } else {
                updateCarTypeFilterOptions();
            }
        }

        function getOrderCarTypeOptions()
        {
            const codes = new Set();
            getVisibleOrderCards().forEach(function(card) {
                const code = (card.dataset.carCode || '').trim();
                if (code) {
                    codes.add(code);
                }
            });
            return Array.from(codes).sort(function(a, b) {
                return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
            });
        }

        function updateCarTypeFilterOptions()
        {
            const select = document.getElementById('filterCarType');
            if (!select) {
                return;
            }

            const previous = select.value;
            const codes = getOrderCarTypeOptions();

            select.innerHTML = '<option value="">All</option>';
            codes.forEach(function(code) {
                const option = document.createElement('option');
                option.value = code;
                option.textContent = code;
                select.appendChild(option);
            });

            if (previous && codes.includes(previous)) {
                select.value = previous;
            } else if (previous) {
                select.value = '';
                reloadExpandedOrderCars();
            }
        }

        function getSelectedCategories()
        {
            return getCarFilters().categories;
        }

        function getCarFilters()
        {
            const categories = [];
            document.querySelectorAll('.car-filter-input:checked').forEach(function(input) {
                categories.push(input.value);
            });

            return {
                categories: categories,
                current_station: document.getElementById('filterCarStation').value,
                current_location: document.getElementById('filterCarLocation').value,
                car_code: document.getElementById('filterCarType').value
            };
        }

        function carFiltersAreActive()
        {
            const filters = getCarFilters();
            return filters.categories.length < 4
                || !!filters.current_station
                || !!filters.current_location
                || !!filters.car_code;
        }

        function buildCarFilterQueryParams()
        {
            const filters = getCarFilters();
            const params = {};

            filters.categories.forEach(function(category, index) {
                params['categories[' + index + ']'] = category;
            });
            if (filters.current_station) {
                params.current_station = filters.current_station;
            }
            if (filters.current_location) {
                params.current_location = filters.current_location;
            }
            if (filters.car_code) {
                params.car_code = filters.car_code;
            }

            return params;
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
            updateCarTypeFilterOptions();
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

        function clearCarFilters()
        {
            document.getElementById('filterCarStation').value = '';
            document.getElementById('filterCarLocation').value = '';
            document.getElementById('filterCarType').value = '';
            document.querySelectorAll('.car-filter-input').forEach(function(input) {
                input.checked = true;
            });
            reloadExpandedOrderCars();
        }

        function reloadExpandedOrderCars()
        {
            document.querySelectorAll('.order-card').forEach(function(card) {
                if (!isOrderExpanded(card)) {
                    return;
                }
                loadAvailableCars(card, card.getAttribute('data-waybill'));
            });
        }

        function applyCarFilters()
        {
            reloadExpandedOrderCars();
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.order-filter').forEach(function(select) {
                select.addEventListener('change', applyOrderFilters);
            });
            document.querySelectorAll('.car-filter').forEach(function(select) {
                select.addEventListener('change', applyCarFilters);
            });
            document.querySelectorAll('.car-filter-input').forEach(function(input) {
                input.addEventListener('change', applyCarFilters);
            });
            document.querySelectorAll('.order-row-check').forEach(function(checkbox) {
                checkbox.addEventListener('change', updateCheckAllOrdersState);
            });
            updateCarTypeFilterOptions();
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
                autoAssignStatus.innerHTML = 'Select at least one car source in Car filters: Pool, Station, Priority, or System.';
                return;
            }

            const carFilters = getCarFilters();

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
                    waybills: selectedWaybills,
                    car_filters: carFilters
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
            const requestData = Object.assign({ waybill_number: waybill }, buildCarFilterQueryParams());

            $.ajax({
                url: 'get_available_cars_ajax.php',
                type: 'GET',
                data: requestData,
                dataType: 'json',
                success: function(data) {
                    let html = '';

                    if (data.total_cars_found === 0) {
                        if (data.total_cars_unfiltered > 0 && carFiltersAreActive()) {
                            html = '<div class="alert alert-warning mb-0">'
                                + data.total_cars_unfiltered + ' eligible cars found, but none match the current car filters.'
                                + '</div>';
                        } else {
                            html = '<div class="alert alert-warning mb-0">No eligible cars found on the system</div>';
                        }
                    } else {
                        const filterNote = (data.total_cars_unfiltered > data.total_cars_found)
                            ? ' <span class="text-muted">(' + data.total_cars_unfiltered + ' before car filters)</span>'
                            : '';

                        html = `<div class="mb-3 text-muted">
                            <small>
                                <strong>${data.total_cars_found} eligible cars found${filterNote}:</strong><br/>
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

                            html += `<div class="car-row ${categoryClass}" data-sts-click="assignCar" data-waybill="${String(waybill).replace(/&/g, '&amp;').replace(/"/g, '&quot;')}" data-car-id="${car.car_id}">
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

            const cancelBtn = document.querySelector('button[data-sts-click="cancelCheckedOrders"]');
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
    

  STS.action('clearOrderFilters', function () { clearOrderFilters(); });
  STS.action('clearCarFilters', function () { clearCarFilters(); });
  STS.action('autoAssignAll', function () { autoAssignAll(); });
  STS.action('checkAllOrders', function () { checkAllOrders(); });
  STS.action('expandCheckedOrders', function () { expandCheckedOrders(); });
  STS.action('collapseCheckedOrders', function () { collapseCheckedOrders(); });
  STS.action('cancelCheckedOrders', function () { cancelCheckedOrders(); });
  STS.action('toggleOrderFromHeader', function (e, el) { toggleOrderFromHeader(e, el); });
  STS.action('orderRowCheck', function () { updateCheckAllOrdersState(); });
  STS.action('assignCar', function (e, el) {
    assignCar(el.getAttribute('data-waybill'), Number(el.getAttribute('data-car-id')), el);
  });
})();
