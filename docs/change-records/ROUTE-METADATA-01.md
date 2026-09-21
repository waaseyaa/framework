# ROUTE-METADATA-01: immutable route metadata composition

- Forge mirrors: Framework #3123, package audit program #3122
- Coupling-audit evidence: #3122 comment `issuecomment-5755224083`, finding
  CS-01 (supplemental forge locator; this record remains the durable authority)
- Baseline: `5196f173b06bac698f14d292c7b827f2ddac388f`
- Status: **proposed design and audited migration roster; no runtime remediation**
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

No broad package remediation, release, deployment, process-static cache,
controller autowiring redesign, or whole-package convergence claim is part of
this design candidate.
