# Architecture integrity synthesis

## Verdict

The framework has a coherent package/layer model and unusually strong local gates,
but it is **not yet architecture-integrity qualified**. The audit found recurring
failures at composition and lifecycle boundaries: metadata that is not enforced by
storage, tests that reset or hand-wire state production owns, early acknowledgement
before durable effects, multiple descriptions of schema truth, and exception paths
that intentionally degrade into silence without the production logger.

This is not a recommendation for a framework rewrite. Most domain implementations
are retained. Remediation should replace a small number of ownership and transition
seams, then retire the superseded fallback/test-only paths.

## Coverage and limits

- Frozen denominator: 7,818 tracked paths, 77 packages, 2,515 package-source PHP
  files, 687 public-map declarations and 194 executable/support paths at
  `50750231a8036ae7afc68416fed8ea271e47159f`.
- Every package/path has one A1–A6 owner. Optional packages and the JavaScript admin
  surface were included; no application dependency closure narrowed scope.
- Program tracking contains 53 issues: 49 open and 4 closed at synthesis time. Open
  work includes 25 P1, 15 P2 and 1 P3 item; eight inherited owners have no priority.
- Bounded evidence exercised Windows and Linux, sql-blob and supported sql-column
  shapes, fresh/upgrade/replay, HTTP/tool/worker/provider paths, installed split
  consumers and deployer handoff. Each issue owns exact SHA/runner evidence.
- No Pi, process-kill-at-every-deploy-phase, complete frontend rebuild/E2E run, or
  complete production OIDC HTTP lifecycle was certified. These remain explicit
  assurance gaps, not implied passes.

## Root-cause clusters

### R1 — Semantic invariants lack one physical enforcement owner

Examples: driver identity #2670, messaging membership #2753, path canonicality
#2754, menu lock #2755, engagement targets #2756, group membership #2762 and OIDC
client identity #2766. Setters, documentation or lookup assumptions describe an
invariant while generic persistence can bypass it.

Disposition: **replace** query-order/setter-only enforcement with declared storage
constraints and canonical mutation-boundary validation. Keep the entities and
repository API.

### R2 — Production composition differs from the tested composition

Examples: entity type manager wiring #2729, attachment repository resolution #2760,
GraphQL process-global schema identity #2764, vector discovery #1606 and the retired
telemetry shadow path #1860. Tests manually inject collaborators or reset global
state that production never injects/resets.

Disposition: **replace** hand-wired/test-reset assumptions with kernel-owned
composition and sequential-composition tests. **Remove** shadow components only
after their canonical replacement is proven; #1860 is the positive precedent.

### R3 — Success/acknowledgement occurs before durable outcome ownership

Examples: nested UnitOfWork effects #2734, queue handler/claim/retry #2740/#2741/
#2743, notification routing #2745, broadcast retained writes #2747 and billing
webhook claims #2750. A local step is treated as final before the outer transaction,
lease, delivery or effect is final.

Disposition: **replace** local completion with fenced, durable outcome state and
idempotent settlement. Keep transports and domain handlers; do not add compensating
success wrappers.

### R4 — Schema truth is described by competing interpreters

Examples: comparator vocabulary #1625/#2682, replay/rollback authority #2730/#2731,
preview reporting #2732, provider boot DDL #2761 and deployer schema handoff #2548.
Compiler, materializer, diagnostics, migration ledger and deployer signatures do not
always share one transition model.

Disposition: **replace/consolidate** duplicated interpretation behind one canonical
schema model and transition evaluator. **Remove** production-boot repair and
independent text-normalization paths after lifecycle proof.

### R5 — Optional adapters fork shared policy or hide failure

Examples: success-shaped null LLM #1608, AI-tool admission bypass #2737, malformed
beacon self-targeting #2746, leaf HTTP clients #2751, media upload authority #2759
and search projection silence #2763. Narrow adapters locally recreate validation,
transport, configuration or error semantics.

Disposition: **consolidate** on shared transport/policy/result types. Retain explicit
offline/null construction only when absence is a typed non-success outcome.

### R6 — External identity is collapsed to a lossy local key

Examples: deployer user merge #2549, media sidecar paths #2758, OIDC client identity
#2766 and driver address/bag identity #2670.

Disposition: **replace** lossy path/numeric/query-order identity with stable identity
plus collision refusal and migration evidence. Keep compatibility readers only with
a time-bounded retirement condition.

### R7 — In-process atomicity is mistaken for crash recovery

Examples: deployer relational handoff #2548/#2549 and unjournaled activation #2765.
Exception rollback is real, but it does not prove recovery by a new process after a
kill or host power loss.

Disposition: **replace or explicitly delegate** the activation protocol after a
durability design decision. Directory-entry durability and restart ownership are
part of the contract.

### R8 — Green evidence does not always execute the distributed consumer contract

Examples: installed testing bases and guides under A6, deletion safety #2055,
database-legacy retirement #1588 and timing flake #2716. Root-monorepo autoload,
test-only mappings, reset helpers and aggregate shell status can mask false accepts
or consumer failures.

Disposition: **keep** useful conformance intent and gates; **replace** stale examples,
optional no-op assurance hooks and masked runners. **Defer deletion** until installed
artifacts and external consumers migrate.

### R9 — Runtime configuration and observability have bypass paths

Environment/secret ownership remains #2479. Across search, GraphQL, SSR and node,
providers omit the canonical logger when constructing collaborators that catch or
downgrade failures.

Disposition: **consolidate** configuration under RuntimePolicy/config authorities and
require canonical logger wiring for every production failure-catching collaborator.

## Keep / remove / replace / defer

| Decision | Scope |
|---|---|
| Keep | Package/layer boundaries; canonical repository; explicit-grant access; audited privileged reads; migration-owned schema requirements; admin bundle integrity gates; per-request GraphQL execution context; Inertia `finally` cleanup; bounded installer refusal/rollback controls |
| Remove after replacement | Geo no-op provider #2752; retired/shadow telemetry precedent #1860; production boot DDL #2761; duplicate adapter transport/policy paths #2751/#2759; stale first-party test assumptions and optional no-op assurance hooks under A6 |
| Replace/consolidate | Issues #2670, #2729–#2734, #2737, #2740–#2751, #2753–#2766 plus existing schema/deployer owners #1625/#2548/#2549/#2682 |
| Defer with trigger | database-legacy #1588 and dormant storage seams #2055 until consumer migration proof; timing flake #2716 until deterministic transport fixture; unsupported storage/profile cells until an explicit support decision |

No code path is deleted merely because current applications do not call it.
