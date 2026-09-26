---
name: waaseyaa-package-convergence
description: Audit and converge one Waaseyaa package when its purpose, boundaries, dependency structure, public API, contracts, wiring, tests, documentation, or distribution quality need a thorough cleanup. Use for package-charter reviews, the Framework-wide package audit program, and issue-to-PR cleanup programs, not ordinary feature work.
---

# Waaseyaa Package Convergence

Decide what a package is for, prove what it actually does, and bring the two
into agreement. A converged package has one reviewable purpose, intentional
dependencies, truthful public seams, exercised composition, and runtime,
contract, test, documentation and distribution behavior that match.

Read the repository guidance first (`AGENTS.md` or `CLAUDE.md`, then
`docs/governance/agent-contract.md`). This skill is an analysis method and
grants no authority to edit, open PRs, file issues, merge or publish. For
authorized implementation or landing, also use `waaseyaa-delivery`.

## Milestones

Three milestones, each a stronger claim. Report which one a package has
reached; never let a later one's work hold up an earlier one.

1. **Assessed.** We know what the package is, what it does, and what is and
   isn't proven. It doesn't mean every profile passes, and it doesn't need a
   GitHub issue per finding.
2. **Repair ready.** Every finding that needs work sits in a bounded
   remediation slice with acceptance criteria, filed as issues (normally one
   package umbrella with children), and every security item has a filed
   private report. Filing needs publication authority.
3. **Converged.** The repairs and the required qualification have landed
   ("Convergence standard" below).

The coverage index (FW-PACKAGE-CONVERGENCE-01, #3118) records:

- **Audit state:** not assessed, inventory only, in progress, assessed, needs
  delta review. "assessed" is milestone 1.
- **Remediation state:** not triaged, no action required, planned, in progress,
  resolved, accepted residual. It follows bounded work, not dispositions: keep
  "not triaged" until bounded slices exist, even when the audit has
  dispositioned every finding. "planned" means repair ready. "resolved" means
  every package-owned slice has landed.

The index has no "converged" state. State convergence in the audit record's
header, with its evidence.

## Workflow

### 1. Set the boundary

Record the repository, exact base SHA, package path and identity, dependency
lock identity, existing issues and PRs, and what completion the user asked for.
Revalidate current code and consumers instead of trusting an old roadmap or
issue text. Leave neighbouring packages and separately owned issues with their
owners.

When agents run the audit, size the run before starting: lanes, verification
tiers and a budget, from [running an audit](references/audit-orchestration.md).
Start the calibration scorecard at the same time.

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
sealing or deleting anything. Include frontend and generated artifacts when the
package ships them. Apply the relevant rows of the
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

**Symfony reuse.** For substantial custom infrastructure, check whether an
installed Symfony component already owns the generic mechanism while Waaseyaa
keeps its domain policy. Start with installed components and supported
versions, and verify against the component's source, tests and official docs.
Compare semantics, public compatibility, failure behavior, installation
profiles, dependency weight and migration cost; name the Waaseyaa behavior
that remains, the smallest adapter, and the equivalence tests. Record retain,
simplify around Symfony, replace, or defer, with evidence and an owner.
Similar names or fewer lines are not evidence. Don't introduce Symfony just to
remove working code, don't replace authorization or sovereignty policy with a
generic mechanism, and don't make unrelated replacement a consumer-unblock
prerequisite. Audit findings don't authorize replacement work.

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

Keep one ledger using the fields in the
[audit record template](references/audit-record-template.md): stable ID,
evidence and reproduction, evidence level, expected contract, consequence and
affected consumers, severity and confidence, refutation, disposition and
destination, dependencies, discriminating acceptance, residual risk and next
action. One entry per finding. Keep refuted leads in the ledger with their
refutation. State the audit's evidence freshness: current at a named commit,
or needing a delta review because the package changed since the base.

**Attribution.** Every finding records four more fields:

- **discovered in:** the package whose audit found it;
- **owned by:** the package that owns the defect, which may be a different one;
- **affected consumers:** packages and applications that feel it;
- **blocks this assessment:** yes only when this package's charter, profile
  answers or dispositions can't be settled without it.

A finding owned by another package goes in this record's cross-package table
and in the ledger, and becomes intake for the owning package's audit. It does
not enter this package's remediation ledger or its counts.

**Destinations.** Every finding needs an accountable owner or triage
destination, not necessarily a GitHub issue. Valid destinations:

- an existing issue;
- a proposed remediation slice in this record's remediation plan (it becomes
  an issue at the repair-ready milestone);
- the owning package's audit, for cross-package findings;
- a private security report ([security triage](references/security-triage.md));
- a named maintainer decision in this record's decisions list;
- an accepted residual with a rationale.

**Evidence levels** prove different things; never count one as another:

- **inventoried:** listed from source;
- **reviewed:** read and reasoned about;
- **reproduced:** demonstrated by a probe or test (label synthetic fixtures as synthetic);
- **qualified:** exercised in a named installation profile (source, split, no-dev, generated app, native host).

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

Verification effort follows the stakes. The tiers, and how to run them, are in
[running an audit](references/audit-orchestration.md):

- **security findings, medium or higher, and any finding that would remove or
  deprecate public surface or change authority:** two independent verifiers,
  one re-deriving the evidence and one trying to refute it;
- **low:** one verifier doing both;
- **info and documentation gaps:** grouped validation, sampling the group and
  verifying all of it when a sample fails.

Tie-break only when verifiers disagree on whether the finding exists, or on
which side of the medium line it falls. A finding verification raises to
medium or higher gets the second verifier. Check every refuted lead's
refutation once.

Security-sensitive findings follow [security triage](references/security-triage.md)
from the moment they are flagged: the orchestrator writes the private brief
from verified evidence, and the public record carries a safe summary only.

### 8. Finish the audit

A package is **assessed** when:

- every production file appears in the roster with a classification;
- the charter is written and every question is answered or recorded as a finding;
- each selected profile's checklist is answered with evidence, marked "does not
  apply" with a reason, or recorded as a gap with a destination;
- every supported installation profile has an evidence class or a
  dispositioned qualification gap with an owner (see the distribution profile);
- every finding has severity, confidence, attribution, a disposition, a
  destination and a next action, and every refuted lead records its refutation;
- every security finding has a private brief and a named owning package;
- open decisions are listed with who decides;
- the base, audit date, dependency identity and evidence freshness are recorded;
- unreviewed areas and host limitations are listed, with the hosted runner that
  owns the missing evidence.

An untested profile or an unfiled issue doesn't keep an audit open when it is
dispositioned. Anything short of the list is **in progress**. Say so.

## Where the output goes

The repository is the record; GitHub mirrors it. One audit-only change adds:

- `docs/audits/packages/<package>.md`: the human record, from the
  [template](references/audit-record-template.md). Keep it near 400 lines.
  If it can't fit, say why in the record.
- `docs/audits/packages/<package>.ledger.json`: the structured ledger, in the
  shape the template gives. It holds what the record summarizes: the full
  roster, every checklist answer, every finding field, refuted leads, the
  cross-package intake, probe metadata, evidence runs and the scorecard. It
  never holds security specifics.
- The retained probes, under `tests/Fixtures/Audits/<Package>/`. Keep a probe
  that reproduces a package-owned finding at medium or higher, or that the
  proposed acceptance reuses. Other probes stay listed in the ledger with
  their result. A probe that reproduces a security finding is never committed
  before the fix.
- The package's row in `docs/audits/packages/coverage-index.json`
  (FW-PACKAGE-CONVERGENCE-01): state, base, audit date, dependency identity,
  owner and evidence. Evidence is only ever a file committed in this
  repository. Capture a historical file (from a commit on `main`) or an issue
  body byte-for-byte into `docs/audits/packages/evidence/<package>/` with its
  provenance header rather than linking to something that can change or
  disappear. The index test and `bin/check-package-coverage-history` enforce
  this.

Publication is a separate, authorized step: opening the PR, posting a short
summary with a link on the owning issue, filing remediation issues, and filing
private security reports.

Write plainly: short sentences, one entry per finding, no hedging stacks.
Reviewers will read dozens of these.

## Turn findings into work

Reaching **repair ready** means turning the record's remediation plan into
bounded issues: one package umbrella, children for slices, acceptance criteria
in each, and private reports for security items. Separate package convergence
from unrelated product defects, and route cross-package findings to their
owners' audits or existing issues instead of this package's umbrella. When
implementation is authorized, sequence it so later work builds on settled
contracts:

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
