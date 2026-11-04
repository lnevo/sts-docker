<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Set Out Cars</title>
    <link href="vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="operations_ui.css?v=3" rel="stylesheet">
    <script src="/sts/js/sts-actions.js?v=1"></script>
    <script src="operations_table_filters.js"></script>
    <?php
      // bring in the javascript function that shows rollingstock photos
      require 'show_image.php';
    ?>
  </head>
  <body class="bg-light ops-steppage ops-page-set_out">
    <?php
    require_once 'operations_nav.php';
    echo ops_render_navbar('set_out.php', 'Set Out Cars', 'bi-arrow-down-circle', '<button class="btn btn-light btn-sm" data-sts-print><i class="bi bi-printer"></i> Print</button>');
    echo ops_render_step_bar('set_out.php', null);
    ?>
    <div class="px-4 py-3">
    <h5 class="mb-3">Set Out Cars</h5>
    <form action="set_out.php" method="get">
    <?php
      // bring in the utility files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // get a database connection
      $dbc = open_db();
      $workflow_alert_html = '';

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
          $row_count = (int) sts_get('row_count');

          // update the current location of the cars as specified by the user
          for ($i=0; $i<$row_count; $i++)
          {
            // construct the list and car field names
            $list_name = 'station_list' . $i;
            $car_name = 'car' . $i;

            // does the drop-down list have a job name in it?
            if (isset($_GET[$list_name]) && strlen(sts_get($list_name)) > 0)
            {
              $car_id = sts_id(sts_get($car_name));
              if ($car_id < 1)
              {
                continue;   // an invalid car id must never reach the car_orders deletes below
              }
              // before marking the car as set out, save the job that is handling it for the history file
              $sql = 'select jobs.name as job_name
                      from jobs, cars
                      where cars.id = ?
                        and jobs.id = cars.handled_by_job_id';
              $rs = sts_q($dbc, $sql, array($car_id));
              $row = mysqli_fetch_array($rs);
              $job_name = $row['job_name'];
//print 'SQL: ' . $sql . ' / job name: ' . $job_name . '<br /><br />';
              // build a query to update the car's current location field, remove the contents of it's "handled_by" field, and set it's position to 0
              $sql = 'update cars
                      set current_location_id = ?,
                          handled_by_job_id = 0,
                          position = "0"
                      where id = ?';
  // print 'SQL: ' . $sql . '<br /><br />';
              if(!sts_q($dbc, $sql, array(sts_id(sts_get($list_name)), $car_id)))
              {
                print 'Update Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql . '<br />';
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

              $sql = 'select current_location_id from cars where id = ?';
              $rs = sts_q($dbc, $sql, array($car_id));
              $row = mysqli_fetch_array($rs);
              $location = $row['current_location_id'];
//print 'SQL: ' . $sql . ' Location: ' . $location . '<br /><br />';
              // insert a car history record
              $sql = 'insert into history(car_id, session_nbr, event_date, event, location)
                      values (?, ?, ?, ?, ?)';

              if (!sts_q($dbc, $sql, array($car_id, $session_nbr, date("Y-m-d H:i:s"), 'Set out by Job ' . $job_name, $location)))
              {
                print 'Insert error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql . '<br /><br />';
              }



              // build a query to update an "ordered" car's status if it is at it's loading location
              // and update "last_spotted" to the current session number
              $sql = 'update cars,
                             car_orders,
                             shipments
                      set cars.status = "Loading",
                          cars.last_spotted = ?
                      where cars.id = ?
                        and cars.status = "Ordered"
                        and car_orders.car = cars.id
                        and car_orders.shipment = shipments.id
                        and cars.current_location_id = shipments.loading_location';
// print 'SQL: ' . $sql . '<br /><br />';
              if(!sts_q($dbc, $sql, array($current_session, $car_id)))
              {
                print 'Update Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql . '<br />';
              }

              // build a query to update a "Loaded" car's status if it is at it's unloading location
              // and update "last_spotted" to the current session number
              $sql = 'update cars,
                             car_orders,
                             shipments
                      set cars.status = "Unloading",
                          cars.last_spotted = ?
                      where cars.id = ?
                        and cars.status = "Loaded"
                        and car_orders.car = cars.id
                        and car_orders.shipment = shipments.id
                        and cars.current_location_id = shipments.unloading_location';
// print 'SQL: ' . $sql . '<br /><br />';
              if(!sts_q($dbc, $sql, array($current_session, $car_id)))
              {
                print 'Update Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql . '<br />';
              }

              // build a query to update the car's status if it is a non-revenue move and at it's destination
              $sql = 'update cars,
                             car_orders
                      set cars.status = "Empty"
                      where car_orders.car = cars.id
                        and car_orders.waybill_number like "___-E__"
                        and cars.status = "Ordered"
                        and cars.current_location_id = car_orders.shipment
                        and cars.id = ' . $car_id;   // $car_id is an integer (sts_id); run unprepared so mysqli_affected_rows() below works

// print 'SQL: ' . $sql . '<br /><br />';
              if(!mysqli_query($dbc, $sql))
              {
                print 'Update Error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br />';
              }
              else
              {
// print 'Updated rows = ' . mysqli_affected_rows($dbc) . '<br /><br />';
                if (mysqli_affected_rows($dbc) > 0)
                {
                  // if the repositioned car was successfully changed from "Ordered" to "Empty", remove it's car order
                  $sql = 'delete from car_orders where car = ?';
                  if (!sts_q($dbc, $sql, array($car_id)))
                  {
                    print 'Delete Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql;
                  }
                  else
                  {
                    $sql2 = 'select reporting_marks from cars where id = ?';
                    $rs2 = sts_q($dbc, $sql2, array($car_id));
                    $row2 = mysqli_fetcH_array($rs2);
                    print sts_html($row2['reporting_marks']) . ' has been successfully repositioned and is now ready for further use.<br />';
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
                          and car_orders.car = ?
                          and cars.id = ?';

              $rs = sts_q($dbc, $sql, array($car_id, $car_id));
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
                $sql2 = 'update cars set status = "Loaded", last_spotted = "0" where id = ?';
                if (!sts_q($dbc, $sql2, array($car_id)))
                {
                  print 'Update Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql2;
                }
              }

              // likewise if the car's status is "Unloading" and it's shipment has negative values for either min or max unloading time,
              // change it's status to "Empty"
              if (($row['status'] == "Unloading") && (($min_unload_time < 0) || ($max_unload_time < 0)))
              {
                $sql2 = 'update cars set status = "Empty", last_spotted = "0" where id = ?';
                if (!sts_q($dbc, $sql2, array($car_id)))
                {
                  print 'Update Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql2;
                }
                // for the cars with this new status, delete the car orders linked to them
                $sql2 = 'delete from car_orders where car = ?';
                if (!sts_q($dbc, $sql2, array($car_id)))
                {
                  print 'Delete Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql2;
                }
              }
            }
          }
        }
        $workflow_alert_html = '<div class="alert alert-success noprint ops-workflow-alert mb-4">'
            . '<span>' . htmlspecialchars((string)$num_cars_set_out) . ' car(s) set out.</span>'
            . '<a class="btn btn-success" href="build_switchlists.php">Return to Build Switch Lists</a>'
            . '</div>';
      }
      print '<div class="row g-3 mb-4 noprint">';
      print '<div class="col-md-6">';
      print '<div class="card h-100">';
      print '<div class="card-header fw-semibold"><i class="bi bi-train-front"></i> Select Job / Train</div>';
      print '<div class="card-body">';
      print '<p class="card-text text-muted small mb-2">Choose the job or train doing the setouts.</p>';
      print '<div class="form-check mb-3"><input type="checkbox" class="form-check-input" name="default_loc" id="default_loc" data-sts-change="reset_job"><label class="form-check-label" for="default_loc">Show default set-out locations only</label></div>';
      print drop_down_jobs("job_list", '', "get_jobs_and_cars();", "setout");
      print '</div></div></div></div>';

      if ($workflow_alert_html !== '')
      {
        print $workflow_alert_html;
      }
    ?>
      <!-- print button is in the navbar -->
      <div id="instructions" class="ops-workflow noprint d-none">
        <div class="ops-panel">
          <p class="ops-panel-text mb-0">
            Choose a set-out location for each car, then click <strong>SET OUT</strong>.
            After placing cars, <a href="organize_cars.php">organize positions</a> at the set-out location and in the train.
          </p>
          <div class="mt-3">
            <button id="finish_btn" name="finish_btn" value="SET OUT" type="submit" disabled class="btn btn-success btn-lg">SET OUT</button>
          </div>
        </div>
        <div class="ops-panel-action ops-panel-tools">
          <div class="ops-toolbar ops-toolbar-stack">
            <div class="ops-toolbar-section ops-toolbar-bulk">
              <label for="bulk_location" class="form-label fw-semibold mb-1">Set all checked locations to:</label>
              <select id="bulk_location" name="bulk_location" class="form-select" style="max-width: 20rem;" data-sts-change="bulkLocationChange">
                <option value="">Select location</option>
              </select>
            </div>
            <div class="ops-toolbar-section d-flex flex-wrap align-items-center gap-2">
              <span class="text-muted small">Assign checked cars to their final destinations.</span>
              <button type="button" class="btn btn-outline-primary btn-sm" data-sts-click="assignFinalDestinations">Assign</button>
              <div id="final_dest_assign_status" class="text-muted small d-none"></div>
            </div>
            <div class="ops-toolbar-section ops-toolbar-filters">
              <?php require 'operations_station_filters.inc.php'; ?>
            </div>
          </div>
        </div>
      </div>
    <div id="job_table_div">
      <!-- the guts of the table are filled in by the HttpRequest call-back function -->
    </div>
    </form>
  </body>

  <script src="/sts/js/set_out.js?v=1"></script>

  </div>
  <script src="vendor/bootstrap/bootstrap.bundle.min.js"></script>
  <script src="vendor/jquery/jquery-3.6.0.min.js"></script>
  </body>
</html>
