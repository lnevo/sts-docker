<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Build Switch Lists</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
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
          <a href="index.html" class="btn btn-outline-light btn-sm">
            <i class="bi bi-house"></i> Home
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
          <p class="card-text text-muted small">Select a station to assign cars to jobs/trains for pickup.</p>
          <?php print drop_down_stations('station_list', '', 'get_cars_and_jobs();'); ?>
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
      }
    ?>
    <br /><br />
    <div id="nothing_to_move" class="alert alert-warning d-none">
      There are no cars at this location that are ready to move.
    </div>
    <div id="instructions" class="alert alert-info d-none">
      Select which job will pick up each of the cars and then click the <b>ASSIGN</b> button.<br />
      The cars will be added to the selected job for pick up at this location.<br />
      If the job column is left blank, the car will remain in place.<br />
      The next destination in each car's route is displayed in <b>bold</b> text.
      <div class="noprint mt-3">
      <label for="bulk_job" class="form-label fw-semibold">Assign all cars to train/job:</label>
      <select id="bulk_job" name="bulk_job" class="form-select" onchange="updateAllJobs(this.value)">
        <option value="">Select train/job</option>
      </select>
      </div>
      <div class="mt-2">
      <button id="build_btn" name="build_btn" value="ASSIGN" type="submit" disabled
        class="btn btn-success btn-lg">ASSIGN</button>
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
          document.getElementById("bulk_job").innerHTML = '<option value="">Select train/job</option>';
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
          setTimeout(populateBulkJobDropdown, 100);
        }
      }

    </script>
    <script>
      function populateBulkJobDropdown() {
        const firstDropdown = document.querySelector('select[name^="job_list"]');
        if (!firstDropdown) return;
        const bulkDropdown = document.getElementById('bulk_job');
        if (!bulkDropdown) return;
        bulkDropdown.innerHTML = '<option value="">Select train/job</option>';
        Array.from(firstDropdown.options).forEach(option => {
          if (option.value) {
            const newOption = document.createElement('option');
            newOption.value = option.value;
            newOption.text = option.text;
            bulkDropdown.add(newOption);
          }
        });
      }

      function updateAllJobs(selectedValue) {
        if (!selectedValue) return;
        const dropdowns = document.querySelectorAll('select[name^="job_list"]');
        dropdowns.forEach(dropdown => {
          const rowCheckbox = dropdown.closest('tr')?.querySelector('.bulk-assign-row');
          if (rowCheckbox && !rowCheckbox.checked) return;
          const optionExists = Array.from(dropdown.options).some(option => option.value === selectedValue);
          if (optionExists) {
            dropdown.value = selectedValue;
          }
        });
      }

      function toggleAllCarAssignments(checked) {
        document.querySelectorAll('.bulk-assign-row').forEach(checkbox => {
          checkbox.checked = checked;
        });
      }
    </script>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("select").forEach(function(el){el.classList.add("form-select");el.style.removeProperty("width");if(el.closest("th")){el.classList.add("form-select-sm");}});});</script>
  </body>

</html>
