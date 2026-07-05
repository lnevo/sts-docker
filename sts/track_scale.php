<?php
require 'track_scale_helpers.php';
track_scale_session_init();
$config = track_scale_load_config();
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
        .scale-display .unit {
            font-size: 1rem;
            color: #6bdc6b;
        }
        .scale-display.position-relative {
            position: relative;
        }
        .scale-led-wrap {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .scale-led {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #333;
            border: 1px solid #555;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.6);
            flex-shrink: 0;
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
        .scale-led-label.oos-label {
            color: #ff6b6b;
            font-weight: 700;
        }
        .scale-led-label {
            font-family: "Courier New", Courier, monospace;
            font-size: 0.62rem;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.1em;
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
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
        }
        .stat-box {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 0.75rem;
        }
        .stat-box .stat-label {
            font-size: 0.72rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .stat-box .stat-value {
            font-size: 1.15rem;
            font-weight: 600;
        }
        .routing-reload { color: #dc3545; }
        .routing-outbound { color: #198754; }
        .mode-panel { display: none; }
        .mode-panel.active { display: block; }
        .sensor-card {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            background: #fff;
            padding: 0.75rem;
            height: 100%;
        }
        .sensor-card .scale-display {
            padding: 0.75rem 1rem;
        }
        .sensor-card .scale-display .value {
            font-size: 1.6rem;
        }
        .sensor-card.calibrated {
            border-color: #2e7d32;
            box-shadow: 0 0 0 1px #2e7d32;
        }
        .sensor-card.car-at-position {
            border-color: #0d6efd;
            box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.25);
            background: #f8fbff;
        }
        .sensor-card.adjustment-locked .cal-adj-group {
            opacity: 0.45;
        }
        .sensor-card .cal-position-btn.active {
            background-color: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
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
        .sensor-average .scale-display .value {
            font-size: 2.8rem;
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
        .status-badge {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.2rem 0.45rem;
            border-radius: 0.25rem;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-success mb-4">
    <div class="container-fluid">
        <span class="navbar-brand"><i class="bi bi-speedometer2"></i> Track Scale — South Yard</span>
        <div class="d-flex gap-2">
            <a href="operations.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left"></i> Operations
            </a>
        </div>
    </div>
</nav>

<div class="container" style="max-width: 960px;">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h5 class="mb-1">South Yard Scale</h5>
            <p class="text-muted small mb-0">Select a car at <strong>SOUTH-YARD-SCALE</strong> or in a train routed to South Yard, then weigh and assign a coke order. Cars weighed in train are set out, unloaded, re-assigned, and picked up automatically when you assign an order.</p>
        </div>
        <div class="btn-group" role="group" aria-label="Scale mode">
            <input type="radio" class="btn-check" name="scaleMode" id="modeWeigh" autocomplete="off" checked>
            <label class="btn btn-outline-success" for="modeWeigh"><i class="bi bi-truck"></i> Weigh</label>
            <input type="radio" class="btn-check" name="scaleMode" id="modeCalibrate" autocomplete="off">
            <label class="btn btn-outline-success" for="modeCalibrate"><i class="bi bi-sliders"></i> Calibrate</label>
        </div>
    </div>

    <!-- Weigh mode: car selection -->
    <div id="weighCarSection">
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul"></i> Cars at Scale / Inbound Train</span>
            <button type="button" class="btn btn-outline-success btn-sm" id="refreshCarsBtn">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>
        <div class="card-body p-0">
            <div id="carsListEmpty" class="p-3 text-muted d-none">No cars at the scale or on a South Yard train right now.</div>
            <div id="carsListError" class="alert alert-danger m-3 d-none" role="alert"></div>
            <div class="list-group list-group-flush" id="carsList"></div>
        </div>
    </div>

    <div id="carPanel" class="d-none">
        <div class="row g-3 mb-3">
            <div class="col-md-5">
                <div class="car-photo" id="carPhotoWrap">
                    <span class="text-muted" id="carPhotoPlaceholder">No photo</span>
                    <img id="carPhoto" alt="" class="d-none">
                </div>
            </div>
            <div class="col-md-7">
                <h4 id="carMarks" class="mb-1"></h4>
                <p class="text-muted mb-2">
                    <span id="carMeta"></span>
                </p>
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
    </div>

    <!-- Weigh mode -->
    <div id="weighPanel" class="mode-panel active">
        <div class="small mb-2 text-danger" id="weighCalibrationMeta">Last calibrated: —</div>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="scale-display h-100">
                    <div class="label">Gross weight (3-sensor avg)</div>
                    <div><span class="value" id="displayGross">0.00</span> <span class="unit">tons</span></div>
                    <div class="small mt-2" style="color:#6bdc6b;" id="sensorBreakdown"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="scale-display h-100 position-relative" id="netDisplayPanel">
                    <div class="scale-led-wrap">
                        <div class="scale-led" id="weightLed" data-state="off" title="Tolerance indicator"></div>
                        <span class="scale-led-label" id="weightLedLabel">—</span>
                    </div>
                    <div class="label">Net load</div>
                    <div><span class="value" id="displayNet">0.00</span> <span class="unit">tons</span></div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <button type="button" class="btn btn-primary btn-lg" id="weighBtn" disabled>
                    <i class="bi bi-speedometer"></i> Weigh Car
                </button>
                <div id="weighResult" class="small text-muted">Select a car from the list, then weigh.</div>
            </div>
        </div>

        <div id="orderSection" class="card mb-3 d-none">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-box-seam"></i> Assign Car Order</span>
                <span id="routingBadge" class="badge"></span>
            </div>
            <div class="card-body">
                <div id="inTrainAssignNote" class="alert alert-info py-2 small d-none mb-3"></div>
                <div class="mb-3">
                    <label for="orderSelect" class="form-label">Open coke orders (pool + load at scale)</label>
                    <select class="form-select" id="orderSelect">
                        <option value="">— Select an order —</option>
                    </select>
                    <div id="orderEmptyMsg" class="form-text text-warning d-none">No matching open orders. Generate one below.</div>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3" id="generateButtons"></div>
                <div id="trainReassignConfirmWrap" class="form-check mb-3 d-none">
                    <input class="form-check-input" type="checkbox" id="trainReassignConfirm">
                    <label class="form-check-label" for="trainReassignConfirm" id="trainReassignConfirmLabel">
                        Confirm set-out at the scale, unload inbound order, and return to train
                    </label>
                </div>
                <button type="button" class="btn btn-success" id="assignBtn" disabled>
                    <i class="bi bi-check2-circle"></i> Assign to Order
                </button>
                <div id="assignResult" class="mt-2 small"></div>
            </div>
        </div>
    </div>

    <!-- Calibrate mode -->
    <div id="calibratePanel" class="mode-panel">
        <div class="small mb-2 text-danger" id="calCalibrationMeta">Last calibrated: —</div>
        <div class="card mb-3">
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Place the scale test car (<strong id="calTestCarMarks">COST1</strong>, LT WT
                    <strong id="calTestCarLbs">80,000</strong> lbs / <span id="calTestCarTons">40.00</span> t)
                    on one section of the track at a time. Mark which sensor the car is on, weigh that position,
                    then adjust until its error reads <strong>0.00</strong> t. Move the car to the next sensor and repeat.
                    Calibration persists for this session only.
                </p>
                <div class="cal-track-wrap" id="calTrackWrap">
                    <div class="cal-track-rail">
                        <div class="cal-track-car position-left" id="calTrackCar">
                            <img id="calTestCarPhoto" alt="Scale test car" class="d-none">
                            <span class="cal-track-car-placeholder d-none" id="calTestCarPhotoPlaceholder">Test car</span>
                        </div>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="sensor-card" id="sensorCard-left">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong>Left sensor</strong>
                                <span class="badge bg-primary d-none" id="sensorCarHere-left">Scale car here</span>
                            </div>
                            <div class="scale-display mb-2">
                                <div class="label">Reading</div>
                                <div><span class="value" id="sensorDisplay-left">—</span> <span class="unit">t</span></div>
                            </div>
                            <div class="small mb-2">Error: <span id="sensorError-left">—</span> t</div>
                            <div class="small text-muted mb-2" id="sensorAdj-left">adj 0.00</div>
                            <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-2 cal-position-btn" data-sensor="left">
                                <i class="bi bi-truck"></i> Scale car here
                            </button>
                            <button type="button" class="btn btn-primary btn-sm w-100 mb-2 cal-weigh-btn" data-sensor="left" disabled>
                                <i class="bi bi-speedometer"></i> Weigh
                            </button>
                            <div class="form-check form-check-sm mb-2">
                                <input class="form-check-input cal-fine-toggle" type="checkbox" id="sensorFineTune-left" data-sensor="left" disabled>
                                <label class="form-check-label small" for="sensorFineTune-left">Fine tune (±0.01 t)</label>
                            </div>
                            <div class="input-group input-group-sm cal-adj-group" data-sensor="left">
                                <button type="button" class="btn btn-outline-secondary cal-adj-btn" data-sensor="left" data-direction="down" disabled>
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                                <input type="text" class="form-control text-center" id="sensorAdjustInput-left" value="0.00" readonly>
                                <button type="button" class="btn btn-outline-secondary cal-adj-btn" data-sensor="left" data-direction="up" disabled>
                                    <i class="bi bi-chevron-up"></i>
                                </button>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-1 cal-adj-reset-btn" data-sensor="left" disabled>
                                Reset adjustment
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="sensor-card" id="sensorCard-center">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong>Center sensor</strong>
                                <span class="badge bg-primary d-none" id="sensorCarHere-center">Scale car here</span>
                            </div>
                            <div class="scale-display mb-2">
                                <div class="label">Reading</div>
                                <div><span class="value" id="sensorDisplay-center">—</span> <span class="unit">t</span></div>
                            </div>
                            <div class="small mb-2">Error: <span id="sensorError-center">—</span> t</div>
                            <div class="small text-muted mb-2" id="sensorAdj-center">adj 0.00</div>
                            <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-2 cal-position-btn" data-sensor="center">
                                <i class="bi bi-truck"></i> Scale car here
                            </button>
                            <button type="button" class="btn btn-primary btn-sm w-100 mb-2 cal-weigh-btn" data-sensor="center" disabled>
                                <i class="bi bi-speedometer"></i> Weigh
                            </button>
                            <div class="form-check form-check-sm mb-2">
                                <input class="form-check-input cal-fine-toggle" type="checkbox" id="sensorFineTune-center" data-sensor="center" disabled>
                                <label class="form-check-label small" for="sensorFineTune-center">Fine tune (±0.01 t)</label>
                            </div>
                            <div class="input-group input-group-sm cal-adj-group" data-sensor="center">
                                <button type="button" class="btn btn-outline-secondary cal-adj-btn" data-sensor="center" data-direction="down" disabled>
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                                <input type="text" class="form-control text-center" id="sensorAdjustInput-center" value="0.00" readonly>
                                <button type="button" class="btn btn-outline-secondary cal-adj-btn" data-sensor="center" data-direction="up" disabled>
                                    <i class="bi bi-chevron-up"></i>
                                </button>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-1 cal-adj-reset-btn" data-sensor="center" disabled>
                                Reset adjustment
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="sensor-card" id="sensorCard-right">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong>Right sensor</strong>
                                <span class="badge bg-primary d-none" id="sensorCarHere-right">Scale car here</span>
                            </div>
                            <div class="scale-display mb-2">
                                <div class="label">Reading</div>
                                <div><span class="value" id="sensorDisplay-right">—</span> <span class="unit">t</span></div>
                            </div>
                            <div class="small mb-2">Error: <span id="sensorError-right">—</span> t</div>
                            <div class="small text-muted mb-2" id="sensorAdj-right">adj 0.00</div>
                            <button type="button" class="btn btn-outline-primary btn-sm w-100 mb-2 cal-position-btn" data-sensor="right">
                                <i class="bi bi-truck"></i> Scale car here
                            </button>
                            <button type="button" class="btn btn-primary btn-sm w-100 mb-2 cal-weigh-btn" data-sensor="right" disabled>
                                <i class="bi bi-speedometer"></i> Weigh
                            </button>
                            <div class="form-check form-check-sm mb-2">
                                <input class="form-check-input cal-fine-toggle" type="checkbox" id="sensorFineTune-right" data-sensor="right" disabled>
                                <label class="form-check-label small" for="sensorFineTune-right">Fine tune (±0.01 t)</label>
                            </div>
                            <div class="input-group input-group-sm cal-adj-group" data-sensor="right">
                                <button type="button" class="btn btn-outline-secondary cal-adj-btn" data-sensor="right" data-direction="down" disabled>
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                                <input type="text" class="form-control text-center" id="sensorAdjustInput-right" value="0.00" readonly>
                                <button type="button" class="btn btn-outline-secondary cal-adj-btn" data-sensor="right" data-direction="up" disabled>
                                    <i class="bi bi-chevron-up"></i>
                                </button>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-1 cal-adj-reset-btn" data-sensor="right" disabled>
                                Reset adjustment
                            </button>
                        </div>
                    </div>
                </div>

                <div class="sensor-average mb-3">
                    <div class="scale-display">
                        <div class="label">Average of 3 sensors</div>
                        <div><span class="value" id="calAverageDisplay">—</span> <span class="unit">tons</span></div>
                        <div class="small mt-2" style="color:#6bdc6b;">
                            Expected test car: <span id="calExpected">30.00</span> t &nbsp;|&nbsp;
                            Average adjustment: <span id="calAverageAdjustment">—</span> t
                            <span class="text-muted" id="calAverageMeta"></span>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <button type="button" class="btn btn-success btn-sm" id="calSaveBtn" disabled>
                        <i class="bi bi-lock"></i> Save calibration
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="calResetBtn">Reset calibration</button>
                    <span id="calStatus" class="small ms-2"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
const CONFIG = <?php echo json_encode($config, JSON_UNESCAPED_SLASHES); ?>;
const PRECISION = CONFIG.precision ?? 2;

let currentCar = null;
let currentProfile = null;
let currentReading = null;
let currentRouting = null;
let selectedCarId = null;
let scaleInService = true;
const SENSOR_POSITIONS = ['left', 'center', 'right'];

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

function hideOrderSection() {
    document.getElementById('orderSection').classList.add('d-none');
    document.getElementById('assignBtn').disabled = true;
    document.getElementById('assignResult').textContent = '';
    const inTrainNote = document.getElementById('inTrainAssignNote');
    if (inTrainNote) {
        inTrainNote.classList.add('d-none');
        inTrainNote.textContent = '';
    }
    const confirmWrap = document.getElementById('trainReassignConfirmWrap');
    const confirmBox = document.getElementById('trainReassignConfirm');
    if (confirmWrap) confirmWrap.classList.add('d-none');
    if (confirmBox) confirmBox.checked = false;
}

function updateAssignBtnState() {
    const select = document.getElementById('orderSelect');
    const assignBtn = document.getElementById('assignBtn');
    const confirmBox = document.getElementById('trainReassignConfirm');
    if (!select || !assignBtn) return;

    let enabled = !!select.value;
    if (currentCar && currentCar.requires_train_reassign_confirm) {
        enabled = enabled && confirmBox && confirmBox.checked;
    }
    assignBtn.disabled = !enabled;
}

function setupTrainReassignConfirm(car) {
    const wrap = document.getElementById('trainReassignConfirmWrap');
    const label = document.getElementById('trainReassignConfirmLabel');
    const confirmBox = document.getElementById('trainReassignConfirm');
    if (!wrap || !label || !confirmBox) return;

    if (car && car.requires_train_reassign_confirm) {
        const waybill = car.active_waybill || 'inbound order';
        const train = car.train_job || 'the same train';
        wrap.classList.remove('d-none');
        label.textContent =
            `I confirm set-out at the scale, unload of ${waybill}, reassignment to a new coke order, and return to ${train}.`;
        confirmBox.checked = false;
    } else {
        wrap.classList.add('d-none');
        confirmBox.checked = false;
    }
    updateAssignBtnState();
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

function setWeightLed(state, label) {
    const led = document.getElementById('weightLed');
    const panel = document.getElementById('netDisplayPanel');
    const ledLabel = document.getElementById('weightLedLabel');
    if (!led || !panel || !ledLabel) return;
    led.dataset.state = state || 'off';
    const labels = { ok: 'In range', fail: 'Out of range', off: '—', oos: 'OUT OF SERVICE' };
    ledLabel.textContent = label || labels[state] || '—';
    ledLabel.classList.toggle('oos-label', state === 'oos');
    panel.classList.toggle('out-of-range', state === 'fail');
    panel.classList.toggle('out-of-service', state === 'oos');
}

function applyScaleServiceState(scale) {
    scaleInService = !(scale && scale.out_of_service);
    const weighBtn = document.getElementById('weighBtn');
    const resultEl = document.getElementById('weighResult');

    if (!scaleInService) {
        if (weighBtn) weighBtn.disabled = true;
        setWeightLed('oos', 'OUT OF SERVICE');
        if (resultEl) {
            resultEl.innerHTML = `<span class="text-danger">${scale.message || 'Scale out of service — calibrate before weighing cars.'}</span>`;
        }
        return;
    }

    panelCleanupOutOfService();
    if (weighBtn && currentCar) {
        weighBtn.disabled = false;
    }
}

function panelCleanupOutOfService() {
    const panel = document.getElementById('netDisplayPanel');
    const ledLabel = document.getElementById('weightLedLabel');
    if (panel) panel.classList.remove('out-of-service');
    if (ledLabel) ledLabel.classList.remove('oos-label');
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

    errorEl.classList.add('d-none');
    const data = await apiGet('cars_at_scale');
    if (!data.success) {
        errorEl.textContent = data.error || 'Could not load cars at scale';
        errorEl.classList.remove('d-none');
        listEl.innerHTML = '';
        emptyEl.classList.add('d-none');
        return;
    }

    if (data.scale_status) {
        applyScaleServiceState(data.scale_status);
    }

    listEl.innerHTML = '';
    if (!data.cars.length) {
        emptyEl.classList.remove('d-none');
        document.getElementById('carPanel').classList.add('d-none');
        document.getElementById('weighBtn').disabled = true;
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
        item.innerHTML = `
            <div class="d-flex justify-content-between align-items-center gap-2">
                <div>
                    <strong>${car.reporting_marks}</strong>
                    <span class="text-muted small ms-2">${car.car_code || ''}</span>
                    ${car.weigh_source === 'in_train' && car.train_job
                        ? `<span class="badge bg-info text-dark ms-1">Train ${car.train_job}</span>`
                        : ''}
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
    }
}

async function selectCar(carId) {
    selectedCarId = carId;
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

    if (info && info.saved_at) {
        const prefix = calibrated ? 'Calibrated this session' : 'Last calibrated';
        const sessionPart = info.session_number ? 'Session ' + info.session_number + ' — ' : '';
        return prefix + ': ' + sessionPart + fmtTimestamp(info.saved_at);
    }

    if (info && info.calibration_unknown) {
        return 'Last calibration unknown';
    }

    return calibrated
        ? 'Calibrated this session'
        : 'Last calibration unknown';
}

function updateCalibrationMeta(cal) {
    const text = calibrationMetaText(cal);
    const calibrated = !!(cal && (cal.calibrated_this_session || cal.calibration_locked));
    const className = 'small mb-2 ' + (calibrated ? 'text-success' : 'text-danger');
    const weighEl = document.getElementById('weighCalibrationMeta');
    const calEl = document.getElementById('calCalibrationMeta');
    [weighEl, calEl].forEach(el => {
        if (!el) return;
        el.textContent = text;
        el.className = className;
    });
    if (cal && cal.scale_status) {
        applyScaleServiceState(cal.scale_status);
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

function setMode(mode) {
    document.getElementById('weighPanel').classList.toggle('active', mode === 'weigh');
    document.getElementById('calibratePanel').classList.toggle('active', mode === 'calibrate');
    document.getElementById('weighCarSection').classList.toggle('d-none', mode !== 'weigh');
    if (mode === 'calibrate') {
        refreshCalibrationState();
    } else {
        refreshCalibrationMeta();
    }
}

async function refreshCalibrationMeta() {
    const data = await apiGet('calibration_state');
    if (data.success) {
        updateCalibrationMeta(data.calibration);
    }
}

document.getElementById('modeWeigh').addEventListener('change', () => setMode('weigh'));
document.getElementById('modeCalibrate').addEventListener('change', () => {
    setMode('calibrate');
    refreshCalibrationState();
});

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
    return res.json();
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
        ? ('In train · ' + (data.car.train_job || 'South Yard job'))
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
            `<span class="text-danger">${(data.scale_status && data.scale_status.message) || 'Scale out of service — calibrate before weighing cars.'}</span>`;
    } else {
        document.getElementById('weighResult').innerHTML = tareOnly
            ? 'Scale test car — weigh to verify tare weight on the scale.'
            : (assignedMsg
                || (unloadingAssign
                    ? '<span class="text-muted">Unloading — weigh, then assign to a new coke order (prior order closes on assign).</span>'
                    : (inboundTrainAssign
                        ? `<span class="text-muted">In train on <strong>${data.car.active_waybill || 'inbound order'}</strong> to the scale — weigh, then assign outbound or reload (confirm train workflow on assign).</span>`
                        : (data.car.weigh_source === 'in_train'
                            ? '<span class="text-muted">In train — weigh, then assign; set-out, unload, and return to '
                                + (data.car.train_job || 'the same train') + ' run automatically on assign.</span>'
                            : 'Ready to weigh.'))));
    }
    document.getElementById('displayGross').textContent = '0.00';
    document.getElementById('displayNet').textContent = '0.00';
    if (scaleInService) {
        setWeightLed('off');
    }
    hideOrderSection();
}

document.getElementById('refreshCarsBtn').addEventListener('click', () => loadCarsAtScale());

document.getElementById('weighBtn').addEventListener('click', async () => {
    if (!currentCar || document.getElementById('weighBtn').disabled) return;
    const data = await apiPost('weigh', { reporting_marks: currentCar.reporting_marks });
    if (!data.success) {
        document.getElementById('weighResult').innerHTML =
            `<span class="text-danger">${data.error || 'Weigh failed'}</span>`;
        return;
    }

    currentReading = data.reading;
    currentRouting = data.reading.routing;

    document.getElementById('displayGross').textContent = fmt(data.reading.gross_tons);
    document.getElementById('displayNet').textContent = fmt(data.reading.net_tons);
    const breakdownEl = document.getElementById('sensorBreakdown');
    if (data.reading.sensor_readings && data.reading.sensor_readings.length) {
        breakdownEl.textContent = data.reading.sensor_readings
            .map(s => s.position.charAt(0).toUpperCase() + ': ' + fmt(s.display_tons))
            .join(' · ');
    } else {
        breakdownEl.textContent = '';
    }

    const resultEl = document.getElementById('weighResult');
    if (data.reading.test_car_weigh) {
        resultEl.innerHTML =
            '<span class="text-muted"><i class="bi bi-info-circle"></i> Scale test car — gross is tare weight only.</span>';
        setWeightLed('off');
        document.getElementById('orderSection').classList.add('d-none');
        return;
    }
    if (data.reading.unloaded_weigh) {
        resultEl.innerHTML =
            '<span class="text-muted"><i class="bi bi-info-circle"></i> Empty car — gross is unloaded (tare) weight only.</span>';
        setWeightLed('off');
        document.getElementById('orderSection').classList.add('d-none');
        return;
    }

    const inTol = data.reading.in_tolerance;
    setWeightLed(inTol ? 'ok' : 'fail');
    if (!carNeedsAssignment(currentCar)) {
        resultEl.innerHTML = assignedOrderMessage(currentCar)
            || '<span class="text-muted">Weigh complete.</span>';
        hideOrderSection();
        return;
    }
    resultEl.innerHTML = inTol
        ? `<span class="routing-outbound"><i class="bi bi-check-circle"></i> Within ±${fmt(data.reading.tolerance_tons)} t of target — assign to outbound coke order.</span>`
        : `<span class="routing-reload"><i class="bi bi-exclamation-triangle"></i> Off by ${fmt(data.reading.delta_tons)} t — assign to coke reload.</span>`;

    await loadOrders(currentRouting);
});

async function loadOrders(routing) {
    if (!currentCar || !carNeedsAssignment(currentCar)) {
        hideOrderSection();
        return;
    }
    const data = await apiGet('open_orders', { car_id: currentCar.id, routing });
    if (!data.success) return;

    const section = document.getElementById('orderSection');
    section.classList.remove('d-none');

    const inTrainNote = document.getElementById('inTrainAssignNote');
    if (inTrainNote) {
        if (currentCar && currentCar.requires_train_reassign_confirm) {
            inTrainNote.classList.remove('d-none');
            inTrainNote.innerHTML =
                '<i class="bi bi-info-circle"></i> This car is loaded in train '
                + (currentCar.train_job ? `<strong>${currentCar.train_job}</strong> ` : '')
                + 'on inbound order <strong>' + (currentCar.active_waybill || '—') + '</strong> '
                + 'to the scale. Assign replaces that order and returns the car to the train.';
        } else if (currentCar && currentCar.weigh_source === 'in_train') {
            inTrainNote.classList.remove('d-none');
            inTrainNote.innerHTML =
                '<i class="bi bi-info-circle"></i> Assign will set out at the scale, unload the inbound order, fill the selected order, re-assign to '
                + (currentCar.train_job || 'the same train') + ', and pick the car back up.';
        } else {
            inTrainNote.classList.add('d-none');
            inTrainNote.textContent = '';
        }
    }

    setupTrainReassignConfirm(currentCar);

    const badge = document.getElementById('routingBadge');
    if (routing === 'reload') {
        badge.className = 'badge bg-danger';
        badge.textContent = 'Coke Reload';
    } else {
        badge.className = 'badge bg-success';
        badge.textContent = 'Outbound Coke';
    }

    const select = document.getElementById('orderSelect');
    select.innerHTML = '<option value="">— Select an order —</option>';
    data.orders.forEach(order => {
        const opt = document.createElement('option');
        opt.value = order.waybill_number;
        opt.textContent = `${order.waybill_number} · ${order.shipment_code} → ${order.unloading_location}`;
        if (order.special_instructions) {
            opt.textContent += ` (${order.special_instructions})`;
        }
        select.appendChild(opt);
    });

    document.getElementById('orderEmptyMsg').classList.toggle('d-none', data.orders.length > 0);

    const genWrap = document.getElementById('generateButtons');
    genWrap.innerHTML = '';
    (data.shipment_codes || []).forEach(code => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-primary btn-sm';
        btn.innerHTML = `<i class="bi bi-plus-circle"></i> Generate ${code}`;
        btn.addEventListener('click', () => generateOrder(code, routing));
        genWrap.appendChild(btn);
    });

    document.getElementById('assignBtn').disabled = true;
    select.onchange = () => updateAssignBtnState();
}

document.getElementById('trainReassignConfirm')?.addEventListener('change', () => updateAssignBtnState());

async function generateOrder(shipmentCode, routing) {
    if (!currentCar) return;
    const data = await apiPost('generate_order', {
        shipment_code: shipmentCode,
        car_id: currentCar.id,
    });
    if (!data.success) {
        alert(data.error || 'Generate failed');
        return;
    }
    await loadOrders(routing);
    const select = document.getElementById('orderSelect');
    select.value = data.waybill_number;
    updateAssignBtnState();
}

document.getElementById('assignBtn').addEventListener('click', async () => {
    const waybill = document.getElementById('orderSelect').value;
    if (!waybill || !currentCar) return;

    const payload = {
        waybill_number: waybill,
        car_id: currentCar.id,
    };
    if (currentCar.requires_train_reassign_confirm) {
        const confirmBox = document.getElementById('trainReassignConfirm');
        if (!confirmBox || !confirmBox.checked) {
            document.getElementById('assignResult').innerHTML =
                '<span class="text-danger">Check the confirmation box before assigning.</span>';
            return;
        }
        payload.confirm_train_reassign = true;
    }

    const data = await apiPost('assign', payload);
    const resultEl = document.getElementById('assignResult');
    if (!data.success) {
        resultEl.innerHTML = `<span class="text-danger">${data.error || 'Assign failed'}</span>`;
        return;
    }
    const unloadNote = (data.unloaded_first || data.closed_prior_order)
        ? '<span class="text-muted">Prior order closed · </span>'
        : '';
    const workflowNote = formatInTrainWorkflowNote(data);
    const workflowHtml = workflowNote
        ? `<div class="text-muted mt-1">${workflowNote}</div>`
        : '';
    resultEl.innerHTML =
        `<span class="text-success"><i class="bi bi-check-circle"></i> ${unloadNote}${data.message} (${data.car_reporting_marks})</span>`
        + workflowHtml;
    currentCar.has_active_order = true;
    currentCar.active_waybill = data.waybill_number || waybill;
    currentCar.needs_assignment = false;
    currentCar.requires_train_reassign_confirm = false;
    if (data.returned_to_train) {
        currentCar.weigh_source = 'in_train';
        currentCar.train_job = data.train_job || currentCar.train_job;
    }
    hideOrderSection();
    document.getElementById('weighResult').innerHTML = assignedOrderMessage(currentCar);
    if (currentReading) {
        setWeightLed(currentReading.in_tolerance ? 'ok' : 'fail');
    }
    await loadCarsAtScale();
});

function updateCalTrackCar(position) {
    const trackCar = document.getElementById('calTrackCar');
    const active = position && SENSOR_POSITIONS.includes(position) ? position : 'left';
    SENSOR_POSITIONS.forEach(pos => {
        trackCar.classList.remove('position-' + pos);
    });
    trackCar.classList.remove('d-none');
    trackCar.classList.add('position-' + active);
}

function renderCalibration(cal) {
    if (!cal) return;

    updateCalibrationMeta(cal);

    const calibrationLocked = !!cal.calibration_locked;

    document.getElementById('calExpected').textContent = fmt(cal.expected_tons);
    if (cal.test_car) {
        const tc = cal.test_car;
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
    const scaleLocation = cal.scale_location || 'SOUTH-YARD-SCALE';

    if (testCarAtScale) {
        updateCalTrackCar(cal.scale_car_position || 'left');
    } else {
        document.getElementById('calTrackCar').classList.add('d-none');
    }

    (cal.sensors || []).forEach(sensor => {
        const pos = sensor.position;
        const card = document.getElementById('sensorCard-' + pos);
        const carHere = testCarAtScale && !!sensor.car_at_position;
        card.classList.toggle('car-at-position', carHere);
        card.classList.toggle('adjustment-locked', !!sensor.adjustment_locked);
        card.classList.toggle('calibrated', !!sensor.is_zero);

        const badge = document.getElementById('sensorCarHere-' + pos);
        badge.classList.toggle('d-none', !carHere);

        const posBtn = card.querySelector('.cal-position-btn');
        posBtn.classList.toggle('active', carHere);
        posBtn.disabled = !testCarAtScale || calibrationLocked;

        const weighBtn = card.querySelector('.cal-weigh-btn');
        weighBtn.disabled = !carHere || calibrationLocked;

        document.getElementById('sensorDisplay-' + pos).textContent =
            sensor.display_tons !== null && sensor.display_tons !== undefined
                ? fmt(sensor.display_tons)
                : '—';
        document.getElementById('sensorError-' + pos).textContent =
            sensor.has_reading ? fmt(sensor.error_tons) : '—';
        document.getElementById('sensorAdjustInput-' + pos).value = fmt(sensor.adjustment_tons);
        document.getElementById('sensorAdj-' + pos).textContent =
            'adj ' + fmt(sensor.adjustment_tons) + ' (step ±' + fmt(sensor.adjust_step_tons || 0.1) + ' t)';

        const fineToggle = document.getElementById('sensorFineTune-' + pos);
        if (fineToggle) {
            fineToggle.checked = !!sensor.fine_tune;
            fineToggle.disabled = calibrationLocked || !!sensor.adjustment_locked || !sensor.has_reading;
        }

        card.querySelectorAll('.cal-adj-btn').forEach(btn => {
            btn.disabled = calibrationLocked || !!sensor.adjustment_locked;
        });
        const resetBtn = card.querySelector('.cal-adj-reset-btn');
        if (resetBtn) {
            resetBtn.disabled = calibrationLocked || !!sensor.adjustment_locked;
        }
    });

    document.getElementById('calResetBtn').disabled = calibrationLocked;
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
            `(${cal.average.sensor_count || 0} of 3 sensors weighed)`;
    } else {
        document.getElementById('calAverageDisplay').textContent = '—';
        document.getElementById('calAverageAdjustment').textContent = '—';
        document.getElementById('calAverageMeta').textContent = '(weigh each position to build average)';
    }

    const statusEl = document.getElementById('calStatus');
    const sessionsSince = cal.scale_status ? cal.scale_status.sessions_since_calibration : 0;
    const lastCal = cal.last_calibration || {};
    if (calibrationLocked) {
        const savedAt = cal.calibration_saved_at ? ` (${fmtTimestamp(cal.calibration_saved_at)})` : '';
        statusEl.innerHTML =
            '<span class="text-success"><i class="bi bi-lock-fill"></i> Calibration saved and locked for this session'
            + savedAt + '.</span>';
    } else if (sessionsSince > 0 && lastCal.session_number != null) {
        statusEl.innerHTML =
            `<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> `
            + `Session ${sessionsSince} since last calibration (Session ${lastCal.session_number}) — `
            + `scale drift applies. Weigh each sensor, adjust to zero error, then save.</span>`;
    } else if (!testCarAtScale) {
        const loc = cal.test_car && cal.test_car.current_location
            ? cal.test_car.current_location
            : 'not at scale';
        statusEl.innerHTML =
            `<span class="text-warning"><i class="bi bi-exclamation-triangle"></i> `
            + `Place scale test car at <strong>${scaleLocation}</strong> to calibrate `
            + `(currently: ${loc}).</span>`;
    } else if (cal.all_calibrated) {
        statusEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> All sensors calibrated.</span>';
    } else if (cal.scale_car_position) {
        statusEl.textContent = 'Scale car marked at ' + cal.scale_car_position + ' sensor — weigh, then adjust to zero error.';
    } else {
        statusEl.textContent = 'Mark which sensor the scale car is on, then weigh that position.';
    }
}

async function setScaleCarPosition(position) {
    const data = await apiPost('calibrate_set_position', { position });
    if (!data.success) {
        document.getElementById('calStatus').innerHTML =
            `<span class="text-danger">${data.error || 'Could not set position'}</span>`;
        return;
    }
    renderCalibration(data.calibration);
}

async function weighSensor(position) {
    const data = await apiPost('calibrate_read', { position });
    if (!data.success) {
        document.getElementById('calStatus').innerHTML =
            `<span class="text-danger">${data.error || 'Weigh failed'}</span>`;
        return;
    }
    renderCalibration(data.calibration);
}

async function adjustSensor(sensor, direction) {
    const data = await apiPost('calibrate_adjust', { sensor, direction });
    if (!data.success) {
        document.getElementById('calStatus').innerHTML =
            `<span class="text-danger">${data.error || 'Adjust failed'}</span>`;
        return;
    }
    renderCalibration(data.calibration);
}

async function resetSensorAdjustment(sensor) {
    const data = await apiPost('calibrate_adjust_reset', { sensor });
    if (!data.success) {
        document.getElementById('calStatus').innerHTML =
            `<span class="text-danger">${data.error || 'Reset failed'}</span>`;
        return;
    }
    renderCalibration(data.calibration);
}

async function setSensorFineTune(sensor, enabled) {
    const data = await apiPost('calibrate_set_fine_tune', { sensor, enabled });
    if (!data.success) {
        document.getElementById('calStatus').innerHTML =
            `<span class="text-danger">${data.error || 'Could not update fine tune'}</span>`;
        await refreshCalibrationState();
        return;
    }
    renderCalibration(data.calibration);
}

document.querySelectorAll('.cal-position-btn').forEach(btn => {
    btn.addEventListener('click', () => setScaleCarPosition(btn.dataset.sensor));
});

document.querySelectorAll('.cal-weigh-btn').forEach(btn => {
    btn.addEventListener('click', () => weighSensor(btn.dataset.sensor));
});

document.querySelectorAll('.cal-adj-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (btn.disabled) return;
        adjustSensor(btn.dataset.sensor, btn.dataset.direction);
    });
});

document.querySelectorAll('.cal-adj-reset-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (btn.disabled) return;
        resetSensorAdjustment(btn.dataset.sensor);
    });
});

document.querySelectorAll('.cal-fine-toggle').forEach(toggle => {
    toggle.addEventListener('change', () => {
        if (toggle.disabled) return;
        setSensorFineTune(toggle.dataset.sensor, toggle.checked);
    });
});

async function saveCalibration() {
    const data = await apiPost('calibrate_save');
    if (!data.success) {
        document.getElementById('calStatus').innerHTML =
            `<span class="text-danger">${data.error || 'Could not save calibration'}</span>`;
        return;
    }
    renderCalibration(data.calibration);
}

document.getElementById('calSaveBtn').addEventListener('click', saveCalibration);

async function refreshCalibrationState() {
    const data = await apiGet('calibration_state');
    if (data.success) {
        renderCalibration(data.calibration);
    }
}

document.getElementById('calResetBtn').addEventListener('click', async () => {
    const data = await apiPost('calibrate_reset');
    if (!data.success) {
        document.getElementById('calStatus').innerHTML =
            `<span class="text-danger">${data.error || 'Could not reset calibration'}</span>`;
        return;
    }
    if (data.calibration) {
        renderCalibration(data.calibration);
        return;
    }
    await refreshCalibrationState();
});

refreshCalibrationState();
loadCarsAtScale();
</script>
</body>
</html>
