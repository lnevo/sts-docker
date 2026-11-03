<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Fix Empty Cars That May Still Be Loaded',
    'icon' => 'bi-box',
    'section' => 'maint',
    'links' => [['href' => 'validate_db.php', 'label' => 'Validate Database', 'icon' => 'bi-check2-square']],
    'head_extra' => <<<'HTML'
HTML,
]);
?>
<div class="sts-card">
<div id="instructions" class="alert alert-warning">
    Cars having a status of Empty should not be shown to have a Commodity<br />
    assigned. Either their status should be changed to Loaded or their waybill<br />
    should be cancelled.<br /><br />
    When the FIX STATUS button is clicked, the desired action will be taken.<br />
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
          if (isset($_GET['load_or_cancel' . $i]))
          {
            if (sts_get('load_or_cancel' . $i) == 'load')
            {
              print '<br />Fixing ' . sts_html(sts_get('car_name' . $i)) . '... Setting status to Loaded...';
              // set the car's current status to loaded
              $sql = 'update cars set status = "Loaded" where id = ?';
//print 'SQL: ' . $sql . '<br />';
              if (!sts_q($dbc, $sql, array(sts_id(sts_get('car_id' . $i)))))
              {
                print 'Unable to update car current location<br />';
                print '</div>'; sts_page_end(); die();
              }
            }
            else
            {
              print '<br />Fixing ' . sts_html(sts_get('car_name' . $i)) . '... Cancelling waybill ' . sts_html(sts_get('waybill_number' . $i)) . '...';
              // Cancel the waybill associated with this car
              $sql = 'delete from car_orders where waybill_number = ?';
//print 'SQL: ' . $sql . '<br />';
              if (!sts_q($dbc, $sql, array(sts_get('waybill_number' . $i))))
              {
                print 'Unable to update car current location<br />';
                print '</div>'; sts_page_end(); die();
              }
            }
          }          
        }
        print '<br /><br />';
      }

      // display the list of cars in trains that still have current locations
      $sql = 'select cars.id as id,
                     cars.reporting_marks as reporting_marks,
                     cars.status as status,
                     car_orders.waybill_number,
                     commodities.code as commodity
                from cars, commodities, car_orders, shipments
               where cars.status = "Empty"
                 and cars.id = car_orders.car
                 and car_orders.shipment = shipments.id
                 and shipments.consignment = commodities.id
               order by cars.reporting_marks';

      $rs = mysqli_query($dbc, $sql);
      
      // if we found some empty cars that have loades, ask the user what to document
      if (mysqli_num_rows($rs) > 0)
      {
        print '<form action="fix_empty_commodity.php" method="get">';
        print '<input type="submit" class="btn btn-danger" id="fix_it_btn" name="fix_it_btn" value="FIX STATUS" onclick="return confirm(\'Fix Status: this changes the database. Continue?\');">&nbsp;<input type="reset" class="btn btn-secondary" value="RESET"><br /><br />';
    
        print '<table class="table table-sm table-bordered w-auto" id="car_table" name="car_table">';
        print '<thead>';
        print '<tr>';
        print '<th>Change Status<br />To Loaded?</th>
               <th>Cancel<br />Waybill?</th>
               <th>Reporting Marks</th>';
        print '</tr>';
        print '</thead>';
        $row_counter = 0;
        while ($row = mysqli_fetch_array($rs))
        {
          print '<tr>
                   <td style="text-align: center;">
                     <input type="radio" id="load_or_cancel' . $row_counter . '" name="load_or_cancel' . $row_counter . '" value="load">
                   </td>
                   <td style="text-align: center;">
                     <input type="radio" id="load_or_cancel' . $row_counter . '" name="load_or_cancel' . $row_counter . '" value="cancel">
                   </td>
                   <td>' . $row['reporting_marks'] . ' (' . $row['status'] . ') is assigned to Waybill ' . $row['waybill_number'] . ' and may be loaded with ' . $row['commodity'] . '
                     <input type="hidden" id="car_id' . $row_counter . '" name="car_id' . $row_counter . '" value="' . $row['id'] . '"> .
                     <input type="hidden" id="car_name' . $row_counter . '" name="car_name' . $row_counter . '" value="' . $row['reporting_marks'] . '">
                     <input type="hidden" id="waybill_number' . $row_counter . '" name="waybill_number' . $row_counter . '" value="' . $row['waybill_number'] . '">
                   </td>
                 </tr>';
          $row_counter++;
        }
        print '</table>';
        print '<input type="hidden" id="row_counter" name="row_counter" value="' . $row_counter . '">';
      
        print '</form>';
      }
      else
      {
        print '<br />No Empty Cars were found that still have commodities assigned to them.';
      }
    ?>
</div>
<?php sts_page_end(); ?>
