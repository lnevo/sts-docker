<?php
// Single-file report: ?generate_report=1 outputs the report HTML fragment and
// exits; the form below fetches it into #report-content. (Replaces the old
// printable_station_qr_code_report.php, which now just redirects here.)
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

  // write a location's bar code image (based on the location's internal ID)
  $make_barcode = function ($location_id, $file_name) use ($image_width, $image_height, $x, $y, $angle, $type, $bar_width, $bar_height) {
    $im = imagecreatetruecolor($image_width, $image_height);
    $black = ImageColorAllocate($im, 0x00, 0x00, 0x00);
    $white = ImageColorAllocate($im, 0xff, 0xff, 0xff);
    imagefilledrectangle($im, 0, 0, $image_width, $image_height, $white);
    Barcode::gd($im, $black, $x, $y, $angle, $type, array('code' => '%' . $location_id . '%'), $bar_width, $bar_height);
    if (is_file($file_name)) {
      unlink($file_name);
    }
    imagepng($im, $file_name);
    imagedestroy($im);
  };
  $make_qr = function ($code, $file_name) {
    if (is_file($file_name)) {
      unlink($file_name);
    }
    QRcode::png($code, $file_name, 'M', 2, 1);
  };

  // locations with an * in their code are left out
  if ($station_name != 'All') {
    $sql = 'select locations.code as code,
                   locations.id as location_id,
                   routing.station as station,
                   locations.track as track,
                   locations.spot as spot,
                   locations.remarks as remarks
              from locations, routing
             where locations.station = routing.id
               and routing.station = "' . mysqli_real_escape_string($dbc, $station_name) . '"
               and (INSTR(locations.code, "*") = 0)
             order by station, code';
    $none = 'No locations found at ' . htmlspecialchars($station_name);
  } else {
    $sql = 'select locations.code as code,
                   locations.id as location_id,
                   routing.station as station,
                   locations.track as track,
                   locations.spot as spot,
                   locations.remarks as remarks
              from locations
              left join routing on routing.id = locations.station
             where (INSTR(locations.code, "*") = 0)
             order by station, code';
    $none = 'No stations found on the system.';
  }

  $rs = mysqli_query($dbc, $sql);
  if (!$rs || mysqli_num_rows($rs) == 0) {
    echo $none . '<br />';
    exit;
  }
  ?>
<div class="report-screen-only"><?php /* the legacy printout was just the table; the heading is screen-only */ ?>
<h1><?= htmlspecialchars($rr_name) ?></h1>
<h2 style="display:inline;">Location QR/Bar Code Report</h2>
<h3>Station: <?= htmlspecialchars($station_name) ?></h3>
QR/Bar codes for locations at this station<br /><br />
</div>
<table class="report-table">
  <thead>
    <tr><th>Location</th><th>Code</th></tr>
  </thead>
  <tbody>
<?php
  while ($row = mysqli_fetch_array($rs)) {
    $code = $row['code'];
    $info = htmlspecialchars($code) . '<br />
            Station: ' . htmlspecialchars($row['station'] ?? '') . '<br />Track: ' . htmlspecialchars($row['track'] ?? '') . '<br />Spot: ' . htmlspecialchars($row['spot'] ?? '') . '<br />
            Remarks: ' . htmlspecialchars($row['remarks'] ?? '');
    $safe_code = str_replace(' ', '', $code);
    if ($code_type == 'qr') {
      $file = './ImageStore/DB_Images/qrcodes/' . $safe_code . '.png';
      $make_qr($code, $file);
      $width = ($station_name != 'All') ? '100px' : '50px';
      echo '<tr><td>' . $info . '</td>
            <td style="vertical-align: middle; width:' . $width . '; text-align: center;"><img src="' . htmlspecialchars($file) . '"></td></tr>';
    } elseif ($station_name != 'All') {
      $file = './ImageStore/DB_Images/barcodes/' . str_replace(' ', '', '%' . $row['location_id'] . '%') . '.png';
      $make_barcode($row['location_id'], $file);
      echo '<tr><td>' . $info . '</td>
            <td><img src="' . htmlspecialchars($file) . '" style="text-align:right;"></td></tr>';
    } else {
      $file = './ImageStore/DB_Images/barcodes/' . $safe_code . '.png';
      $make_barcode($row['location_id'], $file);
      echo '<tr><td>' . $info . '</td>
            <td style="text-align:center;"><img src="' . htmlspecialchars($file) . '"><br />' . htmlspecialchars($code) . '</td></tr>';
    }
  }
  ?>
  </tbody>
</table>
<?php
  exit;
}

require_once 'page_chrome.php';
sts_page_start([
  'title' => 'Location QR/Bar Code Report',
  'icon' => 'bi-qr-code',
  'section' => 'report',
  'head_extra' => '<link href="report_page.css?v=1" rel="stylesheet">',
]);
?>
    <div class="sts-card report-screen-only">
    <form id="report-form" method="get" data-report-endpoint="display_station_qr_code_report.php" data-enable-display data-all-select="station_name">
    <p>Select a station, the desired type of code, and then click the Display button. The QR or bar codes for all of the named locations at the selected station will be displayed below, formatted for printing.</p>

    <?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';

      // generate a drop-down list of stations, radio buttons for the type of codes, and the submit button
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
