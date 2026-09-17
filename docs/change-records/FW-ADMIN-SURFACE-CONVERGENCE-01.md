# FW-ADMIN-SURFACE-CONVERGENCE-01 — admin-surface package convergence

- Parent: `d1e63f9de2d1300c366cf18b0f1eecd369379c1e`
- Forge mirror: `waaseyaa/framework#3074`
- Related: `waaseyaa/framework#3075` (Deptrac adoption),
  `waaseyaa/framework#3073` (generated-owner admission), and
  `waaseyaa/framework#3023` (required identifier validation)
- Contract: `docs/specs/admin-spa.md`
- Branch: `codex/fw-admin-surface-convergence-01`
- Worktree: `C:\dev\waaseyaa\worktrees\fw-admin-surface-convergence-01`
- Authority: package convergence, scoped implementation, tests, review, PR,
  governed auto-merge, and issue reconciliation; no tag, release, deployment,
  production mutation, or unrelated cleanup authority

## Outcome

Make `waaseyaa/admin-surface` a reviewable interface-package boundary with an
explicit package charter, one authoritative PHP-to-TypeScript wire contract,
mechanical SPA compatibility, complete crossing-payload conformance coverage,
truthful optional-capability advertisement, classified public interfaces, and
tracked residual work. Preserve domain authority in the packages that own
authorization, storage, workflows, revisions, and page building.

## First review candidate

The first candidate is bounded to the slice requested by issue #3074:

1. Add the durable package charter and complete route, payload, binding,
   optional-dependency, SPA-consumer, public-symbol, and split-artifact map.
2. Establish the authoritative wire contract and correct verified drift,
   including `mutation_token`.
3. Add a mechanical canonical-to-SPA compatibility gate and expand conformance
   coverage across entity, result, error, list, action, and page-builder shapes.
4. Define and enforce the package's internal PHP dependency model through the
   first scoped Deptrac configuration owned jointly with
   `FW-DEPTRAC-ADOPTION-01`.
5. Give every declared public interface and optional seam a lifecycle and
   composition disposition.
6. Record a decomposition plan and file bounded residual issues rather than
   folding large extraction or unrelated Admin UX work into this candidate.

Runtime behavior remains stable unless a reproduced contract or refusal defect
has a discriminating regression test and fits this review boundary.

## Explicit exclusions

- Issue #3073 retains generated-application owner admission and authorization
  contract changes.
- Issue #3023 retains required entity identifier validation consistency.
- Repository-wide Deptrac parity, PL001–PL010 retirement, and reduction or
  removal of `bin/check-package-layers` remain in issue #3075.
- Large extraction of `GenericAdminSurfaceHost` or
  `AdminSurfaceServiceProvider` requires a separately reviewable acceptance
  reason and is not presumed part of the first candidate.
- No release, split publication, deployment, or production operation.

## Custody

The worktree was created detached from `origin/main` at the parent above and
then placed on the named branch using only
`C:\Program Files\Git\cmd\git.exe`, as required by the task. Composer and Admin
npm dependencies are installed only inside this worktree.

The repository worktree coordinator could not issue a lease on this Windows
host because it invokes the POSIX `bin/git` wrapper, which native Windows PHP
cannot execute. Using that wrapper would also conflict with the task's explicit
Windows-Git-only instruction. The exact path, branch, and parent recorded here
therefore serve as the current custody record; the coordinator limitation is
not treated as cleanup authority over any worktree.

## Work packages

| Work package | Scope | Status |
| --- | --- | --- |
| WP0 | Isolated worktree, local dependencies, and durable records | Complete |
| WP1 | Package charter, inventory, public dispositions, and dependency design | Paused before start |
| WP2 | Scoped Deptrac authority and Mermaid dependency view | Paused before start |
| WP3 | Canonical contract and mechanical SPA compatibility | Paused before start |
| WP4 | Conformance, refusal, route, distribution, and browser evidence | Paused before start |
| WP5 | Independent review, governed PR, exact-head checks, merge, and issue reconciliation | Paused before start |

## Evidence ledger

| Candidate | Evidence | Result |
| --- | --- | --- |
| Parent `d1e63f9de2d1300c366cf18b0f1eecd369379c1e` | Refreshed `origin/main`; local `main` matched; worktree created from that exact object | Pass |
| Uncommitted WP0 | `composer install --no-interaction --prefer-dist` using the locked dependencies with the installed `fileinfo` and `zip` extensions enabled for the process | Pass, 200 installs |
| Uncommitted WP0 | `npm ci` in `packages/admin` | Pass, 827 packages, 0 reported vulnerabilities; one transitive engine warning under Node 24.13.1 |

Review candidates, test commands, elapsed time, hosted run identities,
independent findings, repairs, accepted-main identity, and residual issues will
be appended as the work advances.

