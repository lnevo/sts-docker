<?php
  // this routine updates car positions in the cars table and then returns the refreshed list to the calling HttpRequest

  // get a database connection
  require 'open_db.php';
  require_once 'html_helpers.php';
  $dbc = open_db();

  // pull in the style colors function
  require 'set_colors.php';

  // get the number of incoming cars
  $car_count = min(sts_id(isset($_REQUEST['car_count']) ? $_REQUEST['car_count'] : ''), 2000);

  // start the "where" clause
  $where_clause = "";
  $where_params = array();
  $first_parm = true;

  // loop through the incoming parameters and update each car's position
  for ($i=1; $i<=$car_count; $i++)
  {
    // construct the parameter names
    $car_name = 'car' . $i;
    $pos_name = 'pos' . $i;

    // use integer ids/positions from request
    $car_id = sts_id(sts_get($car_name));
    $car_pos = (int) sts_get($pos_name);

    // an invalid id is skipped entirely
    if ($car_id < 1)
    {
      continue;
    }

    // build and update query
    if (sts_q($dbc, 'update cars set position = ? where id = ?', array($car_pos, $car_id)) === false)
    {
      print 'Update Error: ' . sts_h(sts_err($dbc)) . '<br /><br />';
    }

    // add this car to a string that will become the "where" clause in the next query
    if (! $first_parm)
    {
      $where_clause = $where_clause . ' or ';
    }
    $where_clause = $where_clause . 'cars.id = ?';
    $where_params[] = $car_id;
    $first_parm = false;
  }

  if ($first_parm)
  {
    print 'No cars found.';
    exit;
  }

  // build a query to pull in the necessary info for each car in the parameter list
  $sql = 'select cars.id as id,
                 cars.position as position,
                 cars.reporting_marks as reporting_marks,
                 cars.status as status,
                 cars.handled_by_job_id as handled_by_job_id,
                 cars.current_location_id as current_location_id,
                 car_codes.code as car_code,
                 commodities.code as consignment,
                 loc01.code as current_location,
                 loc02.code as loading_location,
                 loc03.code as unloading_location,
                 sta01.station as current_station,
                 sta02.station as loading_station,
                 sta03.station as unloading_station,
                 car_orders.waybill_number as waybill_number,
                 car_orders.shipment as shipment_id
            from cars
            left join car_orders on car_orders.car = cars.id
            left join shipments on shipments.id = car_orders.shipment
            left join locations loc01 on loc01.id = cars.current_location_id
            left join locations loc02 on loc02.id = shipments.loading_location
            left join locations loc03 on loc03.id = shipments.unloading_location
            left join routing sta01 on sta01.id = loc01.station
            left join routing sta02 on sta02.id = loc02.station
            left join routing sta03 on sta03.id = loc03.station
            left join commodities on commodities.id = shipments.consignment
            left join car_codes on car_codes.id = cars.car_code_id
           where ' . $where_clause . ' order by position';
// print "SQL: " . $sql . "<br /><br />";

  $rs = sts_q($dbc, $sql, $where_params);
  if (!($rs instanceof mysqli_result))
  {
    print 'Query Error: ' . sts_h(sts_err($dbc));
    exit;
  }

  // build a table (less the <table> and </table> tags) and return it as a string
  $row_count = 0;
  if (mysqli_num_rows($rs) > 0)
  {
    $data_table = '<div class="table-responsive"><table id="car_table" class="table table-sm table-bordered table-hover">';
    $data_table .= '<thead><tr style="position: sticky; top: 0; background-color: #F5F5F5">
                     <th>Reorder</th>
                     <th>Position</th>
                     <th>Reporting Marks</th>
                     <th>Car Code</th>
                     <th>Status</th>
                     <th>Consignment</th>
                     <th>Current Station / Location</th>
                     <th>Loading Station / Location</th>
                     <th>Unloading Station / Location</th>
                   </tr></thead>';
    $data_table .= '<tbody id="car_table_body">';
    $js_flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
    while ($row = mysqli_fetch_array($rs))
    {
      // generate the table rows
      $data_table .= '<tr>';

      // column 1 - drag handle for reordering rows
      $data_table .= '<td class="text-center" style="vertical-align: middle;"><span class="drag-handle" title="Drag to reorder">&#9776;</span></td>';

      // column 2 - position at this location
      $data_table .= '<td style="text-align: center; vertical-align: middle;">' . sts_h($row['position']) . '</td>';

      // column 3 - reporting marks
      if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['id'] . '.jpg'))
      {
        $parm_string = json_encode((string) $row['id'], $js_flags) . ', ' . json_encode((string) $row['reporting_marks'], $js_flags);
      }
      else
      {
        $parm_string = '"", ' . json_encode((string) $row['reporting_marks'], $js_flags);
      }
      $data_table .= '<td onclick="show_image(' . sts_h($parm_string) . ');">' . sts_h($row['reporting_marks'])
                  . '<input name="car' . $row_count . '" value="' . sts_h($row['id']) . '" type="hidden"></td>';

      // column 4 - car code
      $data_table .= '<td>' . sts_h($row['car_code']) . '</td>';

      // column 5 - status
      $data_table .= '<td><span class="status-' . sts_h(strtolower($row['status'])) . '">' . sts_h($row['status']) . '</span></td>';

      // column 6 - consignment -  if this is a non-revenue move, display "Non-Revenue", otherwise display the consignment
      if (substr($row['waybill_number'], 4, 1) == 'E')
      {
        $data_table .= '<td>Non-Revenue</td>';
      }
      else
      {
        $data_table .= '<td>' . sts_h($row['consignment']) . '</td>';
      }

      // column 7 - current location
      if ($row['current_location_id'] > 0)
      {
        $data_table .= '<td>' . sts_h($row['current_station']) . '<br />' . sts_h($row['current_location']) . '</td>';
      }
      else
      {
        $data_table .= '<td>In Train</td>';
      }

      // column 8 - loading location
      if (substr($row['waybill_number'], 4, 1) == 'E')
      {
        $data_table .= '<td>N/A</td>';
      }
      else
      {
        // if this car is ordered, bold the the loading location
        if ($row['status'] == 'Ordered')
        {
          $data_table .= '<td style="' . sts_h(set_colors($dbc, $row['loading_location'])) . '"><b>' . sts_h($row['loading_station']) . '<br />' . sts_h($row['loading_location']) . '</b></td>';
        }
        else
        {
          $data_table .= '<td>' . sts_h($row['loading_station']) . '<br />' . sts_h($row['loading_location']) . '</td>';
        }
      }

      // column 9 - unloading location
      if (substr($row['waybill_number'], 4, 1) == 'E')
      {
        // run a quick query to find this car's destination since it isn't linked to a shipment
        // the destination is stored in the car order's shipment field
        $sql2 = 'select locations.code, routing.station
                   from locations, routing
                  where locations.id = ? and locations.station = routing.id';
        $rs2 = sts_q($dbc, $sql2, array($row['shipment_id']));
        $row2 = ($rs2 instanceof mysqli_result) ? mysqli_fetch_array($rs2) : null;
        if (!$row2) { $row2 = array('code' => '', 'station' => ''); }
// print 'SQL: ' . $sql2 . '<br />code: ' . $row2['code'] . '<br />';
        $data_table .= '<td style="' . sts_h(set_colors($dbc, $row2['code'])) . '"><b>' . sts_h($row2['station']) . '<br />' . sts_h($row2['code']) . '</b></td>';
      }
      else
      {
        // if this car is loaded, bold the the final destination
        if ($row['status'] == 'Loaded')
        {
          $data_table .= '<td style="' . sts_h(set_colors($dbc, $row['unloading_location'])) . '"><b>' . sts_h($row['unloading_station']) . '<br />' . sts_h($row['unloading_location']) . '</b></td>';
        }
        else
        {
          $data_table .= '<td>' . sts_h($row['unloading_station']) . '<br />' . sts_h($row['unloading_location']) . '</td>';
        }
      }

      $data_table .= '</tr>';
      $row_count++;
    }
    $data_table .= '</tbody></table></div>';
    // add a hidden field to the end of the table containing the number of rows
    $data_table .= '<input name="row_count" value="' . $row_count . '" type="hidden">';
  }
  else
  {
    $data_table = 'No cars found.';
  }
  print $data_table;

?>
