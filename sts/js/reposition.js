(function () {
      let repoOffHomeOnly = false;

      function matchesRepoLocationFilter(row, selectedValue, stationKey, locationKey) {
        if (!selectedValue) return true;
        if (selectedValue.indexOf('station::') === 0) {
          return row.dataset[stationKey] === selectedValue.substring(9);
        }
        if (selectedValue.indexOf('location::') === 0) {
          return row.dataset[locationKey] === selectedValue.substring(10);
        }
        return true;
      }

      function populateRepoLocationFilterOptions(selectId, stationKey, locationKey) {
        const select = document.getElementById(selectId);
        const rows = Array.from(document.querySelectorAll('#car_tbl tr.repo-car-row'));
        const stations = new Map();

        rows.forEach(function(row) {
          const station = row.dataset[stationKey];
          const location = row.dataset[locationKey];
          if (!station) return;
          if (!stations.has(station)) {
            stations.set(station, new Set());
          }
          if (location) {
            stations.get(station).add(location);
          }
        });

        const previous = select.value;
        select.innerHTML = '';
        const allOption = document.createElement('option');
        allOption.value = '';
        allOption.textContent = 'All';
        select.appendChild(allOption);

        Array.from(stations.keys()).sort().forEach(function(station) {
          const stationOption = document.createElement('option');
          stationOption.value = 'station::' + station;
          stationOption.textContent = station;
          select.appendChild(stationOption);

          Array.from(stations.get(station)).sort().forEach(function(location) {
            const locationOption = document.createElement('option');
            locationOption.value = 'location::' + location;
            locationOption.textContent = station + ' — ' + location;
            select.appendChild(locationOption);
          });
        });

        if (Array.from(select.options).some(function(option) { return option.value === previous; })) {
          select.value = previous;
        }
      }

      function populateRepoFilterOptions(selectId, datasetKey) {
        const select = document.getElementById(selectId);
        const rows = Array.from(document.querySelectorAll('#car_tbl tr.repo-car-row'));
        const values = Array.from(new Set(rows.map(function(row) { return row.dataset[datasetKey]; }).filter(Boolean))).sort();
        const previous = select.value;

        select.innerHTML = '';
        const allOption = document.createElement('option');
        allOption.value = '';
        allOption.textContent = 'All';
        select.appendChild(allOption);

        values.forEach(function(value) {
          const option = document.createElement('option');
          option.value = value;
          option.textContent = value;
          select.appendChild(option);
        });

        if (values.includes(previous)) {
          select.value = previous;
        }
      }

      function populateRepositionFilters() {
        populateRepoFilterOptions('repo_car_code_filter', 'carCode');
        populateRepoLocationFilterOptions('repo_current_loc_filter', 'currentStation', 'currentLocation');
        populateRepoLocationFilterOptions('repo_home_loc_filter', 'homeStation', 'homeLocation');
        applyRepositionFilters();
      }

      function applyRepositionFilters() {
        const carCode = document.getElementById('repo_car_code_filter').value;
        const currentLoc = document.getElementById('repo_current_loc_filter').value;
        const homeLoc = document.getElementById('repo_home_loc_filter').value;
        const marks = document.getElementById('repo_marks_filter').value.trim().toLowerCase();
        const rows = Array.from(document.querySelectorAll('#car_tbl tr.repo-car-row'));
        let visibleCount = 0;

        rows.forEach(function(row) {
          const matchesCarCode = !carCode || row.dataset.carCode === carCode;
          const matchesCurrent = matchesRepoLocationFilter(row, currentLoc, 'currentStation', 'currentLocation');
          const matchesHome = matchesRepoLocationFilter(row, homeLoc, 'homeStation', 'homeLocation');
          const matchesMarks = !marks || row.dataset.reportingMarks.toLowerCase().includes(marks);
          const matchesOffHome = !repoOffHomeOnly || row.dataset.offHome === '1';
          const isVisible = matchesCarCode && matchesCurrent && matchesHome && matchesMarks && matchesOffHome;

          row.hidden = !isVisible;
          const destination = row.querySelector('select[name^="list"]');
          if (destination) {
            destination.disabled = !isVisible;
          }
          if (isVisible) {
            visibleCount++;
          }
        });

        updateRepoGroupHeaderVisibility();
        updateRepoFilterCount(visibleCount, rows.length);
      }

      function updateRepoGroupHeaderVisibility() {
        const table = document.getElementById('car_tbl');
        if (!table) return;

        let groupHeader = null;
        let groupVisibleRows = 0;

        Array.from(table.rows).forEach(function(row) {
          if (row.classList.contains('home-group-header')) {
            if (groupHeader) {
              groupHeader.hidden = groupVisibleRows === 0;
            }
            groupHeader = row;
            groupVisibleRows = 0;
            row.hidden = false;
          }
          else if (row.classList.contains('repo-car-row') && !row.hidden) {
            groupVisibleRows++;
          }
        });

        if (groupHeader) {
          groupHeader.hidden = groupVisibleRows === 0;
        }
      }

      function updateRepoFilterCount(visibleCount, totalCount) {
        const count = document.getElementById('repo_filter_count');
        if (count) {
          count.textContent = visibleCount + ' of ' + totalCount + ' cars shown';
        }
      }

      function clearRepositionFilters() {
        document.getElementById('repo_car_code_filter').value = '';
        document.getElementById('repo_current_loc_filter').value = '';
        document.getElementById('repo_home_loc_filter').value = '';
        document.getElementById('repo_marks_filter').value = '';
        repoOffHomeOnly = false;
        document.getElementById('repo_off_home_btn').classList.remove('active');
        applyRepositionFilters();
      }

      function toggleOffHomeOnly() {
        repoOffHomeOnly = !repoOffHomeOnly;
        document.getElementById('repo_off_home_btn').classList.toggle('active', repoOffHomeOnly);
        applyRepositionFilters();
      }

      function prepareRepositionSubmit() {
        document.querySelectorAll('#car_tbl tr.repo-car-row[hidden] select[name^="list"]').forEach(function(select) {
          select.disabled = true;
        });
        return true;
      }

      function repo_to_home() {
        const rows = Array.from(document.querySelectorAll('#car_tbl tr.repo-car-row:not([hidden])'))
          .filter(function(row) { return row.dataset.offHome === '1'; });

        if (rows.length === 0) {
          alert('No visible cars off home to reposition.');
          return;
        }

        if (!confirm('Reposition ' + rows.length + ' visible car(s) not at home to their home locations?')) {
          return;
        }

        const formData = new FormData();
        rows.forEach(function(row) {
          formData.append('car_ids[]', row.dataset.carId);
        });

        fetch('repo_to_home.php', {
          method: 'POST',
          body: formData
        })
          .then(function(response) { return response.text(); })
          .then(function(text) {
            alert(text + ' Non-Revenue Car Orders Generated');
            location.reload();
          })
          .catch(function() {
            alert('Could not reposition cars to home.');
          });
      }
    
      document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('#car_tbl select[name^="list"]').forEach(function(el) {
          el.classList.add('form-select', 'form-select-sm');
          el.style.removeProperty('width');
        });
        populateRepositionFilters();
      });
  

  STS.action('prepareRepositionSubmit', function () { return prepareRepositionSubmit(); });
  STS.action('repo_to_home', function () { repo_to_home(); });
  STS.action('applyRepositionFilters', function () { applyRepositionFilters(); });
  STS.action('toggleOffHomeOnly', function () { toggleOffHomeOnly(); });
  STS.action('clearRepositionFilters', function () { clearRepositionFilters(); });
})();
