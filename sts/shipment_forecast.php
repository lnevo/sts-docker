<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Shipment Forecast',
    'icon' => 'bi-graph-up-arrow',
    'section' => 'report',
    'print' => true,
    'head_extra' => '<style>th.vert_bottom {vertical-align: bottom} td.numbers {text-align: center} @media print {.sts-card {box-shadow: none; padding: 0}}</style>',
]);
?>
<div class="sts-card">
<?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';

      // get a database connection
      $dbc = open_db();

      // get the current operating session number and the printer width
      $sql = 'select setting_value from settings where setting_name = "session_nbr"';
      $rs = mysqli_query($dbc, $sql);
      $rs_session_nbr = mysqli_fetch_row($rs);

      $sql = 'select setting_value from settings where setting_name = "print_width"';
      $rs = mysqli_query($dbc, $sql);
      $row_print_width= mysqli_fetch_row($rs);
      $print_width = $row_print_width[0];

      print '<div id="instructions" class="text-muted" style="width: ' . $print_width . '">';
    ?>
    The report shows an estimate of how often shipments will occur during the next 10 operating sessions.<br /><br />
    As the random number generator is not predictable (on purpose) the actual number of shipments will vary from day to day but should remain somewhat stable from one ten day period to another.</br /><br />
    The shipments are sorted in alphabetical order by loading location and shipment code. This provides an approximate forecast of how many cars will be loaded at the shippers' locations during the next 10 operating sessions.<br /><br />
    Click on the <b>REFRESH</b> button to generate another set of random numbers. Click on the <b>PRINT</b> button to generate a printable report.<br /><br />
    </div>
    <form action="shipment_forecast.php" method="get">
    <input id="refresh_btn" name="refresh_btn" value="REFRESH" type="submit" class="btn btn-primary">&nbsp;&nbsp;<button type="button" class="btn btn-outline-secondary" data-sts-print>PRINT</button>
    <br /><br />
    <?php

      // pull in the shipment descriptions and their min/max/remainder values
      $sql = 'select locations.code as loading_location,
                     routing.station as loading_station,
                     shipments.code as code,
                     shipments.description as description,
                     car_codes.code as car_code,
                     shipments.last_ship_date as last_ship_date,
                     shipments.min_interval as min_interval,
                     shipments.max_interval as max_interval,
                     shipments.min_amount as min_amount,
                     shipments.max_amount as max_amount
                from (shipments, car_codes)
                left join locations on locations.id = shipments.loading_location
                left join routing on routing.id = locations.station
               where car_codes.id = shipments.car_code
               order by loading_location, code';
// print 'SQL: ' . $sql . '<br /><br />';
      $rs = mysqli_query($dbc, $sql);

      if (mysqli_num_rows($rs) > 0)
      {
        // set up 10 buckets to total the 10 columns
        $col_totals = array(0,0,0,0,0,0,0,0,0,0);

        // start the table
        print '<table style="width: ' . $print_width . ' white-space: nowrap;">
                 <thead style="position: sticky; top: 0; background-color: #F5F5F5">
                   <tr>
                     <th colspan="3">Shipment</th>
                     <th class="vert_bottom" rowspan="2">Car<br />Code</th>
                     <th colspan="10">Operating Sessions</th>
                     <th class="vert_bottom" rowspan="2">Total<br />Shipments</th>
                   </tr>';

        print '<tr>
                 <th>Loading<br /><u>Station</u><br />Location</th>
                 <th>Code</th>
                 <th>Description</th>';

        for ($i=0; $i<10; $i++)
        {
          $session_number = $rs_session_nbr[0] + $i; 
          print '<th>' . $session_number . '</th>';
        }

        print '</tr>';
        print '</thead>';

        while ($row = mysqli_fetch_array($rs))
        {
          // loop through each shipment and display shipment predictions for the next ten operating sessions
          print '<tr>
                   <td><u>' . $row['loading_station'] . '</u><br />' . $row['loading_location'] . '</td>
                   <td>' . $row['code'] . '</td>
                   <td>' . $row['description'] . '</td>
                   <td style="text-align: center;">' . $row['car_code'] . '</td>';
          $total = 0;
          $prev_ship_date = $row['last_ship_date'];
          for ($i=0; $i<10; $i++)
          {
            // do the math
            $min_interval = $row['min_interval'];
            $max_interval = $row['max_interval'];
            $min_amount = $row['min_amount'];
            $max_amount = $row['max_amount'];

            $interval = round(mt_rand($min_interval, $max_interval));

            if (($prev_ship_date + $interval) <= ($rs_session_nbr[0] + $i))
            {
              // if it's time to ship, calculate how many car loads
              $num_cars = round(mt_rand($min_amount, $max_amount));
              print '<td class="numbers">' . $num_cars . '</td>';

              // save the current session as the new last ship date
              $prev_ship_date = $rs_session_nbr[0] + $i;

              // keep track of total carloads for this row
              $total = $total + $num_cars;

              // add this row/column's total carloads into the overall column total
              $col_totals[$i] = $col_totals[$i] + $num_cars;
            }
            else
            {
              // if there are no shipments for this session, generate an emty cell
              print '<td class="numbers">0</td>';
            }
          }
          print '<td class="numbers">' . $total . '</td>';
          print '</tr>';
        }
        // display the total projected shipments for each column (day) and then close the table
        $grand_total = 0;
        print '<tr>';
        print '<td colspan="4"><b>TOTAL</b></td>';
        for ($i=0; $i<10; $i++)
        {
          print '<td><b>' . $col_totals[$i] . '</b></td>';
          $grand_total = $grand_total + $col_totals[$i];
        }
        print '<td class="numbers"><b>' . $grand_total . '</b></td>';
        print '</tr>';
        print '</table>';
      }
      else
      {
        print "<br /><br />No shipments were found.";
      }
    ?>
    </form>
</div>
<?php sts_page_end(); ?>
