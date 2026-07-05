<?php
  // this routine returns a list of cars being handled by a specified job to the calling HttpRequest

  // get a database connection
  require 'open_db.php';
  $dbc = open_db();

  // pull in the style colors function
  require 'set_colors.php';

  // get the incoming parameter
  $job = $_REQUEST['job'];

  // build a query to find all cars currently being handled by the specified job
  $sql = 'select cars.id as id,
                 cars.position as position,
                 cars.reporting_marks,
                 cars.status as status,
                 cars.position,
                 car_orders.waybill_number as waybill_number,
                 car_orders.shipment as shipment,
                 sta01.station as current_station,
                 loc01.code as current_location,
                 sta02.station as loading_station,
                 loc02.code as loading_location,
                 sta03.station as unloading_station,
                 loc03.code as unloading_location,
                 car_codes.code as car_code,
                 commodities.code as consignment
          from cars
          left join car_orders on car_orders.car = cars.id
          left join shipments on shipments.id = car_orders.shipment
          left join locations loc01 on loc01.id = cars.current_location_id
          left join locations loc02 on loc02.id = shipments.loading_location
          left join locations loc03 on loc03.id = shipments.unloading_location
          left join routing sta01 on sta01.id = loc01.station
          left join routing sta02 on sta02.id = loc02.station
          left join routing sta03 on sta03.id = loc03.station
          left join car_codes on car_codes.id = cars.car_code_id
          left join commodities on commodities.id = shipments.consignment
          where cars.handled_by_job_id = "' . $job . '"
            and cars.current_location_id > 0
            and cars.status != "Unavailable"
          order by sta01.sort_seq asc, sta01.station asc, loc01.code asc, cars.position asc, cars.reporting_marks asc';
//          order by sta01.sort_seq, loc01.code, cars.position, cars.reporting_marks';

          // added cars.status != "Unavailable" to handle cars thave have been removed from the railroad

//print 'SQL: ' . $sql . '<br /><br />';
  $rs = mysqli_query($dbc, $sql);

  // build a table (less the <table> and </table> tags) and return it as a string
  $row_count = 0;
  $current_pickup_group = null;
  if (mysqli_num_rows($rs) > 0)
  {
    $data_table = '<div class="table-responsive"><table id="job_table" class="table table-sm table-bordered table-hover">';
    $data_table .= '<tr style="position: sticky; top: 0; background-color: #F5F5F5">
                     <th style="text-align: center;">Check All <input class="form-check-input" id="check_all" name="check_all" type="checkbox" onchange="checkall();" aria-label="Check all cars for pickup"></th>
                     <th>Pickup Location</th>
                     <th>Reporting Marks</th>
                     <th>Car Code</th>
                     <th>Status</th>
                     <th>Consignment</th>
                     <th>Loading Station / Location</th>
                     <th>Unloading Station / Location</th>
                   </tr>';
    while ($row = mysqli_fetch_array($rs))
    {
      $is_non_revenue = substr($row['waybill_number'], 4, 1) == 'E';
      $loading_filter_station = ($is_non_revenue ? '' : $row['loading_station']);
      $loading_filter_location = ($is_non_revenue ? '' : $row['loading_station'] . ' - ' . $row['loading_location']);
      $unloading_filter_station = $row['unloading_station'];
      $unloading_filter_location = $row['unloading_station'] . ' - ' . $row['unloading_location'];
      $pickup_filter_station = $row['current_station'];
      $pickup_filter_location = $row['current_station'] . ' - ' . $row['current_location'];
      $consignment_filter = ($is_non_revenue ? 'Non-Revenue' : $row['consignment']);
      $non_revenue_unloading_station = '';
      $non_revenue_unloading_location = '';

      if ($is_non_revenue)
      {
        // Non-revenue final destination is stored directly on the car order shipment field.
        $sql2 = 'select code from locations where id = "' . $row['shipment'] . '"';
        $rs2 = mysqli_query($dbc, $sql2);
        $row2 = mysqli_fetch_array($rs2);
        $non_revenue_unloading_location = $row2['code'];

        $sql3 = 'select routing.station from routing, locations where (routing.id = locations.station) and (locations.id = "' . $row['shipment'] . '")';
        $rs3 = mysqli_query($dbc, $sql3);
        $row3 = mysqli_fetch_array($rs3);
        $non_revenue_unloading_station = $row3['station'];
        $unloading_filter_station = $non_revenue_unloading_station;
        $unloading_filter_location = $non_revenue_unloading_station . ' - ' . $non_revenue_unloading_location;
      }

      $final_dest_station = '';
      $final_dest_location = '';
      if ($is_non_revenue)
      {
        $final_dest_station = $non_revenue_unloading_station;
        $final_dest_location = $non_revenue_unloading_station . ' - ' . $non_revenue_unloading_location;
      }
      elseif ($row['status'] == 'Ordered')
      {
        $final_dest_station = $row['loading_station'];
        $final_dest_location = $row['loading_station'] . ' - ' . $row['loading_location'];
      }
      elseif ($row['status'] == 'Loaded')
      {
        $final_dest_station = $row['unloading_station'];
        $final_dest_location = $row['unloading_station'] . ' - ' . $row['unloading_location'];
      }

        // insert a group header row when the pickup location changes
        $group_key = $row['current_station'] . '|' . $row['current_location'];
        if ($group_key !== $current_pickup_group)
        {
          $current_pickup_group = $group_key;
          $group_label = htmlspecialchars($row['current_station']) . ' &mdash; ' . htmlspecialchars($row['current_location']);
          $data_table .= '<tr class="table-dark pickup-group-header" data-group-key="' . htmlspecialchars($group_key, ENT_QUOTES) . '">'
                      . '<td class="text-center"><input class="form-check-input location-group-check" type="checkbox" onchange="togglePickupLocationGroup(this);" aria-label="Check all cars at this location"></td>'
                      . '<td colspan="7" class="fw-semibold">' . $group_label . '</td></tr>';
        }

      // generate the table rows
      $data_table .= '<tr class="job-car-row"'
                  . ' data-location-group="' . htmlspecialchars($group_key, ENT_QUOTES) . '"'
                  . ' data-pickup-station="' . htmlspecialchars($pickup_filter_station, ENT_QUOTES) . '"'
                  . ' data-pickup-location="' . htmlspecialchars($pickup_filter_location, ENT_QUOTES) . '"'
                  . ' data-reporting-marks="' . htmlspecialchars($row['reporting_marks'], ENT_QUOTES) . '"'
                  . ' data-car-code="' . htmlspecialchars($row['car_code'], ENT_QUOTES) . '"'
                  . ' data-status="' . htmlspecialchars($row['status'], ENT_QUOTES) . '"'
                  . ' data-consignment="' . htmlspecialchars($consignment_filter, ENT_QUOTES) . '"'
                  . ' data-loading-station="' . htmlspecialchars($loading_filter_station, ENT_QUOTES) . '"'
                  . ' data-loading-location="' . htmlspecialchars($loading_filter_location, ENT_QUOTES) . '"'
                  . ' data-unloading-station="' . htmlspecialchars($unloading_filter_station, ENT_QUOTES) . '"'
                  . ' data-unloading-location="' . htmlspecialchars($unloading_filter_location, ENT_QUOTES) . '"'
                  . ' data-final-destination-station="' . htmlspecialchars($final_dest_station, ENT_QUOTES) . '"'
                  . ' data-final-destination-location="' . htmlspecialchars($final_dest_location, ENT_QUOTES) . '">';

      // column 1 - check box to indicate that the car was picked up
      $data_table .= '<td class="text-center"><input class="form-check-input pickup-row-check" id="check' . $row_count . '" name="check' . $row_count . '" type="checkbox" aria-label="Pick up this car"></td>';

      // column 2 - where the car was picked up (current location)
      $data_table .= '<td>' . $row['current_station'] . '<br />' . $row['current_location'] . '</td>';

      // column 3 - reporting marks
      if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['id'] . '.jpg'))
      {
        $parm_string = '\'' . $row['id'] . '\', \'' . $row['reporting_marks'] . '\'';
      }
      else
      {
        $parm_string = '\'\',\'' . $row['reporting_marks'] . '\'';
      }
      $data_table .= '<td onclick="show_image(' . $parm_string . ');">' . $row['reporting_marks'] . '
                      <input name="car' . $row_count . '" value="' . $row['id'] . '" type="hidden">
                      </td>';

      // column 4 - car code
      $data_table .= '<td>' . $row['car_code'] . '</td>';


      // column 5 - status
      $data_table .= '<td><span class="status-' . strtolower($row['status']) . '">' . $row['status'] . '</span></td>';


      // column 6 - consignment - if this is a non-revenue move, display Non-Revenue, otherwise display the consignment
      if ($is_non_revenue)
      {
        $data_table .= '<td>Non-Revenue</td>';
      }
      else
      {
        $data_table .= '<td>' . $row['consignment'] . '</td>';
      }

      // column 7 - loading location - if this is a non-revenue move, display N/A, otherwise display the loading location
      if ($is_non_revenue)
      {
        $data_table .= '<td>N/A</td>';
      }
      else
      {
        // if the car status is Ordered, mark the loading location with bold letters
        if ($row['status'] == "Ordered")
        {
          $data_table .= '<td style="' . set_colors($dbc, $row['loading_location']) . '"><b>' . $row['loading_station'] . '<br />' . $row['loading_location'] . '</b></td>';
        }
        else
        {
          $data_table .= '<td>' . $row['loading_station'] . '<br />' . $row['loading_location'] . '</td>';
        }
      }

      // column 8 - unloading location - if this is a non-revenue move, display it's final destination which is stored in the car order's shipment column
      if ($is_non_revenue)
      {
        $data_table .= '<td style="' . set_colors($dbc, $non_revenue_unloading_location) . '"><b>' . $non_revenue_unloading_station . '<br />' . $non_revenue_unloading_location . '</b></td>';

      }
      else
      {
        // if the car status is Loaded, mark the loading location with bold letters
        if ($row['status'] == "Loaded")
        {
          $data_table .= '<td style="' . set_colors($dbc, $row['unloading_location']) . '"><b>' . $row['unloading_station'] . '<br />' . $row['unloading_location'] . '</b></td>';
        }
        else
        {
          $data_table .= '<td>' . $row['unloading_station'] . '<br />' . $row['unloading_location'] . '</td>';
        }
      }

      $data_table .= '</tr>';
      $row_count++;
    }
    $data_table .= '</table></div>';
    // add a hidden field to the end of the table containing the number of rows
    $data_table .= '<input name="row_count" value="' . $row_count . '" type="hidden">';
  }
  else
  {
    $data_table = 'None';
  }
  print $data_table;

  ///////////////////////////////////////// return to calling HttpRequest //////////////////////////////////////

?>
