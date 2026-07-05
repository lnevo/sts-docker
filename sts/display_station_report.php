<?php
// Handle report generation request first, before any HTML output
if (isset($_GET['generate_report'])) {
  require 'drop_down_list_functions.php';
  require 'open_db.php';
  require 'set_colors.php';

  $station_id = $_GET['station_name'] ?? 0;
  $hide_unavail = isset($_GET['hide_unavail']) ? ' and cars.status != "Unavailable" ' : '';

  // Get database connection
  $dbc = open_db();

  // Get print width from settings
  $sql = 'select setting_value from settings where setting_name = "print_width"';
  $rs = mysqli_query($dbc, $sql);
  $row = mysqli_fetch_row($rs);
  $print_width = $row[0] ?? '100%';

  // Get railroad name from settings
  $sql = 'select setting_value from settings where setting_name = "railroad_name"';
  $rs = mysqli_query($dbc, $sql);
  $row = mysqli_fetch_row($rs);
  $rr_name = $row[0] ?? 'Railroad';

  if ($station_id > 0) {
    // Single station report
    $sql = 'select station from routing where id = "' . $station_id . '"';
    $rs = mysqli_query($dbc, $sql);
    $row = mysqli_fetch_row($rs);
    $station_name = $row[0] ?? 'Unknown Station';

    $sql = 'select cars.current_location_id as current_location_id,
                   cars.position as position,
                   cars.reporting_marks as reporting_marks,
                   cars.id as id,
                   cars.car_code_id as car_code_id,
                   cars.status as status,
                   shipments.remarks as remarks,
                   commodities.code as consignment,
                   car_orders.waybill_number as waybill_number,
                   car_orders.shipment as shipment_id,
                   car_codes.code as car_code,
                   loc01.code as current_location,
                   loc02.code as loading_location,
                   loc03.code as unloading_location,
                   sta02.station as loading_station,
                   sta03.station as unloading_station,
                   jobs.name as job_name,
                   loc04.code as home_location,
                   sta04.station as home_station
            from cars
            left join car_orders on car_orders.car = cars.id
            left join shipments on shipments.id = car_orders.shipment
            left join car_codes on car_codes.id = cars.car_code_id
            left join commodities on commodities.id = shipments.consignment
            left join locations loc01 on loc01.id = cars.current_location_id
            left join locations loc02 on loc02.id = shipments.loading_location
            left join locations loc03 on loc03.id = shipments.unloading_location
            left join locations loc04 on loc04.id = cars.home_location
            left join routing sta02 on sta02.id = loc02.station
            left join routing sta03 on sta03.id = loc03.station
            left join routing sta04 on sta04.id = loc04.station
            left join jobs on jobs.id = cars.handled_by_job_id
            where cars.current_location_id in (select id from locations where station = "' . $station_id . '")' . $hide_unavail . '
            order by current_location, cars.position, cars.reporting_marks';

    $rs = mysqli_query($dbc, $sql);
    if (mysqli_num_rows($rs) > 0) {
      // Collect all rows; pre-fetch destinations for non-revenue cars
      $rows = [];
      while ($row = mysqli_fetch_array($rs)) {
        if (substr($row['waybill_number'], 4, 1) == "E") {
          $sql2 = 'select locations.code as code, routing.station as station
                   from locations, routing
                   where locations.id = "' . $row['shipment_id'] . '" and locations.station = routing.id';
          $rs2 = mysqli_query($dbc, $sql2);
          $row2 = mysqli_fetch_array($rs2);
          $row['dest_code']    = $row2['code']    ?? '';
          $row['dest_station'] = $row2['station'] ?? '';
        }
        $rows[] = $row;
      }
      ?>
      <div class="print-header">
        <h2><?php echo htmlspecialchars($rr_name); ?></h2>
        <h3>Station Car Report - Cars on Hand</h3>
        <h3><?php echo htmlspecialchars($station_name); ?></h3>
        <small><em>If a car is enroute, the next destination in the route is shown in <strong>Bold</strong> letters.</em></small>
      </div>

      <?php
      // Pickup summary — grouped by service; shown first (and is what prints)
      $handled_rows = array_filter($rows, fn($r) => !empty($r['job_name']));
      if (!empty($handled_rows)) {
        $by_job = [];
        foreach ($handled_rows as $r) { $by_job[$r['job_name']][] = $r; }
        ksort($by_job);
        ?>
        <div class="pickup-summary-section">
          <h4><i class="bi bi-arrow-up-circle"></i> Cars to be Picked Up by Service</h4>
          <?php $total_jobs = count($by_job); $page_num = 0; ?>
          <?php foreach ($by_job as $job_name => $job_rows): $page_num++; ?>
          <div class="job-group">
          <div class="job-print-header">
            <div class="job-print-header-left"><?= htmlspecialchars($rr_name) ?> &mdash; Station Car Report &mdash; <?= htmlspecialchars($station_name) ?></div>
            <div class="job-print-header-right">Page <?= $page_num ?> of <?= $total_jobs ?></div>
          </div>
          <div class="job-group-header"><?= htmlspecialchars($job_name) ?></div>
          <div class="table-responsive">
          <table class="table table-sm report-table mb-3">
            <thead>
              <tr>
                <th>Location</th>
                <th>Reporting Marks</th>
                <th>Car Code</th>
                <th>Status</th>
                <th>Consignment</th>
                <th>Destination</th>
                <th>Handled by</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($job_rows as $r):
                if (substr($r['waybill_number'], 4, 1) == "E") {
                  $dest       = htmlspecialchars($r['dest_station']) . '<br/>' . htmlspecialchars($r['dest_code']);
                  $dest_style = set_colors($dbc, $r['dest_code']);
                } elseif ($r['status'] == "Ordered") {
                  $dest       = htmlspecialchars($r['loading_station']) . '<br/>' . htmlspecialchars($r['loading_location']);
                  $dest_style = set_colors($dbc, $r['loading_location']);
                } elseif (in_array($r['status'], ["Loading", "Loaded", "Unloading"])) {
                  $dest       = htmlspecialchars($r['unloading_station']) . '<br/>' . htmlspecialchars($r['unloading_location']);
                  $dest_style = set_colors($dbc, $r['unloading_location']);
                } else {
                  $dest = ''; $dest_style = '';
                }
              ?>
              <tr>
                <td><?= htmlspecialchars($r['current_location']) ?></td>
                <td><?= htmlspecialchars($r['reporting_marks']) ?></td>
                <td><?= htmlspecialchars($r['car_code']) ?></td>
                <td><span class="status-<?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td><?= (substr($r['waybill_number'], 4, 1) == "E") ? 'Non-Revenue' : htmlspecialchars($r['consignment']) ?></td>
                <td style="<?= $dest_style ?>" class="<?= $dest ? 'destination-highlight' : '' ?>"><?= $dest ?></td>
                <td><?= htmlspecialchars($r['job_name']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php
      }
      ?>

      <div class="no-print-section">
      <div class="on-hand-section-header">All Cars On Hand</div>
      <div class="table-responsive">
      <table class="table table-sm report-table">
        <thead>
          <tr>
            <th>Location</th>
            <th>Reporting Marks</th>
            <th>Car Code</th>
            <th>Status</th>
            <th>Consignment</th>
            <th>Loading Station / Location</th>
            <th>Unloading Station / Location</th>
            <th>Remarks</th>
            <th>Handled by</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $prev_location = '';
          foreach ($rows as $row) {
            // Add location separator
            if ($row['current_location'] != $prev_location && $prev_location != '') {
              echo '<tr class="location-separator"><td colspan="9"></td></tr>';
            }
            $prev_location = $row['current_location'];

            echo '<tr>';

            // Determine image parameter string
            $parm_string = file_exists('./ImageStore/DB_Images/RollingStock/' . $row['id'] . '.jpg')
              ? "'" . $row['id'] . "', '" . $row['reporting_marks'] . "'"
              : "'', '" . $row['reporting_marks'] . "'";

            if (substr($row['waybill_number'], 4, 1) == "E") {
              // Non-revenue waybill
              echo '<td>' . htmlspecialchars($row['current_location']) . '</td>';
              echo '<td onclick="show_image(' . $parm_string . ');" style="cursor: pointer;">' . htmlspecialchars($row['reporting_marks']) . '</td>';
              echo '<td>' . htmlspecialchars($row['car_code']) . '</td>';
              echo '<td><span class="status-' . strtolower($row['status']) . '">' . htmlspecialchars($row['status']) . '</span></td>';
              echo '<td>Non-Revenue</td>';
              echo '<td>N/A</td>';
              echo '<td style="' . set_colors($dbc, $row['dest_code']) . '" class="destination-highlight">' . htmlspecialchars($row['dest_station']) . '<br/>' . htmlspecialchars($row['dest_code']) . '</td>';
              echo '<td>Repositioning</td>';
              echo '<td>' . htmlspecialchars($row['job_name']) . '</td>';
            } else {
              // Revenue waybill
              echo '<td>' . htmlspecialchars($row['current_location']) . '</td>';
              echo '<td onclick="show_image(' . $parm_string . ');" style="cursor: pointer;">' . htmlspecialchars($row['reporting_marks']) . '</td>';
              echo '<td>' . htmlspecialchars($row['car_code']) . '</td>';
              echo '<td><span class="status-' . strtolower($row['status']) . '">' . htmlspecialchars($row['status']) . '</span></td>';
              echo '<td>' . htmlspecialchars($row['consignment']) . '</td>';

              // Loading location
              if ($row['status'] == "Ordered") {
                echo '<td style="' . set_colors($dbc, $row['loading_location']) . '" class="destination-highlight">' . htmlspecialchars($row['loading_station']) . '<br/>' . htmlspecialchars($row['loading_location']) . '</td>';
              } else if (in_array($row['status'], ["Loading", "Loaded", "Unloading"])) {
                echo '<td>' . htmlspecialchars($row['loading_station']) . '<br/>' . htmlspecialchars($row['loading_location']) . '</td>';
              } else {
                echo '<td></td>';
              }

              // Unloading location
              if (in_array($row['status'], ["Loading", "Loaded", "Unloading"])) {
                echo '<td style="' . set_colors($dbc, $row['unloading_location']) . '" class="destination-highlight">' . htmlspecialchars($row['unloading_station']) . '<br/>' . htmlspecialchars($row['unloading_location']) . '</td>';
              } else if ($row['status'] == "Ordered") {
                echo '<td></td>';
              } else {
                echo '<td></td>';
              }

              echo '<td>' . htmlspecialchars($row['remarks']) . '</td>';
              echo '<td>' . htmlspecialchars($row['job_name']) . '</td>';
            }

            echo '</tr>';
          }
          ?>
        </tbody>
      </table>
      </div>
      <p class="small text-muted mt-3"><em>If a car is enroute, the next destination in the route is shown in <strong>Bold</strong> letters.</em></p>
      </div>
    <?php
    } else {
      echo '<div class="alert alert-warning"><strong>No cars found</strong> at the selected location.</div>';
    }
  } else {
    // All stations report
    $sql = 'select cars.current_location_id as current_location_id,
                   cars.position as position,
                   cars.reporting_marks as reporting_marks,
                   cars.id as id,
                   cars.car_code_id as car_code_id,
                   cars.status as status,
                   shipments.remarks as remarks,
                   commodities.code as consignment,
                   car_orders.waybill_number as waybill_number,
                   car_orders.shipment as shipment_id,
                   car_codes.code as car_code,
                   loc01.code as current_location,
                   loc02.code as loading_location,
                   loc03.code as unloading_location,
                   sta02.station as loading_station,
                   sta03.station as unloading_station,
                   jobs.name as job_name,
                   loc04.code as home_location,
                   sta04.station as home_station,
                   routing.station as station_name,
                   routing.station_nbr as station_nbr
            from cars
            left join car_orders on car_orders.car = cars.id
            left join shipments on shipments.id = car_orders.shipment
            left join car_codes on car_codes.id = cars.car_code_id
            left join commodities on commodities.id = shipments.consignment
            left join locations loc01 on loc01.id = cars.current_location_id
            left join locations loc02 on loc02.id = shipments.loading_location
            left join locations loc03 on loc03.id = shipments.unloading_location
            left join locations loc04 on loc04.id = cars.home_location
            left join routing sta02 on sta02.id = loc02.station
            left join routing sta03 on sta03.id = loc03.station
            left join routing sta04 on sta04.id = loc04.station
            left join jobs on jobs.id = cars.handled_by_job_id
            left join routing on routing.id = loc01.station
            where cars.current_location_id > 0' . $hide_unavail . '
            order by routing.sort_seq, routing.station, current_location, cars.position, cars.reporting_marks';

    $rs = mysqli_query($dbc, $sql);
    if (mysqli_num_rows($rs) > 0) {
      ?>
      <div class="print-header">
        <h2><?php echo htmlspecialchars($rr_name); ?></h2>
        <h3>Station Car Report - Cars on Hand</h3>
        <h3>All Stations</h3>
        <small><em>If a car is enroute, the next destination in the route is shown in <strong>Bold</strong> letters.</em></small>
      </div>

      <?php
      // Collect all rows; pre-fetch destinations for non-revenue cars
      $rows = [];
      while ($row = mysqli_fetch_array($rs)) {
        if (substr($row['waybill_number'], 4, 1) == "E") {
          $sql2 = 'select locations.code as code, routing.station as station
                   from locations, routing
                   where locations.id = "' . $row['shipment_id'] . '" and locations.station = routing.id';
          $rs2 = mysqli_query($dbc, $sql2);
          $row2 = mysqli_fetch_array($rs2);
          $row['dest_code']    = $row2['code']    ?? '';
          $row['dest_station'] = $row2['station'] ?? '';
        }
        $rows[] = $row;
      }
      ?>
      <?php
      // Pickup summary — grouped by service; shown first (and is what prints)
      $handled_rows = array_filter($rows, fn($r) => !empty($r['job_name']));
      if (!empty($handled_rows)) {
        $by_job = [];
        foreach ($handled_rows as $r) { $by_job[$r['job_name']][] = $r; }
        ksort($by_job);
        ?>
        <div class="pickup-summary-section">
          <h4><i class="bi bi-arrow-up-circle"></i> Cars to be Picked Up by Service</h4>
          <?php $total_jobs = count($by_job); $page_num = 0; ?>
          <?php foreach ($by_job as $job_name => $job_rows): $page_num++; ?>
          <div class="job-group">
          <div class="job-print-header">
            <div class="job-print-header-left"><?= htmlspecialchars($rr_name) ?> &mdash; Station Car Report &mdash; All Stations</div>
            <div class="job-print-header-right">Page <?= $page_num ?> of <?= $total_jobs ?></div>
          </div>
          <div class="job-group-header"><?= htmlspecialchars($job_name) ?></div>
          <div class="table-responsive">
          <table class="table table-sm report-table mb-3">
            <thead>
              <tr>
                <th>Location</th>
                <th>Reporting Marks</th>
                <th>Car Code</th>
                <th>Status</th>
                <th>Consignment</th>
                <th>Destination</th>
                <th>Handled by</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($job_rows as $r):
                if (substr($r['waybill_number'], 4, 1) == "E") {
                  $dest       = htmlspecialchars($r['dest_station']) . '<br/>' . htmlspecialchars($r['dest_code']);
                  $dest_style = set_colors($dbc, $r['dest_code']);
                } elseif ($r['status'] == "Ordered") {
                  $dest       = htmlspecialchars($r['loading_station']) . '<br/>' . htmlspecialchars($r['loading_location']);
                  $dest_style = set_colors($dbc, $r['loading_location']);
                } elseif (in_array($r['status'], ["Loading", "Loaded", "Unloading"])) {
                  $dest       = htmlspecialchars($r['unloading_station']) . '<br/>' . htmlspecialchars($r['unloading_location']);
                  $dest_style = set_colors($dbc, $r['unloading_location']);
                } else {
                  $dest = ''; $dest_style = '';
                }
              ?>
              <tr>
                <td><?= htmlspecialchars($r['station_name']) ?></td>
                <td><?= htmlspecialchars($r['current_location']) ?></td>
                <td><?= htmlspecialchars($r['reporting_marks']) ?></td>
                <td><?= htmlspecialchars($r['car_code']) ?></td>
                <td><span class="status-<?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td><?= (substr($r['waybill_number'], 4, 1) == "E") ? 'Non-Revenue' : htmlspecialchars($r['consignment']) ?></td>
                <td style="<?= $dest_style ?>" class="<?= $dest ? 'destination-highlight' : '' ?>"><?= $dest ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php
      }
      ?>

      <div class="no-print-section">
      <div class="on-hand-section-header">All Cars On Hand</div>
      <div class="table-responsive">
      <table class="table table-sm report-table">
        <thead>
          <tr>
            <th>Station</th>
            <th>Location</th>
            <th>Reporting Marks</th>
            <th>Car Code</th>
            <th>Status</th>
            <th>Consignment</th>
            <th>Loading Station / Location</th>
            <th>Unloading Station / Location</th>
            <th>Remarks</th>
            <th>Handled by</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $prev_location = '';
          foreach ($rows as $row) {
            // Add location separator
            if ($row['current_location'] != $prev_location && $prev_location != '') {
              echo '<tr class="location-separator"><td colspan="10"></td></tr>';
            }
            $prev_location = $row['current_location'];

            echo '<tr>';

            // Determine image parameter string
            $parm_string = file_exists('./ImageStore/DB_Images/RollingStock/' . $row['id'] . '.jpg')
              ? "'" . $row['id'] . "', '" . $row['reporting_marks'] . "'"
              : "'', '" . $row['reporting_marks'] . "'";

            if (substr($row['waybill_number'], 4, 1) == "E") {
              // Non-revenue waybill
              echo '<td>' . htmlspecialchars($row['station_name']) . '</td>';
              echo '<td>' . htmlspecialchars($row['current_location']) . '</td>';
              echo '<td onclick="show_image(' . $parm_string . ');" style="cursor: pointer;">' . htmlspecialchars($row['reporting_marks']) . '</td>';
              echo '<td>' . htmlspecialchars($row['car_code']) . '</td>';
              echo '<td><span class="status-' . strtolower($row['status']) . '">' . htmlspecialchars($row['status']) . '</span></td>';
              echo '<td>Non-Revenue</td>';
              echo '<td>N/A</td>';
              echo '<td style="' . set_colors($dbc, $row['dest_code']) . '" class="destination-highlight">' . htmlspecialchars($row['dest_station']) . '<br/>' . htmlspecialchars($row['dest_code']) . '</td>';
              echo '<td>Repositioning</td>';
              echo '<td>' . htmlspecialchars($row['job_name']) . '</td>';
            } else {
              // Revenue waybill
              echo '<td>' . htmlspecialchars($row['station_name']) . '</td>';
              echo '<td>' . htmlspecialchars($row['current_location']) . '</td>';
              echo '<td onclick="show_image(' . $parm_string . ');" style="cursor: pointer;">' . htmlspecialchars($row['reporting_marks']) . '</td>';
              echo '<td>' . htmlspecialchars($row['car_code']) . '</td>';
              echo '<td><span class="status-' . strtolower($row['status']) . '">' . htmlspecialchars($row['status']) . '</span></td>';
              echo '<td>' . htmlspecialchars($row['consignment']) . '</td>';

              // Loading location
              if ($row['status'] == "Ordered") {
                echo '<td style="' . set_colors($dbc, $row['loading_location']) . '" class="destination-highlight">' . htmlspecialchars($row['loading_station']) . '<br/>' . htmlspecialchars($row['loading_location']) . '</td>';
              } else if (in_array($row['status'], ["Loading", "Loaded", "Unloading"])) {
                echo '<td>' . htmlspecialchars($row['loading_station']) . '<br/>' . htmlspecialchars($row['loading_location']) . '</td>';
              } else {
                echo '<td></td>';
              }

              // Unloading location
              if (in_array($row['status'], ["Loading", "Loaded", "Unloading"])) {
                echo '<td style="' . set_colors($dbc, $row['unloading_location']) . '" class="destination-highlight">' . htmlspecialchars($row['unloading_station']) . '<br/>' . htmlspecialchars($row['unloading_location']) . '</td>';
              } else if ($row['status'] == "Ordered") {
                echo '<td></td>';
              } else {
                echo '<td></td>';
              }

              echo '<td>' . htmlspecialchars($row['remarks']) . '</td>';
              echo '<td>' . htmlspecialchars($row['job_name']) . '</td>';
            }

            echo '</tr>';
          }
          ?>
        </tbody>
      </table>
      </div>
      <p class="small text-muted mt-3"><em>If a car is enroute, the next destination in the route is shown in <strong>Bold</strong> letters.</em></p>
      </div>
    <?php
    } else {
      echo '<div class="alert alert-warning"><strong>No cars found</strong> on the system.</div>';
    }
  }
  exit;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>STS - Station Car Report</title>
  <!-- Bootstrap CSS -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
  <!-- Lexend font - designed for reading ease / reading ease -->
  <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background-color: #f8f9fa;
    }
    .navbar-brand {
      font-weight: 600;
      font-size: 1.3rem;
    }
    .card {
      border: none;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    }
    .form-control, .form-select {
      border-radius: 0.375rem;
      border: 1.5px solid #dee2e6;
      min-height: 44px;
      font-size: 16px;
    }
    .form-control:focus, .form-select:focus {
      border-color: #80bdff;
      box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }
    .btn-display {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border: none;
      padding: 0.6rem 2rem;
      font-weight: 600;
    }
    .btn-display:hover {
      background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
      color: white;
    }
    .alert-info {
      background-color: #e7f3ff;
      border-color: #b3d9ff;
      color: #004085;
    }

    /* Report styling */
    #report-container {
      display: none;
    }
    #report-container.show {
      display: block;
    }

    .report-header {
      text-align: center;
      padding: 1rem 0;
      border-bottom: 2px solid #dee2e6;
      margin-bottom: 1.5rem;
    }
    .report-header h1 {
      margin: 0.5rem 0;
      color: #333;
    }
    .report-header h2 {
      margin: 0.25rem 0;
      color: #666;
    }
    .report-header h3 {
      margin: 0.25rem 0;
      color: #666;
    }
    .report-header p {
      color: #999;
    }

    .report-table {
      font-size: 0.8rem;
      margin-bottom: 0;
      width: 100%;
      border-collapse: collapse;
      table-layout: auto;
    }
    .report-table thead {
      background-color: #4a90e2;
    }
    .report-table th {
      color: white !important;
      font-weight: 600;
      padding: 6px 4px;
      border: none;
      background-color: #4a90e2;
      position: sticky;
      top: 0;
      z-index: 50;
      white-space: nowrap;
      font-size: 0.75rem;
    }
    .report-table td {
      padding: 6px 8px;
      border-bottom: 1px solid #dee2e6;
      vertical-align: middle;
    }
    .report-table tbody tr:hover {
      background-color: #f8f9fa;
    }

    .location-separator {
      height: 0.5rem;
    }
    .destination-highlight {
      background-color: #fff3cd;
      font-weight: 600;
    }

    /* Status badges */
    .status-empty {
      display: inline-block;
      background-color: #ffeaa7;
      color: #333;
      padding: 4px 8px;
      border-radius: 3px;
      font-weight: 600;
      font-size: 0.85rem;
    }
    .status-loaded {
      display: inline-block;
      background-color: #a8e6cf;
      color: #333;
      padding: 4px 8px;
      border-radius: 3px;
      font-weight: 600;
      font-size: 0.85rem;
    }
    .status-loading {
      display: inline-block;
      background-color: #74b9ff;
      color: white;
      padding: 4px 8px;
      border-radius: 3px;
      font-weight: 600;
      font-size: 0.85rem;
    }
    .status-unloading {
      display: inline-block;
      background-color: #fab1a0;
      color: white;
      padding: 4px 8px;
      border-radius: 3px;
      font-weight: 600;
      font-size: 0.85rem;
    }
    .status-ordered {
      display: inline-block;
      background-color: #dfe6e9;
      color: #333;
      padding: 4px 8px;
      border-radius: 3px;
      font-weight: 600;
      font-size: 0.85rem;
    }
    .status-unavailable {
      display: inline-block;
      background-color: #d63031;
      color: white;
      padding: 4px 8px;
      border-radius: 3px;
      font-weight: 600;
      font-size: 0.85rem;
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

    .print-controls {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 1rem;
    }

    .on-hand-section-header {
      font-size: 0.95rem;
      font-weight: 600;
      color: #555;
      border-left: 4px solid #adb5bd;
      padding: 4px 8px;
      margin: 1.25rem 0 0.5rem;
      background-color: #f8f9fa;
    }

    /* ── Easy Reading mode ─────────────────────────────── */
    body.reading-mode {
      font-family: 'Lexend', Arial, sans-serif !important;
      background-color: #fdf6e3 !important; /* warm cream background */
      color: #1a1a1a !important;
      letter-spacing: 0.05em;
      word-spacing: 0.15em;
      line-height: 1.8;
    }
    body.reading-mode .report-table {
      font-size: 0.95rem;
      font-family: 'Lexend', Arial, sans-serif !important;
    }
    body.reading-mode .report-table th {
      font-size: 0.9rem;
      padding: 10px 12px;
      letter-spacing: 0.04em;
    }
    body.reading-mode .report-table td {
      padding: 10px 12px;
      line-height: 1.6;
    }
    body.reading-mode .report-table tbody tr:nth-child(odd) {
      background-color: #fff9ee;
    }
    body.reading-mode .report-table tbody tr:nth-child(even) {
      background-color: #f0e9d8;
    }
    body.reading-mode .card {
      background-color: #fffdf5;
    }
    body.reading-mode .job-group-header {
      font-size: 1rem;
      padding: 6px 12px;
      letter-spacing: 0.04em;
    }
    body.reading-mode .on-hand-section-header {
      font-size: 1.05rem;
      letter-spacing: 0.04em;
    }
    body.reading-mode .status-empty,
    body.reading-mode .status-loaded,
    body.reading-mode .status-loading,
    body.reading-mode .status-unloading,
    body.reading-mode .status-ordered,
    body.reading-mode .status-unavailable {
      font-size: 0.9rem;
      padding: 5px 10px;
      letter-spacing: 0.03em;
    }
    #reading-btn.active {
      background-color: #e6b800;
      border-color: #c9a000;
      color: #1a1a1a;
    }

    .pickup-summary-section {
      margin-top: 1.5rem;
      border-top: 2px solid #4a90e2;
      padding-top: 1rem;
    }
    .pickup-summary-section h4 {
      color: #4a90e2;
      font-size: 1rem;
      font-weight: 600;
      margin-bottom: 0.75rem;
    }
    .job-group-header {
      background-color: #e8f0fe;
      border-left: 4px solid #4a90e2;
      padding: 4px 8px;
      font-weight: 600;
      font-size: 0.85rem;
      margin-bottom: 0;
    }
    .job-print-header {
      display: none;
    }

    /* Print page setup */
    @page {
      size: A5 landscape;
      margin: 0.25in;
    }

    /* Print media queries */
    @media print {
      body {
        background-color: white;
        font-family: "Courier New", monospace;
        font-size: 6pt;
      }
      .navbar, .print-controls, .form-card, .back-btn {
        display: none !important;
      }
      .container {
        max-width: 100% !important;
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
      }
      #report-container {
        box-shadow: none;
        border: none;
        margin: 0 !important;
        padding: 0 !important;
      }
      #report-container .card {
        box-shadow: none;
        border: none;
      }
      #report-container .card-body {
        padding: 0 !important;
      }
      .print-header {
        position: static;
        text-align: left;
        padding: 0.1rem 0;
        border-bottom: 1px dashed #000;
        margin-bottom: 0.25rem;
        background-color: transparent;
      }
      .print-header h2 {
        font-size: 7pt;
        margin: 0;
        font-weight: normal;
      }
      .print-header h3 {
        font-size: 6pt;
        margin: 0;
        font-weight: normal;
      }
      .print-header small {
        font-size: 5pt;
        display: block;
      }
      .report-table {
        page-break-inside: auto;
        font-family: 'Lexend', Arial, sans-serif !important;
        font-size: 6pt;
        border-collapse: collapse;
        width: 100%;
        table-layout: fixed;
      }
      .report-table thead {
        background-color: transparent;
        display: table-header-group;
      }
      .report-table th {
        color: #000 !important;
        background-color: transparent !important;
        font-weight: bold !important;
        padding: 2px 3px;
        border: 1px solid #000;
        text-align: left;
        font-size: 6pt;
        position: static;
        overflow: hidden;
        word-wrap: break-word;
        letter-spacing: 0.02em;
      }
      .report-table td {
        padding: 2px 3px;
        border: 1px solid #000;
        vertical-align: top;
        background-color: transparent !important;
        overflow: hidden;
        word-wrap: break-word;
        font-size: 6pt;
        letter-spacing: 0.01em;
      }
      .report-table tbody tr {
        page-break-inside: avoid;
      }
      .report-table tbody tr:nth-child(odd) {
        background-color: #f7f7e6;
      }
      .report-table tbody tr:nth-child(even) {
        background-color: #fff;
      }
      .location-separator {
        height: 0;
        display: none;
      }
      .destination-highlight {
        background-color: transparent !important;
        color: #000;
        font-weight: bold;
      }
      /* Hide the full on-hand table; only print the pickup summary */
      .no-print-section {
        display: none !important;
      }
      .print-header {
        display: none !important;
      }
      .job-print-header {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        border-bottom: 1px dashed #000;
        margin-bottom: 0.2rem;
        padding-bottom: 0.1rem;
      }
      .job-print-header-left {
        font-size: 6pt;
        font-weight: bold;
      }
      .job-print-header-right {
        font-size: 6pt;
        font-style: italic;
      }
      .pickup-summary-section {
        border-top: none;
        margin-top: 0;
        padding-top: 0;
      }
      .job-group {
        break-after: page;
      }
      .job-group:last-child {
        break-after: avoid;
      }
      .pickup-summary-section h4 {
        font-size: 6pt;
        color: #000;
        font-weight: bold;
        margin: 0.1rem 0;
        border: none;
      }
      .job-group-header {
        background-color: transparent !important;
        border-left: 2px solid #000;
        padding: 1px 3px;
        font-size: 5pt;
        font-weight: bold;
      }
      /* Hide status badges, show text only */
      .status-empty,
      .status-loaded,
      .status-loading,
      .status-unloading,
      .status-ordered,
      .status-unavailable {
        display: inline;
        background-color: transparent !important;
        color: #000 !important;
        padding: 0 !important;
        border-radius: 0 !important;
        font-weight: normal;
        font-size: 6pt;
      }
    }
  </style>
  <?php
    // bring in the javascript function that shows rollingstock photos
    require 'show_image.php';
  ?>
</head>
<body>
  <!-- Navigation Bar -->
  <nav class="navbar navbar-dark bg-primary no-print">
    <div class="container-fluid">
      <span class="navbar-brand"><i class="bi bi-graph-up"></i> Station Report</span>
      <div class="d-flex align-items-center gap-2">
        <button id="reading-btn" class="btn btn-outline-light btn-sm" onclick="toggleReadingMode()" title="Toggle easy reading mode">
          <i class="bi bi-eye"></i> Aa
        </button>
        <a href="index.html" class="btn btn-outline-light btn-sm me-2"><i class="bi bi-house"></i> Home</a>
        <a href="reports.html" class="btn btn-outline-light btn-sm me-2"><i class="bi bi-file-text"></i> Reports</a>
        <a href="index-t.html" class="btn btn-outline-light btn-sm"><i class="bi bi-diagram-3"></i> Site Map</a>
      </div>
    </div>
  </nav>

  <!-- Main Content -->
  <div class="container mt-5 mb-5">
    <!-- Form Section -->
    <div class="row justify-content-center">
      <div class="col-lg-6 form-card">
        <div class="card">
          <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-building"></i> Station Report of Cars On-Hand</h5>
          </div>
          <div class="card-body">
            <div class="alert alert-info" role="alert">
              <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Instructions</h6>
              <small>
                Select a station and click Display to view cars at that location. Use the browser's <strong>PRINT</strong> button (<kbd>Ctrl+P</kbd>) to print the report.
              </small>
            </div>

            <!-- Form -->
            <form id="station-report-form" method="get">
              <div class="mb-3">
                <label for="station_name" class="form-label"><strong>Select Station</strong></label>
                <?php
                  // bring in the utility files
                  require"drop_down_list_functions.php";
                  require "open_db.php";

                  // generate a drop-down list of stations and the submit button
                  print drop_down_stations("station_name", "form-select", "document.getElementById('display_btn').disabled = false;");

                  // generate some javascript that adds "All" to the top of the station drop-down list
                  print '<script>
                   var drop_down_list = document.getElementById("station_name");
                   var option = document.createElement("option");
                   option.value = "0";
                   option.text = "All";
                   drop_down_list.add(option, drop_down_list[1]);
                 </script>';
                ?>
              </div>

              <div class="mb-3 form-check">
                <input class="form-check-input" type="checkbox" id="hide_unavail" name="hide_unavail" checked>
                <label class="form-check-label" for="hide_unavail">
                  Hide Unavailable Cars
                </label>
              </div>

              <div class="d-grid gap-2 d-sm-flex justify-content-sm-between">
                <button id="display_btn" name="display_btn" type="submit" class="btn btn-display" disabled>
                  <i class="bi bi-eye"></i> Display Report
                </button>
                <a href="reports.html" class="btn btn-outline-secondary back-btn">
                  <i class="bi bi-arrow-left"></i> Back to Reports
                </a>
              </div>
            </form>
          </div>
        </div>

        <!-- Additional Info Card -->
        <div class="card mt-4">
          <div class="card-body">
            <h6 class="card-title"><i class="bi bi-lightbulb"></i> Tips</h6>
            <ul class="small mb-0">
              <li>Select "All" to see cars at all stations</li>
              <li>Check "Hide Unavailable Cars" to filter out unavailable units</li>
              <li>Use your browser's print function to print the report</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Report Section -->
    <div id="report-container" class="mt-5">
      <div class="row justify-content-center">
        <div class="col-lg-12">
          <div class="card">
            <div class="card-body" style="padding: 0.75rem;">
              <div class="print-controls">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print();">
                  <i class="bi bi-printer"></i> Print Report
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('report-container').classList.remove('show'); document.querySelector('.form-card').scrollIntoView({behavior:'smooth'});">
                  <i class="bi bi-pencil"></i> Modify Search
                </button>
                <button type="button" class="btn btn-warning btn-sm" id="reading-btn-report" onclick="toggleReadingMode()" title="Toggle easy reading mode">
                  <i class="bi bi-eye"></i> Reading Mode
                </button>
              </div>

              <div id="report-content"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Bootstrap JS -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

  <script>
    // ── Dyslexia mode ────────────────────────────────────────────
    function toggleReadingMode() {
      const enabled = document.body.classList.toggle('reading-mode');
      localStorage.setItem('reading-mode', enabled ? '1' : '0');
      syncDyslexiaBtns(enabled);
    }
    function syncDyslexiaBtns(enabled) {
      document.querySelectorAll('#reading-btn, #reading-btn-report').forEach(btn => {
        btn.classList.toggle('active', enabled);
      });
    }
    // Restore preference on load
    (function() {
      const saved = localStorage.getItem('reading-mode') === '1';
      if (saved) {
        document.body.classList.add('reading-mode');
        syncDyslexiaBtns(true);
      }
    })();

    document.getElementById('station-report-form').addEventListener('submit', function(e) {
      e.preventDefault();

      const stationName = document.getElementById('station_name').value;
      const hideUnavail = document.getElementById('hide_unavail').checked;

      // Build query parameters
      let params = new URLSearchParams();
      params.append('station_name', stationName);
      params.append('display_btn', '1');
      if (hideUnavail) {
        params.append('hide_unavail', '1');
      }

      // Fetch report content
      fetch('display_station_report.php?generate_report=1&' + params.toString())
        .then(response => response.text())
        .then(html => {
          document.getElementById('report-content').innerHTML = html;
          document.getElementById('report-container').classList.add('show');

          // Scroll to report
          setTimeout(() => {
            document.getElementById('report-container').scrollIntoView({behavior:'smooth'});
          }, 100);
        })
        .catch(error => {
          alert('Error loading report: ' + error);
        });
    });
  </script>
</body>
</html>
