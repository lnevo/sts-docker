// db_list.php?tbl_name=shipments: add-row / filter radios, range checks, column filters.
(function () {
  'use strict';

  var ENTRY = ['code', 'description', 'consignment', 'car_code', 'loading_location', 'unloading_location',
    'last_ship_date', 'min_interval', 'max_interval', 'min_amount', 'max_amount', 'special_instructions',
    'remarks', 'min_load_time', 'max_load_time', 'min_unload_time', 'max_unload_time'];
  var FILTERS = ['commodity_filter', 'car_code_filter', 'loading_location_filter', 'unloading_location_filter'];
  // the first four rows of the table are entry and filter rows
  var FIRST_ROW = 6;

  function setDisabled(ids, value) {
    ids.forEach(function (id) {
      var el = document.getElementById(id);
      if (el) {
        el.disabled = value;
      }
    });
  }

  // Add New Shipment radio: enable the entry boxes, disable the filters.
  function addShipments() {
    setDisabled(['update_btn'], false);
    setDisabled(ENTRY, false);
    setDisabled(FILTERS, true);
  }

  // Filters radio: disable the entry boxes, enable the filters.
  function filterShipments() {
    setDisabled(['update_btn'], true);
    setDisabled(ENTRY, true);
    setDisabled(FILTERS, false);
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
    var table = document.getElementById('ship_tbl');
    for (var i = FIRST_ROW, row; (row = table.rows[i]); i++) {
      var text = row.cells[col].innerText;
      if (text.substr(text.length - needle.length) !== needle) {
        row.style.display = 'none';
      }
    }
  }

  // Minimum must not exceed maximum (interval and amount pairs).
  var PAIRS = {
    min_interval: ['max_interval', 'Minimum interval must be less than or equal to Maximum interval', true],
    max_interval: ['min_interval', 'Minimum interval must be less than or equal to Maximum interval', false],
    min_amount: ['max_amount', 'Minimum amount must be less than or equal to Maximum amount', true],
    max_amount: ['min_amount', 'Minimum amount must be less than or equal to Maximum amount', false]
  };

  STS.action('checkRange', function (event, el) {
    var pair = PAIRS[el.id];
    var other = pair && document.getElementById(pair[0]);
    if (!other || el.value.trim().length === 0 || other.value.trim().length === 0) {
      return;
    }
    // string comparison, as before
    if (pair[2] ? el.value > other.value : el.value < other.value) {
      window.alert(pair[1]);
    }
  });

  STS.action('addShipments', addShipments);
  STS.action('filterShipments', filterShipments);

  STS.action('listFilter', function (event, el) {
    var select = document.getElementById(el.getAttribute('data-filter'));
    filterRows(parseInt(el.getAttribute('data-col'), 10), select.options[select.selectedIndex].text);
    select.disabled = true;
  });

  // location.replace rather than reload: reload resends the POST and could insert a duplicate
  STS.action('clearFilters', function () {
    window.location.replace('db_list.php?tbl_name=shipments');
  });

  // entry boxes start disabled until the Add New Shipment radio is chosen
  STS.ready(function () {
    setDisabled(ENTRY, true);
  });
})();
