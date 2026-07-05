function detachStationFilters(tableId, filtersId, mountId) {
  const filters = document.getElementById(filtersId);
  const mount = document.getElementById(mountId);
  const table = document.getElementById(tableId);

  if (table) {
    const filterRow = table.querySelector('tr.table-filter-row');
    if (filterRow) {
      filterRow.remove();
    }
  }

  if (filters && mount && filters.parentElement !== mount) {
    mount.appendChild(filters);
  }

  if (filters) {
    filters.classList.add('d-none');
  }
}

function integrateStationFiltersIntoTable(tableId, filtersId, mountId) {
  const table = document.getElementById(tableId);
  const filters = document.getElementById(filtersId);
  if (!table || !filters) {
    return;
  }

  detachStationFilters(tableId, filtersId, mountId);

  const headerRows = Array.from(table.querySelectorAll('tr')).filter(function(row) {
    return row.querySelector('th');
  });
  const headerRow = headerRows[headerRows.length - 1];
  if (!headerRow) {
    return;
  }

  const filterRow = document.createElement('tr');
  filterRow.className = 'table-filter-row noprint';
  const cell = document.createElement('td');
  cell.colSpan = headerRow.cells.length;
  cell.className = 'table-filters-cell';
  filterRow.appendChild(cell);
  headerRow.insertAdjacentElement('afterend', filterRow);
  cell.appendChild(filters);
  filters.classList.remove('d-none');
}

function toggleLocationGroupCheck(headerCheckbox, config) {
  const tableId = config.tableId;
  const rowCheckboxSelector = config.rowCheckboxSelector;
  const onRowChecked = config.onRowChecked || null;
  const table = document.getElementById(tableId);
  if (!table || !headerCheckbox) return;

  const groupKey = headerCheckbox.closest('tr').dataset.groupKey;
  const checked = headerCheckbox.checked;

  table.querySelectorAll('tr.job-car-row:not([hidden])').forEach(function(row) {
    if (row.dataset.locationGroup !== groupKey) return;
    const checkbox = row.querySelector(rowCheckboxSelector);
    if (!checkbox || checkbox.disabled) return;
    checkbox.checked = checked;
    if (checked && onRowChecked) {
      onRowChecked(row);
    }
  });

  if (config.updateGroupHeaders) {
    config.updateGroupHeaders();
  }
  if (config.updateCheckAll) {
    config.updateCheckAll();
  }
}

function updateLocationGroupHeaderStates(tableId, headerClass, rowCheckboxSelector) {
  const table = document.getElementById(tableId);
  if (!table) return;

  table.querySelectorAll('tr.' + headerClass).forEach(function(headerRow) {
    const groupKey = headerRow.dataset.groupKey;
    const groupCheck = headerRow.querySelector('.location-group-check');
    if (!groupCheck) return;

    const checks = Array.from(table.querySelectorAll('tr.job-car-row:not([hidden])'))
      .filter(function(row) { return row.dataset.locationGroup === groupKey; })
      .map(function(row) { return row.querySelector(rowCheckboxSelector); })
      .filter(function(checkbox) { return checkbox && !checkbox.disabled; });

    if (checks.length === 0) {
      groupCheck.checked = false;
      groupCheck.indeterminate = false;
      groupCheck.disabled = true;
      return;
    }

    groupCheck.disabled = false;
    const checkedCount = checks.filter(function(checkbox) { return checkbox.checked; }).length;
    groupCheck.checked = checkedCount === checks.length;
    groupCheck.indeterminate = checkedCount > 0 && checkedCount < checks.length;
  });
}

function updateTableGroupHeaderVisibility(tableId, headerClass) {
  const table = document.getElementById(tableId);
  if (!table) return;

  let groupHeader = null;
  let groupVisibleRows = 0;

  Array.from(table.rows).forEach(function(row) {
    if (row.classList.contains(headerClass)) {
      if (groupHeader) {
        groupHeader.hidden = groupVisibleRows === 0;
      }
      groupHeader = row;
      groupVisibleRows = 0;
      row.hidden = false;
    }
    else if (row.classList.contains('job-car-row') && !row.hidden) {
      groupVisibleRows++;
    }
  });

  if (groupHeader) {
    groupHeader.hidden = groupVisibleRows === 0;
  }
}
