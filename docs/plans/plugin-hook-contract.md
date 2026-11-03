# Plugin boundary contract

Rules and hook definitions for separating STS addons from the core app.
Companion to `2026-11-02-plugin-delineation.md`.

## Rules

1. Core is everything under `sts/` except `sts/plugins/`. Core may not name a
   plugin id, call a `track_scale_*`, `coke_orders_*` or other plugin function,
   or carry a HART train, industry, location or shipment identifier (CK1, D749,
   NVL, STG-SCULLY, STG-DEMMLER, Shenango, Scully, Neville, Island, Demmler,
   COKE-, SCL-, NIL-). `bin/check_plugin_boundary.sh` enforces this; it carries
   an allowlist that shrinks as Phase 2 lands.
2. Core reaches plugins only through `plugins_*` functions in
   `sts/plugin_registry.php`. Plugins reach core freely (they are loaded after
   core helpers).
3. A plugin is a directory holding `plugin.php` that returns a manifest array.
   Discovery scans `sts/plugins/*/plugin.php` and every directory listed in the
   `STS_PLUGINS_DIR` environment variable (colon separated). The image ships no
   addons; docker-compose bind-mounts `../sts-plugins/plugins` onto
   `/var/www/html/sts/plugins`.
4. Core must work with zero plugins discovered. Every `plugins_*` helper returns
   an empty result in that case.
5. A change that needs a new hook adds the hook to core in its own commit first,
   then the plugin uses it in the plugin repo.

## Existing manifest keys (unchanged)

`id`, `label`, `version`, `bootstrap`, `helpers`, `operations_buttons`, `stats`,
`condition_variables`, `catalog_steps` / `catalog_steps_callback`,
`catalog_adder`, `legacy_function_ids`, `import_text_guessers`,
`gui_label_hooks`, `normalize_params`, `dispatch_handlers`,
`dispatch_log_hooks`, `no_warm_start_dispatch`, `runtime_without_warm_start`,
`catalog_test_sections_callback`, `catalog_test_round_trip_skip`.

## New manifest keys

### `pages`

Map of page slug to a PHP file relative to the plugin directory.

```php
'pages' => [
    'index' => 'track_scale.php',
    'ajax'  => 'track_scale_ajax.php',
],
```

Served by `sts/plugin.php?id=<plugin>&page=<slug>` (slug defaults to `index`).
The router validates the id and slug against the manifest, `chdir`s to `sts/`
so relative includes keep working, and `require`s the file. `operations_buttons`
`href` values point at the router. Root stubs `sts/track_scale.php` and
`sts/track_scale_ajax.php` are deleted; a plugin's own JS calls the router URL.

### `session_reset_hooks`

List of callables `function ($dbc, array $context): void` invoked by
`session_helpers.php` when a session is restarted or rewound, after the session
number has been restored and before output directories are pruned. `$context`
carries `session_nbr`, `restored_session` and `reason`. Replaces the direct
`require` of the track-scale helpers at `session_helpers.php:579`.

### `delivery_complete_hooks` (Phase 2)

List of callables `function ($dbc, int $car_id, string $shipment_code): void`
invoked by `session_simulator_ops.php` when a simulated unload completes.
Replaces the inline coke statistics branch.

## Declarative client metadata on catalog definitions

A catalog definition (core or plugin) may carry a `client` block that the
editor JS (`workflow-shared.js`) and the PHP label merge
(`operational_steps_catalog.php`) both interpret. This removes every
plugin-specific `if (step.function === ...)` from the JS and lets the plugin
drop its `gui_label_hooks` entry.

```php
'client' => [
    // Param keys not echoed inline after the command label.
    'hide_inline_params' => ['job', 'commodity', 'min_reloads'],
    // Derived placeholders for gui_template, evaluated in order.
    'derived' => [
        ['key' => 'job',                'from' => 'job',         'default' => 'train'],
        ['key' => 'commodity_suffix',   'from' => 'commodity',   'template' => ' ({value})'],
        ['key' => 'min_reloads_suffix', 'from' => 'min_reloads', 'template' => ' (min reloads {value})', 'pattern' => '^\\d+$'],
    ],
    // Token pluralisation driven by a numeric param.
    'plural' => [
        ['token' => 'session[s]', 'from' => 'every_sessions', 'one' => 'session', 'many' => 'sessions', 'min' => 1],
    ],
],
```

Semantics:

- `derived`: `value` is the trimmed param named by `from`. If it is empty, the
  placeholder becomes `default` (or empty). If `pattern` is set and does not
  match, the placeholder is empty. If `template` is set, the placeholder is the
  template with `{value}` substituted; otherwise it is the value itself.
  Placeholders ending in `_suffix` are inserted verbatim (no trimming).
- `plural`: parse the `from` param as an integer, clamp to `min`, write it back
  to the param copy used for rendering, and replace `token` in the template with
  `one` when the integer is 1, else `many`.

Core commands keep their hand-written rules in the JS for now; moving them to
`client` blocks is optional cleanup.

## Generic replacements for HART-specific parameters

### `cancel_orders` / `drain_unfilled_orders`

`keep_coke` (select Yes/No) is replaced by `keep_prefixes` (text, comma
separated shipment-code prefixes that are never cancelled, default empty,
placeholder `e.g. COKE-`). `drain_unfilled_orders.php` takes
`keep_prefixes: list<string>` and builds one `AND s.code NOT LIKE "<prefix>%"`
clause per prefix. The `coke_orders` plugin registers
`normalize_params['cancel_orders']` and `['drain_unfilled_orders']` that:

- map legacy `keep_coke` = `1` or absent to `keep_prefixes` containing `COKE-`,
- map `keep_coke` = `0` to `keep_prefixes` without `COKE-`,
- then unset `keep_coke`.

So a HART install behaves as before, and an install without the plugin gets a
plain generic command. Editor titles change from `(keep coke)` to
`(keep COKE-)`; the title suffix comes from a `client.derived` rule on the core
definition: `['key' => 'keep_suffix', 'from' => 'keep_prefixes', 'template' => ' (keep {value})']`.

### Generated OpenAPI

`generate_operational_steps_openapi_catalog.php --core-only` already exists.
The committed file in core becomes core-only. Plugins regenerate their own
fragment in the plugin repo when they need Swagger coverage.

## Phase 2: HART layout plugin (`hart_layout`)

Input: `warm-start-classification.md` (62 generic, 40 HART, 13 mixed). The
mixed functions are the session orchestration itself (`run`,
`advance_session`, `run_session_work`, `play_operating_session`,
`begin_operating_session`, `evaluate_session_prep`, `summarize`,
`format_begin_session_report`, `default_config`, `staging_job_names`,
`location_code_aliases`, `load_unload`). Rather than add a hook at every HART
branch inside them, the split is by ownership:

- Core keeps the primitives in `sts/session_ops_helpers.php` (same function
  names, so callers in the catalog and session pages do not change): session
  number, config merge, logging, stats log, generate orders, ops cycle,
  pickup/setout/load-unload, staging backlog and completion, backup, lookups.
- Core keeps a **default scenario**: generate, one ops cycle, staging bookend,
  stats commit. No phased locals, no CK1, no stop conditions beyond
  `stop_when_staging_ready`.
- The plugin owns the **HART scenario**: the current `run_session_work`,
  `run`, `advance_session`, `play_operating_session`,
  `begin_operating_session`, `evaluate_session_prep` bodies move there as
  `hart_*` functions together with all 40 HART functions and the 38 track-scale
  calls.

### `warm_start_scenario` manifest key

Exactly one discovered plugin may declare it; core falls back to the default
scenario when none does.

```php
'warm_start_scenario' => [
    'id'               => 'hart',
    'default_config'   => 'hart_default_config',        // array merged over core defaults
    'staging_jobs'     => 'hart_staging_job_names',     // ($dbc, $config) -> list<string>
    'location_aliases' => 'hart_location_code_aliases', // () -> array<string,string>
    'session_work'     => 'hart_run_session_work',      // replaces warm_start_run_session_work
    'run'              => 'hart_run',                   // replaces warm_start_run (stop conditions)
    'begin_session'    => 'hart_begin_operating_session',
    'play_session'     => 'hart_play_operating_session',
    'prep_checks'      => 'hart_evaluate_session_prep', // () -> list<array{ok:bool,message:string}>
    'summary'          => 'hart_summarize_extra',       // ($dbc, array &$summary) adds coke/CK1 keys
    'report_lines'     => 'hart_begin_session_report_lines',
    'featured_stations'=> 'hart_station_report_featured',
],
```

Core wrappers (`warm_start_run_session_work` etc.) stay as thin dispatchers:
call the scenario callable when present, else the default implementation.

### Named statistics replace the coke accumulator

`warm_start_coke_stats*` becomes a generic accumulator in core:
`warm_start_stats_bucket(string $name): array&`,
`warm_start_stats_copy()`, `warm_start_stats_delta()`. The HART scenario uses
bucket `coke`. `session_simulator_ops.php` and `warm_start_session_stats.php`
stop naming coke: the simulator calls `delivery_complete_hooks` (plugin
increments its bucket), and the stats page renders whatever buckets exist, with
labels supplied by the scenario (`stats_labels` callable).

### Other core literals (all move with the plugin)

- `operational_steps_legacy_layout_function_ids()` HART entries move to the
  plugin's `legacy_function_ids` (each mapping to `text_instruction`).
- `master_sw_section_destination()` station-name matches become section config
  (`destination_override` with `side`, `station_match`, `location_prefix`),
  supplied by the plugin's switch-list section definitions.
- `generate_master_switchlists.php` default job list comes from the DB (all
  jobs that have phased switch lists), not a literal.
- `session_wheel_report_stg_d749_inbound_rows()` becomes a
  `wheel_report_extra_rows` hook (`($dbc, $session_nbr, $root) -> array<job, list<row>>`).
- `warm_start_session_stats.php` HART columns come from the scenario's
  `stats_labels`.
- Hard-coded station numbers (3, 9, 10, 14, 15) in `prep_staging_yards`,
  `seed_island_spotting`, `assign_nvl_scully_block` move with those functions
  and become config entries in the plugin.

### Parity gate

`bin/sandbox_baseline.sh <label>` run on the sandbox stack before and after;
compare `functions.txt` (every pre-split function name still defined once the
plugin is mounted), `catalog.json`, the catalog test outputs, the seeded
simulation logs and the final DB dump. Differences must be explained line by
line before merge.
