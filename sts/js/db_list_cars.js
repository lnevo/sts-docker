(function () {
           document.getElementById("table_name").innerHTML = "Cars";
           document.getElementById("tbl_name").value = "cars";
           document.getElementById("update_btn").tabIndex = "9";
           document.getElementById("update_btn").disabled = true;
         
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
         
           var select = document.getElementById("home_location");
           if (select) {
             var option = document.createElement("option");
             option.text = "None";
             option.value = "0";
             select.add(option, select[1]);
           }
         

  STS.action('add_cars', function () { add_cars(); });
  STS.action('filter_cars', function () { filter_cars(); });
  STS.action('clear_filters', function () { clear_filters(); });
  STS.action('hide_rows', function () { hide_rows(4); });
  STS.action('filter_reporting_marks', function (e, el) { filter_reporting_marks(el.value); });
  STS.action('filterSelect', function (e, el) {
    var sel = document.getElementById(el.getAttribute('data-filter'));
    if (!sel) { return; }
    filter_rows(parseInt(el.getAttribute('data-col'), 10), sel.options[sel.selectedIndex].text);
    sel.disabled = true;
  });
  STS.action('makeEditable', function (e, el) {
    var value = el.getAttribute('data-value');
    makeEditable(el, Number(el.getAttribute('data-car-id')), el.getAttribute('data-field'),
      el.getAttribute('data-type') || undefined, value === null ? undefined : value);
  });
})();
