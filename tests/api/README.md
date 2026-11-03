# API v1 integration tests

<!-- markdownlint-disable MD013 -- commands -->

Stdlib-only (`unittest` + `urllib`) tests for `/sts/api/v1` and the legacy wagon
routes. They run against a live stack, so use a **throwaway sandbox**: the suite
restores the database, runs tasks and creates and revokes tokens. Never point it
at the live stack on 8980.

## Run against a sandbox

```bash
# 1. Start a sandbox (see docker-compose.sandbox.yml; choose your own port
#    with a ports.yml override if 8981 is taken)
export STS_SANDBOX_DATA=$HOME/sts-docker-data/sandbox-api
export COMPOSE_PROJECT_NAME=sts-api
export STS_PLUGINS_DIR=$STS_SANDBOX_DATA/plugins        # empty dir = no plugins
mkdir -p $STS_SANDBOX_DATA/{backups,photos,config,plugins}
docker compose -f docker-compose.yml -f docker-compose.sandbox.yml \
  --profile build up -d --build

# 2. Point the suite at it
export STS_API_URL=http://localhost:8981/sts/api/v1
export STS_API_TOKEN_FILE=$STS_SANDBOX_DATA/config/bootstrap_admin.token   # or STS_API_TOKEN=sts_...
export STS_API_BACKUPS_DIR=$STS_SANDBOX_DATA/backups

# 3. Run
python3 -m unittest discover -s tests/api -p 'test_*.py' -v
python3 -m unittest discover -s tests/api -p 'test_cars.py'     # one module
```

| Variable              | Meaning                                                                                      |
| --------------------- | -------------------------------------------------------------------------------------------- |
| `STS_API_URL`         | Base URL, default `http://localhost:8985/sts/api/v1`                                         |
| `STS_API_TOKEN`       | Admin token (the bootstrap token, or `STS_API_ADMIN_TOKEN` on the stack)                     |
| `STS_API_TOKEN_FILE`  | File holding the admin token; falls back to `$STS_SANDBOX_DATA/config/bootstrap_admin.token` |
| `STS_API_BACKUPS_DIR` | Host side of the stack's backups dir. The harness copies `fixtures/api_fixture` there        |

## Data: no private seed needed

`fixtures/api_fixture` is a committed backup of a small synthetic railroad (2
stations, 4 locations, 12 cars `TST 1001`..`TST 1012`, one shipment, session 0).
Modules that need data restore it in `setUpClass`, so each class starts from the
same state. `fixtures/seed.sql` is the source it was generated from (apply it to
a freshly provisioned DB, then `POST /admin/backups {"name":"api_fixture"}`). If
the backup is not on the stack and `STS_API_BACKUPS_DIR` is unset, data tests
skip with an explanation. The restore end-to-end test makes its own backup
in-run (`api_e2e`), so CI needs nothing else.

## What is covered

| Module                   | Covers                                                                                                  |
| ------------------------ | ------------------------------------------------------------------------------------------------------- |
| `test_spec.py`           | health/openapi/docs open; spec valid; every expected route in the spec; every spec route live           |
| `test_auth_matrix.py`    | for every spec operation: min role passes, role below 403, anonymous 401 (destructive ones denial-only) |
| `test_envelope.py`       | success/error envelope (401, 403, 404, 405, 400, 422), pagination meta, filters, array params 422       |
| `test_cars.py`           | load/unload/reposition happy paths and 409s, session advance, edits, cross-origin writes 403            |
| `test_commands_tasks.py` | sync and async commands, task lifecycle and log, maintenance lock 409                                   |
| `test_admin.py`          | token lifecycle, backups, restore end to end                                                            |
| `test_workflows.py`      | workflow save/read/delete/run across roles, destructive-recipe rules                                    |
| `test_legacy.py`         | legacy wagon routes keep their bare shapes                                                              |
| `test_phase2.py`         | login cookie, policy, editor routes, simulator status                                                   |

Phase 2 tests (and the matrix rows for phase 2 routes) **skip automatically**
when `GET /v1/openapi.json` lacks the route; tests that only hold for phase 1
(destructive recipes blocked at save time) skip when `/admin/policy` exists.

## Notes for maintainers

- Add a route: document it in `sts/api/openapi/*.json`; the matrix picks it up.
  New routes also go in `EXPECTED_V1`/`PHASE2` in `helpers.py` (a test fails on
  spec routes the helpers do not know, so drift is noticed).
- The matrix only sends a real (allowed) request for a short list of safe
  operations; restore, warm start, session begin/play/advance, backups, session
  output delete and login/logout are checked for denial only (the router rejects
  those before any handler runs). They have their own dedicated tests.
- Use a car of its own per test (see the table in `test_cars.py`); do not rely
  on test order.

## Bugs the suite found (fixed)

- **Restore vs. concurrent DB reads:** a request that opened the database while
  a restore swapped tables could make `open_db()` recreate `pool` and break the
  restore. Fixed with a restore-in-progress marker: `open_db()` skips its
  migrations, v1 data routes answer 503 `maintenance`, and `/health` reports
  `db: restoring`. Covered by `test_reads_during_a_restore_never_break_it`.
- **Overlong workflow names** made `PUT /workflows/{name}` answer 500; names are
  now capped at 120 characters with a 422 (`test_overlong_name_is_422`).

## CI

`.github/workflows/api-tests.yml` builds the sandbox compose on a runner (empty
plugins dir, `STS_API_ADMIN_TOKEN` as the admin token), waits for `/health` and
runs this suite. It could not be executed locally; see the workflow for the
steps.
