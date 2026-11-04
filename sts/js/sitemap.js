(function () {
  function applySessionLinks(session) {
      const n = parseInt(session, 10);
      if (!n || n < 1) return;
      document.querySelectorAll('[data-session-link]').forEach(function (el) {
        const tpl = el.getAttribute('data-session-link');
        if (tpl) el.setAttribute('href', tpl.replace('{n}', String(n)));
      });
      const label = document.getElementById('session_number');
      if (label) label.textContent = ' — Session ' + n;
    }

    function get_session_nbr() {
      const xmlhttp = new XMLHttpRequest();
      xmlhttp.onreadystatechange = function () {
        if (this.readyState === 4 && this.status === 200) {
          applySessionLinks(this.responseText.trim());
        }
      };
      xmlhttp.open('GET', 'get_session_nbr.php', true);
      xmlhttp.send();
    }

  window.STS.ready(get_session_nbr);
})();
