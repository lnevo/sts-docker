<html>
  <head>
    <title>STS - Fill Car Orders</title>
    <style>
      body {font: normal 20px Verdana, Arial, sans-serif;}
      table {border-collapse: collapse;}
      tr {vertical-align: top}
      th {border: 1px solid black; padding: 10px}
      td {border: 1px solid black; padding: 10px}
      td.number {text-align: center}
    </style>
    <?php
      // bring in the javascript function that shows rollingstock photos
      require 'show_image.php';
    ?>
  </head>
  <body style="margin-left: 50px;">
<p><img src="ImageStore/GUI/Menu/fill.jpg" width="716" height="145" border="0" usemap="#Map6">
  <map name="Map6">
    <area shape="rect" coords="570,3,712,50" href="index.html">
    <area shape="rect" coords="572,98,709,138" href="index-t.html">
    <area shape="rect" coords="568,51,713,92" href="fill_orders.php">
  </map>
</p>
<h2>Simulation Operations</h2>
<h3>Fill Car Orders</h3>
    Assign the desired car to the car order by clicking on it's radio button and then on the <b>ASSIGN</b> button.<br /><br />
    If there aren't any cars available that meet the shipment requirements, a message to that effect will be displayed.
    <br /><br />

    <form method="post" action="fill_orders.php">

    <?php
      // bring in the function files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // get a database connection
      $dbc = open_db();

      // find out which car order is to be filled
      for ($i=0; $i<$_POST['row_count']; $i++)
      {
        $button_name = 'fill' . $i;
        $wbnbr_name = 'wbnbr' . $i;

        if (isset($_POST[$button_name]))
        {
          // found it - get the car order requirements
          $sql = 'select car_orders.shipment as shipment_id,
                         shipments.code as shipment,
                         shipments.description as description,
                         shipments.consignment as consignment_id,
                         shipments.car_code as car_code_id,
                         shipments.loading_location as loading_location_id,
                         shipments.unloading_location as unloading_location_id,
                         shipments.remarks as remarks,
                         commodities.code as consignment,
                         car_codes.code as car_code,
                         sta01.station as loading_station,
                         loc01.code as loading_location,
                         sta02.station as unloading_station,
                         loc02.code as unloading_location
                    from car_orders
                    left join shipments on shipments.id = car_orders.shipment
                    left join commodities on commodities.id = shipments.consignment
                    left join car_codes on car_codes.id = shipments.car_code
                    left join locations loc01 ON loc01.id = shipments.loading_location
                    left join locations loc02 ON loc02.id = shipments.unloading_location
                    left join routing sta01 on sta01.id = loc01.station
                    left join routing sta02 on sta02.id = loc02.station
                    left join routing on routing.id = loc01.station
                    where car_orders.waybill_number = "' . $_POST[$wbnbr_name] . '"';
// print 'SQL: ' . $sql . '<br /><br />';
          $rs = mysqli_query($dbc, $sql);
          $row = mysqli_fetch_array($rs);

          if (mysqli_num_rows($rs) <= 0)
          {
            print 'Select error - SQL: ' . $sql;
          }

          $shipment_id = $row['shipment_id'];
          $shipment = $row['shipment'];                     // field 0
          $description = $row['description'];               // field 1
          $consignment = $row['consignment'];               // field 2
          $car_code = $row['car_code'];                     // field 3
          $loading_station = $row['loading_station'];
          $loading_location = $row['loading_location'];     // field 4
          $unloading_station = $row['unloading_station'];
          $unloading_location = $row['unloading_location']; // field 5
          $shipment_remarks = $row['remarks'];              // field 6

          // display the shipment information
          print '<table>
                   <tr>
                     <td><b>Shipment</b></td><td>' . $shipment . '</td>
                     <td><b>Loading Station</b></td><td>' . $loading_station . '</td></tr>
                   <tr>
                     <td><b>Description</b></td><td>' . $description . '</td>
                     <td><b>Loading Location</b></td><td>' . $loading_location . '</td></tr>
                   <tr>
                     <td><b>Consignment</b></td><td>' . $consignment . '</td>
                     <td><b>Unloading Station</b></td><td>' . $unloading_station . '</td></tr>
                   <tr>
                     <td><b>Car Code</b></td><td>' . $car_code . '</td>
                     <td><b>Unloading Location</b></td><td>' . $unloading_location . '</td></tr>
                   <tr>
                     <td><b>Shipment Remarks</b></td><td colspan="3">' . $shipment_remarks . '</td></tr>
                 </table>';

          // build a query to find any cars in a pool assigned to this shipment
          $sql = 'select cars.reporting_marks as reporting_marks,
                         car_codes.code as car_code,
                         cars.id as car_id,
                         routing.station as current_station,
                         locations.code as current_location,
                         0 as priority,
                         cars.load_count as load_count,
                         cars.remarks as remarks
                  from cars
                  left join pool on cars.id = pool.car_id
                  left join locations on locations.id = cars.current_location_id
                  left join routing on routing.id = locations.station
                  left join car_codes on car_codes.id = cars.car_code_id
                  where cars.status = "Empty"
                    and cars.id not in (select car from car_orders)
                    and car_codes.code like REPLACE("' . $car_code . '", "*", "%")
                    and pool.car_id = cars.Id
                    and pool.shipment_id = "' . $shipment_id . '"
              order by cars.load_count';
              
          $rs_pool = mysqli_query($dbc,$sql);


          // build a query to find all of the cars at the current station
          $sql = 'select cars.reporting_marks as reporting_marks,
                         car_codes.code as car_code,
                         cars.id as car_id,
                         routing.station as current_station,
                         locations.code as current_location,
                         0 as priority,
                         cars.load_count as load_count,
                         cars.remarks as remarks
                  from cars
                  left join locations on locations.id = cars.current_location_id
                  left join routing on routing.id = locations.station
                  left join car_codes on car_codes.id = cars.car_code_id
                  where cars.status = "Empty"
                    and cars.id not in (select car from car_orders)
                    and cars.id not in (select car_id from pool)
                    and car_codes.code like REPLACE("' . $car_code . '", "*", "%")
                    and cars.current_location_id in (select locations.id
                                                     from locations, routing
                                                     where locations.station = routing.id and routing.station = "' . $loading_station . '")
                  order by priority, cars.load_count';

          $rs1 = mysqli_query($dbc, $sql);
//print '<p>$sql1 = ' . $sql . '</p>';
          // build a query to find out if this shipper has prioritized empty search locations
          $sql = 'select cars.reporting_marks as reporting_marks,
                         cars.id as car_id,
                         car_codes.code as car_code,
                         routing.station as current_station,
                         locations.code as current_location,
                         empty_locations.priority as priority,
                         cars.load_count as load_count,
                         cars.remarks as remarks
                  from (cars, empty_locations, shipments)
                  left join locations on locations.id = cars.current_location_id
                  left join routing on routing.id = locations.station
                  left join car_codes on car_codes.id = cars.car_code_id
                  where cars.status = "Empty"
                    and cars.id not in (select car from car_orders)
                    and cars.id not in (select car_id from pool)
                    and car_codes.code like REPLACE("' . $car_code . '", "*", "%")
                    and cars.current_location_id = empty_locations.location
                    and empty_locations.shipment = shipments.id
                    and shipments.code = "' . $shipment . '"
                    and cars.current_location_id not in (select locations.id
                                                      from locations, routing
                                                      where locations.station = routing.id and routing.station = "' . $loading_station . '")
                  order by priority, cars.load_count';

          $rs2 = mysqli_query($dbc, $sql);
//print '<p>sql2: ' . $sql . '</p>';
          // build a query to find all remaining eligible cars on the system
          $sql = 'select distinct cars.reporting_marks as reporting_marks,
                         cars.id as car_id,
                         car_codes.code as car_code,
                         routing.station as current_station,
                         locations.code as current_location,
                         0 as priority,
                         cars.load_count as load_count,
                         cars.remarks as remarks
                  from cars
                  left join locations on locations.id = cars.current_location_id
                  left join routing on routing.id = locations.station
                  left join car_codes on car_codes.id = cars.car_code_id
                  where cars.status = "Empty"
                    and cars.id not in (select car from car_orders)
                    and cars.id not in (select car_id from pool)
                    and car_codes.code like REPLACE("' . $car_code . '", "*", "%")
                    and cars.reporting_marks not in
                    (select cars.reporting_marks
                     from cars
                     where cars.status = "Empty"
                       and car_codes.code like REPLACE("' . $car_code . '", "*", "%")
                       and cars.current_location_id in (select locations.id
                                                        from locations, routing
                                                        where locations.station = routing.id and routing.station = "' . $loading_station . '")
                                                        union
                                                        select cars.reporting_marks
                                                        from (cars, empty_locations)
                                                        where cars.status = "Empty"
                                                          and car_codes.code like REPLACE("' . $car_code . '", "*", "%")
                                                          and cars.current_location_id = empty_locations.location
                                                          and empty_locations.shipment = "' . $shipment . '"
                                                          and cars.current_location_id not in (select locations.id
                                                                                         from locations, routing
                                                                                         where locations.station = routing.id and routing.station = "' . $loading_station . '")
                    )
                  order by priority, cars.load_count';
//print "<p>sql3: " . $sql . '</p>';
          $rs3 = mysqli_query($dbc, $sql);

          // check for no cars found
          $total_cars_found = mysqli_num_rows($rs_pool) + mysqli_num_rows($rs1) + mysqli_num_rows($rs2) + mysqli_num_rows($rs3);

          if ($total_cars_found > 0)
          {
            // display number of cars found
            print '<br />' . $total_cars_found . ' eligible cars found:&nbsp;(';
            print '<span style="color:white;background-color:gray;">Pool: ' . mysqli_num_rows($rs_pool) . '</span>&nbsp;';
            print '<span style="background-color:darkgray;">At Shipper\'s station: ' . mysqli_num_rows($rs1) . '</span>&nbsp;';
            print '<span style="background-color:lightgray;">Priority Locations: ' . mysqli_num_rows($rs2) . '</span>&nbsp;';
            print 'System: ' . mysqli_num_rows($rs3) . ')<br />';

            // display the "Assign" button
            print '<br /><input type="submit" name="assign" value="ASSIGN" style="background-color: #80ff00; font-size: 24px;"><br /><br />';

            // insert a hidden field to pass the waybill number back to the fill_orders program
            print '<input type="hidden" name="wbnbr" value="' . $_POST[$wbnbr_name] . '">';

            // set the first car flag to true
            $first_car = true;

            // build the table listing the cars
            print '<table style="white-space: nowrap;">
                     <thead>';

            // headings
            print '<tr>
                     <th>Select</th>
                     <th>Reporting<br />Marks</th>
                     <th>Car Code</th>
                     <th>Current<br /><u>Station</u><br />Location</th>
                     <th>Priority</th>
                     <th>Load<br />Count</th>
                     <th>Remarks</th>
                   </tr>
                 </thead>';

            // cars in the special shipment pool
            while($row1 = mysqli_fetch_array($rs_pool))
            {
              if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row1['car_id'] . '.jpg'))
              {
                $parm_string = '\'' . $row1['car_id'] . '\', \'' . $row1['reporting_marks'] . '\'';
              }
              else
              {
                $parm_string = '\'\',\'' . $row1['reporting_marks'] . '\'';
              }
              
              print '<tr style="color:White; background-color:Gray;">
                     <td style="text-align: center">';
              if ($first_car)
              {
                print '<input name="car_id" type="radio" value="' . $row1['car_id'] . '" checked>';
                $first_car = false;
              }
              else
              {
                print '<input name="car_id" type="radio" value="' . $row1['car_id'] . '">';
              }
              
              print '</td>
                     <td onclick="show_image(' . $parm_string . ');">' . $row1['reporting_marks'] . '
                       <input name="reporting_marks" value="' . $row1['reporting_marks'] . '" type="hidden">
                     </td>
                     <td>' . $row1['car_code'] . '</td>
                     <td><u>' . $row1['current_station'] . '</u><br />' . $row1['current_location'] . '</td>
                     <td class="number">' . $row1['priority'] . '</td>
                     <td class="number">' . $row1['load_count'] . '</td>
                     <td>' . $row1['remarks'] . '</td>
                   </tr>';
            }

            // cars at the same station as the shipper
            while($row1 = mysqli_fetch_array($rs1))
            {
              if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row1['car_id'] . '.jpg'))
              {
                $parm_string = '\'' . $row1['car_id'] . '\', \'' . $row1['reporting_marks'] . '\'';
              }
              else
              {
                $parm_string = '\'\',\'' . $row1['reporting_marks'] . '\'';
              }
              
              print '<tr style="background-color:DarkGray;">
                     <td style="text-align: center">';
              if ($first_car)
              {
                print '<input name="car_id" type="radio" value="' . $row1['car_id'] . '" checked>';
                $first_car = false;
              }
              else
              {
                print '<input name="car_id" type="radio" value="' . $row1['car_id'] . '">';
              }
              
              print '</td>
                     <td onclick="show_image(' . $parm_string . ');">' . $row1['reporting_marks'] . '
                       <input name="reporting_marks" value="' . $row1['reporting_marks'] . '" type="hidden">
                     </td>
                     <td>' . $row1['car_code'] . '</td>
                     <td><u>' . $row1['current_station'] . '</u><br />' . $row1['current_location'] . '</td>
                     <td class="number">' . $row1['priority'] . '</td>
                     <td class="number">' . $row1['load_count'] . '</td>
                     <td>' . $row1['remarks'] . '</td>
                   </tr>';
            }

            // cars at prioritized locations
            while($row2 = mysqli_fetch_array($rs2))
            {
              print '<tr style="background-color:LightGray;">
                     <td style="text-align: center">';
              if ($first_car)
              {
                print '<input name="car_id" type="radio" value="' . $row2['car_id'] . '" checked>';
                $first_car = false;
              }
              else
              {
                print '<input name="car_id" type="radio" value="' . $row2['car_id'] . '">';
              }
              
              if (file_exists('/ImageStore/DB_Images/RollingStock/' . $row2['car_id'] . '.jpg'))
              {
                $parm_string = '\'' . $row2['car_id'] . '\', \'' . $row2['reporting_marks'] . '\'';
              }
              else
              {
                $parm_string = '\'\',\'' . $row2['reporting_marks'] . '\'';
              }
              print '</td>
                     <td onclick="show_image(' . $parm_string . ');">' . $row2['reporting_marks'] . '
                       <input name="reporting_marks" value="' . $row2['reporting_marks'] . '" type="hidden">
                     </td>
                     <td>' . $row2['car_code'] . '</td>
                     <td><u>' . $row2['current_station'] . '</u><br />' . $row2['current_location'] . '</td>
                     <td class="number">' . $row2['priority'] . '</td>
                     <td class="number">' . $row2['load_count'] . '</td>
                     <td>' . $row2['remarks'] . '</td>
                     </tr>';
            }

            // cars somewhere on the system
            while($row3 = mysqli_fetch_array($rs3))
            {
              print '<tr style="background-color:White;">
                     <td style="text-align: center">';
              if ($first_car)
              {
                print '<input name="car_id" type="radio" value="' . $row3['car_id'] . '" checked>';
                $first_car = false;
              }
              else
              {
                print '<input name="car_id" type="radio" value="' . $row3['car_id'] . '">';
              }
            
              if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row3['car_id'] . '.jpg'))
              {
                $parm_string = '\'' . $row3['car_id'] . '\', \'' . $row3['reporting_marks'] . '\'';
              }
              else
              {
                $parm_string = '\'\',\'' . $row3['reporting_marks'] . '\'';
              }
              print '</td>
                     <td onclick="show_image(' . $parm_string . ');">' . $row3['reporting_marks'] . '
                       <input name="reporting_marks" value="' . $row3['reporting_marks'] . '" type="hidden">
                     </td>
                     <td>' . $row3['car_code'] . '</td>
                     <td><u>' . $row3['current_station'] . '</u><br />' . $row3['current_location'] . '</td>
                     <td class="number">' . $row3['priority'] . '</td>
                     <td class="number">' . $row3['load_count'] . '</td>
                     <td>' . $row3['remarks'] . '</td>
                     </tr>';
            }
            print '</table>';
          }
          else
          {
            print '<br />No eligible cars found on the system';
          }
        }
      }
    ?>
    </form>
  
</body>
</html>
