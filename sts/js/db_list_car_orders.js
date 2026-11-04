// db_list.php?tbl_name=car_orders: hide-filled checkbox and column filters.
(function () {
  'use strict';

  // rows 0 and 1 of the table are the filter and heading rows
  var FIRST_ROW = 2;

  function hideRows(col) {
    var table = document.getElementById('wb_tbl');
    var hide = document.getElementById('hide_unavail').checked;
    for (var i = FIRST_ROW, row; (row = table.rows[i]); i++) {
      if (row.cells[col].innerText.length > 0) {
        row.style.display = hide ? 'none' : '';
      }
    }
  }

  function filterRows(col, needle) {
    if (needle.length === 0) {
      return;
    }
    // convert drop-down locations (station - location) to table locations (station\nlocation)
    var hyphen = needle.search(' - ');
    if (hyphen >= 0) {
      needle = needle.substr(0, hyphen) + '\n' + needle.substr(hyphen + 3, needle.length);
    }
    var table = document.getElementById('wb_tbl');
    for (var i = FIRST_ROW, row; (row = table.rows[i]); i++) {
      var text = row.cells[col].innerText;
      if (col === 1 && needle === 'A') {
        // Automatic: hide Reposition (E) and Manual (M) orders
        if (text.substr(4, 1) === 'E' || text.substr(4, 1) === 'M') {
          row.style.display = 'none';
        }
      } else if (col === 1 && (needle === 'E' || needle === 'M')) {
        if (text.substr(4, 1) !== needle) {
          row.style.display = 'none';
        }
      } else if (text.substr(text.length - needle.length) !== needle) {
        row.style.display = 'none';
      }
    }
  }

  STS.action('hideRows', function (event, el) {
    hideRows(parseInt(el.getAttribute('data-col'), 10));
  });

  STS.action('listFilter', function (event, el) {
    var select = document.getElementById(el.getAttribute('data-filter'));
    var option = select.options[select.selectedIndex];
    filterRows(parseInt(el.getAttribute('data-col'), 10), el.getAttribute('data-use') === 'value' ? option.value : option.text);
    select.disabled = true;
  });

  STS.action('reloadPage', function () {
    window.location.reload();
  });
})();
