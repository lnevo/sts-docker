<html>
  <head>
    <title>STS - Waybills (Shipments)</title>
    <style>
      body {font: normal 20px Verdana, Arial, sans-serif;}
      table {border-collapse: collapse;}
      tr {vertical-align: top}
      th {border: 1px solid black; padding: 10px}
      td {border: 1px solid black; padding: 10px}
      .pagebreak { page-break-before: always;}
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
    <h3>CC/WB Waybills based on Shipments</h3>
    <p>&nbsp;</p>
</div>
    <form method="post" action="printable_ccwaybill.php">
    <?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';

      // has the display button be clicked?
      if (isset($_POST['display_btn']))
      {
        print '<div class="noprint">';
        // display the Print button with a prompt
        print '<button type="button" data-sts-print>PRINT</button>&nbsp;&nbsp;';
        print '<a href="printable_ccwaybill2.php">Return to the Shipment Selection</a><br /><br />';
        print '</div>';        
        
        // get the total number of shipments that need to be examined for checkmarks
        $row_count = $_POST['row_count'];

        // count the number of waybills selected so we know when to do a page break
        $wb_count = 0;

        // loop through all of the selected shipments
        for ($i=0; $i<$row_count; $i++)
        {
          $checkbox = 'check' . $i;
          if (isset($_POST[$checkbox]))
          {
            if (($wb_count % 3) == 0)
            {
              // set up the table that will contain the empty and loaded waybills
              print '<table class="pagebreak">';
            }
            print '<tr>
                   <td>';
            // display the empty waybill image
            $shipment = 'shipment' . $i;
            print '<img src="build_cc_mtybill.php?shipment_id=' . $_POST[$shipment] . '&waybill_number=0">';

            // put a blanks space between the two images
            print '&nbsp;';

            // display the freight waybill image
            print '<img src="build_cc_waybill.php?shipment_id=' . $_POST[$shipment] . '">';

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
        // give the user the ability to choose a specific shipment or all shipments
        print 'Select the desired shipments and then click the <b>DISPLAY</b> button.<br /><br />';
        print 'Waybills for the selected shipments will be displayed and if the page is printed,<br />
               each page will contain up to three sets of waybills.<br /><br />';

        print '<div class="noprint">';
        // display the max number of printable car orders
        print 'Your server allows a maximum number of ' . intval($max_lines) . ' rows of car orders to be selected.<br />
               Any car orders selected beyond that limit will not be shown on the following list.<br /><br />';
        print '</div>';
        
        print '<input name="display_btn" id="display_btn" value="DISPLAY" type="submit"><br /><br >';

        // get a database connection
        $dbc = open_db();

        // get a list of all of the shipments
        $sql = 'select shipments.id as id,
                       shipments.code as code,
                       shipments.description as description,
                       commodities.description as consignment
                  from shipments, commodities
                  where commodities.id = shipments.consignment
                  order by shipments.code';
// print 'SQL: ' . $sql . '<br /><br />';
        $rs = mysqli_query($dbc, $sql);

        // use the shipment list to build a series of check boxes
        $row_count=0;
        print '<table id="shipment_list">';
        print '<th style="width: 110px">Select<br /><hr />
                   Check All <input id="check_all" name="check_all" type="checkbox" data-sts-change="checkAll">
               </th>
               <th>Shipment ID</th>
               <th>Shipment</th>
               <th>Consignment</th>';
        while ($row = mysqli_fetch_array($rs))
        {
          print '<tr>
                   <td align="center">
                     <input id="check' . $row_count . '" name="check' . $row_count . '" type="checkbox">
                     <input name="shipment' . $row_count . '" value="' . $row['id'] . '" type="hidden">
                   </td>
                   <td>' . $row['code'] . '</td>
                   <td>' . $row['description'] . '</td>
                   <td>' . $row['consignment'] . '</td>
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
    ?>
    </form>
  </body>
</html>
