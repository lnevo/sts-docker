<?php
require __DIR__ . '/track_scale_helpers.php';
require_once dirname(__DIR__, 2) . '/open_db.php';
track_scale_session_init();
$config = track_scale_load_config();
// Sync calibration once on page load so the AJAX read endpoints
// (cars_at_scale / calibration_state) can stay side-effect free.
$track_scale_dbc = open_db();
track_scale_sync_session_calibration($track_scale_dbc);
// Fresh calibration UI: car at the scale spot does not count as placed on a sensor.
if (!track_scale_is_calibration_locked($track_scale_dbc)) {
    $track_scale_any_weighed = false;
    foreach (track_scale_sensor_positions() as $track_scale_pos) {
        if (track_scale_sensor_has_reading($track_scale_pos)) {
            $track_scale_any_weighed = true;
            break;
        }
    }
    if (!$track_scale_any_weighed) {
        track_scale_set_scale_car_position(null);
        track_scale_clear_sensor_locks();
    }
}
$track_scale_ui = [
    'siteLabel' => track_scale_site_label($config),
    'routedTrainsLabel' => track_scale_routed_trains_label($config),
    'scaleLocation' => track_scale_loading_location_code($config),
];
$track_scale_test_car_marks = strtoupper(trim((string) (($config['calibration'] ?? [])['test_car_reporting_marks'] ?? '')));
$track_scale_test_car_options = track_scale_test_car_roster_options($config, $track_scale_dbc);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>STS - Track Scale</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .scale-display {
            background: #1a1a1a;
            color: #39ff14;
            font-family: "Courier New", Courier, monospace;
            border: 4px solid #333;
            border-radius: 8px;
            padding: 1.25rem 1.5rem;
            box-shadow: inset 0 0 24px rgba(0, 0, 0, 0.6);
        }
        .scale-display .label {
            color: #6bdc6b;
            font-size: 0.75rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .scale-display .value {
            font-size: 2.4rem;
            font-weight: 700;
            line-height: 1.1;
            letter-spacing: 0.06em;
        }
        .scale-display.is-settling .value {
            transition: none;
            opacity: 0.92;
        }
        .scale-display .unit {
            font-size: 1rem;
            color: #6bdc6b;
        }
        .scale-display.position-relative {
            position: relative;
        }
        .scale-led-wrap {
            position: absolute;
            top: 0.65rem;
            right: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.55rem;
            max-width: min(58%, 22rem);
            z-index: 2;
        }
        .scale-led {
            display: none !important; /* status text only — no indicator lamp */
        }
        .scale-led[data-state="ok"] {
            background: #39ff14;
            border-color: #6bdc6b;
            box-shadow: 0 0 6px #39ff14, 0 0 14px rgba(57, 255, 20, 0.45);
        }
        .scale-led[data-state="fail"] {
            background: #ff3131;
            border-color: #ff6b6b;
            box-shadow: 0 0 6px #ff3131, 0 0 14px rgba(255, 49, 49, 0.5);
        }
        .scale-led[data-state="oos"] {
            background: #ff3131;
            border-color: #ff6b6b;
            box-shadow: 0 0 6px #ff3131, 0 0 14px rgba(255, 49, 49, 0.5);
        }
        .scale-led-label {
            font-family: "Courier New", Courier, monospace;
            font-size: 0.62rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            text-align: right;
            line-height: 1.1;
        }
        /* Status banners share one size (PASS / OOS / FAIL - …) */
        .scale-led-label.ready-label,
        .scale-led-label.oos-label,
        .scale-led-label.fail-label {
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            line-height: 1.1;
        }
        .scale-led-label.oos-label,
        .scale-led-label.fail-label {
            color: #ff6b6b;
        }
        .scale-led-label.ready-label {
            color: #39ff14;
        }
        /* Status banners: full width across top of the LED panel, right-justified */
        .scale-display.out-of-service .scale-led-wrap,
        .scale-display.out-of-range .scale-led-wrap,
        .scale-display.is-ready .scale-led-wrap,
        .scale-display.is-weighing .scale-led-wrap {
            left: 0.65rem;
            right: 0.65rem;
            top: 0.55rem;
            max-width: none;
            width: auto;
            justify-content: flex-end;
        }
        .scale-display.out-of-service .scale-led-label.oos-label,
        .scale-display.out-of-range .scale-led-label.fail-label,
        .scale-display.is-ready .scale-led-label.ready-label,
        .scale-display.is-weighing .scale-led-label.weighing-label {
            display: block;
            width: 100%;
            text-align: right;
            white-space: nowrap;
            letter-spacing: 0.12em;
        }
        .scale-led-label.weighing-label {
            color: #ffd666;
        }
        .scale-display.out-of-range .scale-led-label.fail-label {
            letter-spacing: 0.06em;
            font-size: 1.15rem;
        }
        .scale-display.out-of-range .value,
        .scale-display.out-of-range .unit {
            color: #ff6b6b;
        }
        .scale-display.out-of-range .label {
            color: #ff8888;
        }
        .scale-display.out-of-service .value,
        .scale-display.out-of-service .unit {
            color: #ff3131;
        }
        .scale-display.out-of-service .label {
            color: #ff8888;
        }
        .car-photo {
            background: #eee;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            min-height: 180px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .car-photo img {
            max-width: 100%;
            max-height: 220px;
            object-fit: contain;
        }
        .car-panel-header {
            margin-bottom: 0.75rem;
            text-align: center;
        }
        .car-panel-header #carMarks {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }
        .car-panel-header #carMeta {
            font-size: 1rem;
            line-height: 1.35;
        }
        .car-panel-body {
            display: flex;
            flex-wrap: wrap;
            align-items: stretch;
            gap: 0.75rem;
            container-type: inline-size;
        }
        .car-panel-body .car-photo-col {
            flex: 1 1 calc(100% - 24rem - 0.75rem);
            min-width: 12rem;
            max-width: 100%;
        }
        .car-panel-body .car-stats-col {
            flex: 1 1 24rem;
            min-width: 24rem;
            max-width: 100%;
        }
        .car-panel-body .car-photo {
            width: 100%;
            min-height: 100%;
            height: 100%;
        }
        @container (max-width: 37rem) {
            .car-panel-body .car-photo-col,
            .car-panel-body .car-stats-col {
                flex: 1 1 100%;
                min-width: 100%;
                max-width: 100%;
            }
            .car-panel-body .car-photo {
                aspect-ratio: 16 / 9;
                height: auto;
                min-height: 0;
                max-height: 14rem;
            }
        }
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.5rem 0.65rem;
            height: 100%;
        }
        .stat-box {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 0.5rem 0.6rem;
        }
        .stat-box .stat-label {
            font-size: 0.65rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            line-height: 1.15;
            white-space: nowrap;
        }
        .stat-box .stat-value {
            font-size: 1rem;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
        }
        @media (max-width: 767.98px) {
            .car-panel-header #carMarks {
                font-size: 1.25rem;
            }
            .car-panel-header #carMeta {
                font-size: 0.88rem;
            }
            .car-panel-body {
                gap: 0.5rem;
            }
            .car-panel-body .car-stats-col {
                flex: 1 1 100%;
                min-width: 100%;
            }
            .car-panel-body .car-photo-col {
                flex: 1 1 100%;
            }
            .car-photo {
                min-height: 0;
                aspect-ratio: 16 / 9;
                height: auto;
                max-height: 12rem;
            }
            .car-photo img {
                max-height: 100%;
            }
            .stat-grid {
                gap: 0.35rem 0.5rem;
                height: auto;
            }
            .stat-box {
                padding: 0.35rem 0.45rem;
                border-radius: 0.25rem;
            }
            .stat-box .stat-label {
                font-size: 0.58rem;
            }
            .stat-box .stat-value {
                font-size: 0.85rem;
            }
        }
        .routing-reload {
            display: block;
            flex: 1 1 100%;
            width: 100%;
            background-color: #dc3545;
            color: #fff;
            font-weight: 600;
            padding: 0.65rem 0.85rem;
            border-radius: 0.375rem;
            border: 2px solid #a71d2a;
        }
        .routing-reload .bi {
            color: #fff;
        }
        .order-select {
            width: auto;
            min-width: 16rem;
            max-width: 100%;
        }
        .order-empty-msg {
            display: inline-block;
            width: auto;
            margin-top: 0.375rem;
            font-size: 0.75rem;
            background-color: #ffc107;
            color: #212529;
            font-weight: 600;
            padding: 0.375rem 0.5625rem;
            border-radius: 0.28rem;
            border: 1px solid #e0a800;
        }
        .order-empty-msg .bi {
            color: #212529;
        }
        .routing-outbound { color: #198754; }
        .mode-panel { display: none; }
        .mode-panel.active { display: block; }
        .sensor-card {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            background: #fff;
            padding: 0.75rem;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .sensor-card > .scale-display {
            order: 1;
        }
        .sensor-card > .sensor-error-line {
            order: 2;
        }
        .sensor-card > .sensor-adj-line {
            order: 3;
        }
        .sensor-card > .cal-position-btn {
            order: 4;
            margin-top: 0.35rem;
            margin-bottom: 0.65rem !important;
            min-height: 4rem;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
            /* Match LEFT / CENTER / RIGHT title type */
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        /* Left / Center / Right labels sit at the bottom of each panel */
        .sensor-card > .sensor-card-heading {
            order: 5;
            margin-top: auto;
            margin-bottom: 0 !important;
            text-align: center;
        }
        .sensor-card .sensor-card-title {
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .sensor-card .scale-display {
            padding: 0.75rem 1rem;
        }
        .sensor-card .scale-display .value {
            font-size: 1.6rem;
        }
        .sensor-card.calibrated {
            border-color: #2e7d32;
            box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.35);
            background: #e8f5e9;
        }
        .sensor-card.calibrated.car-at-position {
            border-color: #198754;
            box-shadow: 0 0 0 2px rgba(25, 135, 84, 0.45);
            background: #d1e7dd;
        }
        .cal-central-panel.is-ready {
            border-color: #198754;
            background: #e8f8ee;
            box-shadow: 0 0 0 1px rgba(25, 135, 84, 0.2);
        }
        .scale-display.is-ready .value,
        .scale-display.is-ready .unit,
        .scale-display.is-ready .label {
            color: #39ff14;
        }
        /* Ready wins over OOS red if both classes briefly overlap */
        .scale-display.out-of-service.is-ready .value,
        .scale-display.out-of-service.is-ready .unit,
        .scale-display.out-of-service.is-ready .label {
            color: #39ff14;
        }
        .sensor-card.calibrated .scale-display .value,
        .sensor-card.calibrated .scale-display .unit {
            color: #39ff14;
        }
        .sensor-card.car-at-position {
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.25);
            background: #f8fbff;
        }
        .sensor-card.adjustment-locked .cal-adj-group {
            opacity: 0.45;
        }
        .sensor-card .cal-position-btn.active,
        .sensor-card .cal-position-btn.cal-lock-phase {
            background-color: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
        }
        .sensor-card .cal-position-btn.cal-lock-phase:hover:not(:disabled) {
            background-color: #0b5ed7;
            border-color: #0a58ca;
            color: #fff;
        }
        .sensor-card .cal-position-btn.cal-locked-phase,
        .sensor-card .cal-position-btn.cal-locked-phase:disabled {
            background-color: #198754;
            border-color: #198754;
            color: #fff;
            opacity: 1;
        }
        .sensor-card .sensor-title-short {
            display: none;
        }
        .cal-sensor-row > [class*="col-"] {
            flex: 0 0 33.333333%;
            max-width: 33.333333%;
            min-width: 0;
        }
        @media (max-width: 575.98px) {
            .cal-sensor-row {
                --bs-gutter-x: 0.45rem;
                --bs-gutter-y: 0.45rem;
            }
            .cal-sensor-row .sensor-card {
                padding: 0.45rem 0.4rem;
            }
            .cal-sensor-row .sensor-card > .mb-2 {
                margin-bottom: 0.35rem !important;
            }
            .cal-sensor-row .sensor-card .sensor-card-title {
                font-size: 0.78rem;
                line-height: 1.15;
                display: block;
            }
            .cal-sensor-row .sensor-card .sensor-title-full {
                display: none;
            }
            .cal-sensor-row .sensor-card .sensor-title-short {
                display: inline;
            }
            .cal-sensor-row .sensor-card .scale-display {
                padding: 0.4rem 0.35rem;
                margin-bottom: 0.35rem !important;
            }
            .cal-sensor-row .sensor-card .scale-display .label {
                font-size: 0.6rem;
            }
            .cal-sensor-row .sensor-card .scale-display .value {
                font-size: 1.05rem;
            }
            .cal-sensor-row .sensor-card .scale-display .unit {
                font-size: 0.7rem;
            }
            .cal-sensor-row .sensor-card .sensor-error-line {
                font-size: 0.7rem;
                margin-bottom: 0.35rem !important;
            }
            .cal-sensor-row .sensor-card .cal-position-btn {
                font-size: 0.78rem;
                font-weight: 700;
                letter-spacing: 0.04em;
                padding: 0.7rem 0.25rem;
                min-height: 4.3rem;
                margin-bottom: 0 !important;
            }
            .cal-sensor-row .sensor-card .cal-position-btn .bi {
                display: none;
            }
            .cal-central-panel .cal-central-stack {
                column-gap: 0.55rem;
                row-gap: 1.15rem;
            }
            .cal-central-panel .cal-stat-label {
                font-size: 0.62rem;
            }
            .cal-central-panel .cal-stat-value {
                font-size: 1rem;
            }
            .cal-central-panel .cal-stat-error .cal-stat-value {
                font-size: 1.1rem;
            }
            .cal-central-panel .cal-central-adj-wrap {
                max-width: 100%;
            }
        }
        .cal-track-wrap {
            margin-bottom: 1rem;
        }
        .cal-track-rail {
            position: relative;
            background: linear-gradient(180deg, #e9ecef 0%, #ced4da 100%);
            border: 1px solid #adb5bd;
            border-radius: 0.375rem;
            padding: 0.5rem 0.5rem 3.25rem;
            min-height: 4.5rem;
        }
        .cal-track-car {
            position: absolute;
            bottom: 0.35rem;
            width: 33.333%;
            display: flex;
            justify-content: center;
            align-items: flex-end;
            transition: left 0.35s ease;
            pointer-events: none;
        }
        .cal-track-car.position-left { left: 0; }
        .cal-track-car.position-center { left: 33.333%; }
        .cal-track-car.position-right { left: 66.666%; }
        .cal-track-car img {
            max-height: 4.5rem;
            max-width: 92%;
            object-fit: contain;
            filter: drop-shadow(0 2px 5px rgba(0, 0, 0, 0.25));
        }
        .cal-track-car .cal-track-car-placeholder {
            font-size: 0.75rem;
            color: #6c757d;
            padding-bottom: 0.5rem;
        }
        :root {
            --scale-top-row-height: 150px;
        }
        body.track-scale-accessible {
            --scale-top-row-height: 168px;
        }
        /* Shared LCD height for weigh + calibrate top panels */
        #weighPanel > .scale-top-row .scale-display,
        #calibratePanel > .scale-top-row .scale-display {
            flex: 1 1 auto;
            width: 100%;
            height: var(--scale-top-row-height);
            min-height: var(--scale-top-row-height);
            max-height: var(--scale-top-row-height);
            box-sizing: border-box;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        #weighPanel > .scale-top-row > [class*="col-"] {
            display: flex;
        }
        #weighPanel > .scale-top-row {
            /* Row may wrap on narrow screens; each LCD stays fixed height */
            min-height: var(--scale-top-row-height);
            box-sizing: border-box;
        }
        .sensor-average.scale-top-row {
            display: flex;
            height: var(--scale-top-row-height);
            min-height: var(--scale-top-row-height);
            max-height: var(--scale-top-row-height);
            box-sizing: border-box;
        }
        #weighPanel > .scale-top-row .scale-display .value,
        #calibratePanel > .scale-top-row .scale-display .value {
            font-size: 2.4rem;
        }
        #weighPanel .scale-led-label.ready-label,
        #weighPanel .scale-led-label.oos-label,
        #weighPanel .scale-led-label.fail-label,
        #calibratePanel .scale-led-label.ready-label,
        #calibratePanel .scale-led-label.oos-label,
        #calibratePanel .scale-led-label.fail-label {
            font-size: 1.35rem;
        }
        /* Keep train reassign note on its own row so it never collapses beside action buttons. */
        #inTrainAssignNote.in-train-assign-note {
            display: block;
            width: 100%;
            max-width: 100%;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        #inTrainAssignNote.in-train-assign-note.d-none {
            display: none !important;
        }
        .car-list-item {
            cursor: pointer;
            transition: background-color 0.15s;
        }
        .car-list-item:hover { background-color: #f0fff4; }
        .car-list-item.active {
            background-color: #d1e7dd;
            border-color: #2e7d32 !important;
        }
        .car-list-position {
            font-size: 0.75rem;
            color: #6c757d;
            min-width: 2rem;
            text-align: right;
        }
        .car-list-marks {
            font-weight: 400;
        }
        .status-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.45rem;
            border-radius: 0.25rem;
        }
        .access-toggle-wrap {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            color: #fff;
            font-size: 0.9rem;
            white-space: nowrap;
            user-select: none;
        }
        .access-toggle-wrap .form-check-input {
            width: 2.6rem;
            height: 1.35rem;
            margin: 0;
            cursor: pointer;
            background-color: rgba(255, 255, 255, 0.35);
            border-color: rgba(255, 255, 255, 0.65);
        }
        .access-toggle-wrap .form-check-input:checked {
            background-color: #fff;
            border-color: #fff;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23198754'/%3e%3c/svg%3e");
        }
        .access-toggle-wrap .form-check-input:focus {
            box-shadow: 0 0 0 0.2rem rgba(255, 255, 255, 0.35);
        }
        .access-toggle-wrap label {
            cursor: pointer;
            margin: 0;
            line-height: 1.2;
        }

        /* Larger controls mode — same layout, bigger hit targets / type */
        body.track-scale-accessible {
            font-size: 1.2rem;
        }
        body.track-scale-accessible .navbar-brand {
            font-size: 1.45rem;
        }
        body.track-scale-accessible .access-toggle-wrap {
            font-size: 1.1rem;
            gap: 0.7rem;
        }
        body.track-scale-accessible .access-toggle-wrap .form-check-input {
            width: 3.1rem;
            height: 1.65rem;
        }
        body.track-scale-accessible .container {
            max-width: 1100px !important;
        }
        body.track-scale-accessible h5 {
            font-size: 1.55rem;
        }
        body.track-scale-accessible .text-muted.small,
        body.track-scale-accessible .small {
            font-size: 1.05rem !important;
        }
        body.track-scale-accessible .btn-group .btn {
            font-size: 1.2rem;
            padding: 0.7rem 1.15rem;
            min-height: 3rem;
        }
        body.track-scale-accessible .btn-lg {
            font-size: 1.35rem;
            padding: 0.85rem 1.35rem;
            min-height: 3.4rem;
        }
        body.track-scale-accessible .btn:not(.btn-lg):not(.btn-sm) {
            font-size: 1.15rem;
            padding: 0.65rem 1.1rem;
            min-height: 2.9rem;
        }
        body.track-scale-accessible .btn-sm {
            font-size: 1.1rem;
            padding: 0.55rem 0.95rem;
            min-height: 2.75rem;
        }
        body.track-scale-accessible .btn.cal-adj-btn,
        body.track-scale-accessible .btn.cal-adj-reset-btn {
            font-size: 1.15rem;
            min-height: 3rem;
            padding-top: 0.65rem;
            padding-bottom: 0.65rem;
        }
        body.track-scale-accessible .btn.cal-position-btn,
        body.track-scale-accessible .btn.cal-weigh-btn {
            font-size: 1.25rem;
            font-weight: 700;
            min-height: 6rem;
            padding-top: 1.3rem;
            padding-bottom: 1.3rem;
        }
        body.track-scale-accessible .cal-adj-group .btn {
            min-width: 3rem;
        }
        body.track-scale-accessible .cal-adj-group .form-control {
            font-size: 1.35rem;
            min-height: 3rem;
            font-weight: 700;
        }
        body.track-scale-accessible .form-select,
        body.track-scale-accessible .form-control {
            font-size: 1.2rem;
            min-height: 3rem;
            padding: 0.55rem 0.9rem;
        }
        body.track-scale-accessible .form-select-sm {
            font-size: 1.1rem;
            min-height: 2.85rem;
        }
        body.track-scale-accessible .form-check-input {
            width: 1.45rem;
            height: 1.45rem;
            margin-top: 0.15rem;
        }
        body.track-scale-accessible .form-check-label {
            font-size: 1.1rem;
            padding-left: 0.25rem;
        }
        body.track-scale-accessible .form-label {
            font-size: 1.15rem;
            font-weight: 600;
        }
        body.track-scale-accessible #weighPanel > .scale-top-row .scale-display .value,
        body.track-scale-accessible #calibratePanel > .scale-top-row .scale-display .value {
            font-size: 2.6rem;
        }
        body.track-scale-accessible #weighPanel .scale-led-label.ready-label,
        body.track-scale-accessible #weighPanel .scale-led-label.oos-label,
        body.track-scale-accessible #weighPanel .scale-led-label.fail-label,
        body.track-scale-accessible #calibratePanel .scale-led-label.ready-label,
        body.track-scale-accessible #calibratePanel .scale-led-label.oos-label,
        body.track-scale-accessible #calibratePanel .scale-led-label.fail-label {
            font-size: 1.5rem;
        }
        body.track-scale-accessible .sensor-card .scale-display {
            padding: 1rem 1.15rem;
        }
        body.track-scale-accessible .sensor-card .scale-display .value {
            font-size: 2.4rem;
        }
        body.track-scale-accessible .sensor-card .scale-display .unit {
            font-size: 1.15rem;
        }
        body.track-scale-accessible .sensor-card .sensor-error-line {
            font-size: 1.15rem;
            font-weight: 600;
        }
        .cal-instructions,
        .sensor-adj-line,
        .sensor-car-here-badge,
        .cal-sensor-local-controls {
            display: none !important;
        }
        .cal-central-controls {
            display: block;
            margin-bottom: 0;
        }
        .cal-central-panel {
            border: 2px solid #198754;
            border-radius: 0.5rem;
            background: #f8fff9;
            padding: 1rem 1.15rem;
        }
        /* Status band below the adjustment slider (inside the green panel) */
        .cal-central-panel .cal-actions-meta {
            grid-column: 1 / -1;
            width: 100%;
            max-width: none;
            justify-self: stretch;
            text-align: left;
            min-height: 1.35rem;
            margin-top: 0.35rem;
            margin-bottom: 0.15rem;
            padding-top: 0.35rem;
            padding-left: 0;
        }
        .cal-central-panel .cal-central-layout {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.85rem;
            width: 100%;
        }
        .cal-central-panel .cal-central-stack {
            width: 100%;
            max-width: 52rem;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            column-gap: 1.25rem;
            row-gap: 1.35rem;
            align-items: start;
            justify-items: center;
        }
        .cal-central-panel .cal-central-stats-block {
            display: contents;
        }
        .cal-central-panel .cal-stats-grid {
            display: contents;
        }
        .cal-central-panel .cal-stat {
            text-align: center;
            min-width: 0;
            width: 100%;
            padding: 0.55rem 0.5rem 0.65rem;
            border: 1px solid #c5d4ca;
            border-radius: 0.4rem;
            background: #eef5f0;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.65);
        }
        /* Error stays neutral until a weigh reading exists; then red / green by value */
        .cal-central-panel .cal-stat-error.is-nonzero {
            border-color: #e2b4b8;
            background: #f8ecee;
        }
        .cal-central-panel .cal-stat-error.is-zero {
            border-color: #a6d4b5;
            background: #e4f6ea;
        }
        .cal-central-panel .cal-stat-label {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #6c757d;
            margin-bottom: 0.3rem;
            line-height: 1.2;
            min-height: 1rem;
        }
        .cal-central-panel .cal-stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            line-height: 1.15;
        }
        .cal-central-panel .cal-stat-value .unit {
            font-size: 0.85rem;
            font-weight: 600;
            color: #6c757d;
        }
        .cal-central-panel .cal-stat-error .cal-stat-value {
            font-size: 1.4rem;
        }
        .cal-central-panel .cal-stat-error.is-nonzero .cal-stat-value {
            color: #b02a37;
        }
        .cal-central-panel .cal-stat-error.is-zero .cal-stat-value {
            color: #146c43;
        }
        /* Own grid row so stack row-gap centers it evenly between stats and slider */
        .cal-central-panel .cal-central-adj-value-row {
            grid-column: 1 / -1;
            width: 100%;
            max-width: 44rem;
            justify-self: center;
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: baseline;
            justify-content: center;
            text-align: center;
            gap: 0.45rem;
            margin: 0;
            padding: 0;
        }
        .cal-central-panel .cal-central-adj-wrap {
            grid-column: 1 / -1;
            width: 100%;
            max-width: 44rem;
            justify-self: center;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 0;
            margin: 0;
        }
        .cal-central-panel .cal-central-adj-label {
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #6c757d;
        }
        .cal-central-panel .cal-central-adj-value {
            font-size: 1.65rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, "Cascadia Mono", "Segoe UI Mono", Menlo, Consolas, monospace;
            color: #212529;
            line-height: 1.1;
        }
        .cal-central-panel .cal-central-adj-value .unit {
            font-size: 0.95rem;
            font-weight: 600;
            color: #6c757d;
            margin-left: 0.2rem;
        }
        .cal-central-panel .cal-central-adj {
            width: 100%;
            display: flex;
            flex-wrap: nowrap;
            align-items: stretch;
            gap: 0.7rem;
        }
        /* Scrollbar strip: only the drag track lives here — no number text on top. */
        .cal-central-panel .cal-central-adj-slider-cell {
            flex: 1 1 auto;
            min-width: 0;
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            column-gap: 0.55rem;
            padding: 0.45rem 0.65rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.04);
        }
        .cal-central-panel .cal-central-adj-end {
            flex: 0 0 auto;
            font-size: 0.8rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            font-family: ui-monospace, "Cascadia Mono", "Segoe UI Mono", Menlo, Consolas, monospace;
            color: #495057;
            user-select: none;
            line-height: 1;
        }
        .cal-central-panel .cal-central-adj-slider-track {
            min-width: 0;
            display: flex;
            align-items: center;
            height: 2.4rem;
        }
        .cal-central-panel .cal-central-adj-slider {
            -webkit-appearance: none;
            appearance: none;
            width: 100%;
            height: 2.4rem;
            margin: 0;
            background: transparent;
            cursor: pointer;
        }
        .cal-central-panel .cal-central-adj-slider:focus {
            outline: none;
        }
        .cal-central-panel .cal-central-adj-slider:focus-visible::-webkit-slider-thumb {
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.35);
        }
        .cal-central-panel .cal-central-adj-slider::-webkit-slider-runnable-track {
            height: 0.85rem;
            border-radius: 999px;
            background: linear-gradient(180deg, #f1f3f5 0%, #e9ecef 100%);
            border: 1px solid #ced4da;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.08);
        }
        .cal-central-panel .cal-central-adj-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 1.75rem;
            height: 1.75rem;
            margin-top: calc((0.85rem - 1.75rem) / 2);
            border-radius: 50%;
            background: #0d6efd;
            border: 2px solid #fff;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.35);
        }
        .cal-central-panel .cal-central-adj-slider::-moz-range-track {
            height: 0.85rem;
            border-radius: 999px;
            background: linear-gradient(180deg, #f1f3f5 0%, #e9ecef 100%);
            border: 1px solid #ced4da;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.08);
        }
        .cal-central-panel .cal-central-adj-slider::-moz-range-thumb {
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 50%;
            background: #0d6efd;
            border: 2px solid #fff;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.35);
        }
        .cal-central-panel .cal-central-adj-slider:disabled {
            cursor: not-allowed;
            opacity: 0.45;
        }
        .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="down"] {
            margin-right: 0.35rem !important;
        }
        .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="up"] {
            margin-left: 0.35rem !important;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj {
            gap: 0.9rem;
        }
        .cal-central-panel .cal-central-adj .btn {
            flex: 0 0 auto;
            min-width: 3.5rem;
            min-height: 3.85rem;
            font-size: 1.55rem;
            font-weight: 700;
            font-family: ui-monospace, "Cascadia Mono", "Segoe UI Mono", Menlo, Consolas, monospace;
            letter-spacing: -0.04em;
            padding-left: 0.35rem;
            padding-right: 0.35rem;
        }
        /* After base slider styles so phone width can actually override display:grid */
        @media (max-width: 767.98px) {
            .cal-central-panel .cal-central-adj-slider-cell {
                display: none !important;
            }
            .cal-central-panel .cal-central-adj-wrap {
                max-width: 100%;
                width: 100%;
            }
            .cal-central-panel .cal-central-adj {
                width: 100%;
                justify-content: stretch;
                gap: 0.4rem;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="down"],
            .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="up"] {
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            .cal-central-panel .cal-central-adj .btn {
                flex: 1 1 0;
                /* Keep the large default size; don't collapse as the row narrows */
                min-width: 3.5rem;
                padding-left: 0.15rem;
                padding-right: 0.15rem;
            }
        }
        /*
         * Calibrate layout: Sensors → scale-car track → adjustment panel.
         */
        #calibratePanel .card-body {
            display: flex;
            flex-direction: column;
        }
        #calibratePanel .card-body > .cal-instructions {
            order: 0;
        }
        #calibratePanel .card-body > .cal-sensor-row {
            order: 1;
            margin-bottom: 1rem !important;
        }
        #calibratePanel .card-body > .cal-track-wrap {
            order: 2;
            margin-top: 0.15rem;
            margin-bottom: 1.15rem;
        }
        #calibratePanel .card-body > .cal-central-controls {
            order: 3;
        }
        #calibratePanel .cal-actions-footer {
            margin-top: 0.85rem;
            margin-bottom: 0.5rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-actions-meta {
            max-width: none;
        }
        body.track-scale-accessible #calibratePanel .card-body > .cal-sensor-row {
            margin-bottom: 1.65rem !important;
        }
        body.track-scale-accessible #calibratePanel .card-body > .cal-track-wrap {
            margin-top: 0.85rem;
            margin-bottom: 1.35rem;
        }
        .scale-mode-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem 0.75rem;
            min-height: 2.5rem;
            margin-bottom: 1rem;
        }
        .test-car-select-wrap {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 0.5rem;
            min-width: 12rem;
            max-width: min(28rem, 100%);
            flex: 1 1 auto;
        }
        /* Keep toolbar height stable in Weigh mode (no layout jump into Calibrate) */
        body:not(.mode-calibrate) .test-car-select-wrap {
            visibility: hidden;
            pointer-events: none;
        }
        .test-car-select-wrap .form-label {
            margin-bottom: 0;
            font-size: 0.8rem;
            color: #6c757d;
            white-space: nowrap;
        }
        .test-car-select-wrap .form-select {
            width: auto;
            min-width: 11rem;
            max-width: 22rem;
            flex: 1 1 auto;
        }
        body.track-scale-accessible .test-car-select-wrap .form-label {
            font-size: 0.95rem;
        }
        body.track-scale-accessible .test-car-select-wrap .form-select {
            min-height: 3rem;
            font-size: 1.15rem;
        }
        body.track-scale-accessible .scale-mode-bar {
            min-height: 3.25rem;
        }
        /* Large-controls sizing for the shared calibrate layout */
        body.track-scale-accessible .cal-central-panel {
            padding: 1.25rem 1.35rem;
        }
        /* Stats use display:contents, so hide the cells themselves */
        body.track-scale-accessible .cal-central-panel .cal-stat {
            display: none !important;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-stack {
            max-width: 64rem;
            column-gap: 1.75rem;
            row-gap: 1.55rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-wrap,
        body.track-scale-accessible .cal-central-panel .cal-central-adj-value-row {
            max-width: 58rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-label {
            font-size: 0.95rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-value {
            font-size: 2.45rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-value .unit {
            font-size: 1.25rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj {
            gap: 1.05rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider-cell {
            padding: 0.85rem 1rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider-track,
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider {
            height: 4.25rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider::-webkit-slider-runnable-track,
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider::-moz-range-track {
            height: 1.4rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider::-webkit-slider-thumb {
            width: 2.75rem;
            height: 2.75rem;
            margin-top: calc((1.4rem - 2.75rem) / 2);
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-slider::-moz-range-thumb {
            width: 2.75rem;
            height: 2.75rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj-end {
            font-size: 1.15rem;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="down"] {
            margin-right: 0.7rem !important;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj > .cal-central-adj-btn[data-fine="0"][data-direction="up"] {
            margin-left: 0.7rem !important;
        }
        body.track-scale-accessible .cal-central-panel .cal-central-adj .btn {
            min-width: 5rem;
            min-height: 5.25rem;
            font-size: 2rem;
            padding-left: 0.7rem;
            padding-right: 0.7rem;
        }
        body.track-scale-accessible .sensor-card .cal-position-btn {
            font-size: 1.25rem;
            font-weight: 700;
            min-height: 6.5rem;
            padding: 1.5rem 0.85rem;
        }
        body.track-scale-accessible .sensor-card .sensor-card-title {
            font-size: 1.25rem;
        }
        body.track-scale-accessible #calSaveBtn,
        body.track-scale-accessible #calResetBtn,
        body.track-scale-accessible #calResetAdjBtn {
            font-size: 1.2rem;
            min-height: 3.1rem;
            padding: 0.7rem 1.15rem;
        }
        body.track-scale-accessible .scale-led {
            width: 20px;
            height: 20px;
        }
        body.track-scale-accessible .scale-led-label:not(.ready-label):not(.oos-label):not(.fail-label) {
            font-size: 0.85rem;
        }
        body.track-scale-accessible .car-panel-header #carMarks {
            font-size: 2.1rem;
        }
        body.track-scale-accessible .car-panel-header #carMeta {
            font-size: 1.25rem;
        }
        body.track-scale-accessible .stat-box {
            padding: 0.75rem 0.85rem;
        }
        body.track-scale-accessible .stat-box .stat-label {
            font-size: 0.85rem;
        }
        body.track-scale-accessible .stat-box .stat-value {
            font-size: 1.35rem;
        }
        body.track-scale-accessible .card-header {
            font-size: 1.2rem;
            padding: 0.85rem 1.1rem;
        }
        body.track-scale-accessible .card-body {
            padding: 1.15rem;
        }
        body.track-scale-accessible .list-group-item.car-list-item {
            padding: 1rem 1.15rem;
            min-height: 3.5rem;
            font-size: 1.2rem;
        }
        body.track-scale-accessible .car-list-marks {
            font-size: 1.3rem;
            font-weight: 600;
        }
        body.track-scale-accessible .car-list-position {
            font-size: 1rem;
        }
        body.track-scale-accessible .status-badge {
            font-size: 0.95rem;
            padding: 0.35rem 0.65rem;
        }
        body.track-scale-accessible .badge {
            font-size: 1rem;
            padding: 0.45em 0.7em;
        }
        body.track-scale-accessible .order-empty-msg,
        body.track-scale-accessible .alert {
            font-size: 1.1rem;
        }
        body.track-scale-accessible .sensor-card {
            padding: 1rem;
        }
        body.track-scale-accessible .sensor-card strong {
            font-size: 1.25rem;
        }
        body.track-scale-accessible .cal-track-car img {
            max-height: 5.5rem;
        }
        body.track-scale-accessible #weighResult,
        body.track-scale-accessible #assignResult {
            font-size: 1.15rem !important;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-success mb-4">
    <div class="container-fluid gap-2 flex-wrap">
        <span class="navbar-brand mb-0"><i class="bi bi-speedometer2"></i> Track Scale — <?= htmlspecialchars($track_scale_ui['siteLabel']) ?></span>
        <div class="d-flex align-items-center gap-3 ms-auto flex-wrap">
            <div class="access-toggle-wrap form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="accessibleUiToggle"
                       aria-describedby="accessibleUiHint">
                <label class="form-check-label" for="accessibleUiToggle">
                    Large controls
                </label>
            </div>
            <a href="operations.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left"></i> Operations
            </a>
        </div>
    </div>
</nav>
<span id="accessibleUiHint" class="visually-hidden">
    Larger text and buttons for the weigh and calibrate screens. Preference is saved in this browser.
</span>

<div class="container" style="max-width: 960px;">
    <div class="scale-mode-bar">
        <div class="test-car-select-wrap" id="testCarSelectWrap">
            <label class="form-label" for="testCarSelect">Scale test car</label>
            <select class="form-select form-select-sm" id="testCarSelect" aria-label="Choose scale test car from roster">
                <?php if ($track_scale_test_car_options === []): ?>
                    <option value="">No MS scale test cars</option>
                <?php else: ?>
                    <?php foreach ($track_scale_test_car_options as $opt):
                        $marks = (string) ($opt['reporting_marks'] ?? '');
                        $type = trim((string) ($opt['car_type'] ?? ''));
                        $tare = $opt['tare_tons'] ?? null;
                        $label = $marks;
                        if ($type !== '') {
                            $label .= ' — ' . $type;
                        }
                        if ($tare !== null && $tare !== '') {
                            $label .= ' · ' . number_format((float) $tare, 2) . ' t';
                        }
                        ?>
                        <option value="<?= htmlspecialchars($marks) ?>"<?= strtoupper($marks) === $track_scale_test_car_marks ? ' selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div class="btn-group" role="group" aria-label="Scale mode">
            <input type="radio" class="btn-check" name="scaleMode" id="modeWeigh" autocomplete="off" checked>
            <label class="btn btn-outline-success" for="modeWeigh"><i class="bi bi-truck"></i> Weigh</label>
            <input type="radio" class="btn-check" name="scaleMode" id="modeCalibrate" autocomplete="off">
            <label class="btn btn-outline-success" for="modeCalibrate"><i class="bi bi-sliders"></i> Calibrate</label>
        </div>
    </div>

    <!-- Weigh mode -->
    <div id="weighPanel" class="mode-panel active">
        <div class="row g-3 mb-2 scale-top-row">
            <div class="col-md-6">
                <div class="scale-display h-100">
                    <div class="label">Adjusted Gross Weight</div>
                    <div><span class="value" id="displayGross">0.00</span> <span class="unit">tons</span></div>
                    <div class="small mt-2" style="color:#6bdc6b;" id="sensorBreakdown"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="scale-display h-100 position-relative" id="netDisplayPanel">
                    <div class="scale-led-wrap">
                        <div class="scale-led" id="weightLed" data-state="off" title="Tolerance indicator"></div>
                        <span class="scale-led-label d-none" id="weightLedLabel"></span>
                    </div>
                    <div id="netReadingBlock" class="d-none">
                        <div class="label">Net load</div>
                        <div><span class="value" id="displayNet">0.00</span> <span class="unit">tons</span></div>
                    </div>
                </div>
            </div>
        </div>
        <div id="carPanel" class="d-none mb-3">
            <div class="car-panel-header">
                <h4 id="carMarks" class="mb-1"></h4>
                <p class="text-muted mb-0">
                    <span id="carMeta"></span>
                </p>
            </div>
            <div class="car-panel-body">
                <div class="car-photo-col">
                    <div class="car-photo" id="carPhotoWrap">
                        <span class="text-muted" id="carPhotoPlaceholder">No photo</span>
                        <img id="carPhoto" alt="" class="d-none">
                    </div>
                </div>
                <div class="car-stats-col">
                    <div class="stat-grid">
                        <div class="stat-box">
                            <div class="stat-label">Tare (LT WT)</div>
                            <div class="stat-value" id="statTare">—</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Load limit (LD LMT)</div>
                            <div class="stat-value" id="statLoadLimit">—</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Capacity (CAPY)</div>
                            <div class="stat-value" id="statCapy">—</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Target net</div>
                            <div class="stat-value" id="statTarget">—</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Status</div>
                            <div class="stat-value" id="statStatus">—</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Location</div>
                            <div class="stat-value" id="statLocation">—</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <button type="button" class="btn btn-primary btn-lg" id="weighBtn" disabled>
                            <i class="bi bi-speedometer"></i> Weigh Car
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-lg d-none" id="prevCarBtn" aria-label="Previous car" title="Previous car">
                            <i class="bi bi-skip-backward"></i>
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-lg d-none" id="nextCarBtn" aria-label="Next car" title="Next car">
                            <i class="bi bi-skip-forward"></i>
                        </button>
                    </div>
                    <div id="weighResult" class="small text-muted">Select a car from the list, then weigh.</div>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center mt-2" id="weighNavActions">
                    <button type="button" class="btn btn-outline-danger btn-lg d-none" id="reassignBtn">
                        <i class="bi bi-arrow-left-right"></i> Reassign Order
                    </button>
                </div>
            </div>
        </div>

        <div id="orderSection" class="card mb-3 d-none">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-box-seam"></i> Assign Car Order</span>
                <span id="routingBadge" class="badge"></span>
            </div>
            <div class="card-body">
                <div id="reassignNote" class="alert alert-warning py-2 px-3 small d-none mb-3">
                    <i class="bi bi-info-circle"></i>
                    Rerouting this car — the current order
                    <strong id="reassignPriorWaybill">—</strong>
                    will return to unfilled when you assign a new order.
                </div>
                <div class="mb-3 d-none" id="orderSelectWrap">
                    <label for="orderSelect" class="form-label">Open coke orders</label>
                    <select class="form-select order-select" id="orderSelect">
                        <option value="">— Select order —</option>
                    </select>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2" id="orderActionRow">
                    <div class="d-flex flex-wrap gap-2" id="generateButtons"></div>
                    <button type="button" class="btn btn-success d-none" id="assignBtn" disabled>
                        <i class="bi bi-check2-circle"></i> <span id="assignBtnLabel">Assign to Order</span>
                    </button>
                </div>
                <div id="inTrainAssignNote" class="alert alert-info py-2 px-3 small d-none mb-3 in-train-assign-note"></div>
                <div id="assignResult" class="mt-2 small"></div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-list-ul"></i> Cars at Scale / Inbound Train</span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <select class="form-select form-select-sm" id="trainFilter" style="width: auto; min-width: 10rem;" aria-label="Filter by train">
                        <option value="">All cars</option>
                        <option value="scale">At scale only</option>
                    </select>
                    <button type="button" class="btn btn-outline-success btn-sm" id="refreshCarsBtn">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div id="carsListEmpty" class="p-3 text-muted d-none">No cars at the scale or in a train right now.</div>
                <div id="carsListError" class="alert alert-danger m-3 d-none" role="alert"></div>
                <div class="list-group list-group-flush" id="carsList"></div>
            </div>
        </div>
    </div>

    <!-- Calibrate mode -->
    <div id="calibratePanel" class="mode-panel">
        <div class="sensor-average mb-2 scale-top-row">
            <div class="scale-display h-100 position-relative" id="calAverageDisplayPanel">
                <div class="scale-led-wrap">
                    <div class="scale-led" id="calAverageLed" data-state="off" title="Calibration alignment"></div>
                    <span class="scale-led-label d-none" id="calAverageLedLabel"></span>
                </div>
                <div class="label">Adjusted weight</div>
                <div><span class="value" id="calAverageDisplay">—</span> <span class="unit">tons</span></div>
                <div class="small mt-2" style="color:#6bdc6b;">
                    Average adjustment: <span id="calAverageAdjustment">—</span> t
                    <span class="text-muted" id="calAverageMeta"></span>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <p class="visually-hidden cal-instructions">
                    Scale test car <strong id="calTestCarMarks">COST1</strong>,
                    <strong id="calTestCarLbs">80,000</strong> lbs /
                    <span id="calTestCarTons">40.00</span> t
                </p>
                <div class="cal-track-wrap" id="calTrackWrap">
                    <div class="cal-track-rail">
                        <div class="cal-track-car d-none" id="calTrackCar">
                            <img id="calTestCarPhoto" alt="Scale test car" class="d-none">
                            <span class="cal-track-car-placeholder d-none" id="calTestCarPhotoPlaceholder">Test car</span>
                        </div>
                    </div>
                </div>
                <div class="row g-2 g-md-3 mb-3 cal-sensor-row">
                    <div class="col-4">
                        <div class="sensor-card" id="sensorCard-left">
                            <div class="mb-2 sensor-card-heading"><strong class="sensor-card-title"><span class="sensor-title-full">LEFT</span><span class="sensor-title-short">LEFT</span></strong></div>
                            <div class="scale-display mb-2">
                                <div class="label">Reading</div>
                                <div><span class="value" id="sensorDisplay-left">—</span> <span class="unit">t</span></div>
                            </div>
                            <div class="small mb-2 sensor-error-line">Error: <span id="sensorError-left">—</span> t</div>
                            <div class="small text-muted mb-2 sensor-adj-line" id="sensorAdj-left">adj 0.00</div>
                            <button type="button" class="btn btn-outline-primary w-100 mb-2 cal-position-btn" data-sensor="left">
                                <i class="bi bi-truck"></i> Place Car Here
                            </button>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="sensor-card" id="sensorCard-center">
                            <div class="mb-2 sensor-card-heading"><strong class="sensor-card-title"><span class="sensor-title-full">CENTER</span><span class="sensor-title-short">CENTER</span></strong></div>
                            <div class="scale-display mb-2">
                                <div class="label">Reading</div>
                                <div><span class="value" id="sensorDisplay-center">—</span> <span class="unit">t</span></div>
                            </div>
                            <div class="small mb-2 sensor-error-line">Error: <span id="sensorError-center">—</span> t</div>
                            <div class="small text-muted mb-2 sensor-adj-line" id="sensorAdj-center">adj 0.00</div>
                            <button type="button" class="btn btn-outline-primary w-100 mb-2 cal-position-btn" data-sensor="center">
                                <i class="bi bi-truck"></i> Place Car Here
                            </button>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="sensor-card" id="sensorCard-right">
                            <div class="mb-2 sensor-card-heading"><strong class="sensor-card-title"><span class="sensor-title-full">RIGHT</span><span class="sensor-title-short">RIGHT</span></strong></div>
                            <div class="scale-display mb-2">
                                <div class="label">Reading</div>
                                <div><span class="value" id="sensorDisplay-right">—</span> <span class="unit">t</span></div>
                            </div>
                            <div class="small mb-2 sensor-error-line">Error: <span id="sensorError-right">—</span> t</div>
                            <div class="small text-muted mb-2 sensor-adj-line" id="sensorAdj-right">adj 0.00</div>
                            <button type="button" class="btn btn-outline-primary w-100 mb-2 cal-position-btn" data-sensor="right">
                                <i class="bi bi-truck"></i> Place Car Here
                            </button>
                        </div>
                    </div>
                </div>

                <div class="cal-central-controls">
                    <div class="cal-central-panel">
                        <div class="cal-central-layout">
                            <div class="cal-central-stack">
                                <div class="cal-central-stats-block">
                                    <div class="cal-stats-grid">
                                        <div class="cal-stat">
                                            <div class="cal-stat-label">Sensor</div>
                                            <div class="cal-stat-value text-uppercase" id="calCentralSensor">—</div>
                                        </div>
                                        <div class="cal-stat">
                                            <div class="cal-stat-label">Reading</div>
                                            <div class="cal-stat-value">
                                                <span id="calCentralReading">—</span> <span class="unit">t</span>
                                            </div>
                                        </div>
                                        <div class="cal-stat cal-stat-error">
                                            <div class="cal-stat-label">Error</div>
                                            <div class="cal-stat-value">
                                                <span id="calCentralError">—</span> <span class="unit">t</span>
                                            </div>
                                        </div>
                                        <div class="cal-stat">
                                            <div class="cal-stat-label">Expected</div>
                                            <div class="cal-stat-value">
                                                <span id="calCentralExpected">—</span> <span class="unit">t</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="cal-central-adj-value-row" aria-live="polite">
                                    <span class="cal-central-adj-label">Adjustment</span>
                                    <span class="cal-central-adj-value">
                                        <span id="calCentralAdjValue">0.00</span>
                                        <span class="unit">t</span>
                                    </span>
                                </div>
                                <div class="cal-central-adj-wrap">
                                    <div class="cal-central-adj">
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjMinBtn" data-step="1" data-direction="down" disabled aria-label="Adjust down 1.00">|&lt;&lt;</button>
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjCoarseDown" data-direction="down" data-fine="0" disabled aria-label="Adjust down 0.10">&lt;&lt;</button>
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjFineDown" data-direction="down" data-fine="1" disabled aria-label="Adjust down 0.01">&lt;</button>
                                        <div class="cal-central-adj-slider-cell" title="Drag to adjust">
                                            <span class="cal-central-adj-end" id="calCentralAdjMin">-2.50</span>
                                            <div class="cal-central-adj-slider-track">
                                                <input type="range" class="cal-central-adj-slider" id="calCentralAdjSlider" min="-2.5" max="2.5" step="0.01" value="0" disabled aria-label="Adjustment scrollbar tons">
                                            </div>
                                            <span class="cal-central-adj-end" id="calCentralAdjMax">2.50</span>
                                        </div>
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjFineUp" data-direction="up" data-fine="1" disabled aria-label="Adjust up 0.01">&gt;</button>
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjCoarseUp" data-direction="up" data-fine="0" disabled aria-label="Adjust up 0.10">&gt;&gt;</button>
                                        <button type="button" class="btn btn-outline-secondary cal-central-adj-btn" id="calCentralAdjMaxBtn" data-step="1" data-direction="up" disabled aria-label="Adjust up 1.00">&gt;&gt;|</button>
                                    </div>
                                    <input type="hidden" id="calCentralAdjInput" value="0.00">
                                </div>
                                <div class="cal-actions-meta">
                                    <span id="calCalibrationMeta" class="small text-danger">Last calibrated: —</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="cal-actions-footer">
            <div class="d-flex flex-wrap gap-2 align-items-center cal-actions-row">
                <button type="button" class="btn btn-success btn-sm" id="calSaveBtn" disabled>
                    <i class="bi bi-lock"></i> Save calibration
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="calResetBtn">Reset calibration</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="calResetAdjBtn" disabled>Reset adjustment</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
const CONFIG = <?php echo json_encode($config, JSON_UNESCAPED_SLASHES); ?>;
const TRACK_SCALE_UI = <?php echo json_encode($track_scale_ui, JSON_UNESCAPED_SLASHES); ?>;
const PRECISION = CONFIG.precision ?? 2;
const ACCESSIBLE_UI_KEY = 'track_scale_accessible_ui';
const SCALE_MODE_KEY = 'track_scale_mode';
let selectedTestCarMarks = <?php echo json_encode($track_scale_test_car_marks, JSON_UNESCAPED_SLASHES); ?>;

function applyAccessibleUi(enabled) {
    document.body.classList.toggle('track-scale-accessible', !!enabled);
    const toggle = document.getElementById('accessibleUiToggle');
    if (toggle && toggle.checked !== !!enabled) {
        toggle.checked = !!enabled;
    }
    try {
        localStorage.setItem(ACCESSIBLE_UI_KEY, enabled ? '1' : '0');
    } catch (e) {
        /* ignore quota / private mode */
    }
}

(function initAccessibleUiPreference() {
    let enabled = false;
    try {
        enabled = localStorage.getItem(ACCESSIBLE_UI_KEY) === '1';
    } catch (e) {
        enabled = false;
    }
    applyAccessibleUi(enabled);
    const toggle = document.getElementById('accessibleUiToggle');
    if (toggle) {
        toggle.addEventListener('change', () => applyAccessibleUi(toggle.checked));
    }
})();

let currentCar = null;
let currentProfile = null;
let currentReading = null;
let currentRouting = null;
let currentOpenOrders = [];
let selectedCarId = null;
let scaleInService = true;
let pendingNextCar = null;
let pendingPrevCar = null;
let manualReassignMode = false;
/** In-memory weigh/assign UI per car for this page load only. */
const weighSessionCache = new Map();
let restoringWeighSession = false;
const SENSOR_POSITIONS = ['left', 'center', 'right'];

function snapshotWeighSession(carId) {
    if (restoringWeighSession || !carId || !currentReading) return;
    const orderSection = document.getElementById('orderSection');
    const select = document.getElementById('orderSelect');
    const assignResult = document.getElementById('assignResult');
    const weighResult = document.getElementById('weighResult');
    const led = document.getElementById('weightLed');
    const ledLabel = document.getElementById('weightLedLabel');
    weighSessionCache.set(String(carId), {
        reading: currentReading,
        routing: currentRouting,
        nextCar: pendingNextCar,
        prevCar: pendingPrevCar,
        manualReassignMode: !!manualReassignMode,
        orderVisible: !!(orderSection && !orderSection.classList.contains('d-none')),
        selectedWaybill: select ? String(select.value || '') : '',
        assignResultHtml: assignResult ? assignResult.innerHTML : '',
        weighResultHtml: weighResult ? weighResult.innerHTML : '',
        ledState: led ? (led.dataset.state || 'off') : 'off',
        ledLabel: ledLabel ? ledLabel.textContent : '',
        showReassignBtn: !!(document.getElementById('reassignBtn')
            && !document.getElementById('reassignBtn').classList.contains('d-none')),
    });
}

function clearWeighSession(carId) {
    if (carId == null) return;
    weighSessionCache.delete(String(carId));
}

function applyReadingDisplays(reading) {
    if (!reading) return;
    document.getElementById('displayGross').textContent = fmt(reading.gross_tons);
    document.getElementById('displayNet').textContent = fmt(reading.net_tons);
    setNetReadingVisible(true);
    const breakdownEl = document.getElementById('sensorBreakdown');
    if (breakdownEl) {
        if (reading.sensor_readings && reading.sensor_readings.length) {
            breakdownEl.textContent = reading.sensor_readings
                .map(s => s.position.charAt(0).toUpperCase() + ': ' + fmt(s.display_tons))
                .join(' · ');
        } else {
            breakdownEl.textContent = '';
        }
    }
}

const SCALE_SETTLE_MS = 3000;
/** Calibration place-settle is shorter (smaller car / tighter UI feedback). */
const SCALE_SETTLE_CAL_MS = 2000;
/** Changes/sec at the start of settle (mild flicker — not too top-heavy). */
const SCALE_SETTLE_RATE_START = 5;
/** Changes/sec near the end (slow stabilizing ticks). */
const SCALE_SETTLE_RATE_END = 1;
/** Small up/down band around the final weight (tons). */
const SCALE_SETTLE_AMP_TONS = 0.35;
let scaleSettleGen = 0;
let scaleSettleTimer = null;
let calPlaceBusy = false;
let lastCalibrationSnapshot = null;

function abortScaleSettle() {
    scaleSettleGen += 1;
    if (scaleSettleTimer) {
        clearTimeout(scaleSettleTimer);
        scaleSettleTimer = null;
    }
    document.querySelectorAll('.scale-display.is-settling').forEach(el => {
        el.classList.remove('is-settling');
    });
}

/** Ease-in quad: 0 → 1 — gentler than cubic so early ticks aren’t too dense. */
function settleEaseInQuad(t) {
    const x = Math.min(1, Math.max(0, t));
    return x * x;
}

/**
 * Natural stabilize curve: dense ticks early, sparse at the end.
 * Integrates rate from rateStart → rateEnd along an ease-in over durationMs.
 */
function buildSettleStepDelays(options) {
    options = options || {};
    const durationMs = Math.max(200, Number(options.durationMs) || SCALE_SETTLE_MS);
    const rateStart = Math.max(1, Number(options.rateStart) || SCALE_SETTLE_RATE_START);
    const rateEnd = Math.max(0.5, Number(options.rateEnd) || SCALE_SETTLE_RATE_END);
    const durationSec = durationMs / 1000;
    const delays = [];
    let tSec = 0;
    // Cap steps so a bad curve can't spawn hundreds of updates.
    const maxSteps = 40;
    while (tSec < durationSec - 0.001 && delays.length < maxSteps) {
        const u = tSec / durationSec;
        const eased = settleEaseInQuad(u);
        const rate = rateStart + (rateEnd - rateStart) * eased;
        const dt = 1 / Math.max(0.5, rate);
        const remaining = durationSec - tSec;
        const step = Math.min(dt, remaining);
        delays.push(step * 1000);
        tSec += step;
    }
    return delays;
}

function paintWeighSettleDisplays(gross, net, sensors) {
    const grossEl = document.getElementById('displayGross');
    const netEl = document.getElementById('displayNet');
    if (grossEl) grossEl.textContent = fmt(gross);
    if (netEl) netEl.textContent = fmt(net);
    const breakdownEl = document.getElementById('sensorBreakdown');
    if (!breakdownEl) return;
    if (sensors && sensors.length) {
        breakdownEl.textContent = sensors
            .map(s => s.position.charAt(0).toUpperCase() + ': ' + fmt(s.display_tons))
            .join(' · ');
    } else {
        breakdownEl.textContent = '';
    }
}

function paintCalSettleDisplays(gross, sensors) {
    const avgEl = document.getElementById('calAverageDisplay');
    if (avgEl) avgEl.textContent = fmt(gross);
    (sensors || []).forEach(s => {
        const pos = s.position;
        const el = document.getElementById('sensorDisplay-' + pos);
        if (el) el.textContent = fmt(s.display_tons);
    });
    const central = document.getElementById('calCentralReading');
    if (central && sensors && sensors.length) {
        const active = sensors.find(s => {
            const card = document.getElementById('sensorCard-' + s.position);
            return card && card.classList.contains('car-at-position');
        }) || sensors[0];
        if (active) central.textContent = fmt(active.display_tons);
    }
}

/**
 * Start near the target weight, then discrete up/down steps, then lock.
 * Returns false if aborted (another settle / car change).
 */
function runScaleSettle(options) {
    options = options || {};
    abortScaleSettle();
    const gen = scaleSettleGen;
    const durationMs = Number(options.durationMs) > 0 ? Number(options.durationMs) : SCALE_SETTLE_MS;
    const rateStart = Number(options.rateStart) > 0 ? Number(options.rateStart) : SCALE_SETTLE_RATE_START;
    const rateEnd = Number(options.rateEnd) > 0 ? Number(options.rateEnd) : SCALE_SETTLE_RATE_END;
    const stepDelays = buildSettleStepDelays({ durationMs, rateStart, rateEnd });
    const bounceCount = stepDelays.length;
    const targetGross = Number(options.targetGross);
    const targetNet = Number(options.targetNet);
    const safeGross = Number.isFinite(targetGross) ? targetGross : 0;
    const safeNet = Number.isFinite(targetNet) ? targetNet : 0;
    const sensorTargets = Array.isArray(options.sensorTargets) ? options.sensorTargets : null;
    const amp0 = Number.isFinite(Number(options.amplitudeTons))
        ? Math.max(0.05, Number(options.amplitudeTons))
        : SCALE_SETTLE_AMP_TONS;
    const paint = typeof options.paint === 'function' ? options.paint : paintWeighSettleDisplays;
    const settleSelector = options.settleSelector || '#weighPanel .scale-top-row .scale-display';
    // Alternate above/below the target; exact target after the bounce series.
    const signs = [];
    let sign = Math.random() < 0.5 ? -1 : 1;
    for (let i = 0; i < bounceCount; i++) {
        signs.push(sign);
        sign *= -1;
    }

    if (options.showNet !== false && options.mode !== 'calibrate') {
        setNetReadingVisible(true);
    }
    document.querySelectorAll(settleSelector).forEach(el => {
        el.classList.add('is-settling');
    });

    return new Promise(resolve => {
        function finish(ok) {
            scaleSettleTimer = null;
            document.querySelectorAll(settleSelector).forEach(el => {
                el.classList.remove('is-settling');
            });
            resolve(ok);
        }

        function showStep(index) {
            if (gen !== scaleSettleGen) {
                finish(false);
                return;
            }
            if (index >= bounceCount) {
                paint(safeGross, safeNet, sensorTargets);
                finish(gen === scaleSettleGen);
                return;
            }
            const t = bounceCount <= 1 ? 1 : (index / (bounceCount - 1));
            // Amplitude eases out with the same curve so wobble dies as ticks slow.
            const damp = 1 - settleEaseInQuad(t);
            const offset = signs[index] * amp0 * Math.max(0.08, damp);
            const gross = Math.max(0, safeGross + offset);
            const net = Math.max(0, safeNet + offset * 0.9);
            let sensors = null;
            if (sensorTargets && sensorTargets.length) {
                sensors = sensorTargets.map((s, idx) => {
                    const target = Number(s.display_tons);
                    const safe = Number.isFinite(target) ? target : 0;
                    const sOff = signs[index] * amp0 * damp * (0.75 + (idx % 3) * 0.08);
                    return {
                        position: s.position,
                        display_tons: Math.max(0, safe + sOff),
                    };
                });
            }
            paint(gross, net, sensors);
            const delay = stepDelays[index] != null ? stepDelays[index] : 100;
            scaleSettleTimer = setTimeout(() => showStep(index + 1), delay);
        }

        showStep(0);
    });
}

async function runCalibrationPlaceSettle(calibration) {
    if (!calibration) return true;
    const avg = calibration.average && calibration.average.display_tons != null
        ? Number(calibration.average.display_tons)
        : Number(calibration.expected_tons) || 0;
    const sensors = (calibration.sensors || [])
        .filter(s => s && s.has_reading && s.display_tons != null)
        .map(s => ({ position: s.position, display_tons: Number(s.display_tons) }));
    setCalAverageLed('weighing', 'WEIGHING...');
    return runScaleSettle({
        mode: 'calibrate',
        targetGross: avg,
        targetNet: avg,
        sensorTargets: sensors,
        amplitudeTons: 0.35,
        durationMs: SCALE_SETTLE_CAL_MS,
        rateStart: SCALE_SETTLE_RATE_START,
        rateEnd: SCALE_SETTLE_RATE_END,
        settleSelector: '#calibratePanel .scale-display',
        paint: (gross, _net, stepSensors) => paintCalSettleDisplays(gross, stepSensors),
    });
}

async function restoreWeighSession(carId) {
    const snap = weighSessionCache.get(String(carId));
    if (!snap || !snap.reading || !currentCar) return false;

    restoringWeighSession = true;
    try {
        currentReading = snap.reading;
        currentRouting = snap.routing;
        manualReassignMode = !!snap.manualReassignMode;

        applyReadingDisplays(snap.reading);

        const resultEl = document.getElementById('weighResult');
        if (resultEl && snap.weighResultHtml) {
            resultEl.innerHTML = snap.weighResultHtml;
        }

        if (snap.reading.test_car_weigh || snap.reading.unloaded_weigh) {
            setWeightLed('off');
        } else if (snap.ledState) {
            setWeightLed(snap.ledState, snap.ledLabel || '');
        } else {
            const inTol = snap.reading.in_tolerance;
            setWeightLed(inTol ? 'ok' : 'fail', inTol ? 'PASS' : weighFailureLedLabel(snap.reading));
        }

        if (currentCar.weigh_source === 'in_train') {
            showTrainNavButtons(snap.prevCar, snap.nextCar, { keepVisible: true });
        } else {
            hidePrevCarButton();
            hideNextCarButton();
        }

        if (snap.showReassignBtn && shouldOfferReassignButton(currentCar, snap.reading)) {
            showReassignButton();
        } else {
            hideReassignButton();
        }

        if (snap.orderVisible) {
            await loadOrders(snap.routing || 'outbound', { forceReassign: snap.manualReassignMode });
            const select = document.getElementById('orderSelect');
            if (select && snap.selectedWaybill) {
                const hasOpt = [...select.options].some(o => o.value === snap.selectedWaybill);
                if (hasOpt) select.value = snap.selectedWaybill;
            }
            const assignResult = document.getElementById('assignResult');
            if (assignResult && snap.assignResultHtml) {
                assignResult.innerHTML = snap.assignResultHtml;
            }
            updateAssignBtnState();
        }
    } finally {
        restoringWeighSession = false;
    }

    snapshotWeighSession(carId);
    return true;
}

function hideReassignButton() {
    manualReassignMode = false;
    const btn = document.getElementById('reassignBtn');
    if (!btn) return;
    btn.classList.add('d-none');
    btn.disabled = true;
    const note = document.getElementById('reassignNote');
    if (note) note.classList.add('d-none');
}

function showReassignButton() {
    const btn = document.getElementById('reassignBtn');
    if (!btn) return;
    btn.classList.remove('d-none');
    btn.disabled = false;
}

function hideWeighActionButtons() {
    hidePrevCarButton();
    hideNextCarButton();
    hideReassignButton();
}

function hidePrevCarButton() {
    pendingPrevCar = null;
    const btn = document.getElementById('prevCarBtn');
    if (!btn) return;
    btn.classList.add('d-none');
    btn.disabled = true;
}

function showPrevCarButton(prevCar, options) {
    const btn = document.getElementById('prevCarBtn');
    if (!btn) return;
    const keepVisible = !!(options && options.keepVisible);
    if (!prevCar || !prevCar.id) {
        pendingPrevCar = null;
        if (keepVisible) {
            btn.classList.remove('d-none');
            btn.disabled = true;
            return;
        }
        hidePrevCarButton();
        return;
    }
    pendingPrevCar = prevCar;
    btn.classList.remove('d-none');
    btn.disabled = false;
}

function hideNextCarButton() {
    pendingNextCar = null;
    const btn = document.getElementById('nextCarBtn');
    if (!btn) return;
    btn.classList.add('d-none');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-skip-forward"></i>';
}

function showNextCarButton(nextCar, options) {
    const btn = document.getElementById('nextCarBtn');
    if (!btn) return;
    const keepVisible = !!(options && options.keepVisible);
    if (!nextCar || !nextCar.id) {
        pendingNextCar = null;
        if (keepVisible) {
            btn.classList.remove('d-none');
            btn.disabled = true;
            return;
        }
        hideNextCarButton();
        return;
    }
    pendingNextCar = nextCar;
    btn.classList.remove('d-none');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-skip-forward"></i>';
}

function showTrainNavButtons(prevCar, nextCar, options) {
    const keepVisible = !!(options && options.keepVisible);
    showPrevCarButton(prevCar, { keepVisible });
    showNextCarButton(nextCar, { keepVisible });
}

function applyTrainNavFromResponse(data) {
    if (!data || !data.car || data.car.weigh_source !== 'in_train') {
        hidePrevCarButton();
        hideNextCarButton();
        return;
    }
    showTrainNavButtons(data.prev_car, data.next_car, { keepVisible: true });
}

function getTrainFilterValue() {
    const select = document.getElementById('trainFilter');
    return select ? select.value : '';
}

function populateTrainFilter(trains, selectedValue) {
    const select = document.getElementById('trainFilter');
    if (!select) return;

    const current = selectedValue !== undefined ? selectedValue : select.value;
    select.innerHTML = '';
    [
        { value: '', label: 'All cars' },
        { value: 'scale', label: 'At scale only' },
    ].forEach(option => {
        const opt = document.createElement('option');
        opt.value = option.value;
        opt.textContent = option.label;
        select.appendChild(opt);
    });
    (trains || []).forEach(train => {
        const opt = document.createElement('option');
        opt.value = String(train.id);
        opt.textContent = 'Train ' + train.name;
        select.appendChild(opt);
    });
    if ([...select.options].some(opt => opt.value === current)) {
        select.value = current;
    }
}

function carListPositionLabel(car) {
    if (car.weigh_source === 'in_train' && car.position) {
        return `#${car.position}`;
    }
    if (car.weigh_source === 'at_scale' && car.position) {
        return `#${car.position}`;
    }
    return '';
}

function statusBadgeClass(status) {
    const s = (status || '').toLowerCase();
    if (s === 'loaded') return 'bg-success text-white';
    if (s === 'loading') return 'bg-info text-white';
    if (s === 'unloading') return 'bg-primary text-white';
    if (s === 'empty') return 'bg-warning text-dark';
    if (s === 'ordered') return 'bg-secondary text-white';
    return 'bg-light text-dark';
}

function displayStatus(status, displayStatusOverride) {
    if (displayStatusOverride) return displayStatusOverride;
    return status || '?';
}

function carNeedsAssignment(car) {
    if (!car || car.tare_only === true) return false;
    if (car.needs_assignment === true) return true;
    if (car.needs_assignment === false) return false;
    const s = (car.status || '').toLowerCase();
    if (s === 'unloading') return true;
    return !car.has_active_order;
}

function carHasFinalUnloadAssignment(car) {
    if (!car || !car.has_active_order || !car.active_unloading_location) return false;
    return car.active_unloading_location.toUpperCase() !== (TRACK_SCALE_UI.scaleLocation || '').toUpperCase();
}

/** Already on a coke reload order returning to Shenango — assign panel stays closed unless Reassign. */
function carAssignedToShenCokeReload(car) {
    if (!car || !car.has_active_order || carNeedsAssignment(car)) return false;
    const unload = String(car.active_unloading_location || '').toUpperCase();
    const shipment = String(car.active_shipment_code || '').toUpperCase();
    if (unload === 'SHEN-COKE' || unload.indexOf('SHEN-COKE') >= 0) return true;
    if (shipment.indexOf('RELOAD') >= 0 && shipment.indexOf('SHEN') >= 0) return true;
    return false;
}

function shouldOfferReassignButton(car, reading) {
    if (!car || !reading || reading.unloaded_weigh || reading.test_car_weigh) return false;
    if (car.allows_scale_reassign !== true) return false;
    if (!car.has_active_order || carNeedsAssignment(car)) return false;
    // Fail weigh but already routed back to SHEN-COKE — offer manual reassign only.
    if (!reading.in_tolerance) {
        return carAssignedToShenCokeReload(car);
    }
    if (shouldShowAssignAfterWeigh(car, reading)) return false;
    return true;
}

function shouldShowAssignAfterWeigh(car, reading) {
    if (!car || !reading || reading.unloaded_weigh || reading.test_car_weigh) return false;
    if (!reading.in_tolerance) {
        // Keep assign panel closed when the car is already going back to SHEN-COKE.
        if (carAssignedToShenCokeReload(car)) return false;
        return carNeedsAssignment(car) || car.allows_scale_reassign === true;
    }
    if (carHasFinalUnloadAssignment(car)) return false;
    return carNeedsAssignment(car);
}

function hideOrderSection() {
    manualReassignMode = false;
    currentRouting = null;
    currentOpenOrders = [];
    document.getElementById('orderSection').classList.add('d-none');
    document.getElementById('assignResult').textContent = '';

    const selectWrap = document.getElementById('orderSelectWrap');
    if (selectWrap) selectWrap.classList.add('d-none');
    const select = document.getElementById('orderSelect');
    if (select) select.innerHTML = '';

    const genWrap = document.getElementById('generateButtons');
    if (genWrap) genWrap.innerHTML = '';

    const assignBtn = document.getElementById('assignBtn');
    if (assignBtn) {
        assignBtn.disabled = true;
        assignBtn.classList.add('d-none');
        assignBtn.classList.remove('btn-danger');
        assignBtn.classList.add('btn-success');
        const label = document.getElementById('assignBtnLabel');
        if (label) label.textContent = 'Assign to Order';
        const icon = assignBtn.querySelector('i');
        if (icon) icon.className = 'bi bi-check2-circle';
    }

    const reassignNote = document.getElementById('reassignNote');
    if (reassignNote) {
        reassignNote.classList.add('d-none');
    }
    const inTrainNote = document.getElementById('inTrainAssignNote');
    if (inTrainNote) {
        inTrainNote.classList.add('d-none');
        inTrainNote.textContent = '';
    }
}

function getSelectedOpenOrder() {
    const select = document.getElementById('orderSelect');
    if (!select || !select.value) {
        return null;
    }
    return currentOpenOrders.find(order => order.waybill_number === select.value) || null;
}

function updateInTrainAssignNote() {
    const inTrainNote = document.getElementById('inTrainAssignNote');
    const select = document.getElementById('orderSelect');
    if (!inTrainNote || !currentCar || !select) {
        return;
    }

    const inTrainCar = currentCar.requires_train_reassign_confirm === true
        || currentCar.weigh_source === 'in_train';
    const order = getSelectedOpenOrder();
    if (!inTrainCar || !order) {
        inTrainNote.classList.add('d-none');
        inTrainNote.textContent = '';
        return;
    }

    const waybill = order.waybill_number;

    let leadIn;
    if (currentCar.requires_train_reassign_confirm) {
        leadIn = 'This car is loaded in train '
            + (currentCar.train_job ? `<strong>${currentCar.train_job}</strong> ` : '')
            + 'on inbound order <strong>' + (currentCar.active_waybill || '—') + '</strong> to the scale.';
    } else {
        leadIn = 'This car is in train '
            + (currentCar.train_job ? `<strong>${currentCar.train_job}</strong> ` : '')
            + 'at the scale.';
    }

    inTrainNote.classList.remove('d-none');
    inTrainNote.innerHTML =
        '<i class="bi bi-info-circle"></i> '
        + leadIn
        + ' Reassign to order <strong>' + waybill + '</strong>.';
}

function updateAssignBtnState() {
    const select = document.getElementById('orderSelect');
    const assignBtn = document.getElementById('assignBtn');
    if (!select || !assignBtn) return;
    assignBtn.disabled = !select.value;
    updateInTrainAssignNote();
}

function getNextCarIdInList(currentCarId) {
    const items = document.querySelectorAll('.car-list-item');
    let foundCurrent = false;
    for (const item of items) {
        if (!foundCurrent) {
            if (item.dataset.carId === String(currentCarId)) {
                foundCurrent = true;
            }
            continue;
        }
        return item.dataset.carId;
    }
    return null;
}

function formatInTrainWorkflowNote(data) {
    if (!data || !Array.isArray(data.in_train_workflow) || !data.in_train_workflow.length) {
        return '';
    }
    const labels = {
        set_out: 'Set out at scale',
        unloaded: 'Unloaded prior order',
        assigned: 'Assigned new order',
        returned_to_train: 'Returned to ' + (data.train_job || 'train'),
    };
    return data.in_train_workflow.map(step => labels[step] || step).join(' → ');
}

function setNetReadingVisible(visible) {
    const block = document.getElementById('netReadingBlock');
    if (block) block.classList.toggle('d-none', !visible);
}

function setWeightLed(state, label) {
    const led = document.getElementById('weightLed');
    const panel = document.getElementById('netDisplayPanel');
    const ledLabel = document.getElementById('weightLedLabel');
    if (!panel || !ledLabel) return;
    state = state || 'off';
    if (led) led.dataset.state = state;
    const labels = {
        ok: 'PASS',
        fail: 'BALANCE - FAIL',
        off: '',
        oos: 'OUT OF SERVICE',
        weighing: 'WEIGHING...',
    };
    const text = label != null && label !== '' ? label : (labels[state] || '');
    ledLabel.textContent = text;
    ledLabel.classList.toggle('d-none', !text);
    ledLabel.classList.toggle('oos-label', state === 'oos');
    ledLabel.classList.toggle('fail-label', state === 'fail');
    ledLabel.classList.toggle('ready-label', state === 'ok');
    ledLabel.classList.toggle('weighing-label', state === 'weighing');
    panel.classList.toggle('out-of-range', state === 'fail');
    panel.classList.toggle('out-of-service', state === 'oos');
    panel.classList.toggle('is-weighing', state === 'weighing');
}

function weighFailureLedLabel(reading) {
    const reason = String((reading && reading.failure_reason) || '').toLowerCase();
    if (reason === 'overloaded') return 'LIMIT - FAIL';
    if (reason === 'imbalanced' || reason === 'underweight') return 'BALANCE - FAIL';
    return 'BALANCE - FAIL';
}

let lastCalibrationMetaText = 'Last calibration unknown';

function outOfServiceBannerText() {
    const detail = (lastCalibrationMetaText || 'Last calibration unknown').trim();
    if (/^OUT OF SERVICE\b/i.test(detail)) {
        return detail;
    }
    return 'OUT OF SERVICE - ' + detail;
}

function applyScaleServiceState(scale) {
    scaleInService = !(scale && scale.out_of_service);
    const weighBtn = document.getElementById('weighBtn');
    const resultEl = document.getElementById('weighResult');

    if (!scaleInService) {
        if (weighBtn) weighBtn.disabled = true;
        setWeightLed('oos', 'OUT OF SERVICE');
        setCalAverageLed('oos', 'OUT OF SERVICE');
        setNetReadingVisible(false);
        // Same slot as before (next to Weigh Car) — short last-cal detail instead of
        // "initial calibration must be completed before weighing cars".
        if (resultEl) {
            resultEl.innerHTML = `<span class="text-danger">${outOfServiceBannerText()}</span>`;
        }
        syncCalibrateFooterMeta(true);
        return;
    }

    panelCleanupOutOfService();
    setNetReadingVisible(true);
    syncCalibrateFooterMeta(false);
    if (weighBtn && currentCar) {
        weighBtn.disabled = false;
    }
}

function syncCalibrateFooterMeta(forceOutOfService) {
    const calEl = document.getElementById('calCalibrationMeta');
    if (!calEl) return;
    const metaWrap = calEl.closest('.cal-actions-meta');
    const oos = forceOutOfService || !scaleInService;
    const detail = (lastCalibrationMetaText || 'Last calibration unknown').trim();
    const known = detail !== '' && !/unknown/i.test(detail);
    // In service after calibrate: show known date/time (not OOS). When OOS, prefix
    // OUT OF SERVICE and keep the last-cal detail (date when known).
    calEl.textContent = oos ? outOfServiceBannerText() : detail;
    calEl.className = 'small ' + (oos ? 'text-danger' : (known ? 'text-success' : 'text-danger'));
    calEl.hidden = false;
    if (metaWrap) metaWrap.hidden = false;
}

function panelCleanupOutOfService() {
    const panel = document.getElementById('netDisplayPanel');
    const ledLabel = document.getElementById('weightLedLabel');
    if (panel) panel.classList.remove('out-of-service');
    if (ledLabel) ledLabel.classList.remove('oos-label');
    const calPanel = document.getElementById('calAverageDisplayPanel');
    const calLabel = document.getElementById('calAverageLedLabel');
    if (calPanel) calPanel.classList.remove('out-of-service');
    if (calLabel) calLabel.classList.remove('oos-label');
}

function setCalAverageLed(state, labelText) {
    const panel = document.getElementById('calAverageDisplayPanel');
    const led = document.getElementById('calAverageLed');
    const label = document.getElementById('calAverageLedLabel');
    if (!panel || !label) return;
    state = state || 'off';
    if (led) led.dataset.state = state;
    const defaults = {
        ok: 'READY',
        oos: 'OUT OF SERVICE',
        weighing: 'WEIGHING...',
    };
    const text = labelText != null && labelText !== '' ? labelText : (defaults[state] || '');
    label.textContent = text;
    label.classList.toggle('d-none', !text);
    label.classList.toggle('oos-label', state === 'oos');
    label.classList.toggle('ready-label', state === 'ok');
    label.classList.toggle('fail-label', state === 'fail');
    label.classList.toggle('weighing-label', state === 'weighing');
    panel.classList.toggle('out-of-service', state === 'oos');
    panel.classList.toggle('is-ready', state === 'ok');
    panel.classList.toggle('out-of-range', state === 'fail');
    panel.classList.toggle('is-weighing', state === 'weighing');
}

function assignedOrderMessage(car) {
    if (!car || !car.has_active_order || carNeedsAssignment(car)) return '';
    let msg = `<span class="text-success"><i class="bi bi-check-circle"></i> Assigned to <strong>${car.active_waybill}</strong>`;
    if (car.active_shipment_code) {
        msg += ` · ${car.active_shipment_code}`;
    }
    if (car.active_unloading_location) {
        msg += ` → ${car.active_unloading_location}`;
    }
    return msg + '</span>';
}

async function loadCarsAtScale() {
    const listEl = document.getElementById('carsList');
    const emptyEl = document.getElementById('carsListEmpty');
    const errorEl = document.getElementById('carsListError');
    const filterValue = getTrainFilterValue();

    errorEl.classList.add('d-none');
    const data = await apiGet('cars_at_scale', filterValue ? { job_id: filterValue } : {});
    if (!data.success) {
        errorEl.textContent = data.error || 'Could not load cars at scale';
        errorEl.classList.remove('d-none');
        listEl.innerHTML = '';
        emptyEl.classList.add('d-none');
        return;
    }

    populateTrainFilter(data.trains, filterValue);

    if (data.scale_status) {
        applyScaleServiceState(data.scale_status);
    }

    listEl.innerHTML = '';
    if (!data.cars.length) {
        emptyEl.classList.remove('d-none');
        document.getElementById('carPanel').classList.add('d-none');
        document.getElementById('weighBtn').disabled = true;
        hideWeighActionButtons();
        selectedCarId = null;
        currentCar = null;
        return;
    }

    emptyEl.classList.add('d-none');
    data.cars.forEach(car => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action car-list-item'
            + (String(car.id) === String(selectedCarId) ? ' active' : '');
        item.dataset.carId = car.id;
        const positionLabel = carListPositionLabel(car);
        item.innerHTML = `
            <div class="d-flex justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    ${positionLabel ? `<span class="car-list-position">${positionLabel}</span>` : ''}
                    <div>
                        <span class="car-list-marks">${car.reporting_marks}</span>
                        <span class="text-muted small ms-2">${car.car_code || ''}</span>
                        ${car.tare_only
                            ? '<span class="badge bg-dark ms-1">Scale car</span>'
                            : ''}
                        ${car.weigh_source === 'in_train' && car.train_job
                            ? `<span class="badge bg-info text-dark ms-1">Train ${car.train_job}</span>`
                            : ''}
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted small">${car.tare_only
                        ? fmt(car.tare_tons) + ' t tare'
                        : fmt(car.load_limit_tons) + ' t LD LMT'}</span>
                    <span class="status-badge ${statusBadgeClass(car.status)}">${displayStatus(car.status, car.display_status)}</span>
                    ${car.has_active_order
                        ? `<span class="badge bg-secondary ms-1">${car.active_waybill}</span>`
                        : ''}
                </div>
            </div>`;
        item.addEventListener('click', () => selectCar(car.id));
        listEl.appendChild(item);
    });

    if (selectedCarId && !data.cars.some(c => String(c.id) === String(selectedCarId))) {
        selectedCarId = null;
        currentCar = null;
        document.getElementById('carPanel').classList.add('d-none');
        document.getElementById('weighBtn').disabled = true;
        hideWeighActionButtons();
    }
}

async function selectCar(carId) {
    if (currentCar && currentCar.id && currentReading) {
        snapshotWeighSession(currentCar.id);
    }
    abortScaleSettle();
    selectedCarId = carId;
    hideWeighActionButtons();
    document.querySelectorAll('.car-list-item').forEach(el => {
        el.classList.toggle('active', el.dataset.carId === String(carId));
    });

    const data = await apiGet('get_car', { car_id: carId });
    if (!data.success) {
        document.getElementById('carsListError').textContent = data.error || 'Could not load car';
        document.getElementById('carsListError').classList.remove('d-none');
        return;
    }
    if (data.scale_status) {
        applyScaleServiceState(data.scale_status);
    }
    renderCar(data);
    if (scaleInService) {
        await restoreWeighSession(carId);
    }
    // Train nav stays available whenever a train car is highlighted (not only after weigh).
    applyTrainNavFromResponse(data);
}

function fmtTimestamp(ts) {
    if (ts === null || ts === undefined || ts === '') return '';
    const n = Number(ts);
    if (Number.isFinite(n)) {
        return new Date(n * 1000).toLocaleString();
    }
    const parsed = Date.parse(String(ts));
    if (!Number.isNaN(parsed)) {
        return new Date(parsed).toLocaleString();
    }
    return String(ts);
}

function calibrationMetaText(cal) {
    const info = cal && cal.last_calibration ? cal.last_calibration : null;
    const calibrated = !!(cal && (cal.calibrated_this_session || cal.calibration_locked));
    const savedAt = (info && info.saved_at) || (cal && cal.calibration_saved_at) || null;
    const sessionNumber = (info && info.session_number) ? info.session_number : null;
    const stamped = fmtTimestamp(savedAt);

    if (stamped) {
        const prefix = calibrated ? 'Calibrated this session' : 'Last calibrated';
        const sessionPart = sessionNumber ? 'Session ' + sessionNumber + ' — ' : '';
        return prefix + ': ' + sessionPart + stamped;
    }

    if (info && info.calibration_unknown) {
        return 'Last calibration unknown';
    }

    return calibrated
        ? 'Calibrated this session'
        : 'Last calibration unknown';
}

function updateCalibrationMeta(cal) {
    lastCalibrationMetaText = calibrationMetaText(cal);
    if (cal && cal.scale_status) {
        applyScaleServiceState(cal.scale_status);
    } else {
        syncCalibrateFooterMeta(false);
    }
}

function fmt(value) {
    const num = Number(value);
    if (Number.isNaN(num)) return '—';
    return num.toFixed(PRECISION);
}

function tonsLabel(value) {
    return fmt(value) + ' t';
}

function showError(message) {
    const el = document.getElementById('carsListError');
    el.textContent = message;
    el.classList.remove('d-none');
}

function hideError() {
    document.getElementById('carsListError').classList.add('d-none');
}

function setMode(mode, options) {
    options = options || {};
    mode = (mode === 'calibrate') ? 'calibrate' : 'weigh';
    const weighRadio = document.getElementById('modeWeigh');
    const calRadio = document.getElementById('modeCalibrate');
    if (weighRadio) weighRadio.checked = mode === 'weigh';
    if (calRadio) calRadio.checked = mode === 'calibrate';
    document.getElementById('weighPanel').classList.toggle('active', mode === 'weigh');
    document.getElementById('calibratePanel').classList.toggle('active', mode === 'calibrate');
    document.body.classList.toggle('mode-calibrate', mode === 'calibrate');
    if (!options.skipSave) {
        try {
            localStorage.setItem(SCALE_MODE_KEY, mode);
        } catch (e) {
            /* ignore quota / private mode */
        }
    }
    if (mode === 'calibrate') {
        refreshCalibrationState();
    } else {
        refreshCalibrationMeta();
    }
}

function restoreScaleMode() {
    let mode = 'weigh';
    try {
        const saved = localStorage.getItem(SCALE_MODE_KEY);
        if (saved === 'calibrate' || saved === 'weigh') {
            mode = saved;
        }
    } catch (e) {
        mode = 'weigh';
    }
    setMode(mode, { skipSave: true });
}

async function refreshCalibrationMeta() {
    const data = await apiGet('calibration_state');
    if (data.success) {
        updateCalibrationMeta(data.calibration);
    }
}

document.getElementById('modeWeigh').addEventListener('change', () => setMode('weigh'));
document.getElementById('modeCalibrate').addEventListener('change', () => setMode('calibrate'));
restoreScaleMode();

async function setSelectedTestCar(marks) {
    const sel = document.getElementById('testCarSelect');
    const next = String(marks || '').toUpperCase();
    if (!next || next === selectedTestCarMarks) return;
    const previous = selectedTestCarMarks;
    if (sel) sel.disabled = true;
    try {
        const data = await apiPost('set_test_car', { reporting_marks: next });
        if (!data.success) {
            throw new Error(data.error || 'Could not set test car');
        }
        selectedTestCarMarks = String(data.reporting_marks || next).toUpperCase();
        if (CONFIG.calibration) {
            CONFIG.calibration.test_car_reporting_marks = selectedTestCarMarks;
        }
        if (data.calibration) {
            renderCalibration(data.calibration);
        } else {
            await refreshCalibration();
        }
    } catch (err) {
        if (sel && previous) sel.value = previous;
        setCalActionError(err.message || 'Could not set test car');
    } finally {
        if (sel) sel.disabled = false;
    }
}

(function initTestCarSelect() {
    const sel = document.getElementById('testCarSelect');
    if (!sel) return;
    sel.addEventListener('change', () => setSelectedTestCar(sel.value));
})();

async function apiGet(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const res = await fetch('track_scale_ajax.php?' + qs.toString());
    return res.json();
}

async function apiPost(action, payload = {}) {
    const res = await fetch('track_scale_ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...payload }),
    });
    const text = await res.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        throw new Error(text.trim().slice(0, 180) || ('Request failed (' + res.status + ')'));
    }
}

function renderCar(data) {
    currentCar = data.car;
    currentProfile = data.profile;
    currentReading = null;
    currentRouting = null;

    document.getElementById('carPanel').classList.remove('d-none');
    document.getElementById('carMarks').textContent = data.car.reporting_marks;
    document.getElementById('carMeta').textContent =
        [data.profile.car_type, data.profile.length_ft ? data.profile.length_ft + "'" : '', data.car.car_code]
            .filter(Boolean).join(' · ');

    document.getElementById('statTare').textContent = tonsLabel(data.profile.tare_tons);
    const tareOnly = data.profile.tare_only === true;
    document.getElementById('statLoadLimit').textContent = tareOnly ? '—' : tonsLabel(data.profile.load_limit_tons);
    document.getElementById('statCapy').textContent = tareOnly || data.profile.capy_tons == null
        ? '—' : tonsLabel(data.profile.capy_tons);
    document.getElementById('statTarget').textContent = tareOnly ? '—' : tonsLabel(data.profile.target_net_tons);
    document.getElementById('statStatus').textContent = displayStatus(data.car.status, data.car.display_status) || '—';

    const img = document.getElementById('carPhoto');
    const placeholder = document.getElementById('carPhotoPlaceholder');
    img.onload = () => {
        img.classList.remove('d-none');
        placeholder.classList.add('d-none');
    };
    img.onerror = () => {
        img.classList.add('d-none');
        placeholder.classList.remove('d-none');
        placeholder.textContent = 'No photo available';
    };
    img.src = data.car.image_url + '?' + Date.now();

    const locationLabel = data.car.weigh_source === 'in_train'
        ? ('In train · ' + (data.car.train_job || TRACK_SCALE_UI.routedTrainsLabel + ' job'))
        : (data.car.current_location || '—');
    document.getElementById('statLocation').textContent = locationLabel;
    document.getElementById('statLocation').className = 'stat-value text-success';
    document.getElementById('weighBtn').disabled = !scaleInService;
    const assignedMsg = assignedOrderMessage(data.car);
    const unloadingAssign = carNeedsAssignment(data.car)
        && (data.car.status || '').toLowerCase() === 'unloading';
    const inboundTrainAssign = data.car.requires_train_reassign_confirm === true;
    if (!scaleInService) {
        document.getElementById('weighResult').innerHTML =
            `<span class="text-danger">${outOfServiceBannerText()}</span>`;
    } else {
        document.getElementById('weighResult').innerHTML = tareOnly
            ? 'Scale test car — weigh to verify tare weight on the scale.'
            : (assignedMsg
                || (unloadingAssign
                    ? '<span class="text-muted">Unloading — weigh, then assign to a new coke order (prior order returns to unfilled on assign).</span>'
                    : (inboundTrainAssign
                        ? `<span class="text-muted">In train on <strong>${data.car.active_waybill || 'inbound order'}</strong> to the scale — weigh, then assign outbound or reload (confirm train workflow on assign).</span>`
                        : (data.car.weigh_source === 'in_train'
                            ? '<span class="text-muted">In train — weigh, then assign; set-out, unload, and return to '
                                + (data.car.train_job || 'the same train') + ' run automatically on assign.</span>'
                            : 'Ready to weigh.'))));
    }
    document.getElementById('displayGross').textContent = '0.00';
    document.getElementById('displayNet').textContent = '0.00';
    setNetReadingVisible(scaleInService);
    if (scaleInService) {
        setWeightLed('off');
    }
    hideWeighActionButtons();
    hideOrderSection();
    applyTrainNavFromResponse(data);
}

document.getElementById('trainFilter').addEventListener('change', () => loadCarsAtScale());
document.getElementById('refreshCarsBtn').addEventListener('click', () => loadCarsAtScale());
document.getElementById('prevCarBtn').addEventListener('click', () => {
    if (!pendingPrevCar || !pendingPrevCar.id) return;
    selectCar(pendingPrevCar.id);
});
document.getElementById('nextCarBtn').addEventListener('click', () => {
    if (!pendingNextCar || !pendingNextCar.id) return;
    selectCar(pendingNextCar.id);
});
document.getElementById('reassignBtn').addEventListener('click', () => {
    if (!currentCar || !currentReading || !shouldOfferReassignButton(currentCar, currentReading)) return;
    openManualReassign();
});

async function openManualReassign() {
    manualReassignMode = true;
    const routing = (currentReading && !currentReading.in_tolerance) ? 'reload' : 'outbound';
    await loadOrders(routing, { forceReassign: true });
    const section = document.getElementById('orderSection');
    if (section && !section.classList.contains('d-none')) {
        section.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    if (currentCar) snapshotWeighSession(currentCar.id);
}

document.getElementById('weighBtn').addEventListener('click', async () => {
    const weighBtn = document.getElementById('weighBtn');
    if (!currentCar || !weighBtn || weighBtn.disabled) return;
    const weighedCarId = currentCar.id;
    weighBtn.disabled = true;
    const resultEl = document.getElementById('weighResult');
    if (resultEl) {
        resultEl.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split"></i> Weighing...</span>';
    }
    setWeightLed('weighing', 'WEIGHING...');
    hideWeighActionButtons();
    hideOrderSection();

    const data = await apiPost('weigh', { reporting_marks: currentCar.reporting_marks });
    if (!data.success) {
        abortScaleSettle();
        setWeightLed('off');
        if (resultEl) {
            resultEl.innerHTML =
                `<span class="text-danger">${data.error || 'Weigh failed'}</span>`;
        }
        weighBtn.disabled = !scaleInService;
        return;
    }

    const reading = data.reading;
    const settled = await runScaleSettle({
        targetGross: reading.gross_tons,
        targetNet: reading.net_tons,
        sensorTargets: reading.sensor_readings || null,
        showNet: true,
        amplitudeTons: 0.35,
        durationMs: SCALE_SETTLE_MS,
        rateStart: SCALE_SETTLE_RATE_START,
        rateEnd: SCALE_SETTLE_RATE_END,
    });
    if (!settled || !currentCar || currentCar.id !== weighedCarId) {
        weighBtn.disabled = !scaleInService;
        return;
    }

    currentReading = reading;
    currentRouting = reading.routing;
    applyReadingDisplays(reading);

    if (reading.test_car_weigh) {
        resultEl.innerHTML =
            '<span class="text-muted"><i class="bi bi-info-circle"></i> Scale test car — gross is tare weight only.</span>';
        setWeightLed('off');
        document.getElementById('orderSection').classList.add('d-none');
        hideWeighActionButtons();
        weighBtn.disabled = !scaleInService;
        snapshotWeighSession(currentCar.id);
        return;
    }
    if (reading.unloaded_weigh) {
        resultEl.innerHTML =
            '<span class="text-muted"><i class="bi bi-info-circle"></i> Empty car — gross is unloaded (tare) weight only.</span>';
        setWeightLed('off');
        document.getElementById('orderSection').classList.add('d-none');
        hideReassignButton();
        showTrainNavButtons(
            data.prev_car,
            data.next_car,
            { keepVisible: currentCar.weigh_source === 'in_train' }
        );
        weighBtn.disabled = !scaleInService;
        snapshotWeighSession(currentCar.id);
        return;
    }

    const inTol = reading.in_tolerance;
    setWeightLed(inTol ? 'ok' : 'fail', inTol ? 'PASS' : weighFailureLedLabel(reading));
    showTrainNavButtons(
        data.prev_car,
        data.next_car,
        { keepVisible: currentCar.weigh_source === 'in_train' }
    );
    if (!shouldShowAssignAfterWeigh(currentCar, reading)) {
        let baseMsg;
        if (!inTol) {
            if (String(reading.failure_reason || '').toLowerCase() === 'overloaded') {
                baseMsg =
                    `<div class="routing-reload"><i class="bi bi-exclamation-triangle-fill"></i> Overloaded — net ${fmt(reading.net_tons)} t exceeds load limit ${fmt(reading.target_net_tons)} t.</div>`;
            } else {
                baseMsg =
                    `<div class="routing-reload"><i class="bi bi-exclamation-triangle-fill"></i> Imbalanced — left/right differ by ${fmt(reading.delta_tons)} t.</div>`;
            }
            const assigned = assignedOrderMessage(currentCar);
            if (assigned) {
                baseMsg += `<div class="mt-1">${assigned}</div>`;
            }
        } else {
            baseMsg = assignedOrderMessage(currentCar)
                || '<span class="text-muted">Weigh complete — load balanced on assigned order.</span>';
        }
        const reassignHint = shouldOfferReassignButton(currentCar, reading)
            ? ' <span class="text-muted">Use <strong>Reassign Order</strong> to change destination.</span>'
            : '';
        resultEl.innerHTML = baseMsg + reassignHint;
        hideOrderSection();
        if (shouldOfferReassignButton(currentCar, reading)) {
            showReassignButton();
        } else {
            hideReassignButton();
        }
        weighBtn.disabled = !scaleInService;
        snapshotWeighSession(currentCar.id);
        return;
    }
    hideReassignButton();
    if (inTol) {
        resultEl.innerHTML =
            `<span class="routing-outbound"><i class="bi bi-check-circle"></i> Left/right sensors within ±${fmt(reading.tolerance_tons)} t — assign to outbound coke order.</span>`;
    } else if (String(reading.failure_reason || '').toLowerCase() === 'overloaded') {
        resultEl.innerHTML =
            `<div class="routing-reload"><i class="bi bi-exclamation-triangle-fill"></i> Overloaded — net ${fmt(reading.net_tons)} t exceeds load limit ${fmt(reading.target_net_tons)} t.</div>`;
    } else {
        resultEl.innerHTML =
            `<div class="routing-reload"><i class="bi bi-exclamation-triangle-fill"></i> Imbalanced — left/right differ by ${fmt(reading.delta_tons)} t.</div>`;
    }

    await loadOrders(currentRouting);
    weighBtn.disabled = !scaleInService;
    snapshotWeighSession(currentCar.id);
});

async function loadOrders(routing, options = {}) {
    const forceReassign = options.forceReassign === true || manualReassignMode;
    if (!currentCar || !currentReading) {
        hideOrderSection();
        return;
    }
    if (forceReassign) {
        if (!manualReassignMode && !shouldOfferReassignButton(currentCar, currentReading)) {
            hideOrderSection();
            return;
        }
    } else if (!shouldShowAssignAfterWeigh(currentCar, currentReading)) {
        hideOrderSection();
        return;
    }
    const data = await apiGet('open_orders', { car_id: currentCar.id, routing });
    if (!data.success) return;

    const section = document.getElementById('orderSection');
    section.classList.remove('d-none');

    const reassignNote = document.getElementById('reassignNote');
    const reassignPriorWaybill = document.getElementById('reassignPriorWaybill');
    if (reassignNote && reassignPriorWaybill) {
        if (manualReassignMode) {
            reassignPriorWaybill.textContent = currentCar.active_waybill || '—';
            reassignNote.classList.remove('d-none');
        } else {
            reassignNote.classList.add('d-none');
        }
    }

    const badge = document.getElementById('routingBadge');
    if (routing === 'reload') {
        badge.className = 'badge bg-danger';
        badge.textContent = 'Coke Reload';
    } else if (manualReassignMode) {
        badge.className = 'badge bg-warning text-dark';
        badge.textContent = 'Reroute Outbound';
    } else {
        badge.className = 'badge bg-success';
        badge.textContent = 'Outbound Coke';
    }
    currentRouting = routing;

    currentOpenOrders = data.orders || [];
    const hasOrders = currentOpenOrders.length > 0;
    const selectWrap = document.getElementById('orderSelectWrap');
    const select = document.getElementById('orderSelect');
    const assignBtn = document.getElementById('assignBtn');

    if (selectWrap) selectWrap.classList.toggle('d-none', !hasOrders);

    select.innerHTML = '';
    if (hasOrders) {
        currentOpenOrders.forEach(order => {
            const opt = document.createElement('option');
            opt.value = order.waybill_number;
            opt.textContent = `${order.waybill_number} · ${order.shipment_code} → ${order.unloading_location}`;
            if (order.special_instructions) {
                opt.textContent += ` (${order.special_instructions})`;
            }
            select.appendChild(opt);
        });
        select.value = currentOpenOrders[0].waybill_number;
    }

    const genWrap = document.getElementById('generateButtons');
    genWrap.innerHTML = '';
    const isReloadRouting = routing === 'reload';
    (data.shipment_codes || []).forEach(code => {
        const btn = document.createElement('button');
        btn.type = 'button';
        const isReload = isReloadRouting || String(code).toUpperCase().indexOf('RELOAD') >= 0;
        if (isReload) {
            // Always available so a new order can reopen the dropdown.
            btn.className = 'btn btn-outline-primary';
            btn.innerHTML = '<i class="bi bi-plus-circle"></i> Create work order';
        } else if (hasOrders) {
            // Outbound: hide generate once there is something to assign.
            return;
        } else {
            btn.className = 'btn btn-outline-primary btn-sm';
            btn.innerHTML = `<i class="bi bi-plus-circle"></i> Generate ${code}`;
        }
        btn.addEventListener('click', () => generateOrder(code, routing));
        genWrap.appendChild(btn);
    });

    if (assignBtn) {
        const label = document.getElementById('assignBtnLabel');
        if (isReloadRouting) {
            // Show beside Create only after an order exists to assign.
            assignBtn.classList.toggle('d-none', !hasOrders);
            assignBtn.classList.remove('btn-success');
            assignBtn.classList.add('btn-danger');
            if (label) label.textContent = 'Reassign Car';
            const icon = assignBtn.querySelector('i');
            if (icon) icon.className = 'bi bi-arrow-repeat';
        } else {
            assignBtn.classList.toggle('d-none', !hasOrders);
            assignBtn.classList.remove('btn-danger');
            assignBtn.classList.add('btn-success');
            if (label) label.textContent = 'Assign to Order';
            const icon = assignBtn.querySelector('i');
            if (icon) icon.className = 'bi bi-check2-circle';
        }
    }

    select.onchange = () => {
        updateAssignBtnState();
        if (currentCar) snapshotWeighSession(currentCar.id);
    };
    updateAssignBtnState();
    if (currentCar) snapshotWeighSession(currentCar.id);
}

async function generateOrder(shipmentCode, routing) {
    if (!currentCar) return;
    const data = await apiPost('generate_order', {
        shipment_code: shipmentCode,
        car_id: currentCar.id,
        routing,
    });
    if (!data.success) {
        alert(data.error || 'Generate failed');
        return;
    }
    await loadOrders(routing, { forceReassign: manualReassignMode });
    const select = document.getElementById('orderSelect');
    if (data.waybill_number) {
        select.value = data.waybill_number;
    }
    if ((data.orders_created || 1) > 1) {
        const resultEl = document.getElementById('assignResult');
        resultEl.innerHTML =
            `<span class="text-muted">Created ${data.orders_created} orders for ${data.shipment_code}. Select one to assign this car.</span>`;
    }
    updateAssignBtnState();
    snapshotWeighSession(currentCar.id);
}

document.getElementById('assignBtn').addEventListener('click', async () => {
    const waybill = document.getElementById('orderSelect').value;
    if (!waybill || !currentCar) return;

    const assignedCarId = currentCar.id;
    const nextCarId = pendingNextCar?.id || getNextCarIdInList(currentCar.id);
    const payload = {
        waybill_number: waybill,
        car_id: currentCar.id,
    };
    if (currentCar.requires_train_reassign_confirm) {
        payload.confirm_train_reassign = true;
    }

    const data = await apiPost('assign', payload);
    const resultEl = document.getElementById('assignResult');
    if (!data.success) {
        resultEl.innerHTML = `<span class="text-danger">${data.error || 'Assign failed'}</span>`;
        return;
    }
    clearWeighSession(assignedCarId);
    const priorOrderNote = (data.unfilled_prior_order || data.closed_prior_order)
        ? (data.preserved_load
            ? '<span class="text-muted">Prior order returned to unfilled · car stays loaded · </span>'
            : '<span class="text-muted">Prior order returned to unfilled · </span>')
        : (data.unloaded_first
            ? '<span class="text-muted">Prior order returned to unfilled · </span>'
            : '');
    const workflowNote = formatInTrainWorkflowNote(data);
    const workflowHtml = workflowNote
        ? `<div class="text-muted mt-1">${workflowNote}</div>`
        : '';
    resultEl.innerHTML =
        `<span class="text-success"><i class="bi bi-check-circle"></i> ${priorOrderNote}${data.message} (${data.car_reporting_marks})</span>`
        + workflowHtml;
    hideOrderSection();
    hideWeighActionButtons();
    currentReading = null;
    await loadCarsAtScale();
    if (nextCarId && document.querySelector(`.car-list-item[data-car-id="${nextCarId}"]`)) {
        await selectCar(nextCarId);
        return;
    }
    selectedCarId = null;
    currentCar = null;
    document.getElementById('carPanel').classList.add('d-none');
    document.getElementById('weighBtn').disabled = true;
    document.getElementById('weighResult').textContent = 'Select a car from the list, then weigh.';
    document.getElementById('displayNet').textContent = '0.00';
    setNetReadingVisible(scaleInService);
    setWeightLed(scaleInService ? 'off' : 'oos', scaleInService ? '' : 'OUT OF SERVICE');
});

function updateCalTrackCar(position) {
    const trackCar = document.getElementById('calTrackCar');
    if (!trackCar) return;
    SENSOR_POSITIONS.forEach(pos => {
        trackCar.classList.remove('position-' + pos);
    });
    const active = position && SENSOR_POSITIONS.includes(position) ? position : null;
    if (!active) {
        trackCar.classList.add('d-none');
        return;
    }
    trackCar.classList.remove('d-none');
    trackCar.classList.add('position-' + active);
}

function renderCalibration(cal) {
    if (!cal) return;
    lastCalibrationSnapshot = cal;

    updateCalibrationMeta(cal);

    const calibrationLocked = !!cal.calibration_locked;

    if (cal.test_car) {
        const tc = cal.test_car;
        if (tc.reporting_marks) {
            selectedTestCarMarks = String(tc.reporting_marks).toUpperCase();
            const sel = document.getElementById('testCarSelect');
            if (sel && sel.value !== selectedTestCarMarks) {
                const opt = Array.from(sel.options).find(o => o.value.toUpperCase() === selectedTestCarMarks);
                if (opt) sel.value = opt.value;
            }
        }
        document.getElementById('calTestCarMarks').textContent = tc.reporting_marks || '—';
        document.getElementById('calTestCarLbs').textContent = (tc.tare_lbs || 0).toLocaleString();
        document.getElementById('calTestCarTons').textContent = fmt(tc.tare_tons);
        const img = document.getElementById('calTestCarPhoto');
        const ph = document.getElementById('calTestCarPhotoPlaceholder');
        if (tc.image_url) {
            img.onload = () => { img.classList.remove('d-none'); ph.classList.add('d-none'); };
            img.onerror = () => { img.classList.add('d-none'); ph.classList.remove('d-none'); };
            if (!img.src || img.src.indexOf(tc.image_url) === -1) {
                img.src = tc.image_url + '?' + Date.now();
            }
        } else {
            img.classList.add('d-none');
            ph.classList.remove('d-none');
        }
    }

    const testCarAtScale = cal.test_car_at_scale !== false;
    const scaleLocation = cal.scale_location || TRACK_SCALE_UI.scaleLocation || '';

    if (testCarAtScale) {
        updateCalTrackCar(cal.scale_car_position || null);
    } else {
        updateCalTrackCar(null);
    }

    let activeSensor = null;
    (cal.sensors || []).forEach(sensor => {
        const pos = sensor.position;
        const card = document.getElementById('sensorCard-' + pos);
        const carHere = testCarAtScale && !!sensor.car_at_position;
        const sensorLocked = !!sensor.is_locked;
        card.classList.toggle('car-at-position', carHere);
        card.classList.toggle('adjustment-locked', !!sensor.adjustment_locked);
        // Green when saved, session-locked, or currently zeroed.
        card.classList.toggle('calibrated', calibrationLocked || sensorLocked || !!sensor.is_zero);

        const badge = document.getElementById('sensorCarHere-' + pos);
        if (badge) {
            badge.classList.toggle('d-none', !carHere);
        }

        const posBtn = card.querySelector('.cal-position-btn');
        posBtn.classList.remove('cal-weigh-phase', 'cal-lock-phase', 'cal-locked-phase');
        // After save: place only. Open cal: Place → Lock/Unlock (allowed even with error).
        let phase = 'place';
        if (!calibrationLocked) {
            if (sensorLocked) {
                phase = 'locked';
            } else if (carHere) {
                phase = 'lock';
            }
        }
        posBtn.classList.toggle('active', carHere && phase !== 'locked');
        posBtn.classList.toggle('cal-lock-phase', phase === 'lock');
        posBtn.classList.toggle('cal-locked-phase', phase === 'locked');
        posBtn.classList.toggle('btn-primary', phase === 'lock' || (phase === 'place' && carHere));
        posBtn.classList.toggle('btn-outline-primary', phase === 'place' && !carHere);
        posBtn.classList.toggle('btn-success', phase === 'locked');
        posBtn.dataset.phase = phase;
        posBtn.dataset.carHere = carHere ? '1' : '0';
        posBtn.disabled = !testCarAtScale || calPlaceBusy;
        if (phase === 'locked') {
            if (carHere) {
                posBtn.innerHTML = '<i class="bi bi-unlock"></i> Unlock Sensor';
                posBtn.setAttribute('aria-label', 'Unlock ' + pos + ' sensor');
            } else {
                posBtn.innerHTML = '<i class="bi bi-lock-fill"></i> Locked';
                posBtn.setAttribute('aria-label', 'Place car on locked ' + pos + ' sensor');
            }
        } else if (phase === 'lock') {
            posBtn.innerHTML = '<i class="bi bi-lock"></i> Lock Sensor';
            posBtn.setAttribute('aria-label', 'Lock ' + pos + ' sensor');
        } else {
            posBtn.innerHTML = '<i class="bi bi-truck"></i> Place Car Here';
            posBtn.setAttribute('aria-label', 'Place car on ' + pos + ' sensor');
        }

        const weighBtn = card.querySelector('.cal-weigh-btn');
        if (weighBtn) {
            weighBtn.disabled = !carHere || calibrationLocked || sensorLocked;
        }

        document.getElementById('sensorDisplay-' + pos).textContent =
            sensor.display_tons !== null && sensor.display_tons !== undefined
                ? fmt(sensor.display_tons)
                : '—';
        document.getElementById('sensorError-' + pos).textContent =
            sensor.has_reading ? fmt(sensor.error_tons) : '—';
        const adjInput = document.getElementById('sensorAdjustInput-' + pos);
        if (adjInput) {
            adjInput.value = fmt(sensor.adjustment_tons);
        }
        const adjLine = document.getElementById('sensorAdj-' + pos);
        if (adjLine) {
            adjLine.textContent =
                'adj ' + fmt(sensor.adjustment_tons) + ' (step ±' + fmt(sensor.adjust_step_tons || 0.1) + ' t)';
        }

        card.querySelectorAll('.cal-adj-btn').forEach(btn => {
            btn.disabled = calibrationLocked || !!sensor.adjustment_locked || !sensor.has_reading;
        });
        const resetBtn = card.querySelector('.cal-adj-reset-btn');
        if (resetBtn) {
            resetBtn.disabled = calibrationLocked || !!sensor.adjustment_locked || !sensor.has_reading;
        }

        if (carHere) {
            activeSensor = sensor;
        }
    });

    syncCalCentralControls(activeSensor, testCarAtScale, calibrationLocked);

    document.getElementById('calResetBtn').disabled = false;
    const saveBtn = document.getElementById('calSaveBtn');
    if (saveBtn) {
        saveBtn.disabled = calibrationLocked || !cal.all_calibrated;
    }

    if (cal.average) {
        document.getElementById('calAverageDisplay').textContent = fmt(cal.average.display_tons);
        document.getElementById('calAverageAdjustment').textContent =
            cal.average.adjustment_tons !== null && cal.average.adjustment_tons !== undefined
                ? fmt(cal.average.adjustment_tons)
                : '—';
        document.getElementById('calAverageMeta').textContent =
            `(${cal.average.sensor_count || 0} of 3 sensors in average)`;
    } else {
        document.getElementById('calAverageDisplay').textContent = '—';
        document.getElementById('calAverageAdjustment').textContent = '—';
        document.getElementById('calAverageMeta').textContent = '(zero and lock each sensor — average moves toward expected)';
    }

    syncCalReadyUi(cal, activeSensor);
}

function sensorMatchesExpected(sensor) {
    if (!sensor || !sensor.has_reading) return false;
    if (sensor.is_zero) return true;
    const err = Number(sensor.error_tons);
    return Number.isFinite(err) && Math.abs(err) < (Math.pow(10, -PRECISION) / 2);
}

function syncCalErrorStat(sensor) {
    const errorEl = document.getElementById('calCentralError');
    const errorBox = errorEl ? errorEl.closest('.cal-stat-error') : null;
    if (!errorEl) return;
    if (!errorBox) {
        errorEl.textContent = sensor && sensor.has_reading ? fmt(sensor.error_tons) : '—';
        return;
    }
    errorBox.classList.remove('is-zero', 'is-nonzero');
    if (!sensor || !sensor.has_reading
        || sensor.error_tons === null || sensor.error_tons === undefined) {
        errorEl.textContent = '—';
        return;
    }
    const err = Number(sensor.error_tons);
    errorEl.textContent = fmt(err);
    if (sensorMatchesExpected(sensor)) {
        errorBox.classList.add('is-zero');
    } else {
        errorBox.classList.add('is-nonzero');
    }
}

function syncCalReadyUi(cal, activeSensor) {
    const adjPanel = document.querySelector('#calibratePanel .cal-central-panel');
    const activeReady = sensorMatchesExpected(activeSensor);
    // Banner READY only when all 3 sensors align (save enabled). Keep the
    // average weight readout until then.
    const allReady = !!(cal && cal.all_calibrated);

    if (allReady) {
        setCalAverageLed('ok', 'READY');
    } else if (!scaleInService) {
        setCalAverageLed('oos', 'OUT OF SERVICE');
    } else {
        setCalAverageLed('off', '');
    }
    if (adjPanel) adjPanel.classList.toggle('is-ready', activeReady);
}

const ADJ_SLIDER_MIN = -2.5;
const ADJ_SLIDER_MAX = 2.5;

function clampAdjTons(valueTons) {
    const tons = Number(valueTons);
    const safe = Number.isFinite(tons) ? tons : 0;
    return Math.min(ADJ_SLIDER_MAX, Math.max(ADJ_SLIDER_MIN, safe));
}

function syncCalAdjDisplay(valueTons, options) {
    options = options || {};
    const safe = clampAdjTons(valueTons);
    const text = fmt(safe);
    const adjInput = document.getElementById('calCentralAdjInput');
    const adjValue = document.getElementById('calCentralAdjValue');
    const slider = document.getElementById('calCentralAdjSlider');
    const minEl = document.getElementById('calCentralAdjMin');
    const maxEl = document.getElementById('calCentralAdjMax');
    if (adjInput) adjInput.value = text;
    if (adjValue) adjValue.textContent = text;
    if (minEl) minEl.textContent = fmt(ADJ_SLIDER_MIN);
    if (maxEl) maxEl.textContent = fmt(ADJ_SLIDER_MAX);
    if (slider && !options.skipSlider) {
        slider.min = String(ADJ_SLIDER_MIN);
        slider.max = String(ADJ_SLIDER_MAX);
        const step = (CONFIG.calibration && CONFIG.calibration.fine_adjust_step_tons) || 0.01;
        slider.step = String(step);
        slider.value = String(safe);
    }
}

function syncCalCentralControls(sensor, testCarAtScale, calibrationLocked) {
    const sensorEl = document.getElementById('calCentralSensor');
    const readingEl = document.getElementById('calCentralReading');
    const expectedEl = document.getElementById('calCentralExpected');
    const adjInput = document.getElementById('calCentralAdjInput');
    const slider = document.getElementById('calCentralAdjSlider');
    const adjBtns = document.querySelectorAll('.cal-central-adj-btn');
    const resetAdjBtn = document.getElementById('calResetAdjBtn');
    if (!sensorEl || !adjInput) {
        return;
    }

    if (!sensor) {
        sensorEl.textContent = '—';
        syncCalErrorStat(null);
        if (readingEl) readingEl.textContent = '—';
        if (expectedEl) expectedEl.textContent = '—';
        syncCalAdjDisplay(0);
        adjBtns.forEach(btn => { btn.disabled = true; });
        if (slider) slider.disabled = true;
        if (resetAdjBtn) resetAdjBtn.disabled = true;
        return;
    }

    const locked = calibrationLocked || !!sensor.adjustment_locked || !sensor.has_reading;
    sensorEl.textContent = sensor.position ? String(sensor.position).toUpperCase() : '—';
    syncCalErrorStat(sensor);
    if (readingEl) {
        // Live pad reading (includes position shift + noise); adjustment is separate.
        if (sensor.has_reading
            && sensor.display_tons !== null && sensor.display_tons !== undefined) {
            readingEl.textContent = fmt(Number(sensor.display_tons));
        } else {
            readingEl.textContent = '—';
        }
    }
    if (expectedEl) {
        expectedEl.textContent =
            sensor.expected_tons !== null && sensor.expected_tons !== undefined
                ? fmt(sensor.expected_tons)
                : '—';
    }
    if (!slider || document.activeElement !== slider) {
        syncCalAdjDisplay(sensor.adjustment_tons);
    } else {
        syncCalAdjDisplay(sensor.adjustment_tons, { skipSlider: true });
    }
    adjBtns.forEach(btn => { btn.disabled = locked; });
    if (slider) slider.disabled = locked;
    if (resetAdjBtn) resetAdjBtn.disabled = locked;
}

function getActiveCalSensorPosition() {
    const activeBtn = document.querySelector('.cal-position-btn.active');
    return activeBtn ? activeBtn.dataset.sensor : null;
}

function setCalActionError(message) {
    const el = document.getElementById('calCalibrationMeta');
    if (!el) return;
    el.innerHTML = `<span class="text-danger">${message}</span>`;
    el.hidden = false;
    const wrap = el.closest('.cal-actions-meta');
    if (wrap) wrap.hidden = false;
}

function setCalPlaceButtonsBusy(busy) {
    calPlaceBusy = !!busy;
    const atScale = lastCalibrationSnapshot
        ? (lastCalibrationSnapshot.test_car_at_scale !== false)
        : true;
    document.querySelectorAll('.cal-position-btn').forEach(btn => {
        btn.disabled = busy || !atScale;
    });
}

async function placeCalCar(position, options) {
    options = options || {};
    const keepLocked = options.keepLocked === true;
    const autoLock = options.autoLock === true;
    if (calPlaceBusy) return false;
    setCalPlaceButtonsBusy(true);
    try {
        const setData = await apiPost('calibrate_set_position', { position });
        if (!setData.success) {
            setCalActionError(setData.error || 'Could not place car');
            return false;
        }
        // Show placed state first, then settle meters at the new pad loads.
        renderCalibration(setData.calibration);
        const settled = await runCalibrationPlaceSettle(setData.calibration);
        if (!settled) return false;

        let calibration = setData.calibration;
        if (autoLock && !keepLocked) {
            const lockData = await apiPost('calibrate_read', { position });
            if (!lockData.success) {
                setCalActionError(lockData.error || 'Could not lock sensor');
                renderCalibration(setData.calibration);
                return false;
            }
            calibration = lockData.calibration;
        }
        renderCalibration(calibration);
        return true;
    } finally {
        calPlaceBusy = false;
        if (lastCalibrationSnapshot) {
            renderCalibration(lastCalibrationSnapshot);
        } else {
            setCalPlaceButtonsBusy(false);
        }
    }
}

async function lockCalSensor(position) {
    if (calPlaceBusy) return false;
    const lockData = await apiPost('calibrate_read', { position });
    if (!lockData.success) {
        setCalActionError(lockData.error || 'Could not lock sensor');
        return false;
    }
    renderCalibration(lockData.calibration);
    return true;
}

async function unlockCalSensor(position) {
    if (calPlaceBusy) return false;
    const unlockData = await apiPost('calibrate_unlock', { position });
    if (!unlockData.success) {
        setCalActionError(unlockData.error || 'Could not unlock sensor');
        return false;
    }
    renderCalibration(unlockData.calibration);
    return true;
}

/** @deprecated alias — Lock Sensor is calibrate_read */
async function weighCalPosition(position) {
    return lockCalSensor(position);
}

async function adjustSensor(sensor, direction, fineTune) {
    const payload = { sensor, direction };
    if (fineTune !== undefined && fineTune !== null) {
        payload.fine_tune = !!fineTune;
    }
    const data = await apiPost('calibrate_adjust', payload);
    if (!data.success) {
        setCalActionError(data.error || 'Adjust failed');
        return;
    }
    renderCalibration(data.calibration);
}

let calAdjSliderTimer = null;
let calAdjSliderInFlight = false;
let calAdjSliderPending = null;

async function setSensorAdjustmentTons(sensor, tons) {
    const payload = { sensor, adjustment_tons: clampAdjTons(tons) };
    const data = await apiPost('calibrate_adjust', payload);
    if (!data.success) {
        setCalActionError(data.error || 'Adjust failed');
        return false;
    }
    renderCalibration(data.calibration);
    return true;
}

function queueSensorAdjustmentFromSlider(tons) {
    const pos = getActiveCalSensorPosition();
    if (!pos) return;
    syncCalAdjDisplay(tons, { skipSlider: true });
    calAdjSliderPending = { sensor: pos, tons: Number(tons) };
    if (calAdjSliderTimer) clearTimeout(calAdjSliderTimer);
    calAdjSliderTimer = setTimeout(flushSensorAdjustmentFromSlider, 80);
}

async function flushSensorAdjustmentFromSlider() {
    calAdjSliderTimer = null;
    if (calAdjSliderInFlight || !calAdjSliderPending) return;
    const next = calAdjSliderPending;
    calAdjSliderPending = null;
    calAdjSliderInFlight = true;
    try {
        await setSensorAdjustmentTons(next.sensor, next.tons);
    } finally {
        calAdjSliderInFlight = false;
        if (calAdjSliderPending) {
            flushSensorAdjustmentFromSlider();
        }
    }
}

async function resetSensorAdjustment(sensor) {
    const data = await apiPost('calibrate_adjust_reset', { sensor });
    if (!data.success) {
        setCalActionError(data.error || 'Reset failed');
        return;
    }
    renderCalibration(data.calibration);
}

document.querySelectorAll('.cal-position-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (btn.disabled || calPlaceBusy) return;
        const position = btn.dataset.sensor;
        const phase = btn.dataset.phase;
        const carHere = btn.dataset.carHere === '1';
        if (phase === 'locked') {
            if (carHere) {
                unlockCalSensor(position);
            } else {
                // Relocate onto a locked pad — keep the lock after settle.
                placeCalCar(position, { keepLocked: true });
            }
        } else if (phase === 'lock') {
            // Allowed even when residual error is still present.
            lockCalSensor(position);
        } else {
            placeCalCar(position, { autoLock: false, keepLocked: false });
        }
    });
});

document.querySelectorAll('.cal-adj-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (btn.disabled) return;
        const fine = btn.dataset.fine === '1';
        adjustSensor(btn.dataset.sensor, btn.dataset.direction, fine);
    });
});

document.querySelectorAll('.cal-adj-reset-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (btn.disabled) return;
        resetSensorAdjustment(btn.dataset.sensor);
    });
});

(function wireCalCentralControls() {
    document.querySelectorAll('.cal-central-adj-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.disabled) return;
            const pos = getActiveCalSensorPosition();
            if (!pos) return;
            const step = Number(btn.dataset.step);
            if (Number.isFinite(step) && step > 0) {
                const adjInput = document.getElementById('calCentralAdjInput');
                const current = Number(adjInput ? adjInput.value : 0) || 0;
                const delta = btn.dataset.direction === 'down' ? -step : step;
                setSensorAdjustmentTons(pos, current + delta);
                return;
            }
            adjustSensor(pos, btn.dataset.direction, btn.dataset.fine === '1');
        });
    });
    const slider = document.getElementById('calCentralAdjSlider');
    if (slider) {
        slider.addEventListener('input', () => {
            if (slider.disabled) return;
            queueSensorAdjustmentFromSlider(slider.value);
        });
        slider.addEventListener('change', () => {
            if (slider.disabled) return;
            const pos = getActiveCalSensorPosition();
            if (!pos) return;
            if (calAdjSliderTimer) clearTimeout(calAdjSliderTimer);
            calAdjSliderPending = { sensor: pos, tons: clampAdjTons(slider.value) };
            flushSensorAdjustmentFromSlider();
        });
    }
    document.getElementById('calResetAdjBtn')?.addEventListener('click', () => {
        const btn = document.getElementById('calResetAdjBtn');
        if (!btn || btn.disabled) return;
        const pos = getActiveCalSensorPosition();
        if (!pos) return;
        resetSensorAdjustment(pos);
    });
})();

async function saveCalibration() {
    const saveBtn = document.getElementById('calSaveBtn');
    if (!saveBtn || saveBtn.disabled) return;
    saveBtn.disabled = true;
    const data = await apiPost('calibrate_save');
    if (!data.success) {
        setCalActionError(data.error || 'Could not save calibration');
        saveBtn.disabled = false;
        return;
    }
    renderCalibration(data.calibration);
    refreshCalibrationMeta();
}

document.getElementById('calSaveBtn').addEventListener('click', saveCalibration);

async function refreshCalibrationState() {
    const data = await apiGet('calibration_state');
    if (data.success) {
        renderCalibration(data.calibration);
    }
}

document.getElementById('calResetBtn').addEventListener('click', async () => {
    const resetBtn = document.getElementById('calResetBtn');
    if (resetBtn) resetBtn.disabled = true;
    const data = await apiPost('calibrate_reset');
    if (!data.success) {
        setCalActionError(data.error || 'Could not reset calibration');
        if (resetBtn) resetBtn.disabled = false;
        return;
    }
    if (data.calibration) {
        renderCalibration(data.calibration);
        refreshCalibrationMeta();
        return;
    }
    await refreshCalibrationState();
});

refreshCalibrationState();
loadCarsAtScale();
</script>
</body>
</html>
