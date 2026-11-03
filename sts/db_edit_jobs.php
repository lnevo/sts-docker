<?php
  // edit_jobs.php

  // edits the selected row in the jobs table if the Update button was clicked
  // it replaces only those items where a new value was submitted

  // it also edits the rows in the table associated with this job
  // rows can be added and updated; to remove a row it's index value is set to zerio

  

  // get a database connection
  $dbc = open_db();

  // initiate a database response message
  $sql_msg = '<br />Transaction completed';

  // job names double as step-table names (DDL cannot be parameterised), so a
  // name is only ever used as an identifier if it is an existing job (or, for a
  // rename, if it matches the strict pattern used when jobs are created)
  if (!function_exists('sts_job_exists'))
  {
    function sts_job_exists($dbc, $name)
    {
      if ($name === '') { return false; }
      $r = sts_q($dbc, 'select id from jobs where name = ?', array($name));
      return ($r && mysqli_num_rows($r) > 0);
    }
    function sts_job_name_ok($name)
    {
      return (bool) preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9 _.\-]{0,62}[A-Za-z0-9_.\-])?$/D', $name);
    }
  }

  // page output has already started by the time a job is removed, so a Location header
  // would be ignored: send it only if still possible, otherwise redirect from the page itself
  if (!function_exists('sts_redirect_to_job_list'))
  {
    function sts_redirect_to_job_list()
    {
      $target = 'db_list.php?tbl_name=jobs';
      if (!headers_sent())
      {
        header('Location: ' . $target);
        exit();
      }
      print '<meta http-equiv="refresh" content="0;url=' . $target . '">';
      print '<script>window.location.replace("' . $target . '");</script>';
      print '<p>Job removed. <a href="' . $target . '">Return to the Job Management page</a></p>';
      exit();
    }
  }

  $obj_name = sts_get('obj_name');
  $prev_obj_name = sts_get('prev_obj_name', null);

  // if a previous update was successful and the job's name was changed, rename it's table using the new name
  if ($prev_obj_name !== null && ($prev_obj_name != $obj_name))
  {
    if (!sts_job_exists($dbc, $prev_obj_name) || !sts_job_name_ok($obj_name))
    {
      $sql_msg = '<br />Rename Error: invalid job name';
    }
    else
    {
      $sql = 'rename table ' . sts_ident($prev_obj_name) . ' to ' . sts_ident($obj_name);
      if (!mysqli_query($dbc, $sql))
      {
        $sql_msg =  '<br />Rename Error: ' . sts_html(mysqli_error($dbc));
      }
    }
  }

  // has the submit button been clicked?
  if (isset($_GET['update_btn']))
  {
    // is this a remove operation?
    if (sts_get('update_remove_btn') == 'remove')
    {
      // build a query to remove the selected job
      $job_known = sts_job_exists($dbc, $obj_name);
      if (!sts_q($dbc, 'delete from jobs where name = ?', array($obj_name)))
      {
        $sql_msg =  '<br />Delete Error: ' . sts_html(sts_err($dbc));
        print $sql_msg;
      }
      else
      {
        // if the delete was successful, delete any pickup criteria and then remove this job's step table
        sts_q($dbc, 'delete from pu_criteria where job_id = ?', array($obj_name)); // don't need to check the result of this query since there might not be any records to remove

        // only an existing job's step table may be dropped
        if (!$job_known)
        {
          sts_redirect_to_job_list();
        }
        $sql = 'drop table ' . sts_ident($obj_name);
        if (!mysqli_query($dbc, $sql))
        {
          $sql_msg = '<br />Drop Error: ' . sts_html(mysqli_error($dbc));
          print $sql_msg;
        }
        else
        {
        // if the drop was successful, return to the list_jobs page
          sts_redirect_to_job_list();
        }
      }
    }
    else
    {
      // this must be an update operation
      // build the update query based on the contents of the input text boxes
      // first check the job name and description
      $sql = 'update jobs set ';
      $params = array();
      $first_field = true;

      // check each incoming input text box and add it to the update if there's something there
      if (strlen($obj_name) > 0)
      {
        $sql .= 'name = ? ';
        $params[] = $obj_name;
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

      // run the update query if at least one field is to be updated
      if (!$first_field)
      {
        $sql .= 'where name = ?';
        $params[] = sts_get('prev_obj_name');
        if (sts_q($dbc, $sql, $params))
        {
          $sql_msg =  '<br />Transaction completed';
        }
        else
        {
          $sql_msg =  '<br />Update Error: ' . sts_html(sts_err($dbc));
        }
      }

      // the step table is only touched if this is an existing job
      $step_table_ok = sts_job_exists($dbc, $obj_name);
      $step_table = sts_ident($obj_name);

      // next, check the step table
      if ($step_table_ok && intval(sts_get('row_count')) > 0)
      {
        // first, delete all of this job's step from it's step table
        // then go through each of the existing steps coming in from the web page
        // insert any step that doesn't have a sequence number of zero
        // including anything in the input text boxes

        // delete the existing steps
        $sql = 'delete from ' . $step_table;
        if (!mysqli_query($dbc, $sql))
        {
          $sql_msg = 'Delete error: ' . sts_html(mysqli_error($dbc));
        }
        else
        {
          // loop through the existing steps on the web page and put them back into this job's step table
          for ($i=0; $i<intval(sts_get('row_count')); $i++)
          {
            // construct the names of each of the input fields in this table row
            $step_nbr = 'step' . $i;
            $station_nbr = 'station' . $i;
            $pickup_nbr = 'pickup' . $i;
            $setout_nbr = 'setout' . $i;
            $step_rmks_nbr = 'step_remarks' . $i;

            // if the step sequence number is 0, skip it (this deletes it from the list of steps)
           if (intval(sts_get($step_nbr)) > 0)
           {
              // build the sql insert command
              $ok = sts_q($dbc, 'insert into ' . $step_table . ' values (?, ?, ?, ?, ?)',
                          array(intval(sts_get($step_nbr)), sts_get($station_nbr),
                                isset($_GET[$pickup_nbr]) ? 'T' : 'F',
                                isset($_GET[$setout_nbr]) ? 'T' : 'F',
                                sts_get($step_rmks_nbr)));
              if (!$ok)
              {
                $sql_msg = 'Step Insert Error: ' . sts_html(sts_err($dbc));
              }
            }
          }
        }
      }
      // finally check the input text boxes
      if ($step_table_ok && strlen(sts_get('seq_nbr')) > 0)
      {
        $ok = sts_q($dbc, 'insert into ' . $step_table . ' values (?, ?, ?, ?, ?)',
                    array(sts_get('seq_nbr'), sts_get('station'),
                          isset($_GET['pickup']) ? 'T' : 'F',
                          isset($_GET['setout']) ? 'T' : 'F',
                          sts_get('step_remarks')));
        if (!$ok)
        {
          $sql_msg = 'Insert error: ' . sts_html(sts_err($dbc));
        }
      }
    }
  }

  // generate a hidden field to send this form's table name to itself when it's refreshed
  print '<input id="tbl_name" name="tbl_name" value="jobs" type="hidden">';

  // generate a hidden field to send this form's previous object name to itself when it's refreshed
  print '<input id="prev_obj_name" name="prev_obj_name" value="' . sts_html($obj_name) . '" type="hidden">';

  // query the database for the properties of the selected job  and display them in a table
  $rs = sts_q($dbc, 'select id, name, description from jobs where id = ?', array(sts_id(sts_get('obj_id'))));
  $row = mysqli_fetch_array($rs);

  // generate a hidden field so send this form's previous object ID to itself when it's refreshed
  print '<input id="obj_id" name="obj_id" value="' . sts_html($row['id']) . '" type="hidden">';
  
  print
    '<table class="table table-sm table-bordered table-hover sts-table">
      <tr>
        <th>Property</th>
        <th>Value</th>
      </tr>
      <tr>
        <td>Job Name</td>
        <td><input class="form-control form-control-sm" id="obj_name" name="obj_name" type="text" value="' . sts_html($row['name']) . '" tabindex="1" autofocus></td>
      </tr>
      <tr>
        <td>Description</td>
        <td><textarea class="form-control form-control-sm" name="description" rows="8" cols="128" tabindex="2">' . sts_html($row['description']) . '</textarea></td>
      </tr>
    </table>';

  // query the database for the steps in this job's step table
  
  // the step table is only queried when the name belongs to an existing job
  $rs = false;
  if (sts_job_exists($dbc, $obj_name))
  {
    $t = sts_ident($obj_name);
    $sql = 'select ' . $t . '.step_number as step_number,
                   ' . $t . '.station as station_id,
                   ' . $t . '.pickup as pickup,
                   ' . $t . '.setout as setout,
                   ' . $t . '.remarks as remarks,
                   routing.station as station
              from ' . $t . ', routing
             where ' . $t . '.station = routing.id
             order by step_number';
    $rs = mysqli_query($dbc, $sql);
  }
  if (!$rs)
  {
    $rs = mysqli_query($dbc, 'select 1 as step_number from jobs where 1 = 0');
  }

  // save the row count in a hidden field for use later on
  print '<input id="row_count" name="row_count" value="' . mysqli_num_rows($rs) . '" type="hidden">';

  print '<br /><b>Job Steps</b><br /><br />';
  print 'To add a step, enter the appropriate information and click the UPDATE button.<br />';
  print 'To remove a step, set it\'s sequence number to 0 [Zero] and click the UPDATE button.<br /><br />';
  print 'The <b>+</b> symbol to the right of a station name indicates that the step has auto-assign pickup criteria<br />';
  print 'To add, modify or remove a step\'s auto-assign pickup criteria, click on it\'s station link<br /><br />';

  // generate another submit button
  print '<input class="btn btn-primary btn-sm" id="update_btn" name="update_btn" value="UPDATE" type="submit"><br /><br />';

  // generate a table of this job's steps
  print '<table class="table table-sm table-bordered table-hover sts-table">
           <tr>
             <th>Sequence<br />Number</th>
             <th>Station</th>
             <th>Set Out</th>
             <th>Pick Up</th>
             <th>Remarks</th>
           </tr>
           <tr>
           </tr>
             <td><input class="form-control form-control-sm" id="seq_nbr" name="seq_nbr" type="text" size="5" tabindex="3"></td>
             <td>' . drop_down_stations("station", "4", "") . '</td>
             <td style="text-align: center"><input class="form-check-input" id="setout" name="setout" value="T" type="checkbox" checked tabindex="5"></td>
             <td style="text-align: center"><input class="form-check-input" id="pickup" name="pickup" value="T" type="checkbox" checked tabindex="6"></td>
             <td><input class="form-control form-control-sm" id="step_remarks" name="step_remarks" type="text" size="64" tabindex="7"></td>
           </tr>';

  // keep track of how many rows are on in the step table on the web page
  $row_count=0;
  $tab_count=8;

  // build a new table of step
  while ($row = mysqli_fetch_array($rs))
  {
    // set up the tab index for each field in each row
    $tabindex1 = $tab_count;
    $tabindex2 = $tab_count + 1;
    $tabindex3 = $tab_count + 2;
    $tabindex4 = $tab_count + 3;
    
    // check to see if this step has auto-assign pick up criteria
    $sql2 = 'select id from pu_criteria where job_id = "' . mysqli_real_escape_string($dbc, $obj_name) . '" and step_nbr = "' . intval($row['step_number']) . '"';
//print 'SQL: ' . $sql2 . '<br /><br >';
    $result = mysqli_query($dbc, $sql2);
//print 'Num Rows: ' . mysqli_num_rows($result) . '<br /><br />';
    if (mysqli_num_rows($result) > 0)
    {
      $pickup_flag = '&nbsp;&nbsp;<b>+</b>';
    }
    else
    {
      $pickup_flag = '';
    }
    
    print '<tr>';
    print '<td><input class="form-control form-control-sm" name="step' . $row_count . '" type="text" value="' . sts_html($row['step_number']) . '" size="5" tabindex="' . $tabindex1 . '"></td>';

    print '<td>
             <a href="pu_criteria.php?job_id=' . urlencode(sts_get('obj_id')) . '&job_name=' . urlencode($obj_name) . '&step_nbr=' . urlencode($row['step_number']) .
             '&setout=' . urlencode($row['setout']) . '&pickup=' . urlencode($row['pickup']) . '&station_id=' . urlencode($row['station']) . '">
             ' . sts_html($row['station']) . $pickup_flag . '</a>
             <input name="station' . $row_count . '" type="hidden" value="' . sts_html($row['station_id']) . '">
           </td>';
          
    print '<td style="text-align: center">';
    if ($row['setout'] == "T")
    {
      print '<input class="form-check-input" name="setout' . $row_count . '" value="T" type="checkbox" checked tabindex="' . $tabindex2 . '">';
    }
    else
    {
      print '<input class="form-check-input" name="setout' . $row_count . '" value="F" type="checkbox" tabindex="' . $tabindex2 . '">';
    }
    print '</td>';
    print '<td style="text-align: center">';
    if ($row['pickup'] == "T")
    {
      print '<input class="form-check-input" name="pickup' . $row_count . '" value="T" type="checkbox" checked tabindex="' . $tabindex3 . '">';
    }
    else
    {
      print '<input class="form-check-input" name="pickup' . $row_count . '" value="F" type="checkbox" tabindex="' . $tabindex3 . '">';
    }
    print '</td>';
    print '<td><input class="form-control form-control-sm" name="step_remarks' . $row_count . '" type="text" value="' . sts_html($row['remarks']) . '" size="80" tabindex="' . $tabindex4 . '"></td>';
    print '</tr>';
    $row_count++;
    $tab_count = $tab_count + 4;
  }
  print '</table>';

  // generate a javascript line to set focus on the first input text box
  print '<script>document.getElementById("obj_name").focus();</script>';

  // display a status message
  print $sql_msg;

?>
