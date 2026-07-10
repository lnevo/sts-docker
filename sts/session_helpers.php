<?php
/**
 * Session output (/sts/), phase manifest, waybills, recipe control flow.
 */

require_once __DIR__ . '/warm_start_helpers.php';

function session_web_root()
{
    return '/var/www/html/sts';
}

function session_repo_root($helpers_root = null)
{
    if ($helpers_root === null) {
        $helpers_root = __DIR__;
    }
    return rtrim($helpers_root, '/');
}

function session_dir_for($session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    return rtrim($root, '/') . '/session_' . (int) $session_nbr;
}

function session_manifest_path($session_nbr, $root = null)
{
    return session_dir_for($session_nbr, $root) . '/manifest.json';
}

function session_load_manifest($session_nbr, $root = null)
{
    $path = session_manifest_path($session_nbr, $root);
    if (!is_readable($path)) {
        return ['session' => (string) $session_nbr, 'phases' => [], 'jobs' => []];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : ['session' => (string) $session_nbr, 'phases' => [], 'jobs' => []];
}

function session_save_manifest($session_nbr, array $manifest, $root = null)
{
    $dir = session_dir_for($session_nbr, $root);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $manifest['session'] = (string) $session_nbr;
    $manifest['updated'] = date('c');
    file_put_contents(session_manifest_path($session_nbr, $root), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    session_write_session_index($session_nbr, $root);
}

function session_nav_stylesheet_path()
{
    return '/sts/session-nav.css';
}

function session_bootstrap_head_links()
{
    return '<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">'
        . '<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">';
}

function session_nav_stylesheet_link($href = null)
{
    $href = $href ?? session_nav_stylesheet_path();

    return '<link rel="stylesheet" href="' . htmlspecialchars((string) $href) . '">';
}

function session_static_head_assets($css_href = null)
{
    return session_bootstrap_head_links() . session_nav_stylesheet_link($css_href);
}

function session_nav_icon_for_label($label)
{
    $key = strtolower(trim((string) $label));
    static $map = [
        'sts main menu' => 'house',
        'session editor' => 'pencil-square',
        'all sessions' => 'collection',
        'sessions' => 'collection',
        'prev' => 'chevron-left',
        'next' => 'chevron-right',
        'mobile' => 'phone',
        'half sheet' => 'file-earmark',
        'waybills' => 'file-text',
        'waybill list' => 'file-text',
        'csv' => 'filetype-csv',
        'json' => 'filetype-json',
    ];
    if (preg_match('/^session \d+$/', $key)) {
        return 'calendar-event';
    }
    if (str_contains($key, ' index') || str_contains($key, 'print all')) {
        return 'list-ul';
    }

    return $map[$key] ?? '';
}

/** @param list<array{href: string, label: string, icon?: string, active?: bool}> $links */
function session_nav_bar_html(array $links, $trail = '', $extra_class = 'noprint')
{
    $class = 'navbar navbar-dark';
    if ($extra_class !== '') {
        $class .= ' ' . $extra_class;
    }
    $html = '<nav class="' . $class . '" style="background-color: #343a40;">';
    $html .= '<div class="container-fluid"><div class="d-flex flex-wrap align-items-center gap-2 w-100">';
    foreach ($links as $link) {
        $href = trim((string) ($link['href'] ?? ''));
        $label = trim((string) ($link['label'] ?? ''));
        if ($href === '' || $label === '') {
            continue;
        }
        $icon = trim((string) ($link['icon'] ?? session_nav_icon_for_label($label)));
        $btn_class = 'btn btn-outline-light btn-sm';
        if (!empty($link['active'])) {
            $btn_class .= ' active';
        }
        $html .= '<a href="' . htmlspecialchars($href) . '" class="' . $btn_class . '">';
        if ($icon !== '') {
            $html .= '<i class="bi bi-' . htmlspecialchars($icon) . '"></i> ';
        }
        $html .= htmlspecialchars($label) . '</a>';
    }
    if ($trail !== '') {
        $html .= '<span class="navbar-text ms-auto text-white-50 small">' . htmlspecialchars($trail) . '</span>';
    }
    $html .= '</div></div></nav>';

    return $html;
}

/** @param list<array{href: string, label: string, icon?: string, active?: bool}> $links */
function session_render_nav_bar(array $links, $trail = '')
{
    echo session_nav_bar_html($links, $trail);
}

function session_write_session_index($session_nbr, $root = null)
{
    $root = $root ?? session_web_root();
    $dir = session_dir_for($session_nbr, $root);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $template = __DIR__ . '/session_index_template.php';
    if (is_readable($template)) {
        copy($template, $dir . '/index.php');
    }
    $manifest = session_load_manifest($session_nbr, $root);
    session_ensure_output_stubs($session_nbr, $manifest, $root);
}

function session_write_empty_waybill_index($out_dir, array $options = [])
{
    if (is_file(rtrim($out_dir, '/') . '/index.html')) {
        return ['path' => rtrim($out_dir, '/') . '/index.html', 'count' => 0, 'skipped' => true];
    }
    if (!is_dir($out_dir)) {
        mkdir($out_dir, 0755, true);
    }
    $title = $options['title'] ?? 'Waybills';
    $back = $options['back_href'] ?? '../session.php';
    $back_label = $options['back_label'] ?? 'All Sessions';
    $message = $options['message'] ?? 'No waybills available yet. Run Generate Waybill List in the workflow after switch lists.';
    $index_html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . session_static_head_assets()
        . '</head><body>';
    $index_html .= session_nav_bar_html([
        ['href' => $back, 'label' => $back_label],
    ], $title);
    $index_html .= '<main><h1>' . htmlspecialchars($title) . '</h1>'
        . '<div class="card"><p>' . htmlspecialchars($message) . '</p>'
        . '<ul><li>No waybills available.</li></ul></div></main></body></html>';
    file_put_contents($out_dir . '/index.html', $index_html);
    $print_all = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . ' — print all</title>'
        . session_static_head_assets()
        . '</head><body>';
    $print_all .= session_nav_bar_html([
        ['href' => 'index.html', 'label' => 'Waybill list', 'icon' => 'file-text'],
    ], 'Print all');
    $print_all .= '<main><h1>' . htmlspecialchars($title) . '</h1>'
        . '<div class="card"><p>No waybills to print.</p></div></main></body></html>';
    file_put_contents($out_dir . '/print_all.html', $print_all);
    return [
        'path' => $out_dir . '/index.html',
        'print_all' => $out_dir . '/print_all.html',
        'count' => 0,
    ];
}

function session_ensure_output_stubs($session_nbr, array $manifest, $root = null)
{
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $root = $root ?? session_web_root();
    $session_dir = session_dir_for($session_nbr, $root);
    if (!is_dir($session_dir)) {
        mkdir($session_dir, 0755, true);
    }

    session_write_empty_waybill_index(session_waybill_dir_for($session_nbr, null, $root), [
        'title' => 'Waybills — session ' . (int) $session_nbr,
        'back_href' => '../session.php',
    ]);

    foreach ($manifest['phases'] ?? [] as $phase) {
        $phase_num = (int) ($phase['phase'] ?? 0);
        if ($phase_num < 1) {
            continue;
        }
        $phase_dir = session_phase_output_dir($session_nbr, $phase_num, $root);
        if (!is_dir($phase_dir)) {
            mkdir($phase_dir, 0755, true);
        }
        session_write_empty_waybill_index(session_waybill_dir_for($session_nbr, $phase_num, $root), [
            'title' => 'Waybills — session ' . (int) $session_nbr . ', phase ' . $phase_num,
            'back_href' => '../../index.php',
        ]);
        foreach ($phase['jobs'] ?? [] as $job) {
            $job = trim((string) $job);
            if ($job === '') {
                continue;
            }
            $job_dir = master_sw_job_output_dir($phase_dir, $job);
            if (!is_file(master_sw_job_index_path($job_dir))) {
                master_sw_render_empty_job_index(
                    null,
                    $job,
                    $job_dir,
                    (string) $session_nbr,
                    'No switch list files found for this train and phase. Run Generate Switch Lists in the workflow.'
                );
            }
        }
    }

    foreach (array_keys($manifest['jobs'] ?? []) as $job) {
        $job = trim((string) $job);
        if ($job === '') {
            continue;
        }
        foreach ($manifest['jobs'][$job]['phases'] ?? [] as $phase_num) {
            $phase_dir = session_phase_output_dir($session_nbr, (int) $phase_num, $root);
            $job_dir = master_sw_job_output_dir($phase_dir, $job);
            if (!is_file(master_sw_job_index_path($job_dir))) {
                master_sw_render_empty_job_index(
                    null,
                    $job,
                    $job_dir,
                    (string) $session_nbr,
                    'No switch list files found for this train and phase. Run Generate Switch Lists in the workflow.'
                );
            }
        }
    }
}

function session_condition_variables()
{
    return [
        ['key' => 'session_nbr', 'label' => 'Session number'],
        ['key' => 'unfilled_count', 'label' => 'Unfilled order count'],
        ['key' => 'stg_backlog_eligible', 'label' => 'STG backlog eligible at Scully'],
        ['key' => 'stg_backlog_on_jobs', 'label' => 'STG backlog on staging jobs'],
        ['key' => 'cars_on_job', 'label' => 'Cars on job (param: job name)'],
        ['key' => 'cars_at_location', 'label' => 'Cars at location (param: location code)'],
        ['key' => 'awaiting_assignment', 'label' => 'Cars awaiting assignment'],
    ];
}

function session_condition_operators()
{
    return ['=', '!=', '<', '<=', '>', '>='];
}

function session_evaluate_context($dbc, array $config = [])
{
    $session = warm_start_get_session($dbc);
    $staging = warm_start_staging_job_names($dbc, $config);
    $scully = warm_start_staging_backlog_for_job($dbc, 'STG-SCULLY', $config);
    $summary = warm_start_summarize($dbc);
    return [
        'session_nbr' => (int) $session,
        'unfilled_count' => warm_start_count_unfilled($dbc),
        'stg_backlog_eligible' => (int) ($scully['eligible'] ?? 0),
        'stg_backlog_on_jobs' => (int) ($scully['on_jobs'] ?? 0),
        'awaiting_assignment' => (int) ($summary['awaiting_assignment'] ?? 0),
        '_dbc' => $dbc,
        '_config' => $config,
    ];
}

function session_evaluate_condition(array $ctx, $variable, $operator, $value, array $extra = [])
{
    $left = null;
    switch ($variable) {
        case 'session_nbr':
        case 'unfilled_count':
        case 'stg_backlog_eligible':
        case 'stg_backlog_on_jobs':
        case 'awaiting_assignment':
            $left = (float) ($ctx[$variable] ?? 0);
            break;
        case 'cars_on_job':
            $job = trim($extra['job'] ?? $value);
            $dbc = $ctx['_dbc'];
            $jid = warm_start_job_id($dbc, $job);
            if ($jid <= 0) {
                $left = 0;
            } else {
                $rs = mysqli_query($dbc, 'SELECT COUNT(*) AS c FROM cars WHERE handled_by_job_id = "' . (int) $jid . '" AND current_location_id = 0');
                $left = (float) mysqli_fetch_array($rs)['c'];
            }
            break;
        case 'cars_at_location':
            $loc = trim($extra['location'] ?? $value);
            $dbc = $ctx['_dbc'];
            $lid = warm_start_location_id_by_code($dbc, strtoupper($loc));
            if ($lid <= 0) {
                $left = 0;
            } else {
                $rs = mysqli_query($dbc, 'SELECT COUNT(*) AS c FROM cars WHERE current_location_id = "' . (int) $lid . '"');
                $left = (float) mysqli_fetch_array($rs)['c'];
            }
            break;
        default:
            return true;
    }
    $right = (float) $value;
    switch ($operator) {
        case '=': return $left == $right;
        case '!=': return $left != $right;
        case '<': return $left < $right;
        case '<=': return $left <= $right;
        case '>': return $left > $right;
        case '>=': return $left >= $right;
        default: return true;
    }
}

function session_manual_generate_shipment($dbc, $shipment_code)
{
    $session = warm_start_get_session($dbc);
    $code_esc = mysqli_real_escape_string($dbc, $shipment_code);
    $rs = mysqli_query($dbc, 'SELECT id, min_amount, max_amount FROM shipments WHERE code = "' . $code_esc . '" LIMIT 1');
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return ['generated' => 0, 'error' => 'Shipment not found: ' . $shipment_code];
    }
    $ship = mysqli_fetch_array($rs);
    $shipment_id = (int) $ship['id'];
    mysqli_query($dbc, 'UPDATE shipments SET last_ship_date = ' . (int) $session . ' WHERE id = "' . $shipment_id . '"');

    $rs = mysqli_query($dbc, 'SELECT max(substr(waybill_number, 6, 2)) FROM car_orders WHERE waybill_number LIKE "' . str_pad($session, 3, '0', STR_PAD_LEFT) . '-M__"');
    $row = mysqli_fetch_row($rs);
    $order_counter = (int) ($row[0] ?? 0);

    $min_amount = (float) $ship['min_amount'];
    $max_amount = (float) $ship['max_amount'];
    $num_cars = max(1, (int) round(mt_rand((int) ($min_amount * 100), (int) ($max_amount * 100)) / 100));

    $generated = 0;
    for ($j = 0; $j < $num_cars; $j++) {
        $order_counter++;
        $wb = str_pad($session, 3, '0', STR_PAD_LEFT) . '-M' . str_pad($order_counter, 2, '0', STR_PAD_LEFT);
        if (!mysqli_query($dbc, 'INSERT INTO car_orders (waybill_number, shipment, car) VALUES ("' . mysqli_real_escape_string($dbc, $wb) . '", "' . $shipment_id . '", "0")')) {
            break;
        }
        $generated++;
    }
    return ['generated' => $generated, 'shipment' => $shipment_code, 'session' => $session];
}

function session_resolve_jobs_param($jobs_param)
{
    $jobs_param = trim((string) $jobs_param);
    if ($jobs_param === '' || strtolower($jobs_param) === 'all') {
        return ['D749', 'NVL', 'CK1'];
    }
    return array_values(array_filter(array_map('trim', explode(',', $jobs_param))));
}

function session_phase_output_dir($session_nbr, $phase_num, $root = null)
{
    return session_dir_for($session_nbr, $root) . '/phase_' . str_pad((int) $phase_num, 2, '0', STR_PAD_LEFT);
}

function session_register_phase(array &$manifest, $phase_num, array $meta)
{
    $manifest['phases'] = $manifest['phases'] ?? [];
    $manifest['phases'][] = array_merge(['phase' => (int) $phase_num], $meta);
    foreach ($meta['jobs'] ?? [] as $job) {
        if (!isset($manifest['jobs'][$job])) {
            $manifest['jobs'][$job] = ['phases' => []];
        }
        if (!in_array((int) $phase_num, $manifest['jobs'][$job]['phases'], true)) {
            $manifest['jobs'][$job]['phases'][] = (int) $phase_num;
        }
    }
}

function session_waybill_dir_for($session_nbr, $phase_num = null, $root = null)
{
    $root = $root ?? session_web_root();
    if ($phase_num === null) {
        return session_dir_for($session_nbr, $root) . '/waybills';
    }
    return session_phase_output_dir($session_nbr, $phase_num, $root) . '/waybills';
}

function session_write_waybill_bundle($dbc, $out_dir, array $waybill_numbers, array $options = [])
{
    if (!is_dir($out_dir)) {
        mkdir($out_dir, 0755, true);
    }
    $settings = waybill_print_settings($dbc);
    $written = [];
    $list_items = '';
    $bundle_sheets = '';

    foreach ($waybill_numbers as $waybill_number) {
        $safe = waybill_print_safe_filename($waybill_number);
        $file = $safe . '.html';
        $page = waybill_print_render_page($dbc, $waybill_number, [
            'settings' => $settings,
            'show_controls' => true,
            'nav_html' => '<a href="index.html">Waybill list</a>',
        ]);
        if ($page === '') {
            continue;
        }
        file_put_contents($out_dir . '/' . $file, $page);
        $written[] = $waybill_number;
        $body = waybill_print_render_body($dbc, $waybill_number, $settings);
        $bundle_sheets .= '<div class="waybill-sheet">' . $body . '</div>';
        $list_items .= '<li><a href="' . htmlspecialchars($file) . '">' . htmlspecialchars($waybill_number) . '</a></li>';
    }

    $title = $options['title'] ?? 'Waybills';
    $back = $options['back_href'] ?? '../session.php';
    $back_label = $options['back_label'] ?? 'All Sessions';
    $index_html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . session_static_head_assets()
        . '</head><body>';
    $index_html .= session_nav_bar_html([
        ['href' => $back, 'label' => $back_label],
    ], $title);
    $index_html .= '<main><h1>' . htmlspecialchars($title) . '</h1>'
        . '<div class="card"><p>' . count($written) . ' printable waybill(s) generated from the current database.</p>'
        . ($written ? '<p><a href="print_all.html"><strong>Print all waybills</strong></a></p>' : '')
        . '<ul>' . ($list_items !== '' ? $list_items : '<li>No waybills available.</li>') . '</ul></div>'
        . '</main></body></html>';
    file_put_contents($out_dir . '/index.html', $index_html);

    $print_all = waybill_print_render_bundle_page(
        $title . ' — print all',
        $bundle_sheets,
        ['back_href' => 'index.html']
    );
    file_put_contents($out_dir . '/print_all.html', $print_all);

    return [
        'path' => $out_dir . '/index.html',
        'print_all' => $out_dir . '/print_all.html',
        'count' => count($written),
        'waybills' => $written,
    ];
}

function session_refresh_session_waybills($dbc, $session_nbr, $root = null)
{
    require_once __DIR__ . '/waybill_print_helpers.php';
    $root = $root ?? session_web_root();
    $numbers = waybill_print_session_numbers($dbc, $session_nbr);
    return session_write_waybill_bundle($dbc, session_waybill_dir_for($session_nbr, null, $root), $numbers, [
        'title' => 'Waybills — session ' . (int) $session_nbr,
        'back_href' => '../session.php',
    ]);
}

function session_generate_waybills_for_phase($dbc, $session_nbr, $phase_num, $root = null)
{
    require_once __DIR__ . '/waybill_print_helpers.php';
    $root = $root ?? session_web_root();
    $out_dir = session_waybill_dir_for($session_nbr, $phase_num, $root);
    $numbers = waybill_print_session_numbers($dbc, $session_nbr);
    $phase_back = '../index.html';
    $result = session_write_waybill_bundle($dbc, $out_dir, $numbers, [
        'title' => 'Waybills — session ' . (int) $session_nbr . ', phase ' . (int) $phase_num,
        'back_href' => $phase_back,
    ]);
    $session_bundle = session_refresh_session_waybills($dbc, $session_nbr, $root);
    $result['session_print_all'] = $session_bundle['print_all'] ?? null;
    $result['session_count'] = $session_bundle['count'] ?? 0;
    return $result;
}

function session_run_recipe($dbc, array $recipe, array $options = [])
{
    require_once __DIR__ . '/operational_steps_catalog.php';
    require_once __DIR__ . '/master_switchlist_helpers.php';
    $config = warm_start_merge_config($options['config'] ?? []);
    $format = $options['format'] ?? 'phased';
    $root = $options['session_root'] ?? session_web_root();
    $from_step = max(1, (int) ($options['from_step'] ?? 1));
    $to_step = min(count($recipe['steps'] ?? []), (int) ($options['to_step'] ?? count($recipe['steps'] ?? [])));
    $session_nbr = warm_start_get_session($dbc);
    $manifest = session_load_manifest($session_nbr, $root);
    $phase_num = count($manifest['phases'] ?? []);
    $ctx = session_evaluate_context($dbc, $config);
    $log = [];
    $stopped = false;
    $loop_error = null;
    $step_span = max(1, $to_step - $from_step + 1);
    $max_iterations = max(500, $step_span * 100);
    $iterations = 0;
    $pc = $from_step - 1;

    while ($pc >= 0 && $pc < $to_step) {
        $iterations++;
        if ($iterations > $max_iterations) {
            $stopped = true;
            $loop_error = 'Possible infinite goto loop (exceeded ' . $max_iterations . ' step executions).';
            $log[] = ['step' => $pc + 1, 'action' => 'loop_guard', 'error' => $loop_error];
            break;
        }

        $step = $recipe['steps'][$pc] ?? null;
        if (!is_array($step)) {
            $pc++;
            continue;
        }
        $n = $pc + 1;
        $fid = $step['function'] ?? '';

        if ($fid === 'stop') {
            $stopped = true;
            $log[] = ['step' => $n, 'action' => 'stop'];
            break;
        }
        if ($fid === 'goto') {
            $target = operational_steps_goto_resolve_step($recipe, $step['params'] ?? []);
            $total = count($recipe['steps']);
            if (operational_steps_goto_target_allowed($n, $target, $total)) {
                $pc = $target - 1;
                $log[] = ['step' => $n, 'action' => 'goto', 'target' => $target];
            } else {
                $reason = ($target > 0 && $target <= $n)
                    ? 'backward goto not allowed (use Repeat to loop a section)'
                    : 'invalid target';
                $log[] = ['step' => $n, 'action' => 'goto', 'target' => $target, 'error' => $reason];
                $pc++;
            }
            continue;
        }
        if ($fid === 'if_then') {
            $p = $step['params'] ?? [];
            $ok = session_evaluate_condition(
                $ctx,
                $p['variable'] ?? 'session_nbr',
                $p['operator'] ?? '=',
                $p['value'] ?? '0',
                $p
            );
            $log[] = ['step' => $n, 'action' => 'if_then', 'result' => $ok];
            $pc++;
            if (!$ok) {
                $pc++;
            }
            continue;
        }
        if ($fid === 'section_label') {
            $log[] = ['step' => $n, 'action' => 'section_label', 'label' => $step['params']['label'] ?? ''];
            $pc++;
            continue;
        }

        if ($fid === 'generate_switchlists') {
            $phase_num++;
            $jobs = session_resolve_jobs_param($step['params']['jobs'] ?? 'all');
            $phase_dir = session_phase_output_dir($session_nbr, $phase_num, $root);
            $fmt = master_sw_normalize_switchlist_format($step['params']['format'] ?? $format);
            $written = master_sw_generate_for_jobs($dbc, $jobs, $phase_dir, $config, ['format' => $fmt]);
            session_register_phase($manifest, $phase_num, [
                'step' => $n,
                'jobs' => $jobs,
                'format' => $fmt,
                'label' => operational_steps_compile_recipe(['steps' => [$step]])[0]['instruction'] ?? 'Generate Switch Lists',
                'output' => $phase_dir,
            ]);
            $log[] = ['step' => $n, 'phase' => $phase_num, 'written' => $written];
            $pc++;
            continue;
        }
        if ($fid === 'generate_waybills') {
            if ($phase_num < 1) {
                $phase_num = 1;
            }
            $wb = session_generate_waybills_for_phase($dbc, $session_nbr, $phase_num, $root);
            foreach ($manifest['phases'] as &$phase_entry) {
                if ((int) ($phase_entry['phase'] ?? 0) === (int) $phase_num) {
                    $phase_entry['waybills'] = [
                        'count' => $wb['count'] ?? 0,
                        'index' => 'waybills/index.html',
                        'print_all' => 'waybills/print_all.html',
                    ];
                    break;
                }
            }
            unset($phase_entry);
            $manifest['waybills'] = [
                'count' => $wb['session_count'] ?? ($wb['count'] ?? 0),
                'index' => 'waybills/index.html',
                'print_all' => 'waybills/print_all.html',
                'updated' => date('c'),
            ];
            $log[] = ['step' => $n, 'phase' => $phase_num, 'waybills' => $wb];
            $pc++;
            continue;
        }

        $dispatch_opts = array_merge($config, ['session_root' => $root, 'phase' => $phase_num]);
        $result = operational_steps_dispatch_step($dbc, $step, $dispatch_opts);
        $log[] = array_merge(['step' => $n], $result);
        $ctx = session_evaluate_context($dbc, $config);
        $pc++;
    }

    session_save_manifest($session_nbr, $manifest, $root);
    return [
        'session' => (string) $session_nbr,
        'phases' => $phase_num,
        'stopped' => $stopped,
        'error' => $loop_error,
        'log' => $log,
        'manifest' => $manifest,
    ];
}

function session_discover_sessions($root = null)
{
    $root = $root ?? session_web_root();
    $sessions = [];
    if (!is_dir($root)) {
        return $sessions;
    }
    foreach (scandir($root) ?: [] as $entry) {
        if (preg_match('/^session_(\d+)$/', $entry, $m)) {
            $sessions[] = (int) $m[1];
        }
    }
    sort($sessions);
    return $sessions;
}
