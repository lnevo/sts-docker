# Changelog

All notable changes use semantic versioning (`x.y.z`).

## [0.4.15] - 2026-07-05

- Modernized operations workflow pages (Build Switch Lists, Pick Up, Set Out, Load/Unload) with shared instruction panels, pale-green bulk/filter toolbars, location group checkboxes, and filter-aware bulk actions.
- Enhanced Fill Car Orders with row selection, bulk expand/collapse/cancel, filtered auto-assign, and checkbox sync when filters change.
- Improved Generate Car Orders with manual Next Session confirmation modal, session-aware instructions, and select-all for visible shipments.
- Added operating session line to Operations, Reports, Database, and DB Maintenance menu pages; charcoal home header; restored classic three-column Site Map layout.
- Added Site Map link to page headers; refined Club Operations card spacing and DB Maintenance header button order.

## [0.4.14] - 2026-07-04

- Added car source checkboxes and order filters to Fill Car Orders auto-assign, using Pool → Priority → Station → System priority (System unchecked by default).
- Replaced the Generate Car Orders Select column header with a select-all toggle for visible shipments.

## [0.4.13] - 2026-07-04

- Fix Auto-Assign return link to use a relative URL so it works on non-default ports.

## [0.4.12] - 2026-07-04

- Keep hero logos and page titles on one row at narrow viewport widths on Reports, Database, DB Maintenance, Club Operations, and About pages.

## [0.4.11] - 2026-07-04

- Keep the three home page hero images on one row at narrow screen widths.

## [0.4.10] - 2026-07-04

- Simplified filter dropdown labels on Pick Up Cars and Set Out Cars.

## [0.4.9] - 2026-07-04

- Added station-level and location-level choices to pickup, loading, and unloading location filters.

## [0.4.8] - 2026-07-04

- Expanded Pick Up Cars and Set Out Cars filters to include pickup location, reporting marks, car code, status, and consignment.
- Moved Set Out Cars filters below the instruction and bulk set-out section.

## [0.4.7] - 2026-07-04

- Added loading and unloading station filters to Pick Up Cars and Set Out Cars job tables.
- Limited pickup check-all and set-out bulk location actions to visible filtered rows.

## [0.4.6] - 2026-07-04

- Restored the original main menu icon order.

## [0.4.5] - 2026-07-04

- Swapped Database Management and Club Operations on the main menu.
- Matched Traffic and Routing header styling to the Reference Data section.
- Show the configured railroad name on the main menu when Settings provides one.

## [0.4.4] - 2026-07-04

- Generalized versioning language and main menu heading for upstream-friendly UI improvements.

## [0.4.3] - 2026-07-04

- Modernized the Site Map page while preserving accessible text navigation.
- Polished About page navigation and external-link handling.

## [0.4.2] - 2026-07-04

- Swapped the Data Files and Session Reset Tools cards on the Database Maintenance page.

## [0.4.1] - 2026-07-04

- Moved Database Management Data Files actions to the Database Maintenance page.

## [0.4.0] - 2026-07-04

- Modernized the Reports, Database Maintenance, Club Operations, and About pages with responsive Bootstrap layouts.
- Replaced legacy image-table menu layouts on those pages with text-and-icon cards while preserving existing links and section colors.

## [0.3.2] - 2026-07-04

- Moved Cars into Reference Data on the Database Management page and renamed the remaining table group to Traffic and Routing.

## [0.3.1] - 2026-07-04

- Refined the main menu into a single six-tile grid and removed the duplicate Site Map tile from the page body.

## [0.3.0] - 2026-07-04

- Modernized the main menu with a responsive Bootstrap card layout while preserving the section color scheme.
- Modernized the Database Management page to match the Operations page style, grouping quick query, core tables, reference data, and data file actions.

## [0.2.5] - 2026-07-04

- Updated embedded header banner labels so pages like System Settings no longer show "Menu (Touch)" or "Menu (Text)".

## [0.2.4] - 2026-07-04

- Redrew the Menu and Site Map image labels to better match the original STS menu icon font size and borders.

## [0.2.3] - 2026-07-04

- Renamed the main menu image labels from "Menu (Touch)" to "Menu" and from "Menu (Text)" to "Site Map".

## [0.2.2] - 2026-07-04

- Changed the Database Management header shortcut to link to Database Maintenance using the red DB Maintain icon.

## [0.2.1] - 2026-07-04

- Added a post-setout navigation button back to Build Switch Lists.

## [0.2.0] - 2026-07-04

- Added workflow navigation buttons after generating car orders, assigning switchlists, auto-assigning cars, and picking up cars.

## [0.1.3] - 2026-07-04

- Changed build switchlists bulk-assignment checkboxes to default unchecked so no cars are selected automatically.

## [0.1.2] - 2026-07-04

- Added row-selection checkboxes and a select-all header checkbox to the build switchlists table so bulk train/job assignment only applies to selected cars.

## [0.1.1] - 2026-07-04

- Added a bulk train/job selector to `build_switchlists.php` for assigning all listed cars to the same pickup job.

## [0.1.0] - 2026-07-04

- Added baseline semantic versioning metadata and version-control policy for future changes.
