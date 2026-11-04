(function () {
      // this script resets the drop down job list when the default set-out location checkbox is clicked
      function reset_job()
      {
        document.getElementById('job_list').selectedIndex = "0";
        document.getElementById('job_table_div').innerHTML = "";
        detachStationFilters('job_table', 'station_filters', 'station_filters_mount');
        hideFinalDestAssignStatus();
      }

      function checkall_setout()
      {
        const checked = document.getElementById('check_all').checked;
        document.querySelectorAll('#job_table tr.job-car-row:not([hidden]) .setout-row-check').forEach(function(checkbox) {
          checkbox.checked = checked;
          if (checked) {
            applyBulkLocationToRow(checkbox.closest('tr'));
          }
        });
        updateSetoutLocationGroupHeaders();
      }

      // this javascript routine makes an HttpRequest that provides a list of cars in the selected
      // train and each car will have a drop-down list of locations where it could set out

      function get_jobs_and_cars()
      {
        // check to see if the job list has a job selected
        if (document.getElementById('job_list').value.length > 0)
        {
          // enable the finish button
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
          // set a flag to send the value of the checkbox through
          if (document.getElementById('default_loc').checked == true)
          {
            var default_flag = 'Y';
          }
          else
          {
            var default_flag = 'N';
          }
          var url = 'get_cars_in_job.php?job=' + encodeURIComponent(document.getElementById('job_list').value) + '&default_loc=' + encodeURIComponent(default_flag);
          // alert(url);
          xmlhttp.open('GET', url, true);
          xmlhttp.send();
        }
      };

      // this is the call back function for the list of cars in the selected train
      function populate_job_table(xmlhttp)
      {
        if (xmlhttp.responseText == "None")
        {
          // get the name of the selected job
          job_name = document.getElementById("job_list").value;

          // tell the user that there aren't any cars in this job
          document.getElementById("job_table_div").innerHTML = "<tr><td>The switchlist for this job/train doesn't contain any cars.</td></tr>";
          detachStationFilters('job_table', 'station_filters', 'station_filters_mount');
        }
        else
        {
          // make the instruction block visible
          document.getElementById("instructions").classList.remove("d-none");

          // display the table being returned from the server
          document.getElementById("job_table_div").innerHTML = xmlhttp.responseText;

          // Add slight delay to ensure DOM is updated
          setTimeout(function() {
            populateBulkDropdown();
            populateStationFilters();
            hideFinalDestAssignStatus();
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
        mountStationFiltersInToolbar();
        applyStationFilters();
        attachSetoutCheckboxListeners();
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
          const stationList = row.querySelector('select[name^="station_list"]');
          if (stationList) {
            stationList.disabled = !isVisible;
          }
          const rowCheck = row.querySelector('.setout-row-check');
          if (rowCheck) {
            rowCheck.disabled = !isVisible;
          }
          if (isVisible) {
            visibleCount++;
          }
        });

        updateSetoutLocationGroupHeaders();
        updateStationFilterCount(visibleCount, rows.length);
        updateCheckAllSetoutState();
      }

      function updateSetoutLocationGroupHeaders()
      {
        updateTableGroupHeaderVisibility('job_table', 'setout-group-header');
        updateLocationGroupHeaderStates('job_table', 'setout-group-header', '.setout-row-check');
      }

      function toggleSetoutLocationGroup(headerCheckbox)
      {
        toggleLocationGroupCheck(headerCheckbox, {
          tableId: 'job_table',
          rowCheckboxSelector: '.setout-row-check',
          onRowChecked: applyBulkLocationToRow,
          updateGroupHeaders: updateSetoutLocationGroupHeaders,
          updateCheckAll: updateCheckAllSetoutState
        });
      }

      function updateCheckAllSetoutState()
      {
        const checkAll = document.getElementById('check_all');
        if (!checkAll) return;

        const visibleChecks = Array.from(document.querySelectorAll('#job_table tr.job-car-row:not([hidden]) .setout-row-check'));
        checkAll.checked = visibleChecks.length > 0 && visibleChecks.every(function(checkbox) { return checkbox.checked; });
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

    
      function getBulkLocationDefaultOptions() {
        return '<option value="">Select location</option>';
      }

      function bulkLocationValueToDropdownValue(selectedValue) {
        return selectedValue === '__keep__' ? '' : selectedValue;
      }

      function populateBulkDropdown() {
          const firstDropdown = document.querySelector('select[name^="station_list"]');
          if (!firstDropdown) return;
          const bulkDropdown = document.getElementById('bulk_location');
          if (!bulkDropdown) return;
          bulkDropdown.innerHTML = getBulkLocationDefaultOptions();

          const hasKeepInTrain = Array.from(firstDropdown.options).some(function(option) {
            return option.value === '';
          });
          if (hasKeepInTrain) {
            const keepOption = document.createElement('option');
            keepOption.value = '__keep__';
            keepOption.textContent = 'KEEP IN TRAIN';
            bulkDropdown.appendChild(keepOption);
          }

          Array.from(firstDropdown.options).forEach(function(option) {
              if (option.value) {
                  const newOption = document.createElement('option');
                  newOption.value = option.value;
                  newOption.text = option.text;
                  bulkDropdown.add(newOption);
              }
          });
      }

      function applyBulkLocationToRow(row) {
        const bulkLocation = document.getElementById('bulk_location')?.value;
        if (!bulkLocation || !row) return;
        const checkbox = row.querySelector('.setout-row-check');
        if (!checkbox || !checkbox.checked) return;
        const dropdown = row.querySelector('select[name^="station_list"]');
        if (!dropdown) return;
        const locationValue = bulkLocationValueToDropdownValue(bulkLocation);
        const optionExists = Array.from(dropdown.options).some(function(option) {
          return option.value === locationValue;
        });
        if (optionExists) {
          dropdown.value = locationValue;
        }
      }

      function attachSetoutCheckboxListeners() {
        document.querySelectorAll('#job_table .setout-row-check').forEach(function(checkbox) {
          checkbox.addEventListener('change', function() {
            if (this.checked) {
              applyBulkLocationToRow(this.closest('tr'));
            }
            updateCheckAllSetoutState();
            updateSetoutLocationGroupHeaders();
          });
        });
      }

      function updateAllLocations(selectedValue) {
          const locationValue = bulkLocationValueToDropdownValue(selectedValue);
          const dropdowns = document.querySelectorAll('#job_table tr.job-car-row:not([hidden]) select[name^="station_list"]');
          dropdowns.forEach(function(dropdown) {
              const rowCheckbox = dropdown.closest('tr')?.querySelector('.setout-row-check');
              if (rowCheckbox && !rowCheckbox.checked) return;
              if (selectedValue === '') {
                dropdown.value = '';
                return;
              }
              const optionExists = Array.from(dropdown.options).some(function(option) {
                return option.value === locationValue;
              });
              if (optionExists) {
                  dropdown.value = locationValue;
              }
          });
      }

      function hideFinalDestAssignStatus() {
          const status = document.getElementById('final_dest_assign_status');
          if (status) {
              status.classList.add('d-none');
              status.textContent = '';
          }
      }

      function assignFinalDestinations() {
          const rows = Array.from(document.querySelectorAll('#job_table tr.job-car-row:not([hidden])'));
          let assignedCount = 0;
          let skippedCount = 0;
          let uncheckedCount = 0;
          const skippedLabels = [];

          rows.forEach(function(row) {
              const checkbox = row.querySelector('.setout-row-check');
              if (!checkbox || !checkbox.checked) {
                  uncheckedCount++;
                  return;
              }

              const destinationId = row.dataset.finalDestinationId;
              if (!destinationId) {
                  skippedCount++;
                  return;
              }

              const dropdown = row.querySelector('select[name^="station_list"]');
              if (!dropdown) {
                  skippedCount++;
                  return;
              }

              const matchingOption = Array.from(dropdown.options).find(function(option) {
                return option.value === destinationId;
              });
              if (matchingOption) {
                  dropdown.value = destinationId;
                  assignedCount++;
              } else {
                  skippedCount++;
                  const label = row.dataset.finalDestinationLocation || row.dataset.reportingMarks;
                  if (label && skippedLabels.indexOf(label) === -1) {
                      skippedLabels.push(label);
                  }
              }
          });

          const status = document.getElementById('final_dest_assign_status');
          if (!status) return;

          status.classList.remove('d-none');
          const checkedCount = rows.length - uncheckedCount;
          if (checkedCount === 0) {
              status.textContent = 'Check one or more cars to assign final destinations.';
              return;
          }
          if (assignedCount === 0 && skippedCount === 0) {
              status.textContent = 'No checked cars have a final destination to assign.';
              return;
          }

          let message = assignedCount + ' checked car(s) assigned to final destination.';
          if (skippedCount > 0) {
              message += ' ' + skippedCount + ' checked car(s) skipped';
              if (skippedLabels.length > 0) {
                  message += ' (destination not available on this job: ' + skippedLabels.slice(0, 3).join(', ');
                  if (skippedLabels.length > 3) {
                      message += ', ...';
                  }
                  message += ')';
              } else {
                  message += ' (no final destination or location unavailable).';
              }
          }
          status.textContent = message;
      }
    
document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("select").forEach(function(el){el.classList.add("form-select");el.style.removeProperty("width");if(el.closest("th")){el.classList.add("form-select-sm");}});});

  STS.action('reset_job', function () { reset_job(); });
  STS.action('get_jobs_and_cars', function () { get_jobs_and_cars(); });
  STS.action('checkall_setout', function () { checkall_setout(); });
  STS.action('toggleSetoutLocationGroup', function (e, el) { toggleSetoutLocationGroup(el); });
  STS.action('bulkLocationChange', function (e, el) { updateAllLocations(el.value); });
  STS.action('assignFinalDestinations', function () { assignFinalDestinations(); });
  STS.action('applyStationFilters', function () { applyStationFilters(); });
  STS.action('clearStationFilters', function () { clearStationFilters(); });
})();
