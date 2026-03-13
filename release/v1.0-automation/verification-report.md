# v1.0 Release Automation — Verification Report

**Date:** 2026-03-13
**Milestone:** v1.0 Release (Milestone #22)
**Repo:** waaseyaa/framework

## Merged PRs

All Milestone 22 PRs were merged via squash merge after CI checks passed.

| PR | Title | Merged At |
|---|---|---|
| [#350](https://github.com/waaseyaa/framework/pull/350) | chore: v1.0 release audit issue tracking manifest | 2026-03-13T08:35:41Z |
| [#354](https://github.com/waaseyaa/framework/pull/354) | Fix #317 — Exempt JSON:API from CSRF validation | 2026-03-13T08:35:43Z |
| [#355](https://github.com/waaseyaa/framework/pull/355) | fix(#318): Sanitize HTML in HtmlFormatter to prevent stored XSS | 2026-03-13T08:36:00Z |
| [#356](https://github.com/waaseyaa/framework/pull/356) | Fix #314 — Correct NoteInMemoryStorage::create() return type | 2026-03-13T08:36:03Z |
| [#357](https://github.com/waaseyaa/framework/pull/357) | Fix #315 — Remove Foundation->Path layer violation | 2026-03-13T08:37:40Z |
| [#358](https://github.com/waaseyaa/framework/pull/358) | fix(#321): Enforce single-tenant mode for v1.0 | 2026-03-13T08:36:06Z |
| [#359](https://github.com/waaseyaa/framework/pull/359) | Fix #320 — Exempt MCP endpoint from CSRF validation | 2026-03-13T08:36:09Z |
| [#360](https://github.com/waaseyaa/framework/pull/360) | Fix #316 — Remove Validation->Entity layer violation | 2026-03-13T08:36:11Z |
| [#361](https://github.com/waaseyaa/framework/pull/361) | Fix #319 — Add GPL-2.0-or-later LICENSE file | 2026-03-13T08:36:14Z |
| [#362](https://github.com/waaseyaa/framework/pull/362) | chore: add v1.0 release automation pipeline | 2026-03-13T08:37:45Z |

## Automation Pipeline Created

### CI Workflow (`.github/workflows/ci.yml`)
- **Existing jobs:** Lint, CS Fixer, PHPStan, PHPUnit, Manifest conformance, Ingestion defaults, Security defaults
- **Added jobs:** Frontend build (Nuxt 3 + Vitest), Playwright smoke tests
- **Artifacts:** JUnit XML, coverage report, frontend build output, Playwright screenshots/traces

### Release Workflow (`.github/workflows/release.yml`)
- Triggers on push to `main`
- **Pipeline:** Deploy staging -> Full Playwright sweep -> Approval gate -> Deploy production -> Post-deploy smoke
- **Failure handling:** Auto-rollback + incident issue creation

### Auto-merge Workflow (`.github/workflows/auto-merge.yml`)
- Label-driven: `auto-merge-when-green`
- Checks: all 7 required status checks, no merge conflicts, milestone assigned
- Merges via squash, posts comment with details

### Git Hooks
- `.githooks/pre-push` — PHP syntax, composer validation, PHPStan
- `scripts/install-git-hooks.sh` — one-command hook installer

### Release Scripts
- `scripts/release.sh` — changelog generation, annotated tag, GitHub release
- `scripts/deploy.sh` — staging/production deployment with metadata
- `scripts/rollback.sh` — rollback to previous tag with metadata

## CI Runs

| Run | Workflow | URL |
|---|---|---|
| Latest CI | CI | https://github.com/waaseyaa/framework/actions/runs/23042863695 |
| Latest Release | Release Pipeline | https://github.com/waaseyaa/framework/actions/runs/23042863698 |

## Branch Protection

Branch protection is **not yet configured** on `main`. `REPO_ADMIN_SETUP.md` has been added with:
- CLI commands (`gh api`) to configure all 9 required status checks
- Manual GitHub UI instructions
- CODEOWNERS file guidance

**Action required:** A repo admin must run the setup commands in `REPO_ADMIN_SETUP.md`.

## Existing Release Tag

Tag `v1.0.0` already exists on the repository (created prior to this automation work). The release scripts (`scripts/release.sh`) will create subsequent tags (e.g., `v1.0.1`).

## Rollback Policy

If production smoke tests fail:
1. `release.yml` automatically runs `scripts/rollback.sh <previous-tag>`
2. Post-deploy smoke tests re-run against the rolled-back version
3. An incident issue is created with logs, artifacts, and action items

## Failed Checks / Mitigations

| Issue | Resolution |
|---|---|
| PR #357 merge conflict (composer.lock) | Resolved by merging main into PR branch, re-pushed, merged successfully |
| No branch protection configured | Created `REPO_ADMIN_SETUP.md` with admin instructions (PR #362) |
| `v1.0.0` tag already exists | Release scripts guard against duplicate tags; next release will be `v1.0.1` |

## Status

**All Milestone 22 PRs: MERGED**
**Automation pipeline: DEPLOYED TO MAIN**
**Branch protection: PENDING ADMIN ACTION**
