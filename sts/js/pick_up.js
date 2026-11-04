(function () {
      // this javascript function is triggered by the user changing the "All" checkbox
      function checkall()
      {
        var visibleRows = document.querySelectorAll('#job_table tr.job-car-row:not([hidden])');
        var checked = document.getElementById('check_all').checked;
        visibleRows.forEach(function(row) {
          var checkbox = row.querySelector('.pickup-row-check');
          if (checkbox) {
            checkbox.checked = checked;
          }
        });
        if (typeof updatePickupLocationGroupHeaders === 'function') {
          updatePickupLocationGroupHeaders();
        }
      }
    
      // this javascript routine makes an HttpRequest that provides a list of cars in the selected
      // job including a checkbox that will indicate that the car was picked up by the job

      function get_jobs_and_cars()
      {
        // check to see if the job list has a job selected
        if (document.getElementById('job_list').value.length > 0)
        {
          // enable the pick up button
          document.getElementById("finish_btn").disabled = false;

          // submit the request for the cars at the selected station
          var xmlhttp = new XMLHttpRequest();
          xmlhttp.onreadystatechange = function()
          {
            if (this.readyState == 4 && this.status == 200)
            {
               populate_job_table(this);
            }
          }
          var url = 'get_cars_position_in_job.php?job=' + encodeURIComponent(document.getElementById('job_list').value);
          xmlhttp.open('GET', url, true);
          xmlhttp.send();
        }
      };

      // this is the call back function for the list of cars at the selected station
      function populate_job_table(xmlhttp)
      {
        if (xmlhttp.responseText == "None")
        {
          // get the name of the selected job
          job_name = document.getElementById("job_list").value;

          // tell the user that there aren't any cars in this job
          document.getElementById("instructions").classList.add("d-none");
          document.getElementById("job_table_div").innerHTML = "<tr><td>The switchlist for " + job_name + " doesn't contain any cars.</td></tr>";
          document.getElementById("bulk_pickup_location").innerHTML = getBulkPickupDefaultOptions();
          detachStationFilters('job_table', 'station_filters', 'station_filters_mount');
        }
        else
        {
          // make the instruction block visible
          document.getElementById("instructions").classList.remove("d-none");

          // display the table being returned from the server
          document.getElementById("job_table_div").innerHTML = xmlhttp.responseText;

          setTimeout(function() {
            populateStationFilters();
          }, 100);
        }
      }

      function populateStationFilters()
      {
        const rows = Array.from(document.querySelectorAll('#job_table tr.job-car-row'));
        const filters = document.getElementById('station_filters');

        if (!filters || rows.length === 0) {
          detachStationFilters('job_table', 'station_filters', 'station_filters_mount');
          return;
        }

        populateStationLocationFilterOptions('pickup_location_filter', 'pickupStation', 'pickupLocation', 'pickup stations / locations');
        populateStationFilterOptions('car_code_filter', 'carCode', 'car codes');
        populateStationFilterOptions('status_filter', 'status', 'statuses');
        populateStationFilterOptions('consignment_filter', 'consignment', 'consignments');
        populateStationLocationFilterOptions('final_destination_filter', 'finalDestinationStation', 'finalDestinationLocation', 'final destinations');
        populateStationLocationFilterOptions('loading_station_filter', 'loadingStation', 'loadingLocation', 'loading stations / locations');
        populateStationLocationFilterOptions('unloading_station_filter', 'unloadingStation', 'unloadingLocation', 'unloading stations / locations');
        document.getElementById('reporting_marks_filter').value = '';
        populateBulkPickupDropdown();
        mountStationFiltersInToolbar();
        applyStationFilters();
        attachPickupCheckboxListeners();
      }

      function mountStationFiltersInToolbar()
      {
        detachStationFilters('job_table', 'station_filters', 'station_filters_mount');
        const filters = document.getElementById('station_filters');
        if (filters) {
          filters.classList.remove('d-none');
        }
      }

      function populateStationLocationFilterOptions(selectId, stationKey, locationKey, allLabel)
      {
        const select = document.getElementById(selectId);
        const rows = Array.from(document.querySelectorAll('#job_table tr.job-car-row'));
        const stations = new Map();

        rows.forEach(row => {
          const station = row.dataset[stationKey];
          const location = row.dataset[locationKey];
          if (!station) return;

          if (!stations.has(station)) {
            stations.set(station, new Set());
          }
          if (location) {
            stations.get(station).add(location);
          }
        });

        select.innerHTML = '';

        const allOption = document.createElement('option');
        allOption.value = '';
        allOption.textContent = 'All';
        select.appendChild(allOption);

        Array.from(stations.keys()).sort().forEach(station => {
          const stationOption = document.createElement('option');
          stationOption.value = 'station::' + station;
          stationOption.textContent = station;
          select.appendChild(stationOption);

          Array.from(stations.get(station)).sort().forEach(location => {
            const locationOption = document.createElement('option');
            locationOption.value = 'location::' + location;
            locationOption.textContent = location;
            select.appendChild(locationOption);
          });
        });

        select.value = '';
      }

      function populateStationFilterOptions(selectId, datasetKey, allLabel)
      {
        const select = document.getElementById(selectId);
        const rows = Array.from(document.querySelectorAll('#job_table tr.job-car-row'));
        const stations = Array.from(new Set(rows.map(row => row.dataset[datasetKey]).filter(Boolean))).sort();

        select.innerHTML = '';

        const allOption = document.createElement('option');
        allOption.value = '';
        allOption.textContent = 'All';
        select.appendChild(allOption);

        stations.forEach(station => {
          const option = document.createElement('option');
          option.value = station;
          option.textContent = station;
          select.appendChild(option);
        });

        select.value = '';
      }

      function applyStationFilters()
      {
        const pickupLocationFilter = document.getElementById('pickup_location_filter').value;
        const reportingMarks = document.getElementById('reporting_marks_filter').value.trim().toLowerCase();
        const carCode = document.getElementById('car_code_filter').value;
        const status = document.getElementById('status_filter').value;
        const consignment = document.getElementById('consignment_filter').value;
        const finalDestinationFilter = document.getElementById('final_destination_filter').value;
        const loadingLocationFilter = document.getElementById('loading_station_filter').value;
        const unloadingLocationFilter = document.getElementById('unloading_station_filter').value;
        const rows = Array.from(document.querySelectorAll('#job_table tr.job-car-row'));
        let visibleCount = 0;

        rows.forEach(row => {
          const matchesPickup = matchesStationLocationFilter(row, pickupLocationFilter, 'pickupStation', 'pickupLocation');
          const matchesMarks = !reportingMarks || row.dataset.reportingMarks.toLowerCase().includes(reportingMarks);
          const matchesCarCode = !carCode || row.dataset.carCode === carCode;
          const matchesStatus = !status || row.dataset.status === status;
          const matchesConsignment = !consignment || row.dataset.consignment === consignment;
          const matchesFinalDestination = matchesStationLocationFilter(row, finalDestinationFilter, 'finalDestinationStation', 'finalDestinationLocation');
          const matchesLoading = matchesStationLocationFilter(row, loadingLocationFilter, 'loadingStation', 'loadingLocation');
          const matchesUnloading = matchesStationLocationFilter(row, unloadingLocationFilter, 'unloadingStation', 'unloadingLocation');
          const isVisible = matchesPickup && matchesMarks && matchesCarCode && matchesStatus && matchesConsignment && matchesFinalDestination && matchesLoading && matchesUnloading;

          row.hidden = !isVisible;
          const checkbox = row.querySelector('.pickup-row-check');
          if (checkbox) {
            checkbox.disabled = !isVisible;
          }
          if (isVisible) {
            visibleCount++;
          }
        });

        updatePickupLocationGroupHeaders();
        updateStationFilterCount(visibleCount, rows.length);
        updateCheckAllState();
      }

      function updatePickupLocationGroupHeaders()
      {
        updateTableGroupHeaderVisibility('job_table', 'pickup-group-header');
        updateLocationGroupHeaderStates('job_table', 'pickup-group-header', '.pickup-row-check');
      }

      function togglePickupLocationGroup(headerCheckbox)
      {
        toggleLocationGroupCheck(headerCheckbox, {
          tableId: 'job_table',
          rowCheckboxSelector: '.pickup-row-check',
          updateGroupHeaders: updatePickupLocationGroupHeaders,
          updateCheckAll: updateCheckAllState
        });
      }

      function attachPickupCheckboxListeners()
      {
        document.querySelectorAll('#job_table .pickup-row-check').forEach(function(checkbox) {
          checkbox.addEventListener('change', function() {
            updateCheckAllState();
            updatePickupLocationGroupHeaders();
          });
        });
      }

      function matchesStationLocationFilter(row, selectedValue, stationKey, locationKey)
      {
        if (!selectedValue) return true;
        if (selectedValue.indexOf('station::') === 0) {
          return row.dataset[stationKey] === selectedValue.substring(9);
        }
        if (selectedValue.indexOf('location::') === 0) {
          return row.dataset[locationKey] === selectedValue.substring(10);
        }
        return true;
      }

      function updateStationFilterCount(visibleCount, totalCount)
      {
        const count = document.getElementById('station_filter_count');
        if (count) {
          count.textContent = visibleCount + ' of ' + totalCount + ' cars shown';
        }
      }

      function updateCheckAllState()
      {
        const checkAll = document.getElementById('check_all');
        if (!checkAll) return;

        const visibleCheckboxes = Array.from(document.querySelectorAll('#job_table tr.job-car-row:not([hidden]) .pickup-row-check'));
        checkAll.checked = visibleCheckboxes.length > 0 && visibleCheckboxes.every(checkbox => checkbox.checked);
      }

      function clearStationFilters()
      {
        document.getElementById('pickup_location_filter').value = '';
        document.getElementById('reporting_marks_filter').value = '';
        document.getElementById('car_code_filter').value = '';
        document.getElementById('status_filter').value = '';
        document.getElementById('consignment_filter').value = '';
        document.getElementById('final_destination_filter').value = '';
        document.getElementById('loading_station_filter').value = '';
        document.getElementById('unloading_station_filter').value = '';
        applyStationFilters();
      }

      function getBulkPickupDefaultOptions() {
        return '<option value="">Select pickup location</option>';
      }

      function populateBulkPickupDropdown() {
        const bulkDropdown = document.getElementById('bulk_pickup_location');
        if (!bulkDropdown) return;
        bulkDropdown.innerHTML = getBulkPickupDefaultOptions();

        const locations = new Set();
        document.querySelectorAll('#job_table tr.job-car-row').forEach(function(row) {
          const location = row.dataset.pickupLocation;
          if (location) {
            locations.add(location);
          }
        });

        Array.from(locations).sort().forEach(function(location) {
          const option = document.createElement('option');
          option.value = location;
          option.textContent = location;
          bulkDropdown.appendChild(option);
        });
      }

      function checkRowsAtPickupLocation(selectedValue) {
        if (!selectedValue) {
          document.querySelectorAll('#job_table .pickup-row-check').forEach(function(checkbox) {
            checkbox.checked = false;
          });
          const checkAll = document.getElementById('check_all');
          if (checkAll) {
            checkAll.checked = false;
          }
          updatePickupLocationGroupHeaders();
          return;
        }

        document.querySelectorAll('#job_table tr.job-car-row:not([hidden])').forEach(function(row) {
          if (row.dataset.pickupLocation !== selectedValue) return;
          const checkbox = row.querySelector('.pickup-row-check');
          if (checkbox && !checkbox.disabled) {
            checkbox.checked = true;
          }
        });

        updateCheckAllState();
        updatePickupLocationGroupHeaders();
      }

    
document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("select").forEach(function(el){el.classList.add("form-select");el.style.removeProperty("width");if(el.closest("th")){el.classList.add("form-select-sm");}});});

  STS.action('get_jobs_and_cars', function () { get_jobs_and_cars(); });
  STS.action('checkall', function () { checkall(); });
  STS.action('togglePickupLocationGroup', function (e, el) { togglePickupLocationGroup(el); });
  STS.action('checkRowsAtPickupLocation', function (e, el) { checkRowsAtPickupLocation(el.value); });
  STS.action('applyStationFilters', function () { applyStationFilters(); });
  STS.action('clearStationFilters', function () { clearStationFilters(); });
})();
