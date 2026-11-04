/* Shared by the report pages: the rolling-stock photo pop-up (a report row
   declares data-sts-click="showImage" data-car-id="12" data-marks="ABC 1") and
   the "All" entry the station/car-code drop-downs get at position 1. */
(function () {
  'use strict';

  function imageExists(url) {
    var http = new XMLHttpRequest();
    http.open('HEAD', url, false);
    http.send();
    return http.status != 404;
  }

  function showImage(carId, marks) {
    if (!carId) {
      alert('No photo available for ' + marks);
      return;
    }
    var upperRightX = window.screenX + window.parent.outerWidth;
    var upperRightY = window.screenY;
    var time = new Date().getTime();
    var win = window.open('', 'image_window', 'width=660,height=500,left=' + (upperRightX - 700) + ',top=' + (upperRightY + 30));
    var base = './ImageStore/DB_Images/RollingStock/' + carId;
    var link1 = base + '.jpg?' + time;
    var link2 = base + 'b.jpg?' + time;
    var image1 = imageExists(link1);
    var image2 = imageExists(link2);
    var text = '<html><head><title></title></head><body>';
    text += '<p style="font: normal 20px Verdana, Arial, sans-serif;">' +
      (image1 && image2 ? 'Two Images Available' : 'One Image Available') + '</p>';
    if (image1) text += '<img src="' + link1 + '" style="width:100%;height:auto;">';
    if (image2) text += '<img src="' + link2 + '" style="width:100%;height:auto;">';
    text += '</body></html>';
    win.document.write(text);
    win.document.title = marks;
  }

  STS.action('showImage', function (event, el) {
    showImage(el.getAttribute('data-car-id') || '', el.getAttribute('data-marks') || '');
  });

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
