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
  2. `cd ../sts-helpers && RUN_WORKFLOW=0 bin/run_catalog_tests.sh` prints
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

- Phase 4: continue refining the UI, workflow editor and reports on short
  branches off `active`. No specific item is queued yet.
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
