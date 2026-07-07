<html>
  <head>
  <!-- Ron's wonderful menuing system -->
    <title>STS - Edit Database</title>
    <style>
      body {font: normal 20px Verdana, Arial, sans-serif;}
      table {border-collapse: collapse;}
      tr {vertical-align: top}
      th {border: 1px solid black; padding: 10px}
      td {border: 1px solid black; padding: 10px}
    </style>
  </head>
  <body>
<p><img src="ImageStore/GUI/Menu/manage.jpg" width="718" height="146" border="0" usemap="#Map5">
  <map name="Map5">
    <area shape="rect" coords="569,4,708,48" href="index.html">
    <area shape="rect" coords="569,97,711,140" href="index-t.html">
    <area shape="rect" coords="569,52,709,91" href="database.html">
  </map>
</p>

<?php
  // return link
  print '<a href="db_edit.php?tbl_name=jobs&obj_id=' . $_GET['job_id'] . '&obj_name=' . $_GET['job_name'] . '">
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
  print 'Job ' . $_GET['job_name'] . ' Step ' . $_GET['step_nbr'] . ' Station: ' . $_GET['station_id'] . '<br /><br />';

  if ($_GET['setout'] == 'T')
  {
    print 'Set out: YES &nbsp;';
  }
  else
  {
    print 'Set out: NO &nbsp;';
  }
  
  if ($_GET['pickup'] == 'T')
  {
    print 'Pick up: YES';
  }
  else
  {
    print 'Pick up: NO';
  }

  if ($_GET['pickup'] != 'T')
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
    if (intval($_GET['pickup_id']) > 0)
    {
      // update the existing criteria
      $sql = "update pu_criteria set ";
      
      $status_update = '';
      if (strlen(trim($_GET['car_status'])) > 0)
      {
        $status_update = 'car_status = "' . $_GET['car_status'] . '", ';
      }
      
      $commodity_update = '';
      if (is_numeric($_GET['commodity_id']))
      {
        $commodity_update = 'commodity_id = ' . $_GET['commodity_id'] . ', ';
      }
      
      $car_code_update = '';
      if (is_numeric($_GET['car_code_id']))
      {
        $car_code_update = 'car_code_id = ' . $_GET['car_code_id'] . ', ';
      }
      
      $dest_station_update = '';
      if (is_numeric($_GET['dest_station_id']))
      {
        $dest_station_update = 'dest_station_id = ' . $_GET['dest_station_id'] . ', ';
      }
      
      // assemble the sql statement; scope update to the selected pickup row only
      $sql .= $status_update . $commodity_update . $car_code_update . $dest_station_update;
      $sql = rtrim($sql, ', ');
      $sql .= ' where id = ' . intval($_GET['pickup_id']);
//print 'SQL: ' . $sql . '<br /><br />';
      if(!mysqli_query($dbc, $sql))
      {
        print '<br /><b>SQL Error</b> SQL: ' . $sql . '<br /><br />';
      }
    }
    else
    {
      // insert a new criteria record
      $sql = 'insert into pu_criteria (id, job_id, step_nbr, car_status, commodity_id, car_code_id, dest_station_id)
              values (NULL, "' . $_GET['job_name'] . '", "' . $_GET['step_nbr'] . '", "' . $_GET['car_status'] . '", "' .
                      $_GET['commodity_id'] . '", "' . $_GET['car_code_id'] . '", "' . $_GET['dest_station_id'] . '")';
//print 'SQL: ' . $sql . '<br /><br />';
      if(!mysqli_query($dbc, $sql))
      {
        print '<br /><b>SQL Error</b> SQL: ' . $sql . '<br /><br />';
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
    if (intval($_GET['pickup_id']) > 0)
    {
      $sql = 'delete from pu_criteria where id = "' . $_GET['pickup_id'] . '"';
//print 'SQL: ' . $sql . '<br /><br />';
      if (!mysqli_query($dbc, $sql))
      {
        print '<br /><b>SQL Error</b> SQL: ' . $sql . '<br /><br />';
      }
    }
  }

  // set up the update form
  print '<form name="pu_criteria" method="get" action="pu_criteria.php">';

  // create some hidden fields so we can get back here if we call ourselves
  print '<input type="hidden" name="job_id" value="' . $_GET['job_id'] . '">';
  print '<input type="hidden" name="job_name" value="' . $_GET['job_name'] . '">';
  print '<input type="hidden" name="step_nbr" value="' . $_GET['step_nbr'] . '">';
  print '<input type="hidden" name="setout" value="' . $_GET['setout'] . '">';
  print '<input type="hidden" name="pickup" value="' . $_GET['pickup'] . '">';
  print '<input type="hidden" name="station_id" value="' . $_GET['station_id'] . '">';

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
          where pu_criteria.job_id = "' . $_GET['job_name'] . '"
            and pu_criteria.step_nbr = "' . $_GET['step_nbr'] . '"';
//print 'SQL: ' . $sql . '<br /><br />';
  $rs = mysqli_query($dbc, $sql);
//print 'Num Rows: ' . mysqli_num_rows($rs) . '<br /><br />';  
  // if no records were returned, that means no existing criteria was found
  // otherwise populate the non-empty fields
  if (mysqli_num_rows($rs) > 0)
  {
    $row = mysqli_fetch_array($rs);
    
    // save the pickup criteria id in case we need it in the future
    print '<input type="hidden" name="pickup_id" value="' . $row['pickup_id'] . '">';
    
    // build a table with three columns and three rows
    // display the current values and bring in the drop-down lists
    print '<table>';
    print '<tr><th>Criteria</th><th>Current Value</th><th>New Value</th></tr>';
    print '<tr>
             <td>Car Status</td>
             <td>' . $row['car_status'] . '</td>
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
             <td>' . $row['commodity'] . '</td>
             <td>' . drop_down_commodities("commodity_id", 1) . '</td>
           </tr>';
    print '<tr>
             <td>Car Code</td>
             <td>' . $row['car_code'] . '</td>
             <td>' . drop_down_car_codes("car_code_id", 2, "wild_ok") . '</td>
           </tr>';
    print '<tr>
             <td>Destination Station</td>
             <td>' . $row['dest_station'] . '</td>
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
  print '<input type="submit" name="update_btn" value="UPDATE">&nbsp;&nbsp;';
  print '<input type="submit" name="remove_btn" value="REMOVE">';

  print '</form>';
?>
