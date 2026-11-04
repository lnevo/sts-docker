// add_remove.php: the "All" checkbox ticks or clears every car row.
(function () {
  'use strict';
  STS.action('checkAll', function (event, el) {
    var rows = document.getElementById('car_table').rows.length - 1;
    for (var i = 0; i < rows; i++) {
      var box = document.getElementById('check' + i);
      if (box) {
        box.checked = el.checked;
      }
    }
  });
})();
