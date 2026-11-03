"""Role matrix over every operation in the live OpenAPI spec.

For each operation with x-sts-role R:
  - a token of role R gets past auth (status not 401/403)   [safe operations only]
  - a token of the role below R gets 403
  - no token gets 401 (unless the route is open or reads are open)

Operations that would do real work when they get past auth (restore, warm start,
session play/begin/advance, backups, session output delete, login/logout) are
only checked for denial: the router rejects those before any handler runs.
"""
import helpers as h
from helpers import api
from test_spec import fill_path

# Denial-only: never sent with a token that is allowed to run them.
NO_SUCCESS_PROBE = {
    ("POST", "/admin/restore"), ("POST", "/admin/warm-start"),
    ("POST", "/admin/sessions/begin"), ("POST", "/admin/sessions/play"),
    ("POST", "/admin/backups"), ("POST", "/session/advance"),
    ("DELETE", "/sessions/output"), ("POST", "/auth/login"), ("POST", "/auth/logout"),
    ("PUT", "/admin/policy"), ("PUT", "/workflows/active"), ("POST", "/workflows/import"),
    ("POST", "/sessions/{n}/rerender"), ("POST", "/sessions/{n}/print-all"),
    ("POST", "/admin/tokens"),
}


class AuthMatrix(h.ApiTestCase):
    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        cls.read_open = api("GET", "/cars").status == 200

    def ops(self):
        for m, p, op in h.spec_ops():
            yield m, p, op, op.get("x-sts-role")

    def call(self, m, p, op, token):
        # {} satisfies "body must be an object"; unknown ids make handlers answer 404.
        body = {} if m in ("POST", "PUT", "PATCH") else None
        return api(m, fill_path(p, op), body, token=token)

    def test_minimum_role_gets_past_auth(self):
        for m, p, op, role in self.ops():
            if role == "open" or (m, p) in NO_SUCCESS_PROBE:
                continue
            with self.subTest(op="%s %s" % (m, p), role=role):
                r = self.call(m, p, op, self.tokens[role])
                self.assertNotIn(r.status, (401, 403), r)

    def test_role_below_is_forbidden(self):
        for m, p, op, role in self.ops():
            if role in ("open", "reader"):
                continue
            below = h.ROLES[h.ROLES.index(role) - 1]
            with self.subTest(op="%s %s" % (m, p), role=role, below=below):
                r = self.call(m, p, op, self.tokens[below])
                self.assertEqual(r.status, 403, r)
                self.assertEqual(r.error["code"], "forbidden")

    def test_anonymous_is_unauthorized(self):
        for m, p, op, role in self.ops():
            if role == "open":
                continue
            if role == "reader" and self.read_open:
                continue
            with self.subTest(op="%s %s" % (m, p), role=role):
                r = self.call(m, p, op, None)
                self.assertEqual(r.status, 401, r)
                self.assertEqual(r.error["code"], "unauthorized")

    def test_garbage_token_is_unauthorized_on_protected_routes(self):
        for tok in ("sts_" + "0" * 40, "notatoken"):
            r = api("GET", "/admin/tokens", token=tok)
            self.assertEqual(r.status, 401, r)

    def test_tasks_are_never_opened_by_read_open(self):
        self.assertEqual(api("GET", "/tasks").status, 401)
        self.assertEqual(api("GET", "/tasks", token=self.reader).status, 403)
        self.assertEqual(api("GET", "/tasks", token=self.operator).status, 200)

    def test_revoked_token_stops_working(self):
        r = api("POST", "/admin/tokens", {"name": "apitest-revoke", "role": "operator"}, token=self.admin)
        self.assertEqual(r.status, 201)
        tok, tid = r.data["token"], r.data["id"]
        self.assertEqual(api("GET", "/tasks", token=tok).status, 200)
        self.assertEqual(api("DELETE", "/admin/tokens/" + tid, token=self.admin).status, 200)
        self.assertEqual(api("GET", "/tasks", token=tok).status, 401)
