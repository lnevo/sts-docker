<?php
require_once __DIR__ . '/maint_lock.php'; sts_maint_require('admin');
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Fix Ghost Locations',
    'icon' => 'bi-ghost',
    'section' => 'maint',
    'links' => [['href' => 'validate_db.php', 'label' => 'Validate Database', 'icon' => 'bi-check2-square']],
    'head_extra' => <<<'HTML'
    <script src="/sts/js/maint.js?v=1"></script>
HTML,
]);
?>
<div class="sts-card">
<div id="instructions" class="alert alert-warning">
    The following locations do not appear on the List Locations screen because they are not  linked to a station.<br /><br />
  When the FIX GHOSTS button is clicked, all selected locations will be removed 
  from the database.<br />
  <br />
    </div>
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
            print '<br />Removing ' . sts_html(sts_get('loc' . $i)) . '...';
            
            // remove the ghost location
            $sql = 'delete from locations where id = ?';
  //print 'SQL: ' . $sql . '<br />';
            if (!sts_q($dbc, $sql, array(sts_id(sts_get('loc' . $i)))))
            {
              print 'Unable to remove location<br />';
              print '</div>'; sts_page_end(); die();
            }
          }
        }
        print '<br /><br />';
      }

      // display the list of ghost locations
      $sql = 'select id, code from locations where station not in (select id from routing)';
      $rs = mysqli_query($dbc, $sql);
      
      // if we found some ghosts, ask the user to remove them
      if (mysqli_num_rows($rs) > 0)
      {
        print '<form action="fix_ghost_locations.php" method="get">';
        print '<input type="submit" class="btn btn-danger" id="fix_ghosts_btn" name="fix_ghosts_btn" value="FIX GHOSTS" data-sts-confirm="Fix Ghosts: this changes the database. Continue?">&nbsp;';
    
        print 'Check/Uncheck all ghost locations: <input type="checkbox" id="check_all" name="check_all" data-sts-change="maintCheckAll"><br /><br />';
        print '<table class="table table-sm table-bordered w-auto" id="loc_table" name="loc_table">';
        print '<th>Fix?</th>
               <th>Location</th>';
        $row_counter = 0;
        while ($row = mysqli_fetch_array($rs))
        {
          print '<tr>
                   <td style="text-align: center;">
                     <input type="checkbox" id="check' . $row_counter . '" name="check' . $row_counter . '">
                   </td>
                   <td>' . $row['code'] . '
                     <input type="hidden" id="loc' . $row_counter . '" name="loc' . $row_counter . '" value="' . $row['id'] . '">
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
        print '<br />No ghost locations found.';
      }
    ?>
</div>
<?php sts_page_end(); ?>
