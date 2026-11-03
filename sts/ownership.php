<?php
require_once 'page_chrome.php';
// bring in the javascript function that shows rollingstock photos
ob_start();
require 'show_image.php';
$show_image_js = ob_get_clean();
sts_page_start([
    'title' => 'Rolling Stock Ownership',
    'icon' => 'bi-person-badge',
    'section' => 'club',
    'head_extra' => $show_image_js,
]);
?>
<div class="sts-card">
<?php
      // connect to the database using the credentials from sts04
      require 'open_db.php';
      $dbc = open_db();
      
      // if we got here via a click on the Submit button, update the ownership table based on what's in the owner dropdown list for each car
      if (isset($_POST['submit']))
      {
        // create a temporary table to hold the updated info
        $sql = 'create table if not exists temp_ownership (car_id int, owner_id int, on_off_rr varchar(256))';
        if (!$rtncd = mysqli_query($dbc, $sql))
        {
          print "Error creating temporary ownership table";
        }
        
        // dump the list of car id's, owner id's, and stored status values into the temporary table
        for ($i = 0; $i < (int) sts_post('row_count'); $i++)
        {
          // check for empty values in the drop-down lists
          // (0 means no owner and is stored as NULL)
          $selected_owner = sts_id(sts_post('select' . $i));
          
          // check for empty values in the on_off_rr column
          if (empty($_POST['on_off_rr' . $i]))
          {
            $on_off_rr = 'on';
          }
          else
          {
            $on_off_rr = sts_post('on_off_rr' . $i);
          }
          
          $sql = 'insert into temp_ownership (car_id, owner_id, on_off_rr) values (?, nullif(?, 0), ?)';
          if (!$rtncd = sts_q($dbc, $sql, array(sts_id(sts_post('car_id' . $i)), $selected_owner, $on_off_rr)))
          {
            print 'Unable to insert record into temporary table';
            print '</div>'; sts_page_end(); die();
          }
        }

        // rename the original ownership table
        $sql = 'rename table ownership to orig_ownership';
        if (!$rtncd = mysqli_query($dbc, $sql))
        {
          print 'Unable to rename original ownership table';
        }
        
        // rename the temporary ownership table
        $sql = 'rename table temp_ownership to ownership';
        if (!$rtncd = mysqli_query($dbc, $sql))
        {
          print 'Unable to rename temporary ownership table';
        }
        
        // remove the original ownership table
        $sql = 'drop table orig_ownership';
        if (!$rtncd = mysqli_query($dbc, $sql))
        {
          print 'Unable to drop original ownership table';
        }
      }
      
      // pull in all owners for use later
      $owners = array();
      $owner_count = 0;
      $sql = 'select id, name from owners order by name';
      $rs = mysqli_query($dbc, $sql);
      while ($row = mysqli_fetch_array($rs))
      {
        $owners[$owner_count][0] = $row[0];
        $owners[$owner_count][1] = $row[1];
        $owner_count++;
      }
      
      // start a form for updating ownership information
      print '<form name=get_owner method="post" action="ownership.php">';
      
      // put a submit button on both the top and bottom of the page
      print '<input type="submit" id="submit" name="submit" value="UPDATE" class="btn btn-primary">';
      print '<br /><br />';
      
      // set up the table for the list of cars and owners
      print '<table class="table table-sm table-bordered align-middle bg-white">
               <tr>
                 <th>Car Reporting Marks</th>
                 <th>Owner</th>
               </tr>';
      
      // display all of the cars
      $sql = 'select cars.reporting_marks as reporting_marks,
                     cars.id as car_id,
                     owners.name as owners_name,
                     owners.id as owners_id,
                     ownership.on_off_rr as on_off_rr
                from cars
                left join ownership on ownership.car_id = cars.id
                left join owners on owners.id = ownership.owner_id
                order by cars.reporting_marks';


      if (!$rs = mysqli_query($dbc, $sql))
      {
        print 'Unable to query cars, owners, and ownership tables';
      }
      
      // display the cars and their owners in a table
      $row_count = 0;
      while ($row = mysqli_fetch_array($rs))
      {
        if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['car_id'] . '.jpg'))
        {
          $parm_string = json_encode((string) $row['car_id'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ', ' . json_encode((string) $row['reporting_marks'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        }
        else
        {
          $parm_string = '"", ' . json_encode((string) $row['reporting_marks'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        }
        print '<tr>';
        print '  <td onclick="show_image(' . sts_html($parm_string) . ');">' . sts_html($row['reporting_marks']) . '<input type="hidden" id="car_id' . $row_count . '" name="car_id' . $row_count . '" value="' . $row['car_id'] . '"></td>';
        print '  <td>';
        print '    <select id="select' . $row_count . '" name="select' . $row_count . '" class="form-select form-select-sm">';
        print '      <option value=""></option>';
        for ($i = 0; $i < $owner_count; $i++)
        {
          if ($owners[$i][0] == $row['owners_id'])
          {
            print '  <option value="' . sts_html($owners[$i][0]) . '" selected>' . sts_html($owners[$i][1]) . '</option>';
          }
          else
          {
            print '  <option value="' . sts_html($owners[$i][0]) . '">' . sts_html($owners[$i][1]) . '</option>';
          }
        }
        print '    </select>';
        print '    <input type="hidden" id ="on_off_rr' . $row_count . '" name="on_off_rr' . $row_count . '" value="' . sts_html($row['on_off_rr']) . '">';
        print '  </td>';
        print '</tr>';
        $row_count++;
      }
      print '</table>';
      
      // save the row count in a hidden field for the next time through
      print '<input type="hidden" id = "row_count" name="row_count" value="' . $row_count . '">';

      // put a submit button on both the top and bottom of the page
      print '<br />';
      print '<input type="submit" id="submit" name="submit" value="UPDATE" class="btn btn-primary">';
      print '</form>';
      
    ?>
</div>
<?php sts_page_end(); ?>
