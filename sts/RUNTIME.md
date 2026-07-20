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
| Bootstrap | `session_runtime.php` → `plugins/plugins.php`, `warm_start_helpers.php`, `session_simulator_ops.php` |
| Orders | `generate_order_helpers.php`, `fill_order_helpers.php`, `drain_unfilled_orders.php` (Cancel Orders) |
| Plugins | `plugins/plugins.php` — addon registry (operations buttons, catalog steps, dispatch) |
| Track scale (addon) | `plugins/track_scale/` — GUI + workflow weigh/calibrate steps (`track_scale.php` stub at sts root) |
| Coke orders (addon) | `plugins/coke_orders/` — outbound coke order replenishment catalog step |
| Switch lists | `master_switchlist_helpers.php`, `generate_master_switchlists.php` |

Recipe CSVs live on the **`sts-backups`** bind mount (`backups/session_editor/`), not in the image.

## Optional host-only CLIs

Legacy command-line scripts (`begin_operating_session.php`, `simulate_warm_start.php`, etc.) remain in **`sts-docker-helpers/sts/`** for old bin wrappers. They are not required for the in-browser simulator or `simulator_api.php`.

## Verify before build

From the Car Cards repo:

```bash
sts-docker-helpers/bin/verify_sts_runtime.sh
```
