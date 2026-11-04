/* job.php: train page (phase pager, switch-list style picker, inline frames).
 * Server values come from the JSON block #sts-data-job: {session, style, initialLeg}. */
(function () {
  'use strict';
  const D = STS.data('sts-data-job') || {};
  const legs = Array.from(document.querySelectorAll('.switchlist-leg'));
  const styleSelect = document.getElementById('switchlist-style');
  const styleStatus = document.getElementById('switchlist-style-status');
  const phaseLabel = document.getElementById('phase-label');
  const prevBtn = document.getElementById('phase-prev');
  const nextBtn = document.getElementById('phase-next');
  const reloadBtn = document.getElementById('switchlist-reload');
  const sessionId = D.session;
  const storageKey = 'session_switchlist_style';
  let current = D.initialLeg;

  // Reload one iframe (cache-busted) so a freshly (re)rendered style file is
  // fetched instead of a stale response.
  function reloadFrame(frame) {
    const src = frame.getAttribute('src');
    if (!src) return;
    const bust = (src.indexOf('&_=') === -1 && src.indexOf('?_=') === -1)
      ? src + '&_=' + Date.now()
      : src.replace(/([?&])_=\d+/, '$1_=' + Date.now());
    frame.setAttribute('src', bust);
  }

  // When an inline switch-list frame comes back as the "Not found" placeholder
  // (style file not yet written), regenerate the style once and reload it so
  // the operator never has to hand-refresh.
  function guardFrame(frame) {
    if (frame.dataset.recovered === '1') return;
    try {
      const doc = frame.contentDocument;
      if (!doc) return;
      const text = (doc.body ? doc.body.textContent : '').trim();
      if (text === 'Not found' || text === 'Bad path') {
        frame.dataset.recovered = '1';
        const style = styleSelect ? styleSelect.value : D.style;
        fetch('operational_steps_api.php?action=rerender_session_style', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session: sessionId, style: style }),
        }).then(function () { reloadFrame(frame); });
      }
    } catch (e) { /* cross-origin or blank: ignore */ }
  }

  const savedStyle = localStorage.getItem(storageKey);
  if (savedStyle && styleSelect && !window.location.search.includes('style=')) {
    styleSelect.value = savedStyle;
  }

  function showLeg(idx) {
    if (!legs.length) return;
    current = Math.max(0, Math.min(idx, legs.length - 1));
    legs.forEach(function (leg, i) {
      leg.style.display = i === current ? '' : 'none';
    });
    if (phaseLabel) {
      const info = (legs[current] && legs[current].dataset.info) ? legs[current].dataset.info.trim() : '';
      phaseLabel.textContent = info !== ''
        ? info
        : ('Phase ' + (current + 1) + ' of ' + legs.length);
    }
    if (prevBtn) prevBtn.disabled = current === 0;
    if (nextBtn) nextBtn.disabled = current === legs.length - 1;
  }

  function applyStyle(style) {
    document.querySelectorAll('[data-base]').forEach(function (el) {
      const base = el.getAttribute('data-base');
      if (!base) return;
      const url = base + '_' + style + '.html';
      if (el.tagName === 'IFRAME') {
        el.setAttribute('src', url + '&embed=1');
      } else if (el.tagName === 'A') {
        el.setAttribute('href', url);
      }
    });
    localStorage.setItem(storageKey, style);
    const params = new URL(window.location.href);
    params.searchParams.set('style', style);
    window.history.replaceState({}, '', params);
  }

  prevBtn?.addEventListener('click', function () { showLeg(current - 1); });
  nextBtn?.addEventListener('click', function () { showLeg(current + 1); });

  document.querySelectorAll('.switchlist-frame').forEach(function (frame) {
    frame.addEventListener('load', function () { guardFrame(frame); });
  });

  reloadBtn?.addEventListener('click', async function () {
    const style = styleSelect ? styleSelect.value : D.style;
    if (styleStatus) styleStatus.textContent = 'Reloading…';
    try {
      await fetch('operational_steps_api.php?action=rerender_session_style', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session: sessionId, style: style, force: true }),
      });
    } catch (e) { /* reload anyway */ }
    document.querySelectorAll('.switchlist-frame').forEach(reloadFrame);
    if (styleStatus) styleStatus.textContent = 'Reloaded';
  });

  styleSelect?.addEventListener('change', async function () {
    const style = styleSelect.value;
    if (styleStatus) styleStatus.textContent = 'Generating…';
    try {
      const resp = await fetch('operational_steps_api.php?action=rerender_session_style', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ session: sessionId, style: style }),
      });
      const data = await resp.json();
      if (!data.ok) throw new Error(data.error || 'Generation failed');
      applyStyle(style);
      if (styleStatus) styleStatus.textContent = data.skipped ? 'Ready (cached)' : 'Generated';
    } catch (err) {
      if (styleStatus) styleStatus.textContent = String(err.message || err);
    }
  });

  showLeg(current);
  if (styleSelect) applyStyle(styleSelect.value);
})();

(function () {
  'use strict';
  Array.prototype.forEach.call(document.querySelectorAll('.job-session-select, .job-train-select'), function (sel) {
    sel.addEventListener('change', function () {
      if (sel.value) {
        window.location.href = sel.value;
      }
    });
  });
  var form = document.getElementById('switchlist-style-form');
  if (form) {
    form.addEventListener('submit', function (ev) { ev.preventDefault(); });
  }
})();
