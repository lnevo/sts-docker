<?php
  // bring in the utility files
  require 'open_db.php';

  // get a database connection
  $dbc = open_db();

  // get the railroad initials from the settings table
  $sql = 'select setting_value from settings where setting_name = "railroad_initials"';
  $rs = mysqli_query($dbc, $sql);
  $row = mysqli_fetch_row($rs);
  $rr_initials = $row[0];

  // get the desired shipment ID
  $shipment_id = sts_id(sts_get('shipment_id'));

  // get the shipment info
  $sql = 'select shipments.code as shipment_code,
                 shipments.description as description,
                 commodities.description as consignment,
                 car_codes.code as car_code,
                 loc01.code as unloading_location,
                 shipments.remarks as remarks,
                 rout01.station as unloading_station,
                 loc01.track as unloading_track,
                 loc01.spot as unloading_spot,
                 loc01.rpt_station as unloading_rpt_station,
                 loc01.remarks as unloading_remarks,
                 loc02.code as loading_location,
                 rout02.station as loading_station,
                 loc02.track as loading_track,
                 loc02.spot as loading_spot,
                 loc02.rpt_station as loading_rpt_station,
                 loc02.remarks as loading_remarks,
                 shipments.special_instructions as special_instructions,
                 loc01.color as color
          from shipments
          left join commodities on commodities.id = shipments.consignment
          left join car_codes on car_codes.id = shipments.car_code
          left join locations loc01 on loc01.id = shipments.unloading_location
          left join routing rout01 on rout01.id = loc01.station
          left join locations loc02 on loc02.id = shipments.loading_location
          left join routing rout02 on rout02.id = loc02.station
          where shipments.id = ?';
  $rs = sts_q($dbc, $sql, array($shipment_id));
  $row = ($rs instanceof mysqli_result) ? mysqli_fetch_array($rs) : array();
  $description = $row['description'];
  $consignment = $row['consignment'];
  $car_code = $row['car_code'];
  $unloading_location = $row['unloading_location'];
  $shipment_remarks = $row['remarks'];
  $unloading_station = $row['unloading_station'];
  $unloading_track = $row['unloading_track'];
  $unloading_spot = $row['unloading_spot'];
  $unloading_rpt_station = $row['unloading_rpt_station'];
  $unloading_remarks = $row['unloading_remarks'];
  $loading_location = $row['loading_location'];
  $loading_station = $row['loading_station'];
  $loading_track = $row['loading_track'];
  $loading_spot = $row['loading_spot'];
  $loading_rpt_station = $row['loading_rpt_station'];
  $loading_remarks = $row['loading_remarks'];
  $shipment_code = $row['shipment_code'];
  $special_instructions = $row['special_instructions'];
  $color = $row['color'];

  if (strlen($unloading_rpt_station) > 0)
  {
    $unloading_station = $unloading_rpt_station;
  }
  
  if (strlen($loading_rpt_station) > 0)
  {
    $loading_station = $loading_rpt_station;
  }
  
  // get the widths of the five system fonts
  $fw1 = imagefontwidth(1);
  $fw2 = imagefontwidth(2);
  $fw3 = imagefontwidth(3);
  $fw4 = imagefontwidth(4);
  $fw5 = imagefontwidth(5);

  // get the width of the base image
  $img_size = getimagesize('./ImageStore/DB_Images/graphics/CCWaybill.png');

  // generate the base png image
  $wb_img = imagecreatefrompng ('./ImageStore/DB_Images/graphics/CCWaybill.png');

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

  $str = "TO: " . $unloading_location;
  $left = 3;
  imagestring($wb_img, 5, $left, 35, $str, $blk);

  $str = "STATION: " . $unloading_station;
  $left = 3;
  imagestring($wb_img, 4, $left, 50, $str, $blk);

  $str = "TRACK: " . $unloading_track;
  $left = 3;
  imagestring($wb_img, 4, $left, 65, $str, $blk);

  $str = "SPOT: " . $unloading_spot;
  $left = 3;
  imagestring($wb_img, 4, $left, 80, $str, $blk);

  $str = "CONTENTS:";
  $left = 3;
  imagestring($wb_img, 5, $left, 100, $str, $blk);
  
  $str = $consignment;
  $left = 3;
  imagestring($wb_img, 4, $left, 120, $str, $blk);

  $str = "CAR CODE: " . $car_code;
  $left = 3;
  imagestring($wb_img, 5, $left, 140, $str, $blk);

  $str = "FROM: " . $loading_location;
  $left = 3;
  imagestring($wb_img, 5, $left, 160, $str, $blk);

  $str = "STATION: " . $loading_station;
  $left = 3;
  imagestring($wb_img, 4, $left, 175, $str, $blk);

  $str = "TRACK: " . $loading_track;
  $left = 3;
  imagestring($wb_img, 4, $left, 190, $str, $blk);

  $str = "SPOT: " . $loading_spot;
  $left = 3;
  imagestring($wb_img, 4, $left, 205, $str, $blk);
  
  $str = "REMARKS: ";
  $left = 3;
  imagestring($wb_img, 5, $left, 220, $str, $blk);

  $str = "Shipment: " . $shipment_remarks;
  $left = 3;
  imagestring($wb_img, 1, $left, 240, $str, $blk);

  $str = "Location: " . $unloading_remarks;
  $left = 3;
  imagestring($wb_img, 1, $left, 250, $str, $blk);
  
  $str = $special_instructions;
  $left = 3;
  imagestring($wb_img, 1, $left, 260, $str, $blk);

  $str = "SHIPMENT CODE: " . $shipment_code;
  $left = 3;
  imagestring($wb_img, 1, $left, 270, $str, $blk);

  // return the image to the calling program
  header('Content-Type: image/png');
  imagepng($wb_img);
  imagedestroy($wb_img);
?>