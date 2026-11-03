<?php
/**
 * Generic plugin page router.
 *
 * GET plugin.php?id=<plugin>&page=<slug>   (page defaults to 'index')
 *
 * Serves a page declared in the plugin manifest's 'pages' map (slug => PHP file
 * relative to the plugin directory). Unknown plugin, missing 'pages' entry,
 * unknown slug, or a path that resolves outside the plugin directory gives a
 * plain text 404. The page is required with the working directory set to sts/
 * so relative includes keep working, and sees the same $_GET/$_POST.
 */
require_once __DIR__ . '/plugin_registry.php';

function plugin_router_not_found()
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

$plugin_router_id = (string) ($_GET['id'] ?? '');
$plugin_router_slug = (string) ($_GET['page'] ?? 'index');

plugins_bootstrap_all();
$plugin_router_manifest = plugins_get($plugin_router_id);
if ($plugin_router_manifest === null
    || !is_array($plugin_router_manifest['pages'] ?? null)
    || !isset($plugin_router_manifest['pages'][$plugin_router_slug])) {
    plugin_router_not_found();
}

$plugin_router_dir = realpath($plugin_router_manifest['_dir']);
$plugin_router_file = realpath($plugin_router_manifest['_dir'] . '/' . $plugin_router_manifest['pages'][$plugin_router_slug]);
if ($plugin_router_dir === false || $plugin_router_file === false
    || strpos($plugin_router_file, $plugin_router_dir . DIRECTORY_SEPARATOR) !== 0) {
    plugin_router_not_found();
}

chdir(__DIR__);
require $plugin_router_file;
