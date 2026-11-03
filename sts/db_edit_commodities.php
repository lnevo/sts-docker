<?php
  // edit_commodities.php

  // edits the selected row in the commodities table if the Update button was clicked
  // it replaces only those items where a new value was submitted

  // generate a javascript line to display the table name
  print '<script>document.getElementById("table_name").innerHTML = "Commodities";</script>';

  // generate a javascript line to set the proper return link and text
  print '<script>document.getElementById("return_link").innerHTML = "Return to Commodity Management page";</script>';
  print '<script>document.getElementById("return_link").href = "db_list.php?tbl_name=commodities";</script>';

  // get a database connection
  $dbc = open_db();

  // initiate a database response message
  $sql_msg = '<br />Transaction completed';

  // has the submit button been clicked?
  if (isset($_GET['update_btn']))
  {
    // is this a remove operation?
    if (sts_get('update_remove_btn') == 'remove')
    {
      // build a query to remove the selected car code
      $sql = 'delete from commodities where id = ' . sts_id(sts_get('obj_id')) . '';
      if (!mysqli_query($dbc, $sql))
      {
        $sql_msg =  '<br />Delete Error: ' . sts_html(mysqli_error($dbc));
      }
      else
      {
        // if the delete was successful, return to the list_car_codes page
        header('Location: db_list.php?tbl_name=commodities');
        exit();
      }
    }
    else
    {
      // this must be an update operation
      // build the update query based on the contents of the input text boxes
      $sql = "update commodities set ";
      $params = array();
      $first_field = true;

      // check each incoming input text box and add it to the update if there's something there
      if (strlen(sts_get('obj_name')) > 0)
      {
        $sql .= 'code = ? ';
        $params[] = sts_get('obj_name');
        $first_field = false;
      }

      if (strlen(sts_get('description')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'description = ? ';
        $params[] = sts_get('description');
        $first_field = false;
      }

      if (strlen(sts_get('remarks')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'remarks = ? ';
        $params[] = sts_get('remarks');
        $first_field = false;
      }

      // run the update query if at least one field is to be updated
      if (!$first_field)
      {
        $sql .= 'where id = ?';
        $params[] = sts_id(sts_get('obj_id'));
        if (sts_q($dbc, $sql, $params))
        {
          $sql_msg =  '<br />Transaction completed';
        }
        else
        {
          $sql_msg =  '<br />Update Error: ' . sts_html(sts_err($dbc));
        }
      }
    }
  }

  // generate a hidden field to send this form's table name to itself when it's refreshed
  print '<input id="tbl_name" name="tbl_name" value="commodities" type="hidden">';

  // query the database for the properties of the selected  car code and display them in a table
  $sql = 'select id, code, description, remarks from commodities where id = ' . sts_id(sts_get('obj_id')) . '';
  $rs = mysqli_query($dbc, $sql);
  $row = mysqli_fetch_array($rs);

  // generate a hidden field to send this form's object ID to itself when it's refreshed
  print '<input name="obj_id" type="hidden" value="' . sts_html($row['id']) . '">';

  print
    '<table>
      <tr>
        <th>Property</th>
        <th>Current Value</th>
        <th>New Value</th>
      </tr>
      <tr>
        <td>Commodity Code</td>
        <td>' . sts_html($row['code']) . '</td>
        <td><input id="obj_name" name="obj_name" type="text"></td>
      </tr>
      <tr>
        <td>Description</td>
        <td>' . sts_html($row['description']) . '</td>
        <td><input name="description" type="text"></td>
      </tr>
      <tr>
        <td>Remarks</td>
        <td>' . sts_html($row['remarks']) . '</td>
        <td><input name="remarks" type="text"></td>
      </tr>
    </table>';

  // generate a javascript line to set focus on the first input text box
  print '<script>document.getElementById("obj_name").focus();</script>';

  // display a status message
  print $sql_msg;

?>
