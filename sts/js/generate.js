(function () {
  'use strict';

    function show_auto()
    {
      document.getElementById("automatic").style.display = "block";
      document.getElementById("manual").style.display = "none";
    }
  
    function show_manual()
    {
      document.getElementById("manual").style.display = "block";
      document.getElementById("automatic").style.display = "none";
    }
  
    function confirm_manual_order()
    {
      return confirm('Click OK to order these cars. Click Cancel to go back without ordering.');
    }
  
    // generate some javascript that will hide rows
    // - filter_rows() is called to hide cars that don't have the selected property
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
  
        var table = document.getElementById("ship_tbl");
  
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
      }
    }
  
    function clear_filters()
    {
      document.location.reload();
    }
  
  STS.action('showAuto', function () { show_auto(); });
  STS.action('showManual', function () { show_manual(); });
  STS.action('confirmManualOrder', function () { return confirm_manual_order(); });
  STS.action('clearFilters', function () { clear_filters(); });
  STS.ready(function () {
    var d = STS.data('sts-data-generate');
    if (d && d.showManual) { show_manual(); }
  });

      document.addEventListener("DOMContentLoaded", function() {
        var pendingNextSessionForm = null;
        var nextSessionModalEl = document.getElementById('nextSessionModal');
        var nextSessionModal = nextSessionModalEl ? new bootstrap.Modal(nextSessionModalEl) : null;
        var nextSessionConfirmCheck = document.getElementById('nextSessionConfirmCheck');
        var nextSessionConfirmBtn = document.getElementById('nextSessionConfirmBtn');
        var nextSessionModalMessage = document.getElementById('nextSessionModalMessage');
        var nextSessionConfirmLabel = document.getElementById('nextSessionConfirmLabel');
  
        function openNextSessionConfirm(form) {
          if (!nextSessionModal || !form) {
            return;
          }
  
          pendingNextSessionForm = form;
          var nextSession = form.dataset.nextSession || '';
          nextSessionModalMessage.textContent = 'Are you ready to start session ' + nextSession + '?';
          nextSessionConfirmLabel.textContent = 'Confirm';
          nextSessionConfirmCheck.checked = false;
          nextSessionConfirmBtn.disabled = true;
          nextSessionModal.show();
        }
  
        document.querySelectorAll('.next-session-btn').forEach(function(btn) {
          btn.addEventListener('click', function() {
            openNextSessionConfirm(btn.closest('.next-session-form'));
          });
        });
  
        if (nextSessionConfirmCheck && nextSessionConfirmBtn) {
          nextSessionConfirmCheck.addEventListener('change', function() {
            nextSessionConfirmBtn.disabled = !nextSessionConfirmCheck.checked;
          });
  
          nextSessionConfirmBtn.addEventListener('click', function() {
            if (pendingNextSessionForm && nextSessionConfirmCheck.checked) {
              nextSessionModal.hide();
              pendingNextSessionForm.submit();
            }
          });
        }
  
        document.querySelectorAll("select").forEach(function(el) {
          el.classList.add("form-select", "form-select-sm");
          el.style.removeProperty("width");
        });
        [['commodity_filter',3],['car_code_filter',4],['loading_loc_filter',5],['unloading_loc_filter',6]].forEach(function(f) {
          var el = document.getElementById(f[0]);
          if (el) { el.addEventListener('change', function() { filter_rows(f[1], this.options[this.selectedIndex].text); this.disabled = true; updateSelectAllState(); }); }
        });
  
        var selectAll = document.getElementById('selectAllShipments');
        if (selectAll) {
          selectAll.addEventListener('change', function() {
            document.querySelectorAll('#ship_tbl tbody tr').forEach(function(row) {
              if (row.style.display === 'none') {
                return;
              }
              var cb = row.querySelector('.shipment-select');
              if (cb) {
                cb.checked = selectAll.checked;
              }
            });
          });
  
          document.querySelectorAll('.shipment-select').forEach(function(cb) {
            cb.addEventListener('change', updateSelectAllState);
          });
        }
  
        function updateSelectAllState() {
          var selectAll = document.getElementById('selectAllShipments');
          if (!selectAll) {
            return;
          }
  
          var visible = [];
          document.querySelectorAll('#ship_tbl tbody tr').forEach(function(row) {
            if (row.style.display === 'none') {
              return;
            }
            var cb = row.querySelector('.shipment-select');
            if (cb) {
              visible.push(cb);
            }
          });
  
          if (visible.length === 0) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
            return;
          }
  
          var checkedCount = visible.filter(function(cb) { return cb.checked; }).length;
          selectAll.checked = checkedCount === visible.length;
          selectAll.indeterminate = checkedCount > 0 && checkedCount < visible.length;
        }
      });
    
})();
