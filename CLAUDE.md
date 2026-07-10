# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with
code in this repository.

## Knowledge base

Shared context lives in `llm-wiki/INDEX.md`. Read the index first, then load
only the page whose `load_when` trigger matches the task. Don't bulk-load
everything.

## What this is

A containerised fork of the Shipper-Driven Traffic Simulator (STS), a model
railway operations app. Two implementations live side by side:

- **`app/` — STS v2 (active development).** A ground-up rebuild in SvelteKit +
  TypeScript + SQLite (better-sqlite3, adapter-node, single container). All
  business rules are specified in `docs/SPEC.md` — treat it as the source of
  truth; cite it when changing behaviour. See `app/README.md` for commands
  (`npm run dev|check|build`, `npm run import-legacy`,
  `docker compose --profile v2 up --build`).
- **`sts/` — legacy PHP app (maintenance only).** Plain PHP 8 + Apache +
  MariaDB. No Composer, no test suite, no build step — flat PHP files served
  as-is. The sections below describe this legacy app.

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
- **REST API:** `sts/api/` (router in `index.php`, endpoints in
  `endpoints/wagon.php`) provides JSON endpoints mirroring `scan_car.php`,
  `scan_location.php`, `load_unload.php`, and `reposition.php`. No
  authentication by design (private-network use). Documented in
  `sts/api/README.md`.
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
