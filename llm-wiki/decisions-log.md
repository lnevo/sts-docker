---
title: Decisions Log
purpose: Durable architectural/process decisions and the reasoning behind them.
load_when:
  you want the "why" behind a past decision, or you're about to make one worth
  recording
owner: shared
last_updated: 02/11/2026
---

# Decisions Log

Append entries here when a decision lands — durable facts and reasoning only, no
status updates or in-flight work. Newest first.

## 2026-11-02 — Plugins moved to the sts-plugins repo; core is plugin-agnostic

Addons (track_scale, coke_orders, and the planned HART layout) now live in the
sibling `sts-plugins` repo and are bind-mounted onto `sts/plugins/`; the image
ships that directory empty. Why: plugin changes were forcing edits across core
files, and branches were standing in for a separation that did not exist. Rule:
core (`sts/` minus `sts/plugins/`) must not name a plugin id, a plugin function,
or a HART train, industry, location or shipment identifier. Core reaches plugins
only through `plugins_*` helpers in `sts/plugin_registry.php`, and
`bin/check_plugin_boundary.sh` enforces this in CI. New hooks land in core in
their own commit before a plugin uses them. Contract:
`docs/plans/plugin-hook-contract.md`.

## 2026-07-16 — HART train blocks remain operationally independent

The HART session workflow runs D749 Starting/Outbound, then NVL
Outbound/Return, then CK1. This order is an operating constraint rather than a
score-tuning variable: D749 and NVL must not wait for CK1, and CK1 may run
independently later in the shift. Traffic tuning must optimize within this fixed
sequence even when a simulated throughput score favors the former interleaved
order.

## 2026-07-10 — Vendored all legacy `sts/` frontend assets, dropped CDN links

All 12 `sts/` pages loaded Bootstrap, Bootstrap Icons, jQuery, and (one page)
the Lexend font from external CDNs (cdnjs, code.jquery.com,
fonts.googleapis.com). STS is designed for private-network use (see
`sts/api/README.md`'s no-auth rationale), so any install without outbound
internet silently degraded — unstyled Bootstrap, fallback system fonts, no
error. Downloaded the exact versions already in use into `sts/vendor/`
(`bootstrap/`, `bootstrap-icons/`, `jquery/`, `fonts/lexend/`) and repointed
every page at the local paths; the Dockerfile already `COPY`s all of `sts/`
into the image, so no build/CI change was needed. See
[ui-conventions.md](ui-conventions.md) §1 — new pages must vendor any new
frontend dependency the same way, never link a CDN.

## 2026-07-09 — Mined old Copilot chat history for durable findings

Reviewed ~35MB of archived VS Code chat session history (from a prior machine
profile, `/Volumes/GlobalShare/oldCopilot`) for this repo, plus a Kiro data
volume (no sts-docker workspace found there — only unrelated "railroad-\*"
experiment repos, left alone). Pulled forward what was still durable and still
true: the `publish-docker.yaml` branch-mismatch bug (see
[release-process.md](release-process.md)), the `/opt/sql.initialized`
provisioning-marker mechanism (see `CLAUDE.md`), and the editable-cell
dropdown-cache / display-value gotchas (see
[ui-conventions.md](ui-conventions.md)). Everything else in that history was
either superseded, one-off Q&A with no lasting action, or routine git/PR
mechanics not worth preserving.

## 2026-07-06 — Consolidated scattered dev knowledge into llm-wiki/

Historical development context was split across
`.github/copilot-instructions.md`, `.github/instructions/*.instructions.md`, and
per-editor chat history, each read by a different tool with no shared entry
point. Moved the durable content into this wiki with a single router
(`INDEX.md`), and pointed every editor's entry file (`AGENTS.md`, `CLAUDE.md`,
Copilot's instructions files, Cursor rules) at it, so any assistant gets the
same context instead of a partial one.

## 2026-06-13 — Rebuilt STS as v2 in SvelteKit + TypeScript + SQLite

The legacy `sts/` app (PHP 8 + Apache + MariaDB, no framework, no build step) is
being superseded by a ground-up rebuild in `app/` (SvelteKit, adapter-node,
better-sqlite3, single container). Business rules for v2 are specified in
`docs/SPEC.md`, which is the source of truth for behaviour going forward. The
legacy app remains in maintenance mode only — see
[ui-conventions.md](ui-conventions.md) and
[api-schema-pitfalls.md](api-schema-pitfalls.md) for its conventions, which do
not apply to `app/`.

## Undated — Bugfixes are patch releases

See [release-process.md](release-process.md) — a bugfix is a semver patch bump,
not a minor/major bump, regardless of how long since the last release.
