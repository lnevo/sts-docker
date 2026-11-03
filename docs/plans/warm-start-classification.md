# warm_start_helpers.php classification

File: `sts/warm_start_helpers.php` (3960 lines). Prefix `ws_` below = `warm_start_`. Auto-derived: function bodies scanned for HART literals, in-file call graph, external callers (grep of `sts/**/*.php|js`). Classification by judgment.

## Summary

115 functions: 62 GENERIC, 40 HART, 13 MIXED. MIXED functions are generic orchestrators (config, staging, run loop, summary, report) with embedded HART branches; most branches are session-phase calls (NVL/D749/CK1/STG-SCULLY) that can become hook calls, but `run_session_work` and `run` interleave them heavily. The HART group is the CK1/coke/NVL/D749 operations plus the track_scale plugin bridge (`track_scale_available`, `car_has_routing_order`, `pick_coke_waybill`). Hard-coded station numbers (3, 9, 10, 14, 15) appear in `prep_staging_yards`, `seed_island_spotting`, `assign_nvl_scully_block`.

## Function table

| line | function | class | external callers | notes |
|---|---|---|---|---|
| 18 | ws_track_scale_available | HART | - | ids: track_scale |
| 31 | ws_station_report_featured | HART | session_helpers.php | ids: Coke, Demmler, Island, Scully, Shenango |
| 36 | ws_coke_stats_reset | HART | - | ids: coke |
| 46 | ws_coke_stats_init | HART | - | ids: coke |
| 54 | ws_outbound_coke_shipment_codes | HART | - | ids: coke |
| 64 | ws_outbound_coke_shipment_codes_sql_in | HART | - | ids: coke |
| 74 | ws_is_outbound_coke_shipment | HART | session_simulator_ops.php | ids: coke |
| 79 | ws_coke_stats | HART | plugins/track_scale/track_scale_warm_start.php, session_simulator_ops.php | ids: coke |
| 88 | ws_default_config | MIXED | - | ids: 'STG-DEMMLER', 'STG-SCULLY'; HART branch: staging_jobs default ['STG-SCULLY','STG-DEMMLER'] plus ck1/scale/stg_scully config keys; liftable: core defaults + plugin-supplied overrides via filter hook |
| 122 | ws_merge_config | MIXED | generate_master_switchlists.php, operational_steps_catalog.php, session_helpers.php, session_simulator_helpers.php | HART branch: no literals; inherits default_config |
| 188 | ws_coke_stats_copy | HART | warm_start_session_stats.php | ids: coke |
| 199 | ws_coke_stats_delta | HART | warm_start_session_stats.php | ids: coke |
| 209 | ws_location_station | GENERIC | warm_start_session_stats.php | - |
| 222 | ws_pending_pickup_ids | GENERIC | - | - |
| 227 | ws_car_order_targets_station | GENERIC | - | - |
| 266 | ws_count_shenango_pickup_for_ck1 | HART | - | ids: 'D749', 'NVL'; calls generic/mixed: car_order_targets_station, pending_pickup_ids |
| 280 | ws_count_loaded_coke_at_shenango | HART | - | ids: coke, track_scale; track_scale: track_scale_car_in_coke_fleet, track_scale_load_config |
| 307 | ws_count_ck1_on_train | HART | - | ids: 'CK1', coke; calls generic/mixed: job_id |
| 327 | ws_count_unfilled | GENERIC | - | - |
| 334 | ws_cars_at_station_count | GENERIC | - | - |
| 348 | ws_terminate_job_at_yard | GENERIC | - | - |
| 357 | ws_phased_local_job_names | HART | - | ids: 'CK1', 'D749', 'NVL' |
| 362 | ws_car_targets_any_station | GENERIC | - | - |
| 372 | ws_eligible_car_ids_for_criterion | GENERIC | master_switchlist_helpers.php | - |
| 459 | ws_assign_cars_to_job | GENERIC | master_switchlist_helpers.php, operational_steps_catalog.php | ids: STG |
| 488 | ws_release_job_cars_at_station | GENERIC | operational_steps_catalog.php | - |
| 510 | ws_assign_eligible_at_pickup_station | GENERIC | - | - |
| 540 | ws_pickup_job_at_station | GENERIC | master_switchlist_helpers.php, operational_steps_catalog.php | - |
| 583 | ws_setout_single_car | GENERIC | - | - |
| 617 | ws_setout_job_cars_for_destinations | GENERIC | master_switchlist_helpers.php | - |
| 649 | ws_setout_all_job_train | GENERIC | master_switchlist_helpers.php, operational_steps_catalog.php | - |
| 679 | ws_run_job_criterion | GENERIC | - | - |
| 714 | ws_assign_cars_at_station_for_destinations | GENERIC | - | - |
| 742 | ws_nvl_pickup_scully_from_ck1 | HART | - | ids: 'CK1', 'NVL'; calls generic/mixed: assign_cars_to_job, car_targets_any_station, job_id, pickup_job_at_station |
| 774 | ws_d749_pickup_island_demmler_outbound | HART | - | ids: 'D749'; calls generic/mixed: assign_cars_at_station_for_destinations, pickup_job_at_station, setout_job_cars_for_destinations |
| 790 | ws_run_nvl_pre_ck1 | HART | - | ids: 'NVL'; calls generic/mixed: assign_eligible_at_pickup_station, pickup_job_at_station, setout_job_cars_for_destinations |
| 801 | ws_run_nvl_post_ck1 | HART | - | ids: 'NVL'; calls generic/mixed: run_job_criterion, setout_all_job_train, setout_job_cars_for_destinations |
| 827 | ws_run_d749_phased_ops | HART | - | ids: 'D749'; calls generic/mixed: run_job_criterion, setout_all_job_train |
| 845 | ws_run_pending_stg_scully | HART | - | ids: 'STG-SCULLY'; calls generic/mixed: complete_staging_jobs, staging_backlog_for_job |
| 861 | ws_secure_locals_at_session_end | HART | - | ids: 'D749', 'NVL', 'SCL', 'STG-DEMMLER', 'STG-SCULLY'; calls generic/mixed: assign_all_ordered_cars_at_station, assign_eligible_at_pickup_station, complete_staging_jobs, location_id_by_code, pickup_job_at_station, setout_all_job_train, setout_job_at_location |
| 893 | ws_locals_secured | HART | - | ids: 'NVL'; calls generic/mixed: job_id, non_staging_work_remaining |
| 917 | ws_ck1_reload_shipment_codes | HART | - | ids: COKE, track_scale; track_scale: track_scale_load_config, track_scale_shipment_codes_for_routing |
| 926 | ws_ck1_assign_reload_cars_on_train | HART | - | ids: 'CK1', coke, track_scale; calls generic/mixed: job_id; track_scale: track_scale_car_in_coke_fleet, track_scale_load_config |
| 967 | ws_ck1_setout_train_cars_for_shipments | HART | - | ids: 'CK1', coke, track_scale; calls generic/mixed: job_id, setout_single_car; track_scale: track_scale_car_in_coke_fleet, track_scale_load_config |
| 1008 | ws_ck1_assign_reload_at_south | HART | - | ids: 'CK1', coke, track_scale; calls generic/mixed: job_id; track_scale: track_scale_car_in_coke_fleet, track_scale_load_config |
| 1057 | ws_ck1_assign_outbound_at_south | HART | - | ids: 'CK1', coke, track_scale; calls generic/mixed: job_id; track_scale: track_scale_car_in_coke_fleet, track_scale_load_config |
| 1106 | ws_ck1_setout_train_to_location | HART | - | ids: 'CK1', CK1; calls generic/mixed: apply_setout_transitions, get_session, job_id |
| 1150 | ws_run_ck1_session_ops | HART | - | ids: 'CK1', 'SOUTH', 'SOUTH-SCALE', COKE, coke, track_scale; calls generic/mixed: auto_assign_job_at_station, load_unload, location_id_by_code, pickup_job, pickup_job_at_station, setout_all_job_train; track_scale: track_scale_maybe_calibrate_scale, track_scale_run_ck1_scale_ops |
| 1222 | ws_default_fractions | GENERIC | operational_steps_catalog.php | - |
| 1236 | ws_tracked_sim_overrides | HART | - | ids: SCULLY, STG, Scully |
| 1255 | ws_play_operating_session | MIXED | - | ids: SCULLY, STG; calls HART: tracked_sim_overrides; HART branch: applies HART tracked_sim_overrides; liftable: hook supplying override set |
| 1282 | ws_advance_session | MIXED | - | ids: CK1, SCULLY, STG; calls HART: run_pending_stg_scully; HART branch: calls run_pending_stg_scully at session start and logs STG-SCULLY moves; liftable: begin/end session hooks |
| 1318 | ws_run_session_work | MIXED | - | ids: 'CK1', 'D749', 'NVL', 'STG-SCULLY', CK1, D749, DEMMLER, Demmler, NVL, SCULLY, STG, Scully, South, coke; calls HART: coke_stats_copy, locals_secured, phased_local_job_names, run_ck1_session_ops, run_d749_phased_ops, run_d749_session_start, run_nvl_post_ck1, run_nvl_pre_ck1, secure_locals_at_session_end; HART branch: orchestrates NVL pre/post CK1, D749 phased, CK1 ops, secure locals, STG-SCULLY bookends, coke stats; liftable: 'locals phase' hook list, but body is heavily interleaved |
| 1487 | ws_ck1_outbound_cars_detail | HART | - | ids: 'CK1', coke; calls generic/mixed: job_id |
| 1530 | ws_log_ck1_outbound_cars | HART | - | ids: CK1, coke; calls generic/mixed: log |
| 1553 | ws_staging_job_names | MIXED | operational_steps_catalog.php, session_helpers.php | ids: 'STG-DEMMLER', 'STG-SCULLY'; HART branch: fallback default list ['STG-SCULLY','STG-DEMMLER']; liftable: default from plugin hook/config |
| 1559 | ws_is_staging_job | GENERIC | operational_steps_catalog.php | - |
| 1564 | ws_location_code_aliases | MIXED | - | ids: 'DEM', 'DEM-IN', 'DEM-RCV', 'DEM-YARD', 'DEMMLER-YARD', 'SCL', 'SCL-IN', 'SCL-RCV', 'SCL-YARD', 'SCULLY-YARD', 'SOUTH', 'SOUTH-YARD', COKE; HART branch: alias table is entirely HART codes (SCL/DEM/SOUTH/NIL-SHEN-COKE); liftable: return [] in core, plugin filter adds aliases |
| 1581 | ws_normalize_location_code | GENERIC | - | - |
| 1593 | ws_location_id_by_code | GENERIC | operational_steps_catalog.php, session_helpers.php | - |
| 1605 | ws_non_staging_job_filter_sql | GENERIC | - | - |
| 1616 | ws_non_staging_work_remaining | GENERIC | - | - |
| 1634 | ws_prep_staging_yards | HART | - | ids: 'DEM', 'SCL', 'STG-SCULLY'; calls generic/mixed: location_id_by_code, staging_job_names |
| 1715 | ws_auto_assign_staging_only | GENERIC | - | - |
| 1744 | ws_finish_non_staging_jobs | GENERIC | - | - |
| 1768 | ws_cars_on_staging_jobs | GENERIC | - | - |
| 1787 | ws_staging_backlog_for_job | GENERIC | - | - |
| 1802 | ws_staging_backlog | GENERIC | - | - |
| 1818 | ws_staging_setout_location | GENERIC | - | - |
| 1849 | ws_pickup_staging_only | GENERIC | - | - |
| 1889 | ws_setout_staging_only | GENERIC | - | - |
| 1943 | ws_complete_staging_jobs | GENERIC | operational_steps_catalog.php | calls HART: prep_staging_yards |
| 1975 | ws_complete_staging | GENERIC | - | - |
| 1985 | ws_job_id | GENERIC | plugins/track_scale/track_scale_warm_start.php, session_helpers.php | - |
| 1993 | ws_car_at_station | GENERIC | - | - |
| 2006 | ws_auto_assign_job_at_station | GENERIC | - | - |
| 2036 | ws_pickup_job | GENERIC | master_switchlist_helpers.php, operational_steps_catalog.php | - |
| 2077 | ws_setout_job_at_location | GENERIC | operational_steps_catalog.php | - |
| 2125 | ws_assign_all_ordered_cars_at_station | GENERIC | operational_steps_catalog.php | - |
| 2158 | ws_seed_island_spotting | HART | - | - |
| 2246 | ws_run_d749_session_start | HART | - | ids: 'D749', 'SOUTH'; calls generic/mixed: assign_all_ordered_cars_at_station, location_id_by_code, pickup_job, setout_job_at_location |
| 2255 | ws_assign_nvl_scully_block | HART | - | ids: 'NVL', CK1, coke; calls generic/mixed: assign_all_ordered_cars_at_station |
| 2263 | ws_begin_ck1_test_session | HART | - | ids: 'CK1', CK1, coke, track_scale; calls generic/mixed: auto_assign_job_at_station, auto_fill, count_unfilled, create_reposition_orders, generate_orders, get_next_auto_waybill_counter, get_session, job_id, load_unload, log, pickup_job, set_session; track_scale: track_scale_maybe_calibrate_scale, track_scale_run_ck1_scale_ops |
| 2356 | ws_log | GENERIC | warm_start_session_stats.php | - |
| 2363 | ws_get_session | GENERIC | operational_steps_catalog.php, session_helpers.php, session_runtime.php, session_simulator_ops.php | - |
| 2370 | ws_set_session | GENERIC | - | - |
| 2380 | ws_get_next_auto_waybill_counter | GENERIC | - | - |
| 2401 | ws_generate_orders | GENERIC | - | - |
| 2445 | ws_auto_fill | GENERIC | - | - |
| 2465 | ws_next_e_waybill_counter | GENERIC | - | - |
| 2480 | ws_reposition_row_matches | GENERIC | - | - |
| 2510 | ws_reposition_candidates | GENERIC | - | - |
| 2553 | ws_reposition_create_order | GENERIC | - | - |
| 2584 | ws_reposition_to_home | GENERIC | - | - |
| 2617 | ws_reposition_update | GENERIC | - | - |
| 2668 | ws_create_reposition_orders | GENERIC | operational_steps_catalog.php | - |
| 2688 | ws_auto_assign_jobs | GENERIC | - | - |
| 2702 | ws_auto_assign_all | GENERIC | - | - |
| 2732 | ws_pickup_cars | GENERIC | operational_steps_catalog.php | - |
| 2794 | ws_target_setout_location | GENERIC | - | - |
| 2826 | ws_apply_setout_transitions | GENERIC | - | - |
| 2929 | ws_setout_cars | GENERIC | operational_steps_catalog.php | - |
| 3007 | ws_load_unload_filter_token_match | GENERIC | operational_steps_catalog.php, operations_train_car_filters.php, session_simulator_ops.php | - |
| 3052 | ws_load_unload_row_matches | GENERIC | operational_steps_catalog.php | - |
| 3093 | ws_load_unload | MIXED | operational_steps_catalog.php | ids: CK1, Shenango, coke; calls HART: coke_stats, is_outbound_coke_shipment; HART branch: after unload, if outbound coke shipment, increments coke stats complete_deliveries; liftable: post-unload hook |
| 3197 | ws_ck1_ensure_coke_on_train | HART | - | ids: 'CK1', coke, track_scale; calls generic/mixed: auto_assign_job_at_station, job_id, pickup_job; track_scale: track_scale_car_in_coke_fleet, track_scale_load_config |
| 3249 | ws_car_has_routing_order | HART | plugins/track_scale/track_scale_warm_start.php | ids: track_scale; track_scale: track_scale_get_car_active_order, track_scale_shipment_codes_for_routing |
| 3263 | ws_pick_coke_waybill | HART | plugins/track_scale/track_scale_warm_start.php | ids: coke, track_scale; track_scale: track_scale_car_in_pool_for_shipment, track_scale_generate_order, track_scale_get_open_orders, track_scale_shipment_codes_for_routing |
| 3285 | ws_run_ops_cycle | GENERIC | - | - |
| 3308 | ws_run | MIXED | - | ids: 'STG-SCULLY', CK1, D749, DEMMLER, Demmler, NVL, SCULLY, STG, Scully, coke; calls HART: begin_ck1_test_session, coke_stats_init; HART branch: drives advance_session, CK1 test session, coke_stats_init, STG/SCULLY/NVL/D749 reporting; liftable in parts (ck1 test + stats via hooks) |
| 3511 | ws_summarize | MIXED | session_simulator_helpers.php | ids: 'CK1', 'SOUTH', 'STG-DEMMLER', 'STG-SCULLY', coke; calls HART: coke_stats, outbound_coke_shipment_codes_sql_in; HART branch: coke_* summary keys, CK1 outbound-on-train query, SOUTH/STG-* counts; liftable: summary-extension hook returning extra keys |
| 3643 | ws_backup | GENERIC | operational_steps_catalog.php, session_helpers.php | - |
| 3653 | ws_evaluate_session_prep | MIXED | - | ids: 'D749', 'STG-SCULLY'; HART branch: STG-SCULLY backlog check and D749-on-train check; liftable: prep-check hook |
| 3740 | ws_begin_operating_session | MIXED | - | ids: 'STG-SCULLY', SCULLY, STG, Scully; HART branch: completes STG-SCULLY and emits STG-SCULLY errors; liftable: hook |
| 3838 | ws_format_begin_session_report | MIXED | - | ids: D749, SCULLY, STG, Scully, South; HART branch: report text hard-codes STG-SCULLY, D749 South setout wording; liftable: report-lines hook |

## Seams

### GENERIC/MIXED functions that call HART functions or track_scale_*

- warm_start_play_operating_session (MIXED): tracked_sim_overrides
- warm_start_advance_session (MIXED): run_pending_stg_scully
- warm_start_run_session_work (MIXED): coke_stats_copy, locals_secured, phased_local_job_names, run_ck1_session_ops, run_d749_phased_ops, run_d749_session_start, run_nvl_post_ck1, run_nvl_pre_ck1, secure_locals_at_session_end
- warm_start_complete_staging_jobs (GENERIC): prep_staging_yards
- warm_start_load_unload (MIXED): coke_stats, is_outbound_coke_shipment
- warm_start_run (MIXED): begin_ck1_test_session, coke_stats_init
- warm_start_summarize (MIXED): coke_stats, outbound_coke_shipment_codes_sql_in

### HART functions called from core files outside warm_start_helpers.php (need hook or must stay generic)

- warm_start_station_report_featured: session_helpers.php
- warm_start_is_outbound_coke_shipment: session_simulator_ops.php
- warm_start_coke_stats: plugins/track_scale/track_scale_warm_start.php, session_simulator_ops.php
- warm_start_coke_stats_copy: warm_start_session_stats.php
- warm_start_coke_stats_delta: warm_start_session_stats.php
- warm_start_car_has_routing_order: plugins/track_scale/track_scale_warm_start.php
- warm_start_pick_coke_waybill: plugins/track_scale/track_scale_warm_start.php

### track_scale_* calls by function

- warm_start_count_loaded_coke_at_shenango (HART): track_scale_car_in_coke_fleet, track_scale_load_config
- warm_start_ck1_reload_shipment_codes (HART): track_scale_load_config, track_scale_shipment_codes_for_routing
- warm_start_ck1_assign_reload_cars_on_train (HART): track_scale_car_in_coke_fleet, track_scale_load_config
- warm_start_ck1_setout_train_cars_for_shipments (HART): track_scale_car_in_coke_fleet, track_scale_load_config
- warm_start_ck1_assign_reload_at_south (HART): track_scale_car_in_coke_fleet, track_scale_load_config
- warm_start_ck1_assign_outbound_at_south (HART): track_scale_car_in_coke_fleet, track_scale_load_config
- warm_start_run_ck1_session_ops (HART): track_scale_maybe_calibrate_scale, track_scale_run_ck1_scale_ops
- warm_start_begin_ck1_test_session (HART): track_scale_maybe_calibrate_scale, track_scale_run_ck1_scale_ops
- warm_start_ck1_ensure_coke_on_train (HART): track_scale_car_in_coke_fleet, track_scale_load_config
- warm_start_car_has_routing_order (HART): track_scale_get_car_active_order, track_scale_shipment_codes_for_routing
- warm_start_pick_coke_waybill (HART): track_scale_car_in_pool_for_shipment, track_scale_generate_order, track_scale_get_open_orders, track_scale_shipment_codes_for_routing

### Reverse seam: HART-owned file calling helpers in this file

- warm_start_coke_stats (HART) used by plugins/track_scale/track_scale_warm_start.php
- warm_start_job_id (GENERIC) used by plugins/track_scale/track_scale_warm_start.php
- warm_start_car_has_routing_order (HART) used by plugins/track_scale/track_scale_warm_start.php
- warm_start_pick_coke_waybill (HART) used by plugins/track_scale/track_scale_warm_start.php

Note: GENERIC functions `warm_start_job_id` and (via coke_stats) others are consumed by the plugin; core externals `session_simulator_ops.php` use `is_outbound_coke_shipment` and `coke_stats`.

## Hard-coded HART identifiers

- `CK1`: ws_advance_session, ws_assign_nvl_scully_block, ws_begin_ck1_test_session, ws_ck1_assign_outbound_at_south, ws_ck1_assign_reload_at_south, ws_ck1_assign_reload_cars_on_train, ws_ck1_ensure_coke_on_train, ws_ck1_outbound_cars_detail, ws_ck1_setout_train_cars_for_shipments, ws_ck1_setout_train_to_location, ws_count_ck1_on_train, ws_load_unload, ws_log_ck1_outbound_cars, ws_nvl_pickup_scully_from_ck1, ws_phased_local_job_names, ws_run, ws_run_ck1_session_ops, ws_run_session_work, ws_summarize
- `D749`: ws_count_shenango_pickup_for_ck1, ws_d749_pickup_island_demmler_outbound, ws_evaluate_session_prep, ws_format_begin_session_report, ws_phased_local_job_names, ws_run, ws_run_d749_phased_ops, ws_run_d749_session_start, ws_run_session_work, ws_secure_locals_at_session_end
- `DEM`: ws_location_code_aliases, ws_prep_staging_yards
- `DEM-IN`: ws_location_code_aliases
- `DEM-RCV`: ws_location_code_aliases
- `DEM-YARD`: ws_location_code_aliases
- `DEMMLER-YARD`: ws_location_code_aliases
- `NVL`: ws_assign_nvl_scully_block, ws_count_shenango_pickup_for_ck1, ws_locals_secured, ws_nvl_pickup_scully_from_ck1, ws_phased_local_job_names, ws_run, ws_run_nvl_post_ck1, ws_run_nvl_pre_ck1, ws_run_session_work, ws_secure_locals_at_session_end
- `SCL`: ws_location_code_aliases, ws_prep_staging_yards, ws_secure_locals_at_session_end
- `SCL-IN`: ws_location_code_aliases
- `SCL-RCV`: ws_location_code_aliases
- `SCL-YARD`: ws_location_code_aliases
- `SCULLY-YARD`: ws_location_code_aliases
- `SOUTH`: ws_location_code_aliases, ws_run_ck1_session_ops, ws_run_d749_session_start, ws_summarize
- `SOUTH-SCALE`: ws_run_ck1_session_ops
- `SOUTH-YARD`: ws_location_code_aliases
- `STG-DEMMLER`: ws_default_config, ws_secure_locals_at_session_end, ws_staging_job_names, ws_summarize
- `STG-SCULLY`: ws_begin_operating_session, ws_default_config, ws_evaluate_session_prep, ws_prep_staging_yards, ws_run, ws_run_pending_stg_scully, ws_run_session_work, ws_secure_locals_at_session_end, ws_staging_job_names, ws_summarize
- Free-text (comments/log/report strings) mention Shenango, Scully, Demmler, Neville Island, South Yard, coke in many functions; see notes column `ids:`.
- `ws_station_report_featured`: 'Shenango Coke Works','Demmler Yard','Scully Yard','Neville Island'
- `ws_location_code_aliases`: SCL,DEM,SCULLY-YARD,DEMMLER-YARD,SOUTH-YARD,NIL-SHEN-COKE,SHEN-COKE-SHIPPING and -YARD/-IN/-RCV variants
- `ws_default_config`: staging_jobs STG-SCULLY, STG-DEMMLER; keys run_ck1_*, stop_when_stg_scully_ready, scale_calibrate_every_sessions, ck1_test
- `ws_prep_staging_yards`: station numbers 15,14 (offline) and 9,10 (pickup), codes SCL/DEM, job STG-SCULLY
- `ws_seed_island_spotting`: station 3 (Neville Island); `ws_assign_nvl_scully_block`: station 9
- `ws_ck1_*`, `ws_run_ck1_session_ops`: location codes SOUTH, SOUTH-SCALE, COKE (shipment codes via coke_orders_outbound_shipment_codes / track_scale_shipment_codes_for_routing)
