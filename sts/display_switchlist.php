<?php
require_once 'page_chrome.php';
sts_page_start([
  'title' => 'Switchlist',
  'icon' => 'bi-list-check',
  'section' => 'report',
  'head_extra' => '<script>
      function activate_button()
      {
        document.getElementById("display_btn").disabled = false;
      }
    </script>',
]);
?>
    <!-- this form generates another page that is modified for better printing  -->
    <!-- the user needs to use the browser's back button to return to this page -->
    <!-- or user the link displayed at the bottom of the last page              -->
    <div class="sts-card">
    <form id="switchlistForm" action="printable_switchlist.php" method="get">

    <p>Select job and display/print format and then click the <b>Display</b> button.
    A new page will be displayed that is formatted for printing.
    Use the browser's <b>Back</b> button to return to this page or click on the link that is displayed at the bottom of the last page.</p>

    <?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';

      // generate a drop-down list of jobs and the submit button
      print '<div class="mb-3"><label class="form-label" for="job_name">Job</label>';
      print drop_down_jobs('job_name', '', 'activate_button()');
      print '</div>';
      print '<div class="mb-3">';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="mobile" value="mobile"> <label class="form-check-label" for="mobile">Mobile</label></div>';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="half"  value="half" checked> <label class="form-check-label" for="half">Half Sheet</label></div>';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="full"  value="full"> <label class="form-check-label" for="full">Full Sheet</label></div>';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="dmp"  value="dmp"> <label class="form-check-label" for="dmp">Dot Matrix</label></div>';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="wo"  value="wo"> <label class="form-check-label" for="wo">Work Order</label></div>';
      print '<div class="form-check"><input class="form-check-input" type="radio" name="format" id="x2010"  value="x2010"> <label class="form-check-label" for="x2010">X2010</label></div>';
      print '</div>';
      print '<button id="display_btn" name="display_btn" value="DISPLAY" type="submit" class="btn btn-primary" disabled><i class="bi bi-printer"></i> Display</button>';
    ?>
    </form>
    </div>

<!-- additional logic to ensure that when x2010 is selected the other page is selected -->
<script>
  document.getElementById("switchlistForm").onsubmit = function() {
    if (document.getElementById("x2010").checked) {
      this.action = "printable_switchlist_x2010.php"; // Change this to your desired file
    }
  };
</script>
<!-- additional logic to ensure that when x2010 is selected the other page is selected -->
<?php sts_page_end(); ?>
