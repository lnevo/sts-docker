# Changelog

All notable HART fork changes use semantic versioning (`x.y.z`).

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

- Added HART layout seed generation and generated STS seed SQL.
- Added Docker provisioning, reseed, rebuild, and rolling stock image sync helpers.
- Added HART operating jobs, pickup criteria, consolidated Neville Island locations, and report branding.
- Added baseline version-control policy for future changes.
