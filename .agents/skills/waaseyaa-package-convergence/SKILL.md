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
grants no authority to edit, open PRs, merge or publish. For authorized
implementation or landing, also use `waaseyaa-delivery`.

## States

Keep audit and remediation separate. Use the Framework program (#3118) states:

- **Audit:** not assessed, inventory only, in progress, assessed, needs delta review.
- **Remediation:** not triaged, no action required, planned, in progress, resolved, accepted residual.

A package can be **assessed** while repairs are still planned. Package
**convergence** is a later, stronger claim (see "Convergence standard").

## Workflow

### 1. Set the boundary

Record the repository, exact base SHA, package path and identity, dependency
lock identity, existing issues and PRs, and what completion the user asked for.
Revalidate current code and consumers instead of trusting an old roadmap or
issue text. Leave neighbouring packages and separately owned issues with their
owners.

### 2. Write the charter

Answer briefly, from code and current consumers:

- What capability does this package own, and what does it explicitly not own?
- Who composes and consumes it: at runtime, in generated applications, as a split package?
- Which dependencies are required, optional, or boundary adapters?
- Which symbols and wire contracts are public, and what lifecycle does each promise?
- What observable evidence shows it works in source and in distributed form?

If an answer can't be supported, record that as a finding. Do not describe a
cleaner architecture as if it were current fact.

### 3. Inventory everything

Classify **every production file** in the package before judging, moving or
deleting anything. Apply the relevant rows of the
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

Profiles are focused checklists selected by what the package does. Most
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
can't see.

For PHP, Deptrac is the dependency authority; don't write another parser.
Coordinate the configuration shape with #3075 before adding a new per-package
`deptrac.yaml` (admin-surface has the only one today). Classify from the
charter, fail on uncovered dependencies, and seed allowed, forbidden and
uncovered controls. Assert on Deptrac's JSON report, not its console output.
Keep existing checks such as `bin/check-package-layers` until a replacement is
accepted. Every exception names the dependency, reason and owner, with a
removal condition or a maintained invariant. Refresh generated architecture
views after any production change.

**Symfony reuse.** For substantial custom infrastructure, check whether an
installed Symfony component already owns the generic mechanism while Waaseyaa
keeps its domain policy. Verify against the component's source, tests and
official docs. Record retain, simplify around Symfony, replace, or defer, with
evidence and an owner. Similar names or fewer lines are not evidence. Audit
findings don't authorize replacement work.

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
consumers, disposition, owner issue, acceptance evidence, residual risk.
One finding per row.

**Evidence levels** prove different things; never count one as another:

- **inventoried:** listed from source;
- **reviewed:** read and reasoned about;
- **reproduced:** demonstrated by a probe or test (label synthetic fixtures as synthetic);
- **qualified:** exercised in a named installation profile (source, split, no-dev, generated app, native host).

Tie reused evidence to its source commit, dependency identity and runner. Don't
add overlapping runs together into "unique coverage".

For a consumer-driven, multi-package audit, mark each finding as required for
the consumer unblock, an independent follow-up, or an accepted limitation with a
rationale. Reconcile the producers and consumers of the shared contract
(ownership, readiness, lifetime, failure and completeness semantics, effective
access, installation profiles) before choosing a repair. Unrelated whole-package
convergence is not a prerequisite for the consumer fix. Add a roster entry for
any extra package the repair touches.

### 7. Finish the audit

A package is **assessed** when:

- every production file appears in the roster with a classification;
- the charter is written and every question is answered or recorded as a finding;
- each selected profile's checklist is answered with evidence, or marked "does not apply" with a reason;
- every finding has a disposition and an owner (an issue, or "accepted residual" with a rationale);
- unreviewed areas and untested installation profiles are listed explicitly;
- host limitations are recorded with the hosted runner that owns the missing evidence.

Anything short of that is **in progress**. Say so.

## Where the output goes

The repository is the record; GitHub mirrors it.

- Write the audit to `docs/audits/packages/<package>.md` from the
  [template](references/audit-record-template.md). Update the package's row in
  the program coverage index when one exists (`FW-PACKAGE-CONVERGENCE-01`).
- Keep probes that reproduce a finding. Commit them with the audit record, or
  turn them into the failing regression test that opens the repair PR. Don't
  leave evidence only in a scratch folder or an issue comment.
- Post a short summary with a link on the owning issue.

Write plainly: short sentences, one finding per row, no hedging stacks.
Reviewers will read dozens of these.

## Turn findings into work

Separate package convergence from unrelated product defects. Create bounded
issues for residuals a slice can't safely absorb. When implementation is
authorized, sequence it so later work builds on settled contracts:

1. durable charter and change record;
2. dependency model and discriminating controls;
3. canonical contract convergence;
4. behavioral and failure-boundary repairs;
5. public-surface and documentation reconciliation;
6. source, packaged, generated and distribution qualification;
7. immutable-candidate review, hosted checks and authorized landing.

This is a dependency order, not one large PR. Split where review, ownership or
risk improves. Run governance scanners after changing test harnesses as well
as production code; a scanner finding is an ownership signal, not permission
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
