/* Shared by the report pages: the "All" entry the station/car-code drop-downs get at position 1. */
(function () {
  'use strict';

  // Adds an "All" option (value 0) after the blank first entry of a select.
  window.stsAddAllOption = function (selectId) {
    var list = document.getElementById(selectId);
    if (!list) return;
    var option = document.createElement('option');
    option.value = '0';
    option.text = 'All';
    list.add(option, list[1]);
  };

  window.stsEnableDisplay = function () {
    var btn = document.getElementById('display_btn');
    if (btn) btn.disabled = false;
  };
})();
