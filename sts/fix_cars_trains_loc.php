<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Fix Cars in Trains with Current Locations',
    'icon' => 'bi-geo-alt',
    'section' => 'maint',
    'links' => [['href' => 'validate_db.php', 'label' => 'Validate Database', 'icon' => 'bi-check2-square']],
    'head_extra' => <<<'HTML'
    <script>
      // this javascript function is triggered by the user changing the "All" checkbox
      function checkall()
      {
        var row_count = document.getElementById('car_table').rows.length-1;
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
    Cars in trains cannot have current locations. These cars have been found<br />
    to still have a current location in their database records.<br /><br />
    When the FIX LOCATIONS button is clicked, all selected car orders have<br />
    their current location removed.<br />
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
        for ($i=0; $i<$_GET['row_counter']; $i++)
        {
          if (isset($_GET['check' . $i]))
          {
            print '<br />Fixing ' . $_GET['car_name' . $i] . '...';
            
            // set the car's current location to 0 (zero)
            $sql = 'update cars set current_location_id = 0 where id = ' . $_GET['car_id' . $i];
//print 'SQL: ' . $sql . '<br />';
            if (!mysqli_query($dbc, $sql))
            {
              print 'Unable to update car current location<br />';
              print '</div>'; sts_page_end(); die();
            }
          }          
        }
        print '<br /><br />';
      }

      // display the list of cars in trains that still have current locations
      $sql = 'select cars.id as id,
                     cars.reporting_marks as reporting_marks,
                     cars.status as status,
                     jobs.name as job_name,
                     locations.code as location,
                     routing.station as station
                from cars, jobs, locations, routing
               where cars.handled_by_job_id = jobs.id
                 and cars.current_location_id = locations.id
                 and locations.station = routing.id
               order by cars.reporting_marks';

      $rs = mysqli_query($dbc, $sql);
      
      // if we found some orphans, ask the user to fix them
      if (mysqli_num_rows($rs) > 0)
      {
        print '<form action="fix_cars_trains_loc.php" method="get">';
        print '<input type="submit" class="btn btn-danger" id="fix_it_btn" name="fix_it_btn" value="FIX LOCATIONS" onclick="return confirm(\'Fix Locations: this changes the database. Continue?\');">&nbsp;';
    
        print 'Check/Uncheck all cars: <input type="checkbox" id="check_all" name="check_all" onchange="checkall();"><br /><br />';
        print '<table class="table table-sm table-bordered w-auto" id="car_table" name="car_table">';
        print '<th>Fix?</th>
               <th>Reporting Marks</th>';
        $row_counter = 0;
        while ($row = mysqli_fetch_array($rs))
        {
          print '<tr>
                   <td style="text-align: center;">
                     <input type="checkbox" id="check' . $row_counter . '" name="check' . $row_counter . '">
                   </td>
                   <td>' . $row['reporting_marks'] . ' (' . $row['status'] . ') is in ' . $row['job_name'] . '<br />
                           Current Station - Location: ' . $row['station'] . ' - ' . $row['location'] . '
                     <input type="hidden" id="car_id' . $row_counter . '" name="car_id' . $row_counter . '" value="' . $row['id'] . '"> .
                     <input type="hidden" id="car_name' . $row_counter . '" name="car_name' . $row_counter . '" value="' . $row['reporting_marks'] . '">
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
        print '<br />No Cars were found in trains that still have a Current Location.';
      }
    ?>
</div>
<?php sts_page_end(); ?>
