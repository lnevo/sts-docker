<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Scan Cars',
    'icon' => 'bi-upc-scan',
    'section' => 'data',
]);
?>
<div class="sts-card">
    <div id="instructions" class="text-muted mb-3">
    If the scanner is set to send a "Carriage Return" at the end of the scan,<br />
    it will automatically trigger the Scan button.<br /><br />
    To manually search for car information, enter the car's reporting marks<br />
    into the Reporting Marks input box and then click the <b>SCAN</b> button.
    </div>
<form action="scan_car.php" method="get">
    <label for="scan_code" class="form-label">Scan QR code, Bar code, or RFID tag:</label>
    <div class="d-flex gap-2 mb-3">
      <input id="scan_code" name="scan_code" type="text" class="form-control form-control-lg" autocomplete="off" autofocus data-sts-autofocus>
      <input name="scan_btn" value="SCAN" type="submit" class="btn btn-primary btn-lg">
    </div>
    <?php
      // display everything we know about the selected car

      // was the scan button clicked?
      if (isset($_GET['scan_btn']))
      {
        // pull in the utility files
        require 'open_db.php';

        // get a database connection
        $dbc = open_db();
        
        // check to see if the incoming code is a car ID number delimited by "-"
        $raw_scan_code = sts_get('scan_code');
        if ((substr($raw_scan_code, 0, 1) == '-') && (substr($raw_scan_code, -1, 1)))
        {
          // if it is, get the car's reporting marks
          $car_id = sts_id(substr($raw_scan_code, 1, strlen($raw_scan_code)-2));
          $rs = sts_q($dbc, 'select reporting_marks from cars where id = ?', array($car_id));
          $row = ($rs instanceof mysqli_result) ? mysqli_fetch_array($rs) : null;
          $scan_code = $row ? $row['reporting_marks'] : '';
        }
        else
        {
          // if not, just pass the scanned code on to the query
          $scan_code = $raw_scan_code;
        }

        // build a query to bring in the info
        $sql = 'select cars.id as id,
                       cars.reporting_marks as reporting_marks,
                       cars.car_code_id as car_code_id,
                       cars.current_location_id as current_location_id,
                       cars.status as status,
                       cars.handled_by_job_id,
                       cars.RFID_code as RFID_code,
                       car_orders.waybill_number as waybill_number,
                       shipments.id as shipment_id,
                       shipments.code as shipment,
                       shipments.consignment as consignment_id,
                       shipments.loading_location as loading_location_id,
                       shipments.unloading_location as unloading_location_id,
                       cars.remarks as remarks,
                       cars.load_count as load_count,
                       car_codes.code as car_code,
                       commodities.code as consignment,
                       loc01.code as current_location,
                       loc02.code as loading_location,
                       loc03.code as unloading_location,
                       sta01.station as current_station,
                       sta02.station as loading_station,
                       sta03.station as unloading_station,
                       jobs.name as job_name
                  from cars
                  left join car_orders on cars.id = car_orders.car
                  left join shipments on shipments.id = car_orders.shipment
                  left join jobs on cars.handled_by_job_id = jobs.id
                  left join car_codes on cars.car_code_id = car_codes.id
                  left join commodities on shipments.consignment = commodities.id
                  left join locations loc01 on cars.current_location_id = loc01.id
                  left join locations loc02 on shipments.loading_location = loc02.id
                  left join locations loc03 on shipments.unloading_location = loc03.id
                  left join routing sta01 on sta01.id = loc01.station
                  left join routing sta02 on sta02.id = loc02.station
                  left join routing sta03 on sta03.id = loc03.station
                  where (cars.reporting_marks = ? or
                         cars.RFID_code = ?)';
        $rs = sts_q($dbc, $sql, array(strtoupper($scan_code), $raw_scan_code));
        if ($rs instanceof mysqli_result && mysqli_num_rows($rs) > 0)
        {
          $row = mysqli_fetch_array($rs);
          $e = array_map('sts_h', $row);  // html-escaped copy for output
          $u = array_map(function ($v) { return urlencode((string) $v); }, $row);  // url-encoded copy for links

          // build a table showing the information
          // add links to db_edit_car.php, db_edit_car_code.php, db_edit_location.php, db_edit_job.php, and db_edit_shipment.php programs

          $car_link = '<a href="db_edit.php?tbl_name=cars&obj_id=' .
                                 $u['reporting_marks'] . '&obj_name=' .
                                 $u['id'] . '">' . 
                                 $e['reporting_marks'] . '</a>';
                       
          $car_code_link = '<a href="db_edit.php?tbl_name=car_codes&obj_id=' . 
                            $u['car_code_id'] . '&obj_name=' .
                            $u['car_code'] . '">' .
                            $e['car_code'] . '</a>';
          
          if ($row['current_location_id'] > 0)
          {            
            $location_link = '<a href="scan_location.php?location=' .
                              $u['current_location'] . '&scan_btn=Scan">' .
                              $e['current_location'] . '</a>';
          }
          else
          {
            $location_link = 'In train';
          }
                           
          $job_link = '<a href="db_edit.php?tbl_name=jobs&obj_id=' .
                       $u['handled_by_job_id'] . '&obj_name=' .
                       $u['job_name'] . '">' .
                       $e['job_name'] . '</a>';
                       
          $shipment_link = '<a href="db_edit.php?tbl_name=shipments&obj_id=' .
                            $u['shipment_id'] . '&obj_name=' .
                            $u['shipment'] . '">' .
                            $e['shipment'] . '</a>';

          print '<table class="table table-sm table-bordered table-hover bg-white">
                   <thead>
                     <tr>
                       <th>Property</th>
                       <th>Value</th>
                     </tr>
                   </thead>
                   <tr>
                     <td>Reporting Marks:</td>
                     <td>' . $car_link . '</td>
                   </tr>
                   <tr>
                     <td>Car Code:</td>
                     <td>' . $car_code_link . '</td>
                   </tr>
                   <tr>
                     <td>Current Station:</td>
                     <td>' . $e['current_station'] . '</td>
                   </tr>
                   <tr>
                     <td>Current Location:</td>
                     <td>' . $location_link . '</td>
                   </tr>
                   <tr>
                     <td>Status:</td
                     ><td>' . $e['status'] . '</td>
                   </tr>
                   <tr>
                     <td>Handled By:</td>
                     <td>' . $job_link . '</td>
                   </tr>
                   <tr>
                     <td>Waybill Number:</td>
                     <td>' . $e['waybill_number'] . '</td>
                   </tr>
                   <tr>
                     <td>Shipment Code:</td>
                     <td>' . $shipment_link . '</td>
                   </tr>
                   <tr>
                     <td>Consignment:</td>
                     <td>' . $e['consignment'] . '</td>
                   </tr>
                   <tr>
                     <td>Loading Station:</td>
                     <td>' . $e['loading_station'] . '</td>
                   </tr>
                   <tr>
                     <td>Loading Location:</td>
                     <td>' . $e['loading_location'] . '</td>
                   </tr>
                   <tr>
                     <td>Unloading Station:</td>
                     <td>' . $e['unloading_station'] . '</td>
                   </tr>
                   <tr>
                     <td>Unloading Location:</td>
                     <td>' . $e['unloading_location'] . '</td>
                     </tr>
                   <tr>
                     <td>Remarks:</td>
                     <td>' . $e['remarks'] . '</td>
                   </tr>
                   <tr>
                     <td>Load Count:</td>
                     <td>' . $e['load_count'] . '</td>
                   </tr>
                   <tr>
                     <td>RFID code:</td>
                     <td>' . $e['RFID_code'] . '</td>
                   </tr>
                 </table>';
        }
        else
        {
          print '<div class="alert alert-warning">Car with ID ' . sts_h($raw_scan_code) . ' not found. Check spelling.</div>';
        }
      }
    ?>
    </form>
</div>
<?php sts_page_end(); ?>
