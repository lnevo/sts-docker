
# STS Docker

A containerised fork of the Shipper-Driven Traffic Simulator (STS) model railway operations software. Rather than requiring a local XAMPP or WAMP stack to be installed on your machine, everything — PHP 8, Apache, and MariaDB — runs inside Docker containers.

Current HART fork version: **0.2.3** (see [`VERSION`](VERSION) and [`CHANGELOG.md`](CHANGELOG.md)).

## Versioning and Recovery

This HART fork uses semantic versioning (`x.y.z`):

- `x` increments for breaking changes or major operating model resets.
- `y` increments for new features, seed structure changes, or workflow additions.
- `z` increments for fixes and small adjustments.

Each intentional project change should update `VERSION` and `CHANGELOG.md`, then be committed on a named branch and pushed to GitHub so prior states can be restored with Git.

## Getting Started — Using the pre-built image

1. **Install Docker.** On Mac, open Docker Desktop (find it in Applications) or install it via Homebrew:
   ```
   brew install docker
   ```
2. **Clone this repo:**
   ```
   git clone https://github.com/aaron9589/sts-docker
   cd sts-docker
   ```
3. **Edit `docker-compose.yml`.** Find the `web_prebuilt` section and update the settings marked with comments to suit your setup (port, database name, etc.).
4. **Start the containers:**
   ```
   docker compose up --profile prebuild
   ```
5. **Open your browser** and go to `http://localhost:8980/sts/` (or whichever port you mapped).
6. **Important:** Once everything is working, set `PROVISION_DATABASE` to `0` in `docker-compose.yml` so your database is not wiped on the next update.

## Building your own image

Follow steps 1–3 above, then run:
```
docker compose up --profile build --build
```

---

## Application overview

STS is organised into five main areas accessible from the home screen. Below is a description of every page, what it does, and what has changed in this Docker fork compared to the original XAMPP-based release.

Car status is shown throughout the application using colour-coded badges:

| Badge | Status | Meaning |
|---|---|---|
| 🟡 Yellow | Empty | Car is empty and available |
| 🟢 Green | Loaded | Car has been loaded |
| 🔵 Blue | Loading | Car is in the process of being loaded |
| 🟠 Orange | Unloading | Car is in the process of being unloaded |
| ⬜ Grey | Ordered | Car has an order assigned |
| 🔴 Red | Unavailable | Car is not available for use |

---

### Operations

The day-to-day workflow for running a session. Accessible from the **Operations** button on the home screen.

#### Generate Car Orders
Starts a new session. Select **Automatic** to increment the session number and generate all car orders in one step, or **Manual** to hand-pick specific shipments to order cars for.

**Changes:** UI modernised to Bootstrap 5. The manual order confirmation was cleaned up — now uses the browser's native confirm dialog instead of a custom JS alert. Filter dropdowns on the manual shipment table work without a page reload.

---

#### Fill Car Orders
Shows all open car orders (waybills) for the current session. Click an order to expand it and see all eligible empty cars. Click a car to assign it. Cars are colour-ranked by priority:
1. Cars in the shipment's pool, highlighted in yellow
2. Cars already at the shipper's station (sorted by lowest load count)
3. Cars at priority locations for this shipment
4. All remaining eligible cars, sorted by least used

**Changes:** Completely rebuilt UI. Orders are now shown as interactive expandable cards rather than a flat table. Available cars are loaded on demand via AJAX when you click an order — no page reload required. Car assignment is also done via AJAX. Car status shown with colour-coded badges.

---

#### Reposition Empty Cars
Shows all empty cars on the system and lets you assign each one a destination to reposition it. Selecting a destination creates an E-series waybill for that car. A single **REPOSITION TO HOME** button repositions all out-of-place cars to their home location at once.

**Changes:** UI modernised to Bootstrap 5.

---

#### Build Switch Lists
Assigns cars to jobs/trains for the session. Two modes:
- **Station-by-station**: Select a station to see cars and jobs at that stop, then assign cars individually.
- **Auto-Assign**: Select a job and click AUTO-ASSIGN to let the system fill all positions automatically.

Cars in the station table are now **grouped by current location** with a bold header row separating each group, making it easier to find cars when a station has multiple sidings.

**Changes:** UI modernised to Bootstrap 5. Car assignment uses AJAX — the car table refreshes in place without a full page reload. Job name case is now preserved (the original forced all job table names to lowercase, which broke jobs with mixed-case names). Bug fix: job table lookups no longer incorrectly lowercase the job name before querying.

---

#### Pick Up Cars
Select a job and mark all its assigned cars as picked up (sets their location to "in train"). The switchlist for the selected job is shown with each car's details and waybill information.

**Changes:** UI modernised to Bootstrap 5. On-screen "Working..." spinner removed; now uses Bootstrap's loading state. The "no cars" message now includes the job name for clarity (e.g. *"The switchlist for Job A doesn't contain any cars"* rather than the generic original).

---

#### Organize Cars
Shows the current consist order for a job or location and lets you drag and drop cars to re-sequence them. Position numbers update live as you drag.

**Changes:** UI modernised to Bootstrap 5. The drag-and-drop implementation was completely rewritten from basic HTML5 draggable events to a full pointer-events and touch-events implementation. This means **drag-and-drop now works on tablets and phones** as well as desktop. Lower-priority detail columns are automatically hidden on small screens to prevent horizontal scrolling. Position numbers auto-refresh after each drag without needing to reload the page. Bug fix: `update_car_positions.php` now identifies cars by their internal database ID rather than reporting marks, which is more reliable when marks contain special characters.

---

#### Set Out Cars
Select a job and record where each car in the consist was set out. A dropdown per car lets you choose the set-out location from the station's available sidings.

**New feature: Bulk set-out.** A **"Set all locations to"** dropdown at the top of the car list lets you set every car's destination to the same location in a single click. Individual dropdowns can still be adjusted afterwards. This saves significant time when setting out an entire consist to a single siding.

**Changes:** UI modernised to Bootstrap 5. Responsive layout with lower-priority columns hidden on tablet screens.

---

#### Load / Unload Cars
Shows all cars currently in the process of loading or unloading. Tick the checkbox next to a car and click **UPDATE** to complete the operation. Completing a load changes status to Loaded; completing an unload deletes the car order and returns the car to Empty.

**Changes:** UI modernised to Bootstrap 5. Also available programmatically via the REST API (see below) — useful for physical RFID readers or phone-based scanning apps.

---

### Reports

Printable and on-screen reports. Accessible from the **Reports** button on the home screen.

#### Switch Lists
Generates a printable switch list for a selected job, showing each car's pickup and set-out locations.

**Changes:** New **X2010** print format added alongside Mobile, Half Sheet, and Work Order. The default print format has been changed from Mobile to **Half Sheet**. When X2010 is selected the form automatically routes to a separate template (`printable_switchlist_x2010.php`). Bug fix: `GROUP BY` clause in the switch list SQL query simplified — the over-specified grouping in the original caused duplicate rows on strict MariaDB servers.

##### X2010 — Routing / Via flag

The **Contents** column in the X2010 form shows the commodity code for loaded cars, and displays a red ⚑ routing note beneath it when one is configured. The source of that note depends on the order type:

| Order type | Source field | How to set it |
|---|---|---|
| Revenue car (normal order) | `shipments.special_instructions` | Edit the shipment in **DB Manage → Shipments** |
| Empty/reposition car (E-order) | `locations.remarks` on the destination location | Edit the location in **DB Manage → Locations** |

The flag is **suppressed** (left blank) when:
- The source field is empty/null — the most common case; not every move needs routing notes
- The source field contains exactly `n/a` (case-insensitive) — the explicit opt-out value

Both column headers in the database management pages include a ⓘ tooltip as a reminder of this behaviour.

---

#### Waybills
Generates car waybills for all open car orders in the session.

**Changes:** No functional changes.

---

#### Fleet Report
An overview of all rolling stock — shows each car's reporting marks, car code, current location, status, and load count. Can be filtered by car code.

**Changes:** Filter condition changed from a string comparison (`!= 'All'`) to a numeric check (`> 0`), which is more robust against unexpected form values.

---

#### Station Report
Shows all cars currently at a given station, with their status, current location, and order details. Previously this opened a separate printable page; it now generates inline.

**Changes:** Report now renders directly within the page, no redirect to a separate file. Print button added to the page header.

---

#### Wheel Report
A job-by-job breakdown of all cars in the current session, grouped by job and pickup location. Useful for checking what each crew will be handling.

**Changes:** Major SQL fix — `GROUP BY` clause simplified to `group by job_id, job_name, car_id`, resolving duplicate row issues on strict MariaDB servers. Sort order improved to sort by station sequence, station name, location code, and then position/reporting marks. Job name lookup no longer lowercases names (matches the broader job name case fix).

---

#### Car QR Codes
Generates and prints QR code labels for rolling stock. Each label encodes the car's reporting marks and includes its details.

**Changes:** Filter condition uses numeric comparison (`> 0`). Removed unnecessary `trim()` calls from QR image generation, which could alter code values on some PHP versions.

---

#### Station QR Codes
Generates and prints QR code and barcode labels for each location/siding. Barcodes encode the location ID for scanner lookup.

**Changes:** Filter condition uses numeric comparison (`> 0`). Removed unnecessary `trim()` calls from QR and barcode generation.

---

#### CC Waybill / Shipment Forecast / Car Forecast
Standard conductor's copy waybill and forecasting reports.

**Changes:** No functional changes.

---

### Database Management

View and edit the underlying layout data. Accessible from the **DB Manage** button on the home screen.

#### Cars (Fleet List)
Lists all rolling stock in the database. Add new cars, edit reporting marks, car code, status, remarks, RFID code, load count, and current location.

**Changes:** Fully modernised. Bootstrap 5 layout with sticky table header. **Inline cell editing**: click a cell to edit it directly and save without leaving the page, powered by AJAX (`update_car_ajax.php`, `get_dropdowns_ajax.php`). Car status shown with colour-coded badges. Filters (car code, location, home location, reporting marks prefix) still work as before.

---

#### Scan Car
Look up any car by typing its reporting marks or scanning a QR/barcode. Shows the car's current status and a link to edit it directly.

**Changes:** Bug fix — the "Edit car" link was missing the `obj_id` parameter, so clicking it would fail to load the correct car in the edit form. Now passes both `obj_id` and `obj_name` correctly.

---

#### Jobs
Add, rename, and delete job/train route definitions. Each job has a list of station steps with pickup and set-out instructions.

**Changes:** Bug fix — the original forced all job table names to lowercase when creating, renaming, or querying jobs (`strtolower()`). This broke any job with uppercase letters or mixed case (e.g. a job called "NR Class" would be stored as "nr class" and then fail to link correctly from other pages). Job names are now stored exactly as entered.

---

#### Car Orders
View all open car orders (waybills) currently in the database.

**Changes:** No functional changes.

---

#### Shipments / Locations / Routing / Car Codes / Commodities
Standard data management pages for layout configuration.

**Changes:** No functional changes.

---

#### Backup DB
Triggers a download of the full database backup file.

**Changes:** No functional changes.

---

#### Restore DB
Upload a previously downloaded backup file to restore the database.

**Changes:** UI modernised to Bootstrap 5.

---

#### Import Tables
Import table data from CSV files.

**Changes:** No functional changes.

---

### Database Maintenance

Tools for keeping the database healthy. Accessible from the **DB Maint** button on the home screen.

#### Validate DB
Checks the database for ghost records, orphaned car orders, and inconsistencies between related tables.

**Changes:** Bug fix — job name queries were being lowercased before the lookup (`lower(name)`), causing any mixed-case job names to appear as ghost records even if they were valid. Removed the `lower()` wrapper to match the broader job name case fix.

---

#### Restart Session / Reset DB / Settings
Standard maintenance operations.

**Changes:** No functional changes.

---

#### Wipe DB
Wipes the entire database back to a blank state.

**Changes:** Same job name case fix as Validate DB — the wipe now correctly identifies job tables regardless of their case.

---

### REST API *(new in this fork)*

A JSON REST API has been added so that external tools — RFID readers, phone apps, or layout automation software — can interact with STS programmatically without screen-scraping the web pages. All five endpoints are tested and working.

**Base URL:** `http://localhost:8980/sts/api/index.php`

| Endpoint | Method | What it does |
|---|---|---|
| `/wagon/cargo/id/:tag` | GET | Look up a wagon by reporting marks, RFID code, or car ID |
| `/wagon/location/:location` | GET | List all wagons currently at a given location |
| `/wagon/unload` | POST | Mark a wagon as loaded or empty (mirrors the Load/Unload page) |
| `/wagon/reposition` | POST | Reposition an empty wagon and create an E-series waybill |
| `/wagon/details` | GET | Return full car and order details |

See [`sts/api/README.md`](sts/api/README.md) for full request/response documentation and curl examples.

> **Security note:** The API has no authentication layer. It is designed for use on a private home network. Do not expose the port publicly without first adding authentication.

---

## Docker-specific changes

These are changes specific to running STS inside Docker rather than under XAMPP/WAMP.

- **No local web server needed.** PHP 8.0, Apache, and MariaDB all run inside Docker containers. Nothing extra needs to be installed on the host machine beyond Docker itself.
- **Database configured via environment variables.** The original STS hardcoded the database hostname, username, password, and database name in `credentials.php`. This fork reads those from Docker environment variables (`MYSQL_HOST`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_DATABASE`) set in `docker-compose.yml`. Hardcoded defaults are kept as a fallback.
- **Automatic directory setup.** The Dockerfile creates the required `temp/`, `backups/`, `uploads/`, and `ImageStore/` directories and sets the correct permissions automatically on first run.
- **Database provisioning flag.** Set `PROVISION_DATABASE=1` in `docker-compose.yml` to create a fresh database on startup. Set it back to `0` after first run to prevent accidentally wiping your data on container restarts.
- **HART layout seed data.** On first provision, `start.sh` loads [`sts/seed_hart_data.sql`](sts/seed_hart_data.sql) immediately after the empty schema. This populates stations, industry spots, commodities, car codes, shipments, the freight fleet, and switching jobs for the HART layout.
- **`open_db.php` SQL fix.** The history query contained an over-specified `GROUP BY` clause (`group by car_id, session_nbr`) that caused errors on strict MariaDB servers. Simplified to `group by car_id`.

---

## HART layout seed data

The Docker image can provision a populated HART reference database (no jobs or live session state). Sources live in `~/Desktop/HART/Car Cards/` and are converted to SQL by a generator script.

### Regenerating the seed file

When spot assignments, waybills, or the roster change:

```bash
cd sts-docker
python3 scripts/generate_hart_seed.py
```

Optional flags: `--hart-dir`, `--config`, `--output`. Defaults read from `~/Desktop/HART/Car Cards/` and write `sts/seed_hart_data.sql`.

### Reload database with new seed (usual workflow)

No image rebuild — containers stay up; seed is copied in and SQL is reloaded:

```bash
cd sts-docker
./scripts/reseed_hart_db.sh
```

Optional: `--sync-images`, `--skip-generate`, `--recreate-containers` (down -v + up first).

Manual equivalent:

```bash
python3 scripts/generate_hart_seed.py
docker cp sts/seed_hart_data.sql sts-docker-web-1:/var/www/html/sts/
docker exec sts-docker-web-1 bash -c '
  mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" \
    -e "DROP DATABASE IF EXISTS \`$MYSQL_DATABASE\`; CREATE DATABASE \`$MYSQL_DATABASE\`;"
  mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" \
    < /var/www/html/sts/create_sts_db3.sql
  mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" \
    < /var/www/html/sts/seed_hart_data.sql
'
```

### Full rebuild (seed + Docker image)

Only needed when PHP/sts files in the image changed, not for seed-only updates:

```bash
cd sts-docker
./scripts/rebuild_hart_docker.sh
```

Empty-move shipments (`inbound_empty`, `outbound_empty`) use commodity code **`EMPTY`** so **Validate DB** does not report “missing Commodity”.

Only freight cars with a **CarImagesFinal** photo in `image_metadata.csv` are seeded (60 of 77 roster cars). Car IDs keep the same gaps as the full roster so `./images/{id}.jpg` files stay aligned.

**Car codes** use [AAR mechanical designators](sts/uploads/MRR-AAR_Class_Codes.csv) without length digits (`HA`, `HC`, `HP`, `XM`, …). Open hoppers → `HA`; outside-dump/coke hoppers → `HB`; covered gravity (cement/carbon) → `HC`; covered pneumatic (plastic pellets) → `HP`; lined chemical tanks → `TL`. Shipments pick the matching AAR code in the fleet for each car type/commodity.

### Applying seed changes (no database volume)

For seed-only updates, use **`./scripts/reseed_hart_db.sh`** (see above). It does not require `docker compose build`.

To reprovision via container startup instead (ephemeral DB, no `./db` volume):

```bash
cd sts-docker
python3 scripts/generate_hart_seed.py
docker compose --profile build down -v
docker compose --profile build up -d
# then copy seed + reload, or rebuild web image so COPY sts picks up the file
```

`start.sh` loads the seed only when `/opt/sql.initialized` is absent and `PROVISION_DATABASE=1`. The reseed script reloads SQL directly and is simpler when containers are already running.

### Expected row counts

| Table | Count |
|-------|------:|
| `routing` (stations) | 12 |
| `locations` | 81 |
| `commodities` | 34 |
| `car_codes` | varies (AAR mechanical designators) |
| `shipments` | 88 |
| `cars` (freight + MOW with photos) | 63 |
| `jobs` | 5 |

Settings are updated to `railroad_name=HART Railroad, Pittsburgh & Chartiers Valley Division` and `railroad_initials=HART`. Jobs are seeded from the Neville Island dispatcher and yardmaster ops documents (`D749`, `NVL`, `South Yard`, `CK-1`, `CKX`). Passenger moves (Neville Queen, OCS-1) are dispatcher-managed and not seeded as switching jobs.

**Existing database (without full reprovision):** empty-move shipments use commodity `EMPTY` (not `consignment=0`). Apply [`sts/patch_empty_commodity.sql`](sts/patch_empty_commodity.sql):

```bash
docker exec -i sts-docker-db-1 mariadb -usts_user -psts_password sts_db3 < sts/patch_empty_commodity.sql
```

### Rolling stock photos

STS reads car photos from `ImageStore/DB_Images/RollingStock/{car_id}.jpg` inside the container. That path is bind-mounted from **`./images`** in this repo (see `docker-compose.yml`), so drop or generate JPGs there on the host.

```bash
cd sts-docker
python3 scripts/sync_hart_car_images.py   # writes to ./images/{car_id}.jpg
```

The script reads `~/Desktop/HART/Car Cards/image_metadata.csv`, uses rows tagged `final_ref=CarImagesFinal/...`, maps `roster_id` to STS car IDs, and converts PNG → JPG. About **60 freight cars** get images; engines, MOW, and promotional cars are skipped.

Restart or recreate the web container after syncing so the mount picks up new files:

```bash
docker compose --profile build up -d
```

### Persistent database (optional)

MariaDB data is ephemeral unless you enable a host volume. To persist across container recreations, uncomment in `docker-compose.yml` under the `db` service:

```yaml
volumes:
  - ./db:/var/lib/mysql
```

The `db/` folder is reserved for that mount (see `db/.gitkeep`).

### First-time provision

1. Set `PROVISION_DATABASE=1` in `docker-compose.yml`.
2. Start with a fresh database volume (or first run on a new machine).
3. Open STS and use **DB Maint → Validate DB** to confirm no orphan records.
4. Set `PROVISION_DATABASE=0` before subsequent restarts.