<!-- include the HTML table sort scripts -->
<script src="sorttable.js"></script>

<?php
  // list_shipments_codes.php

  // adds a new shipment to the shipments table if the Update button was
  // clicked and if there is a code in the location code text box.

  // set up some styles for the table
  print '<style>
           th, td {padding: 3px;}
         </style>';

  // generate some javascript to display the table name, identify this table to the program, and set the update button's tab index
  sts_editor_ops(array(
    array('id' => 'tbl_name', 'props' => array('value' => 'shipments')),
    array('id' => 'update_btn', 'props' => array('tabIndex' => '18', 'disabled' => true))
  ));
  print '<script src="/sts/js/db_list_shipments.js?v=1"></script>';

  // get a database connection
  $dbc = open_db();

  // has the submit button been clicked?
  if (isset($_POST['update_btn']))
  {
    // yes, so check to see if there is anything in the input text boxes
    if (strlen(sts_post('code')) > 0)
    {
      // add the new shipment to the shipments table
      sts_q($dbc, 'insert into shipments (code, description, consignment, car_code, loading_location, unloading_location, last_ship_date, min_interval, max_interval, min_amount, max_amount, special_instructions, remarks, min_load_time, max_load_time, min_unload_time, max_unload_time) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array(sts_post('code'), sts_post('description'), sts_post('consignment'), sts_post('car_code'), sts_post('loading_location'), sts_post('unloading_location'), sts_post('last_ship_date'), sts_post('min_interval'), sts_post('max_interval'), sts_post('min_amount'), sts_post('max_amount'), sts_post('special_instructions'), sts_post('remarks'), sts_post('min_load_time'), sts_post('max_load_time'), sts_post('min_unload_time'), sts_post('max_unload_time')));
    }
  }

  // query the database for all of the shipments and display them in a table
  $sql = 'select shipments.id as id,
                 shipments.code as code,
                 shipments.description as description,
                 shipments.consignment as consignment_id,
                 shipments.car_code as car_code_id,
                 shipments.loading_location as loading_location_code_id,
                 shipments.unloading_location as unloading_location_id,
                 shipments.last_ship_date as last_ship_date,
                 shipments.min_interval as min_interval,
                 shipments.max_interval as max_interval,
                 shipments.min_amount as min_amount,
                 shipments.max_amount as max_amount,
                 shipments.special_instructions as special_instructions,
                 shipments.remarks as remarks,
                 shipments.min_load_time as min_load_time,
                 shipments.max_load_time as max_load_time,
                 shipments.min_unload_time as min_unload_time,
                 shipments.max_unload_time as max_unload_time,
                 commodities.code as consignment,
                 car_codes.code as car_code,
                 sta01.station as loading_station,
                 loc01.code as loading_location,
                 sta02.station as unloading_station,
                 loc02.code as unloading_location
            from shipments
            left join commodities on commodities.id = shipments.consignment
            left join locations loc01 on loc01.id = shipments.loading_location
            left join locations loc02 on loc02.id = shipments.unloading_location
            left join routing sta01 on sta01.id = loc01.station
            left join routing sta02 on sta02.id = loc02.station
            left join car_codes on car_codes.id = shipments.car_code
            order by shipments.code';
// print 'SQL: ' . $sql . '<br /><br />';
  $rs = mysqli_query($dbc, $sql);

  // generate the table

  print '<table class="table table-sm table-bordered table-hover sts-table sortable" id="ship_tbl" style="white-space: nowrap;">
           <caption class="sts-table-caption">
             <input class="form-check-input" type="radio" name="entry-filter" id="entry" value="entry" checked=false data-sts-click="addShipments">Add New Shipment
           </caption>
           <thead>
             <tr style="background-color: lightgreen;">
               <th>Shipment<br />ID</th>
               <th>Description</th>
               <th>Consignment</th>
               <th>Car<br />Code</th>
               <th>Loading<br /><u>Station</u><br />Location</th>
               <th>Unloading<br /><u>Station</u><br />Location</th>
               <th>Last<br />Ship<br />Date</th>
               <th>Min<br />Int</th>
               <th>Max<br />Int</th>
               <th>Min<br />Amt</th>
               <th>Max<br />Amt</th>
               <th title="x2010: used as the via/routing flag for revenue (loaded) cars">Special<br />Instructions &#9432;</th>
               <th>Remarks</th>
               <th>Min<br />Load<br />Time</th>
               <th>Max<br />Load<br />Time</th>
               <th>Min<br />Unload<br />Time</th>
               <th>Max<br />Unload<br />Time</th>
               <th>ON/OFF</th>
               <th>Empty Location<br />Search Priority</th>
             </tr>
             <tr style="background-color: lightgreen;">
               <td style="text-align:center;"><input class="form-control form-control-sm" id="code" name="code" type="text" tabindex="1" required autofocus></td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="description" name="description" type="text" tabindex="2"></td>
               <td style="text-align:center;">' . drop_down_commodities('consignment', 3) . '</td>
               <td style="text-align:center;">' . drop_down_car_codes('car_code', 4, 'wild_ok') . '</td>
               <td style="text-align:center;">' . drop_down_locations('loading_location', 5, '') . '</td>
               <td style="text-align:center;">' . drop_down_locations('unloading_location', 6, '') . '</td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="last_ship_date" name="last_ship_date" type="text" size="1" tabindex="7" value="0" required style="text-align: center;"></td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="min_interval" name="min_interval" type="text" size="1" tabindex="8" required data-sts-change="checkRange" style="text-align: center;"></td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="max_interval" name="max_interval" type="text" size="1" tabindex="9" required data-sts-change="checkRange" style="text-align: center;"></td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="min_amount" name="min_amount" type="text" size="1" tabindex="10" required data-sts-change="checkRange" style="text-align: center;"></td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="max_amount" name="max_amount" type="text" size="1" tabindex="11" required data-sts-change="checkRange" style="text-align: center;"></td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="special_instructions" name="special_instructions" type="text" tabindex="12"></td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="remarks" name="remarks" type="text" tabindex="13"></td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="min_load_time" name="min_load_time" type="text" value="0" size="1" tabindex="14" style="text-align: center;"></td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="max_load_time" name="max_load_time" type="text" value="0" size="1" tabindex="15" style="text-align: center;"></td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="min_unload_time" name="min_unload_time" type="text" value="0" size="1" tabindex="16" style="text-align: center;"></td>
               <td style="text-align:center;"><input class="form-control form-control-sm" id="max_unload_time" name="max_unload_time" type="text" value="0" size="1" tabindex="17" style="text-align: center;"></td>
               <td></td>
               <td></td>
             </tr>
             <tr>
               <td colspan="19" style="border:0px;">&nbsp;</td>
             </tr>
             <tr>
              <td colspan="19" style="border:0px; text-align:left;">
                <input class="form-check-input" type="radio" name="entry-filter" id="entry" value="entry" checked=false data-sts-click="filterShipments">Filters
              </td>
             </tr>
             <tr style="background-color: yellow;">
               <td style="border-bottom: 0px; border-right:0px; text-align:center;">
                 <input class="btn btn-secondary btn-sm" type="button" id="clear_filters_btn" name="clear_filters_btn" value="CLEAR FILTERS"
                 data-sts-click="clearFilters" style="white-space: nowrap;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px; text-align:center;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px; text-align:center;"
                 data-sts-change="listFilter" data-col="2" data-filter="commodity_filter" data-use="text">' .
                 drop_down_commodities('commodity_filter', '', '') . '
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px; text-align:center;"
                 data-sts-change="listFilter" data-col="3" data-filter="car_code_filter" data-use="text">' .
                 drop_down_car_codes('car_code_filter', '', 'wild_ok') . '
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px; text-align:center;"
                 data-sts-change="listFilter" data-col="4" data-filter="loading_location_filter" data-use="text">' .
                 drop_down_locations('loading_location_filter', '', '') . '
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px; text-align:center;"
                 data-sts-change="listFilter" data-col="5" data-filter="unloading_location_filter" data-use="text">' .
                 drop_down_locations('unloading_location_filter', '', '') . '
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px; border-right:0px;">
               </td>
               <td style="border-bottom: 0px; border-left: 0px;">
               </td>
             </tr>
             <tr style="position: sticky; top: 0; background-color: #F5F5F5">
               <th><i>Shipment<br />ID</th>
               <th><i>Description</th>
               <th><i>Consignment</th>
               <th><i>Car<br />Code</th>
               <th><i>Loading<br /><u>Station</u><br />Location</th>
               <th><i>Unloading<br /><u>Station</u><br />Location</th>
               <th><i>Last<br />Ship<br />Date</th>
               <th><i>Min<br />Int</th>
               <th><i>Max<br />Int</th>
               <th><i>Min<br />Amt</th>
               <th><i>Max<br />Amt</th>
               <th title="x2010: used as the via/routing flag for revenue (loaded) cars"><i>Special<br />Instructions &#9432;</th>
               <th><i>Remarks</th>
               <th class="sorttable_nosort">Min<br />Load<br />Time</th>
               <th class="sorttable_nosort">Max<br />Load<br />Time</th>
               <th class="sorttable_nosort">Min<br />Unload<br />Time</th>
               <th class="sorttable_nosort">Max<br />Unload<br />Time</th>
               <th class="sorttable_nosort">ON/OFF</th>
               <th class="sorttable_nosort">Empty Location<br />Search Priority</th>
             </tr>
           </thead>';

  if (mysqli_num_rows($rs) > 0)
  {
    while ($row = mysqli_fetch_array($rs))
    {
      // if a shipment is in a pool arrangement with certain cars, highlight the background-color
      $sql_pool = 'select count(*) from pool where shipment_id = ' . intval($row['id']) . '';
      $rs_pool = mysqli_query($dbc, $sql_pool);
      $row_pool = mysqli_fetch_array($rs_pool);
      if ($row_pool[0] > 0)
      {
        $background = '#ffff80';
      }
      else
      {
        $background = 'White';
      }

      print '<tr style="background-color:' . $background . ';">';
      print '  <td><a href="db_edit.php?tbl_name=shipments&obj_id=' . sts_html($row['id']) . '&obj_name=' . urlencode($row['code']) . '">' . sts_html($row['code']) . '</td>';
      print '  <td>' . sts_html($row['description']) . '</td>';
      print '  <td>' . sts_html($row['consignment']) . '</td>';
      print '  <td style="text-align: center;">' . sts_html($row['car_code']) . '</td>';
      print '  <td><u>' . sts_html($row['loading_station']) . '</u><br />' . sts_html($row['loading_location']) . '</td>';
      print '  <td><u>' . sts_html($row['unloading_station']) . '</u><br />' . sts_html($row['unloading_location']) . '</td>';
      print '  <td style="text-align: center">' . sts_html($row['last_ship_date']) . '</td>';
      print '  <td style="text-align: center">' . sts_html($row['min_interval']) . '</td>';
      print '  <td style="text-align: center">' . sts_html($row['max_interval']) . '</td>';
      print '  <td style="text-align: center">' . sts_html($row['min_amount']) . '</td>';
      print '  <td style="text-align: center">' . sts_html($row['max_amount']) . '</td>';
      print '  <td>' . sts_html($row['special_instructions']) . '</td>';
      print '  <td>' . sts_html($row['remarks']) . '</td>';
      print '  <td style="text-align: center">' . sts_html($row['min_load_time']) . '</td>';
      print '  <td style="text-align: center">' . sts_html($row['max_load_time']) . '</td>';
      print '  <td style="text-align: center">' . sts_html($row['min_unload_time']) . '</td>';
      print '  <td style="text-align: center">' . sts_html($row['max_unload_time']) . '</td>';
      if ($row['last_ship_date'] > 1000000)
      {
        print '  <td style="text-align:center;">OFF</td>';
      }
      else
      {
        print '  <td style="text-align:center;">ON</td>';
      }
      print '  <td style="text-align:center;">
                 <a href="db_edit.php?tbl_name=empty_locations&obj_id=' . sts_html($row['id']) . '&obj_name=' . urlencode($row['code']) . '">Add/Edit
               </td>';
      print '</tr>';
    }
  }
  print '</table>';

  // add some extra lines to the instructions div
  sts_editor_ops(array(
    array('id' => 'instructions', 'appendHtml' => 'Filters can be used to hide rows. Click on column titles shown in <i>italics</i> to sort the table<br /><br />Shipments in a car/shipment pooling arrangement are <span style="background-color:#ffff80;">highlighted.</span><br /><br />')
  ));

?>
