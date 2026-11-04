<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Fix Ghost Trains',
    'icon' => 'bi-ghost',
    'section' => 'maint',
    'links' => [['href' => 'validate_db.php', 'label' => 'Validate Database', 'icon' => 'bi-check2-square']],
    'head_extra' => <<<'HTML'
    <script src="/sts/js/maint.js?v=1"></script>
HTML,
]);
?>
<div class="sts-card">
<?php
      // pull in the utility files
      require 'open_db.php';

      // get a database connection
      $dbc = open_db();

      // get a list of all job in the database that aren't in the jobs table
      $ghost_sql = 'SELECT table_name
                FROM information_schema.tables
               WHERE table_type = "base table"
                 AND table_schema="sts_db3"
                 and table_name not in (select name from sts_db3.jobs)
                 and table_name not in ("blocks",
                                        "cars",
                                        "car_codes",
                                        "car_orders",
                                        "commodities",
                                        "empty_locations",
                                        "jobs",
                                        "locations",
                                        "owners",
                                        "ownership",
                                        "pool",
                                        "pu_criteria",
                                        "routing",
                                        "settings",
                                        "shipments")';

      // was the fix it button clicked?
      if (isset($_GET['fix_ghosts_btn']))
      {
        // go through the incoming rows and fix the selected cars
        for ($i=0; $i<(int) sts_get('row_counter'); $i++)
        {
          if (isset($_GET['check' . $i]))
          {
            print '<br />Removing ' . sts_html(sts_get('table_name' . $i)) . ' abandoned job steps...';
            
            // remove the ghost table
            // only abandoned job-step tables (same list shown below) may be dropped
            $ghost_ok = false;
            $ghost_rs = mysqli_query($dbc, $ghost_sql);
            while ($ghost_rs && ($ghost_row = mysqli_fetch_array($ghost_rs)))
            {
              if ($ghost_row['table_name'] === sts_get('table_name' . $i)) $ghost_ok = true;
            }
            if (!$ghost_ok)
            {
              print 'Not an abandoned job table<br />';
              print '</div>'; sts_page_end(); die();
            }
            $sql = 'drop table ' . sts_ident(sts_get('table_name' . $i));
  //print 'SQL: ' . $sql . '<br />';
            if (!mysqli_query($dbc, $sql))
            {
              print 'Unable to drop location<br />';
              print '</div>'; sts_page_end(); die();
            }
          }
        }
      }

      $rs = mysqli_query($dbc, $ghost_sql);
      if (mysqli_num_rows($rs) > 0)
      {
        print'<div id="instructions" class="alert alert-warning">
                The job steps for the following ghost trains do not appear on the Jobs screen because the job/train itself has been<br />
                removed, however sets of steps for each of the jobs/trains still remain in the system. When the FIX GHOSTS button is clicked,<br />
                the selected sets of job steps will be removed from the database.<br /><br />
              </div>';

        print '<form action="fix_ghost_trains.php" method="get">';
        print '<input type="submit" class="btn btn-danger" id="fix_ghosts_btn" name="fix_ghosts_btn" value="FIX GHOSTS" data-sts-confirm="Fix Ghosts: this changes the database. Continue?">&nbsp;';
    
        print 'Check/Uncheck all ghost trains: <input type="checkbox" id="check_all" name="check_all" data-sts-change="maintCheckAll"><br /><br />';
        print '<table class="table table-sm table-bordered w-auto" id="step_table" name="step_table">';
        print '<th>Fix?</th>
               <th>Job Name</th>';
               
        $row_counter = 0;
        while($row = mysqli_fetch_array($rs))
        {
          print '<tr>
                   <td style="text-align: center;">
                     <input type="checkbox" id="check' . $row_counter . '" name="check' . $row_counter . '">
                   </td>
                   <td>' . sts_html($row['table_name']) . '
                     <input type="hidden" id="table_name' . $row_counter . '" name="table_name' . $row_counter . '" value="' . sts_html($row['table_name']) . '">
                   </td>
                 </tr>';
          $row_counter++;
        }
        print '</table>';
        print '<input type="hidden" id="row_counter" name="row_counter" value="' . $row_counter . '">';
      
        print '</form>';
      }
      else
      {
        // if no ghosts found, say so
        print '<br /><br />No ghost trains found';
      }
    ?>
</div>
<?php sts_page_end(); ?>
