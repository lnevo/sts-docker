"""Protocol and HTTP tests for servers/sts_api.py against a stub STS API."""
import json
import os
import subprocess
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SERVER = ROOT / "servers" / "sts_api.py"
sys.path.insert(0, str(ROOT / "tools"))


def run_server(env, *messages):
    lines = "".join(json.dumps(m) + "\n" for m in messages)
    out = subprocess.run([sys.executable, str(SERVER)], input=lines, capture_output=True, text=True,
                         env={**os.environ, "STS_API_POLL": "0.05", **env}, timeout=30, check=True)
    return [json.loads(line) for line in out.stdout.splitlines()]


def call(name, arguments, request_id=2):
    return {"jsonrpc": "2.0", "id": request_id, "method": "tools/call",
            "params": {"name": name, "arguments": arguments}}


INIT = {"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2025-06-18"}}
LIST = {"jsonrpc": "2.0", "id": 9, "method": "tools/list"}


class Stub(BaseHTTPRequestHandler):
    seen = []
    task_polls = 0

    def reply(self, status, payload):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def handle_any(self, method):
        length = int(self.headers.get("Content-Length") or 0)
        body = json.loads(self.rfile.read(length)) if length else None
        Stub.seen.append((method, self.path, self.headers.get("Authorization"), body))
        path = self.path.split("?")[0]
        if path == "/v1/cars" and method == "GET":
            return self.reply(200, {"data": [{"id": 1}], "meta": {"total": 1, "limit": 100, "offset": 0}})
        if path == "/v1/session":
            return self.reply(200, {"data": {"session_nbr": 4, "stats": {}}})
        if path == "/v1/admin/tokens":
            return self.reply(403, {"error": {"code": "forbidden", "message": "admin required"}})
        if path == "/v1/admin/policy":
            Stub.policy_hits = getattr(Stub, "policy_hits", 0) + 1
            return self.reply(200, {"data": {"command_roles": {"backup_database": "editor", "wipe_database": "admin"},
                                             "protected_backups": ["*seed*", "*_locked*"], "read_open": True,
                                             "legacy_open": True, "editor_open": False, "maint_open": False}})
        if path == "/v1/cars/nope":
            return self.reply(404, {"error": {"code": "not_found", "message": "no such car"}})
        if path == "/v1/commands/generate_orders":
            return self.reply(202, {"data": {"task_id": "t1", "status": "queued"}})
        if path == "/v1/tasks/t1":
            Stub.task_polls += 1
            status = "succeeded" if Stub.task_polls >= 3 else "running"
            return self.reply(200, {"data": {"id": "t1", "status": status, "log": []}})
        if path == "/v1/catalog":
            return self.reply(200, {"data": {"functions": [{"id": "fill_orders", "params": [{"key": "percent"}]}]}})
        if path == "/v1/workflows/hart_session/run":
            return self.reply(202, {"data": {"task_id": "t9", "status": "queued", "caller_role": "operator",
                                             "role_warnings": [{"step": 3, "command": "restore_database",
                                                                "needs": "admin", "conditional": True}]}})
        if path == "/v1/workflows/hart_session/download":
            return self.reply(200, {"name": "hart_session", "steps": []})
        if path == "/v1/workflows/hart_session/run-options":
            return self.reply(200, {"data": {"caller_role": "operator", "role_warnings": []}})
        if path == "/v1/workflows/denied/run-options":
            return self.reply(403, {"error": {"code": "forbidden", "message": "no"}})
        if path == "/v1/workflows/active" and method == "GET":
            return self.reply(200, {"data": {"active_workflow": "a.json", "files": ["a.json"]}})
        if path == "/v1/workflows/active":
            return self.reply(200, {"data": {"active_workflow": (body or {}).get("workflow_file")}})
        if path in ("/v1/workflows/compile", "/v1/workflows/normalize", "/v1/workflows/import"):
            return self.reply(200, {"data": {"rows": [], "echo": body}})
        if path in ("/v1/sessions/7/rerender", "/v1/sessions/7/print-all"):
            return self.reply(200, {"data": {"session": 7, "echo": body}})
        if path == "/v1/sessions/output":
            return self.reply(200, {"data": {"removed": ["7"], "count": 1}})
        if path == "/v1/simulator/status":
            return self.reply(200, {"data": {"runtime_available": True}})
        if path == "/v1/admin/tasks/t1/fail":
            return self.reply(200, {"data": {"id": "t1", "status": "failed"}})
        if path == "/v1/locations/SCL":
            return self.reply(200, {"data": {"code": "SCL", "cars": []}})
        if path == "/v1/admin/backups/seed_1.sql/download":
            blob = bytes(range(256)) + b"\x00\xff"
            self.send_response(200)
            self.send_header("Content-Length", str(len(blob)))
            self.end_headers()
            return self.wfile.write(blob)
        if path == "/v1/admin/backups/gone.sql/download":
            return self.reply(404, {"error": {"code": "not_found", "message": "no such backup"}})
        if path == "/v1/cars/RF1/reposition":
            return self.reply(200, {"data": {"ok": True}})
        self.reply(404, {"error": {"code": "not_found", "message": "stub"}})

    def do_GET(self):
        self.handle_any("GET")

    def do_PUT(self):
        self.handle_any("PUT")

    def do_POST(self):
        self.handle_any("POST")

    def do_DELETE(self):
        self.handle_any("DELETE")

    def log_message(self, *args):
        pass


class ServerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.http = HTTPServer(("127.0.0.1", 0), Stub)
        threading.Thread(target=cls.http.serve_forever, daemon=True).start()
        cls.env = {"STS_API_URL": f"http://127.0.0.1:{cls.http.server_port}/v1", "STS_API_TOKEN": "sts_abc"}

    @classmethod
    def tearDownClass(cls):
        cls.http.shutdown()

    def setUp(self):
        Stub.seen.clear()
        Stub.task_polls = 0

    def text(self, out, index=1):
        return out[index]["result"]["content"][0]["text"]

    def test_initialize_and_annotations(self):
        out = run_server(self.env, INIT, LIST)
        self.assertEqual(out[0]["result"]["serverInfo"]["name"], "sts-api")
        tools = {t["name"]: t for t in out[1]["result"]["tools"]}
        for name in ("sts_health", "sts_session", "sts_list", "sts_get", "sts_car_history", "sts_catalog", "sts_task"):
            self.assertTrue(tools[name]["annotations"]["readOnlyHint"], name)
            self.assertFalse(tools[name]["annotations"]["destructiveHint"], name)
        for name in ("sts_command", "sts_restore", "sts_warm_start", "sts_tokens", "sts_update"):
            self.assertFalse(tools[name]["annotations"]["readOnlyHint"], name)
            self.assertTrue(tools[name]["annotations"]["destructiveHint"], name)
        for name in ("sts_car_action", "sts_advance_session"):
            self.assertFalse(tools[name]["annotations"]["readOnlyHint"], name)
            self.assertTrue(tools[name]["annotations"]["destructiveHint"], name)
        self.assertFalse(tools["sts_create_car"]["annotations"]["destructiveHint"])
        self.assertEqual(len(tools), 34)
        self.assertFalse(tools["sts_backup_download"]["annotations"]["readOnlyHint"])
        self.assertFalse(tools["sts_backup_download"]["annotations"]["destructiveHint"])
        self.assertTrue(tools["sts_policy"]["annotations"]["destructiveHint"])

    def test_policy_tool_get_and_put(self):
        out = run_server(self.env, INIT, call("sts_policy", {"action": "get"}),
                         call("sts_policy", {"action": "put", "command_roles": {"backup_database": "editor"}, "editor_open": True}, 3),
                         call("sts_policy", {"action": "put"}, 4))
        self.assertEqual(json.loads(self.text(out))["command_roles"]["backup_database"], "editor")
        method, path, _, body = Stub.seen[1]
        self.assertEqual((method, path), ("PUT", "/v1/admin/policy"))
        self.assertEqual(body, {"command_roles": {"backup_database": "editor"}, "editor_open": True})
        self.assertEqual(len(Stub.seen), 2)
        self.assertTrue(out[3]["result"]["isError"])

    def test_command_role_comes_from_policy_with_fallback(self):
        sys.path.insert(0, str(ROOT / "servers"))
        import sts_api
        self.assertEqual(sts_api.DEFAULT_ADMIN_COMMANDS, {"restore_database", "wipe_database", "reset_session"})
        old = {k: os.environ.get(k) for k in ("STS_API_URL", "STS_API_TOKEN")}
        os.environ.update(self.env)
        try:
            sts_api._POLICY_ROLES = None
            self.assertEqual(sts_api.command_role("backup_database"), "editor")
            self.assertEqual(sts_api.command_role("fill_orders"), "operator")
            os.environ["STS_API_URL"] = "http://127.0.0.1:9/v1"
            sts_api._POLICY_ROLES = None
            self.assertEqual(sts_api.command_role("wipe_database"), "admin")
            self.assertEqual(sts_api.command_role("backup_database"), "operator")
        finally:
            sts_api._POLICY_ROLES = None
            for k, v in old.items():
                if v is None:
                    os.environ.pop(k, None)
                else:
                    os.environ[k] = v

    def test_seg_encodes_and_rejects_dot_segments(self):
        sys.path.insert(0, str(ROOT / "servers"))
        import sts_api
        self.assertEqual(sts_api.seg("a b/c"), "a%20b%2Fc")
        self.assertEqual(sts_api.seg(42), "42")
        for bad in (".", ".."):
            with self.assertRaises(sts_api.ApiError):
                sts_api.seg(bad)

    def test_dot_segment_is_refused_before_any_request(self):
        out = run_server(self.env, INIT, call("sts_get", {"entity": "cars", "id": ".."}))
        self.assertTrue(out[1]["result"]["isError"])
        self.assertIn("not allowed", self.text(out))
        self.assertEqual(Stub.seen, [])

    def test_list_unwraps_data_keeps_meta_sends_bearer(self):
        out = run_server(self.env, INIT, call("sts_list", {"entity": "cars", "filters": {"status": "Empty"}, "limit": 5}))
        body = json.loads(self.text(out))
        self.assertEqual(body["data"], [{"id": 1}])
        self.assertEqual(body["meta"]["total"], 1)
        method, path, auth, _ = Stub.seen[0]
        self.assertEqual((method, auth), ("GET", "Bearer sts_abc"))
        self.assertIn("status=Empty", path)
        self.assertIn("limit=5", path)

    def test_plain_data_without_meta(self):
        out = run_server(self.env, INIT, call("sts_session", {}))
        self.assertEqual(json.loads(self.text(out))["session_nbr"], 4)

    def test_token_file(self):
        env = {**self.env, "STS_API_TOKEN": ""}
        tok = ROOT / "tests" / "_token.tmp"
        tok.write_text("sts_fromfile\n")
        self.addCleanup(tok.unlink)
        run_server({**env, "STS_API_TOKEN_FILE": str(tok)}, INIT, call("sts_session", {}))
        self.assertEqual(Stub.seen[0][2], "Bearer sts_fromfile")

    def test_error_mapping(self):
        out = run_server(self.env, INIT, call("sts_get", {"entity": "cars", "id": "nope"}))
        self.assertTrue(out[1]["result"]["isError"])
        self.assertIn("404 not_found: no such car", self.text(out))

    def test_403_names_role_and_token(self):
        out = run_server(self.env, INIT, call("sts_tokens", {"action": "list"}))
        text = self.text(out)
        self.assertTrue(out[1]["result"]["isError"])
        self.assertIn("403 forbidden", text)
        self.assertIn("admin role", text)
        self.assertIn("STS_API_TOKEN", text)

    def test_unreachable_is_clear(self):
        out = run_server({"STS_API_URL": "http://127.0.0.1:9/v1"}, INIT, call("sts_health", {}))
        self.assertIn("Cannot reach STS", self.text(out))

    def test_async_command_returns_task_hint(self):
        out = run_server(self.env, INIT, call("sts_command", {"command": "generate_orders", "params": {"max_new": 3}, "async": True, "seed": 7}))
        body = json.loads(self.text(out))
        self.assertTrue(body["accepted"])
        self.assertEqual(body["task"]["task_id"], "t1")
        self.assertIn("sts_task", body["next"])
        method, path, _, sent = Stub.seen[0]
        self.assertEqual((method, path), ("POST", "/v1/commands/generate_orders"))
        self.assertEqual(sent, {"params": {"max_new": 3}, "async": True, "seed": 7})

    def test_task_wait_polls_until_done(self):
        out = run_server(self.env, INIT, call("sts_task", {"id": "t1", "wait_seconds": 10}))
        self.assertEqual(json.loads(self.text(out))["status"], "succeeded")
        self.assertEqual(Stub.task_polls, 3)

    def test_task_without_wait_polls_once(self):
        out = run_server(self.env, INIT, call("sts_task", {"id": "t1"}))
        self.assertEqual(json.loads(self.text(out))["status"], "running")
        self.assertEqual(Stub.task_polls, 1)

    def test_catalog_single_command(self):
        out = run_server(self.env, INIT, call("sts_catalog", {"command": "fill_orders"}))
        self.assertEqual(json.loads(self.text(out))["params"][0]["key"], "percent")
        out = run_server(self.env, INIT, call("sts_catalog", {"command": "bogus"}))
        self.assertTrue(out[1]["result"]["isError"])

    def test_car_action_reposition(self):
        run_server(self.env, INIT, call("sts_car_action", {"car": "RF1", "action": "reposition", "location": "YARD"}))
        self.assertEqual(Stub.seen[0][:2], ("POST", "/v1/cars/RF1/reposition"))
        self.assertEqual(Stub.seen[0][3], {"location": "YARD"})

    def test_missing_argument_makes_no_request(self):
        out = run_server(self.env, INIT, call("sts_car_action", {"car": "RF1", "action": "reposition"}))
        self.assertTrue(out[1]["result"]["isError"])
        self.assertEqual(Stub.seen, [])


class Phase2Tests(unittest.TestCase):
    """Phase 2 tools against the same stub."""
    setUpClass = classmethod(ServerTests.setUpClass.__func__)
    tearDownClass = classmethod(ServerTests.tearDownClass.__func__)
    setUp = ServerTests.setUp
    text = ServerTests.text

    def one(self, name, args):
        out = run_server(self.env, INIT, call(name, args), LIST)
        tools = {t["name"]: t for t in out[2]["result"]["tools"]}
        return out[1], tools[name]["annotations"], Stub.seen[0]

    def test_reads_are_read_only(self):
        for name in ("sts_workflow_compile", "sts_workflow_normalize", "sts_workflow_run_options",
                     "sts_workflow_download", "sts_simulator_status"):
            out = run_server(self.env, INIT, LIST)
            ann = {t["name"]: t["annotations"] for t in out[1]["result"]["tools"]}[name]
            self.assertTrue(ann["readOnlyHint"], name)
            self.assertFalse(ann["destructiveHint"], name)

    def test_compile_and_normalize(self):
        res, _, seen = self.one("sts_workflow_compile", {"recipe": {"steps": [1]}})
        self.assertEqual(seen[:2], ("POST", "/v1/workflows/compile"))
        self.assertEqual(seen[3], {"recipe": {"steps": [1]}})
        Stub.seen.clear()
        res, _, seen = self.one("sts_workflow_normalize", {"use_file": True, "workflow_file": "a.json"})
        self.assertEqual(seen[:2], ("POST", "/v1/workflows/normalize"))
        self.assertEqual(seen[3], {"use_file": True, "workflow_file": "a.json"})

    def test_import_is_editor_write(self):
        res, ann, seen = self.one("sts_workflow_import", {"workflow": "{\"steps\":[]}", "workflow_file": "b.json"})
        self.assertEqual(seen[:2], ("POST", "/v1/workflows/import"))
        self.assertEqual(seen[3], {"workflow": "{\"steps\":[]}", "workflow_file": "b.json"})
        self.assertFalse(ann["readOnlyHint"])
        out = run_server(self.env, INIT, call("sts_workflow_import", {}))
        self.assertTrue(out[1]["result"]["isError"])

    def test_active_get_and_set(self):
        out = run_server(self.env, INIT, call("sts_workflow_active", {}),
                         call("sts_workflow_active", {"action": "set", "workflow_file": "b.json"}, 3),
                         call("sts_workflow_active", {"action": "set"}, 4))
        self.assertEqual(json.loads(self.text(out))["active_workflow"], "a.json")
        self.assertEqual(Stub.seen[0][:2], ("GET", "/v1/workflows/active"))
        self.assertEqual(Stub.seen[1][:2], ("PUT", "/v1/workflows/active"))
        self.assertEqual(Stub.seen[1][3], {"workflow_file": "b.json"})
        self.assertTrue(out[3]["result"]["isError"])
        self.assertEqual(len(Stub.seen), 2)

    def test_run_options_and_403_role(self):
        out = run_server(self.env, INIT, call("sts_workflow_run_options", {"name": "hart_session"}),
                         call("sts_workflow_run_options", {"name": "denied"}, 3))
        self.assertEqual(json.loads(self.text(out))["caller_role"], "operator")
        self.assertEqual(Stub.seen[0][:2], ("GET", "/v1/workflows/hart_session/run-options"))
        self.assertTrue(out[2]["result"]["isError"])
        self.assertIn("403 forbidden", out[2]["result"]["content"][0]["text"])

    def test_download_returns_raw_file(self):
        out = run_server(self.env, INIT, call("sts_workflow_download", {"name": "hart_session"}))
        self.assertEqual(json.loads(self.text(out)), {"name": "hart_session", "steps": []})

    def test_workflow_run_surfaces_role_warnings(self):
        out = run_server(self.env, INIT, call("sts_workflow_run", {"name": "hart_session", "timeout_seconds": 60}))
        body = json.loads(self.text(out))
        self.assertTrue(body["accepted"])
        self.assertEqual(body["caller_role"], "operator")
        self.assertEqual(body["role_warnings"][0]["command"], "restore_database")
        self.assertEqual(Stub.seen[0][3], {"timeout_seconds": 60})

    def test_rerender_and_print_all(self):
        res, ann, seen = self.one("sts_session_rerender", {"session": 7, "style": "mobile", "force": True})
        self.assertEqual(seen[:2], ("POST", "/v1/sessions/7/rerender"))
        self.assertEqual(seen[3], {"style": "mobile", "force": True})
        self.assertFalse(ann["readOnlyHint"])
        Stub.seen.clear()
        res, ann, seen = self.one("sts_session_print_all", {"session": 7, "job": "J1"})
        self.assertEqual(seen[:2], ("POST", "/v1/sessions/7/print-all"))
        self.assertEqual(seen[3], {"job": "J1"})
        self.assertFalse(ann["readOnlyHint"])

    def test_simulator_status(self):
        out = run_server(self.env, INIT, call("sts_simulator_status", {}))
        self.assertTrue(json.loads(self.text(out))["runtime_available"])
        self.assertEqual(Stub.seen[0][:2], ("GET", "/v1/simulator/status"))

    def test_clear_session_output_is_destructive_delete(self):
        res, ann, seen = self.one("sts_clear_session_output", {})
        self.assertEqual(seen[:2], ("DELETE", "/v1/sessions/output"))
        self.assertEqual(json.loads(res["result"]["content"][0]["text"])["count"], 1)
        self.assertFalse(ann["readOnlyHint"])
        self.assertTrue(ann["destructiveHint"])

    def test_task_fail(self):
        res, ann, seen = self.one("sts_task_fail", {"id": "t1", "force": True})
        self.assertEqual(seen[:2], ("POST", "/v1/admin/tasks/t1/fail"))
        self.assertEqual(seen[3], {"force": True})
        self.assertTrue(ann["destructiveHint"])
        Stub.seen.clear()
        self.one("sts_task_fail", {"id": "t1"})
        self.assertEqual(Stub.seen[0][3], {})

    def test_task_fail_403_names_admin(self):
        out = run_server(self.env, INIT, call("sts_task_fail", {"id": "nope"}))
        self.assertTrue(out[1]["result"]["isError"])

    def test_locations_alias(self):
        out = run_server(self.env, INIT, call("sts_get", {"entity": "locations", "id": "SCL"}))
        self.assertEqual(json.loads(self.text(out))["code"], "SCL")
        self.assertEqual(Stub.seen[0][:2], ("GET", "/v1/locations/SCL"))


class BackupDownloadTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.http = HTTPServer(("127.0.0.1", 0), Stub)
        threading.Thread(target=cls.http.serve_forever, daemon=True).start()
        cls.env = {"STS_API_URL": f"http://127.0.0.1:{cls.http.server_port}/v1", "STS_API_TOKEN": "sts_abc"}

    @classmethod
    def tearDownClass(cls):
        cls.http.shutdown()

    def setUp(self):
        Stub.seen.clear()
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def run_tool(self, args):
        out = run_server(self.env, INIT, call("sts_backup_download", args))
        res = out[1]["result"]
        return res.get("isError", False), res["content"][0]["text"]

    def test_saves_binary_body_into_directory(self):
        err, text = self.run_tool({"name": "seed_1.sql", "path": self.tmp.name})
        self.assertFalse(err)
        info = json.loads(text)
        dest = Path(self.tmp.name) / "seed_1.sql"
        self.assertEqual(info, {"path": os.path.abspath(dest), "bytes": 258})
        self.assertEqual(dest.read_bytes(), bytes(range(256)) + b"\x00\xff")
        self.assertEqual(Stub.seen[0][:3], ("GET", "/v1/admin/backups/seed_1.sql/download", "Bearer sts_abc"))

    def test_default_path_is_current_directory(self):
        out = subprocess.run([sys.executable, str(SERVER)], cwd=self.tmp.name, capture_output=True, text=True,
                             input="".join(json.dumps(m) + "\n" for m in (INIT, call("sts_backup_download", {"name": "seed_1.sql"}))),
                             env={**os.environ, **self.env}, timeout=30, check=True)
        info = json.loads(json.loads(out.stdout.splitlines()[1])["result"]["content"][0]["text"])
        self.assertEqual(Path(info["path"]).name, "seed_1.sql")
        self.assertTrue((Path(self.tmp.name) / "seed_1.sql").exists())

    def test_refuses_overwrite_unless_asked(self):
        target = Path(self.tmp.name) / "keep.sql"
        target.write_bytes(b"old")
        err, text = self.run_tool({"name": "seed_1.sql", "path": str(target)})
        self.assertTrue(err)
        self.assertIn("overwrite", text)
        self.assertEqual(target.read_bytes(), b"old")
        self.assertEqual(Stub.seen, [])
        err, text = self.run_tool({"name": "seed_1.sql", "path": str(target), "overwrite": True})
        self.assertFalse(err)
        self.assertEqual(len(target.read_bytes()), 258)

    def test_rejects_bad_names_without_a_request(self):
        for bad in ("../x.sql", "a/b.sql", "..", ".hidden"):
            err, _ = self.run_tool({"name": bad, "path": self.tmp.name})
            self.assertTrue(err, bad)
        self.assertEqual(Stub.seen, [])

    def test_404_writes_nothing(self):
        err, text = self.run_tool({"name": "gone.sql", "path": self.tmp.name})
        self.assertTrue(err)
        self.assertIn("404", text)
        self.assertEqual(os.listdir(self.tmp.name), [])


class PolicyFlagTests(unittest.TestCase):
    def test_policy_fields_and_description(self):
        out = run_server({"STS_API_URL": "http://127.0.0.1:9/v1"}, INIT, LIST)
        tool = {t["name"]: t for t in out[1]["result"]["tools"]}["sts_policy"]
        props = set(tool["inputSchema"]["properties"])
        flags = {"command_roles", "protected_backups", "read_open", "legacy_open", "editor_open", "maint_open"}
        self.assertTrue(flags <= props)
        for name in flags:
            self.assertIn(name, tool["description"])

    def test_put_forwards_every_field(self):
        srv = HTTPServer(("127.0.0.1", 0), Stub)
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        self.addCleanup(srv.shutdown)
        Stub.seen.clear()
        env = {"STS_API_URL": f"http://127.0.0.1:{srv.server_port}/v1", "STS_API_TOKEN": "sts_abc"}
        args = {"action": "put", "protected_backups": ["*seed*"], "maint_open": True, "read_open": None,
                "legacy_open": False, "editor_open": True, "command_roles": {"wipe_database": "admin"}}
        run_server(env, INIT, call("sts_policy", args))
        body = Stub.seen[-1][3]
        self.assertEqual(body, {k: v for k, v in args.items() if k != "action"})
        out = run_server(env, INIT, call("sts_policy", {"action": "get"}))
        got = json.loads(out[1]["result"]["content"][0]["text"])
        for k in ("maint_open", "protected_backups", "read_open", "legacy_open", "editor_open", "command_roles"):
            self.assertIn(k, got)


class PortableTests(unittest.TestCase):
    def test_portable_files_current(self):
        import build_portable
        self.assertEqual(build_portable.stale(), [])


if __name__ == "__main__":
    unittest.main()
