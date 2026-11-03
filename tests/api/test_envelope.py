"""Envelope and error shape, pagination, filters (read-only, uses the fixture dataset)."""
import helpers as h
from helpers import api

LIST_ROUTES = ["/cars", "/locations", "/stations", "/jobs", "/shipments", "/commodities",
               "/car-codes", "/owners", "/ownership", "/car-orders", "/settings"]


class Envelope(h.ApiTestCase):
    needs_fixture = True

    def test_success_shape_single(self):
        r = api("GET", "/cars/1")
        self.assertOk(r)
        self.assertEqual(set(r.json), {"data"})
        self.assertIn("json", r.header("Content-Type"))
        self.assertEqual(r.data["reporting_marks"], "TST 1001")

    def test_success_shape_list_has_meta(self):
        for p in LIST_ROUTES:
            with self.subTest(route=p):
                r = api("GET", p)
                self.assertOk(r)
                self.assertIsInstance(r.data, list)
                self.assertIn("total", r.meta)

    def test_snake_case_fields(self):
        car = api("GET", "/cars/1").data
        for k in ("reporting_marks", "car_code_id", "location_id", "load_count", "rfid_code"):
            self.assertIn(k, car)
        self.assertFalse([k for k in car if k != k.lower() or " " in k])

    def test_404_resource(self):
        for p in ("/cars/999999", "/locations/zz-nope", "/jobs/zz-nope", "/shipments/999999",
                  "/stations/999999", "/car-orders/zz-nope", "/settings/zz-nope",
                  "/workflows/zz-nope", "/tasks/zz-nope"):
            with self.subTest(route=p):
                self.assertError(api("GET", p, token=self.admin), 404, "not_found")

    def test_404_unknown_route(self):
        r = api("GET", "/nope")
        self.assertError(r, 404, "not_found")

    def test_405_wrong_method(self):
        for m, p in (("DELETE", "/cars"), ("PUT", "/cars/1/load"), ("POST", "/health"), ("GET", "/cars/1/load")):
            with self.subTest(op=m + " " + p):
                self.assertError(api(m, p, {} if m != "GET" else None, token=self.admin), 405, "method_not_allowed")

    def test_422_validation(self):
        cases = [
            ("GET", "/cars?limit=0", None), ("GET", "/cars?limit=abc", None), ("GET", "/cars?offset=-1", None),
            ("GET", "/car-orders?status=bogus", None),
            ("POST", "/cars/1/reposition", {}),
            ("PATCH", "/cars/9", {"bogus": 1}), ("PATCH", "/cars/9", {"status": "Zzz"}),
            ("POST", "/admin/tokens", {"name": "x", "role": "god"}),
            ("POST", "/commands/stop", {}),
        ]
        for m, p, b in cases:
            with self.subTest(op="%s %s" % (m, p), body=b):
                self.assertError(api(m, p, b, token=self.admin), 422, "invalid")

    def test_400_malformed_body(self):
        r = api("POST", "/cars", raw=b"not json", token=self.admin)
        self.assertError(r, 400, "bad_request")

    def test_401_shape(self):
        self.assertError(api("GET", "/admin/tokens"), 401, "unauthorized")

    def test_403_shape(self):
        self.assertError(api("GET", "/admin/tokens", token=self.editor), 403, "forbidden")

    def test_errors_never_leak_internals(self):
        for r in (api("GET", "/cars/%27%20OR%201=1--"), api("GET", "/cars?status=%27"),
                  api("GET", "/locations/%00")):
            self.assertLess(r.status, 500, r)
            for needle in ("SQL", "mysqli", "Stack trace", "/var/www"):
                self.assertNotIn(needle, r.text)


class Pagination(h.ApiTestCase):
    needs_fixture = True

    def test_meta_defaults(self):
        r = api("GET", "/cars")
        self.assertEqual(r.meta, {"total": 12, "limit": 100, "offset": 0})
        self.assertEqual(len(r.data), 12)

    def test_limit_and_offset(self):
        r = api("GET", "/cars?limit=5&offset=10")
        self.assertEqual(r.meta, {"total": 12, "limit": 5, "offset": 10})
        self.assertEqual(len(r.data), 2)

    def test_pages_are_disjoint_and_cover_everything(self):
        seen = []
        for off in (0, 5, 10):
            seen += [c["id"] for c in api("GET", "/cars?limit=5&offset=%d" % off).data]
        self.assertEqual(len(seen), 12)
        self.assertEqual(len(set(seen)), 12)

    def test_offset_past_end_is_empty_not_error(self):
        r = api("GET", "/cars?offset=500")
        self.assertOk(r)
        self.assertEqual(r.data, [])
        self.assertEqual(r.meta["total"], 12)

    def test_limit_is_capped_at_1000(self):
        r = api("GET", "/cars?limit=5000")
        self.assertOk(r)
        self.assertLessEqual(r.meta["limit"], 1000)

    def test_total_ignores_paging_but_follows_filters(self):
        r = api("GET", "/cars?status=Empty&limit=2")
        self.assertEqual(len(r.data), 2)
        self.assertEqual(r.meta["total"], 9)


class Filters(h.ApiTestCase):
    needs_fixture = True

    def ids(self, path):
        r = api("GET", path)
        self.assertOk(r)
        return sorted(c["id"] for c in r.data)

    def test_cars_by_status(self):
        self.assertEqual(self.ids("/cars?status=Loading"), [5])
        self.assertEqual(self.ids("/cars?status=Unloading"), [6])
        self.assertEqual(self.ids("/cars?status=Loaded"), [7])

    def test_cars_by_location_code_and_id(self):
        self.assertEqual(self.ids("/cars?location=ALPHA-2"), [3, 4])
        self.assertEqual(self.ids("/cars?location=2"), [3, 4])

    def test_cars_by_job_name_and_id(self):
        self.assertEqual(self.ids("/cars?job=Turn"), [3, 4, 7, 8])
        self.assertEqual(self.ids("/cars?job=2"), [3, 4, 7, 8])

    def test_cars_by_car_code(self):
        self.assertEqual(self.ids("/cars?car_code=GB"), [7, 8])

    def test_cars_by_marks_prefix(self):
        self.assertEqual(len(self.ids("/cars?marks=TST")), 12)
        self.assertEqual(self.ids("/cars?marks=TST%201001"), [1])
        self.assertEqual(self.ids("/cars?marks=ZZZ"), [])

    def test_filters_combine(self):
        self.assertEqual(self.ids("/cars?status=Empty&location=ALPHA-2&job=Turn"), [3, 4])
        self.assertEqual(self.ids("/cars?status=Loaded&location=ALPHA-2"), [])

    def test_locations_by_station(self):
        r = api("GET", "/locations?station=1")
        self.assertEqual(sorted(x["code"] for x in r.data), ["ALPHA-1", "ALPHA-2"])

    def test_car_orders_by_status_and_shipment(self):
        r = api("GET", "/car-orders?status=filled")
        self.assertEqual(len(r.data), 3)
        self.assertEqual(api("GET", "/car-orders?status=unfilled").data, [])
        self.assertEqual(api("GET", "/car-orders?status=all").meta["total"], 3)
        self.assertEqual(api("GET", "/car-orders?shipment=WIDGETS-OUT").meta["total"], 3)
        self.assertEqual(api("GET", "/car-orders?shipment=NOPE").meta["total"], 0)

    def test_array_params_are_422(self):
        for p in ("/cars?status[]=Empty", "/cars?location[]=1", "/cars?limit[]=2", "/cars?marks[a]=T",
                  "/locations?station[]=1", "/car-orders?status[]=all", "/car-orders?shipment[]=1"):
            with self.subTest(route=p):
                self.assertError(api("GET", p), 422, "invalid")

    def test_resolvers_accept_id_marks_and_rfid(self):
        for ref in ("1", "TST%201001", "RFID1001"):
            with self.subTest(ref=ref):
                self.assertEqual(api("GET", "/cars/" + ref).data["id"], 1)
        self.assertEqual(api("GET", "/locations/ALPHA-1").data["id"], 1)
        self.assertEqual(api("GET", "/locations/1").data["code"], "ALPHA-1")
        self.assertEqual(api("GET", "/jobs/Local").data["id"], 1)
        self.assertEqual(api("GET", "/jobs/1").data["name"], "Local")
