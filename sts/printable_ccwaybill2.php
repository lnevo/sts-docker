<html>
  <head>
    <title>STS - Waybills (Car Orders)</title>
    <style>
      body {font: normal 20px Verdana, Arial, sans-serif;}
      table {border-collapse: collapse;}
      tr {vertical-align: top}
      th {border: 1px solid black; padding: 10px}
      td {border: 1px solid black; padding: 10px}
      .pagebreak { page-break-before: always; }
      @media print
      {
        .noprint {display:none;}
      }
    </style>
    <?php
      $max_lines = ((ini_get('max_input_vars') - 3) / 3) - 1;
    ?>
    <script type="application/json" id="sts-data-ccwaybill"><?= json_encode(['maxRows' => $max_lines]) ?></script>
    <script src="/sts/js/sts-actions.js?v=1"></script>
    <script src="/sts/js/report-ccwaybill.js?v=1"></script>

  </head>
  <body>
<div class="noprint"> <img src="ImageStore/GUI/Menu/report.jpg" width="715" height="144" border="0" usemap="#MapMap">
  <map name="MapMap">
    <area shape="rect" coords="566,7,704,47" href="index.html">
    <area shape="rect" coords="566,96,706,136" href="index-t.html">
    <area shape="rect" coords="563,51,707,91" href="reports.html">
  </map>
  <p>&nbsp;</p>
    <h2>Reports</h2>
    <h3>CC/WB Waybills based on Car Orders</h3>
</div>
    <form method="post" action="printable_ccwaybill2.php">
    <?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';
      print '<div class="noprint">';
      // display the max number of printable car orders
      print 'Your server allows a maximum number of ' . intval($max_lines) . ' rows of car orders to be selected.<br />
             Any car orders selected beyond that limit will not be shown on the following list.<br /><br />';
      print '</div>';               
      // has the display button be clicked?
      if (isset($_POST['display_btn']))
      {
        print '<div class="noprint">';
        // display the Print button with a prompt
        print '<button type="button" data-sts-print>PRINT</button>&nbsp;&nbsp;';
        print '<a href="printable_ccwaybill2.php">Return to the Shipment Selection</a><br /><br />';
        print '</div>';        
        
        // get the total number of car orders that need to be examined for checkmarks
        $row_count = $_POST['row_count'];

        // count the number of waybills selected so we know when to do a page break
        $wb_count = 0;

        // loop through all of the selected car orders
        for ($i=0; $i<$row_count; $i++)
        {
          $checkbox = 'check' . $i;
          if (isset($_POST[$checkbox]))
          {
            if (($wb_count % 3) == 0)
            {
              // set up the table that will contain the empty and load waybills
              print '<table class="pagebreak">';
            }
            print '<tr>
                   <td>';
            // display the empty waybill image
            $car_order = 'car_order' . $i;
            $waybill_number = 'waybill_number' . $i;
            print '<img src="build_cc_mtybill.php?shipment_id=' . $_POST[$car_order] . '&waybill_number=' . $_POST[$waybill_number] . '">';

            // check to see if this is an empty car repositioning waybill and if not, display the loaded movement waybill
            if (!strpos($_POST[$waybill_number], "E"))
            {
              // put a blanks space between the two images
              print '&nbsp;';

              // display the freight waybill image
              print '<img src="build_cc_waybill.php?shipment_id=' . $_POST[$car_order] . '">';
            }

            print '</td>';
            print '</tr>';
            if (($wb_count % 3) == 2)
            {
              print '</table>';
            }
            $wb_count++;
          }
        }
        // finish up the last table if it was a partial
        if ($wb_count > 0)
        {
          print '</table>';
        }
        print '<div class="noprint"><br /><a href="printable_ccwaybill2.php">Return to the Shipment Selection</a></div>';
      }
      else
      {
        // get a database connection
        $dbc = open_db();

        // get a list of all of the filled car orders
        $sql = 'select shipments.id as shipment_id,
                       shipments.code as shipment,
                       car_orders.waybill_number as waybill_number,
                       cars.reporting_marks as reporting_marks,
                       cars.status as status,
                       shipments.description as description,
                       commodities.description as consignment,
                       car_codes.code as car_code,
                       loc01.code as loading_location,
                       loc02.code as unloading_location
                from car_orders
                left join shipments on shipments.id = car_orders.shipment
                left join cars on cars.id = car_orders.car
                left join commodities on commodities.id = shipments.consignment
                left join car_codes on car_codes.id = shipments.car_code
                left join locations loc01 on loc01.id = shipments.loading_location
                left join locations loc02 on loc02.id = shipments.unloading_location
                where car_orders.car = 0
                order by car_orders.waybill_number';
// print 'SQL: ' . $sql . '<br /><br />';
        $rs = mysqli_query($dbc, $sql);

        // if filled car orders were found, display them, else display a message
        if (mysqli_num_rows($rs) > 0)
        {
          // give the user the ability to choose a specific car order or all car orders
          print 'Select the desired car orders and then click the <b>DISPLAY</b> button.<br /><br />';
          print 'Waybills for the selected car orders will be displayed and if the page is printed,<br />
                 each page will contain up to three sets of waybills.<br /><br />';

          print '<input name="display_btn" id="display_btn" value="DISPLAY" type="submit"><br /><br >';

          // use the shipment list to build a series of check boxes
          $row_count=0;
          print '<table id="car_order_list">';
          print '<th style="width: 110px">Select<br /><hr />
                     Check All <input id="check_all" name="check_all" type="checkbox" data-sts-change="checkAll">
                 </th>
                 <th>Waybill<br>Number</th>
                 <th>Shipment</th>
                 <th>Assigned Car</th>
                 <th>Car Status</th>
                 <th>Description</th>
                 <th>Consignment</th>
                 <th>Car Type</th>
                 <th>Load At</th>
                 <th>Unload At</th>';
          while ($row = mysqli_fetch_array($rs))
          {
            print '<tr>
                     <td align="center">
                       <input id="check' . $row_count . '"name="check' . $row_count . '"type="checkbox" >
                       <input name="car_order' . $row_count . '" value="' . $row['shipment_id'] . '" type="hidden">
                       <input name="waybill_number' . $row_count . '" value="' . $row['waybill_number'] . '" type="hidden">
                     </td>
                     <td>' . $row['waybill_number'] . '</td>
                     <td>' . $row['shipment'] . '</td>
                     <td>' . $row['reporting_marks'] . '</td>
                     <td>' . $row['status'] . '</td>
                     <td>' . $row['description'] . '</td>
                     <td>' . $row['consignment'] . '</td>
                     <td>' . $row['car_code'] . '</td>
                     <td>' . $row['loading_location'] . '</td>
                     <td>' . $row['unloading_location'] . '</td>
                   </tr>';
            $row_count++;
            if ($row_count > $max_lines)
            {
              break;
            }
          }
          print '</table>';
          if ($row_count > $max_lines)
          {
            print '<br />Maximum number of printable waybills reached.';
          }
          print '<input type="hidden" name="row_count" value="' . $row_count . '">';
        }
        else
        {
          print 'All billed cars are enroute. Waybills cannot be displayed or printed while a car is moving.<br />';
          print 'For information about enroute cars, check the <a href="db_list.php?tbl_name=car_orders">car orders</a>
                 or the <a href="db_list.php?tbl_name=cars">cars</a> tables.';
        }
      }
    ?>
    </form>
  </body>
</html>
