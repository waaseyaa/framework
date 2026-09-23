# FW-PACKAGE-CONVERGENCE-01 — Framework package convergence program

- Forge mirror: `waaseyaa/framework#3118`
- Method: `.agents/skills/waaseyaa-package-convergence/` (maintainer skill,
  FW-MAINTAINER-SKILLS-01)
- Coverage index: [`docs/audits/packages/coverage-index.json`](../audits/packages/coverage-index.json),
  gated by `tests/Architecture/PackageConvergenceCoverageIndexTest.php`
- Implementation-authority dimension: [FW-IMPLEMENTATION-AUTHORITY-2026-09](../audits/FW-IMPLEMENTATION-AUTHORITY-2026-09/README.md)
  (#2985). This program links to that matrix; it does not copy or replace it.
- Authority: audit records, the coverage index and the method. No package
  audit here authorizes implementation, API changes, extraction, release,
  publication or deployment.

## Outcome

Every Framework package is assessed against an explicit baseline with one
shared method, every finding has a disposition and an owner, and remaining
repairs are bounded issues. Audit coverage and remediation progress are
tracked separately. The program does not block ordinary Framework delivery.

## States

- **Audit:** not assessed, inventory only, in progress, assessed, needs delta review.
- **Remediation:** not triaged, no action required, planned, in progress, resolved, accepted residual.

"Assessed" has the finish line defined in the skill: every production file in
the roster, charter answered, each selected profile's checklist answered or
marked not applicable, every finding with severity, confidence, an owner and a
next action, refuted leads recorded, base, date, dependency identity and
evidence freshness recorded, gaps and host limits listed. A
package can be assessed while repairs are still planned.

## Coverage index

One row per package directory plus the root `waaseyaa/framework` aggregate,
with identity and form read from the real manifests (schema version 2). Each
row records `audit_state`, `remediation_state`, `audit_base`, `audit_date`,
`dependency_identity` (`composer.lock` SHA-256 at the base), `owner_issue`,
`evidence` and `notes`.

Evidence entries are typed references that can't change silently:

- `repository-path`: a file tracked in this repository;
- `commit-path`: a file at an exact commit;
- `pull-request`: a merged pull request pinned to its merge commit;
- `issue-snapshot`: an issue body pinned by the SHA-256 of
  `gh issue view <n> --json body --jq .body` on the capture date. Issues are
  editable, so a snapshot is provisional evidence until the audit record is
  committed; a changed hash means the issue moved on since capture.

The Architecture test fails when:

- a package is added, renamed or removed without updating the index;
- a state is outside the vocabulary;
- a row beyond "not assessed" lacks a full base, an audit date, a dependency
  identity, an owner issue or evidence;
- a dependency identity doesn't match the lock at its base (checked when the
  base is in the clone);
- a `commit-path` doesn't exist at its commit (checked when the commit is in
  the clone);
- evidence is free text or malformed;
- an "assessed" row doesn't link `docs/audits/packages/<package>.md`.

### admin-surface

admin-surface was assessed before this convention existed. Its
`docs/audits/packages/admin-surface.md` is a migration record: it names the
base, date, dependency identity and evidence freshness, and points to the
immutable change record, charter and Deptrac configuration at
`2718edc02f8a47167db1b31b2192690bff773cfd` rather than copying them. Its next
material source change triggers a delta review in template form.

Initial states (source: the #3118 roster and the audit issues, verified
2026-09-23):

| Package | Audit | Remediation | Owner | Basis |
| --- | --- | --- | --- | --- |
| admin-surface | assessed | planned | #3074 | Reference convergence, PR #3086; residuals owned by #3078, #3079, #3082–#3084 |
| cli | in progress | planned | #3117 | Pilot; complete inventory, large areas not yet reviewed |
| foundation | in progress | planned | #3123 | Route composition and lifecycle slice under #3122 |
| bimaaji | in progress | planned | #3124 | Bounded pass under #3122; evidence is a pinned issue snapshot |
| routing | in progress | planned | #3125 | Bounded audit under #3122; evidence is a pinned issue snapshot |
| all other 74 rows | not assessed | not triaged | — | No audit at a named baseline |

The four "in progress" audits predate the repository audit-record convention.
Moving their evidence from issue bodies into `docs/audits/packages/` is part
of finishing them; until then their issue snapshots pin what was reviewed.

## Order

Follows #3118: calibrate the method on the admin-surface reference and the
CLI pilot, then on one persistence package and one small domain package, then
audit high-fan-in shared authorities before dependent capabilities, then
aggregate and distribution forms. `waaseyaa/ai-vector` is the next audit and
the persistence calibration: it creates its `embeddings` table at runtime
outside schema authority (#3110; `docs/specs/s1-schema-authority.md`). The
small domain package is not yet chosen.

Each audit is its own audit-only change that adds
`docs/audits/packages/<package>.md` and updates that package's index row.
Repairs follow as separate, independently reviewed changes.
