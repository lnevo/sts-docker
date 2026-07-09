<?php
  // this file contains functions that return the contents of various
  // tables to be used in building drop down lists

  ///////////////////////////////////////////////////////////////////////

  // car_codes
  function drop_down_car_codes($list_name, $tab_index, $wild_cards)
  {
    // get a database connection
    $dbc = open_db();

    // build the query to pull in all of the car codes
    $sql = "select id, code from car_codes order by code";

    // retrieve the rows and put them into an html <select> block
    $rs = mysqli_query($dbc, $sql);

    $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '">';
    $select_string .= '<option value=""></option>';

    if (mysqli_num_rows($rs) > 0)
    {
      while ($row = mysqli_fetch_array($rs))
      {
        // check the wild card setting - only add a car code to the list if
        // - wild cards are OK or
        // - wild cards aren't OK and the car code doesn't contain one anyway
        if (($wild_cards == "wild_ok") || (($wild_cards == "no_wild") && (!strpos($row['code'], "*"))))
        {
          $select_string .= '<option value="' . $row['id'] . '">' . $row['code'] . '</option>';
        }
      }
    }

    $select_string .= "</select>";
    return $select_string;
  }

  ///////////////////////////////////////////////////////////////////////

  // locations
  function drop_down_locations($list_name, $tab_index, $on_click, $show_car_counts = false)
  {
    // get a database connection
    $dbc = open_db();

    // build the query to pull in all of the locations
    $sql = "select locations.id, locations.code, routing.station, routing.sort_seq 
              from locations, routing
             where locations.station = routing.id
          order by routing.sort_seq, routing.station, locations.code";

    // retrieve the rows and put them into an array
    $rs = mysqli_query($dbc, $sql);

    $car_counts = [];
    if ($show_car_counts)
    {
      $count_rs = mysqli_query($dbc, 'select current_location_id as location_id, count(*) as cnt
                                        from cars
                                       where current_location_id > 0
                                    group by current_location_id');
      if ($count_rs)
      {
        while ($count_row = mysqli_fetch_array($count_rs))
        {
          $car_counts[$count_row['location_id']] = (int)$count_row['cnt'];
        }
      }
    }

    $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '" onclick="' . $on_click . '" style="width: 100px;">';
    $select_string .= '<option value=""></option>';

    if (mysqli_num_rows($rs) > 0)
    {
      while ($row = mysqli_fetch_array($rs))
      {
        $label = $row['station'] . ' - ' . $row['code'];
        if ($show_car_counts)
        {
          $count = isset($car_counts[$row['id']]) ? $car_counts[$row['id']] : 0;
          if ($count > 0)
          {
            $label .= ' (' . $count . ')';
          }
        }
        $select_string .= '<option value="' . $row['id'] . '">' . $label . '</option>';
      }
    }

    $select_string .= '</select>';
    return $select_string;
  }

  ///////////////////////////////////////////////////////////////////////

  // locations at station
  function drop_down_locations_at_station($station_id, $list_name, $tab_index, $on_click)
  {
    // get a database connection
    $dbc = open_db();

    // build the query to pull in all of the locations
    $sql = 'select locations.id, locations.code
              from locations
             where locations.station = "' . $station_id . '"
          order by locations.code';
// print '<br />SQL: ' . $sql . '<br />';
    // retrieve the rows and put them into an array
    $rs = mysqli_query($dbc, $sql);

    $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '" onclick="' . $on_click . '" style="width: 100px;">';
    $select_string .= '<option value=""></option>';

    if (mysqli_num_rows($rs) > 0)
    {
      while ($row = mysqli_fetch_array($rs))
      {
        $select_string .= '<option value="' . $row['id'] . '">' . $row['code'] . '</option>';
      }
    }

    $select_string .= '</select>';
    return $select_string;
  }

  ///////////////////////////////////////////////////////////////////////

  // shipments
  function drop_down_shipments($list_name, $tab_index)
  {
    // get a database connection
    $dbc = open_db();

    // build the query to pull in all of the locations
    $sql = 'select id, code from shipments order by code';

    // retrieve the rows and put them into an array
    $rs = mysqli_query($dbc, $sql);

    $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '">';
    $select_string .= '<option value=""></option>';

    if (mysqli_num_rows($rs) > 0)
    {
      while ($row = mysqli_fetch_array($rs))
      {
        $select_string .= '<option value="' . $row['id'] . '">' . $row['code'] . '</option>';
      }
    }

    $select_string .= '</select>';
    return $select_string;
  }

  ///////////////////////////////////////////////////////////////////////

  // shipments
  function drop_down_ship_desc($list_name, $tab_index)
  {
    // get a database connection
    $dbc = open_db();

    // build the query to pull in all of the locations
    $sql = 'select id, code, description from shipments order by code';

    // retrieve the rows and put them into an array
    $rs = mysqli_query($dbc, $sql);

    $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '">';
    $select_string .= '<option value=""></option>';

    if (mysqli_num_rows($rs) > 0)
    {
      while ($row = mysqli_fetch_array($rs))
      {
        $select_string .= '<option value="' . $row['id'] . '">' . $row['code'] . ' - ' . substr($row['description'], 0, 20) . '</option>';
      }
    }

    $select_string .= '</select>';
    return $select_string;
  }

  ///////////////////////////////////////////////////////////////////////

  // status
  function drop_down_status($list_name, $tab_index)
  {
    $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '">
                        <option value=""></option>
                        <option value="Empty">Empty</option>
                        <option value="Ordered">Ordered</option>
                        <option value="Loading">Loading</option>
                        <option value="Loaded">Loaded</option>
                        <option value="Unloading">Unloading</option>
                        <option value="Unavailable">Unavailable</option>
                      </select>';
    return $select_string;
  }

  ///////////////////////////////////////////////////////////////////////

  // stations
  function drop_down_stations($list_name, $tab_index, $on_click, $include_all = false, $show_pending_counts = false)
  {
    // get a database connection
    $dbc = open_db();

    // build the query to pull in all of the stations
    $sql = "select id, station from routing order by sort_seq, station";

    // retrieve the rows and put them into an array
    $rs = mysqli_query($dbc, $sql);

    $pending_counts = [];
    $all_pending = 0;
    if ($show_pending_counts)
    {
      $count_sql = 'select locations.station as station_id, count(*) as cnt
                      from cars
                      join locations on locations.id = cars.current_location_id
                     where cars.status in ("Ordered", "Loaded")
                       and cars.handled_by_job_id = 0
                  group by locations.station';
      $count_rs = mysqli_query($dbc, $count_sql);
      if ($count_rs)
      {
        while ($count_row = mysqli_fetch_array($count_rs))
        {
          $pending_counts[$count_row['station_id']] = (int)$count_row['cnt'];
          $all_pending += (int)$count_row['cnt'];
        }
      }
    }

    if ((isset($on_click) && strlen($on_click) > 0))
    {
      // "onclick" won't work with touch screen devices
      // $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '" onclick="' . $on_click . '">';
      $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '" onchange="' . $on_click . '">';
    }
    else
    {
      $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '">';
    }

    $select_string .= '<option value=""></option>';

    if ($include_all)
    {
      $all_label = 'All Stations';
      if ($show_pending_counts && $all_pending > 0)
      {
        $all_label .= ' (' . $all_pending . ')';
      }
      $select_string .= '<option value="all">' . $all_label . '</option>';
    }

    if (mysqli_num_rows($rs) > 0)
    {
      while ($row = mysqli_fetch_array($rs))
      {
        $label = $row['station'];
        if ($show_pending_counts)
        {
          $count = isset($pending_counts[$row['id']]) ? $pending_counts[$row['id']] : 0;
          if ($count > 0)
          {
            $label .= ' (' . $count . ')';
          }
        }
        $select_string .= '<option value="' . $row['id'] . '">' . $label . '</option>';
      }
    }

    $select_string .= '</select>';
    return $select_string;
  }

  ///////////////////////////////////////////////////////////////////////

  // empty cars at one of the locations prioritized for a specified shipment
  function drop_down_specified_cars($list_name, $shipment)
  {
    // get a database connection
    $dbc = open_db();

    // use the shipment ID to pull in the required car code
    $sql = 'select car_code from shipments where code = "' . $shipment . '"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_row($rs);
    $car_code = $row[0];

    // substitute % (SQL wild card) for any * in the car code
    $new_car_code = "";
    for ($i=0; $i<strlen($car_code); $i++)
    {
      if (substr($car_code, $i, 1) == '*')
      {
        $new_car_code = $new_car_code . '%';
      }
      else
      {
        $new_car_code = $new_car_code . substr($car_code, $i, 1);
      }
    }
    $car_code = $new_car_code;

    // find out if this shipment has prioritized empty car locations
    $sql = 'select count(0) from empty_locations where shipment = "' . $shipment . '"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_row($rs);

    if ($row[0] > 0)
    {
/*      // if so, build a query to pull in eligible cars at the specified locations
      $sql = 'select distinct cars.reporting_marks
              from cars, empty_locations
              where cars.status = "Empty"
              and cars.car_code like "' . $car_code . '"
              and cars.current_location like replace(empty_locations.location, "*", "%")
              and empty_locations.shipment = "' . $shipment . '"
              order by empty_locations.priority asc,  cars.load_count desc';
*/
      $sql = 'select cars.reporting_marks, 0 as pr, cars.load_count as lc
              from cars, empty_locations, shipments
              where cars.status = "Empty"
              and cars.car_code like "' . $car_code . '"
              and cars.current_location in
                  (select code from locations where locations.station in
                         (select station from locations, shipments
                          where locations.code = shipments.loading_location and shipments.code = "' . $shipment . '"))
              union
              select distinct cars.reporting_marks, empty_locations.priority as pr, cars.load_count as lc
              from cars, empty_locations
              where cars.status = "Empty"
              and cars.car_code like "' . $car_code . '"
              and cars.current_location like replace(empty_locations.location, "*", "%")
              and empty_locations.shipment = "' . $shipment . '"
              order by pr asc, lc asc';
    }
    else
    {
      // if not, build a query to pull in eligible cars from the entire system
      $sql = 'select cars.reporting_marks, 0 as pr, cars.load_count as lc
              from cars, empty_locations, shipments
              where cars.status = "Empty"
              and cars.car_code like "' . $car_code . '"
              and cars.current_location in
                  (select code from locations where locations.station in
                         (select station from locations, shipments
                          where locations.code = shipments.loading_location and shipments.code = "' . $shipment . '"))
              union
              select cars.reporting_marks, 9999 as pr, cars.load_count as lc
              from cars
              where cars.status = "Empty"
              and cars.car_code like "' . $car_code . '"
              order by pr asc, loc asc';
    }

    $rs = mysqli_query($dbc, $sql);

    if (mysqli_num_rows($rs) > 0)
    {
      $select_string = '<select id="' . $list_name . '" name="' . $list_name . '">';
      $select_string .= '<option value=""></option>';
      while ($row = mysqli_fetch_array($rs))
      {
        $select_string .= '<option value="' . $row[0] . '">' . $row[0] . '</option>';
      }
      $select_string .= '</select>';
      return $select_string;
    }
    else
    {
      return '<select id="' . $list_name . '" name="' . $list_name . '"><option value="">None Avail</option></select>';
    }
  }

  ///////////////////////////////////////////////////////////////////////

  /**
   * Car IDs eligible for auto-assign on a job (same rules as auto_assign.php).
   * Returns unique car ids matching any pu_criteria row for the job.
   */
  function auto_assign_eligible_car_ids_for_job($dbc, $job_name, $unassigned_only = true)
  {
    $car_ids = [];
    $job_name_esc = mysqli_real_escape_string($dbc, $job_name);

    $crit_rs = mysqli_query(
      $dbc,
      'SELECT step_nbr, dest_station_id, car_status
         FROM pu_criteria
        WHERE job_id = "' . $job_name_esc . '"'
    );
    if (!$crit_rs)
    {
      return $car_ids;
    }

    while ($crit = mysqli_fetch_array($crit_rs))
    {
      $step_nbr = (int) $crit['step_nbr'];
      $dest_station_id = (int) $crit['dest_station_id'];

      $step_rs = mysqli_query(
        $dbc,
        'SELECT station FROM `' . $job_name . '` WHERE step_number = ' . $step_nbr
      );
      if (!$step_rs || mysqli_num_rows($step_rs) === 0)
      {
        continue;
      }
      $step_row = mysqli_fetch_array($step_rs);
      $pickup_station_id = (int) $step_row['station'];

      $pickup_location_ids = [];
      $pickup_rs = mysqli_query(
        $dbc,
        'SELECT id FROM locations WHERE station = ' . $pickup_station_id
      );
      while ($pickup_row = mysqli_fetch_array($pickup_rs))
      {
        $pickup_location_ids[] = (int) $pickup_row['id'];
      }

      $dest_location_ids = [];
      $dest_rs = mysqli_query(
        $dbc,
        'SELECT id FROM locations WHERE station = ' . $dest_station_id
      );
      while ($dest_row = mysqli_fetch_array($dest_rs))
      {
        $dest_location_ids[] = (int) $dest_row['id'];
      }

      if (count($pickup_location_ids) === 0 || count($dest_location_ids) === 0)
      {
        continue;
      }

      $pickup_location_string = implode(', ', $pickup_location_ids);
      $dest_location_string = implode(', ', $dest_location_ids);
      $unassigned_clause = $unassigned_only ? ' AND cars.handled_by_job_id = 0' : '';

      $sql_revenue = 'SELECT DISTINCT cars.id
                        FROM cars
                        INNER JOIN car_orders ON cars.id = car_orders.car
                        INNER JOIN shipments ON shipments.id = car_orders.shipment
                       WHERE cars.current_location_id IN (' . $pickup_location_string . ')' . $unassigned_clause . '
                         AND (
                               (cars.status = "Ordered"
                                AND car_orders.waybill_number NOT LIKE "%E%"
                                AND shipments.loading_location IN (' . $dest_location_string . '))
                            OR (cars.status = "Loaded"
                                AND shipments.unloading_location IN (' . $dest_location_string . '))
                         )';

      $car_rs = mysqli_query($dbc, $sql_revenue);
      if ($car_rs)
      {
        while ($car_row = mysqli_fetch_array($car_rs))
        {
          $car_ids[(int) $car_row['id']] = true;
        }
      }

      $sql_reposition = 'SELECT DISTINCT cars.id
                           FROM cars
                           INNER JOIN car_orders ON cars.id = car_orders.car
                          WHERE cars.current_location_id IN (' . $pickup_location_string . ')' . $unassigned_clause . '
                            AND cars.status = "Ordered"
                            AND car_orders.waybill_number LIKE "%E%"
                            AND car_orders.shipment IN (' . $dest_location_string . ')';

      $car_rs = mysqli_query($dbc, $sql_reposition);
      if ($car_rs)
      {
        while ($car_row = mysqli_fetch_array($car_rs))
        {
          $car_ids[(int) $car_row['id']] = true;
        }
      }
    }

    return $car_ids;
  }

  function locations_by_station_map($dbc)
  {
    $map = [];
    $rs = mysqli_query($dbc, 'SELECT id, station FROM locations');
    if (!$rs)
    {
      return $map;
    }

    while ($row = mysqli_fetch_array($rs))
    {
      $station_id = (int) $row['station'];
      if (!isset($map[$station_id]))
      {
        $map[$station_id] = [];
      }
      $map[$station_id][] = (int) $row['id'];
    }

    return $map;
  }

  function pending_assignment_car_pool($dbc)
  {
    $cars = [];
    $sql = 'SELECT cars.id,
                   cars.current_location_id,
                   cars.status,
                   car_orders.waybill_number,
                   car_orders.shipment,
                   shipments.loading_location,
                   shipments.unloading_location
              FROM cars
         LEFT JOIN car_orders ON cars.id = car_orders.car
         LEFT JOIN shipments ON shipments.id = car_orders.shipment
             WHERE cars.handled_by_job_id = 0';

    $rs = mysqli_query($dbc, $sql);
    if (!$rs)
    {
      return $cars;
    }

    while ($row = mysqli_fetch_array($rs))
    {
      $cars[(int) $row['id']] = $row;
    }

    return $cars;
  }

  function pending_assignment_car_matches_criterion($car, $pickup_location_ids, $dest_location_ids)
  {
    if (!in_array((int) $car['current_location_id'], $pickup_location_ids, true))
    {
      return false;
    }

    $waybill_number = $car['waybill_number'] ?? '';
    $is_reposition = strpos($waybill_number, 'E') !== false;

    if ($is_reposition)
    {
      return $car['status'] === 'Ordered'
        && in_array((int) $car['shipment'], $dest_location_ids, true);
    }

    if ($car['status'] === 'Ordered')
    {
      return in_array((int) $car['loading_location'], $dest_location_ids, true);
    }

    if ($car['status'] === 'Loaded')
    {
      return in_array((int) $car['unloading_location'], $dest_location_ids, true);
    }

    return false;
  }

  function pending_assignment_counts_by_job($dbc)
  {
    $counts = [];
    $locations_by_station = locations_by_station_map($dbc);
    $car_pool = pending_assignment_car_pool($dbc);

    $criteria_by_job = [];
    $crit_rs = mysqli_query($dbc, 'SELECT job_id, step_nbr, dest_station_id FROM pu_criteria');
    if ($crit_rs)
    {
      while ($crit = mysqli_fetch_array($crit_rs))
      {
        $criteria_by_job[$crit['job_id']][] = $crit;
      }
    }

    $jobs_rs = mysqli_query($dbc, 'select id, name from jobs order by name');
    if (!$jobs_rs)
    {
      return $counts;
    }

    while ($job = mysqli_fetch_array($jobs_rs))
    {
      $job_id = (int) $job['id'];
      $job_name = $job['name'];
      $eligible_ids = [];
      $criteria = $criteria_by_job[$job_name] ?? [];

      $step_map = [];
      $step_rs = mysqli_query($dbc, 'SELECT step_number, station FROM `' . $job_name . '`');
      if ($step_rs)
      {
        while ($step_row = mysqli_fetch_array($step_rs))
        {
          $step_map[(int) $step_row['step_number']] = (int) $step_row['station'];
        }
      }

      foreach ($criteria as $crit)
      {
        $step_nbr = (int) $crit['step_nbr'];
        $pickup_station_id = $step_map[$step_nbr] ?? null;
        if ($pickup_station_id === null)
        {
          continue;
        }

        $pickup_location_ids = $locations_by_station[$pickup_station_id] ?? [];
        $dest_location_ids = $locations_by_station[(int) $crit['dest_station_id']] ?? [];
        if (count($pickup_location_ids) === 0 || count($dest_location_ids) === 0)
        {
          continue;
        }

        foreach ($car_pool as $car_id => $car)
        {
          if (pending_assignment_car_matches_criterion($car, $pickup_location_ids, $dest_location_ids))
          {
            $eligible_ids[$car_id] = true;
          }
        }
      }

      $counts[$job_id] = count($eligible_ids);
    }

    return $counts;
  }

  function pending_counts_by_job($dbc, $mode)
  {
    if ($mode === true || $mode === 'assignment')
    {
      return pending_assignment_counts_by_job($dbc);
    }

    $counts = [];
    if ($mode === 'pickup')
    {
      $sql = 'select handled_by_job_id as job_id, count(*) as cnt
                from cars
               where handled_by_job_id > 0
                 and current_location_id > 0
                 and status != "Unavailable"
            group by handled_by_job_id';
    }
    elseif ($mode === 'setout')
    {
      $sql = 'select handled_by_job_id as job_id, count(*) as cnt
                from cars
               where handled_by_job_id > 0
                 and current_location_id = 0
            group by handled_by_job_id';
    }
    elseif ($mode === 'organize')
    {
      $sql = 'select handled_by_job_id as job_id, count(*) as cnt
                from cars
               where handled_by_job_id > 0
                 and status != "Unavailable"
            group by handled_by_job_id';
    }
    else
    {
      return $counts;
    }

    $rs = mysqli_query($dbc, $sql);
    if ($rs)
    {
      while ($row = mysqli_fetch_array($rs))
      {
        $counts[$row['job_id']] = (int)$row['cnt'];
      }
    }

    return $counts;
  }

  function organize_total_cars_by_job($dbc)
  {
    return array_sum(pending_counts_by_job($dbc, 'organize'));
  }

  function organize_total_cars_at_locations($dbc)
  {
    $rs = mysqli_query(
      $dbc,
      'SELECT COUNT(*) AS cnt
         FROM cars
        WHERE current_location_id > 0'
    );
    if ($rs && ($row = mysqli_fetch_array($rs)))
    {
      return (int) $row['cnt'];
    }

    return 0;
  }

  function organize_total_unique_cars($dbc)
  {
    $rs = mysqli_query(
      $dbc,
      'SELECT COUNT(*) AS cnt
         FROM cars
        WHERE (handled_by_job_id > 0 AND status != "Unavailable")
           OR current_location_id > 0'
    );
    if ($rs && ($row = mysqli_fetch_array($rs)))
    {
      return (int) $row['cnt'];
    }

    return 0;
  }

  // jobs
  function drop_down_jobs($list_name, $tab_index, $on_click, $pending_count_mode = false)
  {
    // get a database connection
    $dbc = open_db();

    // build the query to pull in all of the jobs
    $sql = 'select id, name from jobs order by name';

    // retrieve the rows and put them into an array
    $rs = mysqli_query($dbc, $sql);

    $pending_counts = [];
    if ($pending_count_mode)
    {
      $pending_counts = pending_counts_by_job($dbc, $pending_count_mode);
    }

    if ((isset($on_click) && strlen($on_click) > 0))
    {
      // $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" onclick="' . $on_click . '">';
      $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '" onchange="' . $on_click . '">';
    }
    else
    {
      $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '">';
    }
    $select_string .= '<option value=""></option>';

    if (mysqli_num_rows($rs) > 0)
    {
      while ($row = mysqli_fetch_array($rs))
      {
        $label = $row['name'];
        if ($pending_count_mode)
        {
          $count = isset($pending_counts[$row['id']]) ? $pending_counts[$row['id']] : 0;
          if ($count > 0)
          {
            $label .= ' (' . $count . ')';
          }
        }
        $select_string .= '<option value="' . $row['id'] . '">' . $label . '</option>';
      }
    }

    $select_string .= '</select>';
    return $select_string;
  }

  ///////////////////////////////////////////////////////////////////////

  // car_orders
  function drop_down_car_orders($list_name, $tab_index)
  {
    // get a database connection
    $dbc = open_db();

    // build the query to pull in the filled car orders
    $sql = 'select car_orders.waybill_number as waybill_number
              from car_orders, cars
             where car_orders.car = cars.id
               and cars.current_location_id > 0
             order by waybill_number';

    // retrieve the rows and put them into an html <select> block
    $rs = mysqli_query($dbc, $sql);

    $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '">';
    $select_string .= '<option value=""></option>';

    if (mysqli_num_rows($rs) > 0)
    {
      while ($row = mysqli_fetch_array($rs))
      {
        $select_string .= '<option value="' . $row[0] . '">' . $row[0] . '</option>';
      }
    }
    else
    {
      $select_string = "None";
      return $select_string;
    }

    $select_string .= "</select>";
    return $select_string;
  }

  ///////////////////////////////////////////////////////////////////////

  // commodities
  function drop_down_commodities($list_name, $tab_index)
  {
    // get a database connection
    $dbc = open_db();

    // build the query to pull in the commodity information
    $sql = 'select id, code from commodities order by code';

    // retrieve the rows and put them into an html <select> block
    $rs = mysqli_query($dbc, $sql);

    $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '" style="width: 100px;">';
    $select_string .= '<option value=""></option>';

    if (mysqli_num_rows($rs) > 0)
    {
      while ($row = mysqli_fetch_array($rs))
      {
        $select_string .= '<option value="' . $row['id'] . '">' . $row['code'] . '</option>';
      }
    }

    $select_string .= "</select>";
    return $select_string;
  }
  ///////////////////////////////////////////////////////////////////////

  // colors
  function drop_down_colors($list_name, $tab_index)
  {
    $select_string = '<select id="' . $list_name . '" name="' . $list_name . '" tabindex="' . $tab_index . '">
                        <option>None</option>
                        <option value="pink" style="color: black; background-color: pink;">Pink</option>
                        <option value="red" style="color: white; background-color: red;">Red</option>
                        <option value="orange" style="color: white; background-color: orange;">Orange</option>
                        <option value="yellow" style="color: black; background-color: yellow;">Yellow</option>
                        <option value="green" style="color: white; background-color: green;">Green</option>
                        <option value="lightblue" style="color: black; background-color: lightblue">Light Blue</option>
                        <option value="mediumblue" style="color: white; background-color: mediumblue;">Medium Blue</option>
                        <option value="purple" style="color: white; background-color: purple;">Purple</option>
                        <option value="lightgrey" style="color: black; background-color: lightgrey;">Grey</option>
                        <option value="black" style="color: white; background-color: black;">Black</option>
                      </select>';
    return $select_string;
  }
  
?>
