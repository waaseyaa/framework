# FW-AUDIT-RECONCILIATION-01

Status: method/README landed; backlog and document-retirement follow-up, 2026-10-08.
Base: `a09e9f9c0276490c05e393b74b1a8b18f9a68562`, matching live main at intake.
Program: [FW-PACKAGE-CONVERGENCE-01](FW-PACKAGE-CONVERGENCE-01.md), #3118.
Owner: Codex root. Initial scope: audit method, documentation policy, README and
two confirmed stale shipped skills. The maintainer subsequently authorized
landing, backlog/document reconciliation and listing/messaging closeout.
This follow-up retires obsolete documents and reconciles coordination records;
it does not claim runtime repairs, release or deployment.

## Intended outcome

The maintainer wants the entire framework audited and remediated, with confirmed
defects and required convergence repairs resolved. Intentional future features
remain in a separately triaged backlog. Specs must be audited as part of SDD.
Repository hygiene takes precedence over retaining superseded documents without
a current obligation.

Keep the two maintainer skills: convergence owns diagnosis and disposition;
delivery owns authorized repair and reconciliation. Add one focused reference,
not another competing audit skill. Use existing ledger fields.

Replace provisional 50/75-agent sizing targets with bounded investigation and
risk-based verification batches. Preserve per-finding tier-A independence;
related findings can share the same two verifiers. Historical calibration
remains evidence, not a staffing requirement for every package.

## Research observations at the base

- The method already covers existing issues/PRs, documentation, Symfony reuse,
  dynamic/dependent consumers, adapter truthfulness and cross-package ownership.
  Complete issue/document intake and clean-board acceptance were not explicit.
  Assessment with bounded outstanding repairs is weaker than the full goal.
- Coverage index: 79 entries, including the root aggregate; 70 not assessed,
  6 in progress, 2 assessed and 1 needing delta review. These are recorded
  states, not new verdicts.
- All open issues were fetched through the paginated GitHub issues API,
  excluding PRs: 196. Project 4 returned its complete 335-item result. Three
  open issues were absent: #3171, #3181 and #3184. This checks membership only,
  not every field or issue's correctness. #2667 owns roadmap synchronization.
  No board writes were performed.
- Listing's landed repair retains #3005/#2859 and does not claim assessment or
  convergence. Messaging is assessed with remaining remediation planned.
  Neither proves framework-wide composition; neither audit is restarted here.
- #2055 documents a past dead-code sweep missing a downstream distribution.
  Its historical reproduction was not revalidated in this review. #3075 owns
  Deptrac adoption. Reuse these existing owners.
- The old README claimed no cycles despite five baseline pairs, named Symfony
  7 despite mixed 7.4/8.x lock entries, described obsolete package counts and
  closures, and omitted site/config activation from the quick start. The
  rewrite follows manifests, the skeleton lifecycle and candidate S1 limits.
- The entity-system skill taught removed storage wiring and unbound queries.
  It now routes to version-matched contracts/source and the composed repository,
  with explicit authorization and acceptance checks. Spec-maintenance wrongly
  made anchor issues the primary ledger; it now uses portable change records.

This is not an exhaustive audit of every shipped skill or a claim that all
packages compose correctly. Remaining doc debt includes live entity-system
prose naming `SqlEntityStorage` and superseded `config-management-v1.md`,
`migration-platform-v1.md` and `entity-storage-two-axis.md` under `docs/specs/`.
The corpus pilot consumes all three, and its test explicitly asserts the
two-axis historical entry. Retirement needs consumer and inbound-link repair.
This candidate removes obsolete skill/README content; it does not claim that
document-tree retirement is complete.

## External guidance consulted

- [Symfony components](https://symfony.com/doc/7.4/components/index.html):
  maintained infrastructure candidates; repository policy already defaults to
  suitable component reuse.
- [Symfony Messenger](https://symfony.com/doc/7.4/messenger.html): bus and
  transport lifecycle includes retries, failure handling and idempotency.
  Infrastructure reuse does not replace Waaseyaa's thread/member domain.
- [Deptrac configuration](https://deptrac.github.io/deptrac/configuration/):
  architectural dependency evidence still needs runtime composition witnesses.
- [GitHub README guidance](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/about-readmes):
  purpose, usefulness, getting started and help shape the new README.

## Acceptance and verification plan

- Complete existing-work/document intake, with unavailable sources explicit.
- Requirement/spec/implementation/consumer/test traceability; no spec edits to
  excuse defects. Scoped deletion after reference and consumer reconciliation.
- Framework composition and final remediation distinguished from assessment.
- README commands follow the skeleton, links resolve, manifests support package
  descriptions and alpha support claims remain qualified.
- Validate skills, links/source references and whitespace; independently review
  the immutable documentation diff. No broad runtime suite is warranted for
  prose changes. Hosted/release qualification is not claimed. Actual results
  belong in the final handoff.

## Authorized follow-up: backlog and document retirement

The first batch landed at `f099100ceedb5160a9ccc78b6ee2e365a096ecae`.
[Main feedback CI](https://github.com/waaseyaa/framework/actions/runs/37859608040)
passed. Independent review approved the source; governed preflight recorded
41 executed passes, two reused passes, no failures, and three not applicable.

The [backlog work map](../audits/packages/backlog-reconciliation.md) routes all
196 open issues and ten dependency PRs. It preserves existing acceptance owners
and distinguishes eight clear future-feature proposals from repair, decision,
qualification and program work. Intake is not a fresh defect-verification claim.
The live #3118 outcome now reflects the maintainer's finish line and links to the
versioned coverage index instead of a stale all-not-assessed inline roster.

Retire 14 files: three superseded specs, the M-004 planning spec, its obsolete
cookbook and upgrade guide, and eight frozen mission-filing metadata files.
The live config, migration and unified revision specs retain current contracts.
Update active routing, corpus manifest, tests and live references together.
No runtime behavior changes. Frozen audit rosters and historical prose retain
original path identities as evidence, not claims that those files still exist.
The charter pointer amendment removes obsolete companion links without changing
DIR-005's required revision/translation invariants or amendment authority.

The pilot corpus now compiles five live specs and one draft. Lifecycle behavior
for superseded/historical material remains covered by purpose-built fixtures.
A native run of all 29 corpus tests hit the two already-governed Windows symlink
permission failures; this is incomplete host evidence, not a candidate regression.
The supported focused run excludes those two unchanged symlink fixtures and
passes 27 tests / 114 assertions. Hosted Linux owns the symlink proof.

Listing custody and messaging lifecycle/profile choices are pending maintainer
answers. Their existing ledgers keep those decisions open. Neither package is
promoted to converged merely because this documentation batch lands.


Board publication used independently reviewed plan
`bfc971120969b2f1daf5d2b58bfd9e8bcb7352beacdb66fb05245485d6177e77`:
16 operations / 22 writes, no failure. #3163's extra acceptance was incorporated
into #3116 before duplicate closure; its closed readiness was then cleared.
79 missing priorities and 15 ambiguous readiness labels remain explicit under
#2667. No stage, milestone, release label or priority was inferred or changed.
