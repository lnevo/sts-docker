<html>
  <head>
    <title>STS - Print Waybill</title>
    <style>
      body {font: normal 20px Verdana, Arial, sans-serif;}
      table {border-collapse: collapse;}
      tr {vertical-align: top}
      th {border: 1px solid black; padding: 10px}
      td {border: 1px solid black; padding: 10px}
      @media print
      {
        .noprint {display:none;}
      }
    </style>
  </head>
  <body>
    <?php
      require 'open_db.php';
      require 'waybill_print_helpers.php';

      if (isset($_POST['display_btn']))
      {
        $dbc = open_db();
        $waybill_number = $_POST['waybill_number'] ?? '';
        echo waybill_print_render_page($dbc, $waybill_number, [
          'show_controls' => true,
          'nav_html' => '<a href="display_waybill.php">Return to Display Waybill page</a>',
        ]);
      }
    ?>
    <div class="noprint">
      <br /><a href="display_waybill.php">Return to Display Waybill page</a>
    </div>
  </body>
</html>
