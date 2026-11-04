(function () {
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
  
document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("select").forEach(function(el){el.classList.add("form-select");el.style.removeProperty("width");if(el.closest("th")){el.classList.add("form-select-sm");}});});

  STS.action('enable_org_job_btn', function () { enable_org_job_btn(); });
  STS.action('enable_org_loc_btn', function () { enable_org_loc_btn(); });
  STS.action('get_cars_in_job', function () { get_cars_in_job(); });
  STS.action('get_cars_at_location', function () { get_cars_at_location(); });
  STS.action('update_car_positions', function () { update_car_positions(); });
})();
