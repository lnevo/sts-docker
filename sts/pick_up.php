<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Pick Up Cars</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="operations_ui.css" rel="stylesheet">
    <script src="operations_table_filters.js"></script>
    <style>
      tr {vertical-align: top;}
      th, td { font-size: 0.875rem; padding: 6px 8px; white-space: nowrap; }
      #job_table th,
      #job_table td {
        white-space: normal;
        word-break: keep-all;
        overflow-wrap: normal;
        hyphens: none;
      }
      @media (max-width: 1024px) {
        #job_table th,
        #job_table td {
          font-size: 0.8rem;
          padding: 4px 6px;
          line-height: 1.2;
        }

        /* Hide lower-priority detail columns on tablet to reduce horizontal scroll. */
        #job_table th:nth-child(6),
        #job_table td:nth-child(6),
        #job_table th:nth-child(7),
        #job_table td:nth-child(7),
        #job_table th:nth-child(8),
        #job_table td:nth-child(8) {
          display: none;
        }
      }
      @media print { .noprint {display:none;} }
      .status-empty    { display:inline-block; background-color:#ffeaa7; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-loaded   { display:inline-block; background-color:#a8e6cf; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-loading  { display:inline-block; background-color:#74b9ff; color:white;  padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-unloading{ display:inline-block; background-color:#fab1a0; color:white;  padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-ordered  { display:inline-block; background-color:#dfe6e9; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-unavailable{ display:inline-block; background-color:#d63031; color:white; padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
    </style>
    <?php
      // bring in the javascript function that shows rollingstock photos
      require 'show_image.php';
    ?>
    <script>
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
    </script>
  </head>
  <body class="bg-light">
    <nav class="navbar navbar-dark noprint mb-3" style="background-color: #2e7d32;">
      <div class="container-fluid">
        <span class="navbar-brand"><i class="bi bi-arrow-up-circle"></i> Pick Up Cars</span>
        <div>
          <a href="operations.html" class="btn btn-outline-light btn-sm me-2">
            <i class="bi bi-arrow-left"></i> Operations
          </a>
          <a href="index.html" class="btn btn-outline-light btn-sm me-2">
            <i class="bi bi-house"></i> Home
          </a>
          <button class="btn btn-light btn-sm noprint me-2" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
          </button>
          <a href="index-t.html" class="btn btn-outline-light btn-sm">
            <i class="bi bi-diagram-3"></i> Site Map
          </a>
        </div>
      </div>
    </nav>
    <div class="px-4">
    <h5 class="mb-3">Pick Up Cars</h5>
    <form action="pick_up.php" method="get">
    <?php
      // bring in the utility files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // get a database connection
      $dbc = open_db();

      // was the Finish button clicked?
      if (isset($_GET['finish_btn']))
      {
        $num_cars_picked_up = 0;
        // only try to pick cars if there were some to be picked up in the first place
        if (isset($_GET['row_count']))
        {
          // get the number of rows that were on the page
          $row_count = $_GET['row_count'];

          // update the position (set to 1) of all cars with a checkmark
          for ($i=0; $i<$row_count; $i++)
          {
            // construct the list and car field names
            $check_name = 'check' . $i;
            $car_name = 'car' . $i;

            // go through all of the check boxes and for cars with a checkmark set their position to 1
            if (isset($_GET[$check_name]))
            {
              // save the car's current location to give to the history file after the car's been picked up
              $sql = 'select current_location_id from cars where id = "' . $_GET[$car_name] . '"';
//print 'SQL: ' . $sql . '<br /><br />';
              $rs = mysqli_query($dbc, $sql);
              $row = mysqli_fetch_array($rs);
              $location = $row['current_location_id'];
//print 'location: ' . $location . '<br /><br />';
              /*            // and it's position to 0 (zero) so they appear at the top of the list when reorganizing the car order
                            $sql = 'update cars
                                    set current_location_id = "0",
                                        position="0"
                                    where id = "' . $_GET[$car_name] . '"';
              */

              // build a query to set the car's current location to 0 (zero) indicating that it's in a train
              // don't set the position to 0 because that undoes any organization performed by the user
              $sql = 'update cars
                      set current_location_id = "0"
                      where id = "' . $_GET[$car_name] . '"';

              if(!mysqli_query($dbc, $sql))
              {
                print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br />';
              }

              // get the info that the history table needs
              $sql = 'select setting_value from settings where setting_name = "session_nbr"';
              $rs = mysqli_query($dbc, $sql);
              $row = mysqli_fetch_array($rs);
              $session_nbr = $row['setting_value'];

              $sql = 'select jobs.name as job_name
                        from jobs, cars
                       where cars.id = "' . $_GET[$car_name] . '" and jobs.id = cars.handled_by_job_id';
//print 'SQL: ' . $sql . '<br /><br />';
              $rs = mysqli_query($dbc, $sql);
              $row = mysqli_fetch_array($rs);
              $job_name = $row['job_name'];

              // insert a car history record
              $sql = 'insert into history(car_id, session_nbr, event_date, event, location)
                      values ("' . $_GET[$car_name] . '",
                              "' . $session_nbr . '",
                              "' . date("Y-m-d H:i:s") . '",
                              "Picked up by Job ' . $job_name . '",
                              "' . $location . '")';

              if (!mysqli_query($dbc, $sql))
              {
                print 'Insert error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br /><br />';
              }
              else
              {
                $num_cars_picked_up++;
              }
            }
          }
        }
        print '<div class="alert alert-success noprint ops-workflow-alert">';
        print '<span>' . $num_cars_picked_up . ' car(s) picked up.</span>';
        print '<a class="btn btn-success" href="set_out.php">Go to Set Out Cars</a>';
        print '</div>';
      }
      print '<div class="noprint">';
      print '<p class="text-muted mb-1">Select a job to do the pickups:</p>';
      print drop_down_jobs("job_list", '', "get_jobs_and_cars();", "pickup");
    ?>
      <div id="instructions" class="ops-workflow noprint d-none">
        <div class="ops-panel">
          <p class="ops-panel-text mb-0">
            Check the cars picked up, then click <strong>PICK UP</strong>.
            After pickup, <a href="organize_cars.php">organize train positions</a> or
            <a href="display_switchlist.php">print an updated switch list</a>.
          </p>
          <div class="mt-3">
            <button id="finish_btn" name="finish_btn" value="PICK UP" type="submit" disabled
              class="btn btn-success btn-lg">PICK UP</button>
          </div>
        </div>
        <div class="ops-panel-action ops-panel-tools">
          <div class="ops-toolbar ops-toolbar-stack">
            <div class="ops-toolbar-section ops-toolbar-bulk">
              <label for="bulk_pickup_location" class="form-label fw-semibold mb-1">Check all boxes at:</label>
              <select id="bulk_pickup_location" name="bulk_pickup_location" class="form-select" style="max-width: 20rem;" onchange="checkRowsAtPickupLocation(this.value)">
                <option value="">Select pickup location</option>
              </select>
            </div>
            <div class="ops-toolbar-section ops-toolbar-filters">
              <?php require 'operations_station_filters.inc.php'; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div id="job_table_div">
      <!-- the guts of the table are filled in by the HttpRequest call-back function -->
    </div>
    </form>
  </body>

    <script>
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

    </script>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("select").forEach(function(el){el.classList.add("form-select");el.style.removeProperty("width");if(el.closest("th")){el.classList.add("form-select-sm");}});});</script>
  </body>

</html>
