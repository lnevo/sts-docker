<!-- include the HTML table sort scripts -->
<script src="sorttable.js"></script>

<?php
  // list_car_orders.php

  // lists all car orders/waybills, the shipments that generated them, and the cars
  // assigned to them

  // generate some javascript to display the table name, identify this table to the program, and set the update button tab index
  sts_editor_ops(array(
    array('id' => 'tbl_name', 'props' => array('value' => 'car_orders')),
    array('id' => 'update_btn', 'props' => array('tabIndex' => '10', 'value' => 'CANCEL ORDERS')),
    array('id' => 'instructions', 'style' => array('display' => 'none')),
    array('id' => 'update', 'style' => array('display' => 'none')),
    array('id' => 'instructions', 'html' => 'To cancel a car order, click on its checkbox and then on the <b>CANCEL ORDERS</b> button.<br />Car orders that have already been filled cannot be canceled.<br /><br />Filters can be used to hide rows. Click on column titles shown in <i>italics</i> to sort the table<br /><br />')
  ));
  print '<script src="/sts/js/db_list_car_orders.js?v=1"></script>';

  // bring in the rollingstock photo popup (js/show_image.js)
  require 'show_image.php';
         
  // get a database connection
  $dbc = open_db();

  // was the update button clicked?
  if (isset($_POST['update_btn']))
  {
    // go through the car orders and see if any of them were marked for cancellation
    $wb_count = intval(sts_post('row_count'));
    for ($i=0; $i<$wb_count; $i++)
    {
      // check each the value of each check box
      $chkbox = "wb" . $i;
      if (isset($_POST[$chkbox]) > 0)
      {
        // delete the car order
        if (!sts_q($dbc, 'delete from car_orders where waybill_number = ?', array(sts_post($chkbox))))
        {
          print "Update Error: " . sts_html(sts_err($dbc)) . "<br /><br />";
        }
      }
    }
  }


  // query the database for all of the car orders and display them in a table
  $sql = 'select car_orders.waybill_number as waybill_number,
                 car_orders.car as car_id,
                 car_orders.shipment as shipment_id,
                 shipments.car_code as car_code_id,
                 shipments.loading_location as loading_location_id,
                 shipments.unloading_location as unloading_location_id,
                 shipments.code as shipment,
                 shipments.remarks as remarks,
                 cars.reporting_marks as car,
                 cars.current_location_id as current_location_id,
                 cars.status as status,
                 car_codes.code as car_code,
                 routing.station as current_station,
                 locations.code as current_location
          from car_orders
          left join shipments on shipments.id = car_orders.shipment
          left join cars on cars.id = car_orders.car
          left join car_codes on car_codes.id = shipments.car_code
          left join locations on locations.id = cars.current_location_id
          left join routing on routing.id = locations.station
          where car_orders.waybill_number like "%-E%"
          or (car_orders.waybill_number != "" or car_orders.waybill_number is not null)
          order by car_orders.waybill_number';

  $rs = mysqli_query($dbc, $sql);

  $row_count = 1;

  if (mysqli_num_rows($rs) > 0)
  {
    // display a prompt and the SUBMIT button
    sts_editor_ops(array(
      array('id' => 'instructions', 'style' => array('display' => 'block')),
      array('id' => 'update', 'style' => array('display' => 'block'))
    ));

    // generate a check box that when activated will run a javascript routine which will hide car orders that have been filled
    print 'Hide filled car orders: <input class="form-check-input" tabindex="3" type="checkbox" id="hide_unavail" name="hide_unavail" data-sts-click="hideRows" data-col="2"><br /><br />';
    
    // generate the table
    print '<table class="table table-sm table-bordered table-hover sts-table sortable" id="wb_tbl" style=""  style="white-space: nowrap;">
             <caption class="sts-table-caption">Row Filters</caption>
             <thead>
               <tr>
                 <th style="border-bottom:0px; border-right:0px;">
                   <input class="btn btn-secondary btn-sm" tabindex="4" type="button" id="clear_filters_btn" name="clear_filters_btn" value="CLEAR FILTERS"
                   data-sts-click="reloadPage" style="text-align:left;">
                 </th>
                 <th style="border-bottom:0px; border-left:0px; border-right:0px;"
                     data-sts-change="listFilter" data-col="1" data-filter="wb_type_filter" data-use="value">
                     <select class="form-select form-select-sm" id="wb_type_filter" name="wb_type_filter" tabindex="5">
                       <option value=""></option>
                       <option value="A">Automatic</option>
                       <option value="M">Manual</option>
                       <option value="E">Reposition</option>
                     </select>
                 </th>
                 <th style="border-bottom:0px; border-left:0px; border-right:0px;">
                 </th>
                 <th style="border-bottom:0px; border-left:0px; border-right:0px;"
                     data-sts-change="listFilter" data-col="3" data-filter="car_code_filter" data-use="text">' .
                               drop_down_car_codes('car_code_filter', '6', 'no_wild') . '
                 </th>
                 <th style="border-bottom:0px; border-left:0px; border-right:0px;"
                     data-sts-change="listFilter" data-col="4" data-filter="shipment_filter" data-use="text">' .
                               drop_down_shipments('shipment_filter', '7', '') . '
                 </th>
                 <th style="border-bottom:0px; border-left:0px; border-right:0px;"
                     data-sts-change="listFilter" data-col="5" data-filter="commodity_filter" data-use="text">' .
                               drop_down_commodities('commodity_filter', '8', '') . '
                 </th>
                 <th style="border-bottom:0px; border-left:0px; border-right:0px;"
                     data-sts-change="listFilter" data-col="6" data-filter="status_filter" data-use="text">' .
                               drop_down_status('status_filter', '9', '') . '
                 </th>
                 <th style="border-bottom:0px; border-left:0px; border-right:0px;"
                     data-sts-change="listFilter" data-col="7" data-filter="loading_loc_filter" data-use="text">' .
                               drop_down_locations('loading_loc_filter', '10', '') . '
                 </th>
                 <th style="border-bottom:0px; border-left:0px; border-right:0px;"
                     data-sts-change="listFilter" data-col="8" data-filter="current_loc_filter" data-use="text">' .
                               drop_down_locations('current_loc_filter', '11', '') . '
                 </th>
                 <th style="border-bottom:0px; border-left:0px; border-right:0px;"
                     data-sts-change="listFilter" data-col="9" data-filter="unloading_loc_filter" data-use="text">' .
                               drop_down_locations('unloading_loc_filter', '12', '') . '
                 </th>
                 <th style="border-bottom:0px; border-left:0px">
                 </th>
               </tr>
               <tr style="position: sticky; top: 0; background-color: #F5F5F5">
                 <th class="sorttable_nosort">
                   Cancel<br /><br />
                 </th>
                 <th>
                   <i>Waybill<br />Number</i><br /></th>
                 <th>
                   <i>Assigned<br />Car</i>
                 </th>
                 <th>
                   <i>Car<br />Code</i><br /></th>
                 <th>
                   <i>Shipment</i><br /><br /></th>
                 <th>
                   <i>Consignment</i><br /><br /></th>
                 <th>
                   <i>Status</i>
                 </th>
                 <th>
                   <i>Loading<br /><u>Station</u><br />Location</i>
                 </th>
                 <th>
                   <i>Current<br /><u>Station</u><br />Location</i>
                 </th>
                 <th>
                   <i>Unloading<br /><u>Station</u><br />Location</i>
                 </th>
                 <th>
                   <i>Remarks
                 </th>
               </tr>
             </thead>';

    while ($row = mysqli_fetch_array($rs))
    {
      // get the info needed to display the photo of the car associated with this waybill
      if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['car_id'] . '.jpg'))
      {
        $img_id = sts_html($row['car_id']);
      }
      else
      {
        $img_id = '';
      }
              
      // if a car order is for a non-revenue move (reposition) find out about it's car
      if (substr($row['waybill_number'], 4, 1) == "E")
      {
        $sql_car_code = 'select cars.car_code_id,
                                car_codes.code as car_code,
                                locations.code as destination,
                                routing.station as destination_station
                           from cars, car_codes, locations, car_orders, routing
                          where reporting_marks = "' . mysqli_real_escape_string($dbc, $row['car']) . '"
                            and car_codes.id = cars.car_code_id
                            and car_orders.car = cars.id
                            and locations.id = car_orders.shipment
                            and routing.id = locations.station';
        $rs_car_code = mysqli_query($dbc, $sql_car_code);
        $row_car_code = mysqli_fetch_array($rs_car_code);

        // generate the empty way bill row
        print '<tr id="row' . $row_count . '">
                 <td style="text-align: center;">
                   <input class="form-check-input" name=wb' . $row_count . ' value="' . sts_html($row['waybill_number']) . '" type="checkbox" disabled>
                 </td>
                 <td>' . sts_html($row['waybill_number']) . '</td>
                 <td data-sts-click="showImage" data-car-id="' . $img_id . '" data-marks="' . sts_html($row['car']) . '">' . sts_html($row['car']) . '</td>
                 <td style="text-align: center;">' . sts_html($row_car_code['car_code']) . '</td>
                 <td>Non-Revenue</td>
                 <td>N/A</td>
                 <td>' . sts_html($row['status']) . '</td>
                 <td>N/A</td>
                 <td><u>' . sts_html($row['current_station']) . '</u><br />' . sts_html($row['current_location']) . '</td>
                 <td><u>' . sts_html($row_car_code['destination_station']) . '</u><br />' . sts_html($row_car_code['destination']) . '</td>
                 <td>Reposition empty car</td>
               </tr>';
      }
      else
      {
        // otherwise generate a normal row
        print '<tr id="row' . $row_count . '">';

        // run some quick queries to get this waybill's consignment, loading location, and unloading location
        $sql2 = 'select commodities.code as consignment
                   from commodities, shipments 
                  where shipments.id = ' . intval($row['shipment_id']) . ' and commodities.id = shipments.consignment';
        $rs2 = mysqli_query($dbc, $sql2);
        $row2 = mysqli_fetch_array($rs2);
        
        $sql3 = 'select code as loading_location from locations where locations.id = ' . intval($row['loading_location_id']) . '';
        $rs3 = mysqli_query($dbc, $sql3);
        $row3 = mysqli_fetch_array($rs3);
        
        $sql4 = 'select code as unloading_location from locations where locations.id = ' . intval($row['unloading_location_id']) . '';
        $rs4 = mysqli_query($dbc, $sql4);
        $row4 = mysqli_fetch_array($rs4);
        
        $sql5 = 'select routing.station as loading_station
                   from routing, locations
                  where routing.id = locations.station
                    and locations.id = ' . intval($row['loading_location_id']) . '';
        $rs5 = mysqli_query($dbc,$sql5);
        $row5 = mysqli_fetch_array($rs5);
        
        if (($row['car_id'] > 0) && ($row['current_location_id'] > 0))
        {
          $current_station_location = '<u>' . sts_html($row['current_station']) . '</u><br />' . sts_html($row['current_location']);
        }
        else if (($row['car_id'] > 0) && ($row['current_location_id'] == 0))
        {
          $sql7 = 'select jobs.name from jobs, cars where jobs.id = cars.handled_by_job_id';
          $rs7 = mysqli_query($dbc, $sql7);
          $row7 = mysqli_fetch_array($rs7);
          $current_station_location = 'In Train ' . sts_html($row7['name']);
        }
        else
        {
          $current_station_location = '';
        }
        
        $sql6 = 'select routing.station as unloading_station
                   from routing, locations
                  where routing.id = locations.station
                    and locations.id = ' . intval($row['unloading_location_id']) . '';
        $rs6 = mysqli_query($dbc,$sql6);
        $row6 = mysqli_fetch_array($rs6);        
        
        // only display an enabled checkbox if a car hasn't been assigned to the car order
        // we don't want to cancel car orders / waybills if a shipment is enroute
        if (strlen($row['car']) > 0)
        {
          print '<td style="text-align: center;">
                   <input class="form-check-input" name=wb' . $row_count . ' value="' . sts_html($row['waybill_number']) . '" type="checkbox" disabled>
                 </td>';
        }
        else
        {
          print '<td style="text-align: center;">
                   <input class="form-check-input" name=wb' . $row_count . ' value="' . sts_html($row['waybill_number']) . '" type="checkbox">
                 </td>';
        }
        
        print '<td>' . sts_html($row['waybill_number']) . '</td>
               <td data-sts-click="showImage" data-car-id="' . $img_id . '" data-marks="' . sts_html($row['car']) . '">' . sts_html($row['car']) . '</td>
               <td style="text-align: center;">' . sts_html($row['car_code']) . '</td>
               <td>' . sts_html($row['shipment']) . '</td>
               <td>' . sts_html($row2['consignment']) . '</td>
               <td>' . sts_html($row['status']) . '</td>
               <td><u>' . sts_html($row5['loading_station']) . '</u><br />' . sts_html($row3['loading_location']) . '</td>
               <td>' . $current_station_location . '</td>
               <td><u>' . sts_html($row6['unloading_station']) . '</u></br>' . sts_html($row4['unloading_location']) . '</td>
               <td>' . sts_html($row['remarks']) . '</td>
             </tr>';
      }
      $row_count++;
    }
    print "</table>";
  }
  else
  {
    print "<br /><br />No car orders on hand<br /><br />";
  }
  print '<input name="row_count" value="' . $row_count . '" type="hidden">';
?>
