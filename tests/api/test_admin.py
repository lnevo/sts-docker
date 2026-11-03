"""Token management, backups and an end-to-end restore (backup created in-run)."""
import re

import helpers as h
from helpers import api


class Tokens(h.ApiTestCase):
    def test_create_list_revoke(self):
        r = api("POST", "/admin/tokens", {"name": "apitest-lifecycle", "role": "reader"}, token=self.admin)
        self.assertOk(r, 201)
        self.assertRegex(r.data["token"], r"^sts_[0-9a-f]{40}$")
        self.assertEqual(r.data["role"], "reader")
        tid = r.data["id"]
        listed = api("GET", "/admin/tokens", token=self.admin)
        row = [t for t in listed.data if t["id"] == tid][0]
        # the secret and its hash are shown once / never
        self.assertNotIn("token", row)
        self.assertNotIn("sha256", row)
        self.assertIsNone(row["revoked_at"])
        self.assertEqual(api("DELETE", "/admin/tokens/" + tid, token=self.admin).status, 200)
        row = [t for t in api("GET", "/admin/tokens", token=self.admin).data if t["id"] == tid]
        self.assertTrue(not row or row[0]["revoked_at"])

    def test_validation(self):
        self.assertError(api("POST", "/admin/tokens", {"name": "x", "role": "god"}, token=self.admin), 422)
        self.assertError(api("POST", "/admin/tokens", {"role": "reader"}, token=self.admin), 422)
        self.assertError(api("DELETE", "/admin/tokens/tok_nope", token=self.admin), 404)

    def test_each_role_authenticates_as_that_role(self):
        for role in ("reader", "operator", "editor"):
            with self.subTest(role=role):
                # reader may read tasks? no: operator minimum
                expected = 200 if role != "reader" else 403
                self.assertEqual(api("GET", "/tasks", token=self.tokens[role]).status, expected)


class Backups(h.ApiTestCase):
    needs_fixture = True

    def test_backup_is_listed(self):
        r = api("POST", "/admin/backups", {"name": "api_backup_probe", "overwrite": True}, token=self.admin)
        self.assertOk(r, 201)
        self.assertEqual(r.data["name"], "api_backup_probe")
        self.assertGreater(r.data["size"], 0)
        names = [b["name"] for b in api("GET", "/backups").data]
        self.assertIn("api_backup_probe", names)

    def test_backup_name_validation(self):
        for bad in ("../x", "a b", "", "x" * 65):
            with self.subTest(name=bad):
                self.assertError(api("POST", "/admin/backups", {"name": bad}, token=self.admin), 422)

    def test_restore_validation(self):
        self.assertError(api("POST", "/admin/restore", {"backup": "zz_missing"}, token=self.admin), 404)
        self.assertError(api("POST", "/admin/restore", {}, token=self.admin), 422)
        self.assertError(api("POST", "/admin/restore", {"backup": "../etc/passwd"}, token=self.admin), 422)

    def test_task_fail_unknown_is_404(self):
        self.assertError(api("POST", "/admin/tasks/zz-nope/fail", {}, token=self.admin), 404)


class RestoreEndToEnd(h.ApiTestCase):
    """Backup the current state, change it, restore, and see the original back.
    Uses only a backup made inside this run, so no private seed data is needed."""

    needs_fixture = True

    def test_backup_change_restore(self):
        mark = api("GET", "/cars/12").data["remarks"]
        self.assertEqual(mark, "api-test")
        self.assertOk(api("POST", "/admin/backups", {"name": "api_e2e", "overwrite": True}, token=self.admin), 201)

        self.assertOk(api("PATCH", "/cars/12", {"remarks": "changed"}, token=self.editor))
        self.assertOk(api("PUT", "/settings/e2e_marker", {"value": "x"}, token=self.editor))
        self.assertOk(api("POST", "/session/advance", {}, token=self.operator))
        self.assertEqual(api("GET", "/cars/12").data["remarks"], "changed")

        r = api("POST", "/admin/restore", {"backup": "api_e2e"}, token=self.admin)
        self.assertOk(r, 202)
        self.assertEqual(r.data["kind"], "restore")
        t = h.wait_restore(r.data["task_id"], log=50)
        self.assertEqual(t["status"], "succeeded", t)
        self.assertTrue(t["exclusive"])
        self.assertIsNone(t["error"])

        self.assertEqual(api("GET", "/cars/12").data["remarks"], "api-test")
        self.assertEqual(api("GET", "/settings/e2e_marker").status, 404)
        self.assertEqual(api("GET", "/session").data["session_nbr"], 0)
        self.assertEqual(api("GET", "/cars").meta["total"], 12)

    def test_restore_does_not_touch_tokens(self):
        # self.tokens were created before the restore in setUpClass and still work
        self.assertEqual(api("GET", "/tasks", token=self.operator).status, 200)
        self.assertEqual(api("GET", "/admin/tokens", token=self.admin).status, 200)

    def test_task_id_format(self):
        r = api("POST", "/admin/restore", {"backup": h.FIXTURE_NAME}, token=self.admin)
        self.assertRegex(r.data["task_id"], re.compile(r"^\d{8}-\d{6}-[0-9a-f]+$"))
        h.wait_restore(r.data["task_id"])
