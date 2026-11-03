<!-- include the HTML table sort scripts -->
<script src="sorttable.js"></script>

<?php
  // list_locations_codes.php

  // adds a new location to the locations table if the Update button was
  // clicked and if there is a code in the location code text box.

  // generate some javascript to display the table name, identify this table to the form, and set the update button's tab index
  print '<script>
           document.getElementById("table_name").innerHTML = "Locations";
           document.getElementById("tbl_name").value = "locations";
           document.getElementById("update_btn").tabIndex = "8";

           function display_msg()
           {
             document.getElementById("sort_msg").innerHTML = "Sorting...";
           }

           function clear_msg()
           {
             document.getElementById("sort_msg").innerHTML = "&nbsp;";
           }
         </script>';


  // get a database connection
  $dbc = open_db();

  // has the submit button been clicked?
  if (isset($_POST['update_btn']))
  {
    // yes, so check to see if there is anything in the first input text box
    if (strlen(sts_post('code')) > 0)
    {
      // add the new location to the locations table
      sts_q($dbc, 'insert into locations (code, station, track, spot, rpt_station, remarks, color) values (?, ?, ?, ?, ?, ?, ?)',
            array(sts_post('code'), sts_post('station'), sts_post('track'), sts_post('spot'),
                  sts_post('rpt_station'), sts_post('remarks'), sts_post('color')));
    }
  }

  // query the database for all of the locations and display them in a table
  $sql = 'select locations.id as id,
                 locations.code as code,
                 locations.station as loc_station,
                 locations.track as track,
                 locations.spot as spot,
                 locations.rpt_station as rpt_station,
                 locations.remarks as remarks,
                 locations.color as color,
                 routing.station as station
          from locations
          left join routing on locations.station = routing.id
		      order by routing.sort_seq, routing.station, code';
//print $sql;
  $rs = mysqli_query($dbc, $sql);

  print '<table class="sortable" id="loc_tbl">
          <caption style="font: bold 15px Verdana, Arial, sans-serif; text-align:left;">Add New Location</caption>
          <thead>
           <tr>
             <th>Station</th>
             <th>Location Code</th>
             <th>Track</th>
             <th>Spot</th>
             <th>Reporting<br />Station</th>
             <th title="x2010: used as the via/routing flag for empty/repositioning cars">Remarks &#9432;</th>
             <th>Color</th>
           </tr>
           <tr>
             <td>' . drop_down_stations('station', '1', '') . '</td>
             <td><input id="code" name="code" type="text" tabindex="2" required></td>
             <td><input name="track" type="text" tabindex="3"></td>
             <td><input name="spot" type="text" tabindex="4"></td>
             <td style="text-align: center;"><input name="rpt_station" type="text" tabindex="5" size="25"></td>
             <td style="text-align: center;"><input name="remarks" type="text" tabindex="6" size="25"></td>
             <td>' . drop_down_colors('color', '7', '') . '</td>
           </tr>
           <tr>
             <td colspan="7" style="border:0px;">
             </td>
           </tr>
           <tr style="position: sticky; top: 0; background-color: #F5F5F5">
             <th><i>Station</i></th>
             <th><i>Location Code</i></th>
             <th><i>Track</i></th>
             <th><i>Spot</i></th>
             <th><i>Reporting<br />Station</i></th>
             <th title="x2010: used as the via/routing flag for empty/repositioning cars"><i>Remarks &#9432;</i></th>
             <th><i>Color</i></th>
           </tr>
         </thead>';

  if (mysqli_num_rows($rs) > 0)
  {
    while ($row = mysqli_fetch_array($rs))
    {
      print '<tr>';
      print '  <td>' . sts_html($row['station']) . '</td>';
      print '  <td><a href="db_edit.php?tbl_name=locations&obj_id=' . sts_html($row['id']) . '&obj_name=' . urlencode($row['code']) . '">' . sts_html($row['code']) . '</a></td>';
      print '  <td>' . sts_html($row['track']) . '</td>';
      print '  <td>' . sts_html($row['spot']) . '</td>';
      print '  <td>' . sts_html($row['rpt_station']) . '</td>';
      print '  <td>' . sts_html($row['remarks']) . '</td>';
      print '  <td style="background-color: ' . sts_html($row['color']) . '"></td>';
      print '</tr>';
    }
  }
  print "</table>";

  // add some extra lines to the instructions div
  print '<script>
           document.getElementById("instructions").innerHTML = document.getElementById("instructions").innerHTML + "Click on column titles shown in <i>italics</i> to sort the table<br /><br />";
         </script>';

  // generate a javascript line to set focus on the first input text box
  print '<script>document.getElementById("code").focus();</script>';
?>
