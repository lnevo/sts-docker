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
ADMIN_COMMANDS = {"restore_database", "wipe_database", "reset_session", "restart_session", "backup_database"}
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
            role: str = "reader") -> tuple[int, dict]:
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
    return status, payload


def unwrap(status: int, payload: dict) -> object:
    data = payload.get("data")
    if status == 202:
        task_id = data.get("task_id") if isinstance(data, dict) else None
        return {"accepted": True, "task": data,
                "next": f"Call sts_task with id={task_id} and wait_seconds (e.g. 60) to follow it."}
    if payload.get("meta"):
        return {"data": data, "meta": payload["meta"]}
    return data


def call(method, path, query=None, body=None, role="reader"):
    return unwrap(*request(method, path, query, body, role))


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

def t_command(args):
    need(args, "command")
    command = args["command"]
    body = {"params": args.get("params") or {}}
    if args.get("async"):
        body["async"] = True
    if args.get("seed") is not None:
        body["seed"] = args["seed"]
    role = "admin" if command in ADMIN_COMMANDS else "operator"
    return call("POST", f"/commands/{seg(command)}", body=body, role=role)


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


# ---- tool table -------------------------------------------------------------

def obj(props: dict, required: list[str] | None = None) -> dict:
    return {"type": "object", "properties": props, "required": required or [], "additionalProperties": False}


S, I, B, O = {"type": "string"}, {"type": "integer"}, {"type": "boolean"}, {"type": "object"}
LIM = {"limit": {"type": "integer", "description": "Page size, default 100, max 1000."},
       "offset": I}
PARAMS = {"params": {"type": "object", "description": "Request body fields; see /sts/api/docs/."}}

TOOLS = [
    Tool("sts_health", "Open endpoint, no token. Returns status, version, stack (live or sandbox), "
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
    Tool("sts_get", "Role: reader. One record. cars accept id, reporting marks or RFID; locations id or "
         "code (includes cars there); jobs id or name (includes steps, pickup criteria, cars); "
         "car-orders by waybill; sessions by number; workflows by name.",
         obj({"entity": {"type": "string", "enum": list(GET_ENTITIES)}, "id": {"type": ["string", "integer"]}},
             ["entity", "id"]), t_get),
    Tool("sts_car_history", "Role: reader. The recorded event history of one car (id, marks or RFID).",
         obj({"car": {"type": ["string", "integer"]}, **LIM}, ["car"]), t_car_history),
    Tool("sts_catalog", "Role: reader. The operational steps catalog: command ids, parameters and "
         "dynamic options (jobs, locations, backups). Pass command for one command's params only.",
         obj({"command": S}), t_catalog),
    Tool("sts_task", "Role: operator (tasks are never readable anonymously). Status and log tail of a background task (from a 202 response). "
         "wait_seconds polls until it succeeds or fails, or the wait runs out.",
         obj({"id": S, "wait_seconds": {"type": "number", "description": "Max 600."},
              "log_lines": {"type": "integer"}}, ["id"]), t_task),

    Tool("sts_command", "Role: operator; admin for restore_database, wipe_database, reset_session, "
         "backup_database. Runs one catalog command (generate_orders, fill_orders, reposition_empties, "
         "pick_up_cars, set_out_cars, load_unload, ...). Some commands change many rows; a few replace "
         "the whole database. async=true (or a slow command) returns a task to follow with sts_task.",
         obj({"command": S, **PARAMS, "async": B, "seed": I}, ["command"]), t_command,
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
    Tool("sts_workflow_run", "Role: operator. Run a saved workflow as a task (always async). Optional "
         "seed, start_step, stop_step, skip, sections, session_count, render. The recipe may contain "
         "any command, so read it first with sts_get workflows.",
         obj({"name": S, "seed": I, "start_step": I, "stop_step": I, "skip": {"type": "array"},
              "sections": {"type": "array"}, "session_count": I, "render": B}, ["name"]),
         t_wf_run, read_only=False, destructive=True),

    Tool("sts_backup", "Role: admin. Write a database backup named name into the backups directory.",
         obj({"name": S}, ["name"]), t_backup, read_only=False, destructive=False),
    Tool("sts_restore", "Role: admin. REPLACES THE WHOLE DATABASE with a backup (task). Confirm with the "
         "user, back up first, prefer the sandbox. photos=true also copies the backup's photos.",
         obj({"backup": S, "photos": B}, ["backup"]), t_restore, read_only=False, destructive=True),
    Tool("sts_warm_start", "Role: admin. Run a warm start (task) that rebuilds the railroad state by "
         "simulating sessions: sessions, max_sessions, seed, backup, continue, tracked. REPLACES THE "
         "DATABASE contents; sandbox only unless the user explicitly approves.",
         obj({"sessions": I, "max_sessions": I, "seed": I, "backup": S, "continue": B, "tracked": B},
             ["sessions", "max_sessions", "seed"]), t_warm_start, read_only=False, destructive=True),
    Tool("sts_session_begin", "Role: admin. Begin an operating session via the warm-start machinery (task). "
         "params go in the request body.", obj(PARAMS), t_session_begin, read_only=False, destructive=True),
    Tool("sts_session_play", "Role: admin. Play an operating session via the warm-start machinery (task). "
         "params go in the request body.", obj(PARAMS), t_session_play, read_only=False, destructive=True),
    Tool("sts_tokens", "Role: admin. action list (hashes only), create (name, role reader|operator|editor|admin; "
         "the token is shown once) or revoke (id).",
         obj({"action": {"type": "string", "enum": ["list", "create", "revoke"]}, "name": S,
              "role": {"type": "string", "enum": ["reader", "operator", "editor", "admin"]}, "id": S}),
         t_tokens, read_only=False, destructive=True),
]

INSTRUCTIONS = ("STS API v1 tools. Check sts_health to see whether you are on the live or sandbox stack "
                "before any write. Write tools need a token (STS_API_TOKEN). Use sts_session counts "
                "between operating steps. restore, warm start, wipe and reset replace the database.")

if __name__ == "__main__":
    serve("sts-api", TOOLS, INSTRUCTIONS)
