<?php
require_once 'page_chrome.php';
sts_page_start([
  'title' => 'Waybill',
  'icon' => 'bi-file-text',
  'section' => 'report',
]);
?>
    <!-- this form generates another page that is modified for better printing  -->
    <!-- the user needs to use the browser's back button to return to this page -->
    <!-- or use the link displayed at the bottom of the last page              -->
    <div class="sts-card">
    <form action="printable_waybill.php" method="post">
    <?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';

      // generate a drop-down list of filled car orders and the submit button
      $select_string = drop_down_car_orders('waybill_number', '');
      if ($select_string != 'None')
      {
        print '<p>Select a waybill and then click the Display button.</p>';

        print '<p>A new page will be displayed that is formatted for printing. If the car assigned to the car order is not at
               the same station as the loading location, an empty car waybill will be also be generated.
               Use the browser "Back" button to return to this page or click on the link that is displayed at the bottom
               of the last page.</p>';

        print '<div class="mb-3"><label class="form-label" for="waybill_number">Waybill</label>';
        print drop_down_car_orders('waybill_number', '');
        print '</div>';
        print '<button name="display_btn" value="Display" type="submit" class="btn btn-primary"><i class="bi bi-file-text"></i> Display</button>';
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
<?php sts_page_end(); ?>
