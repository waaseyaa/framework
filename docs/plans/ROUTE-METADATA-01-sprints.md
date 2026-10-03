# ROUTE-METADATA-01 sprint backlog

Prepared 2 October 2026. Execution started with RM-01 and the Foundation slice.
Current ticket states and evidence are recorded in
[the execution ledger](../audits/ROUTE-METADATA-01-readiness.md).
No sprint completion or delivery is claimed by this backlog.

## Sprint outcome

Repair Bimaaji graph export by making HTTP, route listing, and graph inspection
consume one completed route metadata authority. Inspection must never construct
execution services or invoke legacy route hooks. FETDER must qualify against
individual installed packages without vendor patches.

This backlog operationalizes the preserved ROUTE-METADATA-01 design and
implementation plan at `d2c47f5d493c4b98018d50de5ac2870cc03ea20f`, on branch
`codex/route-metadata-design-3123`. Those documents remain the architecture
authority. The earlier binding prototype is rejected; its passing tests are
diagnostic evidence only.

## Capacity and ownership

Plan three two-week sprints, each with ten working days. These are capacity
budgets, not completion promises or committed calendar dates. Assume one
implementation owner, one independent reviewer, and a supported Linux
qualification runner. Assign people and review capacity before sprint commitment;
this document does not dispatch agents or authorize parallel delivery.

Use roughly seven days for planned implementation and three for review, repairs,
and qualification. Re-estimate at Sprint 1's readiness checkpoint using the
current code and actual FETDER provider roster. If a prerequisite exceeds the
budget, move dependent tickets together rather than relaxing acceptance.

One integration owner maintains the candidate and evidence ledger. Package owners
own their production files. The reviewer assesses immutable candidates; the
qualification owner binds final results to the exact candidate and dependency
cohort. Unassigned roles remain explicit readiness gaps.

## Sprint 1: establish the route authority

Goal: a tested metadata contract and HTTP composition bridge. A working Bimaaji
command is not this sprint's completion claim.

| Order / ticket | Deliverable and owner | Depends on | Acceptance |
|---|---|---|---|
| 1 / RM-01 | Reconcile the preserved design with current Framework and FETDER; integration owner | None | Record exact bases and lock identities, review current route-hook deltas, name FETDER's active providers, classify the required migration cohort, and resolve contract contradictions. Reuse unchanged audit evidence. |
| 2 / RM-02 | Immutable definitions, handler references, contribution context and snapshot; Foundation, #3123 | RM-01 | Deep scalar/value validation rejects objects, closures, resources and secret-bearing inputs. Definitions preserve route fields and stable source order. Consumers cannot mutate another consumer's view. |
| 3 / RM-03 | Provider participation and composition lifecycle; Foundation, #3123 | RM-02 | Bootstrap validates `none`, `declarative`, and `legacy` participation with transitive provenance. Early, recursive, restricted, stale and unknown access refuse. A failed epoch is terminal and publishes no snapshot. Inspection performs no source discovery or refresh. |
| 4 / RM-04 | Symfony compilation and deferred handler resolution; Routing, #3125 | RM-02, RM-03 | Preserve host, schemes, condition, methods, requirements, defaults, options, priority and insertion-order ties. Reject duplicate names. Resolve explicitly registered handlers only after matching and honor their declared lifetimes. |
| 5 / RM-05 | Built-ins, terminal routes and mixed HTTP compatibility; Foundation with Routing | RM-03, RM-04 | Each provider contributes through exactly one path. Canonical inspection refuses actual legacy providers without invoking them. Legacy HTTP remains explicit; middleware, access checks and request context remain in execution. |

Sprint 1 definition of done:

- Focused positive and refusal tests prove ownership, lifecycle and metadata purity.
- HTTP runs a canonical fixture route and constructs its handler only at execution.
- A mixed-provider fixture neither drops nor duplicates migrated routes.
- Scoped review and required local gates pass, or supported-host evidence gaps
  remain explicit and prevent acceptance. No package-wide convergence is claimed.

## Sprint 2: make the consumer path work

Goal: the actual FETDER-required cohort supports strict graph inspection, route
listing and HTTP from the same definitions in an installed candidate.

| Order / ticket | Deliverable and owner | Depends on | Acceptance |
|---|---|---|---|
| 1 / RM-06 | Migrate the required provider cohort; each provider's package owner | RM-01 through RM-05; scoped audit evidence | Move controller factories, file reads, credential/service lookup and other execution work behind registered handlers. Optional route presence comes from finalized declarations/configuration. Preserve paths, methods and access. |
| 2 / RM-07 | Bimaaji snapshot consumption and truthful graph output; Bimaaji, #3124 / #3121 | RM-05; RM-06 for end-to-end acceptance | Serialize stable handler IDs, never live controller state. Define section selection and unavailable/incomplete output. Construction failures and provider failures are explicit; strict mode exits nonzero. |
| 3 / RM-08 | Canonical route listing and literal JSON transport; CLI, #3117 / #3007 | RM-05, RM-07 | Remove the partial route reconstruction. Preserve formatter-like strings through raw output, stdout/stderr separation and machine-readable JSON. Test the real application boundary. |
| 4 / RM-09 | Declared access projection; Bimaaji with Access contract review, #3004 | RM-07 | Conflicting public/authenticated/session/permission/role/gate flags do not report a misleading public posture. Projection describes transport declarations without changing runtime enforcement or claiming effective access. |
| 5 / RM-10 | FETDER application adoption and installed candidate acceptance; consumer owner | RM-06 through RM-09 | Inventory and migrate every active application route contributor. Install CLI/Bimaaji and the required sibling cohort independently. Nonempty application routes agree across HTTP, route listing and graph output. No full-framework workaround or vendor patch. |

RM-06 is split into package-owned tickets after RM-01 identifies the actual
consumer cohort. Possible Framework migrations are `admin-surface`, `ai-agent`,
`api`, `debug`, `genealogy`, `graphql`, `mcp`, `ssr`, `wayfinding`, and `workspace`;
Routing owns the auth/OIDC adapter. Do not assume all are installed in FETDER.
Each changed package needs the scoped prerequisite review and existing audit
entry specified by #3122. Unrelated package findings do not become sprint scope.

Consumer source edits require the applicable FETDER repository authority and
guidance. Until that boundary is available, RM-10 can qualify a representative
installed fixture but cannot claim FETDER acceptance.

Sprint 2 definition of done:

- Actual installed commands inspect a nonempty application route with correct
  routing and public-surface metadata and deterministic, lossless JSON.
- Poison probes record zero inspection-time service, credential, session, query,
  write and handler calls. An HTTP request executes the expected handler.
- Legacy, missing, malformed and unavailable inputs fail explicitly; tolerant
  output cannot masquerade as a complete graph.
- No public MCP exposure or permission expansion occurs.
- Required consumer migrations are complete and independently reviewed. A cohort
  containing a legacy contributor keeps this sprint's end-to-end acceptance open.

## Sprint 3: qualify distribution and prepare delivery

Goal: an independently reviewed, exact-head candidate with supported installation
and consumer proof, plus a concrete release and recovery handoff.

| Order / ticket | Deliverable and owner | Depends on | Acceptance |
|---|---|---|---|
| 1 / RM-11 | Skeleton and generated-provider adoption; CLI / skeleton owners | RM-05 through RM-08 | Fresh generated applications declare metadata and register execution handlers. Retain a real legacy fixture proving refusal. Generated output does not reintroduce the old coupling. |
| 2 / RM-12 | Installation and compatibility matrix; qualification owner | RM-10, RM-11 | Clean candidate-local, non-symlink consumers prove relevant core/cms/full and individual-package profiles, optional absent/present cases, and HTTP/CLI parity. Check old manifests/cohorts cannot falsely admit the new API. |
| 3 / RM-13 | Immutable candidate review and exact-head gates; reviewer / integration owner | RM-12 | Resolve actionable review findings. Run proportionate focused checks, required hooks, governed preflight and exact-SHA hosted qualification. Supported-runner limitations remain non-pass states. Refresh only evidence invalidated by repairs. |
| 4 / RM-14 | Migration, recovery and release handoff; integration / consumer owners | RM-13 | Document provider adoption, compatibility mode, sibling floors, rollback to the prior coherent cohort, failed-upgrade recovery and residual issue ownership. Published cohort and FETDER verification are separately recorded delivery milestones. |

Sprint 3 definition of done:

- Evidence binds exact source, dependency and artifact identities to each claimed
  source, generated, installed, HTTP and hosted boundary.
- The candidate passes independent review and required qualification.
- Recovery verifies the previous coherent Framework/application cohort can be
  restored; no unsupported in-process boot-profile transition is used.
- The release handoff is actionable. Merge, release, publication and deployment
  follow their established authorization boundaries; a handoff is not a deployment.

## Backlog outside the consumer unblock

Provider migrations absent from the FETDER-required cohort remain owned follow-up
tickets unless another supported installation profile requires them for release.
Their presence in a profile keeps canonical inspection unavailable until migrated;
never drop those routes to make qualification pass. Before promising broad
Framework support, qualify the complete advertised provider roster.

Whole-package convergence, unrelated CLI cleanup, full dispatcher parity (#3013),
effective resource authorization (#3004), broader boot retry/profile convergence
(#2859), and the separate management candidate's stale-verification P1 remain
separate. Waaseyaa remains PHP; this sprint adds no Go runtime or build dependency.
NorthCloud owns its own service fingerprints and qualification.

## Sprint controls

- Start with a ready ticket, named file owner, acceptance discriminator and
  candidate-local dependencies. Do not rediscover credentials for this work.
- Keep one coherent candidate in implementation and one review/repair handoff;
  do not begin a dependent ticket before its contract is stable.
- Review at the midpoint and sprint end: completed acceptance, current evidence,
  remaining legacy contributors, review queue and qualification gaps.
- Record ticket states as ready, in progress, review, qualification or done.
  Done means the ticket's acceptance and review passed, not that files were written.
- Carry unfinished work with its missing acceptance explicitly. Do not close
  #3121/#3122 from a unit test or representative fixture alone.
- Preserve the rejected prototype and earlier red evidence as historical proof;
  neither is production code or release qualification.

## First sprint commitment checkpoint

Begin with RM-01. Its output must identify the smallest complete FETDER cohort,
confirm current design compatibility, name reviewers/runner owners, and re-estimate
RM-02 through RM-05. Commit only the ready Sprint 1 backlog after that checkpoint.
No calendar finish date is established by this draft.
