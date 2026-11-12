"""Shared helpers for the STS API v1 integration tests (stdlib only).

Environment:
  STS_API_URL         base URL, default http://localhost:8985/sts/api/v1
  STS_API_TOKEN       admin token (or STS_API_TOKEN_FILE: a file holding it;
                      falls back to $STS_SANDBOX_DATA/config/bootstrap_admin.token)
  STS_API_BACKUPS_DIR host path of the stack's backups directory. When set and the
                      `api_fixture` backup is missing, the harness copies
                      tests/api/fixtures/api_fixture there before restoring it.

The tests assume a throwaway sandbox stack. Never point them at a live stack:
they restore the database and run tasks.
"""
import json
import os
import shutil
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
FIXTURE_NAME = "api_fixture"
FIXTURE_FILE = os.path.join(HERE, "fixtures", FIXTURE_NAME)

BASE = os.environ.get("STS_API_URL", "http://localhost:8985/sts/api/v1").rstrip("/")
ROOT = BASE[: -len("/v1")] if BASE.endswith("/v1") else BASE  # .../sts/api
HOST = urllib.parse.urlparse(BASE).netloc
ROLES = ["reader", "operator", "editor", "admin"]

# Routes the phase-1 API registers. The matrix and the live probe use the spec;
# this list guards against a route silently dropping out of the spec.
EXPECTED_V1 = [
    ("GET", "/health"), ("GET", "/openapi.json"), ("GET", "/session"),
    ("GET", "/settings"), ("GET", "/settings/{name}"), ("PUT", "/settings/{name}"),
    ("GET", "/cars"), ("POST", "/cars"), ("GET", "/cars/{car}"), ("PATCH", "/cars/{car}"),
    ("GET", "/cars/{car}/history"), ("POST", "/cars/{car}/load"),
    ("POST", "/cars/{car}/unload"), ("POST", "/cars/{car}/reposition"),
    ("GET", "/locations"), ("GET", "/locations/{location}"),
    ("GET", "/stations"), ("GET", "/stations/{id}"),
    ("GET", "/jobs"), ("GET", "/jobs/{job}"), ("PATCH", "/jobs/{job}"),
    ("GET", "/shipments"), ("GET", "/shipments/{id}"), ("PATCH", "/shipments/{id}"),
    ("GET", "/commodities"), ("GET", "/car-codes"), ("GET", "/owners"),
    ("GET", "/ownership"), ("PUT", "/ownership/{car}"),
    ("GET", "/car-orders"), ("GET", "/car-orders/{waybill}"),
    ("GET", "/catalog"), ("GET", "/workflows"), ("GET", "/workflows/{name}"),
    ("PUT", "/workflows/{name}"), ("DELETE", "/workflows/{name}"),
    ("POST", "/workflows/{name}/run"),
    ("GET", "/sessions"), ("GET", "/sessions/{n}"), ("GET", "/backups"),
    ("POST", "/commands/{command}"), ("POST", "/session/advance"),
    ("POST", "/admin/backups"), ("POST", "/admin/restore"),
    ("POST", "/admin/warm-start"), ("POST", "/admin/sessions/begin"),
    ("POST", "/admin/sessions/play"), ("POST", "/admin/tasks/{id}/fail"),
    ("GET", "/admin/tokens"), ("POST", "/admin/tokens"), ("DELETE", "/admin/tokens/{id}"),
    ("GET", "/tasks"), ("GET", "/tasks/{id}"),
]

# Phase 2 routes: tests using them skip when the spec lacks the path.
PHASE2 = [
    ("POST", "/auth/login"), ("GET", "/auth/me"), ("POST", "/auth/logout"),
    ("GET", "/admin/policy"), ("PUT", "/admin/policy"),
    ("POST", "/workflows/compile"), ("POST", "/workflows/normalize"),
    ("POST", "/workflows/import"), ("GET", "/workflows/active"),
    ("PUT", "/workflows/active"), ("GET", "/workflows/{name}/run-options"),
    ("GET", "/workflows/{name}/download"), ("GET", "/admin/backups/{name}/download"),
    ("POST", "/sessions/{n}/rerender"),
    ("POST", "/sessions/{n}/print-all"), ("GET", "/simulator/status"),
    ("DELETE", "/sessions/output"),
    ("POST", "/shipments"), ("POST", "/locations"),
    ("POST", "/car-orders/{waybill}/fill"),
]


class Resp:
    def __init__(self, status, headers, text):
        self.status = status
        self.headers = headers
        self.text = text
        try:
            self.json = json.loads(text) if text else None
        except ValueError:
            self.json = None

    @property
    def data(self):
        return (self.json or {}).get("data")

    @property
    def meta(self):
        return (self.json or {}).get("meta")

    @property
    def error(self):
        return (self.json or {}).get("error")

    def header(self, name):
        return self.headers.get(name)

    def __repr__(self):
        return "<Resp %s %s>" % (self.status, self.text[:200])


def request(method, url, body=None, token=None, headers=None, raw=None):
    """One HTTP call. body is JSON-encoded; raw is sent as-is (bytes)."""
    h = dict(headers or {})
    if token:
        h["Authorization"] = "Bearer " + token
    data = None
    if raw is not None:
        data = raw
        h.setdefault("Content-Type", "application/json")
    elif body is not None:
        data = json.dumps(body).encode()
        h.setdefault("Content-Type", "application/json")
    req = urllib.request.Request(url, data=data, method=method, headers=h)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return Resp(r.status, r.headers, r.read().decode("utf-8", "replace"))
    except urllib.error.HTTPError as e:
        return Resp(e.code, e.headers, e.read().decode("utf-8", "replace"))


def api(method, path, body=None, token=None, headers=None, raw=None):
    return request(method, BASE + path, body, token, headers, raw)


def read_admin_token():
    tok = os.environ.get("STS_API_TOKEN", "").strip()
    if tok:
        return tok
    candidates = []
    if os.environ.get("STS_API_TOKEN_FILE"):
        candidates.append(os.environ["STS_API_TOKEN_FILE"])
    if os.environ.get("STS_SANDBOX_DATA"):
        candidates.append(os.path.join(os.environ["STS_SANDBOX_DATA"], "config", "bootstrap_admin.token"))
    for path in candidates:
        try:
            with open(path) as f:
                return f.read().strip()
        except OSError:
            continue
    raise RuntimeError("no admin token: set STS_API_TOKEN or STS_API_TOKEN_FILE")


ADMIN = None


def admin_token():
    global ADMIN
    if ADMIN is None:
        ADMIN = read_admin_token()
    return ADMIN


_SPEC = None


def spec():
    global _SPEC
    if _SPEC is None:
        r = api("GET", "/openapi.json")
        if r.status != 200 or not isinstance(r.json, dict):
            raise RuntimeError("cannot load %s/openapi.json: %r" % (BASE, r))
        _SPEC = r.json
    return _SPEC


def spec_ops():
    """[(METHOD, path, operation)] for every operation in the spec."""
    out = []
    for path, item in spec().get("paths", {}).items():
        for m, op in item.items():
            if m.upper() in ("GET", "POST", "PUT", "PATCH", "DELETE"):
                out.append((m.upper(), path, op))
    return out


def spec_has(method, path):
    item = spec().get("paths", {}).get(path)
    return bool(item) and method.lower() in item


def needs_route(method, path):
    """Skip a test (phase 2) unless the live spec declares the route."""
    def deco(fn):
        def wrapper(self, *a, **kw):
            if not spec_has(method, path):
                self.skipTest("phase 2 route %s %s not in /openapi.json" % (method, path))
            return fn(self, *a, **kw)
        wrapper.__name__ = fn.__name__
        wrapper.__doc__ = fn.__doc__
        return wrapper
    return deco


def without_route(method, path):
    """Skip a test that only holds before the phase-2 policy change."""
    def deco(fn):
        def wrapper(self, *a, **kw):
            if spec_has(method, path):
                self.skipTest("behaviour changed by phase 2 (%s %s exists)" % (method, path))
            return fn(self, *a, **kw)
        wrapper.__name__ = fn.__name__
        wrapper.__doc__ = fn.__doc__
        return wrapper
    return deco


def wait_restore(task_id, token=None, **kw):
    """wait_task for restore tasks (settles first, polls gently)."""
    kw.setdefault("settle", 1.5)
    kw.setdefault("interval", 1.0)
    return wait_task(task_id, token, **kw)


def wait_task(task_id, token=None, timeout=90, interval=0.3, log=0, settle=0.0):
    """Poll a task until it leaves queued/running; returns its record.

    settle: sleep before the first poll. Restore tasks need it: any request that
    opens the database while a restore is between its DROP and CREATE of `pool`
    makes open_db() recreate that table and the restore then fails with "Table
    'pool' already exists" (see README, known bugs). The small fixture restores in
    well under a second, so polling after `settle` avoids tripping it.
    """
    token = token or admin_token()
    deadline = time.time() + timeout
    q = "?log=%d" % log if log else ""
    time.sleep(settle)
    while time.time() < deadline:
        r = api("GET", "/tasks/" + task_id + q, token=token)
        if r.status >= 500:  # transient while a restore has the schema half-built
            time.sleep(interval)
            continue
        if r.status != 200:
            raise AssertionError("task %s poll failed: %r" % (task_id, r))
        if r.data["status"] in ("succeeded", "failed"):
            return r.data
        time.sleep(interval)
    raise AssertionError("task %s still running after %ss" % (task_id, timeout))


def ensure_fixture_backup():
    """Make sure the api_fixture backup exists in the stack; SkipTest otherwise."""
    r = api("GET", "/backups")
    names = [b["name"] for b in (r.data or [])]
    if FIXTURE_NAME in names:
        return
    dest = os.environ.get("STS_API_BACKUPS_DIR")
    if dest and os.path.isdir(dest):
        shutil.copyfile(FIXTURE_FILE, os.path.join(dest, FIXTURE_NAME))
        r = api("GET", "/backups")
        if FIXTURE_NAME in [b["name"] for b in (r.data or [])]:
            return
    raise unittest.SkipTest(
        "backup %r not on the stack: copy tests/api/fixtures/%s into its backups dir "
        "or set STS_API_BACKUPS_DIR" % (FIXTURE_NAME, FIXTURE_NAME))


def reset_fixture():
    """Restore the synthetic dataset (cars TST 1001..1012, session 0)."""
    ensure_fixture_backup()
    r = api("POST", "/admin/restore", {"backup": FIXTURE_NAME}, token=admin_token())
    if r.status != 202:
        raise AssertionError("restore request failed: %r" % r)
    t = wait_restore(r.data["task_id"])
    if t["status"] != "succeeded":
        raise AssertionError("fixture restore failed: %r" % t)


class ApiTestCase(unittest.TestCase):
    """Creates reader/operator/editor tokens for the class and revokes them after."""

    needs_fixture = False
    tokens = {}
    token_ids = []

    @classmethod
    def setUpClass(cls):
        h = api("GET", "/health")
        if h.status != 200:
            raise RuntimeError("API not reachable at %s: %r" % (BASE, h))
        cls.admin = admin_token()
        cls.tokens = {"admin": cls.admin}
        cls.token_ids = []
        for role in ("reader", "operator", "editor"):
            r = api("POST", "/admin/tokens", {"name": "apitest-%s-%d" % (role, os.getpid()), "role": role},
                    token=cls.admin)
            if r.status != 201:
                raise RuntimeError("cannot create %s token: %r" % (role, r))
            cls.tokens[role] = r.data["token"]
            cls.token_ids.append(r.data["id"])
        cls.reader = cls.tokens["reader"]
        cls.operator = cls.tokens["operator"]
        cls.editor = cls.tokens["editor"]
        if cls.needs_fixture:
            reset_fixture()

    @classmethod
    def tearDownClass(cls):
        for tid in cls.token_ids:
            api("DELETE", "/admin/tokens/" + tid, token=cls.admin)

    # shorthands
    def get(self, path, token=None, **kw):
        return api("GET", path, token=token, **kw)

    def post(self, path, body=None, token=None, **kw):
        return api("POST", path, body, token=token, **kw)

    def assertError(self, resp, status, code=None):
        self.assertEqual(resp.status, status, resp)
        self.assertIsInstance(resp.error, dict, resp)
        self.assertEqual(set(resp.error), {"code", "message"}, resp)
        self.assertTrue(resp.error["message"])
        if code:
            self.assertEqual(resp.error["code"], code)
        self.assertNotIn("data", resp.json)

    def assertOk(self, resp, status=200):
        self.assertEqual(resp.status, status, resp)
        self.assertIn("data", resp.json, resp)
        self.assertNotIn("error", resp.json)
