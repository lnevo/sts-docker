<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Reposition Cars</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="operations_ui.css" rel="stylesheet">
    <script src="sorttable.js"></script>
    <style>
      tr {vertical-align: top;}
      th, td { font-size: 0.875rem; padding: 6px 8px; white-space: nowrap; }
      td.checkbox {text-align: center; white-space: normal; }
      @media print { .noprint {display:none;} }
      .status-empty    { display:inline-block; background-color:#ffeaa7; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-loaded   { display:inline-block; background-color:#a8e6cf; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-loading  { display:inline-block; background-color:#74b9ff; color:white;  padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-unloading{ display:inline-block; background-color:#fab1a0; color:white;  padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-ordered  { display:inline-block; background-color:#dfe6e9; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-unavailable{ display:inline-block; background-color:#d63031; color:white; padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      #repo_off_home_btn.active {
        color: #fff;
        background-color: #b45309;
        border-color: #b45309;
      }
    </style>
    <?php
      require 'show_image.php';
    ?>
    <script>
      let repoOffHomeOnly = false;

      function matchesRepoLocationFilter(row, selectedValue, stationKey, locationKey) {
        if (!selectedValue) return true;
        if (selectedValue.indexOf('station::') === 0) {
          return row.dataset[stationKey] === selectedValue.substring(9);
        }
        if (selectedValue.indexOf('location::') === 0) {
          return row.dataset[locationKey] === selectedValue.substring(10);
        }
        return true;
      }

      function populateRepoLocationFilterOptions(selectId, stationKey, locationKey) {
        const select = document.getElementById(selectId);
        const rows = Array.from(document.querySelectorAll('#car_tbl tr.repo-car-row'));
        const stations = new Map();

        rows.forEach(function(row) {
          const station = row.dataset[stationKey];
          const location = row.dataset[locationKey];
          if (!station) return;
          if (!stations.has(station)) {
            stations.set(station, new Set());
          }
          if (location) {
            stations.get(station).add(location);
          }
        });

        const previous = select.value;
        select.innerHTML = '';
        const allOption = document.createElement('option');
        allOption.value = '';
        allOption.textContent = 'All';
        select.appendChild(allOption);

        Array.from(stations.keys()).sort().forEach(function(station) {
          const stationOption = document.createElement('option');
          stationOption.value = 'station::' + station;
          stationOption.textContent = station;
          select.appendChild(stationOption);

          Array.from(stations.get(station)).sort().forEach(function(location) {
            const locationOption = document.createElement('option');
            locationOption.value = 'location::' + location;
            locationOption.textContent = station + ' — ' + location;
            select.appendChild(locationOption);
          });
        });

        if (Array.from(select.options).some(function(option) { return option.value === previous; })) {
          select.value = previous;
        }
      }

      function populateRepoFilterOptions(selectId, datasetKey) {
        const select = document.getElementById(selectId);
        const rows = Array.from(document.querySelectorAll('#car_tbl tr.repo-car-row'));
        const values = Array.from(new Set(rows.map(function(row) { return row.dataset[datasetKey]; }).filter(Boolean))).sort();
        const previous = select.value;

        select.innerHTML = '';
        const allOption = document.createElement('option');
        allOption.value = '';
        allOption.textContent = 'All';
        select.appendChild(allOption);

        values.forEach(function(value) {
          const option = document.createElement('option');
          option.value = value;
          option.textContent = value;
          select.appendChild(option);
        });

        if (values.includes(previous)) {
          select.value = previous;
        }
      }

      function populateRepositionFilters() {
        populateRepoFilterOptions('repo_car_code_filter', 'carCode');
        populateRepoLocationFilterOptions('repo_current_loc_filter', 'currentStation', 'currentLocation');
        populateRepoLocationFilterOptions('repo_home_loc_filter', 'homeStation', 'homeLocation');
        applyRepositionFilters();
      }

      function applyRepositionFilters() {
        const carCode = document.getElementById('repo_car_code_filter').value;
        const currentLoc = document.getElementById('repo_current_loc_filter').value;
        const homeLoc = document.getElementById('repo_home_loc_filter').value;
        const marks = document.getElementById('repo_marks_filter').value.trim().toLowerCase();
        const rows = Array.from(document.querySelectorAll('#car_tbl tr.repo-car-row'));
        let visibleCount = 0;

        rows.forEach(function(row) {
          const matchesCarCode = !carCode || row.dataset.carCode === carCode;
          const matchesCurrent = matchesRepoLocationFilter(row, currentLoc, 'currentStation', 'currentLocation');
          const matchesHome = matchesRepoLocationFilter(row, homeLoc, 'homeStation', 'homeLocation');
          const matchesMarks = !marks || row.dataset.reportingMarks.toLowerCase().includes(marks);
          const matchesOffHome = !repoOffHomeOnly || row.dataset.offHome === '1';
          const isVisible = matchesCarCode && matchesCurrent && matchesHome && matchesMarks && matchesOffHome;

          row.hidden = !isVisible;
          const destination = row.querySelector('select[name^="list"]');
          if (destination) {
            destination.disabled = !isVisible;
          }
          if (isVisible) {
            visibleCount++;
          }
        });

        updateRepoGroupHeaderVisibility();
        updateRepoFilterCount(visibleCount, rows.length);
      }

      function updateRepoGroupHeaderVisibility() {
        const table = document.getElementById('car_tbl');
        if (!table) return;

        let groupHeader = null;
        let groupVisibleRows = 0;

        Array.from(table.rows).forEach(function(row) {
          if (row.classList.contains('home-group-header')) {
            if (groupHeader) {
              groupHeader.hidden = groupVisibleRows === 0;
            }
            groupHeader = row;
            groupVisibleRows = 0;
            row.hidden = false;
          }
          else if (row.classList.contains('repo-car-row') && !row.hidden) {
            groupVisibleRows++;
          }
        });

        if (groupHeader) {
          groupHeader.hidden = groupVisibleRows === 0;
        }
      }

      function updateRepoFilterCount(visibleCount, totalCount) {
        const count = document.getElementById('repo_filter_count');
        if (count) {
          count.textContent = visibleCount + ' of ' + totalCount + ' cars shown';
        }
      }

      function clearRepositionFilters() {
        document.getElementById('repo_car_code_filter').value = '';
        document.getElementById('repo_current_loc_filter').value = '';
        document.getElementById('repo_home_loc_filter').value = '';
        document.getElementById('repo_marks_filter').value = '';
        repoOffHomeOnly = false;
        document.getElementById('repo_off_home_btn').classList.remove('active');
        applyRepositionFilters();
      }

      function toggleOffHomeOnly() {
        repoOffHomeOnly = !repoOffHomeOnly;
        document.getElementById('repo_off_home_btn').classList.toggle('active', repoOffHomeOnly);
        applyRepositionFilters();
      }

      function prepareRepositionSubmit() {
        document.querySelectorAll('#car_tbl tr.repo-car-row[hidden] select[name^="list"]').forEach(function(select) {
          select.disabled = true;
        });
        return true;
      }

      function repo_to_home() {
        const rows = Array.from(document.querySelectorAll('#car_tbl tr.repo-car-row:not([hidden])'))
          .filter(function(row) { return row.dataset.offHome === '1'; });

        if (rows.length === 0) {
          alert('No visible cars off home to reposition.');
          return;
        }

        if (!confirm('Reposition ' + rows.length + ' visible car(s) not at home to their home locations?')) {
          return;
        }

        const formData = new FormData();
        rows.forEach(function(row) {
          formData.append('car_ids[]', row.dataset.carId);
        });

        fetch('repo_to_home.php', {
          method: 'POST',
          body: formData
        })
          .then(function(response) { return response.text(); })
          .then(function(text) {
            alert(text + ' Non-Revenue Car Orders Generated');
            location.reload();
          })
          .catch(function() {
            alert('Could not reposition cars to home.');
          });
      }
    </script>
  </head>
  <body class="bg-light">
    <nav class="navbar navbar-dark noprint mb-3" style="background-color: #2e7d32;">
      <div class="container-fluid">
        <span class="navbar-brand"><i class="bi bi-arrows-move"></i> Reposition Empty Cars</span>
        <div>
          <a href="operations.html" class="btn btn-outline-light btn-sm me-2">
            <i class="bi bi-arrow-left"></i> Operations
          </a>
          <a href="index.html" class="btn btn-outline-light btn-sm me-2">
            <i class="bi bi-house"></i> Home
          </a>
          <a href="index-t.html" class="btn btn-outline-light btn-sm">
            <i class="bi bi-diagram-3"></i> Site Map
          </a>
        </div>
      </div>
    </nav>
    <div class="px-4">
    <h5 class="mb-2">Reposition Empty Cars</h5>
    <p class="text-muted">Select a destination for each empty car that is to be repositioned and then click the UPDATE button.<br />
    Leave the destination blank if the car is to remain at its current location.<br /><br />
    To reposition all cars that are NOT at their home location to their home, click the REPOSITION TO HOME button.<br />
    Filters apply to both Update and Reposition to Home — only visible cars are affected.<br /><br />
    The list of cars can be filtered by car code, current location, home location, or reporting marks.<br />
    To view only those cars not at their home locations, click the <strong>Not at home</strong> button.<br />
    To remove all filters, click the <strong>Clear</strong> button.<br /><br />
    If all empty cars are displayed, those that are not at their home locations are highlighted. These cars<br />
    should be sent to their home locations if they are not needed for revenue moves. They may also be blocking<br />
    an unloading location.</p>
    <form method="post" action="reposition.php" onsubmit="return prepareRepositionSubmit();">
    <?php
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      $dbc = open_db();

      if (isset($_POST['update_btn']))
      {
        $sql = 'select setting_value from settings where setting_name = "session_nbr"';
        $rs = mysqli_query($dbc, $sql);
        if (mysqli_num_rows($rs) > 0)
        {
          $row = mysqli_fetch_row($rs);
          $session_number = $row[0];
        }
        else
        {
          $session_number = 0;
        }

        $sql = 'select waybill_number from car_orders where waybill_number like "' . str_pad($session_number, 3, '0', STR_PAD_LEFT) .  '-E__" order by waybill_number desc limit 1';
        $rs = mysqli_query($dbc, $sql);
        if (mysqli_num_rows($rs) > 0)
        {
          $row = mysqli_fetch_row($rs);
          $waybill_counter = substr($row[0], -2, 2) + 1;
        }
        else
        {
          $waybill_counter = 1;
        }

        for ($i=0; $i<$_POST['row_count']; $i++)
        {
          $list_name = 'list' . $i;
          $car_name = 'car' . $i;

          if (!isset($_POST[$list_name]) || strlen($_POST[$list_name]) === 0)
          {
            continue;
          }

          $wb_nbr = str_pad($session_number, 3, '0', STR_PAD_LEFT) . '-E' . str_pad($waybill_counter, 2, '0', STR_PAD_LEFT);

          $sql = 'insert into car_orders values ("' . $wb_nbr . '", "' . $_POST[$list_name] . '", "' . $_POST[$car_name] . '")';

          if (!mysqli_query($dbc, $sql))
          {
            print 'Insert Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql;
          }

          $sql = 'update cars set status = "Ordered" where id = "' . $_POST[$car_name] . '"';

          if (!mysqli_query($dbc, $sql))
          {
            print 'Update Error: ' . mysqli_error($dbc) . ' SQL: ' . $sql;
          }

          $sql = 'select current_location_id from cars where id = "' . $_POST[$car_name] . '"';
          $rs = mysqli_query($dbc, $sql);
          $row = mysqli_fetch_array($rs);
          $car_id = $row["current_location_id"];

          $sql = 'select code from locations where id = "' . $_POST[$list_name] . '"';
          $rs = mysqli_query($dbc, $sql);
          $row = mysqli_fetch_array($rs);
          $destination = $row["code"];

          $sql = 'insert into history(car_id, session_nbr, event_date, event, location)
                  values ("' . $_POST[$car_name] . '",
                          "' . $session_number . '",
                          "' . date("Y-m-d H:i:s") . '",
                          "Repositioned to ' . $destination . '",
                          "' . $car_id . '")';

          if (!mysqli_query($dbc, $sql))
          {
            print 'Insert error: ' . mysqli_error($dbc) . ' SQL: ' . $sql . '<br /><br />';
          }

          $waybill_counter++;
        }
      }

      $sql = 'select cars.id as id,
                     cars.reporting_marks as reporting_marks,
                     cars.position as position,
                     cars.remarks as remarks,
                     car_codes.code as car_code,
                     loc01.station as current_station_id,
                     loc02.station as home_station_id,
                     loc01.code as current_location,
                     loc02.code as home_location,
                     sta01.station as current_station,
                     sta02.station as home_station,
                     (cars.current_location_id != cars.home_location) as off_home
                from cars
                left join car_codes on car_codes.id = cars.car_code_id
                left join locations loc01 on loc01.id = cars.current_location_id
                left join locations loc02 on loc02.id = cars.home_location
                left join routing sta01 on sta01.id = loc01.station
                left join routing sta02 on sta02.id = loc02.station
               where status = "Empty"
                 and not exists (select car_orders.car from car_orders where cars.id = car_orders.car)
               order by sta02.sort_seq asc, sta02.station asc, loc02.code asc,
                     case when cars.current_location_id != cars.home_location then 0 else 1 end asc,
                     loc01.code asc, reporting_marks asc';
      $rs = mysqli_query($dbc, $sql);
      $row_count = 0;

      if (mysqli_num_rows($rs) > 0)
      {
        print '<div class="mb-3 noprint d-flex gap-2 flex-wrap">'
             . '<input name="update_btn" value="Update" type="submit" class="btn btn-success">'
             . '<button type="button" onclick="repo_to_home()" class="btn btn-warning">Reposition to Home</button>'
             . '</div>';

        print '<div id="reposition_filters_mount" class="noprint ops-panel-tools mb-3">
                 <div id="reposition_filters" class="ops-filters">
                   <div class="ops-filter-grid">
                     <div class="ops-filter-item">
                       <label for="repo_car_code_filter">Car code</label>
                       <select id="repo_car_code_filter" class="form-select" onchange="applyRepositionFilters()">
                         <option value="">All</option>
                       </select>
                     </div>
                     <div class="ops-filter-item">
                       <label for="repo_current_loc_filter">Current loc.</label>
                       <select id="repo_current_loc_filter" class="form-select" onchange="applyRepositionFilters()">
                         <option value="">All</option>
                       </select>
                     </div>
                     <div class="ops-filter-item">
                       <label for="repo_home_loc_filter">Home loc.</label>
                       <select id="repo_home_loc_filter" class="form-select" onchange="applyRepositionFilters()">
                         <option value="">All</option>
                       </select>
                     </div>
                     <div class="ops-filter-item ops-filter-item-wide">
                       <label for="repo_marks_filter">Marks</label>
                       <input id="repo_marks_filter" type="text" class="form-control" placeholder="Filter" oninput="applyRepositionFilters()">
                     </div>
                     <div class="ops-filter-item ops-filter-actions">
                       <button type="button" class="btn btn-outline-secondary btn-sm" id="repo_off_home_btn" onclick="toggleOffHomeOnly()">Not at home</button>
                       <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearRepositionFilters()">Clear</button>
                       <span id="repo_filter_count" class="text-muted small"></span>
                     </div>
                   </div>
                 </div>
               </div>';

        print '<div class="table-responsive"><table class="table table-sm table-bordered table-hover sortable" id="car_tbl" name="car_tbl">
                 <thead>
                   <tr style="position: sticky; top: 0; background-color: #F5F5F5">
                     <th class="sorttable_nosort">Destination</th>
                     <th>Reporting Marks</th>
                     <th>Car Code</th>
                     <th>Current Station / Location</th>
                     <th>Position</th>
                     <th>Home Station / Location</th>
                     <th>Remarks</th>
                   </tr>
                 </thead>';

        $location_list = drop_down_locations("listx", 0, '');
        $current_home_group = null;

        while ($row = mysqli_fetch_array($rs))
        {
          if (file_exists('./ImageStore/DB_Images/RollingStock/' . $row['id'] . '.jpg'))
          {
            $parm_string = '\'' . $row['id'] . '\', \'' . $row['reporting_marks'] . '\'';
          }
          else
          {
            $parm_string = '\'\',\'' . $row['reporting_marks'] . '\'';
          }

          $group_key = $row['home_station'] . ' | ' . $row['home_location'];
          if ($group_key !== $current_home_group)
          {
            $current_home_group = $group_key;
            print '<tr class="table-dark home-group-header" data-group-key="' . htmlspecialchars($group_key, ENT_QUOTES) . '"><td colspan="7" class="fw-semibold">'
                . htmlspecialchars($row['home_station']) . ' &mdash; ' . htmlspecialchars($row['home_location'])
                . '</td></tr>';
          }

          $row_class = $row['off_home'] ? 'table-secondary' : 'table-success';
          print '<tr class="repo-car-row ' . $row_class . '"'
              . ' data-car-id="' . (int) $row['id'] . '"'
              . ' data-reporting-marks="' . htmlspecialchars($row['reporting_marks'], ENT_QUOTES) . '"'
              . ' data-car-code="' . htmlspecialchars($row['car_code'], ENT_QUOTES) . '"'
              . ' data-current-station="' . htmlspecialchars($row['current_station'], ENT_QUOTES) . '"'
              . ' data-current-location="' . htmlspecialchars($row['current_location'], ENT_QUOTES) . '"'
              . ' data-home-station="' . htmlspecialchars($row['home_station'], ENT_QUOTES) . '"'
              . ' data-home-location="' . htmlspecialchars($row['home_location'], ENT_QUOTES) . '"'
              . ' data-off-home="' . ($row['off_home'] ? '1' : '0') . '"'
              . ' data-home-group="' . htmlspecialchars($group_key, ENT_QUOTES) . '">';

          $loc_list_part1 = substr($location_list, 0, 16);
          $loc_list_part2 = substr($location_list, 17, 12);
          $loc_list_part3 = substr($location_list, 32);

          $new_location_list = $loc_list_part1 . $row_count . $loc_list_part2 . $row_count . '" ' . $loc_list_part3;
          print '<td>' . $new_location_list . '</td>
                 <td onclick="show_image(' . $parm_string . ');">' . htmlspecialchars($row['reporting_marks']) . '<input name="car' . $row_count . '" value="' . $row['id'] . '" type="hidden"></td>
                 <td>' . htmlspecialchars($row['car_code']) . '</td>
                 <td>' . htmlspecialchars($row['current_station']) . '<br />' . htmlspecialchars($row['current_location']) . '</td>
                 <td>' . htmlspecialchars($row['position']) . '</td>
                 <td>' . htmlspecialchars($row['home_station']) . '<br />' . htmlspecialchars($row['home_location']) . '</td>
                 <td>' . htmlspecialchars($row['remarks']) . '</td>
                 </tr>';
          $row_count++;
        }
        print '</table></div>';
        print '<input name="row_count" value="' . $row_count . '" type="hidden">';
      }
      else
      {
        print "<br />No cars are currently available for repositioning.";
      }
    ?>
    </form>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script>
      document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('#car_tbl select[name^="list"]').forEach(function(el) {
          el.classList.add('form-select', 'form-select-sm');
          el.style.removeProperty('width');
        });
        populateRepositionFilters();
      });
  </script>
  </body>
</html>
