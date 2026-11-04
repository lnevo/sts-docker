<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Load and/or Unload Cars</title>
    <link href="vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="operations_ui.css?v=3" rel="stylesheet">
    <script src="sorttable.js"></script>
    <script src="/sts/js/sts-actions.js?v=1"></script>
    <script src="/sts/js/load_unload.js?v=1"></script>
  </head>
  <body class="bg-light ops-steppage ops-page-load_unload">
    <?php
    require_once 'operations_nav.php';
    echo ops_render_navbar('load_unload.php', 'Load / Unload Cars', 'bi-truck');
    echo ops_render_step_bar('load_unload.php', null);
    ?>
    <div class="px-4">
    <h5 class="mb-2">Load and/or Unload Cars</h5>
    <div id="instructions" class="text-muted mb-3">
    The cars shown on this page are in the process of being loaded or unloaded. To complete the loading or unloading process,
    click on the desired car's check box and then click the UPDATE button. Cars are color-coded based on their status.<br /><br />
    </div>
    <form method="POST" action="load_unload.php" data-sts-submit="prepareLoadUnloadSubmit">
    <?php
      // this program displays all cars that are in the process of being loaded or unloaded and
      // updates all cars that have been checked off by the user


      // bring in the function files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // open a database connection
      $dbc = open_db();

      // get the current operation session
      $sql = 'select setting_value from settings where setting_name = "session_nbr"';
      $rs = mysqli_query($dbc, $sql);
      $row = mysqli_fetch_array($rs);
      $current_session = $row[0];

      // check to see if the Update button was clicked
      if (isset($_POST['update_btn']))
      {
        // go through each of the rows from the incoming page and if it's checkbox was checked, update it's status
        for ($i=0; $i<(int) sts_post('row_count'); $i++)
        {
          $checkbox_name = "check" . $i;
          if (isset($_POST[$checkbox_name]))
          {
            // determine each car's new status
            $car_name = 'car' . $i;
            $status_name = 'status' . $i;
            $new_status = '';

            // id 0 would match every unfilled car order, so a missing or invalid car id is skipped
            if (sts_id(sts_post($car_name)) < 1)
            {
              continue;
            }

            if (sts_post($status_name) == 'Loading')
            {
              $new_status = 'Loaded';
            }
            else if (sts_post($status_name) == 'Unloading')
            {
              $new_status = 'Empty';

              // for the cars with this new status, delete the car orders linked to them
              $sql = 'delete from car_orders where car = ?';
              if (!sts_q($dbc, $sql, array(sts_id(sts_post($car_name)))))
              {
                print 'Delete Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql;
              }
            }
            else if (sts_post($status_name) == 'Empty')
            {
              $new_status = "Empty";
              // this case is for non-revenue moves, ie: repositioning empty cars
              // for the cars with this new status, delete the car orders linked to them
              $sql = 'delete from car_orders where car = ?';
              if (!sts_q($dbc, $sql, array(sts_id(sts_post($car_name)))))
              {
                print 'Delete Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql;
              }
            }

            // build an sql query to update each car's status (unrecognised status values are ignored)
            if ($new_status === '')
            {
              continue;
            }
            $sql = 'update cars set status = ?, last_spotted = "0" where id = ?';
            if (!sts_q($dbc, $sql, array($new_status, sts_id(sts_post($car_name)))))
            {
              print 'Update Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql;
            }
          }
        }

      }

      // build the sql query to pull in cars that are being loaded, unloaded, or enroute home and have
      // reached their home location
      $sql = 'select cars.id as id,
                     cars.reporting_marks as reporting_marks,
                     cars.position as position,
                     cars.status as status,
                     cars.last_spotted as last_spotted,
                     car_orders.waybill_number,
                     car_orders.shipment as shipment,
                     car_codes.code as car_code,
                     sta01.station as current_station,
                     loc01.code as current_location,
                     sta02.station as loading_station,
                     loc02.code as loading_location,
                     sta03.station as unloading_station,
                     loc03.code as unloading_location,
                     commodities.code as consignment
              from cars
              left join car_orders on car_orders.car = cars.id
              left join shipments on shipments.id = car_orders.shipment
              left join car_codes on car_codes.id = cars.car_code_id
              left join locations loc01 on loc01.id = cars.current_location_id
              left join locations loc02 on loc02.id = shipments.loading_location
              left join locations loc03 on loc03.id = shipments.unloading_location
              left join routing sta01 on sta01.id = loc01.station
              left join routing sta02 on sta02.id = loc02.station
              left join routing sta03 on sta03.id = loc03.station
              left join commodities on commodities.id = shipments.consignment
              left join routing on routing.id = loc01.station
              where ((cars.status = "Loading")
                  or (cars.status = "Unloading")
                  or ((cars.status = "Empty") and (cars.current_location_id = car_orders.shipment)))
              order by (case cars.status
                          when "Loading" then 0
                          when "Unloading" then 1
                          when "Empty" then 2
                        end), routing.sort_seq, current_location, position, reporting_marks';
      $rs = mysqli_query($dbc, $sql);
// print 'SQL: ' . $sql . '<br /><br />';

      // initialize a car counter
      $row_count = 0;

      // build the table of cars to be loaded or unloaded
      if (mysqli_num_rows($rs) > 0)
      {
        // generate the update button
        print '<div class="mb-3 noprint"><button name="update_btn" value="UPDATE" type="submit" class="btn btn-success btn-lg">UPDATE</button></div>';

        // generate filter panel and table
        print '<div class="card mb-3 noprint">
                 <div class="card-body py-2">
                   <div class="row g-2 align-items-end">
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Current Location</label>' .
                       drop_down_locations('current_loc_filter', '', '') . '
                     </div>
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Car Code</label>' .
                       drop_down_car_codes('car_code_filter', '', 'no_wild') . '
                     </div>
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Status</label>' .
                       drop_down_status('status_filter', '', 'no_wild') . '
                     </div>
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Consignment</label>' .
                       drop_down_commodities('commodity_filter', '', '') . '
                     </div>
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Loading Location</label>' .
                       drop_down_locations('loading_loc_filter', '', '') . '
                     </div>
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Unloading Location</label>' .
                       drop_down_locations('unloading_loc_filter', '', '') . '
                     </div>
                     <div class="col-sm-auto">
                       <button type="button" class="btn btn-outline-secondary btn-sm" data-sts-click="reloadPage">Clear Filters</button>
                     </div>
                   </div>
                 </div>
               </div>';
        print '<div class="table-responsive"><table class="table table-sm table-bordered table-hover sortable" id="car_table" name="car_table">
                 <thead>
                   <tr style="position: sticky; top: 0; background-color: #F5F5F5">
                     <th class="sorttable_nosort">
                       Load/Unload Cars
                       <div class="form-check mt-1">
                         <input class="form-check-input" id="check_all" name="check_all" type="checkbox" data-sts-change="checkall">
                         <label class="form-check-label" for="check_all">Check/Uncheck All</label>
                       </div>
                     </th>
                     <th>Current Station / Location</th>
                     <th>Position</th>
                     <th>Reporting Marks</th>
                     <th>Car Code</th>
                     <th>Status</th>
                     <th>Consignment</th>
                     <th>Loading Station / Location</th>
                     <th>Unloading Station / Location</th>
                   </tr>
                 </thead>';

        while ($row = mysqli_fetch_array($rs))
        {
          // look for non-revenue waybills
          if (substr($row['waybill_number'], 4, 1) == 'E')
          {
            $consignment = 'Non-Revenue';
            $load_loc = 'N/A';
            $unload_loc = sts_html($row['unloading_station']) . '<br />' . sts_html($row['unloading_location']);
          }
          else
          {
            $consignment = sts_html($row['consignment']);
            $load_loc = sts_html($row['loading_station']) . '<br />' . sts_html($row['loading_location']);
            $unload_loc = sts_html($row['unloading_station']) . '<br />' . sts_html($row['unloading_location']);
          }

          $checkbox_name = 'check' . $row_count;
          $car_name = 'car' . $row_count;
          $status_name = 'status' . $row_count;
          $unload_loc_name = 'unload' . $row_count;
          if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['id'] . '.jpg'))
          {
            $parm_string = ' data-car-id="' . sts_html((string) $row['id']) . '" data-marks="' . sts_html((string) $row['reporting_marks']) . '"';
          }
          else
          {
            $parm_string = ' data-car-id="" data-marks="' . sts_html((string) $row['reporting_marks']) . '"';
          }

          if ($row['status'] ==  'Loading')
          {
            $row_style = 'background-color:DarkGray;';
          }
          else if ($row['status'] == 'Unloading')
          {
            $row_style = 'background-color:White;';
          }
          else if ($row['status'] == 'Empty')
          {
            $row_style = 'background-color:LightGray;';
          }

          // get the min & max load and unload times from the current shipment
          // if older shipments have empty or blank values in the min and max fields, convert them to a value of 1
          $sql2 = 'select min_load_time, max_load_time, min_unload_time, max_unload_time from shipments where id = ?';
          $rs2 = sts_q($dbc, $sql2, array($row['shipment']));
          $row2 = mysqli_fetch_array($rs2);

          if (strlen(trim($row2[0]))<1)
          {
            $min_load_time = 0;
          }
          else
          {
            $min_load_time = (int)$row2[0];
          }

          if (strlen(trim($row2[1]))<1)
          {
            $max_load_time = 0;
          }
          else
          {
            $max_load_time = (int)$row2[1];
          }

          if (strlen(trim($row2[2]))<1)
          {
            $min_unload_time = 0;
          }
          else
          {
            $min_unload_time = (int)$row2[2];
          }

          if (strlen(trim($row2[3]))<1)
          {
            $max_unload_time = 0;
          }
          else
          {
            $max_unload_time = (int)$row2[3];
          }

          // determine if each car's checkbox should be checked or not
          $chk_box_val = '';
          if ($row['status'] == 'Loading')
          {
            // get a random number between the min and max load times
            $random_load_time = rand($min_load_time, $max_load_time);
//print 'current session: ' . $current_session . ' last spotted: ' . $row['last_spotted'] . ' random load time: ' . $random_load_time . '<br />';
            // add the random load time to the time spotted and if the result is less than the current session, check the box
            if (($row['last_spotted'] + $random_load_time) <= $current_session)
            {
              $chk_box_val = 'checked';
            }
           }
          else if ($row['status'] == 'Unloading')
          {
            // get a random number between the min and max unload times
            $random_unload_time = rand($min_unload_time, $max_unload_time);
//print 'current session: ' . $current_session . ' last spotted: ' . $row['last_spotted'] . ' random unload time: ' . $random_unload_time . '<br />';
            // add the random unload time to the time spotted and if the result is less than the current session, check the box
            if (($row['last_spotted'] + $random_unload_time) <= $current_session)
            {
              $chk_box_val = 'checked';
            }
          }

          print '<tr>
                   <td class="checkbox" style="' . $row_style . '">
                     <input id="' . $checkbox_name . '" name="' . $checkbox_name . '" value="' . sts_html($row['id']) . '" type="checkbox" ' . $chk_box_val . '>
                   </td>
                   <td style="' . $row_style . '">' .
                     sts_html($row['current_station']) . '<br />' . sts_html($row['current_location']) . '
                   </td>
                   <td style="' . $row_style . '">' .
                     sts_html($row['position']) . '
                   </td>
                   <td style="' . $row_style . '" data-sts-click="showImage"' . $parm_string . '>' .
                     sts_html($row['reporting_marks']) . '<input name="' . $car_name . '" value="' . $row[0] . '" type="hidden">
                   </td>
                   <td style="' . $row_style . '">' .
                     sts_html($row['car_code']) . '
                   </td>
                   <td style="' . $row_style . '"><span class="status-' . strtolower($row['status']) . '">' .
                     sts_html($row['status']) . '</span><input name="' . $status_name . '" value="' . sts_html($row['status']) . '" type="hidden">
                   </td>
                   <td style="' . $row_style . '">' .
                     $consignment . '
                   </td>
                   <td style="' . $row_style . '">' .
                     $load_loc . '
                   </td>
                   <td style="' . $row_style . '">' .
                     $unload_loc . '<input name="' . $unload_loc_name . '" value="' . $unload_loc . '" type="hidden">
                   </td>
                 </tr>';
          $row_count++;
          $previous_status = $row['status'];
        }
        print '</table></div>';
        // put the row count into a hidden field for when this program calls itself
        print '<input name="row_count" value="' . $row_count . '" type="hidden">';
      }
      else
      {
        print '<script type="application/json" id="sts-data-load-unload">{"noCars":true}</script>';
        print "<br />There are no cars currently in the process of  being loaded or unloaded.";
      }

    ?>
    </form>
  </div>
  <script src="vendor/bootstrap/bootstrap.bundle.min.js"></script>
  <script src="vendor/jquery/jquery-3.6.0.min.js"></script>
  </body>
</html>
