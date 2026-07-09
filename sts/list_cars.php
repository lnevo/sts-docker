<?php
require 'open_db.php';
require 'drop_down_list_functions.php';

$dbc = open_db();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>STS - Cars</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="operating_session_line.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script src="sorttable.js"></script>
  <style>
    body {
      background-color: #f8f9fa;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }
    .navbar-db { background-color: #795548; }
    .cars-page .card {
      border: none;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    }
    .cars-page-title {
      font-size: 1.1rem;
      font-weight: 600;
      color: #333;
      margin-bottom: 0.75rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .cars-page-title i { color: #795548; }

    /* ── Fleet overview dashboard ───────────────────── */
    .fleet-overview-wrap {
      margin-bottom: 1.25rem;
    }
    .fleet-overview-heading {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem 1rem;
      flex-wrap: wrap;
      margin-bottom: 1rem;
      padding-bottom: 0.25rem;
    }
    .fleet-overview-title {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      font-weight: 600;
      font-size: 1rem;
      color: #333;
    }
    .fleet-overview-title i {
      color: #795548;
      font-size: 1.1rem;
    }
    .fleet-overview-chips {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-wrap: wrap;
    }
    .fleet-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.2rem;
      padding: 0.2rem 0.65rem;
      border-radius: 999px;
      background: #fff;
      border: 1px solid #dee2e6;
      font-size: 0.78rem;
      color: #555;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
      white-space: nowrap;
    }
    .fleet-chip-available {
      border-color: #a8e6cf;
      background: #f0fff8;
      color: #2e7d32;
    }
    .fleet-columns > .col {
      min-width: 0;
      display: flex;
    }
    @media (min-width: 768px) {
      .fleet-columns {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
      .fleet-columns > .col {
        width: 100%;
        max-width: 100%;
      }
    }
    .fleet-group {
      border-radius: 6px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
      overflow: hidden;
      background: #fff;
      width: 100%;
      min-width: 0;
    }
    .fleet-section-header {
      background-color: #795548;
      color: white;
      font-weight: 600;
      padding: 10px 16px;
      font-size: 0.95rem;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .fleet-stat-row {
      display: grid;
      gap: 10px;
      padding: 12px;
    }
    .fleet-stat-row-4 {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }
    .fleet-stat-row-3 {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .fleet-stat-tile {
      text-align: center;
      padding: 10px 6px;
      background: #fff;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      min-height: 72px;
      min-width: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
      transition: background-color 0.15s ease, box-shadow 0.15s ease;
    }
    .fleet-stat-tile:hover {
      background-color: #faf8f6;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
    }
    .fleet-stat-label {
      font-size: 0.65rem;
      color: #888;
      line-height: 1.2;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 100%;
    }
    .fleet-stat-value {
      font-size: 1.15rem;
      font-weight: 700;
      line-height: 1.2;
      margin-top: 2px;
    }
    .fleet-stat-value-default { color: #795548; }
    .fleet-stat-value-success { color: #2e7d32; }
    .fleet-stat-value-warning { color: #b8860b; }
    .fleet-stat-value-danger { color: #c0392b; }
    .fleet-stat-value-info { color: #0d6efd; }
    .fleet-stat-value-primary { color: #4a90e2; }
    .fleet-stat-value-muted { color: #6c757d; }

    @media (max-width: 576px) {
      .cars-page-title { font-size: 1rem; }
      .fleet-overview-heading {
        flex-direction: column;
        align-items: flex-start;
      }
      .fleet-stat-row-4,
      .fleet-stat-row-3 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
  </style>
</head>
<body class="has-operating-session-line">
  <nav class="navbar navbar-dark navbar-db noprint">
    <div class="container-fluid">
      <span class="navbar-brand"><i class="bi bi-boxcar-front"></i> Cars</span>
      <div>
        <a href="database.html" class="btn btn-outline-light btn-sm me-2">
          <i class="bi bi-database"></i> Database
        </a>
        <a href="index.html" class="btn btn-outline-light btn-sm me-2">
          <i class="bi bi-house"></i> Home
        </a>
        <a href="operations.html" class="btn btn-outline-light btn-sm me-2">
          <i class="bi bi-train-freight-front"></i> Operations
        </a>
        <a href="index-t.html" class="btn btn-outline-light btn-sm">
          <i class="bi bi-diagram-3"></i> Site Map
        </a>
      </div>
    </div>
  </nav>

  <div class="container-fluid mt-3 px-3 px-md-4 cars-page">
    <div class="cars-page-title">
      <i class="bi bi-boxcar-front"></i>
      <span>Fleet Roster</span>
    </div>

    <form method="post" action="db_list.php">
      <input name="tbl_name" id="tbl_name" value="cars" type="hidden">
      <h3 id="table_name" class="visually-hidden">Cars</h3>
      <div id="instructions" class="d-none">
        To add a new item, fill in the input items and click on the <b>UPDATE</b> button.
        To edit or remove an item, click on its link.
      </div>
      <div id="update">
        <input id="update_btn" name="update_btn" value="UPDATE" type="submit" class="d-none">
      </div>

      <?php require 'db_list_cars.php'; ?>
    </form>
  </div>

  <p class="operating-session-line mb-0">Operating Session: <strong id="operatingSessionNumber"></strong></p>

  <script src="operating_session_line.js"></script>
</body>
</html>
