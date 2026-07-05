(function () {
  var el = document.getElementById('operatingSessionNumber');
  if (!el) {
    return;
  }

  fetch('get_session_nbr.php')
    .then(function (response) {
      if (!response.ok) {
        throw new Error('Unable to load session number');
      }
      return response.text();
    })
    .then(function (sessionNumber) {
      el.textContent = sessionNumber.trim();
    })
    .catch(function () {
      el.textContent = '0';
    });
})();
