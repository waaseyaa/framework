# ROUTE-METADATA-01 implementation plan

- Design authority: `docs/specs/route-metadata.md`
- Change record: `docs/change-records/ROUTE-METADATA-01.md`
- Audit evidence: `docs/audits/route-hooks-3123.md`
- Forge owners: #3121, #3122, #3123, #3124, #3125, #3117, #3007,
  #3004, #3013, #1866, #2859, and #3083
- Status: **planning only**. This plan does not authorize runtime changes,
  test changes, staging, commits, pushes, pull requests, or GitHub mutations.

## Current preservation boundary

The reviewed design, audit, changelog, source-consumer regression, fixtures, and
evidence README are preserved in local commit
`401a571cf081db3d940898bac2ffe2d4a0ec22aa` containing exactly those eight
files. The native canonical `bin/project-hooks pre-commit` gate was run manually
and passed immediately before the normal Git commit, with portable checks
passing and `cs-check` reporting an empty file list in 2.2 seconds. No hook shim
was installed, no hook was bypassed, and no native toolchain file changed.

The two authorized formatting-only fixture changes received independent
semantic-equivalence review. Their final SHA-256 values are
`052580733083C3B3645FC6F76979CC92141AEC9EA9EDAC634ED93128828F0B99` for
`InspectableApplicationServiceProvider.php` and
`22995A07CA16EE0D07C38D8CBCB3A5A3F56F5394F4C76693EC3F77A315CD7589` for
`route_metadata_runner.php`. Tests were not rerun after formatting; the original
reviewed failing-test evidence is reused because the independent review found no
semantic change. This limitation remains explicit until a later authorized test
run produces new evidence.

Implementation planning proceeds from the reviewed content hashes and the
independent eligibility review at
`route-metadata-eligibility-review.md`, SHA-256
`F9E9EF68F5AA36976B79B8CDF2ED56687D1329918F2CAD78CF1B68AC2548C631`.
Implementation planning is anchored to that preserved checkpoint. This plan
still does not authorize implementation.

## Delivery rule

Deliver the smallest consumer repair in dependency order. Each production
package may be edited only after its package owner has the scoped route-contract
evidence needed for that edit and, for the ten newly added packages, an opened
full package-audit entry under #3122. The full unrelated convergence of every
package is not a blanket predecessor for the consumer repair. Findings outside
the route crossing remain independently owned and may be bounded residuals.

Keep these invariants through every slice:

- Foundation owns neutral metadata values, participation identity, snapshot
  lifecycle, and readiness. Foundation does not import Routing contract types.
- Routing reuses Symfony for compilation, matching, generation, host, schemes,
  condition evaluation, and request-context behavior.
- Route contribution and inspection perform no execution-service construction,
  credential resolution, session start, application-data query, durable write,
  handler call, source read/hash, autoload, reflection, or implicit recompile.
- The bootstrap-validated provider inventory has the closed states `none`,
  `declarative`, and `legacy`, with transitive classification-input identity.
- Mixed legacy HTTP chooses exactly one path per provider per epoch. Canonical
  consumers publish no partial snapshot and refuse actual legacy or stale,
  missing, unknown, or identity-mismatched participation.
- Handler lookup occurs after matching and honors explicit container lifetimes.
- Declared access metadata is distinct from effective middleware, entity,
  field, domain-router, and handler enforcement.

## A0: read-only audit preflight

This is the next ready work slice. It makes no repository or forge mutation.

### Inputs and checks

1. Verify the preserved checkpoint, parent SHA, eight-file hashes, clean
   ownership boundaries, dependency lock identity, and exact native toolchain.
   Stop if the preservation commit still does not exist.
2. Reconcile the live #3121/#3122/#3123 decisions and CS-01 coupling finding
   with the durable design. Do not redo the completed route-hook audit.
3. Confirm the 14-package production roster plus `skeleton` below against the
   exact candidate and confirm no new route producer or consumer has appeared.
4. Record package-audit status. Foundation, Routing, Bimaaji, and CLI have
   bounded audit evidence. The ten additions remain pending full package audit:
   `admin-surface`, `ai-agent`, `api`, `debug`, `genealogy`, `graphql`, `mcp`,
   `ssr`, `wayfinding`, and `workspace`.
5. For the first implementation candidate only, confirm the predecessor
   contracts for manifest identity, completed snapshot readiness, Symfony
   compilation, handler resolution, Bimaaji projection, and CLI kernel access.
6. Confirm the failing source-consumer discriminator still represents the
   intended boundary from its preserved log. Do not rerun it during A0.

### Remaining substantive audit scope

The route-hook audit classified contribution behavior only. Before production
edits in each added package, its #3122 entry must still review the package's
purpose, dependencies, public surface, duplicated or orphaned contracts,
failure boundaries, supported installation profiles, distribution form, and
real consumers. Package-specific emphasis is:

- `admin-surface`: host lifecycle, static delivery, page-builder integration,
  session/CSRF inputs, public surface, and #3083;
- `ai-agent`: controller factories, run repository/audit/broadcast lifetimes,
  permission exposure, and optional AI installation;
- `api`: entity exposure projection, JSON:API generation, workflow/catalog
  availability, optional package gates, and API public surface;
- `debug`: enablement contract, error preview exposure, and production absence;
- `genealogy`: controller registrations, SSR/domain dependencies, access, and
  distribution-extension profile;
- `graphql`: schema/controller wiring, GET/POST and CSRF behavior, optional
  installation, and split form;
- `mcp`: public/write tiers, OAuth metadata, config refusal, transport limits,
  handler lifetimes, and optional package profiles;
- `ssr`: render and SEO handler wiring, static/non-static controller behavior,
  and installs without SSR;
- `wayfinding`: anchor/beacon/session route exposure, capability enforcement,
  and optional MCP integration;
- `workspace`: asset path safety, filesystem execution boundary, priority, and
  interface-package installation.

These audit entries may progress immediately before their matching migration
slice. Unrelated findings receive separate owners and do not automatically stop
Foundation, Routing, Bimaaji, or CLI work.

### A0 exit criteria

- preservation checkpoint exists and is reproducibly identified;
- roster and file ownership are current;
- predecessor decisions have no unresolved contradiction;
- the next implementation slice names only packages whose required audit and
  contract evidence is ready;
- no runtime or test file changed and no test command ran.

## Planned implementation slices

Each slice produces one reviewable candidate or is split further if package
ownership or risk requires it. Later slices depend on the named earlier exits.

### I1: Foundation values, readiness, and participation inventory

Owner: `waaseyaa/foundation`. Expected files include new classes under
`packages/foundation/src/Routing/Metadata/`,
`ServiceProvider/ServiceProviderInterface.php`, `ServiceProvider.php`,
`Discovery/PackageManifest.php`, `Discovery/PackageManifestCompiler.php`, and
focused compiler/service-provider tests.

Responsibilities:

- immutable route definition, handler reference, contribution context,
  completed snapshot, unavailable/failed result, and source/cohort identity;
- manifest-compiled `none | declarative | legacy` records with versioned
  transitive identity over leaf, ancestry, traits, effective route method,
  capability, and base no-op inputs;
- bootstrap validation token; contribution code compares records and tokens
  without inspecting source;
- pure finalized entity/exposure projection from the in-memory definition
  roster and copied declaration config;
- terminal failed epoch, restricted-profile refusal, and immutable ownership.

Predecessors: preservation checkpoint, A0, #1866 signature decision, and the
route lifecycle subset of #2859. Broader failed-boot retry convergence remains
separate.

Focused discriminators:

- inherited Foundation no-op plus pure contributor succeeds;
- parent, trait, direct implementation, and capability precedence classify
  correctly without executing hooks;
- stale/unknown/missing/transitively mismatched records refuse;
- recursive, early, failed, restricted, and mutation attempts refuse;
- poison source/service/storage/session/query/write/handler probes stay zero.

### I2: Routing compiler and request-aware execution resolver

Owner: `waaseyaa/routing`. Expected files include new metadata compiler and
handler resolver classes, `WaaseyaaRouter.php`, `RouteBuilder.php` compatibility
adapters where required, `AuthOidcRouteServiceProvider.php`,
`OidcHttpRoutes.php`, and focused Routing tests.

Responsibilities:

- lossless Symfony `Route` compilation for path, host, schemes, methods,
  condition, defaults, requirements, options, priority, and access metadata;
- stable ordering and hard duplicate-name refusal;
- allowlisted `builtin:` dispatch and registered `service:`/`class:` lookup
  only after matching, preserving declared shared/factory/request lifetimes;
- reuse a suitably bounded existing kernel/HTTP execution-resolution seam. Do
  not use `KernelHandlerContainer::has()` for availability because it constructs
  through `get()`. Do not inherit `get()` reflection auto-wiring or its provider
  fallthrough behavior when an explicit handler binding must fail loudly. Do not
  introduce a second general service locator framework. If a narrow Foundation
  adapter is required to expose exact explicit-binding lookup, I3 owns it;
- no callable/object auto-conversion and no inspection-time class loading;
- auth/OIDC declarations separated from request execution under #3125.

Predecessors: I1 and the relevant #3125 scoped audit checkpoint. #3004 and
#3013 define access and domain-router qualification limits.

### I3: Foundation composition and mixed HTTP bridge

Owner: `waaseyaa/foundation`, with Routing compiler use under the kernel
exemption. Expected files include `Kernel/BuiltinRouteRegistrar.php`,
`Kernel/HttpKernel.php`, `Http/ControllerDispatcher.php` only if required by the
reviewed handler seam, and focused kernel tests.

Responsibilities:

- Foundation built-in, provider, and terminal cohorts with current priority and
  same-priority insertion semantics;
- in legacy-default HTTP, exactly one `none`, `declarative`, or `legacy` action
  per provider and no disappearance or duplicate registration;
- in canonical mode, atomic completed snapshot or explicit refusal;
- when I2 requires it, add the smallest Foundation-owned explicit-binding
  adapter over existing kernel resolution. It must not call `has()`, reflectively
  auto-wire an unbound handler, or continue to a fallback after the selected
  binding's construction failure;
- HTTP matching from the same canonical definitions, followed by request-aware
  handler resolution and unchanged middleware/access/parameter conversion.

Predecessors: I1 and I2. Exit does not claim full domain-router parity (#3013)
or effective access parity (#3004).

### I4: first canonical consumers, Bimaaji and CLI

Owners: `waaseyaa/bimaaji` and `waaseyaa/cli`.

Expected Bimaaji files include
`packages/bimaaji/src/Introspection/Routing/RoutingIntrospectionProvider.php`,
`BimaajiServiceProvider.php`, routing/public-surface graph serializers and
section models reached by that provider, `Command/GraphDumpHandler.php`, and
focused graph tests. Expected CLI files include
`packages/cli/src/Provider/MiscBServiceProvider.php`, the route-list handler and
formatter, ConsoleKernel/application factory access seams, and focused command
tests.

Responsibilities:

- Bimaaji reads the completed snapshot through an explicit kernel service;
- routing and public-surface graph sections serialize stable handler IDs and
  declared access without serializing controllers, closures, providers, or
  services;
- strict/tolerant graph failures preserve completeness semantics;
- `graph:dump` and `route:list` use the actual installed ConsoleKernel provider
  cohort and the same snapshot as HTTP;
- keep existing lossless `writeRaw()` output. Do not introduce a generic
  console-output replacement or conflate formatting with composition.

Predecessors: I3 plus #3124, #3117, and #3007 scoped decisions.

This is the first minimal end-to-end consumer repair candidate. It may land
before the other provider migrations only if its installed fixture cohort has
no actual legacy contributor; otherwise it remains red until the necessary
provider cohort below migrates. Never hide legacy routes to manufacture green.

### I5: declarative provider migrations

Each package owns its provider, request-handler service declarations, and tests.
Its full #3122 package-audit entry must exist before source edits.

1. Low-state declarative cohort: `genealogy`, `graphql`, `ssr`, `wayfinding`,
   and `workspace`. Replace hooks/helpers with Foundation values and explicitly
   register class/service handlers. Preserve paths, priorities, access, render,
   CSRF, methods, host/schemes/condition, and optional absence.
2. Config/availability cohort: `mcp`, `debug`, and `api`. Use finalized copied
   declaration/config inputs. Do not resolve workflow, controller, storage, or
   auth services to decide route presence.
3. Captured-execution cohort: `ai-agent` and `admin-surface`. Move factories,
   hosts, static reads, HTML rewriting, and page-builder execution behind
   registered handlers. Coordinate admin static delivery with #3083.
4. Routing auth/OIDC migration is owned in I2/#3125 and is not duplicated here.

Each package proves its own optional-present and optional-absent profile. A
package migration is not a claim that its full convergence audit is complete.

### I6: generated applications and staged legacy fixtures

Owners: `waaseyaa/cli` generator and repository `skeleton`.

Expected files include
`packages/cli/src/Site/Recipe/PublishedContentRecipe.php`, its rendering tests,
`skeleton/src/Provider/AppServiceProvider.php`, and explicit application handler
services/config generated by those sources.

Responsibilities:

- generate metadata contributors and registered handlers instead of closures;
- migrate the source-consumer `InspectableApplicationServiceProvider` only
  after the canonical contributor API exists, preserving its original red
  checkpoint in history;
- retain a separate actual-legacy fixture proving refusal/no invocation;
- add no-route, declarative, stale/unknown, parent, trait, capability, and base
  identity controls from the pending acceptance matrix.

Predecessors: I1-I4. Test migration is staged after production interfaces exist,
never used to erase the before-remediation evidence.

## Exact production scope

Expected packages are exactly these 14 unless implementation evidence expands
the roster: `foundation`, `routing`, `admin-surface`, `ai-agent`, `api`, `debug`,
`genealogy`, `graphql`, `mcp`, `ssr`, `wayfinding`, `workspace`, `cli`, and
`bimaaji`. The repository `skeleton` is an additional generated-application
source, not a split package.

`auth`, `oidc`, `entity`, `access`, and packages implementing Foundation domain
routers are expected unchanged. Reclassify them only when concrete source edits
become necessary, then update ownership and audit scope before editing.

## Verification plan

Commands are planned, not authorized or executed by this document. Use the
candidate-local locked dependencies and record command, exit code, runner, and
exact head. Select only checks justified by the changed crossing.

### Per-slice focused checks

```powershell
php vendor/bin/phpunit --no-coverage packages/foundation/tests/Unit/Discovery
php vendor/bin/phpunit --no-coverage packages/foundation/tests/Unit/Kernel
php vendor/bin/phpunit --no-coverage packages/routing/tests
php vendor/bin/phpunit --no-coverage packages/bimaaji/tests
php vendor/bin/phpunit --no-coverage packages/cli/tests/Unit/Handler/RouteListHandlerTest.php
php bin/check-package-layers
php bin/check-composer-policy
```

Narrow these commands to the changed tests when directories contain unrelated
slow or flaky coverage. Add a command only when a failure or contract crossing
requires it.

### Consumer and installed boundaries

1. Reuse the preserved source-consumer red evidence before remediation unless
   its inputs change. Do not rerun an unchanged red solely for ceremony. After
   the relevant production interfaces and source change, migrate the staged
   fixture and run it to prove the intended green transition.
2. Build clean `symlink=false` consumers from the candidate split manifests for
   supported `core`, `cms`, and `full` profiles as applicable.
3. Through the installed ConsoleKernel, run `route:list` and `graph:dump`; do not
   substitute direct handlers for final acceptance.
4. In a separate process, dispatch HTTP against the same nonempty application
   provider and compare route name, path, host, schemes, condition, methods,
   priority, handler ID, and declared access with both console consumers.
5. Exercise optional package matrices: auth with and without OIDC, MCP public
   and protected metadata settings, debug disabled/enabled, SSR absent/present,
   and the metapackage profiles that actually include each provider.
6. Prove service factories, credentials, sessions, application queries, writes,
   and handlers remain uninvoked during contribution, `route:list`, and
   `graph:dump`, while HTTP invokes the matched handler through its declared
   lifetime.
7. Prove duplicate, invalid condition/value, actual legacy, stale identity,
   recursive, failed, and unsupported-profile refusals are explicit and publish
   no partial snapshot.
8. Build an individual-dependency consumer like FETDER using `waaseyaa/cli` and
   `waaseyaa/bimaaji` directly, without `waaseyaa/framework` metapackage help,
   root namespace patches, or vendor edits. First qualify it against the local
   path/split candidate. Treat eventual published-package qualification for
   FETDER #145 as a later distinct boundary; local candidate success is not a
   claim about a future published release.

### Candidate gates

After focused evidence is green and only once per coherent candidate:

```powershell
php bin/check-pr-preflight
```

Run any split-package, generated-application, random-order, or broader PHPUnit
gate only when the local testing policy or concrete changed surface requires it.
Required hooks and eventual hosted checks remain mandatory. A Windows-only
limitation is recorded and resolved on a supported runner, not waived.

## Review and qualification

Every slice receives independent review against an immutable candidate. Keep
authentication, authorization, persistence, execution, and isolation changes
independently reviewable. Review must check contract preservation, package
layers, source/manifest identity, optional profiles, refusal behavior, snapshot
immutability, and whether evidence distinguishes the target from the legacy
implementation.

Root owns final integration, custody, exact-head qualification, hosted-check
verification, and any later publication request. No agent review substitutes
for human approval, and no passing source test substitutes for installed
ConsoleKernel, split-package, generated-application, or HTTP evidence.

## Program exit criteria

The bounded consumer repair is ready for review only when:

- the preservation checkpoint and predecessor records are durable;
- every changed package has the required scoped audit evidence and every newly
  added package has its #3122 full-audit entry, without false convergence claims;
- canonical HTTP, installed `route:list`, and installed `graph:dump` agree on a
  nonempty application route from one completed immutable snapshot;
- Bimaaji routing/public-surface output is safe and lossless, and CLI retains
  `writeRaw()`;
- no prohibited contribution/inspection work occurs;
- participation identity, mixed compatibility, ordering, duplicates,
  readiness, failure, optional profiles, and staged legacy migration are proven;
- independent review is resolved on the immutable candidate;
- root records focused, installed, generated, split, preflight, and hosted
  evidence required by the actual final diff.

Foundation's remaining package-wide findings, the ten packages' full
convergence work, #2859 lifecycle work beyond this snapshot contract, #3013
full dispatcher parity, #3004 effective access, #3117 broader CLI convergence,
and unrelated defects remain separately owned. They do not become implicit
blockers or implicit implementation authority through this plan.
