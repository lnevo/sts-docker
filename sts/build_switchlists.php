<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Build Switch Lists</title>
    <link href="vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="operations_ui.css?v=3" rel="stylesheet">
    <script src="/sts/js/sts-actions.js?v=1"></script>
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
  <script src="/sts/js/build_switchlists.js?v=1"></script>
    <?php
      // bring in the javascript function that shows rollingstock photos
      require 'show_image.php';

      // bring in the utility files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

    ?>
  </head>
  <body class="bg-light">
    <?php
    require_once 'operations_nav.php';
    echo ops_render_navbar('build_switchlists.php', 'Build Switch Lists', 'bi-list-check');
    echo ops_render_step_bar('build_switchlists.php', null);
    ?>
    <div class="px-4 py-3">
  <h5 class="mb-3">Build Switch Lists</h5>
  <div class="row g-3 mb-4">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-geo-alt"></i> Assign Cars Station-by-Station</div>
        <div class="card-body">
          <p class="card-text text-muted small">Select a station (or All Stations) to assign cars to jobs/trains for pickup.</p>
          <?php print drop_down_stations('station_list', '', 'get_cars_and_jobs();', true, true); ?>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-robot"></i> Auto-Assign Cars</div>
        <div class="card-body">
          <p class="card-text text-muted small">Select a job/train and click AUTO-ASSIGN to automatically assign cars.</p>
          <div class="d-flex gap-2 flex-wrap align-items-center">
            <?php print drop_down_jobs("auto_assign_job", 2, "", true); ?>
            <button type="button" class="btn btn-success" data-sts-click="go_to_auto_assign">AUTO-ASSIGN</button>
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
        $row_count = intval(sts_post('row_count'));
        $num_cars_assigned = 0;

        // mark the cars selected for pickup by the user
        for ($i=0; $i<$row_count; $i++)
        {
          // construct the list and car field names
          $list_name = 'job_list' . $i;
          $car_name = 'car' . $i;

          // does the drop-down list have a job name in it?
          if (isset($_POST[$list_name]) && strlen(sts_post($list_name)) > 0)
          {
            // build a query to update the car's "handled_by" field
            $post_job_id = sts_id(sts_post($list_name));
            $post_car_id = sts_id(sts_post($car_name));
            if(!sts_q($dbc, 'update cars set handled_by_job_id = ? where id = ?', array($post_job_id, $post_car_id)))
            {
              print 'Update Error: ' . sts_html(sts_err($dbc));
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

            $rs = sts_q($dbc, 'select current_location_id from cars where id = ?', array($post_car_id));
            $row = mysqli_fetch_array($rs);
            $location = $row['current_location_id'];

            $rs = sts_q($dbc, 'select name from jobs where id = ?', array($post_job_id));
            $row = mysqli_fetch_array($rs);
            $job_name = $row['name'];

            // insert a car history record
            if (!sts_q($dbc, 'insert into history(car_id, session_nbr, event_date, event, location) values (?, ?, ?, ?, ?)',
                       array($post_car_id, $session_nbr, date("Y-m-d H:i:s"), 'Assigned to Job ' . $job_name, $location)))
            {
              print 'Insert error: ' . sts_html(sts_err($dbc)) . '<br /><br />';
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
            <select id="bulk_job" name="bulk_job" class="form-select" style="max-width: 20rem;" data-sts-change="bulkJobChange">
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

  </div>
  <script src="vendor/bootstrap/bootstrap.bundle.min.js"></script>
  <script src="vendor/jquery/jquery-3.6.0.min.js"></script>
  </body>

</html>
