/* session_index_template.php: session picker, restart and lock confirmations.
 * Confirm texts come from the JSON block #sts-data-session-index. */
(function () {
  'use strict';
  var data = (window.STS && STS.data('sts-data-session-index')) || {};
  var sessionSelect = document.getElementById('session-select');
  if (sessionSelect) {
    sessionSelect.addEventListener('change', function () {
      var form = document.getElementById('session-select-form');
      if (form) {
        form.submit();
      }
    });
  }

  var restartForm = document.getElementById('session-restart-form');
  var confirmBase = data.restartConfirm || '';
  if (restartForm) {
    restartForm.addEventListener('submit', function (ev) {
      var backupInput = restartForm.querySelector('[name="backup"]');
      var backup = (backupInput && backupInput.value) ? String(backupInput.value).trim() : '';
      if (backupInput && backupInput.tagName === 'SELECT' && backup === '') {
        ev.preventDefault();
        alert('Select a backup to restore before restarting.');
        return;
      }
      var msg = confirmBase + (backup ? ('\n\nBackup: ' + backup) : '');
      if (!confirm(msg)) {
        ev.preventDefault();
      }
    });
  }

  var lockForm = document.getElementById('session-lock-form');
  var lockConfirmBase = data.lockConfirm || '';
  if (lockForm) {
    lockForm.addEventListener('submit', function (ev) {
      var backupInput = lockForm.querySelector('[name="backup"]');
      var backup = (backupInput && backupInput.value) ? String(backupInput.value).trim() : '';
      if (backupInput && backupInput.tagName === 'SELECT' && backup === '') {
        ev.preventDefault();
        alert('Select a backup to lock.');
        return;
      }
      var locked = backup ? (backup.endsWith('_locked') ? backup : (backup + '_locked')) : '';
      var msg = lockConfirmBase
        + (backup ? ('\n\nSource: ' + backup) : '')
        + (locked ? ('\nLocked: ' + locked) : '');
      if (!confirm(msg)) {
        ev.preventDefault();
      }
    });
  }
})();
