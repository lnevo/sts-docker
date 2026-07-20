<?php
require 'open_db.php';
require 'credentials.php';
require_once 'restore_sql_helpers.php';

function sanitize_uploaded_name($name)
{
  $name = basename($name);
  $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
  if ($name === '' || $name === '.' || $name === '..') {
    $name = 'uploaded_restore.sql';
  }
  return $name;
}

function clear_image_folder($dir)
{
  $files = glob($dir . '/*.*');
  foreach ($files as $file) {
    if (is_file($file)) {
      unlink($file);
    }
  }
}

function restore_sql_file($dbc, $restore_name, $sql_file_path)
{
  list($ok, $msg) = sts_restore_sql_file($dbc, $sql_file_path, $restore_name);
  if (!$ok) {
    return array(false, htmlspecialchars($msg));
  }

  // Remove generated and uploaded image artifacts.
  clear_image_folder('./ImageStore/DB_Images/barcodes');
  clear_image_folder('./ImageStore/DB_Images/qrcodes');
  clear_image_folder('./ImageStore/DB_Images/uploads');
  clear_image_folder('./ImageStore/DB_Images/RollingStock');

  // Restore matching photo backup folder if it exists.
  $restore_dir = './backups/' . $restore_name . '_photos';
  if (file_exists($restore_dir)) {
    $files = glob($restore_dir . '/*.*');
    if (sizeof($files) > 0) {
      $photo_dir = './ImageStore/DB_Images/RollingStock';
      foreach ($files as $file) {
        $file_to_go = str_replace($restore_dir, $photo_dir, $file);
        copy($file, $file_to_go);
      }
    }
  }

  return array(true, htmlspecialchars($restore_name) . ' restored successfully.');
}

$status_msg = '';
$status_color = 'red';
$dbc = open_db();

if (isset($_GET['download'])) {
  $download_name = basename($_GET['download']);
  $download_path = './backups/' . $download_name;

  if (is_file($download_path)) {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Content-Length: ' . filesize($download_path));
    readfile($download_path);
    exit;
  }

  http_response_code(404);
  echo 'Backup file not found.';
  exit;
}

if (isset($_POST['restore_btn'])) {
  if (!isset($_POST['restore_name']) || $_POST['restore_name'] === '') {
    $status_msg = 'Select a backup file before clicking RESTORE.';
  } else {
    $restore_name = basename($_POST['restore_name']);
    $restore_path = './backups/' . $restore_name;
    list($ok, $msg) = restore_sql_file($dbc, $restore_name, $restore_path);
    $status_msg = $msg;
    if ($ok) {
      $status_color = 'green';
    }
  }
}

if (isset($_POST['upload_restore_btn'])) {
  if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
    $status_msg = 'Choose a local SQL file and try again.';
  } else {
    $orig_name = sanitize_uploaded_name($_FILES['sql_file']['name']);
    $extension = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

    if ($extension !== 'sql') {
      $status_msg = 'Only .sql files can be restored.';
    } else {
      $stored_name = 'uploaded_' . date('Ymd_His') . '_' . $orig_name;
      $stored_path = './backups/' . $stored_name;

      if (!move_uploaded_file($_FILES['sql_file']['tmp_name'], $stored_path)) {
        $status_msg = 'Could not upload the SQL file.';
      } else {
        list($ok, $msg) = restore_sql_file($dbc, $stored_name, $stored_path);
        if ($ok) {
          $status_color = 'green';
          $status_msg = 'Uploaded file restored successfully: ' . htmlspecialchars($orig_name);
        } else {
          $status_msg = $msg;
        }
      }
    }
  }
}

$backup_files = array_slice(scandir('./backups'), 2);
sort($backup_files);
?>
<html>
  <head>
    <title>STS - Restore DB</title>
    <style>
      body {font: normal 20px Verdana, Arial, sans-serif;}
      table {border-collapse: collapse;}
      tr {vertical-align: top}
      th {border: 1px solid black; padding: 10px}
      td {border: 1px solid black; padding: 10px}
      .section-card {margin-bottom: 24px;}
      .download-link {font-size: 16px;}
      .status-box {font-size: 18px; margin: 12px 0;}
    </style>
    <script>
      function enable_restore_btn()
      {
        document.getElementById("restore_btn").disabled = false;
      }
    </script>
  </head>
  <body>
  <p><img src="ImageStore/GUI/Menu/manage.jpg" width="718" height="146" border="0" usemap="#Map5">
    <map name="Map5">
      <area shape="rect" coords="569,4,708,48" href="index.html">
      <area shape="rect" coords="569,97,711,140" href="index-t.html">
      <area shape="rect" coords="569,52,709,91" href="database.html">
    </map>
  </p>
  <h2>Database Management</h2>
  <h3>Restore Database</h3>

  <?php if ($status_msg !== '') { ?>
    <div class="status-box" style="color: <?php echo $status_color; ?>;"><?php echo $status_msg; ?></div>
  <?php } ?>

  <div class="section-card">
    <form action="restore_db.php" method="post">
      Click on the radio button for the backup copy that you want to restore and then click the <b>RESTORE</b> button.<br /><br />
      The current contents of the database for this railroad will be erased and replaced with the data stored in the backup copy.<br /><br />
      If you are restoring a backup file for a different railroad, run the <b>WIPE</b> function first.<br /><br />

      <?php
      if (count($backup_files) > 0) {
        print '<input id="restore_btn" name="restore_btn" value="RESTORE" type="submit" disabled><br /><br />';

        print '<table>';
        print '<tr><th>Select</th><th>Backup File</th><th>Download</th></tr>';
        foreach ($backup_files as $file_name) {
          if (is_file('./backups/' . $file_name)) {
            print '<tr>
                     <td><input name="restore_name" value="' . htmlspecialchars($file_name) . '" type="radio" onclick="enable_restore_btn();"></td>
                     <td>' . htmlspecialchars($file_name) . '</td>
                     <td><a class="download-link" href="restore_db.php?download=' . urlencode($file_name) . '">Download</a></td>
                   </tr>';
          }
        }
        print '</table>';
      } else {
        print 'No backup copies available.';
      }
      ?>
    </form>
  </div>

  <div class="section-card">
    <h3>Restore From Your Device</h3>
    <form action="restore_db.php" method="post" enctype="multipart/form-data">
      Select a local SQL file and click <b>UPLOAD &amp; RESTORE</b>.<br /><br />
      <input type="file" name="sql_file" accept=".sql" required>
      <input type="submit" name="upload_restore_btn" value="UPLOAD &amp; RESTORE">
    </form>
  </div>
  </body>
</html>
