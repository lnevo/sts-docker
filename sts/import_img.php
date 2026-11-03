<?php
require_once 'page_chrome.php';
require_once 'sql_safe.php';
sts_page_start(['title' => 'Import Rolling Stock Image', 'icon' => 'bi-image', 'section' => 'data', 'back_href' => '', 'nav' => false,
  'head_extra' => '<script>window.onunload = refreshParent; function refreshParent() { window.opener.location.reload(); }</script>']);
?>
<div class="sts-card">
    <?php
      // check to see if the upload button was clicked
      if (isset($_POST['upload_btn']))
      {
        // the image file name is built from the car id: accept a conservative character set only (no path parts)
        $obj_name_in = basename(sts_post('obj_name'));
        $name_ok = (preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $obj_name_in) === 1) && ($obj_name_in === sts_post('obj_name'));

        // check to see if this is image 1 or image 2
        if (sts_post('image') == 1)
        {
          $obj_file_name = $obj_name_in;
        }
        else
        {
          $obj_file_name = $obj_name_in . "b";
        }
        
        // set up the upload parameters
        $target_dir = 'ImageStore/DB_Images/RollingStock/';
        $import_dir = getcwd() . '/temp/';
        $target_file = $target_dir . $obj_file_name . '.jpg';
        $import_file = str_replace('\\','/',($import_dir . basename($_FILES['import_file']['name'])));
        $file_type = strtolower(pathinfo($target_file,PATHINFO_EXTENSION));
//print 'target_dir = [' . $target_dir . ']<br />import_dir = [' . $import_dir . ']<br />target_file = [' . $target_file . ']<br />import_file = [' . $import_file . ']<br /><br \>';
        // only allow files ending in ".jpg" that really are JPEG images, and only for a valid image name
        $upload_name = isset($_FILES['import_file']['name']) ? (string) $_FILES['import_file']['name'] : '';
        $upload_ext = strtolower(pathinfo($upload_name, PATHINFO_EXTENSION));
        $img_info = (isset($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) ? @getimagesize($_FILES['import_file']['tmp_name']) : false;
        if (!$name_ok)
        {
          print 'Invalid image name.';
        }
        else if((($upload_ext == 'jpg') || ($upload_ext == 'jpeg')) && $img_info && $img_info['mime'] == 'image/jpeg')
        {
          // check if file already exists
          if (file_exists($target_file))
          {
            // delete the old file
            unlink($target_file);
          }
          if (move_uploaded_file($_FILES['import_file']['tmp_name'], $target_file))
          {
            print 'Image file for ' . sts_h(sts_post('obj_id')) . ' successfully uploaded.<br /><br />';
          }
          else
          {
            print 'Error uploading file. Please contact the development team.';
          }
        }
        else
        {
          print sts_h(basename($upload_name)) . ' is not the correct type. It must be a jpg file.';
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
        print '<input type="hidden" id="obj_id" name="obj_id" value="' . sts_h($_GET['obj_id'] ?? '') . '">
               <input type="hidden" id="obj_name" name="obj_name" value="' . sts_h($_GET['obj_name'] ?? '') . '">
               <input type="hidden" id="image" name="image" value="' . sts_h($_GET['image'] ?? '') . '">';
        print '</form>';
      }
    ?>
</div>
<?php sts_page_end(); ?>
