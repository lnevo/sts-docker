<?php
require_once __DIR__ . '/maint_lock.php'; sts_maint_require('admin');
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Fix Orphaned Cars',
    'icon' => 'bi-link-45deg',
    'section' => 'maint',
    'links' => [['href' => 'validate_db.php', 'label' => 'Validate Database', 'icon' => 'bi-check2-square']],
    'head_extra' => <<<'HTML'
    <script src="/sts/js/maint.js?v=1"></script>
HTML,
]);
?>
<div class="sts-card">
<div id="instructions" class="alert alert-warning">
    The following cars do not have valid unloading locations, even though they are attached to car orders<br />
    or they have a status code other than "Ordered."<br /><br />
    When the FIX CARS button is clicked, all selected cars will be set to "Empty" and the car order and <br />
  the waybill to which they are attached will be cancelled and removed.<br />
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
          print '<br />Fixing ' . sts_html(sts_get('rm' . $i)) . '...';
          
          // set the orphan car's status to empty
          $sql = 'update cars set status = "Empty" where id = ?';
//print 'SQL: ' . $sql . '<br />';
          if (!sts_q($dbc, $sql, array(sts_id(sts_get('car' . $i)))))
          {
            print 'Unable to update cars table<br />';
            print '</div>'; sts_page_end(); die();
          }
         
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

      // display the list of orphans
      $sql = 'select distinct cars.id,
                     cars.reporting_marks,
                     cars.status,
                     car_orders.waybill_number, 
                     car_orders.shipment
                from cars, car_orders
               where cars.id = car_orders.car
                 and car_orders.waybill_number like "___-E__"
                 and (car_orders.shipment not in (select locations.id from locations)
                      or cars.status != "Ordered")
               order by cars.reporting_marks';
              
      $rs = mysqli_query($dbc, $sql);
      
      // if we found some orphans, ask the user to fix them
      if (mysqli_num_rows($rs) > 0)
      {
        print '<form action="fix_orphans.php" method="get">';
        print '<input type="submit" class="btn btn-danger" id="fix_it_btn" name="fix_it_btn" value="FIX CARS" data-sts-confirm="Fix Cars: this changes the database. Continue?">&nbsp;';
    
        print 'Check/Uncheck all cars: <input type="checkbox" id="check_all" name="check_all" data-sts-change="maintCheckAll"><br /><br />';
        print '<table class="table table-sm table-bordered w-auto" id="car_table" name="car_table">';
        print '<th>Fix?</th>
               <th>Reporting Marks</th>
               <th>Waybill Number</th>
               <th>Problem</th>';
        $row_counter = 0;
        while ($row = mysqli_fetch_array($rs))
        {
          print '<tr>
                   <td style="text-align: center;">
                     <input type="checkbox" id="check' . $row_counter . '" name="check' . $row_counter . '">
                   </td>
                   <td>' . $row['reporting_marks'] . '
                     <input type="hidden" id="car' . $row_counter . '" name="car' . $row_counter . '" value="' . $row['id'] . '">
                     <input type="hidden" id="rm' . $row_counter . '" name="rm' . $row_counter . '" value="' . $row['reporting_marks'] . '">
                   </td>
                   <td>' . $row['waybill_number'] . '
                     <input type="hidden" id="wb' . $row_counter . '" name="wb' . $row_counter . '" value="' . $row['waybill_number'] . '">
                   </td>
                   <td>';
          if ($row['status'] != 'Ordered')
          {
            print 'Invalid status of ' . $row['status'];
          }
          else
          {
            print 'Missing Unloading Location';
          }            
          print '  </td>
                 </tr>';
          $row_counter++;
        }
        print '</table>';
        print '<input type="hidden" id="row_counter" name="row_counter" value="' . $row_counter . '">';
      
        print '</form>';
      }
      else
      {
        print '<br />No orphaned cars found.';
      }
    ?>
</div>
<?php sts_page_end(); ?>
