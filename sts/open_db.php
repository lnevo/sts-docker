<?php
  // standard routine to open a connection to the sts database
  // the connection to the database is stored in $dbc

  function open_db()
  {
    static $dbc = null;

    if ($dbc instanceof mysqli) {
      if (@mysqli_ping($dbc)) {
        return $dbc;
      }
      $dbc = null;
    }

    // bring in the credentials
    require 'credentials.php';

    // disable new error reporting setting
    mysqli_report(MYSQLI_REPORT_OFF);
    
    // disable minor error and warning reporting in php
    error_reporting(E_ERROR);

    // open the connection
    $dbc = mysqli_connect($server_name, $user_name, $password, $db_name);

    // check to see if the connection worked
    if (!$dbc)
    {
      die('Connection to sts_user database failed: ' . mysqli_connect_error());
    }

    /*----------------------- make database adjustment here if necessary ----------------------------------------------*/

    // create the owners table if it doesn't exist
    $sql = 'create table if not exists owners (id int not null auto_increment primary key, name varchar(256), remarks varchar(256))';
    if (!mysqli_query($dbc, $sql))
    {
      print "Error creating owners table";
      die;
    }

    // let's see if the ownership table exists
    $sql = 'show tables like "ownership"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_row($rs);
    if ($row[0] == 'ownership')
    {
      // ownership table exists - check to see if it includes the on_off_rr column
      $sql = 'select * from ownership';
      $rs = mysqli_query($dbc, $sql);
      $row = mysqli_fetch_row($rs);
      $num_fields = mysqli_field_count($dbc);
//print "Number of fields in ownership table: " . $num_fields . '<br /><br />';
      if ($num_fields == 2)
      {
//print 'Altering table...<br />';
        // only two fields in the ownership table, so add the on_off_rr field
        $sql = 'alter table ownership add on_off_rr varchar(256)';
        if (!$rtncd = mysqli_query($dbc, $sql))
        {
          print 'Error - unable to alter table';
          die();
        }
      }
    }
    else
    {
      // ownership table doesn't exist so create it
      $sql = 'create table if not exists ownership (car_id int, owner_id int, on_off_rr varchar(256))';
      if (!$rtncd = mysqli_query($dbc, $sql))
      {
        print "Error creating ownership table";
        die();
      }
    }

    // create the special pool table if it doesn't exist
    $sql = 'create table if not exists pool (car_id int, shipment_id int)';
    if (!mysqli_query($dbc, $sql))
    {
      print "Error creating special pool table";
      die();
    }
    
    // create the pick up criteria table if it doesn't exist
    $sql = 'create table if not exists pu_criteria (id int not null auto_increment primary key,
            job_id int, step_nbr int, car_status varchar(256), commodity_id int, car_code_id int, dest_station_id int)';
    if (!mysqli_query($dbc, $sql))
    {
      print "Error creating pick up criteria table";
      die;
    }

    // check to see if the cars table already had the "block" column
    $block_col_found = false;
    $sql = 'describe cars';
    $rs = mysqli_query($dbc, $sql);
    if ($rs)
    {
      while($row = mysqli_fetch_array($rs))
      {
        if ($row[0] == 'block_id')
        {
          $block_col_found = true;
        }
      }
    }
    // if the column wasn't found, add it
    if (!$block_col_found)
    {
      $sql = 'alter table cars add block_id integer';
      if (!mysqli_query($dbc, $sql))
      {
        print 'Error - unable to add "block_id" column to cars table';
      }
    }

    // check to see if the "blocks" table exists and if it doesn't, create it
    // (this table is not being used at the moment, but it might be needed in the future)
    $sql = 'show tables like "blocks"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_row($rs);
    if ($row[0] != 'blocks')
    {
      $sql = 'create table if not exists blocks (id int, job_id int, seq_nbr int, code varchar(256), description varchar(256))';
      if (!mysqli_query($dbc, $sql))
      {
        print "Error creating blocks table";
        die();
      }
    }
    
    // check to see if the "last_spotted" column is in the cars table
    $last_spotted_found = false;
    $sql = 'describe cars';
    $rs = mysqli_query($dbc, $sql);

    if ($rs)
    {
      while($row = mysqli_fetch_array($rs))
      {
        if ($row[0] == 'last_spotted')
        {
          $last_spotted_found = true;
        }
      }
    }
    
    // if the column wasn't found, add it
    if (!$last_spotted_found)
    {
      $sql = 'alter table cars add last_spotted integer';
      if (!mysqli_query($dbc, $sql))
      {
        print 'Error - unable to add "last_spotted" column to cars table';
      }
    }

    // check to see if the "min_load_time", "max_load_time", "min_unload_time", and "max_unload_time" columns
    // are in the shipment table
    $min_max_cols_found = false;
    $sql = 'describe shipments';
    $rs = mysqli_query($dbc, $sql);
    while ($row = mysqli_fetch_array($rs))
    {
      if ($row[0] == 'min_load_time')
      {
        $min_max_cols_found = true;
      }
    }
    // if the min_load_time column was not found, we will guess that the other three are not there either so try to add all five
    if (!$min_max_cols_found)
    {
      $sql = 'alter table shipments add column min_load_time int';
      if (!mysqli_query($dbc, $sql))
      {
        print 'Error adding min_load_time to shipment table';
        die();
      }
      
      $sql = 'alter table shipments add column max_load_time int';
      if (!mysqli_query($dbc, $sql))
      {
        print 'Error adding max_load_time to shipment table';
        die();
      }
      
      $sql = 'alter table shipments add column min_unload_time int';
      if (!mysqli_query($dbc, $sql))
      {
        print 'Error adding min_unload_time to shipment table';
        die();
      }
      
      $sql = 'alter table shipments add column max_unload_time int';
      if (!mysqli_query($dbc, $sql))
      {
        print 'Error adding max_unload_time to shipment table';
        die();
      }
    }
    
    // create the car history table if it doesn't exist
    $sql = 'create table if not exists history (car_id int, session_nbr int, event_date datetime, event varchar(256), location int)';
    if (!mysqli_query($dbc, $sql))
    {
      print "Error creating history table";
      die();
    }
    
    // set the maximum history entries per car if it doesn't exist
    $sql = 'select count(*) from settings where setting_name = "max_history"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    if ($row[0] == 0)
    {
      $sql = 'insert into settings values ("max_history", "Max History Entries Per Car", 24)';
      if (!mysqli_query($dbc, $sql))
      {
        print 'Error adding max history setting';
        die();
      }
    }
 
    // remove car history records that exceed the maximum number of entries per car
    // get the number of history records allowed per car
    $sql = 'select setting_value from settings where setting_name = "max_history"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $max_history = $row['setting_value'];
    
    // walk through each car in the history file
    $sql = 'select distinct car_id, session_nbr from history group by car_id';
    $rs = mysqli_query($dbc, $sql);
    while ($row = mysqli_fetch_array($rs))
    {
      // find out how many history records exist for each car
      $sql2 = 'select count(*) from history where car_id = ' . $row['car_id'];
      $rs2 = mysqli_query($dbc, $sql2);
      $row2 = mysqli_fetch_array($rs2);
      $history_count = $row2[0];
      if ($history_count > $max_history)
      {
        $excess = $history_count - $max_history;        
        // sort the car history records in ascending order based on operating session number
        // and delete all but the newest (highest) number as specified by the max history setting
        $sql3 = 'delete from history where car_id = ' . $row['car_id'] . ' order by session_nbr asc limit ' . $excess;
        if (!mysqli_query($dbc, $sql3))
        {
          print 'Error deleting excess history records';
          die();
        }
      }
    }
 
    // return the database connection
    return $dbc;
  }
?>
