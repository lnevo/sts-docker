<?php
require_once 'page_chrome.php';
sts_page_start(['title' => 'Import Rolling Stock Image', 'icon' => 'bi-image', 'section' => 'data', 'back_href' => '',
  'head_extra' => '<script>window.onunload = refreshParent; function refreshParent() { window.opener.location.reload(); }</script>']);
?>
<div class="sts-card">
    <?php
      // check to see if the upload button was clicked
      if (isset($_POST['upload_btn']))
      {
        // check to see if this is image 1 or image 2
        if ($_POST['image'] == 1)
        {
          $obj_file_name = $_POST['obj_name'];
        }
        else
        {
          $obj_file_name = $_POST['obj_name'] . "b";
        }
        
        // set up the upload parameters
        $target_dir = 'ImageStore/DB_Images/RollingStock/';
        $import_dir = getcwd() . '/temp/';
        $target_file = $target_dir . $obj_file_name . '.jpg';
        $import_file = str_replace('\\','/',($import_dir . basename($_FILES['import_file']['name'])));
        $file_type = strtolower(pathinfo($target_file,PATHINFO_EXTENSION));
//print 'target_dir = [' . $target_dir . ']<br />import_dir = [' . $import_dir . ']<br />target_file = [' . $target_file . ']<br />import_file = [' . $import_file . ']<br /><br \>';
        // check if file already exists
        if (file_exists($target_file))
        {
          // delete the old file
          unlink($target_file);
        }

        // only allow files ending in ".jpg"
        if(($file_type == 'jpg') || ($file_type == 'jpeg'))
        {
          if (move_uploaded_file($_FILES['import_file']['tmp_name'], $target_file))
          {
            print 'Image file for ' . $_POST['obj_id'] . ' successfully uploaded.<br /><br />';
          }
          else
          {
            print 'Error uploading file. Please contact the development team.';
          }
        }
        else
        {
          print $import_file . ' is not the correct type. It must be a jpg file.';
        }
//        print '<br />Click on the CLOSE button to return to the Edit Car screen. <input type="button" value="CLOSE" onclick="window.close();">';
        print '<br />Close this window to return to the Edit Car Screen';
      }
      else
      {
        // this must be the first time in, so get the name of the file to be uploaded and display an UPLOAD button
        print '<form action="import_img.php" method="post" enctype="multipart/form-data">';

        // display a message showing the acceptable file type and max file size
        if (ini_get('upload_max_filesize') < ini_get('post_max_size'))
        {
          $max_file_size = ini_get('upload_max_filesize');
        }
        else
        {
          $max_file_size = ini_get('post_max_size');
        }
        print '<h3>Upload a Rollingstock Image</h3>
               <p>Select graphic image to upload and click the UPLOAD button.</p>
               The file must be a valid image file and have a file extension of .jpg<br /><br />
               Your server restricts upload files to a maximum size of ' . $max_file_size . ' bytes.<br /><br />';
               
        print '<input type="file" name="import_file" id="import_file" accept=".jpg" class="form-control form-control-sm d-inline-block w-auto">&nbsp;
               <input type="submit" name="upload_btn" id="upload_btn" value="UPLOAD" class="btn btn-primary btn-sm">&nbsp;';
//               <input type="submit" name="close_win" id="close_win" value="CANCEL" onclick="window.close();">';
        print '<br /><br />Close this window to return to the Edit Car Screen';
               
        // pass the incoming car index, car reporting marks, and image number through to this form when it's called a second time
        print '<input type="hidden" id="obj_id" name="obj_id" value="' . $_GET['obj_id'] . '">
               <input type="hidden" id="obj_name" name="obj_name" value="' . $_GET['obj_name'] . '">
               <input type="hidden" id="image" name="image" value="' . $_GET['image'] . '">';
        print '</form>';
      }
    ?>
</div>
<?php sts_page_end(); ?>
