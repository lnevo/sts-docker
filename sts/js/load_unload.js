(function () {
      function show_image(car_id, reporting_marks)
      {
        if (car_id.length > 0)
        {
          // find the upper right corner of the browser window
          var upper_right_x = window.screenX + window.parent.outerWidth;
          var upper_right_y = window.screenY;

          // calculate the upper left corner of the new window
          var upper_left_x = upper_right_x - 700;
          var upper_left_y = upper_right_y + 30;

          // open the window
          window.open("./ImageStore/DB_Images/RollingStock/" + car_id + ".jpg", "image_window", "width=660,height=500,left=" + upper_left_x + ",top=" + upper_left_y);
        }
        else
        {
          alert("No photo available for " + reporting_marks);
        }
      }
    
      function getLoadUnloadDataRows()
      {
        var table = document.getElementById('car_table');
        if (!table) return [];
        return Array.from(table.rows).filter(function(row, index) {
          return index > 0;
        });
      }

      function isLoadUnloadRowVisible(row)
      {
        return row.style.display !== 'none';
      }

      function syncLoadUnloadHiddenRows()
      {
        getLoadUnloadDataRows().forEach(function(row) {
          var checkbox = row.querySelector('input[type="checkbox"][name^="check"]');
          if (!checkbox) return;
          if (!isLoadUnloadRowVisible(row)) {
            checkbox.checked = false;
            checkbox.disabled = true;
          } else {
            checkbox.disabled = false;
          }
        });
        updateLoadUnloadCheckAllState();
      }

      function updateLoadUnloadCheckAllState()
      {
        var checkAll = document.getElementById('check_all');
        if (!checkAll) return;
        var visibleChecks = getLoadUnloadDataRows()
          .filter(isLoadUnloadRowVisible)
          .map(function(row) { return row.querySelector('input[type="checkbox"][name^="check"]'); })
          .filter(function(checkbox) { return checkbox && !checkbox.disabled; });
        checkAll.checked = visibleChecks.length > 0 && visibleChecks.every(function(checkbox) { return checkbox.checked; });
      }

      function prepareLoadUnloadSubmit()
      {
        syncLoadUnloadHiddenRows();
        return true;
      }

      // this javascript function is triggered by the user changing the "All" checkbox
      function checkall()
      {
        var checked = document.getElementById('check_all').checked;
        getLoadUnloadDataRows().forEach(function(row) {
          if (!isLoadUnloadRowVisible(row)) return;
          var checkbox = row.querySelector('input[type="checkbox"][name^="check"]');
          if (checkbox && !checkbox.disabled) {
            checkbox.checked = checked;
          }
        });
        updateLoadUnloadCheckAllState();
      }
    
             function filter_rows(tbl_col, needle)
             {
               // confirm that a non-blank option has been selected
               if (needle.length > 0)
               {
                 // convert drop-down locations (station - location) to table locations (station\nlocation)
                 var hyphen_loc = needle.search(" - ");
                 if (hyphen_loc >= 0)
                 {
                   var new_needle = needle.substr(0, hyphen_loc) + "\n" + needle.substr(hyphen_loc + 3, needle.length);
                   needle = new_needle;
                 }

                 var table = document.getElementById("car_table");

                 //iterate through rows
                 for (var i = 1, row; row = table.rows[i]; i++)
                 {
                   var haystack_length = row.cells[tbl_col].innerText.length;
                   var needle_length = needle.length;
                   var match_start = haystack_length - needle_length;

                   var haystack = row.cells[tbl_col].innerText.substr(match_start);

                   if (haystack != needle)
                   {
                     row.style.display = "none"
                   }
                 }
                 syncLoadUnloadHiddenRows();
               }
             }
           
STS.ready(function () {
    var instr = document.getElementById("instructions");
    var d = STS.data('sts-data-load-unload');
    if (!instr) { return; }
    if (d && d.noCars) { instr.innerHTML = ""; }
    instr.innerHTML = instr.innerHTML + "Filters can be used to hide rows. ";
    instr.innerHTML = instr.innerHTML + "Click on column titles to sort the table<br />";
  });
             
    document.addEventListener("DOMContentLoaded", function() {
      document.querySelectorAll("select").forEach(function(el) {
        el.classList.add("form-select", "form-select-sm");
        el.style.removeProperty("width");
      });
      [['current_loc_filter',1],['car_code_filter',4],['status_filter',5],['commodity_filter',6],['loading_loc_filter',7],['unloading_loc_filter',8]].forEach(function(f) {
        var el = document.getElementById(f[0]);
        if (el) { el.addEventListener('change', function() { filter_rows(f[1], this.options[this.selectedIndex].text); this.disabled = true; }); }
      });
      getLoadUnloadDataRows().forEach(function(row) {
        var checkbox = row.querySelector('input[type="checkbox"][name^="check"]');
        if (checkbox) {
          checkbox.addEventListener('change', updateLoadUnloadCheckAllState);
        }
      });
      updateLoadUnloadCheckAllState();
    });
  

  STS.action('showImage', function (e, el) { show_image(el.getAttribute('data-car-id') || '', el.getAttribute('data-marks') || ''); });
  STS.action('prepareLoadUnloadSubmit', function () { return prepareLoadUnloadSubmit(); });
  STS.action('reloadPage', function () { location.reload(); });
  STS.action('checkall', function () { checkall(); });
})();
