/*
 * Behaviour for generated session output (switch lists, waybills, indexes,
 * station and wheel reports, print-all pages) and for the filters so.php adds
 * when it serves a stored page. Needs sts-actions.js. Generated markup uses
 * data-sts-* attributes; nothing in the output is inline code, so the files also
 * work when opened standalone from backups/session_state/sessions/.
 *
 * so.php filters are switched on by JSON data blocks (id sts-so-<name>):
 * memo, prior {session}, phase {map}, select, selection, swphase.
 */
(function () {
  'use strict';

  var STS = window.STS;
  if (!STS || window.stsSessionOutputLoaded) {
    return;
  }
  window.stsSessionOutputLoaded = true;

  // Cursor / VS Code Simple Browser (Electron) can crash the window on print().
  window.stsSafePrint = function () {
    try {
      var ua = navigator.userAgent || '';
      if (/Electron|Cursor|VSCode|Code\/\d|Simple Browser/i.test(ua)
        || typeof window.acquireVsCodeApi === 'function') {
        alert('Printing from Cursor’s preview can close the window. '
          + 'Copy this page’s URL into Chrome or Safari, then Print.');
        return;
      }
      window.print();
    } catch (e) {
      alert('Print failed — open this page in an external browser.');
    }
  };

  // ---- actions -----------------------------------------------------------

  STS.action('sessPrint', function () { window.stsSafePrint(); });

  // <select data-sts-change="sessGoValue">: the option value is a URL.
  STS.action('sessGoValue', function (e, el) {
    if (el.value) { window.location.href = el.value; }
  });

  // <select data-sts-change="sessGoSo" data-so-prefix="session_3/foo_" data-so-suffix=".html">
  STS.action('sessGoSo', function (e, el) {
    if (el.value) {
      window.location.href = 'so.php?f=' + (el.getAttribute('data-so-prefix') || '')
        + el.value + (el.getAttribute('data-so-suffix') || '');
    }
  });

  STS.action('sessArchivedToggle', function (e, el) {
    if (el.checked) {
      document.cookie = 'sts_browse_archived=1;path=/sts;max-age=31536000';
    } else {
      document.cookie = 'sts_browse_archived=0;path=/sts;max-age=0';
    }
    location.reload();
  });

  // Waybill print-all Train picker (data-session on the select).
  STS.action('sessWbTrainGo', function (e, el) {
    var sid = el.getAttribute('data-session');
    var job = el.value;
    var f = job === '' ? ('session_' + sid + '/waybills/print_all.html')
      : ('session_' + sid + '/waybills/job_' + encodeURIComponent(job) + '.print_all.html');
    window.location.href = '/sts/so.php?f=' + encodeURIComponent(f).replace(/%2F/g, '/');
  });

  // Switch-list print-all Train/Style pickers (data-session on #sw-train-select).
  STS.action('sessSwGo', function () {
    var tr = document.getElementById('sw-train-select');
    var el = document.getElementById('sw-style-select');
    if (!tr || !el) { return; }
    var st = document.getElementById('sw-style-status');
    var sid = tr.getAttribute('data-session');
    var job = tr.value;
    var style = el.value;
    if (style === '') {
      var f = job === '' ? ('session_' + sid + '/print_all.html')
        : ('session_' + sid + '/train_' + encodeURIComponent(job) + '.print_all.html');
      window.location.href = '/sts/so.php?f=' + encodeURIComponent(f).replace(/%2F/g, '/');
      return;
    }
    tr.disabled = true;
    el.disabled = true;
    if (st) { st.textContent = 'Rendering…'; }
    var body = { session: Number(sid), style: style };
    if (job !== '') { body.job = job; }
    fetch('/sts/operational_steps_api.php?action=build_print_all_style', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok || !d.url) { throw new Error(d.error || 'Render failed'); }
        window.location.href = d.url;
      })
      .catch(function (e2) {
        tr.disabled = false;
        el.disabled = false;
        if (st) { st.textContent = String(e2.message || e2); }
      });
  });

  // Per-train switch list page: the Style picker swaps the style suffix in the URL.
  STS.action('sessSwStyleUrl', function (e, el) {
    var re = /_(mobile|half|full|dmp|wo|x2010)\.html/;
    window.location.href = window.location.href.replace(re, '_' + el.value + '.html');
  });

  STS.action('sessMobileInstr', function () {
    var box = document.getElementById('mobile_instructions_checkbox');
    var target = document.getElementById('mobile_job_instructions');
    if (box && target) { target.style.display = box.checked ? 'block' : 'none'; }
  });

  // Hide images that fail to load (replaces onerror=...).
  function hideBrokenImg(img) { img.style.display = 'none'; }
  document.addEventListener('error', function (e) {
    var t = e.target;
    if (t && t.tagName === 'IMG' && t.hasAttribute('data-sts-hide-on-error')) { hideBrokenImg(t); }
  }, true);

  // ---- page modules ------------------------------------------------------

  // Job index page: style links rewrite the phase links.
  function initJobIndex() {
    var list = document.querySelector('.phase-list[data-preferred-style]');
    if (!list) { return; }
    var suffixRe = /_(mobile|half|full|dmp|wo|x2010)\.html$/;
    function applyStyle(style) {
      document.querySelectorAll('.phase-list a').forEach(function (anchor) {
        var href = anchor.getAttribute('href') || '';
        if (href.indexOf('job.php') !== -1) {
          anchor.href = href.replace(/([?&]style=)[^&]*/, '$1' + style);
        } else if (suffixRe.test(href)) {
          anchor.href = anchor.href.replace(suffixRe, '_' + style + '.html');
        }
      });
      document.querySelectorAll('[data-style-link]').forEach(function (a) {
        a.classList.toggle('active', a.getAttribute('data-style-link') === style);
      });
    }
    var params = new URLSearchParams(window.location.search);
    applyStyle(params.get('style') || list.getAttribute('data-preferred-style'));
    document.querySelectorAll('[data-style-link]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        applyStyle(this.getAttribute('data-style-link'));
      });
    });
  }

  function initStationReport() {
    var search = document.getElementById('srp-search');
    if (!search) { return; }
    var station = document.getElementById('srp-station');
    var status = document.getElementById('srp-status');
    var placement = document.getElementById('srp-placement');
    var handling = document.getElementById('srp-handling');
    var clear = document.getElementById('srp-clear');
    var summary = document.getElementById('srp-summary');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.station-card'));

    function apply() {
      var q = (search.value || '').trim().toLowerCase();
      var st = station.value || '';
      var stat = status.value || '';
      var place = placement.value || '';
      var hand = handling.value || '';
      var shownCars = 0, shownStations = 0;

      cards.forEach(function (card) {
        var cardStation = card.getAttribute('data-station');
        var cardPlace = card.getAttribute('data-placement') || '';
        var rows = Array.prototype.slice.call(card.querySelectorAll('tbody tr'));
        var visible = 0;
        rows.forEach(function (row) {
          var ok = true;
          if (q && row.getAttribute('data-search').indexOf(q) === -1) { ok = false; }
          if (ok && stat && row.getAttribute('data-status') !== stat) { ok = false; }
          if (ok && hand && row.getAttribute('data-handling') !== hand) { ok = false; }
          if (ok && place && row.getAttribute('data-placement') !== place) { ok = false; }
          row.classList.toggle('srp-hidden', !ok);
          if (ok) { visible++; }
        });
        var stationHidden = (st && cardStation !== st)
          || (place && cardPlace !== place)
          || visible === 0;
        card.classList.toggle('srp-hidden', stationHidden);
        var countEl = card.querySelector('.count');
        if (countEl) {
          var total = countEl.getAttribute('data-total');
          countEl.textContent = (visible === Number(total))
            ? (total + ' car' + (total === '1' ? '' : 's') + ' · ' + countEl.getAttribute('data-stays') + ' staying')
            : ('showing ' + visible + ' of ' + total);
        }
        if (!stationHidden) { shownStations++; shownCars += visible; }
      });

      var filtered = q || st || stat || place || hand;
      summary.textContent = filtered
        ? ('Showing ' + shownCars + ' car' + (shownCars === 1 ? '' : 's') + ' across ' + shownStations + ' station' + (shownStations === 1 ? '' : 's') + '.')
        : '';
    }

    [search, station, status, placement, handling].forEach(function (el) {
      el.addEventListener('input', apply);
      el.addEventListener('change', apply);
    });
    clear.addEventListener('click', function () {
      search.value = ''; station.value = ''; status.value = ''; placement.value = ''; handling.value = '';
      apply();
    });
    apply();
  }

  function initWheelReport() {
    var train = document.getElementById('wr-train');
    if (!train) { return; }
    var search = document.getElementById('wrp-search');
    var le = document.getElementById('wrp-le');
    var clear = document.getElementById('wrp-clear');
    var summary = document.getElementById('wrp-summary');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.train-card'));

    function apply() {
      var q = (search && search.value || '').trim().toLowerCase();
      var l = (le && le.value) || '';
      var t = train.value || '';
      var shownCars = 0, shownTrains = 0;

      cards.forEach(function (card) {
        var cardJob = card.getAttribute('data-job');
        var rows = Array.prototype.slice.call(card.querySelectorAll('tbody tr'));
        var visible = 0;
        rows.forEach(function (row) {
          var ok = true;
          if (q && row.getAttribute('data-search').indexOf(q) === -1) { ok = false; }
          if (ok && l && row.getAttribute('data-le') !== l) { ok = false; }
          row.classList.toggle('wrp-hidden', !ok);
          if (ok) { visible++; }
        });
        var cardHidden = (t && cardJob !== t) || visible === 0;
        card.classList.toggle('wrp-hidden', cardHidden);
        var countEl = card.querySelector('.count');
        if (countEl) {
          var total = countEl.getAttribute('data-total');
          if (visible !== Number(total)) { countEl.setAttribute('data-filtered', '1'); }
        }
        if (!cardHidden) { shownTrains++; shownCars += visible; }
      });

      var filtered = q || l || t;
      summary.textContent = filtered
        ? ('Showing ' + shownCars + ' car' + (shownCars === 1 ? '' : 's') + ' across ' + shownTrains + ' train' + (shownTrains === 1 ? '' : 's') + '.')
        : '';
    }

    [search, le, train].forEach(function (el) {
      if (!el) { return; }
      el.addEventListener('input', apply);
      el.addEventListener('change', apply);
    });
    if (clear) {
      clear.addEventListener('click', function () {
        if (search) { search.value = ''; }
        if (le) { le.value = ''; }
        train.value = '';
        apply();
      });
    }
    apply();
  }

  // ---- so.php serve-time filters ----------------------------------------

  var WB_NO = /WAYBILL No\.\s*([0-9A-Za-z\-]+)/;

  function soMemo() {
    var wrap = document.querySelector('.waybill-print');
    var cb = document.getElementById('wb-hide-memos');
    if (!wrap || !cb) { return; }
    wrap.querySelectorAll('.waybill-sheet').forEach(function (sheet) {
      var memoTable = null, hasFreight = false;
      sheet.querySelectorAll('h3').forEach(function (h) {
        var t = (h.textContent || '').trim().toUpperCase();
        if (t === 'COMPANY MEMO') {
          var p = h.closest('table');
          while (p && p.parentElement) {
            var up = p.parentElement.closest('table');
            if (!up || !sheet.contains(up)) { break; }
            p = up;
          }
          memoTable = p;
        } else if (t === 'FREIGHT WAYBILL') {
          hasFreight = true;
        }
      });
      if (memoTable) {
        memoTable.classList.add('wb-memo-block');
        sheet.classList.add(hasFreight ? 'wb-has-memo' : 'wb-memo-only');
      }
    });
    var KEY = 'wbHideMemos';
    try { cb.checked = localStorage.getItem(KEY) === '1'; } catch (e) { /* storage blocked */ }
    function apply() {
      wrap.classList.toggle('hide-memos', cb.checked);
      try { localStorage.setItem(KEY, cb.checked ? '1' : '0'); } catch (e) { /* storage blocked */ }
    }
    cb.addEventListener('change', apply);
    apply();
  }

  function soPrior(d) {
    var wrap = document.querySelector('.waybill-print');
    var cb = document.getElementById('wb-this-session-only');
    if (!wrap || !cb) { return; }
    var pageSession = Number(d.session) || 0;
    function originSession(sheet) {
      var t = sheet.textContent || '';
      var m = t.match(/WAYBILL No\.\s*([0-9A-Za-z\-]+)/);
      if (m) {
        var p = m[1].match(/^(\d{1,3})(?=-|$)/);
        if (p) { return parseInt(p[1], 10); }
      }
      m = t.match(/OPERATING SESSION(?: No\.)?\s*:?\s*(\d+)/i);
      return m ? parseInt(m[1], 10) : null;
    }
    var sheets = [].slice.call(wrap.querySelectorAll('.waybill-sheet'));
    var priorCount = 0;
    sheets.forEach(function (s) {
      var o = originSession(s);
      if (o !== null && o < pageSession) { s.classList.add('wb-prior-session'); priorCount++; }
    });
    if (priorCount === 0) {
      var lab = cb.closest('label');
      if (lab) { lab.style.display = 'none'; }
      return;
    }
    var KEY = 'wbThisSessionOnly';
    try { var stored = localStorage.getItem(KEY); cb.checked = (stored === null || stored === '1'); } catch (e) { cb.checked = true; }
    var muted = document.querySelector('main .muted');
    var mutedDefault = muted ? (muted.textContent || '') : '';
    function visibleCount() {
      var n = 0;
      sheets.forEach(function (s) {
        if (s.style.display === 'none') { return; }
        if (wrap.classList.contains('hide-prior-sessions') && s.classList.contains('wb-prior-session')) { return; }
        if (wrap.classList.contains('hide-memos') && s.classList.contains('wb-memo-only')) { return; }
        n++;
      });
      return n;
    }
    function apply() {
      wrap.classList.toggle('hide-prior-sessions', cb.checked);
      try { localStorage.setItem(KEY, cb.checked ? '1' : '0'); } catch (e) { /* storage blocked */ }
      if (muted) {
        if (!cb.checked) {
          muted.textContent = mutedDefault;
        } else {
          var n = visibleCount();
          muted.textContent = n + ' waybill' + (n === 1 ? '' : 's') + ' this session'
            + (priorCount ? (' · ' + priorCount + ' prior hidden') : '')
            + ' · each prints on its own page.';
        }
      }
    }
    cb.addEventListener('change', apply);
    apply();
  }

  function soPhase(d) {
    var sel = document.getElementById('wb-phase-select');
    if (!sel) { return; }
    var map = d.map || {};
    var sheets = [].slice.call(document.querySelectorAll('.waybill-print .waybill-sheet'));
    var wbRaw = new URLSearchParams(location.search).get('wb');
    var wbSet = null;
    if (wbRaw) { wbSet = {}; wbRaw.split(',').forEach(function (x) { x = x.trim(); if (x) { wbSet[x] = 1; } }); }
    var muted = document.querySelector('main .muted');
    var mutedDefault = muted ? (muted.textContent || '') : '';
    function wbNum(s) { var m = (s.textContent || '').match(WB_NO); return m ? m[1] : ''; }
    sheets.forEach(function (s) {
      var n = wbNum(s);
      if (n && map[n] != null) { s.setAttribute('data-wb-phase', String(map[n])); }
    });
    function apply() {
      var v = sel.value;
      var vis = [];
      sheets.forEach(function (s) {
        var n = wbNum(s);
        var phaseOk = (v === '' || s.getAttribute('data-wb-phase') === v);
        var wbOk = !wbSet || !n || wbSet[n];
        var show = phaseOk && wbOk;
        s.style.display = show ? '' : 'none';
        if (show) { vis.push(s); }
      });
      vis.forEach(function (s, i) {
        var last = (i === vis.length - 1);
        s.style.pageBreakAfter = last ? 'auto' : 'always';
        s.style.breakAfter = last ? 'auto' : 'page';
      });
      if (muted) {
        if (v === '') {
          muted.textContent = mutedDefault;
        } else {
          muted.textContent = vis.length + ' waybill' + (vis.length === 1 ? '' : 's')
            + ' · each prints on its own page.';
        }
      }
    }
    sel.addEventListener('change', apply);
  }

  function soSelect() {
    var picks = [].slice.call(document.querySelectorAll('.wb-pick'));
    if (!picks.length) { return; }
    var printAll = null;
    [].slice.call(document.querySelectorAll('a')).forEach(function (a) {
      if (!printAll && /print all waybills/i.test(a.textContent || '')) { printAll = a; }
    });
    var btn = document.getElementById('wb-print-selected');
    var selAll = document.getElementById('wb-select-all');
    var clr = document.getElementById('wb-clear');
    var phaseSel = document.getElementById('wb-phase-select');
    var priorCb = document.getElementById('wb-this-session-only');
    var KEY = 'wbThisSessionOnly';
    if (priorCb) {
      try { var st = localStorage.getItem(KEY); priorCb.checked = (st === null || st === '1'); } catch (e) { priorCb.checked = true; }
    }
    function rowOf(c) { return c.closest ? c.closest('li') : c.parentNode.parentNode; }
    function visible(c) { var r = rowOf(c); return !r || r.style.display !== 'none'; }
    function sel() {
      return picks.filter(function (c) { return c.checked && visible(c); }).map(function (c) { return c.value; });
    }
    function upd() {
      var n = sel().length;
      if (btn) {
        btn.disabled = n === 0;
        btn.innerHTML = '<i class="bi bi-printer"></i> Print selected (' + n + ')';
      }
    }
    function applyFilters() {
      var phaseV = phaseSel ? phaseSel.value : '';
      var hidePrior = !!(priorCb && priorCb.checked);
      picks.forEach(function (c) {
        var r = rowOf(c);
        if (!r) { return; }
        var ph = (r.getAttribute('data-wb-phase') || '').split(/\s+/);
        var phaseOk = (phaseV === '' || ph.indexOf(phaseV) >= 0);
        var priorOk = !hidePrior || r.getAttribute('data-wb-prior') !== '1';
        var show = phaseOk && priorOk;
        r.style.display = show ? '' : 'none';
        if (!show && c.checked) { c.checked = false; }
      });
      if (priorCb) {
        try { localStorage.setItem(KEY, priorCb.checked ? '1' : '0'); } catch (e) { /* storage blocked */ }
      }
      upd();
    }
    picks.forEach(function (c) { c.addEventListener('change', upd); });
    if (selAll) {
      selAll.addEventListener('click', function (e) {
        e.preventDefault();
        picks.forEach(function (c) { if (visible(c)) { c.checked = true; } });
        upd();
      });
    }
    if (clr) {
      clr.addEventListener('click', function (e) {
        e.preventDefault();
        picks.forEach(function (c) { c.checked = false; });
        upd();
      });
    }
    if (phaseSel) { phaseSel.addEventListener('change', applyFilters); }
    if (priorCb) { priorCb.addEventListener('change', applyFilters); }
    if (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var s = sel();
        if (!s.length || !printAll) { return; }
        var base = printAll.getAttribute('href');
        window.location.href = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'wb=' + encodeURIComponent(s.join(','));
      });
    }
    applyFilters();
  }

  function soSelection() {
    var params = new URLSearchParams(location.search);
    var raw = params.get('wb');
    if (!raw) { return; }
    var wrap = document.querySelector('.waybill-print');
    if (!wrap) { return; }
    var set = {};
    raw.split(',').forEach(function (x) { x = x.trim(); if (x) { set[x] = 1; } });
    var sheets = [].slice.call(wrap.querySelectorAll('.waybill-sheet'));
    var shown = {}, vis = [];
    sheets.forEach(function (s) {
      var m = (s.textContent || '').match(WB_NO);
      var n = m ? m[1] : null;
      if (n && set[n]) { s.style.display = ''; shown[n] = 1; vis.push(s); } else { s.style.display = 'none'; }
    });
    vis.forEach(function (s, i) {
      var last = (i === vis.length - 1);
      s.style.pageBreakAfter = last ? 'auto' : 'always';
      s.style.breakAfter = last ? 'auto' : 'page';
    });
    var nsel = Object.keys(shown).length;
    params.delete('wb');
    var showAll = location.pathname + (params.toString() ? ('?' + params.toString()) : '');
    var banner = document.createElement('div');
    banner.className = 'noprint';
    banner.style.cssText = 'margin:.5rem 0;padding:.5rem .75rem;background:#fff3cd;border:1px solid #ffe69c;border-radius:6px;font:14px system-ui,-apple-system,sans-serif;';
    banner.appendChild(document.createTextNode('Showing ' + nsel + ' selected waybill' + (nsel === 1 ? '' : 's') + ' · '));
    var link = document.createElement('a');
    link.href = showAll;
    link.textContent = 'Show all';
    banner.appendChild(link);
    wrap.parentNode.insertBefore(banner, wrap);
    var pb = document.querySelector('.waybill-print-controls button');
    if (pb) { pb.innerHTML = '<i class="bi bi-printer"></i> Print selected'; }
    var cnt = document.querySelector('main .muted');
    if (cnt) {
      cnt.textContent = nsel + ' selected waybill' + (nsel === 1 ? '' : 's') + ' · each prints on its own page.';
    }
  }

  function soSwPhase() {
    var sel = document.getElementById('sw-phase-select');
    var lab = document.querySelector('label[for="sw-phase-select"]');
    if (!sel) { return; }
    var secs = Array.prototype.slice.call(document.querySelectorAll('.page .print-all-phase'));
    if (secs.length < 1) {
      sel.style.display = 'none';
      if (lab) { lab.style.display = 'none'; }
      return;
    }
    secs.forEach(function (s, i) {
      var h = s.querySelector('h2');
      var t = h ? (h.textContent || '').trim() : ('Phase ' + (i + 1));
      // Drop redundant " - Phase N of M" when Train Info already names the leg.
      t = t.replace(/\s*[—\-]\s*Phase\s+\d+\s+of\s+\d+\s*$/i, '').trim() || t;
      s.setAttribute('data-sw-phase', String(i));
      var o = document.createElement('option');
      o.value = String(i);
      o.textContent = t;
      sel.appendChild(o);
    });
    function apply() {
      var v = sel.value;
      secs.forEach(function (s) {
        s.style.display = (v === '' || s.getAttribute('data-sw-phase') === v) ? '' : 'none';
      });
    }
    sel.addEventListener('change', apply);
    apply();
  }

  STS.ready(function () {
    document.querySelectorAll('img[data-sts-hide-on-error]').forEach(function (img) {
      if (img.complete && img.naturalWidth === 0) { hideBrokenImg(img); }
    });
    initJobIndex();
    initStationReport();
    initWheelReport();

    var d;
    if ((d = STS.data('sts-so-memo')) !== null) { soMemo(d); }
    if ((d = STS.data('sts-so-prior')) !== null) { soPrior(d); }
    if ((d = STS.data('sts-so-phase')) !== null) { soPhase(d); }
    if ((d = STS.data('sts-so-select')) !== null) { soSelect(d); }
    if ((d = STS.data('sts-so-selection')) !== null) { soSelection(d); }
    if ((d = STS.data('sts-so-swphase')) !== null) { soSwPhase(d); }
  });
})();
