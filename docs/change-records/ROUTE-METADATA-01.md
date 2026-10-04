# ROUTE-METADATA-01: immutable route metadata composition

- Forge mirrors: Framework #3123, package audit program #3122
- Coupling-audit evidence: #3122 comment `issuecomment-5755224083`, finding
  CS-01 (supplemental forge locator; this record remains the durable authority)
- Baseline: `5196f173b06bac698f14d292c7b827f2ddac388f`
- Status: **reviewed source implementation; release and consumer deployment qualification in progress**
- Enduring contract: `docs/specs/route-metadata.md`

## Problem

Foundation's HTTP registrar builds a live Symfony router and invokes arbitrary
`ServiceProvider::routes()` hooks. CLI builds a different incomplete table and
Bimaaji cannot obtain the completed application routes. Several hooks resolve
execution services, construct controllers, read files, or capture providers and
closures. A clone of the resulting collection still shares nested controller
objects. Executing those hooks and stripping values afterward cannot produce a
pure metadata contract.

## Decision

Adopt the proposed route metadata contract: Foundation-owned layer-neutral
definitions and completed snapshot, Routing compilation and request-local
handler resolution, stable deterministic ordering, hard duplicate refusal,
explicit lifecycle readiness, and no automatic legacy-hook adapter.

The design preserves route matching and declared access semantics. It does not
claim full dispatcher or effective access parity. Process-static live references
are forbidden. Capability presence is declaration-time metadata and is distinct
from execution-service health.

During the compatibility window, legacy-default HTTP dispatch selects one path
per provider per composition epoch from the compiled participation inventory:
`none` does no route work, `declarative` compiles pure metadata, and `legacy`
invokes the old hook. It never runs two paths. Canonical metadata consumers
refuse actual `legacy` contributors, but ordinary providers inheriting the base
no-op do not block publication. The versioned classification-input identity
binds provider ancestry, traits, effective method provenance, capability
interface/implementation, and Foundation's base no-op as applicable. Missing,
unknown, stale, or identity-mismatched inventory refuses readiness and requires
separate manifest compilation. Contribution compares only the bootstrap-
validated token and records, never source files.

## Work packages

1. **Consumer red evidence.** Add the failing source-consumer acceptance
   test described by the spec before production remediation. It must include a
   nonempty app route and poison execution dependencies.
2. **Foundation contract and lifecycle.** Add immutable values, contributor
   capability, compiler-owned `none | declarative | legacy` inventory with
   source/code identity, collection state machine, completed snapshot, built-in
   and terminal contributions, and explicit legacy incompatibility.
3. **Routing compiler and resolver.** Compile snapshots, preserve ordering and
   access options, validate handler IDs, and resolve service/class handlers per
   request without inspection-time loading.
4. **Provider migrations.** Convert every Framework hook in the audit roster.
   Keep package-specific execution wiring outside metadata contribution.
5. **Consumers.** Make HttpKernel, CLI route listing, and Bimaaji routing
   introspection consume the same snapshot. Preserve `writeRaw()` for lossless
   CLI transport; formatting transport itself is not the defect.
6. **Qualification.** Prove source and relevant split, generated, and installed
   forms. Bind evidence to an immutable candidate and retain required hosted
   gates.

The first test profile is a source-consumer regression: a temporary application
adds one provider to the candidate-local locked Framework cohort, dispatches a
real HTTP subprocess through `HttpKernel`, and separately uses the current
registrar plus Bimaaji `RoutingIntrospectionProvider` directly. HTTP passes one
test with 11 assertions. Inspection proves boot counter zero, a nonempty route,
the exact path and method, and handler invocation zero, then fails only because
route inspection constructs one execution service instead of zero. Combined:
two tests, 22 assertions, one expected failure. This is not the Console
`graph:dump` command, a packaged or split install, or future contributor-protocol
qualification.

## Package change roster

Production changes are expected in:

- `foundation`: protocol, lifecycle, built-ins, snapshot ownership, HttpKernel;
- `routing`: metadata compiler, handler validation/resolution, auth/OIDC adapter;
- `admin-surface`: declarative routes; static delivery and host construction at
  execution, coordinated with #3083;
- `ai-agent`: replace provider-capturing closures with service handlers;
- `api`: replace workflow service resolution with declared capability presence;
- `debug`: replace controller construction with a handler ID and config input;
- `genealogy`, `graphql`, `mcp`, `ssr`, `wayfinding`, `workspace`: opt into the
  new contributor contract even where existing hooks are otherwise declarative;
- `cli`: migrate generated `PublishedContentServiceProvider`, consume snapshot
  for `route:list`, and preserve existing raw output behavior;
- `bimaaji`: inspect the canonical snapshot and serialize handler IDs safely.

The non-package `skeleton` application template also changes from a closure
handler to an explicitly registered application service handler. It is a
generated-application qualification target, not an added package audit.

The apparently declarative providers must change because the design cannot
automatically invoke or bless the old arbitrary-PHP hook. Their changes are
contract adoption and qualification, not evidence of a current side effect.

No production change is currently required in `auth` or `oidc`: Routing owns
their route adapter. Their installed absence/presence and request-local service
wiring require acceptance evidence. `entity`, `access`, controller-owning
packages reached by built-in sentinels, and Symfony remain runtime dependencies
or downstream enforcement owners, not route metadata producers. Add them to the
change roster only if implementation changes their production code.

The participation correction does not add packages to this 14-package change
roster. It changes Foundation manifest/compiler and registrar responsibilities
already listed above. The future fixture matrix must cover no-route plus pure,
actual legacy, and stale/unknown inventory controls, including an unchanged leaf
provider with changed parent, trait, capability, or Foundation base inputs,
before remediation can be qualified.

## Audit additions under #3122

Before changing production in a listed package, add it to #3122's full package
audit roster. The scoped route audit records route ownership, declaration
inputs, handler wiring, optional-capability semantics, access metadata, and
installed profiles as initial evidence only. It does not substitute for the
package convergence checklist. This adds `admin-surface`, `ai-agent`, `api`,
`debug`, `genealogy`, `graphql`, `mcp`, `ssr`, `wayfinding`, and `workspace` to
the existing Foundation, Routing, Bimaaji, and CLI audit roster. None is
declared converged by this record.

Retain #3125 for the auth/OIDC adapter, #3083 for admin static delivery, #3007
for CLI route composition, #3004 for declared access posture, #3013 for domain
router dispatch, #1866 for the legacy signature, and #2859 for broader kernel
lifecycle behavior.

## Qualification limits

The first acceptance proves the consumer route-table crossing and the absence
of prohibited calls within contribution and inspection. It does not qualify all
Foundation behavior, arbitrary downstream hooks, full domain-router dispatch,
or effective resource authorization. The known failed-boot and boot-profile
transition findings remain independent unless the snapshot lifecycle needs a
specific guarantee to publish safely.

## Descope

Audit terminal preparation at base 135acaf17124a12fa6d9facb50897c32131ec2b1 shares the router's public index adapter between legacy and explicit dispatch. Its nonshared binding preserves optional absence and refuses unhealthy declared audit adapters. Focused parity and failure controls are source preparation; full API declarations and installed CLI/Bimaaji admission remain open.

No broad package remediation, release, deployment, process-static cache,
controller autowiring redesign, or whole-package convergence claim is part of
this design candidate.
MCP terminal preparation at base 5bdc65440bee02dc62ab907d25132445275dc85f extracts shared admin/approval request actions and binds their routers nonshared. Approval store resolution remains per-action, after validation, preserving sanitized 503 and separation of duties. This is local source preparation for RM-06; complete API and installed graph-export acceptance remain open.
Content-search terminal preparation at base d69aa24ae2cf25a884fe75ed88f12f9313c92870 adds a nonshared router binding using the existing public handler and one shared provider factory. Context-first validation, optional-service 503 and configured rate limiting remain inside request execution. Full metadata admission and installed graph-export acceptance remain open.
Workflow terminal preparation at base 230ba07a9b6ab69e0ea3ce91c034482d72db24d3 adds shared public router actions and nonshared explicit construction. Request parameter authority, opaque access refusals and domain mutation behavior remain. Required and unhealthy declared optional dependencies refuse selection; legacy route inclusion remains pending migration. This does not complete installed graph-export acceptance.
Discovery/OIDC-client preparation at base f0025bf79efd16f07da2a8704735560c7eaa261d shares existing request actions and binds routers nonshared. Discovery keeps kernel cache/access authority through the existing late HTTP hook. OIDC mutation and secret behavior stays controller-owned. The contract roster now includes the already-guarded metadata capability, and prior workflow/MCP controls directly exercise conflicting callable IDs. Installed graph-export acceptance remains open.

ROUTE-METADATA-01 Foundation API terminal preparation: explicit nonshared provider bindings preserve JSON:API/translation request authority, access/exposure/internal visibility, mutation fences and existing document headers. Schema show reuses its legacy action and canonical registry; schema authority absence composes over the boot-scoped field-type registry, never the static default. Workflow list shares its legacy payload and allows absent optional model; unhealthy selected dependencies refuse. Full API metadata admission and installed qualification remain pending.

ROUTE-METADATA-01 field autosave preparation: an explicit nonshared request adapter supplies the matched _entity_type/id/key attributes to the unchanged controller. Required kernel manager/access/field registry services fail closed on selection. The adapter retains media/body validation, working-copy mutation fencing, field/access checks, sanitization and representation behavior; named callable arguments cannot replace matched parameters. This preparatory binding adds no routes; complete API metadata admission and installed strict graph proof remain pending.

ROUTE-METADATA-01 lazy HTTP execution: selected declarative closures use ControllerDispatcher directly with the existing optional Inertia renderer, avoiding construction of unused legacy domain routers. Builtin string and legacy selections retain the router chain. Finalized input custody is rechecked after both selected factory and renderer/legacy construction, so caught publication failure still refuses before handler execution. No fallback or route admission change is introduced.


ROUTE-METADATA-01 frozen API availability prerequisite: the existing neutral boot publication now copies boolean api.route.* facts with exposure, validates their namespace and types, and exposes them only after freeze. Duplicate, malformed and late publication poisons both views. API publishes only after its existing successful install gates and catalog construction, adding no probes or service resolutions. The kernel projects copied facts with existing service-presence inputs into snapshot identity. Optional publish arguments preserve callers; absent API supplies no API route facts. Full API admission and installed strict Bimaaji proof remain open.


ROUTE-METADATA-01 complete API admission: ApiServiceProvider contributes one pure table from finalized copied exposure, api.route availability and declared service presence. Fixed API rows and JsonApiRouteProvider structural rows share the same authority with bare compatibility replay through RouteMetadataCompiler. Canonical declarations identify explicitly bound, nonshared request adapters; no controller or domain-router construction occurs during inspection. The owned JSON structural generator is loaded during register, before cold reads. Canonical workflow inclusion uses declared TransitionService binding presence; an unhealthy selected dependency refuses execution rather than silently withdrawing routes. Bare compatibility retains its prior healthy-resolution gate and semantic controller/alias defaults until legacy callers migrate. OIDC admin inclusion uses copied entity presence. MCP permission/session/CSRF, retention roles/defaults, priorities and hidden opaque404 behavior remain unchanged. The stateless hidden handler accepts optional forwarded request/path arguments without service reads. Removal condition for semantic adapter mapping and bare replay is completion of legacy API callers under RM-06; owner waaseyaa/api. Admin Surface/FETDER producers and CLI/Bimaaji/installed strict export remain open.


ROUTE-METADATA-01 Admin Surface admission: one table declares core, optional page-builder and SPA routes from copied binding presence and preloaded path authority. Explicit nonshared core/page-builder/SPA request handlers preserve existing gates, transport/status rules, principal/body forwarding and cookie rewrite. Host construction, package probes and SPA file reads occur only during selected execution. Canonical unhealthy declared host dependencies refuse; bare compatibility retains its healthy optional-host gate and projects the same table with the same handlers. Custom host factory lifetime is per selected construction, with factory-owned reuse and legacy once-at-registration behavior retained. Deptrac classifies the three handlers in existing Delivery and composition; generated dependency view is refreshed. Source admission does not complete FETDER/CLI/Bimaaji or installed strict export qualification.


ROUTE-METADATA-01 CLI/Bimaaji canonical wiring slice. Base80aa9ef882f6e269c3d2e458ba7839c4d19422b7. Root owns Foundation bus accessor and registry handoff, CLI route:list, Bimaaji route sections/generator/handler, tests and coupled docs. One optional lazy kernel accessor returns completed RouteSnapshot with live custody checks. RouteMetadataCompiler remains the Symfony projection owner. Bimaaji section accessors are evaluated at provide(), not cached during construction; final authority callback checks after all sections, outside soft section-failure handling. CLI uses the same snapshot. Bare explicit collection/router or builtin-list compatibility stays bounded to absent kernel context; no fallback after refusal. Graph output uses writeRaw. Source tests exercise completed and legacy-refused real kernel profiles, cached provider refusal, late final custody and JSON:API defaults. No dependency/lock changes. Required FETDER producer and installed distribution qualification remain open, together with full package audits, broader CLI/access work and supported hosted Linux proof. No publication/deployment.


## Publication integration, 4 October 2026

Russell authorized publication, FETDER adoption and deployment with "publish it".
The release candidate integrates published alpha.304 main 184ab7d6 into the
reviewed route repair 612e41e7. Composer resolutions preserve added dependencies
at the current alpha.304 floor; root lock metadata and canonical SQLite dependency
byte authority are refreshed. Independent integration review approved 3832c97bb.
Focused consumer/kernel tests pass 122 tests / 508 assertions. Integrated FETDER
main f4a2f01 preserves its current 173 routes and admin consolidation; independent
review approved source checkpoint 332a2ef and source-profile checks passed.

The first complete main-relative default preflight ran 43 gates and found one
coverage-companion gap: route value/epoch tests incorrectly declared CoversNothing.
Their existing public-boundary assertions now declare the classes they exercise.
The affected 57 tests / 131 assertions pass and the companion gate passes. No
production behavior changes in this qualification repair. Hosted exact-head proof,
release-cut, published installation and live export are pending at this checkpoint.
Whole-package audits and unrelated kernel/profile/domain-router residuals remain.

Hosted candidate 8d9461c, run 37205279482, passed behavior, coverage, random-order,
browser and native-host owners. Two root failures prevented publication: the
installation smoke used callable-controller and method-only matching assumptions,
and PHPStan's dead-code collector crashed on the resolver's dynamic callable array.
The smoke now supplies real requests and dispatches selected Admin Surface handlers
through the HTTP terminal. The resolver reuses its validated ReflectionMethod to
produce the same bound closure, retaining public/callable refusal checks.

The existing ShipMonk member-usage extension API and PHPStan constant-string
inference supply deferred metadata handler edges, including table declarations.
This is minimal Waaseyaa identifier policy, not a new parser or analysis engine.
Edges retain their actual declaring caller; unknown strings, builtin targets and
unrelated factories do not preserve arbitrary methods. Two existing downstream
JSON:API bare-router compatibility methods explicitly retain their public @api
status. No dead-code baseline, exclusion or release gate is weakened. The native
full installation diagnostic retains all seven optional route-presence checks.

Hosted repair run 37206985396 passed both root repairs and all behavioral
assertions, but the coverage shard refused the new tooling test's CoversClass
target: tools/phpstan is outside the configured packages/*/src product coverage
scope. The architecture tooling test now truthfully declares CoversNothing,
following the existing tooling-test convention. Product coverage targets and
the changed route metadata value/epoch companion declarations remain intact.

Release documentation reconciliation updates the enduring contract status,
describes the lazy completed snapshot dependencies in Bimaaji's README, and
records the old ConsoleKernel gap as historical. The bounded graph:dump fix
has its own #3121 release fragment. Historical source checkpoints and broader
package/MCP/domain-router qualification residuals remain explicit.
