# STS handoff

Read this first when starting a session on the STS work. Last updated
2026-11-03. Keep it current: it is the one file that says where things stand.

## Where things stand

- **Main line:** sts-docker branch `active` (integration branch; `master`
  tracks Aaron's upstream fork). The live container runs the tip of `active`.
- **Repos involved** (all sibling checkouts under `hart/external/`, registered
  as submodules in the hart hub):
  - `sts-docker`: the PHP app, plugin registry, boundary lint, sandbox tooling.
  - `sts-plugins` (github.com/lnevo/sts-plugins, branch `main`): addons
    `track_scale`, `coke_orders`, `hart_layout`. Bind-mounted into the web
    container by docker-compose; the image ships none.
  - `sts-helpers` (github.com/lnevo/sts-docker-helpers, branch `main`): HART
    seed, warm-start and session scripts that drive the container.
- **Done:** plugin boundary (Phase 1), HART warm-start split into the
  `hart_layout` plugin (Phase 2), upstream merge with PHP 8.4.27 and vendored
  assets (Phase 3), leftovers closed. Details and the "why" are in
  [2026-11-02-plugin-delineation.md](2026-11-02-plugin-delineation.md) and the
  hook contract in [plugin-hook-contract.md](plugin-hook-contract.md).
- **Aaron's v2 rewrite** (`milestone/v2` on his fork) was assessed and parked:
  it is a port of his base v1 with no tests and none of our session, plugin or
  simulator work. Development continues on v1.

## Rules that now apply

- Core (`sts/` minus `sts/plugins/`) never names a plugin or a HART
  identifier. `bin/check_plugin_boundary.sh` enforces it (CI workflow
  `plugin-boundary.yml`); the allowlist should only shrink.
- Plugin-specific code goes in `sts-plugins`. A change that needs a new hook
  adds the hook to core in its own commit first.
- No page links a CDN; frontend assets live under `sts/vendor/`.
- The committed `sts/operational_steps_catalog.openapi.generated.yaml` is
  core-only. Catalog test runs regenerate it that way.

## Dev loop and gate

- Live edits: `docker cp sts/<file> sts-docker-web-1:/var/www/html/sts/<file>`;
  plugin edits are live immediately (bind mount). Rebuild after committing:
  `docker compose --profile build up -d --build web`.
- Gate before merging (all must pass):
  1. `bin/check_plugin_boundary.sh` exits 0.
  2. API suite on the sandbox:
     `STS_API_URL=http://localhost:8981/sts/api/v1 STS_API_TOKEN_FILE=$STS_SANDBOX_DATA/config/bootstrap_admin.token STS_API_BACKUPS_DIR=$STS_SANDBOX_DATA/backups python3 -m unittest discover -s tests/api -p 'test_*.py'`,
     `python3 -m unittest discover -s agent/tests`, and `shellcheck -S warning -x`
     on changed scripts. Then
     `cd ../sts-helpers && RUN_WORKFLOW=0 bin/run_catalog_tests.sh` (export
     the sandbox env first to run it there; the helpers now talk to the API of
     whichever stack `COMPOSE_PROJECT_NAME` selects) prints
     `Catalog tests OK` (then `git checkout -- docs/WORKFLOW_TEST_ALL_TYPES.recipe.json`
     there and `git checkout -- sts/operational_steps_catalog.openapi.generated.yaml`
     here if they changed).
  3. Lint sweep in the container:
     `docker exec sts-docker-web-1 sh -c 'cd /var/www/html/sts && for f in *.php plugins/*/*.php; do php -l "$f" >/dev/null || echo FAIL $f; done'`.
  4. Parity for anything touching the simulator or warm start: bring up the
     sandbox (`docker-compose.sandbox.yml`, project `sts-sandbox`, port 8981,
     data under `../sts-docker-data/sandbox`), run
     `bin/sandbox_baseline.sh <label>` and diff against the newest `phase*`
     capture there (warm_start.log, sessions.log, masked db dumps,
     functions.txt must match). Never run the sts-helpers simulation scripts
     against the live stack: they restore the seed into whatever they target.
  5. Upstream super-linter only runs on `master`, so run prettier,
     markdownlint (config in `.github/linters/`) and shellcheck locally on
     touched docs and scripts.
- Merging: the owner does not review these PRs; run the gate, merge into
  `active`, push, rebuild live, then bump the submodule pins in the hart hub
  (`tools/ops/init_external_submodules.sh` and the submodule pointers).

## Open items

- Phase 4 UI and navigation pass landed 2026-11-03 (branch `ui-nav-modernize`,
  parity capture `phase5-ui-nav` matches `phase4`):
  - Step pages render `operations_nav.php`: shared green navbar plus a step bar
    (Prev/Next, jump to any step, session badge).
  - Session Editor has a home card, Steps / Run tabs, a More menu and a confirm
    before a live run; session pages link the editor and Operations.
  - About 45 legacy pages (data editors, club, report forms, maintenance) use
    `page_chrome.php`; hubs share `hub.css`. Conventions are in
    `llm-wiki/ui-conventions.md` 4.10.
- No Home or hub cards for sessions: Sessions and Session Editor are
  navbar buttons on the operations hub and every step page.
- `assign_car.php`, `fix_win2linux.php`, `manage_cargo.php` and
  `session-pages.css` were removed after a search of all three repos, the
  stored session output and the access log found no reference.
- API v1 landed 2026-11-03 ([2026-11-03-api-v1.md](2026-11-03-api-v1.md),
  "As built" lists the deviations): `/sts/api/v1/` with Swagger UI at
  `/sts/api/docs/`, token auth with roles, background tasks for restore, warm
  start, sessions and workflow runs. `sts-helpers` (branch merged to `main`)
  drives the core pipeline through it via `lib/sts_api.sh` / `lib/sts_api.py`;
  sweeps, waves and diagnostics stay on docker by design. The agent plugin is
  under `agent/`. Parity capture `phase6-api-final` matches `phase5-ui-nav`
  (masked dumps identical; logs differ only by the API target line).
- Phase 2 and the backlog waves landed 2026-11-04 (branch `api-admin`):
  - Browser sign-in, command-role policy and the API Access page (Database
    Maintenance).
  - The session editor runs on v1: read-only until signed in, role warnings
    before Run, and per-step role checks with an up-front pre-check. Defaults:
    restore, wipe and reset are admin; backup and restart are operator;
    `*seed*` and `*_locked*` backups are protected.
  - The legacy editor endpoints are write-locked.
  - Legacy SQL hardening (W1a/c/d), shared CSS and escape helper, one navbar,
    single-file report pages (fleet, QR, waybill, switch list), data editor
    bodies, `open_db.php` error logging.
  - The API test suite in `tests/api/` with CI, the agent plugin with 33
    tools, and the remaining helper paths moved onto the API.
  - Parity capture `phase9-final` matches `phase6-api-final` (masked dumps
    identical).
- Open: a CSP for the legacy pages (the HttpOnly cookie doesn't stop XSS in
  `/sts/`); `get_car_data_ajax.php` and the `fill_order_*` parsers still use
  `real_escape_string`; `get_table_guts.php` allows a single arbitrary SELECT;
  CI (`api-tests.yml`) has not run on GitHub yet; no wall-clock limit on tasks
  without `timeout_seconds`.
- Cleanups from the 2026-11-03 review, not scheduled:
  - SQL injection in the CRUD family (`db_list_jobs.php:32` runs
    `create table` on a raw POST value; the `db_edit_*` pages delete by GET).
  - `remove_img.php` unlinks a path built from a POST value.
  - `get_table_guts.php:14` calls `$mysql_error(...)`.
  - `open_db.php` hides query errors.
  - Duplicate escape helpers and navbar builders.
  - Large inline `<style>` blocks on the step pages.
  - `printable_*` and the `db_list_*`/`db_edit_*` bodies are still legacy.
- Aaron's v2 is reference only ([2026-11-03-v2-notes.md](2026-11-03-v2-notes.md));
  the roadmap does not converge on it.
- Pre-existing, untouched: `sts/db_edit.php` requires a missing
  `db_edit_waybills.php`; the QR and barcode libraries live outside `sts/`
  (phpstan cannot see them); a few include fragments and CLI scripts fatal when
  fetched directly over HTTP.
- Archive tags `archive/*` preserve the pre-consolidation feature branches;
  `workflow-editor-legacy` on origin was never reviewed for deletion.

## Working agreements for agents

- Fable designs, reviews, resolves merges and signs off; Sonnet takes
  mechanical refactors against a written brief; Haiku takes lint and docs.
- Every delegated brief names the files, the contract section, and the exact
  verification commands. Subagents never touch the live stack except through
  the sanctioned dev loop above.
