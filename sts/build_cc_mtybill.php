<?php
  // bring in the utility files
  require "open_db.php";

  // get a database connection
  $dbc = open_db();

  // get the railroad initials from the settings table
  $sql = 'select setting_value from settings where setting_name = "railroad_initials"';
  $rs = mysqli_query($dbc, $sql);
  $row = mysqli_fetch_row($rs);
  $rr_initials = $row[0];

  // get the desired shipment ID
  $shipment_id = sts_id(sts_get('shipment_id'));
  
  // get the waybill number
  $waybill_number = sts_get('waybill_number');

  // check to see if this is a waybill for the empty part of a revenue move_uploaded_file
  if (!strpos($waybill_number, "E"))
  {
    // get the shipment info
    $sql = 'select shipments.code as shipment,
                   shipments.description as description,
                   commodities.description as consignment,
                   car_codes.code as car_code,
                   loc01.code as loading_location,
                   shipments.remarks as remarks,
                   rout01.station as station,
                   loc01.track as track,
                   loc01.spot as spot,
                   loc01.rpt_station as rpt_station,
                   loc01.remarks as location_remarks,
                   loc01.color as color
            from shipments
            left join locations loc01 on loc01.id = shipments.loading_location
            left join commodities on commodities.id = shipments.consignment
            left join car_codes on car_codes.id = shipments.car_code
            left join routing rout01 on rout01.id = loc01.station
            where shipments.id = ?';

//print 'SQL: ' . $sql . '<br /><br />';
//error_log('SQL: ' . $sql);

    // run the query
    $rs = sts_q($dbc, $sql, array($shipment_id));
    $row = ($rs instanceof mysqli_result) ? mysqli_fetch_array($rs) : array();
  
    // pull the database info into local variables so that there's less typing ;-D
    $description = $row['description'];
    $consignment = $row['consignment'];
    $car_code = $row['car_code'];
    $loading_location = $row['loading_location'];
    $shipment_remarks = $row['remarks'];
    $station = $row['station'];
    $track = $row['track'];
    $spot = $row['spot'];
    $rpt_station = $row['rpt_station'];
    $location_remarks = $row['location_remarks'];
    $shipment_code = $row['shipment'];
    $color = $row['color'];
  }
  else
  {
    // get the destination for this empty car repositioning move_uploaded_file
    $sql = 'select routing.station as station,
                   locations.track as track,
                   locations.spot as spot,
                   locations.color as color,
                   locations.rpt_station as rpt_station,
                   car_codes.code as car_code
             from car_orders
             left join cars on cars.id = car_orders.car
             left join locations on locations.id = car_orders.shipment
             left join car_codes on car_codes.id = cars.car_code_id
             left join routing on routing.id = locations.station
             where car_orders.waybill_number = ?';

//print 'SQL: ' . $sql . '<br /><br />';
//error_log('SQL: ' . $sql);
               
    //run the query
    $rs = sts_q($dbc, $sql, array($waybill_number));
    $row = ($rs instanceof mysqli_result) ? mysqli_fetch_array($rs) : array();
    
    //pull the query results into local variables and blank out the unused ones so that's there's less typing
    $description = '';
    $consignment = '';
    $car_code = $row['car_code'];
    $loading_location = '';
    $shipment_remarks = '';
    $station = $row['station'];
    $track = $row['track'];
    $spot = $row['spot'];
    $rpt_station = $row['rpt_station'];
    $location_remarks = '';
    $shipment_code = '';
    $color = $row['color'];
  }
  
  if (strlen($rpt_station) > 0)
  {
    $station = $rpt_station;
  }
// print 'row: '; foreach ($row as $element) print $element . '<br />';
  
  // set up the graphic image
  
  // get the widths of the five system fonts
  $fw1 = imagefontwidth(1);
  $fw2 = imagefontwidth(2);
  $fw3 = imagefontwidth(3);
  $fw4 = imagefontwidth(4);
  $fw5 = imagefontwidth(5);

  // get the width of the base image
  $img_size = getimagesize("./ImageStore/DB_Images/graphics/CCWaybill.png");

  // generate the base png image
  $wb_img = imagecreatefrompng ("./ImageStore/DB_Images/graphics/CCWaybill.png");

  // set up the colors
  $wht = imagecolorallocate($wb_img, 0xFF, 0xFF, 0xFF);
  $pnk = imagecolorallocate($wb_img, 0xFF, 0xC0, 0xCB);
  $red = imagecolorallocate($wb_img, 0xFF, 0x00, 0x00);
  $ong = imagecolorallocate($wb_img, 0xFF, 0xA5, 0x00);
  $ylo = imagecolorallocate($wb_img, 0xFF, 0xFF, 0x00);
  $grn = imagecolorallocate($wb_img, 0x00, 0x80, 0x00);
  $lbl = imagecolorallocate($wb_img, 0xAD, 0xD8, 0xE6);
  $mbl = imagecolorallocate($wb_img, 0x00, 0x00, 0xCD);
  $pur = imagecolorallocate($wb_img, 0x80, 0x00, 0x80);
  $gra = imagecolorallocate($wb_img, 0xD3, 0xD3, 0xD3);
  $blk = imagecolorallocate($wb_img, 0x00, 0x00, 0x00);
  
  $color_palette = array("white" => $wht,
                         "pink" => $pnk,
                         "red" => $red,
                         "orange" => $ong,
                         "yellow" => $ylo,
                         "green" => $grn,
                         "lightblue" => $lbl,
                         "mediumblue" => $mbl,
                         "purple" => $pur,
                         "lightgrey" => $gra,
                         "black" => $blk);

  // put a black outline around the image
  $right = $img_size[0] - 1;
  $bottom = $img_size[1] - 1;
  imageline($wb_img, 0, 0, $right, 0, $blk);
  imageline($wb_img, $right, 0, $right, $bottom, $blk);
  imageline($wb_img, $right, $bottom, 0, $bottom, $blk);
  imageline($wb_img, 0, $bottom, 0, 0, $blk);

  // put a colored triangle in the upper right corner based on the loading location
 if (($color != "None") && (strlen(trim($color)) > 0))
  {
    $coordinates = array(0,0,0,25,25,0);
    imagefilledpolygon($wb_img, $coordinates, 3, $color_palette[$color]);
  }
  
  // set the various section headers
  $str = $rr_initials;
  $left = ($img_size[0] - (strlen($str) * $fw4))/2;
  imagestring($wb_img, 4, $left, 0, $str, $blk);

  $str = "FREIGHT WAYBILL";
  $left = ($img_size[0] - (strlen($str) * $fw5))/2;
  imagestring($wb_img, 5, $left, 15, $str, $blk);

  $str = "TO: " . $loading_location;
  $left = 3;
  imagestring($wb_img, 5, $left, 35, $str, $blk);

  $str = "STATION: " . $station;
  $left = 3;
  imagestring($wb_img, 4, $left, 50, $str, $blk);

  $str = "TRACK: " . $track;
  $left = 3;
  imagestring($wb_img, 4, $left, 65, $str, $blk);

  $str = "SPOT: " . $spot;
  $left = 3;
  imagestring($wb_img, 4, $left, 80, $str, $blk);

  $str = "EMPTY CAR ASSIGNMENT";
  $left = ($img_size[0] - (strlen($str) * $fw5))/2;
  imagestring($wb_img, 5, $left, 100, $str, $blk);

  $str = "CAR CODE: " . $car_code;
  $left = 3;
  imagestring($wb_img, 5, $left, 140, $str, $blk);

  $str = "CONSIGNMENT:";
  $left = 3;
  imagestring($wb_img, 4, $left, 160, $str, $blk);
  
  $str = $consignment;
  $left = 3;
  imagestring($wb_img, 4, $left, 180, $str, $blk);

  $str = "REMARKS:";
  $left = 3;
  imagestring($wb_img, 5, $left, 220, $str, $blk);

  $str = "Shipment: " . $shipment_remarks;
  $left = 3;
  imagestring($wb_img, 1, $left, 240, $str, $blk);

  $str = "Location: " . $location_remarks;
  $left = 3;
  imagestring($wb_img, 1, $left, 250, $str, $blk);

  $str = "SHIPMENT CODE: " . $shipment_code;
  $left = 3;
  imagestring($wb_img, 1, $left, 270, $str, $blk);

  // return the image to the calling program
  header('Content-Type: image/png');
  imagepng($wb_img);
  imagedestroy($wb_img);
?>