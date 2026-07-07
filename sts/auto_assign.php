<html>
  <head>
    <title>STS - Auto-Assign Cars to Jobs/Trains</title>
    <style>
      body {font: normal 20px Verdana, Arial, sans-serif;}
      table {border-collapse: collapse;}
      tr {vertical-align: top}
      th {border: 1px solid black; padding: 10px}
      td {border: 1px solid black; padding: 10px}
    </style>
  </head>';
  <body>';
    <p>
      <img src="ImageStore/GUI/Menu/operations.jpg" width="716" height="145" border="0" usemap="#Map2">
      <map name="Map2">
      <area shape="rect" coords="568,5,712,46" href="index.html">
      <area shape="rect" coords="570,97,710,138" href="index-t.html">
      <area shape="rect" coords="568,52,717,93" href="operations.html">
    </map>
    </p>
  <h2>Simulation Operations</h2>
  
  <script>
    function toggle_all_checkboxes(source)
    {
      checkboxes = document.getElementsByClassName('pu_car');
      for(var i=0, n=checkboxes.length;i<n;i++)
      {
        checkboxes[i].checked = source.checked;
      }
    }
  </script>
<?php
  // bring in the utility files
  require 'open_db.php';

  function append_auto_assign_pickup_rows($dbc, $row2, $rs3, &$pickup_list, &$pickup_list_counter)
  {
    while ($row3 = mysqli_fetch_array($rs3))
    {
      $pickup_list[$pickup_list_counter] = $row2['station'];
      $pickup_list[$pickup_list_counter] .= ', ' . $row3['reporting_marks'];
      $pickup_list[$pickup_list_counter] .= ', ' . $row3['car_code'];
      $pickup_list[$pickup_list_counter] .= ', ' . $row3['status'];
      $pickup_list[$pickup_list_counter] .= ', ' . $row3['waybill_number'];
      if (($row3['status'] == 'Ordered') && (strpos($row3['waybill_number'], 'E') === false))
      {
        $pickup_list[$pickup_list_counter] .= ', ' . $row3['commodity'];
        $pickup_list[$pickup_list_counter] .= ', ' . $row3['loading_station'];
        $pickup_list[$pickup_list_counter] .= ', ' . $row3['loading_location'];
      }
      else if ($row3['status'] == 'Loaded')
      {
        $pickup_list[$pickup_list_counter] .= ', ' . $row3['commodity'];
        $pickup_list[$pickup_list_counter] .= ', ' . $row3['unloading_station'];
        $pickup_list[$pickup_list_counter] .= ', ' . $row3['unloading_location'];
      }
      else if (($row3['status'] == 'Ordered') && (strpos($row3['waybill_number'], 'E') !== false))
      {
        $sql6 = 'select routing.station, locations.code from routing, locations
                 where locations.id = ' . $row3['shipment'] . ' and routing.id = locations.station';
        $rs6 = mysqli_query($dbc, $sql6);
        $row6 = mysqli_fetch_array($rs6);
        $pickup_list[$pickup_list_counter] .= ', REPOSITIONING EMPTY';
        $pickup_list[$pickup_list_counter] .= ', ' . $row6['station'];
        $pickup_list[$pickup_list_counter] .= ', ' . $row6['code'];
      }
      $pickup_list[$pickup_list_counter] .= ', ' . $row3['id'];
      $pickup_list_counter++;
    }
  }

  // get a database connection
  $dbc = open_db();

//  print 'Incoming Job: ' . $_GET['job'] . '<br /><br />';
  $sql = 'select name from jobs where id = "' . $_GET['job'] . '"';
  $rs = mysqli_query($dbc, $sql);
  $row  = mysqli_fetch_array($rs);
  $job_name = $row['name'];

  print '<a href="build_switchlists.php">Return to Assign Cars</a><br /><br />';
  
  // decide if this is the second time this page was called (after the assign button was clicked)
  if (isset($_GET['assign_cars']))
  {
    // assign the selected cars to the designated job/train
    $num_cars_assigned = 0;
    for ($i=0; $i<$_GET['pickup_list_count']; $i++)
    {
      // only assign those cars with checked checkboxes
      if (isset($_GET['pu_car' . $i]))
      {
        $sql = 'select reporting_marks from cars where id = ' . $_GET["car_id" . $i];
        $rs = mysqli_query($dbc, $sql);
        $row = mysqli_fetch_array($rs);
        print 'Assigning ' . $row['reporting_marks'] . '...<br />';
        
        // assign this car by updating the car's "handled_by" field
        $sql = 'update cars set handled_by_job_id = "' . $_GET['job'];
        $sql = $sql . '" where id = "' . $_GET["car_id" . $i] . '"';

        if(!mysqli_query($dbc, $sql))
        {
          print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql;
        }
        
        // bump up the number of cars assigned counter
        $num_cars_assigned++;
      }
    }
    if ($num_cars_assigned > 0)
    {
      print '<br />' . $num_cars_assigned . ' car(s) assigned to Job/Train ' . $job_name;
    }
    else
    {
      print '<br />No cars were selected to be assigned to Job/Train ' . $job_name;
    }
    print '<br /><br /><a href="pick_up.php"><button type="button">Go to Pick Up Cars</button></a>';
  }
  else
  {  
    // get this job's pickup criteria (if any) and display iterator_apply
    print '<b>Auto-Assign Cars to Job: ' . $job_name . '</b><br /><br />';

    $sql = 'select pu_criteria.id as id,
                   pu_criteria.job_id as job_id,
                   jobs.name as job_name,
                   pu_criteria.step_nbr as step_nbr,
                   pu_criteria.car_status as car_status,
                   commodities.id as commodity_id,
                   commodities.code as commodity,
                   car_codes.id as car_code_id,
                   car_codes.code as car_code,
                   routing.id as dest_station_id,
                   routing.station as dest_station
              from pu_criteria
              left join jobs on pu_criteria.job_id = jobs.id
              left join commodities on pu_criteria.commodity_id = commodities.id
              left join car_codes on pu_criteria.car_code_id = car_codes.id
              left join routing on pu_criteria.dest_station_id = routing.id
              where pu_criteria.job_id = "' . $job_name . '"';

  //print 'SQL: ' . $sql . '<br /><br />';
    // build an array of cars that meet the pickup requirements
    $pickup_list = [];
    $pickup_list_counter = 0;

    $rs = mysqli_query($dbc, $sql);
    if (mysqli_num_rows($rs) > 0)
    {
      print 'Pickup Criteria<br /><br />';
      print '<table style="font: normal 15px Verdana, Arial, sans-serif;">';
      print '<tr><th>Step Nbr</th><th>Pickup<br />Location</th><th>Car Status</th>
             <th>Commodity</th><th>Car Code</th><th>Destination<br />Station</th></tr>';
      while ($row = mysqli_fetch_array($rs))
      {
        // get the station name where this step takes place
        $sql2 = 'select routing.station as station, routing.id as routing_id from routing, `' . $job_name . '` ' .
                'where routing.id = `' . $job_name . '`.station and `' . $job_name . '`.step_number = "' . $row['step_nbr'] . '"';
  //print 'SQL: ' . $sql . '<br /><br />';
        $rs2 = mysqli_query($dbc, $sql2);
        $row2 = mysqli_fetch_array($rs2);

        // convert empty criteria to "any"
        $car_status = (strlen(trim($row['car_status'])) > 0 ? $row['car_status'] : 'ANY');
        $commodity = (strlen(trim($row['commodity'])) > 0 ? $row['commodity'] : 'ANY');
        $car_code = (strlen(trim($row['car_code'])) > 0 ? $row['car_code'] : 'ANY');
        $dest_station = (strlen(trim($row['dest_station'])) > 0 ? $row['dest_station'] : 'ANY');
        
        // create a table row for each step in the job description
        print '<tr>';
        print '<td style="text-align: center;">' . $row['step_nbr'] . '</td>';
        print '<td>' . $row2['station'] . '</td>';
        print '<td>' . $car_status . '</td>';
        print '<td>' . $commodity . '</td>';
        print '<td>' . $car_code . '</td>';
        print '<td>' . $dest_station . '</td>';
        print '</tr>';
        
        // build an array of cars that meet the pickup criteria for this step
        // first get what is needed to build the query
        $car_status_search = ($car_status == 'ANY' ? '%' : $row['car_status']);
        $commodity_search_id = ($commodity == 'ANY' ? '%' : $row['commodity_id']);
        $car_code_search_id = ($car_code == 'ANY' ? '%' : $row['car_code_id']);
        $dest_search_id = ($dest_station == 'ANY' ? '%' : $row['dest_station_id']);
        
        // get the ids of the locations that belong to the pickup station
        $current_location_string = '';
        $sql4 = 'select id from locations where station = "' . $row2['routing_id'] . '"';
        $rs4 = mysqli_query($dbc, $sql4);
        while ($row4 = mysqli_fetch_array($rs4))
        {
          $current_location_string .= $row4['id'] . ', ';
        }
        $current_location_string = rtrim($current_location_string, ', ');
        
        // get the ids of the locations that belong to dest_search_id
        $dest_search_string = '';
        $sql5 = 'select id from locations where station = "' . $dest_search_id . '"';
        $rs5 = mysqli_query($dbc, $sql5);
        while ($row5 = mysqli_fetch_array($rs5))
        {
          $dest_search_string .= $row5['id'] . ', ';
        }
        $dest_search_string = rtrim($dest_search_string, ', ');
  //print 'dest_search_string: ' . $dest_search_string . '<br /><br />';
        if (strlen($current_location_string) > 0 && strlen($dest_search_string) > 0)
        {
          // Revenue moves: car_orders.shipment links to shipments.id
          $sql3_revenue = 'select cars.id,
                                  cars.reporting_marks as reporting_marks,
                                  car_codes.code as car_code,
                                  cars.status as status,
                                  car_orders.waybill_number as waybill_number,
                                  car_orders.shipment as shipment,
                                  commodities.code as commodity,
                                  r1.station as unloading_station,
                                  r2.station as loading_station,
                                  loc1.code as unloading_location,
                                  loc2.code as loading_location
                             from cars
                             inner join car_orders on cars.id = car_orders.car
                             inner join shipments on shipments.id = car_orders.shipment
                             inner join commodities on commodities.id = shipments.consignment
                             inner join car_codes on cars.car_code_id = car_codes.Id
                             inner join locations loc1 on shipments.unloading_location = loc1.id
                             inner join locations loc2 on shipments.loading_location = loc2.id
                             inner join routing r1 on loc1.station = r1.id
                             inner join routing r2 on loc2.station = r2.id
                            where cars.current_location_id in (' . $current_location_string . ')
                              and ((cars.status = "Ordered"
                                    and car_orders.waybill_number not like "%E%"
                                    and shipments.loading_location in (' . $dest_search_string . '))
                                   or
                                   (cars.status = "Loaded"
                                    and shipments.unloading_location in (' . $dest_search_string . ')))';
          append_auto_assign_pickup_rows($dbc, $row2, mysqli_query($dbc, $sql3_revenue), $pickup_list, $pickup_list_counter);

          // Non-revenue repositions: car_orders.shipment holds destination location id
          $sql3_reposition = 'select cars.id,
                                     cars.reporting_marks as reporting_marks,
                                     car_codes.code as car_code,
                                     cars.status as status,
                                     car_orders.waybill_number as waybill_number,
                                     car_orders.shipment as shipment,
                                     "" as commodity,
                                     "" as unloading_station,
                                     "" as loading_station,
                                     "" as unloading_location,
                                     "" as loading_location
                                from cars
                                inner join car_orders on cars.id = car_orders.car
                                inner join car_codes on cars.car_code_id = car_codes.Id
                               where cars.current_location_id in (' . $current_location_string . ')
                                 and cars.status = "Ordered"
                                 and car_orders.waybill_number like "%E%"
                                 and car_orders.shipment in (' . $dest_search_string . ')';
          append_auto_assign_pickup_rows($dbc, $row2, mysqli_query($dbc, $sql3_reposition), $pickup_list, $pickup_list_counter);
        }
      }
      print '</table>';
      
      // start a form here so the user can assign the selected cars to the designated job/train
      print '<form action="auto_assign.php" method="get">';
      
      // remember the job that called this form for the next time around
      print '<input type="hidden" name="job" id="job" value="' . $_GET['job'] . '">';
      
      // remember how many cars were in the pickup list
      print '<input type="hidden" name="pickup_list_count" value="' . $pickup_list_counter . '">';
      
      // display the pickup list
      print '<br /><b>Pickup List</b><br /><br />';
      print $pickup_list_counter . ' cars on the Pickup List<br /><br />';
      print '<input type="submit" name="assign_cars" id="assign_cars" value="ASSIGN CARS">&nbsp;&nbsp;';
      print '<input type="checkbox" name="check_all" id="check_all" checked onClick="toggle_all_checkboxes(this)">
             Click to check or uncheck all cars in the list.<br /><br />';
      print '<table>';
      print '<tr>
               <th>Pick<br />Up?</th> 
               <th>Pickup<br />Location</th>
               <th>Reporting<br />Marks</th>
               <th>Car<br />Code</th>
               <th>Status</th>
               <th>Waybill<br />Number</th>
               <th>Contents</th>
               <th>Destination<br />Station<br />Location</th>
             </tr>';
      for ($i=0; $i<$pickup_list_counter; $i++)
      {
        $pickup_list_columns = explode(',', $pickup_list[$i]);
        print '<tr>
                 <td style="text-align: center;"><input type="checkbox" class="pu_car" name="pu_car' . $i . '" checked></td>
                 <td>' . $pickup_list_columns[0] . '</td>
                 <td>' . $pickup_list_columns[1] . '<input type="hidden" name="car_id' . $i . '" value="' . $pickup_list_columns[8] . '"></td>
                 <td style="text-align: center";>' . $pickup_list_columns[2] . '</td>
                 <td>' . $pickup_list_columns[3] . '</td>
                 <td>' . $pickup_list_columns[4] . '</td>
                 <td>' . $pickup_list_columns[5] . '</td>
                 <td>' . $pickup_list_columns[6] . '<br />' . $pickup_list_columns[7] . '</td>
               </tr>';
      }
      print '</table>';
      print '</form>';
    }
    else
    {
      print 'Job ' . $job_name . ' doesn\'t have any pickup criteria.';
    }
  }

?>
  </body>
</html>
