<?php
// Single-file report: ?generate_report=1 outputs the waybill HTML fragment and
// exits; the form below fetches it into #report-content. (Replaces the old
// printable_waybill.php, which now just redirects here.) The waybill markup
// itself comes from waybill_print_helpers.php, shared with the session generator.
if (isset($_GET['generate_report'])) {
  require 'open_db.php';
  require 'waybill_print_helpers.php';

  $dbc = open_db();
  $body = waybill_print_render_body($dbc, $_GET['waybill_number'] ?? '');
  echo $body === '' ? '<p>Waybill not found.</p>' : waybill_print_wrap_sheets($body);
  exit;
}

require_once 'page_chrome.php';
sts_page_start([
  'title' => 'Waybill',
  'icon' => 'bi-file-text',
  'section' => 'report',
  'head_extra' => '<link href="report_page.css?v=1" rel="stylesheet">
<style>
  /* The waybill is built from classless layout tables with inline fonts; keep the
     shared page-chrome table styling off them so the printed sheet matches the
     legacy printable waybill (bordered tables, Verdana 20px). */
  #report-content { font: normal 20px Verdana, Arial, sans-serif; line-height: normal; color: #000; }
  #report-content table { display: table; border-collapse: collapse; font-size: inherit; margin-bottom: 0; max-width: none; overflow: visible; background: none; }
  #report-content tr { vertical-align: top; }
  #report-content th, #report-content td { border: 1px solid #000; padding: 10px; background: none; color: #000; vertical-align: top; }
  #report-content h2 { font-size: 1.5em; margin: 0.83em 0; font-weight: bold; line-height: normal; color: #000; }
  #report-content td > h2:first-child { margin-top: 0; } /* the legacy page ran in quirks mode: no top margin on the first child of a cell */
  #report-content h3 { font-size: 1.17em; margin: 1em 0; font-weight: bold; line-height: normal; color: #000; }
  #report-content hr { margin: 0.5em auto; border: 1px inset; opacity: 1; color: gray; }
  .waybill-sheet { margin-bottom: 32px; }
  .waybill-break-before { margin-top: 32px; }
  @page { size: auto; margin: 0.5in; }
  @media print {
    body.sts-page { margin: 0; padding: 0; }
    .waybill-sheet { page-break-after: always; break-after: page; page-break-inside: avoid; break-inside: avoid; margin: 0; height: auto; max-height: none; overflow: visible; }
    .waybill-sheet:last-child { page-break-after: auto; break-after: auto; }
    .waybill-break-before { page-break-before: always; break-before: page; margin-top: 0; }
  }
</style>',
]);
?>
    <div class="sts-card report-screen-only">
    <form id="report-form" method="get" data-report-endpoint="display_waybill.php">
    <?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';

      // generate a drop-down list of filled car orders and the submit button
      $select_string = drop_down_car_orders('waybill_number', '');
      if ($select_string != 'None')
      {
        print '<p>Select a waybill and then click the Display button.</p>';

        print '<p>The waybill is displayed below, formatted for printing. If the car assigned to the car order is not at
               the same station as the loading location, an empty car waybill will be also be generated.</p>';

        print '<div class="mb-3"><label class="form-label" for="waybill_number">Waybill</label>';
        print $select_string;
        print '</div>';
        print '<button name="display_btn" value="Display" type="submit" class="btn btn-primary"><i class="bi bi-eye"></i> Display</button>';
      }
      else
      {
        print '<p>All billed cars are enroute. Waybills cannot be displayed or printed while a car is moving.</p>';
        print '<p>For information about enroute cars, check the <a href="db_list.php?tbl_name=car_orders">car orders</a>
               or the <a href="db_list.php?tbl_name=cars">cars</a> tables.</p>';
      }

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
<script src="/sts/js/report-common.js?v=1"></script>
<script src="/sts/js/report-page.js?v=3"></script>
<?php sts_page_end(); ?>
