<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Build Switch Lists</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="operations_ui.css" rel="stylesheet">
    <script src="operations_table_filters.js"></script>
    <style>
      tr {vertical-align: top;}
      th, td { font-size: 0.875rem; padding: 6px 8px; white-space: nowrap; }
      @media print { .noprint {display:none;} }
      .status-empty    { display:inline-block; background-color:#ffeaa7; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-loaded   { display:inline-block; background-color:#a8e6cf; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-loading  { display:inline-block; background-color:#74b9ff; color:white;  padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-unloading{ display:inline-block; background-color:#fab1a0; color:white;  padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-ordered  { display:inline-block; background-color:#dfe6e9; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-unavailable{ display:inline-block; background-color:#d63031; color:white; padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
    </style>
    <script>
      function go_to_auto_assign()
      {
        $job = document.getElementById("auto_assign_job").value;
        location.href = "auto_assign.php?job=" + $job;
      }
    </script>
    <?php
      // bring in the javascript function that shows rollingstock photos
      require 'show_image.php';

      // bring in the utility files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

    ?>
  </head>
  <body class="bg-light">
    <nav class="navbar navbar-dark noprint mb-3" style="background-color: #2e7d32;">
      <div class="container-fluid">
        <span class="navbar-brand"><i class="bi bi-list-check"></i> Build Switch Lists</span>
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
    <div class="px-4 py-3">
  <h5 class="mb-3">Build Switch Lists</h5>
  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-geo-alt"></i> Assign Cars Station-by-Station</div>
        <div class="card-body">
          <p class="card-text text-muted small">Select a station (or All Stations) to assign cars to jobs/trains for pickup.</p>
          <?php print drop_down_stations('station_list', '', 'get_cars_and_jobs();', true); ?>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-robot"></i> Auto-Assign Cars</div>
        <div class="card-body">
          <p class="card-text text-muted small">Select a job/train and click AUTO-ASSIGN to automatically assign cars.</p>
          <div class="d-flex gap-2 flex-wrap align-items-center">
            <?php print drop_down_jobs("auto_assign_job", 2, ""); ?>
            <button type="button" class="btn btn-success" onclick="go_to_auto_assign();">AUTO-ASSIGN</button>
          </div>
        </div>
      </div>
    </div>
  </div>
    <form action="build_switchlists.php" method="POST">
    <?php
      // get a database connection
      $dbc = open_db();

      // was the Build button clicked?
      if ((isset($_POST['build_btn'])) && (isset($_POST['row_count'])))
      {
        // get the number of rows that were on the page
        $row_count = $_POST['row_count'];
        $num_cars_assigned = 0;

        // mark the cars selected for pickup by the user
        for ($i=0; $i<$row_count; $i++)
        {
          // construct the list and car field names
          $list_name = 'job_list' . $i;
          $car_name = 'car' . $i;

          // does the drop-down list have a job name in it?
          if (strlen($_POST[$list_name]) > 0)
          {
            // build a query to update the car's "handled_by" field
            $sql = 'update cars set handled_by_job_id = "' . $_POST[$list_name];
            $sql = $sql . '" where id = "' . $_POST[$car_name] . '"';

            if(!mysqli_query($dbc, $sql))
            {
              print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql;
            }
            else
            {
              $num_cars_assigned++;
            }

            // get the info that the history table needs
            $sql = 'select setting_value from settings where setting_name = "session_nbr"';
            $rs = mysqli_query($dbc, $sql);
            $row = mysqli_fetch_array($rs);
            $session_nbr = $row['setting_value'];

            $sql = 'select current_location_id from cars where id = "' . $_POST[$car_name] . '"';
            $rs = mysqli_query($dbc, $sql);
            $row = mysqli_fetch_array($rs);
            $location = $row['current_location_id'];

            $sql = 'select name from jobs where id = ' . $_POST[$list_name];
//print 'SQL: ' . $sql . ' $_POST[$list_name]; ' . $_POST[$list_name] . '<br /><br />';
            $rs = mysqli_query($dbc, $sql);
            $row = mysqli_fetch_array($rs);
            $job_name = $row['name'];

            // insert a car history record
            $sql = 'insert into history(car_id, session_nbr, event_date, event, location)
                    values ("' . $_POST[$car_name] . '",
                            "' . $session_nbr . '",
                            "' . date("Y-m-d H:i:s") . '",
                            "Assigned to Job ' . $job_name . '",
                            "' . $location . '")';

            if (!mysqli_query($dbc, $sql))
            {
              print 'Insert error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br /><br />';
            }
          }
        }
        print '<div class="alert alert-success noprint ops-workflow-alert">';
        print '<span>' . $num_cars_assigned . ' car(s) assigned to pickup jobs.</span>';
        print '<a class="btn btn-success" href="pick_up.php">Go to Pick Up Cars</a>';
        print '</div>';
      }
    ?>
    <div id="nothing_to_move" class="ops-empty-note d-none">
      There are no cars at this location that are ready to move.
    </div>
    <div id="instructions" class="ops-workflow noprint d-none">
      <div class="ops-panel">
        <p class="ops-panel-text mb-0">
          Choose a pickup job for each car, then click <strong>ASSIGN</strong>.
          Leave a job blank to keep the car in place. The next route destination is shown in <strong>bold</strong>.
        </p>
        <div class="mt-3">
          <button id="build_btn" name="build_btn" value="ASSIGN" type="submit" disabled
            class="btn btn-success btn-lg">ASSIGN</button>
        </div>
      </div>
      <div class="ops-panel-action ops-panel-tools">
        <div class="ops-toolbar ops-toolbar-stack">
          <div class="ops-toolbar-section ops-toolbar-bulk">
            <label for="bulk_job" class="form-label fw-semibold mb-1">Assign all checked cars to:</label>
            <select id="bulk_job" name="bulk_job" class="form-select" style="max-width: 20rem;" onchange="updateAllJobs(this.value)">
              <option value="">Select train/job</option>
            </select>
          </div>
          <div class="ops-toolbar-section ops-toolbar-filters">
            <?php require 'operations_station_filters.inc.php'; ?>
          </div>
        </div>
      </div>
    </div>
    <div id="car_table_div">
      <!-- the guts of the table are filled in by the HttpRequest call-back function -->
    </div>
    </form>
  </body>

    <script>
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

    </script>
    <script>
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
    </script>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("select").forEach(function(el){el.classList.add("form-select");el.style.removeProperty("width");if(el.closest("th")){el.classList.add("form-select-sm");}});});</script>
  </body>

</html>
