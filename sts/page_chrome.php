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
 *   body_attrs  Raw attributes for <body>, e.g. 'onload="..."'. Caller escapes.
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
    echo '<nav class="navbar navbar-dark noprint mb-3 ' . sts_h($section['nav_class']) . "\">\n";
    echo "  <div class=\"container-fluid sts-navbar-inner\">\n";
    echo '    <span class="navbar-brand"><i class="bi ' . sts_h($icon) . '"></i> ' . sts_h($title) . "</span>\n";
    echo "    <div class=\"sts-navbar-links\">\n";
    if ($back_href !== '') {
        echo sts_page_nav_link($back_href, $back_label, $back_icon);
    }
    foreach ($links as $link) {
        if (!empty($link['href']) && !empty($link['label'])) {
            echo sts_page_nav_link($link['href'], $link['label'], isset($link['icon']) ? $link['icon'] : '');
        }
    }
    echo sts_page_nav_link('index.html', 'Home', 'bi-house');
    echo sts_page_nav_link('index-t.html', 'Site Map', 'bi-diagram-3');
    if (!empty($opts['print'])) {
        echo "      <button type=\"button\" class=\"btn btn-light btn-sm\" onclick=\"window.print()\"><i class=\"bi bi-printer\"></i> Print</button>\n";
    }
    echo "    </div>\n  </div>\n</nav>\n";
    echo '<main class="' . $container . " sts-page-body\">\n";
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
