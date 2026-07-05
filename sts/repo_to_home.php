<?php
  // Create car orders for empty non-billed cars not at home.
  // Accepts optional POST car_ids[] to limit repositioning to filtered cars.

  require 'open_db.php';

  $dbc = open_db();

  $sql = 'select setting_value from settings where setting_name = "session_nbr"';
  $rs = mysqli_query($dbc, $sql);
  if (mysqli_num_rows($rs) > 0)
  {
    $row = mysqli_fetch_row($rs);
    $session_number = $row[0];
  }
  else
  {
    $session_number = 0;
  }

  $sql = 'select waybill_number from car_orders where waybill_number like "' . str_pad($session_number, 3, '0', STR_PAD_LEFT) .  '-E__" order by waybill_number desc limit 1';
  $rs = mysqli_query($dbc, $sql);
  if (mysqli_num_rows($rs) > 0)
  {
    $row = mysqli_fetch_row($rs);
    $waybill_counter = substr($row[0], -2, 2) + 1;
  }
  else
  {
    $waybill_counter = 1;
  }

  $car_filter_sql = '';
  if (isset($_POST['car_ids']) && is_array($_POST['car_ids']))
  {
    $car_ids = array_values(array_filter(array_map('intval', $_POST['car_ids']), function($id) {
      return $id > 0;
    }));
    if (count($car_ids) === 0)
    {
      print '0';
      exit;
    }
    $car_filter_sql = ' and cars.id in (' . implode(',', $car_ids) . ')';
  }

  $sql = 'select cars.id as id,
                 cars.reporting_marks as reporting_marks,
                 cars.current_location_id as current_location_id,
                 cars.home_location as home_location_id
            from cars
           where status = "Empty"
             and not exists (select car_orders.car from car_orders where cars.id = car_orders.car)
             and cars.current_location_id != cars.home_location'
         . $car_filter_sql;

  $rs = mysqli_query($dbc, $sql);

  $row_count = 0;

  while($row = mysqli_fetch_array($rs))
  {
    $wb_nbr = str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-E' . str_pad($waybill_counter, 2, '0', STR_PAD_LEFT);

    $sql = 'insert into car_orders values ("' . $wb_nbr . '", "' . $row['home_location_id'] . '", "' . $row['id'] . '")';

    if (!mysqli_query($dbc, $sql))
    {
      print 'Insert Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql;
      exit;
    }

    $sql = 'update cars set status = "Ordered" where id = "' . $row['id'] . '"';

    if (!mysqli_query($dbc, $sql))
    {
      print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql;
      exit;
    }
    $row_count++;
    $waybill_counter++;
  }

  print $row_count;

?>
