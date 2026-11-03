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
})();
