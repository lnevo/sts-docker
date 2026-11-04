<?php
  // this routine returns a list of cars at a specified location to the calling HttpRequest

  // get a database connection
  require 'open_db.php';
  $dbc = open_db();

  // pull in the style colors function
  require 'set_colors.php';

  // get the incoming parameter
  $location_id = (string) sts_id(urldecode(isset($_REQUEST['location']) && is_scalar($_REQUEST['location']) ? $_REQUEST['location'] : ''));

  // run a quick query to get the code for this location
  $sql = 'select code from locations where id = ?';
  $rs = sts_q($dbc, $sql, array($location_id));
  $row = mysqli_fetch_array($rs);
  $location_code = $row['code'];

  // build a query to find all cars currently at the designated location
  $sql = 'select cars.id as id,
                 cars.position as position,
                 cars.reporting_marks as reporting_marks,
                 cars.status as status,
                 car_orders.waybill_number as waybill_number,
                 car_orders.shipment as shipment_id,
                 loc01.code as current_location,
                 loc02.code as loading_location,
                 loc03.code as unloading_location,
                 sta01.station as current_station,
                 sta02.station as loading_station,
                 sta03.station as unloading_station,
                 commodities.code as consignment,
                 car_codes.code as car_code
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
          where cars.current_location_id = ?
          order by position, reporting_marks';
// print 'SQL: ' . $sql . '<br /><br />';
  $rs = sts_q($dbc, $sql, array($location_id));
  if ($rs === false) {
    echo 'No cars at ' . htmlspecialchars($location_code);
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
                      <th>Loading Station / Location</th>
                      <th>Unloading Station / Location</th>
                    </tr></thead>';
    $data_table .= '<tbody id="car_table_body">';

    while ($row = mysqli_fetch_array($rs))
    {
      // generate the table rows
      $data_table .= '<tr>';

      // column 1 - drag handle for reordering rows
      $data_table .= '<td class="text-center" style="vertical-align: middle;"><span class="drag-handle" title="Drag to reorder">&#9776;</span></td>';

      // column 2 - position at this location
      $data_table .= '<td>' . sts_html($row['position']) . '</td>';

      // column 3 - reporting marks
      if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['id'] . '.jpg'))
      {
        $parm_string = ' data-car-id="' . htmlspecialchars((string)$row['id'], ENT_QUOTES) . '" data-marks="' . htmlspecialchars((string)$row['reporting_marks'], ENT_QUOTES) . '"';
      }
      else
      {
        $parm_string = ' data-car-id="" data-marks="' . htmlspecialchars((string)$row['reporting_marks'], ENT_QUOTES) . '"';
      }
      $data_table .= '<td data-sts-click="showImage"' . $parm_string . '>' . $row['reporting_marks']
                  . '<input name="car' . $row_count . '" value="' . sts_html($row['id']) . '" type="hidden"></td>';

      // column 4 - car code
      $data_table .= '<td>' . sts_html($row['car_code']) . '</td>';

      // column 5 - status
      $data_table .= '<td><span class="status-' . strtolower($row['status']) . '">' . sts_html($row['status']) . '</span></td>';

      // column 6 - consignment -  if this is a non-revenue move, display "Non-Revenue", otherwise display the consignment
      if (substr($row['waybill_number'], 4, 1) == 'E')
      {
        $data_table .= '<td>Non-Revenue</td>';
      }
      else
      {
        $data_table .= '<td>' . sts_html($row['consignment']) . '</td>';
      }

      // column 7 - loading location
      if (substr($row['waybill_number'], 4, 1) == 'E')
      {
        $data_table .= '<td>N/A</td>';
      }
      else
      {
        // if this car is ordered, bold the the loading location
        if ($row['status'] == 'Ordered')
        {
          $data_table .= '<td style="' . set_colors($dbc, $row['loading_location']) . '"><b>' . sts_html($row['loading_station']) . '<br />' . sts_html($row['loading_location']) . '</b></td>';
        }
        else if ($row['status'] == "Empty")
        {
           $data_table .= '<td></td>';
        }
        else
        {
          $data_table .= '<td>' . sts_html($row['loading_station']) . '<br />' . sts_html($row['loading_location']) . '</td>';
        }
      }

      // column 8 - unloading location
      if (substr($row['waybill_number'], 4, 1) == 'E')
      {
        // run a quick query to find this car's destination since it isn't linked to a shipment
        // the destination is stored in the car order's shipment field
        $sql2 = 'select locations.code as code, routing.station as station
                   from locations, routing
                  where locations.id = ? and locations.station = routing.id';
        $rs2 = sts_q($dbc, $sql2, array($row['shipment_id']));
        $row2 = mysqli_fetch_array($rs2);

        $data_table .= '<td style="' . set_colors($dbc, $row2['code']) . '"><b>' . sts_html($row2['station']) . '<br />' . sts_html($row2['code']) . '</b></td>';
      }
      else
      {
        // if this car is loaded, bold the the final destination
        if ($row['status'] == 'Loaded')
        {
          $data_table .= '<td style="' . set_colors($dbc, $row['unloading_location']) . '"><b>' . sts_html($row['unloading_station']) . '<br />' . sts_html($row['unloading_location']) . '</b></td>';
        }
        else if ($row['status'] == "Empty")
        {
          $data_table .= '<td></td>';
        }
        else
        {
          $data_table .= '<td>' . sts_html($row['unloading_station']) . '<br />' . sts_html($row['unloading_location']) . '</td>';
        }
      }

      $data_table .= '</tr>';
      $row_count++;
    }
    $data_table .= '</tbody>';
    $data_table .= '</table></div>';
    // add a hidden field to the end of the table containing the number of rows
    $data_table .= '<input name="row_count" value="' . $row_count . '" type="hidden">';
  }
  else
  {
    $data_table = 'No cars at ' . $location_code;
  }
  print $data_table;

?>
