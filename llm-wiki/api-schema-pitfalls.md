---
title: STS Legacy DB Schema & API Notes
purpose:
  Discovered MySQL schema, endpoint behaviour, and known pitfalls for the legacy
  sts/ app.
load_when:
  writing or reviewing SQL/queries against the legacy sts/ MySQL schema, or
  working on sts/api/
owner: shared
last_updated: 06/07/2026
---

# STS Legacy DB Schema & API Notes

> Applies to the legacy `sts/` PHP app's MySQL/mysqli schema and `sts/api/`. The
> v2 rebuild (`app/`) uses its own SQLite schema — see
> `app/src/lib/server/db.ts` and `docs/SPEC.md`, not this page.

## Discovered Database Schema

### Cars Table

- `Id` (int, PK, auto_increment)
- `reporting_marks` (varchar)
- `car_code_id` (int, FK → car_codes.Id)
- `current_location_id` (int, FK → locations.Id)
- `position` (int)
- `status` (varchar)
- `handled_by_job_id` (int, FK → jobs.Id)
- `remarks` (text)
- `load_count` (int)
- `home_location` (int, FK → locations.Id)
- `RFID_code` (char)
- `block_id` (int) - added later
- `last_spotted` (int) - added later

### Car_Orders Table

- `waybill_number` (varchar, PK)
- `shipment` (int, FK → shipments.Id) **NOT destination**
- `car` (int, FK → cars.Id)

### Locations Table

- `Id` (int, PK, auto_increment)
- `code` (tinytext)
- `station` (int, FK → routing.id) **NOT station_id**
- `track` (tinytext)
- `spot` (tinytext)
- `rpt_station` (tinytext)
- `remarks` (text)
- `color` (tinytext)

### History Table (created dynamically in open_db.php)

```sql
CREATE TABLE IF NOT EXISTS history (
  car_id int,
  session_nbr int,
  event_date datetime,
  event varchar(256),
  location int
)
```

### Routing Table

- `id` (int, PK, auto_increment)
- `station` (tinytext)
- `station_nbr` (int)
- `instructions` (text)
- `sort_seq` (int)
- `color1` (int)
- `color2` (int)

## Endpoint Behaviour (sts/api/)

### GET /wagon/cargo/id/:tag_name

**Purpose:** Scan car by reporting marks, RFID, or car ID **Input Formats:**

- Reporting marks (e.g., "104-F") - uppercase
- RFID code (e.g., "123456789")
- Car ID format (e.g., "-123-") - extracts ID and looks up reporting marks

**Query:** Replicates scan_car.php exactly with all joins **Returns:** All car
details including orders, locations, stations, commodities

### GET /wagon/location/:location_name

**Purpose:** Get location and all cars at that location **Input Formats:**

- Location code (e.g., "PORT1")
- Location ID format (e.g., "%123%") - extracts ID and looks up code

**Query:** Replicates scan_location.php query structure **Returns:** Location
details + list of cars with position, car code, status

### POST /wagon/load and /wagon/unload

**Purpose:** Complete loading or unloading of a car **Display Query (which cars
appear on page):**

```sql
WHERE ((cars.status = "Loading")
    OR (cars.status = "Unloading")
    OR ((cars.status = "Empty") AND (cars.current_location_id = car_orders.shipment)))
```

**Update Logic (POST handler):**

```php
if ($_POST['status'] == 'Loading') {
  $new_status = 'Loaded';
  // Keep car_orders
} else if ($_POST['status'] == 'Unloading') {
  $new_status = 'Empty';
  DELETE FROM car_orders WHERE car = ?;  // Delete orders
}
UPDATE cars SET status = ?, last_spotted = 0 WHERE id = ?;
```

**Required Fields:** waybill_number, reportingMarks, status (Loading or
Unloading)

### POST /wagon/reposition

**Purpose:** Reposition empty car to new location **Display Query (which cars
appear on page):**

```sql
WHERE status = "Empty"
  AND NOT EXISTS (SELECT car_orders.car FROM car_orders WHERE cars.id = car_orders.car)
```

**Update Logic (POST handler):**

1. Get current session number from settings
2. Generate next E-series waybill number (XXX-E##)
3. INSERT INTO car_orders (waybill_number, shipment, car) VALUES (?, ?, ?)
4. UPDATE cars SET status = 'Ordered' WHERE id = ?
5. INSERT INTO history (car_id, session_nbr, event_date, event, location)

**Required Fields:** wagonId, reportingMarks, locationId

## Known Schema Pitfalls

1. ❌ Use `station` (not `station_id`) in locations table joins
2. ❌ Use `shipment` (not `destination`) in car_orders
3. ❌ Always qualify column names in multi-join queries to avoid
   ambiguous-column errors
4. ❌ History record `location` field stores a location ID (int), not a name
5. ❌ Do not wrap job names in `lower()` in queries — job name case is preserved
   (a past bug)
6. ❌ `update_car_positions.php` keys on `cars.id`, never `reporting_marks`

## Validation Testing Plan (REST API vs legacy pages)

When changing `sts/api/` endpoint behaviour, verify parity against the
equivalent legacy page:

### Phase 1: Through Existing Pages

1. Test cargo lookup via scan_car.php
2. Test location lookup via scan_location.php
3. Test load operation via load_unload.php
4. Test unload operation via load_unload.php
5. Test reposition operation via reposition.php
6. Record exact values, timestamps, database changes

### Phase 2: Through REST API

1. Call GET /wagon/cargo/id/:tag_name with same input
2. Call GET /wagon/location/:location_name with same location
3. Call POST /wagon/load with captured data
4. Call POST /wagon/unload with captured data
5. Call POST /wagon/reposition with captured data
6. Compare results and database changes

### Phase 3: Validation

- Verify fields match exactly
- Verify status transitions are correct
- Verify car_orders are deleted when appropriate
- Verify history records are created
- Verify waybill numbering is correct

---

_Migrated from `.github/instructions/api-schema.instructions.md` into the shared
wiki on 06/07/2026 — see [decisions-log.md](decisions-log.md)._
