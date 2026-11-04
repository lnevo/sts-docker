<?php
require_once __DIR__ . '/maint_lock.php'; sts_maint_require('operator');
require_once 'page_chrome.php';
sts_page_start([
    'title' => 'Back Up Database',
    'icon' => 'bi-cloud-upload',
    'section' => 'maint',
    'head_extra' => <<<'HTML'
    <script src="/sts/js/maint.js?v=1"></script>
HTML,
]);
?>
<div class="sts-card">
  <form action="backup_db.php" method="get">
    <div align="left">Enter a name for the backup file and then click the <b>Back 
    Up</b> button. This file can be used to restore the database at a later date.<br />
    <br />
    (Leave the file name box empty and click the Back Up button to refresh the 
    list of existing files.)<br />
    <br />
    <input id="backup_name" name="backup_name" type="text" class="form-control d-inline-block w-auto">
    &nbsp; 
    <input id="backup_btn" name="backup_btn" value="BACK UP" type="submit" class="btn btn-primary" data-sts-click="maintBusy">
    <br />
    <br />
    <?php
      // set up a message area
      print '<div id="msg1"><br /></div><br />';

      // bring in the utility files
      require 'open_db.php';

      // bring in the function that backs up all of the tables into one file
      require 'backup_tables.php';

      // get a database connection
      $dbc = open_db();

      // has the Back Up button been clicked?
      if (isset($_GET['backup_btn']))
      {
        // get the name of the backup from the form
        $backup_name = strtolower($_GET['backup_name']);

        // get a list of files in the backup directory
        $backup_names = array_slice(scandir('./backups'), 2);

        // display a list of existing files in the backup directory
        if (count($backup_names) > 0)
        {
          print 'Existing backup files: <ul><li>' . implode('<li>', $backup_names) . '</ul>';
        }

        // check to see if this name has already been used
        if (in_array($backup_name, $backup_names))
        {
          print 'That name has already been used. Try again.';
        }
        else
        {
          if (strlen($backup_name) > 0)
          {
            // dump the current database to the backups directory and use the desired name for the file
            backup_tables($dbc, $backup_name);
            print 'Database backup file created...<br /><br />';
            
            // get a list of files in the rollingstock photo directory
            $photo_dir = './ImageStore/DB_Images/RollingStock';
            $files = glob($photo_dir . '/*.*');
            
            // if there are files in there, make a backup copy of them
            if (sizeof($files) > 0)
            {
              // create a subdirectory of the backups directory with the same name as the backup sql file
              $backup_dir = './backups/' . $backup_name . '_photos';
              print 'Creating rollingstock photo backup directory ' . $backup_dir . '...<br /><br />';
              mkdir($backup_dir);
              
              // copy the photos from the rollingstock photo directory into the new photo backup directory
              print 'Copying rollingstock photos to backup directory...<br /><br />';
              foreach($files as $file)
              {
                $file_to_go = str_replace($photo_dir,$backup_dir,$file);
                copy($file, $file_to_go);
              }
            }
            print 'Backup complete...';
          }
        }
      }
    ?>
    </div>
  </form>
</div>
<?php sts_page_end(); ?>
