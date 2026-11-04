"""Catalog commands (sync and async), task lifecycle, maintenance lock."""
import os
import unittest

import helpers as h
from helpers import api


class Commands(h.ApiTestCase):
    needs_fixture = True

    def test_sync_command(self):
        before = api("GET", "/session").data["session_nbr"]
        r = self.post("/commands/increment_session", {}, self.operator)
        self.assertOk(r)
        self.assertEqual(r.data["command"], "increment_session")
        self.assertEqual(r.data["result"]["session_nbr_after"], before + 1)

    def test_sync_command_with_seed_and_params(self):
        r = self.post("/commands/generate_orders", {"seed": 7, "params": {"increment_session": "0"}}, self.operator)
        self.assertOk(r)
        self.assertEqual(r.data["command"], "generate_orders")

    def test_unknown_command_404(self):
        self.assertError(self.post("/commands/zz-nope", {}, self.operator), 404, "not_found")

    def test_flow_control_and_non_runnable_422(self):
        for c in ("stop", "goto", "validate_database", "wipe_database"):
            with self.subTest(command=c):
                self.assertError(self.post("/commands/" + c, {}, self.admin), 422, "invalid")

    def test_bad_body_422(self):
        self.assertError(self.post("/commands/increment_session", {"bogus": 1}, self.operator), 422)
        self.assertError(self.post("/commands/increment_session", {"async": "yes"}, self.operator), 422)

    def test_destructive_commands_need_admin(self):
        for c in ("restore_database", "reset_session"):
            with self.subTest(command=c):
                r = self.post("/commands/" + c, {}, self.operator)
                self.assertError(r, 403, "forbidden")


class TaskLifecycle(h.ApiTestCase):
    needs_fixture = True

    def test_async_command_task(self):
        r = self.post("/commands/drain_unfilled_orders", {"async": True}, self.operator)
        self.assertOk(r, 202)
        self.assertEqual(r.data["status"], "queued")
        tid = r.data["task_id"]
        self.assertTrue(tid)
        t = h.wait_task(tid, self.operator, log=20)
        self.assertEqual(t["status"], "succeeded", t)
        self.assertEqual(t["kind"], "command")
        self.assertIsNotNone(t["started_at"])
        self.assertIsNotNone(t["finished_at"])
        self.assertIn("log", t)

    def test_task_default_timeout_recorded(self):
        # Server default (STS_API_TASK_DEFAULT_TIMEOUT, 21600 s unless the stack
        # sets it; set the same value in this process if it differs, 0 = none).
        want = int(os.environ.get("STS_API_TASK_DEFAULT_TIMEOUT", "21600"))
        r = self.post("/commands/drain_unfilled_orders", {"async": True}, self.operator)
        self.assertOk(r, 202)
        t = h.wait_task(r.data["task_id"], self.operator, log=20)
        self.assertEqual(t["spec"].get("timeout_seconds"), want if want > 0 else None, t["spec"])

    def test_slow_commands_always_run_as_tasks(self):
        r = self.post("/commands/generate_waybills", {}, self.operator)
        self.assertOk(r, 202)
        t = h.wait_task(r.data["task_id"], self.operator)
        self.assertIn(t["status"], ("succeeded", "failed"))

    def test_task_listing(self):
        r = self.post("/commands/drain_unfilled_orders", {"async": True}, self.operator)
        h.wait_task(r.data["task_id"], self.operator)
        lst = api("GET", "/tasks?limit=5", token=self.operator)
        self.assertOk(lst)
        self.assertIn(r.data["task_id"], [t["id"] for t in api("GET", "/tasks", token=self.operator).data])
        self.assertLessEqual(len(lst.data), 5)
        self.assertEqual(lst.meta["limit"], 5)
        for k in ("id", "kind", "status", "created_at", "created_by"):
            self.assertIn(k, lst.data[0])

    def test_task_log_lines(self):
        r = self.post("/commands/drain_unfilled_orders", {"async": True}, self.operator)
        tid = r.data["task_id"]
        h.wait_task(tid, self.operator)
        one = api("GET", "/tasks/%s?log=3" % tid, token=self.operator)
        self.assertOk(one)
        self.assertIsInstance(one.data.get("log"), (list, str))

    def test_task_reads_need_operator(self):
        r = self.post("/commands/drain_unfilled_orders", {"async": True}, self.operator)
        tid = r.data["task_id"]
        self.assertError(api("GET", "/tasks/" + tid, token=self.reader), 403)
        self.assertError(api("GET", "/tasks/" + tid), 401)
        h.wait_task(tid, self.operator)

    def test_destructive_command_runs_as_exclusive_task(self):
        r = self.post("/commands/restore_database", {"params": {"backup": h.FIXTURE_NAME}}, self.admin)
        self.assertOk(r, 202)
        t = h.wait_restore(r.data["task_id"])
        self.assertEqual(t["status"], "succeeded", t)
        self.assertTrue(t["exclusive"])
        self.assertEqual(api("GET", "/cars").meta["total"], 12)


class MaintenanceLock(h.ApiTestCase):
    """While an exclusive task is queued or running, other writes answer 409.

    The restore is tiny, so each attempt fires the write immediately after the 202;
    the lock is taken when the task is created, so the window is the whole runner
    start-up. Nothing here opens the database while the lock is held (see the
    skipped test below for why)."""

    needs_fixture = True

    def test_writes_get_409_while_exclusive_task_runs(self):
        seen = tid = None
        for _ in range(8):
            r = api("POST", "/admin/restore", {"backup": h.FIXTURE_NAME}, token=self.admin)
            self.assertEqual(r.status, 202, r)
            tid = r.data["task_id"]
            w = api("PUT", "/settings/lock_probe", {"value": "1"}, token=self.editor)
            # 409 = maintenance lock; 503 = restore in progress (tables being swapped)
            if w.status in (409, 503):
                seen = w
                # token routes stay available during the lock (file-based, no DB)
                self.assertEqual(api("GET", "/admin/tokens", token=self.admin).status, 200)
                for m, p, b in (("POST", "/cars/1/load", {}), ("POST", "/commands/increment_session", {}),
                                ("POST", "/session/advance", {}), ("PATCH", "/cars/1", {"remarks": "x"}),
                                ("POST", "/admin/backups", {"name": "lock_probe"})):
                    x = api(m, p, b, token=self.admin)
                    # the task may have finished mid-loop; only 409 or success are valid
                    self.assertIn(x.status, (409, 503, 200, 201, 404, 422), (m, p, x))
                    if x.status == 409:
                        self.assertIn("maintenance task", x.error["message"])
                    if x.status == 503:
                        self.assertEqual(x.error["code"], "maintenance")
                h.wait_restore(tid)
                break
            h.wait_restore(tid)
        self.assertIsNotNone(seen, "never observed the maintenance lock (restore finished first 8 times)")
        if seen.status == 409:
            self.assertEqual(seen.error["code"], "conflict")
            self.assertIn("maintenance task", seen.error["message"])
            self.assertIn(tid, seen.error["message"])
        else:
            self.assertEqual(seen.error["code"], "maintenance")
            self.assertIn("restore in progress", seen.error["message"])

    def test_writes_work_again_after_the_task(self):
        r = api("POST", "/admin/restore", {"backup": h.FIXTURE_NAME}, token=self.admin)
        h.wait_restore(r.data["task_id"])
        self.assertEqual(api("PUT", "/settings/lock_probe", {"value": "2"}, token=self.editor).status, 200)

    def test_second_exclusive_task_is_409(self):
        for _ in range(8):
            a = api("POST", "/admin/restore", {"backup": h.FIXTURE_NAME}, token=self.admin)
            self.assertEqual(a.status, 202, a)
            b = api("POST", "/admin/restore", {"backup": h.FIXTURE_NAME}, token=self.admin)
            h.wait_restore(a.data["task_id"])
            if b.status == 409:
                self.assertEqual(b.error["code"], "conflict")
                return
            h.wait_restore(b.data["task_id"])
        self.fail("never saw a second exclusive task refused")

    def test_reads_during_a_restore_never_break_it(self):
        """While a restore swaps tables, reads get 200 or 503 maintenance (never a
        500), and the restore still succeeds (open_db skips its migrations)."""
        r = api("POST", "/admin/restore", {"backup": h.FIXTURE_NAME}, token=self.admin)
        for _ in range(20):
            x = api("GET", "/cars")
            self.assertIn(x.status, (200, 503), x)
            if x.status == 503:
                self.assertEqual(x.error["code"], "maintenance")
            self.assertEqual(api("GET", "/health").status, 200)
        t = h.wait_restore(r.data["task_id"])
        self.assertEqual(t["status"], "succeeded", t)
        self.assertEqual(api("GET", "/cars").status, 200)
