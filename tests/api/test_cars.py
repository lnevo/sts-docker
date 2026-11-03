"""Car moves (load/unload/reposition), session advance, data edits, cross-origin writes.

Each test uses its own cars from the fixture so order does not matter:
  1,2,9,10,11,12 Empty at ALPHA-1 | 3,4 Empty at ALPHA-2 | 5 Loading | 6 Unloading
  7 Loaded | 8 Empty at BETA-2
"""
import helpers as h
from helpers import api


class CarMoves(h.ApiTestCase):
    needs_fixture = True

    def car(self, ref):
        return api("GET", "/cars/%s" % ref).data

    def test_load_happy_path_then_409(self):
        r = self.post("/cars/5/load", {}, self.operator)
        self.assertOk(r)
        self.assertEqual(r.data["status"], "Loaded")
        self.assertEqual(r.data["id"], 5)
        self.assertError(self.post("/cars/5/load", {}, self.operator), 409, "conflict")

    def test_load_needs_loading_status(self):
        r = self.post("/cars/1/load", {}, self.operator)
        self.assertError(r, 409, "conflict")
        self.assertEqual(self.car(1)["status"], "Empty")

    def test_unload_happy_path_clears_order_then_409(self):
        self.assertEqual(api("GET", "/car-orders/000-002").status, 200)
        r = self.post("/cars/6/unload", {}, self.operator)
        self.assertOk(r)
        self.assertEqual(r.data["status"], "Empty")
        self.assertEqual(api("GET", "/car-orders/000-002").status, 404)
        self.assertError(self.post("/cars/6/unload", {}, self.operator), 409, "conflict")

    def test_unload_of_loaded_car_is_409(self):
        self.assertError(self.post("/cars/7/unload", {}, self.operator), 409, "conflict")

    def test_move_of_unknown_car_is_404(self):
        for act in ("load", "unload", "reposition"):
            with self.subTest(action=act):
                self.assertError(self.post("/cars/999999/%s" % act, {"location": "ALPHA-1"}, self.operator), 404)

    def test_reposition_happy_path_then_409(self):
        r = self.post("/cars/2/reposition", {"location": "BETA-1"}, self.operator)
        self.assertOk(r)
        self.assertEqual(r.data["status"], "Ordered")
        orders = self.car(2)["orders"]
        self.assertEqual(len(orders), 1)
        self.assertIn("-E", orders[0]["waybill_number"])
        self.assertEqual(orders[0]["unloading_location"], "BETA-1")
        self.assertError(self.post("/cars/2/reposition", {"location": "BETA-1"}, self.operator), 409, "conflict")

    def test_reposition_by_location_id(self):
        r = self.post("/cars/9/reposition", {"location": 4}, self.operator)
        self.assertOk(r)
        self.assertEqual(r.data["status"], "Ordered")

    def test_reposition_validation(self):
        self.assertError(self.post("/cars/10/reposition", {}, self.operator), 422, "invalid")
        self.assertError(self.post("/cars/10/reposition", {"location": "nowhere"}, self.operator), 422, "invalid")
        self.assertEqual(self.car(10)["status"], "Empty")

    def test_reposition_loaded_car_is_409(self):
        self.assertError(self.post("/cars/7/reposition", {"location": "ALPHA-1"}, self.operator), 409, "conflict")

    def test_reposition_waybill_numbers_do_not_collide(self):
        a = self.post("/cars/11/reposition", {"location": "BETA-1"}, self.operator)
        b = self.post("/cars/12/reposition", {"location": "BETA-1"}, self.operator)
        self.assertOk(a)
        self.assertOk(b)
        wa = self.car(11)["orders"][0]["waybill_number"]
        wb = self.car(12)["orders"][0]["waybill_number"]
        self.assertNotEqual(wa, wb)

    def test_moves_need_operator(self):
        self.assertError(self.post("/cars/8/reposition", {"location": "ALPHA-1"}, self.reader), 403, "forbidden")
        self.assertEqual(self.car(8)["status"], "Empty")

    def test_history_lists_a_move(self):
        r = api("GET", "/cars/8/history")
        self.assertOk(r)
        self.assertIn("total", r.meta)


class SessionAdvance(h.ApiTestCase):
    needs_fixture = True

    def test_advance_increments_session(self):
        before = api("GET", "/session").data["session_nbr"]
        r = self.post("/session/advance", {}, self.operator)
        self.assertOk(r)
        self.assertEqual(r.data["session_nbr_before"], before)
        self.assertEqual(r.data["session_nbr"], before + 1)
        self.assertEqual(api("GET", "/session").data["session_nbr"], before + 1)
        self.assertEqual(api("GET", "/settings/session_nbr").data["value"], str(before + 1))

    def test_advance_needs_operator(self):
        self.assertError(self.post("/session/advance", {}, self.reader), 403)


class Edits(h.ApiTestCase):
    needs_fixture = True

    def test_patch_car(self):
        r = api("PATCH", "/cars/3", {"remarks": "patched"}, token=self.editor)
        self.assertOk(r)
        self.assertEqual(r.data["remarks"], "patched")
        self.assertEqual(api("GET", "/cars/3").data["remarks"], "patched")

    def test_patch_car_by_names(self):
        r = api("PATCH", "/cars/4", {"job": "Local", "location": "ALPHA-1"}, token=self.editor)
        self.assertOk(r)
        self.assertEqual((r.data["job_id"], r.data["location_id"]), (1, 1))

    def test_patch_unknown_car_404_and_unknown_field_422(self):
        self.assertError(api("PATCH", "/cars/999999", {"remarks": "x"}, token=self.editor), 404)
        self.assertError(api("PATCH", "/cars/3", {"nonsense": 1}, token=self.editor), 422)
        self.assertError(api("PATCH", "/cars/3", {"location": "nowhere"}, token=self.editor), 422)

    def test_patch_needs_editor(self):
        self.assertError(api("PATCH", "/cars/3", {"remarks": "x"}, token=self.operator), 403)

    def test_create_car_and_explicit_id_conflict(self):
        body = {"reporting_marks": "NEW 1", "car_code_id": 1, "location_id": 1, "status": "Empty"}
        r = api("POST", "/cars", body, token=self.editor)
        self.assertOk(r, 201)
        new_id = r.data["id"]
        self.assertEqual(api("GET", "/cars/%d" % new_id).data["reporting_marks"], "NEW 1")
        dup = dict(body, reporting_marks="NEW 2", id=new_id)
        self.assertError(api("POST", "/cars", dup, token=self.editor), 409, "conflict")

    def test_create_car_validation(self):
        self.assertEqual(api("POST", "/cars", {}, token=self.editor).status, 422)
        self.assertEqual(api("POST", "/cars", {"reporting_marks": "X"}, token=self.editor).status, 422)

    def test_patch_job_writes_only_description(self):
        r = api("PATCH", "/jobs/Turn", {"description": "new text"}, token=self.editor)
        self.assertOk(r)
        self.assertEqual(r.data["description"], "new text")
        self.assertEqual(api("PATCH", "/jobs/Turn", {"name": "Renamed"}, token=self.editor).status, 422)

    def test_patch_shipment(self):
        r = api("PATCH", "/shipments/1", {"remarks": "r"}, token=self.editor)
        self.assertOk(r)
        self.assertEqual(r.data["remarks"], "r")

    def test_put_ownership(self):
        r = api("PUT", "/ownership/2", {"owner_id": 1, "on_off_rr": "on"}, token=self.editor)
        self.assertEqual(r.status, 200, r)
        rows = api("GET", "/ownership").data
        self.assertIn(2, [x["car_id"] for x in rows])

    def test_put_setting_roundtrip_and_session_nbr_refused(self):
        r = api("PUT", "/settings/apitest_setting", {"value": "42"}, token=self.editor)
        self.assertOk(r)
        self.assertEqual(api("GET", "/settings/apitest_setting").data["value"], "42")
        self.assertError(api("PUT", "/settings/session_nbr", {"value": "9"}, token=self.editor), 422)


class CrossOrigin(h.ApiTestCase):
    needs_fixture = True
    EVIL = {"Origin": "http://evil.example"}

    def test_foreign_origin_write_is_403_and_changes_nothing(self):
        r = api("POST", "/cars/1/reposition", {"location": "BETA-1"}, token=self.operator, headers=self.EVIL)
        self.assertError(r, 403, "forbidden")
        self.assertEqual(api("GET", "/cars/1").data["status"], "Empty")

    def test_foreign_origin_blocked_for_every_write_method(self):
        for m, p in (("POST", "/commands/zz-nope"), ("PUT", "/settings/x"), ("PATCH", "/cars/1"),
                     ("DELETE", "/workflows/zz-nope")):
            with self.subTest(op=m + " " + p):
                r = api(m, p, {}, token=self.admin, headers=self.EVIL)
                self.assertError(r, 403, "forbidden")

    def test_foreign_origin_with_other_port_is_403(self):
        host = h.HOST.split(":")[0]
        r = api("PUT", "/settings/x", {"value": "1"}, token=self.editor, headers={"Origin": "http://%s:1" % host})
        self.assertEqual(r.status, 403, r)

    def test_same_origin_write_is_allowed(self):
        r = api("PUT", "/settings/apitest_origin", {"value": "1"}, token=self.editor,
                headers={"Origin": "http://" + h.HOST})
        self.assertOk(r)

    def test_foreign_origin_read_is_fine_with_cors_star(self):
        r = api("GET", "/cars/1", headers=self.EVIL)
        self.assertEqual(r.status, 200)
        self.assertEqual(r.header("Access-Control-Allow-Origin"), "*")
