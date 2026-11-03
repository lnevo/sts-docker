# Session workflow runtime (self-contained in this image)

The Docker image copies all of `sts/` to `/var/www/html/sts/`. After `docker compose build`, the **workflow editor**, **operational steps API**, and **session simulator** run without hot-sync from `sts-docker-helpers/`.

## Simulator stack

| Layer | Files |
|-------|--------|
| UI | `editor.html`, `workflow-shared.js`, `workflow-ui.css` |
| Recipe API | `operational_steps_api.php`, `operational_steps_catalog.php`, `save_operational_steps.php` |
| Simulator API | `simulator_api.php`, `session_simulator_helpers.php` |
| Recipe runner | `session_helpers.php`, `session_run_recipe()` |
| Dispatch | `operational_steps_dispatch_step()` in catalog |
| Bootstrap | `session_runtime.php` → `plugin_registry.php`, `warm_start_helpers.php`, `session_simulator_ops.php` |
| Orders | `generate_order_helpers.php`, `fill_order_helpers.php`, `drain_unfilled_orders.php` (Cancel Orders) |
| Plugins | `plugin_registry.php` — discovers `plugins/*/plugin.php` and `STS_PLUGINS_DIR`; core calls only `plugins_*` helpers |
| Track scale (addon) | `sts-plugins` repo, `plugins/track_scale/` (mounted at `sts/plugins/track_scale/`) — GUI + workflow weigh/calibrate steps, served via `plugin.php?id=track_scale&page=index`; ajax via `&page=ajax` |
| Coke orders (addon) | `sts-plugins` repo, `plugins/coke_orders/` — outbound coke replenishment catalog step; maps legacy `keep_coke` to `keep_prefixes` |
| Switch lists | `master_switchlist_helpers.php`, `generate_master_switchlists.php` |

Recipe CSVs live on the **`sts-docker-data/backups`** bind mount (`backups/session_editor/`), not in the image.

## Optional host-only CLIs

Legacy command-line scripts (`begin_operating_session.php`, `simulate_warm_start.php`, etc.) remain in **`sts-docker-helpers/sts/`** for old bin wrappers. They are not required for the in-browser simulator or `simulator_api.php`.

## Verify before build

From `external/` in the hart repo:

```bash
sts-helpers/bin/verify_sts_runtime.sh
```
