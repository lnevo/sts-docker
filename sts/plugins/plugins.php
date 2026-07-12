<?php
/**
 * STS plugin registry.
 *
 * Each addon lives in plugins/<id>/plugin.php and returns a manifest array.
 * Stock pages call the plugins_* helpers below to merge buttons, stats, catalog
 * steps, and dispatch handlers without hardcoding feature-specific code.
 */

function plugins_root_dir()
{
    return __DIR__;
}

function plugins_sts_root_dir()
{
    return dirname(__DIR__);
}

/**
 * @return array<string, array>
 */
function plugins_discover()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    foreach (glob(plugins_root_dir() . '/*/plugin.php') ?: [] as $path) {
        $manifest = include $path;
        if (!is_array($manifest) || empty($manifest['id'])) {
            continue;
        }
        $manifest['_dir'] = dirname($path);
        $manifest['_path'] = $path;
        $cache[(string) $manifest['id']] = $manifest;
    }
    ksort($cache);

    return $cache;
}

function plugins_has($id)
{
    return isset(plugins_discover()[(string) $id]);
}

function plugins_get($id)
{
    return plugins_discover()[(string) $id] ?? null;
}

function plugins_bootstrap_all()
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }
    $bootstrapped = true;

    foreach (plugins_discover() as $manifest) {
        $bootstrap = $manifest['bootstrap'] ?? ($manifest['_dir'] . '/bootstrap.php');
        if (is_readable($bootstrap)) {
            require_once $bootstrap;
        }
    }
}

function plugins_require_helper($id)
{
    static $loaded = [];
    $id = (string) $id;
    if (!empty($loaded[$id])) {
        return true;
    }

    $manifest = plugins_get($id);
    if ($manifest === null) {
        return false;
    }

    plugins_bootstrap_all();

    $helpers = $manifest['helpers'] ?? ($manifest['_dir'] . '/' . $id . '_helpers.php');
    if (is_readable($helpers)) {
        require_once $helpers;
        $loaded[$id] = true;

        return true;
    }

    return false;
}

/**
 * Render op-btn anchors for a placement CID (before|during|after).
 */
function plugins_render_operations_buttons($cid, array $stats)
{
    plugins_bootstrap_all();
    $cid = (string) $cid;
    $buttons = [];

    foreach (plugins_discover() as $manifest) {
        foreach ($manifest['operations_buttons'] ?? [] as $button) {
            if (($button['cid'] ?? '') !== $cid) {
                continue;
            }
            $buttons[] = $button;
        }
    }

    if ($buttons === []) {
        return '';
    }

    usort($buttons, static function ($a, $b) {
        return strcmp((string) ($a['after'] ?? ''), (string) ($b['after'] ?? ''));
    });

    $html = '';
    foreach ($buttons as $button) {
        $href = (string) ($button['href'] ?? '#');
        $icon = (string) ($button['icon'] ?? 'bi-puzzle');
        $title = (string) ($button['title'] ?? 'Plugin');
        $stat_cols = [];
        foreach ($button['stats'] ?? [] as $col) {
            $key = (string) ($col['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $stat_cols[] = [
                'label' => (string) ($col['label'] ?? $key),
                'value' => (int) ($stats[$key] ?? 0),
            ];
        }

        $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="op-btn">'
            . '<i class="bi ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . ' op-icon"></i>'
            . '<div class="op-btn-body"><div class="op-btn-title">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</div></div>';
        if ($stat_cols !== [] && function_exists('operations_render_stat_columns')) {
            $html .= operations_render_stat_columns($stat_cols);
        }
        $html .= '</a>';
    }

    return $html;
}

function plugins_apply_stats($dbc, array &$stats)
{
    plugins_bootstrap_all();
    foreach (plugins_discover() as $manifest) {
        foreach ($manifest['stats'] ?? [] as $stat) {
            $key = (string) ($stat['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (!array_key_exists($key, $stats)) {
                $stats[$key] = (int) ($stat['default'] ?? 0);
            }
            $callback = $stat['callback'] ?? null;
            if (is_string($callback) && is_callable($callback)) {
                $stats[$key] = (int) call_user_func($callback, $dbc);
            } elseif (is_callable($callback)) {
                $stats[$key] = (int) $callback($dbc);
            }
        }
    }
}

function plugins_condition_variables()
{
    plugins_bootstrap_all();
    $vars = [];
    foreach (plugins_discover() as $manifest) {
        foreach ($manifest['condition_variables'] ?? [] as $var) {
            if (!empty($var['key'])) {
                $vars[] = $var;
            }
        }
    }

    return $vars;
}

function plugins_catalog_definitions()
{
    plugins_bootstrap_all();
    $defs = [];
    foreach (plugins_discover() as $manifest) {
        if (!empty($manifest['catalog_steps_callback']) && is_callable($manifest['catalog_steps_callback'])) {
            $chunk = call_user_func($manifest['catalog_steps_callback']);
            if (is_array($chunk)) {
                $defs = array_merge($defs, $chunk);
            }
            continue;
        }
        if (!empty($manifest['catalog_steps']) && is_array($manifest['catalog_steps'])) {
            $defs = array_merge($defs, $manifest['catalog_steps']);
        }
    }

    return $defs;
}

function plugins_catalog_adder_order(array $order)
{
    plugins_bootstrap_all();
    foreach (plugins_discover() as $manifest) {
        foreach ($manifest['catalog_adder'] ?? [] as $group => $ids) {
            if (!is_array($ids) || $ids === []) {
                continue;
            }
            if (!isset($order[$group]) || !is_array($order[$group])) {
                $order[$group] = [];
            }
            foreach ($ids as $id) {
                if (!in_array($id, $order[$group], true)) {
                    $order[$group][] = $id;
                }
            }
        }
    }

    return $order;
}

/**
 * All dispatch ids handled by plugins (via dispatch_handlers). Lets tooling
 * treat plugin-dispatched steps as valid without a case in
 * operational_steps_dispatch_step().
 *
 * @return list<string>
 */
function plugins_dispatch_ids()
{
    plugins_bootstrap_all();
    $ids = [];
    foreach (plugins_discover() as $manifest) {
        foreach (array_keys($manifest['dispatch_handlers'] ?? []) as $dispatch) {
            $ids[] = (string) $dispatch;
        }
    }

    return array_values(array_unique($ids));
}

function plugins_no_warm_start_dispatches()
{
    plugins_bootstrap_all();
    $ids = [];
    foreach (plugins_discover() as $manifest) {
        foreach ($manifest['no_warm_start_dispatch'] ?? [] as $dispatch) {
            $ids[] = (string) $dispatch;
        }
    }

    return $ids;
}

/** Dispatch ids that may run when warm_start/session runtime helpers are absent. */
function plugins_runtime_without_warm_start()
{
    plugins_bootstrap_all();
    $ids = [];
    foreach (plugins_discover() as $manifest) {
        foreach ($manifest['runtime_without_warm_start'] ?? [] as $dispatch) {
            $ids[] = (string) $dispatch;
        }
    }

    return $ids;
}

function plugins_append_dispatch_log_messages($dispatch, array $entry, array &$messages)
{
    plugins_bootstrap_all();
    $dispatch = (string) $dispatch;
    foreach (plugins_discover() as $manifest) {
        $hooks = $manifest['dispatch_log_hooks'] ?? [];
        if (!isset($hooks[$dispatch])) {
            continue;
        }
        $hook = $hooks[$dispatch];
        if (is_callable($hook)) {
            $hook($entry, $messages);
        }
    }
}

function plugins_catalog_test_sections($dbc, array $context)
{
    plugins_bootstrap_all();
    $sections = [];
    foreach (plugins_discover() as $manifest) {
        $callback = $manifest['catalog_test_sections_callback'] ?? null;
        if (!is_callable($callback)) {
            continue;
        }
        $chunk = call_user_func($callback, $dbc, $context);
        if (is_array($chunk)) {
            $sections = array_merge($sections, $chunk);
        }
    }

    return $sections;
}

function plugins_catalog_test_round_trip_skip()
{
    plugins_bootstrap_all();
    $ids = [];
    foreach (plugins_discover() as $manifest) {
        foreach ($manifest['catalog_test_round_trip_skip'] ?? [] as $id) {
            $ids[] = (string) $id;
        }
    }

    return $ids;
}

function plugins_migrate_legacy_function_id($fid)
{
    plugins_bootstrap_all();
    $fid = (string) $fid;
    foreach (plugins_discover() as $manifest) {
        $map = $manifest['legacy_function_ids'] ?? [];
        if (isset($map[$fid])) {
            return (string) $map[$fid];
        }
    }

    return null;
}

function plugins_guess_function_id_from_text($text)
{
    plugins_bootstrap_all();
    $text = (string) $text;
    foreach (plugins_discover() as $manifest) {
        foreach ($manifest['import_text_guessers'] ?? [] as $guesser) {
            if (!is_callable($guesser)) {
                continue;
            }
            $id = call_user_func($guesser, $text);
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }
    }

    return null;
}

function plugins_apply_gui_label_merge(array $def, array $params, array &$merged)
{
    plugins_bootstrap_all();
    $step_id = (string) ($def['id'] ?? '');
    if ($step_id === '') {
        return;
    }
    foreach (plugins_discover() as $manifest) {
        $hooks = $manifest['gui_label_hooks'] ?? [];
        if (!isset($hooks[$step_id])) {
            continue;
        }
        $hook = $hooks[$step_id];
        if (is_callable($hook)) {
            // Call directly (not call_user_func) so by-reference params bind.
            $hook($params, $merged);
        }
    }
}

function plugins_normalize_step_params($fid, array &$params)
{
    plugins_bootstrap_all();
    $fid = (string) $fid;
    foreach (plugins_discover() as $manifest) {
        $hooks = $manifest['normalize_params'] ?? [];
        if (!isset($hooks[$fid])) {
            continue;
        }
        $hook = $hooks[$fid];
        if (is_callable($hook)) {
            // Call directly (not call_user_func) so by-reference params bind.
            $hook($params);
        }
    }
}

/**
 * @return array|null Dispatch result when a plugin handled the step.
 */
function plugins_try_dispatch($dbc, $dispatch, array $step, array $def, array $params, array $config, array $result)
{
    plugins_bootstrap_all();
    $dispatch = (string) $dispatch;
    foreach (plugins_discover() as $manifest) {
        $handlers = $manifest['dispatch_handlers'] ?? [];
        if (!isset($handlers[$dispatch])) {
            continue;
        }
        $handler = $handlers[$dispatch];
        if (!is_callable($handler)) {
            continue;
        }

        return call_user_func($handler, $dbc, $step, $def, $params, $config, $result);
    }

    return null;
}
