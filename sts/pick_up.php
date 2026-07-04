<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Pick Up Cars</title>
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
        var row_count = document.getElementById('job_table').rows.length-1;
        if (document.getElementById('check_all').checked == true)
        {
          for (var i=0; i < row_count; i++)
          {
            var checkbox_name = "check" + i.toString();
            document.getElementById(checkbox_name).checked = true;
          }
        }
        else
        {
          for (var i=0; i < row_count; i++)
          {
            var checkbox_name = "check" + i.toString();
            document.getElementById(checkbox_name).checked = false;
          }
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
          <button class="btn btn-light btn-sm noprint" onclick="window.print()">
            <i class="bi bi-printer"></i> Print
          </button>
        </div>
      </div>
    </nav>
    <div class="px-4">
    <h5 class="mb-2">Pick Up Cars</h5>
    <div class="noprint text-muted mb-3">Select a job to do the pickups</div>
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
        print '<div class="alert alert-success noprint d-flex align-items-center justify-content-between gap-3 flex-wrap">';
        print '<span>' . $num_cars_picked_up . ' car(s) picked up.</span>';
        print '<a class="btn btn-success" href="set_out.php">Go to Set Out Cars</a>';
        print '</div>';
      }
      print '<div class="noprint mb-3">';
      // generate the list of jobs from which the user can choose
            print '<div class="d-flex flex-wrap align-items-center gap-2">';
            print drop_down_jobs("job_list", '', "get_jobs_and_cars();");
            print '<button id="finish_btn" name="finish_btn" value="PICK UP" type="submit" disabled
              class="btn btn-success btn-lg">PICK UP</button>';
            print '</div>';
    ?>
      <!-- print button is in the navbar -->
      <div id="instructions" class="alert alert-info d-none mt-2">
      Mark the cars that have been picked up with check marks and then click the <b>PICK UP</b> button.<br /><br />
      After picking up the cars, click <a href="organize_cars.php"><b>here</b></a> to update the positions of the cars in the train.<br /><br />
      Click <a href="display_switchlist.php"><b>here</b></a> to generate an updated switch list if desired.
      </div>
    </div>
    <br />
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
          document.getElementById("job_table_div").innerHTML = "<tr><td>The switchlist for " + job_name + " doesn't contain any cars.</td></tr>";
        }
        else
        {
          // make the instruction block visible
          document.getElementById("instructions").classList.remove("d-none");

          // display the table being returned from the server
          document.getElementById("job_table_div").innerHTML = xmlhttp.responseText;
        }
      }

    </script>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("select").forEach(function(el){el.classList.add("form-select");el.style.removeProperty("width");if(el.closest("th")){el.classList.add("form-select-sm");}});});</script>
  </body>

</html>
