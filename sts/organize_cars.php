<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - View/Organize Cars</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
      tr {vertical-align: top;}
      th, td { font-size: 0.875rem; padding: 6px 8px; white-space: nowrap; }
      td.checkbox {text-align: center; white-space: normal; }
      @media print { .noprint {display:none;} }
      #car_table th,
      #car_table td {
        white-space: normal;
        word-break: keep-all;
        overflow-wrap: normal;
        hyphens: none;
      }
      @media (max-width: 1024px) {
        #car_table th,
        #car_table td {
          font-size: 0.8rem;
          padding: 4px 6px;
          line-height: 1.2;
        }

        /* Hide low-priority detail columns on tablet to avoid horizontal scroll. */
        #car_table th:nth-child(6),
        #car_table td:nth-child(6),
        #car_table th:nth-child(8),
        #car_table td:nth-child(8),
        #car_table th:nth-child(9),
        #car_table td:nth-child(9) {
          display: none;
        }
      }
      #car_table tbody tr { transition: transform 140ms ease, background-color 220ms ease, box-shadow 220ms ease; }
      .drag-handle { cursor: grab; font-size: 1.2rem; line-height: 1; user-select: none; touch-action: none; display: inline-block; transition: transform 120ms ease; }
      .drag-handle:active { transform: scale(1.18); }
      body.drag-active { touch-action: none; overflow: hidden; user-select: none; }
      .drag-cell-active {
        background-color: #ffbf47 !important;
        color: #222;
        box-shadow: inset 0 0 0 2px #ff8f00;
      }
      .dragging-row {
        opacity: 0.92;
        box-shadow: 0 0.65rem 1.1rem rgba(0, 0, 0, 0.18);
        transform: scale(1.01);
      }
      .dragging-row > td {
        background-color: #ffbf47 !important;
        color: #222;
        box-shadow: inset 0 0 0 1px #ff8f00;
      }
      .row-moved-flash { animation: rowMovedFlash 420ms ease; }
      @keyframes rowMovedFlash {
        0% { background-color: #ffe08a; }
        100% { background-color: transparent; }
      }
      .status-empty    { display:inline-block; background-color:#ffeaa7; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-loaded   { display:inline-block; background-color:#a8e6cf; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-loading  { display:inline-block; background-color:#74b9ff; color:white;  padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-unloading{ display:inline-block; background-color:#fab1a0; color:white;  padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-ordered  { display:inline-block; background-color:#dfe6e9; color:#333;   padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
      .status-unavailable{ display:inline-block; background-color:#d63031; color:white; padding:3px 7px; border-radius:3px; font-weight:600; font-size:0.82rem; }
    </style>
    <script>
      function enable_org_loc_btn()
      {
        // enable the org_loc button
        document.getElementById("org_loc").disabled = false;
      }
      function enable_org_job_btn()
      {
        // enable the org_loc button
        document.getElementById("org_job").disabled = false;
      }
    </script>
    <script>
    // drag-n-drop initialization for both mouse and touch interactions
    var activeDragRow = null;
    var activeDragCell = null;
    var dragHandle = null;
    var dragListenersBound = false;

    function refresh_position_cells()
    {
      var car_table = document.getElementById('car_table');
      if (!car_table || !car_table.tBodies || car_table.tBodies.length === 0)
      {
        return;
      }

      var rows = car_table.tBodies[0].rows;
      for (var i = 0; i < rows.length; i++)
      {
        rows[i].cells[1].innerHTML = (i + 1);
      }
    }

    function move_row_by_pointer(clientY)
    {
      var car_table = document.getElementById('car_table');
      if (!car_table || !car_table.tBodies || car_table.tBodies.length === 0 || !activeDragRow)
      {
        return;
      }

      var tbody = car_table.tBodies[0];
      var rows = Array.prototype.slice.call(tbody.rows);
      var targetRow = null;

      for (var i = 0; i < rows.length; i++)
      {
        var r = rows[i];
        if (r === activeDragRow)
        {
          continue;
        }
        var rect = r.getBoundingClientRect();
        if (clientY >= rect.top && clientY <= rect.bottom)
        {
          targetRow = r;
          break;
        }
      }

      if (!targetRow)
      {
        return;
      }

      var targetRect = targetRow.getBoundingClientRect();
      var activeIndex = rows.indexOf(activeDragRow);
      var targetIndex = rows.indexOf(targetRow);

      // Reorder immediately based on drag direction for a snappier feel.
      if (activeIndex > targetIndex)
      {
        tbody.insertBefore(activeDragRow, targetRow);
      }
      else if (activeIndex < targetIndex)
      {
        tbody.insertBefore(activeDragRow, targetRow.nextSibling);
      }
      else
      {
        return;
      }
      activeDragRow.classList.remove('row-moved-flash');
      void activeDragRow.offsetWidth;
      activeDragRow.classList.add('row-moved-flash');
      refresh_position_cells();
    }

    function get_client_y(e)
    {
      if (e.touches && e.touches.length > 0)
      {
        return e.touches[0].clientY;
      }
      if (e.changedTouches && e.changedTouches.length > 0)
      {
        return e.changedTouches[0].clientY;
      }
      return e.clientY;
    }

    function on_drag_move(e)
    {
      if (!activeDragRow)
      {
        return;
      }
      move_row_by_pointer(get_client_y(e));
      e.preventDefault();
    }

    function on_drag_end(e)
    {
      if (!activeDragRow)
      {
        return;
      }
      stop_drag();
      if (e)
      {
        e.preventDefault();
      }
    }

    function stop_drag()
    {
      if (activeDragRow)
      {
        activeDragRow.classList.remove('dragging-row');
      }
      if (activeDragCell)
      {
        activeDragCell.classList.remove('drag-cell-active');
      }
      document.body.classList.remove('drag-active');
      activeDragRow = null;
      activeDragCell = null;
      dragHandle = null;
    }

    function init_drag_sort()
    {
      var car_table = document.getElementById('car_table');
      if (!car_table || !car_table.tBodies || car_table.tBodies.length === 0)
      {
        return;
      }

      var tbody = car_table.querySelector('#car_table_body') || car_table.tBodies[0];
      if (!tbody)
      {
        return;
      }

      var handles = tbody.querySelectorAll('.drag-handle');
      for (var i = 0; i < handles.length; i++)
      {
        var handle = handles[i];

        handle.onmousedown = function(e) {
          var row = e.target.closest('tr');
          if (!row)
          {
            return;
          }
          activeDragRow = row;
          activeDragCell = e.currentTarget.closest('td');
          dragHandle = e.currentTarget;
          activeDragRow.classList.add('dragging-row');
          if (activeDragCell)
          {
            activeDragCell.classList.add('drag-cell-active');
          }
          document.body.classList.add('drag-active');
          e.preventDefault();
        };

        handle.ontouchstart = function(e) {
          var row = e.target.closest('tr');
          if (!row)
          {
            return;
          }
          activeDragRow = row;
          activeDragCell = e.currentTarget.closest('td');
          dragHandle = e.currentTarget;
          activeDragRow.classList.add('dragging-row');
          if (activeDragCell)
          {
            activeDragCell.classList.add('drag-cell-active');
          }
          document.body.classList.add('drag-active');
          e.preventDefault();
        };

        handle.onpointerdown = function(e) {
          var row = e.target.closest('tr');
          if (!row)
          {
            return;
          }
          activeDragRow = row;
          activeDragCell = e.currentTarget.closest('td');
          dragHandle = e.currentTarget;
          activeDragRow.classList.add('dragging-row');
          if (activeDragCell)
          {
            activeDragCell.classList.add('drag-cell-active');
          }
          document.body.classList.add('drag-active');
          e.preventDefault();
        };
      }

      if (!dragListenersBound)
      {
        dragListenersBound = true;
        document.addEventListener('mousemove', on_drag_move);
        document.addEventListener('mouseup', on_drag_end);
        document.addEventListener('pointermove', on_drag_move);
        document.addEventListener('pointerup', on_drag_end);
        document.addEventListener('pointercancel', on_drag_end);
        document.addEventListener('touchmove', on_drag_move, { passive: false });
        document.addEventListener('touchend', on_drag_end, { passive: false });
        document.addEventListener('touchcancel', on_drag_end, { passive: false });
      }

      refresh_position_cells();
    }

    /* --- Not used, at least not yet ---
    // insert blank row
    function insert_blank_row()
    {
      var table = document.getElementById("car_table");
      var blank_row = table.insertRow(1);
      var blank_td00 = blank_row.insertCell(0);
      blank_td00.innerHTML = "<img src='./ImageStore/DB_Images/graphics/up_arrow.png' onclick='move_row(this.parentElement, -1);' alt='UP'/>" +
      " <br /><br />" + " <img src='./ImageStore/DB_Images/graphics/dn_arrow.png' onclick='move_row(this.parentElement, 1);' alt='DN'/>";
      blank_td00.style = "text-align: center; vertical-align: middle;";
      var blank_td01 = blank_row.insertCell(1);
      blank_td01.colSpan = 8;
      blank_td01.innerHTML = "&nbsp;";
    }
    --- Not used, at least not yet ---*/
    </script>
    <?php
      // bring in the javascript function that shows rollingstock photos
      require 'show_image.php';
    ?>
  </head>
  <body class="bg-light">
    <nav class="navbar navbar-dark noprint mb-3" style="background-color: #2e7d32;">
      <div class="container-fluid">
        <span class="navbar-brand"><i class="bi bi-sort-numeric-up"></i> Organize Cars</span>
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
    <h5 class="mb-3">View and/or Organize Cars</h5>
    <!-- <form method="get" action="organize_cars.php"> -->
    <?php
      // this program displays a list of locations and a list of jobs, either of which can be used
      // to display a list of cars at that location or in that job so the user can adjust the positions of the cars

      // bring in the function files
      require 'open_db.php';
      require 'drop_down_list_functions.php';

      // open a database connection
      $dbc = open_db();

      // set up a Bootstrap card row with two cards: one for jobs, one for locations
      print '<p class="text-muted">Use these functions to view cars in selected trains or on selected tracks and also to organize them in the database so it knows their physical arrangement.</p>';
      print '<div class="row g-3 mb-3">';
      // job card
      print '  <div class="col-md-6">';
      print '    <div class="card h-100">';
      print '      <div class="card-header fw-semibold"><i class="bi bi-train-front"></i> By Job/Train</div>';
      print '      <div class="card-body">';
      print '        <p class="card-text text-muted small">Select a job/train and click <b>VIEW/ORGANIZE</b>.<br />After arranging the cars, click <a href="display_switchlist.php">here</a> to generate an updated switch list.</p>';
      print        drop_down_jobs('job_list', '3', 'enable_org_job_btn()') . '<br class="mt-2" />';
      print '        <button id="org_job" name="org_job" onclick="get_cars_in_job();" disabled class="btn btn-success mt-2">';
      print '          VIEW/ORGANIZE';
      print '        </button>';
      print '      </div>';
      print '    </div>';
      print '  </div>';
      // location card
      print '  <div class="col-md-6">';
      print '    <div class="card h-100">';
      print '      <div class="card-header fw-semibold"><i class="bi bi-geo-alt"></i> By Location</div>';
      print '      <div class="card-body">';
      print '        <p class="card-text text-muted small">Select a location and click <b>VIEW/ORGANIZE</b>.<br />After arranging the cars, click <a href="display_station_report.php">here</a> to generate an updated station car report.</p>';
      print        drop_down_locations('location_list', '0', 'enable_org_loc_btn()') . '<br class="mt-2" />';
      print '        <button id="org_loc" name="org_loc" onclick="get_cars_at_location()" disabled class="btn btn-success mt-2">';
      print '          VIEW/ORGANIZE';
      print '        </button>';
      print '      </div>';
      print '    </div>';
      print '  </div>';
      print '</div>';
      // update section
      print '<div class="mb-3">';
      print '  <h5 id="location_or_job_name" class="mb-2"></h5>';
      print '  <button id="update_pos" name="update_pos" disabled onclick="update_car_positions();" class="btn btn-warning">';
      print '    <b>UPDATE POSITIONS</b>';
      print '  </button>';
      print '  <span id="update_feedback" class="ms-2 text-muted small"></span>';
      print '</div>';
      print '<div id="car_section"></div>';

    ?>
    <!-- </form> -->
  </body>
  <script>
    //////////////////////////////////// call back function for the HttpRequest functions //////////////////////////////

    // this is the call back function for the list of cars at the selected station or in the selected job
    function populate_car_table(xmlhttp)
    {
      if (xmlhttp.responseText.substring(0, 10) == "No cars at")
      {
        // tell the user that there aren't any cars at this location
        document.getElementById("car_section").innerHTML = "<p class='text-muted'>No cars found at this location.</p>";
        // disable the update and insert blank row buttons
        document.getElementById("update_pos").disabled = true;
        // document.getElementById("insert_blank_button").disabled = true;
      }
      else if (xmlhttp.responseText.substring(0, 10) == "No cars in")
      {
        // tell the user that there aren't any cars in this train
        document.getElementById("car_section").innerHTML = "<p class='text-muted'>This job is not handling any cars.</p>";
        // disable the update and insert blank row buttons
        document.getElementById("update_pos").disabled = true;
        // document.getElementById("insert_blank_button").disabled = true;
      }
      else
      {
        // display the table being returned from the server
        document.getElementById("car_section").innerHTML = xmlhttp.responseText;
        // enable the update and insert blank row buttons
        document.getElementById("update_pos").disabled = false;
        init_drag_sort();
        // document.getElementById("insert_blank_button").disabled = false;
      }
    }

    ///////////////////////////////////////// javascript for organizing cars at a location /////////////////////////////

    // build some javascript functions that will bring in a list of cars from the specified location
    function get_cars_at_location()
    {
      // check to see if the selection from the station list is non-blank
      if (document.getElementById('location_list').value.length > 0)
      {
        // display the name of the location in the instructions block
        var location_index = document.getElementById('location_list').selectedIndex;
        document.getElementById('location_or_job_name').innerHTML = "Location: " + document.getElementById('location_list').options[location_index].text;

        // submit the request for the cars at the selected location
        var xmlhttp = new XMLHttpRequest();
        xmlhttp.onreadystatechange = function()
        {
          if (this.readyState == 4 && this.status == 200)
          {
             populate_car_table(this);
          }
        }
        var url = 'get_location_cars.php?location=' + encodeURIComponent(document.getElementById('location_list').value);
        xmlhttp.open('GET', url, true);
        xmlhttp.send();
      }
    }

    //////////////////////////////////////// javascript for organizing cars in a job /////////////////////////////////////

    // build some javascript functions that will bring in a list of cars from the specified job
    function get_cars_in_job()
    {
      // check to see if the selection from the station list is non-blank
      if (document.getElementById('job_list').value.length > 0)
      {
        // display the name of the location in the instructions block
        var job_index = document.getElementById('job_list').selectedIndex;
        document.getElementById('location_or_job_name').innerHTML = "Job: " + document.getElementById('job_list').options[job_index].text;

        // submit the request for the cars at the selected location
        var xmlhttp = new XMLHttpRequest();
        xmlhttp.onreadystatechange = function()
        {
          if (this.readyState == 4 && this.status == 200)
          {
             populate_car_table(this);
          }
        }
        var url = 'get_job_cars.php?job=' + encodeURIComponent(document.getElementById('job_list').value);
        xmlhttp.open('GET', url, true);
        xmlhttp.send();
      }
    }

    //////////////////////////////////// javascript function to update car positions in the database ////////////////////////////

    // this function takes each car's position in the car_table table and uses it to update the car's position in the cars table
    function update_car_positions()
    {
      var update_btn = document.getElementById("update_pos");
      var feedback = document.getElementById("update_feedback");

      // build the parameters to the url
      var parms = "";
      var url, car_id, car_input;
      var first_parm = true;
      var car_table = document.getElementById("car_table");
      if (!car_table || !car_table.tBodies || car_table.tBodies.length === 0)
      {
        return;
      }

      var rows = car_table.tBodies[0].rows;
      for (var i=0; i<rows.length; i++)
      {
        car_input = rows[i].querySelector('input[name^="car"]');
        if (!car_input)
        {
          continue;
        }
        car_id = car_input.value;
        if (!first_parm)
        {
          parms = parms + "&";
        }
        parms = parms + "car" + (i + 1) + "=" + car_id + "&pos" + (i + 1) + "=" + (i + 1);
        first_parm=false;
      }
      // add the car count to the start of the parameters
      parms = "car_count=" + rows.length + "&" + parms;

      var existing_table_html = document.getElementById("car_section").innerHTML;
      update_btn.disabled = true;
      feedback.className = "ms-2 text-primary small";
      feedback.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Saving positions...';

      // submit the request for the cars at the selected location
      var xmlhttp = new XMLHttpRequest();
      xmlhttp.onreadystatechange = function()
      {
        if (this.readyState == 4 && this.status == 200)
        {
          var response = (this.responseText || "").trim();

          if (response.length === 0)
          {
            document.getElementById("car_section").innerHTML = existing_table_html;
            feedback.className = "ms-2 text-danger small";
            feedback.textContent = "Save failed: empty server response.";
            update_btn.disabled = false;
            init_drag_sort();
            return;
          }

          if ((response.indexOf("Query Error:") === 0) || (response.indexOf("Update Error") === 0))
          {
            document.getElementById("car_section").innerHTML = existing_table_html;
            feedback.className = "ms-2 text-danger small";
            feedback.textContent = "Save failed. See server error details in response.";
            update_btn.disabled = false;
            init_drag_sort();
            return;
          }

          document.getElementById("car_section").innerHTML = response;
          update_btn.disabled = false;
          feedback.className = "ms-2 text-success small";
          feedback.textContent = "Positions saved.";
          init_drag_sort();
        }
        else if (this.readyState == 4)
        {
          document.getElementById("car_section").innerHTML = existing_table_html;
          feedback.className = "ms-2 text-danger small";
          feedback.textContent = "Save failed: network/server error.";
          update_btn.disabled = false;
          init_drag_sort();
        }
      }
      //var url = 'update_car_positions.php?' + encodeURIComponent(parms);
      var url = 'update_car_positions.php?' + parms;
      xmlhttp.open('GET', url, true);
      xmlhttp.send();
    }
  </script>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("select").forEach(function(el){el.classList.add("form-select");el.style.removeProperty("width");if(el.closest("th")){el.classList.add("form-select-sm");}});});</script>
  </body>
</html>
