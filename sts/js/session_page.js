/* session.php: submit the session picker when it changes. */
(function () {
  'use strict';
  var sessionSelect = document.getElementById('session-select');
  if (sessionSelect) {
    sessionSelect.addEventListener('change', function () {
      var form = document.getElementById('session-select-form');
      if (form) {
        form.submit();
      }
    });
  }
})();
