<?php
// Single-file report: ?generate_report=1 outputs the report HTML fragment and
// exits; the form below fetches it into #report-content. (Replaces the old
// printable_fleet_report.php, which now just redirects here.)
if (isset($_GET['generate_report'])) {
  require 'drop_down_list_functions.php';
  require 'open_db.php';

  $dbc = open_db();

  $car_code_id = $_GET['car_code'] ?? 0;
  $car_code = 'All';
  if (is_numeric($car_code_id) && (int) $car_code_id > 0) {
    // a car code ID other than 0 (zero): look up the actual car code
    $rs = mysqli_query($dbc, 'select code from car_codes where id = ' . (int) $car_code_id);
    $row = mysqli_fetch_row($rs);
    $car_code = $row[0] ?? 'All';
  }

  $rs = mysqli_query($dbc, 'select setting_value from settings where setting_name = "railroad_name"');
  $row = mysqli_fetch_row($rs);
  $rr_name = $row[0] ?? '';

  if ($car_code != 'All') {
    // substitute % (SQL wild card) for any * in the car code
    $like = mysqli_real_escape_string($dbc, str_replace('*', '%', $car_code));
    $sql = 'select car_codes.code as car_code,
                   routing.station as current_station,
                   locations.code as current_location,
                   cars.reporting_marks as reporting_marks,
                   cars.status as status,
                   cars.remarks as remarks
              from (cars, car_codes)
              left join locations on locations.id = cars.current_location_id
              left join routing on routing.id = locations.station
             where car_codes.code like "' . $like . '" and cars.car_code_id = car_codes.id
             order by car_code, current_location, reporting_marks';
    $intro = 'List of cars of this type and where they are located.';
    $none = 'No cars of this type found';
  } else {
    $sql = 'select car_codes.code as car_code,
                   routing.station as current_station,
                   locations.code as current_location,
                   cars.reporting_marks as reporting_marks,
                   cars.status as status,
                   cars.remarks as remarks
              from cars, car_codes, locations, routing
             where cars.car_code_id = car_codes.id
               and locations.id = cars.current_location_id
               and routing.id = locations.station
             order by car_code, current_location, reporting_marks';
    $intro = 'List of all cars and where they are located';
    $none = 'No cars found on the system.';
  }

  $rs = mysqli_query($dbc, $sql);
  if (!$rs || mysqli_num_rows($rs) == 0) {
    echo $none . '<br />';
    exit;
  }
  ?>
<h1><?= htmlspecialchars($rr_name) ?></h1>
<h2 style="display:inline;">Car Fleet Management Report</h2>
<h3>Car Code: <?= htmlspecialchars($car_code) ?></h3>
<?= $intro ?><br /><br />
<table class="report-table" style="font: normal 10px Verdana, Arial, sans-serif; white-space: nowrap;">
  <thead>
    <tr style="background-color: #F5F5F5"><th>Car<br />Code</th><th><u>Station</u><br />Location</th><th>Reporting<br />Marks</th><th>Status</th><th>Remarks</th></tr>
  </thead>
  <tbody>
<?php
  $prev_row = '';
  $first_row = true;
  while ($row = mysqli_fetch_array($rs)) {
    // blank row between car codes
    if (($row['car_code'] != $prev_row) && (!$first_row)) {
      echo '<tr><td colspan="10" class="report-gap"></td></tr>';
    }
    $prev_row = $row['car_code'];
    $first_row = false;
    echo '<tr>
            <td style="text-align: center;">' . htmlspecialchars($row['car_code']) . '</td>
            <td><u>' . htmlspecialchars($row['current_station'] ?? '') . '</u><br />' . htmlspecialchars($row['current_location'] ?? '') . '</td>
            <td>' . htmlspecialchars($row['reporting_marks']) . '</td>
            <td>' . htmlspecialchars($row['status'] ?? '') . '</td>
            <td>' . htmlspecialchars($row['remarks'] ?? '') . '</td>
          </tr>';
  }
  ?>
  </tbody>
</table>
<?php
  exit;
}

require_once 'page_chrome.php';
sts_page_start([
  'title' => 'Car Fleet Management Report',
  'icon' => 'bi-train-freight-front',
  'section' => 'report',
  'head_extra' => '<link href="report_page.css?v=1" rel="stylesheet">',
]);
?>
    <div class="sts-card report-screen-only">
    <form id="report-form" method="get">
    <p>Select a car type and then click the <b>Display</b> button.</p>
    <p>A list of cars of that type and their location will be displayed below, formatted for printing.</p>

    <?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';

      // generate a drop-down list of car codes and the submit button
      print '<div class="mb-3"><label class="form-label" for="car_code">Car type</label>';
      print drop_down_car_codes('car_code', '0', 'wild_ok');
      print '</div>';
      print '<button name="display_btn" value="DISPLAY" type="submit" class="btn btn-primary"><i class="bi bi-eye"></i> Display</button>';

      // generate some javascript that adds "All" to the top of the car code drop-down list
      print '<script>
               var drop_down_list = document.getElementById("car_code");
               var option = document.createElement("option");
               option.value = "0";
               option.text = "All";
               drop_down_list.add(option, drop_down_list[1]);
             </script>';
    ?>
    </form>
    </div>

    <div id="report-card" class="sts-card report-card" hidden>
      <div class="report-controls report-screen-only">
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="report-modify"><i class="bi bi-pencil"></i> Modify Search</button>
      </div>
      <div id="report-content" class="report-output"></div>
    </div>
<script src="report_page.js?v=1"></script>
<script>stsReportInit({ formId: 'report-form', endpoint: 'display_fleet_report.php' });</script>
<?php sts_page_end(); ?>
