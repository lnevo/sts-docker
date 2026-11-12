#!/usr/bin/env python3
"""MCP server for the STS REST API v1 (stdlib only).

Environment:
  STS_API_URL         base URL, default http://localhost:8980/sts/api/v1 (sandbox: 8981)
  STS_API_TOKEN       bearer token (optional while reads are open)
  STS_API_TOKEN_FILE  file holding the token, used when STS_API_TOKEN is unset
  STS_API_TIMEOUT     seconds per request, default 30
"""
from __future__ import annotations

import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from mcp_stdio import Tool, serve  # noqa: E402

DEFAULT_URL = "http://localhost:8980/sts/api/v1"
# Published defaults only; the server decides (GET /admin/policy with an admin token) and its 403 is final.
DEFAULT_ADMIN_COMMANDS = {"restore_database", "wipe_database", "reset_session"}
_POLICY_ROLES: dict | None = None
DONE = ("succeeded", "failed")


class ApiError(Exception):
    pass


def base_url() -> str:
    return os.environ.get("STS_API_URL", DEFAULT_URL).rstrip("/")


def token() -> str:
    value = os.environ.get("STS_API_TOKEN", "").strip()
    path = os.environ.get("STS_API_TOKEN_FILE", "").strip()
    if not value and path:
        try:
            with open(os.path.expanduser(path)) as handle:
                value = handle.read().strip()
        except OSError as exc:
            raise ApiError(f"Cannot read STS_API_TOKEN_FILE {path}: {exc.strerror}") from None
    return value


def request(method: str, path: str, query: dict | None = None, body: object = None,
            role: str = "reader", bare: bool = False, binary: bool = False) -> tuple[int, dict]:
    """Call the API; return (status, parsed envelope). Raise ApiError on failure."""
    url = base_url() + path
    pairs = {k: v for k, v in (query or {}).items() if v is not None and v != ""}
    if pairs:
        url += "?" + urllib.parse.urlencode(pairs)
    headers = {"Accept": "application/json"}
    data = None
    bearer = token()
    if bearer:
        headers["Authorization"] = f"Bearer {bearer}"
    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=float(os.environ.get("STS_API_TIMEOUT", "30"))) as resp:
            status, raw = resp.status, resp.read()
    except urllib.error.HTTPError as exc:
        status, raw = exc.code, exc.read()
    except (urllib.error.URLError, OSError) as exc:
        reason = getattr(exc, "reason", exc)
        raise ApiError(f"Cannot reach STS at {base_url()}: {reason}. Is the stack up, and is "
                       "STS_API_URL right (live 8980, sandbox 8981)?") from None
    if binary and status < 400:  # A file download: hand back the bytes untouched.
        return status, {"bytes": raw}
    try:
        payload = json.loads(raw.decode() or "null")
    except ValueError:
        raise ApiError(f"HTTP {status} from {method} {path}: response was not JSON") from None
    if status >= 400:
        err = payload.get("error") if isinstance(payload, dict) else None
        code = err.get("code", "error") if isinstance(err, dict) else "error"
        message = err.get("message", "") if isinstance(err, dict) else str(payload)
        hint = ""
        if status in (401, 403):
            hint = (f" This call needs the {role} role (or higher). Set STS_API_TOKEN (or "
                    "STS_API_TOKEN_FILE) to a token with that role; the bootstrap admin token is "
                    "in sts-docker-data/config/bootstrap_admin.token on the docker host.")
        raise ApiError(f"STS API error {status} {code}: {message}{hint}")
    if not isinstance(payload, dict):
        raise ApiError(f"HTTP {status} from {method} {path}: unexpected response shape")
    if bare:  # A file download: the body is the document itself, not an envelope.
        return status, {"data": payload}
    return status, payload


def unwrap(status: int, payload: dict) -> object:
    data = payload.get("data")
    if status == 202:
        task_id = data.get("task_id") if isinstance(data, dict) else None
        out = {"accepted": True, "task": data,
               "next": f"Call sts_task with id={task_id} and wait_seconds (e.g. 60) to follow it."}
        if isinstance(data, dict):
            for key in ("caller_role", "role_warnings"):
                if key in data:
                    out[key] = data[key]
        return out
    if payload.get("meta"):
        return {"data": data, "meta": payload["meta"]}
    return data


def call(method, path, query=None, body=None, role="reader", bare=False):
    return unwrap(*request(method, path, query, body, role, bare))


def seg(value: object) -> str:
    """One URL path segment, percent-encoded. '.' and '..' would climb the path, so they are refused."""
    text = str(value)
    if text in (".", ".."):
        raise ApiError(f"Invalid path segment {text!r}: '.' and '..' are not allowed in ids or names")
    return urllib.parse.quote(text, safe="")


def need(args: dict, *keys: str) -> None:
    missing = [k for k in keys if args.get(k) in (None, "")]
    if missing:
        raise ApiError("Missing required argument: " + ", ".join(missing))


# ---- read tools -------------------------------------------------------------

LIST_PATHS = {
    "cars": "/cars", "locations": "/locations", "stations": "/stations", "jobs": "/jobs",
    "shipments": "/shipments", "commodities": "/commodities", "car-codes": "/car-codes",
    "owners": "/owners", "ownership": "/ownership", "car-orders": "/car-orders",
    "workflows": "/workflows", "sessions": "/sessions", "backups": "/backups",
    "tasks": "/tasks", "settings": "/settings",
}
GET_ENTITIES = ("cars", "locations", "stations", "jobs", "shipments", "car-orders",
                "workflows", "sessions", "settings", "tasks")


def t_health(args):
    return call("GET", "/health")


def t_session(args):
    return call("GET", "/session")


def t_list(args):
    need(args, "entity")
    entity = args["entity"]
    if entity not in LIST_PATHS:
        raise ApiError(f"Unknown entity {entity}. Use one of: {', '.join(LIST_PATHS)}")
    query = dict(args.get("filters") or {})
    query["limit"] = args.get("limit")
    query["offset"] = args.get("offset")
    return call("GET", LIST_PATHS[entity], query, role="operator" if entity == "tasks" else "reader")


def t_get(args):
    need(args, "entity", "id")
    if args["entity"] not in GET_ENTITIES:
        raise ApiError(f"Unknown entity {args['entity']}. Use one of: {', '.join(GET_ENTITIES)}")
    return call("GET", f"/{args['entity']}/{seg(args['id'])}",
                role="operator" if args["entity"] == "tasks" else "reader")


def t_car_history(args):
    need(args, "car")
    return call("GET", f"/cars/{seg(args['car'])}/history",
                {"limit": args.get("limit"), "offset": args.get("offset")})


def t_catalog(args):
    catalog = call("GET", "/catalog")
    command = args.get("command")
    if not command:
        return catalog
    for fn in (catalog or {}).get("functions", []):
        if fn.get("id") == command:
            return fn
    raise ApiError(f"Unknown command {command}. Call sts_catalog without arguments to list them.")


def t_task(args):
    need(args, "id")
    wait = min(float(args.get("wait_seconds") or 0), 600)
    log = args.get("log_lines", 50)
    deadline = time.time() + wait
    poll = float(os.environ.get("STS_API_POLL", "2"))
    while True:
        task = call("GET", f"/tasks/{seg(args['id'])}", {"log": log}, role="operator")
        status = task.get("status") if isinstance(task, dict) else None
        if status in DONE or time.time() >= deadline:
            if isinstance(task, dict) and status not in DONE and wait:
                task["waited_out"] = True
                task["note"] = "Still running when wait_seconds ran out; call sts_task again."
            return task
        time.sleep(min(poll, max(0.0, deadline - time.time())))


# ---- write tools ------------------------------------------------------------

def command_role(command: str) -> str:
    """Minimum role for a command: from GET /admin/policy when this token may read it, else the defaults."""
    global _POLICY_ROLES
    if _POLICY_ROLES is None:
        try:
            data = call("GET", "/admin/policy", role="admin")
            _POLICY_ROLES = data.get("command_roles") or {} if isinstance(data, dict) else {}
        except ApiError:
            _POLICY_ROLES = {}
    if command in _POLICY_ROLES:
        return _POLICY_ROLES[command]
    return "admin" if command in DEFAULT_ADMIN_COMMANDS else "operator"


def t_command(args):
    need(args, "command")
    command = args["command"]
    body = {"params": args.get("params") or {}}
    if args.get("async"):
        body["async"] = True
    if args.get("seed") is not None:
        body["seed"] = args["seed"]
    if args.get("timeout_seconds") is not None:
        body["timeout_seconds"] = args["timeout_seconds"]
    try:
        return call("POST", f"/commands/{seg(command)}", body=body, role="operator")
    except ApiError as exc:
        if " 403 " in str(exc):
            raise ApiError(f"{exc} The policy requires role {command_role(command)} for {command}.") from None
        raise


def t_car_action(args):
    need(args, "car", "action")
    action = args["action"]
    if action not in ("load", "unload", "reposition"):
        raise ApiError("action must be load, unload or reposition")
    body = {}
    if action == "reposition":
        need(args, "location")
        body["location"] = args["location"]
    return call("POST", f"/cars/{seg(args['car'])}/{action}", body=body, role="operator")


def t_advance(args):
    return call("POST", "/session/advance", body={}, role="operator")


UPDATE_ROUTES = {"cars": "PATCH", "jobs": "PATCH", "shipments": "PATCH",
                 "ownership": "PUT", "settings": "PUT"}


def t_update(args):
    need(args, "entity", "id", "fields")
    entity = args["entity"]
    if entity not in UPDATE_ROUTES:
        raise ApiError(f"entity must be one of: {', '.join(UPDATE_ROUTES)}")
    return call(UPDATE_ROUTES[entity], f"/{entity}/{seg(args['id'])}", body=args["fields"], role="editor")


def t_create_car(args):
    need(args, "fields")
    return call("POST", "/cars", body=args["fields"], role="editor")


def t_wf_save(args):
    need(args, "name", "recipe")
    return call("PUT", f"/workflows/{seg(args['name'])}", body={"recipe": args["recipe"]}, role="editor")


def t_wf_delete(args):
    need(args, "name")
    return call("DELETE", f"/workflows/{seg(args['name'])}", role="editor")


def t_wf_run(args):
    need(args, "name")
    body = {k: v for k, v in args.items() if k != "name" and v is not None}
    return call("POST", f"/workflows/{seg(args['name'])}/run", body=body, role="operator")


def t_backup(args):
    need(args, "name")
    return call("POST", "/admin/backups", body={"name": args["name"]}, role="admin")


def t_backup_download(args):
    need(args, "name")
    name = str(args["name"])
    if "/" in name or "\\" in name or ".." in name or name.startswith("."):
        raise ApiError(f"Invalid backup name {name!r}: a plain file name, no '/' or '..'")
    dest = os.path.expanduser(str(args.get("path") or "."))
    if os.path.isdir(dest):
        dest = os.path.join(dest, name)
    dest = os.path.abspath(dest)
    if os.path.exists(dest) and not args.get("overwrite"):
        raise ApiError(f"{dest} exists; pass overwrite=true to replace it")
    _, payload = request("GET", f"/admin/backups/{seg(name)}/download", role="admin", binary=True)
    tmp = dest + ".part"
    try:
        with open(tmp, "wb") as handle:
            handle.write(payload["bytes"])
        os.replace(tmp, dest)
    except OSError as exc:
        raise ApiError(f"Cannot write {dest}: {exc.strerror}") from None
    return {"path": dest, "bytes": len(payload["bytes"])}


def t_restore(args):
    need(args, "backup")
    body = {"backup": args["backup"]}
    if args.get("photos") is not None:
        body["photos"] = bool(args["photos"])
    return call("POST", "/admin/restore", body=body, role="admin")


def t_warm_start(args):
    return call("POST", "/admin/warm-start", body=args, role="admin")


def t_session_begin(args):
    return call("POST", "/admin/sessions/begin", body=args.get("params") or {}, role="admin")


def t_session_play(args):
    return call("POST", "/admin/sessions/play", body=args.get("params") or {}, role="admin")


def t_tokens(args):
    action = args.get("action", "list")
    if action == "list":
        return call("GET", "/admin/tokens", role="admin")
    if action == "create":
        need(args, "name", "role")
        result = call("POST", "/admin/tokens", body={"name": args["name"], "role": args["role"]}, role="admin")
        return {"token_created": result, "note": "The token value is shown once; store it now."}
    if action == "revoke":
        need(args, "id")
        return call("DELETE", f"/admin/tokens/{seg(args['id'])}", role="admin")
    raise ApiError("action must be list, create or revoke")


POLICY_FIELDS = ("command_roles", "protected_backups", "read_open", "legacy_open", "editor_open", "maint_open")


def t_policy(args):
    global _POLICY_ROLES
    action = args.get("action", "get")
    if action == "get":
        return call("GET", "/admin/policy", role="admin")
    if action == "put":
        body = {k: args[k] for k in POLICY_FIELDS if k in args}
        if not body:
            raise ApiError("put needs at least one of: " + ", ".join(POLICY_FIELDS))
        _POLICY_ROLES = None
        return call("PUT", "/admin/policy", body=body, role="admin")
    raise ApiError("action must be get or put")


def t_wf_compile(args):
    return call("POST", "/workflows/compile", body={"recipe": args["recipe"]} if args.get("recipe") else {})


def t_wf_normalize(args):
    body = {k: args[k] for k in ("recipe", "use_file", "workflow_file") if args.get(k) is not None}
    return call("POST", "/workflows/normalize", body=body)


def t_wf_import(args):
    body = {k: args[k] for k in ("recipe", "workflow", "use_file", "workflow_file") if args.get(k) is not None}
    if not body:
        raise ApiError("Give recipe, workflow (JSON text or object), or use_file with workflow_file")
    return call("POST", "/workflows/import", body=body, role="editor")


def t_wf_active(args):
    action = args.get("action", "get")
    if action == "get":
        return call("GET", "/workflows/active", {"workflow_file": args.get("workflow_file")})
    if action == "set":
        need(args, "workflow_file")
        return call("PUT", "/workflows/active", body={"workflow_file": args["workflow_file"]}, role="editor")
    raise ApiError("action must be get or set")


def t_wf_run_options(args):
    need(args, "name")
    return call("GET", f"/workflows/{seg(args['name'])}/run-options")


def t_wf_download(args):
    need(args, "name")
    return call("GET", f"/workflows/{seg(args['name'])}/download", bare=True)


def t_rerender(args):
    need(args, "session")
    body = {k: args[k] for k in ("style", "force") if args.get(k) is not None}
    return call("POST", f"/sessions/{seg(args['session'])}/rerender", body=body, role="operator")


def t_print_all(args):
    need(args, "session")
    body = {k: args[k] for k in ("style", "job") if args.get(k) is not None}
    return call("POST", f"/sessions/{seg(args['session'])}/print-all", body=body, role="operator")


def t_simulator_status(args):
    return call("GET", "/simulator/status")


def t_clear_output(args):
    return call("DELETE", "/sessions/output", role="editor")


def t_task_fail(args):
    need(args, "id")
    body = {"force": True} if args.get("force") else {}
    return call("POST", f"/admin/tasks/{seg(args['id'])}/fail", body=body, role="admin")


# ---- tool table -------------------------------------------------------------

def obj(props: dict, required: list[str] | None = None) -> dict:
    return {"type": "object", "properties": props, "required": required or [], "additionalProperties": False}


S, I, B, O = {"type": "string"}, {"type": "integer"}, {"type": "boolean"}, {"type": "object"}
LIM = {"limit": {"type": "integer", "description": "Page size, default 100, max 1000."},
       "offset": I}
PARAMS = {"params": {"type": "object", "description": "Request body fields; see /sts/api/docs/."}}

TOOLS = [
    Tool("sts_health", "Open endpoint, no token. Returns status, version, stack (live, dev or sandbox), "
         "session_nbr and db state. Call first to confirm which stack you are on.",
         obj({}), t_health),
    Tool("sts_session", "Role: reader (open by default). Current session number and the operations hub "
         "counts (open_orders, unfilled_orders, unassigned, pending_pickup, pending_setout, "
         "load_unload_pending, reposition_off_home, ...). Check between operating steps.",
         obj({}), t_session),
    Tool("sts_list", "Role: reader. List an entity with filters. Filters: cars {status, location, job, "
         "car_code, marks(prefix)}; locations {station}; car-orders {status: unfilled|filled|all, "
         "shipment}. Returns data plus meta {total, limit, offset}.",
         obj({"entity": {"type": "string", "enum": list(LIST_PATHS)},
              "filters": {"type": "object", "description": "Query filters, e.g. {\"status\":\"Empty\"}."},
              **LIM}, ["entity"]), t_list),
    Tool("sts_get", "Role: reader. One record. cars accept id, reporting marks or RFID; locations id, "
         "code or a plugin-registered alias such as SCL (includes cars there); jobs id or name (includes steps, pickup criteria, cars); "
         "car-orders by waybill; sessions by number; workflows by name.",
         obj({"entity": {"type": "string", "enum": list(GET_ENTITIES)}, "id": {"type": ["string", "integer"]}},
             ["entity", "id"]), t_get),
    Tool("sts_car_history", "Role: reader. The recorded event history of one car (id, marks or RFID).",
         obj({"car": {"type": ["string", "integer"]}, **LIM}, ["car"]), t_car_history),
    Tool("sts_catalog", "Role: reader. The operational steps catalog: command ids, parameters and "
         "dynamic options (jobs, locations, backups). Pass command for one command's params only.",
         obj({"command": S}), t_catalog),
    Tool("sts_task", "Role: operator (tasks are never readable anonymously). Status and log tail of a background task (from a 202 response). "
         "wait_seconds polls until it succeeds or fails, or the wait runs out. A task is killed and marked failed "
         "after its timeout_seconds, by default STS_API_TASK_DEFAULT_TIMEOUT (6 hours; 0 = no limit).",
         obj({"id": S, "wait_seconds": {"type": "number", "description": "Max 600."},
              "log_lines": {"type": "integer"}}, ["id"]), t_task),

    Tool("sts_command", "Role: operator by default; admin for restore_database, wipe_database, "
         "reset_session (the policy can change this, see sts_policy). Runs one catalog command (generate_orders, fill_orders, reposition_empties, "
         "pick_up_cars, set_out_cars, load_unload, ...). Some commands change many rows; a few replace "
         "the whole database. async=true (or a slow command) returns a task to follow with sts_task; "
         "timeout_seconds (1-86400, default 6 hours) caps a task.",
         obj({"command": S, **PARAMS, "async": B, "seed": I, "timeout_seconds": I}, ["command"]), t_command,
         read_only=False, destructive=True),
    Tool("sts_car_action", "Role: operator. Load, unload or reposition one car (id, marks or RFID). "
         "reposition needs a location (id or code).",
         obj({"car": {"type": ["string", "integer"]}, "action": {"type": "string", "enum": ["load", "unload", "reposition"]},
              "location": {"type": ["string", "integer"]}}, ["car", "action"]), t_car_action,
         read_only=False, destructive=True),
    Tool("sts_advance_session", "Role: operator. Increment the session number (end of an operating "
         "session). Not reversible except by restoring a backup.",
         obj({}), t_advance, read_only=False, destructive=True),
    Tool("sts_update", "Role: editor. Edit one record: PATCH cars, jobs, shipments; PUT ownership, "
         "settings. Writable columns are whitelisted server-side; fields use snake_case.",
         obj({"entity": {"type": "string", "enum": list(UPDATE_ROUTES)}, "id": {"type": ["string", "integer"]},
              "fields": O}, ["entity", "id", "fields"]), t_update, read_only=False, destructive=True),
    Tool("sts_create_car", "Role: editor. Create a car (POST /cars). Fields e.g. reporting_marks, "
         "car_code_id, location_id, status; optional id (409 if taken).",
         obj({"fields": O}, ["fields"]), t_create_car, read_only=False, destructive=False),
    Tool("sts_workflow_save", "Role: editor. Create or overwrite a named workflow recipe (JSON).",
         obj({"name": S, "recipe": O}, ["name", "recipe"]), t_wf_save, read_only=False, destructive=True),
    Tool("sts_workflow_delete", "Role: editor. Delete a named workflow recipe.",
         obj({"name": S}, ["name"]), t_wf_delete, read_only=False, destructive=True),
    Tool("sts_workflow_run", "Role: operator. Run a saved workflow as a task (always async, exclusive). "
         "Call sts_workflow_run_options FIRST and read role_warnings: each step is checked against your role "
         "as it runs and the task fails at the first step that needs more (e.g. restore_database needs admin). "
         "Steps skipped by conditions or ranges are not checked. The 202 result repeats caller_role and "
         "role_warnings at the top level. Optional seed, start_step, stop_step, skip (step numbers/ranges), "
         "sections, session_count, timeout_seconds (60-86400, admins from 1; kills the task, marked failed; "
         "default STS_API_TASK_DEFAULT_TIMEOUT, 6 hours), render. The recipe may "
         "contain any command, so read it first with sts_get workflows.",
         obj({"name": S, "seed": I, "start_step": I, "stop_step": I,
              "skip": {"type": ["string", "array"], "description": "e.g. \"1-2,5\" or [1,2,5]"},
              "sections": {"type": "array"}, "session_count": I, "timeout_seconds": I, "render": B}, ["name"]),
         t_wf_run, read_only=False, destructive=True),

    Tool("sts_workflow_compile", "Role: reader. Compile a recipe (or the active workflow when recipe is "
         "omitted) to its display rows. Saves and runs nothing.",
         obj({"recipe": O}), t_wf_compile),
    Tool("sts_workflow_normalize", "Role: reader. Normalize a recipe, or with use_file reload workflow_file "
         "(or the active one). Saves nothing.",
         obj({"recipe": O, "use_file": B, "workflow_file": S}), t_wf_normalize),
    Tool("sts_workflow_import", "Role: editor. Import workflow JSON (recipe object, workflow text/object, or "
         "use_file + workflow_file). A given workflow_file becomes the active workflow. Does not save the "
         "recipe; use sts_workflow_save.",
         obj({"recipe": O, "workflow": {"type": ["string", "object"]}, "use_file": B, "workflow_file": S}),
         t_wf_import, read_only=False, destructive=True),
    Tool("sts_workflow_active", "Role: reader for action get (default; optional workflow_file), editor for "
         "action set (workflow_file required). Shows or changes which workflow file the editor treats as active.",
         obj({"action": {"type": "string", "enum": ["get", "set"]}, "workflow_file": S}),
         t_wf_active, read_only=False, destructive=True),
    Tool("sts_workflow_run_options", "Role: reader. Run options for a workflow: step indices, sections, "
         "compiled rows, condition context, current and existing sessions, plus caller_role and "
         "role_warnings (steps needing a higher role than your token; conditional=true means a goto or "
         "condition may skip it, e.g. hart_session step 3 restore_database runs only at session 0). "
         "Call before sts_workflow_run; if a non-conditional warning applies, stop and ask the user.",
         obj({"name": S}, ["name"]), t_wf_run_options),
    Tool("sts_workflow_download", "Role: reader. The raw workflow JSON file as text (not an envelope).",
         obj({"name": S}, ["name"]), t_wf_download),
    Tool("sts_session_rerender", "Role: operator. Re-render a session's switch lists in a style "
         "(default mobile); force overwrites. Synchronous. Rewrites that session's generated output.",
         obj({"session": {"type": ["integer", "string"]}, "style": S, "force": B}, ["session"]),
         t_rerender, read_only=False, destructive=True),
    Tool("sts_session_print_all", "Role: operator. Build the print-all bundle for a session and style "
         "(job limits it to one consolidated train); 404 when nothing renders. Writes output files.",
         obj({"session": {"type": ["integer", "string"]}, "style": S, "job": S}, ["session"]),
         t_print_all, read_only=False, destructive=False),
    Tool("sts_simulator_status", "Role: reader. Simulator runtime availability, current session, condition "
         "context and warm-start summary.", obj({}), t_simulator_status),
    Tool("sts_clear_session_output", "Role: operator. DELETES every generated session output directory "
         "(switch lists, waybills); the database is untouched. 409 while any task is queued or running. "
         "Confirm with the user.", obj({}), t_clear_output, read_only=False, destructive=True),
    Tool("sts_task_fail", "Role: admin. Mark a stuck queued or running task failed, which releases the "
         "maintenance lock. A task whose process is still alive is refused (409) unless force=true; the "
         "process is not killed. Confirm with the user.",
         obj({"id": S, "force": B}, ["id"]), t_task_fail, read_only=False, destructive=True),

    Tool("sts_backup", "Role: admin. Write a database backup named name into the backups directory.",
         obj({"name": S}, ["name"]), t_backup, read_only=False, destructive=False),
    Tool("sts_backup_download", "Role: admin. Download a backup file (GET /admin/backups/{name}/download; "
         "backups are not web-served) to a local file. name is a plain file name from sts_list backups. path: "
         "target file or directory (default: current directory, file named after the backup). Refuses to "
         "overwrite an existing file unless overwrite=true. Returns {path, bytes}. Writes a local file only; "
         "the server is untouched.",
         obj({"name": S, "path": S, "overwrite": B}, ["name"]), t_backup_download,
         read_only=False, destructive=False),
    Tool("sts_restore", "Role: admin. REPLACES THE WHOLE DATABASE with a backup (task). Confirm with the "
         "user, back up first, prefer the sandbox. photos=true also copies the backup's photos.",
         obj({"backup": S, "photos": B}, ["backup"]), t_restore, read_only=False, destructive=True),
    Tool("sts_warm_start", "Role: admin. Run a warm start (task) that rebuilds the railroad state by "
         "simulating sessions: sessions, max_sessions, seed, backup, continue, tracked, timeout_seconds (1-86400, "
         "default STS_API_TASK_DEFAULT_TIMEOUT = 6 hours). REPLACES THE "
         "DATABASE contents; sandbox only unless the user explicitly approves.",
         obj({"sessions": I, "max_sessions": I, "seed": I, "backup": S, "continue": B, "tracked": B, "timeout_seconds": I},
             ["sessions", "max_sessions", "seed"]), t_warm_start, read_only=False, destructive=True),
    Tool("sts_session_begin", "Role: admin. Begin an operating session via the warm-start machinery (task). "
         "params go in the request body (timeout_seconds optional, default 6 hours).", obj(PARAMS), t_session_begin, read_only=False, destructive=True),
    Tool("sts_session_play", "Role: admin. Play an operating session via the warm-start machinery (task). "
         "params go in the request body (timeout_seconds optional, default 6 hours).", obj(PARAMS), t_session_play, read_only=False, destructive=True),
    Tool("sts_policy", "Role: admin. action get shows the effective policy: command_roles (minimum role per "
         "catalog command), protected_backups (globs, default *seed* and *_locked*: non-admins may not overwrite "
         "a matching backup), and the flags read_open, legacy_open, editor_open and maint_open (true leaves the "
         "Database Maintenance pages open without sign-in; default false), plus flag_sources and defaults. "
         "action put changes any subset at once: command_roles {id: operator|editor|admin} (replaces the stored "
         "map), protected_backups (list of globs), and the flags; null resets a field to its default. "
         "Lowering a role or opening a flag lets lesser callers in; confirm with the user first.",
         obj({"action": {"type": "string", "enum": ["get", "put"]}, "command_roles": O,
              "protected_backups": {"type": ["array", "null"], "items": S},
              "read_open": {"type": ["boolean", "null"]}, "legacy_open": {"type": ["boolean", "null"]},
              "editor_open": {"type": ["boolean", "null"]}, "maint_open": {"type": ["boolean", "null"]}}),
         t_policy, read_only=False, destructive=True),
    Tool("sts_tokens", "Role: admin. action list (hashes only), create (name, role reader|operator|editor|admin; "
         "the token is shown once) or revoke (id).",
         obj({"action": {"type": "string", "enum": ["list", "create", "revoke"]}, "name": S,
              "role": {"type": "string", "enum": ["reader", "operator", "editor", "admin"]}, "id": S}),
         t_tokens, read_only=False, destructive=True),
]

INSTRUCTIONS = ("STS API v1 tools. Check sts_health to see whether you are on the live, dev or sandbox stack "
                "before any write. Write tools need a bearer token (STS_API_TOKEN). Use sts_session counts "
                "between operating steps. Call sts_workflow_run_options before sts_workflow_run and heed role_warnings. restore, warm start, wipe and reset replace the database.")

if __name__ == "__main__":
    serve("sts-api", TOOLS, INSTRUCTIONS)
