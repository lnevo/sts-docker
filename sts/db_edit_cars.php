<?php
  // edit_cars.php

  // edits the selected row in the car table if the Update button was clicked
  // it replaces only those items where a new value was submitted

//print 'CWD: ' . getcwd() . '<br /><br />';
  // generate a QR code for this car
  require '../phpqrcode/qrlib.php';
  // file names are built only from a conservative character set (no path separators)
  $obj_file = preg_replace('/[^A-Za-z0-9_.\-]/', '', sts_get('obj_id'));
  QRcode::png(sts_get('obj_id'), './ImageStore/DB_Images/qrcodes/' . $obj_file . '.png', 'M', 1.5, 1);

  // pull in the bar code library
  require '../php-barcode/php-barcode.php';

  // set the parameters for the bar code
  $image_height = 30;
  $image_width = 300;
  $x = $image_width/2;  // barcode center
  $y = $image_height/2; // barcode center
  $bar_width = 2;       // barcode height in 1D ; not use in 2D
  $bar_height = 20;     // barcode height in 1D ; module size in 2D
  $angle = 0;           // rotation in degrees
  $type = 'code39';     // type of bar code

  // create the basic image and fill it with white
  $im = imagecreatetruecolor($image_width, $image_height);
  $black = ImageColorAllocate($im,0x00,0x00,0x00);
  $white = ImageColorAllocate($im,0xff,0xff,0xff);
  imagefilledrectangle($im, 0, 0, $image_width, $image_height, $white);

  // get a database connection
  $dbc = open_db();

  // get the internal ID for this car
  $rs = sts_q($dbc, 'select id from cars where reporting_marks = ?', array(sts_get('obj_id')));
  $row = mysqli_fetch_array($rs);
  
  // add the bar code to the basic image
  $bar_code = '-' . sts_html($row['id']) . '-';
  $data = Barcode::gd($im, $black, $x, $y, $angle, $type, array("code"=>$bar_code), $bar_width, $bar_height);
  $bar_file_name = './ImageStore/DB_Images/barcodes/' . $obj_file . '.png';
  imagepng($im, $bar_file_name);
  imagedestroy($im);

  // initiate a database response message
  $sql_msg = '<br />Transaction completed';

  // has the submit button been clicked?
  if (isset($_GET['update_btn']))
  {
    // is this a remove operation?
    if (sts_get('update_remove_btn') == 'remove')
    {
      // build a query to remove the selected car
      $sql = 'delete from cars where id = ' . sts_id(sts_get('obj_name'));
      if (!mysqli_query($dbc, $sql))
      {
        $sql_msg = '<br />Delete Error: ' . sts_html(mysqli_error($dbc));
      }
      else
      {
        // if the delete was successful, return to the list_cars page
        header('Location: db_list.php?tbl_name=cars');
        exit();
      }
    }
    else
    {
      // this must be an update operation
      // initialize the unavailable car and the first field flags
      $unavailable_car = false;
      $first_field = true;

      // build the update query based on the contents of the input text boxes
      $sql = 'update cars set ';
      $params = array();

      // check each incoming input text box and add it to the update if there's something there
      if (strlen(sts_get('reporting_marks')) > 0)
      {
        $sql .= 'reporting_marks = ? ';
        $params[] = sts_get('reporting_marks');
        $first_field = false;
      }

      if (strlen(sts_get('car_code')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'car_code_id = ? ';
        $params[] = sts_get('car_code');
        $first_field = false;
      }

      if (strlen(sts_get('current_location')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'current_location_id = ? ';
        $params[] = sts_get('current_location');
        $first_field = false;
      }

      if (strlen(sts_get('position')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'position = ? ';
        $params[] = sts_get('position');
        $first_field = false;
      }

      if (strlen(sts_get('status')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'status = ? ';
        $params[] = sts_get('status');
        $first_field = false;
        
        // check to see if this car is now either empty or unavailable (these are the only two possibilities)
        if ((sts_get('status') == 'Unavailable') || (sts_get('status') == 'Empty'))
        {
          $unavailable_car = true;
        }
        else
        {
          $unavailable_car = false;
        }
      }

      if (strlen(sts_get('handled_by')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'handled_by_job_id = ? ';
        $params[] = sts_get('handled_by');
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

      if (strlen(sts_get('home_location')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'home_location = ? ';
        $params[] = sts_get('home_location');
        $first_field = false;
      }

      if (strlen(sts_get('load_count')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'load_count = ? ';
        $params[] = sts_get('load_count');
        $first_field = false;
      }

      if (strlen(sts_get('RFID_code')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'RFID_code = ? ';
        $params[] = sts_get('RFID_code');
        $first_field = false;
      }

      if (strlen(sts_get('last_spotted')) > 0)
      {
        if (!$first_field)
        {
          $sql .= ', ';
        }
        $sql .= 'last_spotted = ? ';
        $params[] = sts_get('last_spotted');
        $first_field = false;
      }
      // run the update query if at least one field is to be updated
      if (!$first_field)
      {
        $sql .= 'where id = ?';
        $params[] = sts_id(urldecode(sts_get('prev_obj_name')));
        if (sts_q($dbc, $sql, $params))
        {
          $sql_msg =  '<br />Transaction completed<br /><br />';
          
          // if the update was successful and if this car is now unavailable or newly emptied, take it out of the train
          // and delete the car order to which it is connected
          if ($unavailable_car)
          {
            // take the car out of any train that it might have been in
            $sql = 'update cars set handled_by_job_id = 0 where id = ' . sts_id(urldecode(sts_get('prev_obj_name')));
// print 'SQL: ' . $sql . '<br /><br />';
            if (!mysqli_query($dbc, $sql))
            {
              $sql_msg = '<br />Update Error: ' . sts_html(mysqli_error($dbc));
            }
            
            // delete any car order that is linked to this car
            $sql = 'delete from car_orders where car in (select id from cars where id = ' . sts_id(urldecode(sts_get('prev_obj_name'))) . ')';
// print 'SQL: ' . $sql . '<br /><br />';
            if (!mysqli_query($dbc, $sql))
            {
              $sql_msg = '<br />Update Error: ' . sts_html(mysqli_error($dbc));
            }
            
          }
        }
        else
        {
          $sql_msg =  '<br />Update Error: ' . sts_html(sts_err($dbc));
        }
      }
    }
  }

  // generate a hidden field to send this form's table name to itself when it's refreshed
  print '<input id="tbl_name" name="tbl_name" value="cars" type="hidden">';

  // generate a hidden field to send this form's previous object name to itself when it's refreshed
  print '<input id="prev_obj_name" name="prev_obj_name" value="' . sts_html(urlencode(sts_get('obj_name'))) . '" type="hidden">';

  // query the database for the properties of the selected  car code and display them in a table
  $sql = 'select cars.id as car_id,
                 cars.reporting_marks as reporting_marks,
                 cars.car_code_id as car_code_id,
				         cars.current_location_id as current_location_id,
				         cars.position as position,
                 cars.status as status,
				         cars.handled_by_job_id as handled_by_job_id,
				         cars.remarks as remarks,
				         cars.load_count as load_count,
                 cars.RFID_code as RFID_code,
                 cars.last_spotted as last_spotted,
				         car_codes.code as car_code,
				         loc01.code as current_location,
                 loc02.code as home_location,
				         jobs.name as job_name
          from cars
		      left join car_codes on cars.car_code_id = car_codes.Id
			    left join locations loc01 on cars.current_location_id = loc01.Id
          left join locations loc02 on cars.home_location = loc02.id
			    left join jobs on cars.handled_by_job_id = jobs.Id
          where cars.id = ' . sts_id(sts_get('obj_name'));
  $rs = mysqli_query($dbc, $sql);
  $row = mysqli_fetch_array($rs);

  // if this car's current location is 0 (zero) that means it's in the assigned train
  if ($row['current_location_id'] > 0)
  {
    $current_location = $row['current_location'];
  }
  else
  {
    $current_location = "In Train";
  }
  
  // add big table so any graphical image will be on the right side of the page
  print '<div class="d-flex flex-wrap gap-4">
             <div>';
  print
    '<table class="table table-sm table-bordered table-hover sts-table">
      <tr>
        <th>Property</th>
        <th>Current Value</th>
        <th>New Value</th>
      </tr>
      <tr>
        <td>Reporting marks</td>
        <td>' . sts_html($row['reporting_marks']) . '</td>
        <td>
        <input class="form-control form-control-sm" id="reporting_marks" name="reporting_marks" type="text" tabindex="1" autofocus>
          <input id="obj_name" name="obj_name" type="hidden" value="' . sts_html(sts_get('obj_name')) . '">
          <input id="obj_id" name="obj_id" type="hidden" value="' . sts_html(sts_get('obj_id')) . '">
        </td>
      </tr>
      <tr>
        <td>Car Code</td>
        <td>' . sts_html($row['car_code']) . '</td>
        <td>' . drop_down_car_codes('car_code', 2, 'no_wild') . '</td>
      </tr>
      <tr>
        <td>Current Location</td>
        <td>' . $current_location . '</td>
        <td>' . drop_down_locations('current_location', 3, '') . '</td>
      </tr>
      <tr>
        <td>Position</td>
        <td>' . sts_html($row['position']) . '</td>
        <td><input class="form-control form-control-sm" name="position" type="text" tabindex="4"></td>
      </tr>
      <tr>
        <td>Status</td>
        <td>' . sts_html($row['status']) . '</td>
        <td><select class="form-select form-select-sm" id="status" name="status"><option value=""></option><option value="Empty">Empty</option><option value="Unavailable">Unavailable</option></select></td>
      </tr>
      <tr>
        <td>Handled By</td>
        <td>' . sts_html($row['job_name']) . '</td>
        <td>' . drop_down_jobs('handled_by', '6', '') . '</td>
      </tr>
      <tr>
        <td>Remarks</td>
        <td>' . sts_html($row['remarks']) . '</td>
        <td><input class="form-control form-control-sm" name="remarks" type="text" tabindex="7"></td>
      </tr>
      <tr>
        <td>Home Location</td>
        <td>' . sts_html($row['home_location']) . '</td>
        <td>' . drop_down_locations('home_location','8', '') . '</td>
      </tr>
      <tr>
        <td>Load Count</td>
        <td>' . sts_html($row['load_count']) . '</td>
        <td><input class="form-control form-control-sm" name="load_count" type="text" tabindex="9"></td>
      </tr>
      <tr>
        <td>RFID code</td>
        <td>' . sts_html($row['RFID_code']) . '</td>
        <td><input class="form-control form-control-sm" name="RFID_code" type="text" tabindex="10"></td>
      </tr>
      <tr>
        <td>QR Code</td>
        <td style="text-align: center;">
          <img src="./ImageStore/DB_Images/qrcodes/' . sts_html($obj_file) . '.png" style="vertical-align: middle">&nbsp;&nbsp;' . sts_html(sts_get('obj_id')) . '</td>
        <td></td>
      </tr>
      <tr>
        <td>Bar Code</td>
        <td style="text-align: center">
          <img src="' . $bar_file_name . '" style="vertical-align: middle"><br />' . sts_html(sts_get('obj_id')) . '</td>
        <td></td>
      </tr>
      <tr>
        <td>Last Spotted</td>
        <td>' . sts_html($row['last_spotted']) . '</td>
        <td><input class="form-control form-control-sm" name="last_spotted" type="text" tabindex="11"></td>
      </tr>
    </table>';
 
  // close the left hand box and start the right hand box 
  print '</div>
         <div>';

  // image 1
  print '<div id="image1" name="image1">';
  
  // handle image 1 of this car
  $car_key = sts_id(sts_get('obj_name'));
  $image_name1 = './ImageStore/DB_Images/RollingStock/' . $car_key . '.jpg';

  print '<br />Rolling stock image or photo No. 1&nbsp;';
  if (file_exists($image_name1))
  {
    print '<input class="btn btn-secondary btn-sm" type="button" value="IMPORT" disabled>&nbsp;
           <a href="remove_img.php?obj_id=' . urlencode(sts_get('obj_id')) . '&obj_name=' . urlencode(sts_get('obj_name')) . '&image=1" target="_blank"><input class="btn btn-danger btn-sm" type="button" value="REMOVE"></a><br /><br />';

    // display the image
    $filemtime = filemtime($image_name1);
    print '<img src="' . $image_name1 . '?' . $filemtime. '" style="width:640px;"><br /><br />';
  }
  else
  {
    print '<a href="import_img.php?obj_id=' . urlencode(sts_get('obj_id')) . '&obj_name=' . urlencode(sts_get('obj_name')) . '&image=1" target="_blank"><input class="btn btn-secondary btn-sm" type="button" value="IMPORT"></a>&nbsp;
           <input class="btn btn-danger btn-sm" type="button" value="REMOVE" disabled><br /><br />';
           
    print 'No image available';
  }
  // end of top photo
  print '</div>';
  print '<br /><hr />';
  
  // image 2
  print '<div id="image2" name="image2">';
  
  // handle image 2 of this car
  $image_name2 = './ImageStore/DB_Images/RollingStock/' . $car_key . 'b.jpg';

  print '<br />Rolling stock image or photo No. 2&nbsp;';
  if (file_exists($image_name2))
  {
    print '<input class="btn btn-secondary btn-sm" type="button" value="IMPORT" disabled>&nbsp;
           <a href="remove_img.php?obj_id=' . urlencode(sts_get('obj_id')) . '&obj_name=' . urlencode(sts_get('obj_name')) . '&image=2" target="_blank"><input class="btn btn-danger btn-sm" type="button" value="REMOVE"></a><br /><br />';

    // display the image
    $filemtime = filemtime($image_name2);
    print '<img src="' . $image_name2 . '?' . $filemtime. '" style="width:640px;"><br /><br />';
  }
  else
  {
    print '<a href="import_img.php?obj_id=' . urlencode(sts_get('obj_id')) . '&obj_name=' . urlencode(sts_get('obj_name')) . '&image=2" target="_blank"><input class="btn btn-secondary btn-sm" type="button" value="IMPORT"></a>&nbsp;
           <input class="btn btn-danger btn-sm" type="button" value="REMOVE" disabled><br /><br />';
           
    print 'No image available';
  }
  // end of top photo
  print '</div>';
  // close the right hand column
  print '</div>';
  
  // close the big box
  print '</div>';

  // display a status message
  print $sql_msg;

  // add a "None" option to the top of the home location drop down box
  sts_editor_ops(array(array('id' => 'home_location', 'addOption' => array('text' => 'None', 'value' => '0', 'index' => 1))));
?>
