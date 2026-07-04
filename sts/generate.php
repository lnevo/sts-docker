<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Generate Car Orders</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="sorttable.js"></script>
    <style>
      tr {vertical-align: top;}
      th, td { font-size: 0.875rem; padding: 6px 8px; white-space: nowrap; }
      #ship_tbl th,
      #ship_tbl td {
        white-space: normal;
        word-break: keep-all;
        overflow-wrap: normal;
        hyphens: none;
      }
      @media (max-width: 1024px) {
        #ship_tbl th,
        #ship_tbl td {
          font-size: 0.8rem;
          padding: 4px 6px;
          line-height: 1.2;
        }

        /* Hide lower-priority numeric planning columns on tablet widths. */
        #ship_tbl th:nth-child(8),
        #ship_tbl td:nth-child(8),
        #ship_tbl th:nth-child(9),
        #ship_tbl td:nth-child(9),
        #ship_tbl th:nth-child(10),
        #ship_tbl td:nth-child(10),
        #ship_tbl th:nth-child(11),
        #ship_tbl td:nth-child(11),
        #ship_tbl th:nth-child(12),
        #ship_tbl td:nth-child(12) {
          display: none;
        }
      }
      @media print { .noprint {display:none;} }
    </style>
  </head>
  <body class="bg-light">
<nav class="navbar navbar-dark noprint mb-3" style="background-color: #2e7d32;">
  <div class="container-fluid">
    <span class="navbar-brand"><i class="bi bi-gear"></i> Generate Car Orders</span>
    <div>
      <a href="operations.html" class="btn btn-outline-light btn-sm me-2">
        <i class="bi bi-arrow-left"></i> Operations
      </a>
      <a href="index.html" class="btn btn-outline-light btn-sm">
        <i class="bi bi-house"></i> Home
      </a>
    </div>
  </div>
</nav>
<div class="px-4 py-3">
<h5 class="mb-3">Generate Car Orders</h5>
<div class="row g-3 mb-3">
  <div class="col-sm-6">
    <div class="card h-100">
      <div class="card-body d-flex flex-column">
        <h6 class="card-title"><i class="bi bi-lightning-charge"></i> Automatic Generation</h6>
        <p class="card-text text-muted small">Increment the operating session number and automatically generate car orders based on shipment schedules.</p>
        <button class="btn btn-success mt-auto w-100" onclick="show_auto();">AUTOMATIC</button>
      </div>
    </div>
  </div>
  <div class="col-sm-6">
    <div class="card h-100">
      <div class="card-body d-flex flex-column">
        <h6 class="card-title"><i class="bi bi-list-check"></i> Manual Generation</h6>
        <p class="card-text text-muted small">Choose specific shipments and click MANUAL to generate car orders for those shipments only.</p>
        <button class="btn btn-success mt-auto w-100" onclick="show_manual();">MANUAL</button>
      </div>
    </div>
  </div>
</div>

<script type="text/javascript">
  function show_auto()
  {
    document.getElementById("automatic").style.display = "block";
    document.getElementById("manual").style.display = "none";
  }

  function show_manual()
  {
    document.getElementById("manual").style.display = "block";
    document.getElementById("automatic").style.display = "none";
  }

  function confirm_manual_order()
  {
    alert('Click "OK" to order these cars. Otherwise click the browser back button to cancel.');
  }

  // generate some javascript that will hide rows
  // - filter_rows() is called to hide cars that don't have the selected property
  function filter_rows(tbl_col, needle)
  {
    // confirm that a non-blank option has been selected
    if (needle.length > 0)
    {
      // convert drop-down locations (station - location) to table locations (station\nlocation)
      var hyphen_loc = needle.search(" - ");
      if (hyphen_loc >= 0)
      {
        var new_needle = needle.substr(0, hyphen_loc) + "\n" + needle.substr(hyphen_loc + 3, needle.length);
        needle = new_needle;
      }

      var table = document.getElementById("ship_tbl");

      //iterate through rows
      for (var i = 1, row; row = table.rows[i]; i++)
      {
        var haystack_length = row.cells[tbl_col].innerText.length;
        var needle_length = needle.length;
        var match_start = haystack_length - needle_length;

        var haystack = row.cells[tbl_col].innerText.substr(match_start);

        if (haystack != needle)
        {
          row.style.display = "none"
        }
      }
    }
  }

  function clear_filters()
  {
    document.location.reload();
  }
</script>

<?php
      // this program generates car orders

      // bring in the function files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // get a database connection
      $dbc = open_db();
      $orders_generated = false;
      $generated_order_count = 0;

      // bring in and display the current operating session number
      $sql = 'select setting_value from settings where setting_name = "session_nbr"';
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) < 1)
      {
        print 'Setting not found - Error: [' . mysqli_error($dbc) . '] SQL: ' . $sql . '<br /><br />';
      }
      $row = mysqli_fetch_array($rs);
      $session_number = $row['setting_value'];

//-------------------------------------------- process the request -------------------------------------------

      if (isset($_POST['autogenerate_btn']))      // --------------------------------------------- was the "Auto Generate" button clicked?
      {
        // increment the operating session number and store it in the settings table
        $session_number++;

        // display the new operating session number
        print 'Auto-generating car orders for  Operating Session ' . $session_number . '...</br><br >';

        $sql = 'update settings set setting_value = ' . $session_number . ' where setting_name = "session_nbr"';
        if (!mysqli_query($dbc, $sql))
        {
          print 'Setting not found - Error: [' . mysqli_error($dbc) . '] SQL: ' . $sql . '<br /><br />';
        }

        // initialize a counter for the number of waybills generated this session
        $waybill_counter = 0;

        // go through the shippers and generate car orders as appropriate
        $sql = 'select id as id,
                       shipments.code as code,
                       shipments.last_ship_date as last_ship_date,
                       shipments.min_interval as min_interval,
                       shipments.max_interval as max_interval,
                       shipments.min_amount as min_amount,
                       shipments.max_amount as max_amount
                  from shipments';
        $rs_shipments = mysqli_query($dbc, $sql);
        if (mysqli_num_rows($rs_shipments) > 0)
        {
          while ($row = mysqli_fetch_array($rs_shipments))
          {
            // do the math
            $last_ship_date = $row['last_ship_date'];
            $min_interval = $row['min_interval'];
            $max_interval = $row['max_interval'];
            $min_amount = $row['min_amount'];
            $max_amount = $row['max_amount'];

            // find a random number between the min and max intervals and round any fraction either up or down
            $interval = round(mt_rand($min_interval * 100, $max_interval * 100)/100);

            // add the random number to the last ship date
            $ship_date = $last_ship_date + $interval;

            // is it time to ship?
            if ($ship_date <= $session_number)
            {
              // store this session number as the new last ship date
              $sql = 'update shipments set last_ship_date = ' . $session_number . ' where id = "' . $row['id'] . '"';
              if (!mysqli_query($dbc, $sql))
              {
                print 'Update Error: [' . mysqli_error($dbc) . '] SQL: ' . $sql . '<br /><br />';
              }

              // determine the number of cars to order and round either up or down
              $num_cars = round(mt_rand($min_amount * 100, $max_amount * 100)/100);

              for ($i=0; $i<$num_cars; $i++)
              {
                // increment the waybill counter
                $waybill_counter++;

                // build the waybill number
                $wb_nbr = str_pad($session_number, 3, '0', STR_PAD_LEFT) . "-" . str_pad($waybill_counter, 3, '0', STR_PAD_LEFT);

                $sql = 'insert into car_orders (waybill_number, shipment, car) values ("' . $wb_nbr . '", "' . $row['id'] . '", "0")';
                if (!mysqli_query($dbc, $sql))
                {
                  print 'Insert Error: [' . mysqli_error($dbc) . '] SQL: ' . $sql . '<br /><br />';
                }
              }
            }
          }
          // display the number of car orders created
          print $waybill_counter . ' car orders generated<br /><br />';
          $orders_generated = true;
          $generated_order_count = $waybill_counter;
        }
      }
      elseif (isset($_POST['mangenerate_btn']))         // -------------------------------- was the "Manual Generate" button clicked?
      {
        // initialize the waybill counter
        $manual_generated_count = 0;
        $sql = 'select max(substr(waybill_number, 6, 2)) from car_orders where waybill_number like "' . str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-M__"';
        $rs = mysqli_query($dbc, $sql);
        $row = mysqli_fetch_row($rs);
        $order_counter = $row[0];
// print "Order counter: " . $order_counter . "<br />";
        // loop through the shipments and order cars for each one that was checkedmarked
        for ($i=0; $i<$_POST['row_count']; $i++)
        {
          if (isset($_POST['select' . $i]))
          {
// print 'Ordering cars for row ' . $i . '<br />';

            // get the things we need to create the waybills
            $shipment_id = $_POST['id' . $i];
            $min_amount = $_POST['min_amt' . $i];
            $max_amount = $_POST['max_amt' . $i];

            // store this session number as the new last ship date
            $sql = 'update shipments set last_ship_date = ' . $session_number . ' where id = "' . $_POST['id' . $i] . '"';
            if (!mysqli_query($dbc, $sql))
            {
              print 'Update Error: [' . mysqli_error($dbc) . '] SQL: ' . $sql . '<br /><br />';
              die();
            }

            // determine the number of cars to order and round either up or down
            $num_cars = round(mt_rand($min_amount * 100, $max_amount * 100)/100);
// print 'Ordering ' . $num_cars . '<br />';
            for ($j=0; $j<$num_cars; $j++)
            {
              // increment the waybill counter
              $order_counter++;

              // build the waybill number
              $wb_nbr = str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-M' . str_pad($order_counter, 2, '0', STR_PAD_LEFT);
// print 'Generating Waybill ' . $wb_nbr . '<br />';
              $sql = 'insert into car_orders (waybill_number, shipment, car) values ("' . $wb_nbr . '", "' . $shipment_id . '", "0")';
// print 'SQL: ' . $sql . '<br /.';
              if (!mysqli_query($dbc, $sql))
              {
                print 'Insert Error: [' . mysqli_error($dbc) . '] SQL: ' . $sql . '<br /><br />';
                die();
              }
              $manual_generated_count++;
            }
          }
        }
        print $manual_generated_count . ' manual car orders generated<br /><br />';
        $orders_generated = true;
        $generated_order_count = $manual_generated_count;
      }

      if ($orders_generated)
      {
        print '<div class="alert alert-success noprint d-flex align-items-center justify-content-between gap-3 flex-wrap">';
        print '<span>' . $generated_order_count . ' car order(s) ready for filling.</span>';
        print '<a class="btn btn-success" href="fill_orders.php">Go to Fill Car Orders</a>';
        print '</div>';
      }

//-------------------------------------------- automatic generation -------------------------------------------

      // set up the auto-generate div
      print '<div name="automatic" id="automatic" style="display:none;">';

      // start the auto-generate form
      print '<form name="automatic" id="automatic" method="post" action="generate.php">';
      print '<p class="text-muted">Ready to generate car orders automatically.</p>';
      print '<input name="autogenerate_btn" id="autogenerate_btn" value="AUTOMATIC" type="submit"
             class="btn btn-success btn-lg"><br /><br />';
      print '</form>';

      print '</div>';

//----------------------------------- manual generation division -------------------------------

      // set up the manual car order generation div
      print '<div id="manual" name="manual" style="display:none;">';

      // start the manual generation form
      print '<form name="manual" id="manual" method="post" action="generate.php">';

      // pull in all shipments
      $sql = 'select shipments.id as id,
                     shipments.code as code,
                     shipments.description as description,
                     shipments.last_ship_date as last_ship_date,
                     shipments.min_interval as min_interval,
                     shipments.max_interval as max_interval,
                     shipments.min_amount as min_amount,
                     shipments.max_amount as max_amount,
                     commodities.code as commodity,
                     car_codes.code as car_code,
                     loc01.code as loading_location,
                     sta01.station as loading_station,
                     loc02.code as unloading_location,
                     sta02.station as unloading_station
                from shipments
                left join commodities on commodities.id = shipments.consignment
                left join car_codes on car_codes.id = shipments.car_code
                left join locations loc01 on loc01.id = shipments.loading_location
                left join routing sta01 on sta01.id = loc01.station
                left join locations loc02 on loc02.id = shipments.unloading_location
                left join routing sta02 on sta02.id = loc02.station
               order by shipments.code';

      $rs_shipments = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs_shipments) > 0)
      {
        print '<p class="text-muted">Check shipments to order cars for, then click MANUAL.</p>';
        // put a submit button on the top and the bottom of the div
        print '<input name="mangenerate_btn" value="MANUAL" type="submit" onmouseup="confirm_manual_order();"
               class="btn btn-success btn-lg mb-3"><br /><br />';
        // filter panel above the table
        print '<div class="card mb-3">
                 <div class="card-body py-2">
                   <div class="row g-2 align-items-end">
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Commodity</label>' .
                       drop_down_commodities('commodity_filter', '', '') . '
                     </div>
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Car Code</label>' .
                       drop_down_car_codes('car_code_filter', '', 'no_wild') . '
                     </div>
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Loading Location</label>' .
                       drop_down_locations('loading_loc_filter', '', '') . '
                     </div>
                     <div class="col-sm-auto">
                       <label class="form-label small mb-1">Unloading Location</label>' .
                       drop_down_locations('unloading_loc_filter', '', '') . '
                     </div>
                     <div class="col-sm-auto">
                       <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clear_filters();">Clear Filters</button>
                     </div>
                   </div>
                 </div>
               </div>';
        // table with clean column headers only
        print '<div class="table-responsive"><table id="ship_tbl" class="table table-sm table-bordered table-hover sortable">
                 <thead>
                   <tr style="position: sticky; top: 0; background-color: #F5F5F5">
                     <th class="sorttable_nosort">Select</th>
                     <th><i>Shipment<br />Code</th>
                     <th><i>Description</th>
                     <th><i>Commodity</th>
                     <th><i>Car<br />Code</th>
                     <th><i>Loading<br />Location</th>
                     <th><i>Unloading<br />Location</th>
                     <th><i>Last<br />Ship<br /> Date</th>
                     <th><i>Min<br />Int</th>
                     <th><i>Max<br />Int</th>
                     <th><i>Min<br />Amt</th>
                     <th><i>Max<br />Amt</th>
                 </tr>
                </thead>';

        $row_count = 0;
        while ($row = mysqli_fetch_array($rs_shipments))
        {
          print '<tr>
                   <td style="text-align:center;"><input type="checkbox" name="select' . $row_count . '" id="select' . $row_count . '"></th>
                   <td>' .
                     $row['code'] . '<input type="hidden" name="id' . $row_count . '" id="id' . $row_count . '" value="' . $row['id'] . '">
                   </td>
                   <td>' . $row['description'] . '</td>
                   <td>' . $row['commodity'] . '</td>
                   <td style="text-align:center;">' . $row['car_code'] . '</td>
                   <td><u>' . $row['loading_station'] . '</u><br />' . $row['loading_location'] . '</td>
                   <td><u>' . $row['unloading_station'] . '</u><br />' . $row['unloading_location'] . '</td>
                   <td style="text-align:center;">' . $row['last_ship_date'] . '</td>
                   <td style="text-align:center;">' . $row['min_interval'] . '</td>
                   <td style="text-align:center;">' . $row['max_interval'] . '</td>
                   <td style="text-align:center;">' .
                     $row['min_amount'] . '<input type="hidden" name="min_amt' . $row_count . '"id=min_amt' . $row_count . '" value="' . $row['min_amount'] . '">
                   </td>
                   <td style="text-align:center;">' .
                     $row['max_amount'] . '<input type="hidden" name="max_amt' . $row_count . '"id=max_amt' . $row_count . '" value="' . $row['max_amount'] . '"?
                   </td>
                 </tr>';
          $row_count++;
        }
        print '</table></div>';

        // save the row count for the next time around
        print '<input type="hidden" name="row_count" id="row_count" value="' . $row_count . '">';
        // put a submit button at the bottom as well as the top of the div
        print '<br /><input name="mangenerate_btn" value="MANUAL" type="submit" onclick="return confirm(\'Order these cars?\');" class="btn btn-success btn-lg mt-2"><br /><br />';
      }
      else
      {
        print 'No shipments found...<br />';
      }
      print '</form>';
      print '</div>';
    ?>
</div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      document.querySelectorAll("select").forEach(function(el) {
        el.classList.add("form-select", "form-select-sm");
        el.style.removeProperty("width");
      });
      [['commodity_filter',3],['car_code_filter',4],['loading_loc_filter',5],['unloading_loc_filter',6]].forEach(function(f) {
        var el = document.getElementById(f[0]);
        if (el) { el.addEventListener('change', function() { filter_rows(f[1], this.options[this.selectedIndex].text); this.disabled = true; }); }
      });
    });
  </script>
</body>
</html>
