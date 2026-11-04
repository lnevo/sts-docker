<div id="station_filters_mount" class="noprint">
  <div id="station_filters" class="d-none ops-filters">
    <div class="ops-filter-grid">
      <div class="ops-filter-item">
        <label for="pickup_location_filter">Pickup</label>
        <select id="pickup_location_filter" class="form-select" data-sts-change="applyStationFilters">
          <option value="">All</option>
        </select>
      </div>
      <div class="ops-filter-item ops-filter-item-wide">
        <label for="reporting_marks_filter">Marks</label>
        <input id="reporting_marks_filter" type="text" class="form-control" placeholder="Filter" data-sts-input="applyStationFilters">
      </div>
      <div class="ops-filter-item">
        <label for="car_code_filter">Car code</label>
        <select id="car_code_filter" class="form-select" data-sts-change="applyStationFilters">
          <option value="">All</option>
        </select>
      </div>
      <div class="ops-filter-item">
        <label for="status_filter">Status</label>
        <select id="status_filter" class="form-select" data-sts-change="applyStationFilters">
          <option value="">All</option>
        </select>
      </div>
      <div class="ops-filter-item">
        <label for="consignment_filter">Consignment</label>
        <select id="consignment_filter" class="form-select" data-sts-change="applyStationFilters">
          <option value="">All</option>
        </select>
      </div>
      <div class="ops-filter-item">
        <label for="final_destination_filter">Final dest.</label>
        <select id="final_destination_filter" class="form-select" data-sts-change="applyStationFilters">
          <option value="">All</option>
        </select>
      </div>
      <div class="ops-filter-item">
        <label for="loading_station_filter">Loading</label>
        <select id="loading_station_filter" class="form-select" data-sts-change="applyStationFilters">
          <option value="">All</option>
        </select>
      </div>
      <div class="ops-filter-item">
        <label for="unloading_station_filter">Unloading</label>
        <select id="unloading_station_filter" class="form-select" data-sts-change="applyStationFilters">
          <option value="">All</option>
        </select>
      </div>
      <div class="ops-filter-item ops-filter-actions">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-sts-click="clearStationFilters">Clear</button>
        <span id="station_filter_count" class="text-muted small"></span>
      </div>
    </div>
  </div>
</div>
