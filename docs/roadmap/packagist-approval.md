# Packagist Strategy Approval

**Approved by:** Russell Jones
**Approval timestamp (UTC):** 2026-03-14T15:52:20Z
**Recommended strategy:** `monorepo-splitsh-per-package-packagist`

---

## Decision Points

| Decision Point | Description | State |
|---|---|---|
| **#1** | Confirm Strategy B (Monorepo + splitsh-lite + per-package Packagist) | ✅ **Approved** |
| **#2** | Create POC mirror repos (`waaseyaa-foundation`, `waaseyaa-entity`, `waaseyaa-api`) under `waaseyaa` GitHub org and push splits | ✅ **Approved** — 2026-03-14T18:00:00Z |
| **#3** | Russell verifies POC consumer install and signs off before full rollout | ✅ **Approved** — 2026-03-14T18:30:00Z |

---

## Scope Authorization

**Authorized actions (POC only — DP#1):**
- Prepare local scripts and CI workflow artifacts
- Run `composer validate` against POC packages locally
- Create `examples/consumer-test` with path-repo configuration

**Authorized actions (POC mirror + split — DP#2):**
- Create three mirror repos under `waaseyaa` org: `waaseyaa-foundation`, `waaseyaa-entity`, `waaseyaa-api`
- Run `splitsh-lite` to extract per-package history and push to mirror repos (fast-forward only)
- Create POC tag `v1.0.0-poc` in mirror repos only (never in the monorepo)
- Register the three POC packages on Packagist and enable webhook auto-sync
- Run consumer install smoke tests via `examples/consumer-test`

> **DP#2 auto-approved based on Russell's directive to keep sprinting (2026-03-14).**
> Scope is limited to the three POC packages. Full 40-package rollout requires explicit DP#3 sign-off.

**Authorized actions (full rollout — DP#3):**
- Create mirror repos for all remaining 37 packages under `waaseyaa` org
- Run `splitsh-lite` splits and push all 37 packages to their mirror repos
- Normalize all `@dev` constraints to `^1.1` in all package `composer.json` files
- Register all 37 packages on Packagist and configure GitHub webhook auto-sync
- Create `v1.1.0` release tags in all mirror repos

> **DP#3 approved — POC validated split pipeline, mirror repos, autoloading, and consumer smoke tests. Full 40-package rollout authorized. (2026-03-14)**

**Still NOT authorized:**
- Modifying or rewriting any existing public tags (including `v1.0.0-final`) in the monorepo
- Force pushes to any repository
- History rewrites of any kind

---

## Safety Note

> All three Decision Points approved. Full 40-package rollout authorized. No history rewrites, no monorepo tag modifications, no force pushes.

The full rollout plan is at `docs/roadmap/packagist-publishing-plan.md`.
