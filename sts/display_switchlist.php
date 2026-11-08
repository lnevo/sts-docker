<?php
// Single-file report: ?generate_report=1 outputs the switch list HTML fragment
// and exits; the form below fetches it into #report-content. The format
// selector picks the layout (half, full, dmp, mobile, wo, x2010). Replaces
// printable_switchlist.php and printable_switchlist_x2010.php, which now just
// redirect here with the same query string.
//
// Fragment rules: no <style> or <script> here (innerHTML never runs scripts and
// CSS would bleed into the page). Styles live in report_switchlist.css and the
// behaviour (job instructions toggle, blank line insert, strike-out) in
// js/report-switchlist.js.

// Maximum visual "lines" of content that fit in the data area of one A5
// landscape page (x2010). Tune this value if content clips or pages are
// under-filled.
define('X2010_LINES_PER_PAGE', 22);

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


    function print_chunks($incoming_string, $page_width)
    {
      // because text inside <pre></pre> tags doesn't respect boundaries, reformat any strings that
      // are longer than the page width setting_name by turning the text string into an array and
      // checking each of the individual lines
      $string_array = explode('<br />', nl2br($incoming_string));
      for ($i=0; $i<sizeof($string_array); $i++)
      {
        if (strlen($string_array[$i]) <= $page_width)
        {
          // if the string is less than page width, just print it
          print $string_array[$i] . '<br />';
        }
        else
        {
          // turn this string into an array of words and print them one by one until the next word
          // in line will exceed the page width
          $line_string_array = explode(' ', $string_array[$i]);
          $col_counter = 0;
          for ($j=0; $j<sizeof($line_string_array); $j++)
          {
            $end_of_word = $col_counter + strlen($line_string_array[$j]);
            if ($end_of_word > $page_width)
            {
              $end_of_word = 0;
              print '<br />';
            }
            print $line_string_array[$j] . ' ';
            $col_counter = $end_of_word + strlen($line_string_array[$j]);
          }
        }
      }
    }

if (isset($_GET['generate_report'])) {
  require 'drop_down_list_functions.php';
  require 'open_db.php';
  require 'set_colors.php';

  $dbc = open_db();

  $job_name = (int) ($_GET['job_name'] ?? 0);
  $format = $_GET['format'] ?? 'half';
  if (!in_array($format, ['half', 'full', 'dmp', 'mobile', 'wo', 'x2010'], true)) {
    $format = 'half';
  }

  // get the print width from the settings table
  $sql = 'select setting_value from settings where setting_name = "print_width"';
  $rs = mysqli_query($dbc, $sql);
  $row = mysqli_fetch_row($rs);
  $print_width = $row[0] ?? '8in';
  $page_width = substr($print_width, 0, 3) * 10;

  // get the railroad initials and name from the settings table
  $sql = 'select setting_value from settings where setting_name = "railroad_initials"';
  $rs = mysqli_query($dbc, $sql);
  $row = mysqli_fetch_row($rs);
  $rr_initials = $row[0] ?? '';

  $sql = 'select setting_value from settings where setting_name = "railroad_name"';
  $rs = mysqli_query($dbc, $sql);
  $row = mysqli_fetch_row($rs);
  $rr_name = $row[0] ?? '';

  // the job's description and table name
  $sql = 'select jobs.description as description,
                 jobs.name as table_name
            from jobs
           where id = "' . $job_name . '"';
  $rs = mysqli_query($dbc, $sql);
  $row = $rs ? mysqli_fetch_array($rs) : null;
  if (!$row) {
    echo '<p>Job not found.</p>';
    exit;
  }
  $job_desc = $row['description'];
  $table_name = $row['table_name'];

  // The first query in the union looks for cars that are assigned to the
  // specified job and are revenue moves; the second for cars assigned to the
  // job that are repositioning moves.
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

  // First pass: collect the reporting marks (for the "insert blank line" list)
  // and simulate the x2010 pagination to get the true page count.
  $rs = mysqli_query($dbc, $sql);
  $car_list = [];
  $row_lines_list = [];
  $page_count = 1;
  $lines_on_page = 0;
  while ($rs && ($row = mysqli_fetch_array($rs))) {
    $car_list[] = $row['reporting_marks'];
    $rl = count_row_lines($row);
    $row_lines_list[] = $rl;
    if ($lines_on_page > 0 && $lines_on_page + $rl > X2010_LINES_PER_PAGE) {
      $page_count++;
      $lines_on_page = $rl;
    } else {
      $lines_on_page += $rl;
    }
  }

  // run the query again to build the switchlist
  $rs = mysqli_query($dbc, $sql);
  echo '<div class="sw-report sw-fmt-' . $format . '">';
  if ($rs && mysqli_num_rows($rs) > 0) {
    // initialize the counters for loads and empties
    $loads = 0;
    $empties = 0;

          if ($format == 'half')
          {
/*----------------------------------------------------------------------------------------------------------------*/
            // half-sheet format

            // remember if there are any special instructions to be displayed/printed
            $special_instructions = array();
            $special_instruction_counter = 0;

            print '<div class="noprint">';
            // generate a check box that when checked displays the job instructions
            print '<input type="checkbox" checked id="half_sheet_checkbox" data-sts-change="toggleJobInstructions" data-target="half_sheet_job_instructions" data-shown="table-cell">Show Job Instructions&nbsp;&nbsp;';
            print '<br /><br />';
            // generate a drop-down list, a text box, and a button to insert a blank line
            // after the specified reporting marks with an optional text comment
            print 'Insert blank line before this car:&nbsp';
            print '<select name="reporting_marks_list" id="reporting_marks_list">';
            for ($i=0; $i<sizeof($car_list); $i++)
            {
              print '<option value="' . $car_list[$i] . '">' . $car_list[$i]. '</option>';
            }
            print '</select>&nbsp;';
            print 'With comment:&nbsp; <input type="text" name="comment" id="comment">&nbsp;';
            print '<button type="button" data-sts-click="findCar" data-start="1">Insert</button>&nbsp;';
            print '<br /><br />';
            print '</div><br />';

            // set up two columns, left one for the switch list, right one for the job's description
            $units = substr($print_width, -2);
            $value = substr($print_width, 0, strlen($print_width) - 2);
            $col_width = (($value/2) - 0.125) . $units;

            print '<table>';
            print '<tr>';
            print '<td>';

            // generate the heading
            print '<h2 style="text-align: center;">' . $rr_initials . '</h2>';
            print '<h3 style="text-align: center;">Switchlist</h3>';

            // generate the train number and other header information
            print '<table style="font: normal 10px Verdana, Arial, sans-serif; width: ' . $col_width . ';">';
            print '<tr>';
            print '<td style="width: 50%;"><b>Train: ' . $table_name . '</b><br /><br /><br /></td>';
            print '<td style="width: 50%; vertical-align: top;"><b>Dpt (station/date/time)</b><br /><br /><br /></td>';
            print '</tr>';
            print '<tr>';
            print '<td style="width: 50%;"><b>Engine:</b><br /><br /><b>DCC Address:</b><br /><br /><b>Caboose:</b><br /></td>';
            print '<td style="width: 50%; vertical-align: top;"><b>Arr (station/date/time)</b><br /><br /><br /></td>';
            print '</tr>';
            print '<tr>';
            print '<td style="width: 50%;"><b>Engineer:</b><br /><br /><br /></td>';
            print '<td style="width: 50%;"><b>Conductor:</b><br /><br /><br /></td>';
            print '</tr>';
            print '</table>';

            // build a table for the selected job's switchlist
            print '<table id="consist" style="font: normal 8px Verdana, Arial, sans-serif; width: ' . $col_width . ';">';
            print '<tr>
                     <th style="width: 60px;">Rptg<br />Marks</th>
                     <th style="width: 22px; text-align: center;">Car<br />Code</th>
                     <th style="width: 15px; text-align: center;">E/L</th>
                     <th>Contents</th>
                     <th>From</th>
                     <th>To</th>
                     <th style="width: 30px">Picked<br />Up</th>
                     <th style="width: 35px">Left<br /> At</th>
                   </tr>';

            $row_num = 1;
            while ($row = mysqli_fetch_array($rs))
            {
              print '<tr>';

              // column 1 - reporting marks
              print '<td>' . $row['reporting_marks'] . '</td>';

              // column 2 - car code
              print '<td style="text-align: center">' . substr($row['car_code'], 0, 4) . '</td>';

              // column 3 - L/E
              if (($row['status'] == "Empty") || ($row['status'] == "Ordered"))
              {
                print '<td style="text-align: center">E</td>';
                $empties++;
              }
              elseif ($row['status'] == "Loaded")
              {
                print '<td style="text-align: center">L</td>';
                $loads++;
              }

              // column 4 - consignment
              if ($row['status'] == "Loaded")
              {
                print '<td>' . $row['consignment'];
                if (strlen($row['special_instructions']) > 0)
                {
                  print '<br />Spec Instr';
                  $special_instructions[$special_instruction_counter][0] = $row['reporting_marks'];
                  $special_instructions[$special_instruction_counter][1] = $row['consignment'];
                  $special_instructions[$special_instruction_counter][2] = $row['special_instructions'];
                  $special_instruction_counter++;
                }
                print '</td>';
              }
              else
              {
                print '<td>&nbsp;';
                if (strlen($row['special_instructions']) > 0)
                {
                  print 'Spec Instr';
                  print '<br />Spec Instr';
                  $special_instructions[$special_instruction_counter][0] = $row['reporting_marks'];
                  $special_instructions[$special_instruction_counter][1] = $row['consignment'];
                  $special_instructions[$special_instruction_counter][2] = $row['special_instructions'];
                  $special_instruction_counter++;
                }
                print '</td>';
              }

              // column 5 - current location
              if ($row['current_location_id'] > 0)
              {
                print '<td><u>' . $row['current_station'] . '</u><br />' . $row['current_location'] . '</td>';
              }
              else
              {
                print '<td>In Train</td>';
              }

              // column 6 - destination
              if (($row['status'] == "Empty") || ($row['status'] == "Ordered"))
              {
                // if the commodity column is empty, this is a non revenue move and the car's destination
                // is the unloading location
                if ($row['consignment_id'] <= 0)
                {
                  print '<td style="' . set_colors($dbc, $row['unloading_location']) . '"><u>' . $row['unloading_station'] . '</u><br />' . $row['unloading_location']  . '</td>';
                }
                else
                {
                  print '<td style="' . set_colors($dbc, $row['loading_location']) . '"><u>' . $row['loading_station'] . '</u><br />'. $row['loading_location']  . '</td>';
                }
              }
              elseif ($row['status'] == "Loaded")
              {
                print '<td style="' . set_colors($dbc, $row['unloading_location']) . '"><u>' . $row['unloading_station'] . '</u><br />' . $row['unloading_location']  . '</td>';
              }

              // column 7 - picked up
              // If the car hasn't been picked up yet, leave this box blank, otherwise put an X in it
              if ($row['current_location_id'] > 0)
              {
                print '<td style="width: 30px;"></td>';
              }
              else
              {
                print '<td style="width: 30px; text-align: center"><b>X</b></td>';
              }

              // column 8 - left at
              print '<td style="width: 35px"></td>';

              print '</tr>';

              // insert a blank line between cars for easier readability
//              print '<tr><td colspan="8"></tr>';

              // increment the row number
              $row_num++;
            }
            print '</table>';

            // display the number of loads and empties
            $total_cars = $loads + $empties;
            print "<br />";
            print "Loads: " . $loads . "<br />";
            print "Empties: " . $empties . "<br />";
            print "Total cars: " . $total_cars;
            print '</td>';

            // build the right hand column
            print '<td id="half_sheet_job_instructions" style="padding: 10px">';

            // display the selected job's description
            print '<h3>Crew Instructions</h3>';
            print '<table style="width: ' . $col_width . '";>';
            print '  <tr>';
            print '    <td style="border: 0px;">
                         <h3>Job: ' . $table_name . '</h3>
                             Description: ' . nl2br($job_desc) . '
                       </td>
                     </tr>
                   </table>';
            print '</td>';
            print '</tr>';
            print '</table>';

            // if there are any special instructions, print them on their own page
            if ($special_instruction_counter > 0)
            {
              // generate a page break
              print '<p style="page-break-after: always;">&nbsp;</p>';
              print '<table  style="table-collapse: collapse; font: normal 15px Verdana, Arial, sans-serif; width: ' . $print_width . '">';
              print '<tr>
                       <td style="border: 0px;">
                         <h3>Special Instructions</h3>';
              for ($i=0; $i<$special_instruction_counter; $i++)
              {
                print $special_instructions[$i][0] . ' (' . $special_instructions[$i][1] . ') ' . $special_instructions[$i][2] . '<br /><br />';
              }
              print '    </td>
                       </tr>
                     </table>';
            }

            // generate a page break
            print '<p style="page-break-after: always;">&nbsp;</p>';
            print '<div class="noprint">';
            print '<hr />';
            print '</div>';
          }
else if ($format == 'x2010') {
        $serial_number = sprintf("%06d", rand(1, 999999));

        $operator_number = $table_name[2] ?? 'x';
        $logo = match ($operator_number) {
          '0' => 'railcorp',
          '2' => 'pn',
          '8' => 'arg',
          '5' => 'sct',
          '4' => 'ssr',
          'n' => 'manildra',
          default => 'nswgr'
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
          print '<td style="text-align: center;">' . trim(explode('|', (string) $row['remarks'])[0]) . '</td>';
          print '<td style="text-align: center;">' . trim(explode('|', (string) $row['remarks'])[1] ?? '') . '</td>';

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
          else if ($format == 'full')
          {
/*----------------------------------------------------------------------------------------------------------------*/
            // full page format

            // remember if there are any special instructions to be displayed/printed
            $special_instructions = array();
            $special_instruction_counter = 0;

            print '<div class="noprint">';
            // generate a check box that when checked displays the job instructions
            print '<input type="checkbox" checked id="full_sheet_checkbox" data-sts-change="toggleJobInstructions" data-target="full_sheet_job_instructions" data-shown="block">Show Job Instructions&nbsp;&nbsp;';
            // generate a button that will insert a blank row into the list
            print '<br /><br />';
            print 'Insert blank line before this car:&nbsp';
            print '<select name="reporting_marks_list" id="reporting_marks_list">';
            for ($i=0; $i<sizeof($car_list); $i++)
            {
              print '<option value="' . $car_list[$i] . '">' . $car_list[$i]. '</option>';
            }
            print '</select>&nbsp;';
            print 'With comment:&nbsp; <input type="text" name="comment" id="comment">&nbsp;';
            print '<button type="button" data-sts-click="findCar" data-start="5">Insert</button>&nbsp;';
            print '<br /><br />';
            print '</div><br />';

            print '<table id="consist">';
            print '  <tr>';
            print '    <td colspan="8">';

            // generate the heading
            print '      <h2 style="text-align: center;">' . $rr_initials . '</h2>';
            print '      <h3 style="text-align: center;">Switchlist</h3>';
            print '    </td>';
            print '  </tr>';

            // generate the train number and other header information
            print '  <tr>';
            print '    <td colspan="4"><b>Train: ' . $table_name . '</b><br /><br /><br /></td>';
            print '    <td colspan="4"><b>Dpt (station/date/time)</b><br /><br /><br /></td>';
            print '  </tr>';
            print '  <tr>';
            print '    <td colspan="4"><b>Engine:</b><br /><br /><b>DCC Address:</b><br /><br /><b>Caboose:</b><br ></td>';
            print '    <td colspan="4" style="vertical-align: top;"><b>Arr (station/date/time)</b><br /><br /><br /></td>';
            print '  </tr>';
            print '  <tr>';
            print '    <td colspan="4"><b>Engineer:</b><br /><br /><br /></td>';
            print '    <td colspan="4"><b>Conductor:</b><br /><br /><br /></td>';
            print '  </tr>';

            // build the selected job's switchlist
            print '  <tr>
                       <th>Rptg<br />Marks</th>
                       <th style="text-align: center;">Car<br />Code</th>
                       <th style="text-align: center;">E/L</th>
                       <th>Contents</th>
                       <th>From</th>
                       <th>To</th>
                       <th>Picked<br />Up</th>
                       <th style="width: 100px;">Left<br /> At</th>
                     </tr>';

            $row_num = 1;
            while ($row = mysqli_fetch_array($rs))
            {
              print '  <tr>';

              // column 1 - reporting marks
              print '    <td>' . $row['reporting_marks'] . '</td>';

              // column 2 - car code
              print '    <td style="text-align: center">' . $row['car_code'] . '</td>';

              // column 3 - L/E
              if (($row['status'] == "Empty") || ($row['status'] == "Ordered"))
              {
                print '    <td style="text-align: center">E</td>';
                $empties++;
              }
              elseif ($row['status'] == "Loaded")
              {
                print '    <td style="text-align: center">L</td>';
                $loads++;
              }

              // column 4 - consignment
              if ($row['status'] == "Loaded")
              {
                print '    <td>' . $row['consignment'];
                if (strlen($row['special_instructions']) > 0)
                {
                  print '<br />Spec Instr';
                  $special_instructions[$special_instruction_counter][0] = $row['reporting_marks'];
                  $special_instructions[$special_instruction_counter][1] = $row['consignment'];
                  $special_instructions[$special_instruction_counter][2] = $row['special_instructions'];
                  $special_instruction_counter++;
                }
                print '</td>';
              }
              else
              {
                print '    <td>&nbsp;';
                if (strlen($row['special_instructions']) > 0)
                {
                  print 'Spec Instr';
                  $special_instructions[$special_instruction_counter][0] = $row['reporting_marks'];
                  $special_instructions[$special_instruction_counter][1] = $row['consignment'];
                  $special_instructions[$special_instruction_counter][2] = $row['special_instructions'];
                  $special_instruction_counter++;
                }
                print '</td>';
              }

              // column 5 - current location
              if ($row['current_location_id'] > 0)
              {
                print '    <td><u>' . $row['current_station'] . '</u><br />' . $row['current_location'] . '</td>';
              }
              else
              {
                print '    <td>In Train</td>';
              }

              // column 6 - destination
              if (($row['status'] == "Empty") || ($row['status'] == "Ordered"))
              {
                // if the commodity column is empty, this is a non revenue move and the car's destination
                // is the unloading location
                if ($row['consignment_id'] <= 0)
                {
                  print '    <td style="' . set_colors($dbc, $row['unloading_location']) . '"><u>' . $row['unloading_station'] . '</u><br />' . $row['unloading_location']  . '</td>';
                }
                else
                {
                  print '    <td style="' . set_colors($dbc, $row['loading_location']) . '"><u>' . $row['loading_station'] . '</u><br />' . $row['loading_location']  . '</td>';
                }
              }
              elseif ($row['status'] == "Loaded")
              {
                print '    <td style="' . set_colors($dbc, $row['unloading_location']) . '"><u>' . $row['unloading_station'] . '</u><br />' . $row['unloading_location']  . '</td>';
              }

              // column 7 - picked up
              // If the car hasn't been picked up yet, leave this box blank, otherwise put an X in it
              if ($row['current_location_id'] > 0)
              {
                print '    <td>&nbsp;</td>';
              }
              else
              {
                print '    <td style="text-align: center"><b>X</b></td>';
              }

              // column 8 - left at
              print '    <td>&nbsp;</td>';

              print '  </tr>';

              // print a blank line between cars for improved readability
//              print '<tr><td colspan="8"></td></tr>';

              // increment the row number
              $row_num++;
            }
            print '</table>';

            // display the number of loads and empties
            $total_cars = $loads + $empties;
            print "<br />";
            print "Loads: " . $loads . "<br />";
            print "Empties: " . $empties . "<br />";
            print "Total cars: " . $total_cars;

            // if there are any special instructions, print them on their own page
            if ($special_instruction_counter > 0)
            {
              // generate a page break
              print '<p style="page-break-after: always;">&nbsp;</p>';
              print '<table  style="table-collapse: collapse; font: normal 15px Verdana, Arial, sans-serif; width: ' . $print_width . '">';
              print '<tr>
                       <td style="border: 0px;">
                         <h3>Special Instructions</h3>';
              for ($i=0; $i<$special_instruction_counter; $i++)
              {
                print $special_instructions[$i][0] . ' (' . $special_instructions[$i][1] . ') ' . $special_instructions[$i][2] . '<br /><br />';
              }
              print '    </td>
                       </tr>
                     </table>';
            }

            // generate a page break
            print '<p style="page-break-after: always;">&nbsp;</p>';

            // display the selected job's description
            print '<div  id="full_sheet_job_instructions">';
            print '<h3>Crew Instructions</h3>';
            print '<table>';
            print '<tr>';
            print '<td style="border: 0px;">
                   <h3>Job: ' . $table_name . '</h3>
                   Description: ' . nl2br($job_desc) . '
                   </td>';
            print '</tr>';
            print '</td>';
            print '</tr>';
            print '</table>';
            print '</div>';

            // generate a page break
            print '<p style="page-break-after: always;">&nbsp;</p>';
            print '<div class="noprint">';
            print '<hr />';
            print '</div>';
          }
          else if (($format == 'dmp') or ($format == 'mobile'))
          {
/*----------------------------------------------------------------------------------------------------------------*/
            // dot matrix and mobile formats

            // remember if there are any special instructions to be displayed/printed
            $special_instructions = array();
            $special_instruction_counter = 0;

            // mark this output as pre-formated
            print '<pre>';

            print '<div class="noprint">';
            // generate a check box that when checked displays the job instructions
            print '<input type="checkbox" checked id="dot_matrix_checkbox" data-sts-change="toggleJobInstructions" data-target="dot_matrix_job_instructions" data-shown="block">Show Job Instructions&nbsp;&nbsp;';
            // generate a button that will insert a blank row into the list
            print '<button type="button" data-sts-click="insertBlankRow" data-where="5">Add Blank Row</button><br /><br />';
            
            print '</div><br />';

            // generate the headings based on format (mobile = less heading information)
            print str_pad($rr_name, $page_width, ' ', STR_PAD_BOTH). '<br/>';
            if ($format != 'mobile')
            {
              print '<br />';
            }

            print str_pad('Switchlist', $page_width, ' ', STR_PAD_BOTH) . '<br />';
            if ($format != 'mobile')
            {
              print '<br />';
            }

            // generate the train number and other header information
            if ($format == 'dmp')
            {
              print str_pad('Train: ' . substr($table_name, 0, 22), $page_width / 3) .
                    str_pad(' Dpt ___________________', $page_width / 3) . 'Arr ___________________<br />';
              print str_repeat(' ', $page_width / 3) .
                    str_pad('     (station/date/time)', $page_width / 3) . '    (station/date/time)<br /> <br />';
              print str_pad('Engine: ______________', $page_width / 3) .
                    str_pad('Engineer: _____________', $page_width / 3) . 'Conductor: ____________<br /> <br />';
              print str_pad('DCC Address: _________', $page_width / 3) .
                    str_repeat(' ', $page_width / 3) . 'Caboose:   ____________<br /><br />';
            }
            else if ($format == 'mobile')
            {
              print str_pad('Train: ' . $table_name, $page_width, ' ', STR_PAD_BOTH) . '<br />';
            }
            print '<br />';

            // build the selected job's switchlist
            print 'Rptg Marks  Type E/L Contents      From           To             PkUp Left<br />';
            print '----------- ---- --- ------------- -------------- -------------- ---- ----</br />';

            $row_num = 1;
            while ($row = mysqli_fetch_array($rs))
            {
              // generate the first line for each car -----------------------------------------------------------------------------------------------------

              // columns 1 and 2 - reporting marks and car code
              print str_pad(substr($row['reporting_marks'], 0, 11), 11) . ' ' . str_pad(substr($row['car_code'], 0, 4), 4) . ' ';

              // column 3 - L/E
              if (($row['status'] == "Empty") || ($row['status'] == "Ordered"))
              {
                print ' E  ';
                $empties++;
              }
              elseif ($row['status'] == "Loaded")
              {
                print ' L  ';
                $loads++;
              }

              // column 4 - consignment
              if ($row['status'] == "Loaded")
              {
                print str_pad(substr($row['consignment'], 0, 13), 13) . ' ';
              }
              else
              {
                print str_repeat(' ', 13) . ' ';
              }

              // column 5 - current location
              if ($row['current_location_id'] > 0)
              {
                print str_pad(substr($row['current_station'], 0, 14), 14) . ' ';
              }
              else
              {
                print str_pad('In Train', 14) . ' ';
              }

              // column 6 - destination
              if (($row['status'] == "Empty") || ($row['status'] == "Ordered"))
              {
                // if the commodity column is empty, this is a non revenue move and the car's destination
                // is the unloading location
                if ($row['consignment_id'] <= 0)
                {
                  if ($format != 'mobile')
                  {
                    print str_pad(substr($row['unloading_station'], 0, 14), 14) . ' ';
                  }
                  else
                  {
                    print '<span style="' . set_colors($dbc, $row['unloading_location']) . '">' . str_pad(substr($row['unloading_station'], 0, 14), 14) . '</span> ';
                  }
                }
                else
                {
                  if ($format != 'mobile')
                  {
                    print str_pad(substr($row['loading_station'], 0, 14), 14) . ' ';
                  }
                  else
                  {
                    print '<span style="' . set_colors($dbc, $row['loading_location']) . '">' . str_pad(substr($row['loading_station'], 0, 14), 14) . '</span> ';
                  }
                }
              }
              elseif ($row['status'] == "Loaded")
              {
                if ($format != 'mobile')
                {
                  print str_pad(substr($row['unloading_station'], 0, 14), 14) . ' ';
                }
                else
                {
                  print '<span style="' . set_colors($dbc, $row['unloading_location']) . '">' . str_pad(substr($row['unloading_station'], 0, 14), 14) . '</span> ';
                }
              }
              print '<br />';

              // generate the second line for each car ---------------------------------------------------------------------------------------------------

              // skip past the first three fields
              print str_repeat(' ', 20) . ' ';

              // column 4 - display a special instructions reminder if one exists
              if (strlen($row['special_instructions']) > 0)
              {
                print 'See Spec Inst ';
                $special_instructions[$special_instruction_counter][0] = $row['reporting_marks'];
                $special_instructions[$special_instruction_counter][1] = $row['consignment'];
                $special_instructions[$special_instruction_counter][2] = $row['special_instructions'];
                $special_instruction_counter++;
              }
              else
              {
                print str_repeat(' ', 13) . ' ';
              }

              // column 5 - current location
//              if ($row['current_location_id'] > 0)
//              {
                print str_pad(substr($row['current_location'], 0, 14), 14) . ' ';
//              }
//              else
//              {
//                print str_pad('In Train', 15) . ' ';
//              }

              // column 6 - destination
              if (($row['status'] == "Empty") || ($row['status'] == "Ordered"))
              {
                // if the commodity column is empty, this is a non revenue move and the car's destination
                // is the unloading location
                if ($row['consignment_id'] <= 0)
                {
                  print str_pad(substr($row['unloading_location'], 0, 14), 14) . ' ';
                }
                else
                {
                  print str_pad(substr($row['loading_location'], 0, 14), 14) . ' ';
                }
              }
              elseif ($row['status'] == "Loaded")
              {
                print str_pad(substr($row['unloading_location'], 0, 14), 14) . ' ';
              }

              // column 7 - picked up
              // If the car hasn't been picked up yet, leave this box blank, otherwise put an X in it
              if ($row['current_location_id'] > 0)
              {
                print '____ ';
              }
              else
              {
                print '_XX_ ';
              }

              // column 8 - left at
              print '____ <br />';

              // print a blank line between cars for improved readability
              print '<br />';

              // increment the row number
              $row_num = $row_num + 3;
            }

            // display the number of loads and empties
            $total_cars = $loads + $empties;
            print 'Loads: ' . $loads . '<br />';
            print 'Empties: ' . $empties . '<br />';
            print 'Total cars: ' . $total_cars . '<br />';

            // generate a page break
            if ($special_instruction_counter > 0)
            {
              print '<p style="page-break-after: always;">&nbsp;</p>';
              print str_pad(' Special Instructions ', $page_width-1, '-', STR_PAD_BOTH) . '<br /><br />';
              for ($i=0; $i<$special_instruction_counter; $i++)
              {
                $special_instruction_string = $special_instructions[$i][0] . ' (' . $special_instructions[$i][1] . ') ' . $special_instructions[$i][2];
                print_chunks($special_instruction_string, $page_width);
              }
            }
            // generate a page break
            print '<p style="page-break-after: always;">&nbsp;</p>';

            // display the selected job's description
            print '<div  id="dot_matrix_job_instructions">';
            print str_pad(' Crew Instructions ', $page_width-1, '-', STR_PAD_BOTH) . '<br />';
            print 'Job: ' . $table_name . '<br /><br />';
            print_chunks($job_desc, $page_width);
            print '</div>';

            // generate a page break
            print '<p style="page-break-after: always;">&nbsp;</p>';
            print '<div class="noprint">';
            print '<hr />';
            print '</div>';
            print '</pre>';
          }
          else if ($format == 'wo')
          {
/*----------------------------------------------------------------------------------------------------------------*/
            // work order format

            // remember if there are any special instructions to be displayed/printed
            $special_instructions = array();
            $special_instruction_counter = 0;

            // mark this output as pre-formated
            print '<pre>';

            print '<div class="noprint">';
            // generate a check box that when checked displays the job instructions
            print '<input type="checkbox" checked id="workorder_checkbox" data-sts-change="toggleJobInstructions" data-target="workorder_job_instructions" data-shown="block">Show Job Instructions&nbsp;&nbsp;';
            
            print '</div><br />';

            // generate the headings based on format (mobile = less heading information)
            print str_pad($rr_name, $page_width, ' ', STR_PAD_BOTH). '<br/><br />';

            print str_pad('Work Order', $page_width, ' ', STR_PAD_BOTH) . '<br /><br />';

            // generate the train number and other header information
            print str_pad('Train: ' . substr($table_name, 0, 22), $page_width / 3) .
                  str_pad('Dpt ___________________', $page_width / 3) . 'Arr ___________________<br />';
            print str_repeat(' ', $page_width / 3) .
                  str_pad('    (station/date/time)', $page_width / 3) . '    (station/date/time)<br /> <br />';
            print str_pad('Engine: ______________', $page_width / 3) .
                  str_pad('Engineer: _____________', $page_width / 3) . 'Conductor: ____________<br /> <br />';
            print str_pad('DCC Address: _________', $page_width / 3) .
                  str_repeat(' ', $page_width / 3) . 'Caboose:   ____________<br /><br />';

            // display the selected job's description
            print '<div id="workorder_job_instructions">';
            print str_pad(' CREW INSTRUCTIONS ', $page_width, '-', STR_PAD_BOTH) . '<br /><br />';
            print wordwrap(nl2br($job_desc), $page_width, "<br />") . '<br /><br />';
            print '</div>';

//            print str_repeat('-', $page_width) . '<br /><br />';

            // build the selected job's switchlist
            $row_num = 1;
            $prev_station = '';
            $first_station = true;
            while ($row = mysqli_fetch_array($rs))
            {
              // print a station header if it's changed from the previous car, or if it's the first one
              if (($first_station) || ($prev_station != $row['current_station']))
              {
                if ($row['current_location_id'] > 0)
                {
                  $current_station = 'STATION/YARD PICK UP AT ' . $row['current_station'];
                }
                else
                {
                  $current_station = 'IN TRAIN';
                }
                print str_repeat('-', (($page_width - strlen($current_station)) / 2)) .
                      ' ' . $current_station . ' ' .
                      str_repeat('-', (($page_width - strlen($current_station)) /2)) . '<br /><br />';

                print 'Loc/Trk/Spot   Rptg Marks  E/L Type Contents      To Station     Loc/Trk/Spot<br />';
                print '-------------- ----------- --- ---- ------------- -------------- --------------</br />';

                $prev_station = $row['current_station'];
                $first_station = false;
              }

              // generate the first line for each car -----------------------------------------------------------------------------------------------------

              // column 1 - current location
              if ($row['current_location_id'] > 0)
              {
                print str_pad(substr($row['current_location'], 0, 14), 14) . ' ';

                // get the track and spot for this location
                $sql1 = 'select track, spot from locations where code = "' . $row['current_location'] . '"';
                $rs1 = mysqli_query($dbc, $sql1);
                $row1 = mysqli_fetch_array($rs1);
                $current_track = $row1['track'];
                $current_spot = $row1['spot'];
              }
              else
              {
                print str_pad('In Train', 14) . ' ';
                $current_track = '';
                $current_spot = '';
              }

              // columns 2 reporting marks
              print str_pad(substr($row['reporting_marks'], 0, 11), 11) . ' ';

              // column 3 - L/E
              if (($row['status'] == "Empty") || ($row['status'] == "Ordered"))
              {
                print ' E  ';
                $empties++;
              }
              elseif ($row['status'] == "Loaded")
              {
                print ' L  ';
                $loads++;
              }

              // column 4 car code
              print str_pad(substr($row['car_code'], 0, 4), 4) . ' ';

              // column 5 - consignment
              if ($row['status'] == "Loaded")
              {
                print str_pad(substr($row['consignment'], 0, 13), 13) . ' ';
              }
              else
              {
                print str_repeat(' ', 13) . ' ';
              }

              // columns 6 and 7 - destination station, location, track, and spot
              if (($row['status'] == "Empty") || ($row['status'] == "Ordered"))
              {
                // if the commodity column is empty, this is a non revenue move and the car's destination
                // is the unloading location
                if ($row['consignment_id'] <= 0)
                {
                  print '<span style="' . set_colors($dbc, $row['unloading_location']) . '">' . str_pad(substr($row['unloading_station'], 0, 14), 14) . '</span> ';
                  print str_pad(substr($row['unloading_location'], 0, 14), 14);
                  $sql3 = 'select track, spot from locations where code = "' . $row['unloading_location'] . '"';
                  $rs3 = mysqli_query($dbc, $sql3);
                  $row3 = mysqli_fetch_array($rs3);
                  $destination_track = $row3['track'];
                  $destination_spot = $row3['spot'];
                  $style_color = set_colors($dbc, $row['unloading_location']);
                }
                else
                {
                  print '<span style="' . set_colors($dbc, $row['loading_location']) . '">' . str_pad(substr($row['loading_station'], 0, 14), 14) . '</span> ';
                  print str_pad(substr($row['loading_location'], 0, 14), 14);
                  $sql2 = 'select track, spot from locations where code = "' . $row['loading_location'] . '"';
                  $rs2 = mysqli_query($dbc, $sql2);
                  $row2 = mysqli_fetch_array($rs2);
                  $destination_track = $row2['track'];
                  $destination_spot = $row2['spot'];
                  $style_color = set_colors($dbc, $row['loading_location']);
                }
              }
              elseif ($row['status'] == "Loaded")
              {
                print '<span style="' . set_colors($dbc, $row['unloading_location']) . '">' . str_pad(substr($row['unloading_station'], 0, 14), 14) . '</span> ';
                print str_pad(substr($row['unloading_location'], 0, 14), 14);
                $sql3 = 'select track, spot from locations where code = "' . $row['unloading_location'] . '"';
                $rs3 = mysqli_query($dbc, $sql3);
                $row3 = mysqli_fetch_array($rs3);
                $destination_track = $row3['track'];
                $destination_spot = $row3['spot'];
                $style_color = set_colors($dbc, $row['unloading_location']);
              }

              print '<br />';

              // generate the second line for each car ---------------------------------------------------------------------------------------------------

              // column 1 - display the current track
              if (strlen(trim($current_spot)) > 0)
              {
                print str_pad(substr($current_track, 0, 9) . '/' . substr($current_spot, 0, 4), 14) . ' ';
              }
              else
              {
                print str_pad(substr($current_track, 0, 9), 14) . ' ';
              }

              // skip past the next three fields
              print str_repeat(' ', 20) . ' ';

              // column 5 - display a special instructions reminder if one exists
              if (strlen($row['special_instructions']) > 0)
              {
                print 'See Spec Inst ';
                $special_instructions[$special_instruction_counter][0] = $row['reporting_marks'];
                $special_instructions[$special_instruction_counter][1] = $row['consignment'];
                $special_instructions[$special_instruction_counter][2] = $row['special_instructions'];
                $special_instruction_counter++;
              }
              else
              {
                print str_repeat(' ', 13) . ' ';
              }

              // skip column 6
              print str_repeat(' ', 14) . ' ';

              // column 7 - destination location, track and spot
              if (strlen(trim($destination_spot)) > 0)
              {
                print str_pad(substr($destination_track, 0, 9) . '/' . substr($destination_spot, 0, 4), 14);
              }
              else
              {
                print str_pad(substr($destination_track, 0, 14), 14);
              }

              // print two blank lines between cars for improved readability
              print '<br /><br />';

              // increment the row number
              $row_num = $row_num + 3;
            }

            // display the number of loads and empties
            $total_cars = $loads + $empties;
            print 'Loads: ' . $loads . '<br />';
            print 'Empties: ' . $empties . '<br />';
            print 'Total cars: ' . $total_cars . '<br />';

            // generate a page break
            if ($special_instruction_counter > 0)
            {
              print '<p style="page-break-after: always;">&nbsp;</p>';
              print str_pad(' Special Instructions ', $page_width, '-', STR_PAD_BOTH) . '<br /><br />';
              for ($i=0; $i<$special_instruction_counter; $i++)
              {
                $special_instruction_string = $special_instructions[$i][0] . ' (' . $special_instructions[$i][1] . ') ' . $special_instructions[$i][2];
                print_chunks($special_instruction_string, $page_width);
              }
            }

           // generate a page break
            print '<p style="page-break-after: always;">&nbsp;</p>';
            print '<div class="noprint">';
            print '<hr />';
            print '</div>';
            print '</pre>';
          }
  }
  else
  {
    print '<p style="font-family: verdana;">';
    print 'No switchlist found for ' . $table_name . '<br />';
    print '</p>';
  }
  echo '</div>';
  exit;
}

require_once 'page_chrome.php';
sts_page_start([
  'title' => 'Switchlist',
  'icon' => 'bi-list-check',
  'section' => 'report',
  'head_extra' => '<link href="report_page.css?v=1" rel="stylesheet"><link href="report_switchlist.css?v=2" rel="stylesheet">',
]);
?>
    <div class="sts-card report-screen-only">
    <form id="report-form" method="get">

    <p>Select job and display/print format and then click the <b>Display</b> button.
    The switch list is displayed below, formatted for printing.</p>

    <?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';

      // generate a drop-down list of jobs and the submit button
      print '<div class="mb-3"><label class="form-label" for="job_name">Job</label>';
      print drop_down_jobs('job_name', '', '');
      print '</div>';
      print '<div class="mb-3">';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="mobile" value="mobile"> <label class="form-check-label" for="mobile">Mobile</label></div>';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="half"  value="half" checked> <label class="form-check-label" for="half">Half Sheet</label></div>';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="full"  value="full"> <label class="form-check-label" for="full">Full Sheet</label></div>';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="dmp"  value="dmp"> <label class="form-check-label" for="dmp">Dot Matrix</label></div>';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="wo"  value="wo"> <label class="form-check-label" for="wo">Work Order</label></div>';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="x2010"  value="x2010"> <label class="form-check-label" for="x2010">X2010</label></div>';
      print '</div>';
      print '<button id="display_btn" name="display_btn" value="DISPLAY" type="submit" class="btn btn-primary" disabled><i class="bi bi-eye"></i> Display</button>';
    ?>
    </form>
    </div>

    <div id="report-card" class="sts-card report-card" hidden>
      <div class="report-controls report-screen-only">
        <button type="button" class="btn btn-primary btn-sm" data-sts-print><i class="bi bi-printer"></i> Print</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="report-modify"><i class="bi bi-pencil"></i> Modify Search</button>
      </div>
      <div id="report-content" class="report-output"></div>
    </div>
<script src="/sts/js/report-page.js?v=3"></script>
<script src="/sts/js/report-switchlist.js?v=2"></script>
<?php sts_page_end(); ?>
