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

Session workflow editor, recipe CSV/JSON, and simulator HTTP API.

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

**Deploy:** `docker cp sts/operational_steps_api.php sts-docker-web-1:/var/www/html/sts/`
(and the `.openapi.yaml` + `-docs.html` siblings). Or copy all changed `sts/`
files per `CLAUDE.md`.

**Helpers:** Host-side sync/validate scripts (e.g. catalog test matrix, workflow
CSV generation) belong in the parent **`sts-docker-helpers/`** repo, not under
`sts-docker/sts/`.

### Session runtime (branches)

| Branch | Contents |
|--------|----------|
| `workflow-editor` | Session editor UI/API, catalog, switch-list helpers — **no** `warm_start_helpers.php` or track-scale CLI |
| `track-scale` | Warm-start simulation, CK1 scale, `warm_start_helpers.php` |
| `active` | `track-scale` + session editor layered for local full-stack runs |

Bootstrap: `sts/session_runtime.php` loads `warm_start_helpers.php` when present,
then `sts/session_simulator_ops.php` (filtered fill/reposition/load-unload and
play-session composites). Recipe editing works on `workflow-editor`; simulator
dispatch needs `active` or a merge with `track-scale`.

**Data model:** The workflow CSV is the only persisted recipe. Rows are compiled
from the catalog (`GET ?action=catalog`); jobs, locations, and stations come from
the DB via `dynamic_options`, not hardcoded PHP lists. Switch-list phases replay
the CSV steps in a dry-run transaction and capture at each `build_switchlists_sts`
step for the requested job.


Separate from operational steps: `sts/api/` — documented in `sts/api/README.md`.
