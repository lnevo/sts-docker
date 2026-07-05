<?php
require 'open_db.php';
require 'operations_stats.php';

$dbc = open_db();
$stats = operations_get_stats($dbc);
$session_number = operations_get_session_nbr($dbc);
mysqli_close($dbc);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>STS - Operations Menu</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="operating_session_line.css" rel="stylesheet">
  <style>
    body { background-color: #f8f9fa; }
    .section-header {
      background-color: #2e7d32;
      color: white;
      font-weight: 600;
      padding: 10px 16px;
      border-radius: 6px 6px 0 0;
      font-size: 0.95rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .op-group {
      border-radius: 6px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
      overflow: hidden;
      width: 100%;
      min-width: 0;
    }
    .op-btn {
      display: grid;
      grid-template-columns: 26px minmax(0, 1fr) 5.25rem;
      align-items: center;
      gap: 12px;
      padding: 14px 16px;
      border-bottom: 1px solid #dee2e6;
      background: #fff;
      text-decoration: none;
      color: #333;
      transition: background-color 0.15s;
      min-width: 0;
    }
    .op-btn:last-child { border-bottom: none; }
    .op-btn:hover { background-color: #f0fff4; color: #333; text-decoration: none; }
    .op-btn i.op-icon { font-size: 1.4rem; color: #2e7d32; width: 26px; justify-self: center; }
    .op-btn-body { min-width: 0; }
    .op-btn-title {
      font-weight: 600;
      font-size: 0.95rem;
      line-height: 1.25;
      overflow-wrap: break-word;
    }
    .op-btn-stat-cols {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      padding-left: 12px;
      border-left: 1px solid #e9ecef;
      box-sizing: border-box;
      width: 5.25rem;
      min-width: 5.25rem;
      max-width: 5.25rem;
    }
    .op-stat-col {
      text-align: center;
      width: 100%;
    }
    .op-stat-label {
      font-size: 0.65rem;
      color: #888;
      line-height: 1.2;
      white-space: nowrap;
    }
    .op-stat-value {
      font-size: 1.15rem;
      font-weight: 700;
      color: #2e7d32;
      line-height: 1.2;
      margin-top: 2px;
    }
    .ops-columns > .col {
      min-width: 0;
      display: flex;
    }
    @media (min-width: 768px) {
      .ops-columns {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
      .ops-columns > .col {
        width: 100%;
        max-width: 100%;
      }
    }
  </style>
</head>
<body class="has-operating-session-line">
  <nav class="navbar navbar-dark noprint" style="background-color: #2e7d32;">
    <div class="container-fluid">
      <span class="navbar-brand"><i class="bi bi-train-freight-front"></i> Operations</span>
      <div>
        <a href="index.html" class="btn btn-outline-light btn-sm me-2">
          <i class="bi bi-house"></i> Home
        </a>
        <a href="database.html" class="btn btn-outline-light btn-sm me-2">
          <i class="bi bi-database"></i> Database
        </a>
        <a href="reports.html" class="btn btn-outline-light btn-sm me-2">
          <i class="bi bi-file-text"></i> Reports
        </a>
        <a href="index-t.html" class="btn btn-outline-light btn-sm">
          <i class="bi bi-diagram-3"></i> Site Map
        </a>
      </div>
    </div>
  </nav>

  <div class="container mt-4" style="max-width: 960px;">
    <div class="row g-4 ops-columns row-cols-1 row-cols-md-3">

      <!-- Before Operations -->
      <div class="col">
        <div class="op-group">
          <div class="section-header"><i class="bi bi-clock-history"></i> Before Operations</div>
          <a href="generate.php" class="op-btn">
            <i class="bi bi-gear op-icon"></i>
            <div class="op-btn-body">
              <div class="op-btn-title">Generate Car Orders</div>
            </div>
            <?php echo operations_render_stat_columns([['label' => 'Open', 'value' => $stats['open_orders']]]); ?>
          </a>
          <a href="fill_orders.php" class="op-btn">
            <i class="bi bi-box-seam op-icon"></i>
            <div class="op-btn-body">
              <div class="op-btn-title">Fill Car Orders</div>
            </div>
            <?php echo operations_render_stat_columns([['label' => 'Unfilled', 'value' => $stats['unfilled_orders']]]); ?>
          </a>
          <a href="reposition.php" class="op-btn">
            <i class="bi bi-arrows-move op-icon"></i>
            <div class="op-btn-body">
              <div class="op-btn-title">Reposition Empty Cars</div>
            </div>
            <?php echo operations_render_stat_columns([['label' => 'Not Home', 'value' => $stats['reposition_off_home']]]); ?>
          </a>
        </div>
      </div>

      <!-- During Operations -->
      <div class="col">
        <div class="op-group">
          <div class="section-header"><i class="bi bi-play-circle"></i> During Operations</div>
          <a href="build_switchlists.php" class="op-btn">
            <i class="bi bi-list-check op-icon"></i>
            <div class="op-btn-body">
              <div class="op-btn-title">Build Switch Lists</div>
            </div>
            <?php echo operations_render_stat_columns([['label' => 'Unassigned', 'value' => $stats['unassigned']]]); ?>
          </a>
          <a href="pick_up.php" class="op-btn">
            <i class="bi bi-arrow-up-circle op-icon"></i>
            <div class="op-btn-body">
              <div class="op-btn-title">Pick Up Cars</div>
            </div>
            <?php echo operations_render_stat_columns([['label' => 'Pending', 'value' => $stats['pending_pickup']]]); ?>
          </a>
          <a href="organize_cars.php" class="op-btn">
            <i class="bi bi-sort-numeric-up op-icon"></i>
            <div class="op-btn-body">
              <div class="op-btn-title">Organize Cars</div>
            </div>
            <?php echo operations_render_stat_columns([['label' => 'In Train', 'value' => $stats['in_train']]]); ?>
          </a>
          <a href="set_out.php" class="op-btn">
            <i class="bi bi-arrow-down-circle op-icon"></i>
            <div class="op-btn-body">
              <div class="op-btn-title">Set Out Cars</div>
            </div>
            <?php echo operations_render_stat_columns([['label' => 'Pending', 'value' => $stats['pending_setout']]]); ?>
          </a>
          <a href="track_scale.php" class="op-btn">
            <i class="bi bi-speedometer2 op-icon"></i>
            <div class="op-btn-body">
              <div class="op-btn-title">Track Scale</div>
            </div>
            <?php echo operations_render_stat_columns([['label' => 'To Weigh', 'value' => $stats['scale_to_weigh']]]); ?>
          </a>
        </div>
      </div>

      <!-- After Operations -->
      <div class="col">
        <div class="op-group">
          <div class="section-header"><i class="bi bi-check-circle"></i> After Operations</div>
          <a href="load_unload.php" class="op-btn">
            <i class="bi bi-truck op-icon"></i>
            <div class="op-btn-body">
              <div class="op-btn-title">Load / Unload Cars</div>
            </div>
            <?php echo operations_render_stat_columns([['label' => 'Pending', 'value' => $stats['load_unload_pending']]]); ?>
          </a>
        </div>
      </div>

    </div>
  </div>

  <p class="operating-session-line mb-0">Operating Session: <strong><?php echo htmlspecialchars((string)$session_number, ENT_QUOTES, 'UTF-8'); ?></strong></p>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</body>
</html>
