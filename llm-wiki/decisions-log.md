---
title: Decisions Log
purpose: Durable architectural/process decisions and the reasoning behind them.
load_when:
  you want the "why" behind a past decision, or you're about to make one worth
  recording
owner: shared
last_updated: 09/07/2026
---

# Decisions Log

Append entries here when a decision lands — durable facts and reasoning only, no
status updates or in-flight work. Newest first.

## 2026-07-16 — HART train blocks remain operationally independent

The HART session workflow runs D749 Starting/Outbound, then NVL
Outbound/Return, then CK1. This order is an operating constraint rather than a
score-tuning variable: D749 and NVL must not wait for CK1, and CK1 may run
independently later in the shift. Traffic tuning must optimize within this fixed
sequence even when a simulated throughput score favors the former interleaved
order.

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
