<?php
// Shared navigation for the operations workflow pages (include-only).
// Defines the ordered step list, the green navbar, and the step bar.
// Styles live in operations_ui.css.

require_once __DIR__ . '/html_helpers.php';
require_once __DIR__ . '/page_chrome.php';

// Ordered workflow steps: file => [group, label, short label, icon]
function ops_steps()
{
    return array(
        array('file' => 'generate.php',          'group' => 'Before', 'label' => 'Generate Car Orders',   'short' => 'Generate',   'icon' => 'bi-gear'),
        array('file' => 'fill_orders.php',       'group' => 'Before', 'label' => 'Fill Car Orders',       'short' => 'Fill',       'icon' => 'bi-box-seam'),
        array('file' => 'reposition.php',        'group' => 'Before', 'label' => 'Reposition Empty Cars', 'short' => 'Reposition', 'icon' => 'bi-arrows-move'),
        array('file' => 'build_switchlists.php', 'group' => 'During', 'label' => 'Build Switch Lists',    'short' => 'Build',      'icon' => 'bi-list-check'),
        array('file' => 'pick_up.php',           'group' => 'During', 'label' => 'Pick Up Cars',          'short' => 'Pick Up',    'icon' => 'bi-arrow-up-circle'),
        array('file' => 'organize_cars.php',     'group' => 'During', 'label' => 'Organize Cars',         'short' => 'Organize',   'icon' => 'bi-sort-numeric-up'),
        array('file' => 'set_out.php',           'group' => 'During', 'label' => 'Set Out Cars',          'short' => 'Set Out',    'icon' => 'bi-arrow-down-circle'),
        array('file' => 'load_unload.php',       'group' => 'After',  'label' => 'Load / Unload Cars',    'short' => 'Load/Unload', 'icon' => 'bi-truck'),
    );
}

function ops_h($text)
{
    return sts_h($text);
}

// Green operations navbar. $extra_html is trusted markup (e.g. a Print button).
function ops_render_navbar($current, $title, $icon, $extra_html = '')
{
    $links = array(
        array('operations.php',       'bi-arrow-left',     'Operations'),
        array('session_overview.php', 'bi-calendar-check', 'Sessions'),
        array('editor.html',          'bi-pencil-square',  'Session Editor'),
        array('reports.html',         'bi-file-text',      'Reports'),
        array('index.html',           'bi-house',          'Home'),
        array('index-t.html',         'bi-diagram-3',      'Site Map'),
    );
    $nav_links = array();
    foreach ($links as $l) {
        $nav_links[] = array('href' => $l[0], 'icon' => $l[1], 'label' => $l[2]);
    }
    return sts_navbar_html(array(
        'title' => $title,
        'icon' => $icon,
        'nav_class' => 'ops-navbar',
        'style' => 'background-color: #2e7d32;',
        'inner_class' => 'container-fluid',
        'links_class' => 'ops-navbar-links',
        'links' => $nav_links,
        'extra_html' => $extra_html,
    ));
}

// Step bar: Prev, grouped step pills, Next, optional session badge.
function ops_render_step_bar($current, $session_nbr = null)
{
    $steps = ops_steps();
    $idx = -1;
    foreach ($steps as $i => $s) {
        if ($s['file'] === $current) {
            $idx = $i;
            break;
        }
    }

    $html = '<div class="ops-stepbar noprint" role="navigation" aria-label="Operations steps"><div class="ops-stepbar-inner">';
    if ($idx > 0) {
        $p = $steps[$idx - 1];
        $html .= '<a class="ops-step-nav" href="' . ops_h($p['file']) . '" title="' . ops_h($p['label']) . '">'
               . '<i class="bi bi-chevron-left"></i> Prev</a>';
    }

    $group = null;
    foreach ($steps as $i => $s) {
        if ($s['group'] !== $group) {
            if ($group !== null) {
                $html .= '</span>';
            }
            $group = $s['group'];
            $html .= '<span class="ops-step-group"><span class="ops-step-group-label">' . ops_h($group) . '</span>';
        }
        $is_cur = ($i === $idx);
        $html .= '<a class="ops-step' . ($is_cur ? ' ops-step-current' : '') . '" href="' . ops_h($s['file']) . '"'
               . ($is_cur ? ' aria-current="page"' : '') . ' title="' . ops_h($s['label']) . '">'
               . '<i class="bi ' . ops_h($s['icon']) . '"></i> ' . ops_h($s['short']) . '</a>';
    }
    if ($group !== null) {
        $html .= '</span>';
    }

    if ($idx >= 0 && $idx < count($steps) - 1) {
        $n = $steps[$idx + 1];
        $html .= '<a class="ops-step-nav" href="' . ops_h($n['file']) . '" title="' . ops_h($n['label']) . '">'
               . 'Next <i class="bi bi-chevron-right"></i></a>';
    }
    // Pages that render the bar before opening the DB pass null; the badge then
    // fills itself from get_session_nbr.php so every step page shows the session.
    $html .= '<a class="ops-session-badge" href="session_overview.php" title="Session overview">'
           . '<i class="bi bi-calendar-check"></i> Session <span class="ops-session-nbr">'
           . ($session_nbr !== null ? ops_h($session_nbr) : '&hellip;') . '</span></a>';
    $html .= '</div></div>';
    if ($session_nbr === null) {
        $html .= '<script>(function(){var el=document.currentScript.previousElementSibling'
               . '.querySelector(".ops-session-nbr");fetch("get_session_nbr.php",{cache:"no-cache"})'
               . '.then(function(r){return r.text();}).then(function(t){el.textContent=t.trim()||"?";})'
               . '.catch(function(){el.textContent="?";});})();</script>';
    }
    return $html;
}
