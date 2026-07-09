---
title: LLM Wiki Index
purpose: Router — match your task to a trigger below, then open only that page.
load_when: always (read this file first, every session)
owner: shared
last_updated: 06/07/2026
---

# LLM Wiki

Shared context for any developer (human or AI) making changes to this repo. Not
a RAG — a small set of dense markdown pages. Read this index, match your task to
a `load_when` trigger, open only that page. Don't bulk-load everything.

| Page                                             | Load when                                                                                                                                  |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ |
| [ui-conventions.md](ui-conventions.md)           | Touching any page/template in the legacy `sts/` PHP app — CSS, layout, print styles, status badges, drag-and-drop, mobile/tablet behaviour |
| [api-schema-pitfalls.md](api-schema-pitfalls.md) | Writing or reviewing SQL/queries against the legacy `sts/` MySQL schema, or working on `sts/api/`                                          |
| [release-process.md](release-process.md)         | Cutting a release, tagging, or debugging why a release GitHub Action didn't run                                                            |
| [decisions-log.md](decisions-log.md)             | You want the "why" behind a past architectural or process decision, or you're about to make one worth remembering                          |

## Scope note

This wiki covers cross-cutting conventions and process knowledge that used to be
copy-pasted or scattered across `.github/copilot-instructions.md`,
`.github/instructions/`, and various chat histories. Project-specific
architecture, commands, and file layout for each implementation (`app/` vs
`sts/`) still live in `CLAUDE.md` at repo root — that's the source of truth for
"what is this codebase," this wiki is the source of truth for "conventions and
lessons that apply across it."

## Keeping this useful

- Durable facts and decisions only — no PR counts, no "current status," no
  anything that goes stale. The live repo/git history is the source of truth for
  what's currently true.
- Update `decisions-log.md` proactively when a decision lands, not just when
  asked.
- Prune pages as the underlying app changes (e.g. once the legacy `sts/` PHP app
  is fully retired, `ui-conventions.md` and `api-schema-pitfalls.md` go with
  it).
