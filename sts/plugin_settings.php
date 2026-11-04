<?php
require_once __DIR__ . '/maint_lock.php'; sts_maint_require('admin');
/**
 * Plugin Settings: one link per installed plugin that has a Settings page
 * (manifest 'pages' => ['settings' => ...]). Names no plugin; with none installed
 * it says so. Admin sign-in, like the other Maintenance pages (policy flag maint_open
 * opens it).
 */
require_once 'page_chrome.php';
require_once 'plugin_registry.php';

plugins_bootstrap_all();
$plugin_settings = [];
foreach (plugins_discover() as $manifest) {
    if (!isset($manifest['pages']['settings'])) {
        continue;
    }
    $plugin_settings[] = [
        'href' => plugins_page_url($manifest['id'], 'settings'),
        'label' => (string) ($manifest['label'] ?? $manifest['id']),
        'id' => (string) $manifest['id'],
        'version' => (string) ($manifest['version'] ?? ''),
    ];
}
usort($plugin_settings, static function ($a, $b) {
    return strcasecmp($a['label'], $b['label']);
});

sts_page_start([
    'title' => 'Plugin Settings',
    'icon' => 'bi-puzzle',
    'section' => 'maint',
    'container' => 'narrow',
]);
?>
<p class="text-muted">Plugins add their own settings pages. Each one below opens that plugin's settings.</p>
<?php if ($plugin_settings === []): ?>
  <div class="alert alert-secondary">No installed plugin has a settings page.</div>
<?php else: ?>
  <div class="list-group">
    <?php foreach ($plugin_settings as $p): ?>
      <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3" href="<?php echo sts_h($p['href']); ?>">
        <span><i class="bi bi-gear me-2"></i><?php echo sts_h($p['label']); ?></span>
        <small class="text-muted"><?php echo sts_h($p['id'] . ($p['version'] !== '' ? ' ' . $p['version'] : '')); ?></small>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
sts_page_end();
