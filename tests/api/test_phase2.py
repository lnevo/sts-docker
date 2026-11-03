"""Phase 2 endpoints: sign-in cookie, policy, editor routes, simulator status.

Every test skips automatically when GET /v1/openapi.json lacks its route, so this
file is inert against a phase-1 stack. Written against the phase 2 contract in
docs/plans/2026-11-03-api-v1.md; assertions are deliberately conservative.
"""
import json

import helpers as h
from helpers import api, needs_route

RECIPE = {"steps": [{"function": "generate_orders", "params": {"increment_session": "0"}}]}


def cookie_of(resp):
    raw = resp.headers.get_all("Set-Cookie") or []
    for c in raw:
        if c.startswith("sts_api="):
            return c
    return None


class Auth(h.ApiTestCase):
    @needs_route("POST", "/auth/login")
    def test_login_me_logout(self):
        bad = api("POST", "/auth/login", {"token": "sts_" + "0" * 40})
        self.assertError(bad, 401)
        self.assertIsNone(cookie_of(bad))

        r = api("POST", "/auth/login", {"token": self.operator})
        self.assertEqual(r.status, 200, r)
        c = cookie_of(r)
        self.assertIsNotNone(c, "login must set the sts_api cookie")
        self.assertIn("HttpOnly", c)
        self.assertIn("SameSite=Strict", c)
        self.assertNotIn(self.operator, c)  # the cookie holds a session id, never the token
        cookie = {"Cookie": c.split(";")[0]}

        me = api("GET", "/auth/me", headers=cookie)
        self.assertOk(me)
        self.assertEqual(me.data["role"], "operator")
        self.assertIn("name", me.data)
        self.assertIn("expires_at", me.data)
        # cookie authenticates reads of operator-only routes
        self.assertEqual(api("GET", "/tasks", headers=cookie).status, 200)

        self.assertEqual(api("GET", "/auth/me").status, 401)
        out = api("POST", "/auth/logout", {}, headers=cookie)
        self.assertIn(out.status, (200, 204))
        self.assertEqual(api("GET", "/auth/me", headers=cookie).status, 401)

    @needs_route("POST", "/auth/login")
    def test_revoking_a_token_ends_its_sessions(self):
        t = api("POST", "/admin/tokens", {"name": "apitest-session", "role": "reader"}, token=self.admin)
        r = api("POST", "/auth/login", {"token": t.data["token"]})
        cookie = {"Cookie": cookie_of(r).split(";")[0]}
        self.assertEqual(api("GET", "/auth/me", headers=cookie).status, 200)
        api("DELETE", "/admin/tokens/" + t.data["id"], token=self.admin)
        self.assertEqual(api("GET", "/auth/me", headers=cookie).status, 401)

    @needs_route("POST", "/auth/login")
    def test_cookie_write_still_checks_origin(self):
        r = api("POST", "/auth/login", {"token": self.editor})
        cookie = cookie_of(r).split(";")[0]
        w = api("PUT", "/settings/apitest_cookie", {"value": "1"},
                headers={"Cookie": cookie, "Origin": "http://evil.example"})
        self.assertEqual(w.status, 403, w)


class Policy(h.ApiTestCase):
    @needs_route("GET", "/admin/policy")
    def test_policy_is_admin_only_and_roundtrips(self):
        self.assertError(api("GET", "/admin/policy", token=self.editor), 403)
        self.assertError(api("GET", "/admin/policy"), 401)
        r = api("GET", "/admin/policy", token=self.admin)
        self.assertOk(r)
        self.assertIn("command_roles", r.data)
        self.assertEqual(r.data["command_roles"]["restore_database"], "admin")
        writable = {k: r.data[k] for k in ("command_roles", "read_open", "legacy_open", "editor_open") if k in r.data}
        back = api("PUT", "/admin/policy", writable, token=self.admin)
        self.assertEqual(back.status, 200, back)
        self.assertEqual(api("GET", "/admin/policy", token=self.admin).data["command_roles"],
                         r.data["command_roles"])

    @needs_route("GET", "/admin/policy")
    def test_command_role_follows_policy(self):
        self.assertError(api("POST", "/commands/restore_database", {}, token=self.operator), 403)


class EditorRoutes(h.ApiTestCase):
    needs_fixture = True

    @classmethod
    def tearDownClass(cls):
        api("DELETE", "/workflows/apitest_p2", token=cls.admin)
        super().tearDownClass()

    @needs_route("POST", "/workflows/compile")
    def test_compile_and_normalize_are_reader_level(self):
        for p in ("/workflows/compile", "/workflows/normalize"):
            with self.subTest(route=p):
                r = api("POST", p, {"recipe": RECIPE}, token=self.reader)
                self.assertEqual(r.status, 200, r)
                self.assertIn("data", r.json)

    @needs_route("POST", "/workflows/compile")
    def test_compile_rejects_a_bad_recipe(self):
        r = api("POST", "/workflows/compile", {"recipe": "nope"}, token=self.reader)
        self.assertIn(r.status, (400, 422), r)

    @needs_route("GET", "/workflows/active")
    def test_active_workflow(self):
        self.assertEqual(api("GET", "/workflows/active", token=self.reader).status, 200)
        self.assertEqual(api("PUT", "/workflows/active", {"name": "zz-nope"}, token=self.operator).status, 403)

    @needs_route("GET", "/workflows/{name}/run-options")
    def test_run_options_and_download(self):
        self.assertEqual(api("GET", "/workflows/zz-nope/run-options").status, 404)
        self.assertEqual(api("GET", "/workflows/zz-nope/download").status, 404)
        api("PUT", "/workflows/apitest_p2", {"recipe": RECIPE}, token=self.editor)
        ro = api("GET", "/workflows/apitest_p2/run-options")
        self.assertEqual(ro.status, 200, ro)
        dl = api("GET", "/workflows/apitest_p2/download")
        self.assertEqual(dl.status, 200)
        self.assertEqual(json.loads(dl.text)["steps"][0]["function"], "generate_orders")

    @needs_route("POST", "/workflows/import")
    def test_import_needs_editor(self):
        self.assertError(api("POST", "/workflows/import", {}, token=self.operator), 403)

    @needs_route("POST", "/sessions/{n}/rerender")
    def test_rerender_and_print_all_unknown_session(self):
        for act in ("rerender", "print-all"):
            with self.subTest(action=act):
                r = api("POST", "/sessions/999999/" + act, {}, token=self.operator)
                self.assertIn(r.status, (200, 404), r)  # phase 2 answers 200 with empty results
                self.assertError(api("POST", "/sessions/1/" + act, {}, token=self.reader), 403)

    @needs_route("GET", "/simulator/status")
    def test_simulator_status(self):
        r = api("GET", "/simulator/status")
        self.assertEqual(r.status, 200, r)
        self.assertIsInstance(r.data, dict)

    @needs_route("DELETE", "/sessions/output")
    def test_delete_session_output(self):
        self.assertError(api("DELETE", "/sessions/output", token=self.reader), 403)
        self.assertError(api("DELETE", "/sessions/output"), 401)
        r = api("DELETE", "/sessions/output", token=self.operator)
        self.assertEqual(r.status, 200, r)
