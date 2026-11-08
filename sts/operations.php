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
  <link href="vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
  <link href="vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
  <link href="operating_session_line.css" rel="stylesheet">
  <link href="operations_ui.css?v=3" rel="stylesheet">
</head>
<body class="has-operating-session-line ops-page-operations">
  <nav class="navbar navbar-dark noprint" style="background-color: #2e7d32;">
    <div class="container-fluid">
      <span class="navbar-brand"><i class="bi bi-train-freight-front"></i> Operations</span>
      <div>
        <a href="session_overview.php" class="btn btn-outline-light btn-sm me-2">
          <i class="bi bi-calendar-check"></i> Sessions
        </a>
        <a href="editor.html" class="btn btn-outline-light btn-sm me-2">
          <i class="bi bi-pencil-square"></i> Session Editor
        </a>
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
            <?php echo operations_render_stat_columns([['label' => 'Cars', 'value' => $stats['organize_unique']]]); ?>
          </a>
          <a href="set_out.php" class="op-btn">
            <i class="bi bi-arrow-down-circle op-icon"></i>
            <div class="op-btn-body">
              <div class="op-btn-title">Set Out Cars</div>
            </div>
            <?php echo operations_render_stat_columns([['label' => 'Pending', 'value' => $stats['pending_setout']]]); ?>
          </a>
          <?php echo plugins_render_operations_buttons('during', $stats); ?>
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
          <?php echo plugins_render_operations_buttons('after', $stats); ?>
        </div>
      </div>

    </div>
  </div>

  <p class="operating-session-line mb-0">Operating Session: <strong><?php echo htmlspecialchars((string)$session_number, ENT_QUOTES, 'UTF-8'); ?></strong></p>

  <script src="vendor/bootstrap/bootstrap.bundle.min.js"></script>
  <script src="vendor/jquery/jquery-3.6.0.min.js"></script>
</body>
</html>
