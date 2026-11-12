# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with
code in this repository.

## Knowledge base

Current state, gate and open items: `docs/plans/HANDOFF.md` (read it at the
start of a session, keep it current).

Shared context lives in `llm-wiki/INDEX.md`. Read the index first, then load
only the page whose `load_when` trigger matches the task. Don't bulk-load
everything.

## What this is

A containerised fork of the Shipper-Driven Traffic Simulator (STS), a model
railway operations app. This repo holds the legacy app in `sts/`: plain PHP 8 +
Apache + MariaDB. No Composer, no test suite, no build step — flat PHP files
served as-is. STS v2 (`app/`) is not in this repo.

## Commands

```bash
# Build and run from local source (app at http://localhost:8980/sts/)
docker compose up --profile build --build

# Run the pre-built image instead
docker compose up --profile prebuilt

# Live-update a running container without a rebuild (primary dev loop)
docker cp sts/some_file.php sts-docker-web-1:/var/www/html/sts/some_file.php

# Copy everything modified since the last commit into the running container
git diff --name-only | grep '^sts/' | xargs -I{} docker cp {} sts-docker-web-1:/var/www/html/{}

# Throwaway sandbox stack (project sts-sandbox, port 8981) for parity runs;
# never run sts-helpers simulation scripts against the live stack
bin/sandbox_baseline.sh <label>

# Plugin boundary lint (also run by .github/workflows/plugin-boundary.yml)
bin/check_plugin_boundary.sh
```

The app lives at `/var/www/html/sts/` inside the `sts-docker-web-1` container;
`docker cp` of `sts/foo.php` preserves the path prefix. After validating in the
live container, commit and rebuild
(`docker compose down && docker compose up -d --build`).

CI runs GitHub super-linter on push (`.github/workflows/super-linter.yml`) and
publishes the image via `publish-docker.yaml`.

## Architecture

- **Page-per-file PHP, no framework.** Each `.php` file in `sts/` is a complete
  page (or AJAX endpoint). Static entry points are `index.html`,
  `operations.html`, `reports.html`, `database.html`, `db-maint.html`.
- **Database access:** every page calls `open_db()` from `sts/open_db.php`
  (returns a mysqli connection `$dbc`). Credentials come from
  `sts/credentials.php`, which reads `MYSQL_HOST/USER/PASSWORD/DATABASE` env
  vars (set in `docker-compose.yml`) with hardcoded fallbacks. `open_db()` also
  performs runtime schema migrations (creates `owners`/`history` tables, adds
  columns if missing) — schema changes that must survive existing installs go
  there, not only in `create_sts_db3.sql`.
- **Database provisioning:** `start.sh` (container entrypoint) runs
  `sts/create_sts_db3.sql` only when `PROVISION_DATABASE=1`, and only once —
  it's guarded by an `/opt/sql.initialized` marker file inside the container, so
  toggling the env var back off after a reinit doesn't matter; what matters is
  whether that marker exists (survives container restarts, not recreation).
  MariaDB runs with `--sql_mode=""` (STRICT_TRANS_TABLES breaks
  `pu_criteria.php`).
- **AJAX fragment pattern:** pages like `build_switchlists.php`,
  `organize_cars.php`, `pick_up.php`, `set_out.php` load car tables via AJAX
  from `get_*.php` files that return raw `<table>` HTML fragments. **Never add
  `<style>` blocks to these fragment files** — CSS bleeds into the parent page.
  All CSS belongs in the parent full-page file.
- **Single-file report pattern:** report pages (e.g.
  `display_station_report.php`) handle both the form view and the report output
  in one file — `?generate_report=1` outputs the report HTML and `exit`s; the
  form fetches it and injects into `#report-content`. Do not create separate
  `printable_*` versions for new reports (legacy `printable_*.php` files still
  exist for older reports).
- **Plugins:** addons live in the sibling repo `../sts-plugins` (under
  `plugins/<id>/plugin.php`) and are bind-mounted onto `sts/plugins/` by
  docker-compose; the image ships that directory empty. The release stack
  (`docker-compose.release.yml`) builds them into the image instead. `sts/plugin_registry.php`
  discovers `sts/plugins/*/plugin.php` plus the colon-separated `STS_PLUGINS_DIR`.
  Plugin pages are served by `sts/plugin.php?id=<plugin>&page=<slug>`. Core (`sts/`
  minus `sts/plugins/`) must not name a plugin or HART identifier and reaches
  plugins only via `plugins_*` helpers; `bin/check_plugin_boundary.sh` enforces
  this. Contract: `docs/plans/plugin-hook-contract.md`.
- **REST API v1:** `/sts/api/v1/` (router `sts/api/index.php`, routes in
  `sts/api/v1/routes_*.php`, shared code in `sts/api/lib/`, long jobs as tasks
  in `sts/api/tasks/`). Swagger UI at `/sts/api/docs/`, merged OpenAPI 3.1 at
  `/sts/api/v1/openapi.json` (fragments in `sts/api/openapi/*.json`; add a route
  and its fragment together). Bearer tokens with roles reader < operator <
  editor < admin; tokens live in `/var/lib/sts` (host `../sts-docker-data/config`),
  bootstrap admin token in `bootstrap_admin.token` there. Legacy
  `/sts/api/wagon/*` URLs keep their old shapes. Contract:
  `docs/plans/2026-11-03-api-v1.md`; usage: `sts/api/README.md`.
- **Agent plugin:** `agent/` (MCP server `servers/sts_api.py`, skill
  `skills/sts-api`, agents `sts-inspector` / `sts-operator`); marketplace entry
  at `.claude-plugin/marketplace.json`. Tests: `python3 -m unittest discover -s
agent/tests`.
- **Operational Steps API:** `sts/operational_steps_api.php` — session workflow
  editor, recipe compile/save, simulator. OpenAPI + Swagger UI:
  `sts/operational_steps_api.openapi.yaml`, `sts/operational_steps_api-docs.html`.
  Command/param schema: `GET ?action=catalog`. Maintainer notes in `AGENTS.md`.

## Database schema pitfalls

See `llm-wiki/api-schema-pitfalls.md` for the full discovered schema, REST API
endpoint behaviour, and known pitfalls (e.g. `locations.station` not
`station_id`, `car_orders.shipment` not `destination`, ambiguous-column guards,
`update_car_positions.php` keys on `cars.id` not `reporting_marks`).

## UI conventions

See `llm-wiki/ui-conventions.md` for the full UI steering doc — colour palette,
component patterns, print CSS, touch/mobile rules, and the `organize_cars.php`
drag-and-drop engine. Follow it for any `sts/` page work.
