/* Shared client for the single-file report pages. The report is fetched from
   <endpoint>?generate_report=1&... and the HTML fragment is injected into
   #report-content. Opening the page with the same query string the old
   printable_* page took (display_btn=...&field=...) fills the form and runs
   the report straight away, so old links and bookmarks keep working. */
(function () {
  window.stsReportInit = function (opt) {
    var form = document.getElementById(opt.formId);
    var card = document.getElementById('report-card');
    var content = document.getElementById('report-content');
    if (!form || !card || !content) return;

    function run(params) {
      content.innerHTML = '<p class="text-muted">Working...</p>';
      card.hidden = false;
      fetch(opt.endpoint + '?generate_report=1&' + params)
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.text();
        })
        .then(function (html) {
          content.innerHTML = html;
          if (opt.onLoaded) opt.onLoaded(content);
          card.scrollIntoView({ behavior: 'smooth' });
        })
        .catch(function (err) {
          content.innerHTML = '';
          card.hidden = true;
          alert('Error loading report: ' + err);
        });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var p = new URLSearchParams(new FormData(form));
      if (!p.has('display_btn')) p.set('display_btn', '1');
      run(p.toString());
    });

    var q = new URLSearchParams(window.location.search);
    if (q.has('display_btn')) {
      q.forEach(function (value, key) {
        var els = form.elements[key];
        if (!els) return;
        if (els.length && els[0].type === 'radio') {
          Array.prototype.forEach.call(els, function (r) {
            r.checked = r.value === value;
          });
        } else if (els.tagName === 'SELECT' || els.tagName === 'INPUT') {
          els.value = value;
        }
      });
      if (opt.onPrefill) opt.onPrefill(form);
      run(q.toString());
    }

    var modify = document.getElementById('report-modify');
    if (modify) {
      modify.addEventListener('click', function () {
        form.scrollIntoView({ behavior: 'smooth' });
      });
    }
  };

  // A form that declares data-report-endpoint is wired up without page code:
  //   data-all-select="id"   adds an "All" entry to that drop-down
  //   data-enable-display    the first drop-down's change (or a prefill) enables #display_btn
  STS.ready(function () {
    var form = document.querySelector('form[data-report-endpoint]');
    if (!form) return;
    var allSelect = form.getAttribute('data-all-select');
    if (allSelect && window.stsAddAllOption) window.stsAddAllOption(allSelect);
    var enable = form.hasAttribute('data-enable-display');
    if (enable) {
      var sel = form.querySelector('select');
      if (sel) sel.addEventListener('change', window.stsEnableDisplay);
    }
    window.stsReportInit({
      formId: form.id,
      endpoint: form.getAttribute('data-report-endpoint'),
      onPrefill: enable ? window.stsEnableDisplay : null
    });
  });
})();
