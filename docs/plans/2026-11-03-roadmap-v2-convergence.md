# STS roadmap: v1 hardening and convergence with Aaron's v2

Written 2026-11-03. Inputs: the review passes run that day (navigation, style,
session editor, cleanup, diff review) and a read of `aaron/milestone/v2` at
`2fddec8`. Current state and the merge gate are in [HANDOFF.md](HANDOFF.md).

## Where v2 is going

Aaron's `milestone/v2` is a ground-up rebuild in `app/`:

- **Stack:** SvelteKit 2 / Svelte 5 / TypeScript on Node 24, SQLite via
  better-sqlite3 (WAL, foreign keys on). One container, no database service.
  Compose profile `v2`, data in `~/sts-data`.
- **Scope:** the same pages as v1 (operations steps, generic `/data/[entity]`
  CRUD driven by `entities.ts`, reports, maintenance), with the business rules
  kept exactly. `docs/SPEC.md` extracts them from the PHP with file references
  and is declared the source of truth.
- **Improvements over v1:**
  - Every workflow runs in a transaction.
  - The load/unload pre-check is deterministic; v1 re-rolls `rand()` on every
    render.
  - Waybill counters use a numeric MAX (fixes M9 vs M10).
  - History is pruned on insert.
  - Logo upload; backups download as a `.db` file.
  - An importer reads a v1 `.sql` dump or a live MariaDB.
- **Kept:** the visual language (status badge colours, green operations
  navbar, print-first reports), no authentication, and the `/sts/api/wagon/*`
  paths. `hooks.ts` reroutes them, so RFID clients keep working.
- **Dropped:** per-job dynamic step tables (replaced by one `job_steps` table),
  the `fix_*.php` repair pages, `blocks` / `cars.block_id`, and the PHP
  printables (replaced by Svelte pages and a JS QR library).
- **State:** no tests yet. `docs/v2-hardening-plan.md` puts a vitest suite in
  Phase 2, ahead of behaviour changes. CI only type-checks and builds.
- **Not in v2:** the session editor, recipes and simulator, the plugin
  registry, our REST endpoints beyond the five wagon routes, and the HART warm
  start. v2 has no extension point a plugin could hook into.

### v2 schema changes against our v1

Additive (can land in v1's `open_db()` now):

- Indexes on `cars(current_location_id)`, `cars(handled_by_job_id)`,
  `car_orders(car)`, `locations(station)`, `history(car_id, session_nbr)`.
- `car_orders.shipment` is overloaded for E-waybills (it holds a location id);
  v2 splits it into `shipment_id` plus `destination_location_id`. Additive if
  dual-written.
- Per-job dynamic step tables become one
  `job_steps(job_id, step_number, station_id, pickup, setout, remarks)` table.
  The new table is additive, but writes have to go to both.
- `pu_criteria.job_id` holds a job **name** today; v2 makes it a real FK to
  `jobs(id)`. Add a new column, then switch readers over.
- `jobs.name` UNIQUE, PKs/FKs on `ownership` and `pool`, `history.id`. These
  are additive once a dedupe and orphan check passes.
- NULL-able load times and similar columns become NOT NULL DEFAULT 0, via a
  backfill.
- `blocks` and `cars.block_id` are dropped; nothing depends on them.

Breaking (only behind a data-access layer, see Phase 4):

- `routing` becomes `stations` (`station` → `name`, `station_nbr` →
  `default_setout_location_id`).
- Every FK column gets an `_id` suffix: `locations.station`,
  `shipments.consignment`, `cars.home_location`, and others.
  `RFID_code` → `rfid_code`, `Id` → `id`.
- `0` sentinels (`current_location_id`, `handled_by_job_id`,
  `car_orders.car_id`) become NULL, which changes every `= 0` and `> 0` test.
- MariaDB/latin1 is replaced by SQLite.

## Strategy

Keep v1 as the product we run, and move its **data model** toward v2 in
additive, dual-written steps. Two exits stay open:

- **(a)** v2's importer can take our database without loss.
- **(b)** our unique features can be ported onto v2 later.

The breaking renames happen only behind a data-access layer, and only once we
pick an exit. Each phase below is short branches off `active` through the
normal gate. Anything touching the simulator or warm start needs a sandbox
parity capture.

## Phase 1: fixes and cleanups (now)

From today's review passes.

- **Security and robustness:**
  - Use prepared statements or `intval` on the CRUD family. Worst cases:
    `db_list_jobs.php:32`, which runs `create table` on a raw POST value, and
    the `db_edit_*` delete-by-GET.
  - Sanitise the `remove_img.php` filename (`basename` plus a whitelist).
  - `get_table_guts.php:14` calls `$mysql_error(...)` (a fatal error in
    PHP 8); use `mysqli_error`.
  - Drop the `db_edit.php` branch that requires the missing
    `db_edit_waybills.php`.
  - `open_db.php` sets `E_ERROR` and `MYSQLI_REPORT_OFF`, which hides failed
    queries. Log them to stderr instead.
- **Dead files:** `secure.html` (no inbound links), `get_car_data_ajax.php`,
  `reload_html_table.php`, `import_tables.php.orig`, the unreferenced
  `ImageStore/GUI/Menu` imagemap JPGs (now that the banners are gone), unused
  logos in `images/`, `patches/` if already applied, and the unused
  `session_helpers.php` URL helpers (check for dynamic calls first).
- **Duplication:**
  - One escape helper (`sts_h`), with `ops_h` and `sm_h` as aliases.
  - `ops_render_navbar` and `session_nav_bar_html` share the
    `page_chrome.php` navbar partial.
  - Move the large inline `<style>` blocks into `operations_ui.css` /
    `page_chrome.css`: `fill_orders.php` 268 lines, `list_cars.php` 167,
    `operations.php` 111.
- **Still legacy:**
  - Fold the `printable_*` print layouts and their `display_*` forms into the
    single-file `?generate_report=1` pattern. This lines up with v2 dropping
    printables.
  - `db_list_*` / `db_edit_*` bodies still inject headings with JS.
  - `operational_steps_api.php` has no Bootstrap or viewport.

## Phase 2: behaviour fixes v2 already made

Code-level changes that need no schema change and bring v1's behaviour in line
with SPEC.md:

1. Wrap assign, set out, pick up, fill and generate in transactions.
2. Make the load/unload pre-check deterministic:
   `last_spotted + min_time <= session`.
3. Waybill counter by numeric MAX.
4. Prune history on insert, not on every `open_db()`.

Items 1 and 2 change simulator output, so each needs a fresh sandbox baseline
captured twice to separate them from the simulator's own randomness.

## Phase 3: additive schema convergence (in `open_db()`)

Each step is a runtime migration that existing installs pick up on the next
page load. It is mirrored in `create_sts_db3.sql`, and the old column keeps
being written until every reader has moved.

1. The five v2 indexes (`create index if not exists`). No behaviour change.
2. `car_orders.destination_location_id`: backfill from E-waybills
   (`substr(waybill_number,5,1)='E'`), dual-write, then move readers off the
   overloaded `shipment`.
3. `pu_criteria.job_ref` as a real `jobs.id`: backfill from the name and
   dual-write. `jobs.name` UNIQUE after a dedupe check.
4. `job_steps` table: backfill from the per-job tables and dual-write from the
   job editor. The simulator and the warm start keep reading the per-job tables
   until a later switch, which is a parity-checked change.
5. NOT NULL DEFAULT 0 backfills; PKs on `ownership` / `pool`; `history.id`.
6. Drop `blocks` / `cars.block_id`.

## Phase 4: data-access layer

Put table and column names behind `sts/lib/db/*.php` repository functions,
one per entity, using prepared statements (this absorbs the Phase 1 SQL
fixes). Start with cars, car_orders and locations, which the steps and the
simulator use most. Once callers go through the layer, the v2 renames
(`stations`, `*_id`, NULL sentinels) become a change in one file plus a
migration. They can start as SQL VIEWs (`stations` over `routing`) so readers
move first.

## Phase 5: decision point

Decide with Aaron once v2 has its test suite:

- **Port to v2.** Bring the session editor, simulator, REST API and a plugin
  hook mechanism into `app/`, using SPEC.md plus our `plugin-hook-contract.md`
  as the contract. Phases 3–4 make the data import lossless. Offer the
  operational steps API and the plugin contract upstream first, since v2 has
  nothing in their place.
- **Stay on v1.** Finish the renames through the Phase 4 layer, keep
  v2-compatible names, and keep `/sts/api/wagon/*` matching v2's routes so
  either backend can serve the RFID clients.

Inputs to the decision: whether v2 passes its own test suite, how complete it
is against SPEC.md, and how much of our session and simulator code depends on
PHP-side state (`session_helpers.php` is about 7k lines).

## Open questions for Aaron

- Would he take the operational steps API, session editor and simulator into
  v2, or prefer them as a plugin?
- Is SQLite a firm choice? Club installs with several tablets write
  concurrently; WAL helps, but this needs confirming.
- Does SPEC.md §10 include anything (auth, multi-user) planned but not yet
  written?
