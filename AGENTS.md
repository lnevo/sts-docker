# AGENTS.md

A containerised fork of the Shipper-Driven Traffic Simulator (STS), a model
railway operations app. Two implementations live side by side: `app/` (STS v2 —
SvelteKit + TypeScript + SQLite, active development) and `sts/` (legacy PHP 8 +
Apache + MariaDB, maintenance only).

## Knowledge base

Shared context lives in `llm-wiki/INDEX.md`. Read the index first, then load
only the page whose `load_when` trigger matches the task. Don't bulk-load
everything.

## Project instructions

Full architecture, commands, and conventions for this repo are in `CLAUDE.md` at
the repo root — read it too, it's tool-agnostic despite the name.
