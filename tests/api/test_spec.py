"""Open endpoints and the OpenAPI spec."""
import json
import unittest

import helpers as h
from helpers import api


class OpenEndpoints(unittest.TestCase):
    def test_health_is_open(self):
        r = api("GET", "/health")
        self.assertEqual(r.status, 200)
        d = r.data
        self.assertEqual(d["status"], "ok")
        self.assertEqual(d["db"], "ok")
        for k in ("version", "stack", "session_nbr"):
            self.assertIn(k, d)

    def test_health_ignores_a_bad_token(self):
        self.assertEqual(api("GET", "/health", token="sts_" + "0" * 40).status, 200)

    def test_openapi_is_open_and_json(self):
        r = api("GET", "/openapi.json")
        self.assertEqual(r.status, 200)
        self.assertIn("json", r.header("Content-Type"))

    def test_swagger_ui_is_open(self):
        r = h.request("GET", h.ROOT + "/docs/")
        self.assertEqual(r.status, 200)
        self.assertIn("swagger", r.text.lower())

    def test_options_is_204_with_cors(self):
        r = api("OPTIONS", "/cars")
        self.assertEqual(r.status, 204)
        self.assertEqual(r.header("Access-Control-Allow-Origin"), "*")
        self.assertIn("Authorization", r.header("Access-Control-Allow-Headers"))


class Spec(unittest.TestCase):
    def test_valid_openapi_31_document(self):
        s = h.spec()
        json.dumps(s)  # round-trips
        self.assertTrue(s["openapi"].startswith("3.1"), s["openapi"])
        self.assertIn("bearerAuth", s["components"]["securitySchemes"])
        self.assertTrue(s["paths"])

    def test_every_operation_declares_a_role(self):
        for m, p, op in h.spec_ops():
            with self.subTest(op="%s %s" % (m, p)):
                self.assertIn(op.get("x-sts-role"), h.ROLES + ["open"])
                self.assertTrue(op.get("summary"))
                self.assertIn("responses", op)

    def test_every_expected_phase1_route_is_in_the_spec(self):
        for m, p in h.EXPECTED_V1:
            with self.subTest(route="%s %s" % (m, p)):
                self.assertTrue(h.spec_has(m, p), "spec lacks %s %s" % (m, p))

    def test_spec_has_no_unknown_routes(self):
        """Anything in the spec beyond phase 1 must be a known phase 2 route."""
        known = set(h.EXPECTED_V1) | set(h.PHASE2)
        for m, p, _ in h.spec_ops():
            with self.subTest(op="%s %s" % (m, p)):
                self.assertIn((m, p), known, "undocumented in tests/api/helpers.py")

    def test_every_spec_operation_is_live(self):
        """Probe each spec operation anonymously. Non-open routes answer 401 before
        any handler runs, so nothing is changed. A router-level 404/405 means the
        route is documented but not registered."""
        for m, p, op in h.spec_ops():
            if op.get("x-sts-role") == "open" and m != "GET":
                continue  # open writes (login) are probed by their own tests
            path = fill_path(p, op)
            with self.subTest(op="%s %s" % (m, p)):
                r = api(m, path)
                self.assertNotEqual(r.status, 405, r)
                if r.status == 404:
                    self.assertNotEqual(r.error["message"], "No such API route", r)

    def test_unregistered_route_is_not_in_the_spec_and_404s(self):
        r = api("GET", "/definitely/not/a/route")
        self.assertEqual(r.status, 404)
        self.assertEqual(r.error["message"], "No such API route")

    def test_command_ids_are_injected(self):
        enum = h.spec()["components"]["schemas"]["CommandId"].get("enum")
        self.assertTrue(enum, "command enum missing")
        self.assertIn("generate_orders", enum)
        self.assertIn("restore_database", enum)


def fill_path(pattern, op):
    """Replace {params} with values that cannot match a real resource."""
    types = {prm["name"]: prm.get("schema", {}).get("type") for prm in op.get("parameters", [])
             if prm.get("in") == "path"}
    out = pattern
    for name, typ in types.items():
        out = out.replace("{%s}" % name, "999999" if typ == "integer" else "zz-nope")
    return out
