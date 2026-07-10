/**
 * Shared workflow editor UI — inline rows, catalog dropdowns, category colors.
 */
(function (global) {
  'use strict';

  const WorkflowUI = {
    API: 'operational_steps_api.php',
    SIMULATOR_API: 'simulator_api.php',
    catalog: { adder_functions: [], functions: [], adder_categories: {} },
    catalogMap: {},
    dynamicOptions: {},
    recipe: { version: 1, name: 'workflow', steps: [] },
    compiledSteps: [],
    runOptions: {},
    csvFiles: [],
    activeCsv: '',
    autoLoadCsv: false,
    dragIndex: null,
    insertMode: 'before',
    insertStepNum: 1,
    hideNonExecute: false,
    executionPathSteps: null,
    dirty: false,

    el(id) { return document.getElementById(id); },

    markDirty() {
      this.dirty = true;
    },

    clearDirty() {
      this.dirty = false;
    },

    escapeHtml(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    },

    async api(action, method, body, queryParams) {
      queryParams = queryParams || {};
      const opts = { method: method || (body ? 'POST' : 'GET'), cache: 'no-store' };
      if (body && opts.method === 'POST') {
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body = JSON.stringify(Object.assign({ action: action }, body));
      }
      const params = new URLSearchParams({ action: action, t: String(Date.now()) });
      Object.keys(queryParams).forEach((k) => {
        if (queryParams[k] != null && queryParams[k] !== '') {
          params.set(k, String(queryParams[k]));
        }
      });
      const url = opts.method === 'POST' ? this.API : this.API + '?' + params.toString();
      const res = await fetch(url, opts);
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed');
      return data;
    },

    async simulatorApi(action, method, body) {
      const opts = { method: method || 'GET', cache: 'no-store' };
      if (body) {
        opts.method = 'POST';
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body = JSON.stringify(Object.assign({ action: action }, body));
      }
      const url = body
        ? this.SIMULATOR_API
        : this.SIMULATOR_API + '?action=' + encodeURIComponent(action) + '&t=' + Date.now();
      const res = await fetch(url, opts);
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed');
      return data;
    },

    setStatus(msg, kind) {
      const node = this.el('status');
      if (!node) return;
      node.textContent = msg;
      node.className = 'status ' + (kind || 'info');
    },

    log(msg) {
      const node = this.el('log');
      if (!node) return;
      node.textContent = typeof msg === 'string' ? msg : JSON.stringify(msg, null, 2);
    },

    buildCatalogMap() {
      this.catalogMap = {};
      (this.catalog.functions || []).concat(this.catalog.adder_functions || []).forEach((f) => {
        this.catalogMap[f.id] = f;
      });
      this.adderFunctionIds = new Set((this.catalog.adder_functions || []).map((f) => f.id));
    },

    isAdderFunction(fid) {
      if (!fid) return false;
      if (!this.adderFunctionIds) {
        this.adderFunctionIds = new Set((this.catalog.adder_functions || []).map((f) => f.id));
      }
      return this.adderFunctionIds.has(fid);
    },

    isTextInstructionRow(step) {
      const fid = step?.function || '';
      if (!fid) return false;
      if (fid === 'text_instruction' || fid === 'marker') return true;
      return !this.isAdderFunction(fid);
    },

    stepColorClass(step) {
      const def = this.catalogMap[step.function];
      const group = def?.adder_group || '';
      const cat = def?.category || '';
      if (this.isTextInstructionRow(step) || cat === 'workflow' || group === 'workflow') {
        return 'cat-neutral';
      }
      if (['before', 'during', 'after'].includes(group) || cat === 'operations') return 'cat-operations';
      if (group === 'database' || cat === 'database') return 'cat-database';
      if (group === 'reports' || group === 'waybills' || cat === 'reports') return 'cat-reports';
      return 'cat-neutral';
    },

    isDatabaseStep(step) {
      const def = this.catalogMap[step?.function];
      const group = def?.adder_group || '';
      const cat = def?.category || '';
      return group === 'database' || cat === 'database';
    },

    stsLinkHtml(step) {
      if (!step?.function || this.isDatabaseStep(step)) return '';
      const def = this.catalogMap[step.function];
      if (!def?.gui_path) return '';
      return '<a class="gui-link hdr-gui-link" href="' + this.escapeHtml(def.gui_path) +
        '" target="_blank" rel="noopener">STS</a>';
    },

    catalogGroups() {
      const cats = this.catalog.adder_categories || {};
      const byGroup = {};
      Object.values(this.catalogMap).forEach((f) => {
        const g = f.adder_group || f.category || 'other';
        (byGroup[g] = byGroup[g] || []).push(f);
      });
      Object.keys(byGroup).forEach((g) => {
        byGroup[g].sort((a, b) => (a.label || a.id).localeCompare(b.label || b.id));
      });
      const order = Object.keys(cats).length ? Object.keys(cats) : Object.keys(byGroup);
      return { order, byGroup, cats };
    },

    commandSelectHtml(step, rowIdx) {
      const selectedId = typeof step === 'string' ? step : (step?.function || '');
      const actualFn = selectedId;
      const displayId = (selectedId && !this.isAdderFunction(selectedId)) ? 'text_instruction' : selectedId;
      const adder = this.catalog.adder_functions || [];
      const cats = this.catalog.adder_categories || {};
      const byGroup = {};
      adder.forEach((f) => {
        const g = f.adder_group || 'during';
        (byGroup[g] = byGroup[g] || []).push(f);
      });
      const order = Object.keys(cats).length ? Object.keys(cats) : Object.keys(byGroup);
      let html = '<select class="row-fn field-select" data-fn-select data-row="' + rowIdx + '"';
      if (displayId === 'text_instruction' && actualFn && actualFn !== 'text_instruction') {
        html += ' data-actual-fn="' + this.escapeHtml(actualFn) + '"';
      }
      html += '>';
      const hasSelection = displayId && this.catalogMap[displayId];
      html += '<option value=""' + (!hasSelection ? ' selected' : '') + '>-- Select a command --</option>';
      order.forEach((g) => {
        const items = byGroup[g] || [];
        if (!items.length) return;
        const groupDisabled = g === 'reports';
        html += '<optgroup label="' + this.escapeHtml(cats[g] || g) + '"' + (groupDisabled ? ' disabled' : '') + '>';
        items.forEach((f) => {
          const dis = groupDisabled || f.disabled;
          html += '<option value="' + f.id + '"' +
            (f.id === displayId ? ' selected' : '') +
            (dis ? ' disabled' : '') + '>' +
            this.escapeHtml(f.label || f.id) + '</option>';
        });
        html += '</optgroup>';
      });
      html += '</select>';
      return html;
    },

    nonStagingJobNames() {
      const staging = new Set((this.dynamicOptions.staging_jobs || ['STG-SCULLY', 'STG-DEMMLER']).map(String));
      return (this.dynamicOptions.jobs || [])
        .map((j) => j.name)
        .filter((name) => name && !staging.has(name));
    },

    resolveAutoAssignJobsValue(val, step) {
      let selected = String(val || '').split(',').map((s) => s.trim()).filter(Boolean);
      if (!selected.length && step?.params?.scope) {
        if (step.params.scope === 'locals') {
          selected = this.nonStagingJobNames();
        } else {
          selected = [String(step.params.scope).trim()];
        }
      }
      if (!selected.length) {
        selected = this.nonStagingJobNames();
      }
      return selected;
    },

    optionsForParam(p, context) {
      context = context || {};
      const from = p.options_from || p.type;
      const d = this.dynamicOptions;
      if (p.type === 'select' && p.options) {
        return p.options.map((o) => {
          if (o && typeof o === 'object') {
            const value = o.value != null ? o.value : o.label;
            return { value, label: o.label != null ? o.label : String(value) };
          }
          return { value: o, label: o === '' ? 'Any' : o };
        });
      }
      if (from === 'jobs') return (d.jobs || []).map((j) => ({ value: j.name, label: j.name }));
      if (from === 'shipments') return (d.shipments || []).map((s) => ({ value: s.code, label: s.label }));
      if (from === 'car_codes') return (d.car_codes || []).map((c) => ({ value: c.code, label: c.label }));
      if (from === 'commodities') return (d.commodities || []).map((c) => ({ value: c.code, label: c.label }));
      if (from === 'locations') return (d.locations || []).map((l) => ({ value: l.code || l.label, label: l.label }));
      if (from === 'stations') return (d.stations || []).map((s) => ({ value: s.name, label: s.label }));
      if (from === 'setout_locations') return d.setout_locations || [];
      if (from === 'scopes') return d.scopes || [];
      if (from === 'backups') return (d.backups || []).map((b) => ({ value: b, label: b }));
      if (from === 'switchlist_trains' || from === 'job_or_all' || p.type === 'job_or_all') {
        const preferred = ['D749', 'NVL', 'CK1'];
        const names = new Set(['all']);
        preferred.forEach((n) => names.add(n));
        (d.jobs || []).forEach((j) => {
          if (j.name) names.add(j.name);
        });
        return [{ value: 'all', label: 'All' }].concat(
          [...names].filter((n) => n !== 'all').sort().map((n) => ({ value: n, label: n }))
        );
      }
      if (from === 'workflow_section' || p.type === 'workflow_section') {
        const fromStep = (context.rowIdx != null ? context.rowIdx : -1) + 1;
        return (this.buildRunSections() || [])
          .filter((s) => s.id !== 'all')
          .filter((s) => {
            if (context.step?.function !== 'goto' || fromStep <= 0) return true;
            return s.start > fromStep;
          })
          .map((s) => ({
            value: s.id,
            label: this.truncateSectionLabel(s.label, 56) + ' (step ' + s.start + ')',
          }));
      }
      return [];
    },

    inlineParamFieldHtml(p, val, rowIdx, dataParamKey, visibleLabels, step) {
      const paramKey = dataParamKey || p.key;
      const id = 'r' + rowIdx + '-' + paramKey.replace(/\./g, '-');
      const lblClass = visibleLabels ? 'inline-lbl inline-lbl-visible' : 'inline-lbl';
      const lbl = '<span class="' + lblClass + '">' + this.escapeHtml(p.label) + '</span>';
      const opts = this.optionsForParam(p, { rowIdx, step });
      val = val != null ? val : (p.default != null ? p.default : '');

      if (p.type === 'select' && p.options) {
        let h = '<label class="inline-field' + (visibleLabels ? ' inline-field-labeled' : '') + '">' + lbl +
          '<select class="field-select" data-param="' + this.escapeHtml(paramKey) + '" id="' + id + '">';
        p.options.forEach((o) => {
          const optVal = (o && typeof o === 'object') ? (o.value != null ? o.value : o.label) : o;
          const optLabel = (o && typeof o === 'object')
            ? (o.label != null ? o.label : String(optVal))
            : (o === '' ? (p.key === 'off_home_only' ? 'Any' : 'Any') : o);
          const displayLabel = p.key === 'off_home_only' && String(optVal) === '1' ? 'Not at home' : optLabel;
          h += '<option value="' + this.escapeHtml(String(optVal)) + '"' + (String(val) === String(optVal) ? ' selected' : '') + '>' +
            this.escapeHtml(String(displayLabel)) + '</option>';
        });
        return h + '</select></label>';
      }
      if (['job', 'location', 'station', 'backup', 'scope', 'setout_location', 'shipment', 'car_code', 'commodity', 'switchlist_trains', 'job_or_all', 'workflow_section'].includes(p.type) || opts.length) {
        const allowCustom = p.type !== 'backup' && p.type !== 'switchlist_trains' && p.type !== 'job_or_all' && p.type !== 'station' && p.allow_custom !== false;
        let h = '<label class="inline-field' + (visibleLabels ? ' inline-field-labeled' : '') + '">' + lbl +
          '<select class="field-select" data-param="' + this.escapeHtml(paramKey) + '" id="' + id + '">';
        if (allowCustom) h += '<option value="">Any</option>';
        opts.forEach((o) => {
          const v = o.value != null ? o.value : o;
          const l = o.label != null ? o.label : o;
          h += '<option value="' + this.escapeHtml(String(v)) + '"' + (String(val) === String(v) ? ' selected' : '') + '>' +
            this.escapeHtml(String(l)) + '</option>';
        });
        if (val && !opts.some((o) => String(o.value || o) === String(val))) {
          h += '<option value="' + this.escapeHtml(String(val)) + '" selected>' + this.escapeHtml(String(val)) + '</option>';
        }
        return h + '</select></label>';
      }
      if (p.type === 'number') {
        const min = p.min != null ? ' min="' + p.min + '"' : '';
        const max = p.max != null ? ' max="' + p.max + '"' : '';
        const step = p.step != null ? ' step="' + p.step + '"' : '';
        return '<label class="inline-field' + (visibleLabels ? ' inline-field-labeled' : '') + '">' + lbl +
          '<input type="number" class="field-input" data-param="' + this.escapeHtml(paramKey) + '" id="' + id + '" value="' +
          this.escapeHtml(String(val)) + '"' + min + max + step + '></label>';
      }
      if (p.type === 'percent') {
        const min = p.min != null ? p.min : 0;
        const max = p.max != null ? p.max : 100;
        const step = p.step != null ? p.step : 1;
        return '<label class="inline-field inline-field-percent' + (visibleLabels ? ' inline-field-labeled' : '') + '">' + lbl +
          '<span class="field-percent-wrap">' +
          '<input type="number" class="field-input field-percent" data-param="' + this.escapeHtml(paramKey) + '" id="' + id + '" value="' +
          this.escapeHtml(String(val)) + '" min="' + min + '" max="' + max + '" step="' + step + '">' +
          '<span class="field-percent-suffix">%</span></span></label>';
      }
      if (p.type === 'jobs_multiselect') {
        const opts = this.optionsForParam(p, { rowIdx, step });
        const selected = this.resolveAutoAssignJobsValue(val, step);
        const selectedSet = new Set(selected.map(String));
        let h = '<label class="inline-field inline-field-multiselect' + (visibleLabels ? ' inline-field-labeled' : '') + '">' + lbl +
          '<select multiple class="field-select field-multiselect" data-param="' + this.escapeHtml(paramKey) + '" id="' + id + '" size="4">';
        opts.forEach((o) => {
          const v = o.value != null ? o.value : o;
          const l = o.label != null ? o.label : o;
          h += '<option value="' + this.escapeHtml(String(v)) + '"' +
            (selectedSet.has(String(v)) ? ' selected' : '') + '>' + this.escapeHtml(String(l)) + '</option>';
        });
        selected.forEach((v) => {
          if (!opts.some((o) => String(o.value != null ? o.value : o) === String(v))) {
            h += '<option value="' + this.escapeHtml(String(v)) + '" selected>' + this.escapeHtml(String(v)) + '</option>';
          }
        });
        return h + '</select></label>';
      }
      return '<label class="inline-field' + (visibleLabels ? ' inline-field-labeled' : '') + '">' + lbl +
        '<input type="text" class="field-input" data-param="' + this.escapeHtml(paramKey) + '" id="' + id + '" value="' +
        this.escapeHtml(String(val)) + '"></label>';
    },

    filterGroupHtml(p, values, rowIdx) {
      if (p.layout === 'fill_order') {
        return this.fillOrderFilterGroupHtml(p, values, rowIdx);
      }
      if (p.layout === 'fill_car') {
        return this.fillCarFilterGroupHtml(p, values, rowIdx);
      }
      if (p.layout === 'reposition') {
        return this.repositionFilterGroupHtml(p, values, rowIdx);
      }
      const fields = p.fields || [];
      values = values || {};
      const topKeys = ['car_code', 'status', 'commodity'];
      const locationKeys = ['current_location', 'loading_location', 'unloading_location'];
      const byKey = {};
      fields.forEach((f) => { byKey[f.key] = f; });

      let html = '<div class="param-filter-grid">';
      html += '<div class="param-filter-row param-filter-row-main">';
      topKeys.forEach((key) => {
        const f = byKey[key];
        if (f) html += this.inlineParamFieldHtml(f, values[f.key], rowIdx, p.key + '.' + f.key, true);
      });
      html += '</div>';
      html += '<div class="param-filter-locations">';
      html += '<span class="param-filter-group-lbl">Locations</span>';
      html += '<div class="param-filter-row param-filter-row-locations">';
      locationKeys.forEach((key) => {
        const f = byKey[key];
        if (f) html += this.inlineParamFieldHtml(f, values[f.key], rowIdx, p.key + '.' + f.key, true);
      });
      html += '</div></div></div>';
      return html;
    },

    fillSourcesFieldHtml(f, val, rowIdx, groupKey) {
      const paramKey = groupKey + '.' + f.key;
      const id = 'r' + rowIdx + '-' + paramKey.replace(/\./g, '-');
      const options = f.options || ['pool', 'station', 'priority', 'system'];
      let selected = [];
      if (Array.isArray(val)) {
        selected = val.map(String);
      } else {
        selected = String(val || f.default || 'pool,station,priority,system')
          .split(',')
          .map((s) => s.trim())
          .filter(Boolean);
      }
      const hiddenVal = selected.join(',');
      let html = '<div class="param-fill-sources">';
      html += '<span class="param-filter-group-lbl">' + this.escapeHtml(f.label) + '</span>';
      html += '<div class="param-fill-sources-row">';
      options.forEach((opt) => {
        const checked = selected.indexOf(opt) >= 0 ? ' checked' : '';
        html += '<label class="param-fill-source-check">' +
          '<input type="checkbox" data-fill-source data-fill-source-group="' + this.escapeHtml(groupKey) + '" value="' +
          this.escapeHtml(opt) + '"' + checked + '> ' + this.escapeHtml(opt.charAt(0).toUpperCase() + opt.slice(1)) +
          '</label>';
      });
      html += '</div>';
      html += '<input type="hidden" data-param="' + this.escapeHtml(paramKey) + '" id="' + id + '" value="' +
        this.escapeHtml(hiddenVal) + '">';
      html += '</div>';
      return html;
    },

    fillOrderFilterGroupHtml(p, values, rowIdx) {
      const fields = p.fields || [];
      values = values || {};
      let html = '<div class="param-filter-grid param-filter-grid-fill-order">';
      html += '<span class="param-filter-group-lbl">' + this.escapeHtml(p.label || 'Order filters') + '</span>';
      html += '<div class="param-filter-row param-filter-row-fill-order">';
      fields.forEach((f) => {
        html += this.inlineParamFieldHtml(f, values[f.key], rowIdx, p.key + '.' + f.key, true);
      });
      html += '</div></div>';
      return html;
    },

    fillCarFilterGroupHtml(p, values, rowIdx) {
      const fields = p.fields || [];
      values = values || {};
      const byKey = {};
      fields.forEach((f) => { byKey[f.key] = f; });
      let html = '<div class="param-filter-grid param-filter-grid-fill-car">';
      html += '<span class="param-filter-group-lbl">' + this.escapeHtml(p.label || 'Auto assign') + '</span>';
      if (byKey.categories) {
        html += this.fillSourcesFieldHtml(byKey.categories, values.categories, rowIdx, p.key);
      }
      html += '<div class="param-filter-row param-filter-row-fill-car">';
      ['current_station', 'current_location', 'car_code'].forEach((key) => {
        const f = byKey[key];
        if (f) html += this.inlineParamFieldHtml(f, values[f.key], rowIdx, p.key + '.' + f.key, true);
      });
      html += '</div></div>';
      return html;
    },

    repositionFilterGroupHtml(p, values, rowIdx) {
      const fields = p.fields || [];
      values = values || {};
      const byKey = {};
      fields.forEach((f) => { byKey[f.key] = f; });
      let html = '<div class="param-filter-grid param-filter-grid-reposition">';
      html += '<span class="param-filter-group-lbl">' + this.escapeHtml(p.label || 'Filters') + '</span>';
      html += '<div class="param-filter-row param-filter-row-reposition-top">';
      if (byKey.car_code) {
        html += this.inlineParamFieldHtml(byKey.car_code, values.car_code, rowIdx, p.key + '.car_code', true);
      }
      if (byKey.off_home_only) {
        html += this.inlineParamFieldHtml(byKey.off_home_only, values.off_home_only, rowIdx, p.key + '.off_home_only', true);
      }
      html += '</div>';
      html += '<div class="param-filter-locations">';
      html += '<span class="param-filter-group-lbl">Current</span>';
      html += '<div class="param-filter-row param-filter-row-reposition-locations">';
      ['current_station', 'current_location'].forEach((key) => {
        const f = byKey[key];
        if (f) html += this.inlineParamFieldHtml(f, values[f.key], rowIdx, p.key + '.' + f.key, true);
      });
      html += '</div></div>';
      html += '<div class="param-filter-locations">';
      html += '<span class="param-filter-group-lbl">Home</span>';
      html += '<div class="param-filter-row param-filter-row-reposition-locations">';
      ['home_station', 'home_location'].forEach((key) => {
        const f = byKey[key];
        if (f) html += this.inlineParamFieldHtml(f, values[f.key], rowIdx, p.key + '.' + f.key, true);
      });
      html += '</div></div></div>';
      return html;
    },

    compileFillOrdersTitle(params) {
      params = params || {};
      const order = params.order_filters || {};
      const car = params.car_filters || {};
      const orderLabels = {
        loading_location: 'load',
        unloading_location: 'unload',
        consignment: 'commodity',
        car_code: 'car',
      };
      const parts = [];
      Object.keys(orderLabels).forEach((key) => {
        const value = String(order[key] || '').trim();
        if (value) parts.push(orderLabels[key] + '=' + value);
      });
      const defaultSources = 'pool,station,priority,system';
      const sources = String(car.categories || defaultSources).trim();
      if (sources && sources !== defaultSources) {
        parts.push('src=' + sources);
      }
      if (String(car.current_station || '').trim()) {
        parts.push('car_station=' + String(car.current_station).trim());
      }
      if (String(car.current_location || '').trim()) {
        parts.push('car_loc=' + String(car.current_location).trim());
      }
      if (String(car.car_code || '').trim()) {
        parts.push('car_type=' + String(car.car_code).trim());
      }
      return parts.length ? 'Fill Orders ' + parts.join('; ') : 'Fill Orders';
    },

    compileRepositionTitle(params) {
      params = params || {};
      const mode = String(params.mode || 'reposition_to_home').trim() || 'reposition_to_home';
      let title = mode === 'update' ? 'Reposition Empties update' : 'Reposition Empties to home';
      const parts = [];
      if (mode === 'update') {
        const dest = String(params.destination || '').trim();
        if (dest) parts.push('dest=' + dest);
      }
      const filters = params.filters || {};
      const labels = {
        car_code: 'car',
        current_station: 'current',
        current_location: 'current_loc',
        home_station: 'home',
        home_location: 'home_loc',
      };
      Object.keys(labels).forEach((key) => {
        const value = String(filters[key] || '').trim();
        if (value) parts.push(labels[key] + '=' + value);
      });
      if (String(filters.off_home_only || '') === '1') {
        parts.push('off_home=1');
      }
      return parts.length ? title + ' ' + parts.join('; ') : title;
    },

    compileGenerateOrdersTitle(params) {
      params = params || {};
      const parts = [];
      const shipment = String(params.shipment || '').trim();
      if (shipment) parts.push(shipment);
      if (String(params.increment_session || '') === '1') parts.push('increment session');
      const maxUnfilled = String(params.max_unfilled || '').trim();
      if (maxUnfilled) parts.push('max_unfilled=' + maxUnfilled);
      if (!parts.length) return 'Generate Orders';
      return 'Generate Orders ' + parts.join('; ');
    },

    compileAutoAssignTitle(params) {
      params = params || {};
      const jobs = String(params.jobs || '').trim();
      if (!jobs) {
        if (params.scope === 'locals' || !params.scope) {
          return 'Auto-Assign Cars locals';
        }
        return 'Auto-Assign Cars ' + String(params.scope);
      }
      return 'Auto-Assign Cars ' + jobs.split(',').map((s) => s.trim()).filter(Boolean).join(', ');
    },

    compileLoadUnloadTitle(filters) {
      filters = filters || {};
      const labels = {
        current_location: 'current',
        car_code: 'car',
        status: 'status',
        commodity: 'consignment',
        loading_location: 'load',
        unloading_location: 'unload',
      };
      const parts = [];
      Object.keys(labels).forEach((key) => {
        const value = String(filters[key] || '').trim();
        if (value) parts.push(labels[key] + '=' + value);
      });
      return parts.length ? 'Load/Unload ' + parts.join('; ') : 'Load/Unload offline';
    },

    shouldHideInlineParam(step, p) {
      if (!step || !p) return false;
      if (step.function === 'section_label' && p.key === 'remarks') return true;
      if (step.function === 'goto' && (p.key === 'step' || p.key === 'section_label')) return true;
      if (step.function === 'if_then' && (p.key === 'job' || p.key === 'location')) return true;
      if (step.function === 'reposition_empties' && p.key === 'destination') {
        return (step.params?.mode || 'reposition_to_home') !== 'update';
      }
      return false;
    },

    rowRemarksText(step) {
      if (!step) return '';
      if (step.function === 'section_label') {
        return step.description || step.params?.remarks || '';
      }
      if (step.function === 'text_instruction' || step.function === 'marker') {
        return step.description || '';
      }
      return step.description || '';
    },

    legacyInstructionText(step, idx) {
      if (!step?.function || this.isAdderFunction(step.function)) return '';
      return (step.params?.instruction || this.compileOne(step, idx) || '').trim();
    },

    paramsHtmlForStep(step, rowIdx) {
      if (!step?.function) {
        return '<span class="inline-empty">Select a command</span>';
      }
      if (!this.isAdderFunction(step.function)) {
        const def = this.catalogMap.text_instruction;
        const p = def?.params?.[0];
        if (!p) return '<span class="inline-empty">No parameters</span>';
        const val = this.legacyInstructionText(step, rowIdx);
        return this.inlineParamFieldHtml(p, val, rowIdx, undefined, false, step);
      }
      const def = this.catalogMap[step.function];
      if (!def || !def.params || !def.params.length) {
        return '<span class="inline-empty">No parameters</span>';
      }
      const values = step.params || {};
      const fields = def.params
        .filter((p) => !this.shouldHideInlineParam(step, p))
        .map((p) => {
          if (p.type === 'filter_group') {
            return this.filterGroupHtml(p, values[p.key] || {}, rowIdx);
          }
          return this.inlineParamFieldHtml(p, values[p.key], rowIdx, undefined, !!p.visible_label, step);
        });
      return fields.length ? fields.join('') : '<span class="inline-empty">No parameters</span>';
    },

    readParamsFromRow(row) {
      const params = {};
      row.querySelectorAll('[data-param]').forEach((node) => {
        const key = node.getAttribute('data-param');
        if (!key) return;
        if (node.multiple) return;
        if (key.indexOf('.') >= 0) {
          const parts = key.split('.');
          const parent = parts.shift();
          const child = parts.join('.');
          if (!params[parent]) params[parent] = {};
          params[parent][child] = node.value;
        } else {
          params[key] = node.value;
        }
      });
      row.querySelectorAll('select[multiple][data-param]').forEach((node) => {
        const key = node.getAttribute('data-param');
        if (!key) return;
        params[key] = Array.from(node.selectedOptions).map((opt) => opt.value).join(',');
      });
      row.querySelectorAll('[data-param-custom]').forEach((node) => {
        const k = node.getAttribute('data-param-custom');
        if (node.value.trim() !== '') params[k] = node.value.trim();
      });
      return params;
    },

    syncStepFromRow(row, idx) {
      const fnSelect = row.querySelector('[data-fn-select]');
      const selectedFn = fnSelect?.value || this.recipe.steps[idx]?.function || '';
      const preservedFn = fnSelect?.dataset.actualFn || '';
      const desc = row.querySelector('[data-notes]')?.value?.trim() || '';
      const step = { function: selectedFn, params: this.readParamsFromRow(row) };
      if (selectedFn === 'text_instruction' && preservedFn) {
        const instruction = (step.params.instruction || '').trim();
        const compiled = this.compileOne(this.recipe.steps[idx], idx);
        if (instruction !== compiled.trim()) {
          step.function = 'text_instruction';
          step.params = { instruction };
        } else {
          step.function = preservedFn;
          delete step.params.instruction;
        }
      } else {
        step.function = selectedFn;
        if (fnSelect) delete fnSelect.dataset.actualFn;
      }
      if (step.function === 'goto' && step.params.section) {
        const sec = this.buildRunSections().find((s) => s.id === step.params.section);
        if (sec) {
          step.params.section_label = sec.label;
          if (sec.start <= idx + 1) {
            delete step.params.section;
            delete step.params.section_label;
          }
        }
      }
      if (desc) step.description = desc;
      if (step.function === 'section_label' && step.params.remarks !== undefined) {
        delete step.params.remarks;
      }
      this.recipe.steps[idx] = step;
      return step;
    },

    syncAllStepsFromDom() {
      const list = this.el('steps-list');
      if (!list) return;
      list.querySelectorAll('.step-row[data-idx]').forEach((row) => {
        const idx = parseInt(row.dataset.idx, 10);
        if (!isNaN(idx)) this.syncStepFromRow(row, idx);
      });
    },

    blankStep() {
      return {
        function: '',
        params: {},
        description: '',
      };
    },

    freshEditorRecipe() {
      return { version: 1, name: 'workflow', steps: [this.blankStep()] };
    },

    readInsertFromDom() {
      const mode = this.el('insert-mode')?.value || 'before';
      const num = parseInt(this.el('insert-step-num')?.value, 10) || 1;
      this.insertMode = mode;
      this.insertStepNum = num;
      this.insertAtIndex = this.resolveInsertIndex();
    },

    resolveInsertIndex() {
      const n = this.recipe.steps.length;
      const num = Math.max(1, this.insertStepNum || 1);
      switch (this.insertMode) {
        case 'end':
          return n;
        case 'before':
          return Math.min(Math.max(0, num - 1), n);
        case 'after':
          return Math.min(num, n);
        default:
          return 0;
      }
    },

    syncInsertFromDom() {
      this.readInsertFromDom();
      this.highlightInsertTarget();
    },

    syncInsertUi() {
      const wrap = this.el('insert-step-num-wrap');
      const numInput = this.el('insert-step-num');
      const modeSel = this.el('insert-mode');
      if (!wrap || !numInput || !modeSel) return;
      const mode = modeSel.value || 'before';
      this.insertMode = mode;
      const needsNum = mode === 'before' || mode === 'after' || mode === 'end';
      wrap.hidden = !needsNum;
      const n = this.recipe.steps.length;
      if (mode === 'end') {
        this.insertStepNum = n + 1;
        numInput.value = String(n + 1);
        numInput.readOnly = true;
      } else {
        numInput.readOnly = false;
        numInput.max = String(Math.max(1, n));
        if (needsNum) {
          this.insertStepNum = Math.max(1, Math.min(this.insertStepNum || 1, Math.max(1, n)));
          numInput.value = String(this.insertStepNum);
        }
      }
      this.insertAtIndex = this.resolveInsertIndex();
      this.highlightInsertTarget();
    },

    setInsertBefore(stepNum) {
      const modeSel = this.el('insert-mode');
      const numInput = this.el('insert-step-num');
      if (modeSel) modeSel.value = 'before';
      this.insertMode = 'before';
      this.insertStepNum = stepNum;
      if (numInput) numInput.value = String(stepNum);
      this.syncInsertUi();
    },

    highlightInsertTarget() {
      const list = this.el('steps-list');
      if (!list) return;
      list.querySelectorAll('.step-row.insert-marker').forEach((n) => n.classList.remove('insert-marker'));
      const marker = list.querySelector('.step-row[data-idx="' + this.insertAtIndex + '"]');
      if (marker) marker.classList.add('insert-marker');
    },

    remarksExpandedClass(step) {
      return this.rowRemarksText(step).trim() ? ' row-top-remarks-expanded' : '';
    },

    updateRemarksLayout(row) {
      const input = row.querySelector('[data-notes]');
      const top = row.querySelector('.row-top');
      if (!input || !top) return;
      top.classList.toggle('row-top-remarks-expanded', input.value.trim().length > 0);
    },

    stepRowInnerHtml(step, idx) {
      const rowKey = idx;

      return (
        '<div class="row-top row-top-align-start' + ((step.function === 'load_unload' || step.function === 'fill_orders' || step.function === 'reposition_empties') ? ' row-has-filters' : '') +
          this.remarksExpandedClass(step) + '">' +
          '<span class="handle" title="Drag to reorder">⋮⋮</span>' +
          '<span class="step-num">' + (idx + 1) + '</span>' +
          '<button type="button" class="btn-icon btn-insert-before" title="Add step below step ' + (idx + 1) + '">+</button>' +
          '<label class="inline-field row-command">' +
            '<span class="inline-lbl">Command</span>' +
            this.commandSelectHtml(step, rowKey) +
          '</label>' +
          '<div class="row-params">' + this.paramsHtmlForStep(step, rowKey) + '</div>' +
          '<label class="inline-field row-remarks">' +
            '<span class="inline-lbl">Remarks</span>' +
            '<input type="text" class="field-input field-remarks" data-notes placeholder="Optional remarks" value="' +
            this.escapeHtml(this.rowRemarksText(step)) + '">' +
          '</label>' +
          '<button type="button" class="btn-icon btn-del" title="Delete">×</button>' +
        '</div>' +
        '<div class="row-bottom">' +
          '<div class="step-preview">' + this.previewHtml(step, idx) + '</div>' +
        '</div>'
      );
    },

    updateRowStsLink() {
      // STS links live in the page nav; per-row links removed.
    },

    defaultParamsForFunction(fid, oldParams) {
      const def = this.catalogMap[fid];
      const params = {};
      oldParams = oldParams || {};
      (def?.params || []).forEach((p) => {
        const k = p.key;
        if (p.type === 'filter_group') {
          params[k] = {};
          (p.fields || []).forEach((f) => {
            const fk = f.key;
            if (oldParams[k]?.[fk] !== undefined && oldParams[k][fk] !== '') {
              params[k][fk] = oldParams[k][fk];
            } else if (fk === 'current_location' && oldParams.location !== undefined && oldParams.location !== '') {
              params[k][fk] = oldParams.location;
            } else if (oldParams[fk] !== undefined && oldParams[fk] !== '') {
              params[k][fk] = oldParams[fk];
            } else if (f.default !== undefined) {
              params[k][fk] = f.default;
            } else {
              params[k][fk] = '';
            }
          });
          return;
        }
        if (k === 'percent' && oldParams.fraction !== undefined && oldParams.fraction !== '' && (oldParams.percent === undefined || oldParams.percent === '')) {
          const fraction = parseFloat(oldParams.fraction);
          params[k] = String(!isNaN(fraction) ? (fraction <= 1 ? Math.round(fraction * 100) : Math.round(fraction)) : (p.default || ''));
          return;
        }
        if (k === 'jobs' && p.type === 'jobs_multiselect') {
          let jobsVal = oldParams.jobs;
          if ((!jobsVal || jobsVal === '') && oldParams.scope) {
            if (oldParams.scope === 'locals') {
              jobsVal = this.nonStagingJobNames().join(',');
            } else {
              jobsVal = String(oldParams.scope);
            }
          }
          params[k] = jobsVal || this.nonStagingJobNames().join(',');
          return;
        }
        if (oldParams[k] !== undefined && oldParams[k] !== '') {
          params[k] = oldParams[k];
        } else if (p.default !== undefined && p.default !== '') {
          params[k] = p.default;
        } else {
          params[k] = '';
        }
      });
      return params;
    },

    compileOne(step, idx) {
      const def = this.catalogMap[step.function];
      let title = '';
      if (step.function === 'load_unload') {
        return this.compileLoadUnloadTitle(step.params?.filters);
      }
      if (step.function === 'fill_orders') {
        return this.compileFillOrdersTitle(step.params);
      }
      if (step.function === 'reposition_empties') {
        return this.compileRepositionTitle(step.params);
      }
      if (step.function === 'generate_orders') {
        return this.compileGenerateOrdersTitle(step.params);
      }
      if (step.function === 'auto_assign_locals') {
        return this.compileAutoAssignTitle(step.params);
      }
      if (step.function === 'pick_up_cars' && !String(step.params?.job || '').trim()) {
        return 'Pick Up Cars locals';
      }
      if (step.function === 'set_out_cars'
        && !String(step.params?.job || '').trim()
        && !String(step.params?.location || '').trim()) {
        return 'Set Out Cars locals';
      }
      if (step.function === 'goto') {
        const p = step.params || {};
        if (p.section_label) return 'Goto ' + p.section_label;
        const sec = (this.runSections || this.buildRunSections()).find((s) => s.id === p.section);
        if (sec) return 'Goto ' + sec.label;
        if (p.step) return 'Goto step ' + p.step;
      }
      if (step.function === 'if_then') {
        const p = step.params || {};
        const v = p.variable === 'session_nbr' ? 'session #' : (p.variable || 'session #');
        return ('If ' + v + ' ' + (p.operator || '') + ' ' + (p.value || '')).replace(/\s+/g, ' ').trim();
      }
      if (step.function === 'text_instruction') {
        return (step.params?.instruction || '').trim();
      }
      if (step.function === 'generate_switchlists') {
        const p = step.params || {};
        const jobs = String(p.jobs || 'all').trim() || 'all';
        const fmt = String(p.format || 'phased').trim() || 'phased';
        return ('Generate Switch Lists ' + jobs + ' (' + fmt + ')').replace(/\s+/g, ' ').trim();
      }
      if (def) {
        let t = def.gui_template || def.label || '';
        const p = step.params || {};
        if (step.function === 'pick_up_cars') {
          t = t.replace('{location_suffix}', p.location ? p.location : '');
        }
        title = t.replace(/\{(\w+)\}/g, (_, k) => (p[k] != null && p[k] !== '' ? String(p[k]) : '')).replace(/\s+/g, ' ').trim();
      }
      if (!title && step.instruction) title = String(step.instruction).trim();
      if (!title && step.params?.label) title = String(step.params.label).trim();
      if (!title && step.function) title = step.function.replace(/_/g, ' ');
      if (!title && idx != null) title = 'Step ' + (idx + 1);
      return title || '?';
    },

    previewHtml(step, idx) {
      if (!step?.function) {
        return '<span class="preview-fallback">-- Select a command --</span>';
      }
      const def = this.catalogMap[step.function];
      if (step.function === 'section_label') {
        const label = step.params?.label || def?.label || 'Section label';
        let html = '<span class="preview-cmd">' + this.escapeHtml(label) + '</span>';
        const remarks = this.rowRemarksText(step);
        if (remarks) {
          html += '<span class="preview-remarks">' + this.escapeHtml(remarks) + '</span>';
        }
        return html;
      }
      if (step.function === 'text_instruction') {
        const text = (step.params?.instruction || '').trim() || '?';
        return '<span class="preview-cmd">' + this.escapeHtml(text) + '</span>';
      }
      if (!this.isAdderFunction(step.function)) {
        const text = this.compileOne(step, idx);
        return '<span class="preview-cmd">' + this.escapeHtml(text || def?.label || step.function) + '</span>';
      }
      const cmd = def?.label || (step.function || '').replace(/_/g, ' ');
      let html = '<span class="preview-cmd">' + this.escapeHtml(cmd) + '</span>';
      if (step.function === 'load_unload' || step.function === 'fill_orders' || step.function === 'reposition_empties' || step.function === 'generate_orders' || step.function === 'auto_assign_locals' || step.function === 'goto' || step.function === 'if_then') {
        const compiled = this.compileOne(step, idx);
        if (compiled) {
          return '<span class="preview-cmd">' + this.escapeHtml(compiled) + '</span>';
        }
      }
      const params = step.params || {};
      const pdefs = def?.params || [];
      let pi = 0;
      pdefs.forEach((p) => {
        if (this.shouldHideInlineParam(step, p)) return;
        const val = params[p.key];
        if (val !== undefined && String(val).trim() !== '') {
          html += '<span class="preview-param p-' + (pi % 5) + '" title="' + this.escapeHtml(p.label) + '">' +
            this.escapeHtml(String(val)) + '</span>';
          pi++;
        }
      });
      if (pi === 0) {
        const compiled = this.compileOne(step, idx);
        if (compiled && compiled !== cmd) {
          html += '<span class="preview-fallback">' + this.escapeHtml(compiled) + '</span>';
        }
      }
      return html;
    },

    updateRowPreview(row, idx) {
      const step = this.recipe.steps[idx];
      if (!step) return;
      const preview = row.querySelector('.step-preview');
      if (preview) preview.innerHTML = this.previewHtml(step, idx);
    },

    refreshRowParams(idx) {
      const list = this.el('steps-list');
      const row = list?.querySelector('.step-row[data-idx="' + idx + '"]');
      if (!row) return;
      const step = this.recipe.steps[idx];
      const paramsEl = row.querySelector('.row-params');
      if (paramsEl) paramsEl.innerHTML = this.paramsHtmlForStep(step, idx);
      const top = row.querySelector('.row-top');
      if (top) {
        top.classList.toggle('row-has-filters', step.function === 'load_unload' || step.function === 'fill_orders' || step.function === 'reposition_empties');
      }
      row.className = 'step-row ' + this.stepColorClass(step);
      this.updateRowStsLink(row, step);
      this.updateRowPreview(row, idx);
    },

    bindRowEvents(row, idx) {
      row.querySelector('[data-fn-select]')?.addEventListener('change', (e) => {
        const newFn = e.target.value;
        if (newFn !== 'text_instruction') {
          delete e.target.dataset.actualFn;
        }
        this.syncStepFromRow(row, idx);
        this.recipe.steps[idx].function = newFn;
        this.recipe.steps[idx].params = newFn
          ? this.defaultParamsForFunction(newFn, this.recipe.steps[idx].params)
          : {};
        this.refreshRowParams(idx);
        const updatedRow = this.el('steps-list')?.querySelector('.step-row[data-idx="' + idx + '"]');
        if (updatedRow) {
          updatedRow.className = 'step-row ' + this.stepColorClass(this.recipe.steps[idx]);
          this.updateRowStsLink(updatedRow, this.recipe.steps[idx]);
        }
        this.setStatus(
          newFn ? ('Step ' + (idx + 1) + ': ' + (this.catalogMap[newFn]?.label || newFn)) : ('Step ' + (idx + 1) + ': pick a command'),
          'info'
        );
      });

      row.querySelector('.btn-del')?.addEventListener('click', () => {
        if (!confirm('Delete step ' + (idx + 1) + '?')) return;
        this.syncAllStepsFromDom();
        this.recipe.steps.splice(idx, 1);
        this.markDirty();
        this.renderSteps({ skipSync: true, preserveScroll: true });
        this.setStatus('Deleted step', 'ok');
      });

      row.querySelector('.btn-insert-before')?.addEventListener('click', () => {
        this.insertStepBelow(idx);
      });

      const onRowEdit = () => {
        this.syncStepFromRow(row, idx);
        row.className = 'step-row ' + this.stepColorClass(this.recipe.steps[idx]);
        this.updateRemarksLayout(row);
        this.updateRowPreview(row, idx);
      };
      row.addEventListener('change', (e) => {
        const target = e.target;
        if (target.matches('[data-param="mode"]')) {
          this.syncStepFromRow(row, idx);
          this.refreshRowParams(idx);
          return;
        }
        if (target.matches('[data-fill-source]')) {
          const groupKey = target.getAttribute('data-fill-source-group') || 'car_filters';
          const hidden = row.querySelector('[data-param="' + groupKey + '.categories"]');
          if (hidden) {
            const selected = Array.from(
              row.querySelectorAll('[data-fill-source][data-fill-source-group="' + groupKey + '"]:checked')
            ).map((node) => node.value);
            hidden.value = selected.join(',');
          }
        }
        onRowEdit();
      });
      row.addEventListener('input', onRowEdit);
      this.updateRemarksLayout(row);

      row.querySelector('.handle')?.addEventListener('mousedown', (e) => e.stopPropagation());

      row.addEventListener('dragstart', (e) => {
        if (e.target.closest('select, input, button')) { e.preventDefault(); return; }
        this.syncAllStepsFromDom();
        this.dragIndex = idx;
        row.classList.add('dragging');
      });
      row.addEventListener('dragend', () => {
        row.classList.remove('dragging');
        this.dragIndex = null;
        this.el('steps-list')?.querySelectorAll('.drag-over').forEach((n) => n.classList.remove('drag-over'));
      });
      row.addEventListener('dragover', (e) => {
        e.preventDefault();
        if (this.dragIndex !== null && this.dragIndex !== idx) row.classList.add('drag-over');
      });
      row.addEventListener('dragleave', () => row.classList.remove('drag-over'));
      row.addEventListener('drop', (e) => {
        e.preventDefault();
        row.classList.remove('drag-over');
        if (this.dragIndex === null || this.dragIndex === idx) return;
        const moved = this.recipe.steps.splice(this.dragIndex, 1)[0];
        this.recipe.steps.splice(idx, 0, moved);
        this.markDirty();
        this.renderSteps({ preserveScroll: true });
      });
    },

    renderSteps(options) {
      options = options || {};
      const scrollY = options.preserveScroll ? window.scrollY : null;

      if (!options.skipSync) {
        this.syncAllStepsFromDom();
      }

      const list = this.el('steps-list');
      if (!list) return;
      list.innerHTML = '';

      this.recipe.steps.forEach((step, idx) => {
        const row = document.createElement('div');
        row.className = 'step-row ' + this.stepColorClass(step);
        row.draggable = true;
        row.dataset.idx = String(idx);
        row.innerHTML = this.stepRowInnerHtml(step, idx);
        this.bindRowEvents(row, idx);
        list.appendChild(row);
      });

      this.syncInsertUi();
      this.highlightInsertTarget();

      if (scrollY != null) window.scrollTo(0, scrollY);

      if (options.scrollToIndex != null) {
        const target = list.querySelector('.step-row[data-idx="' + options.scrollToIndex + '"]');
        if (target) {
          target.classList.add('insert-marker');
          target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
      }

      const count = this.el('step-count');
      if (count) count.textContent = '(' + this.recipe.steps.length + ')';
      this.syncRunDefaults();
      this.syncEditorSectionSelect();
      this.syncStepVisibility();
    },

    isExecutableStep(step) {
      const fid = step?.function || '';
      if (['section_label', 'text_instruction', 'marker', 'goto', 'if_then'].includes(fid)) {
        return false;
      }
      const def = this.catalogMap[fid];
      if (!def) return false;
      return !!def.runnable;
    },

    resolveGotoTarget(step, fromStep) {
      const p = step?.params || {};
      const sections = this.buildRunSections();
      let target = 0;
      if (p.section) {
        const sec = sections.find((s) => s.id === p.section);
        if (sec) target = sec.start;
      }
      if (!target) {
        const label = String(p.section_label || '').trim();
        if (label) {
          const sec = sections.find((s) => {
            const sl = String(s.label || '').trim();
            return sl === label || sl.includes(label) || label.includes(sl);
          });
          if (sec) target = sec.start;
        }
      }
      if (!target) {
        const n = parseInt(p.step, 10);
        if (n > 0) target = n;
      }
      if (fromStep > 0 && target > 0 && target <= fromStep) {
        return 0;
      }
      return target;
    },

    evaluateCondition(params) {
      params = params || {};
      const ctx = this.runOptions?.condition_context || {};
      const variable = params.variable || 'session_nbr';
      const operator = params.operator || '>=';
      const value = params.value ?? '0';
      if (ctx[variable] == null) return true;
      const left = parseFloat(ctx[variable]);
      const right = parseFloat(value);
      if (Number.isNaN(left) || Number.isNaN(right)) return true;
      switch (operator) {
        case '=': return left === right;
        case '!=': return left !== right;
        case '<': return left < right;
        case '<=': return left <= right;
        case '>': return left > right;
        case '>=': return left >= right;
        default: return true;
      }
    },

    computeExecutionPath(fromStep, toStep) {
      const steps = this.recipe.steps || [];
      const executed = new Set();
      if (!steps.length) return executed;
      let pc = Math.max(0, fromStep - 1);
      const end = Math.min(steps.length, toStep);
      const maxIter = Math.max(500, (end - fromStep + 1) * 100);
      let iter = 0;
      while (pc >= fromStep - 1 && pc < end && iter++ < maxIter) {
        const step = steps[pc];
        if (!step) {
          pc++;
          continue;
        }
        const fid = step.function || '';
        if (fid === 'stop') {
          if (this.isExecutableStep(step)) executed.add(pc + 1);
          break;
        }
        if (fid === 'goto') {
          const target = this.resolveGotoTarget(step, pc + 1);
          if (target >= 1 && target <= steps.length) {
            pc = target - 1;
          } else {
            pc++;
          }
          continue;
        }
        if (fid === 'if_then') {
          const ok = this.evaluateCondition(step.params || {});
          pc++;
          if (!ok) pc++;
          continue;
        }
        if (this.isExecutableStep(step)) {
          executed.add(pc + 1);
        }
        pc++;
      }
      return executed;
    },

    shouldShowStepRow(idx) {
      if (!this.hideNonExecute) return true;
      const step = this.recipe.steps[idx];
      if (!step || !this.isExecutableStep(step)) return false;
      const stepNum = idx + 1;
      const { start, stop } = this.getRunStepRange();
      if (stepNum >= start && stepNum <= stop) {
        if (!this.executionPathSteps) {
          this.executionPathSteps = this.computeExecutionPath(start, stop);
        }
        return this.executionPathSteps.has(stepNum);
      }
      return true;
    },

    syncStepVisibility() {
      const list = this.el('steps-list');
      if (!list) return;
      this.executionPathSteps = null;
      if (this.hideNonExecute) {
        const { start, stop } = this.getRunStepRange();
        this.executionPathSteps = this.computeExecutionPath(start, stop);
      }
      let visible = 0;
      list.querySelectorAll('.step-row[data-idx]').forEach((row) => {
        const idx = parseInt(row.dataset.idx, 10);
        const show = this.shouldShowStepRow(idx);
        row.classList.toggle('step-row-hidden', !show);
        if (show) visible++;
      });
      const count = this.el('step-count');
      if (count && this.hideNonExecute) {
        count.textContent = '(' + visible + ' of ' + this.recipe.steps.length + ')';
      } else if (count) {
        count.textContent = '(' + this.recipe.steps.length + ')';
      }
    },

    getRunStepRange() {
      const total = Math.max(1, this.recipe.steps?.length || 1);
      let start = parseInt(this.el('run-start')?.value, 10) || 1;
      let stop = parseInt(this.el('run-stop')?.value, 10) || total;
      start = Math.max(1, Math.min(start, total));
      stop = Math.max(start, Math.min(stop, total));
      return { start, stop, total };
    },

    syncRunRangeHighlight() {
      const list = this.el('steps-list');
      if (!list) return;
      const { start, stop } = this.getRunStepRange();
      list.querySelectorAll('.step-row[data-idx]').forEach((row) => {
        const stepNum = parseInt(row.dataset.idx, 10) + 1;
        row.classList.toggle('run-range', stepNum >= start && stepNum <= stop);
      });
    },

    editorSections() {
      return this.buildRunSections().filter((s) => s.id !== 'all');
    },

    syncEditorSectionSelect() {
      const sel = this.el('editor-section');
      const btn = this.el('btn-goto-section');
      if (!sel) return;
      const sections = this.editorSections();
      const current = sel.value;
      if (!sections.length) {
        sel.innerHTML = '<option value="">No sections</option>';
        sel.disabled = true;
        if (btn) btn.disabled = true;
        return;
      }
      let html = '';
      sections.forEach((s) => {
        const text = this.truncateSectionLabel(s.label, 48) + ' (step ' + s.start + ')';
        html += '<option value="' + this.escapeHtml(s.id) + '" title="' + this.escapeHtml(s.label) + '">' +
          this.escapeHtml(text) + '</option>';
      });
      sel.innerHTML = html;
      sel.disabled = false;
      if (sections.some((s) => s.id === current)) {
        sel.value = current;
      } else {
        sel.value = sections[0].id;
      }
      this.syncEditorSectionUi();
    },

    syncEditorSectionUi() {
      const sel = this.el('editor-section');
      const btn = this.el('btn-goto-section');
      if (!btn || !sel) return;
      btn.disabled = sel.disabled || !sel.value;
    },

    scrollToEditorSection() {
      const sel = this.el('editor-section');
      if (!sel?.value) return;
      const sec = this.editorSections().find((s) => s.id === sel.value);
      if (!sec) return;
      const list = this.el('steps-list');
      const idx = sec.start - 1;
      const row = list?.querySelector('.step-row[data-idx="' + idx + '"]');
      if (!row) {
        this.setStatus('Section not found at step ' + sec.start, 'err');
        return;
      }
      list.querySelectorAll('.step-row.section-highlight').forEach((n) => n.classList.remove('section-highlight'));
      row.classList.add('section-highlight');
      row.scrollIntoView({ block: 'start', behavior: 'smooth' });
      window.setTimeout(() => row.classList.remove('section-highlight'), 2500);
      this.setStatus('Jumped to ' + this.truncateSectionLabel(sec.label, 56) + ' (step ' + sec.start + ')', 'ok');
    },

    addStep() {
      this.syncAllStepsFromDom();
      this.readInsertFromDom();
      const at = this.resolveInsertIndex();
      this.insertStepAt(at);
    },

    insertStepBelow(idx) {
      this.syncAllStepsFromDom();
      this.insertStepAt(idx + 1, 'below step ' + (idx + 1));
    },

    insertStepAt(at, posLabel) {
      this.recipe.steps.splice(at, 0, this.blankStep());
      this.markDirty();
      this.renderSteps({ skipSync: true, scrollToIndex: at });
      if (!posLabel) {
        posLabel = 'before step ' + (at + 1);
        if (this.insertMode === 'end' || at >= this.recipe.steps.length - 1) {
          posLabel = 'at the end';
        } else if (this.insertMode === 'after') {
          posLabel = 'after step ' + at;
        }
      }
      this.setStatus('Added blank step ' + posLabel, 'ok');
      const row = this.el('steps-list')?.querySelector('.step-row[data-idx="' + at + '"]');
      row?.querySelector('[data-fn-select]')?.focus();
    },

    async loadCompiled() {
      const d = await this.api('compile', 'POST', { recipe: this.recipe });
      this.compiledSteps = d.compiled || [];
    },

    syncCsvSelect(options) {
      options = options || {};
      const sel = this.el('csv-file');
      if (!sel) return;
      const files = this.csvFiles || [];
      const placeholder = 'Select a file to load';
      let html = '';
      if (files.length !== 1) {
        html += '<option value="">' + this.escapeHtml(placeholder) + '</option>';
      }
      html += files.map((f) =>
        '<option value="' + this.escapeHtml(f) + '">' + this.escapeHtml(f) + '</option>'
      ).join('');
      sel.innerHTML = html;
      if (files.length === 1) {
        sel.value = files[0];
        this.activeCsv = files[0];
      } else if (files.length > 1) {
        if (!options.preferEmpty && this.activeCsv && files.includes(this.activeCsv)) {
          sel.value = this.activeCsv;
        } else {
          sel.value = '';
          if (options.preferEmpty) {
            this.activeCsv = '';
          }
        }
      } else {
        sel.value = '';
      }
      this.syncCsvSaveFilename();
      this.syncCsvDownloadLinks();
    },

    syncCsvSaveFilename() {
      const sel = this.el('csv-file');
      const input = this.el('csv-save-as');
      if (!input || this.dirty) return;
      input.value = sel?.value || this.activeCsv || '';
    },

    readSaveCsvFilename() {
      const input = this.el('csv-save-as');
      let name = (input?.value || this.activeCsv || '').trim();
      if (!name) {
        throw new Error('Enter a CSV filename to save');
      }
      if (!/\.csv$/i.test(name)) name += '.csv';
      return name.replace(/[^a-zA-Z0-9._-]/g, '_').replace(/^\.+/, '');
    },

    syncCsvDownloadLinks() {
      const sel = this.el('csv-file');
      const name = sel?.value || this.activeCsv;
      const dl = this.el('csv-download');
      const json = this.el('json-download');
      if (!name) {
        if (dl) {
          dl.removeAttribute('href');
          dl.classList.add('disabled');
        }
        if (json) {
          json.removeAttribute('href');
          json.classList.add('disabled');
        }
        return;
      }
      const q = encodeURIComponent(name);
      if (dl) {
        dl.href = 'operational_steps_api.php?action=download&kind=csv&csv_file=' + q;
        dl.download = name;
        dl.classList.remove('disabled');
      }
      if (json) {
        json.href = 'operational_steps_api.php?action=download&kind=recipe&csv_file=' + q;
        json.download = name === 'STS_OPERATIONAL_STEPS.csv'
          ? 'STS_OPERATIONAL_RECIPE.json'
          : name.replace(/\.csv$/i, '.recipe.json');
        json.classList.remove('disabled');
      }
    },

    async loadCsvList(options) {
      options = options || {};
      const prevActive = this.activeCsv;
      const d = await this.api('list_csv');
      this.csvFiles = d.files || [];
      if (this.csvFiles.length === 1) {
        this.activeCsv = d.active_csv || this.csvFiles[0];
        this.autoLoadCsv = true;
        this.syncCsvSelect();
        return;
      }
      this.autoLoadCsv = false;
      if (this.csvFiles.length > 1) {
        if (options.keepActive && prevActive && this.csvFiles.includes(prevActive)) {
          this.activeCsv = prevActive;
          this.syncCsvSelect();
        } else {
          this.activeCsv = '';
          this.syncCsvSelect({ preferEmpty: true });
        }
        return;
      }
      this.activeCsv = '';
      this.syncCsvSelect({ preferEmpty: true });
    },

    async loadRecipe(options) {
      options = options || {};
      if (!this.activeCsv) {
        this.recipe = { version: 1, name: 'workflow', steps: [] };
        this.compiledSteps = [];
        if (options.clearDirty !== false) {
          this.clearDirty();
        }
        return;
      }
      if (options.fromCsv) {
        const imported = await this.api('import_csv', 'POST', {
          use_file: true,
          csv_file: this.activeCsv,
        });
        this.recipe = imported.recipe;
        this.activeCsv = imported.csv_file || this.activeCsv;
      } else {
        const d = await this.api('recipe', 'GET', null, { csv_file: this.activeCsv });
        this.recipe = d.recipe;
        this.activeCsv = d.csv_file || this.activeCsv;
        if (!this.recipe.steps?.length && this.activeCsv) {
          try {
            const imported = await this.api('import_csv', 'POST', {
              use_file: true,
              csv_file: this.activeCsv,
            });
            this.recipe = imported.recipe;
          } catch (e) {
            this.recipe = { version: 1, name: 'workflow', steps: [] };
          }
        }
      }
      await this.loadCompiled();
      this.syncCsvSelect();
      if (options.clearDirty !== false) {
        this.clearDirty();
      }
    },

    async loadSelectedCsv() {
      const sel = this.el('csv-file');
      const next = sel?.value || '';
      if (!next) {
        this.setStatus('Choose a CSV file, then click Load', 'info');
        return;
      }
      if (this.dirty) {
        if (!confirm('Load ' + next + ' from disk? Unsaved changes will be lost.')) {
          sel.value = this.activeCsv;
          return;
        }
      }
      this.activeCsv = next;
      await this.api('set_active_csv', 'POST', { csv_file: this.activeCsv });
      await this.loadRecipe({ fromCsv: true });
      await this.loadRunOptions();
      this.renderSteps({ skipSync: true });
      this.syncCsvSaveFilename();
      this.setStatus(
        'Loaded ' + this.activeCsv + ' — ' + this.recipe.steps.length + ' steps · DB session ' +
        (this.runOptions?.current_session ?? '?'),
        'ok'
      );
    },

    resetEditor() {
      const stepCount = this.recipe.steps?.length || 0;
      if (this.dirty || stepCount > 0) {
        if (!confirm('Reset the editor? All unsaved steps will be cleared.')) {
          return;
        }
      }
      this.recipe = this.freshEditorRecipe();
      this.compiledSteps = [];
      this.markDirty();
      this.renderSteps({ skipSync: true, scrollToIndex: 0 });
      this.setStatus('Editor reset — step 1 is blank; add commands or Save to write to disk', 'ok');
      this.el('steps-list')?.querySelector('[data-fn-select]')?.focus();
    },

    async loadCatalog() {
      this.catalog = await this.api('catalog');
      this.dynamicOptions = this.catalog.dynamic_options || {};
      this.buildCatalogMap();
    },

    async saveRecipe() {
      this.syncAllStepsFromDom();
      const saveAs = this.readSaveCsvFilename();
      const d = await this.api('save', 'POST', { recipe: this.recipe, csv_file: saveAs });
      this.activeCsv = d.csv_file || saveAs;
      await this.loadCsvList({ keepActive: true });
      await this.loadCompiled();
      this.renderSteps();
      this.clearDirty();
      this.setStatus(
        'Saved ' + this.activeCsv + ' (' + (d.rows || this.recipe.steps.length) + ' steps)',
        'ok'
      );
      return d;
    },

    async normalizeRecipe() {
      this.syncAllStepsFromDom();
      const data = await this.api('normalize_recipe', 'POST', { recipe: this.recipe });
      this.recipe = data.recipe;
      await this.loadCompiled();
      this.renderSteps({ skipSync: true });
      this.setStatus('Normalized ' + data.rows + ' steps', 'ok');
    },

    async importCsv() {
      return this.promptImportCsv();
    },

    promptImportCsv() {
      this.el('csv-import-file')?.click();
    },

    async importCsvFile(file) {
      if (!file) return;
      const name = file.name || 'imported.csv';
      const text = await file.text();
      const d = await this.api('import_csv', 'POST', { csv: text });
      const importedSteps = d.recipe?.steps || [];
      if (!importedSteps.length) {
        this.setStatus('No steps found in ' + name, 'err');
        return;
      }
      const currentCount = this.recipe.steps?.length || 0;
      let append = false;
      if (currentCount > 0) {
        if (confirm(
          'Append ' + importedSteps.length + ' steps from "' + name + '" to the current ' +
          currentCount + ' steps?\n\nOK = Append\nCancel = Replace all steps instead'
        )) {
          append = true;
        } else if (!confirm(
          'Replace all ' + currentCount + ' steps with "' + name + '"?\n\nCancel = abort import'
        )) {
          return;
        }
      } else if (!confirm(
        'Import ' + importedSteps.length + ' steps from "' + name + '"?\n\nNothing is saved until you click Save.'
      )) {
        return;
      }
      if (append) {
        this.recipe.steps = (this.recipe.steps || []).concat(importedSteps);
      } else {
        this.recipe = d.recipe;
      }
      const saveAs = this.el('csv-save-as');
      if (saveAs) saveAs.value = name;
      this.markDirty();
      await this.loadCompiled();
      this.renderSteps({ skipSync: true });
      const action = append ? 'Appended' : 'Imported';
      this.setStatus(
        action + ' ' + importedSteps.length + ' steps from ' + name +
        ' (' + (this.recipe.steps?.length || 0) + ' total) — Save to write to disk',
        'ok'
      );
    },

    async loadRunOptions() {
      this.runOptions = await this.simulatorApi('run_options');
      this.syncRunDefaults();
    },

    truncateSectionLabel(label, max) {
      label = String(label || '').trim();
      max = max || 72;
      return label.length <= max ? label : label.slice(0, max - 1) + '…';
    },

    buildRunSections() {
      const steps = this.recipe.steps || [];
      const total = steps.length;
      const sections = [{
        id: 'all',
        label: 'All',
        start: 1,
        stop: Math.max(1, total),
      }];
      steps.forEach((step, i) => {
        if (step.function !== 'section_label') return;
        const label = (step.params?.label || '').trim() || ('Section at step ' + (i + 1));
        const start = i + 1;
        let stop = total;
        for (let j = i + 1; j < steps.length; j++) {
          const fid = steps[j].function;
          if (fid === 'section_label') {
            stop = j;
            break;
          }
          if (fid === 'goto') {
            stop = j + 1;
            break;
          }
        }
        sections.push({
          id: 'step-' + start,
          label: label,
          start: start,
          stop: stop,
        });
      });
      return sections;
    },

    syncRunSectionSelect() {
      const sel = this.el('run-section');
      if (!sel) return;
      this.runSections = this.buildRunSections();
      const current = sel.value || 'all';
      let html = '';
      this.runSections.forEach((s) => {
        const text = s.id === 'all' ? 'All' : this.truncateSectionLabel(s.label);
        html += '<option value="' + this.escapeHtml(s.id) + '" title="' + this.escapeHtml(s.label) + '">' +
          this.escapeHtml(text) + ' (steps ' + s.start + '–' + s.stop + ')</option>';
      });
      sel.innerHTML = html;
      if (this.runSections.some((s) => s.id === current)) {
        sel.value = current;
      } else {
        sel.value = 'all';
      }
    },

    applyRunSection() {
      const id = this.el('run-section')?.value || 'all';
      const sec = (this.runSections || this.buildRunSections()).find((s) => s.id === id);
      if (!sec) return;
      const startEl = this.el('run-start');
      const stopEl = this.el('run-stop');
      const total = Math.max(1, this.recipe.steps?.length || 1);
      if (startEl) {
        delete startEl.dataset.userSet;
        startEl.min = '1';
        startEl.max = String(total);
        startEl.value = String(sec.start);
      }
      if (stopEl) {
        delete stopEl.dataset.userSet;
        stopEl.min = '1';
        stopEl.max = String(total);
        stopEl.value = String(sec.stop);
      }
      this.syncRunRangeHighlight();
      this.syncStepVisibility();
    },

    syncRunDefaults() {
      const total = Math.max(1, this.recipe.steps?.length || 1);
      this.syncRunSectionSelect();
      const startEl = this.el('run-start');
      const stopEl = this.el('run-stop');
      const userSet = startEl?.dataset.userSet === '1' || stopEl?.dataset.userSet === '1';
      if (!userSet) {
        this.applyRunSection();
      } else {
        if (startEl) {
          startEl.min = '1';
          startEl.max = String(total);
        }
        if (stopEl) {
          stopEl.min = '1';
          stopEl.max = String(total);
        }
      }
      const sumSession = this.el('run-db-session');
      if (sumSession) {
        sumSession.textContent = this.runOptions?.current_session ?? '—';
      }
      this.syncRunRangeHighlight();
      this.syncStepVisibility();
    },

    async runWorkflow(options) {
      options = options || {};
      const repeatEnabled = !!this.el('run-repeat-enabled')?.checked;
      const repeat = repeatEnabled
        ? Math.max(2, parseInt(this.el('run-repeat')?.value, 10) || 2)
        : 1;
      this.syncAllStepsFromDom();
      if (options.saveFirst) {
        await this.saveRecipe();
      }
      const total = Math.max(1, this.recipe.steps?.length || 1);
      let start = parseInt(this.el('run-start')?.value, 10) || 1;
      let stop = parseInt(this.el('run-stop')?.value, 10) || total;
      start = Math.max(1, Math.min(start, total));
      stop = Math.max(start, Math.min(stop, total));
      if (stop < start) {
        throw new Error('Stop step must be at or after start step');
      }
      this.setStatus(
        repeat > 1 ? ('Running ' + repeat + ' cycles (steps ' + start + '–' + stop + ')…') : ('Running steps ' + start + '–' + stop + '…'),
        'info'
      );
      const sectionId = this.el('run-section')?.value || 'all';
      const runBody = {
        recipe: this.recipe,
        save_recipe: true,
        session_count: repeat,
        csv_file: this.activeCsv,
      };
      let d;
      if (sectionId === 'all') {
        d = await this.simulatorApi('run', 'POST', Object.assign(runBody, {
          start_step: start,
          stop_step: stop,
        }));
      } else {
        d = await this.simulatorApi('run_section', 'POST', Object.assign(runBody, {
          section_id: sectionId,
          start_step: start,
          stop_step: stop,
        }));
      }
      const lines = (d.summary || []).slice();
      if (d.start_step) lines.unshift('Steps ' + d.start_step + '–' + d.stop_step);
      if (d.warnings?.length) lines.push('', ...d.warnings);
      if (d.index_url) lines.push('', 'Sessions: ' + d.index_url);
      if (d.session_url) lines.push('Latest: ' + d.session_url);
      this.log(lines.join('\n'));
      this.setStatus(
        'Complete — session ' + (d.session || (d.sessions || []).join(', ') || ''),
        'ok'
      );
      this.runOptions = await this.simulatorApi('run_options');
      this.syncRunDefaults();
      return d;
    },
  };

  global.WorkflowUI = WorkflowUI;
})(window);
