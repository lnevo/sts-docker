<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Reposition Cars</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="sorttable.js"></script>
    <style>
      tr {vertical-align: top;}
      th, td { font-size: 0.875rem; padding: 6px 8px; white-space: nowrap; }
      td.checkbox {text-align: center; white-space: normal; }
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
      function filter_rows(tbl_col, needle)
      {
        // this script filters the contents of the table based on the user selections of car type, current location, or home location

        // confirm that a non-blank option has been selected
        if (needle.length > 0)
        {
          // convert drop-down locations (station - location) to table locations (station\nlocation)
          var hyphen_loc = needle.search(" - ");
          if (hyphen_loc >= 0)
          {
            var new_needle = needle.substr(0, hyphen_loc) + "\n" + needle.substr(hyphen_loc + 3, needle.length);
            needle = new_needle;
          }

          var table = document.getElementById("car_tbl");

          //iterate through rows
          for (var i = 1, row; row = table.rows[i]; i++)
          {
            var haystack_length = row.cells[tbl_col].innerText.length;
            var needle_length = needle.length;
            var match_start = haystack_length - needle_length;

            var haystack = row.cells[tbl_col].innerText.substr(match_start);

            if (haystack != needle)
            {
              row.style.display = "none";
            }
          }
        }
      }

      function show_all() // not currently used because it's not working... :(
      {
        // this script filters the contents of the table based on cars current locations compared to their home locations

        var table = document.getElementById("car_tbl");

        // iterate through the rows
        for (var i = 1, row; row = table.rows[i]; i++)
        {
          if (row.cells[3].innerText == row.cells[5].innerText)
          {
            row.style.display = "none";
          }
        }
      }

      function repo_to_home()
      {
        if (confirm("Reposition all cars not at their\nhome location to that destination?"))
        {
          // submit the request to generate car orders for the empty non-billed cars so they move to their home locations
          var xmlhttp = new XMLHttpRequest();
          xmlhttp.onreadystatechange = function()
          {console.log("readyState = " + this.readyState + " this.status = " + this.status);
            if (this.readyState == 4 && this.status == 200)
            {
               show_car_order_count(this);
            }
          }
          var url = 'repo_to_home.php';
          xmlhttp.open('GET', url, false);
          xmlhttp.send();
        }
        else
        {
          alert("Reposition Cancelled");
        }
      }

      function show_car_order_count(xmlhttp)
      { console.log("Return handler called");
        alert(xmlhttp.responseText + " Non-Revenue Car Orders Generated");
        location.reload();
      }

    </script>
  </head>
  <body class="bg-light">
    <nav class="navbar navbar-dark noprint mb-3" style="background-color: #2e7d32;">
      <div class="container-fluid">
        <span class="navbar-brand"><i class="bi bi-arrows-move"></i> Reposition Empty Cars</span>
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
    <div class="px-4">
    <h5 class="mb-2">Reposition Empty Cars</h5>
    <p class="text-muted">Select a destination for each empty car that is to be repositioned and then click the UPDATE button.<br />
    Leave the destination blank if the car is to remain at its current location.<br /><br />
    To reposition all cars that are NOT at their home location to their home, click the REPOSITION TO HOME button.<br /><br />
    The list of cars can be filtered by selecting a car type, a current location and/or a home location.<br />
    To view only those cars not at their home locations, click the "SHOW ONLY CARS NOT AT THEIR HOME LOC" button.<br />
    To remove the four filters, click the "CLEAR FILTERS" button.<br /><br />
    If all empty cars are displayed, those that are not at their home locations are highlighted. These cars<br />
    should be sent to their home locations if they are not needed for revenue moves. They may also be blocking<br />
    an unloading location.</p>
    <form method="post" action="reposition.php">
    <?php
      // this program displays all cars that have a status of "Empty" and are not billed anywhere
      // it also creates empty car waybills for any cars where the user selects a destination

      // bring in the function files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // open a database connection
      $dbc = open_db();

      // check to see if the Update button was clicked
      if (isset($_POST['update_btn']))
      {
        // get the current operating session number, default to zero if the query returns nothing
        $sql = 'select setting_value from settings where setting_name = "session_nbr"';
        $rs = mysqli_query($dbc, $sql);
        if (mysqli_num_rows($rs) > 0)
        {
          $row = mysqli_fetch_row($rs);
          $session_number = $row[0];
        }
        else
        {
          $session_number = 0;
        }

        // get the last reposition waybill number generated, default to 1 if the query returns nothing
        $sql = 'select waybill_number from car_orders where waybill_number like "' . str_pad($session_number, 3, '0', STR_PAD_LEFT) .  '-E__" order by waybill_number desc limit 1';
        $rs = mysqli_query($dbc, $sql);
        if (mysqli_num_rows($rs) > 0)
        {
          $row = mysqli_fetch_row($rs);
          $waybill_counter = substr($row[0], -2, 2) + 1;
        }
        else
        {
          $waybill_counter = 1;
        }

        // go through each of the rows from the incoming page and if a destination was selected from any car's drop-down list,
        // insert an empty car waybill into the waybills table
        for ($i=0; $i<$_POST['row_count']; $i++)
        {
          // build the names of the input fields
          $list_name = 'list' . $i;
          $car_name = 'car' . $i;

          // construct the waybill number
          $wb_nbr = str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-E' . str_pad($waybill_counter, 2, '0', STR_PAD_LEFT);

          if (strlen($_POST[$list_name]) > 0)
          {
            // build an sql query to create the empty car waybill
            $sql = 'insert into car_orders values ("' . $wb_nbr . '", "' . $_POST[$list_name] . '", "' . $_POST[$car_name] . '")';

            if (!mysqli_query($dbc, $sql))
            {
              print 'Insert Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql;
            }

            // build an sql query to update the car's status to "Ordered"
            $sql = 'update cars set status = "Ordered" where id = "' . $_POST[$car_name] . '"';

            if (!mysqli_query($dbc, $sql))
            {
              print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql;
            }

            // the background information needed by the history file
            $sql = 'select current_location_id from cars where id = "' . $_POST[$car_name] . '"';
            $rs = mysqli_query($dbc, $sql);
            $row = mysqli_fetch_array($rs);
            $car_id = $row["current_location_id"];

            $sql = 'select code from locations where id = "' . $_POST[$list_name] . '"';
            $rs = mysqli_query($dbc, $sql);
            $row = mysqli_fetch_array($rs);
            $destination = $row["code"];

            // insert a car history record
            $sql = 'insert into history(car_id, session_nbr, event_date, event, location)
                    values ("' . $_POST[$car_name] . '",
                            "' . $session_number . '",
                            "' . date("Y-m-d H:i:s") . '",
                            "Repositioned to ' . $destination . '",
                            "' . $car_id . '")';
//print 'SQL: ' . $sql . ' session: ' . $session_nbr . ' destination: ' . $_POST[$list_name] . ' location: ' . $row["current_location_id"] . '<br /><br />';

            if (!mysqli_query($dbc, $sql))
            {
              print 'Insert error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br /><br />';
            }

            $waybill_counter++;
          }
        }
      }

      // build the sql query to pull in cars that have a status of "Empty-Available" and aren't billed
      $sql = 'select cars.id as id,
                     cars.reporting_marks as reporting_marks,
                     cars.position as position,
                     cars.remarks as remarks,
                     car_codes.code as car_code,
                     loc01.station as current_station_id,
                     loc02.station as home_station_id,
                     loc01.code as current_location,
                     loc02.code as home_location,
                     sta01.station as current_station,
                     sta02.station as home_station
                from cars
                left join car_codes on car_codes.id = cars.car_code_id
                left join locations loc01 on loc01.id = cars.current_location_id
                left join locations loc02 on loc02.id = cars.home_location
                left join routing sta01 on sta01.id = loc01.station
                left join routing sta02 on sta02.id = loc02.station
               where status = "Empty"
                 and not exists (select car_orders.car from car_orders where cars.id = car_orders.car)
               order by sta02.sort_seq asc, sta02.station asc, loc02.code asc,
                     case when cars.current_location_id != cars.home_location then 0 else 1 end asc,
                     loc01.code asc, reporting_marks asc';
//print '<br />Query started ' . date("h:i:s") . '<br />';
      $rs = mysqli_query($dbc, $sql);
//print '<br />Query returned ' . date("h:i:s") . '<br />';
      // initialize a car counter
      $row_count = 0;

      // build the table of empty-available cars
      if (mysqli_num_rows($rs) > 0)
      {
        // generate the update button
        print '<div class="mb-3 noprint d-flex gap-2 flex-wrap"><input name="update_btn" value="UPDATE" type="submit" class="btn btn-success btn-lg">';
        print '<button type="button" onclick="repo_to_home()" class="btn btn-warning btn-lg">REPOSITION TO HOME</button></div>';

        // generate filter panel and table
        print '<div class="card mb-3 noprint">
                 <div class="card-body py-2">
                   <div class="row g-2 align-items-end">
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Car Code</label>' .
                       drop_down_car_codes('car_code_filter', '', 'no_wild') . '
                     </div>
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Current Location</label>' .
                       drop_down_locations('current_loc_filter', '', '') . '
                     </div>
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Home Location</label>' .
                       drop_down_locations('home_loc_filter', '', '') . '
                     </div>
                     <div class="col-sm-auto">
                       <button type="button" class="btn btn-outline-secondary btn-sm" onclick="location.reload();">Clear Filters</button>
                     </div>
                   </div>
                 </div>
               </div>';
        print '<div class="table-responsive"><table class="table table-sm table-bordered table-hover sortable" id="car_tbl" name="car_tbl">
                 <thead>
                   <tr style="position: sticky; top: 0; background-color: #F5F5F5">
                     <th class="sorttable_nosort">Destination</th>
                     <th>Reporting Marks</th>
                     <th>Car Code</th>
                     <th>Current Station / Location</th>
                     <th>Position</th>
                     <th>Home Station / Location</th>
                     <th>Remarks</th>
                   </tr>
                 </thead>';
/*
// these lines came from the last <th> cell
                       <button name="show_all_btn" id="show_all_btn" onclick="show_all();"
                        style="font: bold 12px Verdana, Arial, sans-serif; text-align: center; background-color: #ffff80;">
                         SHOW ONLY CARS<br />NOT AT HOME LOC
                       </button>
*/
        $location_list = drop_down_locations("listx", 0, '');
        $current_home_group = null;

        while ($row = mysqli_fetch_array($rs))
        {

          if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['id'] . '.jpg'))
          {
            $parm_string = '\'' . $row['id'] . '\', \'' . $row['reporting_marks'] . '\'';
          }
          else
          {
            $parm_string = '\'\',\'' . $row['reporting_marks'] . '\'';
          }

          // insert group header when home station changes
          $group_key = $row['home_station'] . ' | ' . $row['home_location'];
          if ($group_key !== $current_home_group)
          {
            $current_home_group = $group_key;
            print '<tr class="table-dark"><td colspan="7" class="fw-semibold">'
                . htmlspecialchars($row['home_station']) . ' &mdash; ' . htmlspecialchars($row['home_location'])
                . '</td></tr>';
          }

          if ($row['current_station'] != $row['home_station'])
          {
            print '<tr class="table-secondary">';
          }
          else
          {
            print '<tr class="table-success">';
          }

          $loc_list_part1 = substr($location_list, 0, 16);
          $loc_list_part2 = substr($location_list, 17, 12);
          $loc_list_part3 = substr($location_list, 32);

          $new_location_list = $loc_list_part1 . $row_count . $loc_list_part2 . $row_count . '" ' . $loc_list_part3;
          print '<td>' . $new_location_list . '</td>
                 <td onclick="show_image(' . $parm_string . ');">' . $row['reporting_marks'] . '<input name="car' . $row_count . '" value="' . $row['id'] . '" type="hidden"></td>
                 <td>' . $row['car_code'] . '</td>
                 <td>' . $row['current_station'] . '<br />' . $row['current_location'] . '</td>
                 <td>' . $row['position'] . '</td>
                 <td>' . $row['home_station'] . '<br />' . $row['home_location'] . '</td>
                 <td>' . $row['remarks'] . '</td>
                 </tr>';
          $row_count++;

        }
        print '</table></div>';
        // put the row count into a hidden field for when this program calls itself
        print '<input name="row_count" value="' . $row_count . '" type="hidden">';
      }
      else
      {
        print "<br />No cars are currently available for repositioning.";
      }
    ?>
    </form>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      document.querySelectorAll("select").forEach(function(el) {
        el.classList.add("form-select", "form-select-sm");
        el.style.removeProperty("width");
      });
      [['car_code_filter',2],['current_loc_filter',3],['home_loc_filter',5]].forEach(function(f) {
        var el = document.getElementById(f[0]);
        if (el) { el.addEventListener('change', function() { filter_rows(f[1], this.options[this.selectedIndex].text); this.disabled = true; }); }
      });
    });
  </script>
  </body>
</html>
