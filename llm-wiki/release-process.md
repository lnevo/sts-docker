---
title: Release Process
purpose: Versioning policy and the GitHub Actions release trigger gotcha.
load_when:
  cutting a release, tagging, or debugging why a release GitHub Action didn't
  run
owner: shared
last_updated: 09/07/2026
---

# Release Process

## Versioning

Use semantic versioning. A bugfix is a **patch** bump (e.g. `v1.9` → `v1.9.1`),
not a minor/major bump — don't jump to `v2.0` for a fix just because it's the
next release.

## GitHub Actions release trigger

If a release workflow doesn't fire after publishing a GitHub release, check the
trigger: it must be `release: [created]`, not a bare tag push. Pushing a tag
alone does not fire a `release:` triggered workflow — the release object itself
has to be created (e.g. via `gh release create` or the GitHub UI).

## Known CI/CD gaps (as of last review)

- `super-linter/super-linter` is pinned to `v6.5.0`; `docker/build-push-action`
  is pinned to `v5`. Both have newer majors available — bump when touching these
  workflows next, not urgent on their own.

Fixed 09/07/2026: `publish-docker.yaml`'s `push:` trigger targeted `main` while
the repo's default branch is `master`, so push-triggered publishing never fired
(only `release: [created]` and manual `workflow_dispatch` did). Corrected to
`master`.

---

_Sourced from Copilot chat history for this repo (versioning, Actions-trigger
debugging, and a workflow review session) on 09/07/2026 — see
[decisions-log.md](decisions-log.md)._
