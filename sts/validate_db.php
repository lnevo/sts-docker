<?php
require_once __DIR__ . '/maint_lock.php'; sts_maint_require('operator');
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Validate Database',
    'icon' => 'bi-check2-square',
    'section' => 'maint',
]);
?>
<div class="sts-card">
    <table class="table table-sm" style="max-width:900px;">
    <tr style="border: 0px;">
      <td style="border: 0px;">
        This report checks the STS database for any broken links that may be due to database objects having been removed after the simulation is running or because
        of other events that may have corrupted some of the database components.
      </td>
    </tr>

    <?php
      // pull in the utility files
      require 'open_db.php';

      // get a database connection
      $dbc = open_db();

// check locations for missing stations -----------------------------------------------------------------------------------------------------------------------

      print '<tr>
               <td>
                 <b>Locations without a valid link to a station</b> These locations will not be displayed on the List Locations screen because of the broken link.
                    Use the <a class="btn btn-sm btn-outline-danger py-0" href="./fix_ghost_locations.php">Fix Ghost Locations</a> function to remove these locations.
               </td>
             </tr>';
      $sql = 'select code from locations where station not in (select id from routing) order by code';
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) > 0)
      {
        print '<tr>
                 <td>
                   <ul>';
        while($row = mysqli_fetch_array($rs))
        {
          print '<li>' . $row['code'] . '</li>';
        }
        print '    </ul>
                 </td>
               </tr>';
      }
      else
      {
        print '<tr><td>No Location link errors found</td></tr>';
      }
      print '<tr><td></td></tr>';
      
// check shipments for missing commodities, car codes, loading locations, and unloading locations and any car orders and cars affected by this-----------------

      print '<tr>
               <td>
                 <b>Shipments with invalid links</b> To repair these shipments, use the Edit Shipment screen and select a replacement for the missing item.
                    If necessary, create a new item to replace what is missing and then link the shipment to it.
               </td>
             </tr>';
      
      $sql = 'select shipments.id as id,
                     shipments.code as shipment_code, 
                     commodities.code as commodity_code,
                     car_codes.code as car_code,
                     loc1.code as loading_loc,
                     loc2.code as unloading_loc
                from shipments
                left join commodities on shipments.consignment = commodities.Id
                left join car_codes on shipments.car_code = car_codes.Id
                left join locations loc1 on shipments.loading_location = loc1.Id
                left join locations loc2 on shipments.unloading_location = loc2.Id
               where consignment not in (select id from commodities)
                  or car_code not in (select id from car_codes)
                  or loading_location not in (select id from locations)
                  or unloading_location not in (select id from locations)
            order by shipments.code';
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) > 0)
      {
        print '<tr>
                 <td>
                   <ul>';
        while($row = mysqli_fetch_array($rs))
        {
          print '<li><a href="./db_edit.php?tbl_name=shipments&obj_id=' . $row['id'] . '&obj_name=' . $row['shipment_code'] . '">' . $row['shipment_code'] . '</a> is missing';
          $show_comma = false;
          if (strlen($row['commodity_code']) < 1)
          {
            print ' Commodity';
            $show_comma = true;
          }
          if (strlen($row['car_code']) < 1)
          {
            if ($show_comma)
            {
              print ', ';
            }
            print ' Car code';
            $show_comma = true;
          }
          if (strlen($row['loading_loc']) < 1)
          {
            if ($show_comma)
            {
              print ', ';
            }
            print ' Loading location';
            $show_comma = true;
          }
          if (strlen($row['unloading_loc']) < 1)
          {
            if ($show_comma)
            {
              print ', ';
            }
            print ' Unloading location';
          }
         print '</li>';
        }
        print '    </ul>
                 </td>
               </tr>';
      }
      else
      {
        print '<tr><td>No Shipment link errors found</td></tr>';
      }
      print '<tr><td></td></tr>';
      
// check cars for missing or invalid car codes, status codes, current location, loading location, unloading location,  job, and/or home location --------------

        print '<tr>
                 <td>
                   <b>Cars with invalid links</b> To repair broken Car Code, Status, Current Location, Home Location and Job links, 
                     click on a car\'s reporting marks and use the Edit Car screen to select a replacement for the missing item. If
                     necessary, create a new item to replace what is missing and then link the car to it. Cars that have been
                     assigned to car orders can have missing Loading and/or Unloading locations if the shipment associated with that
                     car order has broken links to those locations. (See Shipments with Invalid Links above.)
                 </td>
               </tr>';
      
      $sql = 'select cars.id as id,
                     cars.reporting_marks,
                     "Car Code" as missing_item
                from cars
               where cars.car_code_id not in (select id from car_codes)
               UNION ALL
              select cars.id as id,
                     cars.reporting_marks,
                     "Status" as missing_item
                     from cars
               where cars.status not in ("Empty", "Ordered", "Loading", "Loaded", "Unloading", "Unavailable")
               UNION ALL
              select cars.id as id,
                     cars.reporting_marks,
                     "Current Location" as missing_item
                from cars
               where cars.current_location_id > 0
                 and cars.current_location_id not in (select id from locations)
               UNION ALL
              select cars.id as id,
                     cars.reporting_marks,
                     "Home Location" as missing_item
                from cars
               where cars.home_location not in (select id from locations)
               UNION ALL
              select cars.id as id,
                     cars.reporting_marks,
                     "Loading Location" as missing_item
                from cars, car_orders, shipments
               where cars.status = "Empty"
                 and cars.handled_by_job_id > 0
                 and car_orders.car = cars.Id
                 and shipments.id = car_orders.shipment
                 and shipments.loading_location not in (select id from locations)
               UNION ALL
              select cars.id as id,
                     cars.reporting_marks,
                     "Unloading Location" as missing_item
                from cars, car_orders, shipments
               where cars.status = "Loaded"
                 and cars.handled_by_job_id > 0
                 and car_orders.car = cars.Id
                 and shipments.id = car_orders.shipment
                 and shipments.unloading_location not in (select id from locations)
               UNION ALL
              select cars.id as id,
                     cars.reporting_marks as reporting_marks,
                     "Invalid Job" as missing_item
                from cars
               where cars.handled_by_job_id > 0
                 and cars.handled_by_job_id not in (select id from jobs)
              order by reporting_marks, 
                       CASE 
                         WHEN missing_item = "Car Code" THEN 0 
                         WHEN missing_item = "Current Location" THEN 1
                         when missing_item = "Home Location" then 2
                         WHEN missing_item = "Loading Location" THEN 3
                         WHEN missing_item = "Unloading Location" THEN 4
                         when missing_item = "Invalid Job" then 5
                       END'; 
// print 'SQL: ' . $sql . '<br /><br />';      
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) > 0)
      {
        print '<tr>
                 <td>
                   <ul>';
        while($row = mysqli_fetch_array($rs))
        {
          // if the missing item is a loading or unloading location, run a quick query to find which shipment is the culprit
          if (($row['missing_item'] == 'Loading Location') || ($row['missing_item'] == 'Unloading Location'))
          {
            $sql2 = 'select shipments.id as id, 
                            shipments.code as code
                       from shipments, car_orders, cars
                      where car_orders.car = ' . $row['id'] . '
                        and shipments.id = car_orders.shipment';
            $rs2 = mysqli_query($dbc, $sql2);
            $row2 = mysqli_fetch_array($rs2);
            $shipment_id = $row2['id'];
            $shipment_code = $row2['code'];
            $shipment_link = 'db_edit.php?tbl_name=shipments&obj_id=' . $shipment_id . '&obj_name=' . $shipment_code;
          }
        
          print '<li>';
          if (($row['missing_item'] == 'Car Code') || 
              ($row['missing_item'] == 'Status') ||
              ($row['missing_item'] == 'Current Location') || 
              ($row['missing_item'] == 'Home Location') || 
              ($row['missing_item'] == 'Invalid Job'))
          {
            print '<a href="./db_edit.php?tbl_name=cars&obj_name=' . $row['id'] . '&obj_id=' . $row['reporting_marks'] . '">' . $row['reporting_marks'] . '</a>: ';
            print $row['missing_item'];
          }
          else if ($row['missing_item'] == 'Loading Location')
          {
            print $row['reporting_marks'] . ': Missing loading location in shipment ' . '<a href="' . $shipment_link . '">' . $shipment_code . '</a>';
          }
          else if ($row['missing_item'] == 'Unloading Location')
          {
            print $row['reporting_marks'] . ': Missing unloading location in shipment ' . '<a href="' . $shipment_link . '">' . $shipment_code . '</a>';
          }
          print '</li>';
        }
        print '    </ul>
                 </td>
               </tr>';
      }
      else
      {
        print '<tr><td>No Car link errors found</td></tr>';
      }
      print '<tr><td></td></tr>';
            
// check for empty cars that still have a commodity code in their consignment field ---------------------------------------------------------------------------

      print '<tr>
               <td>
                 <b>Cars shown as Empty but may still be loaded</b> Any car with a status of Empty should not be shown as having a consignment.
                    Use the <a class="btn btn-sm btn-outline-danger py-0" href="./fix_empty_commodity.php">Find and Fix Empty Cars having Consignments</a> function to repair these problems.
               </td>
             </tr>';

      $sql = 'select cars.id as id,
                     cars.reporting_marks as reporting_marks,
                     cars.status as status,
                     car_orders.waybill_number,
                     commodities.code as commodity
                from cars, commodities, car_orders, shipments
               where cars.status = "Empty"
                 and cars.id = car_orders.car
                 and car_orders.shipment = shipments.id
                 and shipments.consignment = commodities.id
               order by cars.reporting_marks';

      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) > 0)
      {
       print '<tr>
                <td>
                  <ul>';
       while($row = mysqli_fetch_array($rs))
       {
         print '<li>' . 
           $row['reporting_marks'] . ' (' . $row['status'] . ') is assigned to Waybill No. ' . $row['waybill_number'] . ' and may be loaded with ' . $row['commodity'];
         print '</li>';
       }
       print '    </ul>
                </td>
              </tr>';
      }
      else
      {
       print '<tr><td>No Cars with the Empty/Loaded conflict found</td></tr>';
      }
      print '<tr><td></td></tr>';

// check for cars in trains that still have current locations -------------------------------------------------------------------------------------------------
// this check will turn up false positives when cars have been assigned to jobs but haven't been picked up yet
// we won't run this check unless problems pop up
/*-----
      print '<tr>
               <td>
                 <b>Cars in Trains that still have Current Locations</b> Any car currently in a train should not have a "Current Location".
                    Use the <a class="btn btn-sm btn-outline-danger py-0" href="./fix_cars_trains_loc.php">Find and Fix Cars in Trains w/Current Location</a> function to repair these problems.
               </td>
             </tr>';

      $sql = 'select cars.id as id,
                     cars.reporting_marks as reporting_marks,
                     cars.status as status,
                     jobs.name as job_name,
                     locations.code as location,
                     routing.station as station
                from cars, jobs, locations, routing
               where cars.handled_by_job_id = jobs.id
                 and cars.current_location_id = locations.id
                 and locations.station = routing.id
               order by cars.reporting_marks';

      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) > 0)
      {
       print '<tr>
                <td>
                  <ul>';
       while($row = mysqli_fetch_array($rs))
       {
         print '<li>' . 
           $row['reporting_marks'] . ' (' . $row['status'] . ') is in ' . $row['job_name'] . '<br />Current Station - Location: ' . $row['station'] . ' - ' . $row['location'];
         print '</li>';
       }
       print '    </ul>
                </td>
              </tr>';
      }
      else
      {
       print '<tr><td>No Orphaned Cars found</td></tr>';
      }
      print '<tr><td></td></tr>';
-----*/

// check for orphaned cars ------------------------------------------------------------------------------------------------------------------------------------

      print '<tr>
               <td>
                 <b>Orphaned Cars</b> These repositioned cars are assigned to car orders which are missing their links to an unloading
                    location or the cars have a status code other than "Ordered." Use the <a class="btn btn-sm btn-outline-danger py-0" href="./fix_orphans.php">Find and Fix Orphaned Cars</a> function to repair these problems.
               </td>
             </tr>';
       
      $sql = 'select distinct cars.id,
                     cars.reporting_marks as reporting_marks,
                     cars.status,
                     car_orders.waybill_number as waybill_number, 
                     car_orders.shipment
                from cars, car_orders
               where cars.id = car_orders.car
                 and car_orders.waybill_number like "___-E__"
                 and (car_orders.shipment not in (select locations.id from locations)
                      or cars.status != "Ordered")
               order by cars.reporting_marks';
              
      $rs = mysqli_query($dbc, $sql);
      
      // display the list of orphans
      if (mysqli_num_rows($rs) > 0)
      {
        print '<tr>
                 <td>
                   <ul>';
        while($row = mysqli_fetch_array($rs))
        {
          print '<li>' . $row['reporting_marks'] . ', assigned to Waybill ' . $row['waybill_number'];
          if ($row['status'] != 'Ordered')
          {
            print ' - Incorrect status of ' . $row['status'];
          }
          else
          {
            print ' - Missing Unloading Location';
          }
          print '</li>';
        }
        print '    </ul>
                 </td>
               </tr>';
      }
      else
      {
        print '<tr><td>No Orphaned Cars found</td></tr>';
      }
      print '<tr><td></td></tr>';

// check for incomplete waybills ------------------------------------------------------------------------------------------------------------------------------

      print '<tr>
               <td>
                 <b>Incomplete Waybills</b> These waybills were not closed out when the cars attached to them were unloaded at their final destination.
                 Use the <a class="btn btn-sm btn-outline-danger py-0" href="./fix_waybills.php">Find and Fix Incomplete Waybills</a> function to repair these problems.
               </td>
             </tr>';
       
      $sql = 'select car_orders.waybill_number as waybill_number, 
              shipments.code as shipment, 
              routing.station as station,
              locations.code as location, 
              cars.reporting_marks as reporting_marks, 
              cars.status as status
         from car_orders, cars, shipments, locations, routing
        where cars.id = car_orders.car
          and car_orders.shipment = shipments.Id
          and cars.status = "Empty"
          and cars.current_location_id = shipments.unloading_location
          and locations.id = cars.current_location_id
          and routing.id = locations.station
     order by waybill_number';
              
      $rs = mysqli_query($dbc, $sql);
      
      // display the list of unfinished waybills
      if (mysqli_num_rows($rs) > 0)
      {
        print '<tr>
                 <td>
                   <table style="table-collapse: collapse;">
                     <tr>
                       <th style="border: 0px;">Waybill<br />Number</th>
                       <th style="border: 0px;">Shipment</th>
                       <th style="border: 0px;"><u>Unloading Station</u><br />Location</th>
                       <th style="border: 0px;">Reporting<br />Marks</th>
                       <th style="border: 0px;">Status</th>
                     </tr>';
        while($row = mysqli_fetch_array($rs))
        {
          print '<tr>
                   <td style="border: 0px;">' . $row['waybill_number'] . '</td>
                   <td style="border: 0px;">' . $row['shipment'] . '</td>
                   <td style="border: 0px;"><u>' . $row['station'] . '</u><br />' . $row['location'] . '</td>
                   <td style="border: 0px;">' . $row['reporting_marks'] . '</td>
                   <td style="border: 0px;">' . $row['status'] . '</td>
                 </tr>';
        }
        print '    </table>
                 </td>
               </tr>';
      }
      else
      {
        print '<tr><td>No Incomplete Waybills found</td></tr>';
      }
      print '<tr><td></td></tr>';

// check job step tables for missing stations -----------------------------------------------------------------------------------------------------------------
      
      print '<tr>
               <td>
                 <b>Job Steps without a valid link to a station</b> These job steps will not be displayed in the Job Step list because of the
                    broken link. Use the <a class="btn btn-sm btn-outline-danger py-0" href="./fix_ghost_steps.php">Fix Ghost Job Steps</a> function to remove the missing steps, then 
                    recreate the job step and link it to an existing station.
               </td>
             </tr>';
      $bad_link_found = false;
      $first_time = true;
      $tr_printed = false;
      $sql = 'select name from jobs';
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) > 0)
      {
        // use the list of station names from the first query to provide table names for the second search
        while ($row = mysqli_fetch_array($rs))
        {
          $sql2 = 'select step_number from `' . $row['name'] . '` where station not in (select id from routing)';
          $rs2 = mysqli_query($dbc, $sql2);
          if (mysqli_num_rows($rs2))
          {
            if ($first_time)
            {
              print '<tr>
                       <td>';
              $first_time = false;
              $tr_printed = true;
            }
          
            $bad_link_found = true;
            print ' <ul>';
            while($row2 = mysqli_fetch_array($rs2))
            {
              print '<li>' . $row['name'] . ', Step ' . $row2['step_number'] . ' is not linked to a station</li>';
            }
            print '    </ul>';
          }
        }
        if ($tr_printed)
        {
          print '    </td>
                   </tr>';
        }
      }
      if (!$bad_link_found)
      {
        print '<tr><td>No Job Step link errors found</td></tr>';
      }
      print '<tr><td></td></tr>';

// check for ghost cars ---------------------------------------------------------------------------------------------------------------------------------------

      print '<tr>
               <td>
                 <b>Ghost Cars</b> These cars are referenced in car orders but don\'t exist in the cars database. Use the <a class="btn btn-sm btn-outline-danger py-0" href="./fix_ghost_cars.php">Fix Ghost Cars</a>
                   function to remove these car orders.
               </td>
             </tr>';
             
      $sql = 'select waybill_number from car_orders where car > 0 and car not in (select id from cars) order by waybill_number';
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) > 0)
      {
        print '<tr>
                 <td>
                   <ul>';
        while ($row = mysqli_fetch_array($rs))
        {
          print '<li>' . $row['waybill_number'] . '</li>';
        }
        print '    </ul>
                 </td>
               </tr>';
      }
      else
      {
        print '<tr><td>No Ghost Cars found</tr></td>';
      }
      print '<tr><td></td></tr>';

// check for ghost trains -------------------------------------------------------------------------------------------------------------------------------------

      print '<tr>
               <td>
                 <b>Ghost Trains</b> These jobs/trains, while no longer in the database, still have job steps associated with them
                    that are taking up resources and may cause database corruption. Use the <a class="btn btn-sm btn-outline-danger py-0" href="./fix_ghost_trains.php">Fix Ghost Trains</a>
                    function to eliminate each ghost job\'s abandoned steps.
               </td>
             </tr>';
      $sql = 'SELECT table_name
                FROM information_schema.tables
               WHERE table_type = "base table"
                 AND table_schema="sts_db3"
                 and table_name not in (select name from sts_db3.jobs)
                 and table_name not in ("blocks",
                                        "cars",
                                        "car_codes",
                                        "car_orders",
                                        "commodities",
                                        "empty_locations",
                                        "history",
                                        "jobs",
                                        "locations",
                                        "owners",
                                        "ownership",
                                        "pool",
                                        "pu_criteria",
                                        "routing",
                                        "settings",
                                        "shipments")';
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) > 0)
      {
        print '<tr>
                 <td>
                   <ul>';
        while ($row = mysqli_fetch_array($rs))
        {
          print '<li>' . $row['table_name'] . '</li>';
        }
        print '    </ul>
                 </td>
               </tr>';
      }
      else
      {
        print '<tr><td>No Ghost Trains found</td></tr>';
      }
      print '<tr><td></td></tr>';
      
// Check for illegal characters in the reporting marks, like &

      print '<tr>
               <td>
                 <b>Illegal Characters in Reporting Marks</b> Reporting marks may only consist of alphabetic letters. The & is not allowed.
                 Use the <a class="btn btn-sm btn-outline-danger py-0" href="./fix_reporting_marks.php">Fix Reporting Marks</a> function to remove illegal characters.
               </td>
             </tr>';
      $sql = 'select reporting_marks from cars where locate("&", reporting_marks)';
      $rs = mysqli_query($dbc, $sql);
      if (mysqli_num_rows($rs) > 0)
      {
        print '<tr>
                 <td>
                   <ul>';
        while ($row = mysqli_fetch_array($rs))
        {
          print '<li>' . $row['reporting_marks'] . '</li>';
        }
        print '    </ul>
                 </td>
               </tr>';
      }
      else
      {
          print '<tr><td>No illegal characters found in reporting marks</td></tr>';
      }

    ?>
    </table>
</div>
<?php sts_page_end(); ?>


