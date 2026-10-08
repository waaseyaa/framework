# FW-AUDIT-RECONCILIATION-01

Status: local documentation and skill candidate, 2026-10-08.
Base: `a09e9f9c0276490c05e393b74b1a8b18f9a68562`, matching live main at intake.
Program: [FW-PACKAGE-CONVERGENCE-01](FW-PACKAGE-CONVERGENCE-01.md), #3118.
Owner: Codex root. Scope: audit method, documentation policy, README and two
confirmed stale shipped skills. No runtime repair, package reassessment,
GitHub mutation, release or deployment is part of this candidate.

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

The live #3118 description and #2667/Project state still need reconciliation
when publication is authorized. This local candidate does not claim it occurred.
