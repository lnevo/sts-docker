"""Workflow save/get/run/delete across roles (phase 1 rules, with phase 2 variants)."""
import unittest

import helpers as h
from helpers import api

SAFE = {"steps": [{"function": "generate_orders", "params": {"increment_session": "0"}},
                  {"function": "drain_unfilled_orders", "params": {}}]}
DESTRUCTIVE = {"steps": [{"function": "restore_database", "params": {"backup": h.FIXTURE_NAME}}]}
NAMES = ("apitest_safe", "apitest_destructive")


class Workflows(h.ApiTestCase):
    needs_fixture = True

    @classmethod
    def tearDownClass(cls):
        for n in NAMES:
            api("DELETE", "/workflows/" + n, token=cls.admin)
        super().tearDownClass()

    def tearDown(self):
        for n in NAMES:
            api("DELETE", "/workflows/" + n, token=self.admin)

    def put(self, name, recipe, token):
        return api("PUT", "/workflows/" + name, {"recipe": recipe}, token=token)

    def test_editor_saves_reads_and_deletes(self):
        r = self.put("apitest_safe", SAFE, self.editor)
        self.assertOk(r, 201)
        self.assertTrue(r.data["created"])
        self.assertEqual(r.data["rows"], 2)
        again = self.put("apitest_safe", SAFE, self.editor)
        self.assertOk(again, 200)
        self.assertFalse(again.data["created"])
        got = api("GET", "/workflows/apitest_safe")
        self.assertOk(got)
        self.assertEqual([s["function"] for s in got.data["recipe"]["steps"]],
                         ["generate_orders", "drain_unfilled_orders"])
        listed = [w["name"] for w in api("GET", "/workflows").data["workflows"]]
        self.assertIn("apitest_safe.workflow.json", listed)
        d = api("DELETE", "/workflows/apitest_safe", token=self.editor)
        self.assertOk(d)
        self.assertEqual(api("GET", "/workflows/apitest_safe").status, 404)

    def test_save_validation(self):
        self.assertError(api("PUT", "/workflows/apitest_safe", {"steps": []}, token=self.editor), 422)
        self.assertError(api("PUT", "/workflows/apitest_safe", {"recipe": {}}, token=self.editor), 422)
        self.assertError(api("PUT", "/workflows/apitest_safe", {"recipe": {"steps": "x"}}, token=self.editor), 422)

    def test_overlong_name_is_422(self):
        self.assertEqual(self.put("x" * 300, SAFE, self.editor).status, 422)

    def test_bad_names_are_422(self):
        for name in ("a%20b!", "a$b", "a;b"):
            with self.subTest(name=name):
                self.assertEqual(self.put(name, SAFE, self.editor).status, 422)
                self.assertEqual(api("GET", "/workflows/" + name).status, 422)
                self.assertEqual(api("POST", "/workflows/%s/run" % name, {}, token=self.operator).status, 422)
                self.assertEqual(api("DELETE", "/workflows/" + name, token=self.editor).status, 422)

    def test_operator_cannot_save_or_delete(self):
        self.assertError(self.put("apitest_safe", SAFE, self.operator), 403)
        self.assertError(api("DELETE", "/workflows/apitest_safe", token=self.operator), 403)

    def test_operator_runs_a_safe_workflow(self):
        self.assertOk(self.put("apitest_safe", SAFE, self.editor), 201)
        r = api("POST", "/workflows/apitest_safe/run", {"seed": 3}, token=self.operator)
        self.assertOk(r, 202)
        self.assertEqual(r.data["kind"], "workflow_run")
        t = h.wait_task(r.data["task_id"], self.operator)
        self.assertEqual(t["status"], "succeeded", t)
        self.assertEqual(t["created_by"][:7], "apitest")

    def test_run_validation(self):
        self.assertOk(self.put("apitest_safe", SAFE, self.editor), 201)
        self.assertError(api("POST", "/workflows/apitest_safe/run", {"render": "x"}, token=self.operator), 422)
        self.assertError(api("POST", "/workflows/zz-nope/run", {}, token=self.operator), 404)
        self.assertError(api("POST", "/workflows/apitest_safe/run", {}, token=self.reader), 403)

    @h.without_route("GET", "/admin/policy")
    def test_phase1_editor_cannot_save_destructive_recipe(self):
        r = self.put("apitest_destructive", DESTRUCTIVE, self.editor)
        self.assertError(r, 403, "forbidden")
        self.assertIn("1", r.error["message"])  # names the step number
        self.assertEqual(api("GET", "/workflows/apitest_destructive").status, 404)

    @h.without_route("GET", "/admin/policy")
    def test_phase1_operator_cannot_run_destructive_recipe_admin_can_save(self):
        self.assertOk(self.put("apitest_destructive", DESTRUCTIVE, self.admin), 201)
        r = api("POST", "/workflows/apitest_destructive/run", {}, token=self.operator)
        self.assertError(r, 403, "forbidden")

    @h.needs_route("GET", "/admin/policy")
    def test_phase2_operator_run_refused_up_front_for_unconditional_admin_step(self):
        """Phase 2: saving never blocks; a run whose unconditional, in-range step
        needs a higher role is refused before anything is dispatched (403 naming
        the step). Conditional steps are checked at dispatch time instead."""
        self.assertOk(self.put("apitest_destructive", DESTRUCTIVE, self.editor), 201)
        r = api("POST", "/workflows/apitest_destructive/run", {}, token=self.operator)
        self.assertEqual(r.status, 403, r)
        self.assertIn("needs admin", r.error["message"])
        self.assertIn("restore_database", r.error["message"])
        self.assertEqual(api("GET", "/cars").meta["total"], 12)  # nothing was restored over the data
