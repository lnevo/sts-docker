<?php
require_once 'page_chrome.php';
require_once 'sql_safe.php';
sts_page_start([
    'title' => 'Remove Backup Database',
    'icon' => 'bi-trash',
    'section' => 'maint',
    'head_extra' => <<<'HTML'
    <script>
      function enable_remove_btn()
      {
        document.getElementById("remove_btn").disabled = false;
      }
    </script>
    <script>
      function display_msg1()
      {
        document.getElementById("msg1").innerHTML = "Processing...";
      }
    </script>
HTML,
]);
?>
<div class="sts-card">
  <form action="remove_backup.php" method="get">
    Click on the radio button for the backup copy that you want to remove and then click the <b>REMOVE</b> button.<br /><br />
    <div class="alert alert-danger">
    After you have clicked on the <b>REMOVE</b> button there is no way to recover 
    the removed backup database!
    </div>
    <div id="msg1"></div>
    <?php
      // has the Remove button been clicked?
      $msg2 = "";
      if (isset($_GET['remove_btn']))
      {
        // check to see if the user selected a backup file to be removed
        // the name must be a plain file name that exists in the backups directory
        $remove_name = sts_get('remove_name');
        $remove_ok = ($remove_name !== '') && ($remove_name === basename($remove_name))
                     && (preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.\-]*$/', $remove_name) === 1)
                     && is_file('./backups/' . $remove_name);
        if (isset($_GET['remove_name']) && !$remove_ok)
        {
          print 'Invalid backup name.<br /><br />';
        }
        if (isset($_GET['remove_name']) && $remove_ok)
        {
          // check to see if a subdirectory of photos exists for this backup file
          $remove_dir = './backups/' . $remove_name . '_photos';
          if (file_exists($remove_dir))
          {
            // get a list of file names in the directory to be removed
            $files = glob($remove_dir . '/*.*');
            
            // if there are files in there, delete them
            if (sizeof($files) > 0)
            {
              // remove the rollingstock photos
              print 'Removing rollingstock photos from the backup directory...<br /><br />';
              foreach($files as $file)
              {
                unlink($file);
              }
            }
            // delete the photo backup directory
            rmdir($remove_dir);
            print 'Photo backup directory ' . sts_html($remove_dir) . ' removed...<br /><br />';
          }
          
          // delete the backup file if it exists
          if (file_exists('./backups/' . $remove_name))
          {
            unlink('./backups/' . $remove_name);
            print sts_html($remove_name) . ' backup file removed.<br /><br />';
          }
        }
      }

      // get the list of names from the backup directory
      $backup_names = array_slice(scandir('./backups'), 2);

      if (count($backup_names) > 0)
      {
        // since there are backup copies, we can now display the remove button
        print '<input id="remove_btn" name="remove_btn" value="REMOVE" type="submit" class="btn btn-danger" onclick="if (!confirm(\'Permanently remove the selected backup? This cannot be undone.\')) { return false; } display_msg1();" disabled><br /><br />';

        // build a table of backup names with radio buttons
        print '<table class="table table-sm table-bordered w-auto">';
        foreach($backup_names as $name)
        {
          // if the item in the directory list is a file, add it to the radio button list
          if (is_file('./backups/' . $name))
          {
            print '<tr>
                     <td><input name="remove_name" value="' . sts_html($name) . '" type="radio" onclick="enable_remove_btn();"></td>
                     <td>' . sts_html($name) . '</td>
                   </tr>';
          }
        }
        print '</table>';
      }
      else
      {
        print 'No backup copies available.';
      }
    ?>
    <br />
  </form>
</div>
<?php sts_page_end(); ?>
