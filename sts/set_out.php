<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Set Out Cars</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
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
        #job_table th:nth-child(8),
        #job_table td:nth-child(8),
        #job_table th:nth-child(9),
        #job_table td:nth-child(9) {
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
  </head>
  <body class="bg-light">
    <nav class="navbar navbar-dark noprint mb-3" style="background-color: #2e7d32;">
      <div class="container-fluid">
        <span class="navbar-brand"><i class="bi bi-arrow-down-circle"></i> Set Out Cars</span>
        <div>
          <a href="operations.html" class="btn btn-outline-light btn-sm me-2">
            <i class="bi bi-arrow-left"></i> Operations
          </a>
          <a href="index.html" class="btn btn-outline-light btn-sm me-2">
            <i class="bi bi-house"></i> Home
          </a>
          <button class="btn btn-light btn-sm noprint" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
          </button>
        </div>
      </div>
    </nav>
    <div class="px-4">
    <h5 class="mb-3">Set Out Cars</h5>
    <form action="set_out.php" method="get">
    <?php
      // bring in the utility files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // get a database connection
      $dbc = open_db();

      // get the current session number
      $sql = 'select setting_value from settings where setting_name = "session_nbr"';
      $rs = mysqli_query($dbc, $sql);
      $row = mysqli_fetch_array($rs);
      $current_session = $row[0];

      // was the Finish button clicked?
      if (isset($_GET['finish_btn']))
      {
        $num_cars_set_out = 0;
        // only try to close out a switchlist if there was one to be closed out in the first place
        if (isset($_GET['row_count']))
        {
          // get the number of rows that were on the page
          $row_count = $_GET['row_count'];

          // update the current location of the cars as specified by the user
          for ($i=0; $i<$row_count; $i++)
          {
            // construct the list and car field names
            $list_name = 'station_list' . $i;
            $car_name = 'car' . $i;

            // does the drop-down list have a job name in it?
            if (strlen($_GET[$list_name]) > 0)
            {
              // before marking the car as set out, save the job that is handling it for the history file
              $sql = 'select jobs.name as job_name
                      from jobs, cars
                      where cars.id = "' . $_GET[$car_name] . '"
                        and jobs.id = cars.handled_by_job_id';
              $rs = mysqli_query($dbc, $sql);
              $row = mysqli_fetch_array($rs);
              $job_name = $row['job_name'];
//print 'SQL: ' . $sql . ' / job name: ' . $job_name . '<br /><br />';
              // build a query to update the car's current location field, remove the contents of it's "handled_by" field, and set it's position to 0
              $sql = 'update cars
                      set current_location_id = "' . $_GET[$list_name] . '",
                          handled_by_job_id = 0,
                          position = "0"
                      where id = "' . $_GET[$car_name] . '"';
  // print 'SQL: ' . $sql . '<br /><br />';
              if(!mysqli_query($dbc, $sql))
              {
                print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br />';
              }
              else
              {
                $num_cars_set_out++;
              }

              // get the info that the history table needs
              $sql = 'select setting_value from settings where setting_name = "session_nbr"';
              $rs = mysqli_query($dbc, $sql);
              $row = mysqli_fetch_array($rs);
              $session_nbr = $row['setting_value'];

              $sql = 'select current_location_id from cars where id = "' . $_GET[$car_name] . '"';
              $rs = mysqli_query($dbc, $sql);
              $row = mysqli_fetch_array($rs);
              $location = $row['current_location_id'];
//print 'SQL: ' . $sql . ' Location: ' . $location . '<br /><br />';
              // insert a car history record
              $sql = 'insert into history(car_id, session_nbr, event_date, event, location)
                      values ("' . $_GET[$car_name] . '",
                              "' . $session_nbr . '",
                              "' . date("Y-m-d H:i:s") . '",
                              "Set out by Job ' . $job_name . '",
                              "' . $location . '")';

              if (!mysqli_query($dbc, $sql))
              {
                print 'Insert error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br /><br />';
              }



              // build a query to update an "ordered" car's status if it is at it's loading location
              // and update "last_spotted" to the current session number
              $sql = 'update cars,
                             car_orders,
                             shipments
                      set cars.status = "Loading",
                          cars.last_spotted = "' . $current_session . '"
                      where cars.id = "' . $_GET[$car_name] . '"
                        and cars.status = "Ordered"
                        and car_orders.car = cars.id
                        and car_orders.shipment = shipments.id
                        and cars.current_location_id = shipments.loading_location';
// print 'SQL: ' . $sql . '<br /><br />';
              if(!mysqli_query($dbc, $sql))
              {
                print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br />';
              }

              // build a query to update a "Loaded" car's status if it is at it's unloading location
              // and update "last_spotted" to the current session number
              $sql = 'update cars,
                             car_orders,
                             shipments
                      set cars.status = "Unloading",
                          cars.last_spotted = "' . $current_session . '"
                      where cars.id = "' . $_GET[$car_name] . '"
                        and cars.status = "Loaded"
                        and car_orders.car = cars.id
                        and car_orders.shipment = shipments.id
                        and cars.current_location_id = shipments.unloading_location';
// print 'SQL: ' . $sql . '<br /><br />';
              if(!mysqli_query($dbc, $sql))
              {
                print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br />';
              }

              // build a query to update the car's status if it is a non-revenue move and at it's destination
              $sql = 'update cars,
                             car_orders
                      set cars.status = "Empty"
                      where car_orders.car = cars.id
                        and car_orders.waybill_number like "___-E__"
                        and cars.status = "Ordered"
                        and cars.current_location_id = car_orders.shipment
                        and cars.id = "' . $_GET[$car_name] . '"';

// print 'SQL: ' . $sql . '<br /><br />';
              if(!mysqli_query($dbc, $sql))
              {
                print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br />';
              }
              else
              {
// print 'Updated rows = ' . mysqli_affected_rows($dbc) . '<br /><br />';
                if (mysqli_affected_rows($dbc) > 0)
                {
                  // if the repositioned car was successfully changed from "Ordered" to "Empty", remove it's car order
                  $sql = 'delete from car_orders where car = "' . $_GET[$car_name]. '"';
                  if (!mysqli_query($dbc, $sql))
                  {
                    print 'Delete Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql;
                  }
                  else
                  {
                    $sql2 = 'select reporting_marks from cars where id = ' . $_GET[$car_name];
                    $rs2 = mysqli_query($dbc, $sql2);
                    $row2 = mysqli_fetcH_array($rs2);
                    print $row2['reporting_marks'] . ' has been successfully repositioned and is now ready for further use.<br />';
                  }
                }
              }

              // check to see if there are any cars that have just been set to "Loading" or "Unloading" where the applicable
              // shipment has min & max load & unload times of zero
              // if so, bump their status to the next setting

              // first get the min & max load & unload values for the shipment associated with this car
              $sql = 'select shipments.min_load_time as min_load_time,
                              shipments.max_load_time as max_load_time,
                              shipments.min_unload_time as min_unload_time,
                              shipments.max_unload_time as max_unload_time,
                              cars.status as status
                         from shipments, car_orders, cars
                        where car_orders.shipment = shipments.id
                          and car_orders.car = "' . $_GET[$car_name] . '"
                          and cars.id = "' . $_GET[$car_name] . '"';

              $rs = mysqli_query($dbc, $sql);
              $row = mysqli_fetch_array($rs);
// print 'SQL: ' . $sql . '<br /><br />';
              // convert blank min & max load & unload times to an integer value
                $min_load_time = (int)$row['min_load_time'];
                $max_load_time = (int)$row['max_load_time'];
                $min_unload_time = (int)$row['min_unload_time'];
                $max_unload_time = (int)$row['max_unload_time'];

              // if the car's status is "Loading" and it's shipment has negative values for either the min or max loading time,
              // change it's status to "Loaded"
              if (($row['status'] == "Loading") && (($min_load_time < 0) || ($max_load_time < 0)))
              {
                $sql2 = 'update cars set status = "Loaded", last_spotted = "0" where id = "' . $_GET[$car_name] . '"';
                if (!mysqli_query($dbc, $sql2))
                {
                  print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql2;
                }
              }

              // likewise if the car's status is "Unloading" and it's shipment has negative values for either min or max unloading time,
              // change it's status to "Empty"
              if (($row['status'] == "Unloading") && (($min_unload_time < 0) || ($max_unload_time < 0)))
              {
                $sql2 = 'update cars set status = "Empty", last_spotted = "0" where id = "' . $_GET[$car_name] . '"';
                if (!mysqli_query($dbc, $sql2))
                {
                  print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql2;
                }
                // for the cars with this new status, delete the car orders linked to them
                $sql2 = 'delete from car_orders where car = "' . $_GET[$car_name]. '"';
                if (!mysqli_query($dbc, $sql2))
                {
                  print 'Delete Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql2;
                }
              }
            }
          }
        }
        print '<div class="alert alert-success noprint d-flex align-items-center justify-content-between gap-3 flex-wrap">';
        print '<span>' . $num_cars_set_out . ' car(s) set out.</span>';
        print '<a class="btn btn-success" href="build_switchlists.php">Return to Build Switch Lists</a>';
        print '</div>';
      }
      print '<div class="noprint">';
      // choose to display all set out location possibilities for the selected job or only the default locations
      print '<div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="default_loc" id="default_loc" onchange="reset_job();"><label class="form-check-label" for="default_loc">Show default set-out locations only</label></div>';

      // generate the list of jobs from which the user can choose
      print '<p class="text-muted mb-1">Select a job to do the setouts:</p>';
      print drop_down_jobs("job_list", '', "get_jobs_and_cars();");
    ?>
      <!-- print button is in the navbar -->
      <div id="instructions" class="alert alert-info d-none mt-2">
      Mark where each car was left by selecting its set-out location from its drop-down list.<br /><br />
      To update the current location of each car that was set out, click the <b>SET OUT</b> button.<br /><br />
      After placing the cars, click <a href="organize_cars.php">here</a> to update the positions of the cars at the setout location
      as well as those remaining in the train.
      <div class="noprint mt-3">
      <label for="bulk_location" class="form-label fw-semibold">Set all locations to:</label>
      <select id="bulk_location" name="bulk_location" class="form-select" onchange="updateAllLocations(this.value)">
        <option value="">Select location</option>
      </select>
      </div>
      <div class="mt-3">
      <button id="finish_btn" name="finish_btn" value="SET OUT" type="submit" disabled class="btn btn-success btn-lg">SET OUT</button>
      </div>
      </div>
    </div>
    <div id="job_table_div">
      <!-- the guts of the table are filled in by the HttpRequest call-back function -->
    </div>
    </form>
  </body>

    <script>
      // this script resets the drop down job list when the default set-out location checkbox is clicked
      function reset_job()
      {
        document.getElementById('job_list').selectedIndex = "0";
        document.getElementById('job_table_div').innerHTML = "";
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
        }
        else
        {
          // make the instruction block visible
          document.getElementById("instructions").classList.remove("d-none");

          // display the table being returned from the server
          document.getElementById("job_table_div").innerHTML = xmlhttp.responseText;

          // Add slight delay to ensure DOM is updated
          setTimeout(populateBulkDropdown, 100);
        }
      }

    </script>

    <script>
      function populateBulkDropdown() {
          const firstDropdown = document.querySelector('select[name^="station_list"]');
          if (!firstDropdown) return;
          const bulkDropdown = document.getElementById('bulk_location');
          if (!bulkDropdown) return;
          bulkDropdown.innerHTML = '<option value="">Select location</option>';
          Array.from(firstDropdown.options).forEach(option => {
              if (option.value) {
                  const newOption = document.createElement('option');
                  newOption.value = option.value;
                  newOption.text = option.text;
                  bulkDropdown.add(newOption);
              }
          });
      }

      function updateAllLocations(selectedValue) {
          if (!selectedValue) return;
          const dropdowns = document.querySelectorAll('select[name^="station_list"]');
          dropdowns.forEach(dropdown => {
              const optionExists = Array.from(dropdown.options).some(option => option.value === selectedValue);
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
