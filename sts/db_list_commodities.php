<!-- include the HTML table sort scripts -->
<script src="sorttable.js"></script>

<?php
  // list_ccommodities.php

  // adds a new commodity to the commodities table if the Update button was
  // clicked and if there is a code in the commodity code text box.

  // generate some javascript to display the table name, identify this table to the form, and set the update button's tab index
  sts_editor_ops(array(
    array('id' => 'tbl_name', 'props' => array('value' => 'commodities')),
    array('id' => 'update_btn', 'props' => array('tabIndex' => '4'))
  ));

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

  print '<table class="table table-sm table-bordered table-hover sts-table sortable">
           <caption class="sts-table-caption">Add New Commodity</caption>
           <thead>
             <tr>
               <th>Commodity Code</th>
               <th>Description</th>
               <th>Remarks</th>
             </tr>
             <tr>
               <td><input class="form-control form-control-sm" id="commodity_code" name="commodity_code" type="text" tabindex="1" size="20" required autofocus></td>
               <td style="text-align: center;"><input class="form-control form-control-sm" name="description" type="text" tabindex="2" size="25"></td>
               <td style="text-align: center;"><input class="form-control form-control-sm" name="remarks" type="text" tabindex="3" size="25"></td>
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
  sts_editor_ops(array(
    array('id' => 'instructions', 'appendHtml' => 'Click on column titles shown in <i>italics</i> to sort the table<br /><br />')
  ));
  
  // generate a javascript line to set focus on the first input text box
  sts_editor_ops(array(array('id' => 'commodity_code', 'focus' => true)));

?>
