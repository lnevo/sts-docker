<?php
  // connect to the database
  require 'open_db.php';
  $dbc = open_db();
  
  print '<html><head><title>Convert Windows Table Names to Upper Case for Linux</title></head><body>';

  print 'Existing database tables<hr />';
  $sql = 'show tables';
  $rs = mysqli_query($dbc, $sql);
  while ($row = mysqli_fetch_array($rs))
  {
    print $row[0] . '<br />';
  }
  print '<hr />';
  
  // pull in a list of all the jobs
  $sql = 'select name from jobs';
  $rs = mysqli_query($dbc, $sql);

  // go through all of the job names and rename the corresponding table so it's in ALL CAPITAL LETTERS
  while ($row = mysqli_fetch_array($rs))
  {
    $lower_case_name = strtolower($row[0]);
    print 'Converting [' . $lower_case_name . '] to [' . $row[0] . ']<br /><br />';
    
    $sql2 = 'ALTER TABLE `' . $lower_case_name . '` RENAME TO `' . $row[0] . '`';
    print 'SQL: ' . $sql2 . '<br /><br />';
    if (mysqli_query($dbc, $sql2))
    {
      print '[' . $lower_case_name . '] renamed to [' . $row[0] . ']<br /><br />';
    }
    else
    {
      print 'Rename failed<br /><br />SQL:' . $sql . '<br /><br />'. mysqli_errno($dbc) .'<br /><br />';
    }
  }
?>