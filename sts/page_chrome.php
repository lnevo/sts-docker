<?php
/**
 * Shared page chrome for full STS pages: doctype, head assets, navbar, body wrapper.
 *
 * Used to bring legacy pages onto the STS UI conventions (llm-wiki/ui-conventions.md)
 * without hand-copying a head block into each one. Include-only: produces no output
 * on require. Not for AJAX fragments (get_*.php) - those never emit chrome or <style>.
 *
 *   require_once 'page_chrome.php';
 *   sts_page_start(['title' => 'Scan Cars', 'icon' => 'bi-upc-scan', 'section' => 'data']);
 *   ... page body ...
 *   sts_page_end();
 */

require_once __DIR__ . '/html_helpers.php';

/** Navbar colour, default back link and label per page section (ui-conventions 4.1 / 4.10). */
function sts_page_sections()
{
    return [
        'ops'     => ['nav_class' => 'sts-nav-ops',  'back' => 'operations.php', 'back_label' => 'Operations',  'back_icon' => 'bi-train-freight-front'],
        'report'  => ['nav_class' => 'bg-primary',   'back' => 'reports.html',   'back_label' => 'Reports',     'back_icon' => 'bi-file-text'],
        'data'    => ['nav_class' => 'bg-secondary', 'back' => 'database.html',  'back_label' => 'Database',    'back_icon' => 'bi-database'],
        'maint'   => ['nav_class' => 'bg-secondary', 'back' => 'db-maint.html',  'back_label' => 'Maintenance', 'back_icon' => 'bi-tools'],
        'club'    => ['nav_class' => 'bg-secondary', 'back' => 'club_index.html', 'back_label' => 'Club Operations', 'back_icon' => 'bi-people'],
        'session' => ['nav_class' => 'bg-dark',      'back' => 'session_overview.php', 'back_label' => 'Sessions', 'back_icon' => 'bi-calendar-check'],
    ];
}

/**
 * Emit everything up to and including the opening content wrapper.
 *
 * Options:
 *   title       Page title (navbar brand; <title> gets an "STS - " prefix).
 *   icon        Bootstrap Icons class for the brand, e.g. 'bi-upc-scan'.
 *   section     ops | report | data | maint | club | session (navbar colour + back link).
 *   back_href   Override the section's back link ('' hides it).
 *   back_label  Override the back link label.
 *   links       Extra navbar links: [['href' => ..., 'label' => ..., 'icon' => ...], ...].
 *   print       true adds a Print button.
 *   head_extra  Raw HTML appended inside <head> (page-specific <link>/<style>/<script>).
 *   body_attrs  Raw attributes for <body>, e.g. 'class="x"'. Caller escapes.
 *   container   'fluid' (default) or 'narrow' (max-width 960px, centred).
 *   nav         false omits the navbar (popup windows).
 */
function sts_page_start(array $opts = [])
{
    $sections = sts_page_sections();
    $section_key = isset($opts['section'], $sections[$opts['section']]) ? $opts['section'] : 'data';
    $section = $sections[$section_key];

    $title = isset($opts['title']) ? (string) $opts['title'] : 'STS';
    $icon = isset($opts['icon']) ? (string) $opts['icon'] : 'bi-train-freight-front';
    $back_href = array_key_exists('back_href', $opts) ? (string) $opts['back_href'] : $section['back'];
    $back_label = isset($opts['back_label']) ? (string) $opts['back_label'] : $section['back_label'];
    $back_icon = $back_href === $section['back'] ? $section['back_icon'] : 'bi-arrow-left';
    $links = isset($opts['links']) && is_array($opts['links']) ? $opts['links'] : [];
    $container = (isset($opts['container']) && $opts['container'] === 'narrow') ? 'container sts-page-narrow' : 'container-fluid';

    echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
    echo "  <meta charset=\"UTF-8\">\n";
    echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
    echo '  <title>STS - ' . sts_h($title) . "</title>\n";
    echo "  <link href=\"vendor/bootstrap/bootstrap.min.css\" rel=\"stylesheet\">\n";
    echo "  <link href=\"vendor/bootstrap-icons/bootstrap-icons.min.css\" rel=\"stylesheet\">\n";
    echo "  <link href=\"page_chrome.css?v=2\" rel=\"stylesheet\">\n";
    echo "  <link href=\"touch.css\" rel=\"stylesheet\">\n";
    // Delegated handlers for data-sts-* attributes (strict CSP: no inline scripts).
    echo "  <script src=\"/sts/js/sts-actions.js?v=1\"></script>\n";
    if (!empty($opts['head_extra'])) {
        echo $opts['head_extra'] . "\n";
    }
    echo "</head>\n";
    echo '<body class="sts-page sts-section-' . sts_h($section_key) . '"'
        . (!empty($opts['body_attrs']) ? ' ' . $opts['body_attrs'] : '') . ">\n";

    if (array_key_exists('nav', $opts) && $opts['nav'] === false) {
        // Popup windows (image upload/remove) get no navbar: following a link
        // would navigate the popup away from its opener.
        echo '<main class="' . $container . " sts-page-body pt-3\">\n";
        return;
    }
    $nav_links = [];
    if ($back_href !== '') {
        $nav_links[] = ['href' => $back_href, 'label' => $back_label, 'icon' => $back_icon];
    }
    foreach ($links as $link) {
        if (!empty($link['href']) && !empty($link['label'])) {
            $nav_links[] = $link;
        }
    }
    $nav_links[] = ['href' => 'index.html', 'label' => 'Home', 'icon' => 'bi-house'];
    $nav_links[] = ['href' => 'index-t.html', 'label' => 'Site Map', 'icon' => 'bi-diagram-3'];
    echo sts_navbar_html([
        'title' => $title,
        'icon' => $icon,
        'nav_class' => $section['nav_class'],
        'links' => $nav_links,
        'print' => !empty($opts['print']),
    ]);
    echo '<main class="' . $container . " sts-page-body\">\n";
}

/**
 * The one navbar implementation (ui-conventions 4.1 / 4.10). Returns markup only, and relies on
 * Bootstrap alone (no page_chrome.css), so standalone and generated pages can use it too.
 *
 * Options:
 *   title        Brand text ('' with no icon omits the brand, e.g. the session bar).
 *   icon         Brand icon class, e.g. 'bi-upc-scan'.
 *   nav_class    Extra classes on <nav> (section colour: bg-primary, bg-secondary, sts-nav-ops, ...).
 *   style        Inline style on <nav> (for pages that cannot load a stylesheet).
 *   inner_class  Classes on the container div (default 'container-fluid sts-navbar-inner').
 *   links_class  Classes on the links div (default 'sts-navbar-links d-flex flex-wrap gap-2').
 *   links        [['href','label','icon' (full 'bi-x' class), 'active', 'right', 'attrs' (raw), 'html' (raw
 *                 markup used instead of a link)], ...]. 'right' links sit after the trail at the far edge.
 *   trail        Muted text between left and right links.
 *   flush        true omits the bottom margin (mb-3).
 *   print        true appends a Print button.
 *   extra_html   Trusted markup appended after the links.
 */
function sts_navbar_html(array $opts = [])
{
    $title = isset($opts['title']) ? (string) $opts['title'] : '';
    $icon = isset($opts['icon']) ? (string) $opts['icon'] : '';
    $inner_class = isset($opts['inner_class']) ? (string) $opts['inner_class'] : 'container-fluid sts-navbar-inner';
    $links_class = isset($opts['links_class']) ? (string) $opts['links_class'] : 'sts-navbar-links d-flex flex-wrap gap-2';
    $links = isset($opts['links']) && is_array($opts['links']) ? $opts['links'] : [];
    $trail = isset($opts['trail']) ? (string) $opts['trail'] : '';

    $html = '<nav class="navbar navbar-dark noprint' . (!empty($opts['flush']) ? '' : ' mb-3')
        . (!empty($opts['nav_class']) ? ' ' . sts_h($opts['nav_class']) : '') . '"'
        . (!empty($opts['style']) ? ' style="' . sts_h($opts['style']) . '"' : '') . ">\n";
    $html .= '  <div class="' . sts_h($inner_class) . "\">\n";
    if ($title !== '' || $icon !== '') {
        $html .= '    <span class="navbar-brand">' . ($icon !== '' ? '<i class="bi ' . sts_h($icon) . '"></i> ' : '')
            . sts_h($title) . "</span>\n";
    }
    $html .= '    <div class="' . sts_h($links_class) . "\">\n";
    $left = [];
    $right = [];
    foreach ($links as $link) {
        if (!empty($link['right'])) {
            $right[] = $link;
        } else {
            $left[] = $link;
        }
    }
    foreach ($left as $link) {
        $html .= sts_page_nav_link_ex($link, false);
    }
    // The ms-auto spacer goes on the first element after the left group.
    $spacer_used = false;
    if ($trail !== '') {
        $html .= '      <span class="navbar-text text-white-50 small ms-auto">' . sts_h($trail) . "</span>\n";
        $spacer_used = true;
    }
    foreach ($right as $link) {
        $html .= sts_page_nav_link_ex($link, !$spacer_used);
        $spacer_used = true;
    }
    if (!empty($opts['print'])) {
        $html .= "      <button type=\"button\" class=\"btn btn-light btn-sm\" data-sts-print><i class=\"bi bi-printer\"></i> Print</button>\n";
    }
    if (!empty($opts['extra_html'])) {
        $html .= $opts['extra_html'];
    }
    return $html . "    </div>\n  </div>\n</nav>\n";
}

function sts_page_nav_link_ex(array $link, $spacer)
{
    if (!empty($link['html'])) {
        return $link['html'];
    }
    $href = trim((string) ($link['href'] ?? ''));
    $label = trim((string) ($link['label'] ?? ''));
    if ($href === '' || $label === '') {
        return '';
    }
    $icon = trim((string) ($link['icon'] ?? ''));
    $class = 'btn btn-outline-light btn-sm' . ($spacer ? ' ms-auto' : '') . (!empty($link['active']) ? ' active' : '');
    return '      <a href="' . sts_h($href) . '" class="' . $class . '"' . (!empty($link['attrs']) ? ' ' . $link['attrs'] : '') . '>'
        . ($icon !== '' ? '<i class="bi ' . sts_h($icon) . '"></i> ' : '') . sts_h($label) . "</a>\n";
}

function sts_page_nav_link($href, $label, $icon = '')
{
    $icon_html = $icon !== '' ? '<i class="bi ' . sts_h($icon) . '"></i> ' : '';
    return '      <a href="' . sts_h($href) . '" class="btn btn-outline-light btn-sm">' . $icon_html . sts_h($label) . "</a>\n";
}

/** Close the content wrapper and the document; loads the vendored JS. */
function sts_page_end()
{
    echo sts_page_end_html();
}

/** The closing markup as a string, for die()/exit paths that print a message. */
function sts_page_end_html()
{
    return "</main>\n"
        . "<script src=\"vendor/bootstrap/bootstrap.bundle.min.js\"></script>\n"
        . "<script src=\"vendor/jquery/jquery-3.6.0.min.js\"></script>\n"
        . "</body>\n</html>\n";
}
