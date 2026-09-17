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
- Issue #3078 owns the Node creation-timestamp lifecycle authority. The
  Admin Surface compatibility branch remains unchanged in this candidate.
- Issue #3079 owns the declaration of config-entity generic mutability outside
  the generic host. The reviewed `taxonomy_vocabulary` exception remains
  unchanged in this candidate.
- Feature-owned authentication, workflow, MCP, queue, scheduler, notification,
  media, OIDC, and similar APIs remain outside `admin-surface`. Distributing
  their SPA consumers does not transfer their domain or HTTP ownership to this
  package.
- Repository-wide Deptrac parity, PL001–PL010 retirement, and reduction or
  removal of `bin/check-package-layers` remain in issue #3075.
- Large extraction of `GenericAdminSurfaceHost` or
  `AdminSurfaceServiceProvider` requires a separately reviewable acceptance
  reason and is not presumed part of the first candidate. Issues #3082 and
  #3083 own those respective coordination-seam extractions.
- Issue #3084 owns removal or replacement of the deprecated legacy TypeScript
  aggregate exports. This candidate preserves them for compatibility.
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
| WP4 | Conformance, refusal, route, distribution, and browser evidence | Complete locally; three symlink controls remain owned by hosted Linux CI |
| WP5 | Independent review, governed PR, exact-head checks, merge, and issue reconciliation | Immutable integration review complete; repairs and final exact-head qualification in progress |

## Evidence ledger

| Candidate | Evidence | Result |
| --- | --- | --- |
| Parent `d1e63f9de2d1300c366cf18b0f1eecd369379c1e` | Refreshed `origin/main`; local `main` matched; worktree created from that exact object | Pass |
| WP0 checkpoint | `composer install --no-interaction --prefer-dist` using the locked dependencies with the installed `fileinfo` and `zip` extensions enabled for the process | Pass, 200 installs |
| WP0 checkpoint | `npm ci` in `packages/admin` | Pass, 827 packages, 0 reported vulnerabilities; one transitive engine warning under Node 24.13.1 |
| WP1 charter checkpoint | `packages/admin-surface/README.md` charter inventory | 30 of 30 production PHP files classified; 13 HTTP routes, composition and optional seams, canonical and drifted wire consumers, public dispositions, and split contents recorded |
| WP1 charter checkpoint | Inventory cross-check against `src/**/*.php`, provider `addRoute()` calls, `public-surface.php`, source `@api` annotations, split workflow, and packaged-form acceptance | Initial finding: 30 files, 0 missing classifications, 13 routes, 9 declared public entries, 19 files carrying `@api`; the reviewed candidate reconciles the governed declaration map to 24 entries |

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
  observed runtime consumer; they remain deprecated compatibility exports
  pending issue #3084.
- The declaration map contains nine public entries, while source `@api`
  annotations expose additional path authorities, catalog/list APIs, crossing
  values, and the generic page-builder implementation. The charter assigns a
  disposition to each family. The governed declaration file is reconciled to
  24 entries in the reviewed candidate.
- `admin_surface.action` is authentication-gated but, unlike the page-builder
  mutation routes, does not call the router's CSRF gate. This is recorded for
  authorization/refusal review and is not changed without discriminating
  evidence in the bounded candidate.

## Finding ledger

| ID | Area and observed evidence | Consequence | Disposition and owner | Acceptance evidence | Issue |
| --- | --- | --- | --- | --- | --- |
| AS-ARCH-001 | `AdminSurfaceHostFactoryInterface` returns `AbstractAdminSurfaceHost`; the initial charter placed the two in Boundary and Application respectively | The public factory contract depended outward and the first Deptrac run failed | Repaired in Framework by classifying the public extension base as Boundary contract; no exception | Clean Deptrac run plus forbidden-edge fixture | #3074 / #3075 |
| AS-CONTRACT-001 | PHP emits and the SPA consumes `AdminSurfaceEntity.mutation_token`, absent from canonical `contract/types.ts` | Canonical contract did not describe required optimistic-concurrency behavior | Repaired: canonical and SPA types now declare the always-present nullable field, request types require a non-null token for fenced mutations, and a newer null response clears cached authority | Exact TypeScript compatibility, PHP emitter inventory, and 19 adapter regression tests | #3074 |
| AS-CONTRACT-002 | Page-builder wire shapes were owned only by SPA-local `app/contracts/pageBuilder.ts` | Split package did not own every crossing payload | Repaired: canonical contract owns definitions, drafts, commands, preview, history, restore, error, result, and request shapes; SPA mirrors remain only for clean declaration emission | Exact TypeScript compatibility, 8 page-builder client regressions, and producer-derived definitions/draft/refusal conformance | #3074 |
| AS-CONTRACT-003 | The PHP schema producer emits top-level `additionalProperties: false`, absent from canonical and SPA schema types | A producer-owned JSON Schema constraint crossed the boundary without being declared | Repaired: both schema contracts declare the optional legacy-compatible literal `false`; producer-derived conformance rejects future undeclared fields | PHP conformance 9 tests / 250 assertions plus exact TypeScript compatibility and Nuxt typecheck | #3074 |
| AS-PUBLIC-001 | Nine declaration-map entries versus 19 source files carrying `@api` | Compatibility promises were incomplete or ambiguous | Repaired: the declaration map now records 24 supported PHP interfaces and values; the internal default reader no longer carries a misleading `@api`; deprecated legacy TypeScript aggregate exports are retained explicitly under #3084 | Public-surface parity and consumer evidence | #3074 / #3084 |
| AS-SEC-001 | `admin_surface.action` was authentication-gated but lacked the page-builder mutation routes' CSRF gate; malformed or non-object JSON could escape the host boundary | Cookie-authenticated JSON mutations bypassed CSRF validation, while invalid action bodies could throw or type-fail instead of returning the Admin refusal contract | Repaired in Framework: require CSRF on the action route and refuse invalid/non-object JSON before invoking the action; no admission or domain authorization rule changed | Red-first route-option and invalid-body tests; real `CsrfMiddleware` missing-token refusal and matching-token pass-through; registered-route HTTP 400 promotion | #3074 |
| AS-COHESION-001 | `GenericAdminSurfaceHost` and `AdminSurfaceServiceProvider` concentrate multiple coordination roles | Large extraction would exceed the first review candidate | Retain for this slice; #3082 owns generic-host coordination seams and #3083 owns provider routing/static-delivery seams | Bounded issue links and no unreviewed extraction in candidate | #3082 / #3083 |
| AS-LIFECYCLE-001 | `GenericAdminSurfaceHost::handleCreate()` names `node` and initializes `created`/`changed`; the documented `TimestampFieldConvention` has no observed production storage caller | An interface adapter currently carries entity lifecycle compatibility behavior because no proven canonical creation-time authority is wired | Framework entity lifecycle/storage and `waaseyaa/node` own the replacement contract; preserve current behavior here and resolve only in the bounded residual | Cross-path Node creation tests, server-owned `changed`, removal of the exact-type host branch, and package-layer/public-surface checks | #3078 |
| AS-AFFORDANCE-001 | `GenericAdminSurfaceHost::MUTABLE_CONFIG_ROW_TYPES` names only `taxonomy_vocabulary` and controls catalog mutability, mutation-token projection, and action refusal | A generic adapter contains a type-specific lifecycle affordance that could become an implicit policy registry | `waaseyaa/taxonomy` owns vocabulary lifecycle, a lower-layer declaration owns generic CRUD support, and Admin Surface only projects/refuses; preserve the exception in this candidate | Unchanged authorized/refused vocabulary behavior, undeclared config types remain read-only, removal of the hard-coded type, and focused layer/public checks | #3079 |
| AS-DIST-001 | The canonical Admin rebuild could not pass its own guard under native Windows PHP: the guard redirected to `/dev/null`, Git Bash exposed a POSIX PATH to a Windows policy, and `getenv()` returned uppercase `SYSTEMROOT` | The task-mandated Git-for-Windows rebuild path stopped before the hermetic build, leaving the committed distribution stale | Repaired in the canonical build tooling without weakening its guard: Git diagnostics remain visible, an existing-directory native PATH projection is supplied to Windows PHP, both `SystemRoot` casings are accepted, and the sanitized child still receives only validated values | Red-first guard/build refusals; 8 workspace-guard tests, 2 Windows environment tests / 14 assertions, two byte-identical builds, freshness and manifest verification | #3074 |
| AS-DIST-002 | Exact exported-file digests disagreed after clean artifact installation on Windows despite identical file count and bytes. Complete path-sorted manifests retained 30 mismatches: 15 case-only path pairs with identical per-file sizes and SHA-256 values, zero case-fold collisions, and zero case-folded differences | The aggregate validator treated Windows extraction's preservation of an already-created parent directory's casing as content drift, blocking a sound artifact | Repaired without weakening content integrity: exact comparison remains authoritative, with a retry only after proving the installed filesystem is case-insensitive; both rosters are then case-folded while file counts, byte counts, and SHA-256 remain covered, and any case-fold collision fails closed | Retained 8,832-row archive and installed manifests plus all mismatches; repaired validator passed against the retained archive/install without rebuild and the clean candidate rerun passed all positive split surfaces | #3074 |
| AS-DIST-003 | Immutable review found that the Windows case-fold retry compared the current archive and installed tree but did not recheck the archive against its sealed SHA-256 | A jointly modified archive/install pair could satisfy the fallback after the seal was recorded | Repaired: fallback eligibility now requires `hash_equals()` against the sealed archive digest before normalized content comparison; behavioral self-tests reject changed content, case collisions, and post-seal archive mutation | Digest behavioral self-test plus focused architecture gate | #3074 |
| AS-CLIENT-001 | Immutable review found that `admin_surface.action` now required CSRF while the SPA transport adapter still sent unsafe requests without the configured XSRF cookie token | Shipped create, update, delete, action, schema, history, revision, and restore requests could be refused with 403 | Repaired: unsafe same-origin adapter requests decode the configured cookie and send `X-XSRF-TOKEN`; safe methods do not send it and explicit headers remain authoritative | Adapter regressions, exact contract compatibility, Nuxt typecheck, and browser qualification | #3074 |

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

## WP4 behavioral evidence

- Red-first route and host tests reproduced two refusal defects before repair:
  the cookie-authenticated core action route did not require CSRF, and malformed
  or non-object JSON could escape the host boundary before dispatch. The route
  now requires CSRF, the host accepts only an empty body or a JSON object, and
  invalid input is refused without invoking the action.
- Route composition and HTTP-refusal coverage passes 148 tests / 1,307
  assertions. The real `CsrfMiddleware` discriminator proves a missing token is
  rejected with 403 and a matching header/cookie pair reaches the action.
- Authorization, field-oracle, mutation-fence, advisory, allowlist, pagination,
  and page-builder refusal coverage passes 83 tests / 373 assertions.
- Producer-derived PHP-to-TypeScript conformance now reads every canonical
  contract module and covers non-empty session UI, nested catalog values,
  result/error/advisory allowlisting, entity/list output including
  `mutation_token`, schema output, revision history, and page-builder block,
  layout, template, nested draft, preview, history, revision, restore, and
  refusal payloads. It passes 9 tests / 250 assertions.
- Browser route fixtures use canonical contract types and include required
  `emailVerified`, principal `capabilities`, entity `mutation_token`, and
  catalog `revisions` fields. Nuxt typecheck and exact contract compatibility
  pass. Focused Vitest passes 3 files / 31 tests.
- Focused Chromium behavior passes 12 authentication, entity-form,
  schema-deduplication, and page-builder advisory tests; the directly changed
  target-size and lifecycle fixtures pass another 5 tests.
- Static delivery now has a content-byte and MIME matrix for JavaScript, CSS,
  JSON, HTML, SVG, PNG, WOFF2, source maps, and unknown extensions, plus a
  route-level application-asset precedence discriminator. The provider suite
  passes 46 tests / 176 assertions.
- Deptrac remains authoritative after the refusal repair: 0 violations,
  0 skipped, 0 uncovered, 429 allowed. The generated Mermaid view was refreshed
  and its five architecture controls pass with 27 assertions.
- The canonical two-pass hermetic operation rebuilt and accepted the Admin
  distribution with Node 24.13.1 and npm 11.8.0. Both independently generated
  snapshots normalized to the same 99-file, 597,982-byte tree digest
  `3d9fb284d3b09f3358d320833f632ca0623290e8f3a35d873b8ab09b6d5a6dab`.
  Source signature `417c1cdba730ae27231ad9dd91ae5a451e1b724c1ecbaa1e9b81ade3f8dceac4`
  and manifest SHA-256
  `ce0b5382a4371174a062c02fef2bc4ba02aceefeb541f34e29fc143a4aa7a593`
  verify locally.
- Distribution, guard, acceptance, and toolchain coverage passes 87 tests /
  613 assertions. The two Windows environment discriminators pass with 14
  assertions. Linux-oriented synthetic pipeline tests that require creating
  symlinks cannot execute on this Windows host and remain owned by hosted CI.
- The first clean split installation exposed AS-DIST-002 after installation
  itself and surface composition passed. Retained path-sorted manifests cover
  all 8,832 archive and installed files. Their 30 raw mismatches are exactly 15
  case-only pairs; every paired byte count and SHA-256 matches, there are no
  case-fold collisions, and the complete normalized manifests have zero
  differences. The collision-safe validator repair passed against that same
  retained archive and installation without rebuilding either one.
- Candidate `520c53ca447cd3db00d11fd220c1e0d15089954a` then passed a fresh sealed
  archive/install run for package composition, exported-file integrity,
  bootstrap, and no-dev acceptance. Fifteen of sixteen negative controls were
  detected locally. Windows denied creation of the source-symlink fixture, so
  issue #3081 records that host limitation and hosted Linux CI owns that
  control.
- `HermeticAdminBuildPipelineTest::exact_lock_install_and_generate_use_one_minimal_environment_then_scan_outputs`
  and `HermeticAdminBuildPipelineTest::an_explicit_public_registry_authorization_retries_only_an_offline_cache_miss`
  were not locally executed because their symlink fixtures are unavailable on
  this Windows host. Together with the split harness `source-symlink-installed`
  negative control, hosted Linux CI owns all three symlink cases. They are not
  reported as locally passing.

## Immutable integration review of candidate `520c53ca4`

The complete base-to-head diff received one bounded integration review. The
architecture assessment found no unexplained Deptrac violation: all 30
production PHP tokens remain classified, uncovered dependencies fail closed,
and the retained architecture controls remain in force. Review identified and
repaired these material integration findings:

- the SPA transport did not propagate the configured CSRF token after the
  action route was fenced;
- the Windows case-fold split fallback did not re-anchor the archive to its
  sealed digest;
- page-builder producer conformance stopped short of preview and revision
  lifecycle payloads;
- PHP public declarations and deprecated TypeScript aggregate lifecycle were
  not yet reconciled; and
- cohesion residual ownership and split-evidence claims were incomplete.

The Composer lock impact is bounded but not byte-neutral: Deptrac adds seven
new development packages; `symfony/dependency-injection`,
`symfony/polyfill-deepclone`, and `symfony/var-exporter` move from runtime to
development lock sections because no production package requires them; and
three local path-package metadata records refresh without version or path
reference changes.

Any further substantive repair creates a new immutable candidate and requires
proportionate evidence refresh. After these repairs, only the repaired areas
are re-reviewed before one final exact-head qualification.

Review candidates, test commands, elapsed time, hosted run identities,
independent findings, repairs, accepted-main identity, and residual issues will
be appended as the work advances.
