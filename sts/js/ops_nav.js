(function () {
  'use strict';
  var script = document.currentScript;
  var bar = script && script.previousElementSibling;
  var el = bar && bar.querySelector('.ops-session-nbr');
  if (!el) {
    return;
  }
  fetch('get_session_nbr.php', { cache: 'no-cache' })
    .then(function (r) { return r.text(); })
    .then(function (t) { el.textContent = t.trim() || '?'; })
    .catch(function () { el.textContent = '?'; });
})();
