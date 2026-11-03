<?php
  // list_jobs.php

  // adds a new job to the jobs table if the Update button was
  // clicked and if there is a code in the job name text box.
  // also creates a table using the name of the job that will contain the job's steps

  // generate some javascript to display the table name, identify this table to the form, and set the update button's tab index
  print '<script>
           document.getElementById("tbl_name").value = "jobs";
           document.getElementById("update_btn").tabIndex = "3";
         </script>';

  // get a database connection
  $dbc = open_db();

  // initialize an sql message
  $sql_msg = '<br />Transaction Completed';

  // has the submit button been clicked?
  if (isset($_POST['update_btn']))
  {
    // yes, so check to see if there is anything in the input text boxes
    if (strlen(sts_post('name')) > 0)
    {
      $job_name = sts_post('name');

      // the job name also becomes a table name (DDL cannot be parameterised),
      // so only a conservative character set is accepted
      if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9 _.\-]{0,62}[A-Za-z0-9_.\-])?$/D', $job_name))
      {
        $sql_msg = '<br />Job names may only contain letters, numbers, spaces, underscores, periods and hyphens (64 characters maximum, starting with a letter or number).';
      }
      else
      {
        // create the new job step table
        $sql = 'create table ' . sts_ident($job_name) . ' ';
        $sql .='(step_number int primary key, station int, pickup char(1), setout char(1), remarks varchar(256))';

        if (!mysqli_query($dbc, $sql))
        {
          $sql_msg = 'SQL error: ' . sts_html(mysqli_error($dbc));
        }
        else
        {
          // add the new job to the jobs table
          sts_q($dbc, 'insert into jobs (name, description) values (?, ?)',
                array($job_name, sts_post('description')));
        }
      }
    }
  }

  // query the database for all of the jobs and display them in a table
  $sql = 'select id, name, description from jobs order by name';
  $rs = mysqli_query($dbc, $sql);

  print '<table class="table table-sm table-bordered table-hover sts-table">
           <caption class="sts-table-caption">Add New Job/Train</caption>
           <thead>
             <tr>
                <th>Job Name</th><th>Description</th>
             </tr>           
             <tr>
               <td><input class="form-control form-control-sm" id="name" name="name" type="text" tabindex="1"required></td>
               <td><textarea class="form-control form-control-sm" name="description" rows="8" cols="128" tabindex="2"></textarea></td>
             </tr>
             <tr>
               <td colspan="2" style="border:0px;">
             </tr>
             <tr style="position: sticky; top: 0; background-color: #F5F5F5">
                <th>Job Name</th><th>Description</th>
             </tr>
           </thead>';

  if (mysqli_num_rows($rs) > 0)
  {
    while ($row = mysqli_fetch_array($rs))
    {
      print '<tr>
               <td><a href="db_edit.php?tbl_name=jobs&obj_id=' . sts_html($row['id']) . '&obj_name=' . urlencode($row['name']) . '">' . sts_html($row['name']) . '</td>
               <td>' . nl2br(sts_html($row['description'])) . '</td>
             </tr>';
    }
  }
  print "</table>";

  // display a database status message
  print $sql_msg;

  // generate a javascript line to set focus on the first text box
  print '<script>document.getElementById("name").focus();</script>';

?>
