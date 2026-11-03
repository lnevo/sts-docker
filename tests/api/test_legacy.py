"""Legacy wagon routes keep their old bare response shapes (no data/meta envelope)."""
import json

import helpers as h
from helpers import request

LEGACY = h.ROOT  # .../sts/api


def call(method, path, body=None, headers=None):
    return request(method, LEGACY + path, raw=json.dumps(body).encode() if body is not None else None,
                   headers=headers)


class LegacyWagon(h.ApiTestCase):
    needs_fixture = True

    def test_cargo_by_rfid_is_bare(self):
        for prefix in ("/index.php", ""):
            with self.subTest(prefix=prefix):
                r = call("GET", prefix + "/wagon/cargo/id/RFID1002")
                self.assertEqual(r.status, 200, r)
                self.assertNotIn("data", r.json)
                self.assertEqual(r.json["reportingMarks"], "TST 1002")
                self.assertEqual(r.json["status"], "Empty")
                self.assertIn("carCode", r.json)

    def test_cargo_by_car_id_syntax(self):
        r = call("GET", "/wagon/cargo/id/-2-")
        self.assertEqual(r.json["reportingMarks"], "TST 1002")

    def test_unknown_car_is_flat_error(self):
        r = call("GET", "/wagon/cargo/id/NOPE")
        self.assertEqual(r.status, 404)
        self.assertEqual(r.json, {"error": "Car not found"})

    def test_location_is_a_bare_list(self):
        r = call("GET", "/wagon/location/ALPHA-2")
        self.assertEqual(r.status, 200, r)
        self.assertIsInstance(r.json, list)
        self.assertEqual(sorted(c["reportingMarks"] for c in r.json), ["TST 1003", "TST 1004"])
        self.assertEqual(set(r.json[0]), {"reportingMarks", "carType", "status"})

    def test_unload_happy_path(self):
        r = call("POST", "/wagon/unload", {"wagonId": 6, "reportingMarks": "TST 1006", "status": "Unloading"})
        self.assertEqual(r.status, 200, r)
        self.assertEqual(r.json, {"message": "Wagon unload completed", "carId": 6,
                                  "reportingMarks": "TST 1006", "newStatus": "Empty"})

    def test_load_happy_path(self):
        r = call("POST", "/wagon/load", {"wagonId": 5, "reportingMarks": "TST 1005", "status": "Loading"})
        self.assertEqual(r.status, 200, r)
        self.assertEqual(r.json["newStatus"], "Loaded")
        self.assertEqual(set(r.json), {"message", "carId", "reportingMarks", "newStatus"})
        # the v1 API sees the legacy write
        self.assertEqual(h.api("GET", "/cars/5").data["status"], "Loaded")

    def test_reposition_happy_path(self):
        r = call("POST", "/wagon/reposition", {"wagonId": 8, "reportingMarks": "TST 1008", "locationId": 1})
        self.assertEqual(r.status, 200, r)
        self.assertEqual(r.json["newStatus"], "Ordered")
        self.assertIn("-E", r.json["waybillNumber"])
        self.assertEqual(set(r.json), {"message", "carId", "reportingMarks", "waybillNumber",
                                       "destinationLocation", "newStatus"})

    def test_validation_errors_are_flat(self):
        r = call("POST", "/wagon/load", {})
        self.assertEqual(r.status, 400)
        self.assertEqual(set(r.json), {"error"})
        self.assertIsInstance(r.json["error"], str)

    def test_wrong_method_405(self):
        self.assertEqual(call("GET", "/wagon/load").status, 405)

    def test_cross_origin_write_rejected_with_legacy_shape(self):
        r = call("POST", "/wagon/load", {"wagonId": 1, "reportingMarks": "TST 1001", "status": "Loading"},
                 headers={"Origin": "http://evil.example"})
        self.assertEqual(r.status, 403)
        self.assertIsInstance(r.json["error"], str)
