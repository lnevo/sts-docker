<?php
require_once 'page_chrome.php';
require_once __DIR__ . '/sql_safe.php';
$sts_edit_pages = array(
  'commodities' => array('Commodities', 'Return to Commodity Management page', 'commodities'),
  'car_codes' => array('Car Codes', 'Return to Car Code Management page', 'car_codes'),
  'locations' => array('Locations', 'Return to Location Management page', 'locations'),
  'shipments' => array('Shipments', 'Return to Shipment Management page', 'shipments'),
  'empty_locations' => array('Empty Car Location Search Priorities', 'Return to Shipment Management page', 'shipments'),
  'routing' => array('Stations & Routing Instructions', 'Return to Stations & Routing Management page', 'routing'),
  'cars' => array('Cars', 'Return to Car Management page', 'cars'),
  'jobs' => array('Jobs', 'Return to Job Management page', 'jobs'),
  'pool' => array('Edit Special Shipment Pool Cars', 'Return to Car Management page', 'cars')
);
$sts_ep = isset($sts_edit_pages[sts_get('tbl_name')]) ? $sts_edit_pages[sts_get('tbl_name')] : array('', '', '');
$page_heading = $sts_ep[0];
$return_label = $sts_ep[1];
$return_href = $sts_ep[2] === '' ? '' : 'db_list.php?tbl_name=' . $sts_ep[2];
sts_page_start(['title' => 'Edit Database Object', 'icon' => 'bi-pencil-square', 'section' => 'data']);
?>
<div class="sts-card">
<?php if ($return_href !== '') { ?><a id="return_link" href="<?php echo htmlspecialchars($return_href); ?>"><?php echo htmlspecialchars($return_label); ?></a><?php } ?>
    
    <h3 id="table_name"><?php echo htmlspecialchars($page_heading); ?></h3>
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
      $tbl_name = sts_get('tbl_name');

      // generate the <form> tag
      print '<form method="get" action="db_edit.php">'; print "\n";

      // generate some javascript that changes the caption on the update/remove button when the radio buttons are clicked
      print '<script>
               function enable_update(){var b=document.getElementById("update_btn");b.value="UPDATE";b.className="btn btn-primary btn-sm";}
               function enable_remove(){var b=document.getElementById("update_btn");b.value="REMOVE";b.className="btn btn-danger btn-sm";}
             </script>';

      // put the radio buttons, update/remove button, and reset button on one line
      print '<div class="d-flex flex-wrap align-items-center gap-3 mb-3">';

      // generate the two radio buttons that determine if this is an update or a remove operation
      print '<div id="update_remove_btn">
             <label class="me-3"><input class="form-check-input" name="update_remove_btn" value="update" type="radio" checked onclick="enable_update()"> Update</label>
             <label><input class="form-check-input" name="update_remove_btn" value="remove" type="radio" onclick="enable_remove()"> Remove</label>
             </div>';

      // generate the submit button
      print '<input id="update_btn" name="update_btn" value="UPDATE" type="submit" class="btn btn-primary btn-sm">';

      // generate the reset button
      print '<input name="reset_btn" value="RESET" type="reset" class="btn btn-secondary btn-sm">';

      print '</div>';

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
          // the waybill editor is not shipped with this install
          if (is_file(__DIR__ . '/db_edit_waybills.php'))
          {
            require 'db_edit_waybills.php';
          }
          else
          {
            print '<p>Waybill editing is not available.</p>';
          }
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
