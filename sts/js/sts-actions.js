/*
 * STS action delegation. Pages run under a strict Content-Security-Policy
 * (script-src 'self'), so inline <script> code, on*= attributes and javascript:
 * URLs do not run. Markup declares behaviour with data attributes instead, and
 * page scripts (sts/js/<page>.js) register the named handlers:
 *
 *   <button data-sts-click="saveRow" data-row="12">Save</button>
 *   STS.action('saveRow', function (event, el) { ... return false to cancel ... });
 *
 * Attributes (several names may be space-separated; they run in order):
 *   data-sts-click, data-sts-change, data-sts-input, data-sts-submit,
 *   data-sts-keyup, data-sts-keydown, data-sts-dblclick,
 *   data-sts-focus, data-sts-blur (focusin/focusout), data-sts-mouseover,
 *   data-sts-mouseout.
 * Generic behaviours that need no page script:
 *   data-sts-confirm="Message"  ask before the click/submit; cancel stops it
 *   data-sts-print              window.print()
 *   data-sts-nav="url"          navigate to url
 *   data-sts-back               history.back()
 *   data-sts-close              window.close()
 *   data-sts-autofocus          focus this element on load
 *   data-sts-autosubmit         submit the element's form on change
 * Server data for scripts: <script type="application/json" id="sts-data-x">{...}</script>
 * (a data block is not executed, so CSP allows it) read with STS.data('sts-data-x').
 * Handlers also work for content inserted later (AJAX fragments), because the
 * listeners sit on document.
 */
(function () {
  'use strict';

  var STS = (window.STS = window.STS || {});
  var handlers = STS._handlers || (STS._handlers = {});

  STS.action = function (name, fn) {
    handlers[name] = fn;
  };

  STS.ready = function (fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  };

  STS.data = function (id) {
    var el = document.getElementById(id);
    if (!el) {
      return null;
    }
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return null;
    }
  };

  function closestWith(target, attr) {
    var el = target && target.nodeType === 1 ? target : target && target.parentElement;
    return el && el.closest ? el.closest('[' + attr + ']') : null;
  }

  // Returns false when a handler cancelled the event.
  function run(attr, event) {
    var el = closestWith(event.target, attr);
    if (!el) {
      return true;
    }
    var names = (el.getAttribute(attr) || '').split(/\s+/);
    for (var i = 0; i < names.length; i++) {
      var name = names[i];
      if (!name) {
        continue;
      }
      var fn = handlers[name];
      if (typeof fn !== 'function') {
        if (window.console) {
          console.warn('sts-actions: no handler registered for "' + name + '"');
        }
        continue;
      }
      if (fn.call(el, event, el) === false) {
        event.preventDefault();
        event.stopPropagation();
        return false;
      }
    }
    return true;
  }

  function confirmed(event) {
    var el = closestWith(event.target, 'data-sts-confirm');
    if (!el) {
      return true;
    }
    // A submit is confirmed by its form's attribute only when the submitter has none.
    if (event.type === 'submit' && el.tagName !== 'FORM') {
      return true;
    }
    if (!window.confirm(el.getAttribute('data-sts-confirm'))) {
      event.preventDefault();
      event.stopPropagation();
      return false;
    }
    return true;
  }

  document.addEventListener('click', function (event) {
    var target = closestWith(event.target, 'data-sts-confirm');
    if (target && target.tagName !== 'FORM' && !confirmed(event)) {
      return;
    }
    if (!run('data-sts-click', event)) {
      return;
    }
    var el = closestWith(event.target, 'data-sts-print');
    if (el) {
      event.preventDefault();
      window.print();
      return;
    }
    el = closestWith(event.target, 'data-sts-nav');
    if (el) {
      event.preventDefault();
      window.location.href = el.getAttribute('data-sts-nav');
      return;
    }
    if (closestWith(event.target, 'data-sts-back')) {
      event.preventDefault();
      window.history.back();
      return;
    }
    if (closestWith(event.target, 'data-sts-close')) {
      event.preventDefault();
      window.close();
    }
  });

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (form && form.hasAttribute && form.hasAttribute('data-sts-confirm')) {
      if (!window.confirm(form.getAttribute('data-sts-confirm'))) {
        event.preventDefault();
        return;
      }
    }
    run('data-sts-submit', event);
  });

  document.addEventListener('change', function (event) {
    if (!run('data-sts-change', event)) {
      return;
    }
    var el = closestWith(event.target, 'data-sts-autosubmit');
    if (el && el.form) {
      if (typeof el.form.requestSubmit === 'function') {
        el.form.requestSubmit();
      } else {
        el.form.submit();
      }
    }
  });

  var simple = {
    input: 'data-sts-input',
    keyup: 'data-sts-keyup',
    keydown: 'data-sts-keydown',
    dblclick: 'data-sts-dblclick',
    focusin: 'data-sts-focus',
    focusout: 'data-sts-blur',
    mouseover: 'data-sts-mouseover',
    mouseout: 'data-sts-mouseout'
  };
  Object.keys(simple).forEach(function (type) {
    document.addEventListener(type, function (event) {
      run(simple[type], event);
    });
  });

  STS.ready(function () {
    var el = document.querySelector('[data-sts-autofocus]');
    if (el && typeof el.focus === 'function') {
      el.focus();
    }
  });
})();
