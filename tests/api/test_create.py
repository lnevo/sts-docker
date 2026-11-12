"""Create shipments and locations, and fill one car order with a chosen car.

Fixture: shipment 1 WIDGETS-OUT (car code 1, ALPHA-1 to location 3), stations
1 Alpha Yard and 2 Beta Siding, cars 1,2,9-12 Empty at ALPHA-1, car 7 Loaded on
order 000-003.
"""
import helpers as h
from helpers import api

SHIP = {
    "code": "NEW-LANE", "description": "Alpha to Beta", "commodity_id": 1,
    "car_code_id": 1, "loading_location": "ALPHA-1", "unloading_location": 3,
    "min_interval": 1, "max_interval": 2, "min_amount": 1, "max_amount": 3,
}


class CreateShipment(h.ApiTestCase):
    needs_fixture = True

    @h.needs_route("POST", "/shipments")
    def test_create_then_duplicate_is_409(self):
        r = api("POST", "/shipments", SHIP, token=self.editor)
        self.assertOk(r, 201)
        self.assertEqual(r.data["code"], "NEW-LANE")
        self.assertEqual(r.data["loading_location"], "ALPHA-1")
        self.assertEqual(r.data["last_ship_date"], 0)
        got = api("GET", "/shipments/%d" % r.data["id"]).data
        self.assertEqual(got["max_amount"], 3)
        self.assertError(api("POST", "/shipments", SHIP, token=self.editor), 409, "conflict")

    @h.needs_route("POST", "/shipments")
    def test_validation(self):
        for drop in ("code", "commodity_id", "loading_location", "max_amount"):
            with self.subTest(missing=drop):
                body = {k: v for k, v in SHIP.items() if k != drop}
                self.assertEqual(api("POST", "/shipments", dict(body, code="V-" + drop) if drop != "code" else body, token=self.editor).status, 422)
        self.assertEqual(api("POST", "/shipments", dict(SHIP, code="V-REV", min_amount=4), token=self.editor).status, 422)
        self.assertEqual(api("POST", "/shipments", dict(SHIP, code="V-NEG", min_interval=-1), token=self.editor).status, 422)
        self.assertEqual(api("POST", "/shipments", dict(SHIP, code="V-LOC", loading_location="NOPE"), token=self.editor).status, 422)
        self.assertEqual(api("POST", "/shipments", dict(SHIP, code="V-EXTRA", id=99), token=self.editor).status, 422)

    @h.needs_route("POST", "/shipments")
    def test_needs_editor(self):
        self.assertEqual(api("POST", "/shipments", dict(SHIP, code="V-ROLE"), token=self.operator).status, 403)


class CreateLocation(h.ApiTestCase):
    needs_fixture = True

    @h.needs_route("POST", "/locations")
    def test_create_by_station_name_then_duplicate_is_409(self):
        body = {"code": "BETA-NEW", "station": "Beta Siding", "track": "Spur", "rpt_station": "Beta Co"}
        r = api("POST", "/locations", body, token=self.editor)
        self.assertOk(r, 201)
        self.assertEqual(r.data["station"], "Beta Siding")
        self.assertEqual(r.data["spot"], "")
        self.assertEqual(r.data["cars"], [])
        self.assertEqual(api("GET", "/locations/BETA-NEW").data["rpt_station"], "Beta Co")
        self.assertError(api("POST", "/locations", body, token=self.editor), 409, "conflict")

    @h.needs_route("POST", "/locations")
    def test_validation(self):
        self.assertEqual(api("POST", "/locations", {"code": "NO-STN"}, token=self.editor).status, 422)
        self.assertEqual(api("POST", "/locations", {"code": "BAD-STN", "station": "Nowhere"}, token=self.editor).status, 422)
        self.assertEqual(api("POST", "/locations", {"station_id": 1}, token=self.editor).status, 422)


class FillOrder(h.ApiTestCase):
    needs_fixture = True

    def new_order(self):
        """An unfilled WIDGETS-OUT order, made by the generate_orders command."""
        before = {o["waybill_number"] for o in api("GET", "/car-orders?status=unfilled&limit=1000").data}
        r = self.post("/commands/generate_orders", {"params": {"shipment": "WIDGETS-OUT"}}, self.operator)
        self.assertOk(r)
        orders = api("GET", "/car-orders?status=unfilled&limit=1000").data
        fresh = [o["waybill_number"] for o in orders if o["waybill_number"] not in before]
        self.assertTrue(fresh, "generate_orders made no unfilled order")
        return fresh[0]

    @h.needs_route("POST", "/car-orders/{waybill}/fill")
    def test_fill_at_loading_location_is_loaded_then_409(self):
        wb = self.new_order()
        r = self.post("/car-orders/%s/fill" % wb, {"car": "TST 1001"}, self.operator)
        self.assertOk(r)
        self.assertEqual(r.data["car_id"], 1)
        self.assertEqual(r.data["car_status"], "Loaded")
        self.assertError(self.post("/car-orders/%s/fill" % wb, {"car": 2}, self.operator), 409, "conflict")

    @h.needs_route("POST", "/car-orders/{waybill}/fill")
    def test_fill_elsewhere_is_ordered_unless_loaded(self):
        wb = self.new_order()
        r = self.post("/car-orders/%s/fill" % wb, {"car": 3}, self.operator)  # Empty at ALPHA-2
        self.assertOk(r)
        self.assertEqual(r.data["car_status"], "Ordered")
        wb2 = self.new_order()
        r = self.post("/car-orders/%s/fill" % wb2, {"car": 4, "loaded": True}, self.operator)
        self.assertOk(r)
        self.assertEqual(r.data["car_status"], "Loaded")

    @h.needs_route("POST", "/car-orders/{waybill}/fill")
    def test_refusals(self):
        wb = self.new_order()
        self.assertError(self.post("/car-orders/%s/fill" % wb, {"car": 7}, self.operator), 409, "conflict")  # Loaded, has order
        self.assertError(self.post("/car-orders/NOPE-1/fill", {"car": 9}, self.operator), 404)
        self.assertError(self.post("/car-orders/%s/fill" % wb, {"car": 999999}, self.operator), 404)
        self.assertEqual(self.post("/car-orders/%s/fill" % wb, {"car": 9, "loaded": "yes"}, self.operator).status, 422)
        self.assertEqual(self.post("/car-orders/%s/fill" % wb, {"car": 9, "x": 1}, self.operator).status, 422)
        self.assertEqual(api("POST", "/car-orders/%s/fill" % wb, {"car": 9}, token=self.reader).status, 403)
        self.assertEqual(api("GET", "/cars/9").data["status"], "Empty")
