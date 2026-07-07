# Changes to port to `ui-improvements`

Notes for work done on **`track-scale`** (or main HART layout branch) that must be carried over when switching to or merging into **`ui-improvements`**.

---

## 1. Auto-assign dropdown counts (Build Switch Lists)

**Date:** 2026-07-06  
**Status:** Applied on `track-scale` and `ui-improvements` (includes E-waybill reposition fix)

### Problem
On **Build Switch Lists → Auto-Assign**, the job dropdown shows a count in parentheses (e.g. `CK1 (6)`). That number did **not** match the cars listed on `auto_assign.php` (e.g. 4).

There is **no cache** — both values are computed on each page load.

### Root cause
`drop_down_jobs(..., true)` calls `pending_assignment_counts_by_job()`, which used to count **every** `Ordered` / `Loaded` car at **any pickup station** for the job.

`auto_assign.php` uses **`pu_criteria`**: car must be at the step’s station **and** match the criteria destination (revenue load, loaded unload, or non-revenue `E` reposition).

Example: CK1 counted 2 extra **Ordered** cars at North Yard that were not coke / did not match CK1 destination criteria.

### Fix
**File:** `sts/drop_down_list_functions.php`

1. Add `auto_assign_eligible_car_ids_for_job()` — same matching rules as `auto_assign.php` (unique car IDs per job).
2. Change `pending_assignment_counts_by_job()` to use that helper instead of the broad station-only `COUNT(*)`.

**Also fixed in `auto_assign.php`:** split revenue and E-waybill reposition into two queries (reposition rows cannot join `shipments`).

Non-revenue waybills (`waybill_number LIKE '%E%'`) are **included** in auto-assign and in the new count logic. E-waybill repositions store the destination in `car_orders.shipment` as a **location id** (not `shipments.id`), so they use a separate query without joining `shipments`.

### Apply on `ui-improvements`

```bash
cd sts-docker
git checkout ui-improvements

# Option A — patch
git apply patches/auto-assign-dropdown-count.patch

# Option B — cherry-pick (if committed on track-scale)
# git cherry-pick <commit-sha>

# Rebuild / restart web container so PHP is not stale
docker compose --profile build up -d --build
```

### Verify
1. Open **Build Switch Lists** — note `CK1 (N)` in the Auto-Assign dropdown.
2. Select CK1 → **AUTO-ASSIGN** — pickup list should show the same **N** cars (unique; auto-assign may list duplicates if a car matches multiple criteria rows, but count uses unique IDs).

### Patch file
`sts-docker/patches/auto-assign-dropdown-count.patch`

---

## Adding future items

Copy the section template above when porting more fixes from `track-scale` to `ui-improvements`.
