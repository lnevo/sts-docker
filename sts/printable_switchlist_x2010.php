<html>

<head>
  <title>STS - Print Switchlist</title>
  <style>
    body {
      font: normal 14px 'Arial Narrow', Arial, sans-serif;
    }

    table {
      border-collapse: collapse;
    }

    tr {
      vertical-align: middle;
    }

    th {
      border: 1px solid black;
      padding: 3px 5px;
    }

    td {
      border: 1px solid black;
      padding: 3px 5px;
    }

    .form-page {
      width: 100%;
      max-width: 1200px;
      font-family: 'Arial Narrow', 'Franklin Gothic', Arial, sans-serif;
      margin-bottom: 40px;
    }

    .logo-header {
      width: 100%;
      border-collapse: collapse;
    }

    .logo-header td {
      border: none;
      padding: 3px;
    }

    .detail-table {
      width: 100%;
      border-collapse: collapse;
      border: 1px solid black;
      margin-top: -1px;
    }

    .detail-table td {
      border: 1px solid black;
      padding: 4px 5px;
      font-size: 12px;
      height: 2.8em;
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
      border: 1px solid black;
      margin-top: -1px;
      font-size: 12px;
    }

    .data-table th {
      border: 1px solid black;
      padding: 4px 5px;
      background-color: #1e4d78;
      color: white;
    }

    .data-table td {
      border: 1px solid black;
      padding: 4px 5px;
    }

    .data-table tbody tr:nth-child(even) td {
      background-color: #d0e8ff;
    }

    /* Bold the row number and wagon number columns for easy scanning */
    .data-table tbody td:nth-child(1) {
      font-weight: bold;
    }

    .data-table tbody td:nth-child(3) {
      font-weight: bold;
    }

    .serial-number {
      color: red;
      font-size: 20px;
      font-weight: bold;
      text-align: right;
    }

    .page-info {
      font-size: 12px;
      text-align: left;
    }

    .page-break {
      page-break-before: always;
      break-before: page;
    }

    @media print {
      .noprint {
        display: none !important;
      }

      @page {
        size: A5 landscape;
      }

      body {
        font-size: 8pt;
        font-family: 'Arial Narrow', Arial, sans-serif;
      }

      .form-page {
        max-width: 100% !important;
        margin-bottom: 0 !important;
      }

      .page-break {
        padding-top: 3mm;
      }

      .logo-img {
        height: 32px !important;
        width: auto !important;
      }

      h2.form-title {
        font-size: 10pt !important;
        margin: 1px 0 !important;
      }

      .serial-number {
        font-size: 11pt !important;
      }

      .detail-table td {
        font-size: 7pt !important;
        padding: 1px 3px !important;
        line-height: 1.2 !important;
      }

      .data-table th,
      .data-table td {
        font-size: 8pt !important;
        padding: 2px 4px !important;
        line-height: 1.3 !important;
      }

      .data-table th {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        background-color: #1e4d78 !important;
        color: white !important;
      }

      .data-table tbody tr:nth-child(even) td {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        background-color: #d0e8ff !important;
      }
    }
  </style>
</head>

<body>
  <script>

    function move_row(cell, move) {
      // incoming cell is the one containing the up or down arrow image
      var row_num = cell.parentElement.rowIndex;

      // get the collection of rows in the table
      var rows = document.getElementById("consist").rows;

      // make sure that we do not go above the top or below the bottom of the table
      if ((move == 1) && (row_num < rows.length - 1) || ((move == -1) && (row_num > 1))) {
        // swap the rows
        var old_row = rows[row_num].innerHTML;
        var new_row = rows[row_num + move].innerHTML;
        rows[row_num].innerHTML = new_row;
        rows[row_num + move].innerHTML = old_row;
      }
    }

    function find_car(target, starting_row) {
      // find the row containing the specified reporting marks
      // and insert a new line after that location
      consist_table = document.getElementById("consist");
      table_rows = consist_table.getElementsByTagName("tr");
      for (i = starting_row; i < table_rows.length; i++) {
        table_cells = table_rows[i].getElementsByTagName("td");
        reporting_marks = table_cells[0].innerText;
        if (reporting_marks == target) {
          insert_blank_row(i);
          return (0);
        }
      }
    }

    function insert_blank_row(where) {
      // insert a blank row at the location specified by "where"
      // create one cell on the left with the up and down arrows in it to it can be moved if necessary
      // the other seven columns will be left blank
      var table = document.getElementById("consist");
      var blank_row = table.insertRow(where);
      var blank_td_1 = blank_row.insertCell(0);
      blank_td_1.innerHTML = "<img src='./ImageStore/DB_Images/graphics/up_arrow.png' onclick='move_row(this.parentElement, -1);' alt='UP'/>" +
        " <br /><br />" + " <img src='./ImageStore/DB_Images/graphics/dn_arrow.png' onclick='move_row(this.parentElement, 1);' alt='DN'/>";
      blank_td_1.style = "text-align: center; vertical-align: middle;";
      var blank_td_2 = blank_row.insertCell(1);
      blank_td_2.colSpan = 7;
      blank_td_2.innerHTML = document.getElementById("comment").value;
    }

  </script>

  <?php
  // bring in the utility files
  require 'drop_down_list_functions.php';
  require 'open_db.php';
  require 'set_colors.php';

  // Maximum visual "lines" of content that fit in the data area of one A5 landscape page.
  // Each row is at least 2 lines (station + location code); rows with special instructions
  // are counted as 3+ lines. Tune this value if content clips or pages are under-filled.
  define('X2010_LINES_PER_PAGE', 22);

  /**
   * Estimate how many printed lines a data row will consume.
   * Counts explicit <br> separators and wraps for long special-instruction text.
   */
  function count_row_lines($row) {
    // Current Location: bold station <br> location code — always 2 lines unless "In Train"
    $current_loc_lines = ($row['current_location_id'] > 0) ? 2 : 1;

    // Destination: bold station <br> location code — always 2 lines
    $dest_lines = 2;

    // Special instructions flag now appears in the Contents column.
    // Contents column is roughly 1/11 of ~194mm usable width ≈ 18mm.
    // At 8pt × 0.85em Arial Narrow, roughly 18 characters fit per line.
    $spec_instr    = trim($row['special_instructions'] ?? '');
    $loc_remarks   = trim($row['location_remarks'] ?? '');
    $show_spec     = ($spec_instr !== '' && strtolower($spec_instr) !== 'n/a');
    $show_loc_rem  = ($loc_remarks !== '' && strtolower($loc_remarks) !== 'n/a');
    $contents_lines = 1; // commodity code or empty — single line
    if ($show_spec) {
      $contents_lines += max(1, (int) ceil(mb_strlen($spec_instr) / 18));
    }
    if ($show_loc_rem) {
      $contents_lines += max(1, (int) ceil(mb_strlen($loc_remarks) / 18));
    }

    return max($current_loc_lines, $dest_lines, $contents_lines);
  }

  function x2010_page_header($logo, $table_name, $serial_number, $page_num, $page_count, $driverNameWidth, $timeOnDutyWidth, $depotWidth) {
    // Logo / title / serial — inside the bordered table like the rest of the header
    print '<table class="detail-table">';
    print '<tr>';
    print '<td style="width: 30%; border: 1px solid black; padding: 4px;"><img class="logo-img" src="images/' . $logo . '_logo.jpg" alt="Company Logo" style="height: 52px; width: auto;"></td>';
    print '<td style="width: 45%; text-align: center; border: 1px solid black; padding: 4px; vertical-align: middle;"><h2 class="form-title" style="margin: 0;">Train Consist Form x 2010</h2></td>';
    print '<td style="width: 25%; border: 1px solid black; padding: 4px; position: relative;">';
    print '<div style="position: absolute; bottom: 4px; left: 4px;" class="page-info">PAGE ' . $page_num . ' OF ' . $page_count . '</div>';
    print '<div style="position: absolute; bottom: 4px; right: 4px;" class="serial-number">' . $serial_number . '</div>';
    print '</td>';
    print '</tr>';
    print '</table>';

    // Train details
    print '<table class="detail-table">';
    print '<tr>';
    print '<td style="width: 10%;">Train No.<br/><b>' . trim(explode('|', $table_name)[0]) . '</b></td>';
    print '<td style="width: 10%;">Date</td>';
    print '<td style="width: 10%;">Dept Time</td>';
    print '<td style="width: 15%;">Origin</td>';
    print '<td style="width: 16%;">Destination</td>';
    print '<td style="width: ' . $driverNameWidth . ';">Driver Name</td>';
    print '<td style="width: ' . $timeOnDutyWidth . ';">Time on Duty</td>';
    print '<td style="width: ' . $depotWidth . ';">Depot</td>';
    print '</tr>';
    print '</table>';

    // Radio / unit
    print '<table class="detail-table">';
    print '<tr>';
    print '<td style="width: 30%;">Train Radio Number</td>';
    print '<td style="width: 15%;">Unit No.</td>';
    print '<td style="width: 16%;">P.M. Date Due</td>';
    print '<td style="width: 14%;">Driver Name</td>';
    print '<td style="width: 13%;">Time on Duty</td>';
    print '<td style="width: 12%;">Depot</td>';
    print '</tr>';
    print '</table>';

    // Mobile / brake
    print '<table class="detail-table">';
    print '<tr>';
    print '<td style="width: 30%;">Mobile Number</td>';
    print '<td style="width: 45%;">Brake Certificate No.</td>';
    print '<td style="width: 25%;">Train Type</td>';
    print '</tr>';
    print '</table>';
  }

  // has the display button be clicked?
  if (isset($_GET['display_btn'])) {
    // get a database connection
    $dbc = open_db();

    // get the desired job name
    $job_name = $_GET['job_name'];

    // get the print width from the settings table
    $sql = 'select setting_value from settings where setting_name = "print_width"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_row($rs);
    $print_width = $row[0];
    $page_width = substr($print_width, 0, 3) * 10;

    // get the railroad initials and name from the settings table
    $sql = 'select setting_value from settings where setting_name = "railroad_initials"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_row($rs);
    $rr_initials = $row[0];

    $sql = 'select setting_value from settings where setting_name = "railroad_name"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_row($rs);
    $rr_name = $row[0];

    // build a query to pull in the job's description and table name
    $sql = 'select jobs.description as description,
                       jobs.name as table_name
                  from jobs
                 where id = "' . $job_name . '"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_array($rs);
    $job_desc = $row['description'];
    $table_name = $row['table_name'];

    // build a query to pull in the switchlist information
    // the first query in the union looks for cars that are assigned to the specified job and are revenue moves
    // the second query in the union looks for cars that are assigned to the specified job but are repositioning moves

    $sql = '(select
                 cars.reporting_marks as reporting_marks,
                 car_codes.code as car_code,
                 cars.status as status,
                 cars.remarks as remarks,
                 commodities.code as consignment,
                 shipments.consignment as consignment_id,
                 shipments.special_instructions as special_instructions,
                 "" as location_remarks,
                 routing.station as current_station,
                 locations.code as current_location,
                 loading_sta.station as loading_station,
                 loading_loc.code as loading_location,
                 unloading_sta.station as unloading_station,
                 unloading_loc.code as unloading_location,

                 cars.current_location_id,
                 cars.position as position,
                 cars.car_code_id as car_code_id,
                 cars.handled_by_job_id as handled_by,
                 locations.station as current_station_id,
                 `' . $table_name . '`.step_number

                 from cars

                 left join locations on locations.id = cars.current_location_id
                 left join routing on routing.id = locations.station
                 inner join car_orders on car_orders.car = cars.Id
                 inner join car_codes on car_codes.id = cars.car_code_id
                 inner join shipments on shipments.id = car_orders.shipment
                 inner join commodities on commodities.id = shipments.consignment

                 inner join locations loading_loc on loading_loc.id = shipments.loading_location
                 inner join routing loading_sta on loading_sta.id = loading_loc.station

                 inner join locations unloading_loc on unloading_loc.id = shipments.unloading_location
                 inner join routing unloading_sta on unloading_sta.id = unloading_loc.station

                 left join `' . $table_name . '` on `' . $table_name . '`.station = routing.id

                 where ((cars.handled_by_job_id = "' . $job_name . '") and (not instr(car_orders.waybill_number, "E")))

                 group by cars.reporting_marks)

                 UNION

                 (select
                 cars.reporting_marks as reporting_marks,
                 car_codes.code as car_code,
                 cars.status as status,
                 cars.remarks as remarks,
                 "" as consignment,
                 0 as consignment_id,
                 "" as special_instructions,
                 unloading_loc.remarks as location_remarks,
                 routing.station as current_station,
                 locations.code as current_location,
                 0 as loading_station,
                 "" as loading_location,
                 unloading_sta.station as unloading_station,
                 unloading_loc.code as unloading_location,

                 cars.current_location_id,
                 cars.position as position,
                 cars.car_code_id as car_code_id,
                 cars.handled_by_job_id as handled_by,
                 locations.station as current_station_id,
                 `' . $table_name . '`.step_number

                 from cars

                 left join locations on locations.id = cars.current_location_id
                 left join routing on routing.id = locations.station
                 inner join car_orders on car_orders.car = cars.Id
                 inner join car_codes on car_codes.id = cars.car_code_id

                 inner join locations unloading_loc on unloading_loc.id = car_orders.shipment
                 inner join routing unloading_sta on unloading_sta.id = unloading_loc.station

                 left join `' . $table_name . '` on `' . $table_name . '`.station = routing.id

                 where ((cars.handled_by_job_id = "' . $job_name . '") and (instr(car_orders.waybill_number, "E")))

                 group by cars.reporting_marks)

                 ORDER BY position, step_number, current_station, current_location, unloading_location, reporting_marks';
    //                 inner join shipments on shipments.id = car_orders.shipment // removed because repositions don't have shipments
//                 inner join commodities on commodities.id = shipments.consignment // ditto
//                 ORDER BY max_step_number, position, unloading_location, reporting_marks'; // fixed sort order

    //print 'SQL: ' . $sql . '<br /><br />';

    // First pass: collect reporting marks and simulate line-based pagination to get the true page count
    $rs = mysqli_query($dbc, $sql);
    $car_list        = [];
    $row_lines_list  = [];   // line count for each row, indexed in order
    $page_count      = 1;
    $lines_on_page   = 0;
    $first_page_sim  = true;
    while ($row = mysqli_fetch_array($rs)) {
      $car_list[] = $row['reporting_marks'];
      $rl = count_row_lines($row);
      $row_lines_list[] = $rl;
      $budget = X2010_LINES_PER_PAGE;
      if ($lines_on_page > 0 && $lines_on_page + $rl > $budget) {
        $page_count++;
        $lines_on_page = $rl;
        $first_page_sim = false;
      } else {
        $lines_on_page += $rl;
      }
    }
    $page_count = max(1, $page_count);
    $car_count  = count($car_list);

    // run the query again to build the switchlist table
    $rs = mysqli_query($dbc, $sql);
    if (mysqli_num_rows($rs) > 0) {
      // initialize the counters for loads and empties
      $loads = 0;
      $empties = 0;

      // x2010 format based on Pacific National Train Consist Form
      if ($_GET['format'] == 'x2010') {
        print '<div class="noprint">';
        print '<button onclick="window.print()">PRINT</button>&nbsp;&nbsp;';
        print '<a href="display_switchlist.php">Return to Display Switchlist page</a><br /><br />';
        print '</div>';

        $serial_number = sprintf("%06d", rand(1, 999999));

        $operator_number = $table_name[2] ?? 'x';
        $logo = match ($operator_number) {
          '0' => 'railcorp',
          '2' => 'pn',
          '8' => 'arg',
          '5' => 'sct',
          '4' => 'ssr',
          'n' => 'manildra',
          default => 'hart'
        };

        $driverNameWidth = "14%";
        $timeOnDutyWidth = "13%";
        $depotWidth = "12%";

        $special_instruction_counter = 0;
        $page_num = 0;
        $row_num  = 1;
        $row_idx  = 0;   // index into $row_lines_list
        $lines_on_current_page = 0;

        while ($row = mysqli_fetch_array($rs)) {

          $rl = $row_lines_list[$row_idx] ?? count_row_lines($row);

          // Start a new page at row 1, or when adding this row would exceed the line budget
          $budget = X2010_LINES_PER_PAGE;
          if ($row_num === 1 || $lines_on_current_page + $rl > $budget) {
            // Close the previous page's data table and wrapper div
            if ($row_num > 1) {
              print '</tbody></table></div>';
            }
            $page_num++;
            $break_class = ($page_num > 1) ? ' page-break' : '';
            print '<div class="form-page' . $break_class . '">';
            x2010_page_header($logo, $table_name, sprintf("%06d", $serial_number + $page_num - 1), $page_num, $page_count, $driverNameWidth, $timeOnDutyWidth, $depotWidth);
            // Data table column headers
            print '<table class="data-table">';
            print '<colgroup>';
            print '<col style="width:6%">';   // Sl. No
            print '<col style="width:7%">';   // Wagon Class
            print '<col style="width:10%">';  // Wagon/Loco Number
            print '<col style="width:2%">';   // CL
            print '<col style="width:2%">';   // Sta
            print '<col style="width:7%">';   // DG
            print '<col style="width:5%">';   // Gross Mass
            print '<col style="width:5%">';   // Length Metres
            print '<col style="width:18%">'; // Current Location
            print '<col style="width:19%">'; // Destination
            print '<col style="width:19%">'; // Contents
            print '</colgroup>';
            print '<thead><tr>';
            print '<th>Sl.<br/>No</th>';
            print '<th>Wagon Class</th>';
            print '<th>Wagon or Locomotive<br/>Number</th>';
            print '<th>CL</th>';
            print '<th>Sta</th>';
            print '<th>DG</th>';
            print '<th>Gross<br/>Mass</th>';
            print '<th>Length<br/>Metres</th>';
            print '<th>Current Location</th>';
            print '<th>Destination</th>';
            print '<th>Contents<br/><span style="font-weight:normal;font-size:0.85em;">&#9873; Routing</span></th>';
            print '</tr></thead>';
            print '<tbody>';
            $lines_on_current_page = 0;
          }

          $lines_on_current_page += $rl;

          print '<tr>';
          print '<td style="text-align: center;">' . $row_num . '</td>';
          print '<td style="text-align: center;">' . substr($row['car_code'], 0, 4) . '</td>';

          // Wagon number - strip trailing alpha check letter
          if (ctype_alpha($row['reporting_marks'][strlen($row['reporting_marks']) - 1])) {
            print '<td style="text-align: center;">' . preg_replace("/[a-zA-Z\-]+$/", "", $row['reporting_marks']) . '</td>';
          } else {
            print '<td style="text-align: center;">' . $row['reporting_marks'] . '</td>';
          }

          // CL check letter
          if (ctype_alpha($row['reporting_marks'][strlen($row['reporting_marks']) - 1])) {
            print '<td style="text-align: center;">' . $row['reporting_marks'][strlen($row['reporting_marks']) - 1] . '</td>';
          } else {
            print '<td></td>';
          }

          // Status
          print '<td style="text-align: center;">' . ($row['status'] == 'Loaded' ? 'L' : 'E') . '</td>';

          // DG
          if ($row['car_code'] == 'ATMF' || $row['car_code'] == 'NTAF') {
            print '<td style="text-align: center; white-space: nowrap;">Y-Petroleum</td>';
          } else {
            print '<td></td>';
          }

          // Gross mass and length from remarks field
          print '<td style="text-align: center;">' . trim(explode('|', $row['remarks'])[0]) . '</td>';
          print '<td style="text-align: center;">' . trim(explode('|', $row['remarks'])[1]) . '</td>';

          // Current location
          if ($row['current_location_id'] > 0) {
            print '<td style="text-align: center;"><b>' . $row['current_station'] . '</b><br>' . $row['current_location'] . '</td>';
          } else {
            print '<td style="text-align: center;">In Train</td>';
          }

          // Special instructions flag
          $spec_instr = trim($row['special_instructions'] ?? '');
          $show_spec = ($spec_instr !== '' && strtolower($spec_instr) !== 'n/a');
          $spec_flag = $show_spec ? '<br><span style="color:#b30000;font-size:0.85em;">&#9873; ' . htmlspecialchars($spec_instr) . '</span>' : '';

          // Location routing remarks flag (for empty/repositioning cars)
          $loc_remarks = trim($row['location_remarks'] ?? '');
          $show_loc_rem = ($loc_remarks !== '' && strtolower($loc_remarks) !== 'n/a');
          $loc_flag = $show_loc_rem ? '<br><span style="color:#b30000;font-size:0.85em;">&#9873; ' . htmlspecialchars($loc_remarks) . '</span>' : '';

          // Destination
          if ($row['status'] == 'Empty' || $row['status'] == 'Ordered') {
            if ($row['consignment_id'] <= 0) {
              print '<td style="text-align: center;"><b>' . $row['unloading_station'] . '</b><br>' . $row['unloading_location'] . '</td>';
            } else {
              print '<td style="text-align: center;"><b>' . $row['loading_station'] . '</b><br>' . $row['loading_location'] . '</td>';
            }
          } elseif ($row['status'] == 'Loaded') {
            print '<td style="text-align: center;"><b>' . $row['unloading_station'] . '</b><br>' . $row['unloading_location'] . '</td>';
          }

          // Contents (with special instructions / location routing flags below)
          if ($row['status'] == 'Loaded') {
            print '<td style="text-align: center;">' . $row['consignment'] . $spec_flag . $loc_flag . '</td>';
          } else {
            print '<td style="text-align: center;">' . $spec_flag . $loc_flag . '</td>';
          }

          print '</tr>';
          $row_num++;
          $row_idx++;
        }

        // Close the last page
        if ($row_num > 1) {
          print '</tbody></table></div>';
        }

      } // end x2010

    } else {
      print '<p style="font-family: verdana;">';
      print 'No switchlist found for ' . $table_name . '<br />';
      print '</p>';
    }

    print '<div class="noprint"><hr /></div>';


  }

  function print_chunks($incoming_string, $page_width)
  {
    // because text inside <pre></pre> tags doesn't respect boundaries, reformat any strings that
    // are longer than the page width setting_name by turning the text string into an array and
    // checking each of the individual lines
    $string_array = explode('<br />', nl2br($incoming_string));
    for ($i = 0; $i < sizeof($string_array); $i++) {
      if (strlen($string_array[$i]) <= $page_width) {
        // if the string is less than page width, just print it
        print $string_array[$i] . '<br />';
      } else {
        // turn this string into an array of words and print them one by one until the next word
        // in line will exceed the page width
        $line_string_array = explode(' ', $string_array[$i]);
        $col_counter = 0;
        for ($j = 0; $j < sizeof($line_string_array); $j++) {
          $end_of_word = $col_counter + strlen($line_string_array[$j]);
          if ($end_of_word > $page_width) {
            $end_of_word = 0;
            print '<br />';
          }
          print $line_string_array[$j] . ' ';
          $col_counter = $end_of_word + strlen($line_string_array[$j]);
        }
      }
    }
  }


  ?>
  <!-- Begin Strikeout Table Script -->
  <style>
    .strikethrough {
      text-decoration: line-through;
      opacity: 0.5;
    }

    .clear-strikes-button {
      display: inline-block;
      margin: 10px 0 15px 0;
      padding: 5px 10px;
      font-size: 14px;
      background-color: #f44336;
      color: white;
      border: none;
      border-radius: 5px;
      cursor: pointer;
    }

    .clear-strikes-button:hover {
      background-color: #d32f2f;
    }

    @media print {
      .noprint {
        display: none !important;
      }
    }
  </style>

  <script>
    document.addEventListener("DOMContentLoaded", function () {
      const tables = document.querySelectorAll(".data-table");
      let clearButtonInserted = false; // only add button once

      tables.forEach((table, tableIndex) => {
        const rows = table.querySelectorAll("tbody tr");

          rows.forEach((row, rowIndex) => {
            const firstCell = row.querySelector("td:first-child");
            if (firstCell) {
              const checkbox = document.createElement("input");
              checkbox.type = "checkbox";
              checkbox.style.marginRight = "5px";

              const rowId = `row-${tableIndex}-${rowIndex}`;
              row.setAttribute("data-row-id", rowId);

              if (localStorage.getItem(rowId) === "striked") {
                row.classList.add("strikethrough");
                checkbox.checked = true;
              }

              checkbox.addEventListener("change", function () {
                if (this.checked) {
                  row.classList.add("strikethrough");
                  localStorage.setItem(rowId, "striked");
                } else {
                  row.classList.remove("strikethrough");
                  localStorage.removeItem(rowId);
                }
              });

              firstCell.prepend(checkbox);
            }
          });

          // Insert clear button once above the first consist table
          if (!clearButtonInserted) {
            const clearButton = document.createElement("button");
            clearButton.textContent = "Clear Strikes";
            clearButton.className = "clear-strikes-button noprint";
            clearButton.addEventListener("click", function () {
              document.querySelectorAll("tr[data-row-id]").forEach((row) => {
                row.classList.remove("strikethrough");
                const checkbox = row.querySelector("input[type='checkbox']");
                if (checkbox) checkbox.checked = false;
                localStorage.removeItem(row.getAttribute("data-row-id"));
              });
            });

            table.parentNode.insertBefore(clearButton, table);
            clearButtonInserted = true;
          }
        });
    });
  </script>

  <!-- End Strikeout Table Script -->



  <div class="noprint">
    <br /><a href="display_switchlist.php">Return to Display Switchlist page</a>
    <br />
  </div>
</body>

</html>
