<!DOCTYPE html>
<html>
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Wheel Reports</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
      body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        background-color: #f8f9fa;
      }
      .navbar-brand {
        font-weight: 600;
        font-size: 1.3rem;
      }
      .table-responsive {
        border-radius: 0.375rem;
      }
      .table {
        margin-bottom: 0;
      }
      .table thead th {
        background-color: #4a90e2;
        color: white;
        font-weight: 600;
        border: none;
        padding: 12px;
      }
      .table tbody td {
        padding: 10px 12px;
        vertical-align: middle;
        border-bottom: 1px solid #dee2e6;
      }
      .table tbody tr:hover {
        background-color: #f8f9fa;
      }
      .table-summary {
        background-color: #e8f0fe;
        font-weight: 600;
      }
      .job-header {
        background-color: #4a90e2;
        color: white;
        padding: 12px;
      }
      .job-section {
        margin-bottom: 2rem;
        border-radius: 0.375rem;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
      }
      .print-header {
        text-align: center;
        padding: 1rem 0;
        border-bottom: 2px solid #dee2e6;
        margin-bottom: 1.5rem;
      }
      .print-header h2 {
        margin: 0.5rem 0;
        color: #333;
      }
      .print-header h3 {
        margin: 0.25rem 0;
        color: #666;
      }
      .print-header small {
        color: #999;
      }
      @media print {
        .noprint {display:none !important;}
        body { background-color: white; }
        .job-section { box-shadow: none; }
        .print-header { border-bottom: 1px solid #333; }
        .btn { display: none; }
      }
      .status-empty { background-color: #ffeaa7; padding: 2px 6px; border-radius: 3px; }
      .status-loaded { background-color: #a8e6cf; padding: 2px 6px; border-radius: 3px; }
    </style>
    <?php
      // bring in the javascript function that shows rollingstock photos
      require 'show_image.php';
    ?>
  </head>
  <body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-dark bg-primary noprint">
      <div class="container-fluid">
        <span class="navbar-brand"><i class="bi bi-graph-up"></i> Wheel Reports</span>
        <div>
          <a href="index.html" class="btn btn-outline-light btn-sm me-2"><i class="bi bi-house"></i> Home</a>
          <a href="reports.html" class="btn btn-outline-light btn-sm me-2"><i class="bi bi-file-text"></i> Reports</a>
          <button class="btn btn-light btn-sm me-2" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
          <a href="index-t.html" class="btn btn-outline-light btn-sm"><i class="bi bi-diagram-3"></i> Site Map</a>
        </div>
      </div>
    </nav>

    <!-- Header Section -->
    <div class="noprint">
      <div class="container-fluid mt-4">
        <div class="card mb-4">
          <div class="card-body">
            <h4 class="card-title"><i class="bi bi-info-circle"></i> About This Report</h4>
            <p class="card-text">
              These wheel reports contain a list of all cars currently assigned to a job/train. All assigned cars are included
              whether they have been picked up or not. Cars that have been picked up are shown as "In Train". Cars that were
              previously picked up and have since been set out are not included. This report is only valid as of the date and
              time that it was generated as cars may have been picked up and/or set out since the report was created.
            </p>
          </div>
        </div>
      </div>
    </div>
    <?php
      // bring in the utility files
      require 'open_db.php';
      require 'set_colors.php';

      // get a database connection
      $dbc = open_db();

      // get the print width from the settings table
      $sql = 'select setting_value from settings where setting_name = "print_width"';
      $rs = mysqli_query($dbc, $sql);
      $row = mysqli_fetch_row($rs);
      $print_width = $row[0];

      // get the railroad name from the settings table
      $sql = 'select setting_value from settings where setting_name = "railroad_name"';
      $rs = mysqli_query($dbc, $sql);
      $row = mysqli_fetch_row($rs);
      $rr_name = $row[0];

      // get a list of all the jobs/trains
      $sql1 = 'select id, name, description from jobs order by name';
      $rs1 = mysqli_query($dbc, $sql1);
      if (mysqli_num_rows($rs1))
      {
        // print report header
        print '<div class="container-fluid mt-4 mb-4">';

        // Report title section
        print '<div class="print-header">
                 <h2>' . $rr_name . '</h2>
                 <h3>Wheel Reports for All Jobs/Trains</h3>
                 <small>Report generated: ' . date('H:i l d M Y') . '</small>
               </div>';

        // loop through each job/train
        while ($row1 = mysqli_fetch_array($rs1))
        {
          // print the train header info
          print '<div class="job-section">
                   <div class="job-header">
                     <div class="row">
                       <div class="col-md-6">
                         <h5 style="margin: 0;"><i class="bi bi-train-freight"></i> ' . $row1['name'] . '</h5>
                       </div>
                       <div class="col-md-6">
                         <p style="margin: 0; font-size: 0.9rem;">' . nl2br($row1['description']) . '</p>
                       </div>
                     </div>
                   </div>
                   <div class="table-responsive">';

          print '<table class="table">
                   <thead>
                     <tr>
                       <th style="width: 5%;">Seq</th>
                       <th style="width: 12%;">Reporting<br/>Marks</th>
                       <th style="width: 8%;">Type</th>
                       <th style="width: 6%;">L/E</th>
                       <th style="width: 15%;">Commodity</th>
                       <th style="width: 27%;">Pick Up At</th>
                       <th style="width: 27%;">Destination</th>
                     </tr>
                   </thead>
                   <tbody>';

          //
          $sql2 = 'select jobs.id as job_id,
                        jobs.name as job_name,
                        cars.id as car_id,
                        cars.position as position,
                        cars.reporting_marks as reporting_marks,
                        car_codes.code as car_code,
                        cars.status as status,
                        cars.remarks as car_remarks,
                        loc01.code as pickup_location,
                        ifnull(sta01.station, "In Train") as pickup_station,
                        loc02.code as unloading_location,
                        sta02.station as unloading_station,
                        commodities.code as commodity,
                        car_orders.shipment as shipment_id,
                        car_orders.waybill_number as wb_number,
                        `' . $row1['name'] . '`.step_number as job_step

                   from jobs

                   left join cars on jobs.id = cars.handled_by_job_id
                   left join car_codes on cars.car_code_id = car_codes.id
                   left join car_orders on car_orders.car = cars.id
                   left join shipments on shipments.id = car_orders.shipment
                   left join locations loc01 on cars.current_location_id = loc01.id
                   left join routing sta01 on loc01.station = sta01.id
                   left join locations loc02 on shipments.unloading_location = loc02.id
                   left join routing sta02 on loc02.station = sta02.id
                   left join commodities on commodities.id = shipments.consignment
                   left join `' . $row1['name'] . '` on `' . $row1['name'] . '`.station = sta01.id

                   where jobs.name = "' . $row1['name'] . '"

                   group by job_id, job_name, car_id

                   order by jobs.name, job_step, pickup_location, reporting_marks';
// print '<br />SQL2: ' . $sql2 . '<br />';
          $rs2 = mysqli_query($dbc, $sql2);
          if (mysqli_num_rows($rs2))
          {
            // count cars
            $empties = 0;
            $loads = 0;

            // generate a row in the table for each car
            while ($row2 = mysqli_fetch_array($rs2))
            {
              // if this row contains a car, generate a table row
              if ($row2['car_id'] > 0)
              {
                // set up a link to this car's image if it exists
                if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row2['car_id'] . '.jpg'))
                {
                  $parm_string = '\'' . $row2['car_id'] . '\', \'' . $row2['reporting_marks'] . '\'';
                }
                else
                {
                  $parm_string = '\'\',\'' . $row2['reporting_marks'] . '\'';
                }

                // compress the load/empty column
                if (($row2['status'] == "Empty") || ($row2['status'] == "Ordered"))
                {
                  $car_status = '<span class="status-empty">E</span>';
                  $empties++;
                }
                else if ($row2['status'] == "Loaded")
                {
                  $car_status = '<span class="status-loaded">L</span>';
                  $loads++;
                }
                else
                {
                  $car_status = "";
                }

                //watch for empty car moves
                if ($row2['status'] == "Ordered")
                {
                  $commodity = 'Ordered';
                  if (substr($row2['wb_number'], 4, 1) != "E")
                  {
                    $sql3 = 'select routing.station as dest_station, locations.code as dest_location
                               from routing, locations, shipments
                              where routing.id = locations.station
                                and locations.id = shipments.loading_location
                                and shipments.id = ' . $row2['shipment_id'];
// print '<br />SQL3: ' . $sql3 . '<br /><br />';
                    $rs3 = mysqli_query($dbc, $sql3);
                    $row3 = mysqli_fetch_array($rs3);
                    $destination = '<strong>' . $row3['dest_station'] . '</strong><br />' . $row3['dest_location'];
                  }
                  else
                  {
                    $sql3 = 'select routing.station as dest_station, locations.code as dest_location
                               from routing, locations, shipments
                              where routing.id = locations.station
                                and locations.id = ' . $row2['shipment_id'];
                    $rs3 = mysqli_query($dbc, $sql3);
                    $row3 = mysqli_fetch_array($rs3);
                    $destination = '<strong>' . $row3['dest_station'] . '</strong><br />' . $row3['dest_location'];
                  }
                }
                else
                {
                  $commodity = $row2['commodity'];
                  $destination = '<strong>' . $row2['unloading_station'] . '</strong><br />' . $row2['unloading_location'];
                }

                print '<tr>
                         <td class="text-center">' . $row2['position'] . '</td>
                         <td style="cursor: pointer;" onclick="show_image(' . $parm_string . ');">' . $row2['reporting_marks'] . '</td>
                         <td class="text-center">' . $row2['car_code'] . '</td>
                         <td class="text-center">' . $car_status . '</td>
                         <td>' . $commodity . '</td>
                         <td><strong>' . $row2['pickup_station'] . '</strong><br />' . $row2['pickup_location'] . '</td>
                         <td>' . $destination . '</td>
                       </tr>';
              }
              else
              {
                // generate a "no cars" row
                print '<tr><td colspan="7"><span class="badge bg-info">No cars assigned to this job/train</span></td></tr>';
              }
            }

            $cars = $loads + $empties;
            if ($cars > 0)
            {
              print '<tr class="table-summary"><td colspan="7">
                       <i class="bi bi-check-circle"></i> ' . $loads . ' Loaded / ' . $empties . ' Empty / ' . $cars . ' Total Car(s)
                     </td></tr>';
            }
            print '</tbody></table>
                   </div>
                 </div>';
          }
          else
          {
            // no cars in this job/train
            print '<div class="job-section">
                     <div class="job-header">
                       <h5 style="margin: 0;"><i class="bi bi-train-freight"></i> ' . $row1['name'] . '</h5>
                     </div>
                     <div class="alert alert-info mb-0" style="border-radius: 0;">
                       No cars assigned to this job/train
                     </div>
                   </div>';
          }
        }
        // close the main container
        print '</div>';
      }
      else
      {
        // no jobs/trains found
        print '<div class="container-fluid mt-4">
                 <div class="alert alert-warning" role="alert">
                   <i class="bi bi-exclamation-triangle"></i> No jobs/trains found in the system.
                 </div>
               </div>';
      }
    ?>
    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  </body>
</html>
