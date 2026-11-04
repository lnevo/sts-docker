<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - View/Organize Cars</title>
    <link href="vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="operations_ui.css?v=3" rel="stylesheet">
    <script src="/sts/js/sts-actions.js?v=1"></script>
    <script src="/sts/js/organize_cars.js?v=1"></script>
    <?php
      // bring in the javascript function that shows rollingstock photos
      require 'show_image.php';
    ?>
  </head>
  <body class="bg-light ops-steppage ops-page-organize_cars">
    <?php
    require_once 'operations_nav.php';
    echo ops_render_navbar('organize_cars.php', 'Organize Cars', 'bi-sort-numeric-up');
    echo ops_render_step_bar('organize_cars.php', null);
    ?>
    <div class="px-4">
    <h5 class="mb-3">View and/or Organize Cars</h5>
    <!-- <form method="get" action="organize_cars.php"> -->
    <?php
      // this program displays a list of locations and a list of jobs, either of which can be used
      // to display a list of cars at that location or in that job so the user can adjust the positions of the cars

      // bring in the function files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // open a database connection
      $dbc = open_db();
      $organize_job_total = organize_total_cars_by_job($dbc);
      $organize_location_total = organize_total_cars_at_locations($dbc);

      // set up a Bootstrap card row with two cards: one for jobs, one for locations
      print '<p class="text-muted">Use these functions to view cars in selected trains or on selected tracks and also to organize them in the database so it knows their physical arrangement.</p>';
      print '<div class="row g-3 mb-3">';
      // job card
      print '  <div class="col-md-6">';
      print '    <div class="card h-100">';
      print '      <div class="card-header fw-semibold"><i class="bi bi-train-front"></i> By Job/Train - ' . (int) $organize_job_total . '</div>';
      print '      <div class="card-body">';
      print '        <p class="card-text text-muted small">Select a job/train and click <b>VIEW/ORGANIZE</b>.<br />After arranging the cars, click <a href="display_switchlist.php">here</a> to generate an updated switch list.</p>';
      print        drop_down_jobs('job_list', '3', 'enable_org_job_btn()', 'organize') . '<br class="mt-2" />';
      print '        <button id="org_job" name="org_job" data-sts-click="get_cars_in_job" disabled class="btn btn-success mt-2">';
      print '          VIEW/ORGANIZE';
      print '        </button>';
      print '      </div>';
      print '    </div>';
      print '  </div>';
      // location card
      print '  <div class="col-md-6">';
      print '    <div class="card h-100">';
      print '      <div class="card-header fw-semibold"><i class="bi bi-geo-alt"></i> By Location - ' . (int) $organize_location_total . '</div>';
      print '      <div class="card-body">';
      print '        <p class="card-text text-muted small">Select a location and click <b>VIEW/ORGANIZE</b>.<br />After arranging the cars, click <a href="display_station_report.php">here</a> to generate an updated station car report.</p>';
      print        drop_down_locations('location_list', '0', 'enable_org_loc_btn()', true) . '<br class="mt-2" />';
      print '        <button id="org_loc" name="org_loc" data-sts-click="get_cars_at_location" disabled class="btn btn-success mt-2">';
      print '          VIEW/ORGANIZE';
      print '        </button>';
      print '      </div>';
      print '    </div>';
      print '  </div>';
      print '</div>';
      // update section
      print '<div class="mb-3">';
      print '  <h5 id="location_or_job_name" class="mb-2"></h5>';
      print '  <button id="update_pos" name="update_pos" disabled data-sts-click="update_car_positions" class="btn btn-warning">';
      print '    <b>UPDATE POSITIONS</b>';
      print '  </button>';
      print '  <span id="update_feedback" class="ms-2 text-muted small"></span>';
      print '</div>';
      print '<div id="car_section"></div>';

    ?>
    <!-- </form> -->
  </body>
  </div>
  <script src="vendor/bootstrap/bootstrap.bundle.min.js"></script>
  <script src="vendor/jquery/jquery-3.6.0.min.js"></script>
  </body>
</html>
