# FW-BIMAAJI-ROUTE-WIRING-01

Status: **rejected prototype, not an accepted repair or release candidate**.
Reconciliation identified the existing ROUTE-METADATA-01 authority preserved at
d2c47f5d493c4b98018d50de5ac2870cc03ea20f in the route-metadata-design-3123
worktree. Its design explicitly forbids invoking legacy route hooks and cloning
their output for inspection. The prototype below violates that boundary even
though it repairs the missing binding. Do not publish or deploy it.

The strict ConsoleKernel regression reproduced the exact missing-binding failure
before production edits. The prototype passed 51 focused tests / 210 assertions,
but those tests do not establish metadata purity, immutable nested values,
terminal failed epochs, or installed consumer qualification. Default preflight
failed seven gates; no qualification pass is claimed. No commit, push, merge,
release, deployment, or external issue mutation occurred.

Existing owners: #3121 consumer defect, #3122 workstream, #3123 Foundation design,
#3124 Bimaaji, #3125 Routing, #3117 CLI, and #3007 route listing. The preserved
architecture plan starts with A0 audit readiness then I1-I6 in dependency order.
The separate management candidate P1 is outside this route repair.

Prototype patch and regression source are retained at
C:/Users/jones/Documents/Codex/2026-10-02/bimaaji-route-wiring/ for diagnosis.
The following design is historical rejected work, not the current architecture.

Repair strict CLI graph export's missing RouteCollection/WaaseyaaRouter bus binding.
Base: 325c405a6 (alpha.303 or newer framework source). Owner: Codex integration lane.
Candidate: C:/dev/waaseyaa/framework-worktrees/bimaaji-route-wiring,
branch codex/bimaaji-route-wiring. The worktree coordinator rejects native Windows
paths; this retained isolated checkout is recorded here without a lease.

## Design and acceptance

Foundation's provider service bus lazily composes a route snapshot through the
existing BuiltinRouteRegistrar, including active application provider routes.
Symfony RouteCollection and WaaseyaaRouter remain the route authorities. Explicit
provider bindings retain precedence. No HTTP request context is reused, no new
runtime dependencies are introduced, and failed registration cannot cache a partial
table. This snapshot is intended for post-boot introspection, not HTTP dispatch.

Owned files: provider service bus, focused bus tests, real ConsoleKernel graph
export integration test, Bimaaji spec, and release fragment. Other management
candidate defects, release, deployment, and whole-package convergence are outside
this repair.

Capture regression failures before implementation. Verify lazy route assembly,
shared collection/router identity, provider routes/access metadata, binding
precedence, failed-registration retry and deterministic strict graph export through
real CLI boot. Run focused Foundation/Bimaaji caller checks and default preflight.
Broaden only for an unexplained shared bootstrap regression. Codex owns local
integration qualification; independent review remains pending and hosted full
qualification remains owned by ci/full-qualification. No release acceptance is
claimed from native Windows results.
