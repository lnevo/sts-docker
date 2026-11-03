/* Behaviour for the switch list report (display_switchlist.php). The report
   fragment is injected with innerHTML, so its scripts cannot travel with it;
   the functions its inline handlers call live here as globals. */

function activate_button() {
  var btn = document.getElementById('display_btn');
  if (btn) btn.disabled = false;
}

function stsSwitchToggle(checkboxId, targetId, shown) {
  var box = document.getElementById(checkboxId);
  var target = document.getElementById(targetId);
  if (box && target) target.style.display = box.checked ? shown : 'none';
}

function toggle_half_sheet() {
  stsSwitchToggle('half_sheet_checkbox', 'half_sheet_job_instructions', 'table-cell');
}

function toggle_full_sheet() {
  stsSwitchToggle('full_sheet_checkbox', 'full_sheet_job_instructions', 'block');
}

function toggle_dot_matrix() {
  stsSwitchToggle('dot_matrix_checkbox', 'dot_matrix_job_instructions', 'block');
}

function toggle_workorder() {
  stsSwitchToggle('workorder_checkbox', 'workorder_job_instructions', 'block');
}

function move_row(cell, move) {
  // incoming cell is the one containing the up or down arrow image
  var row_num = cell.parentElement.rowIndex;

  // get the collection of rows in the table
  var rows = document.getElementById('consist').rows;

  // make sure that we do not go above the top or below the bottom of the table
  if ((move == 1 && row_num < rows.length - 1) || (move == -1 && row_num > 1)) {
    // swap the rows
    var old_row = rows[row_num].innerHTML;
    var new_row = rows[row_num + move].innerHTML;
    rows[row_num].innerHTML = new_row;
    rows[row_num + move].innerHTML = old_row;
  }
}

function find_car(target, starting_row) {
  // find the row containing the specified reporting marks
  // and insert a new line before that location
  var table_rows = document.getElementById('consist').getElementsByTagName('tr');
  for (var i = starting_row; i < table_rows.length; i++) {
    var table_cells = table_rows[i].getElementsByTagName('td');
    if (table_cells[0].innerText == target) {
      insert_blank_row(i);
      return 0;
    }
  }
}

function insert_blank_row(where) {
  // insert a blank row at the location specified by "where": one cell on the
  // left with up and down arrows so it can be moved, then a cell holding the
  // comment
  var table = document.getElementById('consist');
  var blank_row = table.insertRow(where);
  var blank_td_1 = blank_row.insertCell(0);
  blank_td_1.innerHTML = "<img src='./ImageStore/DB_Images/graphics/up_arrow.png' onclick='move_row(this.parentElement, -1);' alt='UP'/>" +
    " <br /><br />" + " <img src='./ImageStore/DB_Images/graphics/dn_arrow.png' onclick='move_row(this.parentElement, 1);' alt='DN'/>";
  blank_td_1.style = 'text-align: center; vertical-align: middle;';
  var blank_td_2 = blank_row.insertCell(1);
  blank_td_2.colSpan = 7;
  blank_td_2.innerHTML = document.getElementById('comment').value;
}

/* Called by report_page.js after each report is injected. */
function stsSwitchlistLoaded(content) {
  // the x2010 form prints on A5 landscape; @page cannot be scoped to an
  // element, so the rule is added only while an x2010 report is shown
  var old = document.getElementById('sw-page-style');
  if (old) old.remove();
  if (content.querySelector('.sw-fmt-x2010')) {
    var style = document.createElement('style');
    style.id = 'sw-page-style';
    style.media = 'print';
    style.textContent = '@page { size: A5 landscape; }';
    document.head.appendChild(style);
  }

  // x2010: a tick box on every row (saved in localStorage) strikes it out
  var tables = content.querySelectorAll('.data-table');
  var clearButtonInserted = false;
  tables.forEach(function (table, tableIndex) {
    table.querySelectorAll('tbody tr').forEach(function (row, rowIndex) {
      var firstCell = row.querySelector('td:first-child');
      if (!firstCell) return;
      var checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.style.marginRight = '5px';

      var rowId = 'row-' + tableIndex + '-' + rowIndex;
      row.setAttribute('data-row-id', rowId);
      try {
        if (localStorage.getItem(rowId) === 'striked') {
          row.classList.add('strikethrough');
          checkbox.checked = true;
        }
      } catch (e) { /* storage unavailable */ }

      checkbox.addEventListener('change', function () {
        row.classList.toggle('strikethrough', this.checked);
        try {
          if (this.checked) localStorage.setItem(rowId, 'striked');
          else localStorage.removeItem(rowId);
        } catch (e) { /* storage unavailable */ }
      });
      firstCell.prepend(checkbox);
    });

    // one "Clear Strikes" button above the first consist table
    if (!clearButtonInserted) {
      var clearButton = document.createElement('button');
      clearButton.type = 'button';
      clearButton.textContent = 'Clear Strikes';
      clearButton.className = 'clear-strikes-button noprint';
      clearButton.addEventListener('click', function () {
        content.querySelectorAll('tr[data-row-id]').forEach(function (row) {
          row.classList.remove('strikethrough');
          var cb = row.querySelector("input[type='checkbox']");
          if (cb) cb.checked = false;
          try { localStorage.removeItem(row.getAttribute('data-row-id')); } catch (e) { /* ignore */ }
        });
      });
      table.parentNode.insertBefore(clearButton, table);
      clearButtonInserted = true;
    }
  });
}
