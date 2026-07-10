<?php
/**
 * Build master (multi-phase) switch lists for phased local jobs.
 * Dry-runs session ops inside a transaction and rolls back so live state is unchanged.
 */

require_once __DIR__ . '/session_runtime.php';
session_runtime_bootstrap();

function master_sw_job_meta($dbc, $job_name)
{
    $job_name_esc = mysqli_real_escape_string($dbc, $job_name);
    $rs = mysqli_query(
        $dbc,
        'SELECT id, name AS table_name, description
         FROM jobs
         WHERE name = "' . $job_name_esc . '"
         LIMIT 1'
    );
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return null;
    }
    return mysqli_fetch_array($rs);
}

function master_sw_switchlist_sql($job_id, $table_name)
{
    $job_id = (int) $job_id;
    $table_name = preg_replace('/[^A-Za-z0-9_-]/', '', $table_name);

    return '(SELECT
                 cars.reporting_marks AS reporting_marks,
                 car_codes.code AS car_code,
                 cars.status AS status,
                 commodities.code AS consignment,
                 shipments.consignment AS consignment_id,
                 shipments.special_instructions AS special_instructions,
                 routing.station AS current_station,
                 locations.code AS current_location,
                 loading_sta.station AS loading_station,
                 loading_loc.code AS loading_location,
                 unloading_sta.station AS unloading_station,
                 unloading_loc.code AS unloading_location,
                 cars.current_location_id,
                 cars.position AS position,
                 `' . $table_name . '`.step_number
             FROM cars
             LEFT JOIN locations ON locations.id = cars.current_location_id
             LEFT JOIN routing ON routing.id = locations.station
             INNER JOIN car_orders ON car_orders.car = cars.Id
             INNER JOIN car_codes ON car_codes.id = cars.car_code_id
             INNER JOIN shipments ON shipments.id = car_orders.shipment
             INNER JOIN commodities ON commodities.id = shipments.consignment
             INNER JOIN locations loading_loc ON loading_loc.id = shipments.loading_location
             INNER JOIN routing loading_sta ON loading_sta.id = loading_loc.station
             INNER JOIN locations unloading_loc ON unloading_loc.id = shipments.unloading_location
             INNER JOIN routing unloading_sta ON unloading_sta.id = unloading_loc.station
             LEFT JOIN `' . $table_name . '` ON `' . $table_name . '`.station = routing.id
             WHERE cars.handled_by_job_id = "' . $job_id . '"
               AND (NOT INSTR(car_orders.waybill_number, "E"))
             GROUP BY cars.reporting_marks)
             UNION
             (SELECT
                 cars.reporting_marks AS reporting_marks,
                 car_codes.code AS car_code,
                 cars.status AS status,
                 "" AS consignment,
                 0 AS consignment_id,
                 "" AS special_instructions,
                 routing.station AS current_station,
                 locations.code AS current_location,
                 0 AS loading_station,
                 "" AS loading_location,
                 unloading_sta.station AS unloading_station,
                 unloading_loc.code AS unloading_location,
                 cars.current_location_id,
                 cars.position AS position,
                 `' . $table_name . '`.step_number
             FROM cars
             LEFT JOIN locations ON locations.id = cars.current_location_id
             LEFT JOIN routing ON routing.id = locations.station
             INNER JOIN car_orders ON car_orders.car = cars.Id
             INNER JOIN car_codes ON car_codes.id = cars.car_code_id
             INNER JOIN locations unloading_loc ON unloading_loc.id = car_orders.shipment
             INNER JOIN routing unloading_sta ON unloading_sta.id = unloading_loc.station
             LEFT JOIN `' . $table_name . '` ON `' . $table_name . '`.station = routing.id
             WHERE cars.handled_by_job_id = "' . $job_id . '"
               AND INSTR(car_orders.waybill_number, "E")
             GROUP BY cars.reporting_marks)
             ORDER BY position, step_number, current_station, current_location, unloading_location, reporting_marks';
}

function master_sw_fetch_car_rows($dbc, $job_id, $table_name)
{
    $sql = master_sw_switchlist_sql($job_id, $table_name);
    $rs = mysqli_query($dbc, $sql);
    if (!$rs) {
        return [];
    }
    $rows = [];
    while ($row = mysqli_fetch_array($rs)) {
        $rows[] = $row;
    }
    return $rows;
}

function master_sw_capture($dbc, $job_name, $label, array &$sections, array $options = [])
{
    $meta = master_sw_job_meta($dbc, $job_name);
    if ($meta === null) {
        return;
    }
    $cars = master_sw_fetch_car_rows($dbc, (int) $meta['id'], $meta['table_name']);
    if (count($cars) === 0) {
        return;
    }
    if (!empty($options['skip_if_same_as_last']) && count($sections) > 0) {
        $last = $sections[count($sections) - 1];
        if (master_sw_same_car_marks($last['cars'], $cars)) {
            return;
        }
    }
    master_sw_add_section($sections, $label, $cars, $options);
}

function master_sw_same_car_marks(array $left, array $right)
{
    $marks = function (array $cars) {
        $values = [];
        foreach ($cars as $row) {
            $values[] = (string) ($row['reporting_marks'] ?? '');
        }
        sort($values);
        return $values;
    };
    return $marks($left) === $marks($right);
}

function master_sw_is_phased_format($format)
{
    return in_array($format, ['phased', 'phased-mobile'], true);
}

function master_sw_normalize_switchlist_format($format, $default = 'phased')
{
    if (!function_exists('operational_steps_normalize_switchlist_format')) {
        require_once __DIR__ . '/operational_steps_catalog.php';
    }

    return operational_steps_normalize_switchlist_format($format, $default);
}

function master_sw_phase_layouts_for_format($format)
{
    return $format === 'phased-mobile' ? ['mobile'] : ['mobile', 'halfsheet'];
}

function master_sw_phase_layout_suffix($layout)
{
    return $layout === 'halfsheet' ? '_halfsheet.html' : '_mobile.html';
}

function master_sw_add_section(array &$sections, $label, array $cars, array $options = [])
{
    if (count($cars) === 0) {
        return;
    }
    $sections[] = array_merge([
        'label' => $label,
        'cars' => $cars,
    ], $options);
}

function master_sw_car_id_by_marks($dbc, $marks)
{
    $marks_esc = mysqli_real_escape_string($dbc, $marks);
    $rs = mysqli_query($dbc, 'SELECT id FROM cars WHERE reporting_marks = "' . $marks_esc . '" LIMIT 1');
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return 0;
    }
    return (int) mysqli_fetch_row($rs)[0];
}

function master_sw_car_destinations($dbc, $car_id)
{
    $car_id = (int) $car_id;
    $rs = mysqli_query(
        $dbc,
        'SELECT loading_sta.station AS loading_station,
                loading_loc.code AS loading_location,
                unloading_sta.station AS unloading_station,
                unloading_loc.code AS unloading_location
         FROM car_orders
         INNER JOIN shipments ON shipments.id = car_orders.shipment
         LEFT JOIN locations loading_loc ON loading_loc.id = shipments.loading_location
         LEFT JOIN routing loading_sta ON loading_sta.id = loading_loc.station
         LEFT JOIN locations unloading_loc ON unloading_loc.id = shipments.unloading_location
         LEFT JOIN routing unloading_sta ON unloading_sta.id = unloading_loc.station
         WHERE car_orders.car = "' . $car_id . '"
           AND car_orders.waybill_number IS NOT NULL
           AND car_orders.waybill_number != ""
         ORDER BY car_orders.waybill_number DESC
         LIMIT 1'
    );
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return null;
    }
    return mysqli_fetch_array($rs);
}

function master_sw_enrich_row_destinations($dbc, array $row)
{
    $car_id = master_sw_car_id_by_marks($dbc, $row['reporting_marks'] ?? '');
    if ($car_id <= 0) {
        return $row;
    }
    $dest = master_sw_car_destinations($dbc, $car_id);
    if (!$dest) {
        return $row;
    }
    foreach (['loading_station', 'loading_location', 'unloading_station', 'unloading_location'] as $key) {
        if (!empty($dest[$key])) {
            $row[$key] = $dest[$key];
        }
    }
    return $row;
}

function master_sw_enrich_rows_destinations($dbc, array $rows)
{
    $enriched = [];
    foreach ($rows as $row) {
        $enriched[] = master_sw_enrich_row_destinations($dbc, $row);
    }
    return $enriched;
}

function master_sw_begin_dry_run($dbc)
{
    mysqli_begin_transaction($dbc, MYSQLI_TRANS_START_READ_WRITE);
}

function master_sw_end_dry_run($dbc)
{
    mysqli_rollback($dbc);
}

function master_sw_run_job_pickup_only($dbc, $job_name, $step_nbr)
{
    $step_nbr = (int) $step_nbr;
    $eligible = array_keys(warm_start_eligible_car_ids_for_criterion($dbc, $job_name, $step_nbr));
    $assigned = warm_start_assign_cars_to_job($dbc, $job_name, $eligible);

    $pickup_station = 0;
    $table_name = preg_replace('/[^A-Za-z0-9_-]/', '', $job_name);
    $step_rs = mysqli_query(
        $dbc,
        'SELECT station FROM `' . $table_name . '` WHERE step_number = ' . $step_nbr
    );
    if ($step_rs && mysqli_num_rows($step_rs) > 0) {
        $pickup_station = (int) mysqli_fetch_array($step_rs)['station'];
    }
    $picked_up = $pickup_station > 0
        ? warm_start_pickup_job_at_station($dbc, $job_name, $pickup_station)
        : warm_start_pickup_job($dbc, $job_name);

    return compact('assigned', 'picked_up');
}

function master_sw_run_job_setout_only($dbc, $job_name, $step_nbr)
{
    $step_nbr = (int) $step_nbr;
    $job_name_esc = mysqli_real_escape_string($dbc, $job_name);

    $dest_stations = [];
    $crit_rs = mysqli_query(
        $dbc,
        'SELECT dest_station_id FROM pu_criteria
         WHERE job_id = "' . $job_name_esc . '" AND step_nbr = ' . $step_nbr
    );
    while ($crit_rs && ($crit = mysqli_fetch_array($crit_rs))) {
        $dest_stations[] = (int) $crit['dest_station_id'];
    }

    $table_name = preg_replace('/[^A-Za-z0-9_-]/', '', $job_name);
    $step_rs = mysqli_query(
        $dbc,
        'SELECT setout FROM `' . $table_name . '` WHERE step_number = ' . $step_nbr
    );
    $has_setout = $step_rs && mysqli_fetch_array($step_rs)['setout'] === 'T';

    if (!$has_setout) {
        return 0;
    }
    if (count($dest_stations) > 0) {
        return warm_start_setout_job_cars_for_destinations($dbc, $job_name, $dest_stations);
    }
    return warm_start_setout_all_job_train($dbc, $job_name);
}

function master_sw_replay_recipe_for_job($dbc, $job_name, array $recipe, $through_step, array &$sections, array $config = [])
{
    require_once __DIR__ . '/operational_steps_catalog.php';
    $steps = $recipe['steps'] ?? [];
    $through_step = max(0, (int) $through_step);
    if ($through_step < 1 || count($steps) === 0) {
        master_sw_capture($dbc, $job_name, '1 — Current assignment', $sections);
        return;
    }

    $pc = 0;
    $iterations = 0;
    $max_iterations = max(500, $through_step * 20);

    while ($pc < $through_step && $iterations++ < $max_iterations) {
        $step = $steps[$pc] ?? null;
        if (!is_array($step)) {
            $pc++;
            continue;
        }
        $n = $pc + 1;
        $fid = $step['function'] ?? '';

        if ($fid === 'goto') {
            $target = operational_steps_goto_resolve_step($recipe, $step['params'] ?? []);
            if ($target > 0 && $target <= $through_step && $target > $n) {
                $pc = $target - 1;
            } else {
                $pc++;
            }
            continue;
        }
        if ($fid === 'if_then') {
            $pc += 2;
            continue;
        }
        if (in_array($fid, ['section_label', 'text_instruction', 'marker', 'stop'], true)) {
            $pc++;
            continue;
        }
        if ($fid === 'generate_switchlists') {
            break;
        }
        if ($fid === 'build_switchlists_sts') {
            $step_job = trim($step['params']['job'] ?? '');
            operational_steps_dispatch_step($dbc, $step, $config);
            if ($step_job !== '' && strcasecmp($step_job, $job_name) === 0) {
                $compiled = operational_steps_compile_recipe(['steps' => [$step]]);
                $label = $compiled[0]['instruction'] ?? ('Phase ' . (count($sections) + 1));
                master_sw_capture($dbc, $job_name, $label, $sections);
            }
            $pc++;
            continue;
        }

        operational_steps_dispatch_step($dbc, $step, $config);
        $pc++;
    }

    if (count($sections) === 0) {
        master_sw_capture($dbc, $job_name, '1 — Current assignment', $sections);
    }
}

function master_sw_build_sections($dbc, $job_name, array $config = [], array $options = [])
{
    $sections = [];
    master_sw_begin_dry_run($dbc);

    $recipe = $options['recipe'] ?? null;
    $through_step = (int) ($options['through_step'] ?? 0);
    if (is_array($recipe) && $through_step > 0) {
        master_sw_replay_recipe_for_job($dbc, $job_name, $recipe, $through_step, $sections, $config);
    } else {
        master_sw_capture($dbc, $job_name, '1 — Current assignment', $sections);
    }

    master_sw_end_dry_run($dbc);
    return $sections;
}

function master_sw_get_setting($dbc, $name)
{
    $name_esc = mysqli_real_escape_string($dbc, $name);
    $rs = mysqli_query($dbc, 'SELECT setting_value FROM settings WHERE setting_name = "' . $name_esc . '" LIMIT 1');
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return '';
    }
    return (string) mysqli_fetch_row($rs)[0];
}

function master_sw_row_destination_style($dbc, $row)
{
    if (!function_exists('set_colors')) {
        return '';
    }
    $location = '';
    if (($row['status'] === 'Empty') || ($row['status'] === 'Ordered')) {
        $location = ((int) $row['consignment_id'] <= 0)
            ? ($row['unloading_location'] ?? '')
            : ($row['loading_location'] ?? '');
    } elseif ($row['status'] === 'Loaded') {
        $location = $row['unloading_location'] ?? '';
    }
    if ($location === '') {
        return '';
    }
    return set_colors($dbc, $location);
}

function master_sw_sections_cache_path($output_dir, $job_name, $session_nbr)
{
    return rtrim($output_dir, '/') . '/' . $job_name . '_session_' . $session_nbr . '_master.json';
}

function master_sw_output_path($output_dir, $job_name, $session_nbr, $format)
{
    $suffix = $format === 'mobile' ? '_master_mobile.html' : '_master.html';
    return rtrim($output_dir, '/') . '/' . $job_name . '_session_' . $session_nbr . $suffix;
}

function master_sw_save_sections_cache($output_dir, $job_name, $session_nbr, array $sections)
{
    $path = master_sw_sections_cache_path($output_dir, $job_name, $session_nbr);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $payload = [
        'job' => $job_name,
        'session' => (string) $session_nbr,
        'sections' => $sections,
    ];
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $path;
}

function master_sw_load_sections_cache($output_dir, $job_name, $session_nbr)
{
    $path = master_sw_sections_cache_path($output_dir, $job_name, $session_nbr);
    if (!is_readable($path)) {
        return null;
    }
    $payload = json_decode(file_get_contents($path), true);
    if (!is_array($payload) || empty($payload['sections'])) {
        return null;
    }
    return $payload['sections'];
}

function master_sw_parse_location_cell($html)
{
    $html = trim($html);
    if ($html === 'In Train') {
        return ['station' => 'In Train', 'location' => ''];
    }
    $text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
    $parts = preg_split("/\r\n|\n|\r/", $text);
    $station = trim($parts[0] ?? '');
    $location = trim($parts[1] ?? '');
    return ['station' => $station, 'location' => $location];
}

function master_sw_parse_halfsheet_html($html_path)
{
    if (!is_readable($html_path)) {
        return null;
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(file_get_contents($html_path));
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $phase_rows = $xpath->query("//tr[contains(@class, 'phase-row')]");
    if ($phase_rows === false || $phase_rows->length === 0) {
        return null;
    }

    $sections = [];
    for ($i = 0; $i < $phase_rows->length; $i++) {
        $phase_row = $phase_rows->item($i);
        $label = trim($phase_row->textContent);
        $cars = [];
        $node = $phase_row->nextSibling;
        while ($node !== null) {
            if ($node->nodeType !== XML_ELEMENT_NODE || strtolower($node->nodeName) !== 'tr') {
                $node = $node->nextSibling;
                continue;
            }
            if (strpos($node->getAttribute('class') ?? '', 'phase-row') !== false) {
                break;
            }
            $cells = $node->getElementsByTagName('td');
            if ($cells->length < 7) {
                $node = $node->nextSibling;
                continue;
            }

            $marks = trim($cells->item(0)->textContent);
            if ($marks === '') {
                $node = $node->nextSibling;
                continue;
            }

            $el = trim($cells->item(2)->textContent);
            $status = $el === 'L' ? 'Loaded' : ($el === 'E' ? 'Empty' : 'Ordered');
            $contents = trim(str_replace('Spec Instr', '', $cells->item(3)->textContent));
            $from = master_sw_parse_location_cell($dom->saveHTML($cells->item(4)));
            $to = master_sw_parse_location_cell($dom->saveHTML($cells->item(5)));
            $picked = trim($cells->item(6)->textContent) !== '';
            $special = strpos($cells->item(3)->textContent, 'Spec Instr') !== false ? 'See special instructions' : '';

            $cars[] = [
                'reporting_marks' => $marks,
                'car_code' => trim($cells->item(1)->textContent),
                'status' => $status,
                'consignment' => $contents,
                'consignment_id' => $contents === '' ? 0 : 1,
                'special_instructions' => $special,
                'current_station' => $from['station'],
                'current_location' => $from['location'],
                'current_location_id' => ($from['station'] === 'In Train' || $picked) ? 0 : 1,
                'loading_station' => $status !== 'Loaded' ? $to['station'] : '',
                'loading_location' => $status !== 'Loaded' ? $to['location'] : '',
                'unloading_station' => $to['station'],
                'unloading_location' => $to['location'],
                'position' => count($cars),
                'step_number' => null,
            ];
            $node = $node->nextSibling;
        }

        if (count($cars) > 0) {
            $sections[] = ['label' => $label, 'cars' => $cars];
        }
    }

    return count($sections) > 0 ? $sections : null;
}

function master_sw_backfill_cache_from_halfsheet($output_dir, $job_name, $session_nbr)
{
    $html_path = master_sw_output_path($output_dir, $job_name, $session_nbr, 'halfsheet');
    $sections = master_sw_parse_halfsheet_html($html_path);
    if ($sections === null) {
        return null;
    }
    master_sw_save_sections_cache($output_dir, $job_name, $session_nbr, $sections);
    return $sections;
}

function master_sw_print_chunks($incoming_string, $page_width)
{
    $string_array = explode('<br />', nl2br($incoming_string));
    for ($i = 0; $i < count($string_array); $i++) {
        if (strlen($string_array[$i]) <= $page_width) {
            print $string_array[$i] . '<br />';
            continue;
        }
        $line_string_array = explode(' ', $string_array[$i]);
        $col_counter = 0;
        for ($j = 0; $j < count($line_string_array); $j++) {
            $end_of_word = $col_counter + strlen($line_string_array[$j]);
            if ($end_of_word > $page_width) {
                $end_of_word = 0;
                print '<br />';
            }
            print $line_string_array[$j] . ' ';
            $col_counter = $end_of_word + strlen($line_string_array[$j]);
        }
    }
}

function master_sw_mobile_destination_station($dbc, $row)
{
    if (($row['status'] === 'Empty') || ($row['status'] === 'Ordered')) {
        if ((int) $row['consignment_id'] <= 0) {
            return [$row['unloading_station'] ?? '', $row['unloading_location'] ?? ''];
        }
        return [$row['loading_station'] ?? '', $row['loading_location'] ?? ''];
    }
    if ($row['status'] === 'Loaded') {
        return [$row['unloading_station'] ?? '', $row['unloading_location'] ?? ''];
    }
    return ['', ''];
}

function master_sw_job_output_dir($output_dir, $job_name)
{
    return rtrim($output_dir, '/') . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $job_name);
}

function master_sw_phase_output_path($job_dir, $phase_index, $layout = 'mobile')
{
    $suffix = master_sw_phase_layout_suffix($layout);
    return rtrim($job_dir, '/') . '/phase_' . str_pad((string) (int) $phase_index, 2, '0', STR_PAD_LEFT) . $suffix;
}

function master_sw_job_index_path($job_dir)
{
    return rtrim($job_dir, '/') . '/index.html';
}

function master_sw_print_all_path($job_dir)
{
    return rtrim($job_dir, '/') . '/print_all.html';
}

function master_sw_session_index_path($output_dir)
{
    return rtrim($output_dir, '/') . '/index.html';
}

function master_sw_session_print_all_path($output_dir)
{
    return rtrim($output_dir, '/') . '/print_all.html';
}

function master_sw_write_html_file($output_path, $html)
{
    $dir = dirname($output_path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (file_put_contents($output_path, $html) === false) {
        throw new RuntimeException('Failed to write ' . $output_path);
    }
    return $output_path;
}

function master_sw_render_mobile_car_block($dbc, array $section, $page_width, &$loads, &$empties, array &$special_instructions)
{
    $phase_line = '--- ' . $section['label'] . ' ---';
    echo str_pad($phase_line, $page_width, '-', STR_PAD_BOTH) . '<br /><br />';

    foreach ($section['cars'] as $row) {
        $is_empty = ($row['status'] === 'Empty') || ($row['status'] === 'Ordered');
        if ($is_empty) {
            $empties++;
            $el = 'E';
        } elseif ($row['status'] === 'Loaded') {
            $loads++;
            $el = 'L';
        } else {
            $el = ' ';
        }

        echo str_pad(substr($row['reporting_marks'], 0, 11), 11) . ' ';
        echo str_pad(substr($row['car_code'], 0, 4), 4) . ' ';
        echo ' ' . $el . '  ';

        if ($row['status'] === 'Loaded') {
            echo str_pad(substr($row['consignment'], 0, 13), 13) . ' ';
        } else {
            echo str_repeat(' ', 13) . ' ';
        }

        if ((int) $row['current_location_id'] > 0) {
            echo str_pad(substr($row['current_station'], 0, 14), 14) . ' ';
        } else {
            echo str_pad('In Train', 14) . ' ';
        }

        [$dest_station, $dest_location, $dest_style] = master_sw_section_destination($dbc, $row, $section);
        $left_at = master_sw_section_left_at($section);
        if ($dest_style !== '' && $dest_location !== '') {
            echo '<span style="' . $dest_style . '">'
                . str_pad(substr($dest_station, 0, 14), 14) . '</span> ';
        } else {
            echo str_pad(substr($dest_station, 0, 14), 14) . ' ';
        }
        echo '<br />';

        echo str_repeat(' ', 20) . ' ';
        if (strlen($row['special_instructions'] ?? '') > 0) {
            echo 'See Spec Inst ';
            $special_instructions[] = [
                $row['reporting_marks'],
                $row['consignment'],
                $row['special_instructions'],
            ];
        } else {
            echo str_repeat(' ', 13) . ' ';
        }

        echo str_pad(substr($row['current_location'], 0, 14), 14) . ' ';
        echo str_pad(substr($dest_location, 0, 14), 14) . ' ';
        $pickup_mark = master_sw_section_pickup_mark($row, $section);
        echo $pickup_mark !== '' ? str_pad($pickup_mark, 4) . ' ' : '____ ';
        $left_at = master_sw_section_left_at($section);
        echo $left_at !== '' ? str_pad(substr($left_at, 0, 4), 4) : '____';
        echo ' <br /><br />';
    }
}

function master_sw_waybills_href_for_job_dir($job_dir)
{
    $waybill_index = dirname(rtrim($job_dir, '/')) . '/waybills/index.html';
    return is_file($waybill_index) ? '../waybills/index.html' : '';
}

function master_sw_waybills_card_html($href, $title = 'Waybills')
{
    if ($href === '') {
        return '';
    }
    return '<div class="card">
      <h2>' . htmlspecialchars($title) . '</h2>
      <p>View or print freight waybills generated for this session phase.</p>
      <a class="button" href="' . htmlspecialchars($href) . '">Open waybills</a>
      <p style="margin-top:10px;font-size:14px;"><a href="' . htmlspecialchars(dirname($href) . '/print_all.html') . '">Print all waybills</a></p>
    </div>';
}

function master_sw_render_head_assets()
{
    if (!function_exists('session_static_head_assets')) {
        require_once __DIR__ . '/session_helpers.php';
    }

    return session_static_head_assets();
}

function master_sw_render_nav_styles()
{
    return '';
}

function master_sw_nav_links_from_legacy(array $nav, $session_nbr)
{
    $links = [
        ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
    ];
    if (!empty($nav['session_href'])) {
        $links[] = [
            'href' => $nav['session_href'],
            'label' => $nav['session_label'] ?? ('Session ' . $session_nbr),
            'icon' => 'calendar-event',
        ];
    }
    if (!empty($nav['sessions_href'])) {
        $links[] = [
            'href' => $nav['sessions_href'],
            'label' => $nav['sessions_label'] ?? 'All Sessions',
            'icon' => 'collection',
        ];
    }

    return $links;
}

function master_sw_nav_for_job_dir($job_dir, $session_nbr)
{
    $normalized = str_replace('\\', '/', $job_dir);
    if (strpos($normalized, '/phase_') !== false) {
        return [
            'session_href' => '../../../index.php',
            'session_label' => 'Session ' . $session_nbr,
            'sessions_href' => '../../../../session.php',
            'sessions_label' => 'All Sessions',
        ];
    }
    return [
        'session_href' => '../index.html',
        'session_label' => 'Session ' . $session_nbr,
        'sessions_href' => '../../index.html',
        'sessions_label' => 'All Sessions',
    ];
}

function master_sw_nav_for_session_dir($output_dir, $session_nbr)
{
    $normalized = str_replace('\\', '/', rtrim($output_dir, '/'));
    if (preg_match('#/sts/session_\d+$#', $normalized)) {
        return [
            'sessions_href' => '../session.php',
            'sessions_label' => 'All Sessions',
        ];
    }
    return [
        'sessions_href' => '../index.html',
        'sessions_label' => 'All Sessions',
    ];
}

function master_sw_render_nav_bar(array $nav, $trail = '')
{
    if (!function_exists('session_nav_bar_html')) {
        require_once __DIR__ . '/session_helpers.php';
    }
    $session_nbr = preg_replace('/\D/', '', (string) ($nav['session_label'] ?? ''));
    echo session_nav_bar_html(master_sw_nav_links_from_legacy($nav, $session_nbr), $trail);
}

function master_sw_render_empty_job_index($dbc, $job_name, $job_dir, $session_nbr, $message)
{
    $meta = ($dbc !== null) ? master_sw_job_meta($dbc, $job_name) : null;
    $table_name = $meta['table_name'] ?? $job_name;
    $nav = master_sw_nav_for_job_dir($job_dir, $session_nbr);
    ob_start();
    echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . htmlspecialchars($table_name) . ' — Session ' . htmlspecialchars($session_nbr) . '</title>
  ' . master_sw_render_head_assets() . '
</head>
<body>';
    master_sw_render_nav_bar($nav, 'Train ' . $table_name);
    echo '<div class="page">
    <h1>Train ' . htmlspecialchars($table_name) . '</h1>
    <p class="subtitle">Session ' . htmlspecialchars($session_nbr) . '</p>
    <div class="card">
      <h2>Switch lists</h2>
      <p>' . htmlspecialchars($message) . '</p>
    </div>
  </div>
</body>
</html>';
    return master_sw_write_html_file(master_sw_job_index_path($job_dir), ob_get_clean());
}

function master_sw_render_empty_session_index($dbc, $output_dir, $session_nbr, $message)
{
    $rr_name = ($dbc !== null) ? (master_sw_get_setting($dbc, 'railroad_name') ?: 'HART Railroad') : 'HART Railroad';
    $nav = master_sw_nav_for_session_dir($output_dir, $session_nbr);
    $waybills_href = is_file(rtrim($output_dir, '/') . '/waybills/index.html') ? 'waybills/index.html' : '';
    ob_start();
    echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Session ' . htmlspecialchars($session_nbr) . ' Switchlists</title>
  ' . master_sw_render_head_assets() . '
</head>
<body>';
    master_sw_render_nav_bar($nav, 'Session ' . $session_nbr);
    echo '<div class="page" style="padding-top:16px;">
    <h1>' . htmlspecialchars($rr_name) . '</h1>
    <p class="subtitle">Session ' . htmlspecialchars($session_nbr) . ' — engineer switch list index</p>
    <div class="card"><p>' . htmlspecialchars($message) . '</p></div>';
    if ($waybills_href !== '') {
        echo master_sw_waybills_card_html($waybills_href, 'Waybills');
    }
    echo '</div>
</body>
</html>';
    return master_sw_write_html_file(master_sw_session_index_path($output_dir), ob_get_clean());
}

function master_sw_render_format_toggle_script()
{
    return '';
}

function master_sw_build_phase_list_html(array $sections, $layout)
{
    $items = '';
    foreach ($sections as $index => $section) {
        $phase_num = $index + 1;
        $phase_path = 'phase_' . str_pad((string) $phase_num, 2, '0', STR_PAD_LEFT) . master_sw_phase_layout_suffix($layout);
        $label = htmlspecialchars($section['label']);
        $car_count = count($section['cars']);
        $items .= '<li><a href="' . $phase_path . '">' . $label
            . '<span class="meta">' . $car_count . ' car' . ($car_count === 1 ? '' : 's') . '</span></a></li>';
    }
    return $items;
}

function master_sw_build_phase_nav($job_dir, $phase_index, $phase_total, $layout)
{
    $nav = [
        'job_index' => 'index.html',
        'session_index' => '../index.html',
        'sessions_index' => '../../index.html',
        'layout' => $layout,
        'phase_index' => $phase_index,
        'phase_total' => $phase_total,
    ];
    $waybills_href = master_sw_waybills_href_for_job_dir($job_dir);
    if ($waybills_href !== '') {
        $nav['waybills_href'] = $waybills_href;
    }
    if ($phase_index > 1) {
        $nav['prev'] = basename(master_sw_phase_output_path($job_dir, $phase_index - 1, $layout));
    }
    if ($phase_index < $phase_total) {
        $nav['next'] = basename(master_sw_phase_output_path($job_dir, $phase_index + 1, $layout));
    }
    $nav['mobile_href'] = basename(master_sw_phase_output_path($job_dir, $phase_index, 'mobile'));
    $nav['halfsheet_href'] = basename(master_sw_phase_output_path($job_dir, $phase_index, 'halfsheet'));
    return $nav;
}

function master_sw_render_phase_nav_bar($table_name, $session_nbr, array $nav)
{
    if (!function_exists('session_nav_bar_html')) {
        require_once __DIR__ . '/session_helpers.php';
    }
    $layout = $nav['layout'] ?? 'mobile';
    $phase_index = (int) ($nav['phase_index'] ?? 0);
    $phase_total = (int) ($nav['phase_total'] ?? 0);
    $links = [
        ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
    ];
    if (!empty($nav['prev'])) {
        $links[] = ['href' => $nav['prev'], 'label' => 'Prev', 'icon' => 'chevron-left'];
    }
    if (!empty($nav['job_index'])) {
        $links[] = ['href' => $nav['job_index'], 'label' => $table_name . ' Index', 'icon' => 'list-ul'];
    }
    if (!empty($nav['session_index'])) {
        $links[] = ['href' => $nav['session_index'], 'label' => 'Session ' . $session_nbr, 'icon' => 'calendar-event'];
    }
    if (!empty($nav['sessions_index'])) {
        $links[] = ['href' => $nav['sessions_index'], 'label' => 'All Sessions', 'icon' => 'collection'];
    }
    if (!empty($nav['waybills_href'])) {
        $links[] = ['href' => $nav['waybills_href'], 'label' => 'Waybills', 'icon' => 'file-text'];
    }
    $links[] = [
        'href' => $nav['mobile_href'] ?? '',
        'label' => 'Mobile',
        'icon' => 'phone',
        'active' => $layout === 'mobile',
    ];
    $links[] = [
        'href' => $nav['halfsheet_href'] ?? '',
        'label' => 'Half sheet',
        'icon' => 'file-earmark',
        'active' => $layout === 'halfsheet',
    ];
    if (!empty($nav['next'])) {
        $links[] = ['href' => $nav['next'], 'label' => 'Next', 'icon' => 'chevron-right'];
    }
    $trail = $phase_index > 0 ? ('Phase ' . $phase_index . ' / ' . $phase_total) : '';
    echo session_nav_bar_html($links, $trail);
}

function master_sw_render_job_index($dbc, $job_name, array $sections, $job_dir, $session_nbr)
{
    if (!function_exists('session_nav_bar_html')) {
        require_once __DIR__ . '/session_helpers.php';
    }
    $meta = master_sw_job_meta($dbc, $job_name);
    if ($meta === null) {
        throw new RuntimeException('Unknown job: ' . $job_name);
    }

    $table_name = $meta['table_name'];
    $job_desc = nl2br(htmlspecialchars($meta['description']));
    $phase_items = master_sw_build_phase_list_html($sections, 'mobile');
    $waybills_href = master_sw_waybills_href_for_job_dir($job_dir);

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . htmlspecialchars($table_name) . ' — Session ' . htmlspecialchars($session_nbr) . '</title>
  ' . master_sw_render_head_assets() . '
</head>
<body>
  ' . session_nav_bar_html([
        ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
        ['href' => '../../../index.php', 'label' => 'Session ' . $session_nbr, 'icon' => 'calendar-event'],
        ['href' => '../../../../session.php', 'label' => 'All Sessions', 'icon' => 'collection'],
    ], 'Train ' . $table_name) . '
  <div class="page">
    <h1>Train ' . htmlspecialchars($table_name) . '</h1>
    <p class="subtitle">Session ' . htmlspecialchars($session_nbr) . ' — ' . count($sections) . ' work phases</p>
    <div class="card">
      <h2>Crew instructions</h2>
      <p>' . $job_desc . '</p>
    </div>
    <div class="card">
      <h2>Switch lists</h2>
      <p>Open each leg in order. Choose mobile or half sheet on the switch list page. Mark pickups and setouts as you work.</p>
      <ul class="phase-list">' . $phase_items . '</ul>
    </div>
    ' . master_sw_waybills_card_html($waybills_href) . '
  </div>
</body>
</html>';

    return master_sw_write_html_file(master_sw_job_index_path($job_dir), $html);
}

function master_sw_render_session_index($dbc, array $job_summaries, $output_dir, $session_nbr)
{
    if (!function_exists('session_nav_bar_html')) {
        require_once __DIR__ . '/session_helpers.php';
    }
    $rr_name = master_sw_get_setting($dbc, 'railroad_name') ?: 'HART Railroad';
    $cards = '';
    $total_phases = 0;
    $total_cars = 0;
    foreach ($job_summaries as $summary) {
        $job_name = htmlspecialchars($summary['job']);
        $phases = (int) $summary['phases'];
        $cars = (int) $summary['cars'];
        $total_phases += $phases;
        $total_cars += $cars;
        $desc = htmlspecialchars($summary['description'] ?? '');
        if (strlen($desc) > 180) {
            $desc = substr($desc, 0, 177) . '...';
        }
        if ($phases === 0) {
            $cards .= '<div class="card">
      <h2>Train ' . $job_name . '</h2>
      <p>' . $desc . '</p>
      <p>No switch lists generated for this train.</p>
      <a class="button" href="' . $job_name . '/index.html">View ' . $job_name . ' page</a>
    </div>';
            continue;
        }
        $cards .= '<div class="card">
      <h2>Train ' . $job_name . '</h2>
      <p>' . $desc . '</p>
      <p>' . $phases . ' phases, ' . $cars . ' car rows total</p>
      <a class="button" href="' . $job_name . '/index.html">Open ' . $job_name . ' switch lists</a>
    </div>';
    }

    if ($total_cars > 0) {
        $cards .= '<div class="card">
      <h2>Print all switch lists</h2>
      <p>One printable document for every train and phase in this session. Each phase starts on a new printed page.</p>
      <p>' . count($job_summaries) . ' trains, ' . $total_phases . ' phases, ' . $total_cars . ' car rows total</p>
      <a class="button" href="print_all.html">Open print-all switch lists</a>
    </div>';
    }

    $waybills_href = is_file(rtrim($output_dir, '/') . '/waybills/index.html') ? 'waybills/index.html' : '';
    if ($waybills_href !== '') {
        $cards .= master_sw_waybills_card_html($waybills_href, 'Waybills');
    }

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Session ' . htmlspecialchars($session_nbr) . ' Switchlists</title>
  ' . master_sw_render_head_assets() . '
</head>
<body>
  ' . session_nav_bar_html([
        ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
        ['href' => '../session.php', 'label' => 'All Sessions', 'icon' => 'collection'],
    ], 'Session ' . $session_nbr) . '
  <div class="page" style="padding-top:16px;">
    <h1>' . htmlspecialchars($rr_name) . '</h1>
    <p class="subtitle">Session ' . htmlspecialchars($session_nbr) . ' — engineer switch list index</p>
    ' . $cards . '
  </div>
</body>
</html>';

    return master_sw_write_html_file(master_sw_session_index_path($output_dir), $html);
}

function master_sw_discover_session_dirs($output_root, $max_session = null)
{
    $sessions = [];
    if (!is_dir($output_root)) {
        return $sessions;
    }
    foreach (scandir($output_root) ?: [] as $entry) {
        if (!preg_match('/^session_(\d+)$/', $entry, $matches)) {
            continue;
        }
        $nbr = (int) $matches[1];
        if ($max_session !== null && $nbr > (int) $max_session) {
            continue;
        }
        $path = rtrim($output_root, '/') . '/' . $entry;
        if (!is_dir($path) || !is_file($path . '/index.html')) {
            continue;
        }
        $sessions[] = [
            'number' => $nbr,
            'dir' => $entry,
            'path' => $path,
        ];
    }
    usort($sessions, function ($a, $b) {
        return $b['number'] <=> $a['number'];
    });
    return $sessions;
}

function master_sw_render_switchlists_root_index($output_root, $max_session = null)
{
    $sessions = master_sw_discover_session_dirs($output_root, $max_session);
    $cards = '';
    foreach ($sessions as $session) {
        $nbr = (int) $session['number'];
        $cards .= '<div class="card">
      <h2>Session ' . $nbr . '</h2>
      <p>Phased switch lists generated from the workflow recipe.</p>
      <a class="button" href="session_' . $nbr . '/index.html">Open session ' . $nbr . ' switch lists</a>
    </div>';
    }
    if ($cards === '') {
        $cards = '<div class="card"><p>No session switch lists found yet. Run <strong>Generate Switch Lists</strong> from the workflow editor after operating steps complete.</p></div>';
    }

    $tools_card = '<div class="card">
      <h2>Workflow Builder</h2>
      <p>Define STS operational steps from functions and parameters. Compile, save, and run the switch list generator.</p>
      <a class="button" href="operational_steps_editor.html">Edit workflow &amp; run generator</a>
    </div>';

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>HART Switchlists</title>
  ' . master_sw_render_head_assets() . '
</head>
<body>
  <div class="page" style="padding-top:24px;">
    <h1>HART Switchlists</h1>
    <p class="subtitle">Select an operating session or edit workflow steps</p>
    ' . $tools_card . $cards . '
  </div>
</body>
</html>';

    return master_sw_write_html_file(rtrim($output_root, '/') . '/index.html', $html);
}

function master_sw_generate_phased($dbc, $job_name, array $sections, $output_dir, $session_nbr, array $options = [])
{
    $job_dir = master_sw_job_output_dir($output_dir, $job_name);
    $phase_total = count($sections);
    $written_paths = [];
    $format = master_sw_normalize_switchlist_format($options['format'] ?? 'phased');
    $layouts = master_sw_phase_layouts_for_format($format);

    for ($phase_index = 1; $phase_index <= $phase_total; $phase_index++) {
        $section = [$sections[$phase_index - 1]];
        foreach ($layouts as $layout) {
            $nav = master_sw_build_phase_nav($job_dir, $phase_index, $phase_total, $layout);
            $path = master_sw_phase_output_path($job_dir, $phase_index, $layout);
            if ($layout === 'mobile') {
                master_sw_render_mobile(
                    $dbc,
                    $job_name,
                    $section,
                    $path,
                    [
                        'phase_index' => $phase_index,
                        'phase_total' => $phase_total,
                        'nav' => $nav,
                    ]
                );
            } else {
                master_sw_render_halfsheet(
                    $dbc,
                    $job_name,
                    $section,
                    $path,
                    [
                        'phase_index' => $phase_index,
                        'phase_total' => $phase_total,
                        'nav' => $nav,
                    ]
                );
            }
            $written_paths[] = $path;
        }
    }

    master_sw_render_job_index($dbc, $job_name, $sections, $job_dir, $session_nbr);

    return [
        'job_dir' => $job_dir,
        'phase_paths' => $written_paths,
        'index_path' => master_sw_job_index_path($job_dir),
    ];
}

function master_sw_render_print_all_phase_body($dbc, array $section, $phase_index, $phase_total, $table_name)
{
    $loads = 0;
    $empties = 0;
    $special_instructions = [];
    $car_rows = '';

    foreach ($section['cars'] as $row) {
        $is_empty = ($row['status'] === 'Empty') || ($row['status'] === 'Ordered');
        if ($is_empty) {
            $empties++;
            $el = 'E';
        } elseif ($row['status'] === 'Loaded') {
            $loads++;
            $el = 'L';
        } else {
            $el = '';
        }

        [$dest_station, $dest_location, $dest_style] = master_sw_section_destination($dbc, $row, $section);
        $left_at = master_sw_section_left_at($section);

        $contents = '';
        if ($row['status'] === 'Loaded') {
            $contents .= htmlspecialchars($row['consignment']);
        }
        if (strlen($row['special_instructions'] ?? '') > 0) {
            $special_instructions[] = [
                $row['reporting_marks'],
                $row['consignment'],
                $row['special_instructions'],
            ];
            $contents .= '<br>Spec Instr';
        }

        $from = ((int) $row['current_location_id'] > 0)
            ? '<u>' . htmlspecialchars($row['current_station']) . '</u><br>' . htmlspecialchars($row['current_location'])
            : 'In Train';

        $to = '';
        if ($dest_station !== '') {
            $to = '<u>' . htmlspecialchars($dest_station) . '</u><br>' . htmlspecialchars($dest_location);
        }

        $car_rows .= '<tr>
    <td>' . htmlspecialchars($row['reporting_marks']) . '</td>
    <td style="text-align: center">' . htmlspecialchars(substr($row['car_code'], 0, 4)) . '</td>
    <td style="text-align: center">' . htmlspecialchars($el) . '</td>
    <td>' . $contents . '</td>
    <td>' . $from . '</td>
    <td' . ($dest_style !== '' ? ' style="' . $dest_style . '"' : '') . '>' . $to . '</td>
    <td style="text-align: center">' . (master_sw_section_pickup_mark($row, $section) !== '' ? '<b>' . master_sw_section_pickup_mark($row, $section) . '</b>' : '') . '</td>
    <td style="text-align: center">' . ($left_at !== '' ? '<b>' . htmlspecialchars($left_at) . '</b>' : '') . '</td>
  </tr>';
    }

    $total = $loads + $empties;
    $special = '';
    if (count($special_instructions) > 0) {
        $special .= '<h3>Special Instructions</h3>';
        foreach ($special_instructions as $si) {
            $special .= '<p style="font-size: 10px;">'
                . htmlspecialchars($si[0]) . ' (' . htmlspecialchars($si[1]) . ') '
                . htmlspecialchars($si[2]) . '</p>';
        }
    }

    return '<section class="print-all-phase">
  <h2>' . htmlspecialchars($table_name) . ' — Phase ' . (int) $phase_index . ' of ' . (int) $phase_total . '</h2>
  <table>
    <tr>
      <th style="width: 60px;">Rptg<br>Marks</th>
      <th style="width: 22px; text-align: center;">Car<br>Code</th>
      <th style="width: 15px; text-align: center;">E/L</th>
      <th>Contents</th>
      <th>From</th>
      <th>To</th>
      <th style="width: 30px">Picked<br>Up</th>
      <th style="width: 35px">Left<br>At</th>
    </tr>
    <tr class="phase-row"><td colspan="8">' . htmlspecialchars($section['label']) . '</td></tr>
    ' . $car_rows . '
  </table>
  <br>
  Loads: ' . (int) $loads . '<br>
  Empties: ' . (int) $empties . '<br>
  Total cars: ' . (int) $total . '
  ' . $special . '
</section>';
}

function master_sw_render_print_all($dbc, $job_name, array $sections, $job_dir, $session_nbr)
{
    if (!function_exists('session_nav_bar_html')) {
        require_once __DIR__ . '/session_helpers.php';
    }
    $meta = master_sw_job_meta($dbc, $job_name);
    if ($meta === null) {
        throw new RuntimeException('Unknown job: ' . $job_name);
    }

    $table_name = $meta['table_name'];
    $phase_total = count($sections);
    $phases_html = '';
    for ($i = 0; $i < $phase_total; $i++) {
        $phases_html .= master_sw_render_print_all_phase_body(
            $dbc,
            $sections[$i],
            $i + 1,
            $phase_total,
            $table_name
        );
    }

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . htmlspecialchars($table_name) . ' Print All — Session ' . htmlspecialchars($session_nbr) . '</title>
  ' . master_sw_render_head_assets() . '
</head>
<body>
  ' . session_nav_bar_html([
        ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
        ['href' => 'index.html', 'label' => $table_name . ' Index', 'icon' => 'list-ul'],
        ['href' => '../../../index.php', 'label' => 'Session ' . $session_nbr, 'icon' => 'calendar-event'],
        ['href' => '../../../../session.php', 'label' => 'All Sessions', 'icon' => 'collection'],
    ], 'Print all · ' . (int) $phase_total . ' phases') . '
  <div class="page">
    <div class="noprint" style="margin-bottom:12px;">
      <button onclick="window.print()">PRINT ALL PHASES</button>
      <p style="margin:8px 0 0; color:#555; font-size:14px;">Each phase starts on a new printed page.</p>
    </div>
    ' . $phases_html . '
  </div>
</body>
</html>';

    return master_sw_write_html_file(master_sw_print_all_path($job_dir), $html);
}

function master_sw_render_session_print_all($dbc, array $job_sections, $output_dir, $session_nbr)
{
    if (!function_exists('session_nav_bar_html')) {
        require_once __DIR__ . '/session_helpers.php';
    }
    $phases_html = '';
    $phase_count = 0;
    foreach ($job_sections as $job_name => $sections) {
        $meta = master_sw_job_meta($dbc, $job_name);
        if ($meta === null) {
            continue;
        }
        $table_name = $meta['table_name'];
        $phase_total = count($sections);
        for ($i = 0; $i < $phase_total; $i++) {
            $phases_html .= master_sw_render_print_all_phase_body(
                $dbc,
                $sections[$i],
                $i + 1,
                $phase_total,
                $table_name
            );
            $phase_count++;
        }
    }

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Session ' . htmlspecialchars($session_nbr) . ' Print All Switchlists</title>
  ' . master_sw_render_head_assets() . '
</head>
<body>
  ' . session_nav_bar_html([
        ['href' => '/sts/index.html', 'label' => 'STS Main Menu', 'icon' => 'house'],
        ['href' => 'index.html', 'label' => 'Session ' . $session_nbr . ' Index', 'icon' => 'list-ul'],
        ['href' => '../session.php', 'label' => 'All Sessions', 'icon' => 'collection'],
    ], 'Print all · ' . count($job_sections) . ' trains · ' . (int) $phase_count . ' phases') . '
  <div class="page">
    <div class="noprint" style="margin-bottom:12px;">
      <button onclick="window.print()">PRINT ALL SWITCH LISTS</button>
      <p style="margin:8px 0 0; color:#555; font-size:14px;">Each phase starts on a new printed page.</p>
    </div>
    ' . $phases_html . '
  </div>
</body>
</html>';

    return master_sw_write_html_file(master_sw_session_print_all_path($output_dir), $html);
}

function master_sw_render_mobile($dbc, $job_name, array $sections, $output_path, array $options = [])
{
    if (is_readable(__DIR__ . '/set_colors.php')) {
        require_once __DIR__ . '/set_colors.php';
    }

    $meta = master_sw_job_meta($dbc, $job_name);
    if ($meta === null) {
        throw new RuntimeException('Unknown job: ' . $job_name);
    }

    $print_width = master_sw_get_setting($dbc, 'print_width') ?: '7.5in';
    $rr_name = master_sw_get_setting($dbc, 'railroad_name') ?: 'HART Railroad';
    $session_nbr = master_sw_get_setting($dbc, 'session_nbr');
    $table_name = $meta['table_name'];
    $job_desc = $meta['description'];
    $page_width = (int) ((float) substr($print_width, 0, 3) * 10);
    $phase_index = (int) ($options['phase_index'] ?? 0);
    $phase_total = (int) ($options['phase_total'] ?? count($sections));
    $nav = $options['nav'] ?? null;
    $single_phase = $phase_index > 0;

    ob_start();

    if (is_array($nav)) {
        echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . htmlspecialchars($table_name) . ' Phase ' . $phase_index . ' — Session ' . htmlspecialchars($session_nbr) . '</title>
  ' . master_sw_render_head_assets() . '
</head>
<body>';
        master_sw_render_phase_nav_bar($table_name, $session_nbr, $nav);
        echo '<div class="page"><pre class="switchlist">';
    } else {
        echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>' . htmlspecialchars($table_name) . ' Master Switchlist — Session ' . htmlspecialchars($session_nbr) . '</title>
  <style>
    @media print { .noprint { display: none; } }
  </style>
  <script>
    function toggle_mobile_instructions() {
      var box = document.getElementById("mobile_instructions_checkbox");
      document.getElementById("mobile_job_instructions").style.display = box.checked ? "block" : "none";
    }
  </script>
</head>
<body>
<pre>';
    }
?>
<div class="noprint">
  <button onclick="window.print()">PRINT</button>&nbsp;&nbsp;
<?php if (!$single_phase) { ?>
  <input type="checkbox" checked id="mobile_instructions_checkbox" onchange="toggle_mobile_instructions();">Show Job Instructions
<?php } ?>
</div><br />
<?= str_pad($rr_name, $page_width, ' ', STR_PAD_BOTH) . '<br />' ?>
<?= str_pad('Switchlist', $page_width, ' ', STR_PAD_BOTH) . '<br />' ?>
<?php
    if ($single_phase && isset($sections[0]['label'])) {
        echo str_pad('Train: ' . $table_name . '  Session ' . $session_nbr, $page_width, ' ', STR_PAD_BOTH) . '<br />';
        echo str_pad('Phase ' . $phase_index . ' of ' . $phase_total, $page_width, ' ', STR_PAD_BOTH) . '<br />';
    } else {
        echo str_pad('Train: ' . $table_name . '  Session ' . $session_nbr, $page_width, ' ', STR_PAD_BOTH) . '<br />';
    }
?>
<br />
Rptg Marks  Type E/L Contents      From           To             PkUp Left<br />
----------- ---- --- ------------- -------------- -------------- ---- ----<br />
<?php
    $loads = 0;
    $empties = 0;
    $special_instructions = [];

    foreach ($sections as $section) {
        master_sw_render_mobile_car_block($dbc, $section, $page_width, $loads, $empties, $special_instructions);
    }

    $total = $loads + $empties;
    echo 'Loads: ' . $loads . '<br />';
    echo 'Empties: ' . $empties . '<br />';
    echo 'Total cars: ' . $total . '<br />';
    if (!$single_phase) {
        echo '<br />Master list: work each phase section in order; rebuild between sections.<br />';
    }

    if (count($special_instructions) > 0) {
        echo '<p style="page-break-after: always;">&nbsp;</p>';
        echo str_pad(' Special Instructions ', $page_width - 1, '-', STR_PAD_BOTH) . '<br /><br />';
        foreach ($special_instructions as $si) {
            master_sw_print_chunks($si[0] . ' (' . $si[1] . ') ' . $si[2], $page_width);
        }
    }

    if (!$single_phase) {
        echo '<p style="page-break-after: always;">&nbsp;</p>';
        echo '<div id="mobile_job_instructions">';
        echo str_pad(' Crew Instructions ', $page_width - 1, '-', STR_PAD_BOTH) . '<br />';
        echo 'Job: ' . htmlspecialchars($table_name) . '<br /><br />';
        master_sw_print_chunks($job_desc, $page_width);
        echo '</div>';
    }

    if (is_array($nav)) {
        echo '</pre></div></body></html>';
    } else {
        echo '</pre></body></html>';
    }

    $html = ob_get_clean();
    return master_sw_write_html_file($output_path, $html);
}

function master_sw_render($dbc, $job_name, array $sections, $output_path, $format = 'halfsheet', array $options = [])
{
    if ($format === 'mobile') {
        return master_sw_render_mobile($dbc, $job_name, $sections, $output_path, $options);
    }
    return master_sw_render_halfsheet($dbc, $job_name, $sections, $output_path, $options);
}

function master_sw_render_halfsheet($dbc, $job_name, array $sections, $output_path, array $options = [])
{
    if (is_readable(__DIR__ . '/set_colors.php')) {
        require_once __DIR__ . '/set_colors.php';
    }

    $meta = master_sw_job_meta($dbc, $job_name);
    if ($meta === null) {
        throw new RuntimeException('Unknown job: ' . $job_name);
    }

    $print_width = master_sw_get_setting($dbc, 'print_width') ?: '7.5in';
    $rr_initials = master_sw_get_setting($dbc, 'railroad_initials') ?: 'HART';
    $session_nbr = master_sw_get_setting($dbc, 'session_nbr');
    $table_name = $meta['table_name'];
    $job_desc = $meta['description'];
    $phase_index = (int) ($options['phase_index'] ?? 0);
    $phase_total = (int) ($options['phase_total'] ?? count($sections));
    $nav = $options['nav'] ?? null;
    $single_phase = $phase_index > 0;

    $units = substr($print_width, -2);
    $value = substr($print_width, 0, strlen($print_width) - 2);
    $col_width = (($value / 2) - 0.125) . $units;

    ob_start();
    if (is_array($nav)) {
        echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>' . htmlspecialchars($table_name) . ' Phase ' . $phase_index . ' — Session ' . htmlspecialchars($session_nbr) . '</title>
  ' . master_sw_render_head_assets() . '
</head>
<body>';
        master_sw_render_phase_nav_bar($table_name, $session_nbr, $nav);
        echo '<div class="page"><div class="halfsheet-wrap">';
    } else {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($table_name) ?> Master Switchlist — Session <?= htmlspecialchars($session_nbr) ?></title>
  <style>
    body { font: normal 20px Verdana, Arial, sans-serif; }
    table { border-collapse: collapse; table-layout: fixed; }
    tr { vertical-align: middle; }
    th, td { border: 1px solid black; padding: 1px; }
    .phase-row td { background: #e8f4ea; font-weight: bold; font-size: 9px; padding: 4px 2px; }
    @media print { .noprint { display: none; } }
  </style>
</head>
<body>
<?php } ?>
<div class="noprint">
  <button onclick="window.print()">PRINT</button>
</div>
<?php if ($single_phase) { ?>
<h2 style="text-align:center; font-size:16px;"><?= htmlspecialchars($table_name) ?> — Phase <?= (int) $phase_index ?> of <?= (int) $phase_total ?></h2>
<?php } ?>
<table>
<?php if (!$single_phase) { ?><tr><td><?php } ?>
<?php if (!$single_phase) { ?>
<h2 style="text-align: center;"><?= htmlspecialchars($rr_initials) ?></h2>
<h3 style="text-align: center;">Master Switchlist</h3>
<table style="font: normal 10px Verdana, Arial, sans-serif; width: <?= htmlspecialchars($col_width) ?>;">
  <tr>
    <td style="width: 50%;"><b>Train: <?= htmlspecialchars($table_name) ?></b><br>Session <?= htmlspecialchars($session_nbr) ?><br><br></td>
    <td style="width: 50%; vertical-align: top;"><b>Dpt (station/date/time)</b><br><br><br></td>
  </tr>
  <tr>
    <td><b>Engine:</b><br><br><b>DCC Address:</b><br><br><b>Caboose:</b></td>
    <td style="vertical-align: top;"><b>Arr (station/date/time)</b><br><br><br></td>
  </tr>
  <tr>
    <td><b>Engineer:</b><br><br><br></td>
    <td><b>Conductor:</b><br><br><br></td>
  </tr>
</table>
<?php } ?>
<table style="font: normal 8px Verdana, Arial, sans-serif; width: <?= htmlspecialchars($single_phase ? '100%' : $col_width) ?>;">
  <tr>
    <th style="width: 60px;">Rptg<br>Marks</th>
    <th style="width: 22px; text-align: center;">Car<br>Code</th>
    <th style="width: 15px; text-align: center;">E/L</th>
    <th>Contents</th>
    <th>From</th>
    <th>To</th>
    <th style="width: 30px">Picked<br>Up</th>
    <th style="width: 35px">Left<br>At</th>
  </tr>
<?php
    $loads = 0;
    $empties = 0;
    $special_instructions = [];

    foreach ($sections as $section) {
        ?>
  <tr class="phase-row"><td colspan="8"><?= htmlspecialchars($section['label']) ?></td></tr>
        <?php
        foreach ($section['cars'] as $row) {
            $is_empty = ($row['status'] === 'Empty') || ($row['status'] === 'Ordered');
            if ($is_empty) {
                $empties++;
                $el = 'E';
            } elseif ($row['status'] === 'Loaded') {
                $loads++;
                $el = 'L';
            } else {
                $el = '';
            }

            [$dest_station, $dest_location, $dest_style] = master_sw_section_destination($dbc, $row, $section);
            $left_at = master_sw_section_left_at($section);
            ?>
  <tr>
    <td><?= htmlspecialchars($row['reporting_marks']) ?></td>
    <td style="text-align: center"><?= htmlspecialchars(substr($row['car_code'], 0, 4)) ?></td>
    <td style="text-align: center"><?= htmlspecialchars($el) ?></td>
    <td><?php
            if ($row['status'] === 'Loaded') {
                echo htmlspecialchars($row['consignment']);
            }
            if (strlen($row['special_instructions'] ?? '') > 0) {
                $special_instructions[] = [
                    $row['reporting_marks'],
                    $row['consignment'],
                    $row['special_instructions'],
                ];
                echo '<br>Spec Instr';
            }
            ?></td>
    <td><?php
            if ((int) $row['current_location_id'] > 0) {
                echo '<u>' . htmlspecialchars($row['current_station']) . '</u><br>' . htmlspecialchars($row['current_location']);
            } else {
                echo 'In Train';
            }
            ?></td>
    <td<?= $dest_style !== '' ? ' style="' . $dest_style . '"' : '' ?>><?php
            if ($dest_station !== '') {
                echo '<u>' . htmlspecialchars($dest_station) . '</u><br>' . htmlspecialchars($dest_location);
            }
            ?></td>
    <td style="text-align: center"><?= master_sw_section_pickup_mark($row, $section) !== '' ? '<b>' . master_sw_section_pickup_mark($row, $section) . '</b>' : '' ?></td>
    <td style="text-align: center"><?= $left_at !== '' ? '<b>' . htmlspecialchars($left_at) . '</b>' : '' ?></td>
  </tr>
            <?php
        }
    }
    $total = $loads + $empties;
    ?>
</table>
<br>
Loads: <?= (int) $loads ?><br>
Empties: <?= (int) $empties ?><br>
Total cars: <?= (int) $total ?>
<?php if (!$single_phase) { ?>
</td>
<td style="padding: 10px; vertical-align: top;">
  <h3>Crew Instructions</h3>
  <h3>Job: <?= htmlspecialchars($table_name) ?></h3>
  <div style="font-size: 10px; width: <?= htmlspecialchars($col_width) ?>;">
    <?= nl2br(htmlspecialchars($job_desc)) ?>
  </div>
  <p style="font-size: 9px; margin-top: 12px;"><b>Master list:</b> phased sections show each switchlist rebuild during the session. Work each section in order; rebuild the consist between sections.</p>
</td>
</tr>
</table>
<?php } else { ?>
</div></div>
<?php } ?>
<?php if (count($special_instructions) > 0) { ?>
<p style="page-break-after: always;">&nbsp;</p>
<h3>Special Instructions</h3>
<?php foreach ($special_instructions as $si) { ?>
<p style="font-size: 10px;"><?= htmlspecialchars($si[0]) ?> (<?= htmlspecialchars($si[1]) ?>) <?= htmlspecialchars($si[2]) ?></p>
<?php } ?>
<?php } ?>
</body>
</html>
<?php
    $html = ob_get_clean();
    return master_sw_write_html_file($output_path, $html);
}

function master_sw_generate_for_jobs($dbc, array $job_names, $output_dir, array $config = [], array $options = [])
{
    $session_nbr = isset($options['session_override']) && $options['session_override'] !== ''
        ? (string) $options['session_override']
        : master_sw_get_setting($dbc, 'session_nbr');
    $format = master_sw_normalize_switchlist_format($options['format'] ?? 'halfsheet', 'halfsheet');
    $render_only = !empty($options['render_only']);
    $from_halfsheet = !empty($options['from_halfsheet']);
    $save_cache_only = !empty($options['save_cache_only']);
    $written = [];
    $job_summaries = [];
    $job_sections_map = [];

    foreach ($job_names as $job_name) {
        $sections = null;

        if ($render_only || $from_halfsheet) {
            $sections = master_sw_load_sections_cache($output_dir, $job_name, $session_nbr);
            if ($sections === null && $from_halfsheet) {
                $sections = master_sw_backfill_cache_from_halfsheet($output_dir, $job_name, $session_nbr);
            }
            if ($sections === null) {
                fwrite(STDERR, "No cache for {$job_name} — pass --from-halfsheet or run a full generate first.\n");
                continue;
            }
        } else {
            $sections = master_sw_build_sections($dbc, $job_name, $config, [
                'recipe' => $options['recipe'] ?? null,
                'through_step' => (int) ($options['through_step'] ?? 0),
            ]);
            if (count($sections) === 0) {
                if (master_sw_is_phased_format($format)) {
                    $job_dir = master_sw_job_output_dir($output_dir, $job_name);
                    $meta = master_sw_job_meta($dbc, $job_name);
                    master_sw_render_empty_job_index(
                        $dbc,
                        $job_name,
                        $job_dir,
                        $session_nbr,
                        'No switch lists generated — no cars matched the dry-run phases for this train.'
                    );
                    $job_summaries[] = [
                        'job' => $job_name,
                        'phases' => 0,
                        'cars' => 0,
                        'description' => $meta['description'] ?? '',
                    ];
                    $written[] = [
                        'job' => $job_name,
                        'path' => master_sw_job_index_path($job_dir),
                        'phases' => 0,
                        'cars' => 0,
                        'format' => $format,
                        'empty' => true,
                    ];
                }
                continue;
            }
            master_sw_save_sections_cache($output_dir, $job_name, $session_nbr, $sections);
            if ($save_cache_only) {
                $written[] = [
                    'job' => $job_name,
                    'path' => master_sw_sections_cache_path($output_dir, $job_name, $session_nbr),
                    'phases' => count($sections),
                    'cars' => array_sum(array_map(function ($s) {
                        return count($s['cars']);
                    }, $sections)),
                    'cache_only' => true,
                ];
                continue;
            }
        }

        $car_count = 0;
        foreach ($sections as $section) {
            $car_count += count($section['cars']);
        }

        if (master_sw_is_phased_format($format)) {
            $phased = master_sw_generate_phased($dbc, $job_name, $sections, $output_dir, $session_nbr, ['format' => $format]);
            $meta = master_sw_job_meta($dbc, $job_name);
            $job_sections_map[$job_name] = $sections;
            $job_summaries[] = [
                'job' => $job_name,
                'phases' => count($sections),
                'cars' => $car_count,
                'description' => $meta['description'] ?? '',
            ];
            $written[] = [
                'job' => $job_name,
                'path' => $phased['index_path'],
                'phases' => count($sections),
                'cars' => $car_count,
                'format' => $format,
            ];
            continue;
        }

        $path = master_sw_output_path($output_dir, $job_name, $session_nbr, $format);
        master_sw_render($dbc, $job_name, $sections, $path, $format);
        $written[] = [
            'job' => $job_name,
            'path' => $path,
            'phases' => count($sections),
            'cars' => $car_count,
            'format' => $format,
        ];
    }

    if (master_sw_is_phased_format($format)) {
        if (count($job_summaries) > 0 && array_sum(array_column($job_summaries, 'cars')) > 0) {
            $index_path = master_sw_render_session_index($dbc, $job_summaries, $output_dir, $session_nbr);
            $session_print_all_path = master_sw_render_session_print_all($dbc, $job_sections_map, $output_dir, $session_nbr);
            $written[] = [
                'job' => 'INDEX',
                'path' => $index_path,
                'phases' => count($job_summaries),
                'cars' => array_sum(array_column($job_summaries, 'cars')),
                'format' => $format,
            ];
            $written[] = [
                'job' => 'PRINT_ALL',
                'path' => $session_print_all_path,
                'phases' => array_sum(array_column($job_summaries, 'phases')),
                'cars' => array_sum(array_column($job_summaries, 'cars')),
                'format' => $format,
            ];
        } else {
            $empty_msg = count($job_summaries) > 0
                ? 'No switch list files were generated for this session — every train had zero matching cars.'
                : 'No switch list files were generated for this session.';
            $index_path = master_sw_render_empty_session_index($dbc, $output_dir, $session_nbr, $empty_msg);
            $written[] = [
                'job' => 'INDEX',
                'path' => $index_path,
                'phases' => 0,
                'cars' => 0,
                'format' => $format,
                'empty' => true,
            ];
        }
        $root_index = master_sw_render_switchlists_root_index(dirname(rtrim($output_dir, '/')), $session_nbr);
        $written[] = [
            'job' => 'ROOT',
            'path' => $root_index,
            'phases' => count($job_summaries),
            'cars' => array_sum(array_column($job_summaries, 'cars')),
            'format' => $format,
        ];
    }

    return $written;
}
