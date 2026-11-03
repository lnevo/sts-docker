<!DOCTYPE HTML>
<html>
  <head>
    <title>Display the instructions for the specified job</title>
  </head>
  <body>
<?php
  // this program is called from get_cars_in_job.php, which is part of set_out.php
  
  // query the sts database for the instructions associated with the incoming job_id
  require 'open_db.php';
  $dbc = open_db();
  $sql = 'select name, description from jobs where id = ?';
  $rs = sts_q($dbc, $sql, array(sts_id(sts_get('job_id'))));
  $row = mysqli_fetch_array($rs);
  
  print '<h1>Job Name: ' . sts_html($row['name']) . '</h1>';
  print '<h2>Instructions: <br /><hr />' . nl2br(sts_html($row['description'])) . '</h2><br /><br />';
  
?>
  </body>
</html>