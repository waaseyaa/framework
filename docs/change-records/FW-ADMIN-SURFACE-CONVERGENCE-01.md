# FW-ADMIN-SURFACE-CONVERGENCE-01 — admin-surface package convergence

- Initial parent: `d1e63f9de2d1300c366cf18b0f1eecd369379c1e`
- Current base: `a682a17e03d3ecee565798a87551210a9be90174`
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
| WP1 | Package charter, inventory, public dispositions, and dependency design | Complete |
| WP2 | Scoped Deptrac authority and Mermaid dependency view | Complete locally; hosted evidence pending |
| WP3 | Canonical contract and mechanical SPA compatibility | Complete locally; hosted evidence pending |
| WP4 | Conformance, refusal, route, distribution, and browser evidence | Paused before start |
| WP5 | Independent review, governed PR, exact-head checks, merge, and issue reconciliation | Paused before start |

## Evidence ledger

| Candidate | Evidence | Result |
| --- | --- | --- |
| Parent `d1e63f9de2d1300c366cf18b0f1eecd369379c1e` | Refreshed `origin/main`; local `main` matched; worktree created from that exact object | Pass |
| WP0 checkpoint | `composer install --no-interaction --prefer-dist` using the locked dependencies with the installed `fileinfo` and `zip` extensions enabled for the process | Pass, 200 installs |
| WP0 checkpoint | `npm ci` in `packages/admin` | Pass, 827 packages, 0 reported vulnerabilities; one transitive engine warning under Node 24.13.1 |
| WP1 charter checkpoint | `packages/admin-surface/README.md` charter inventory | 30 of 30 production PHP files classified; 13 HTTP routes, composition and optional seams, canonical and drifted wire consumers, public dispositions, and split contents recorded |
| WP1 charter checkpoint | Inventory cross-check against `src/**/*.php`, provider `addRoute()` calls, `public-surface.php`, source `@api` annotations, split workflow, and packaged-form acceptance | Pass: 30 files, 0 missing classifications, 13 routes, 9 declared public entries, 19 files carrying `@api`; split target and artifact acceptance located |

## WP1 findings carried into later work

- The intended internal model is Boundary contract, Application adapters, and
  Delivery and composition, with dependencies directed toward Boundary
  contract. Deptrac must make this classification exhaustive in WP2.
- PHP emits and the SPA consumes `AdminSurfaceEntity.mutation_token`, but the
  canonical `contract/types.ts` does not declare it.
- Page-builder crossing types are currently authoritative only in the SPA-local
  `packages/admin/app/contracts/pageBuilder.ts`; they must move under the
  package contract authority without changing the route behavior.
- SPA-local types also drift on session UI, `emailVerified`, and capability
  optionality. `AdminSurfaceContract.ts` and `ADMIN_SURFACE_VERSION` have no
  observed runtime consumer.
- The declaration map contains nine public entries, while source `@api`
  annotations expose additional path authorities, catalog/list APIs, crossing
  values, and the generic page-builder implementation. The charter assigns a
  disposition to each family; the governed declaration file remains to be
  reconciled in its implementation work package.
- `admin_surface.action` is authentication-gated but, unlike the page-builder
  mutation routes, does not call the router's CSRF gate. This is recorded for
  authorization/refusal review and is not changed without discriminating
  evidence in the bounded candidate.

## Finding ledger

| ID | Area and observed evidence | Consequence | Disposition and owner | Acceptance evidence | Issue |
| --- | --- | --- | --- | --- | --- |
| AS-ARCH-001 | `AdminSurfaceHostFactoryInterface` returns `AbstractAdminSurfaceHost`; the initial charter placed the two in Boundary and Application respectively | The public factory contract depended outward and the first Deptrac run failed | Repaired in Framework by classifying the public extension base as Boundary contract; no exception | Clean Deptrac run plus forbidden-edge fixture | #3074 / #3075 |
| AS-CONTRACT-001 | PHP emits and the SPA consumes `AdminSurfaceEntity.mutation_token`, absent from canonical `contract/types.ts` | Canonical contract did not describe required optimistic-concurrency behavior | Repaired: canonical and SPA types now declare the always-present nullable field, request types require a non-null token for fenced mutations, and a newer null response clears cached authority | Exact TypeScript compatibility, PHP emitter inventory, and 19 adapter regression tests | #3074 |
| AS-CONTRACT-002 | Page-builder wire shapes were owned only by SPA-local `app/contracts/pageBuilder.ts` | Split package did not own every crossing payload | Repaired: canonical contract owns definitions, drafts, commands, preview, history, restore, error, result, and request shapes; SPA mirrors remain only for clean declaration emission | Exact TypeScript compatibility plus 8 page-builder client regressions; PHP conformance expansion remains WP4 | #3074 |
| AS-PUBLIC-001 | Nine declaration-map entries versus 19 source files carrying `@api` | Compatibility promises are incomplete or ambiguous | Reconcile public declarations and annotations, with lifecycle rationale | Public-surface validators and consumer evidence | #3074 |
| AS-SEC-001 | `admin_surface.action` is authentication-gated but lacks the page-builder mutation routes' CSRF gate | Mutation refusal behavior needs independent review before any change | Review and repair only with a discriminating route-level test; otherwise file a bounded residual | Authorization, CSRF, malformed-body, and refusal tests | #3074 |
| AS-COHESION-001 | `GenericAdminSurfaceHost` and `AdminSurfaceServiceProvider` concentrate multiple coordination roles | Large extraction would exceed the first review candidate | Retain for this slice and create bounded residual cleanup issues with concrete seams | Issue links and no unreviewed extraction in candidate | #3074 |

## WP2 evidence

- Implementation checkpoint: `1a53726c2`.
- Exact Deptrac 4.7.2 is locked as a root development dependency, installed
  only inside the owned worktree.
- `packages/admin-surface/deptrac.yaml` is shipped with the split subtree and
  classifies all 30 production tokens plus referenced external authorities.
- Deptrac reports 0 violations, 0 skipped, 0 uncovered, 427 allowed, and no
  unassigned tokens. Layer membership is 24 Boundary contract, 4 Application
  adapters, and 2 Delivery and composition.
- Five Architecture controls prove the production graph and Mermaid view are
  current, an allowed inward edge passes, a forbidden outward edge fails, and
  an unclassified dependency fails.
- Composer, preflight, and hosted CI wiring retain `check-package-layers` and
  add the scoped Deptrac gate. CI also emits an exact-head JSON report artifact.

## WP3 evidence

- A red-first `npm run check:contract-compatibility` run failed on the known
  session, account, catalog-capability, entity, list-result, and absent
  page-builder types before implementation.
- The canonical contract is split by concern: `types.ts` owns bootstrap,
  catalog, entity, result/error, list, and CRUD; `schema.ts`, `revisions.ts`,
  and `pageBuilder.ts` own those protocols. `types.ts` and `index.ts` preserve a
  single import entry. The entity contract declares the always-emitted nullable
  `mutation_token` field.
- PHP-emitter inventory corrected additional optionality drift: session
  `features`, `capabilities`, `email`, and `emailVerified` are always emitted;
  the latter two are nullable. The SPA mirrors now match those facts exactly.
- `packages/admin/contract-compatibility.ts` checks exact type equality across
  56 canonical/mirror type pairs. The no-emit config spans the two sibling
  package trees without changing the Admin package's `rootDir: app`
  declaration build.
- The Admin contracts workflow runs the compatibility gate and now triggers on
  canonical contract-only changes. The root PHP-only preflight is unchanged
  because it does not provision Admin Node dependencies; the dedicated
  blocking `admin/contracts` job is the correct hosted owner.
- Independent Sol review found two blockers before commit: schema/revision core
  payloads remained outside canonical authority, and a newer null mutation
  token left an older validator cached. The contract was split by concern and
  expanded to cover those payloads. A red-first adapter test reproduced the
  stale-token write (resolved instead of refusing); the adapter now clears the
  canonical cache entry and refuses the next write locally with 428.
- Local verification: compatibility check, declaration build, Nuxt typecheck,
  and lint pass; focused transport, page-builder client, and entity-revision
  coverage passes 4 files / 38 tests.

Review candidates, test commands, elapsed time, hosted run identities,
independent findings, repairs, accepted-main identity, and residual issues will
be appended as the work advances.
