// import_img.php / remove_img.php popups: reload the opener (Edit Car) when the popup closes.
(function () {
  'use strict';
  window.addEventListener('unload', function () {
    if (window.opener && !window.opener.closed) {
      window.opener.location.reload();
    }
  });
})();
