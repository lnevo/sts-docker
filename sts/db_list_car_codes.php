<!-- include the HTML table sort scripts -->
<script src="sorttable.js"></script>

<?php
  // list_car_codes.php

  // adds a new car code to the car_codes table if the Update button was
  // clicked and if there is a code in the car code text box.

  // generate some javascript to display the table name, identify this table to the form, and set the update button's tab index
  print '<script>
           document.getElementById("tbl_name").value = "car_codes";
           document.getElementById("update_btn").tabIndex = "4";
         </script>';

  // get a database connection
  $dbc = open_db();

  // has the submit button been clicked?
  if (isset($_POST['update_btn']))
  {
    // yes, so check to see if there is anything in the input text boxes
    if (strlen(sts_post('car_code')) > 0)
    {
      // add the new car code to the car code table
      sts_q($dbc, 'insert into car_codes (code, description, remarks) values (?, ?, ?)',
            array(sts_post('car_code'), sts_post('description'), sts_post('remarks')));
    }
  }

  // query the database for all of the car codes and display them in a table
  $sql = 'select id, code, description, remarks from car_codes order by code';
  $rs = mysqli_query($dbc, $sql);

  print '<table class="table table-sm table-bordered table-hover sts-table sortable">
           <caption class="sts-table-caption">Add New Car Code</caption>
           <thead>
             <tr>
               <th>Car Code</th>
               <th>Description</th
               ><th>Remarks</th>
             </tr>
             <tr>
               <td><input class="form-control form-control-sm" id="car_code" name="car_code" type="text" tabindex="1" size="10" required autofocus></td>
               <td style="text-align: center;"><input class="form-control form-control-sm" name="description" type="text" tabindex="2" size="25"></td>
               <td style="text-align: center;"><input class="form-control form-control-sm" name="remarks" type="text" tabindex="3" size="25"></td>
             </tr>
             <tr>
               <td style="border:0px;">
               </td>
               </tr>
             <tr style="position: sticky; top: 0; background-color: #F5F5F5">
               <th><i>Car Code</i></th>
               <th><i>Description</i></th
               ><th><i>Remarks</i></th>
             </tr>
           </thead>';

  if (mysqli_num_rows($rs) > 0)
  {
    while ($row = mysqli_fetch_array($rs))
    {
      print '<tr>
               <td><a href="db_edit.php?tbl_name=car_codes&obj_id=' . sts_html($row['id']) . '&obj_name=' . urlencode($row['code']) . '">' . sts_html($row['code']) . '</a></td>
               <td>' . sts_html($row['description']) . '</td>
               <td>' . sts_html($row['remarks']) . '</td>
             </tr>';
    }
  }
  print "</table>";

  // add some extra lines to the instructions div
  print '<script>
           document.getElementById("instructions").innerHTML = document.getElementById("instructions").innerHTML + "Click on column titles shown in <i>italics</i> to sort the table<br /><br />";
         </script>';
  
  // generate a javascript line to set focus on the first input text box
  print '<script>document.getElementById("car_code").focus();</script>';

?>
