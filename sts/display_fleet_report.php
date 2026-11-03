<?php
require_once 'page_chrome.php';
sts_page_start([
  'title' => 'Car Fleet Management Report',
  'icon' => 'bi-train-freight-front',
  'section' => 'report',
]);
?>
    <!-- this form generates another page that is modified for better printing  -->
    <!-- the user needs to use the browser's back button to return to this page -->
    <!-- or user the link displayed at the bottom of the last page              -->
    <div class="sts-card">
    <form action="printable_fleet_report.php" method="get">
    <p>Select a car type and then click the <b>Display</b> button.</p>
    <p>A list of cars of that type and their location will be displayed on a page formatted for printing.
    Use the browser's <b>Back</b> button to return to this page or click on the link that is displayed at the bottom of the last page.</p>

    <?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';

      // generate a drop-down list of car codes and the submit button
      print '<div class="mb-3"><label class="form-label" for="car_code">Car type</label>';
      print drop_down_car_codes('car_code', '0', 'wild_ok');
      print '</div>';
      print '<button name="display_btn" value="DISPLAY" type="submit" class="btn btn-primary"><i class="bi bi-printer"></i> Display</button>';

      // generate some javascript that adds "All" to the top of the car code drop-down list
      print '<script>
               var drop_down_list = document.getElementById("car_code");
               var option = document.createElement("option");
               option.text = "All";
               drop_down_list.add(option, drop_down_list[1]);
             </script>';
    ?>
    </form>
    </div>
<?php sts_page_end(); ?>
