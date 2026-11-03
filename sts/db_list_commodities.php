<!-- include the HTML table sort scripts -->
<script src="sorttable.js"></script>

<?php
  // list_ccommodities.php

  // adds a new commodity to the commodities table if the Update button was
  // clicked and if there is a code in the commodity code text box.

  // generate some javascript to display the table name, identify this table to the form, and set the update button's tab index
  print '<script>
           document.getElementById("table_name").innerHTML = "Commodities";
           document.getElementById("tbl_name").value = "commodities";
           document.getElementById("update_btn").tabIndex = "4";
         </script>';

  // get a database connection
  $dbc = open_db();

  // has the submit button been clicked?
  if (isset($_POST['update_btn']))
  {
    // yes, so check to see if there is anything in the input text boxes
    if (strlen(sts_post('commodity_code')) > 0)
    {
      // add the new commodity to the commodities table
      sts_q($dbc, 'insert into commodities (code, description, remarks) values (?, ?, ?)',
            array(sts_post('commodity_code'), sts_post('description'), sts_post('remarks')));
    }
  }

  // query the database for all of the commodities and display them in a table
  $sql = 'select id, code, description, remarks from commodities order by code';
  $rs = mysqli_query($dbc, $sql);

  print '<table class="sortable">
           <caption style="font: bold 15px Verdana, Arial, sans-serif; text-align:left;">Add New Commodity</caption>
           <thead>
             <tr>
               <th>Commodity Code</th>
               <th>Description</th>
               <th>Remarks</th>
             </tr>
             <tr>
               <td><input id="commodity_code" name="commodity_code" type="text" tabindex="1" size="20" required autofocus></td>
               <td style="text-align: center;"><input name="description" type="text" tabindex="2" size="25"></td>
               <td style="text-align: center;"><input name="remarks" type="text" tabindex="3" size="25"></td>
             </tr>
             <tr>
               <td colspan="3" style="border:0px;"
             </tr>
             <tr style="position: sticky; top: 0; background-color: #F5F5F5">
               <th><i>Commodity Code</i></th>
               <th><i>Description</i></th>
               <th><i>Remarks</i></th>
             </tr>
           </thead>';

  if (mysqli_num_rows($rs) > 0)
  {
    while ($row = mysqli_fetch_array($rs))
    {
      print '<tr>
               <td><a href="db_edit.php?tbl_name=commodities&obj_id=' . sts_html($row['id']) . '&obj_name=' . urlencode($row['code']) . '">' . sts_html($row['code']) . '</a></td>
               <td>' . sts_html($row['description']) . '</td>
               <td>' . sts_html($row['remarks']) . '</td>
             </tr>';
    }
  }
  print '</table>';

  // add some extra lines to the instructions div
  print '<script>
           document.getElementById("instructions").innerHTML = document.getElementById("instructions").innerHTML + "Click on column titles shown in <i>italics</i> to sort the table<br /><br />";
         </script>';
  
  // generate a javascript line to set focus on the first input text box
  print '<script>document.getElementById("car_code").focus();</script>';

?>
