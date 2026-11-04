<?php
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Wipe Database',
    'icon' => 'bi-eraser',
    'section' => 'maint',
    'head_extra' => <<<'HTML'
    <script src="/sts/js/maint.js?v=1"></script>
HTML,
]);
?>
<div class="sts-card">
<div class="alert alert-danger">
    Click the check boxes to activate the <b>WIPE</b> button<br />
Click the <b>WIPE</b> button to erase the contents of the database and start over.<br />
There is no &quot;Undo&quot; feature.
</div>
    <form method="get" action="wipe.php">
      <input id="wipe_check_box1" value="reset" type="checkbox" class="form-check-input" data-sts-click="maintGate" data-target="wipe_check_box2">
      Yes, I'm certain I want to wipe the entire database!<br /><br />
      <input id="wipe_check_box2" value="wipe2" type="checkbox" class="form-check-input" data-sts-click="maintGate" data-target="wipe_btn" disabled>
      Yes, I'm REALLY REALLY certain that I want to wipe the entire database! (No going back!)<br /><br />
      <input id="wipe_btn" name="wipe_btn" value="WIPE" type="submit" class="btn btn-danger" data-sts-confirm="Wipe the entire database now? There is no undo." disabled><br /><br />
    </form>

    <?php
      // this program deletes all data from all tables except settings, which it updates to default values

      // bring in the function files
      require 'open_db.php';

      // get a database connection
      $dbc = open_db();

      // was the "Wipe" button clicked?
      if (isset($_GET['wipe_btn']))
      {
        // drop the individual job tables
        $rs = mysqli_query($dbc, 'select name from jobs');
        while ($row = mysqli_fetch_array($rs))
        {
          $sql = 'drop table `' . $row['name'] . '`';
          if (!mysqli_query($dbc, $sql))
          {
            print 'Drop Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql;
          }
          else
          {
            print 'Deleting Job ' . $row[0] . '...<br />';
          }
        }

        // delete records from the job table
        if(!mysqli_query($dbc, 'truncate table jobs'))
        {
          print 'Truncate Table Error: ' . mysqli_error($dbc) . ' SQL: truncate table jobs';
        }
        else
        {
          print 'All jobs removed...<br />';
        }

        // delete all records from the car orders table
        if(!mysqli_query($dbc, 'truncate table car_orders'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table car_orders';
        }
        else
        {
          print 'All car orders removed...<br />';
        }

        // delete all records from the car table
        if(!mysqli_query($dbc, 'truncate table cars'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table cars';
        }
        else
        {
          print 'All cars removed...<br />';
        }
        
        // delete all records from the shipment table
        if(!mysqli_query($dbc, 'truncate table shipments'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table shipments';
        }
        else
        {
          print 'All shipments removed...<br />';
        }
        
        // delete all records from the location table
        if(!mysqli_query($dbc, 'truncate table locations'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table locations';
        }
        else
        {
          print 'All locations removed...<br />';
        }
        
        // delete all records from the empty_location table
        if(!mysqli_query($dbc, 'truncate table empty_locations'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table empty_locations';
        }
        else
        {
          print 'All empty locations removed...<br />';
        }
        
        // delete all records from the car code table
        if(!mysqli_query($dbc, 'truncate table car_codes'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table car_codes';
        }
        else
        {
          print 'All car codes removed...<br />';
        }
        
        // delete all records from the routing table
        if(!mysqli_query($dbc, 'truncate table routing'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table routing';
        }
        else
        {
          print 'All routing instructions removed...<br />';
        }
        
        // delete all records from the commodities table
        if(!mysqli_query($dbc, 'truncate table commodities'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table commodities';
        }
        else
        {
          print 'All commodities removed...<br />';
        }
        
        // delete all records from the blocks table
        if(!mysqli_query($dbc, 'truncate table blocks'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table blocks';
        }
        else
        {
          print 'All blocking of cars removed...<br />';
        }
        
        // delete all records in the owners table
        
        if(!mysqli_query($dbc, 'truncate table owners'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table owners';
        }
        else
        {
          print 'All car owners removed...<br />';
        }
        
        // delete all records in the ownership table
        
        if(!mysqli_query($dbc, 'truncate table ownership'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table ownership';
        }
        else
        {
          print 'All car ownership links removed...<br />';
        }
        
        // delete all records in the pool table
        
        if(!mysqli_query($dbc, 'truncate table pool'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table pool';
        }
        else
        {
          print 'All car pools removed...<br />';
        }
        
        // delete all the records in the pu_criteria table
        
        if(!mysqli_query($dbc, 'truncate table pu_criteria'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table pu_criteria';
        }
        else
        {
          print 'All pickup criteria removed...<br />';
        }

        // delete all car history
        
        if(!mysqli_query($dbc, 'truncate table history'))
        {
          print 'Truncate Error: ' . mysqli_error($dbc) . ' SQL: truncate table history';
        }
        else
        {
          print 'All car history removed...<br />';
        }


        // delete any other tables that are not part of the STS system, such as leftover job tables
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
          print 'Deleting Zombie Tables...</br >';
          while ($row = mysqli_fetch_array($rs))
          {
            $table_name = '`' . $row[0] . '`';
            $sql = 'drop table ' . $table_name;
            if (!mysqli_query($dbc, $sql))
            {
              print 'Drop Error:: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br />';
            }
            else
            {
              print $table_name . ' dropped<br />';
            }
          }
        }
                
        // set the operating session to 0
        $sql = 'update settings set setting_value = "0" where setting_name = "session_nbr"';
        if (!mysqli_query($dbc, $sql))
        {
          print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br />';
        }

        // set the print width to 7.5 inches
        $sql = 'update settings set setting_value = "7.5in" where setting_name = "print_width"';
        if (!mysqli_query($dbc, $sql))
        {
          print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br />';
        }

        // set the railroad name to blanks
        $sql = 'update settings set setting_value = "" where setting_name = "railroad_name"';
        if (!mysqli_query($dbc, $sql))
        {
          print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br />';
        }

        // set the railroad initials to blanks
        $sql = 'update settings set setting_value = "" where setting_name = "railroad_initials"';
        if (!mysqli_query($dbc, $sql))
        {
          print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br />';
        }

        // delete all files in the qrcode, barcode, uploads, and rollingstock photo directories
        // get rid of any existing QR, bar code, upload and rollingstock photo files.
        // the QR and barcode files will be replaced when the reports are run
        
        //first, get a list of all of the file names in the barcodes folder.
        $files = glob('./ImageStore/DB_Images/barcodes' . '/*.*');
         
        //Loop through the file list.
        foreach($files as $file)
        {
          //Make sure that this is a file and not a directory.
          if(is_file($file)){
              //Use the unlink function to delete the file.
                unlink($file);
//print 'Deleting barcode file ' . $file . '<br />';
          }
        }        
        print 'Barcodes deleted...<br />';
        
        //next, get a list of all of the file names in the qrcodes folder.
        $files = glob('./ImageStore/DB_Images/qrcodes' . '/*.*');
         
        //Loop through the file list.
        foreach($files as $file)
        {
          //Make sure that this is a file and not a directory.
          if(is_file($file)){
              //Use the unlink function to delete the file.
                unlink($file);
//print 'Deleting QR code file ' . $file . '<br />';
          }
        }        
        print 'QR codes deleted...<br />';

        //get a list of all of the file names in the uploads folder.
        $files = glob('./ImageStore/DB_Images/uploads' . '/*.*');
         
        //Loop through the file list.
        foreach($files as $file)
        {
          //Make sure that this is a file and not a directory.
          if(is_file($file)){
              //Use the unlink function to delete the file.
                unlink($file);
//print 'Deleting uploaded file ' . $file . '<br />';
          }
        }
        print 'Uploaded files deleted...<br />';
        
        // get a list of all the file names in the rollingstock photo directory
        $files = glob('./ImageStore/DB_Images/RollingStock' . '/*.*');
         
        //Loop through the file list.
        foreach($files as $file)
        {
          //Make sure that this is a file and not a directory.
          if(is_file($file)){
              //Use the unlink function to delete the file.
                unlink($file);
//print 'Deleting rollingstock photo ' . $file . '<br />';
          }
        }
        print 'Rollingstock photos deleted...<br />';
        
        // tell the user what happened
        print '<br />Database wiped, default settings restored...';
      }
    ?>

</div>
<?php sts_page_end(); ?>
