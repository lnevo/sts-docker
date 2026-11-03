<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Fix Ghost Cars',
    'icon' => 'bi-ghost',
    'section' => 'maint',
    'links' => [['href' => 'validate_db.php', 'label' => 'Validate Database', 'icon' => 'bi-check2-square']],
    'head_extra' => <<<'HTML'
    <script>
      // this javascript function is triggered by the user changing the "All" checkbox
      function checkall()
      {
        var row_count = document.getElementById('car_order_table').rows.length-1;
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
    The following car orders are linked to cars that do not exist in the database.<br /><br />
    When the FIX GHOSTS button is clicked, all selected car orders will be removed.<br />
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
          if (isset($_GET['check' . $i]))
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
        }
        print '<br /><br />';
      }

      // display the list of orphans
      $sql = 'select waybill_number from car_orders where car > 0 and car not in (select id from cars) order by waybill_number';
              
      $rs = mysqli_query($dbc, $sql);
      
      // if we found some orphans, ask the user to fix them
      if (mysqli_num_rows($rs) > 0)
      {
        print '<form action="fix_ghost_cars.php" method="get">';
        print '<input type="submit" class="btn btn-danger" id="fix_it_btn" name="fix_it_btn" value="FIX GHOSTS" onclick="return confirm(\'Fix Ghosts: this changes the database. Continue?\');">&nbsp;';
    
        print 'Check/Uncheck all car orders: <input type="checkbox" id="check_all" name="check_all" onchange="checkall();"><br /><br />';
        print '<table class="table table-sm table-bordered w-auto" id="car_order_table" name="car_table">';
        print '<th>Fix?</th>
               <th>Waybill Number</th>';
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
                 </tr>';
          $row_counter++;
        }
        print '</table>';
        print '<input type="hidden" id="row_counter" name="row_counter" value="' . $row_counter . '">';
      
        print '</form>';
      }
      else
      {
        print '<br />No Ghost Cars were found.';
      }
    ?>
</div>
<?php sts_page_end(); ?>
