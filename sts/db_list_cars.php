<?php
  // list_cars.php
  // adds a new car to the cars table if the Update button was clicked
  // and if there is something in the reporting marks text box.

  print '<script type="text/javascript">
           document.getElementById("table_name").innerHTML = "Cars";
           document.getElementById("tbl_name").value = "cars";
           document.getElementById("update_btn").tabIndex = "9";
           document.getElementById("update_btn").disabled = true;
         </script>';

  print '
  <style>
    /* ── Cars page layout ───────────────────────────── */
    #toolbar-card, #toolbar-card *, .d-flex, .d-flex *, .table-responsive, .cell-editor-overlay, .cell-editor-panel { font-family: inherit; }
    #toolbar-card select { font-size: 13px; min-height: 31px; padding: 2px 8px; border: 1px solid #ced4da; border-radius: 4px; width: 100%; background-color: #fff; }
    #instructions { display: none; }
    #update { display: inline; }
    #update_btn { display: none; }

    /* ── Modern Car Table ───────────────────────────── */
    #car_tbl {
      border-collapse: collapse;
      font-size: 13px;
      width: 100%;
    }
    #car_tbl th {
      background-color: #e9ecef;
      font-weight: 600;
      white-space: nowrap;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    #car_tbl th, #car_tbl td {
      border: 1px solid #dee2e6;
      padding: 6px 8px;
      vertical-align: middle;
    }
    #car_tbl tbody tr:hover,
    #car_tbl tbody tr:hover > td {
      background-color: #f0f4ff !important;
    }
    .pool-highlight,
    .pool-highlight > td {
      background-color: #fff9c4 !important;
    }

    /* ── Editable cells ─────────────────────────────── */
    .editable-cell {
      cursor: pointer;
      position: relative;
    }
    .editable-cell::after {
      content: "\270E";          /* pencil unicode */
      position: absolute;
      top: 2px;
      right: 3px;
      font-size: 10px;
      color: #adb5bd;
      opacity: 0;
      transition: opacity .15s;
    }
    .editable-cell:hover::after,
    .editable-cell:focus::after {
      opacity: 1;
    }

    /* ── Edit-mode overlay ──────────────────────────── */
    .cell-editor-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,.35);
      z-index: 9999;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .cell-editor-panel {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 8px 32px rgba(0,0,0,.25);
      padding: 24px 28px;
      min-width: 320px;
      max-width: 90vw;
      animation: editorIn .15s ease-out;
    }
    @keyframes editorIn {
      from { transform: scale(.92); opacity: 0; }
      to   { transform: scale(1);   opacity: 1; }
    }
    .cell-editor-panel .editor-title {
      font-size: 14px;
      font-weight: 600;
      color: #495057;
      margin-bottom: 12px;
    }
    .cell-editor-panel .form-select,
    .cell-editor-panel .form-control {
      font-size: 16px;          /* prevents iOS zoom */
      min-height: 48px;         /* large touch target */
    }
    .cell-editor-panel .btn-row {
      display: flex;
      gap: 8px;
      margin-top: 16px;
    }
    .cell-editor-panel .btn-row .btn {
      flex: 1;
      min-height: 44px;
    }
    .save-flash {
      animation: flashGreen .6s;
    }
    @keyframes flashGreen {
      0%   { background-color: #d4edda; }
      100% { background-color: transparent; }
    }

    /* ── Filter / Add-Car toolbar ───────────────────── */
    #toolbar-card .form-select,
    #toolbar-card .form-control {
      font-size: 14px;
    }

    /* ── Responsive: collapse columns on small screens */
    @media (max-width: 992px) {
      .col-hide-md { display: none !important; }
    }
    @media (max-width: 768px) {
      .col-hide-sm { display: none !important; }
    }
  </style>

  <div id="saveNotification" class="alert alert-success d-none" role="alert"></div>
  ';
  ?>

  <script>
    /* ── Dropdown cache ──────────────────────────────── */
    let dropdownCache = { carCodes: [], locations: [], jobs: [], loaded: false };

    function loadDropdownOptions() {
      if (dropdownCache.loaded) return $.when(dropdownCache);
      return $.ajax({
        url: "get_dropdowns_ajax.php", type: "GET", dataType: "json",
        success: function(data) {
          dropdownCache = { carCodes: data.carCodes||[], locations: data.locations||[], jobs: data.jobs||[], loaded: true };
        },
        error: function(e) { console.error("Error loading dropdown options:", e); }
      });
    }

    // Pre-load dropdowns on page load
    $(function(){
      loadDropdownOptions();
      var tooltipList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
      tooltipList.map(function(el) { return new bootstrap.Tooltip(el); });
    });

    /* ── Modal-style editor (works on desktop + touch) ─ */
    function makeEditable(cell, carId, field, fieldType, currentValue) {
      fieldType = fieldType || "text";
      currentValue = currentValue != null ? currentValue : null;

      if (document.querySelector('.cell-editor-overlay')) return; // already open

      const originalText = cell.textContent.trim();
      const originalValue = currentValue != null ? currentValue : originalText;

      loadDropdownOptions().done(function() { openEditor(); });

      function openEditor() {
        // Build overlay
        const overlay = document.createElement('div');
        overlay.className = 'cell-editor-overlay';

        const panel = document.createElement('div');
        panel.className = 'cell-editor-panel';

        const title = document.createElement('div');
        title.className = 'editor-title';

        const fieldLabels = {
          'car_code_id': 'Car Code', 'current_location_id': 'Current Location',
          'position': 'Position', 'status': 'Status', 'handled_by_job_id': 'Handled By Job',
          'remarks': 'Remarks', 'home_location': 'Home Location', 'RFID_code': 'RFID Code'
        };
        title.textContent = 'Edit: ' + (fieldLabels[field] || field);
        panel.appendChild(title);

        let input;

        if (fieldType === 'status') {
          input = document.createElement('select');
          input.className = 'form-select';
          [['', '-- Select Status --'], ['Empty','Empty'], ['Unavailable','Unavailable']].forEach(function(o) {
            const opt = document.createElement('option');
            opt.value = o[0]; opt.textContent = o[1];
            if (o[0] === originalText) opt.selected = true;
            input.appendChild(opt);
          });
        } else if (fieldType === 'car_code') {
          input = document.createElement('select');
          input.className = 'form-select';
          const d = document.createElement('option');
          d.value = ''; d.textContent = '-- Select Car Code --';
          input.appendChild(d);
          dropdownCache.carCodes.forEach(function(c) {
            const opt = document.createElement('option');
            opt.value = c.id; opt.textContent = c.code;
            if (c.id == originalValue) opt.selected = true;
            input.appendChild(opt);
          });
        } else if (fieldType === 'location') {
          input = document.createElement('select');
          input.className = 'form-select';
          const d = document.createElement('option');
          d.value = ''; d.textContent = '-- Select Location --';
          input.appendChild(d);
          dropdownCache.locations.forEach(function(l) {
            const opt = document.createElement('option');
            opt.value = l.id; opt.textContent = l.station + ' - ' + l.location;
            if (l.id == originalValue) opt.selected = true;
            input.appendChild(opt);
          });
        } else if (fieldType === 'job') {
          input = document.createElement('select');
          input.className = 'form-select';
          const d = document.createElement('option');
          d.value = ''; d.textContent = '-- Select Job --';
          input.appendChild(d);
          dropdownCache.jobs.forEach(function(j) {
            const opt = document.createElement('option');
            opt.value = j.id; opt.textContent = j.name;
            if (j.id == originalValue) opt.selected = true;
            input.appendChild(opt);
          });
        } else {
          input = document.createElement('input');
          input.type = 'text';
          input.className = 'form-control';
          input.value = originalText;
        }

        panel.appendChild(input);

        // Buttons
        const btnRow = document.createElement('div');
        btnRow.className = 'btn-row';

        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'btn btn-primary';
        saveBtn.innerHTML = '<i class="bi bi-check-lg"></i> Save';

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'btn btn-outline-secondary';
        cancelBtn.innerHTML = '<i class="bi bi-x-lg"></i> Cancel';

        btnRow.appendChild(saveBtn);
        btnRow.appendChild(cancelBtn);
        panel.appendChild(btnRow);
        overlay.appendChild(panel);
        document.body.appendChild(overlay);

        // Focus the input
        setTimeout(function() { input.focus(); }, 50);

        // Store row for dependent cell refresh
        const row = cell.closest('tr');

        function closeEditor() {
          overlay.remove();
        }

        function saveValue() {
          const newValue = input.value;
          if (newValue === String(originalValue) || (newValue === '' && originalText === '')) {
            closeEditor();
            return;
          }

          saveBtn.disabled = true;
          saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';

          $.ajax({
            url: "update_car_ajax.php", type: "POST", contentType: "application/json",
            data: JSON.stringify({ car_id: carId, field: field, value: newValue }),
            success: function(response) {
              closeEditor();
              if (response.formatAsHtml) {
                cell.innerHTML = response.displayValue;
              } else {
                cell.textContent = response.displayValue || newValue || originalText;
              }
              cell.classList.add('save-flash');
              setTimeout(function(){ cell.classList.remove('save-flash'); }, 700);

              if (field === 'status' && (newValue === 'Empty' || newValue === 'Unavailable')) {
                refreshDependentCells(row);
              }
            },
            error: function(err) {
              saveBtn.disabled = false;
              saveBtn.innerHTML = '<i class="bi bi-check-lg"></i> Save';
              console.error("Error updating field:", err);
              alert("Failed to save. Please try again.");
            }
          });
        }

        saveBtn.addEventListener('click', saveValue);
        cancelBtn.addEventListener('click', closeEditor);
        overlay.addEventListener('click', function(e) {
          if (e.target === overlay) closeEditor();
        });
        input.addEventListener('keydown', function(e) {
          if (e.key === 'Enter') { e.preventDefault(); saveValue(); }
          if (e.key === 'Escape') closeEditor();
        });
      }
    }

    function refreshDependentCells(row) {
      if (row && row.cells) {
        row.cells[5].textContent = "";  // Handled By
        row.cells[6].textContent = "";  // Consignment
        row.cells[7].textContent = "";  // Loading Location
        row.cells[8].textContent = "";  // Unloading Location
      }
    }
  </script>

  <?php

  // JavaScript filter/sort functions
  print '<script type="text/javascript">
           function hide_rows(tbl_col)
           {
             if (document.getElementById("hide_unavail").checked == true)
             {
               var table = document.getElementById("car_tbl");
               for (var i = 0, row; row = table.tBodies[0].rows[i]; i++)
               {
                 if (row.cells[tbl_col].innerText == "Unavailable")
                     row.style.display = "none";
               }
             }
             else
             {
               var table = document.getElementById("car_tbl");
               for (var i = 0, row; row = table.tBodies[0].rows[i]; i++)
               {
                 if (row.cells[tbl_col].innerText == "Unavailable")
                   row.style.display = "";
               }
             }
           }

           function filter_rows(tbl_col, needle)
           {
             if (needle.length > 0)
             {
               var hyphen_loc = needle.search(" - ");
               if (hyphen_loc >= 0)
               {
                 var new_needle = needle.substr(0, hyphen_loc) + "\n" + needle.substr(hyphen_loc + 3, needle.length);
                 needle = new_needle;
               }
               var table = document.getElementById("car_tbl");
               for (var i = 0, row; row = table.tBodies[0].rows[i]; i++)
               {
                 var haystack_length = row.cells[tbl_col].innerText.length;
                 var needle_length = needle.length;
                 var match_start = haystack_length - needle_length;
                 var haystack = row.cells[tbl_col].innerText.substr(match_start);
                 if (haystack != needle) row.style.display = "none";
               }
             }
           }

           function filter_reporting_marks(needle)
           {
             if (needle.length > 0)
             {
               var table = document.getElementById("car_tbl");
               for (var i = 0, row; row = table.tBodies[0].rows[i]; i++)
               {
                 var needle_length = needle.length;
                 var haystack = row.cells[0].innerText.substr(0, needle_length);
                 if (haystack != needle) row.style.display = "none";
               }
             }
           }

           function add_cars()
           {
             document.getElementById("update_btn").disabled = false;
             document.getElementById("update_btn").style.display = "inline-block";
           }

           function filter_cars()
           {
             document.getElementById("update_btn").disabled = true;
             document.getElementById("update_btn").style.display = "none";
           }

           function clear_filters()
           {
             window.location.replace("db_list.php?tbl_name=cars");
           }
         </script>';

  // get a database connection
  global $dbc;
  if (!($dbc instanceof mysqli)) {
    $dbc = open_db();
  }
  require_once 'db_cars_stats.php';
  $car_stats = db_cars_get_stats($dbc);

  // has the submit button been clicked?
  if (isset($_POST['update_btn']))
  {
    if (strlen($_POST['rptgmarks']) > 0)
    {
      $position = (strlen($_POST['position']) > 0) ? $_POST['position'] : 0;
      $sql = 'insert into cars (reporting_marks, car_code_id, current_location_id, position, status, handled_by_job_id, remarks, home_location, load_count, RFID_code)
              values ("' . $_POST['rptgmarks'] . '",
                      "' . $_POST['car_code'] . '",
                      "' . $_POST['current_location'] . '",
                      "' . $position . '",
                      "Empty",
                       0,
                      "' . $_POST['remarks'] . '",
                      "' . $_POST['home_location'] . '",
                      "0",
                      "' . $_POST['RFID_code'] .'")';
      if (!mysqli_query($dbc, $sql))
      {
        print '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> Insert Error: ' . mysqli_error($dbc) . '</div>';
      }
    }
  }

  print db_cars_render_stats_panel($car_stats);

  /* ── Toolbar: Add Car / Filters ───────────────────────────────────── */
  print '
  <div id="toolbar-card" class="card mb-3">
    <div class="card-header p-2">
      <ul class="nav nav-tabs card-header-tabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="add-tab" data-bs-toggle="tab" data-bs-target="#add_car_section"
                  type="button" role="tab" onclick="add_cars()">
            <i class="bi bi-plus-circle"></i> Add Car
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="filter-tab" data-bs-toggle="tab" data-bs-target="#filter_section"
                  type="button" role="tab" onclick="filter_cars()">
            <i class="bi bi-funnel"></i> Filters
          </button>
        </li>
      </ul>
    </div>
    <div class="card-body p-2 tab-content">

      <!-- Add Car pane -->
      <div class="tab-pane fade" id="add_car_section" role="tabpanel">
        <div class="row g-2 align-items-end">
          <div class="col-6 col-md-2">
            <label class="form-label mb-0 small">Reporting Marks</label>
            <input id="rptgmarks" name="rptgmarks" type="text" class="form-control form-control-sm" tabindex="1" required>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label mb-0 small">Car Code</label>
            ' . drop_down_car_codes('car_code', 2, 'no_wild') . '
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label mb-0 small">Location</label>
            ' . drop_down_locations('current_location', 3, '') . '
          </div>
          <div class="col-3 col-md-1">
            <label class="form-label mb-0 small">Position</label>
            <input id="position" name="position" type="text" class="form-control form-control-sm" tabindex="4">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label mb-0 small">Remarks</label>
            <input id="remarks" name="remarks" type="text" class="form-control form-control-sm" tabindex="5">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label mb-0 small">Home Location</label>
            ' . drop_down_locations('home_location', 6, '') . '
          </div>
          <div class="col-6 col-md-1">
            <label class="form-label mb-0 small">RFID</label>
            <input id="RFID_code" name="RFID_code" type="text" class="form-control form-control-sm" tabindex="7">
          </div>
        </div>
      </div>

      <!-- Filter pane -->
      <div class="tab-pane fade show active" id="filter_section" role="tabpanel">
        <div class="row g-2 align-items-end">
          <div class="col-6 col-md-2">
            <label class="form-label mb-0 small">Reporting Marks</label>
            <input type="text" id="reporting_marks_filter" class="form-control form-control-sm"
                   onchange="filter_reporting_marks(this.value);">
          </div>
          <div class="col-6 col-md-2"
               onchange="filter_rows(1, document.getElementById(\'car_code_filter\').options[document.getElementById(\'car_code_filter\').selectedIndex].text);
                          document.getElementById(\'car_code_filter\').disabled=true;">
            <label class="form-label mb-0 small">Car Code</label>
            ' . drop_down_car_codes('car_code_filter', '', 'no_wild') . '
          </div>
          <div class="col-6 col-md-2"
               onchange="filter_rows(2, document.getElementById(\'current_location_filter\').options[document.getElementById(\'current_location_filter\').selectedIndex].text);
                          document.getElementById(\'current_location_filter\').disabled=true;">
            <label class="form-label mb-0 small">Location</label>
            ' . drop_down_locations('current_location_filter', '', '') . '
          </div>
          <div class="col-6 col-md-2"
               onchange="filter_rows(4, document.getElementById(\'status_filter\').options[document.getElementById(\'status_filter\').selectedIndex].text);
                          document.getElementById(\'status_filter\').disabled=true;">
            <label class="form-label mb-0 small">Status</label>
            ' . drop_down_status('status_filter', '', '') . '
          </div>
          <div class="col-6 col-md-2"
               onchange="filter_rows(5, document.getElementById(\'job_filter\').options[document.getElementById(\'job_filter\').selectedIndex].text);
                          document.getElementById(\'job_filter\').disabled=true;">
            <label class="form-label mb-0 small">Job</label>
            ' . drop_down_jobs('job_filter', '', '') . '
          </div>
          <div class="col-6 col-md-2"
               onchange="filter_rows(6, document.getElementById(\'commodity_filter\').options[document.getElementById(\'commodity_filter\').selectedIndex].text);
                          document.getElementById(\'commodity_filter\').disabled=true;">
            <label class="form-label mb-0 small">Commodity</label>
            ' . drop_down_commodities('commodity_filter', '', '') . '
          </div>
          <div class="col-6 col-md-2"
               onchange="filter_rows(7, document.getElementById(\'loading_location_filter\').options[document.getElementById(\'loading_location_filter\').selectedIndex].text);
                          document.getElementById(\'loading_location_filter\').disabled=true;">
            <label class="form-label mb-0 small">Loading Loc</label>
            ' . drop_down_locations('loading_location_filter', '', '') . '
          </div>
          <div class="col-6 col-md-2"
               onchange="filter_rows(8, document.getElementById(\'unloading_location_filter\').options[document.getElementById(\'unloading_location_filter\').selectedIndex].text);
                          document.getElementById(\'unloading_location_filter\').disabled=true;">
            <label class="form-label mb-0 small">Unloading Loc</label>
            ' . drop_down_locations('unloading_location_filter', '', '') . '
          </div>
          <div class="col-6 col-md-2"
               onchange="filter_rows(10, document.getElementById(\'home_location_filter\').options[document.getElementById(\'home_location_filter\').selectedIndex].text);
                          document.getElementById(\'home_location_filter\').disabled=true;">
            <label class="form-label mb-0 small">Home Loc</label>
            ' . drop_down_locations('home_location_filter', '', '') . '
          </div>
          <div class="col-auto">
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="clear_filters();">
              <i class="bi bi-x-circle"></i> Clear
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex align-items-center gap-3 mb-2">
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" id="hide_unavail" onclick="hide_rows(4);">
      <label class="form-check-label small" for="hide_unavail">Hide unavailable</label>
    </div>
    <small class="text-muted">Cars in a pooling arrangement are <span class="badge" style="background:#fff9c4;color:#333;">highlighted</span>.
    Click on column titles to sort. Tap editable cells to change values.</small>
  </div>';

  /* ── Data table ────────────────────────────────────────────────────── */
  print '
  <div class="table-responsive" style="max-height:75vh;overflow-y:auto;">
  <table class="sortable table table-sm table-bordered table-hover mb-0" id="car_tbl">
    <thead>
      <tr>
        <th>Reporting<br>Marks</th>
        <th>Car<br>Code</th>
        <th>Current<br><u>Station</u><br>Location</th>
        <th>Pos</th>
        <th>Status</th>
        <th>Handled By</th>
        <th class="col-hide-md">Consignment</th>
        <th class="col-hide-sm">Loading<br><u>Station</u><br>Location</th>
        <th class="col-hide-sm">Unloading<br><u>Station</u><br>Location</th>
        <th>Remarks</th>
        <th class="col-hide-md">Home<br><u>Station</u><br>Location</th>
        <th class="col-hide-md">Loads</th>
        <th class="col-hide-md sorttable_nosort">Pool</th>
        <th class="col-hide-sm">RFID</th>
        <th class="col-hide-md">Spotted</th>
        <th class="sorttable_nosort">Hist</th>
      </tr>
    </thead>
    <tbody>';

  // query the database
  $sql = 'select cars.id as id,
                 cars.reporting_marks as reporting_marks,
                 cars.car_code_id as car_code_id,
                 cars.current_location_id as current_location_id,
                 cars.position as position,
                 cars.status as status,
                 cars.handled_by_job_id as handled_by,
                 cars.remarks as remarks,
                 cars.load_count as load_count,
                 cars.RFID_code as RFID_code,
                 cars.last_spotted as last_spotted,
                 car_orders.waybill_number as waybill_number,
                 car_orders.shipment as shipment,
                 commodities.code as consignment,
                 shipments.loading_location as loading_location_id,
                 shipments.unloading_location as unloading_location_id,
                 jobs.name as job_name,
                 car_codes.code as car_code,
                 loc01.code as current_location,
                 loc02.code as loading_location,
                 loc03.code as unloading_location,
                 loc04.code as home_location,
                 cars.home_location as home_location_id,
                 sta01.station as current_station,
                 sta02.station as loading_station,
                 sta03.station as unloading_station,
                 sta04.station as home_station
            from cars
            left join car_orders on cars.id = car_orders.car
            left join shipments on car_orders.shipment = shipments.id
            left join commodities on commodities.id = shipments.consignment
            left join jobs on cars.handled_by_job_id = jobs.id
            left join car_codes on cars.car_code_id = car_codes.id
            left join locations loc01 on cars.current_location_id = loc01.id
            left join locations loc02 on shipments.loading_location = loc02.id
            left join locations loc03 on shipments.unloading_location = loc03.id
            left join locations loc04 on cars.home_location = loc04.id
            left join routing sta01 on sta01.id = loc01.station
            left join routing sta02 on sta02.id = loc02.station
            left join routing sta03 on sta03.id = loc03.station
            left join routing sta04 on sta04.id = loc04.station
           order by cars.reporting_marks';

  $rs = mysqli_query($dbc, $sql);

  if (mysqli_num_rows($rs) > 0)
  {
    while ($row = mysqli_fetch_array($rs))
    {
      // Pool highlight
      $sql_pool = 'select count(*) from pool where car_id = "' . $row['id'] . '"';
      $rs_pool = mysqli_query($dbc, $sql_pool);
      $row_pool = mysqli_fetch_array($rs_pool);
      $pool_class = ($row_pool[0] > 0) ? ' pool-highlight' : '';

      print '<tr class="' . $pool_class . '">';

      // col 0 – reporting marks (link to edit page)
      print '<td><a href="db_edit.php?tbl_name=cars&obj_name=' . urlencode($row['id']) . '&obj_id=' . urlencode($row['reporting_marks']) . '">' . $row['reporting_marks'] . '</a></td>';

      // col 1 – car code (editable)
      print '<td class="editable-cell" style="text-align:center" onclick="makeEditable(this, ' . $row['id'] . ', \'car_code_id\', \'car_code\', ' . $row['car_code_id'] . ')">' . $row['car_code'] . '</td>';

      // col 2 – current location (editable)
      if ($row['current_location_id'] > 0)
      {
        print '<td class="editable-cell" onclick="makeEditable(this, ' . $row['id'] . ', \'current_location_id\', \'location\', ' . $row['current_location_id'] . ')"><u>' . $row['current_station'] . '</u><br>' . $row['current_location'] . '</td>';
      }
      else
      {
        print '<td class="editable-cell" onclick="makeEditable(this, ' . $row['id'] . ', \'current_location_id\', \'location\', 0)"><span class="badge bg-info text-dark">In Train</span></td>';
      }

      // col 3 – position (editable)
      print '<td class="editable-cell" style="text-align:center" onclick="makeEditable(this, ' . $row['id'] . ', \'position\')">' . $row['position'] . '</td>';

      // col 4 – status (editable)
      $status_badge = '';
      if ($row['status'] == 'Empty')        $status_badge = '<span class="badge bg-secondary">Empty</span>';
      elseif ($row['status'] == 'Ordered')   $status_badge = '<span class="badge bg-warning text-dark">Ordered</span>';
      elseif ($row['status'] == 'Loaded')    $status_badge = '<span class="badge bg-success">Loaded</span>';
      elseif ($row['status'] == 'Unavailable') $status_badge = '<span class="badge bg-danger">Unavailable</span>';
      else $status_badge = $row['status'];
      print '<td class="editable-cell" onclick="makeEditable(this, ' . $row['id'] . ', \'status\', \'status\')">' . $status_badge . '</td>';

      // col 5 – handled by (editable)
      print '<td class="editable-cell" onclick="makeEditable(this, ' . $row['id'] . ', \'handled_by_job_id\', \'job\', ' . $row['handled_by'] . ')">' . ($row['job_name'] ? $row['job_name'] : '<span class="text-muted">(none)</span>') . '</td>';

      // col 6 – consignment
      if (substr($row['waybill_number'], 4, 1) == 'E')
        print '<td class="col-hide-md"><span class="text-info">Non-Revenue</span></td>';
      else
        print '<td class="col-hide-md">' . $row['consignment'] . '</td>';

      // col 7 – loading location
      if (substr($row['waybill_number'], 4, 1) == 'E')
      {
        print '<td class="col-hide-sm">N/A</td>';
      }
      else
      {
        if ($row['status'] == 'Ordered')
          print '<td class="col-hide-sm"><b><u>' . $row['loading_station'] . '</u><br>' . $row['loading_location'] . '</b></td>';
        else if (($row['status'] == "Empty") || ($row['status'] == "Unavailable"))
          print '<td class="col-hide-sm"></td>';
        else
          print '<td class="col-hide-sm"><u>' . $row['loading_station'] . '</u><br>' . $row['loading_location'] . '</td>';
      }

      // col 8 – unloading location
      if (substr($row['waybill_number'], 4, 1) == 'E')
      {
        $sql2 = 'select code from locations where id = "' . $row['shipment'] . '"';
        $rs2 = mysqli_query($dbc, $sql2);
        $row2 = mysqli_fetch_array($rs2);
        $sql3 = 'select routing.station from routing, locations where routing.id = locations.station and locations.id = "' . $row['shipment'] . '"';
        $rs3 = mysqli_query($dbc, $sql3);
        $row3 = mysqli_fetch_array($rs3);
        print '<td class="col-hide-sm"><b><u>' . $row3['station'] . '</u><br>' . $row2['code'] . '</b></td>';
      }
      else
      {
        if ($row['status'] == 'Loaded')
          print '<td class="col-hide-sm"><b><u>' . $row['unloading_station'] . '</u><br>' . $row['unloading_location'] . '</b></td>';
        else if (($row['status'] == "Empty") || ($row['status'] == "Unavailable"))
          print '<td class="col-hide-sm"></td>';
        else
          print '<td class="col-hide-sm"><u>' . $row['unloading_station'] . '</u><br>' . $row['unloading_location'] . '</td>';
      }

      // col 9 – remarks (editable)
      print '<td class="editable-cell" onclick="makeEditable(this, ' . $row['id'] . ', \'remarks\')">' . $row['remarks'] . '</td>';

      // col 10 – home location (editable)
      print '<td class="editable-cell col-hide-md" onclick="makeEditable(this, ' . $row['id'] . ', \'home_location\', \'location\', ' . $row['home_location_id'] . ')"><u>' . $row['home_station'] . '</u><br>' . $row['home_location'] . '</td>';

      // col 11 – load count
      print '<td class="col-hide-md" style="text-align:center">' . $row['load_count'] . '</td>';

      // col 12 – pool
      print '<td class="col-hide-md" style="text-align:center"><a href="db_edit.php?tbl_name=pool&obj_name=' . $row['id'] . '&obj_id=' . $row['reporting_marks'] . '" class="btn btn-outline-secondary btn-sm py-0 px-1" style="font-size:11px">Edit</a></td>';

      // col 13 – RFID (editable)
      print '<td class="editable-cell col-hide-sm" onclick="makeEditable(this, ' . $row['id'] . ', \'RFID_code\')">' . $row['RFID_code'] . '</td>';

      // col 14 – last spotted
      print '<td class="col-hide-md" style="text-align:center">' . $row['last_spotted'] . '</td>';

      // col 15 – history
      print '<td><a href="car_history.php?car_id=' . $row['id'] . '" class="btn btn-outline-primary btn-sm py-0 px-1" style="font-size:11px"><i class="bi bi-clock-history"></i></a></td>';

      print '</tr>';
    }
  }
  print '</tbody></table></div>';

  // Add "None" option to home_location dropdown
  print '<script>
           var select = document.getElementById("home_location");
           if (select) {
             var option = document.createElement("option");
             option.text = "None";
             option.value = "0";
             select.add(option, select[1]);
           }
         </script>';
?>
