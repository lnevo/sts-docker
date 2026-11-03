<?php
require_once 'page_chrome.php';
sts_page_start([
  'title' => 'Location QR/Bar Code Report',
  'icon' => 'bi-qr-code',
  'section' => 'report',
  'head_extra' => '<script>
      function enable_display_btn()
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
    <form action="printable_station_qr_code_report.php" method="get">
    <p>Select a station, the desired type of code, and then click the Display button. The QR or bar codes for all of the named locations at the selected station will be displayed on a new page that is formatted for printing.</p>
    <p>Use the browser's <b>BACK</b> button to return to this page or click on the link on the bottom of the last page of the report.</p>

    <?php
      // bring in the utility files
      require 'drop_down_list_functions.php';
      require 'open_db.php';

      // generate a drop-down list of stations, radio buttons for the type of codes,  and the submit button
      print '<div class="mb-3"><label class="form-label" for="station_name">Station</label>' . drop_down_stations('station_name', '', 'enable_display_btn()') . '</div>';

      print '<div class="mb-3">
               <div class="form-check form-check-inline"><input class="form-check-input" id="code_type_qr" name="code_type" type="radio" value="qr" checked> <label class="form-check-label" for="code_type_qr">QR Code</label></div>
               <div class="form-check form-check-inline"><input class="form-check-input" id="code_type_bar" name="code_type" type="radio" value="bar"> <label class="form-check-label" for="code_type_bar">Bar Code</label></div>
             </div>';

      print '<button id="display_btn" name="display_btn" value="Display" type="submit" class="btn btn-primary" onclick="start_dots();" disabled><i class="bi bi-printer"></i> Display</button>';
      print '<div id="dots" class="mt-2 text-muted" style="visibility: hidden">Working</div>';

      // generate some javascript that adds "All" to the top of the station drop-down list
      print '<script>
               var drop_down_list = document.getElementById("station_name");
               var option = document.createElement("option");
               option.text = "All";
               drop_down_list.add(option, drop_down_list[1]);
             </script>';

      // generate some javascript that will show a progress indicator until the printable page loads
      print '<script>
              function iterateDots()
              {
                var el = document.getElementById("dots");
                var dotsStr = el.innerHTML;
                var dotsLen = dotsStr.length;
                var maxDots = 10;
                el.innerHTML = (dotsLen < maxDots ? dotsStr + "." : "Working");
              }

              function start_dots()
              {
                var intervalMs = 300;
                var interval = setInterval("iterateDots()", intervalMs);
                document.getElementById("dots").style.visibility="visible";
              }
            </script>';
    ?>
    </form>
    </div>
<?php sts_page_end(); ?>
