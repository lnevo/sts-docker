/*
 * Rollingstock photo popup. Markup:
 *   <td data-sts-click="showImage" data-car-id="12" data-marks="ABC 123">
 * An empty data-car-id means the car has no photo.
 */
(function () {
  'use strict';

  function imageExists(url) {
    var http = new XMLHttpRequest();
    http.open('HEAD', url, false);
    http.send();
    return http.status != 404;
  }

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function showImage(carId, marks) {
    if (!carId || carId.length === 0) {
      alert('No photo available for ' + marks);
      return;
    }
    var upperRightX = window.screenX + window.parent.outerWidth;
    var upperRightY = window.screenY;
    var left = upperRightX - 700;
    var top = upperRightY + 30;
    var time = new Date().getTime();
    var imgWindow = window.open('', 'image_window', 'width=660,height=500,left=' + left + ',top=' + top);
    var base = './ImageStore/DB_Images/RollingStock/' + carId;
    var link1 = base + '.jpg?' + time;
    var link2 = base + 'b.jpg?' + time;
    var image1 = imageExists(link1);
    var image2 = imageExists(link2);
    var text = '<html><head><title>' + esc(marks) + '</title></head><body>';
    text += '<p style="font: normal 20px Verdana, Arial, sans-serif;">' +
      (image1 && image2 ? 'Two Images Available' : 'One Image Available') + '</p>';
    if (image1) {
      text += '<img src="' + link1 + '" style="width:100%;height:auto;">';
    }
    if (image2) {
      text += '<img src="' + link2 + '" style="width:100%;height:auto;">';
    }
    text += '</body></html>';
    imgWindow.document.write(text);
  }

  window.show_image = showImage;

  if (window.STS && STS.action) {
    STS.action('showImage', function (event, el) {
      showImage(el.getAttribute('data-car-id') || '', el.getAttribute('data-marks') || '');
    });
  }
})();
