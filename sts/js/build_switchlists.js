(function () {
      function go_to_auto_assign()
      {
        var job = document.getElementById("auto_assign_job").value;
        location.href = "auto_assign.php?job=" + job;
      }
    
      // this javascript routine makes an HttpRequest that provides a list of cars at the selected
      // station and each car will have a drop-down list of jobs that could add it to their pickup switchlist

      function get_cars_and_jobs()
      {
        // check to see if the selection from the station list is non-blank
        if (document.getElementById('station_list').value.length > 0)
        {
          // enable the build button
          document.getElementById("build_btn").disabled = false;

          // submit the request for the cars at the selected station
          var xmlhttp = new XMLHttpRequest();
          xmlhttp.onreadystatechange = function()
          {
            if (this.readyState == 4 && this.status == 200)
            {
               populate_car_table(this);
            }
          }
          var url = 'get_cars_at_station.php?station=' + encodeURIComponent(document.getElementById('station_list').value);
          xmlhttp.open('GET', url, true);
          xmlhttp.send();
        }
      };

      // this is the call back function for the list of cars at the selected station
      function populate_car_table(xmlhttp)
      {
        if (xmlhttp.responseText == "None")
        {
          // make the instruction block invisible
          document.getElementById("instructions").classList.add("d-none");

          // tell the user that there aren't any cars at this location that are ready to move
          document.getElementById("nothing_to_move").classList.remove("d-none");

          // hide the table that doesn't contain any cars
          document.getElementById("car_table_div").style.visibility = "hidden";
          document.getElementById("bulk_job").innerHTML = getBulkJobDefaultOptions();
          detachStationFilters('car_table', 'station_filters', 'station_filters_mount');
        }
        else
        {
          // make the instruction block visible
          document.getElementById("instructions").classList.remove("d-none");

          // hide the "nothing to move" div
          document.getElementById("nothing_to_move").classList.add("d-none");

          // display the table being returned from the server
          document.getElementById("car_table_div").innerHTML = xmlhttp.responseText;
          document.getElementById("car_table_div").style.visibility = "visible";

          // Populate after the table HTML exists.
          setTimeout(function() {
            populateBulkJobDropdown();
            populateStationFilters();
          }, 100);
        }
      }

      function populateStationFilters()
      {
        const rows = Array.from(document.querySelectorAll('#car_table tr.job-car-row'));
        const filters = document.getElementById('station_filters');

        if (!filters || rows.length === 0) {
          detachStationFilters('car_table', 'station_filters', 'station_filters_mount');
          return;
        }

        populateStationLocationFilterOptions('pickup_location_filter', 'pickupStation', 'pickupLocation');
        populateStationFilterOptions('car_code_filter', 'carCode');
        populateStationFilterOptions('status_filter', 'status');
        populateStationFilterOptions('consignment_filter', 'consignment');
        populateStationLocationFilterOptions('final_destination_filter', 'finalDestinationStation', 'finalDestinationLocation');
        populateStationLocationFilterOptions('loading_station_filter', 'loadingStation', 'loadingLocation');
        populateStationLocationFilterOptions('unloading_station_filter', 'unloadingStation', 'unloadingLocation');
        document.getElementById('reporting_marks_filter').value = '';
        mountStationFiltersInToolbar();
        applyStationFilters();
        attachBulkAssignCheckboxListeners();
      }

      function mountStationFiltersInToolbar()
      {
        detachStationFilters('car_table', 'station_filters', 'station_filters_mount');
        const filters = document.getElementById('station_filters');
        if (filters) {
          filters.classList.remove('d-none');
        }
      }

      function checkall_build()
      {
        const checked = document.getElementById('check_all').checked;
        document.querySelectorAll('#car_table tr.job-car-row:not([hidden]) .bulk-assign-row').forEach(function(checkbox) {
          checkbox.checked = checked;
          if (checked) {
            applyBulkJobToRow(checkbox.closest('tr'));
          }
        });
        updateBuildLocationGroupHeaders();
      }

      function populateStationLocationFilterOptions(selectId, stationKey, locationKey)
      {
        const select = document.getElementById(selectId);
        const rows = Array.from(document.querySelectorAll('#car_table tr.job-car-row'));
        const stations = new Map();

        rows.forEach(function(row) {
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

        Array.from(stations.keys()).sort().forEach(function(station) {
          const stationOption = document.createElement('option');
          stationOption.value = 'station::' + station;
          stationOption.textContent = station;
          select.appendChild(stationOption);

          Array.from(stations.get(station)).sort().forEach(function(location) {
            const locationOption = document.createElement('option');
            locationOption.value = 'location::' + location;
            locationOption.textContent = location;
            select.appendChild(locationOption);
          });
        });

        select.value = '';
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

      function populateStationFilterOptions(selectId, datasetKey)
      {
        const select = document.getElementById(selectId);
        const rows = Array.from(document.querySelectorAll('#car_table tr.job-car-row'));
        const values = Array.from(new Set(rows.map(function(row) { return row.dataset[datasetKey]; }).filter(Boolean))).sort();

        select.innerHTML = '';
        const allOption = document.createElement('option');
        allOption.value = '';
        allOption.textContent = 'All';
        select.appendChild(allOption);

        values.forEach(function(value) {
          const option = document.createElement('option');
          option.value = value;
          option.textContent = value;
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
        const rows = Array.from(document.querySelectorAll('#car_table tr.job-car-row'));
        let visibleCount = 0;

        rows.forEach(function(row) {
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
          const rowCheck = row.querySelector('.bulk-assign-row');
          if (rowCheck) {
            rowCheck.disabled = !isVisible;
          }
          const jobList = row.querySelector('select[name^="job_list"]');
          if (jobList) {
            jobList.disabled = !isVisible;
          }
          if (isVisible) {
            visibleCount++;
          }
        });

        updateBuildLocationGroupHeaders();
        updateStationFilterCount(visibleCount, rows.length);
        updateCheckAllBuildState();
      }

      function updateBuildLocationGroupHeaders()
      {
        updateTableGroupHeaderVisibility('car_table', 'location-group-header');
        updateLocationGroupHeaderStates('car_table', 'location-group-header', '.bulk-assign-row');
      }

      function toggleBuildLocationGroup(headerCheckbox)
      {
        toggleLocationGroupCheck(headerCheckbox, {
          tableId: 'car_table',
          rowCheckboxSelector: '.bulk-assign-row',
          onRowChecked: applyBulkJobToRow,
          updateGroupHeaders: updateBuildLocationGroupHeaders,
          updateCheckAll: updateCheckAllBuildState
        });
      }

      function updateStationFilterCount(visibleCount, totalCount)
      {
        const count = document.getElementById('station_filter_count');
        if (count) {
          count.textContent = visibleCount + ' of ' + totalCount + ' cars shown';
        }
      }

      function updateCheckAllBuildState()
      {
        const checkAll = document.getElementById('check_all');
        if (!checkAll) return;

        const visibleChecks = Array.from(document.querySelectorAll('#car_table tr.job-car-row:not([hidden]) .bulk-assign-row'));
        checkAll.checked = visibleChecks.length > 0 && visibleChecks.every(function(checkbox) { return checkbox.checked; });
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

    
      function getBulkJobDefaultOptions() {
        return '<option value="">Select train/job</option>';
      }

      function populateBulkJobDropdown() {
        const bulkDropdown = document.getElementById('bulk_job');
        if (!bulkDropdown) return;
        bulkDropdown.innerHTML = getBulkJobDefaultOptions();

        const jobs = new Map();
        document.querySelectorAll('#car_table select[name^="job_list"]').forEach(function(dropdown) {
          Array.from(dropdown.options).forEach(function(option) {
            if (option.value && !jobs.has(option.value)) {
              jobs.set(option.value, option.text);
            }
          });
        });

        Array.from(jobs.entries())
          .sort(function(a, b) { return a[1].localeCompare(b[1]); })
          .forEach(function(entry) {
            const newOption = document.createElement('option');
            newOption.value = entry[0];
            newOption.text = entry[1];
            bulkDropdown.add(newOption);
          });
      }

      function applyBulkJobToRow(row) {
        const bulkJob = document.getElementById('bulk_job')?.value;
        if (!bulkJob || !row) return;
        const checkbox = row.querySelector('.bulk-assign-row');
        if (!checkbox || !checkbox.checked) return;
        const dropdown = row.querySelector('select[name^="job_list"]');
        if (!dropdown) return;
        const optionExists = Array.from(dropdown.options).some(function(option) { return option.value === bulkJob; });
        if (optionExists) {
          dropdown.value = bulkJob;
        }
      }

      function attachBulkAssignCheckboxListeners() {
        document.querySelectorAll('#car_table .bulk-assign-row').forEach(function(checkbox) {
          checkbox.addEventListener('change', function() {
            if (this.checked) {
              applyBulkJobToRow(this.closest('tr'));
            }
            updateCheckAllBuildState();
            updateBuildLocationGroupHeaders();
          });
        });
      }

      function updateAllJobs(selectedValue) {
        const dropdowns = document.querySelectorAll('#car_table tr.job-car-row:not([hidden]) select[name^="job_list"]');
        dropdowns.forEach(function(dropdown) {
          const rowCheckbox = dropdown.closest('tr')?.querySelector('.bulk-assign-row');
          if (rowCheckbox && !rowCheckbox.checked) return;
          if (selectedValue === '') {
            dropdown.value = '';
            return;
          }
          const optionExists = Array.from(dropdown.options).some(function(option) { return option.value === selectedValue; });
          if (optionExists) {
            dropdown.value = selectedValue;
          }
        });
      }
    
document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("select").forEach(function(el){el.classList.add("form-select");el.style.removeProperty("width");if(el.closest("th")){el.classList.add("form-select-sm");}});});

  STS.action('go_to_auto_assign', function () { go_to_auto_assign(); });
  STS.action('get_cars_and_jobs', function () { get_cars_and_jobs(); });
  STS.action('bulkJobChange', function (e, el) { updateAllJobs(el.value); });
  STS.action('checkall_build', function () { checkall_build(); });
  STS.action('toggleBuildLocationGroup', function (e, el) { toggleBuildLocationGroup(el); });
  STS.action('applyStationFilters', function () { applyStationFilters(); });
  STS.action('clearStationFilters', function () { clearStationFilters(); });
})();
