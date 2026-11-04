(function () {
  const W = WorkflowUI;

  W.el('btn-add').addEventListener('click', () => W.addStep());
  W.el('btn-save').addEventListener('click', () => W.saveRecipe().catch((e) => W.setStatus(e.message, 'err')));
  W.el('btn-preview')?.addEventListener('click', () => W.togglePreview());
  W.el('btn-preview-check-all')?.addEventListener('click', () => W.setAllStepsIncluded(true));
  W.el('btn-preview-uncheck-all')?.addEventListener('click', () => W.setAllStepsIncluded(false));
  W.el('btn-preview-copy')?.addEventListener('click', () => W.copyPreviewContents());
  W.el('btn-preview-print')?.addEventListener('click', () => window.print());
  W.el('btn-import').addEventListener('click', () => W.promptImportWorkflow());
  W.el('workflow-import-file')?.addEventListener('change', (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (file) W.importWorkflowFile(file).catch((err) => W.setStatus(err.message, 'err'));
  });
  W.el('btn-reload').addEventListener('click', () => {
    if (W.dirty && !confirm('Reload from disk? Unsaved changes will be lost.')) return;
    init();
  });
  W.el('btn-load-workflow')?.addEventListener('click', () => {
    W.loadSelectedWorkflow().catch((e) => W.setStatus(e.message, 'err'));
  });
  W.el('btn-delete-workflow')?.addEventListener('click', () => {
    W.deleteSelectedWorkflow().catch((e) => W.setStatus(e.message, 'err'));
  });
  W.el('btn-reset')?.addEventListener('click', () => W.resetEditor());
  W.el('steps-list')?.addEventListener('input', () => W.markDirty());
  W.el('steps-list')?.addEventListener('change', () => W.markDirty());
  W.el('workflow-file')?.addEventListener('change', () => {
    W.syncWorkflowSaveFilename();
    W.syncWorkflowDownloadLink();
  });
  W.el('insert-mode')?.addEventListener('change', () => W.syncInsertUi());
  W.el('insert-step-num')?.addEventListener('input', () => W.syncInsertFromDom());
  W.el('btn-goto-section')?.addEventListener('click', () => W.scrollToEditorSection());
  W.el('btn-collapse-sections')?.addEventListener('click', () => W.collapseAllSections());
  W.el('btn-expand-sections')?.addEventListener('click', () => W.expandAllSections());
  W.el('editor-section')?.addEventListener('change', () => W.syncEditorSectionUi());
  W.el('hide-non-execute')?.addEventListener('change', (e) => {
    W.hideNonExecute = !!e.target.checked;
    W.syncStepVisibility();
  });
  W.el('hide-remarks')?.addEventListener('change', (e) => {
    W.hideRemarks = !!e.target.checked;
    if (W.previewMode) W.renderStepsPreview();
  });

  W.el('run-start')?.addEventListener('input', (e) => {
    e.target.dataset.userSet = '1';
    W.syncRunRangeHighlight();
    W.syncStepVisibility();
    W.renderRoleWarnings();
  });
  W.el('run-stop')?.addEventListener('input', (e) => {
    e.target.dataset.userSet = '1';
    W.syncRunRangeHighlight();
    W.syncStepVisibility();
    W.renderRoleWarnings();
  });
  W.el('run-skip')?.addEventListener('input', () => {
    // Live preview/highlight only — wait for blur/change before unchecking sections.
    W.applyRunSkipEdit({ normalize: false, syncSections: false });
  });
  W.el('run-skip')?.addEventListener('change', () => {
    W.applyRunSkipEdit({ normalize: true, syncSections: true });
    W.renderRoleWarnings();
  });
  W.el('btn-run')?.addEventListener('click', () => {
    if (!W.canRun()) return;
    const msg = W.canEdit()
      ? 'Save this workflow and run the selected steps against the live STS database?'
      : 'Run the saved workflow\'s selected steps against the live STS database?';
    if (!confirm(msg)) return;
    W.runWorkflow({ saveFirst: true }).catch((e) => {
      W.setStatus(e.message, 'err');
      W.showRunError(e.message);
    });
  });
  W.el('auth-signed-out')?.addEventListener('submit', (e) => {
    e.preventDefault();
    const box = W.el('auth-token');
    const token = box.value;
    box.value = '';
    W.signIn(token).then(() => {
      W.setStatus('Signed in as ' + W.me.name + ' (' + W.me.role + ')', 'ok');
    }, (err) => W.setStatus('Sign-in failed: ' + err.message, 'err'));
  });
  W.el('auth-signout')?.addEventListener('click', () => {
    W.signOut().then(() => W.setStatus('Signed out', 'info'));
  });
  W.el('run-repeat-enabled')?.addEventListener('change', (e) => {
    const n = W.el('run-repeat');
    if (n) n.disabled = !e.target.checked;
  });

  async function init() {
    try {
      await W.loadAuth();
      await W.loadCatalog();
      await W.loadWorkflowList();
      if (W.autoLoadWorkflow && W.activeWorkflow) {
        await W.loadRecipe();
      } else {
        W.recipe = W.freshEditorRecipe();
        W.compiledSteps = [];
        W.clearDirty();
      }
      await W.loadRunOptions();
      W.syncInsertUi();
      W.renderSteps({ skipSync: true });
      W.showPreview();
      const stepMsg = W.recipe.steps.length
        ? (W.activeWorkflow + ' · ' + W.recipe.steps.length + ' steps')
        : (W.workflowFiles.length
          ? 'Choose a workflow and click Load, or import and Save'
          : 'No workflow files yet — import or Save to create one');
      W.setStatus(
        'Ready — ' + stepMsg + ' · DB session ' + (W.runOptions?.current_session ?? '?'),
        'ok'
      );
    } catch (e) {
      W.setStatus(e.message, 'err');
    }
  }
  init();
})();
