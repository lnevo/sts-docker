/* "Check All" box of the shipment/car-order selection tables in
   printable_ccwaybill.php and printable_ccwaybill2.php. The limit comes from
   a data block so the form stays under PHP's max_input_vars. */
(function () {
  'use strict';

  STS.action('checkAll', function (event, el) {
    var data = STS.data('sts-data-ccwaybill') || {};
    var maxRows = data.maxRows || 0;
    var table = document.getElementById('shipment_list') || document.getElementById('car_order_list');
    if (!table) return;
    var rowCount = table.rows.length - 1;
    if (el.checked && rowCount > maxRows) {
      rowCount = maxRows;
    }
    for (var i = 0; i < rowCount; i++) {
      var box = document.getElementById('check' + i);
      if (box) box.checked = el.checked;
    }
  });
})();
