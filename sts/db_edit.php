<?php
require_once 'page_chrome.php';
sts_page_start(['title' => 'Edit Database Object', 'icon' => 'bi-pencil-square', 'section' => 'data']);
?>
<div class="sts-card">
<a id="return_link" href=""></a>
    
    <h3 id="table_name"></h3>
    <div id="instructions">
    To update the item, modify the desired fields and click UPDATE.<br />
    To remove the item, click the Remove radio button and then click REMOVE.<br />
  <i class="bi bi-exclamation-triangle-fill text-warning"></i>
  Be careful when removing items as there is no "Undo". </div>
    <?php
      // this program builds the shell for all edit operations
      // it is called from the db_ui.php program
      // incoming parameters are the name of the table and the key value of the object to be edited

      // bring in the function files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // pull in the table to be hooked to this page
      $tbl_name = $_GET['tbl_name'];

      // generate the <form> tag
      print '<form method="get" action="db_edit.php">'; print "\n";

      // generate some javascript that changes the caption on the update/remove button when the radio buttons are clicked
      print '<script>
               function enable_update(){document.getElementById("update_btn").value="UPDATE";}
               function enable_remove(){document.getElementById("update_btn").value="REMOVE";}
             </script>';

      // put the radio buttons, update/remove button, and reset button on one line
      print '<table>
             <tr>
             <td style="border: none;">';

      // generate the two radio buttons that determine if this is an update or a remove operation
      print '<div id="update_remove_btn">
             <input name="update_remove_btn" value="update" type="radio" checked onclick="enable_update()">Update &nbsp;
             <input name="update_remove_btn" value="remove" type="radio" onclick="enable_remove()">Remove &nbsp;
             </div>';

      print '</td>
             <td style="border: none;">';

      // generate the submit button
      print '<input id="update_btn" name="update_btn" value="UPDATE" type="submit" class="btn btn-primary btn-sm">&nbsp;';

      // generate the reset button
      print '<input name="reset_btn" value="RESET" type="reset" class="btn btn-secondary btn-sm"><br /><br />';

      print '</td>
             </tr>
             </table>';

      // build the appropriate HTML table
      switch($tbl_name)
      {
        case 'commodities':
          require 'db_edit_commodities.php';
          break;
        case 'car_codes':
          require 'db_edit_car_codes.php';
          break;
        case 'locations':
          require 'db_edit_locations.php';
          break;
        case 'shipments':
          require 'db_edit_shipments.php';
          break;
        case 'empty_locations':
          require 'db_edit_empty_locations.php';
          break;
        case 'routing':
          require 'db_edit_routing.php';
          break;
        case 'cars':
          require 'db_edit_cars.php';
          break;
        case 'waybills':
          require 'db_edit_waybills.php';
          break;
        case 'jobs':
          require 'db_edit_jobs.php';
          break;
        case 'pool':
          require 'db_edit_special_pool.php';
      }
      print '</form>';
    ?>
</div>
<?php sts_page_end(); ?>
