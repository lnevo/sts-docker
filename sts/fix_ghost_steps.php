<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Fix Ghost Job Steps',
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

      // was the fix it button clicked?
      if (isset($_GET['fix_ghosts_btn']))
      {
        // go through the incoming rows and fix the selected cars
        for ($i=0; $i<(int) sts_get('row_counter'); $i++)
        {
          if (isset($_GET['check' . $i]))
          {
            print '<br />Removing Step ' . sts_html(sts_get('step' . $i)) . ' in Job ' . sts_html(sts_get('name' . $i)) . '...';
            
            // remove the ghost location
            // the step table must belong to a real job
            $chk = sts_q($dbc, 'select name from jobs where name = ?', array(sts_get('name' . $i)));
            if (!$chk || mysqli_num_rows($chk) == 0)
            {
              print 'Unknown job<br />';
              print '</div>'; sts_page_end(); die();
            }
            $sql = 'delete from ' . sts_ident(sts_get('name' . $i)) . ' where step_number = ?';
  //print 'SQL: ' . $sql . '<br />';
            if (!sts_q($dbc, $sql, array(sts_get('step' . $i))))
            {
              print 'Unable to remove location<br />';
              print '</div>'; sts_page_end(); die();
            }
          }
        }
      }

      // get a list of all jobs in the database
      $sql = 'select name from jobs';
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) > 0)
      {
        $row_counter = 0;
        $header_not_printed = true;
        $no_ghosts_found = true;
        // use the list of job names from the first query to provide table names for the second search
        while ($row = mysqli_fetch_array($rs))
        {
          $sql2 = 'select step_number as step from ' . sts_ident($row['name']) . ' where station not in (select id from routing)';
          $rs2 = mysqli_query($dbc, $sql2);
          
          // if we found some ghosts, ask the user to remove them
          if (mysqli_num_rows($rs2))
          {
            $no_ghosts_found = false;
            if ($header_not_printed)
            {
              print'<div id="instructions" class="alert alert-warning">
                      The following locations do not appear on the List Locations screen because they are not  linked to a station.<br /><br />
                      When the FIX GHOSTS button is clicked, all selected locations will be removed from the database.<br /><br />
                    </div>';

              print '<form action="fix_ghost_steps.php" method="get">';
              print '<input type="submit" class="btn btn-danger" id="fix_ghosts_btn" name="fix_ghosts_btn" value="FIX GHOSTS" data-sts-confirm="Fix Ghosts: this changes the database. Continue?">&nbsp;';
          
              print 'Check/Uncheck all ghost steps: <input type="checkbox" id="check_all" name="check_all" data-sts-change="maintCheckAll"><br /><br />';
              print '<table class="table table-sm table-bordered w-auto" id="step_table" name="step_table">';
              print '<th>Fix?</th>
                     <th>Job Name</th>
                     <th>Job Step</th>';
              $header_not_printed = false;
            }
            while($row2 = mysqli_fetch_array($rs2))
            {
              print '<tr>
                       <td style="text-align: center;">
                         <input type="checkbox" id="check' . $row_counter . '" name="check' . $row_counter . '">
                       </td>
                       <td>' . sts_html($row['name']) . '
                         <input type="hidden" id="name' . $row_counter . '" name="name' . $row_counter . '" value="' . sts_html($row['name']) . '">
                       </td>
                       <td style="text-align: center;">' . sts_html($row2['step']) . '
                         <input type="hidden" id="step' . $row_counter . '" name="step' . $row_counter . '" value="' . sts_html($row2['step']) . '">
                       </td>
                     </tr>';
              $row_counter++;
            }
          }
        }
        if (!$header_not_printed)
        {
          print '</table>';
          print '<input type="hidden" id="row_counter" name="row_counter" value="' . $row_counter . '">';
        
          print '</form>';
        }
      }
      // if no ghosts found, say socket_accept
      if ($no_ghosts_found)
      {
        print 'No Job Steps with bad links detected';
      }
    ?>
</div>
<?php sts_page_end(); ?>
