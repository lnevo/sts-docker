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
    /** When true, Preview mode omits step remarks lines. */
    hideRemarks: false,
    executionPathSteps: null,
    dirty: false,
    previewMode: true,
    /** Section ids (`step-N`) the operator has collapsed in edit/preview. */
    collapsedSectionIds: null,
    /** One-shot merge of legacy `enabled:false` steps into the Skip field. */
    _skipFromEnabled: null,

    el(id) { return document.getElementById(id); },

    ensureCollapsedSectionIds() {
      if (!(this.collapsedSectionIds instanceof Set)) {
        this.collapsedSectionIds = new Set();
      }
      return this.collapsedSectionIds;
    },

    /**
     * Display numbering matches recipe indices (1-based array position) used by
     * Start/Stop/Skip, goto/if_then "step-N" ids, and the run API.
     * Section headers keep kind:'section' for styling (S prefix) but share the
     * same number sequence — S8 is recipe step 8, not a separate S1..Sn count.
     */
    displayNumbers() {
      const steps = (this.recipe && this.recipe.steps) || [];
      let sectionCount = 0;
      let stepCount = 0;
      const map = steps.map((s, i) => {
        const num = i + 1;
        if ((s && s.function) === 'section_label') {
          sectionCount += 1;
          return { kind: 'section', num, sectionOrd: sectionCount };
        }
        stepCount += 1;
        return { kind: 'step', num, stepOrd: stepCount };
      });
      return { map, sectionCount, stepCount, total: steps.length };
    },

    displayInfoForIndex(idx) {
      const { map } = this.displayNumbers();
      const step = (this.recipe && this.recipe.steps && this.recipe.steps[idx]) || null;
      const kind = (step && step.function === 'section_label') ? 'section' : 'step';
      return map[idx] || { kind, num: idx + 1 };
    },

    ensureCheckboxDropdownDocHandlers() {
      if (this._cddDocHandlers) return;
      this._cddDocHandlers = true;
      document.addEventListener('click', (e) => {
        if (e.target.closest('[data-checkbox-dropdown]')) return;
        this.closeAllCheckboxDropdowns();
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') this.closeAllCheckboxDropdowns();
      });
    },

    markDirty() {
      this.dirty = true;
    },

    clearDirty() {
      this.dirty = false;
    },

    escapeHtml(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    },

    async parseJsonResponse(res) {
      const text = await res.text();
      try {
        return JSON.parse(text);
      } catch (e) {
        const snippet = String(text || '')
          .replace(/<[^>]+>/g, ' ')
          .replace(/\s+/g, ' ')
          .trim()
          .slice(0, 300);
        throw new Error(
          snippet
            ? ('Server returned non-JSON (HTTP ' + res.status + '): ' + snippet)
            : ('Invalid JSON response (HTTP ' + res.status + ')')
        );
      }
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
      const data = await this.parseJsonResponse(res);
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
      const data = await this.parseJsonResponse(res);
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
      return 'step-row ' + this.stepColorClass(step);
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
      return this.resolveCsvValues(val);
    },

    resolveCsvValues(val) {
      if (Array.isArray(val)) {
        return val.map((s) => String(s).trim()).filter(Boolean);
      }
      return String(val || '').split(',').map((s) => s.trim()).filter(Boolean);
    },

    checkboxDropdownSummary(selected, opts, emptyLabel, summaryLabel) {
      const empty = emptyLabel || 'Any';
      if (!selected.length) return empty;
      const labelFor = (v) => {
        const hit = (opts || []).find((o) => String(o.value != null ? o.value : o) === String(v));
        if (!hit) return String(v);
        return String(hit.label != null ? hit.label : (hit.value != null ? hit.value : hit));
      };
      if (selected.length === 1) return labelFor(selected[0]);
      if (selected.length <= 2) return selected.map(labelFor).join(', ');
      return selected.length + ' ' + (summaryLabel || 'selected');
    },

    syncCheckboxDropdown(root) {
      if (!root) return;
      const hidden = root.querySelector('[data-param]');
      const toggle = root.querySelector('[data-cdd-toggle]');
      const selected = Array.from(root.querySelectorAll('[data-cdd-opt]:checked')).map((n) => n.value);
      if (hidden) hidden.value = selected.join(',');
      if (!toggle) return;

      const emptyLabel = root.getAttribute('data-empty-label') || 'Any';
      const summaryLabel = root.getAttribute('data-summary-label') || 'selected';
      const allLabel = root.getAttribute('data-all-label') || '';
      const countOnly = root.getAttribute('data-count-summary') === '1';
      const optionCount = root.querySelectorAll('[data-cdd-opt]').length;
      const selectedLabels = () => Array.from(root.querySelectorAll('[data-cdd-opt]:checked')).map((n) => {
        const lab = n.closest('label');
        const span = lab && lab.querySelector('.cdd-opt-lbl');
        return (span ? span.textContent : n.value || '').trim();
      }).filter(Boolean);

      let text;
      if (!selected.length) {
        text = emptyLabel;
      } else if (allLabel && optionCount > 0 && selected.length >= optionCount) {
        text = allLabel;
      } else if (countOnly) {
        const unit = summaryLabel === 'sections' && selected.length === 1 ? 'section' : summaryLabel;
        text = selected.length + ' ' + unit;
      } else if (selected.length <= 2) {
        const labels = selectedLabels();
        text = labels.length
          ? labels.join(', ')
          : this.checkboxDropdownSummary(selected, null, emptyLabel, summaryLabel);
      } else {
        text = selected.length + ' ' + summaryLabel;
      }
      toggle.textContent = text;
      toggle.title = selected.length
        ? (allLabel && selected.length >= optionCount ? allLabel : selectedLabels().join(', '))
        : emptyLabel;
    },

    closeAllCheckboxDropdowns(except) {
      document.querySelectorAll('[data-checkbox-dropdown].open').forEach((root) => {
        if (except && root === except) return;
        root.classList.remove('open');
        const menu = root.querySelector('[data-cdd-menu]');
        if (menu) menu.hidden = true;
        const toggle = root.querySelector('[data-cdd-toggle]');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
      });
    },

    checkboxDropdownFieldHtml(p, val, rowIdx, dataParamKey, visibleLabels, step) {
      const paramKey = dataParamKey || p.key;
      const id = 'r' + rowIdx + '-' + paramKey.replace(/\./g, '-');
      const lblClass = visibleLabels ? 'inline-lbl inline-lbl-visible' : 'inline-lbl';
      const lbl = (visibleLabels && p.label)
        ? '<span class="' + lblClass + '">' + this.escapeHtml(p.label) + '</span>'
        : '';
      const opts = this.optionsForParam(p, { rowIdx, step, paramKey });
      const selected = this.resolveCsvValues(val != null ? val : (p.default != null ? p.default : ''));
      const selectedSet = new Set(selected.map(String));
      const emptyLabel = p.empty_label || 'Any';
      const summaryLabel = p.summary_label || 'selected';
      const allLabel = p.all_label || '';
      const countOnly = !!p.count_summary;
      let summary;
      if (!selected.length) {
        summary = emptyLabel;
      } else if (allLabel && opts.length && selected.length >= opts.length) {
        summary = allLabel;
      } else if (countOnly) {
        const unit = summaryLabel === 'sections' && selected.length === 1 ? 'section' : summaryLabel;
        summary = selected.length + ' ' + unit;
      } else {
        summary = this.checkboxDropdownSummary(selected, opts, emptyLabel, summaryLabel);
      }

      let menu = '<div class="checkbox-dropdown-menu" data-cdd-menu hidden>';
      menu += '<div class="checkbox-dropdown-search-wrap">'
        + '<input type="search" class="checkbox-dropdown-search" data-cdd-search placeholder="Filter…" autocomplete="off">'
        + '</div>';
      menu += '<div class="checkbox-dropdown-actions">'
        + '<button type="button" class="checkbox-dropdown-action" data-cdd-all>All</button>'
        + '<button type="button" class="checkbox-dropdown-action" data-cdd-none>None</button>'
        + '</div>';
      menu += '<div class="checkbox-dropdown-list" data-cdd-list>';
      opts.forEach((o) => {
        const v = o.value != null ? o.value : o;
        const l = o.label != null ? o.label : o;
        const oid = id + '-opt-' + String(v).replace(/[^a-zA-Z0-9_-]/g, '_');
        menu += '<label class="checkbox-dropdown-option" data-cdd-option>'
          + '<input type="checkbox" data-cdd-opt value="' + this.escapeHtml(String(v)) + '"'
          + (selectedSet.has(String(v)) ? ' checked' : '') + ' id="' + this.escapeHtml(oid) + '">'
          + '<span class="cdd-opt-lbl">' + this.escapeHtml(String(l)) + '</span></label>';
      });
      selected.forEach((v) => {
        if (!opts.some((o) => String(o.value != null ? o.value : o) === String(v))) {
          const oid = id + '-opt-extra-' + String(v).replace(/[^a-zA-Z0-9_-]/g, '_');
          menu += '<label class="checkbox-dropdown-option" data-cdd-option>'
            + '<input type="checkbox" data-cdd-opt value="' + this.escapeHtml(String(v)) + '" checked id="'
            + this.escapeHtml(oid) + '">'
            + '<span class="cdd-opt-lbl">' + this.escapeHtml(String(v)) + '</span></label>';
        }
      });
      if (!opts.length && !selected.length) {
        menu += '<div class="checkbox-dropdown-empty">No options</div>';
      }
      menu += '</div></div>';

      return '<div class="inline-field inline-field-checkbox-dropdown'
        + (visibleLabels ? ' inline-field-labeled' : '') + '">' + lbl
        + '<div class="checkbox-dropdown" data-checkbox-dropdown data-empty-label="'
        + this.escapeHtml(emptyLabel) + '" data-summary-label="' + this.escapeHtml(summaryLabel) + '"'
        + (allLabel ? (' data-all-label="' + this.escapeHtml(allLabel) + '"') : '')
        + (countOnly ? ' data-count-summary="1"' : '')
        + '>'
        + '<button type="button" class="field-select checkbox-dropdown-toggle" data-cdd-toggle '
        + 'aria-haspopup="listbox" aria-expanded="false" id="' + id + '-toggle">'
        + this.escapeHtml(summary) + '</button>'
        + menu
        + '<input type="hidden" data-param="' + this.escapeHtml(paramKey) + '" id="' + id
        + '" value="' + this.escapeHtml(selected.join(',')) + '">'
        + '</div></div>';
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

      if (p.type === 'checkbox_dropdown' || p.type === 'jobs_multiselect') {
        return this.checkboxDropdownFieldHtml(p, val, rowIdx, dataParamKey, visibleLabels, step);
      }
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
          const anyLabel = p.empty_label
            || ((p.type === 'station_location' || paramKey.indexOf('car_filters.') === 0) ? 'All' : 'Any');
          h += '<option value=""' + (String(val) === '' ? ' selected' : '') + '>' + this.escapeHtml(String(anyLabel)) + '</option>';
        }
        // Stale workflow_section ids ("step-N") after reorder: re-resolve via
        // section_label on the step before falling back to a raw orphan option.
        if (
          p.type === 'workflow_section'
          && val
          && !opts.some((o) => String(o.value != null ? o.value : o) === String(val))
        ) {
          const sections = this.buildRunSections() || [];
          const healed = this.findSectionForGoto(sections, (step && step.params) || {});
          if (healed && opts.some((o) => String(o.value != null ? o.value : o) === String(healed.id))) {
            val = healed.id;
          }
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
      const percent = String(params.percent != null && String(params.percent).trim() !== '' ? params.percent : '100').trim();
      parts.push(percent + '%');
      Object.keys(orderLabels).forEach((key) => {
        const raw = String(order[key] || '').trim();
        if (!raw) return;
        const pretty = this.resolveCsvValues(raw).map((t) => this.formatStationLocationToken(t)).join(', ');
        if (pretty) parts.push(orderLabels[key] + '=' + pretty);
      });
      const defaultSources = 'pool,station,priority,system';
      const sources = String(car.categories || defaultSources).trim() || defaultSources;
      parts.push('src=' + sources);
      if (String(car.current_station || '').trim()) {
        const pretty = this.resolveCsvValues(car.current_station).map((t) => this.formatStationLocationToken(t)).join(', ');
        if (pretty) parts.push('car_station=' + pretty);
      }
      if (String(car.current_location || '').trim()) {
        const pretty = this.resolveCsvValues(car.current_location).map((t) => this.formatStationLocationToken(t)).join(', ');
        if (pretty) parts.push('car_loc=' + pretty);
      }
      if (String(car.car_code || '').trim()) {
        parts.push('car_type=' + String(car.car_code).trim());
      }
      return 'Fill Orders ' + parts.join('; ');
    },

    compileRepositionTitle(params) {
      params = params || {};
      const mode = String(params.mode || 'reposition_to_home').trim() || 'reposition_to_home';
      let title = mode === 'update' ? 'Reposition Empties update' : 'Reposition Empties to home';
      const parts = [];
      const percent = String(params.percent != null && String(params.percent).trim() !== '' ? params.percent : '65').trim();
      parts.push(percent + '%');
      if (mode === 'update') {
        const dest = String(params.destination || '').trim();
        if (dest) {
          const pretty = this.resolveCsvValues(dest).map((t) => this.formatStationLocationToken(t)).join(', ');
          if (pretty) parts.push('dest=' + pretty);
        }
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
        const raw = String(filters[key] || '').trim();
        if (!raw) return;
        const pretty = this.resolveCsvValues(raw).map((t) => this.formatStationLocationToken(t)).join(', ');
        if (pretty) parts.push(labels[key] + '=' + pretty);
      });
      if (String(filters.off_home_only || '') === '1') {
        parts.push('off_home=1');
      }
      return title + ' ' + parts.join('; ');
    },

    compileGenerateOrdersTitle(params) {
      params = params || {};
      const parts = [];
      const shipment = this.resolveCsvValues(params.shipment).join(', ');
      if (shipment) parts.push(shipment);
      // Catalog default is Yes (1); blank/No means do not increment.
      if (String(params.increment_session || '') === '1') parts.push('increment session');
      else if (Object.prototype.hasOwnProperty.call(params, 'increment_session') && String(params.increment_session || '') !== '1') {
        parts.push('no increment');
      }
      const maxUnfilled = String(params.max_unfilled || '').trim();
      if (maxUnfilled) parts.push('max_unfilled=' + maxUnfilled);
      const maxNew = String(params.max_new || '').trim();
      if (maxNew) parts.push('max_new=' + maxNew);
      const seed = String(params.seed || '').trim();
      if (seed) parts.push('seed=' + seed);
      const exclude = String(params.exclude_prefixes || '').trim();
      if (exclude) parts.push('exclude=' + exclude);
      if (!parts.length) return 'Generate Orders automatic';
      return 'Generate Orders ' + parts.join('; ');
    },

    compileAutoAssignTitle(params) {
      params = params || {};
      const jobs = this.resolveCsvValues(params.jobs).join(', ');
      let line = jobs ? ('Assign Cars ' + jobs) : 'Assign Cars';
      const stations = this.resolveCsvValues(params.station).filter((s) => s.toLowerCase() !== 'all');
      if (stations.length) {
        line += ' at ' + stations.join(', ');
      }
      const destLabels = this.resolveCsvValues(params.destination).map((token) => {
        if (token.indexOf('station::') === 0) return token.slice(9);
        if (token.indexOf('location::') === 0) return token.slice(10);
        return token;
      }).filter(Boolean);
      if (destLabels.length) {
        line += ' → ' + destLabels.join(', ');
      }
      return line;
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
        const raw = String(filters[key] || '').trim();
        if (!raw) return;
        const pretty = this.resolveCsvValues(raw).map((t) => this.formatStationLocationToken(t)).join(', ');
        if (pretty) parts.push(labels[key] + '=' + pretty);
      });
      return parts.length ? 'Load/Unload ' + parts.join('; ') : 'Load/Unload offline';
    },

    /**
     * Preview command line: compileOne title plus any catalog params not already
     * represented (so Preview always shows what will run, not just remarks).
     */
    previewCommandText(step, idx) {
      let cmd = (this.compileOne(step, idx) || step?.function || '—').trim();
      if (cmd.indexOf('[object Object]') >= 0) {
        cmd = cmd.split('[object Object]').join('').replace(/\s+/g, ' ').trim() || (step?.function || '—');
      }
      if (!step?.function) return cmd;

      const params = step.params || {};
      const pdefs = (this.catalogMap[step.function] || {}).params || [];
      if (!pdefs.length) return cmd;

      const cmdLower = cmd.toLowerCase();
      const alreadyShown = (shown) => {
        const s = String(shown || '').trim();
        if (!s) return true;
        if (cmd.indexOf(s) >= 0) return true;
        // percent chips are often rendered as "25%" while the raw value is "25"
        if (/^\d+(\.\d+)?$/.test(s) && cmd.indexOf(s + '%') >= 0) return true;
        const lower = s.toLowerCase();
        if (cmdLower.indexOf(lower) >= 0) return true;
        return false;
      };

      const filterBits = [];
      const paramBits = [];
      pdefs.forEach((p) => {
        if (this.shouldHideInlineParam(step, p)) return;
        // Dedicated compilers already expand these filter bags with short labels.
        if (
          (step.function === 'fill_orders' && (p.key === 'order_filters' || p.key === 'car_filters'))
          || (step.function === 'reposition_empties' && p.key === 'filters')
          || (step.function === 'load_unload' && p.key === 'filters')
          || ((step.function === 'pick_up_cars' || step.function === 'set_out_cars') && p.key === 'car_filters')
        ) {
          return;
        }
        // Mode / action is already in the reposition title wording.
        if (step.function === 'reposition_empties' && p.key === 'mode') return;
        if (step.function === 'reposition_empties' && p.key === 'percent') return;
        if (step.function === 'fill_orders' && p.key === 'percent') return;
        if (step.function === 'generate_orders' && (p.key === 'shipment' || p.key === 'increment_session'
          || p.key === 'max_unfilled' || p.key === 'max_new' || p.key === 'seed' || p.key === 'exclude_prefixes')) {
          return;
        }
        if (step.function === 'generate_station_report' || step.function === 'generate_wheel_report') {
          if (p.key === 'info') return;
        }
        if (step.function === 'generate_switchlists' && (p.key === 'jobs' || p.key === 'format' || p.key === 'title' || p.key === 'info')) {
          return;
        }
        if (step.function === 'auto_assign_locals' && (p.key === 'jobs' || p.key === 'station' || p.key === 'destination')) {
          return;
        }
        if ((step.function === 'pick_up_cars' || step.function === 'set_out_cars') && (p.key === 'job' || p.key === 'location')) {
          return;
        }
        if (step.function === 'if_then' || step.function === 'goto') return;
        if (step.function === 'track_scale' && (p.key === 'job' || p.key === 'commodity' || p.key === 'min_reloads')) return;
        if (step.function === 'calibrate_track_scale' && p.key === 'every_sessions') return;
        if ((step.function === 'cancel_orders' || step.function === 'drain_unfilled_orders')
          && (p.key === 'threshold' || p.key === 'target' || p.key === 'order' || p.key === 'keep_coke')) {
          return;
        }

        const shown = this.formatParamDisplay(params[p.key], p);
        if (!shown || alreadyShown(shown)) return;
        if (p.type === 'filter_group') {
          filterBits.push(shown);
        } else if (p.type === 'percent') {
          paramBits.push(shown + '%');
        } else {
          paramBits.push((p.label || p.key) + '=' + shown);
        }
      });
      if (filterBits.length) {
        cmd = (cmd + ' (' + filterBits.join('; ') + ')').replace(/\s+/g, ' ').trim();
      }
      if (paramBits.length) {
        cmd = (cmd + ' — ' + paramBits.join('; ')).replace(/\s+/g, ' ').trim();
      }
      return cmd;
    },

    formatStationLocationToken(token) {
      token = String(token || '').trim();
      if (token.indexOf('station::') === 0) return token.slice(9);
      if (token.indexOf('location::') === 0) return token.slice(10);
      if (token === 'remainder') return 'Final Destination';
      return token;
    },

    /** Pretty value for preview/compile — expands filter objects to active keys only. */
    formatParamDisplay(val, paramDef) {
      if (val == null) return '';
      if (typeof val === 'object' && !Array.isArray(val)) {
        const fields = (paramDef && paramDef.fields) || [];
        const labelFor = (key) => {
          const f = fields.find((x) => x.key === key);
          return (f && f.label) || key;
        };
        const parts = [];
        Object.keys(val).forEach((key) => {
          const raw = val[key];
          if (raw == null || typeof raw === 'object') return;
          const text = String(raw).trim();
          if (!text) return;
          const pretty = this.resolveCsvValues(text)
            .map((t) => this.formatStationLocationToken(t))
            .filter(Boolean)
            .join(', ');
          if (!pretty) return;
          parts.push(labelFor(key) + '=' + pretty);
        });
        return parts.join('; ');
      }
      if (Array.isArray(val)) {
        return val.map((v) => this.formatParamDisplay(v, paramDef)).filter(Boolean).join(', ');
      }
      const text = String(val).trim();
      if (!text || text === '[object Object]') return '';
      const type = paramDef && paramDef.type;
      const from = paramDef && paramDef.options_from;
      if (type === 'select' && Array.isArray(paramDef.options)) {
        // Keep the common "all" format chip short (not "All styles").
        if (paramDef.key === 'format' && text === 'all') {
          return 'All';
        }
        const opt = paramDef.options.find((o) => {
          const ov = (o && typeof o === 'object') ? (o.value != null ? o.value : o.label) : o;
          return String(ov) === text;
        });
        if (opt && typeof opt === 'object' && opt.label != null) {
          return String(opt.label);
        }
      }
      if (
        type === 'checkbox_dropdown'
        || type === 'jobs_multiselect'
        || type === 'station_location'
        || type === 'setout_location'
        || from === 'station_locations'
        || from === 'setout_locations'
        || from === 'stations'
        || from === 'locations'
        || from === 'jobs'
      ) {
        return this.resolveCsvValues(text)
          .map((t) => this.formatStationLocationToken(t))
          .filter(Boolean)
          .join(', ');
      }
      return text;
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
        const raw = String(filters[key] || '').trim();
        if (!raw) return;
        const pretty = this.resolveCsvValues(raw).map((t) => this.formatStationLocationToken(t)).join(', ');
        if (pretty) parts.push(labels[key] + '=' + pretty);
      });
      return parts.length ? parts.join('; ') : '';
    },

    ifThenGotoParamsHtml(step, rowIdx) {
      const def = this.catalogMap.if_then;
      const values = Object.assign({}, step.params || {});
      const paramByKey = {};
      (def?.params || []).forEach((p) => { paramByKey[p.key] = p; });

      // Prefer stable section_label when section id is blank/stale so the
      // Goto dropdown shows the real target (e.g. After Coke Generate).
      const sections = this.runSections || this.buildRunSections();
      const resolved = this.findSectionForGoto(sections, values);
      if (resolved) {
        values.section = resolved.id;
        values.section_label = resolved.label;
        values.step = String(resolved.start);
      }

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

      // Order filters for open_orders / unfilled_orders (commodity / shipment /
      // car code / loading / unloading / final destination).
      html += '<div class="param-if-then-filters-row">';
      ['commodity', 'shipment', 'car_code'].forEach((key) => {
        const p = paramByKey[key];
        if (!p) return;
        html += this.inlineParamFieldHtml(p, values[key], rowIdx, undefined, true, step);
      });
      html += '</div>';
      html += '<div class="param-if-then-filters-row">';
      ['loading_location', 'unloading_location', 'final_destination'].forEach((key) => {
        const p = paramByKey[key];
        if (!p) return;
        html += this.inlineParamFieldHtml(p, values[key], rowIdx, undefined, true, step);
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
          { ...step, params: values }
        );
      }
      // Persist stable label/step across DOM sync (select only writes section id).
      html += '<input type="hidden" data-param="section_label" value="'
        + this.escapeHtml(String(values.section_label || '')) + '">';
      html += '<input type="hidden" data-param="step" value="'
        + this.escapeHtml(String(values.step || '')) + '">';
      html += '</div>';

      html += '</div>';
      return html;
    },

    gotoParamsHtml(step, rowIdx) {
      const def = this.catalogMap.goto;
      const values = Object.assign({}, step.params || {});
      const sectionParam = (def?.params || []).find((p) => p.key === 'section');
      if (!sectionParam) return '<span class="inline-empty">No parameters</span>';
      const sections = this.runSections || this.buildRunSections();
      const resolved = this.findSectionForGoto(sections, values);
      if (resolved) {
        values.section = resolved.id;
        values.section_label = resolved.label;
        values.step = String(resolved.start);
      }
      let html = '<div class="param-if-then-goto-grid">';
      html += '<div class="param-goto-row">';
      html += this.inlineParamFieldHtml(
        { ...sectionParam, label: 'Goto section' },
        values.section,
        rowIdx,
        undefined,
        true,
        { ...step, params: values }
      );
      html += '<input type="hidden" data-param="section_label" value="'
        + this.escapeHtml(String(values.section_label || '')) + '">';
      html += '<input type="hidden" data-param="step" value="'
        + this.escapeHtml(String(values.step || '')) + '">';
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
          return this.inlineParamFieldHtml(p, values[p.key], rowIdx, undefined, true, step);
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
      if (step.function === 'goto' || step.function === 'if_then') {
        // DOM only stores the position-encoded section id ("step-N"). Keep the
        // prior stable section_label so targets survive insert/delete/reorder
        // when that id goes stale (otherwise the dropdown falls back to showing
        // raw "step-11").
        const prevLabel = String((prev.params || {}).section_label || '').trim();
        if (!String(step.params.section_label || '').trim() && prevLabel) {
          step.params.section_label = prevLabel;
        }
        if (!String(step.params.step || '').trim() && (prev.params || {}).step) {
          step.params.step = String(prev.params.step);
        }
        if (step.params.section || step.params.section_label || step.params.step) {
          const sections = this.buildRunSections();
          const sec = this.findSectionForGoto(sections, step.params)
            || sections.find((s) => s.id === step.params.section);
          if (sec) {
            step.params.section = sec.id;
            step.params.section_label = sec.label;
            step.params.step = String(sec.start);
            if (sec.start <= idx + 1) {
              delete step.params.section;
              delete step.params.section_label;
              delete step.params.step;
            }
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
      // Legacy per-step `enabled` is retired — use Skip steps / Include checkboxes.
      if (Object.prototype.hasOwnProperty.call(step, 'enabled')) {
        delete step.enabled;
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
      const recipeNum = idx + 1;
      const { map, total } = this.displayNumbers();
      const disp = map[idx] || { kind: 'step', num: recipeNum };
      const isSection = step.function === 'section_label';
      const displayMax = Math.max(1, total || ((this.recipe.steps || []).length));
      const displaySize = Math.max(2, String(displayMax).length);
      const collapseBtn = isSection
        ? ('<button type="button" class="section-collapse-btn" data-section-collapse' +
          ' aria-expanded="true" title="Collapse section">▼</button>')
        : '';
      const numTitle = isSection
        ? ('Recipe step ' + recipeNum + ' (section header) — same number as Start/Stop/Skip and goto step-'
          + recipeNum + '; type a recipe step number to move this row')
        : ('Recipe step ' + recipeNum + ' — same number as Start/Stop/Skip; type a recipe step number to move this row');
      const numAria = isSection ? 'Recipe step number (section header)' : 'Recipe step number';
      const prefix = isSection
        ? '<span class="step-num-prefix" title="Section header at recipe step ' + recipeNum + '">S</span>'
        : '';

      return (
        '<div class="row-top row-top-align-start' + (this.rowHasMultiRowParams(step) ? ' row-has-filters' : '') +
          this.remarksExpandedClass(step) + '">' +
          '<div class="step-num-control' + (isSection ? ' step-num-control-section' : '') + '">' +
            collapseBtn +
            prefix +
            '<input type="number" class="step-num step-num-input field-input" data-step-num' +
            ' data-display-kind="' + disp.kind + '"' +
            ' min="1" max="' + displayMax + '" size="' + displaySize + '"' +
            ' value="' + recipeNum + '" title="' + this.escapeHtml(numTitle) + '"' +
            ' aria-label="' + numAria + '">' +
            '<div class="step-num-arrows">' +
              '<button type="button" class="step-num-arrow" data-step-arrow="up" title="Move up">▲</button>' +
              '<button type="button" class="step-num-arrow" data-step-arrow="down" title="Move down">▼</button>' +
            '</div>' +
          '</div>' +
          '<button type="button" class="btn-icon btn-insert-before" title="Add step below">+</button>' +
          '<label class="inline-field row-command inline-field-labeled">' +
            '<span class="inline-lbl inline-lbl-visible">Command</span>' +
            this.commandSelectHtml(step, rowKey) +
          '</label>' +
          '<div class="row-params">' + this.paramsHtmlForStep(step, rowKey) + '</div>' +
          '<label class="inline-field row-remarks inline-field-labeled">' +
            '<span class="inline-lbl inline-lbl-visible">Remarks</span>' +
            '<input type="text" class="field-input field-remarks" data-notes placeholder="Optional remarks" value="' +
            this.escapeHtml(this.rowRemarksText(step)) + '">' +
          '</label>' +
          '<button type="button" class="btn-icon btn-del" title="Delete">×</button>' +
        '</div>' +
        '<div class="row-bottom">' +
          '<label class="step-include-toggle" title="' +
            (isSection
              ? 'Include this section in the run (checks/unchecks every step in the section)'
              : 'Include in run. Unchecked adds this step to Skip steps.') +
            '">' +
            '<input type="checkbox" data-step-include data-step-num="' + recipeNum + '" checked>' +
            '<span>Run</span>' +
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
        if (p.type === 'jobs_multiselect' || p.type === 'checkbox_dropdown') {
          params[k] = oldParams[k] != null ? String(oldParams[k]) : '';
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
        const jobs = this.resolveCsvValues(step.params?.job).join(', ');
        const locs = this.resolveCsvValues(step.params?.location).join(', ');
        const filterSuffix = this.compileTrainCarFiltersTitle(step.params?.car_filters);
        if (!jobs && !locs) {
          return filterSuffix ? ('Pick Up Cars locals (' + filterSuffix + ')') : 'Pick Up Cars locals';
        }
        let title = '';
        if (!jobs) title = 'Pick Up Cars locals';
        else title = locs ? ('Pick Up Cars ' + jobs + ' ' + locs) : ('Pick Up Cars ' + jobs);
        if (filterSuffix) title += ' (' + filterSuffix + ')';
        return title;
      }
      if (step.function === 'set_out_cars') {
        const jobs = this.resolveCsvValues(step.params?.job).join(', ');
        const locTokens = this.resolveCsvValues(step.params?.location);
        const locs = locTokens.map((t) => this.formatStationLocationToken(t)).join(', ');
        const filterSuffix = this.compileTrainCarFiltersTitle(step.params?.car_filters);
        if (!jobs && !locs) return 'Set Out Cars locals';
        let title = '';
        if (jobs && !locs) title = 'Set Out Cars ' + jobs + ' Final Destination';
        else if (jobs && locTokens.length === 1 && locTokens[0] === 'remainder') {
          title = 'Set Out Cars ' + jobs + ' Final Destination';
        } else {
          title = ('Set Out Cars ' + jobs + ' ' + locs).replace(/\s+/g, ' ').trim();
        }
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
        const filterBits = [];
        const pushFilter = (label, raw) => {
          const text = String(raw || '').trim();
          if (!text) return;
          const pretty = this.resolveCsvValues(text).map((t) => this.formatStationLocationToken(t)).join(', ');
          filterBits.push(label + '=' + pretty);
        };
        if (String(p.commodity || '').trim()) filterBits.push('commodity=' + String(p.commodity).trim());
        if (String(p.shipment || '').trim()) filterBits.push('shipment=' + String(p.shipment).trim());
        if (String(p.car_code || '').trim()) filterBits.push('car=' + String(p.car_code).trim());
        pushFilter('loading', p.loading_location);
        pushFilter('unloading', p.unloading_location);
        pushFilter('dest', p.final_destination);
        if (filterBits.length) title += ' (' + filterBits.join('; ') + ')';
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
      if (step.function === 'skip_steps') {
        const ranges = String(step.params?.steps || '').trim();
        return ranges ? ('Skip Steps ' + ranges) : 'Skip Steps';
      }
      if (step.function === 'generate_switchlists') {
        const p = step.params || {};
        const jobs = String(p.jobs || 'all').trim() || 'all';
        const fmt = String(p.format || 'all').trim() || 'all';
        const fmtLabel = (this.catalogMap.generate_switchlists?.params || [])
          .find((param) => param.key === 'format')?.options
          ?.find((opt) => String(opt.value || opt) === fmt)?.label || fmt;
        let title = ('Generate Switch Lists ' + jobs + ' (' + fmtLabel + ')').replace(/\s+/g, ' ').trim();
        const swTitle = String(p.title || '').trim();
        const swInfo = String(p.info || '').trim();
        if (swTitle) title += ' — ' + swTitle;
        if (swInfo) title += (swTitle ? ' · ' : ' — ') + swInfo;
        return title;
      }
      if (def) {
        let t = def.gui_template || def.label || '';
        // Derived placeholders used by gui_template (parity with PHP catalog merge).
        const p = Object.assign({}, step.params || {});
        if (step.function === 'generate_station_report' || step.function === 'generate_wheel_report') {
          const info = String(p.info || '').trim();
          p.info_suffix = info ? (' — ' + info) : '';
        }
        if (step.function === 'track_scale') {
          const job = String(p.job || '').trim();
          p.job = job || 'train';
          const commodity = String(p.commodity || '').trim();
          p.commodity_suffix = commodity ? (' (' + commodity + ')') : '';
          const min = String(p.min_reloads || '').trim();
          p.min_reloads_suffix = (min !== '' && /^\d+$/.test(min)) ? (' (min reloads ' + min + ')') : '';
        }
        if (step.function === 'calibrate_track_scale') {
          const every = Math.max(1, parseInt(p.every_sessions, 10) || 1);
          p.every_sessions = String(every);
          t = t.replace('session[s]', every === 1 ? 'session' : 'sessions');
        }
        if (step.function === 'pick_up_cars') {
          t = t.replace('{location_suffix}', p.location ? p.location : '');
        }
        title = t.replace(/\{(\w+)\}/g, (_, k) => {
          if (!Object.prototype.hasOwnProperty.call(p, k)) return '';
          // Derived suffix placeholders already include leading spaces/punctuation —
          // do not run them through formatParamDisplay (it trims).
          if (/_suffix$/.test(k)) return String(p[k] == null ? '' : p[k]);
          const pdef = (def.params || []).find((pp) => pp.key === k);
          return this.formatParamDisplay(p[k], pdef);
        }).replace(/\s+/g, ' ').trim();
        if (step.function === 'cancel_orders' || step.function === 'drain_unfilled_orders') {
          const keep = String(p.keep_coke != null ? p.keep_coke : '1').trim();
          title += keep === '0' ? ' (cancel coke)' : ' (keep coke)';
        }
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
      if (step.function === 'load_unload' || step.function === 'fill_orders' || step.function === 'reposition_empties' || step.function === 'generate_orders' || step.function === 'auto_assign_locals' || step.function === 'pick_up_cars' || step.function === 'set_out_cars' || step.function === 'goto' || step.function === 'if_then') {
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
        const shown = this.formatParamDisplay(params[p.key], p);
        if (!shown) return;
        html += '<span class="preview-param p-' + (pi % 5) + '" title="' + this.escapeHtml(p.label || p.key) + '">' +
          this.escapeHtml(shown) + '</span>';
        pi++;
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
      row.querySelector('[data-step-include]')?.addEventListener('change', (e) => {
        if (this._syncingIncludeBoxes) return;
        this.setStepIncluded(idx + 1, !!e.target.checked);
      });
      row.querySelector('[data-section-collapse]')?.addEventListener('click', (e) => {
        e.preventDefault();
        const sid = row.dataset.sectionId;
        if (sid) this.toggleSectionCollapsed(sid);
      });

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
      row.addEventListener('click', (e) => {
        const toggle = e.target.closest('[data-cdd-toggle]');
        if (toggle && row.contains(toggle)) {
          e.preventDefault();
          const root = toggle.closest('[data-checkbox-dropdown]');
          const menu = root && root.querySelector('[data-cdd-menu]');
          if (!root || !menu) return;
          const willOpen = menu.hidden;
          this.closeAllCheckboxDropdowns(willOpen ? root : null);
          menu.hidden = !willOpen;
          root.classList.toggle('open', willOpen);
          toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
          if (willOpen) {
            const search = menu.querySelector('[data-cdd-search]');
            if (search) {
              search.value = '';
              menu.querySelectorAll('[data-cdd-option]').forEach((opt) => { opt.hidden = false; });
              queueMicrotask(() => search.focus());
            }
          }
          return;
        }
        const allBtn = e.target.closest('[data-cdd-all]');
        if (allBtn && row.contains(allBtn)) {
          e.preventDefault();
          const root = allBtn.closest('[data-checkbox-dropdown]');
          root.querySelectorAll('[data-cdd-opt]').forEach((n) => {
            if (!n.closest('[data-cdd-option]')?.hidden) n.checked = true;
          });
          this.syncCheckboxDropdown(root);
          onRowEdit();
          return;
        }
        const noneBtn = e.target.closest('[data-cdd-none]');
        if (noneBtn && row.contains(noneBtn)) {
          e.preventDefault();
          const root = noneBtn.closest('[data-checkbox-dropdown]');
          root.querySelectorAll('[data-cdd-opt]').forEach((n) => { n.checked = false; });
          this.syncCheckboxDropdown(root);
          onRowEdit();
        }
      });
      row.addEventListener('input', (e) => {
        if (e.target.matches('[data-cdd-search]')) {
          const q = e.target.value.trim().toLowerCase();
          const menu = e.target.closest('[data-cdd-menu]');
          if (!menu) return;
          menu.querySelectorAll('[data-cdd-option]').forEach((opt) => {
            const label = (opt.querySelector('.cdd-opt-lbl')?.textContent || '').toLowerCase();
            opt.hidden = q !== '' && label.indexOf(q) < 0;
          });
        }
      });
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
        if (target.matches('[data-cdd-opt]')) {
          this.syncCheckboxDropdown(target.closest('[data-checkbox-dropdown]'));
        }
        onRowEdit();
      });
      row.addEventListener('input', (e) => {
        if (e.target.matches('[data-step-num]') || e.target.matches('[data-cdd-search]')) return;
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
          this.setStatus(
            'Moved to recipe step ' + (newIdx + 1)
              + (this.recipe.steps[newIdx]?.function === 'section_label' ? ' (section header)' : ''),
            'ok'
          );
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
          stepNumInput.value = String(this.displayInfoForIndex(idx).num);
        });
      }
    },

    renderSteps(options) {
      this.ensureCheckboxDropdownDocHandlers();
      options = options || {};
      const scrollY = options.preserveScroll ? window.scrollY : null;

      if (!options.skipSync) {
        this.syncAllStepsFromDom();
      }

      this.renormalizeGotoTargets();

      const list = this.el('steps-list');
      if (!list) return;
      list.innerHTML = '';

      const sections = this.editorSections();
      this.recipe.steps.forEach((step, idx) => {
        const row = document.createElement('div');
        row.className = this.rowClassName(step);
        row.dataset.idx = String(idx);
        const sid = this.sectionIdForStepNum(idx + 1, sections);
        if (sid) row.dataset.sectionId = sid;
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
      this.syncStepIncludeCheckboxes();
      this.applySectionCollapseUi();
      this.syncStepVisibility();
      if (this.previewMode) {
        this.runSections = this.buildRunSections();
        this.renderStepsPreview();
        this.syncPreviewUi();
      }
    },

    isExecutableStep(step) {
      const fid = step?.function || '';
      if (['section_label', 'text_instruction', 'marker', 'goto', 'if_then', 'skip_steps'].includes(fid)) {
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
    // step numbers change. Also heal stale section ids when the label is
    // missing but the id still resolves, or when a bare step points at a
    // section_label row.
    renormalizeGotoTargets() {
      const steps = (this.recipe && this.recipe.steps) || [];
      if (!steps.length) return;
      const sections = this.buildRunSections();
      steps.forEach((step, idx) => {
        if (!step || (step.function !== 'goto' && step.function !== 'if_then')) return;
        const p = step.params || {};
        let sec = this.findSectionForGoto(sections, p);
        if (!sec && p.section) {
          sec = sections.find((s) => s.id === p.section) || null;
        }
        if (!sec) {
          const n = parseInt(p.step, 10);
          if (n > 0) {
            sec = sections.find((s) => s.start === n) || null;
          }
        }
        if (!sec) return;
        // Keep non-section step targets (e.g. session_nbr → increment_session)
        // when the resolved section would be a backward/invalid goto.
        if (sec.start <= idx + 1) return;
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
      if (operator === '%' || operator === '!%') {
        const raw = String(value ?? '').trim();
        const m = raw.match(/^(\d+)\s*(?:[=,]\s*(\d+))?$/);
        if (!m) return false;
        const modulus = parseInt(m[1], 10);
        const remainder = m[2] != null ? parseInt(m[2], 10) : 0;
        if (!modulus || Number.isNaN(left) || remainder < 0 || remainder >= modulus) return false;
        const matches = (Math.trunc(left) % modulus) === remainder;
        return operator === '%' ? matches : !matches;
      }
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
      const skipSet = this.getRunSkipSet();
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
        const n = pc + 1;
        if (skipSet.has(n)) {
          pc++;
          continue;
        }
        const fid = step.function || '';
        if (fid === 'stop') {
          if (this.isExecutableStep(step)) executed.add(n);
          break;
        }
        if (fid === 'skip_steps') {
          this.parseStepRanges(step.params?.steps || '').forEach((sn) => skipSet.add(sn));
          pc++;
          continue;
        }
        if (fid === 'goto') {
          const target = this.resolveGotoTarget(step, n);
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
            const target = this.resolveGotoTarget({ params: step.params || {} }, n);
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
          executed.add(n);
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
      if (this.getRunSkipSet().has(stepNum)) return false;
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

    parseStepRanges(text) {
      const out = new Set();
      String(text || '').split(',').forEach((part) => {
        part = part.trim();
        if (!part) return;
        const m = part.match(/^(\d+)\s*-\s*(\d+)$/);
        if (m) {
          let a = parseInt(m[1], 10);
          let b = parseInt(m[2], 10);
          if (a > b) {
            const t = a;
            a = b;
            b = t;
          }
          for (let n = a; n <= b; n++) {
            if (n >= 1) out.add(n);
          }
          return;
        }
        if (/^\d+$/.test(part)) {
          const n = parseInt(part, 10);
          if (n >= 1) out.add(n);
        }
      });
      return out;
    },

    formatStepRanges(nums) {
      const list = Array.from(nums || []).map((n) => parseInt(n, 10)).filter((n) => n >= 1);
      list.sort((a, b) => a - b);
      const uniq = [];
      list.forEach((n) => {
        if (!uniq.length || uniq[uniq.length - 1] !== n) uniq.push(n);
      });
      if (!uniq.length) return '';
      const parts = [];
      let start = uniq[0];
      let prev = uniq[0];
      for (let i = 1; i <= uniq.length; i++) {
        const n = i < uniq.length ? uniq[i] : null;
        if (n !== null && n === prev + 1) {
          prev = n;
          continue;
        }
        parts.push(start === prev ? String(start) : (start + '-' + prev));
        if (n === null) break;
        start = prev = n;
      }
      return parts.join(',');
    },

    getSelectedRunSectionIds() {
      const host = this.el('run-section-host');
      if (!host) {
        const raw = (this.el('run-section')?.value || '').trim();
        return raw ? raw.split(',').map((s) => s.trim()).filter(Boolean) : [];
      }
      const checked = Array.from(host.querySelectorAll('[data-cdd-opt]:checked')).map((n) => n.value);
      return checked.filter((id) => id && id !== 'all');
    },

    getRunCoverage() {
      const total = Math.max(1, this.recipe.steps?.length || 1);
      const sections = this.editorSections();
      let selectedIds = this.getSelectedRunSectionIds();
      if (!sections.length) {
        return {
          start: 1,
          stop: total,
          total,
          selectedIds: [],
          included: null,
          skipSteps: [],
          skipStr: '',
          all: true,
        };
      }
      // None selected — operator must pick sections (distinct from All).
      if (!selectedIds.length) {
        const skipAll = [];
        for (let n = 1; n <= total; n++) skipAll.push(n);
        return {
          start: 1,
          stop: total,
          total,
          selectedIds: [],
          included: new Set(),
          skipSteps: skipAll,
          skipStr: this.formatStepRanges(skipAll),
          all: false,
          none: true,
        };
      }
      // Every section checked ⇒ full contiguous range, no skip.
      if (selectedIds.length >= sections.length) {
        selectedIds = sections.map((s) => s.id);
        return {
          start: 1,
          stop: total,
          total,
          selectedIds,
          included: null,
          skipSteps: [],
          skipStr: '',
          all: true,
          none: false,
        };
      }
      // Partial: keep full 1..total span so unchecked sections stay visible as
      // skipped (Run boxes unchecked) instead of disappearing outside start/stop.
      const selected = sections.filter((s) => selectedIds.indexOf(s.id) >= 0);
      const included = new Set();
      selected.forEach((s) => {
        for (let n = s.start; n <= s.stop; n++) included.add(n);
      });
      const skipSteps = [];
      for (let n = 1; n <= total; n++) {
        if (!included.has(n)) skipSteps.push(n);
      }
      return {
        start: 1,
        stop: total,
        total,
        selectedIds: selected.map((s) => s.id),
        included,
        skipSteps,
        skipStr: this.formatStepRanges(skipSteps),
        all: false,
        none: false,
      };
    },

    getRunSkipSet() {
      // Skip field is source of truth once shown/edited; section gaps only seed it.
      const manual = (this.el('run-skip')?.value || '').trim();
      if (manual) return this.parseStepRanges(manual);
      return new Set(this.getRunCoverage().skipSteps || []);
    },

    normalizeRunSkipField(opts) {
      opts = opts || {};
      const skipEl = this.el('run-skip');
      if (!skipEl) return '';
      const total = Math.max(1, this.recipe.steps?.length || 1);
      const parsed = this.parseStepRanges(skipEl.value);
      const clamped = [];
      parsed.forEach((n) => {
        if (n >= 1 && n <= total) clamped.push(n);
      });
      const str = this.formatStepRanges(clamped);
      if (opts.write !== false) {
        // Preserve free typing until blur/change normalize.
        if (opts.force || skipEl !== document.activeElement) {
          skipEl.value = str;
        }
      }
      return str;
    },

    /**
     * After skip edits: optionally uncheck any section whose every step is skipped.
     * Does not rewrite the skip field from section gaps (avoids clobbering custom skips).
     */
    applyRunSkipEdit(opts) {
      opts = opts || {};
      if (this._applyingRunSection) return;
      this._applyingRunSkip = true;
      try {
        if (opts.normalize) {
          this.normalizeRunSkipField({ force: true });
        }
        const syncSections = opts.syncSections !== false;
        const skipSet = this.getRunSkipSet();
        const host = this.el('run-section-host');
        const root = host && host.querySelector('[data-checkbox-dropdown]');
        const sections = this.editorSections();
        let changed = false;
        if (syncSections && root && sections.length) {
          const optsByValue = {};
          root.querySelectorAll('[data-cdd-opt]').forEach((n) => { optsByValue[n.value] = n; });
          sections.forEach((sec) => {
            const opt = optsByValue[sec.id];
            if (!opt || !opt.checked) return;
            let fullySkipped = true;
            for (let n = sec.start; n <= sec.stop; n++) {
              if (!skipSet.has(n)) {
                fullySkipped = false;
                break;
              }
            }
            if (fullySkipped && sec.stop >= sec.start) {
              opt.checked = false;
              changed = true;
            }
          });
          if (changed) this.syncCheckboxDropdown(root);
        }

        // Recompute start/stop from remaining checked sections; keep skip as-is.
        const coverage = this.getRunCoverage();
        const startEl = this.el('run-start');
        const stopEl = this.el('run-stop');
        const hidden = this.el('run-section');
        const total = coverage.total;
        if (!coverage.none) {
          if (startEl && startEl.dataset.userSet !== '1') {
            startEl.min = '1';
            startEl.max = String(total);
            startEl.value = String(coverage.start);
          }
          if (stopEl && stopEl.dataset.userSet !== '1') {
            stopEl.min = '1';
            stopEl.max = String(total);
            stopEl.value = String(coverage.stop);
          }
        }
        if (hidden) {
          hidden.value = coverage.all ? '' : coverage.selectedIds.join(',');
        }
        if (this.previewMode) this.renderStepsPreview();
        this.syncRunRangeHighlight();
        this.syncStepIncludeCheckboxes();
        this.applySectionCollapseUi();
        this.syncStepVisibility();
      } finally {
        this._applyingRunSkip = false;
      }
    },

    sectionIdForStepNum(stepNum, sections) {
      const list = sections || this.editorSections();
      for (let i = list.length - 1; i >= 0; i--) {
        const s = list[i];
        if (stepNum >= s.start && stepNum <= s.stop) return s.id;
      }
      return '';
    },

    /**
     * Convert legacy recipe `enabled: false` into Skip-field entries (once).
     */
    absorbDisabledStepsIntoSkip() {
      const steps = this.recipe.steps || [];
      steps.forEach((step, i) => {
        if (!step || !Object.prototype.hasOwnProperty.call(step, 'enabled')) return;
        if (step.enabled === false) {
          if (!(this._skipFromEnabled instanceof Set)) this._skipFromEnabled = new Set();
          this._skipFromEnabled.add(i + 1);
        }
        delete step.enabled;
      });
    },

    flushSkipFromEnabled() {
      if (!(this._skipFromEnabled instanceof Set) || !this._skipFromEnabled.size) return;
      const skipEl = this.el('run-skip');
      if (!skipEl) return;
      const set = this.parseStepRanges(skipEl.value);
      this._skipFromEnabled.forEach((n) => set.add(n));
      this._skipFromEnabled.clear();
      skipEl.value = this.formatStepRanges(set);
    },

    /** True when recipe step n will run (not skipped / not outside selected sections). */
    isStepIncludedInRun(stepNum) {
      stepNum = parseInt(stepNum, 10);
      if (!stepNum || stepNum < 1) return false;
      const coverage = this.getRunCoverage();
      if (coverage.none) return false;
      if (this.getRunSkipSet().has(stepNum)) return false;
      if (coverage.all) return true;
      return !!(coverage.included && coverage.included.has(stepNum));
    },

    sectionForStepNum(stepNum) {
      stepNum = parseInt(stepNum, 10);
      if (!stepNum) return null;
      const sections = this.editorSections();
      for (let i = 0; i < sections.length; i++) {
        const s = sections[i];
        if (stepNum >= s.start && stepNum <= s.stop) return s;
      }
      return null;
    },

    ensureSectionsCheckedForRange(start, stop) {
      const host = this.el('run-section-host');
      const root = host && host.querySelector('[data-checkbox-dropdown]');
      if (!root) return false;
      let changed = false;
      this.editorSections().forEach((sec) => {
        if (sec.stop < start || sec.start > stop) return;
        const opt = root.querySelector('[data-cdd-opt][value="' + sec.id + '"]');
        if (opt && !opt.checked) {
          opt.checked = true;
          changed = true;
        }
      });
      if (changed) {
        this.syncCheckboxDropdown(root);
        const hidden = this.el('run-section');
        const coverage = this.getRunCoverage();
        if (hidden) {
          hidden.value = coverage.all ? '' : coverage.selectedIds.join(',');
        }
      }
      return changed;
    },

    setStepsIncludedInRange(start, stop, included) {
      start = parseInt(start, 10);
      stop = parseInt(stop, 10);
      const total = Math.max(1, this.recipe.steps?.length || 1);
      if (!start || start < 1) start = 1;
      if (!stop || stop < start) stop = start;
      if (stop > total) stop = total;
      if (this._applyingRunSkip || this._applyingRunSection) return;

      if (included) {
        this.ensureSectionsCheckedForRange(start, stop);
      }

      const skipEl = this.el('run-skip');
      const set = this.getRunSkipSet();
      for (let n = start; n <= stop; n++) {
        if (included) set.delete(n);
        else set.add(n);
      }
      if (skipEl) skipEl.value = this.formatStepRanges(set);
      this.applyRunSkipEdit({ normalize: true, syncSections: true });
    },

    setStepIncluded(stepNum, included) {
      stepNum = parseInt(stepNum, 10);
      if (!stepNum || stepNum < 1) return;
      if (this._applyingRunSkip || this._applyingRunSection) return;
      const step = (this.recipe.steps || [])[stepNum - 1];
      // Section header Run box toggles every recipe step in that section.
      if (step && step.function === 'section_label') {
        const sec = this.editorSections().find((s) => s.start === stepNum)
          || this.sectionForStepNum(stepNum);
        if (sec) {
          this.setStepsIncludedInRange(sec.start, sec.stop, included);
          return;
        }
      }
      this.setStepsIncludedInRange(stepNum, stepNum, included);
    },

    setAllStepsIncluded(included) {
      if (this._applyingRunSkip || this._applyingRunSection) return;
      const host = this.el('run-section-host');
      const root = host && host.querySelector('[data-checkbox-dropdown]');
      if (root) {
        root.querySelectorAll('[data-cdd-opt]').forEach((n) => {
          n.checked = !!included;
        });
        this.syncCheckboxDropdown(root);
      }
      // Reseed Skip from section selection (all clear, or every step skipped).
      this.applyRunSection({ reseedSkip: true });
      this.setStatus(included ? 'Checked all steps' : 'Unchecked all steps', 'ok');
    },

    syncStepIncludeCheckboxes() {
      this._syncingIncludeBoxes = true;
      try {
        document.querySelectorAll('[data-step-include]').forEach((box) => {
          let n = parseInt(box.getAttribute('data-step-num'), 10);
          if (!n) {
            const row = box.closest('[data-idx]');
            n = row ? parseInt(row.dataset.idx, 10) + 1 : 0;
          }
          if (!n) return;
          box.checked = this.isStepIncludedInRun(n);
        });
      } finally {
        this._syncingIncludeBoxes = false;
      }
    },

    toggleSectionCollapsed(sectionId) {
      if (!sectionId) return;
      const set = this.ensureCollapsedSectionIds();
      if (set.has(sectionId)) set.delete(sectionId);
      else set.add(sectionId);
      this.applySectionCollapseUi();
    },

    collapseAllSections() {
      const set = this.ensureCollapsedSectionIds();
      this.editorSections().forEach((s) => {
        if (s.id) set.add(s.id);
      });
      this.applySectionCollapseUi();
      this.setStatus('Collapsed all sections', 'ok');
    },

    expandAllSections() {
      this.ensureCollapsedSectionIds().clear();
      this.applySectionCollapseUi();
      this.setStatus('Expanded all sections', 'ok');
    },

    applySectionCollapseUi() {
      const collapsed = this.ensureCollapsedSectionIds();
      const sections = this.editorSections();
      const headerStarts = new Set(sections.map((s) => s.start));

      const list = this.el('steps-list');
      if (list) {
        list.querySelectorAll('.step-row[data-idx]').forEach((row) => {
          const n = parseInt(row.dataset.idx, 10) + 1;
          const sid = row.dataset.sectionId || '';
          const isHeader = headerStarts.has(n);
          const btn = row.querySelector('[data-section-collapse]');
          if (btn) {
            const isCollapsed = !!(sid && collapsed.has(sid));
            btn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            btn.textContent = isCollapsed ? '▶' : '▼';
            btn.title = isCollapsed ? 'Expand section' : 'Collapse section';
            row.classList.toggle('section-collapsed', isCollapsed);
          }
          const hide = !!(sid && collapsed.has(sid) && !isHeader);
          row.classList.toggle('section-collapsed-hidden', hide);
        });
      }

      const preview = this.el('steps-preview');
      if (preview) {
        preview.querySelectorAll('[data-section-id]').forEach((li) => {
          const sid = li.dataset.sectionId || '';
          const isHeader = li.classList.contains('wf-preview-section');
          const btn = li.querySelector('[data-section-collapse]');
          if (btn) {
            const isCollapsed = !!(sid && collapsed.has(sid));
            btn.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            btn.textContent = isCollapsed ? '▶' : '▼';
            btn.title = isCollapsed ? 'Expand section' : 'Collapse section';
          }
          const hide = !!(sid && collapsed.has(sid) && !isHeader);
          li.classList.toggle('section-collapsed-hidden', hide);
        });
      }
    },

    getRunStepRange() {
      const coverage = this.getRunCoverage();
      const total = coverage.total;
      let start = parseInt(this.el('run-start')?.value, 10) || coverage.start;
      let stop = parseInt(this.el('run-stop')?.value, 10) || coverage.stop;
      start = Math.max(1, Math.min(start, total));
      stop = Math.max(start, Math.min(stop, total));
      const skipStr = (this.el('run-skip')?.value || coverage.skipStr || '').trim();
      return {
        start,
        stop,
        total,
        skipStr,
        included: coverage.included,
        all: coverage.all,
        none: !!coverage.none,
      };
    },

    syncRunRangeHighlight() {
      const list = this.el('steps-list');
      if (!list) return;
      const { start, stop, included } = this.getRunStepRange();
      const skipSet = this.getRunSkipSet();
      list.querySelectorAll('.step-row[data-idx]').forEach((row) => {
        const stepNum = parseInt(row.dataset.idx, 10) + 1;
        const inSpan = stepNum >= start && stepNum <= stop;
        const isSkip = skipSet.has(stepNum);
        const inRun = inSpan && !isSkip && (included == null || included.has(stepNum));
        row.classList.toggle('run-range', inRun);
        row.classList.toggle('run-skip', inSpan && isSkip);
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
      sections.forEach((s, i) => {
        const text = 'S' + (i + 1) + ' · ' + this.truncateSectionLabel(s.label, 48);
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
      const sections = this.editorSections();
      const sec = sections.find((s) => s.id === sel.value);
      if (!sec) return;
      if (sec.id) {
        this.ensureCollapsedSectionIds().delete(sec.id);
        this.applySectionCollapseUi();
      }
      const list = this.el('steps-list');
      const idx = sec.start - 1;
      const row = list?.querySelector('.step-row[data-idx="' + idx + '"]');
      if (!row) {
        this.setStatus('Section not found', 'err');
        return;
      }
      list.querySelectorAll('.step-row.section-highlight').forEach((n) => n.classList.remove('section-highlight'));
      row.classList.add('section-highlight');
      row.scrollIntoView({ block: 'start', behavior: 'smooth' });
      window.setTimeout(() => row.classList.remove('section-highlight'), 2500);
      this.setStatus(
        'Jumped to recipe step ' + sec.start + ' · ' + this.truncateSectionLabel(sec.label, 56),
        'ok'
      );
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
      const savedActive = d.active_workflow || '';
      if (this.workflowFiles.length === 1) {
        this.activeWorkflow = savedActive || this.workflowFiles[0];
        this.autoLoadWorkflow = true;
        this.syncWorkflowSelect();
        return;
      }
      if (this.workflowFiles.length > 1) {
        if (options.keepActive && prevActive && this.workflowFiles.includes(prevActive)) {
          this.activeWorkflow = prevActive;
        } else if (savedActive && this.workflowFiles.includes(savedActive)) {
          this.activeWorkflow = savedActive;
        } else {
          this.activeWorkflow = '';
        }
        this.autoLoadWorkflow = !!this.activeWorkflow;
        if (this.activeWorkflow) {
          this.syncWorkflowSelect();
        } else {
          this.syncWorkflowSelect({ preferEmpty: true });
        }
        return;
      }
      this.autoLoadWorkflow = false;
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
      this.showPreview();
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
          if (fid === 'goto' || (fid === 'if_then' && this.ifThenHasGoto(steps[j]))) {
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

    ensureRunSectionDropdownHandlers() {
      if (this._runSectionHandlers) return;
      this._runSectionHandlers = true;
      this.ensureCheckboxDropdownDocHandlers();
      const host = this.el('run-section-host');
      if (!host) return;
      host.addEventListener('click', (e) => {
        const toggle = e.target.closest('[data-cdd-toggle]');
        if (toggle && host.contains(toggle)) {
          e.preventDefault();
          const root = toggle.closest('[data-checkbox-dropdown]');
          const menu = root && root.querySelector('[data-cdd-menu]');
          if (!root || !menu) return;
          const willOpen = menu.hidden;
          this.closeAllCheckboxDropdowns(willOpen ? root : null);
          menu.hidden = !willOpen;
          root.classList.toggle('open', willOpen);
          toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
          if (willOpen) {
            const search = menu.querySelector('[data-cdd-search]');
            if (search) {
              search.value = '';
              menu.querySelectorAll('[data-cdd-option]').forEach((opt) => { opt.hidden = false; });
              queueMicrotask(() => search.focus());
            }
          }
          return;
        }
        const allBtn = e.target.closest('[data-cdd-all]');
        if (allBtn && host.contains(allBtn)) {
          e.preventDefault();
          const root = allBtn.closest('[data-checkbox-dropdown]');
          root.querySelectorAll('[data-cdd-opt]').forEach((n) => {
            if (!n.closest('[data-cdd-option]')?.hidden) n.checked = true;
          });
          this.syncCheckboxDropdown(root);
          this.applyRunSection();
          return;
        }
        const noneBtn = e.target.closest('[data-cdd-none]');
        if (noneBtn && host.contains(noneBtn)) {
          e.preventDefault();
          const root = noneBtn.closest('[data-checkbox-dropdown]');
          root.querySelectorAll('[data-cdd-opt]').forEach((n) => { n.checked = false; });
          this.syncCheckboxDropdown(root);
          this.applyRunSection();
        }
      });
      host.addEventListener('input', (e) => {
        if (!e.target.matches('[data-cdd-search]')) return;
        const q = e.target.value.trim().toLowerCase();
        const menu = e.target.closest('[data-cdd-menu]');
        if (!menu) return;
        menu.querySelectorAll('[data-cdd-option]').forEach((opt) => {
          const label = (opt.querySelector('.cdd-opt-lbl')?.textContent || '').toLowerCase();
          opt.hidden = q !== '' && label.indexOf(q) < 0;
        });
      });
      host.addEventListener('change', (e) => {
        if (!e.target.matches('[data-cdd-opt]')) return;
        this.syncCheckboxDropdown(e.target.closest('[data-checkbox-dropdown]'));
        this.applyRunSection();
      });
    },

    syncRunSectionSelect() {
      const host = this.el('run-section-host');
      const hidden = this.el('run-section');
      if (!host) return;
      this.ensureRunSectionDropdownHandlers();
      this.runSections = this.buildRunSections();
      const sections = this.editorSections();
      const prev = this.getSelectedRunSectionIds();
      const prevSet = new Set(prev);
      const hadControls = !!host.querySelector('[data-cdd-opt]');
      // First paint defaults to All; afterwards honor None vs partial vs All.
      const selectAll = !hadControls
        ? true
        : (sections.length > 0 && prev.length >= sections.length);
      const selectNone = hadControls && !prev.length;
      const selectedCsv = selectAll
        ? sections.map((s) => s.id).join(',')
        : (selectNone ? '' : prev.filter((id) => sections.some((s) => s.id === id)).join(','));
      const opts = sections.map((s) => ({
        value: s.id,
        label: this.truncateSectionLabel(s.label) + ' (steps ' + s.start + '–' + s.stop + ')',
      }));
      const param = {
        key: 'run_sections',
        label: '',
        type: 'checkbox_dropdown',
        empty_label: '-- Select Sections --',
        all_label: 'All Sections',
        summary_label: 'sections',
        count_summary: true,
        default: selectedCsv,
      };
      // optionsForParam won't know these; inject via temporary dynamic override.
      const prevJobs = this.optionsForParam;
      this.optionsForParam = (p, context) => {
        if (p && p.key === 'run_sections') return opts;
        return prevJobs.call(this, p, context);
      };
      host.innerHTML = this.checkboxDropdownFieldHtml(param, selectedCsv, 'run', 'run_sections', false, null);
      this.optionsForParam = prevJobs;
      const root = host.querySelector('[data-checkbox-dropdown]');
      if (root) {
        root.querySelectorAll('[data-cdd-opt]').forEach((n) => {
          n.checked = selectAll ? true : prevSet.has(n.value);
        });
        this.syncCheckboxDropdown(root);
      }
      if (hidden) {
        hidden.value = selectAll ? '' : selectedCsv;
      }
    },

    applyRunSection(opts) {
      opts = opts || {};
      // Section checkbox changes reset skip from section gaps; routine re-sync preserves custom skips.
      const reseedSkip = opts.reseedSkip !== false;
      if (this._applyingRunSkip) return;
      this._applyingRunSection = true;
      try {
        const coverage = this.getRunCoverage();
        const startEl = this.el('run-start');
        const stopEl = this.el('run-stop');
        const skipEl = this.el('run-skip');
        const hidden = this.el('run-section');
        const total = coverage.total;
        if (startEl) {
          delete startEl.dataset.userSet;
          startEl.min = '1';
          startEl.max = String(total);
          startEl.value = String(coverage.start);
        }
        if (stopEl) {
          delete stopEl.dataset.userSet;
          stopEl.min = '1';
          stopEl.max = String(total);
          stopEl.value = String(coverage.stop);
        }
        if (skipEl && skipEl !== document.activeElement) {
          if (reseedSkip || !String(skipEl.value || '').trim()) {
            skipEl.value = coverage.skipStr;
          }
        }
        this.flushSkipFromEnabled();
        if (hidden) {
          hidden.value = coverage.all ? '' : coverage.selectedIds.join(',');
        }
        if (this.previewMode) {
          this.renderStepsPreview();
        }
        this.syncRunRangeHighlight();
        this.syncStepIncludeCheckboxes();
        this.applySectionCollapseUi();
        this.syncStepVisibility();
      } finally {
        this._applyingRunSection = false;
      }
    },

    syncRunDefaults() {
      const total = Math.max(1, this.recipe.steps?.length || 1);
      this.absorbDisabledStepsIntoSkip();
      this.syncRunSectionSelect();
      const startEl = this.el('run-start');
      const stopEl = this.el('run-stop');
      const userSet = startEl?.dataset.userSet === '1' || stopEl?.dataset.userSet === '1';
      if (!userSet) {
        // Keep Skip steps (include checkboxes) across step list re-renders.
        this.applyRunSection({ reseedSkip: false });
      } else {
        if (startEl) {
          startEl.min = '1';
          startEl.max = String(total);
        }
        if (stopEl) {
          stopEl.min = '1';
          stopEl.max = String(total);
        }
        // Preserve editable skip unless empty (then seed gaps from sections).
        const skipEl = this.el('run-skip');
        if (skipEl && !String(skipEl.value || '').trim()) {
          skipEl.value = this.getRunCoverage().skipStr;
        }
        this.flushSkipFromEnabled();
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
      this.syncStepIncludeCheckboxes();
      this.applySectionCollapseUi();
      this.syncStepVisibility();
    },

    async runWorkflow(options) {
      options = options || {};
      const repeatEnabled = !!this.el('run-repeat-enabled')?.checked;
      const repeat = repeatEnabled
        ? Math.max(2, parseInt(this.el('run-repeat')?.value, 10) || 2)
        : 1;
      this.syncAllStepsFromDom();
      const selectedWorkflow = this.el('workflow-file')?.value || '';
      if (!this.activeWorkflow || (selectedWorkflow && selectedWorkflow !== this.activeWorkflow)) {
        throw new Error('Load the selected workflow before running it');
      }
      const coverage = this.getRunCoverage();
      if (coverage.none) {
        throw new Error('Select at least one section to run');
      }
      if (options.saveFirst) {
        await this.saveRecipe();
      }
      const total = coverage.total;
      let start = parseInt(this.el('run-start')?.value, 10) || coverage.start;
      let stop = parseInt(this.el('run-stop')?.value, 10) || coverage.stop;
      start = Math.max(1, Math.min(start, total));
      stop = Math.max(start, Math.min(stop, total));
      if (stop < start) {
        throw new Error('Stop step must be at or after start step');
      }
      const skipStr = (this.el('run-skip')?.value || coverage.skipStr || '').trim();
      this.clearRunCompleteActions();
      let statusMsg = repeat > 1
        ? ('Running ' + repeat + ' cycles (steps ' + start + '–' + stop + ')')
        : ('Running steps ' + start + '–' + stop);
      if (skipStr) statusMsg += ' (skip ' + skipStr + ')';
      statusMsg += '…';
      this.setStatus(statusMsg, 'info');
      const runBody = {
        recipe: this.recipe,
        save_recipe: true,
        session_count: repeat,
        workflow_file: this.activeWorkflow,
        start_step: start,
        stop_step: stop,
        skip_steps: skipStr,
      };
      // Multi-section (or gaps) always uses the range runner with skip_steps.
      const d = await this.simulatorApi('run', 'POST', runBody);
      const lines = (d.summary || []).slice();
      if (d.start_step) {
        let head = 'Steps ' + d.start_step + '–' + d.stop_step;
        if (d.skip_steps) head += ' (skip ' + d.skip_steps + ')';
        lines.unshift(head);
      }
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

    /**
     * In-page recipe summary (compiled command + remarks), shown in place of
     * the Steps editor. Toggle with Preview / Edit — no pop-up window.
     */
    togglePreview() {
      if (this.previewMode) this.hidePreview();
      else this.showPreview();
    },

    showPreview() {
      this.syncAllStepsFromDom();
      this.runSections = this.buildRunSections();
      this.previewMode = true;
      this.renderStepsPreview();
      this.syncPreviewUi();
    },

    hidePreview() {
      this.previewMode = false;
      this.syncPreviewUi();
    },

    syncPreviewUi() {
      const previewing = !!this.previewMode;
      const scroll = this.el('steps-scroll');
      const preview = this.el('steps-preview');
      const title = this.el('steps-panel-title');
      const btn = this.el('btn-preview');
      const printBtn = this.el('btn-preview-print');
      const copyBtn = this.el('btn-preview-copy');
      const editNav = this.el('steps-edit-nav');
      const editInsert = this.el('steps-edit-insert');
      const addBtn = this.el('btn-add');
      const reloadBtn = this.el('btn-reload');
      if (scroll) scroll.hidden = previewing;
      if (preview) preview.hidden = !previewing;
      if (title) title.textContent = previewing ? 'Preview' : 'Steps';
      if (btn) {
        btn.textContent = previewing ? 'Edit' : 'Preview';
        btn.title = previewing ? 'Return to the step editor' : 'Show clean command summary';
        btn.classList.toggle('btn-dark', previewing);
        btn.classList.toggle('btn-outline-dark', !previewing);
      }
      if (printBtn) printBtn.hidden = !previewing;
      if (copyBtn) copyBtn.hidden = !previewing;
      const checkAllBtn = this.el('btn-preview-check-all');
      const uncheckAllBtn = this.el('btn-preview-uncheck-all');
      if (checkAllBtn) checkAllBtn.hidden = !previewing;
      if (uncheckAllBtn) uncheckAllBtn.hidden = !previewing;
      // Keep section jump + Collapse/Expand all visible in Preview and Edit.
      if (editNav) editNav.hidden = false;
      if (editInsert) editInsert.hidden = previewing;
      if (addBtn) addBtn.hidden = previewing;
      if (reloadBtn) reloadBtn.hidden = previewing;
      document.body.classList.toggle('workflow-preview-mode', previewing);
      this.syncEditorSectionSelect();
      this.applySectionCollapseUi();
    },

    bindPreviewEvents(host) {
      if (!host) return;
      host.querySelectorAll('[data-step-include]').forEach((box) => {
        box.addEventListener('change', (e) => {
          if (this._syncingIncludeBoxes) return;
          const n = parseInt(e.target.getAttribute('data-step-num'), 10);
          if (n) this.setStepIncluded(n, !!e.target.checked);
        });
      });
      host.querySelectorAll('[data-section-collapse]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const li = btn.closest('[data-section-id]');
          const sid = li?.dataset.sectionId;
          if (sid) this.toggleSectionCollapsed(sid);
        });
      });
    },

    renderStepsPreview() {
      const host = this.el('steps-preview');
      if (!host) return;
      const steps = Array.isArray(this.recipe.steps) ? this.recipe.steps : [];
      const name = (this.recipe.name || this.activeWorkflow || 'workflow').trim() || 'workflow';
      const file = (this.activeWorkflow || '').trim();
      const esc = (s) => this.escapeHtml(s);
      const coverage = this.getRunCoverage();
      const { start, stop, skipStr } = this.getRunStepRange();
      const skipSet = this.getRunSkipSet();
      const sections = this.editorSections();

      let body = '';
      if (!steps.length) {
        body = '<p class="wf-preview-empty">No steps in this workflow.</p>';
      } else {
        if (coverage.none) {
          body = '<p class="wf-preview-empty">No sections selected — all steps unchecked. Use <strong>Check all</strong> or the Sections control.</p>';
        }
        body += '<ol class="wf-preview-steps">';
        const appendStep = (step, idx, n) => {
          const included = this.isStepIncludedInRun(n);
          const skipped = !included;
          const cmd = this.previewCommandText(step, idx);
          const remarks = (this.rowRemarksText(step) || '').trim();
          const isSection = step.function === 'section_label';
          const sid = this.sectionIdForStepNum(n, sections);
          // Preview numbers = recipe indices (match Start/Stop/Skip); S marks section headers only.
          const displayLabel = isSection ? ('S' + n) : String(n);
          const liClass = [
            isSection ? 'wf-preview-section' : 'wf-preview-step',
            skipped ? 'disabled wf-preview-skipped' : '',
          ].filter(Boolean).join(' ');
          const collapseBtn = isSection
            ? ('<button type="button" class="section-collapse-btn" data-section-collapse' +
              ' aria-expanded="true" title="Collapse section">▼</button>')
            : '';
          const includeTitle = isSection
            ? 'Include this section in the run (checks/unchecks every step in the section)'
            : 'Include in run. Unchecked adds this step to Skip steps.';
          const includeBox = '<label class="step-include-toggle wf-preview-include" title="' + this.escapeHtml(includeTitle) + '">'
            + '<input type="checkbox" data-step-include data-step-num="' + n + '"'
            + (skipped ? '' : ' checked')
            + ' aria-label="Include recipe step ' + n + ' in run"></label>';
          body += '<li class="' + liClass + '"'
            + (sid ? (' data-section-id="' + esc(sid) + '"') : '')
            + ' data-step-num="' + n + '">'
            + '<div class="wf-preview-cmd">'
            + collapseBtn
            + includeBox
            + '<span class="wf-preview-num">' + displayLabel + '.</span> '
            + esc(cmd)
            + (skipped ? ' <span class="wf-preview-tag">(skipped)</span>' : '')
            + '</div>';
          if (remarks && !this.hideRemarks) {
            body += '<div class="wf-preview-remarks">remarks: ' + esc(remarks) + '</div>';
          }
          body += '</li>';
        };

        // Always list the full recipe so section check/uncheck stays visible.
        for (let idx = 0; idx < steps.length; idx++) {
          appendStep(steps[idx], idx, idx + 1);
        }
        body += '</ol>';
      }

      let meta = esc(name)
        + ' · ' + steps.length + ' step' + (steps.length === 1 ? '' : 's')
        + (file ? (' · ' + esc(file)) : '');
      if (!coverage.all || skipStr) {
        meta += ' · run ' + start + '–' + stop;
        if (skipStr) meta += ' (skip ' + esc(skipStr) + ')';
      }
      host.innerHTML = '<div class="wf-preview-meta">' + meta + '</div>' + body;
      this.bindPreviewEvents(host);
      this.applySectionCollapseUi();
    },

    async copyPreviewContents() {
      const host = this.el('steps-preview');
      if (!host || host.hidden) return;
      const text = String(host.innerText || host.textContent || '').replace(/\s+$/g, '');
      if (!text) {
        this.setStatus('Nothing to copy', 'err');
        return;
      }
      const copyBtn = this.el('btn-preview-copy');
      const flashCopied = () => {
        if (!copyBtn) return;
        const prev = copyBtn.innerHTML;
        copyBtn.innerHTML = '<i class="bi bi-clipboard-check"></i>';
        copyBtn.classList.add('btn-success');
        copyBtn.classList.remove('btn-outline-dark');
        setTimeout(() => {
          copyBtn.innerHTML = prev;
          copyBtn.classList.remove('btn-success');
          copyBtn.classList.add('btn-outline-dark');
        }, 1200);
      };
      try {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
          await navigator.clipboard.writeText(text);
        } else {
          const ta = document.createElement('textarea');
          ta.value = text;
          ta.setAttribute('readonly', '');
          ta.style.position = 'fixed';
          ta.style.left = '-9999px';
          document.body.appendChild(ta);
          ta.select();
          document.execCommand('copy');
          document.body.removeChild(ta);
        }
        flashCopied();
        this.setStatus('Preview copied to clipboard', 'ok');
      } catch (e) {
        this.setStatus('Could not copy preview: ' + (e && e.message ? e.message : e), 'err');
      }
    },
  };

  global.WorkflowUI = WorkflowUI;
})(window);
