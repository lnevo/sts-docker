<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Fix Incomplete Waybills',
    'icon' => 'bi-file-earmark-text',
    'section' => 'maint',
    'links' => [['href' => 'validate_db.php', 'label' => 'Validate Database', 'icon' => 'bi-check2-square']],
    'head_extra' => <<<'HTML'
    <script>
      // this javascript function is triggered by the user changing the "All" checkbox
      function checkall()
      {
        var row_count = document.getElementById('wb_table').rows.length-1;
        if (document.getElementById('check_all').checked == true)
        {
          for (var i=0; i < row_count; i++)
          {
            var checkbox_name = "check" + i.toString();
            document.getElementById(checkbox_name).checked = true;
          }
        }
        else
        {
          for (var i=0; i < row_count; i++)
          {
            var checkbox_name = "check" + i.toString();
            document.getElementById(checkbox_name).checked = false;
          }
        }
      }
    </script>
HTML,
]);
?>
<div class="sts-card">
<div id="instructions" class="alert alert-warning">
    These waybills were not closed out when the cars attached to them were unloaded at their final destination.<br /><br />
    When the FIX WAYBILLS button is clicked, all selected waybills will be closed out and the empty cars attached<br />
    to them will be released for use.<br />
  <br />
    </div>
<?php
      // pull in the utility files
      require 'open_db.php';

      // get a database connection
      $dbc = open_db();

      // was the fix it button clicked?
      if (isset($_GET['fix_it_btn']))
      {
        // go through the incoming rows and fix the selected cars
        for ($i=0; $i<(int) sts_get('row_counter'); $i++)
        {
          print '<br />Fixing ' . sts_html(sts_get('wb' . $i)) . '...';
          
          // remove the car order
          $sql = 'delete from car_orders where waybill_number = ?';
//print 'SQL: ' . $sql . '<br />';
          if (!sts_q($dbc, $sql, array(sts_get('wb' . $i))))
          {
            print 'Unable to remove car order<br />';
            print '</div>'; sts_page_end(); die();
          }
          
        }
        print '<br /><br />';
      }

      // display the list of unfinished waybills
      $sql = 'select car_orders.waybill_number as waybill_number, 
              shipments.code as shipment, 
              routing.station as station,
              locations.code as location, 
              cars.reporting_marks as reporting_marks, 
              cars.status as status
         from car_orders, cars, shipments, locations, routing
        where cars.id = car_orders.car
          and car_orders.shipment = shipments.Id
          and cars.status = "Empty"
          and cars.current_location_id = shipments.unloading_location
          and locations.id = cars.current_location_id
          and routing.id = locations.station
     order by waybill_number';
              
      $rs = mysqli_query($dbc, $sql);
      
      // if we found some open waybills, ask the user to fix them
      if (mysqli_num_rows($rs) > 0)
      {
        print '<form action="fix_waybills.php" method="get">';
        print '<input type="submit" class="btn btn-danger" id="fix_it_btn" name="fix_it_btn" value="FIX WAYBILLS" onclick="return confirm(\'Fix Waybills: this changes the database. Continue?\');">&nbsp;';
    
        print 'Check/Uncheck all waybills: <input type="checkbox" id="check_all" name="check_all" onchange="checkall();"><br /><br />';
        print '<table class="table table-sm table-bordered w-auto" id="wb_table" name="wb_table">';
        print '<tr>
                 <th>Fix?</th>
                 <th>Waybill Number</th>
                 <th>Shipment</th>
                 <th><u>Unloading Station</u><br />Location</th>
                 <th>Reporting<br />Marks</th>
                 <th>Status</th>
               </tr>';
        $row_counter = 0;
        while ($row = mysqli_fetch_array($rs))
        {
          print '<tr>
                   <td style="text-align: center;">
                     <input type="checkbox" id="check' . $row_counter . '" name="check' . $row_counter . '">
                   </td>
                   <td>' . $row['waybill_number'] . '
                     <input type="hidden" id="wb' . $row_counter . '" name="wb' . $row_counter . '" value="' . $row['waybill_number'] . '">
                   </td>
                   <td>' . $row['shipment'] . '</td>
                   <td><u>' . $row['station'] . '</u><br />' . $row['location'] . '</td>
                   <td>' . $row['reporting_marks'] . '</td>
                   <td>' . $row['status'] . '</td>
                 </tr>';
          $row_counter++;
        }
        print '</table>';
        print '<input type="hidden" id="row_counter" name="row_counter" value="' . $row_counter . '">';
      
        print '</form>';
      }
      else
      {
        print '<br />No Incomplete Waybills found.';
      }
    ?>
</div>
<?php sts_page_end(); ?>
