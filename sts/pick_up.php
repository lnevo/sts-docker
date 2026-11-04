<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Pick Up Cars</title>
    <link href="vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="operations_ui.css?v=3" rel="stylesheet">
    <script src="/sts/js/sts-actions.js?v=1"></script>
    <script src="operations_table_filters.js"></script>
    <?php
      // bring in the javascript function that shows rollingstock photos
      require 'show_image.php';
    ?>
  <script src="/sts/js/pick_up.js?v=1"></script>
  </head>
  <body class="bg-light ops-steppage ops-page-pick_up">
    <?php
    require_once 'operations_nav.php';
    echo ops_render_navbar('pick_up.php', 'Pick Up Cars', 'bi-arrow-up-circle', '<button class="btn btn-light btn-sm" data-sts-print><i class="bi bi-printer"></i> Print</button>');
    echo ops_render_step_bar('pick_up.php', null);
    ?>
    <div class="px-4 py-3">
    <h5 class="mb-3">Pick Up Cars</h5>
    <form action="pick_up.php" method="get">
    <?php
      // bring in the utility files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // get a database connection
      $dbc = open_db();
      $workflow_alert_html = '';

      // was the Finish button clicked?
      if (isset($_GET['finish_btn']))
      {
        $num_cars_picked_up = 0;
        // only try to pick cars if there were some to be picked up in the first place
        if (isset($_GET['row_count']))
        {
          // get the number of rows that were on the page
          $row_count = (int) sts_get('row_count');

          // update the position (set to 1) of all cars with a checkmark
          for ($i=0; $i<$row_count; $i++)
          {
            // construct the list and car field names
            $check_name = 'check' . $i;
            $car_name = 'car' . $i;

            // go through all of the check boxes and for cars with a checkmark set their position to 1
            if (isset($_GET[$check_name]))
            {
              $car_id = sts_id(sts_get($car_name));
              if ($car_id < 1)
              {
                continue;
              }
              // save the car's current location to give to the history file after the car's been picked up
              $sql = 'select current_location_id from cars where id = ?';
//print 'SQL: ' . $sql . '<br /><br />';
              $rs = sts_q($dbc, $sql, array($car_id));
              $row = mysqli_fetch_array($rs);
              $location = $row['current_location_id'];
//print 'location: ' . $location . '<br /><br />';
              /*            // and it's position to 0 (zero) so they appear at the top of the list when reorganizing the car order
                            $sql = 'update cars
                                    set current_location_id = "0",
                                        position="0"
                                    where id = ?';
              */

              // build a query to set the car's current location to 0 (zero) indicating that it's in a train
              // don't set the position to 0 because that undoes any organization performed by the user
              $sql = 'update cars
                      set current_location_id = "0"
                      where id = ?';

              if(!sts_q($dbc, $sql, array($car_id)))
              {
                print 'Update Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql . '<br />';
              }

              // get the info that the history table needs
              $sql = 'select setting_value from settings where setting_name = "session_nbr"';
              $rs = mysqli_query($dbc, $sql);
              $row = mysqli_fetch_array($rs);
              $session_nbr = $row['setting_value'];

              $sql = 'select jobs.name as job_name
                        from jobs, cars
                       where cars.id = ? and jobs.id = cars.handled_by_job_id';
//print 'SQL: ' . $sql . '<br /><br />';
              $rs = sts_q($dbc, $sql, array($car_id));
              $row = mysqli_fetch_array($rs);
              $job_name = $row['job_name'];

              // insert a car history record
              $sql = 'insert into history(car_id, session_nbr, event_date, event, location)
                      values (?, ?, ?, ?, ?)';

              if (!sts_q($dbc, $sql, array($car_id, $session_nbr, date("Y-m-d H:i:s"), 'Picked up by Job ' . $job_name, $location)))
              {
                print 'Insert error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql . '<br /><br />';
              }
              else
              {
                $num_cars_picked_up++;
              }
            }
          }
        }
        $workflow_alert_html = '<div class="alert alert-success noprint ops-workflow-alert mb-4">'
            . '<span>' . htmlspecialchars((string)$num_cars_picked_up) . ' car(s) picked up.</span>'
            . '<a class="btn btn-success" href="set_out.php">Go to Set Out Cars</a>'
            . '</div>';
      }
      print '<div class="row g-3 mb-4 noprint">';
      print '<div class="col-md-6">';
      print '<div class="card h-100">';
      print '<div class="card-header fw-semibold"><i class="bi bi-train-front"></i> Select Job / Train</div>';
      print '<div class="card-body">';
      print '<p class="card-text text-muted small mb-2">Choose the job or train doing the pickups.</p>';
      print drop_down_jobs("job_list", '', "get_jobs_and_cars();", "pickup");
      print '</div></div></div></div>';

      if ($workflow_alert_html !== '')
      {
        print $workflow_alert_html;
      }
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
              <select id="bulk_pickup_location" name="bulk_pickup_location" class="form-select" style="max-width: 20rem;" data-sts-change="checkRowsAtPickupLocation">
                <option value="">Select pickup location</option>
              </select>
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

  </div>
  <script src="vendor/bootstrap/bootstrap.bundle.min.js"></script>
  <script src="vendor/jquery/jquery-3.6.0.min.js"></script>
  </body>

</html>
