<?php
  $tbl_name = isset($_POST['tbl_name']) ? $_POST['tbl_name'] : (isset($_GET['tbl_name']) ? $_GET['tbl_name'] : '');
  if ($tbl_name === 'cars') {
    require 'list_cars.php';
    exit;
  }
?>
<html>
  <head>
    <title>STS - Database Management</title>
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
<h2>Database Management</h2>
    <h3 id="table_name"></h3>
    <div id="instructions">
    To add a new item, fill in the input items and click on the <b>UPDATE</b> button.<br />
    To edit or remove an item, click on it's link<br /><br />
    </div>

    <?php
      // this program builds the shell for all pages that list the contents of the database table
      // it is called from index.html
      // the incoming parameter is the name of the table to be listed
      // in addition to listing the contents of the tables, it also does an sql insert for new object

      // bring in the function files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // pull in the table to be hooked to this page
      if (isset($_POST['tbl_name']))
      {
        $tbl_name = $_POST['tbl_name'];
      }
      else
      {
        $tbl_name = $_GET['tbl_name'];
      }

      // generate the <form> tag
      print '<form method="post" action="db_list.php">';

      // generate a hidden tag that sends the table name back to this
      // program as an incoming parameter when the page is reloaded
      print '<input name="tbl_name" id="tbl_name" value="" type="hidden">';

      // generate the submit button
      print '<div id="update"><input id="update_btn" name="update_btn" value="UPDATE" type="submit"><br /><br /></div>';

      // build the appropriate HTML table
      switch($tbl_name)
      {
        case 'commodities':
          require 'db_list_commodities.php';
          break;
        case 'car_codes':
          require 'db_list_car_codes.php';
          break;
        case 'locations':
          require 'db_list_locations.php';
          break;
        case 'routing':
          require 'db_list_routing.php';
          break;
        case 'shipments':
          require 'db_list_shipments.php';
          break;
        case 'cars':
          require 'db_list_cars.php';
          break;
        case 'car_orders':
          require 'db_list_car_orders.php';
          break;
        case 'jobs':
          require 'db_list_jobs.php';
          break;
      }
      print '</form>';
    ?>
  </body>
</html>
