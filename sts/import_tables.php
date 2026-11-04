<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Import Data from a File',
    'icon' => 'bi-upload',
    'section' => 'maint',
    'head_extra' => '<script src="/sts/js/import_tables.js?v=1"></script>',
]);
?>
<div class="sts-card">
<div class="alert alert-warning">There is no &quot;Undo&quot; for the Replace function!</div>
    <?php
      // bring in the utility files
      require 'open_db.php';
      
      // set up templates to create temporary tables for the incoming data
//      $col_clause = ["commodities" => "Id int(11), code tinytext, description tinytext, remarks text, primary key (Id)",
      $col_clause = ["commodities" => "Id int(11), code tinytext, description tinytext, remarks text",
                     "car_codes" => "Id int(11), code tinytext, description tinytext, remarks text",
                     "routing" => "Id int(11), station tinytext, station_nbr tinytext, instructions text,
                                   sort_seq int(11), color1 int(11), color2 int(11)",
                     "locations" => "Id int(11), code tinytext, station tinytext, track tinytext, spot tinytext,
                                     rpt_station tinytext, remarks text, color tinytext",
                     "shipments" => "Id int(11), code tinytext, description tinytext, consignment tinytext,
                                     car_code tinytext, loading_location tinytext, unloading_location tinytext, last_ship_date int(11),
                                     min_interval int(11), max_interval int(11), min_amount int(11), max_amount int(11),
                                     special_instructions tinytext, remarks text,
                                     min_load_time int(11), max_load_time int(11), min_unload_time int(11), max_unload_time int(11)",
                     "cars" => "Id int(11), reporting_marks varchar(16), car_code_id tinytext,
                               current_location_id tinytext, position int(11), status varchar(256), handled_by_job_id tinytext,
                               remarks text, load_count int(11), home_location tinytext, RFID_code char(255), block_id int(11),
                               last_spotted int(11)"];

      // if the Import button was clicked, process the uploaded file
      if (isset($_POST['import']) && !isset($col_clause[sts_post('table')]))
      {
        print '<div class="alert alert-danger">Unknown import table. Nothing was imported.</div>';
      }
      elseif (isset($_POST['import']))
      {
        $target_dir = 'uploads/';
        $import_dir = getcwd() . '/uploads/';
        $target_file = $target_dir . basename($_FILES['import_file']['name']);
        $import_file = str_replace('\\','/',($import_dir . basename($_FILES['import_file']['name'])));
        $file_type = pathinfo($target_file,PATHINFO_EXTENSION);

        // check if file already exists
        if (file_exists($target_file))
        {
          // delete the old file
          unlink($target_file);
        }

        // only allow files ending in ".csv"
        if($file_type == 'csv')
        {
          if (move_uploaded_file($_FILES['import_file']['tmp_name'], $target_file))
          {
            print sts_html(basename( $_FILES['import_file']['name'])). ' successfully uploaded.<br /><br />';

            // get a database connection
            $dbc = open_db();

            // check to see if this is an append or a replace operation
            if (sts_post('add_replace') == 'replace')
            {
              // remove the existing car data
              $sql = 'truncate table ' . sts_ident(sts_post('table'));
              if (!mysqli_query($dbc, $sql))
              {
                print 'Truncate error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
              }
              else
              {
                print 'Existing ' . sts_html(sts_post('table')) . ' data removed...<br /><br />';
              }
            }
            
            // create a temporary table to receive the incoming data (try to drop the table first in case it still exists)
            $sql = 'drop table import_table';
            $result = mysqli_query($dbc, $sql);
            
//            $sql = 'create table import_table (' . $col_clause[$_POST['table']] . ', primary key (Id))';
            $sql = 'create table import_table (' . $col_clause[sts_post('table')] . ')';
            if (!mysqli_query($dbc, $sql))
            {
              print 'Create error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
            }
            else
            {
              print 'Temporary table created...<br /><br />';
            }

            // import the file
//            $sql = 'load data infile "' . $import_file . '"
//                    into table import_table
//                    fields terminated by "," optionally enclosed by \'"\'
//                    lines terminated by "\r\n"
//                    ignore 1 lines';
            
//            if (!mysqli_query($dbc, $sql))
//            {
//              print 'Load Data error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
//            }
//            else
//            {
//              print $_POST['table'] . ' data successfully imported<br /><br />';
//            }

            // turn on auto_detect_line_endings if we are running a version prior to 8
            if (version_compare(PHP_VERSION, '8.0.0', '<'))
            {
              ini_set('auto_detect_line_endings', TRUE);
            }
           
            // read the import file and insert each row into the temporary table
            $fh = fopen($import_file, "r") or die('<div class="alert alert-danger">Unable to open import file!</div></div>' . sts_page_end_html());
            
            // discard the first line of the file
            $import_line = fgetcsv($fh);

            while(($import_line = fgetcsv($fh)) !== FALSE)
            {
              // start building the sql insert command
              $sql = 'insert into import_table values (';
              $import_params = array();
              
              // build the rest of the sql insert command
              for ($i=0; $i<count($import_line); $i++)
              {
                // deal with cars that don't have current locations before trying to transfer
                // the data from the import_table to the cars table
                if ((sts_post('table') == 'cars') && (strlen(trim($import_line[3])) == 0))
                {
                  $import_line[3] = '1';
                }
                // append the value to the insert command
                $sql .= '?, ';
                $import_params[] = $import_line[$i];
              }
              // get rid of the final comma
              $sql = rtrim($sql, ', ');
              // append the trailing right paren...
              $sql .= ')';
//print 'Insert Command: ' . $sql . '<br />';

              // insert the record into the temporary table
              if (!sts_q($dbc, $sql, $import_params))
              {
                print 'Insert Error: ' . sts_html(sts_err($dbc)) . ' SQL: ' . $sql . '<br /><br />';
                die;
              }
            }
            fclose($fh);  
//die;
            // based on which file was imported, try to convert alpha codes to the corresponding numeric codes for
            // some of the columns in the locations, shipments, and cars tables
            switch (sts_post('table'))
            {
              case "locations":
                $sql = 'update import_table, routing set import_table.station = routing.id where import_table.station = routing.station';
                if (!mysqli_query($dbc, $sql))
                {
                  print 'Update error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
                }
                else
                {
                  // deal with locations that don't have a station yet
                  $sql = 'update import_table set station = 0 where not (station REGEXP "^[0-9]+$")';
                  $result = mysqli_query($dbc, $sql);
                  print 'Location station codes converted...<br /><br />';
                }
                break;
              case "shipments":
                $sql = 'update import_table, commodities set import_table.consignment = commodities.id where import_table.consignment = commodities.code';
                if (!mysqli_query($dbc, $sql))
                {
                  print 'Update error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
                }
                else
                {
                  // deal with shipments that don't have a commodity yet
                  $sql = 'update import_table set consignment = 0 where not (consignment REGEXP "^[0-9]+$")';
                  $result = mysqli_query($dbc, $sql);
                  print 'Shipment consignment codes converted...<br /><br />';
                }
                $sql = 'update import_table, car_codes set import_table.car_code = car_codes.id where import_table.car_code = car_codes.code';
                if (!mysqli_query($dbc, $sql))
                {
                  print 'Update error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
                }
                else
                {
                  // deal with shipments that don't have a car code yet
                  $sql = 'update import_table set car_code = 0 where not (car_code REGEXP "^[0-9]+$")';
                  $result = mysqli_query($dbc, $sql);
                  print 'Shipment car codes converted...<br /><br />';
                }
                $sql = 'update import_table, locations set import_table.loading_location = locations.id where import_table.loading_location = locations.code';
                if (!mysqli_query($dbc, $sql))
                {
                  print 'Update error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
                }
                else
                {
                  // deal with shipments that don't have a loading location yet
                  $sql = 'update import_table set loading_location = 0 where not (loading_location REGEXP "^[0-9]+$")';
                  $result = mysqli_query($dbc, $sql);
                  print 'Shipment loading location codes converted...<br /><br />';
                }
                $sql = 'update import_table, locations set import_table.unloading_location = locations.id where import_table.unloading_location = locations.code';
                if (!mysqli_query($dbc, $sql))
                {
                  print 'Update error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
                }
                else
                {
                  // deal with shipments that don't have an unloading location yet
                  $sql = 'update import_table set unloading_location = 0 where not (unloading_location REGEXP "^[0-9]+$")';
                  $result = mysqli_query($dbc, $sql);
                  print 'Shipment unloading location codes converted...<br /><br />';
                }
                break;
              case "cars":
                $sql = 'update import_table, car_codes set import_table.car_code_id = car_codes.id where import_table.car_code_id = car_codes.code';
                if (!mysqli_query($dbc, $sql))
                {
                  print 'Update error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
                }
                else
                {
                  // deal with cars that don't have a car code yet
                  $sql = 'update import_table set car_code_id = 0 where not (car_code_id REGEXP "^[0-9]+$")';
                  $result = mysqli_query($dbc, $sql);
                  print 'Cars car codes converted...<br /><br />';
                }
                $sql = 'update import_table, locations set import_table.current_location_id = locations.id where import_table.current_location_id = locations.code';
                if (!mysqli_query($dbc, $sql))
                {
                  print 'Update error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
                }
                else
                {
                  print 'Cars current location codes converted...<br /><br />';
                }
                $sql = 'update import_table, locations set import_table.home_location = locations.id where import_table.home_location = locations.code';
                if (!mysqli_query($dbc, $sql))
                {
                  print 'Update error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
                }
                else
                {
                  // deal with cars that don't have a home location yet
                  $sql = 'update import_table set home_location = 0 where not (home_location REGEXP "^[0-9]+$")';
                  $result = mysqli_query($dbc, $sql);
                  print 'Cars home location codes converted...<br /><br />';
                }
                break;
            }

            // set the Id column of the import table to zero so it triggers an autoincrement in the destination table
            $sql = 'update import_table set id = 0';
            if (!mysqli_query($dbc, $sql))
            {
              print 'Update import_table error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
            }
            else
            {
              print 'Temporary table keys updated...<br /><br />';
            }
//die;            
            // copy the rows in the import table into the appropriate table
            $sql = 'insert into ' . sts_ident(sts_post('table')) .  ' select * from import_table';
            if (!mysqli_query($dbc, $sql))
            {
              print 'Insert into Select from error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
            }
            else
            {
              print 'Data transferred from temporary table to destination...<br /><br />';
            }
            // after transferring the imported data, drop the import_table
            $sql = 'drop table import_table';
            if (!mysqli_query($dbc, $sql))
            {
              print 'Drop table error: ' . sts_html(mysqli_error($dbc)) . ' SQL: ' . $sql . '<br /><br />';
            }
            else
            {
              print 'Temporary table dropped...<br /><br />';
            }
            
          }
          else
          {
            print "Error uploading file, please try again";
          }
        }
        else
        {
          print "Incorrect file type. Only .csv file are allowed. Please try again.";
        }
      }

      // display a message showing the acceptable file type and max file size
      if (ini_get('upload_max_filesize') < ini_get('post_max_size'))
      {
        $max_file_size = ini_get('upload_max_filesize');
      }
      else
      {
        $max_file_size = ini_get('post_max_size');
      }
      
      // display some instructions for the user
      print 'Select the file to be imported, type of import (add or replace), the name<br />
             of the  file to upload, and then click the <b>IMPORT</b> button<br /><br />';
      
      print 'Your server restricts upload files to a maximum size of ' . $max_file_size . ' bytes.<br /><br />';
    ?>

    <form action="import_tables.php" method="post" enctype="multipart/form-data">
      <table class="table table-bordered w-auto">
        <tr>
          <th>Table</th>
          <th>Add or Replace</th>
          <th>File to be imported</th>
        </tr>
        <tr>
          <td>
            <input type="radio" name="table" value="cars" required>Cars<br /><br />
            <input type="radio" name="table" value="shipments" required>Shipments<br /><br />
            <input type="radio" name="table" value="locations" required>Locations<br /><br />
            <input type="radio" name="table" value="routing" required>Stations<br /><br />
            <input type="radio" name="table" value="car_codes" required>Car Codes<br /><br />
            <input type="radio" name="table" value="commodities" required>Commodities
          </td>
          <td style="valign: top";>
            <input type="radio" name="add_replace" value="add" checked> Add to Existing Data<br /><br />
            <input type="radio" name="add_replace" value="replace"> Replace all Existing Data
          </td>
          <td style="valign: top";>
            <input type="file" class="form-control" name="import_file" id="import_file" accept=".csv"><br /><br />
            <input type="submit" class="btn btn-danger" value="IMPORT" name="import" data-sts-click="importConfirm">
          </td>
        </tr>
      </table>
    </form>
</div>
<?php sts_page_end(); ?>
