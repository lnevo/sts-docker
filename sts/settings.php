<?php
require_once __DIR__ . '/maint_lock.php'; sts_maint_require('admin');
require_once 'page_chrome.php';
sts_page_start(['title' => 'System Settings', 'icon' => 'bi-gear', 'section' => 'maint']);
?>
<div class="sts-card">
<h3>
Current STS server IPv4 address(s):<br />
</h3>
<?php
  // determine what kind of OS is running
  if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
  {
    // find the STS server's ip address if it's running via XAMPP on a Windows PC
    $ipconfig_output = shell_exec("ipconfig");
    $ipconfig_array = explode('<br />', nl2br($ipconfig_output));
    for ($i=0; $i<sizeof($ipconfig_array); $i++)
    {
      if (strpos($ipconfig_array[$i], 'adapter') > 0)
      {
        $adapter_name = $ipconfig_array[$i];
      }
      if (strpos($ipconfig_array[$i], 'IPv4') > 0)
      {
        print $adapter_name . ' ' . $ipconfig_array[$i] . '<br />';
      }
    }
  }
  else
  {
    // try to find the STS server's ip address if it's running plain Apache/PHP on a *nix box
    print $_SERVER['SERVER_ADDR'];
  }
?>

<h3>System Settings</h3>
    <form>
      <div id="instructions">
        After modifying setting values, click the <b>UPDATE</b> button to put the new<br />
        values into effect.<br /><br />
        When entering a new value for the Print Width setting, be certain to follow<br />
        the numerical value with a valid unit of measurement, such as in for inches,<br />
        cm for centimeters, etc... A blank space between the numeric value and the<br />
        unit of measurement will generate an error.<br /><br />
        Examples - Legal: 7.5in &nbsp;&nbsp; Illegal: 7.5 in<br /><br />
        7.5in is the default setting for US letter size paper set to print in portrait<br />
        mode and 190mm is correct for A4.
      </div>
      <br />
      <input name="update_btn" value="UPDATE" type="submit" class="btn btn-primary btn-sm"><br /><br />

      <?php
        // display a list of settings and update the settings values if the user clicks the Update button

        // bring in the utility files
        require "open_db.php";

        // get a database connection
        $dbc = open_db();

        // was the Update button clicked?
        if (isset($_GET["update_btn"]))
        {
          // collect the names of all of the settings
          $row_count = 0;
          $sql = 'select setting_name from settings';
          $rs = mysqli_query($dbc, $sql);
          while ($row = mysqli_fetch_array($rs))
          {
            $settings[$row_count] = $row[0];
            $row_count++;
          }

          // now go through the array of setting names and update any changes
          for ($i=0; $i<$row_count; $i++)
          {
            if (strlen(sts_get($settings[$i])) > 0)
            {
              // only make a change if there's a new value
              $setting_value = sts_get($settings[$i]);
              $sql = 'update settings set setting_value = ? where setting_name = ?';
              if (sts_q($dbc, $sql, array($setting_value, $settings[$i])) === false)
              {
                print "Update Error: " . sts_h(sts_err($dbc));
              }
            }
          }

        }

        // build an sql query to bring in the settings and their values
        $sql = 'select * from settings';
        $rs = mysqli_query($dbc, $sql);

        if (mysqli_num_rows($rs) > 0)
        {
          print '<table>';
          print '<tr><th>Setting</th><th>Current Value</th><th>New Value</th></tr>';

          while ($row = mysqli_fetch_array($rs))
          {
            print '<tr>
                     <td>' . sts_h($row[1]) . '</td>
                     <td>' . sts_h($row[2]) . '</td>
                     <td><input name="' . sts_h($row[0]) . '" type="text" class="form-control form-control-sm"></td>
                   </tr>';
          }

          print '</table>';
        }
        else
        {
          print "No settings found. Curious... There should be some here. :-(";
        }
      ?>
    </form>
</div>
<?php sts_page_end(); ?>
