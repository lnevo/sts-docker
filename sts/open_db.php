<?php
  require_once __DIR__ . '/sql_safe.php';
  // standard routine to open a connection to the sts database
  // the connection to the database is stored in $dbc

  // Failed-query logging. Page output must not change, so nothing here prints;
  // everything goes to the PHP error log (stderr -> Apache error log / docker logs).
  if (!function_exists('sts_log'))
  {
    function sts_log($msg)
    {
      error_log('[sts] ' . $msg);
    }
  }

  // Log a failed query with caller file:line, errno, error and the first 300 chars of the SQL.
  if (!function_exists('sts_db_log_error'))
  {
    function sts_db_log_error($dbc, $sql)
    {
      $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
      $f = $bt[count($bt) - 1];
      $where = basename($f['file'] ?? '?') . ':' . ($f['line'] ?? 0);
      sts_log('db error at ' . $where . ' errno=' . mysqli_errno($dbc) . ' error=' . mysqli_error($dbc)
        . ' sql=' . substr(preg_replace('/\s+/', ' ', (string) $sql), 0, 300));
    }
  }

  // Drop-in for mysqli_query($dbc, $sql): same return value, logs on failure.
  // Currently used only by open_db.php's own migrations; use it in new code
  // that wants failures visible in the log (mysqli_report stays OFF).
  if (!function_exists('sts_query'))
  {
    function sts_query($dbc, $sql)
    {
      $rs = mysqli_query($dbc, $sql);
      if ($rs === false)
      {
        sts_db_log_error($dbc, $sql);
      }
      return $rs;
    }
  }

  function open_db()
  {
    static $dbc = null;

    if ($dbc instanceof mysqli) {
      // A cached handle may have been closed elsewhere (e.g. mysqli_close in a
      // page/helper). On PHP 8.1+ calling mysqli_ping() on a closed connection
      // throws an Error rather than returning false, so guard it and reconnect.
      try {
        if (@mysqli_ping($dbc)) {
          return $dbc;
        }
      } catch (\Throwable $e) {
        // fall through and open a fresh connection below
      }
      $dbc = null;
    }

    // bring in the credentials
    require 'credentials.php';

    // disable new error reporting setting
    mysqli_report(MYSQLI_REPORT_OFF);
    
    // Displayed errors stay limited to fatals (page output unchanged), but
    // everything except deprecations is logged to stderr instead of vanishing.
    error_reporting(E_ERROR);
    ini_set('log_errors', '1');
    ini_set('error_log', 'php://stderr');
    set_error_handler(function ($no, $str, $file, $line) {
      // @-suppressed calls (error_reporting() == 4437 in PHP 8) are expected noise
      if (error_reporting() === 4437 || !($no & (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED))) {
        return true;
      }
      sts_log('php error ' . $no . ': ' . $str . ' in ' . basename($file) . ':' . $line);
      return true;
    });

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
    if (!sts_query($dbc, $sql))
    {
      sts_log("Error creating owners table");
      die;
    }

    // let's see if the ownership table exists
    $sql = 'show tables like "ownership"';
    $rs = sts_query($dbc, $sql);
    $row = mysqli_fetch_row($rs);
    if ($row[0] == 'ownership')
    {
      // ownership table exists - check to see if it includes the on_off_rr column
      $sql = 'select * from ownership';
      $rs = sts_query($dbc, $sql);
      $row = mysqli_fetch_row($rs);
      $num_fields = mysqli_field_count($dbc);
//print "Number of fields in ownership table: " . $num_fields . '<br /><br />';
      if ($num_fields == 2)
      {
//print 'Altering table...<br />';
        // only two fields in the ownership table, so add the on_off_rr field
        $sql = 'alter table ownership add on_off_rr varchar(256)';
        if (!$rtncd = sts_query($dbc, $sql))
        {
          sts_log('Error - unable to alter table');
          die();
        }
      }
    }
    else
    {
      // ownership table doesn't exist so create it
      $sql = 'create table if not exists ownership (car_id int, owner_id int, on_off_rr varchar(256))';
      if (!$rtncd = sts_query($dbc, $sql))
      {
        sts_log("Error creating ownership table");
        die();
      }
    }

    // create the special pool table if it doesn't exist
    $sql = 'create table if not exists pool (car_id int, shipment_id int)';
    if (!sts_query($dbc, $sql))
    {
      sts_log("Error creating special pool table");
      die();
    }
    
    // create the pick up criteria table if it doesn't exist
    $sql = 'create table if not exists pu_criteria (id int not null auto_increment primary key,
            job_id int, step_nbr int, car_status varchar(256), commodity_id int, car_code_id int, dest_station_id int)';
    if (!sts_query($dbc, $sql))
    {
      sts_log("Error creating pick up criteria table");
      die;
    }

    // check to see if the cars table already had the "block" column
    $block_col_found = false;
    $sql = 'describe cars';
    $rs = sts_query($dbc, $sql);
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
      if (!sts_query($dbc, $sql))
      {
        sts_log('Error - unable to add "block_id" column to cars table');
      }
    }

    // check to see if the "blocks" table exists and if it doesn't, create it
    // (this table is not being used at the moment, but it might be needed in the future)
    $sql = 'show tables like "blocks"';
    $rs = sts_query($dbc, $sql);
    $row = mysqli_fetch_row($rs);
    if ($row[0] != 'blocks')
    {
      $sql = 'create table if not exists blocks (id int, job_id int, seq_nbr int, code varchar(256), description varchar(256))';
      if (!sts_query($dbc, $sql))
      {
        sts_log("Error creating blocks table");
        die();
      }
    }
    
    // check to see if the "last_spotted" column is in the cars table
    $last_spotted_found = false;
    $sql = 'describe cars';
    $rs = sts_query($dbc, $sql);

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
      if (!sts_query($dbc, $sql))
      {
        sts_log('Error - unable to add "last_spotted" column to cars table');
      }
    }

    // check to see if the "min_load_time", "max_load_time", "min_unload_time", and "max_unload_time" columns
    // are in the shipment table
    $min_max_cols_found = false;
    $sql = 'describe shipments';
    $rs = sts_query($dbc, $sql);
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
      if (!sts_query($dbc, $sql))
      {
        sts_log('Error adding min_load_time to shipment table');
        die();
      }
      
      $sql = 'alter table shipments add column max_load_time int';
      if (!sts_query($dbc, $sql))
      {
        sts_log('Error adding max_load_time to shipment table');
        die();
      }
      
      $sql = 'alter table shipments add column min_unload_time int';
      if (!sts_query($dbc, $sql))
      {
        sts_log('Error adding min_unload_time to shipment table');
        die();
      }
      
      $sql = 'alter table shipments add column max_unload_time int';
      if (!sts_query($dbc, $sql))
      {
        sts_log('Error adding max_unload_time to shipment table');
        die();
      }
    }
    
    // create the car history table if it doesn't exist
    $sql = 'create table if not exists history (car_id int, session_nbr int, event_date datetime, event varchar(256), location int)';
    if (!sts_query($dbc, $sql))
    {
      sts_log("Error creating history table");
      die();
    }
    
    // set the maximum history entries per car if it doesn't exist
    $sql = 'select count(*) from settings where setting_name = "max_history"';
    $rs = sts_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    if ($row[0] == 0)
    {
      $sql = 'insert into settings values ("max_history", "Max History Entries Per Car", 24)';
      if (!sts_query($dbc, $sql))
      {
        sts_log('Error adding max history setting');
        die();
      }
    }
 
    // remove car history records that exceed the maximum number of entries per car
    // get the number of history records allowed per car
    $sql = 'select setting_value from settings where setting_name = "max_history"';
    $rs = sts_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $max_history = $row['setting_value'];
    
    // walk through each car in the history file
    $sql = 'select distinct car_id, session_nbr from history group by car_id';
    $rs = sts_query($dbc, $sql);
    while ($row = mysqli_fetch_array($rs))
    {
      // find out how many history records exist for each car
      $sql2 = 'select count(*) from history where car_id = ' . $row['car_id'];
      $rs2 = sts_query($dbc, $sql2);
      $row2 = mysqli_fetch_array($rs2);
      $history_count = $row2[0];
      if ($history_count > $max_history)
      {
        $excess = $history_count - $max_history;        
        // sort the car history records in ascending order based on operating session number
        // and delete all but the newest (highest) number as specified by the max history setting
        $sql3 = 'delete from history where car_id = ' . $row['car_id'] . ' order by session_nbr asc limit ' . $excess;
        if (!sts_query($dbc, $sql3))
        {
          sts_log('Error deleting excess history records');
          die();
        }
      }
    }
 
    // return the database connection
    return $dbc;
  }
?>
