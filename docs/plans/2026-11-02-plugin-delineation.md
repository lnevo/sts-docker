# Plan: separate the HART plugins from the STS core, then merge upstream

Written 2026-11-02 against `origin/active` (88642c7). Live container
`sts-docker-web-1` was built 2026-10-31 and matches `origin/active`.

## Why

- `sts/plugins/plugins.php` already gives plugins a manifest-driven registry
  (21 hook keys: operations buttons, stats, catalog steps, dispatch, normalize,
  import guessers, test sections). The boundary leaks in a handful of places,
  and those leaks are what force every plugin change to touch core files.
- The bigger leak is `sts/warm_start_helpers.php` (3,960 lines, 115 functions,
  53 of them named for HART trains/industries: CK1, D749, NVL, Shenango, Scully,
  coke). It calls the track-scale plugin directly 38 times. HART names also sit
  in `warm_start_session_stats.php` (76 hits), `session_helpers.php` (16),
  `operational_steps_catalog.php` (7 + 11 "coke" params such as `keep_coke`),
  `drain_unfilled_orders.php` (8 coke), `session_simulator_ops.php` (4 coke),
  `master_switchlist_helpers.php`, `session-sitemap.html`, `restore_sql_helpers.php`.
- Branches were used as the separation mechanism (`workflow-editor` is "active
  minus the track-scale plugin", rebuilt by hand). That is why commits
  intertwine. The fix is a directory/repo boundary plus an enable switch, not a
  branch.

## Leak inventory (core files referencing a specific plugin)

| Core file | Leak | Fix |
| --- | --- | --- |
| `sts/session_helpers.php:579` | hard `require` of `plugins/track_scale/track_scale_helpers.php`, calls `track_scale_reset_cached_weights` | new `session_reset` hook |
| `sts/warm_start_helpers.php` | 38 `track_scale_*` calls inside HART scenario functions | move with the HART scenario code (Phase 2) |
| `sts/workflow-shared.js:968,1732` | hard-coded step ids `track_scale`, `calibrate_track_scale`, param `keep_coke` | catalog emits per-step `client_meta` (hidden params, title formatter); JS becomes generic |
| `sts/track_scale.php`, `sts/track_scale_ajax.php` | root stubs | manifest `pages` key + generic `sts/plugin.php?id=&page=` router (or keep stubs, generated) |
| `sts/operational_steps_catalog.openapi.generated.yaml` | 20 plugin entries in a committed generated file | generate core and per-plugin yaml separately, or exclude plugin steps |
| `sts/operational_steps_catalog.php`, `drain_unfilled_orders.php`, `session_simulator_ops.php` | `keep_coke` / coke order params in core commands | move to `coke_orders` plugin via `normalize_params` + a new `order_filter` hook |
| `sts/RUNTIME.md`, `sts/session-sitemap.html` | docs/links naming plugin pages | manifest-driven |

## Decisions (2026-11-02)

- Plugins move to a new dedicated repo, `sts-plugins`, checked out at
  `hart/external/sts-plugins` beside this repo. docker-compose bind-mounts its
  addons directory into the container; the published image ships no HART
  plugins.
- Full carve-out (Phase 1 and Phase 2) lands before Aaron's master is merged.
- `active` stays the integration branch; `master` keeps tracking Aaron.
- Tasks are delegated to Sonnet/Haiku subagents from the Fable session; Fable
  does design, carve-outs, merge resolution, and sign-off.

## Phase 0. Housekeeping (Fable, under an hour)

1. Check out `active` locally (HEAD is detached at 909c2de; content is identical
   to `origin/active`).
2. Confirm branch containment and delete merged branches: `ui-improvements`,
   `track-scale`, `workflow-editor`, `refresh-cars-page` are all ancestors of
   `active`. `workflow-editor-legacy` is not (8.8k lines not in active, dated
   2026-07-10); review before deleting.
3. Create `sts-plugins` (local git repo first, GitHub remote on approval).

## Phase 1. Harden the plugin boundary (no behaviour change)

Rule after this phase: nothing under `sts/` outside the plugin directory may
name a plugin id, a `track_scale_*`, `coke_orders_*` function, or a HART train or
industry. CI enforces it.

| # | Task | Model | Verify |
| --- | --- | --- | --- |
| 1.1 | Move registry to `sts/plugin_registry.php`; `sts/plugins/` becomes addon-only; add `STS_PLUGINS_DIR` env (default in-image dir) so plugins can be bind-mounted from another repo | sonnet | `php -l`, operations page renders Track Scale button |
| 1.2 | Add hooks: `session_reset`, `order_filter`, `client_meta`; replace the session_helpers require and the JS hard-coding | Fable designs contract, sonnet implements | editor renders track_scale step titles identically; session restart still resets weights |
| 1.3 | Manifest `pages` key + generic plugin page router; delete root stubs | sonnet | `/sts/track_scale.php` redirect or router returns same page |
| 1.4 | Split generated OpenAPI into core + per-plugin files; regenerate | sonnet | Swagger UI loads both |
| 1.5 | Move `keep_coke` and coke order filtering out of core catalog/drain/simulator into `coke_orders` | Fable | catalog test matrix round-trip passes; drain step output unchanged on hart_seed |
| 1.6 | Boundary lint script `bin/check_plugin_boundary.sh` + CI job | haiku | script fails on current tree before 1.1–1.5, passes after |
| 1.7 | Update `CLAUDE.md`, `AGENTS.md`, `sts/RUNTIME.md`, llm-wiki decisions log | haiku | lint passes |
| 1.8 | Fix the four missing catalog test-matrix steps (pre-existing failure since 2026-07-15) and make `run_catalog_tests.sh` regenerate core-only | sonnet | `Catalog tests OK` |
| 1.9 | Seed the workflow runner: `run_catalog_workflow.php` and the session simulator honour `STS_SIM_SEED` (one `mt_srand` at start) so sandbox runs are comparable | sonnet | two same-seed sandbox runs give identical `db.sql` |

### Sandbox baseline findings (2026-11-02)

`bin/sandbox_baseline.sh` and `docker-compose.sandbox.yml` exist; stack
`sts-sandbox` on port 8981, data under `sts-docker-data/sandbox/`. Baseline
label `baseline-88642c7`. A full run takes under a minute.

- Deterministic across runs: lint, function inventory, catalog JSON, generated
  OpenAPI, test recipe, warm-start log, and the post-warm-start DB dump once
  history timestamps are masked. These are the parity gate.
- Not deterministic: the two workflow-driven sessions (unseeded randomness in
  catalog dispatch), hence task 1.9.
- At 88642c7 the CLI warm start fatals unless the plugin registry is preloaded
  (`coke_orders_outbound_shipment_codes` undefined inside
  `warm_start_summarize`). The phase1 capture installed a CLI-only prepend shim
  to get past it; Phase 2 (hart_layout) removed the dependency:
  `warm_start_helpers.php` bootstraps the plugin registry itself and the shim
  step is gone from the script.
- `mariadb-dump` is used (the image has no `mysqldump`).

## Phase 2. Carve the HART layout out of warm start

Split `warm_start_helpers.php` into:

- `sts/session_ops_helpers.php` (core, generic): the ~40 `warm_start_*`
  functions the catalog and session pages call (get_session, stats log, pickup,
  setout, load_unload, staging, backup, config merge, reposition orders).
- `plugins/hart_layout/` (HART-specific): CK1/D749/NVL/Shenango/Scully/coke
  scenario functions, `warm_start_session_stats.php` HART sections, the 38
  track-scale calls. Registers through `dispatch_handlers`, `stats`,
  `catalog_steps_callback` and new `warm_start_scenario` hook.

| # | Task | Model | Verify |
| --- | --- | --- | --- |
| 2.1 | Function-by-function classification list (generic / HART) with callers | sonnet | reviewed by Fable |
| 2.2 | Mechanical move per the list, keep function names (aliases where needed) | sonnet | `php -l`; `sts-helpers/bin/run_session_simulations.sh` stats report byte-identical to a baseline captured before the move |
| 2.3 | Replace cross-calls with hooks; remove HART names from core | Fable | boundary lint passes; simulation parity holds |

Baseline first: run the session simulation and catalog tests on the current
image and keep the output under `sts-docker-data/backups/baseline-2026-11/`.

## Phase 3. Merge Aaron's master (37 commits)

- Dockerfile merges cleanly: base image moves from PHP 8.0 (buster, EOL) to
  8.4.27 bookworm, which is the PHP2Shell fix (CVE-2026-109618/109619), plus
  `session.upload_progress.enabled=0` and `E_DEPRECATED` suppressed.
- 8 content conflicts, all of one kind: Aaron replaced CDN `<link>/<script>`
  tags with vendored `sts/vendor/*` assets and ran prettier on
  `operations.html`; our UI-improvement pages rewrote the same headers. Resolve
  toward vendored assets.
- Also brings `.github/linters/phpstan.neon`, codespell, `biome.json`.
- Risk: 50k lines of our PHP have never run on 8.4. Deprecations are silenced
  but removed features are not. Plan: `php -l` every file in the 8.4 image,
  run phpstan with his config, then a scripted click-through (session editor,
  simulator, track scale, warm start, reports) on a throwaway compose stack
  before replacing the live container.

| # | Task | Model |
| --- | --- | --- |
| 3.1 | Merge + resolve 8 conflicts | Fable |
| 3.2 | Build 8.4 image on a second compose project, `php -l` sweep, phpstan | sonnet |
| 3.3 | Smoke click-through + simulation parity vs Phase 2 baseline | sonnet, Fable signs off |
| 3.4 | Rebuild live container | Fable |

## Phase 4. Continue refinement

UI improvements, workflow editor, reports continue on short-lived branches off
the main line. Plugin changes land in the plugin repo/directory only; a change
that needs a new hook adds the hook to core in its own commit first.

## Working agreements

- Dev loop: `docker cp` into `sts-docker-web-1` for verification, then commit
  and rebuild. Capture baselines before any refactor that touches the
  simulator.
- Every delegated task gets: the exact files, the hook contract or
  classification list, the verify command, and "no behaviour change" unless
  stated.
