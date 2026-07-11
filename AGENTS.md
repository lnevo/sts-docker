# AGENTS.md

A containerised fork of the Shipper-Driven Traffic Simulator (STS), a model
railway operations app. Two implementations live side by side: `app/` (STS v2 —
SvelteKit + TypeScript + SQLite, active development) and `sts/` (legacy PHP 8 +
Apache + MariaDB, maintenance only).

## Knowledge base

Shared context lives in `llm-wiki/INDEX.md`. Read the index first, then load
only the page whose `load_when` trigger matches the task. Don't bulk-load
everything.

## Project instructions

Full architecture, commands, and conventions for this repo are in `CLAUDE.md` at
the repo root — read it too, it's tool-agnostic despite the name.

## Operational Steps API (legacy `sts/`)

Session workflow editor, recipe JSON, and simulator HTTP API.

| Artifact | Path |
|----------|------|
| Live endpoint | `sts/operational_steps_api.php` |
| OpenAPI spec | `sts/operational_steps_api.openapi.yaml` |
| Swagger UI | `sts/operational_steps_api-docs.html` → loads the YAML beside it |
| Command catalog (schema source) | `GET /sts/operational_steps_api.php?action=catalog` |
| Catalog implementation | `sts/operational_steps_catalog.php` |
| Editor UI | `sts/editor.html`, `sts/workflow-shared.js` |

**When you change the API** (new/removed `action`, POST fields, or response
shapes): update the file header in `operational_steps_api.php`, the action tables
in `operational_steps_api.openapi.yaml`, and this section if paths move.

**When you change catalog commands or params:** run
`php sts/generate_operational_steps_openapi_catalog.php` (or
`bin/run_catalog_tests.sh`) to refresh `operational_steps_catalog.openapi.generated.yaml`
for Swagger. The live `GET ?action=catalog` response remains authoritative at runtime.

**Deploy:** Rebuild the web image after changing `sts/` (`docker compose --profile build up -d --build`). For live dev without rebuild, `sts-docker-helpers/bin/sync_operational_steps.sh` hot-copies `sts-docker/sts/` into the running container.

**Runtime PHP** (workflow editor, catalog, session dispatch, track scale, switch-list helpers) lives in **`sts-docker/sts/`** and is included in the Docker image. **`sts-docker-helpers/sts/`** holds optional legacy CLI scripts only (`begin_operating_session.php`, `simulate_warm_start.php`, etc.) — see that folder's README.

**Helpers:** Host-side seed/sync/validate scripts belong in **`sts-docker-helpers/`** (not under `sts-docker/sts/`).

### Session runtime (in-image)

The default Docker image includes the full workflow simulator runtime under `sts/`:

- `session_runtime.php` bootstraps `warm_start_helpers.php` and `session_simulator_ops.php`
- `simulator_api.php` + `session_simulator_helpers.php` drive in-editor simulation
- `operational_steps_api.php` + catalog dispatch run recipe steps against the live DB

No hot-sync from `sts-docker-helpers/sts/` is required after rebuild. See **`sts/RUNTIME.md`** for the file list. Verify with `sts-docker-helpers/bin/verify_sts_runtime.sh`.

Optional legacy CLI scripts (`begin_operating_session.php`, `simulate_warm_start.php`, …) stay in **`sts-docker-helpers/sts/`** for host bin wrappers only.

**Data model:** Workflow JSON (`*.workflow.json` or `*.recipe.json` under
`sts-backups/session_editor/`) is the persisted recipe. Steps are compiled
from the catalog (`GET ?action=catalog`); jobs, locations, and stations come from
the DB via `dynamic_options`, not hardcoded PHP lists. Switch-list phases replay
recipe steps in a dry-run transaction and capture at each `build_switchlists_sts`
step for the requested job.


Separate from operational steps: `sts/api/` — documented in `sts/api/README.md`.
