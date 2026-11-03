<?php
  // edit_shipments.php

  // edits the selected row in the shipment table if the Update button was clicked
  // it replaces only those items where a new value was submitted

  

  // generate some javascript that will mark the shipment as being turned off by adding one million (1000000) to the last ship date
  print '<script>
           function set_ship_off()
           {
             var old_ship_date = parseInt(document.getElementById("old_last_ship_date").innerHTML, 10) || 0;
             document.getElementById("last_ship_date").value = old_ship_date + 1000000;
             document.getElementById("last_ship_date").readOnly = true;
           }
           
           function set_ship_on()
           {
             var old_ship_date = parseInt(document.getElementById("old_last_ship_date").innerHTML, 10) || 0;
             if (old_ship_date < 1000000)
             {
               old_ship_date = old_ship_date + 1000000;
             }
             document.getElementById("last_ship_date").value = old_ship_date - 1000000;
             document.getElementById("last_ship_date").readOnly = false;
           }
           
         </script>';

  // get a database connection
  $dbc = open_db();

  // initiate a database response message
  $sql_msg = '<br />Transaction completed';

  // has the submit button been clicked?
  if (isset($_GET['update_btn']))
  {
    // is this a remove operation?
    if (sts_get('update_remove_btn') == 'remove')
    {
      // build a query to remove the selected shipment
      $sql = 'delete from shipments where id = ' . sts_id(sts_get('obj_id')) . '';
      if (!mysqli_query($dbc, $sql))
      {
        $sql_msg = '<br />Delete Error: ' . sts_html(mysqli_error($dbc));
      }
      else
      {
        // if the delete was successful, return to the list_shipments page
        header('Location: db_list.php?tbl_name=shipments');
        exit();
      }
    }
    else
    {
      // this must be an update operation
      // build the update query based on the contents of the input text boxes
      $sql = 'update shipments set ';
      $params = array();
      $first_field = true;

      // check each incoming input text box and add it to the update if there's something there
      if (strlen(sts_get('obj_name')) > 0)
      {
        $sql .= 'code = ? ';
        $params[] = sts_get('obj_name');
        $first_field = false;
      }

      if (strlen(sts_get('description')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'description = ? ';
        $params[] = sts_get('description');
        $first_field = false;
      }

      if (strlen(sts_get('consignment')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'consignment = ? ';
        $params[] = sts_get('consignment');
        $first_field = false;
      }

      if (strlen(sts_get('car_code')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'car_code = ? ';
        $params[] = sts_get('car_code');
        $first_field = false;
      }

      if (strlen(sts_get('loading_location')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'loading_location = ? ';
        $params[] = sts_get('loading_location');
        $first_field = false;
      }

      if (strlen(sts_get('unloading_location')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'unloading_location = ? ';
        $params[] = sts_get('unloading_location');
        $first_field = false;
      }

      if (strlen(sts_get('last_ship_date')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'last_ship_date = ? ';
        $params[] = sts_get('last_ship_date');
        $first_field = false;
      }

      if (strlen(sts_get('min_interval')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'min_interval = ? ';
        $params[] = sts_get('min_interval');
        $first_field = false;
      }

      if (strlen(sts_get('max_interval')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'max_interval = ? ';
        $params[] = sts_get('max_interval');
        $first_field = false;
      }

      if (strlen(sts_get('min_amount')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'min_amount = ? ';
        $params[] = sts_get('min_amount');
        $first_field = false;
      }

      if (strlen(sts_get('max_amount')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'max_amount = ? ';
        $params[] = sts_get('max_amount');
        $first_field = false;
      }

      if (strlen(sts_get('special_instructions')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'special_instructions = ? ';
        $params[] = sts_get('special_instructions');
        $first_field = false;
      }

       if (strlen(sts_get('remarks')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'remarks = ? ';
        $params[] = sts_get('remarks');
        $first_field = false;
      }

       if (strlen(sts_get('min_load_time')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'min_load_time = ? ';
        $params[] = sts_get('min_load_time');
        $first_field = false;
      }

       if (strlen(sts_get('max_load_time')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'max_load_time = ? ';
        $params[] = sts_get('max_load_time');
        $first_field = false;
      }

       if (strlen(sts_get('min_unload_time')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'min_unload_time = ? ';
        $params[] = sts_get('min_unload_time');
        $first_field = false;
      }

       if (strlen(sts_get('max_unload_time')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'max_unload_time = ? ';
        $params[] = sts_get('max_unload_time');
        $first_field = false;
      }

      // run the update query if at least one field is to be updated
      if (!$first_field)
      {
        $sql .= 'where id = ?';
        $params[] = sts_id(urldecode(sts_get('obj_id')));
        if (sts_q($dbc, $sql, $params))
        {
          $sql_msg =  '<br />Transaction completed<br /><br />';
        }
        else
        {
          $sql_msg =  '<br />Update Error: ' . sts_html(sts_err($dbc));
        }
      }
    }
  }

  // generate a hidden field to send this form's table name to itself when it's refreshed
  print '<input id="tbl_name" name="tbl_name" value="shipments" type="hidden">';

  // query the database for the properties of the selected  car code and display them in a table
  $sql = 'select shipments.id as id,
                 shipments.code as code,
                 shipments.description as description,
                 shipments.consignment as consignment_id,
                 shipments.car_code as car_code_id,
                 shipments.loading_location as loading_location_id,
                 shipments.unloading_location as unloading_location_id,
                 shipments.last_ship_date as last_ship_date,
                 shipments.min_interval as min_interval,
                 shipments.max_interval as max_interval,
                 shipments.min_amount as min_amount,
                 shipments.max_amount as max_amount,
                 shipments.special_instructions as special_instructions,
                 shipments.remarks as remarks,
                 shipments.min_load_time as min_load_time,
                 shipments.max_load_time as max_load_time,
                 shipments.min_unload_time as min_unload_time,
                 shipments.max_unload_time as max_unload_time,
                 commodities.code as consignment,
                 car_codes.code as car_code,
                 loc01.code as loading_location,
                 loc02.code as unloading_location
            from shipments
       left join commodities on shipments.consignment = commodities.id
       left join car_codes on shipments.car_code = car_codes.id
       left join locations loc01 on shipments.loading_location = loc01.id
       left join locations loc02 on shipments.unloading_location = loc02.id
	         where shipments.id = ' . sts_id(sts_get('obj_id')) . ''; 
  $rs = mysqli_query($dbc, $sql);
  $row = mysqli_fetch_array($rs);

  // find out if the shipper is operating or not
  if ($row['last_ship_date'] > 1000000)
  {
    // if the last ship date is greater than one million, this shipment is off
    $ship = '';
    $no_ship = 'checked';
    $ship_status = 'OFF';
  }
  else
  {
    // if the last ship date is less than one million, this shipment is on
    $ship = 'checked';
    $no_ship = '';
    $ship_status = 'ON';
  }

  // generate a hidden field to send this form's previous object ID to itself when it's refreshed
  print '<input id="obj_id" name="obj_id" value="' . sts_id(sts_get('obj_id')) . '" type="hidden">';

  print
    '<table class="table table-sm table-bordered table-hover sts-table">
      <tr>
        <th>Property</th>
        <th>Current Value</th>
        <th>New Value</th>
      </tr>
      <tr>
        <td>Shipment ID</td>
        <td>' . sts_html($row['code']) . '</td>
        <td><input class="form-control form-control-sm" id="obj_name" name="obj_name" type="text"></td>
      </tr>
      <tr>
        <td>Description</td>
        <td>' . sts_html($row['description']) . '</td>
        <td><input class="form-control form-control-sm" name="description" type="text"></td>
      </tr>
      <tr>
        <td>Commodity</td>
        <td>' . sts_html($row['consignment']) . '</td>
        <td>' . drop_down_commodities('consignment', '') . '</td>
      </tr>
      <tr>
        <td>Car Code</td>
        <td>' . sts_html($row['car_code']) . '</td>
        <td>' . drop_down_car_codes('car_code', '', 'wild_ok') . '</td>
      </tr>
      <tr>
        <td>Loading Location</td>
        <td>' . sts_html($row['loading_location']) . '</td>
        <td>' . drop_down_locations('loading_location', '', '') . '</td>
      </tr>
      <tr>
        <td>Unloading Location</td>
        <td>' . sts_html($row['unloading_location']) . '</td>
        <td>' . drop_down_locations('unloading_location', '', '') . '</td>
      </tr>
      <tr>
        <td>Last Ship Date</td>
        <td id="old_last_ship_date">' . sts_html($row['last_ship_date']) . '</td>
        <td><input class="form-control form-control-sm" id="last_ship_date" name="last_ship_date" type="text"></td>
      </tr>
      <tr>
        <td>Min Interval</td>
        <td>' . sts_html($row['min_interval']) . '</td>
        <td><input class="form-control form-control-sm" name="min_interval" type="text"></td>
      </tr>
      <tr>
        <td>Max Interval</td>
        <td>' . sts_html($row['max_interval']) . '</td>
        <td><input class="form-control form-control-sm" name="max_interval" type="text"></td>
      </tr>
      <tr>
        <td>Min Amount</td>
        <td>' . sts_html($row['min_amount']) . '</td>
        <td><input class="form-control form-control-sm" name="min_amount" type="text"></td>
      </tr>
      <tr>
        <td>Max Amount</td>
        <td>' . sts_html($row['max_amount']) . '</td>
        <td><input class="form-control form-control-sm" name="max_amount" type="text"></td>
      </tr>
      <tr>
        <td>Special Instructions</td>
        <td>' . sts_html($row['special_instructions']) . '</td>
        <td><input class="form-control form-control-sm" name="special_instructions" type="text"></td>
      </tr>
      <tr>
        <td>Remarks</td>
        <td>' . sts_html($row['remarks']) . '</td>
        <td><input class="form-control form-control-sm" name="remarks" type="text"></td>
      </tr>
      <tr>
        <td>Shipment On/Off</td>
        <td id="ship_status" name="ship_status">' . $ship_status . '</td>
        <td>ON <input class="form-check-input" id="ship_on" name="ship_on_off" type="radio" value="True" onchange="set_ship_on(); " '. $ship .'>
            OFF <input class="form-check-input" id="ship_off" name="ship_on_off" type="radio" value="False" onchange="set_ship_off(); " ' . $no_ship . '>
      </tr>
      <tr>
        <td>Min Load Time</td>
        <td>' . sts_html($row['min_load_time']) . '</td>
        <td><input class="form-control form-control-sm" name="min_load_time" type="text"></td>
      </tr>
      <tr>
        <td>Max Load Time</td>
        <td>' . sts_html($row['max_load_time']) . '</td>
        <td><input class="form-control form-control-sm" name="max_load_time" type="text"></td>
      </tr>
      <tr>
        <td>Min Unload Time</td>
        <td>' . sts_html($row['min_unload_time']) . '</td>
        <td><input class="form-control form-control-sm" name="min_unload_time" type="text"></td>
      </tr>
      <tr>
        <td>Max Unload Time</td>
        <td>' . sts_html($row['max_unload_time']) . '</td>
        <td><input class="form-control form-control-sm" name="max_unload_time" type="text"></td>
      </tr>
    </table>';

  // get this shipment's prioritized empty locations, if any
  $sql = 'select empty_locations.priority as priority,
                 locations.code as location
            from empty_locations, locations
           where shipment = ' . sts_id(sts_get('obj_id')) . '
             and locations.id = empty_locations.location
           order by priority';
  $rs = mysqli_query($dbc, $sql);
  if (mysqli_num_rows($rs) > 0)
  {
    $link = 'db_edit.php?tbl_name=empty_locations&obj_id=' . urlencode(sts_get('obj_id')) . '&obj_name=' . urlencode(sts_get('obj_name'));

    print '<br />';
    print 'Prioritized Empty Car Search Locations - 
           Click <a href="' . $link . '">here</a> to modify the priorities<br /><br />';
    print '<table class="table table-sm table-bordered table-hover sts-table">
           <tr>
           <th>Priority</th>
           <th>Location</th>
           </tr>';
    while ($row = mysqli_fetch_array($rs))
    {
      print '<tr>
               <td>' . sts_html($row[0]) . '</td>
               <td>' . sts_html($row[1]) . '</td>
             </tr>';
    }
    print '</table>';
  }
  else
  {
    print '<br />';
    print 'This shipment does not have any prioritized empty car locations';
  }

  // display a status message
  print $sql_msg;

  // generate a javascript line to set focus on the first input text box
  print '<script>document.getElementById("obj_name").focus();</script>';

?>
