# Packagist Strategy Approval

**Approved by:** Russell Jones
**Approval timestamp (UTC):** 2026-03-14T15:52:20Z
**Recommended strategy:** `monorepo-splitsh-per-package-packagist`

---

## Decision Points

| Decision Point | Description | State |
|---|---|---|
| **#1** | Confirm Strategy B (Monorepo + splitsh-lite + per-package Packagist) | ✅ **Approved** |
| **#2** | Create 40 mirror repos under `waaseyaa` GitHub org and configure `SPLIT_GITHUB_TOKEN` | ⏳ Pending |
| **#3** | Russell verifies POC consumer install and signs off before full rollout | ⏳ Pending |

---

## Scope Authorization

**Authorized actions (POC only):**
- Prepare local scripts and CI workflow artifacts
- Run `composer validate` against POC packages locally
- Create `examples/consumer-test` with path-repo configuration

**Explicitly NOT authorized until Decision Point #2:**
- Creating mirror GitHub repositories
- Publishing any package to Packagist
- Pushing the split workflow to production CI
- Modifying or rewriting any existing public tags
- Force pushes of any kind

---

## Safety Note

> No destructive actions authorized. Proceed to POC preparation only.

All work in the POC sprint must be local and reversible. The plan document is at
`docs/roadmap/packagist-publishing-plan.md`. Full rollout requires explicit approval
of Decision Points #2 and #3 by Russell before any external side effects occur.
