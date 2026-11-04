/* Station report page (display_station_report.php). */
(function () {
  'use strict';

  function syncReadingButtons(enabled) {
    document.querySelectorAll('#reading-btn, #reading-btn-report').forEach(function (btn) {
      btn.classList.toggle('active', enabled);
    });
  }

  STS.action('toggleReadingMode', function () {
    var enabled = document.body.classList.toggle('reading-mode');
    try { localStorage.setItem('reading-mode', enabled ? '1' : '0'); } catch (e) { /* ignore */ }
    syncReadingButtons(enabled);
  });

  STS.action('modifySearch', function () {
    document.getElementById('report-container').classList.remove('show');
    document.querySelector('.form-card').scrollIntoView({ behavior: 'smooth' });
  });

  STS.ready(function () {
    var saved = false;
    try { saved = localStorage.getItem('reading-mode') === '1'; } catch (e) { /* ignore */ }
    if (saved) {
      document.body.classList.add('reading-mode');
      syncReadingButtons(true);
    }

    stsAddAllOption('station_name');
    document.getElementById('station_name').addEventListener('change', stsEnableDisplay);

    document.getElementById('station-report-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var params = new URLSearchParams();
      params.append('station_name', document.getElementById('station_name').value);
      params.append('display_btn', '1');
      if (document.getElementById('hide_unavail').checked) {
        params.append('hide_unavail', '1');
      }
      fetch('display_station_report.php?generate_report=1&' + params.toString())
        .then(function (response) { return response.text(); })
        .then(function (html) {
          document.getElementById('report-content').innerHTML = html;
          document.getElementById('report-container').classList.add('show');
          setTimeout(function () {
            document.getElementById('report-container').scrollIntoView({ behavior: 'smooth' });
          }, 100);
        })
        .catch(function (error) {
          alert('Error loading report: ' + error);
        });
    });
  });
})();
