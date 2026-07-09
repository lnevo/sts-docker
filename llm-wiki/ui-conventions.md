---
title: STS UI Conventions (legacy PHP app)
purpose: Canonical UI/CSS/JS conventions for sts/ pages — visual and behavioural
  consistency.
load_when:
  touching any page/template in sts/ — CSS, layout, print styles, status badges,
  drag-and-drop, mobile/tablet behaviour
owner: shared
last_updated: 09/07/2026
---

# STS UI Conventions

> Applies to the legacy `sts/` PHP app. Every new or refactored page in `sts/`
> must follow these conventions to maintain visual and behavioural consistency.
> For the v2 rebuild (`app/`), consult `docs/SPEC.md` instead — it uses
> SvelteKit components, not raw Bootstrap/jQuery templates.
>
> Derived from: `display_station_report.php`, `wheel_report.php`,
> `db_list_cars.php`, and the deleted `printable_station_report.php` (merged
> into `display_station_report.php`).

## 1. Technology Stack

| Layer         | Technology       | Version | CDN                                                                                          |
| ------------- | ---------------- | ------- | -------------------------------------------------------------------------------------------- |
| CSS Framework | Bootstrap        | 5.3.0   | `https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css`               |
| Icons         | Bootstrap Icons  | 1.11.0  | `https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css` |
| JS Framework  | jQuery           | 3.6.0   | `https://code.jquery.com/jquery-3.6.0.min.js`                                                |
| JS Framework  | Bootstrap Bundle | 5.3.0   | `https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js`          |
| Table Sorting | sorttable.js     | local   | `sorttable.js` (legacy, retain where already used)                                           |

### Load Order (in `<head>`)

```html
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<link
  href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css"
  rel="stylesheet"
/>
<link
  href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.0/font/bootstrap-icons.min.css"
  rel="stylesheet"
/>
```

### Load Order (before `</body>`)

```html
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
```

> **Note:** Always include the `viewport` meta tag. It prevents iOS zoom on form
> inputs and enables responsive breakpoints.

---

## 2. Colour Palette

### Primary Colours

| Name            | Hex       | Usage                                                        |
| --------------- | --------- | ------------------------------------------------------------ |
| Primary Blue    | `#4a90e2` | Table headers (`thead`), job section headers, navbar accents |
| Page Background | `#f8f9fa` | `body` background, table row hover                           |
| Card Background | `#ffffff` | Cards, panels, modals                                        |
| Border Grey     | `#dee2e6` | Table cell borders, card borders, input borders              |
| Text Primary    | `#333`    | Headings, body text                                          |
| Text Secondary  | `#666`    | Sub-headings                                                 |
| Text Muted      | `#999`    | Captions, small print                                        |

### Status Badge Colours

| Status      | Background | Text   | Class                 |
| ----------- | ---------- | ------ | --------------------- |
| Empty       | `#ffeaa7`  | `#333` | `.status-empty`       |
| Loaded      | `#a8e6cf`  | `#333` | `.status-loaded`      |
| Loading     | `#74b9ff`  | `#fff` | `.status-loading`     |
| Unloading   | `#fab1a0`  | `#fff` | `.status-unloading`   |
| Ordered     | `#dfe6e9`  | `#333` | `.status-ordered`     |
| Unavailable | `#d63031`  | `#fff` | `.status-unavailable` |

### Accent Colours

| Swatch                | Hex                       | Usage                                    |
| --------------------- | ------------------------- | ---------------------------------------- |
| Destination Highlight | `#fff3cd`                 | Enroute destination cells                |
| Pool Highlight        | `#fff9c4`                 | Cars in special pool                     |
| Summary Row           | `#e8f0fe`                 | Table summary/totals row                 |
| Edit Hover            | `#f0f4ff`                 | Table row hover on editable tables       |
| Focus Ring            | `rgba(0, 123, 255, 0.25)` | Input focus box-shadow                   |
| Focus Border          | `#80bdff`                 | Input focus border                       |
| Save Flash            | `#d4edda`                 | Momentary green flash on successful save |
| Button Gradient Start | `#667eea`                 | Display/action buttons                   |
| Button Gradient End   | `#764ba2`                 | Display/action buttons                   |

---

## 3. Typography

### Screen

```css
font-family:
  -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue",
  Arial, sans-serif;
```

- **Body text:** `14px` (inherited from Bootstrap)
- **Table text:** `0.8rem – 0.9rem` (reports), `13px` (data editor tables)
- **Table headers:** `0.75rem` (reports), `13px` with `font-weight: 600`
- **Navbar brand:** `1.3rem`, `font-weight: 600`
- **Status badges:** `0.85rem`, `font-weight: 600`

### Print

```css
font-family: "Courier New", monospace;
```

- **Body:** `6pt`
- **Table cells:** `6pt`
- **Table headers:** `5pt`, `font-weight: bold`
- **Report title (h2):** `7pt`
- **Report subtitle (h3):** `6pt`
- **Small/caption:** `5pt`

---

## 4. Component Patterns

### 4.1 Navigation Bar

Every page must include a top navbar (hidden in print):

```html
<nav class="navbar navbar-dark bg-primary noprint">
  <div class="container-fluid">
    <span class="navbar-brand"><i class="bi bi-icon-name"></i> Page Title</span>
    <div>
      <a href="index.html" class="btn btn-outline-light btn-sm me-2">
        <i class="bi bi-house"></i> Home
      </a>
      <a href="reports.html" class="btn btn-outline-light btn-sm me-2">
        <i class="bi bi-file-text"></i> Reports
      </a>
      <button class="btn btn-light btn-sm" onclick="window.print()">
        <i class="bi bi-printer"></i> Print
      </button>
    </div>
  </div>
</nav>
```

### 4.2 Cards

Cards are the primary content container. No visible border; shadow only:

```css
.card {
  border: none;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
}
```

### 4.3 Report Tables

```css
.report-table {
  font-size: 0.8rem;
  width: 100%;
  border-collapse: collapse;
  table-layout: auto;
}
.report-table thead {
  background-color: #4a90e2;
}
.report-table th {
  color: white !important;
  font-weight: 600;
  padding: 6px 4px;
  border: none;
  background-color: #4a90e2;
  position: sticky;
  top: 0;
  z-index: 50;
}
.report-table td {
  padding: 6px 4px;
  border-bottom: 1px solid #dee2e6;
  vertical-align: middle;
}
.report-table tbody tr:hover {
  background-color: #f8f9fa;
}
```

> **Sticky headers:** Apply `position: sticky` to `<th>` elements directly,
> **not** `<thead>`. Always set `background-color` on `<th>` (not just
> `<thead>`) to prevent content bleeding through.

### 4.4 Data Editor Tables

For editable listing pages (e.g. `db_list_cars.php`):

```css
#table_id th {
  background-color: #e9ecef;
  font-weight: 600;
  white-space: nowrap;
  position: sticky;
  top: 0;
  z-index: 10;
}
#table_id th,
#table_id td {
  border: 1px solid #dee2e6;
  padding: 6px 8px;
  vertical-align: middle;
}
```

### 4.5 Status Badges

Wrap status text in a `<span>` with the appropriate class:

```html
<span class="status-empty">Empty</span>
<span class="status-loaded">Loaded</span>
```

Base CSS (all badges share):

```css
display: inline-block;
padding: 4px 8px;
border-radius: 3px;
font-weight: 600;
font-size: 0.85rem;
```

Use `strtolower()` to generate the class from the database value:

```php
echo '<span class="status-' . strtolower($row['status']) . '">' . htmlspecialchars($row['status']) . '</span>';
```

### 4.6 Print Header

```html
<div class="print-header">
  <h2>Railroad Name</h2>
  <h3>Report Title</h3>
  <h3>Station/Section Name</h3>
  <small><em>Footnote text</em></small>
</div>
```

The print-header scrolls with the page on screen. It is styled for both screen
(centred, decorative border) and print (left-aligned, compact, monospace).

### 4.7 Modal Overlay (Inline Editing)

For touch-friendly editing, use a fixed overlay with a centred panel:

```css
.cell-editor-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.35);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
}
.cell-editor-panel {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.25);
  padding: 24px 28px;
  min-width: 320px;
  max-width: 90vw;
  animation: editorIn 0.15s ease-out;
}
```

> **Why not `<select>` inline?** Touch devices cannot programmatically open
> native `<select>` dropdowns. The modal overlay pattern lets the user tap an
> option list reliably.

### 4.8 Editable Cells

Mark editable cells with a pencil icon that appears on hover:

```css
.editable-cell {
  cursor: pointer;
}
.editable-cell::after {
  content: "\270E";
  position: absolute;
  top: 2px;
  right: 3px;
  font-size: 10px;
  color: #adb5bd;
  opacity: 0;
  transition: opacity 0.15s;
}
.editable-cell:hover::after {
  opacity: 1;
}
```

> **Dropdown cache gotcha:** if editable cells populate their `<select>` from a
> client-side cache (e.g. car codes, locations, jobs fetched once and reused),
> the cache-loading function must actually be _called_ — and awaited — before
> the dropdown is built. A real bug in `db_list_cars.php` had
> `loadDropdownOptions()` defined but never invoked, so the cache stayed empty
> and no options ever appeared on first click. Load eagerly at page load, or
> make the cell-editor function async and await the load before populating.

<!-- -->

> **Return a display value after save, not just persist the ID.** For editable
> cells backed by an FK (car code, location, job), the AJAX save endpoint (e.g.
> `update_car_ajax.php`) must return the human-readable display value in its
> response, not just confirm the write. If it only echoes back the saved ID, the
> cell shows a raw number after saving instead of the name the user picked.

---

### 4.9 Operations Page Tables

Operations pages (`build_switchlists.php`, `organize_cars.php`, `pick_up.php`,
`set_out.php`, `load_unload.php`, `reposition.php`) render interactive car lists
that may be wide. Use the following pattern — distinct from report tables
(§4.3).

#### Bootstrap Classes

```html
<div class="table-responsive">
  <table class="table table-sm table-bordered table-hover">
    <thead>
      <tr style="position: sticky; top: 0; background-color: #F5F5F5;">
        <th>Column Header</th>
        ...
      </tr>
    </thead>
    <tbody>
      ...
    </tbody>
  </table>
</div>
```

> **Note:** `position: sticky` here causes the header to stick to the top of the
> **scroll container** (the `.table-responsive` div), not the viewport. This is
> correct behaviour for wide, horizontally-scrollable tables on operations
> pages.

#### Required CSS (in parent full-page file's `<style>` block)

```css
table th,
table td {
  font-size: 0.875rem;
  padding: 6px 8px;
  white-space: nowrap;
}
```

Plus the full status badge block (see §4.5).

#### Column Header Rules

| Rule                       | Correct                        | Incorrect                           |
| -------------------------- | ------------------------------ | ----------------------------------- |
| Format                     | Plain text, title case         | `<i>`, `<u>`, `<br/>` inside `<th>` |
| Station + location columns | `"Loading Station / Location"` | Multi-line with underlined station  |
| No line breaks in headers  | Single line always             | `Loading Station<br/>Location`      |

#### Cell Content Rules

| Cell type                 | Correct                                                                               | Incorrect                                |
| ------------------------- | ------------------------------------------------------------------------------------- | ---------------------------------------- |
| Station / location        | `Station Name<br />Location Code`                                                     | `<u>Station Name</u><br />Location Code` |
| Status                    | `<span class="status-<?= strtolower($row['status']) ?>"><?= $row['status'] ?></span>` | Plain text                               |
| Destination (coloured)    | `set_colors()` background + bold text                                                 | Add `<u>` wrapping                       |
| Car code / position #     | Plain text                                                                            | `text-align: center`                     |
| Arrow / checkbox controls | Keep `text-align: center`                                                             | Remove centering                         |

---

### 4.10 Operations Navbar Variant

Operations pages use a **green** navbar to visually distinguish them from
data-entry and report pages.

```html
<nav
  class="navbar navbar-dark px-3 py-2 d-flex justify-content-between align-items-center"
  style="background-color: #2e7d32;"
>
  <span class="navbar-brand mb-0 h5">Page Title</span>
  <div class="d-flex gap-2">
    <a href="operations.html" class="btn btn-sm btn-outline-light">
      <i class="bi bi-arrow-left"></i> Operations
    </a>
    <a href="index.html" class="btn btn-sm btn-outline-light">
      <i class="bi bi-house"></i> Home
    </a>
    <!-- Optional: print button for pick_up / set_out -->
    <button
      onclick="window.print()"
      class="btn btn-sm btn-outline-light noprint"
    >
      <i class="bi bi-printer"></i> Print
    </button>
  </div>
</nav>
```

| Navbar colour                 | Page type                                                                                      |
| ----------------------------- | ---------------------------------------------------------------------------------------------- |
| `#2e7d32` (green)             | Operations pages (build_switchlists, pick_up, set_out, organize_cars, load_unload, reposition) |
| `bg-primary` (Bootstrap blue) | Report / display pages (display_station_report, display_fleet_report, etc.)                    |
| `bg-secondary`                | Data editor pages (db*edit*_, db*list*_)                                                       |

---

### 4.11 Ajax Fragment Tables

Several pages load car lists via AJAX into a `<div>` container. The response
files return a raw HTML `<table>` fragment — not a full page.

**Ajax response files:**

| File                           | Called by               | Purpose                               |
| ------------------------------ | ----------------------- | ------------------------------------- |
| `get_cars_at_station.php`      | `build_switchlists.php` | Cars available at a station           |
| `get_cars_in_job.php`          | `set_out.php`           | Cars assigned to a job for set-out    |
| `get_cars_position_in_job.php` | `pick_up.php`           | Cars in a job for pick-up             |
| `get_job_cars.php`             | `organize_cars.php`     | Cars in a job (by job view)           |
| `get_location_cars.php`        | `organize_cars.php`     | Cars at a location (by location view) |

**Critical rules:**

- ❌ **Never add `<style>` blocks to Ajax response files.** CSS defined inside
  an AJAX-injected fragment is applied globally and unpredictably — it will
  bleed into the parent page and persist across subsequent AJAX calls.
- ✅ All CSS (including status badge styles, `th/td` padding, etc.) **must be
  defined in the parent full-page file**.
- ✅ Ajax response files may use inline `style=""` attributes on individual
  elements where dynamic values are needed (e.g. `set_colors()` output).
- ✅ Ajax response table classes must match what the parent page's CSS targets:
  `table table-sm table-bordered table-hover`.

---

## 5. Touch & Mobile Guidelines

| Rule                     | Value                                              | Rationale                                         |
| ------------------------ | -------------------------------------------------- | ------------------------------------------------- |
| Minimum touch target     | `44px` height                                      | Apple HIG / WCAG 2.5.8                            |
| Form input min-height    | `48px`                                             | Comfortable tap target                            |
| Input font-size          | `≥ 16px`                                           | Prevents iOS auto-zoom                            |
| Viewport meta            | Always include                                     | Enables responsive layout                         |
| Responsive column hiding | `.col-hide-md` (< 992px), `.col-hide-sm` (< 768px) | Collapse non-critical columns                     |
| Select elements          | Use modal overlay, not inline `<select>`           | Touch devices can't open selects programmatically |

---

## 6. Form Controls

```css
.form-control,
.form-select {
  border-radius: 0.375rem;
  border: 1.5px solid #dee2e6;
  min-height: 44px;
  font-size: 16px;
}
.form-control:focus,
.form-select:focus {
  border-color: #80bdff;
  box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}
```

---

## 7. AJAX Patterns

### Report Loading (fetch API)

For pages that generate reports dynamically without a full page reload:

```javascript
form.addEventListener("submit", function (e) {
  e.preventDefault();
  const params = new URLSearchParams(new FormData(form));
  params.append("generate_report", "1");

  fetch("same_page.php?" + params.toString())
    .then((response) => response.text())
    .then((html) => {
      document.getElementById("report-content").innerHTML = html;
      document.getElementById("report-container").classList.add("show");
    });
});
```

> **Single-file pattern:** The same PHP file handles both the form page (normal
> load) and the report data (when `?generate_report=1` is set). The report path
> calls `exit` after output to prevent the form HTML from being appended.

### Data Saving (jQuery AJAX)

For inline cell editing:

```javascript
$.ajax({
  url: "update_endpoint.php",
  type: "POST",
  dataType: "json",
  data: { id: carId, field: fieldName, value: newValue },
  success: function (response) {
    // Flash cell green, update displayed value
  },
});
```

---

## 8. Print CSS

### Page Setup

```css
@page {
  size: landscape;
  margin: 0.3in;
}
```

### Key Rules

```css
@media print {
  /* Hide non-printable elements */
  .navbar,
  .print-controls,
  .form-card,
  .back-btn,
  .noprint {
    display: none !important;
  }

  /* Reset containers */
  .container {
    max-width: 100% !important;
    width: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
  }
  .card {
    box-shadow: none;
    border: none;
  }
  .card-body {
    padding: 0 !important;
  }

  /* Monospace for dot-matrix look */
  body {
    font-family: "Courier New", monospace;
    font-size: 6pt;
  }

  /* Table headers repeat on each page */
  .report-table thead {
    display: table-header-group;
  }
  .report-table th {
    font-weight: bold !important;
    color: #000 !important;
    background-color: transparent !important;
    border: 1px solid #000;
    font-size: 5pt;
    position: static; /* remove sticky in print */
  }

  /* Table fits page width */
  .report-table {
    width: 100%;
    table-layout: fixed;
    font-size: 6pt;
  }
  .report-table td {
    border: 1px solid #000;
    padding: 1px 2px;
  }

  /* Rows don't break across pages */
  .report-table tbody tr {
    page-break-inside: avoid;
  }

  /* Status badges: plain text in print */
  .status-empty,
  .status-loaded,
  .status-loading,
  .status-unloading,
  .status-ordered,
  .status-unavailable {
    background-color: transparent !important;
    color: #000 !important;
    padding: 0 !important;
    font-weight: normal;
    font-size: 6pt;
  }

  /* Preserve bold and underline formatting */
  .destination-highlight {
    font-weight: bold;
  }
  /* Do NOT override u { text-decoration: none } */
}
```

### Print Checklist

- [ ] `@page` size set to `landscape` (or `portrait` if appropriate)
- [ ] Navbar, buttons, form controls hidden via `.noprint` or explicit rules
- [ ] Table headers repeat via `display: table-header-group` on `<thead>`
- [ ] `position: sticky` overridden to `static` in print
- [ ] Status badges reset to plain text
- [ ] Bold and underline formatting preserved (do not override `<strong>`,
      `<u>`)
- [ ] Font family set to `"Courier New", monospace`
- [ ] Table uses `table-layout: fixed; width: 100%` to fit page

---

## 9. File Architecture

### Single-File Report Pattern

Reports that previously used two files (display + printable) are now
consolidated into a single file:

```text
same_page.php
├── PHP: if ($_GET['generate_report']) → output report HTML → exit
└── PHP: else → output full page with form + empty report container
```

The form submits via `fetch()` to itself with `?generate_report=1`. The response
HTML is injected into `#report-content`. The `#report-container` is toggled
visible via a `.show` class.

### Page Structure Template

```html
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>STS - Page Title</title>
    <!-- Bootstrap CSS + Icons -->
    <style>
      /* Page-specific styles */
    </style>
  </head>
  <body>
    <nav class="navbar ..."><!-- Nav --></nav>
    <div class="container mt-4">
      <!-- Form / Controls -->
      <!-- Report / Data Output -->
    </div>
    <!-- Bootstrap JS + jQuery -->
  </body>
</html>
```

---

## 10. Do's and Don'ts

### Do

- ✅ Use Bootstrap 5 utility classes (`mt-4`, `btn-sm`, `d-flex`, etc.)
- ✅ Use Bootstrap Icons (`bi bi-printer`, `bi bi-house`, etc.)
- ✅ Apply `htmlspecialchars()` to all user-facing database output
- ✅ Use `position: sticky` on `<th>` elements (not `<thead>`)
- ✅ Set `background-color` on sticky `<th>` to match `<thead>` colour
- ✅ Use `!important` on print-specific overrides where Bootstrap conflicts
- ✅ Use the modal overlay pattern for touch-editable fields
- ✅ Keep print font sizes ≤ 7pt to fit landscape pages with many columns
- ✅ Consolidate display + printable into a single-file AJAX pattern
- ✅ Use `set_colors.php` / `set_colors()` for location-based highlighting
- ✅ Use `table table-sm table-bordered table-hover` on all operations tables
- ✅ Wrap wide operations tables in `.table-responsive` to enable horizontal
  scroll
- ✅ Use status badge `<span class="status-...">` for all car status values (see
  §4.5)
- ✅ Use green navbar (`#2e7d32`) on all operations pages (see §4.10)
- ✅ Define all CSS (including status badges) in the parent full-page file, not
  in Ajax response files (see §4.11)

### Don't

- ❌ Wrap **report** tables in `.table-responsive` when viewport-sticky headers
  are needed — `overflow` creates a new scroll context and breaks
  `position: sticky` relative to the viewport. Operations tables (§4.9) are
  exempt: their sticky header intentionally sticks within the scroll container.
- ❌ Set `overflow-x: auto` on any ancestor of a report-table sticky header
- ❌ Use inline `<select>` for touch-editable fields (won't open on tap)
- ❌ Override `<u>` or `<strong>` formatting in print CSS
- ❌ Use `font-size` below `16px` on form inputs (causes iOS zoom)
- ❌ Apply `position: sticky` to `<thead>` (doesn't work reliably)
- ❌ Forget `display: table-header-group` on `<thead>` in print CSS
- ❌ Use hardcoded colours without referencing this palette
- ❌ Create separate "printable" versions of report pages
- ❌ Add `<style>` blocks to Ajax response files — CSS bleeds into the parent
  page (see §4.11)
- ❌ Use `<u>` tags in station/location table cells on operations pages
- ❌ Use `<br/>`, `<u>`, or `<i>` inside `<th>` column headers
- ❌ Apply `text-align: center` to data cells (car code, reporting marks,
  position number); keep it only on arrow/checkbox control cells

---

## 11. Z-Index Scale

| Layer                | z-index     | Usage                             |
| -------------------- | ----------- | --------------------------------- |
| Table sticky headers | `10` – `50` | `<th>` with `position: sticky`    |
| Cell editor overlay  | `9999`      | Modal backdrop for inline editing |

---

## 12. Existing Utility Files

| File                           | Purpose                                                       |
| ------------------------------ | ------------------------------------------------------------- |
| `open_db.php`                  | Database connection (MySQLi)                                  |
| `set_colors.php`               | Returns inline style string for location-based cell colouring |
| `show_image.php`               | JavaScript function for rolling stock photo popups            |
| `credentials.php`              | Database credentials                                          |
| `drop_down_list_functions.php` | Shared dropdown/select generation helpers                     |
| `get_dropdowns_ajax.php`       | AJAX endpoint returning car codes, locations, jobs as JSON    |

---

## 13. Tablet Responsiveness (Operations Pages)

Operations pages are regularly used on tablets. Apply this pattern to every
operations table.

### CSS Pattern

Add inside the page's `<style>` block:

```css
/* Prevent word-splitting in all table cells */
#your_table_id th,
#your_table_id td {
  word-break: keep-all;
  overflow-wrap: normal;
  hyphens: none;
}

/* On tablets (≤1024px), hide non-critical columns */
@media (max-width: 1024px) {
  #your_table_id .hide-tablet {
    display: none;
  }
  #your_table_id th,
  #your_table_id td {
    font-size: 0.78rem;
    padding: 4px 5px;
  }
}
```

Mark non-critical `<th>` and `<td>` with `class="hide-tablet"`. Retain at
minimum: car road/number, status, current location, and action controls.

### Applied Pages

| Page                | Table ID          | Hidden tablet columns            |
| ------------------- | ----------------- | -------------------------------- |
| `organize_cars.php` | (drag-sort table) | Position #, car code description |
| `generate.php`      | `#ship_tbl`       | Commodity, car type detail       |
| `pick_up.php`       | `#job_table`      | Position #, car code             |
| `set_out.php`       | `#job_table`      | Position #, car code             |
| `load_unload.php`   | `#car_table`      | Position #, car code             |

### Button / Dropdown Collision Fix

When a page has a dropdown + action button side-by-side (e.g. `pick_up.php`),
wrap them together:

```html
<div class="d-flex flex-wrap align-items-center gap-2">
  <select class="form-select form-select-sm" ...>
    ...
  </select>
  <button class="btn btn-sm btn-primary" ...>Go</button>
</div>
```

This prevents the button from overlapping the dropdown on narrow viewports.

---

## 14. Drag-and-Drop Car Ordering (`organize_cars.php`)

### Architecture

`organize_cars.php` renders a two-mode view (by job / by location). Car rows are
draggable to reorder; the new order is sent to `update_car_positions.php` and
persisted in the `cars.position` database column.

**File roles:**

| File                       | Role                                                    |
| -------------------------- | ------------------------------------------------------- |
| `organize_cars.php`        | Parent page: drag engine, save button, UI               |
| `get_job_cars.php`         | Ajax: returns drag-ready table fragment for a job       |
| `get_location_cars.php`    | Ajax: returns drag-ready table fragment for a location  |
| `update_car_positions.php` | Ajax: saves new order, returns refreshed table fragment |

### Drag Engine

Use **document-level pointer/mouse/touch event listeners** — not element-level.
Element-level listeners lose the drag if the pointer moves off the source
element before the browser fires the event.

```javascript
function init_drag_sort() {
  const tbody = document.querySelector("#car_table tbody");

  tbody.addEventListener("mousedown", start_drag);
  tbody.addEventListener("pointerdown", start_drag);
  tbody.addEventListener("touchstart", start_drag, { passive: true });

  // CRITICAL: non-passive touchmove so preventDefault() works
  document.addEventListener("touchmove", on_move, { passive: false });
  document.addEventListener("mousemove", on_move);
  document.addEventListener("pointermove", on_move);

  document.addEventListener("mouseup", end_drag);
  document.addEventListener("pointerup", end_drag);
  document.addEventListener("touchend", end_drag);
}
```

> **Non-passive `touchmove`:** Required to call `e.preventDefault()` and
> suppress page scroll during a drag. Chrome logs a warning if you call
> `preventDefault()` on a passive listener — always register `touchmove` with
> `{ passive: false }`.

<!-- -->

> **`body.drag-active` class:** Add `document.body.classList.add('drag-active')`
> on drag start and remove on end. Target `body.drag-active` in CSS to disable
> `user-select` and `cursor` globally during a drag.

### Row Reordering Logic

Use **instant direction-based swapping** — do not wait until the pointer reaches
the 50% midpoint of the next row. This makes reordering feel immediate.

```javascript
function move_row_by_pointer(clientY) {
  const rows = Array.from(tbody.querySelectorAll("tr"));
  for (const row of rows) {
    if (row === dragging_row) continue;
    const rect = row.getBoundingClientRect();
    const mid = rect.top + rect.height / 2;
    if (clientY < mid) {
      tbody.insertBefore(dragging_row, row);
      break;
    }
  }
}
```

### Visual Feedback

```css
/* Dragged row: orange highlight */
.dragging-row > td {
  background-color: #fd7e14 !important;
  color: #fff !important;
  opacity: 0.85;
}

/* Flash animation when a row moves */
@keyframes row-moved-flash {
  0% {
    background-color: #fff3cd;
  }
  100% {
    background-color: transparent;
  }
}
.row-flash > td {
  animation: row-moved-flash 0.4s ease-out;
}
```

Apply `.row-flash` to `tr` elements that shifted position, and remove it after
the animation ends.

### Save Feedback

The save button should reflect three states: idle → saving (spinner) →
success/error.

```javascript
function show_save_state(state) {
  // state: 'saving' | 'success' | 'error'
  const btn = document.getElementById("save_btn");
  if (state === "saving") {
    btn.innerHTML =
      '<span class="spinner-border spinner-border-sm"></span> Saving…';
    btn.disabled = true;
  } else if (state === "success") {
    btn.innerHTML = '<i class="bi bi-check-circle"></i> Saved';
    btn.classList.replace("btn-primary", "btn-success");
    setTimeout(() => reset_save_btn(), 2000);
  } else {
    btn.innerHTML = '<i class="bi bi-x-circle"></i> Error';
    btn.classList.replace("btn-primary", "btn-danger");
    setTimeout(() => reset_save_btn(), 3000);
  }
}
```

### thead / tbody Separation (Ajax Fragment Files)

`get_job_cars.php` and `get_location_cars.php` **must** use a proper `<thead>`
containing only the header row and a `<tbody>` containing only data rows.
Without this separation, the drag engine's row iterator will count the header as
a draggable row and the Position column will display "1" instead of the header
text.

```html
<table class="table table-sm table-bordered table-hover" id="car_table">
  <thead>
    <tr>
      <th>☰</th>
      <th>Reporting Marks</th>
      <th>Position</th>
      ...
    </tr>
  </thead>
  <tbody>
    <!-- data rows here, each with a hidden input: -->
    <!-- <td><input type="hidden" name="car_id[]" value="<?= $row['id'] ?>"> ... </td> -->
  </tbody>
</table>
```

### Persistence: Save by Car ID

`update_car_positions.php` must use `cars.id` (not `reporting_marks`) as the key
when writing positions back to the database:

```php
// CORRECT: qualify the column to avoid ambiguous-column error in multi-join queries
$sql = "UPDATE cars SET position = \"$car_pos\" WHERE cars.id = \"$car_id\"";
```

> **Why not `reporting_marks`?** The drag engine sends `car_id[]` hidden inputs.
> The `reporting_marks` key was used in a previous (removed) implementation and
> will silently fail to match if the new drag HTML is in use.

<!-- -->

> **Ambiguous column guard:** Multi-join queries that include `cars.id` and
> another table's `id` must always qualify every column reference in the WHERE
> clause to prevent `mysqli` from returning `false` with an "ambiguous column"
> error.

---

_Derived from: `feat/report_ui`, `feat/car_db_ui_refresh`,
`feat/on_hand_report_updates`, `feat/ui_updates` branches._ _Migrated from
`.github/copilot-instructions.md` into the shared wiki on 06/07/2026 — see
[decisions-log.md](decisions-log.md)._
