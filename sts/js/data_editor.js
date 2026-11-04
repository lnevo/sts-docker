/*
 * Shared setup for the data editor pages (db_list.php, db_edit.php and their
 * db_list_*.php / db_edit_*.php parts). PHP prints data blocks
 *   <script type="application/json" class="sts-editor-ops">[ ...ops... ]</script>
 * (sts_editor_ops() in drop_down_list_functions.php) and this script applies them
 * once the page has loaded. Op: {id, props, style, html, appendHtml, focus, remove,
 * addOption: {text, value, index}}.
 */
(function () {
  'use strict';

  function apply(op) {
    var el = document.getElementById(op.id);
    if (!el) {
      return;
    }
    var k;
    if (op.props) {
      for (k in op.props) {
        el[k] = op.props[k];
      }
    }
    if (op.style) {
      for (k in op.style) {
        el.style[k] = op.style[k];
      }
    }
    if (typeof op.html === 'string') {
      el.innerHTML = op.html;
    }
    if (typeof op.appendHtml === 'string') {
      el.innerHTML = el.innerHTML + op.appendHtml;
    }
    if (op.addOption) {
      var option = document.createElement('option');
      option.text = op.addOption.text;
      option.value = op.addOption.value;
      el.add(option, el[op.addOption.index]);
    }
    if (op.remove && el.parentNode) {
      el.parentNode.removeChild(el);
    }
    if (op.focus) {
      el.focus();
    }
  }

  // db_edit.php: the Update / Remove radios change the caption of the submit button.
  STS.action('updateRemoveMode', function (event, el) {
    var b = document.getElementById('update_btn');
    if (!b) {
      return;
    }
    if (el.value === 'remove') {
      b.value = 'REMOVE';
      b.className = 'btn btn-danger btn-sm';
    } else {
      b.value = 'UPDATE';
      b.className = 'btn btn-primary btn-sm';
    }
  });

  STS.ready(function () {
    var blocks = document.querySelectorAll('script.sts-editor-ops');
    for (var i = 0; i < blocks.length; i++) {
      var ops;
      try {
        ops = JSON.parse(blocks[i].textContent);
      } catch (e) {
        continue;
      }
      for (var j = 0; j < ops.length; j++) {
        apply(ops[j]);
      }
    }
  });
})();
