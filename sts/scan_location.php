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
        if ((substr($_GET['location'], 0, 1) == '%') && ((substr($_GET['location'], -1, 1)) == '%'))
        {
          // if it is, get the car's reporting marks
          $location_id = substr($_GET['location'], 1, strlen($_GET['location'])-2);
          $sql = 'select code from locations where id = ' . $location_id;
          $rs = mysqli_query($dbc, $sql);
          $row = mysqli_fetch_array($rs);
          $scan_code = $row['code'];
        }
        else
        {
          // if not, just pass the scanned code on to the query
          $scan_code = $_GET['location'];
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
                 where locations.code = "' . $scan_code . '"
                   and locations.station = routing.id';

        $rs = mysqli_query($dbc, $sql);
        if (mysqli_num_rows($rs) > 0)
        {
          $row = mysqli_fetch_array($rs);

          // build a table showing the information
          // add a link to db_edit_location.php
          $location_link = '<a href="db_edit.php?tbl_name=locations&obj_id=' . $row['id'] . 
                           '&obj_name=' . $row['code'] . '">' . $row['code'] . '</a>';

          $station_link = '<a href="db_edit.php?tbl_name=routing&obj_id=' . $row['station_id'] . 
                          '&obj_name=' . $row['station'] . '">' . $row['station'] . '</a>';

          print '<table class="table table-sm table-bordered table-hover bg-white">
                 <tr><th>Property</th><th>Value</th></tr>
                 <tr><td>Location Code</td><td>' . $location_link . '</td>
                 <tr><td>Station</td><td>' . $station_link . '</td>
                 <tr><td>Track</td><td>' . $row['track'] . '</td>
                 <tr><td>Spot</td><td>' . $row['spot'] . '</td>
                 <tr><td>Remarks</td><td>' . $row['remarks'] . '</td>
                 </table>';

          // find all of the cars at this location
          $sql2 = 'select cars.position as position,
                          cars.reporting_marks as reporting_marks,
                          cars.car_code_id as car_code_id,
                          cars.status as status,
                          car_codes.code as car_code
                     from cars, car_codes
                    where cars.current_location_id = "' . $row['id'] . '"
                      and cars.car_code_id = car_codes.id
                    order by cars.position, cars.reporting_marks';

          $rs2 = mysqli_query($dbc, $sql2);
          if (mysqli_num_rows($rs2) > 0)
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
              $car_link = '<a href="scan_car.php?scan_code=' . $row2['reporting_marks'] . '&scan_btn=Scan">' . $row2['reporting_marks'] . '</a>';
              print '<tr>
                     <td align="center">' . $row2['position'] . '</td>
                     <td>' . $car_link . '</td>
                     <td align="center">' . $row2['car_code'] . '</td>
                     <td>' . $row2['status'] . '</td>
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
          print '<div class="alert alert-warning">Location "' . $_GET['location'] . '" not found. Check spelling.</div>';
        }
      }
    ?>
    </form>
</div>
<?php sts_page_end(); ?>
