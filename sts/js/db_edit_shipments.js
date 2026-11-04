// db_edit_shipments.php: switching a shipment off adds one million to its last ship date.
(function () {
  'use strict';

  function oldDate() {
    return parseInt(document.getElementById('old_last_ship_date').innerHTML, 10) || 0;
  }

  STS.action('shipOff', function () {
    var field = document.getElementById('last_ship_date');
    field.value = oldDate() + 1000000;
    field.readOnly = true;
  });

  STS.action('shipOn', function () {
    var field = document.getElementById('last_ship_date');
    var old = oldDate();
    if (old < 1000000) {
      old = old + 1000000;
    }
    field.value = old - 1000000;
    field.readOnly = false;
  });
})();
