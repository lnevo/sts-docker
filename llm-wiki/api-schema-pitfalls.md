---
title: STS Legacy DB Schema & API Notes
purpose:
  Discovered MySQL schema, endpoint behaviour, and known pitfalls for the legacy
  sts/ app.
load_when:
  writing or reviewing SQL/queries against the legacy sts/ MySQL schema, or
  working on sts/api/
owner: shared
last_updated: 11/03/2026
---

# STS Legacy DB Schema & API Notes

> Applies to the legacy `sts/` PHP app's MySQL/mysqli schema and `sts/api/`. The
> v2 rebuild (`app/`) uses its own SQLite schema — see
> `app/src/lib/server/db.ts` and `docs/SPEC.md`, not this page.

## REST API v1 (`/sts/api/v1`)

The contract and the "as built" deviations are in
`docs/plans/2026-11-03-api-v1.md`. Routes, environment flags, the token CLI and
the steps for adding a route are in [sts/api/README.md](../sts/api/README.md).
This section records only what trips people up.

- **Envelope.** Success is `{"data": ..., "meta": {...}}`, with `meta` (`total`,
  `limit`, `offset`) on lists only. Errors are
  `{"error": {"code": "...", "message": "..."}}`. A 500 never includes SQL or
  exception text, so look in the server log.
- **snake_case everywhere.** `reporting_marks`, `car_code`, `location_id`. A
  foreign key `<thing>_id` sits beside its resolved value. The legacy wagon
  routes use camelCase (`reportingMarks`, `wagonId`), so field names do not
  carry over between the two.
- **Identifiers resolve loosely.** `{car}` takes a database id, reporting marks
  or an RFID code. `{location}` takes an id or a `locations.code`. `{job}` takes
  an id or a job name. Array-valued query parameters (`status[]=x`) are 422.
- **`0` means unassigned.** `cars.current_location_id` and
  `cars.handled_by_job_id` hold `0`, not NULL, when nothing is assigned. Compare
  with `= 0` and `> 0`; `IS NULL` matches nothing.
- **`pu_criteria.job_id` holds the job name**, not `jobs.Id`. Join on the name
  and never wrap it in `lower()`.
- **`car_orders.shipment` is overloaded.** It holds a shipment id for ordinary
  orders and a location id for empty-car E-waybills (`NNN-E##`).
  `GET /car-orders` marks empty moves with `is_empty_move: true` and returns the
  destination location, so check that flag before reading `shipment` as a
  shipment.
- **Roles are hierarchical:** `reader` < `operator` < `editor` < `admin`. GETs
  need no token by default (`STS_API_READ_OPEN=1`), but `/tasks` always needs
  `operator`. The per-route mapping is in the README.
- **Tokens** are sent as `Authorization: Bearer sts_<40 hex>`. Only SHA-256
  hashes are stored, in `/var/lib/sts/api_tokens.json`. That path is a bind
  mount of `../sts-docker-data/config`, outside the database, because a seed
  restore drops every table. On start, `start.sh` writes a bootstrap admin token
  to `bootstrap_admin.token` in the same directory if no active admin exists.
- **Cross-site writes are refused.** A POST, PUT, PATCH or DELETE whose `Origin`
  host:port differs from `Host` gets 403. Requests without `Origin` (curl,
  helpers, RFID devices) are unaffected.
- **Destructive recipes need admin.** A recipe with `restore_database`,
  `backup_database`, `wipe_database`, `reset_session` or `restart_session` gets
  403 from a non-admin on save and on run, and the check repeats when the task
  starts. The stock `hart_session` and `start_session` workflows contain these
  steps, so an operator token cannot run them.
- **Long work returns 202 with a task.** Restore, warm start, session begin and
  play, workflow runs and the slow catalog commands all return `task_id`. Poll
  `GET /tasks/{id}?log=N` with an operator token. Task files live in
  `/var/lib/sts/tasks/`. Only one exclusive task runs per stack. While one is
  queued or running, other writes get 409 `conflict` with
  `maintenance task <id> running`.
- **No wall-clock timeout yet.** A hung task holds the maintenance lock until an
  admin fails it with `POST /admin/tasks/{id}/fail`. The process check on read
  marks a task failed when its process has vanished.

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

## Endpoint Behaviour (sts/api/): legacy wagon API

> **Legacy.** These routes are still served at `/sts/api/wagon/*` and
> `/sts/api/index.php/wagon/*` with the old bare response shapes and camelCase
> fields, for RFID scanners and existing clients. Use `/sts/api/v1` for new
> code. Reference for the legacy half of the API:
> [sts/api/README.md](../sts/api/README.md).

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
