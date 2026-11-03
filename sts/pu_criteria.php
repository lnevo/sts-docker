<?php
require_once 'page_chrome.php';
require_once 'sql_safe.php';
sts_page_start(['title' => 'Pick Up Criteria', 'icon' => 'bi-funnel', 'section' => 'data', 'back_href' => '']);
?>
<div class="sts-card">
<?php
  // return link
  print '<a href="db_edit.php?tbl_name=jobs&obj_id=' . urlencode(sts_get('job_id')) . '&obj_name=' . urlencode(sts_get('job_name')) . '">
           Return to Edit Job page.
         </a>';
?>
<h3>Add and/or Remove Pick Up Criteria</h3>

<?php
  // pu_criteria.php
  // adds and removes pick up criteria from the job step that linked to this screen

  // bring in the function files
  require 'open_db.php';
  require 'drop_down_list_functions.php';

  // display info about the job and step so the user knows to which one the criteria will be added
  print 'Job ' . sts_html(sts_get('job_name')) . ' Step ' . sts_html(sts_get('step_nbr')) . ' Station: ' . sts_html(sts_get('station_id')) . '<br /><br />';

  if (sts_get('setout') == 'T')
  {
    print 'Set out: YES &nbsp;';
  }
  else
  {
    print 'Set out: NO &nbsp;';
  }
  
  if (sts_get('pickup') == 'T')
  {
    print 'Pick up: YES';
  }
  else
  {
    print 'Pick up: NO';
  }

  if (sts_get('pickup') != 'T')
  {
    print '<br /><br />This job/train is not set to pick up any cars at this station.';
  }
  else
  {
    print '<br /><br />Select criteria and click UPDATE to save changes or REMOVE to clear the criteria for this step<br /><br />';
  }

  // get a database connection
  $dbc = open_db();

  // check to see if the update button was clicked
  if (isset($_GET['update_btn']))
  {
//print '<b>Update Button Clicked</b><br /><br />';
//print 'Pickup ID: ' . $_GET['pickup_id'] . '<br />';
//print 'Car Status: ' . $_GET['car_status'] . '<br />';
//print 'Commodity ID: ' . $_GET['commodity_id'] . '<br />';
//print 'Car Code ID: ' . $_GET['car_code_id'] . '<br />';
//print 'Dest Station ID: ' . $_GET['dest_station_id'] . '<br /><br />';

    // check to see if there is an existing pickup_id
    // if so, update the record, otherwise create a new record
    if (intval(sts_get('pickup_id')) > 0)
    {
      // update the existing criteria
      $sql = "update pu_criteria set ";
      
      $upd_params = array();
      $status_update = '';
      if (strlen(trim(sts_get('car_status'))) > 0)
      {
        $status_update = 'car_status = ?, ';
        $upd_params[] = sts_get('car_status');
      }
      
      $commodity_update = '';
      if (is_numeric(sts_get('commodity_id')))
      {
        $commodity_update = 'commodity_id = ?, ';
        $upd_params[] = sts_get('commodity_id') + 0;
      }
      
      $car_code_update = '';
      if (is_numeric(sts_get('car_code_id')))
      {
        $car_code_update = 'car_code_id = ?, ';
        $upd_params[] = sts_get('car_code_id') + 0;
      }
      
      $dest_station_update = '';
      if (is_numeric(sts_get('dest_station_id')))
      {
        $dest_station_update = 'dest_station_id = ?, ';
        $upd_params[] = sts_get('dest_station_id') + 0;
      }
      
      // assemble the sql statement; scope update to the selected pickup row only
      $sql .= $status_update . $commodity_update . $car_code_update . $dest_station_update;
      $sql = rtrim($sql, ', ');
      $sql .= ' where id = ?';
      $upd_params[] = intval(sts_get('pickup_id'));
//print 'SQL: ' . $sql . '<br /><br />';
      if(!sts_q($dbc, $sql, $upd_params))
      {
        print '<br /><b>SQL Error</b> SQL: ' . sts_html($sql) . '<br /><br />';
      }
    }
    else
    {
      // insert a new criteria record
      $sql = 'insert into pu_criteria (id, job_id, step_nbr, car_status, commodity_id, car_code_id, dest_station_id)
              values (NULL, ?, ?, ?, ?, ?, ?)';
//print 'SQL: ' . $sql . '<br /><br />';
      if(!sts_q($dbc, $sql, array(sts_get('job_name'), sts_get('step_nbr'), sts_get('car_status'), sts_get('commodity_id'), sts_get('car_code_id'), sts_get('dest_station_id'))))
      {
        print '<br /><b>SQL Error</b> SQL: ' . sts_html($sql) . '<br /><br />';
      }
    }
  }

  // check to see if the remove button was clicked
  if (isset($_GET['remove_btn']))
  {
//print '<b>Remove Button Clicked</b><br /><br />';
//print 'Pickup ID: ' . $_GET['pickup_id'] . '<br />';
//print 'Car Status: '. $_GET['car_status'] . '<br />';
//print 'Commodity ID: ' . $_GET['commodity_id'] . '<br />';
//print 'Car Code ID: ' . $_GET['car_code_id'] . '<br />';
//print 'Dest Station ID: ' . $_GET['dest_station_id'] . '<br /><br />';

    // if we have a pickup id, delete that record, otherwise don't do anything
    if (intval(sts_get('pickup_id')) > 0)
    {
      $sql = 'delete from pu_criteria where id = ?';
//print 'SQL: ' . $sql . '<br /><br />';
      if (!sts_q($dbc, $sql, array(intval(sts_get('pickup_id')))))
      {
        print '<br /><b>SQL Error</b> SQL: ' . sts_html($sql) . '<br /><br />';
      }
    }
  }

  // set up the update form
  print '<form name="pu_criteria" method="get" action="pu_criteria.php">';

  // create some hidden fields so we can get back here if we call ourselves
  print '<input type="hidden" name="job_id" value="' . sts_html(sts_get('job_id')) . '">';
  print '<input type="hidden" name="job_name" value="' . sts_html(sts_get('job_name')) . '">';
  print '<input type="hidden" name="step_nbr" value="' . sts_html(sts_get('step_nbr')) . '">';
  print '<input type="hidden" name="setout" value="' . sts_html(sts_get('setout')) . '">';
  print '<input type="hidden" name="pickup" value="' . sts_html(sts_get('pickup')) . '">';
  print '<input type="hidden" name="station_id" value="' . sts_html(sts_get('station_id')) . '">';

  // fetch the pickup criteria for this step
  $sql = 'select pu_criteria.id as pickup_id,
                 pu_criteria.car_status as car_status,
                 commodities.code as commodity,
                 car_codes.code as car_code,
                 routing.station as dest_station
          from pu_criteria
          left join commodities on pu_criteria.commodity_id = commodities.Id
          left join car_codes on pu_criteria.car_code_id = car_codes.Id
          left join routing on pu_criteria.dest_station_id = routing.id
          where pu_criteria.job_id = ?
            and pu_criteria.step_nbr = ?';
//print 'SQL: ' . $sql . '<br /><br />';
  $rs = sts_q($dbc, $sql, array(sts_get('job_name'), sts_get('step_nbr')));
//print 'Num Rows: ' . mysqli_num_rows($rs) . '<br /><br />';  
  // if no records were returned, that means no existing criteria was found
  // otherwise populate the non-empty fields
  if (mysqli_num_rows($rs) > 0)
  {
    $row = mysqli_fetch_array($rs);
    
    // save the pickup criteria id in case we need it in the future
    print '<input type="hidden" name="pickup_id" value="' . sts_html($row['pickup_id']) . '">';
    
    // build a table with three columns and three rows
    // display the current values and bring in the drop-down lists
    print '<table>';
    print '<tr><th>Criteria</th><th>Current Value</th><th>New Value</th></tr>';
    print '<tr>
             <td>Car Status</td>
             <td>' . sts_html($row['car_status']) . '</td>
             <td>
               <select name="car_status" id="car_status">
                 <option value=""></option>
                 <option value="Loaded">Loaded</option>
                 <option value="Empty">Empty</option>
               </select>
             </td>
           </tr>';
    print '<tr>
             <td>Commodity</td>
             <td>' . sts_html($row['commodity']) . '</td>
             <td>' . drop_down_commodities("commodity_id", 1) . '</td>
           </tr>';
    print '<tr>
             <td>Car Code</td>
             <td>' . sts_html($row['car_code']) . '</td>
             <td>' . drop_down_car_codes("car_code_id", 2, "wild_ok") . '</td>
           </tr>';
    print '<tr>
             <td>Destination Station</td>
             <td>' . sts_html($row['dest_station']) . '</td>
             <td>' . drop_down_stations("dest_station_id", 3, "") . '</td>
           </tr>';
    print '</table>';
  }
  else
  {
    // we don't have a pickup criteria ID yet, so make it empty
    print '<input type="hidden" name="pickup_id" value="">';
    
    // build a table with three columns and three rows
    // leave the existing value cells blank but bring in the drop-down lists
    print '<table>';
    print '<tr><th>Criteria</th><th>Current Value</th><th>New Value</th></tr>';
    print '<tr>
             <td>Car Status</td>
             <td></td>
             <td>
               <select name="car_status" id="car_status">
                 <option value=""></option>
                 <option value="Loaded">Loaded</option>
                 <option value="Empty">Empty</option>
               </select>
             </td>
           </tr>';
    print '<tr>
             <td>Commodity</td>
             <td></td>
             <td>' . drop_down_commodities("commodity_id", 1) . '</td>
           </tr>';
    print '<tr>
             <td>Car Code</td>
             <td></td>
             <td>' . drop_down_car_codes("car_code_id", 2, "wild_ok") . '</td>
           </tr>';
    print '<tr>
             <td>Destination Station</td><td>
             </td>
             <td>' . drop_down_stations("dest_station_id", 3, "") . '</td>
           </tr>';
    print '</table>';
  }
  
  // update and remove buttons do a recursive call on this page and all of the form
  // values are passed through when the page loads
  print '<br />';
  print '<input type="submit" name="update_btn" value="UPDATE" class="btn btn-primary btn-sm">&nbsp;&nbsp;';
  print '<input type="submit" name="remove_btn" value="REMOVE" class="btn btn-danger btn-sm">';

  print '</form>';
?>
</div>
<?php sts_page_end(); ?>
