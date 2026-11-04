<?php

/**
 * Script references for generated session output (switch lists, waybills,
 * indexes, reports) and for the filters so.php injects when it serves a stored
 * page. The pages run under script-src 'self', so they carry no inline code:
 * markup uses data-sts-* attributes and these two files supply the behaviour.
 * Absolute /sts/js/ URLs keep the files working when opened standalone from
 * backups/session_state/sessions/.
 */

const SESSION_OUTPUT_JS_VERSION = 1;

/** The two <script src> tags every generated page needs. */
function session_output_script_tags()
{
    $v = '?v=' . SESSION_OUTPUT_JS_VERSION;
    return '<script src="/sts/js/sts-actions.js' . $v . '"></script>'
        . '<script src="/sts/js/session_output.js' . $v . '"></script>';
}

/** A JSON data block read by session_output.js (id sts-so-<name>). */
function session_output_data_block($name, $data = [])
{
    $json = json_encode(
        (object) $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    return '<script type="application/json" id="sts-so-' . htmlspecialchars($name, ENT_QUOTES) . '">'
        . $json . '</script>';
}

/**
 * Upgrade one stored session page written before generated output went
 * script-free: drop inline <script> code, turn the old inline handlers into
 * data-sts-* attributes and add the script references. Idempotent; returns the
 * input unchanged for pages that are already clean.
 */
function session_output_upgrade_html($html)
{
    // Output stored before the assets were vendored links Bootstrap from a CDN,
    // which the CSP (and the private-network rule) blocks: point it at sts/vendor/.
    $html = strtr($html, [
        'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css' => '/sts/vendor/bootstrap/bootstrap.min.css',
        'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js' => '/sts/vendor/bootstrap/bootstrap.bundle.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css' => '/sts/vendor/bootstrap-icons/bootstrap-icons.min.css',
        'https://code.jquery.com/jquery-3.6.0.min.js' => '/sts/vendor/jquery/jquery-3.6.0.min.js',
    ]);
    $had_inline = preg_match('#<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>#i', $html) === 1;
    $had_attr = preg_match('#\son(?:click|change|submit|error|load)="#i', $html) === 1;
    if (!$had_inline && !$had_attr) {
        return $html;
    }

    // Session number baked into the old Train/Style picker scripts.
    $sid = null;
    if (preg_match('#var sid=(\d+);#', $html, $m)) {
        $sid = (int) $m[1];
    }

    $html = preg_replace(
        '#<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>.*?</script>#is',
        '',
        $html
    );

    $html = preg_replace('#\sonclick=(["\'])(?:window\.print\(\)|stsSafePrint\(\))\1#', ' data-sts-click="sessPrint"', $html);
    $html = str_replace(
        ' onchange="if(this.value){window.location.href=this.value;}"',
        ' data-sts-change="sessGoValue"',
        $html
    );
    $html = preg_replace(
        '#\sonchange="if\(this\.value\)\{window\.location\.href=\'so\.php\?f=session_\'\+this\.value\+\'(/[a-z_]+\.html)\';\}"#',
        ' data-sts-change="sessGoSo" data-so-prefix="session_" data-so-suffix="$1"',
        $html
    );
    $html = preg_replace(
        '#\sonchange="if\(this\.value\)\{window\.location\.href=\'so\.php\?f=(session_\d+/[a-z_]+_)\'\+this\.value\+\'\.html\';\}"#',
        ' data-sts-change="sessGoSo" data-so-prefix="$1" data-so-suffix=".html"',
        $html
    );
    $html = str_replace(" onerror=\"this.style.display='none'\"", ' data-sts-hide-on-error', $html);
    $html = str_replace(' onchange="toggle_mobile_instructions();"', ' data-sts-change="sessMobileInstr"', $html);
    $html = str_replace(' onsubmit="return false;"', '', $html);

    if ($sid !== null) {
        $html = preg_replace(
            '#<select id="wb-train-select"([^>]*?)>#',
            '<select id="wb-train-select"$1 data-session="' . $sid . '" data-sts-change="sessWbTrainGo">',
            $html,
            1
        );
        $html = preg_replace(
            '#<select id="sw-train-select"([^>]*?)>#',
            '<select id="sw-train-select"$1 data-session="' . $sid . '" data-sts-change="sessSwGo">',
            $html,
            1
        );
        $html = preg_replace(
            '#<select id="sw-style-select"([^>]*?)>#',
            '<select id="sw-style-select"$1 data-sts-change="sessSwGo">',
            $html,
            1
        );
    } else {
        $html = preg_replace(
            '#<select id="sw-style-select"([^>]*?)>#',
            '<select id="sw-style-select"$1 data-sts-change="sessSwStyleUrl">',
            $html,
            1
        );
    }

    if (strpos($html, 'session_output.js') === false) {
        $tags = session_output_script_tags();
        $html = stripos($html, '</head>') !== false
            ? preg_replace('#</head>#i', $tags . '</head>', $html, 1)
            : $tags . $html;
    }
    return $html;
}

/**
 * Upgrade every stored page under one session's output directory. Returns the
 * number of files rewritten.
 */
function session_output_upgrade_session($session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $dir = rtrim((string) $root, '/') . '/session_' . (int) $session_nbr;
    if (!is_dir($dir)) {
        return 0;
    }
    $changed = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'html') {
            continue;
        }
        $old = (string) @file_get_contents($file->getPathname());
        $new = session_output_upgrade_html($old);
        if ($new !== $old) {
            file_put_contents($file->getPathname(), $new);
            $changed++;
        }
    }
    return $changed;
}
