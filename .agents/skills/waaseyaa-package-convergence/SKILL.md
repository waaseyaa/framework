---
name: waaseyaa-package-convergence
description: Audit and converge one Waaseyaa package when its purpose, boundaries, dependencies, public API, contracts, wiring, tests, documentation, or distribution quality need cleanup. Use for package-charter reviews, the Framework-wide package audit program, and remediation of accepted package findings, not ordinary feature work.
---

# Waaseyaa Package Convergence

Decide what a package is for, prove what it actually does, and bring the two
into agreement. A converged package has one reviewable purpose, intentional
dependencies, truthful public seams, exercised composition, and runtime,
contract, test, documentation and distribution behavior that match.

Read the repository guidance first (`AGENTS.md` or `CLAUDE.md`, then
`docs/governance/agent-contract.md`). This skill is an analysis method and
grants no authority to edit, open PRs, file issues, merge or publish. Without
edit authority, draft the audit in the session scratchpad, mirroring the
repository paths, and aim for the assessed milestone in draft; the audit-only
change is a separately authorized step. For authorized implementation or
landing, also use `waaseyaa-delivery`.

## Milestones

Three milestones, each a stronger claim about the package. Report which one it
has reached, and never let a later one's work hold up an earlier one.

1. **Assessed.** We know what the package is, what it does, and what is and
   isn't proven. It doesn't mean every profile passes, and it doesn't need a
   GitHub issue per finding.
2. **Repair ready.** Every package-owned finding that needs work sits in a
   bounded remediation slice with acceptance criteria in the record's
   remediation plan. A slice is filed as an issue before its remediation
   begins, and every remaining finding (deferred, residual or cross-package
   intake) has a bounded issue before the #3118 program's final
   reconciliation. Filing is publication and needs authority.
3. **Converged.** The repairs and the required qualification have landed
   ("Convergence standard" below).

The coverage index (FW-PACKAGE-CONVERGENCE-01, #3118) records two independent
axes, as before:

- **Audit state:** not assessed, inventory only, in progress, assessed, needs
  delta review. "assessed" is milestone 1.
- **Remediation state:** not triaged, no action required, planned, in progress,
  resolved, accepted residual. It tracks filed work: "planned" once at least
  one bounded slice is filed as an issue, "in progress" once one is being
  worked, "resolved" when every package-owned slice has landed, and "no action
  required" or "accepted residual" when no finding needs a slice. Until the
  first slice is filed it stays "not triaged", even when the audit has
  dispositioned every finding. A consumer-driven slice can be planned or in
  progress while the audit is still in progress.

Repair ready and converged are claims in the audit record's header, with
their evidence; the index has no state for either. Rows recorded before v2
keep their states until their next delta review.

## Remediate an accepted finding

Enter here instead of restarting the audit workflow when all of these are
true: the audit record and ledger are committed and current for the affected
surface; a filed issue names the accepted finding and discriminating
acceptance; its ownership, severity, disposition, dependencies and required
decisions are settled; and implementation is authorized. Revalidate the base,
current package delta, dependencies and named consumers before editing. For a
security finding, also load its private brief through the authorized custody
path without copying sensitive details into public records.

Do not repeat package intake, exhaustive inventory, finding discovery,
severity calibration, verification, or audit-only publication merely because
remediation has begun. Consume those accepted artifacts as evidence. Return to
the full workflow below when the finding or its evidence is new, stale,
disputed, or materially changed, when ownership or a required decision is
unsettled, or when the implementation exposes an unassessed boundary.

The minimum remediation output is:

1. a bounded implementation matching the issue acceptance;
2. focused positive and refusal or negative controls at the affected boundary;
3. one risk-based subagent review of the immutable candidate;
4. required hooks and exact-head qualification under `waaseyaa-delivery`;
5. reconciliation of the finding and any changed evidence without rewriting
   unaffected audit history.

Reconcile the audit record, ledger, coverage index, and owning issue after the
repair lands. Mark only the repaired finding or slice resolved. A package is
not converged until every package-owned slice and the convergence standard are
satisfied.

## Workflow

### 1. Set the boundary

Record the repository, exact base SHA, package path and identity, dependency
lock identity, existing issues and PRs, and what completion the user asked for.
Revalidate current code and consumers instead of trusting an old roadmap or
issue text. Leave neighbouring packages and separately owned issues with their
owners.

Collect the **intake**: findings and handed-off leads other audits routed to
this package. Read every committed ledger's `findings` and `handoffs` whose
owner or co-owners name this package, every committed record's cross-package
table (older records have no ledger), and ask the maintainer for private
security briefs routed here. Each intake item keeps its original ID, is
re-verified at the tier of its current severity, and is dispositioned in this
record.

When agents run the audit, size the run before starting: lanes, verification
tiers and a budget, from [running an audit](references/audit-orchestration.md).
Start the calibration scorecard at the same time, and snapshot every checkout
the agents can reach with `scripts/lane-integrity.php` before each run; verify
it after. A run that changed anything outside its output areas, or that the
check can't verify, stops there.

### 2. Write the charter

Answer briefly, from code and current consumers:

- What capability does this package own, and what does it explicitly not own?
- Who composes and consumes it: at runtime, in generated applications, as a split package?
- Which dependencies are required, optional, or boundary adapters?
- Which symbols and wire contracts are public, and what lifecycle does each promise?
- Which installation profiles does it support, and what observable evidence shows it works in source and in each?

If an answer can't be supported, record that as a finding. Do not describe a
cleaner architecture as if it were current fact.

### 3. Inventory everything

Classify **every production file** in the package before judging, moving,
sealing or deleting anything. A production file is any file the split export
ships outside `tests/`: source, manifests, `public-surface.php`, resources,
README and changelog, and frontend or generated artifacts when the package
ships them. Apply the relevant rows of the
[package audit checklist](references/package-audit-checklist.md). Trace
declared public surfaces to real callers and composition roots, and runtime
entrypoints back to contracts, dependencies, failure behavior and tests.

Give each reviewed surface one classification:

- owned and coherent;
- necessary but under-specified;
- duplicated or drifting contract;
- wrong package or wrong layer;
- unwired, unreachable or obsolete;
- optional behavior presented as required, or the reverse;
- missing refusal, lifecycle, compatibility or distribution evidence.

A search with no results is a lead, not proof of dead code. Check dynamic
registration, provider manifests, generated code, reflection, serialization
names, package exports, fixtures, documentation examples and downstream
repositories first.

**Alpha Framework policy.** The maintainer does not require backward
compatibility for obsolete Waaseyaa Framework code during alpha. Public
visibility, `@api`, deprecation, a historical example or a hypothetical external
caller is not a reason to retain a legacy callback, alias, fallback or shim.
Identify current callers and update them to the canonical contract in an
authorized repair. Separate code compatibility from persisted-data integrity
and actual supported integration obligations; this policy does not authorize
data loss, external migrations or unscoped deletion.

Complete the structural review in the initial assessment, before choosing new
domain behavior or beginning repairs. Use the checklist's "Code quality and
cohesion" section to disposition unused paths and state, repeated mechanisms,
competing authorities and adapters that reconcile conflicting contracts. A
file roster, green static checks or dependency graph alone does not answer
these questions. Existing audits missing this evidence need a bounded
structural supplement, not a restarted package audit.

### 4. Pick the profiles

Profiles are focused checklists selected by what the package does. They
extend this shared method; they are not separate per-package workflows. Most
packages need more than one. Record which you applied and why the others don't apply.

| Profile | Use when the package… |
| --- | --- |
| [Kernel and runtime](references/profiles/kernel-runtime.md) | owns composition, provider discovery, service registries or kernel entrypoints |
| [Introspection and CLI](references/profiles/introspection-cli.md) | describes the application, or adapts behavior to commands or AI tools |
| [HTTP, UI and wire contracts](references/profiles/http-ui-contracts.md) | serves routes, middleware or refusals, or shares a payload contract with another language or client |
| [Domain contracts](references/profiles/domain-contracts.md) | owns invariants, lifecycle rules, events or extension seams |
| [Persistence and execution](references/profiles/persistence-execution.md) | writes storage, creates tables, runs jobs, retries, or reports mutation outcomes |
| [Generation and build](references/profiles/generation-build.md) | generates code, scaffolds projects, or builds artifacts |
| [Distribution](references/profiles/distribution.md) | always, for its split-package form; plus metapackages, committed build output and no-dev installs |

### 5. Review the boundaries

**Adapter truthfulness.** Treat every production adapter, wrapper, bridge,
facade, proxy and compatibility layer as a claim about terminal behavior, not
as proof that the behavior exists. Trace a real call from a supported
composition root through the full adapter chain to its terminal implementation.
Verify that the terminal implementation is production-reachable in each
installation profile that claims the capability and that the chain preserves
the promised inputs, fields, authorization and refusal behavior, errors,
mutations, persistence effects, transaction boundaries, retries and lifecycle
transitions that apply. Identify test-only or fake delegates, effective no-ops,
manufactured success, swallowed failures and lossy translation. An adapter that
only relocates coupling, hides an incomplete or defective implementation, or
makes an unavailable capability appear available is a finding.

Prove the chain through a real composition root with at least one positive path
and one applicable refusal or failure path. Assert the terminal side effect or
returned state, and include a discriminator showing that a no-op or fake
delegate could not pass. Mocks can isolate a separate unit contract, but they
do not prove adapter truthfulness.

**Adapter necessity.** For each adapter chain, identify the canonical producer
and consumer contracts, the real mismatch and the semantics each layer adds.
An adapter that translates a required external or maintained-component boundary
may be justified. An internal adapter that preserves obsolete shapes, fills in
missing required data, silently coerces inconsistent values, catches contract
failures or duplicates another authority is a convergence finding even when
its terminal path works. Prefer fixing the producer and consumers around one
contract, then removing the adapter in the authorized repair. Retention needs
a current boundary obligation and evidence, not compatibility speculation.

**Dependencies.** Look past imports: dependence on another package's
internals, initialization order, shared mutable state, provider callbacks,
service-locator lookups, and changes that force coordinated edits across
owners. Trace one real producer/consumer interaction and what happens when it
fails or changes. Use lifecycle and consumer probes for coupling static tools
can't see. Separate necessary composition from accidental coupling; extra
interfaces or wrappers do not by themselves reduce coupling.

For PHP, Deptrac is the dependency authority; don't write another parser.
Adopt it incrementally when repository-wide migration isn't part of the task,
and coordinate the configuration shape with #3075 before adding a new
per-package `deptrac.yaml` (admin-surface has the only one today). Classify
layers from the charter, fail on uncovered dependencies, and seed allowed,
forbidden and uncovered controls plus the clean zero-uncovered case.

Tests assert on Deptrac's JSON report, so terminal detection in CI can't
change the evidence shape. Console output is for human diagnosis only; Mermaid
output is for a committed architecture view. Generated reports and diagrams
are evidence views, never the architectural authority.

Keep existing checks such as `bin/check-package-layers` until a replacement is
accepted. Every exception names the dependency, reason and owner: a removal
condition for a transitional exception, or a maintained invariant and a review
trigger for an intentional long-lived adapter. Refresh generated architecture
views after any production change, including behavioral repairs; a new edge
can change counts or topology even when the intended layering doesn't.

For non-PHP surfaces, use the ecosystem's own mechanical compatibility and
import checks. Prefer exact structural compatibility, schema validation, build
exports and real package installation over prose comparisons or
hand-maintained symbol lists.

**Symfony reuse.** Apply the repository's maintained-infrastructure rule in
`docs/governance/agent-contract.md`. For generic infrastructure, maintained
Symfony components are the default and existing home-rolled equivalents are
replacement candidates, including build and maintenance tooling. Evaluate
suitable components even when they are not installed; verify supported
versions against source, tests and official documentation. Inventory the
custom mechanisms, real callers and overlapping component capabilities using
[the audit checklist](references/package-audit-checklist.md).

Name the domain policy that remains in Waaseyaa, direct use or the smallest
necessary adapter, compatibility and installation impact, and discriminating
equivalence tests. Missing dependencies, fewer lines or existing passing tests
alone do not justify retaining duplicated infrastructure. A retain decision
needs an evidenced capability or compatibility gap, owner and review trigger.
A defer decision needs a bounded migration follow-up with acceptance and a
removal condition. Route verified opportunities into remediation rather than
leaving them as optional observations. Preserve authorization, sovereignty and
lifecycle contracts; don't recreate the component behind a generic wrapper.
Audit findings don't authorize replacement work or unrelated scope expansion.

**Behavior.** Ask of the package:

1. Does every production responsibility belong to the charter?
2. Are large hosts or providers coordinating policy that belongs in smaller seams?
3. Is there one canonical wire contract, with mechanically checked consumers?
4. Do public interfaces have production consumers, stable failure semantics and composition docs?
5. Are optional capabilities discoverable and absent without changing unrelated behavior?
6. Do authorization, mutation fencing, validation and error mapping happen at the real boundary?
7. Do source, split-package, generated-application and committed-distribution forms agree?
8. Can the tests tell the intended architecture apart from today's accidental one?

More interfaces, smaller files or zero Deptrac violations do not by
themselves make a good package. Prefer the smallest boundary set that
expresses real ownership and makes invalid dependencies fail mechanically.

### 6. Record findings

Record every finding in the structured ledger, using the fields in the
[audit record template](references/audit-record-template.md): stable ID,
evidence and reproduction, evidence level, expected contract, consequence and
affected consumers, severity and confidence, refutation, disposition and
destination, dependencies, discriminating acceptance, residual risk and next
action. One entry per finding. Keep refuted leads in the ledger with their
refutation. State the audit's evidence freshness: current at a named commit,
or needing a delta review because the package changed since the base.

**Attribution.** Every finding also records:

- **discovered in:** the package whose audit found it;
- **owned by:** the one package that holds the defective code at the base, in
  the ledger's owner format (a Composer name, `waaseyaa/framework` for the root
  aggregate, CI and tooling, or `external:<repository>`); other packages with a
  share are **co-owners**;
- **blocks this assessment:** yes only when this package's charter, profile
  answers or dispositions can't be settled without it.

When the repairs in two packages are separable, split the finding into one per
owner and cross-link them. When the owner depends on an open decision, name the
decision. A finding owned by another package goes in the cross-package table
and the ledger, and becomes intake for the owning package's audit. It isn't
part of this package's remediation plan or its severity counts.

**Destinations.** Every finding needs an accountable owner or triage
destination, not necessarily a GitHub issue. Valid destinations:

- an existing open issue whose scope you have checked covers the finding;
- a proposed slice in this record's remediation plan, with its acceptance;
- the owning package's audit, for a cross-package finding, when that audit
  hasn't reached assessed; for an assessed package, a delta review, an issue
  or a decision instead;
- a private security report ([security triage](references/security-triage.md));
- a named maintainer decision in this record's decisions list;
- an accepted residual with a rationale.

**Evidence levels** prove different things; never count one as another:

- **inventoried:** listed from source;
- **reviewed:** read and reasoned about;
- **reproduced:** demonstrated by a probe or test (label synthetic fixtures as synthetic);
- **qualified:** exercised in a named installation profile, with evidence in a distribution evidence class other than source (see the [distribution profile](references/profiles/distribution.md)).

A source read, a synthetic probe, an injected-service integration test and a
real installed-consumer test each prove a different boundary. Tie reused
evidence to its source commit, dependency identity and runner. Don't add
overlapping runs together into "unique coverage".

For a consumer-driven, multi-package audit, mark each finding as required for
the consumer unblock, an independent follow-up, or an accepted limitation with a
rationale. Reconcile the producers and consumers of the shared contract
(ownership, readiness, lifetime, failure and completeness semantics, effective
access, installation profiles) before choosing a repair. Record conflicting
contracts and unresolved evidence, and name which uncertainties actually block
a safe repair. Unrelated whole-package
convergence is not a prerequisite for the consumer fix. Add a roster entry for
any extra package the repair touches.

### 7. Verify findings

Verification effort follows the stakes; severity and security decide the tier.
The tiers, and how to run them, are in
[running an audit](references/audit-orchestration.md):

Every verifier, refutation reviewer, tie-break reviewer, and immutable-candidate
reviewer is a subagent role. Keep these roles AI-agnostic: prompts and evidence
must not depend on a named model, vendor, or model-specific skill. The
orchestrator supplies the immutable source identity and evidence, reconciles
the result, and never substitutes its own second pass for an independent
subagent verdict. If review delegation is unavailable or unauthorized, record
the required review as pending.

- **tier A, for security-sensitive findings, medium or higher, and any finding
  that would remove or deprecate public surface or change who may read or
  mutate data:** two independent verifiers, one re-deriving the evidence and
  one trying to refute it;
- **tier B, for low findings:** one verifier doing both;
- **tier C, for info findings:** grouped validation, sampling the group and
  verifying every claim when a sample fails.

Tie-break any material disagreement between tier-A verifiers: whether the
finding exists, whether it is security-sensitive, its owner, its disposition,
or a severity difference that crosses the medium line or changes the finding's
tier, disposition or destination. A low-versus-info split that changes none of
those isn't material; take the lower band and say so. What a tie-break can't settle becomes a maintainer
decision. A finding verification raises to medium or higher gets the second
verifier; a finding created after verification gets its tier's verification
too. Check every refuted lead's refutation once.

Security-sensitive findings follow [security triage](references/security-triage.md)
from the moment they are flagged: the orchestrator writes the private brief
from verified evidence, and the public record carries a safe summary only.

### 8. Finish the audit

A package is **assessed** when:

- every production file appears in the roster with a classification;
- structural review explicitly dispositions unused paths/state, duplicated
  logic/authorities and adapter necessity with evidence or named gaps;
- the charter is written and every question is answered or recorded as a finding;
- each selected profile's checklist is answered with evidence, marked "does not
  apply" with a reason, or recorded as a gap with a destination;
- every supported installation profile has qualifying evidence or a recorded
  qualification gap with an owner (see the distribution profile);
- every finding has severity, confidence, attribution, a disposition, a
  destination and a next action, has had its tier's verification, and every
  refuted lead records its refutation;
- no finding or handed-off lead that blocks this assessment is open: each is
  settled, or is a maintainer decision;
- every intake item is dispositioned;
- every security finding is verified at tier A and has a private brief in
  durable custody, a named owning package and the advisory route;
- open decisions, unresolved uncertainties and lane conflicts are listed with
  what would settle them;
- the base, audit date, dependency identity and evidence freshness are recorded;
- unreviewed areas and host limitations are listed, with the hosted runner that
  owns the missing evidence.

An untested profile or an unfiled issue doesn't keep an audit open when it is
dispositioned. Anything short of the list is **in progress**. Say so.

## Where the output goes

The repository is the record; GitHub mirrors it. One audit-only change adds:

- `docs/audits/packages/<package>.md`: the human record, from the
  [template](references/audit-record-template.md). Keep it near 400 lines;
  past that, state why and get the maintainer's agreement.
- `docs/audits/packages/<package>.ledger.json`: the structured ledger, in the
  shape and with the keys the template gives. It holds what the record
  summarizes: the full roster, every checklist answer, every finding and its
  fields, intake, handoffs, refuted leads, decisions, uncertainties, probe
  metadata, evidence runs and the scorecard. It never holds security
  specifics. `bin/lib/package-audit-ledger.php` validates it, and
  `tests/Architecture/PackageAuditLedgerTest.php` enforces it for every
  committed ledger; run the validator on the draft before it leaves scratch.
- Retained probes: the smallest probe for each non-security finding at medium
  or higher (package-owned or cross-package), plus any probe an acceptance
  criterion cites. Use a flat layout,
  `tests/Fixtures/Audits/<Package>/<FindingID>-<slug>.php`. Each runs from the
  repository at the base, states its expected exit status, and uses no local
  consumer paths. Every other probe stays in the ledger with its purpose,
  result and a reproduction description, and the finding's evidence level
  reads "reproduced (probe not retained)"; the repair's failing regression
  test becomes its durable evidence. A probe that reproduces a security
  finding stays with the private brief until the fix lands. Retained probes
  are scanned like any test file (SQLite construction rosters, subprocess and
  wait contracts), so prefer the framework's test database utilities.
- The package's row in `docs/audits/packages/coverage-index.json`
  (FW-PACKAGE-CONVERGENCE-01): state, base, audit date, dependency identity,
  owner, evidence and notes. `owner_issue` is the program issue #3118 until a
  package umbrella is filed. Evidence lists the record, the ledger and each
  retained probe, and is only ever a file committed in this repository.
  Capture a historical file (from a commit on `main`) or an issue body
  byte-for-byte into `docs/audits/packages/evidence/<package>/` with its
  provenance header rather than linking to something that can change or
  disappear; hosted runs are cited by run and job ID instead. Notes are at most
  two sentences. The index test and `bin/check-package-coverage-history`
  enforce this.

Before the audit-only change, run `php bin/check-pr-preflight` and, when
retained probes change a governed roster, `php bin/refresh-governance-artifacts`.

Publication is a separate, authorized step: opening the PR, posting a short
summary with a link on the owning issue, filing remediation issues, and filing
private security reports.

Write plainly: short sentences, one entry per finding, no hedging stacks.
Reviewers will read dozens of these.

## Turn findings into work

**Repair ready** means the record's remediation plan bounds every
package-owned finding that needs work into slices with acceptance criteria.
File a slice as an issue (normally one package umbrella with children) before
its remediation begins, and file every remaining finding before the program's
final reconciliation. Separate package convergence from unrelated product
defects, and route cross-package findings to their owners' audits or existing
issues instead of this package's umbrella. When implementation is authorized,
sequence it so later work builds on settled contracts:

1. durable charter and change record;
2. dependency model and discriminating controls;
3. canonical contract convergence;
4. behavioral and failure-boundary repairs;
5. public-surface and documentation reconciliation;
6. source, packaged, generated and distribution qualification;
7. immutable-candidate review, hosted checks and authorized landing.

This is a dependency order, not one large PR. Split where review, ownership or
risk improves. Run governance scanners after changing test harnesses as well
as production code: test-only database construction, service composition,
generated manifests and dependency setup can still enter construction rosters
or other governed authorities. A scanner finding is an ownership signal, not permission
to suppress it. Keep the parent issue open until its full acceptance is proven.

## Convergence standard

Claim a package is **converged** only with:

- a committed charter matching observed ownership;
- an exhaustive, reviewed dependency classification of the scoped production code;
- a disposition for every public surface and duplicated contract;
- no unexplained unwired production code;
- discriminating behavior and refusal evidence at real boundaries;
- verified source and relevant distributed forms;
- residual work linked to bounded issues;
- exact-candidate and hosted qualification evidence.

Report partial slices as partial. A green dependency graph alone proves
nothing about correctness, usability or distribution.

## Host limits

Native Windows can't run some checks: symlink fixtures, POSIX-only `bin/`
gates and hooks, and file-permission teardown in temp directories (#3096,
#3081). Record them as hosted-Linux evidence. Don't count them as passes, and
don't re-investigate them as new defects.
