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
      <a href="index.html" class="btn btn-outline-light btn-sm me-2">
        <i class="bi bi-house"></i> Home
      </a>
      <a href="index-t.html" class="btn btn-outline-light btn-sm">
        <i class="bi bi-diagram-3"></i> Site Map
      </a>
    </div>
  </div>
</nav>
<div class="px-4 py-3">
<h5 class="mb-3">Generate Car Orders</h5>

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
    return confirm('Click OK to order these cars. Click Cancel to go back without ordering.');
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
      require_once __DIR__ . '/generate_order_helpers.php';

      // get a database connection
      $dbc = open_db();
      $orders_generated = false;
      $generated_order_count = 0;
      $orders_generated_alert_html = '';

      // bring in and display the current operating session number
      $sql = 'select setting_value from settings where setting_name = "session_nbr"';
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) < 1)
      {
        print 'Setting not found - Error: [' . mysqli_error($dbc) . '] SQL: ' . $sql . '<br /><br />';
      }
      $row = mysqli_fetch_array($rs);
      $session_number = $row['setting_value'];

      function run_automatic_car_order_generation($dbc, $session_number, $waybill_counter)
      {
        return generate_orders_run_automatic($dbc, $session_number, $waybill_counter);
      }

      function get_next_auto_waybill_counter($dbc, $session_number)
      {
        return generate_orders_get_next_auto_waybill_counter($dbc, $session_number);
      }

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

        $generated_order_count = run_automatic_car_order_generation($dbc, $session_number, 0);
        print $generated_order_count . ' car orders generated<br /><br />';
        $orders_generated = true;
      }
      elseif (isset($_POST['autogenerate_no_session_btn']))
      {
        if ((int)$session_number <= 0)
        {
          print 'Cannot generate orders without a session. Use Generate Session first.<br /><br />';
        }
        else
        {
          print 'Auto-generating car orders for Operating Session ' . $session_number . ' (session not incremented)...</br><br >';

          $waybill_counter = get_next_auto_waybill_counter($dbc, $session_number);
          $generated_order_count = run_automatic_car_order_generation($dbc, $session_number, $waybill_counter);
          print $generated_order_count . ' car orders generated<br /><br />';
          $orders_generated = true;
        }
      }
      elseif (isset($_POST['increment_session_btn']))
      {
        $previous_session = (int)$session_number;
        $session_number = $previous_session + 1;

        $sql = 'update settings set setting_value = ' . $session_number . ' where setting_name = "session_nbr"';
        if (!mysqli_query($dbc, $sql))
        {
          print 'Setting not found - Error: [' . mysqli_error($dbc) . '] SQL: ' . $sql . '<br /><br />';
        }
        else
        {
          print '<div class="alert alert-info noprint">Operating session advanced from '
              . htmlspecialchars((string)$previous_session) . ' to '
              . htmlspecialchars((string)$session_number) . '.</div>';
          print '<script>document.addEventListener("DOMContentLoaded", function() { show_manual(); });</script>';
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
        $orders_generated_alert_html = '<div class="alert alert-success noprint d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">'
            . '<span>' . htmlspecialchars((string)$generated_order_count) . ' car order(s) ready for filling.</span>'
            . '<a class="btn btn-success" href="fill_orders.php">Go to Fill Car Orders</a>'
            . '</div>';
      }

      $next_session_number = (int)$session_number + 1;

      print '<div class="row g-3 mb-3">
                 <div class="col-sm-6">
                   <div class="card h-100">
                     <div class="card-body d-flex flex-column">
                       <h6 class="card-title"><i class="bi bi-lightning-charge"></i> Automatic Generation</h6>
                       <p class="card-text text-muted small">Increment the operating session number and automatically generate car orders based on shipment schedules.</p>
                       <button type="button" class="btn btn-success mt-auto w-100" onclick="show_auto();">AUTOMATIC</button>
                     </div>
                   </div>
                 </div>
                 <div class="col-sm-6">
                   <div class="card h-100">
                     <div class="card-body d-flex flex-column">
                       <h6 class="card-title"><i class="bi bi-list-check"></i> Manual Generation</h6>
                       <p class="card-text text-muted small">Choose specific shipments and click MANUAL to generate car orders for those shipments only.</p>
                       <button type="button" class="btn btn-success mt-auto w-100" onclick="show_manual();">MANUAL</button>
                     </div>
                   </div>
                 </div>
               </div>';

      if ($orders_generated_alert_html !== '')
      {
        print $orders_generated_alert_html;
      }

//-------------------------------------------- automatic generation -------------------------------------------

      // set up the auto-generate div
      print '<div name="automatic" id="automatic" style="display:none;">';

      // start the auto-generate form
      print '<form name="automatic" id="automatic" method="post" action="generate.php">';
      if ((int)$session_number <= 0)
      {
        print '<p class="text-muted mb-2">Current operating session: <strong>' . htmlspecialchars((string)$session_number) . '</strong></p>';
        print '<p class="text-muted">No operating session yet. Start session 1 and generate car orders.</p>';
      }
      else
      {
        print '<p class="text-muted mb-2">Current operating session: <strong>' . htmlspecialchars((string)$session_number) . '</strong></p>';
        print '<p class="text-muted">Ready to generate car orders automatically for session ' . htmlspecialchars((string)$session_number) . '.</p>';
      }
      print '<div class="d-flex gap-2 flex-wrap mb-3">';
      print '<input name="autogenerate_btn" id="autogenerate_btn" value="Generate Session" type="submit"
             class="btn btn-success btn-lg">';
      if ((int)$session_number > 0)
      {
        print '<input name="autogenerate_no_session_btn" id="autogenerate_no_session_btn"
               value="Generate Orders" type="submit"
               class="btn btn-outline-success btn-lg"
               title="Generate orders for the current session without incrementing the session number">';
      }
      print '</div>';
      print '</form>';

      print '</div>';

//----------------------------------- manual generation division -------------------------------

      // set up the manual car order generation div
      print '<div id="manual" name="manual" style="display:none;">';

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
        print '<p class="text-muted">Check Shipments to order cars for session <strong>' . htmlspecialchars((string)$session_number) . '</strong>, then click MANUAL.</p>';
        print '<div class="d-flex gap-2 flex-wrap mb-3">';
        print '<input name="mangenerate_btn" value="MANUAL" type="submit" form="manualOrderForm" onclick="return confirm_manual_order();"
               class="btn btn-success btn-lg">';
        print '<form method="post" action="generate.php" class="mb-0 next-session-form" data-next-session="' . htmlspecialchars((string)$next_session_number) . '">';
        print '<input type="hidden" name="increment_session_btn" value="Next Session">';
        print '<button type="button" class="btn btn-outline-success btn-lg next-session-btn">
                 Next Session
               </button>';
        print '</form>';
        print '</div>';

        // start the manual generation form
        print '<form name="manualOrderForm" id="manualOrderForm" method="post" action="generate.php">';
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
                     <th class="sorttable_nosort" style="text-align:center;">
                       <input type="checkbox" id="selectAllShipments" title="Select all visible shipments" aria-label="Select all visible shipments">
                     </th>
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
                   <td style="text-align:center;"><input type="checkbox" class="shipment-select" name="select' . $row_count . '" id="select' . $row_count . '"></td>
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
        print '<br /><input name="mangenerate_btn" value="MANUAL" type="submit" onclick="return confirm_manual_order();" class="btn btn-success btn-lg mt-2"><br /><br />';
      }
      else
      {
        print 'No shipments found...<br />';
      }
      print '</form>';
      print '</div>';
    ?>
</div>

<div class="modal fade noprint" id="nextSessionModal" tabindex="-1" aria-labelledby="nextSessionModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="nextSessionModalLabel">Start Next Session</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p id="nextSessionModalMessage" class="mb-3"></p>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="nextSessionConfirmCheck">
          <label class="form-check-label" for="nextSessionConfirmCheck" id="nextSessionConfirmLabel"></label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="nextSessionConfirmBtn" disabled>Start Session</button>
      </div>
    </div>
  </div>
</div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      var pendingNextSessionForm = null;
      var nextSessionModalEl = document.getElementById('nextSessionModal');
      var nextSessionModal = nextSessionModalEl ? new bootstrap.Modal(nextSessionModalEl) : null;
      var nextSessionConfirmCheck = document.getElementById('nextSessionConfirmCheck');
      var nextSessionConfirmBtn = document.getElementById('nextSessionConfirmBtn');
      var nextSessionModalMessage = document.getElementById('nextSessionModalMessage');
      var nextSessionConfirmLabel = document.getElementById('nextSessionConfirmLabel');

      function openNextSessionConfirm(form) {
        if (!nextSessionModal || !form) {
          return;
        }

        pendingNextSessionForm = form;
        var nextSession = form.dataset.nextSession || '';
        nextSessionModalMessage.textContent = 'Are you ready to start session ' + nextSession + '?';
        nextSessionConfirmLabel.textContent = 'Confirm';
        nextSessionConfirmCheck.checked = false;
        nextSessionConfirmBtn.disabled = true;
        nextSessionModal.show();
      }

      document.querySelectorAll('.next-session-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
          openNextSessionConfirm(btn.closest('.next-session-form'));
        });
      });

      if (nextSessionConfirmCheck && nextSessionConfirmBtn) {
        nextSessionConfirmCheck.addEventListener('change', function() {
          nextSessionConfirmBtn.disabled = !nextSessionConfirmCheck.checked;
        });

        nextSessionConfirmBtn.addEventListener('click', function() {
          if (pendingNextSessionForm && nextSessionConfirmCheck.checked) {
            nextSessionModal.hide();
            pendingNextSessionForm.submit();
          }
        });
      }

      document.querySelectorAll("select").forEach(function(el) {
        el.classList.add("form-select", "form-select-sm");
        el.style.removeProperty("width");
      });
      [['commodity_filter',3],['car_code_filter',4],['loading_loc_filter',5],['unloading_loc_filter',6]].forEach(function(f) {
        var el = document.getElementById(f[0]);
        if (el) { el.addEventListener('change', function() { filter_rows(f[1], this.options[this.selectedIndex].text); this.disabled = true; updateSelectAllState(); }); }
      });

      var selectAll = document.getElementById('selectAllShipments');
      if (selectAll) {
        selectAll.addEventListener('change', function() {
          document.querySelectorAll('#ship_tbl tbody tr').forEach(function(row) {
            if (row.style.display === 'none') {
              return;
            }
            var cb = row.querySelector('.shipment-select');
            if (cb) {
              cb.checked = selectAll.checked;
            }
          });
        });

        document.querySelectorAll('.shipment-select').forEach(function(cb) {
          cb.addEventListener('change', updateSelectAllState);
        });
      }

      function updateSelectAllState() {
        var selectAll = document.getElementById('selectAllShipments');
        if (!selectAll) {
          return;
        }

        var visible = [];
        document.querySelectorAll('#ship_tbl tbody tr').forEach(function(row) {
          if (row.style.display === 'none') {
            return;
          }
          var cb = row.querySelector('.shipment-select');
          if (cb) {
            visible.push(cb);
          }
        });

        if (visible.length === 0) {
          selectAll.checked = false;
          selectAll.indeterminate = false;
          return;
        }

        var checkedCount = visible.filter(function(cb) { return cb.checked; }).length;
        selectAll.checked = checkedCount === visible.length;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < visible.length;
      }
    });
  </script>
</body>
</html>
