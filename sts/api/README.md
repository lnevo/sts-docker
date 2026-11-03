# STS REST API

Two generations live side by side behind one front controller (`index.php`):

- **v1** (`/sts/api/v1/...`): resource-shaped JSON, bearer-token auth, OpenAPI
  spec, Swagger UI. Contract: `docs/plans/2026-11-03-api-v1.md`.
- **Legacy wagon API** (`/sts/api/index.php/wagon/...` and `/sts/api/wagon/...`):
  unchanged paths, methods and bare response shapes for RFID scanners and
  existing clients. Documented in the second half of this file.

Apache hands any `/sts/api/` path that is not a real file to `index.php`
(`docker/apache-sts-api.conf`, `FallbackResource`), so no mod_rewrite is needed.

## v1 quick start

- Base URL: `http://localhost:8980/sts/api/v1` (sandbox: `8981`)
- Spec: `GET /sts/api/v1/openapi.json`; Swagger UI: `/sts/api/docs/`
- Success: `{"data": ..., "meta": {"total", "limit", "offset"}}`
  (`meta` only on lists; `limit` default 100, max 1000)
- Error: `{"error": {"code": "<slug>", "message": "..."}}` with 400 `bad_request`,
  401 `unauthorized`, 403 `forbidden`, 404 `not_found`, 405 `method_not_allowed`,
  409 `conflict`, 422 `invalid`, 500 `internal` (never SQL or exception text).
- All v1 field names are **snake_case** (`reporting_marks`, `location_id`,
  `car_code`); a foreign key `<thing>_id` sits beside its resolved value.
- CORS is open (`*`); `OPTIONS` returns 204.

```bash
curl http://localhost:8980/sts/api/v1/health
curl -H "Authorization: Bearer $(cat ../sts-docker-data/config/bootstrap_admin.token)" \
     http://localhost:8980/sts/api/v1/admin/tokens
curl 'http://localhost:8980/sts/api/v1/cars?status=Empty&limit=20'
```

### Auth and roles

`Authorization: Bearer sts_<40 hex>`. Roles are hierarchical:
`reader` < `operator` < `editor` < `admin`.

| Role | Allowed |
| --- | --- |
| reader | every GET |
| operator | operating moves (load/unload/reposition, advance session, run workflows, non-destructive commands) |
| editor | data edits, saving/deleting workflows |
| admin | backup/restore, warm start, begin/play session, destructive commands, token management |

Environment (web service):

- `STS_API_READ_OPEN=1` (default): GETs need no token. `0` requires `reader`.
- `STS_API_LEGACY_OPEN=1` (default): legacy wagon routes need no token. `0`
  requires `operator`.
- `STS_API_ADMIN_TOKEN`: accepted as an admin token, not stored (CI use).
- `GET /v1/health`, `GET /v1/openapi.json` and `/sts/api/docs/` are always open.
- `STS_STACK_NAME` (`live` by default, `sandbox` in the sandbox compose) is
  reported by `/health`.

Tokens live in `/var/lib/sts/api_tokens.json` (bind mount of
`../sts-docker-data/config`; sandbox: `${STS_SANDBOX_DATA}/config`), outside the
web root and the database, so a restore cannot wipe them. Only SHA-256 hashes are
stored; a token is shown once, at creation. `last_used_at` is refreshed at most
once a minute per token.

On container start `start.sh` runs `tokens.php bootstrap`: when the store has no
active admin token it creates one named `bootstrap` and writes it to
`/var/lib/sts/bootstrap_admin.token` (mode 640, www-data), i.e.
`../sts-docker-data/config/bootstrap_admin.token` on the host.

### Token CLI

```bash
docker exec sts-docker-web-1 php /var/www/html/sts/api/bin/tokens.php list
docker exec sts-docker-web-1 php /var/www/html/sts/api/bin/tokens.php create ci-bot operator
docker exec sts-docker-web-1 php /var/www/html/sts/api/bin/tokens.php revoke tok_1a2b3c4d
```

The same operations over HTTP (admin): `GET/POST /v1/admin/tokens`,
`DELETE /v1/admin/tokens/{id}`.

### Read routes (reader)

`/health` (open), `/session`, `/settings[/{name}]`, `/cars[/{car}[/history]]`
(filters `status`, `location`, `job`, `car_code`, `marks`), `/locations[/{location}]`
(filter `station`), `/stations[/{id}]`, `/jobs[/{job}]` (steps, pickup criteria,
cars), `/shipments[/{id}]`, `/commodities`, `/car-codes`, `/owners`, `/ownership`,
`/car-orders[/{waybill}]` (filters `status=unfilled|filled|all`, `shipment`;
empty-car E-waybills have `is_empty_move: true` and a destination location),
`/catalog`, `/workflows[/{name}]`, `/sessions[/{n}]`, `/backups`.
`{car}` takes an id, reporting marks or RFID code; `{location}` an id or code;
`{job}` an id or name. Operate/edit/admin and task routes are added by later
stages; the spec at `/v1/openapi.json` always lists what is live.

### Adding a route (maintainers)

1. Register in a `sts/api/v1/routes_*.php` file (every such file is loaded):
   `api_route('GET', '/things/{id}', $handler, 'reader', [...])`. The handler gets
   `$req = [method, params, query, path, user, legacy]` and either returns the
   `data` value or calls `api_ok()`, `api_ok_list()`, `api_error()`.
2. Helpers live in `sts/api/lib/`: `response.php`, `input.php`, `db.php`
   (prepared-statement helpers), `auth.php`, `tokens.php`, `router.php`,
   `openapi.php`. Prepared statements only; whitelist filters.
3. Document the route in the matching fragment under `sts/api/openapi/`
   (`read`, `operate`, `edit`, `admin`); they are merged at request time.

## Legacy wagon API

Base URL: `http://localhost:8980/sts/api/index.php` (or `/sts/api`, without
`index.php`). Responses are the old bare shapes; errors are `{"error": "message"}`.
Authentication: open unless `STS_API_LEGACY_OPEN=0`, then an `operator` token.

## Endpoints

### 1. Get Car Details by Tag/ID
**GET** `/wagon/cargo/id/:tag_name`

Returns comprehensive car information including current status, orders, and shipment details. Searches by reporting marks (will uppercase) or RFID code.

Can also accept car ID format delimited by "-" (e.g., "-123-") which will be converted to reporting marks.

**Parameters:**
- `tag_name` (required): Car reporting marks, RFID code, or car ID in format -ID-

**Response:**
```json
{
  "id": 1,
  "reportingMarks": "104-F",
  "carCode": "MHGX",
  "carCodeId": 5,
  "status": "Loaded",
  "rfidCode": "041AF3987005",
  "remarks": "76t | 14.6m",
  "loadCount": 8,
  "currentLocation": "Gunnedah",
  "currentLocationId": 13,
  "currentStation": "Staging",
  "waybillNumber": "005-007",
  "shipmentCode": "Gunnedah Flour",
  "consignment": "Flour",
  "loadingLocation": "Gunnedah",
  "loadingLocationId": 13,
  "loadingStation": "Staging",
  "unloadingLocation": "Flour Dump Road",
  "unloadingLocationId": 17,
  "unloadingStation": "Shoalhaven<br>Starches"
}
```

**Example:**
```bash
curl http://localhost:8980/sts/api/index.php/wagon/cargo/id/104-F
```

---

### 2. Get Cars at Location
**GET** `/wagon/location/:location_name`

Returns location details and all cars currently at that location.

Can also accept location ID format delimited by "%" (e.g., "%123%") which will be converted to location code.

**Parameters:**
- `location_name` (required): Location code or location ID in format %ID%

**Response:**
```json
{
  "location": {
    "id": 13,
    "code": "Gunnedah",
    "stationId": 0,
    "station": "Staging",
    "track": "",
    "spot": "",
    "remarks": ""
  },
  "cars": [
    {
      "position": 0,
      "reportingMarks": "104-F",
      "carCode": "MHGX",
      "status": "Loaded"
    },
    {
      "position": 0,
      "reportingMarks": "107-J",
      "carCode": "MHGX",
      "status": "Loaded"
    }
  ]
}
```

**Example:**
```bash
curl http://localhost:8980/sts/api/index.php/wagon/location/Gunnedah
```

---

### 3. Complete Wagon Unload
**POST** `/wagon/unload`

Completes the unloading process for a wagon. Only cars with Loading or Unloading status can be operated on (those that appear on the load_unload.php page).

**Behavior:**
- If status is "Loading": Changes to "Loaded", car orders remain
- If status is "Unloading": Changes to "Empty", car orders are deleted

**Request Body:**
```json
{
  "wagonId": 64,
  "reportingMarks": "2230W",
  "status": "Unloading"
}
```

**Required Fields:**
- `wagonId` (integer): Car database ID
- `reportingMarks` (string): Car reporting marks (for validation)
- `status` (string): Current status must be "Loading" or "Unloading"

**Response:**
```json
{
  "message": "Wagon unload completed",
  "carId": 64,
  "reportingMarks": "2230W",
  "newStatus": "Empty"
}
```

**Example:**
```bash
curl -X POST http://localhost:8980/sts/api/index.php/wagon/unload \
  -H "Content-Type: application/json" \
  -d '{
    "wagonId": 64,
    "reportingMarks": "2230W",
    "status": "Unloading"
  }'
```

---

### 4. Complete Wagon Load
**POST** `/wagon/load`

Completes the loading process for a wagon. Uses identical validation and status transition logic as unload (both operate on the same set of cars).

**Behavior:**
- If status is "Loading": Changes to "Loaded", car orders remain
- If status is "Unloading": Changes to "Empty", car orders are deleted

**Request Body:**
```json
{
  "wagonId": 65,
  "reportingMarks": "2240H",
  "status": "Unloading"
}
```

**Required Fields:**
- `wagonId` (integer): Car database ID
- `reportingMarks` (string): Car reporting marks (for validation)
- `status` (string): Current status must be "Loading" or "Unloading"

**Response:**
```json
{
  "message": "Wagon load completed",
  "carId": 65,
  "reportingMarks": "2240H",
  "newStatus": "Empty"
}
```

**Example:**
```bash
curl -X POST http://localhost:8980/sts/api/index.php/wagon/load \
  -H "Content-Type: application/json" \
  -d '{
    "wagonId": 65,
    "reportingMarks": "2240H",
    "status": "Unloading"
  }'
```

---

### 5. Reposition Empty Car
**POST** `/wagon/reposition`

Repositions an empty car to a new location. Only Empty cars with no existing car_orders can be repositioned (those that appear on the reposition.php page).

Creates an E-series empty car waybill with session-based numbering (e.g., 007-E01), sets car status to "Ordered", and inserts a history record.

**Request Body:**
```json
{
  "wagonId": 64,
  "reportingMarks": "2230W",
  "locationId": 2
}
```

**Required Fields:**
- `wagonId` (integer): Car database ID
- `reportingMarks` (string): Car reporting marks (for validation)
- `locationId` (integer): Destination location ID

**Validation:**
- Car must have status = "Empty"
- Car must NOT have any existing car_orders
- Destination location must exist

**Response:**
```json
{
  "message": "Wagon repositioned successfully",
  "carId": 64,
  "reportingMarks": "2230W",
  "waybillNumber": "007-E01",
  "destinationLocation": "Chillfreeze Logistics | Intermodal Siding",
  "newStatus": "Ordered"
}
```

**Example:**
```bash
curl -X POST http://localhost:8980/sts/api/index.php/wagon/reposition \
  -H "Content-Type: application/json" \
  -d '{
    "wagonId": 64,
    "reportingMarks": "2230W",
    "locationId": 2
  }'
```

---

## Error Codes

| Status Code | Meaning |
|---|---|
| 200 | Success |
| 400 | Bad Request (invalid parameters, missing required fields, or validation failure) |
| 404 | Not Found (car, location, or car not eligible for operation) |
| 405 | Method Not Allowed (wrong HTTP method) |
| 500 | Internal Server Error |

## Common Errors

### Missing Required Fields
```json
{
  "error": "Missing required fields: wagonId, reportingMarks"
}
```

### Car Not Eligible for Operation
```json
{
  "error": "Car not available for repositioning (must be Empty with no orders)"
}
```

### Car Not Found
```json
{
  "error": "Car not found or does not have Loading/Unloading status"
}
```

### Location Not Found
```json
{
  "error": "Destination location not found"
}
```

---

## Implementation Notes

- All car operations validate that the car ID matches the reporting marks to prevent accidental operations on wrong cars
- Status transitions follow the business rules: Loading→Loaded, Unloading→Empty (with order deletion)
- Car orders are only deleted when a car completes unloading (transitions to Empty status)
- Reposition operations create traceable history records with timestamps
- E-series waybill numbering increments per session and persists across API calls
- The API maintains complete referential integrity with the existing database schema

---

## Testing & Validation

### Test Coverage
All 5 endpoints have been tested and verified:

| Endpoint | Method | Test Car | Status | Result |
|----------|--------|----------|--------|--------|
| Get Car Details | GET | 104-F | Loaded | ✅ PASS - Returns full car details with order and station info |
| Get Location Cars | GET | Gunnedah | - | ✅ PASS - Returns location with 11+ cars at location |
| Unload Wagon | POST | 2230W | Unloading→Empty | ✅ PASS - Status changed, car_orders deleted |
| Load Wagon | POST | 2240H | Unloading→Empty | ✅ PASS - Status changed, car_orders deleted |
| Reposition Car | POST | 2230W (after unload) | Empty→Ordered | ✅ PASS - E-series waybill created (007-E01), location recorded |

### Database Validation
- ✅ Car status transitions verified in database
- ✅ Car orders properly deleted on unload operations
- ✅ E-series waybill numbers generated and persisted
- ✅ Destination location correctly stored in `car_orders.shipment` field
- ✅ All referential integrity constraints maintained

### Request/Response Format
- ✅ All requests use `Content-Type: application/json`
- ✅ All responses return valid JSON
- ✅ Error responses include descriptive error messages
- ✅ Success responses include operation details and new state

## Agent plugin

`agent/` holds a Claude Code plugin (skill, MCP server, inspector and operator
agents) that drives this API. Install with
`claude plugin marketplace add lnevo/sts-docker` then
`claude plugin install sts@sts-docker`, or add a local checkout path to
`marketplace add`. It reads `STS_API_URL` (default
`http://localhost:8980/sts/api/v1`; sandbox `8981`) and `STS_API_TOKEN` or
`STS_API_TOKEN_FILE`. See `agent/README.md`.
