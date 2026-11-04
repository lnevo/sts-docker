/* Behaviour for the switch list report (display_switchlist.php). The report
   fragment is injected with innerHTML, so its scripts cannot travel with it;
   its controls declare data-sts-* attributes and the handlers live here. */

(function () {
  'use strict';

  function activateButton() {
    var btn = document.getElementById('display_btn');
    if (btn) btn.disabled = false;
  }

  STS.action('toggleJobInstructions', function (event, el) {
    var target = document.getElementById(el.getAttribute('data-target'));
    if (target) target.style.display = el.checked ? el.getAttribute('data-shown') : 'none';
  });

  function moveRow(cell, move) {
    // incoming cell is the one containing the up or down arrow image
    var rowNum = cell.parentElement.rowIndex;

    // get the collection of rows in the table
    var rows = document.getElementById('consist').rows;

    // make sure that we do not go above the top or below the bottom of the table
    if ((move == 1 && rowNum < rows.length - 1) || (move == -1 && rowNum > 1)) {
      // swap the rows
      var oldRow = rows[rowNum].innerHTML;
      var newRow = rows[rowNum + move].innerHTML;
      rows[rowNum].innerHTML = newRow;
      rows[rowNum + move].innerHTML = oldRow;
    }
  }

  function insertBlankRow(where) {
    // insert a blank row at the location specified by "where": one cell on the
    // left with up and down arrows so it can be moved, then a cell holding the
    // comment
    var table = document.getElementById('consist');
    var blankRow = table.insertRow(where);
    var cell1 = blankRow.insertCell(0);
    cell1.innerHTML = "<img src='./ImageStore/DB_Images/graphics/up_arrow.png' data-sts-click='moveRow' data-move='-1' alt='UP'/>" +
      " <br /><br />" + " <img src='./ImageStore/DB_Images/graphics/dn_arrow.png' data-sts-click='moveRow' data-move='1' alt='DN'/>";
    cell1.style = 'text-align: center; vertical-align: middle;';
    var cell2 = blankRow.insertCell(1);
    cell2.colSpan = 7;
    cell2.innerHTML = document.getElementById('comment').value;
  }

  function findCar(target, startingRow) {
    // find the row containing the specified reporting marks
    // and insert a new line before that location
    var rows = document.getElementById('consist').getElementsByTagName('tr');
    for (var i = startingRow; i < rows.length; i++) {
      var cells = rows[i].getElementsByTagName('td');
      if (cells[0].innerText == target) {
        insertBlankRow(i);
        return;
      }
    }
  }

  STS.action('moveRow', function (event, el) {
    moveRow(el.parentElement, parseInt(el.getAttribute('data-move'), 10));
  });
  STS.action('insertBlankRow', function (event, el) {
    insertBlankRow(parseInt(el.getAttribute('data-where'), 10));
  });
  STS.action('findCar', function (event, el) {
    findCar(document.getElementById('reporting_marks_list').value, parseInt(el.getAttribute('data-start'), 10));
  });

/* Called by report-page.js after each report is injected. */
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

  STS.ready(function () {
    var job = document.getElementById('job_name');
    if (job) job.addEventListener('change', activateButton);
    stsReportInit({
      formId: 'report-form',
      endpoint: 'display_switchlist.php',
      onPrefill: activateButton,
      onLoaded: stsSwitchlistLoaded
    });
  });
})();
