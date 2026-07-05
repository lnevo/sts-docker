<?php
  // this routine returns a list of cars at a specified station to the calling HttpRequest

  // get a database connection
  require 'open_db.php';
  $dbc = open_db();

  // pull in the style color function
  require 'set_colors.php';

  // get the incoming parameter
  $station = urldecode($_REQUEST['station']);
  $all_stations = ($station === 'all');

  if ($all_stations)
  {
    $instructions = 'Showing cars at all stations ready for pickup assignment.';
  }
  else
  {
    // build a query to get the routing instructions for this station
    $sql = 'select instructions from routing where id = "' . $station . '"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_row($rs);
    if ($row && strlen($row[0]) > 0)
    {
      $instructions = 'Routing Instructions:<br /><br /> ' . $row[0];
    }
    else
    {
      $instructions = 'Routing Instruction: None';
    }
  }

  $station_filter = '';
  if (!$all_stations)
  {
    $station_filter = 'and cars.current_location_id in (select id from locations where station = "' . $station . '")';
  }

  $order_by = $all_stations ? 'sta01.station, loc01.code' : 'loc01.code';

  // build a query to find all cars currently at the designated station
  $sql = 'select cars.id as id,
                 cars.reporting_marks as reporting_marks,
                 cars.status as status,
                 car_orders.waybill_number as waybill_number,
                 car_orders.shipment as shipment_id,
                 sta01.id as current_station_id,
                 sta01.station as current_station,
                 loc01.code as current_location,
                 sta02.station as loading_station,
                 loc02.code as loading_location,
                 sta03.station as unloading_station,
                 loc03.code as unloading_location,
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
          where cars.status in ("Ordered", "Loaded")
            and (cars.handled_by_job_id = 0)
            ' . $station_filter . '
          order by ' . $order_by;
//print 'SQL: ' . $sql . '<br /><br />';
  $rs = mysqli_query($dbc, $sql);

  // build a table (less the <table> and </table> tags) and return it as a string
  $row_count = 0;
  $current_location_group = null;
  if (mysqli_num_rows($rs) > 0)
  {
    $data_table = '<div class="table-responsive"><table id="car_table" class="table table-sm table-bordered table-hover">';
    $data_table .= '<tr><td colspan="9">' . nl2br($instructions) . '</td></tr>';
    $data_table .= '<tr style="position: sticky; top: 0; background-color: #F5F5F5">
                      <th style="width: 1%; text-align: center;">Check All <input class="form-check-input" id="check_all" name="check_all" type="checkbox" onchange="checkall_build();" aria-label="Check all cars for bulk assignment"></th>
                      <th>Pickup Job</th>
                      <th>Reporting Marks</th>
                      <th>Car Code</th>
                      <th>Current Location</th>
                      <th>Loading Station / Location</th>
                      <th>Status</th>
                      <th>Unloading Station / Location</th>
                      <th>Consignment</th>
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
        $sql2 = 'select code from locations where locations.id = "' . $row['shipment_id'] . '"';
        $rs2 = mysqli_query($dbc, $sql2);
        $row2 = mysqli_fetch_array($rs2);
        $non_revenue_unloading_location = $row2['code'];

        $sql3 = 'select routing.station from routing, locations where (locations.id = ' . $row['shipment_id'] . ') and (routing.id = locations.station)';
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

      // insert a group header row when the current location changes
      $group_key = $row['current_station'] . '|' . $row['current_location'];
      if ($group_key !== $current_location_group)
      {
        $current_location_group = $group_key;
        $group_label = htmlspecialchars($row['current_station']) . ' &mdash; ' . htmlspecialchars($row['current_location']);
        $data_table .= '<tr class="table-dark location-group-header" data-group-key="' . htmlspecialchars($group_key, ENT_QUOTES) . '">'
            . '<td class="text-center"><input class="form-check-input location-group-check" type="checkbox" onchange="toggleBuildLocationGroup(this);" aria-label="Check all cars at this location"></td>'
            . '<td colspan="8" class="fw-semibold">' . $group_label . '</td></tr>';
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

      // column 1 - include this row when using the bulk job selector
      $data_table .= '<td class="text-center"><input class="form-check-input bulk-assign-row" type="checkbox" aria-label="Include this car in bulk assignment"></td>';

      // column 2 - list of eligible jobs
      $job_station = $all_stations ? $row['current_station_id'] : $station;
      $data_table .= '<td>' . get_jobs_at_station($dbc, $job_station, $row_count) . '</td>';

      // column 3 - reporting marks
      if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['id'] . '.jpg'))
      {
        $parm_string = '\'' . $row['id'] . '\', \'' . $row['reporting_marks'] . '\'';
      }
      else
      {
        $parm_string = '\'\',\'' . $row['reporting_marks'] . '\'';
      }

      $data_table .= '<td onclick="show_image(' . $parm_string . ');">' . $row['reporting_marks'] . '<input name="car' . $row_count . '"';
      $data_table = $data_table . ' value="' . $row['id'] . '" type="hidden"></td>';

      // column 4 - car code
      $data_table .= '<td>' . $row['car_code'] . '</td>';

      // column 5 - current location
      $data_table .= '<td>' . $row['current_station'] . '<br />' . $row['current_location'] . '</td>';

      // column 6 - loading location
      if (substr($row['waybill_number'], 4, 1) == 'E')
      {
        $data_table .= '<td>N/A</td>';
      }
      else
      {
        // if this car is ordered, bold the the loading location
        if ($row['status'] == 'Ordered')
        {
          $data_table .= '<td style="' . set_colors($dbc, $row['loading_location']) . '"><b>' . $row['loading_station'] . '<br />' . $row['loading_location'] . '</b></td>';
        }
        else
        {
          $data_table .= '<td>' . $row['loading_station'] . '<br />' . $row['loading_location'] . '</td>';
        }
      }

      // column 7 - status
      $data_table .= '<td><span class="status-' . strtolower($row['status']) . '">' . $row['status'] . '</span></td>';

      // column 8 - unloading location
      if (substr($row['waybill_number'], 4, 1) == 'E')
      {
        // run a couple quick queries to find this car's destination since it isn't linked to a shipment
        // the destination is stored in the car order's shipment field
        $sql2 = 'select code from locations where locations.id = "' . $row['shipment_id'] . '"';
        $rs2 = mysqli_query($dbc, $sql2);
        $row2 = mysqli_fetch_array($rs2);

        $sql3 = 'select routing.station from routing, locations where (locations.id = ' . $row['shipment_id'] . ') and (routing.id = locations.station)';
        $rs3 = mysqli_query($dbc, $sql3);
        $row3 = mysqli_fetch_array($rs3);

        $data_table .= '<td style="' . set_colors($dbc, $row2['code']) . '"><b>' . $row3['station'] . '<br />' . $row2['code'] . '</b></td>';
      }
      else
      {
        // if this car is loaded, bold the the final destination
        if ($row['status'] == 'Loaded')
        {
          $data_table .= '<td style="' . set_colors($dbc, $row['unloading_location']) . '"><b>' . $row['unloading_station'] . '<br />' . $row['unloading_location'] . '</b></td>';
        }
        else
        {
          $data_table .= '<td>' . $row['unloading_station'] . '<br />' . $row['unloading_location'] . '</td>';
        }
      }

      // column 9 - consignment -  if this is a non-revenue move, display "Non-Revenue", otherwise display the consignment
      if (substr($row['waybill_number'], 4, 1) == 'E')
      {
        $data_table .= '<td>Non-Revenue</td>';
      }
      else
      {
        $data_table .= '<td>' . $row['consignment'] . '</td>';
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

  // this function returns a drop-down list of jobs that pick up at this station
  function get_jobs_at_station($dbc, $station, $row_count)
  {
    // build a query to get the names of all of the jobs
    $sql = 'select id, name from jobs';
    $rs = mysqli_query($dbc, $sql);

    // build a drop-down list from the jobs that are set to pick up at this station
    if (mysqli_num_rows($rs))
    {
      $job_list = '<select name="job_list' . $row_count . '" class="form-select form-select-sm">';
      $job_list .= '<option value=""></option>';
      while ($row = mysqli_fetch_array($rs))
      {
        // build a query to see if this job is set to pick up at this station
        // if so, add it to the list of options
        $sql = 'select count(*) from `' . $row['name'] . '` where station = "' . $station . '" and pickup = "T"';
        $rs_steps = mysqli_query($dbc, $sql);
        $row_steps = mysqli_fetch_row($rs_steps);
        if ($row_steps[0] > 0)
        {
          $job_list .= '<option value="' . $row['id'] . '">' . $row['name'] . '</option>';
        }
      }
      $job_list .= '</select>';
    }
    else
    {
      $job_list = 'No job picks up here';
    }
    return $job_list;
  }
?>
