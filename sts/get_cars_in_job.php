<?php
  // this routine returns a list of cars being handled by a specified job  to the calling HttpRequest

  // get a database connection
  require 'open_db.php';
  $dbc = open_db();

  // pull in the style color function
  require 'set_colors.php';

  // get the incoming parameter
  $job = $_REQUEST['job'];
  $default_loc = $_REQUEST['default_loc'];

  // get this job's instructions
  $sql = 'select name, description from jobs where id = "' . $job . '"';
  $rs = mysqli_query($dbc, $sql);
  $row = mysqli_fetch_array($rs);
  $job_name = $row['name'];

  /* Job instructions now come from "get_cars_in_job.php"
  if (strlen($row['description']) > 0)
  {
    $job_instructions = nl2br($row['description']);
  }
  else
  {
    $job_instructions = "None";
  }
  */

  // build a query to find all cars currently being handled by the specified job
  $sql = 'select cars.id as id,
                 cars.reporting_marks as reporting_marks,
                 cars.status as status,
                 cars.position as position,
                 car_orders.waybill_number as waybill_number,
                 car_orders.shipment as shipment,
                 sta01.station as current_station,
                 loc01.code as current_location,
                 sta02.station as loading_station,
                 loc02.code as loading_location,
                 shipments.loading_location as loading_location_id,
                 sta03.station as unloading_station,
                 loc03.code as unloading_location,
                 shipments.unloading_location as unloading_location_id,
                 (select pickup_sta.station
                    from history pickup_history
                    left join locations pickup_loc on pickup_loc.id = pickup_history.location
                    left join routing pickup_sta on pickup_sta.id = pickup_loc.station
                   where pickup_history.car_id = cars.id
                     and pickup_history.event = "Picked up by Job ' . $job_name . '"
                   order by pickup_history.event_date desc
                   limit 1) as pickup_station,
                 (select pickup_loc.code
                    from history pickup_history
                    left join locations pickup_loc on pickup_loc.id = pickup_history.location
                   where pickup_history.car_id = cars.id
                     and pickup_history.event = "Picked up by Job ' . $job_name . '"
                   order by pickup_history.event_date desc
                   limit 1) as pickup_location,
                 car_codes.code as car_code,
                 commodities.code as consignment,
                 `' . $job_name . '`.step_number as step_number

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
          left join `' . $job_name . '` on `' . $job_name . '`.station = sta01.id

          where cars.handled_by_job_id = "' . $job . '"
            and cars.current_location_id = 0

          group by reporting_marks
          order by pickup_station, pickup_location, position, reporting_marks';
//print 'SQL: ' . $sql . '<br /><br />';
  $rs = mysqli_query($dbc, $sql);

  // build a table and return it as a string
  $row_count = 0;
  $current_pickup_group = null;
  if (mysqli_num_rows($rs) > 0)
  {
    $data_table = '<div class="table-responsive"><table id="job_table" class="table table-sm table-bordered table-hover">';
    $data_table .= '<tr>
                     <td colspan="9">';
//    $data_table .= 'JOB INSTRUCTIONS FOR '. $job_name . '<hr />' . $job_instructions; // replaced by a link to show_job_description.php
    $data_table .= 'Click <a href="show_job_description.php?job_id=' . $job . '" target="_blank">HERE</a> for Job Instructions<hr />';
    $data_table .= '  </td>
                   </tr>';
    $data_table .= '<tr style="position: sticky; top: 0; background-color: #F5F5F5">
                     <th style="text-align: center;">Check All <input id="check_all" name="check_all" type="checkbox" onchange="checkall_setout();"></th>
                     <th>Set-out Location</th>
                     <th>Position</th>
                     <th>Reporting Marks</th>
                     <th>Car Code</th>
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
      $pickup_filter_station = '';
      $pickup_filter_location = '';
      $consignment_filter = ($is_non_revenue ? 'Non-Revenue' : $row['consignment']);
      $non_revenue_unloading_station = '';
      $non_revenue_unloading_location = '';

      if (strlen($row['pickup_station']) > 0 || strlen($row['pickup_location']) > 0)
      {
        $pickup_filter_station = $row['pickup_station'];
        $pickup_filter_location = $row['pickup_station'] . ' - ' . $row['pickup_location'];
      }

      if ($is_non_revenue)
      {
        // Non-revenue final destination is stored directly on the car order shipment field.
        $sql2 = 'select code from locations where id = "' . $row['shipment'] . '"';
        $rs2 = mysqli_query($dbc, $sql2);
        $row2 = mysqli_fetch_array($rs2);
        $non_revenue_unloading_location = $row2['code'];

        $sql3 = 'select routing.station from routing, locations where locations.id = "' . $row['shipment'] . '" and routing.id = locations.station';
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

      $final_dest_location_id = '';
      if ($is_non_revenue)
      {
        $final_dest_location_id = $row['shipment'];
      }
      elseif ($row['status'] == 'Ordered')
      {
        $final_dest_location_id = $row['loading_location_id'];
      }
      elseif ($row['status'] == 'Loaded')
      {
        $final_dest_location_id = $row['unloading_location_id'];
      }

      if (strlen($pickup_filter_station) > 0)
      {
        $group_key = $pickup_filter_station . '|' . $row['pickup_location'];
        $group_label = htmlspecialchars($pickup_filter_station) . ' &mdash; ' . htmlspecialchars($row['pickup_location']);
      }
      else
      {
        $group_key = '__unknown_pickup__';
        $group_label = 'Pickup location unknown';
      }

      if ($group_key !== $current_pickup_group)
      {
        $current_pickup_group = $group_key;
        $data_table .= '<tr class="table-dark setout-group-header" data-group-key="' . htmlspecialchars($group_key, ENT_QUOTES) . '">'
            . '<td class="text-center"><input class="form-check-input location-group-check" type="checkbox" onchange="toggleSetoutLocationGroup(this);" aria-label="Check all cars at this location"></td>'
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
                  . ' data-final-destination-location="' . htmlspecialchars($final_dest_location, ENT_QUOTES) . '"'
                  . ' data-final-destination-id="' . htmlspecialchars($final_dest_location_id, ENT_QUOTES) . '">';

      // column 1 - checkbox for bulk set-out actions
      $data_table .= '<td style="text-align: center;"><input class="form-check-input setout-row-check" type="checkbox" aria-label="Include car in bulk set-out actions"></td>';

      // column 2 - list of locations where the selected job can set cars out
      $data_table .= '<td>' . get_job_setout_locations($dbc, $job, $row_count, $default_loc) . '</td>';

      // column 2 - position of the car in the train
      $data_table .= '<td>' . $row['position'] . '</td>';

      // column 3 - reporting marks
      if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['id'] . '.jpg'))
      {
        $parm_string = '\'' . $row['id'] . '\', \'' . $row['reporting_marks'] . '\'';
      }
      else
      {
        $parm_string = '\'\',\'' . $row['reporting_marks'] . '\'';
      }
      $data_table .= '<td onclick="show_image(' . $parm_string . ');">' . $row['reporting_marks'] . '<input name="car' . $row_count . '" value="' . $row['id'] . '" type="hidden"></td>';

      // column 4 - car code
      $data_table .= '<td>' . $row['car_code'] . '</td>';

      // column 5 - loading location - if this is a non-revenue move, display N/A, otherwise display the loading location
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
        else if ($row['status'] == "Empty")
        {
          $data_table .= '<td></td>';
        }
        else
        {
          $data_table .= '<td>' . $row['loading_station'] . '<br />' . $row['loading_location'] . '</td>';
        }
      }

      // column 6 - status
      $data_table .= '<td><span class="status-' . strtolower($row['status']) . '">' . $row['status'] . '</span></td>';

      // column 7 - unloading location - if this is a non-revenue move, display it's final destination which is stored in the car order's shipment column
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
        else if ($row['status'] == "Empty")
        {
          $data_table .= '<td></td>';
        }
        else
        {
          $data_table .= '<td>' . $row['unloading_station'] . '<br />' . $row['unloading_location'] . '</td>';
        }
      }

      // column 8 - consignment - if this is a non-revenue move, display Non-Revenue, otherwise display the consignment
      if ($is_non_revenue)
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

  ///////////////////////////////////////// return to calling HttpRequest //////////////////////////////////////

  // this function returns a drop-down list of locations where a car could be set out
  function get_job_setout_locations($dbc, $job, $row_count, $default_loc)
  {
    // build a query to get the name linked to this job id
    $sql = 'select name from jobs where id = "' . $job . '"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $job_name = $row['name'];

    // build a query to get the stations where this job can set out cars and find out it's default set-out location if there is one
    $sql = 'select distinct locations.id,
                   locations.code,
                   routing.sort_seq,
                   routing.station,
                   if (locations.id = routing.station_nbr, "Y", "N") as default_loc
              from locations, routing, `' . $job_name . '`
             where locations.station = `' . $job_name .'`.station
               and routing.id = locations.station
               and `' . $job_name . '`.setout = "T"
             order by routing.sort_seq, routing.station, locations.code';

    $rs = mysqli_query($dbc, $sql);

    if (mysqli_num_rows($rs) > 0)
    {
      $station_list = '<select name="station_list' . $row_count . '" class="form-select form-select-sm">';
      if ($default_loc == 'N')
      {
        $station_list .= '<option value="">KEEP IN TRAIN</option>';
      }
      while ($row = mysqli_fetch_array($rs))
      {
        if ($default_loc == 'Y')
        {
          if ($row['default_loc'] == "Y")
          {
            $station_list .= '<option value="' . $row['id'] . '">' . $row['station'] . ' - ' . $row['code'] . '</option>';
          }
        }
        else
        {
          $station_list .= '<option value="' . $row['id'] . '">' . $row['station'] . ' - ' . $row['code'] . '</option>';
        }
      }
      if ($default_loc == 'Y')
      {
        $station_list .= '<option value="">KEEP IN TRAIN</option>';
      }
      $station_list .= "</select>";
    }
    else
    {
      $station_list = '<select name="station_list"><option value="">This job has no set-out locations</option></select>';
    }
    return $station_list;
  }
?>
