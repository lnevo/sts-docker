<?php
// Single-file report: ?generate_report=1 outputs the report HTML fragment and
// exits; the form below fetches it into #report-content. (Replaces the old
// printable_car_qr_report.php, which now just redirects here.)
if (isset($_GET['generate_report'])) {
  require 'drop_down_list_functions.php';
  require 'open_db.php';
  require '../phpqrcode/qrlib.php';
  require '../php-barcode/php-barcode.php';

  // parameters for the bar code
  $image_height = 30;
  $image_width = 400;
  $x = $image_width / 2;  // barcode center
  $y = $image_height / 2; // barcode center
  $bar_width = 2;
  $bar_height = 20;
  $angle = 0;
  $type = 'code39';

  $dbc = open_db();
  $code_type = ($_GET['code_type'] ?? 'qr') == 'qr' ? 'qr' : 'bar';

  // station_name is a station ID; anything that is not a positive number means All
  $station_id = $_GET['station_name'] ?? 0;
  $station_name = 'All';
  if (is_numeric($station_id) && (int) $station_id > 0) {
    $rs = mysqli_query($dbc, 'select station from routing where id = ' . (int) $station_id);
    $row = mysqli_fetch_row($rs);
    $station_name = $row[0] ?? 'All';
  }

  $rs = mysqli_query($dbc, 'select setting_value from settings where setting_name = "railroad_name"');
  $row = mysqli_fetch_row($rs);
  $rr_name = $row[0] ?? '';

  // write a car's bar code image (based on the car's internal ID to avoid illegal characters)
  $make_barcode = function ($car_id, $file_name) use ($image_width, $image_height, $x, $y, $angle, $type, $bar_width, $bar_height) {
    $im = imagecreatetruecolor($image_width, $image_height);
    $black = ImageColorAllocate($im, 0x00, 0x00, 0x00);
    $white = ImageColorAllocate($im, 0xff, 0xff, 0xff);
    imagefilledrectangle($im, 0, 0, $image_width, $image_height, $white);
    Barcode::gd($im, $black, $x, $y, $angle, $type, array('code' => '-' . $car_id . '-'), $bar_width, $bar_height);
    if (is_file($file_name)) {
      unlink($file_name);
    }
    imagepng($im, $file_name);
    imagedestroy($im);
  };
  $make_qr = function ($marks, $file_name) {
    if (is_file($file_name)) {
      unlink($file_name);
    }
    QRcode::png($marks, $file_name, 'M', 1.5, 1);
  };

  if ($station_name != 'All') {
    $sql = 'select locations.code as current_location,
                   cars.reporting_marks as reporting_marks,
                   cars.id as car_id
              from cars
              left join locations on locations.id = cars.current_location_id
             where cars.current_location_id in
                   (select locations.id
                      from locations, routing
                     where locations.station = routing.id and routing.station = "' . mysqli_real_escape_string($dbc, $station_name) . '")
             order by current_location, reporting_marks';
    $none = 'No cars found at ' . htmlspecialchars($station_name);
  } else {
    $sql = 'select routing.station as current_station,
                   locations.code as current_location,
                   cars.reporting_marks as reporting_marks,
                   cars.id as car_id
              from cars
              left join locations on locations.id = cars.current_location_id
              left join routing on routing.id = locations.station
             order by current_location, reporting_marks';
    $none = 'No cars found on the system.';
  }

  $rs = mysqli_query($dbc, $sql);
  if (!$rs || mysqli_num_rows($rs) == 0) {
    echo $none . '<br />';
    exit;
  }
  ?>
<div class="report-screen-only"><?php /* the legacy printout was just the table; the heading is screen-only */ ?>
<div class="report-screen-only"><?php /* the legacy printout was just the table; the heading is screen-only */ ?>
<h1><?= htmlspecialchars($rr_name) ?></h1>
<h2 style="display:inline;">Car QR/Bar Code Report</h2>
<h3>Station: <?= htmlspecialchars($station_name) ?></h3>
Car QR/Bar Codes<br /><br />
</div>
</div>
<table class="report-table" style="font: normal 10px Verdana, Arial, sans-serif;">
<?php
  if ($station_name != 'All') {
    echo '<thead><tr><th>Location</th><th>' . ($code_type == 'qr' ? 'QR Code' : 'Barcode') . '</th></tr></thead><tbody>';
    while ($row = mysqli_fetch_array($rs)) {
      $marks = htmlspecialchars($row['reporting_marks']);
      if ($code_type == 'qr') {
        $file = './ImageStore/DB_Images/qrcodes/' . str_replace(' ', '', $row['reporting_marks']) . '.png';
        $make_qr($row['reporting_marks'], $file);
        echo '<tr><td>' . htmlspecialchars($row['current_location'] ?? '') . '</td>
              <td><img src="' . htmlspecialchars($file) . '" style="vertical-align: middle">&nbsp;&nbsp;' . $marks . '</td></tr>';
      } else {
        $file = './ImageStore/DB_Images/barcodes/' . str_replace(' ', '', '-' . $row['car_id'] . '-') . 'ID.png';
        $make_barcode($row['car_id'], $file);
        echo '<tr><td>' . htmlspecialchars($row['current_location'] ?? '') . '</td>
              <td style="text-align:center"><img src="' . htmlspecialchars($file) . '" style="vertical-align: middle"><br />' . $marks . '</td></tr>';
      }
    }
  } else {
    // (no <thead>: the legacy page did not repeat this header row on every printed page)
    echo '<tr><th>Station</th><th>Location</th><th>Code</th></tr>';
    $prev_row = '';
    $first_row = true;
    while ($row = mysqli_fetch_array($rs)) {
      // blank row between locations
      if (($row['current_location'] != $prev_row) && (!$first_row)) {
        echo '<tr><td colspan="10"></td></tr>';
      }
      $prev_row = $row['current_location'];
      $first_row = false;
      $marks = htmlspecialchars($row['reporting_marks']);
      $cells = '<td style="vertical-align: middle;">' . htmlspecialchars($row['current_station'] ?? '') . '</td>
                <td style="vertical-align: middle;">' . htmlspecialchars($row['current_location'] ?? '') . '</td>';
      if ($code_type == 'qr') {
        $file = './ImageStore/DB_Images/qrcodes/' . str_replace(' ', '', $row['reporting_marks']) . '.png';
        $make_qr($row['reporting_marks'], $file);
        echo '<tr>' . $cells . '<td><img src="' . htmlspecialchars($file) . '" style="vertical-align: middle">&nbsp;&nbsp;' . $marks . '</td></tr>';
      } else {
        $file = './ImageStore/DB_Images/barcodes/' . str_replace(' ', '', $row['reporting_marks']) . '.png';
        $make_barcode($row['car_id'], $file);
        echo '<tr>' . $cells . '<td style="text-align:center"><img src="' . htmlspecialchars($file) . '" style="vertical-align: middle"><br />' . $marks . '</td></tr>';
      }
    }
  }
  echo $station_name != 'All' ? '</tbody>' : '';
  ?>
</table>
<?php
  exit;
}

require_once 'page_chrome.php';
sts_page_start([
  'title' => 'Car QR/Bar Code Report',
  'icon' => 'bi-qr-code',
  'section' => 'report',
  'head_extra' => '<link href="report_page.css?v=1" rel="stylesheet">',
]);
?>
    <div class="sts-card report-screen-only">
    <form id="report-form" method="get" data-report-endpoint="display_car_qr_report.php" data-enable-display data-all-select="station_name">
    <p>Select the desired station, the desired type of code, and then click the Display button. The codes for all of the cars at the selected station will be displayed below, formatted for printing.</p>

    <?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';

      // generate a drop-down list of stations, radio buttons for the type of codes,  and the submit button
      print '<div class="mb-3"><label class="form-label" for="station_name">Station</label>' . drop_down_stations('station_name', '', '') . '</div>';

      print '<div class="mb-3">
               <div class="form-check form-check-inline"><input class="form-check-input" id="code_type_qr" name="code_type" type="radio" value="qr" checked> <label class="form-check-label" for="code_type_qr">QR Code</label></div>
               <div class="form-check form-check-inline"><input class="form-check-input" id="code_type_bar" name="code_type" type="radio" value="bar"> <label class="form-check-label" for="code_type_bar">Bar Code</label></div>
             </div>';

      print '<button id="display_btn" name="display_btn" value="Display" type="submit" class="btn btn-primary" disabled><i class="bi bi-eye"></i> Display</button>';
    ?>
    </form>
    </div>

    <div id="report-card" class="sts-card report-card" hidden>
      <div class="report-controls report-screen-only">
        <button type="button" class="btn btn-primary btn-sm" data-sts-print><i class="bi bi-printer"></i> Print</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="report-modify"><i class="bi bi-pencil"></i> Modify Search</button>
      </div>
      <div id="report-content" class="report-output"></div>
    </div>
<script src="/sts/js/report-common.js?v=1"></script>
<script src="/sts/js/report-page.js?v=3"></script>
<?php sts_page_end(); ?>
