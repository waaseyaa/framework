# FW-PACKAGE-CONVERGENCE-01 — Framework package convergence program

- Forge mirror: `waaseyaa/framework#3118`
- Method: `.agents/skills/waaseyaa-package-convergence/` (maintainer skill,
  FW-MAINTAINER-SKILLS-01)
- Coverage index: [`docs/audits/packages/coverage-index.json`](../audits/packages/coverage-index.json),
  gated by `tests/Architecture/PackageConvergenceCoverageIndexTest.php` and
  `bin/check-package-coverage-history`
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
with identity and form read from the real manifests (schema version 3). Each
row records `audit_state`, `remediation_state`, `audit_base`, `audit_date`,
`dependency_identity` (`composer.lock` SHA-256 at the base), `owner_issue`,
`evidence` and `notes`.

Evidence is only ever a file committed in this repository
(`repository-path`). Historical files and issue bodies are captured
byte-for-byte under `docs/audits/packages/evidence/`, each with a provenance
header:

```text
source: commit <40-hex> <path>   or   issue <owner>/<repo>#<n>
captured: YYYY-MM-DD
body-sha256: <SHA-256 of the body bytes>
---
<body bytes>
```

Issue bodies are captured with `gh issue view <n> --json body --jq .body`
written straight to a file (bytes unchanged; some bodies contain CR).
`.gitattributes` stores the directory with no line-ending or whitespace
normalization. A commit-sourced copy must come from a commit on `main`'s
history, so it stays verifiable after feature branches are squashed and
deleted; records that exist only on unmerged branches are cited once they
land.

Two layers of checks:

- `tests/Architecture/PackageConvergenceCoverageIndexTest.php` works in a
  shallow checkout. It fails on package-set drift, unknown states, missing
  identity fields, free-text or non-repository evidence, a cited file that
  isn't committed, an evidence file whose body doesn't match its digest, a
  captured file no row cites, or an "assessed" row without
  `docs/audits/packages/<package>.md`.
- `bin/check-package-coverage-history` needs full history and fails closed. It
  runs in `ci/verify-gates` (`fetch-depth: 0`) and local preflight. It refuses a
  shallow clone, an audit base or evidence source commit that isn't an
  ancestor of HEAD, a dependency identity that doesn't match `composer.lock` at
  its base, and a commit-sourced copy that differs from its source.
  `tests/Architecture/PackageCoverageHistoryGateTest.php` proves each refusal on
  a fixture repository.

### admin-surface

admin-surface was assessed before this convention existed. Its
`docs/audits/packages/admin-surface.md` is a migration record naming the base,
date, dependency identity and evidence freshness. Its change record and
package README at `2718edc02f8a47167db1b31b2192690bff773cfd` are captured under
`docs/audits/packages/evidence/admin-surface/`. Its next material source
change triggers a delta review in template form.

Initial states (source: the #3118 roster and the audit issues, verified
2026-09-23):

| Package | Audit | Remediation | Owner | Basis |
| --- | --- | --- | --- | --- |
| admin-surface | assessed | planned | #3074 | Reference convergence, PR #3086; residuals owned by #3078, #3079, #3082–#3084 |
| cli | in progress | planned | #3117 | Pilot; complete inventory, large areas not yet reviewed |
| foundation | in progress | planned | #3123 | Route composition and lifecycle slice under #3122 |
| bimaaji | in progress | planned | #3124 | Bounded pass under #3122; issue body captured as evidence |
| routing | in progress | planned | #3125 | Bounded audit under #3122; issue body captured as evidence |
| all other 74 rows | not assessed | not triaged | — | No audit at a named baseline |

The four "in progress" audits predate the repository audit-record convention.
Moving their evidence from issue bodies into `docs/audits/packages/` is part
of finishing them; until then their captured issue bodies preserve what was
reviewed.

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
