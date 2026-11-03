<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Rollingstock Photo Album',
    'icon' => 'bi-images',
    'section' => 'data',
    'print' => true,
    'head_extra' => '<style>div.scrollable {width: 100%; height: 100%; margin: 0; padding: 0; overflow: auto;}</style>',
]);
?>
<div class="sts-card">
    <?php
      // bring in the utility files
      require 'open_db.php';
      
      // get a database connection
      $dbc = open_db();

      print '<a name="#TOP">';


      // build a shortcut list of all letters in the alphabet where there's at least one car on the system that has reporting marks
      // starting with those letters
      $alphabet = range('A', 'Z');
      $links = range('A', 'Z');
      $sql = 'select reporting_marks from cars order by reporting_marks';
      $rs = mysqli_query($dbc, $sql);
      while ($row = mysqli_fetch_array($rs))
      {
        if($index = array_search(substr($row['reporting_marks'], 0, 1), $alphabet))
        {
          $links[$index] = '<a href="#' . $alphabet[$index] . '">' . $alphabet[$index] . '</a>';
        }
      }

      print '<table>
             <thead>
             </thead>';
      print '<tr>';
      for ($i=0; $i<26; $i++)
      {
        print '<td>' . $links[$i] . '</td>';
      }
      print '</tr>';
      print '</table><br />';

      // generate a list of all cars sorted by reporting marks
      $sql = 'select car_codes.code as car_code,
                     routing.station as current_station,
                     locations.code as current_location,
                     cars.id as car_id,
                     cars.reporting_marks as reporting_marks,
                     cars.status as status,
                     cars.remarks as remarks
                from cars, car_codes, locations, routing
               where cars.car_code_id = car_codes.id
                 and locations.id = cars.current_location_id
                 and routing.id = locations.station
               UNION
               select car_codes.code as car_code,
                     "In Train" as current_station,
                     jobs.name as current_location,
                     cars.id as car_id,
                     cars.reporting_marks as reporting_marks,
                     cars.status as status,
                     cars.remarks as remarks
                from cars, car_codes, jobs
               where cars.car_code_id = car_codes.id
                 and cars.handled_by_job_id = jobs.id
               order by reporting_marks';
// print 'SQL: ' . $sql . '<br /><br />';
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) > 0)
      {
        // build a table for the report
        print '<table style="font: normal 15px Verdana, Arial, sans-serif; white-space: nowrap;">
                 <thead>
                   <tr>
                     <th>Index</th><th>Photo</th><th>Properties</th>
                   </tr>
                 </thead>';

        $prev_row = '';
        $first_row = true;
        while ($row = mysqli_fetch_array($rs))
        {
          // if this is the first time through, generate a blank like with the first letter of the first car'sans-serif
          // reporting marks as the index.
          if($first_row)
          {
            print '<tr>
                     <td>' . 
                       substr($row['reporting_marks'], 0, 1) . '<a name="' . substr($row['reporting_marks'], 0, 1) . '"></td><td colspan="2"></td></tr>';
          }
          
          // if the first letter of this car's reporting marks for this row is different than the
          // previous row (and it's not the first row) generate a blank row to separate the car codes
          if ((substr($row['reporting_marks'], 0, 1) != $prev_row) && (!$first_row))
          {
            print '<tr>
                     <td>' . 
                       substr($row['reporting_marks'], 0, 1) . '<a name="' . substr($row['reporting_marks'], 0, 1) . '">
                       <a href="#TOP">TOP</a></td><td colspan="2">
                     </td>
                   </tr>';
          }
          $prev_row = substr($row['reporting_marks'], 0, 1);
          $first_row = false;

          // check to see if the ownership table exists and if it does, ask for this car's ownership
          $sql2 = 'show tables like "ownership"';
          $rs2 = mysqli_query($dbc, $sql2);
          if (mysqli_num_rows($rs2) > 0)
          {
            // ownership table exists, see if this car has an ownership
            $sql2 = 'select owners.name from owners, ownership where ownership.car_id = "' . $row['car_id'] . '" and ownership.owner_id = owners.id';
            $rs2 = mysqli_query($dbc, $sql2);
            if (mysqli_num_rows($rs2) > 0)
            {
              $row2 = mysqli_fetch_array($rs2);
              $owner = $row2['name'];
            }
            else
            {
              $owner = 'None assigned';
            }
          }

          // generate the table row
          print '<tr>';
          print '<td></td>';
          print '<td><div class="scrollable">';
          
          // display image 1
          if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['car_id'] . '.jpg'))
          {
            $filemtime = filemtime('./ImageStore/DB_Images/RollingStock/' . $row['car_id'] . '.jpg');
            print '<img src="./ImageStore/DB_Images/RollingStock/' . $row['car_id'] . '.jpg?' . $filemtime . '" style="width:640px;"><br />';
          }
          else
          {
            print 'Image 1 not available<br />';
          }

          // display image 2
          if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['car_id'] . 'b.jpg'))
          {
            $filemtime = filemtime('./ImageStore/DB_Images/RollingStock/' . $row['car_id'] . 'b.jpg');
            print '<img src="./ImageStore/DB_Images/RollingStock/' . $row['car_id'] . 'b.jpg?' . $filemtime . '" style="width:640px;">';
          }
          else
          {
            print 'Image 2 not available';
          }

          print '</div>';
          print '</td>';

          print '<td>Reporting Marks: ' . $row['reporting_marks'] . '<br />
                     Car Code: ' . $row['car_code'] . '<br />';
          if ($row['current_station'] == 'In Train')
          {
            print 'Currently being handled by ' . $row['current_location'] . '<br />';
          }
          else
          {
            print 'Current Station: ' . $row['current_station'] . '<br />
                   Current Location: ' . $row['current_location'] . '<br />';
          }
          print 'Status: ' . $row['status'] . '<br />
                 Remarks: ' . $row['remarks'] . '<br />
                 Owner: ' . $owner . '</td>';
          print '</tr>';
        }
        print '</table>';
      }
      else
      {
        print "No cars found on the system.<br />";
      }
    ?>
</div>
<?php sts_page_end(); ?>
