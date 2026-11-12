---
name: sts-api
description:
  Operates and inspects a Shipper-driven Traffic Simulator (STS) model railroad
  through its REST API v1 and the sts MCP tools, covering the operating cycle
  (generate orders, fill, reposition, build switch lists, pick up, organize, set
  out, load/unload, advance session), roles and tokens, live versus sandbox
  stacks, async tasks, and the schema pitfalls. Use this skill when running or
  checking an STS operating session, asking where a car is or why an order is
  unfilled, calling /sts/api/v1, creating API tokens, or restoring, backing up
  or warm-starting an STS database.
---

# STS through the API

STS is a car-forwarding simulator: shippers have shipments, shipments become
**car orders** (waybills), orders are filled with cars, cars are moved by
**jobs** (switch lists) between **locations** grouped into **stations**, then
loaded or unloaded. One pass is a **session**; the session number advances at
the end. Checked against sts-docker commit `314118c` (API v1 phase 2 plus maintenance
lock, strict CSP, protected backups; see `docs/plans/2026-11-03-api-v1.md`). If a tool answers 404, that
route is not live yet; `GET /sts/api/v1/openapi.json` lists what is.

## Which stack

- The local build stack is `http://localhost:8980/sts/api/v1` and the sandbox
  `http://localhost:8981/sts/api/v1`; a live (release) stack may be on another
  host. Set `STS_API_URL` accordingly and call `sts_health`: `stack` is `live`
  (the release stack), `dev` (the local build stack, a working copy) or
  `sandbox`.
- `live` is the railroad: never write to it (or to an unnamed stack) without
  the user's explicit approval for that action. `dev` is the owner's working
  copy: a request to work there covers its writes; confirm only far-reaching or
  complex steps (restore, warm start, wipe, reset, many records). The sandbox
  needs no approval. Rehearse in the sandbox first.

## Tokens and roles

`reader` < `operator` < `editor` < `admin`. Reads are open by default
(`STS_API_READ_OPEN=1`, or the policy flag `read_open`). Agents authenticate with
a **bearer token**: `Authorization: Bearer sts_<40 hex>`, which the MCP server
sends from `STS_API_TOKEN` or the file named by `STS_API_TOKEN_FILE`. The
browser sign-in cookie (`sts_api_<port>`, from `POST /auth/login`, used by the editor
and `/sts/api_admin.php`) is for people; do not use it from tools.

- operator: commands, car load/unload/reposition, advance session, run
  workflows, rerender and print-all, clear session output.
- editor: edit cars, jobs, shipments, ownership, settings; save, delete, import
  workflows and set the active one.
- admin: backup, backup download, restore, warm start, session begin/play,
  tokens, the policy, and failing stuck tasks (`sts_task_fail`).
- Bootstrap admin token, on the docker host:
  `../sts-docker-data/config/bootstrap_admin.token` (sandbox:
  `sts-docker-data/sandbox/config/bootstrap_admin.token`). Prefer minting a
  narrower token with `sts_tokens create` and using that. A token is shown once.

## Command policy

The minimum role per catalog command is a policy, not a fixed list. Defaults:
`restore_database`, `wipe_database`, `reset_session`, `remove_backup` need **admin**; every other
command (including `backup_database` and `restart_session`) needs **operator**.
Admins change it on `/sts/api_admin.php` (API Access) or with
`sts_policy`. The policy also holds the flags `read_open`, `legacy_open`,
`editor_open`, `maint_open` (all `null` = default) and `protected_backups`
(globs, default `*seed*`, `*_locked*`). With an admin token, `sts_policy get` (`GET /admin/policy`) shows
the effective map; otherwise assume the defaults, and always trust the server's
403 over your assumption. Do not work around a 403 by another route.

Per-step role checks and `role_warnings` (see Running a workflow) are unchanged.

## Maintenance pages and backups

- The Database Maintenance web pages need a sign-in (`sts/maint_lock.php`):
  backup and validate need operator; restore, remove backup, reset, restart,
  wipe, fix pages, import tables and settings need admin. The policy flag
  `maint_open` reopens them. The operations pages and Database Management
  pages stay open. Agents should use the API, not these pages.
- Backups are not web-served. Download with `sts_backup_download` (admin; `name`
  from `sts_list backups`, `path` file or directory, default current dir;
  refuses to overwrite unless `overwrite=true`; returns `{path, bytes}`), i.e.
  `GET /admin/backups/{name}/download`.
- Protected backups (`*seed*`, `*_locked*`, see `protected_backups`) cannot be
  overwritten by non-admins, including through commands and workflow steps.
- After restoring an old backup run `bin/rerender_stored_sessions.sh` (repo
  script, on the docker host) so stored session output matches.

## Editing STS pages (code work)

The web app sends a strict CSP: no inline scripts or handlers. Do not add
`<script>` blocks or `onclick`; use `data-sts-*` attributes and a file in
`sts/js/*.js`. Check a page with `node tests/browser/cdp_check.mjs`.

## Running a session

Check `sts_session` (session number and counts) before and after each step. Run
steps with `sts_command`; ids and params come from `sts_catalog` (call it with
`command` for one).

1. `generate_orders` (params e.g. `max_new`, `max_unfilled`, `seed`)
2. `fill_orders` (`percent`)
3. `reposition_empties` (`mode`, `percent`)
4. `generate_switchlists` or `build_switchlists_sts`
5. `pick_up_cars` (`job`, `location`)
6. organize (reorder cars on a job, a UI step; use `sts_update` on position only
   if you must)
7. `set_out_cars` (`job`, `location`)
8. `load_unload`
9. `sts_advance_session` (or the `increment_session` command)

Counts to watch: `open_orders`, `unfilled_orders`, `unassigned`,
`pending_pickup`, `pending_setout`, `load_unload_pending`,
`reposition_off_home`. A step that leaves its count unchanged did nothing; say
so and look at why before moving on. Pass `seed` for reproducible runs. A saved
workflow (`sts_workflow_run`) runs a whole recipe as a task.

### Running a workflow

1. `sts_get workflows <name>` to read the recipe, then
   `sts_workflow_run_options <name>`. It returns the steps plus `caller_role` and
   `role_warnings`: steps whose command needs more than your token's role.
2. A warning with `conditional: true` may be skipped by a goto or condition. For
   example `hart_session` step 3 (`restore_database`, admin) runs only at session
   0; an operator can run that workflow at later sessions. A **non-conditional**
   warning will stop the run: stop and ask the user (use an admin token, change
   the policy, or `skip`/`start_step` past it).
3. `sts_workflow_run` checks each step again right before it runs and fails the
   task at the first step the role does not cover, naming the step. Skipped
   steps are never checked. The 202 result repeats `caller_role` and
   `role_warnings`. Set `timeout_seconds` (60-86400; admins may go down to 1) to cap a run; a run is refused up front (403) when a certain step needs a higher role than yours; on expiry the
   task is `failed`, "timed out after N seconds".
4. Runs are exclusive: one at a time, and other writes get 409 meanwhile.

The editor tools mirror the web editor: `sts_workflow_compile`,
`sts_workflow_normalize` (reader, change nothing), `sts_workflow_import` and
`sts_workflow_active set` (editor; import does not save, use
`sts_workflow_save`), `sts_workflow_download` (raw JSON), `sts_simulator_status`.

### Session output

`sts_session_rerender` and `sts_session_print_all` (operator, synchronous)
regenerate a session's switch lists in a style (default `mobile`).
`sts_clear_session_output` (operator) deletes every generated output directory;
the database is untouched. It returns 409 while any task is queued or running.

## Tasks

Slow commands, workflows and all admin actions return 202 with a `task_id`.
Follow with `sts_task` (`wait_seconds` up to 600, `log_lines`). Statuses:
queued, running, succeeded, failed. Only one admin task runs at a time (409
`conflict` otherwise). Do not start the next step until the task has succeeded. A stuck task keeps the
maintenance lock; an admin can release it with `sts_task_fail` (`force` when its
process is still alive; the process is not killed). Every task has a
timeout: `timeout_seconds` (1-86400; non-admins 60 or more for workflows), else the
server default `STS_API_TASK_DEFAULT_TIMEOUT` (21600 s = 6 hours, `0` = none);
on expiry the task is `failed`, "timed out after N seconds".
While a restore, warm start, begin, play, workflow run or destructive command is
queued or running, every other write returns 409
`maintenance task <id> running`; wait for the task. `sts_task` needs the
operator role. Recipes with destructive commands (restore, backup, wipe, reset)
can only be saved or run by an admin.

## Editor lock

While an exclusive task runs, the web editor is read-only and every other write
returns 409. With the policy flag `editor_open` false (default), the legacy
`operational_steps_api.php`, `simulator_api.php` and `save_operational_steps.php`
refuse writes and runs unless the caller is signed in or sends a bearer token
with the needed role. Agents should use the v1 routes instead.

## Safety

- `sts_restore`, `sts_warm_start`, `wipe_database`, `reset_session`, and
  `restore_database` replace the whole database. State exactly what will be
  lost, confirm with the user, run `sts_backup` first, and prefer the sandbox.
- `sts_update` and `sts_workflow_save` overwrite; read the record first and show
  the change.
- Errors arrive as `STS API error <status> <code>: <message>`; 401/403 name the
  role needed. A 500 hides internals; the cause is in the web container's log.

## Pitfalls

- v1 fields are snake_case (`reporting_marks`, `car_code`, `location_id`,
  `job_id`); a foreign key `<thing>_id` sits beside its resolved name.
- A car is addressed by id, reporting marks (upper case, e.g. `104-F`) or RFID;
  a location by id, code or a plugin-registered alias (`sts_get locations SCL`); a job by id or name.
- Car status values: Empty, Loading, Loaded, Unloading. Empty-car moves are
  E-series waybills (`is_empty_move: true`).
- `car_orders.shipment` is a shipment id; `locations.station` is the routing id,
  not `station_id`. Filters on `sts_list` are exact except `marks` (prefix).
- Lists default to 100 rows (max 1000); use `meta.total` and `offset`. Set
  `limit` instead of dumping everything.
- Station zero in dynamic options is "all stations".
- The legacy `/wagon/...` URLs still work with their old camelCase shapes; do
  not mix them with v1 shapes.

## More

Full spec: `/sts/api/docs/` (Swagger UI) and `/sts/api/v1/openapi.json`.
Contract: `docs/plans/2026-11-03-api-v1.md`. Domain pitfalls:
`llm-wiki/api-schema-pitfalls.md`. Use the `sts-inspector` agent for read-only
questions and `sts-operator` to run steps.
