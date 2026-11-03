<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Fix Reporting Marks',
    'icon' => 'bi-fonts',
    'section' => 'maint',
    'links' => [['href' => 'validate_db.php', 'label' => 'Validate Database', 'icon' => 'bi-check2-square']],
    'head_extra' => <<<'HTML'
HTML,
]);
?>
<div class="sts-card">
<div id="instructions" class="alert alert-warning">
    The Association of American Railroads (AAR) controls the issuance of<br />
    reporting marks which are allowed to consist of any alphabetic letter.<br />
    The AAR does not allow ampersands. ( & ) Likewise, STS does not allow<br />
    ampersands in reporting marks either as that character can cause<br />
    unpredicatable results.<br /><br />
    Click on the FIX REPORTING MARKS button to remove all ampersands.<br />
  <br />
    </div>
<?php
      // pull in the utility files
      require 'open_db.php';

      // get a database connection
      $dbc = open_db();

      // was the fix it button clicked?
      if (isset($_GET['fix_it_btn']))
      {
        // remove all ampersands
        $sql = 'update cars set reporting_marks = replace(reporting_marks, "&", "")';
        mysqli_query($dbc, $sql);
        $rows_updated = mysqli_affected_rows($dbc);
        if ($rows_updated > 0)
        {
          print $rows_updated . ' reporting marks updated.<br />';
        }
      }

      // display the list of reporting marks that contain ampersands
      $sql = 'select reporting_marks from cars where locate("&", reporting_marks) order by reporting_marks';

      $rs = mysqli_query($dbc, $sql);
      
      // if we found some ampersands, list them
      if (mysqli_num_rows($rs) > 0)
      {
        print '<form action="fix_reporting_marks.php" method="get">';
        print '<input type="submit" class="btn btn-danger" id="fix_it_btn" name="fix_it_btn" value="FIX REPORTING MARKS" onclick="return confirm(\'Fix Reporting Marks: this changes the database. Continue?\');"><br /><br />';
    
        print '<table class="table table-sm table-bordered w-auto" id="car_table" name="car_table">';
        print '<thead>';
        print '<tr>';
        print '<th>Reporting Marks</th>';
        print '</tr>';
        print '</thead>';
        while ($row = mysqli_fetch_array($rs))
        {
          print '<tr><td style="text-align: center;">'. $row['reporting_marks'] . '</td></tr>';
        }
        print '</table>';
      
        print '</form>';
      }
      else
      {
        print '<br />No illegal characters found in reporting marks.';
      }
    ?>
</div>
<?php sts_page_end(); ?>
