/*
 * Database maintenance pages (backup, restore, remove, reset, restart, wipe,
 * fix_*). Handlers for the data-sts-* attributes those pages declare.
 */
(function () {
  'use strict';

  function target(el) {
    return document.getElementById(el.getAttribute('data-target'));
  }

  // A checkbox gates its target: unchecking it disables the target (and, for a
  // checkbox, unchecks it and closes whatever that one gates).
  function gate(el) {
    var t = target(el);
    if (!t) {
      return;
    }
    t.disabled = !el.checked;
    if (!el.checked && t.type === 'checkbox') {
      t.checked = false;
      if (t.getAttribute('data-target')) {
        gate(t);
      }
    }
  }

  STS.action('maintGate', function (event, el) {
    gate(el);
  });

  STS.action('maintEnable', function (event, el) {
    var t = target(el);
    if (t) {
      t.disabled = false;
    }
  });

  STS.action('maintBusy', function () {
    var msg = document.getElementById('msg1');
    if (msg) {
      msg.textContent = 'Processing...';
    }
  });

  // The "All" checkbox on the fix_* pages: rows are check0, check1, ...
  STS.action('maintCheckAll', function (event, el) {
    var boxes = document.querySelectorAll('input[type="checkbox"]');
    for (var i = 0; i < boxes.length; i++) {
      if (/^check\d+$/.test(boxes[i].id)) {
        boxes[i].checked = el.checked;
      }
    }
  });

  // Backups are served only through the authenticated API route, so fetch it
  // with the sign-in cookie and save the result; without a session say so.
  STS.action('maintDownload', function (event, el) {
    var note = document.getElementById('download-msg');
    function say(text) {
      if (note) {
        note.textContent = text;
        note.classList.toggle('d-none', !text);
      }
    }
    say('');
    if (el.getAttribute('data-signed-in') === '0') {
      say('Sign in on API Access (Database Maintenance) to download');
      return false;
    }
    fetch(el.getAttribute('href'), { credentials: 'same-origin' }).then(function (r) {
      if (r.status === 401 || r.status === 403) {
        say('Sign in on API Access (Database Maintenance) to download');
        return null;
      }
      if (!r.ok) {
        say('Download failed (' + r.status + ').');
        return null;
      }
      return r.blob();
    }).then(function (blob) {
      if (!blob) {
        return;
      }
      var a = document.createElement('a');
      var url = URL.createObjectURL(blob);
      a.href = url;
      a.download = decodeURIComponent(el.getAttribute('href').split('/').slice(-2)[0]);
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }).catch(function () {
      say('Download failed.');
    });
    return false;
  });
}());
