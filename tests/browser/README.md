# Browser regression checks

<!-- markdownlint-disable MD013 -- commands and tables -->

Headless-Chrome checks for the `/sts/` pages: no CSP violations, no console
errors or uncaught exceptions, and the page behaviours listed in
`steps/*.json`. They run against a **throwaway sandbox** (never the live stack
on 8980): the runner restores the database, advances sessions and clicks the
maintenance pages (reset, restart, wipe).

## Run

```bash
# sandbox stack as in tests/api/README.md (any port; 8981 is the default below)
export STS_BASE=http://localhost:8981/sts
export STS_API_TOKEN_FILE=$STS_SANDBOX_DATA/config/bootstrap_admin.token   # or STS_API_TOKEN=sts_...
export STS_API_BACKUPS_DIR=$STS_SANDBOX_DATA/backups     # lets the runner copy the fixture in
export STS_PHOTOS_DIR=$STS_SANDBOX_DATA/photos           # optional, see "Photos"
export CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"   # default: google-chrome etc. on PATH

tests/browser/run.sh            # every steps file
tests/browser/run.sh c5 c6      # some of them
STS_BROWSER_BACKUP=hart_seed tests/browser/run.sh    # the private HART layout instead
```

Output is a table (`PASS`/`FAIL`/`SKIP`, pages, seconds) with the failing
assertions of each failed file above it. The exit status is 1 when any file
fails. `SKIP` means the data source is not on the stack.

| Variable | Meaning |
| --- | --- |
| `STS_BASE` | Site root, default `http://localhost:8981/sts` |
| `STS_API_TOKEN`, `STS_API_TOKEN_FILE` | Admin token (the bootstrap token, or `STS_API_ADMIN_TOKEN` on the stack) |
| `STS_BROWSER_BACKUP` | Data source backup, default `browser_fixture` (committed). `hart_seed` also works when present |
| `STS_API_BACKUPS_DIR` | Host side of the stack's backups dir; the committed fixture is copied there if the stack lacks it |
| `STS_PHOTOS_DIR` | Host side of the photo dir; the runner adds a tiny photo per car and removes it again |
| `CHROME` | Chrome/Chromium binary |
| `CDP_NO_SANDBOX=1` | Pass `--no-sandbox` to Chrome (CI runners) |

## How the data is prepared

`run.sh` calls `prep.mjs`, which only uses the public API (Node 22, no
dependencies):

1. Creates an admin and an operator token and signs both in (cookies), then
   exports `STS_TEST_TOKEN`, `STS_OP_TOKEN`, `STS_TEST_COOKIE`,
   `STS_OP_COOKIE`, `STS_COOKIE_NAME`. All are revoked on exit.
2. Before **each** steps file: restores the data source backup, so files do not
   depend on what an earlier file did (c2 advances the session, c4 wipes).
3. For c5 and c6 it saves and runs a tiny workflow
   (`fixtures/browser_session.workflow.json`: orders, fill, waybills, then
   assign / switch lists / pick up for the first two jobs, and reports), so
   session 1 has output files, waybills and cars on the train. For other data
   sources the same recipe is built for their first two jobs.
4. Makes data the checks need instead of assuming it: c4 gets two cars with an
   `&` in their marks (`PATCH /cars/..`, for `fix_reporting_marks.php`); c2 gets
   three empty cars moved away from home (reposition page).
5. Discovers ids and exports them as placeholders for the steps:
   `STS_JOB1_ID/NAME`, `STS_JOB2_ID/NAME`, `STS_LOC_ID`, `STS_SHIP_ID/CODE`,
   `STS_CAR_ID`, `STS_CAR_MARKS_ENC`, `STS_MARKS_PREFIX`, `STS_WAYBILL_OPEN`
   (one the Waybill report offers), and after a session `STS_SESSION`,
   `STS_SESSION_JOB`, `STS_SESSION_PHASE`, `STS_SESSION_WAYBILL`.

Steps use `${NAME}` placeholders (see the header of `cdp_check.mjs`), so
nothing in `steps/` names a job, location or session. A page in a steps file
can carry `"needs": "<backup name>"`; the runner drops it unless that is the
data source in use (none of the current pages need one).

## Data source

`fixtures/browser_fixture` is a committed backup of a small synthetic railroad:
three stations, six locations, three car codes and commodities, two jobs
(`LOC`, `TURN`) with steps and pickup criteria, six shipments and thirty cars.
`fixtures/seed.sql` is its source: apply it to a freshly provisioned database,
then `POST /admin/backups {"name":"browser_fixture"}` and copy the file from the
backups dir to `tests/browser/fixtures/`. It is separate from
`tests/api/fixtures/api_fixture` so the API tests keep their exact counts.
`prep.mjs recipe LOC TURN > fixtures/browser_session.workflow.json` regenerates
the committed workflow.

## Photos

The photo popups (`showImage`) only open when a car has a photo file. With
`STS_PHOTOS_DIR` set, the runner writes a 1x1 JPEG per car for the run. Without
photos, c3's add/remove and ownership pages cannot find a car with a photo and
fail; c5 falls back to the "No photo available" alert.

## Steps files

| File | Covers |
| --- | --- |
| `c1` | Static pages, API docs, API admin page, workflow editor sign-in |
| `c2` | Operations: generate, fill orders, reposition, switch lists, pick up, set out, organize, load/unload, car list |
| `c3` | Database maintenance lists and editors, owners, settings, forecasts |
| `c4` | Backup, restore, remove, validate, the fix pages, reset, restart, wipe (as admin) |
| `c4-lock` | The same maintenance pages as signed-out and operator users: locked or denied |
| `c5` | Reports and switch lists, printable redirects |
| `c6` | Stored session output (`so.php`, archived pages, waybills, station and wheel reports) |

## CI

`.github/workflows/browser-tests.yml` builds the sandbox like `api-tests.yml`
(empty plugins dir, `STS_API_ADMIN_TOKEN`), uses the Chrome preinstalled on
`ubuntu-latest` and Node 22, and runs `tests/browser/run.sh`.

## Known issues the checks flag

None open.
