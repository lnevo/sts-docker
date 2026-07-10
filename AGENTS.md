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

## REST API (wagons / scanning)

Separate from operational steps: `sts/api/` — documented in `sts/api/README.md`.
