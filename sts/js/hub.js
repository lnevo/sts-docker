(function () {
  'use strict';

  function fetchText(url, done) {
    var xmlhttp = new XMLHttpRequest();
    xmlhttp.onreadystatechange = function () {
      if (this.readyState == 4 && this.status == 200) {
        done(this.responseText);
      }
    };
    xmlhttp.open('GET', url, true);
    xmlhttp.send();
  }

  // Main menu (index.html) and the older index-t.html share this file.
  function get_session_nbr() {
    var os = document.getElementById('os_number');
    var sn = document.getElementById('session_number');
    if (!os && !sn) {
      return;
    }
    fetchText('get_session_nbr.php', function (text) {
      if (os) {
        os.innerText = text;
      }
      if (sn) {
        sn.innerText = ' Operating Session ' + text;
      }
    });
  }

  function get_railroad_name() {
    var target = document.getElementById('railroad_name');
    if (!target) {
      return;
    }
    fetchText('get_railroad_name.php', function (text) {
      var name = text.trim();
      if (name) {
        target.innerText = name;
      }
    });
  }

  window.STS.ready(function () {
    get_session_nbr();
    get_railroad_name();
  });
})();
