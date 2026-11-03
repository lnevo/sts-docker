<?php
  // edit_empty)locations.php

  // edits the selected shipment's empty location search priorities
  // it replaces only those items where a new value was submitted

  // generate a javascript line to display the table name
  print '<script>document.getElementById("table_name").innerHTML = "Empty Car Location Search Priorities";</script>';
  
  // generate some javascript to set the proper return link and text
  print '<script>document.getElementById("return_link").innerHTML = "Return to Shipment Management page";</script>';
  print '<script>document.getElementById("return_link").href = "db_list.php?tbl_name=shipments";</script>';
  
  // get a database connection
  $dbc = open_db();

  // decode the incoming shipment name and ID
  if (isset($_GET['obj_name']))
  {
    $obj_name = urldecode(sts_get('obj_name'));
  }
  else
  {
    $obj_name = sts_get('prev_obj_name');
  }

  if (isset($_GET['obj_id']))
  {
    $obj_id = sts_get('obj_id');
  }
  else
  {
    $obj_id = '';
  }
  
  // generate some javascript to remove the Update/Remove radio buttons since we don't need it for this page
  print '<script>var parent=document.getElementById("update_remove_btn").parentNode; var child=document.getElementById("update_remove_btn"); parent.removeChild(child);</script>';

  // generate some javascript to replace the boilerplate instructions with proper ones for this page
  $instructions = '<h3>Shipment ID: ' . sts_html($obj_name) . '</h3>';
  $instructions .= '<p>After adding locations or modify existing priorities, click the Update button.<br /><br />';
  $instructions .= '1 is the highest priority, 2 is the second highest, etc...<br /><br />';
  $instructions .= 'To remove a location from the list of locations being searched for empty<br />';
  $instructions .= 'cars, set the priority to 0 (Zero) and click the Update button.</p>';
  print '<script>document.getElementById("instructions").innerHTML = ' . json_encode($instructions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
         
  // initiate a database response message
  $sql_msg = '<br />Transaction completed';

  // has the submit button been clicked?
  if (isset($_GET['update_btn']))
  {
    // check the location priority table
    $ship_id = sts_id(sts_get('obj_id'));
    if (intval(sts_get('row_count')) > 0)
    {
      // first, delete all of this shipment's search locations from the table
      // then go through each of the existing rows coming in from the web page
      // insert any row that doesn't have a sequence number of zero
      // including anything in the input text boxes

      // delete the existing rows
      if (!sts_q($dbc, 'delete from empty_locations where shipment = ?', array($ship_id)))
      {
        $sql_msg = 'Delete error: ' . sts_html(sts_err($dbc)) . '<br /><br />';
      }
      else
      {
        // loop through the existing steps on the web page and put them back into this job's step table
        for ($i=0; $i<intval(sts_get('row_count')); $i++)
        {
          // construct the names of each of the input fields in this table row
          $priority_nbr = 'priority' . $i;
          $location_nbr = 'location' . $i;

          // if the priority sequence number is 0, skip it (this deletes it from the list of steps)
         if (sts_get($priority_nbr) > 0)
         {
            // build the sql insert command
            if (!sts_q($dbc, 'insert into empty_locations (shipment, priority, location) values (?, ?, ?)',
                       array($ship_id, sts_get($priority_nbr), sts_get($location_nbr))))
            {
              $sql_msg = 'Insert Error: ' . sts_html(sts_err($dbc)) . '<br /><br />';
            }
          }
        }
      }
    }
    // if there's new priority and location information in the text boxes, insert that information as well
    if (sts_get('new_priority') > 0)
    {
      // build the sql insert query
      if (!sts_q($dbc, 'insert into empty_locations (shipment, priority, location) values (?, ?, ?)',
                 array(sts_id(sts_get('obj_id')), intval(sts_get('new_priority')), sts_get('new_location'))))
      {
        $sql_msg = 'Insert error: ' . sts_html(sts_err($dbc)) . '<br /><br />';
      }
    }
  }

  // generate a hidden field to send this form's table name to itself when it's refreshed
  print '<input id="tbl_name" name="tbl_name" value="empty_locations" type="hidden">';

  // generate a hidden field to send this form's previous object name to itself when it's refreshed
  print '<input id="prev_obj_name" name="prev_obj_name" value="' . sts_html($obj_name) . '" type="hidden">';

  // set up input fields for new entries to the table and the column headings
  print '<table>
           <tr>
             <td style="text-align: center;">
               <input name="new_priority" type="text" style="text-align: center; width: 50px;">
             </td>
             <td>' . drop_down_locations('new_location', 2, '') . '</td>
           </tr>
           <tr>
             <th>Priority</th>
             <th>Location</th>
           </tr>';

  // query the database for the list of empty car location search priorities for this shipment
  $sql = 'select empty_locations.location as location_id,
                 empty_locations.shipment as shipment_id,
                 empty_locations.priority as priority,
                 shipments.code as shipment,
                 locations.code as location
            from (empty_locations, locations)
            left join shipments on shipments.id = empty_locations.shipment
           where empty_locations.shipment = ' . sts_id($obj_id) . '
             and locations.id = empty_locations.location
           order by priority';
// print 'SQL: ' . $sql . '<br /><br />';
  $rs = mysqli_query($dbc, $sql);

  // build the table of search locations and their priorities
  $row_count = 0;
  if (mysqli_num_rows($rs) > 0)
  {
    while ($row = mysqli_fetch_array($rs))
    {
      print '<tr>
               <td style="text-align: center;">
                 <input name="priority' . $row_count . '" type="text" value="' . sts_html($row['priority']) . '" style="text-align: center; width: 50px;">
               </td>
               <td>
                 <input name="location' . $row_count . '" type="hidden" value="' . sts_html($row['location_id']) . '">' . sts_html($row['location']) . '
               </td>
             </tr>';
      $row_count++;
    }
    print '</table>';

    // store the number of rows in the table for future use
    print '<input name="row_count" id="row_count" type="hidden" value="' . $row_count . '">';
    
  }
  else
  {
  }
  // pass the shipment name and ID on to this form
  print '<input name="obj_name" type="hidden" value="' . sts_html($obj_name) . '">
         <input name="obj_id" type="hidden" value="' . sts_html($obj_id) . '">';

  // display a status message
  print $sql_msg;

  // generate a javascript line to set focus on the first input text box

?>
