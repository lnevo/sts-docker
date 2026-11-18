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
| Addons | Optional addons from the sts-plugins repo (see its README); mounted at `sts/plugins/` and reached only through `plugins_*` helpers. Core runs with none installed. |
| Switch lists | `master_switchlist_helpers.php`, `generate_master_switchlists.php` |

Recipe CSVs live on the **`sts-docker-data/backups`** bind mount (`backups/session_editor/`), not in the image.

## Optional host-only CLIs

The old begin, play and warm-start command-line scripts are gone from **`sts-docker-helpers/sts/`**; the API tasks in `sts/api/tasks/` do that work. Nothing in the image needs a host-side CLI.

## Verify before build

From the folder that holds both `sts-docker` and `sts-helpers` checkouts:

```bash
sts-helpers/bin/verify_sts_runtime.sh
```
