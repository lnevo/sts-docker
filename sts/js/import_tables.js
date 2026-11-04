// import_tables.php: "Replace" erases the table, so confirm before importing.
(function () {
  'use strict';
  STS.action('importConfirm', function () {
    var r = document.querySelector('input[name=add_replace]:checked');
    if (r && r.value === 'replace') {
      return confirm('Replace will erase all existing data in the selected table. There is no undo. Continue?');
    }
    return true;
  });
})();
