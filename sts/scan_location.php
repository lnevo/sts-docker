<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Scan Locations',
    'icon' => 'bi-upc-scan',
    'section' => 'data',
    'body_attrs' => 'onload=\'document.getElementById("location").focus();\'',
]);
?>
<div class="sts-card">
    <div id="instructions" class="text-muted mb-3">
    If the scanner is set to send a "Carriage Return" at the end of the scan,<br />
    it will automatically trigger the Scan button.<br /><br />
    To manually search for location information, enter the location's ID code<br />
    into the input box and then click the <b>SCAN</b> button.
    </div>
<form action="scan_location.php" method="get">
      <div class="d-flex gap-2 mb-3">
        <input id="location" name="location" type="text" class="form-control form-control-lg" autocomplete="off" autofocus>
        <input name="scan_btn" value="SCAN" type="submit" class="btn btn-primary btn-lg">
      </div>

    <?php
      // display everything we know about the location

      // was the scan button clicked?
      if (isset($_GET['scan_btn']))
      {      
        // pull in the utility files
        require 'open_db.php';

        // get a database connection
        $dbc = open_db();
        
        // check to see if the incoming code is a car ID number delimited by "-"
        $raw_location = sts_get('location');
        if ((substr($raw_location, 0, 1) == '%') && ((substr($raw_location, -1, 1)) == '%'))
        {
          // if it is, get the car's reporting marks
          $location_id = sts_id(substr($raw_location, 1, strlen($raw_location)-2));
          $rs = sts_q($dbc, 'select code from locations where id = ?', array($location_id));
          $row = ($rs instanceof mysqli_result) ? mysqli_fetch_array($rs) : null;
          $scan_code = $row ? $row['code'] : '';
        }
        else
        {
          // if not, just pass the scanned code on to the query
          $scan_code = $raw_location;
        }
        
        // build a query to bring in the info
        $sql = 'select locations.id as id,
                       locations.code as code,
                       locations.station as station_id,
                       locations.track as track,
                       locations.spot as spot,
                       locations.remarks as remarks,
                       routing.station as station
                  from locations, routing
                 where locations.code = ?
                   and locations.station = routing.id';

        $rs = sts_q($dbc, $sql, array($scan_code));
        if ($rs instanceof mysqli_result && mysqli_num_rows($rs) > 0)
        {
          $row = mysqli_fetch_array($rs);
          $e = array_map('sts_h', $row);  // html-escaped copy for output
          $u = array_map(function ($v) { return urlencode((string) $v); }, $row);  // url-encoded copy for links

          // build a table showing the information
          // add a link to db_edit_location.php
          $location_link = '<a href="db_edit.php?tbl_name=locations&obj_id=' . $u['id'] . 
                           '&obj_name=' . $u['code'] . '">' . $e['code'] . '</a>';

          $station_link = '<a href="db_edit.php?tbl_name=routing&obj_id=' . $u['station_id'] . 
                          '&obj_name=' . $u['station'] . '">' . $e['station'] . '</a>';

          print '<table class="table table-sm table-bordered table-hover bg-white">
                 <tr><th>Property</th><th>Value</th></tr>
                 <tr><td>Location Code</td><td>' . $location_link . '</td>
                 <tr><td>Station</td><td>' . $station_link . '</td>
                 <tr><td>Track</td><td>' . $e['track'] . '</td>
                 <tr><td>Spot</td><td>' . $e['spot'] . '</td>
                 <tr><td>Remarks</td><td>' . $e['remarks'] . '</td>
                 </table>';

          // find all of the cars at this location
          $sql2 = 'select cars.position as position,
                          cars.reporting_marks as reporting_marks,
                          cars.car_code_id as car_code_id,
                          cars.status as status,
                          car_codes.code as car_code
                     from cars, car_codes
                    where cars.current_location_id = ?
                      and cars.car_code_id = car_codes.id
                    order by cars.position, cars.reporting_marks';

          $rs2 = sts_q($dbc, $sql2, array($row['id']));
          if ($rs2 instanceof mysqli_result && mysqli_num_rows($rs2) > 0)
          {
            print '<h3 class="mt-4">Cars at this location</h3>';
            print '<table class="table table-sm table-bordered table-hover bg-white">';
            print '<tr>
                   <th>Position</th>
                   <th>Reporting<br />Marks</th>
                   <th>Car<br />Type</th>
                   <th>Status</th>
                   </tr>';

            while ($row2 = mysqli_fetch_array($rs2))
            {
              $car_link = '<a href="scan_car.php?scan_code=' . urlencode($row2['reporting_marks']) . '&scan_btn=Scan">' . sts_h($row2['reporting_marks']) . '</a>';
              print '<tr>
                     <td align="center">' . sts_h($row2['position']) . '</td>
                     <td>' . $car_link . '</td>
                     <td align="center">' . sts_h($row2['car_code']) . '</td>
                     <td>' . sts_h($row2['status']) . '</td>
                     </tr>';
            }
            
            print '</table>';
          }
          else
          {
            print '<div class="alert alert-info mt-3">No cars have been found at this location.</div>';
          }
        }
        else
        {
          print '<div class="alert alert-warning">Location "' . sts_h($raw_location) . '" not found. Check spelling.</div>';
        }
      }
    ?>
    </form>
</div>
<?php sts_page_end(); ?>
