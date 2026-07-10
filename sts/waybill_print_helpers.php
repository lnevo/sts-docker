<?php
/**
 * Printable waybill HTML — shared by STS printable_waybill.php and session generator.
 */

function waybill_print_get_setting($dbc, $name, $default = '')
{
    $esc = mysqli_real_escape_string($dbc, $name);
    $rs = mysqli_query($dbc, 'SELECT setting_value FROM settings WHERE setting_name = "' . $esc . '" LIMIT 1');
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return $default;
    }
    $row = mysqli_fetch_row($rs);
    return (string) ($row[0] ?? $default);
}

function waybill_print_settings($dbc)
{
    return [
        'print_width' => waybill_print_get_setting($dbc, 'print_width', '700px'),
        'railroad_name' => waybill_print_get_setting($dbc, 'railroad_name', 'HART Railroad'),
        'session_nbr' => waybill_print_get_setting($dbc, 'session_nbr', '0'),
    ];
}

function waybill_print_session_prefix($session_nbr)
{
    return str_pad((int) $session_nbr, 3, '0', STR_PAD_LEFT);
}

function waybill_print_session_numbers($dbc, $session_nbr)
{
    $prefix = waybill_print_session_prefix($session_nbr);
    $rs = mysqli_query(
        $dbc,
        'SELECT DISTINCT waybill_number
         FROM car_orders
         WHERE waybill_number LIKE "' . mysqli_real_escape_string($dbc, $prefix) . '-%"
         ORDER BY waybill_number'
    );
    $numbers = [];
    while ($row = mysqli_fetch_array($rs)) {
        $wb = trim((string) ($row['waybill_number'] ?? ''));
        if ($wb !== '') {
            $numbers[] = $wb;
        }
    }
    return $numbers;
}

function waybill_print_location_meta($dbc, $loc_code)
{
    $loc_code = trim((string) $loc_code);
    if ($loc_code === '') {
        return ['station' => '', 'track' => '', 'spot' => '', 'rpt_station' => ''];
    }
    $esc = mysqli_real_escape_string($dbc, $loc_code);
    $rs = mysqli_query(
        $dbc,
        'SELECT routing.station, locations.track, locations.spot, locations.rpt_station
         FROM locations
         INNER JOIN routing ON routing.id = locations.station
         WHERE locations.code = "' . $esc . '"
         LIMIT 1'
    );
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return ['station' => '', 'track' => '', 'spot' => '', 'rpt_station' => ''];
    }
    $row = mysqli_fetch_row($rs);
    return [
        'station' => (string) ($row[0] ?? ''),
        'track' => (string) ($row[1] ?? ''),
        'spot' => (string) ($row[2] ?? ''),
        'rpt_station' => (string) ($row[3] ?? ''),
    ];
}

function waybill_print_apply_rpt_station($station, $rpt_station)
{
    return strlen($rpt_station) > 0 ? $rpt_station : $station;
}

function waybill_print_fetch_row($dbc, $waybill_number)
{
    $wb = mysqli_real_escape_string($dbc, $waybill_number);
    if (strpos($waybill_number, 'E') !== false) {
        $sql = 'SELECT cars.reporting_marks AS reporting_marks,
                       "" AS shipment,
                       "" AS description,
                       "" AS consignment,
                       "" AS commodity_code,
                       loc01.code AS current_location,
                       sta01.station AS current_station,
                       "" AS loading_location,
                       "" AS loading_station,
                       loc03.code AS unloading_location,
                       sta03.station AS unloading_station,
                       cars.remarks AS remarks,
                       car_codes.code AS car_code,
                       "" AS special_instructions
                  FROM cars
                  LEFT JOIN car_orders ON car_orders.car = cars.id
                  LEFT JOIN locations loc01 ON loc01.id = cars.current_location_id
                  LEFT JOIN locations loc03 ON loc03.id = car_orders.shipment
                  LEFT JOIN routing sta01 ON sta01.id = loc01.station
                  LEFT JOIN routing sta03 ON sta03.id = loc03.station
                  LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
                 WHERE car_orders.waybill_number = "' . $wb . '"
                   AND car_orders.car = cars.id
                 LIMIT 1';
    } else {
        $sql = 'SELECT cars.reporting_marks AS reporting_marks,
                       shipments.code AS shipment,
                       shipments.description AS description,
                       commodities.description AS consignment,
                       commodities.code AS commodity_code,
                       loc01.code AS current_location,
                       sta01.station AS current_station,
                       loc02.code AS loading_location,
                       sta02.station AS loading_station,
                       loc03.code AS unloading_location,
                       sta03.station AS unloading_station,
                       shipments.remarks AS remarks,
                       car_codes.code AS car_code,
                       shipments.special_instructions
                  FROM car_orders
                  LEFT JOIN cars ON cars.id = car_orders.car
                  LEFT JOIN shipments ON shipments.id = car_orders.shipment
                  LEFT JOIN commodities ON commodities.id = shipments.consignment
                  LEFT JOIN locations loc01 ON loc01.id = cars.current_location_id
                  LEFT JOIN locations loc02 ON loc02.id = shipments.loading_location
                  LEFT JOIN locations loc03 ON loc03.id = shipments.unloading_location
                  LEFT JOIN routing sta01 ON sta01.id = loc01.station
                  LEFT JOIN routing sta02 ON sta02.id = loc02.station
                  LEFT JOIN routing sta03 ON sta03.id = loc03.station
                  LEFT JOIN car_codes ON car_codes.id = cars.car_code_id
                 WHERE car_orders.waybill_number = "' . $wb . '"
                 LIMIT 1';
    }
    $rs = mysqli_query($dbc, $sql);
    if (!$rs || mysqli_num_rows($rs) === 0) {
        return null;
    }
    return mysqli_fetch_array($rs);
}

function waybill_print_render_body($dbc, $waybill_number, array $settings = null)
{
    $settings = $settings ?? waybill_print_settings($dbc);
    $row = waybill_print_fetch_row($dbc, $waybill_number);
    if (!$row) {
        return '';
    }

    $print_width = $settings['print_width'];
    $rr_name = $settings['railroad_name'];
    $os_number = $settings['session_nbr'];

    $reporting_marks = $row['reporting_marks'] ?? '';
    $shipment = $row['shipment'] ?? '';
    $description = $row['description'] ?? '';
    $consignment = $row['consignment'] ?? '';
    $commodity_code = $row['commodity_code'] ?? '';
    $from_loc = $row['loading_location'] ?? '';
    $to_loc = $row['unloading_location'] ?? '';
    $current_loc = $row['current_location'] ?? '';
    $car_code = $row['car_code'] ?? '';
    $special_instructions = $row['special_instructions'] ?? '';

    $empty = waybill_print_location_meta($dbc, $current_loc);
    $from = waybill_print_location_meta($dbc, $from_loc);
    $to = waybill_print_location_meta($dbc, $to_loc);

    $empty_station = $empty['station'];
    $empty_track = $empty['track'];
    $empty_spot = $empty['spot'];
    $from_station = $from['station'];
    $from_track = $from['track'];
    $from_spot = $from['spot'];
    $to_station = $to['station'];
    $to_track = $to['track'];
    $to_spot = $to['spot'];

    $html = '';

    if ($empty_station !== $from_station) {
        $empty_station = waybill_print_apply_rpt_station($empty_station, $empty['rpt_station']);
        $from_station = waybill_print_apply_rpt_station($from_station, $from['rpt_station']);
        $to_station = waybill_print_apply_rpt_station($to_station, $to['rpt_station']);

        $html .= '<table style="width: ' . htmlspecialchars($print_width) . ';">
<tr style="font: normal 15px Verdana, Arial, sans-serif;">
<td style="text-align: center;" colspan="2">
<h2 style="font-family: Times New Roman, Times, serif;">' . htmlspecialchars($rr_name) . '</h2>
<h3>FREIGHT WAYBILL</h3>
<div style="font: normal 10px Verdana, Arial, sans-serif;">TO BE USED FOR SINGLE CONSIGNMENTS, CARLOAD AND LESS THAN CARLOAD</div>
</td></tr>
<tr><td style="width: 50%;"><table><tr style="font: normal 10px Verdana, Arial, sans-serif;">
<td style="width: 50%; text-align: center;">CAR INITIALS AND NUMBER<br /><br />' . htmlspecialchars($reporting_marks) . '</td>
<td style="width: 50%; text-align: center;">KIND<br /><br />' . htmlspecialchars($car_code) . '</td>
</tr></table></td>
<td style="width: 50%;"><table><tr style="font: normal 10px Verdana, Arial, sans-serif;">
<td style="width: 50%; text-align: center;">OPERATING SESSION No.<br /><br />' . htmlspecialchars($os_number) . '</td>
<td style="width: 50%; text-align: center;">WAYBILL No.<br /><br />' . htmlspecialchars($waybill_number) . '</td>
</tr></table></td></tr>
<tr style="font: normal 10px Verdana, Arial, sans-serif;"><td>';
        if (strpos($waybill_number, 'E') !== false) {
            $html .= '<b>TO</b> ' . htmlspecialchars($to_loc) . '<br /><b>STATION</b> ' . htmlspecialchars($to_station)
                . '<br /><b>TRACK</b> ' . htmlspecialchars($to_track) . '<br /><b>SPOT</b> ' . htmlspecialchars($to_spot) . '<br />';
        } else {
            $html .= '<b>TO</b> ' . htmlspecialchars($from_loc) . '<br /><b>STATION</b> ' . htmlspecialchars($from_station)
                . '<br /><b>TRACK</b> ' . htmlspecialchars($from_track) . '<br /><b>SPOT</b> ' . htmlspecialchars($from_spot) . '<br />';
        }
        $html .= '</td><td><b>FROM</b> ' . htmlspecialchars($current_loc) . '<br /><b>STATION</b> ' . htmlspecialchars($empty_station)
            . '<br /><b>TRACK</b> ' . htmlspecialchars($empty_track) . '<br /><b>SPOT</b> ' . htmlspecialchars($empty_spot) . '<br /></td></tr>
<tr style="font: normal 10px Verdana, Arial, sans-serif;">
<td style="height: 100px">SPECIAL INSTRUCTIONS (Regarding Icing, Weighing, Etc.)</td>
<td>SHIPMENT</td></tr>
<tr style="font: normal 10px Verdana, Arial, sans-serif;"><td colspan="2">DESCRIPTION OF ARTICLES<br /><br />Empty Car Assignment</td></tr>
</table><br />';
    } elseif ($empty_station === $from_station && $current_loc !== $from_loc) {
        $from_station = waybill_print_apply_rpt_station($from_station, $from['rpt_station']);
        $html .= '<table style="width: ' . htmlspecialchars($print_width) . ';">
<tr style="font: normal 15px Verdana, Arial, sans-serif;"><td style="text-align: center;" colspan="2">
<h1 style="font-family: Times New Roman, Times, serif;">' . htmlspecialchars($rr_name) . '</h1><h2>COMPANY MEMO</h2></td></tr>
<tr><td>FROM:<br /><br /><hr />TO C&E No.<br /><br /><hr />OPERATING SESSION: ' . htmlspecialchars($os_number) . '</td></tr>
<tr><td>REPOSITION THE FOLLOWING EMPTY CAR<br /><br />FOR LOADING AT ' . htmlspecialchars($from_station) . ' / ' . htmlspecialchars($from_loc)
            . '<br /><br />CAR INITIALS AND NUMBER: ' . htmlspecialchars($reporting_marks) . ' KIND: ' . htmlspecialchars($car_code)
            . '<br /><br />LOCATED AT: ' . htmlspecialchars($row['current_station'] ?? '') . ' / ' . htmlspecialchars($current_loc) . '</td></tr>
</table><br />';
    }

    if (strpos($waybill_number, 'E') === false) {
        $from_station = waybill_print_apply_rpt_station($from_station, $from['rpt_station']);
        $to_station = waybill_print_apply_rpt_station($to_station, $to['rpt_station']);
        $html .= '<table style="width: ' . htmlspecialchars($print_width) . ';">
<tr style="font: normal 15px Verdana, Arial, sans-serif;"><td style="text-align: center;" colspan="2">
<h2 style="font-family: Times New Roman, Times, serif;">' . htmlspecialchars($rr_name) . '</h2>
<h3>FREIGHT WAYBILL</h3>
<div style="font: normal 10px Verdana, Arial, sans-serif;">TO BE USED FOR SINGLE CONSIGNMENTS, CARLOAD AND LESS THAN CARLOAD</div>
</td></tr>
<tr><td style="width: 50%;"><table><tr style="font: normal 10px Verdana, Arial, sans-serif;">
<td style="width: 50%; text-align: center;">CAR INITIALS AND NUMBER<br /><br />' . htmlspecialchars($reporting_marks) . '</td>
<td style="width: 50%; text-align: center;">KIND<br /><br />' . htmlspecialchars($car_code) . '</td>
</tr></table></td>
<td style="width: 50%;"><table><tr style="font: normal 10px Verdana, Arial, sans-serif;">
<td style="width: 50%; text-align: center;">OPERATING SESSION No.<br /><br />' . htmlspecialchars($os_number) . '</td>
<td style="width: 50%; text-align: center;">WAYBILL No.<br /><br />' . htmlspecialchars($waybill_number) . '</td>
</tr></table></td></tr>
<tr style="font: normal 10px Verdana, Arial, sans-serif;">
<td><b>TO</b> ' . htmlspecialchars($to_loc) . '<br /><b>STATION</b> ' . htmlspecialchars($to_station)
            . '<br /><b>TRACK</b> ' . htmlspecialchars($to_track) . '<br /><b>SPOT</b> ' . htmlspecialchars($to_spot) . '<br /></td>
<td><b>FROM</b> ' . htmlspecialchars($from_loc) . '<br /><b>STATION</b> ' . htmlspecialchars($from_station)
            . '<br /><b>TRACK</b> ' . htmlspecialchars($from_track) . '<br /><b>SPOT</b> ' . htmlspecialchars($from_spot) . '<br /></td></tr>
<tr style="font: normal 10px Verdana, Arial, sans-serif;">
<td style="height: 100px;">SPECIAL INSTRUCTIONS (Regarding Icing, Weighing, Etc.)<br /><br />' . htmlspecialchars($special_instructions) . '</td>
<td>SHIPMENT<br /><br />' . htmlspecialchars($description) . '<br /><br />(' . htmlspecialchars($shipment) . ')</td></tr>
<tr style="font: normal 10px Verdana, Arial, sans-serif;">
<td>DESCRIPTION OF ARTICLES<br /><br />' . htmlspecialchars($consignment) . '</td>
<td>COMMODITY CODE: ' . htmlspecialchars($commodity_code) . '</td></tr>
</table>';
    }

    return $html;
}

function waybill_print_safe_filename($waybill_number)
{
    return preg_replace('/[^a-zA-Z0-9._-]+/', '_', $waybill_number);
}

function waybill_print_page_styles()
{
    return 'body{font:normal 20px Verdana,Arial,sans-serif;margin:0;padding:16px}'
        . 'table{border-collapse:collapse}tr{vertical-align:top}'
        . 'th,td{border:1px solid black;padding:10px}'
        . '.waybill-sheet{margin-bottom:32px}'
        . '@media print{.noprint{display:none!important}.waybill-sheet{page-break-after:always;break-after:page}'
        . '.waybill-sheet:last-child{page-break-after:auto;break-after:auto}}';
}

function waybill_print_render_page($dbc, $waybill_number, array $options = [])
{
    $settings = $options['settings'] ?? waybill_print_settings($dbc);
    $body = waybill_print_render_body($dbc, $waybill_number, $settings);
    if ($body === '') {
        return '';
    }
    $title = 'Waybill ' . $waybill_number;
    $nav = $options['nav_html'] ?? '';
    $controls = '';
    if (!empty($options['show_controls'])) {
        $controls = '<div class="noprint"><button type="button" onclick="window.print()">Print</button>'
            . ($nav !== '' ? ' &nbsp; ' . $nav : '') . '<br /><br /></div>';
    }
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>'
        . htmlspecialchars($title) . '</title><style>' . waybill_print_page_styles() . '</style></head><body>'
        . $controls . '<div class="waybill-sheet">' . $body . '</div></body></html>';
}

function waybill_print_render_bundle_page($title, $sheets_html, array $options = [])
{
    $back = $options['back_href'] ?? '';
    $controls = '<div class="noprint"><button type="button" onclick="window.print()">Print all</button>';
    if ($back !== '') {
        $controls .= ' &nbsp; <a href="' . htmlspecialchars($back) . '">Back</a>';
    }
    $controls .= '<br /><br /></div>';
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>'
        . htmlspecialchars($title) . '</title><style>' . waybill_print_page_styles() . '</style></head><body>'
        . $controls . $sheets_html . '</body></html>';
}
