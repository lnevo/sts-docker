<?php
require_once 'page_chrome.php';
sts_page_start(['title' => 'Car History', 'icon' => 'bi-clock-history', 'section' => 'data', 'print' => true]);
?>
<div class="sts-card">
    <?php
      // bring in the utility files
      require 'open_db.php';

      // get a database connection
      $dbc = open_db();

      // get the print width from the settings table
      $sql = 'select setting_value from settings where setting_name = "print_width"';
      $rs = mysqli_query($dbc, $sql);
      $row = mysqli_fetch_row($rs);
      $print_width = $row[0];

      // get the railroad name from the settings table
      $sql = 'select setting_value from settings where setting_name = "railroad_name"';
      $rs = mysqli_query($dbc, $sql);
      $row = mysqli_fetch_row($rs);
      $rr_name = $row[0];
      
      // get the current information about the requested car
      $sql = 'select cars.reporting_marks as reporting_marks,
                     cars.current_location_id as current_location_id,
                     car_codes.code as car_code,
                     locations.code as location_code,
                     cars.status as status
               from cars
               left join car_codes on cars.car_code_id = car_codes.id
               left join locations on cars.current_location_id = locations.id
               where cars.id = "' . $_GET["car_id"] . '"';
//print 'SQL: ' . $sql . '<br /><br />';
      $rs = mysqli_query($dbc, $sql);
      $row = mysqli_fetch_array($rs);
      $reporting_marks = $row['reporting_marks'];
      $car_code = $row['car_code'];
      if ($row['current_location_id'] == 0)
      {
        $location_code = "In Train";
      }
      else
      {
        $location_code = $row['location_code'];
      }
      $status = $row['status'];
      
      // get the history for the requested car id
      $sql = 'select history.session_nbr as session_number,
                     history.event_date as event_date,
                     history.event as event,
                     locations.code as location
                from history
                left join locations on history.location = locations.id
                where history.car_id = "' . $_GET["car_id"] . '"
                order by history.event_date desc';
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs))
      {
        // print report header
        print '<div class="noprint">';
        print '<h3>Car History Report</h3>';
               
        print 'This report shows all car assignments, repositionings, pick-ups, and set-outs.<br /><br />';
        print 'Click <a href="db_list.php?tbl_name=cars">here</a> to return to the Car List<br /><br />';
        print '<hr />
               </div>';
        
// print 'Time Zone: ' . date_default_timezone_get() . '<br /><br />';

        // start the report table
        print '<table>
                 <thead>
                   <tr>
                     <td style="text-align: center; border: 0px;" colspan="8">
                       <h2>' . $rr_name . '</h2>
                       <h3>Car History - Newest Events First</h3>
                     </td>
                   </tr>
                   <tr>
                     <td>
                       Reporting Marks: ' . $reporting_marks . '
                     </td>
                     <td>
                       Car Type: ' . $car_code . '
                     </td>
                     <td>
                       Status: ' . $status . '
                     </td>
                     <td>
                       Current Location: ' . $location_code . '
                     </td>
                   </tr>
                 </thead>';
        
        // print the header info
        print '<tr>
                 <th>Session Number</th>
                 <th>Date/Time</th>
                 <th>Event</th>
                 <th>Location of Event</th>
               </tr>';
        // loop through each job/train
        while ($row = mysqli_fetch_array($rs))
        {
          print '<tr>
                   <td style="text-align: center;">' . $row["session_number"] . '</td>
                   <td style="text-align: center;">' . $row["event_date"] . '</td>
                   <td style="text-align: center;">' . $row["event"] . '</td>
                   <td style="text-align: center;">' . $row["location"] . '</td>
                 </tr>';
        }

        // close the table
        print '</table>';
      }
      else
      {
        // no history for this car
        print 'No history found for this car</td></tr>';
      }
    ?>
</div>
<?php sts_page_end(); ?>
