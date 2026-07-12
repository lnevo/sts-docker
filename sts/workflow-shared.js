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
    workflowFiles: [],
    activeWorkflow: '',
    autoLoadWorkflow: false,
    insertMode: 'end',
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

    clearRunCompleteActions() {
      const node = this.el('run-complete-actions');
      if (!node) return;
      node.textContent = '';
      node.hidden = true;
    },

    showRunCompleteActions(result) {
      const node = this.el('run-complete-actions');
      if (!node) return;
      node.textContent = '';
      const sessions = (result.sessions && result.sessions.length)
        ? result.sessions
        : (result.session ? [result.session] : []);
      const latest = sessions.length ? sessions[sessions.length - 1] : '';
      const href = latest
        ? ('/sts/session_overview.php?session=' + encodeURIComponent(latest))
        : (result.index_url || '/sts/session_overview.php');
      const link = document.createElement('a');
      link.className = 'btn btn-dark btn-sm';
      link.href = href;
      link.textContent = latest
        ? ('View session ' + latest + ' overview')
        : 'View session overview';
      node.appendChild(link);
      node.hidden = false;
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

    rowClassName(step) {
      return 'step-row ' + this.stepColorClass(step) + (step && step.enabled === false ? ' step-disabled' : '');
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
      const selectedHint = this.catalogHintText(step, actualFn);
      const adder = this.catalog.adder_functions || [];
      const cats = this.catalog.adder_categories || {};
      const byGroup = {};
      adder.forEach((f) => {
        if (f.disabled) return;
        const g = f.adder_group || 'during';
        (byGroup[g] = byGroup[g] || []).push(f);
      });
      const order = Object.keys(cats).length ? Object.keys(cats) : Object.keys(byGroup);
      let html = '<select class="row-fn field-select" data-fn-select data-row="' + rowIdx + '"';
      if (selectedHint) {
        html += ' title="' + this.escapeHtml(selectedHint) + '"';
      }
      if (displayId === 'text_instruction' && actualFn && actualFn !== 'text_instruction') {
        html += ' data-actual-fn="' + this.escapeHtml(actualFn) + '"';
      }
      html += '>';
      const hasSelection = displayId && this.catalogMap[displayId];
      html += '<option value=""' + (!hasSelection ? ' selected' : '') + '>-- Select a command --</option>';
      order.forEach((g) => {
        const items = byGroup[g] || [];
        if (!items.length) return;
        html += '<optgroup label="' + this.escapeHtml(cats[g] || g) + '">';
        items.forEach((f) => {
          const hint = (f.description || '').trim();
          html += '<option value="' + f.id + '"' +
            (f.id === displayId ? ' selected' : '') +
            (hint ? ' title="' + this.escapeHtml(hint) + '"' : '') + '>' +
            this.escapeHtml(f.label || f.id) + '</option>';
        });
        html += '</optgroup>';
      });
      html += '</select>';
      return html;
    },

    catalogHintText(step, fid) {
      const fn = fid || step?.function || '';
      if (!fn) return '';
      return (step?.catalog_description || this.catalogMap[fn]?.description || '').trim();
    },

    resolveAutoAssignJobsValue(val) {
      return String(val || '').split(',').map((s) => s.trim()).filter(Boolean);
    },

    optionsForParam(p, context) {
      context = context || {};
      const from = p.options_from || p.type;
      const d = this.dynamicOptions;
      const paramKey = context.paramKey || '';
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
      if (from === 'station_locations') return d.station_locations || [];
      if (from === 'stations') {
        let stations = (d.stations || []).map((s) => ({ value: s.name, label: s.label }));
        if (paramKey.indexOf('car_filters.') === 0 || p.suppress_all_station_option) {
          stations = stations.filter((s) => String(s.value).toLowerCase() !== 'all');
        }
        return stations;
      }
      if (from === 'setout_locations') return d.setout_locations || [];
      if (from === 'scopes') return d.scopes || [];
      if (from === 'backups') return (d.backups || []).map((b) => ({ value: b, label: b }));
      if (from === 'condition_variables') {
        return (d.condition_variables || []).map((v) => ({
          value: v.key,
          label: v.label || v.key,
        }));
      }
      if (from === 'switchlist_trains' || from === 'job_or_all' || p.type === 'job_or_all') {
        const names = new Set(['all']);
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
            if ((context.step?.function !== 'goto' && context.step?.function !== 'if_then') || fromStep <= 0) return true;
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
      const lbl = (visibleLabels && p.label)
        ? '<span class="' + lblClass + '">' + this.escapeHtml(p.label) + '</span>'
        : '';
      const opts = this.optionsForParam(p, { rowIdx, step, paramKey });
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
      if (['job', 'location', 'station', 'backup', 'scope', 'setout_location', 'station_location', 'shipment', 'car_code', 'commodity', 'switchlist_trains', 'job_or_all', 'workflow_section'].includes(p.type) || opts.length) {
        const allowAny = p.allow_custom !== false
          && p.type !== 'backup'
          && p.type !== 'switchlist_trains'
          && p.type !== 'job_or_all'
          && !p.suppress_any_option;
        if (
          (paramKey.indexOf('car_filters.') === 0 && (p.key === 'current_station' || p.key === 'current_location'))
          || p.suppress_all_station_option
        ) {
          const v = String(val || '').trim();
          if (v.toLowerCase() === 'all' || v.toLowerCase() === 'any') {
            val = '';
          }
        }
        let h = '<label class="inline-field' + (visibleLabels ? ' inline-field-labeled' : '') + '">' + lbl +
          '<select class="field-select" data-param="' + this.escapeHtml(paramKey) + '" id="' + id + '">';
        if (allowAny && p.type !== 'setout_location') {
          const anyLabel = (p.type === 'station_location' || paramKey.indexOf('car_filters.') === 0) ? 'All' : 'Any';
          h += '<option value=""' + (String(val) === '' ? ' selected' : '') + '>' + anyLabel + '</option>';
        }
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
      if (p.type === 'station_location' || p.type === 'text') {
        return '<label class="inline-field' + (visibleLabels ? ' inline-field-labeled' : '') + '">' + lbl +
          '<input type="text" class="field-input" data-param="' + this.escapeHtml(paramKey) + '" id="' + id + '" value="' +
          this.escapeHtml(String(val)) + '"></label>';
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
        const selected = this.resolveAutoAssignJobsValue(val);
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
      if (p.layout === 'train_car') {
        return this.trainCarFilterGroupHtml(p, values, rowIdx);
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

    trainCarFilterGroupHtml(p, values, rowIdx) {
      const fields = p.fields || [];
      values = values || {};
      const byKey = {};
      fields.forEach((f) => { byKey[f.key] = f; });
      const row1 = ['pickup_location', 'reporting_marks', 'car_code', 'status', 'consignment'];
      const row2 = ['final_destination', 'loading_location', 'unloading_location'];
      let html = '<div class="param-filter-grid param-filter-grid-train-car">';
      html += '<span class="param-filter-group-lbl">' + this.escapeHtml(p.label || 'Car filters') + '</span>';
      html += '<div class="param-filter-row param-filter-row-train-car">';
      row1.forEach((key) => {
        const f = byKey[key];
        if (f) html += this.inlineParamFieldHtml(f, values[f.key], rowIdx, p.key + '.' + f.key, true);
      });
      html += '</div>';
      html += '<div class="param-filter-row param-filter-row-train-car-locations">';
      row2.forEach((key) => {
        const f = byKey[key];
        if (f) html += this.inlineParamFieldHtml(f, values[f.key], rowIdx, p.key + '.' + f.key, true);
      });
      html += '</div></div>';
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
      const seed = String(params.seed || '').trim();
      if (seed) parts.push('seed=' + seed);
      if (!parts.length) return 'Generate Orders';
      return 'Generate Orders ' + parts.join('; ');
    },

    compileAutoAssignTitle(params) {
      params = params || {};
      const jobs = String(params.jobs || '').trim();
      if (!jobs) {
        return 'Assign Cars';
      }
      return 'Assign Cars ' + jobs.split(',').map((s) => s.trim()).filter(Boolean).join(', ');
    },

    compileTrainCarFiltersTitle(filters) {
      filters = filters || {};
      const parts = [];
      const labels = {
        pickup_location: 'pickup',
        reporting_marks: 'marks',
        car_code: 'car',
        status: 'status',
        consignment: 'consignment',
        final_destination: 'final',
        loading_location: 'load',
        unloading_location: 'unload',
      };
      Object.keys(labels).forEach((key) => {
        const v = String(filters[key] || '').trim();
        if (v) parts.push(labels[key] + '=' + v);
      });
      return parts.length ? parts.join('; ') : '';
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

    ifThenGotoParamsHtml(step, rowIdx) {
      const def = this.catalogMap.if_then;
      const values = step.params || {};
      const paramByKey = {};
      (def?.params || []).forEach((p) => { paramByKey[p.key] = p; });

      let html = '<div class="param-if-then-goto-grid">';

      html += '<div class="param-if-then-row">';
      if (paramByKey.variable) {
        html += this.inlineParamFieldHtml(
          { ...paramByKey.variable, label: 'If variable' },
          values.variable,
          rowIdx,
          undefined,
          true,
          step
        );
      }
      ['operator', 'value'].forEach((key) => {
        const p = paramByKey[key];
        if (p) html += this.inlineParamFieldHtml(p, values[key], rowIdx, undefined, true, step);
      });
      html += '</div>';

      html += '<div class="param-goto-row">';
      if (paramByKey.section) {
        html += this.inlineParamFieldHtml(
          { ...paramByKey.section, label: 'Goto section' },
          values.section,
          rowIdx,
          undefined,
          true,
          step
        );
      }
      html += '</div>';

      html += '</div>';
      return html;
    },

    gotoParamsHtml(step, rowIdx) {
      const def = this.catalogMap.goto;
      const values = step.params || {};
      const sectionParam = (def?.params || []).find((p) => p.key === 'section');
      if (!sectionParam) return '<span class="inline-empty">No parameters</span>';
      let html = '<div class="param-if-then-goto-grid">';
      html += '<div class="param-goto-row">';
      html += this.inlineParamFieldHtml(
        { ...sectionParam, label: 'Goto section' },
        values.section,
        rowIdx,
        undefined,
        true,
        step
      );
      html += '</div></div>';
      return html;
    },

    rowHasMultiRowParams(step) {
      if (!step?.function) return false;
      return step.function === 'if_then'
        || step.function === 'goto'
        || step.function === 'load_unload'
        || step.function === 'fill_orders'
        || step.function === 'reposition_empties'
        || step.function === 'pick_up_cars'
        || step.function === 'set_out_cars';
    },

    ifThenHasGoto(step) {
      if (!step || step.function !== 'if_then') return false;
      const p = step.params || {};
      return !!(String(p.section || '').trim() || String(p.section_label || '').trim() || parseInt(p.step, 10) > 0);
    },

    shouldHideInlineParam(step, p) {
      if (!step || !p) return false;
      if (step.function === 'section_label' && p.key === 'remarks') return true;
      if (step.function === 'goto' && (p.key === 'step' || p.key === 'section_label')) return true;
      if (step.function === 'if_then') {
        if (p.key === 'section_label' || p.key === 'step') return true;
      }
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
      if (step.function === 'if_then') {
        return this.ifThenGotoParamsHtml(step, rowIdx);
      }
      if (step.function === 'goto') {
        return this.gotoParamsHtml(step, rowIdx);
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
      const prev = this.recipe.steps[idx] || {};
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
      if ((step.function === 'goto' || step.function === 'if_then') && step.params.section) {
        const sec = this.buildRunSections().find((s) => s.id === step.params.section);
        if (sec) {
          step.params.section_label = sec.label;
          step.params.step = String(sec.start);
          if (sec.start <= idx + 1) {
            delete step.params.section;
            delete step.params.section_label;
            delete step.params.step;
          }
        }
      }
      if (step.function && this.isAdderFunction(step.function)) {
        if (prev.function === step.function && prev.catalog_description) {
          step.catalog_description = prev.catalog_description;
        } else {
          step.catalog_description = this.catalogMap[step.function]?.description || '';
        }
      } else if (prev.catalog_description) {
        step.catalog_description = prev.catalog_description;
      }
      if (desc) {
        step.description = desc;
      }
      if (step.function === 'section_label' && step.params.remarks !== undefined) {
        delete step.params.remarks;
      }
      const enabledBox = row.querySelector('[data-step-enabled]');
      if (enabledBox ? !enabledBox.checked : prev.enabled === false) {
        step.enabled = false;
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
        catalog_description: '',
      };
    },

    freshEditorRecipe() {
      return { version: 1, name: 'workflow', steps: [this.blankStep()] };
    },

    readInsertFromDom() {
      const mode = this.el('insert-mode')?.value || 'end';
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
      const mode = modeSel.value || 'end';
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

    moveStepToNumber(fromIdx, requestedNum) {
      const steps = this.recipe.steps;
      const n = steps.length;
      if (fromIdx < 0 || fromIdx >= n || n === 0) return fromIdx;

      let targetNum = parseInt(requestedNum, 10);
      if (isNaN(targetNum) || targetNum < 1) targetNum = 1;
      if (targetNum > n) targetNum = n;

      const toIdx = targetNum - 1;
      if (fromIdx === toIdx) return fromIdx;

      const moved = steps.splice(fromIdx, 1)[0];
      steps.splice(toIdx, 0, moved);
      return toIdx;
    },

    swapStepWithNeighbor(fromIdx, delta) {
      const steps = this.recipe.steps;
      const toIdx = fromIdx + delta;
      if (fromIdx < 0 || fromIdx >= steps.length || toIdx < 0 || toIdx >= steps.length) {
        return fromIdx;
      }
      const tmp = steps[fromIdx];
      steps[fromIdx] = steps[toIdx];
      steps[toIdx] = tmp;
      return toIdx;
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
      const stepNumSize = Math.max(2, String(this.recipe.steps.length).length);

      return (
        '<div class="row-top row-top-align-start' + (this.rowHasMultiRowParams(step) ? ' row-has-filters' : '') +
          this.remarksExpandedClass(step) + '">' +
          '<div class="step-num-control">' +
            '<input type="number" class="step-num step-num-input field-input" data-step-num min="1" max="' + this.recipe.steps.length + '" size="' + stepNumSize + '" value="' + (idx + 1) + '" title="Type step number to jump" aria-label="Step number">' +
            '<div class="step-num-arrows">' +
              '<button type="button" class="step-num-arrow" data-step-arrow="up" title="Move step up">▲</button>' +
              '<button type="button" class="step-num-arrow" data-step-arrow="down" title="Move step down">▼</button>' +
            '</div>' +
          '</div>' +
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
          '<label class="step-active-toggle" title="When unchecked, this step is skipped in every run (like a commented-out command)">' +
            '<input type="checkbox" data-step-enabled' + (step.enabled === false ? '' : ' checked') + '>' +
            '<span>Active</span>' +
          '</label>' +
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
            let v = '';
            if (oldParams[k]?.[fk] !== undefined && oldParams[k][fk] !== '') {
              v = oldParams[k][fk];
            } else if (fk === 'current_location' && oldParams.location !== undefined && oldParams.location !== '') {
              v = oldParams.location;
            } else if (oldParams[fk] !== undefined && oldParams[fk] !== '') {
              v = oldParams[fk];
            } else if (f.default !== undefined) {
              v = f.default;
            }
            if (k === 'car_filters' && (fk === 'current_station' || fk === 'current_location')) {
              v = String(v ?? '').trim();
              if (v.toLowerCase() === 'all' || v.toLowerCase() === 'any') {
                v = '';
              }
            }
            params[k][fk] = v;
          });
          return;
        }
        if (k === 'percent' && oldParams.fraction !== undefined && oldParams.fraction !== '' && (oldParams.percent === undefined || oldParams.percent === '')) {
          const fraction = parseFloat(oldParams.fraction);
          params[k] = String(!isNaN(fraction) ? (fraction <= 1 ? Math.round(fraction * 100) : Math.round(fraction)) : (p.default || ''));
          return;
        }
        if (k === 'jobs' && p.type === 'jobs_multiselect') {
          params[k] = oldParams.jobs != null ? String(oldParams.jobs) : '';
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
      if (step.function === 'pick_up_cars') {
        const job = String(step.params?.job || '').trim();
        const filterSuffix = this.compileTrainCarFiltersTitle(step.params?.car_filters);
        if (!job && !String(step.params?.location || '').trim()) {
          return filterSuffix ? ('Pick Up Cars locals (' + filterSuffix + ')') : 'Pick Up Cars locals';
        }
        let title = '';
        if (!job) title = 'Pick Up Cars locals';
        else {
          const loc = String(step.params?.location || '').trim();
          title = loc ? ('Pick Up Cars ' + job + ' ' + loc) : ('Pick Up Cars ' + job);
        }
        if (filterSuffix) title += ' (' + filterSuffix + ')';
        return title;
      }
      if (step.function === 'set_out_cars') {
        const job = String(step.params?.job || '').trim();
        const loc = String(step.params?.location || '').trim();
        const filterSuffix = this.compileTrainCarFiltersTitle(step.params?.car_filters);
        if (!job && !loc) return 'Set Out Cars locals';
        let title = '';
        if (job && !loc) title = 'Set Out Cars ' + job + ' Final Destination';
        else title = this.catalogMap[step.function]
          ? (this.catalogMap[step.function].gui_template || '')
              .replace('{job}', job).replace('{location}', loc).replace(/\s+/g, ' ').trim()
          : ('Set Out Cars ' + job + ' ' + loc).trim();
        if (filterSuffix) title += ' (' + filterSuffix + ')';
        return title;
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
        let title = ('If ' + v + ' ' + (p.operator || '') + ' ' + (p.value || '')).replace(/\s+/g, ' ').trim();
        if (p.section_label) {
          title += ' then Goto ' + p.section_label;
        } else if (p.section) {
          const sec = (this.runSections || this.buildRunSections()).find((s) => s.id === p.section);
          if (sec) title += ' then Goto ' + sec.label;
        } else if (p.step) {
          title += ' then Goto step ' + p.step;
        }
        return title;
      }
      if (step.function === 'text_instruction') {
        return (step.params?.instruction || '').trim();
      }
      if (step.function === 'generate_switchlists') {
        const p = step.params || {};
        const jobs = String(p.jobs || 'all').trim() || 'all';
        const fmt = String(p.format || 'all').trim() || 'all';
        const fmtLabel = (this.catalogMap.generate_switchlists?.params || [])
          .find((param) => param.key === 'format')?.options
          ?.find((opt) => String(opt.value || opt) === fmt)?.label || fmt;
        return ('Generate Switch Lists ' + jobs + ' (' + fmtLabel + ')').replace(/\s+/g, ' ').trim();
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
        top.classList.toggle('row-has-filters', this.rowHasMultiRowParams(step));
      }
      row.className = this.rowClassName(step);
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
        if (newFn) {
          this.recipe.steps[idx].catalog_description = this.catalogMap[newFn]?.description || '';
        } else {
          this.recipe.steps[idx].catalog_description = '';
        }
        this.refreshRowParams(idx);
        const updatedRow = this.el('steps-list')?.querySelector('.step-row[data-idx="' + idx + '"]');
        if (updatedRow) {
          updatedRow.className = this.rowClassName(this.recipe.steps[idx]);
          this.updateRowStsLink(updatedRow, this.recipe.steps[idx]);
          const fnSelect = updatedRow.querySelector('[data-fn-select]');
          const hint = this.catalogHintText(this.recipe.steps[idx], newFn);
          if (fnSelect) {
            fnSelect.title = hint || '';
          }
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
        row.className = this.rowClassName(this.recipe.steps[idx]);
        this.updateRemarksLayout(row);
        this.updateRowPreview(row, idx);
      };
      row.addEventListener('change', (e) => {
        const target = e.target;
        if (target.matches('[data-step-num]')) return;
        if (target.matches('[data-param="mode"]') || target.matches('[data-param="variable"]')) {
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
      row.addEventListener('input', (e) => {
        if (e.target.matches('[data-step-num]')) return;
        onRowEdit();
      });
      this.updateRemarksLayout(row);

      const stepNumInput = row.querySelector('[data-step-num]');
      if (stepNumInput) {
        let stepNumHandled = false;

        const finishStepMove = (newIdx) => {
          stepNumHandled = true;
          this.markDirty();
          this.renderSteps({
            skipSync: true,
            preserveScroll: true,
            scrollToIndex: newIdx,
            focusStepNum: true,
          });
          this.setStatus('Moved step to position ' + (newIdx + 1), 'ok');
          queueMicrotask(() => {
            stepNumHandled = false;
          });
        };

        stepNumInput.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            stepNumInput.blur();
          }
        });
        row.querySelector('[data-step-arrow="up"]')?.addEventListener('click', () => {
          this.syncStepFromRow(row, idx);
          const newIdx = this.swapStepWithNeighbor(idx, -1);
          if (newIdx !== idx) finishStepMove(newIdx);
        });
        row.querySelector('[data-step-arrow="down"]')?.addEventListener('click', () => {
          this.syncStepFromRow(row, idx);
          const newIdx = this.swapStepWithNeighbor(idx, +1);
          if (newIdx !== idx) finishStepMove(newIdx);
        });
        stepNumInput.addEventListener('change', () => {
          if (stepNumHandled) return;
          this.syncStepFromRow(row, idx);
          const newIdx = this.moveStepToNumber(idx, stepNumInput.value);
          if (newIdx !== idx) {
            finishStepMove(newIdx);
            return;
          }
          stepNumInput.value = String(idx + 1);
        });
      }
    },

    renderSteps(options) {
      options = options || {};
      const scrollY = options.preserveScroll ? window.scrollY : null;

      if (!options.skipSync) {
        this.syncAllStepsFromDom();
      }

      this.renormalizeGotoTargets();

      const list = this.el('steps-list');
      if (!list) return;
      list.innerHTML = '';

      this.recipe.steps.forEach((step, idx) => {
        const row = document.createElement('div');
        row.className = this.rowClassName(step);
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
          if (options.focusStepNum) {
            target.querySelector('[data-step-num]')?.focus();
          }
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
      // Section label is the stable identifier and must win over the
      // position-encoded section id ("step-N"), which goes stale when steps
      // are inserted/removed/reordered.
      const sec = this.findSectionForGoto(sections, p);
      if (sec) target = sec.start;
      if (!target) {
        const n = parseInt(p.step, 10);
        if (n > 0) target = n;
      }
      if (fromStep > 0 && target > 0 && target <= fromStep) {
        return 0;
      }
      return target;
    },

    // Resolve the section a goto/if_then points at, preferring the stable
    // section label over the position-encoded id.
    findSectionForGoto(sections, params) {
      const p = params || {};
      const label = String(p.section_label || '').trim();
      if (label) {
        let sec = sections.find((s) => String(s.label || '').trim() === label);
        if (!sec) {
          sec = sections.find((s) => {
            const sl = String(s.label || '').trim();
            return sl && (sl.includes(label) || label.includes(sl));
          });
        }
        if (sec) return sec;
      }
      if (p.section) {
        const sec = sections.find((s) => s.id === p.section);
        if (sec) return sec;
      }
      return null;
    },

    // Re-sync each goto/if_then's stored section id + step number from its
    // (stable) section label so the displayed target follows the section when
    // step numbers change.
    renormalizeGotoTargets() {
      const steps = (this.recipe && this.recipe.steps) || [];
      if (!steps.length) return;
      const sections = this.buildRunSections();
      steps.forEach((step) => {
        if (!step || (step.function !== 'goto' && step.function !== 'if_then')) return;
        const p = step.params || {};
        if (!String(p.section_label || '').trim()) return;
        const sec = this.findSectionForGoto(sections, p);
        if (!sec) return;
        p.section = sec.id;
        p.section_label = sec.label;
        p.step = String(sec.start);
        step.params = p;
      });
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
          if (ok && this.ifThenHasGoto(step)) {
            const target = this.resolveGotoTarget({ params: step.params || {} }, pc + 1);
            if (target >= 1 && target <= steps.length) {
              pc = target - 1;
            } else {
              pc++;
            }
          } else {
            pc++;
          }
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
      if (stepNum < start || stepNum > stop) return false;
      if (!this.executionPathSteps) {
        this.executionPathSteps = this.computeExecutionPath(start, stop);
      }
      return this.executionPathSteps.has(stepNum);
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

    syncWorkflowSelect(options) {
      options = options || {};
      const sel = this.el('workflow-file');
      if (!sel) return;
      const files = this.workflowFiles || [];
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
        this.activeWorkflow = files[0];
      } else if (files.length > 1) {
        if (!options.preferEmpty && this.activeWorkflow && files.includes(this.activeWorkflow)) {
          sel.value = this.activeWorkflow;
        } else {
          sel.value = '';
          if (options.preferEmpty) {
            this.activeWorkflow = '';
          }
        }
      } else {
        sel.value = '';
      }
      this.syncWorkflowSaveFilename();
      this.syncWorkflowDownloadLink();
    },

    syncWorkflowSaveFilename() {
      const sel = this.el('workflow-file');
      const input = this.el('workflow-save-as');
      if (!input || this.dirty) return;
      input.value = sel?.value || this.activeWorkflow || '';
    },

    readSaveWorkflowFilename() {
      const input = this.el('workflow-save-as');
      let name = (input?.value || this.activeWorkflow || '').trim();
      if (!name) {
        throw new Error('Enter a workflow filename to save');
      }
      if (!/\.(workflow|recipe)\.json$/i.test(name)) {
        name += '.workflow.json';
      }
      return name.replace(/[^a-zA-Z0-9._-]/g, '_').replace(/^\.+/, '');
    },

    syncWorkflowDownloadLink() {
      const sel = this.el('workflow-file');
      const name = sel?.value || this.activeWorkflow;
      const dl = this.el('workflow-download');
      if (!name) {
        if (dl) {
          dl.removeAttribute('href');
          dl.classList.add('disabled');
        }
        return;
      }
      const q = encodeURIComponent(name);
      if (dl) {
        dl.href = 'operational_steps_api.php?action=download&workflow_file=' + q;
        dl.download = name;
        dl.classList.remove('disabled');
      }
    },

    async loadWorkflowList(options) {
      options = options || {};
      const prevActive = this.activeWorkflow;
      const d = await this.api('list_workflows');
      this.workflowFiles = d.files || [];
      if (this.workflowFiles.length === 1) {
        this.activeWorkflow = d.active_workflow || this.workflowFiles[0];
        this.autoLoadWorkflow = true;
        this.syncWorkflowSelect();
        return;
      }
      this.autoLoadWorkflow = false;
      if (this.workflowFiles.length > 1) {
        if (options.keepActive && prevActive && this.workflowFiles.includes(prevActive)) {
          this.activeWorkflow = prevActive;
          this.syncWorkflowSelect();
        } else {
          this.activeWorkflow = '';
          this.syncWorkflowSelect({ preferEmpty: true });
        }
        return;
      }
      this.activeWorkflow = '';
      this.syncWorkflowSelect({ preferEmpty: true });
    },

    async loadRecipe(options) {
      options = options || {};
      if (!this.activeWorkflow) {
        this.recipe = { version: 1, name: 'workflow', steps: [] };
        this.compiledSteps = [];
        if (options.clearDirty !== false) {
          this.clearDirty();
        }
        return;
      }
      const d = await this.api('recipe', 'GET', null, { workflow_file: this.activeWorkflow });
      this.recipe = d.recipe;
      this.activeWorkflow = d.workflow_file || this.activeWorkflow;
      await this.loadCompiled();
      this.syncWorkflowSelect();
      if (options.clearDirty !== false) {
        this.clearDirty();
      }
    },

    async loadSelectedWorkflow() {
      const sel = this.el('workflow-file');
      const next = sel?.value || '';
      if (!next) {
        this.setStatus('Choose a workflow file, then click Load', 'info');
        return;
      }
      if (this.dirty) {
        if (!confirm('Load ' + next + ' from disk? Unsaved changes will be lost.')) {
          sel.value = this.activeWorkflow;
          return;
        }
      }
      this.activeWorkflow = next;
      await this.api('set_active_workflow', 'POST', { workflow_file: this.activeWorkflow });
      await this.loadRecipe();
      await this.loadRunOptions();
      this.renderSteps({ skipSync: true });
      this.syncWorkflowSaveFilename();
      this.setStatus(
        'Loaded ' + this.activeWorkflow + ' — ' + this.recipe.steps.length + ' steps · DB session ' +
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

    async deleteSelectedWorkflow() {
      const sel = this.el('workflow-file');
      const name = sel?.value || this.activeWorkflow || '';
      if (!name) {
        this.setStatus('Choose a workflow file to delete', 'info');
        return;
      }
      if (!confirm(
        'Delete "' + name + '" from the session editor folder?\n\nThis permanently removes the file from disk and cannot be undone.'
      )) {
        return;
      }
      if (this.activeWorkflow === name && this.dirty) {
        if (!confirm('The editor has unsaved changes for this workflow. Delete the file anyway?')) {
          return;
        }
      }
      const d = await this.api('delete_workflow', 'POST', { workflow_file: name });
      const wasActive = this.activeWorkflow === name;
      this.workflowFiles = d.files || [];
      this.activeWorkflow = d.active_workflow || '';
      this.syncWorkflowSelect({ preferEmpty: !this.activeWorkflow });
      if (wasActive || !this.activeWorkflow) {
        this.recipe = this.freshEditorRecipe();
        this.compiledSteps = [];
        this.clearDirty();
        await this.loadRunOptions();
        this.renderSteps({ skipSync: true });
      }
      this.syncWorkflowSaveFilename();
      this.syncWorkflowDownloadLink();
      this.setStatus('Deleted ' + (d.deleted || name), 'ok');
    },

    async loadCatalog() {
      this.catalog = await this.api('catalog');
      this.dynamicOptions = this.catalog.dynamic_options || {};
      this.buildCatalogMap();
    },

    async saveRecipe() {
      this.syncAllStepsFromDom();
      const saveAs = this.readSaveWorkflowFilename();
      const d = await this.api('save', 'POST', { recipe: this.recipe, workflow_file: saveAs });
      this.activeWorkflow = d.workflow_file || saveAs;
      await this.loadWorkflowList({ keepActive: true });
      await this.loadCompiled();
      this.renderSteps();
      this.clearDirty();
      this.setStatus(
        'Saved ' + this.activeWorkflow + ' (' + (d.rows || this.recipe.steps.length) + ' steps)',
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

    async importWorkflow() {
      return this.promptImportWorkflow();
    },

    promptImportWorkflow() {
      this.el('workflow-import-file')?.click();
    },

    async importWorkflowFile(file) {
      if (!file) return;
      const name = file.name || 'imported.workflow.json';
      const text = await file.text();
      let payload;
      try {
        payload = JSON.parse(text);
      } catch (e) {
        throw new Error('Invalid workflow JSON in ' + name);
      }
      const d = await this.api('import_workflow', 'POST', { recipe: payload });
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
      const saveAs = this.el('workflow-save-as');
      if (saveAs) saveAs.value = name.replace(/\.(workflow|recipe)\.json$/i, '') + '.workflow.json';
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
          if (fid === 'goto' || (fid === 'if_then' && this.ifThenHasGoto(step))) {
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
      const sessionText = this.runOptions?.current_session ?? '—';
      const sumSession = this.el('run-db-session');
      if (sumSession) {
        sumSession.textContent = sessionText;
      }
      const navSession = this.el('nav-db-session');
      if (navSession) {
        navSession.textContent = sessionText;
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
      this.clearRunCompleteActions();
      this.setStatus(
        repeat > 1 ? ('Running ' + repeat + ' cycles (steps ' + start + '–' + stop + ')…') : ('Running steps ' + start + '–' + stop + '…'),
        'info'
      );
      const sectionId = this.el('run-section')?.value || 'all';
      const runBody = {
        recipe: this.recipe,
        save_recipe: true,
        session_count: repeat,
        workflow_file: this.activeWorkflow,
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
      this.showRunCompleteActions(d);
      this.runOptions = await this.simulatorApi('run_options');
      this.syncRunDefaults();
      return d;
    },
  };

  global.WorkflowUI = WorkflowUI;
})(window);
