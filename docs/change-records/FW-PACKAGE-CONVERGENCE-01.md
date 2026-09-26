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

The skill defines three milestones, each a stronger claim:

1. **Assessed** (audit state "assessed"): every production file in the roster,
   charter answered, each selected profile's checklist answered, marked not
   applicable or recorded as a gap with a destination, every supported
   installation profile with an evidence class or a dispositioned
   qualification gap, every finding with severity, confidence, attribution, a
   disposition, an accountable destination and a next action, security
   findings with a private brief and an owning package, refuted leads,
   decisions, base, date, dependency identity, evidence freshness, gaps and
   host limits recorded. A destination need not be a filed issue.
2. **Repair ready** (remediation state "planned"): bounded remediation slices
   with acceptance criteria are filed as issues, and security items have
   filed private reports. Filing needs publication authority.
3. **Converged:** repairs and required qualification have landed. The index
   has no state for it; the audit record's header states it with evidence.

Remediation state follows bounded work, not dispositions: it stays "not
triaged" until bounded slices exist, even when the audit has dispositioned
every finding.

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
  runs in `ci/verify-gates` (`fetch-depth: 0`, which fetches every branch into
  `refs/remotes/origin/*`) and local preflight. Ancestry is judged against a
  trusted main ref, `--main-ref`, defaulting to `refs/remotes/origin/main`;
  never HEAD, because on a pull request feature-branch commits are ancestors
  of HEAD but vanish from main after a squash merge. It refuses a shallow
  clone, an unresolvable main ref, an audit base or evidence source commit that
  isn't an ancestor of the main ref, a dependency identity that doesn't match
  `composer.lock` at its base, and a commit-sourced copy that differs from its
  source. `tests/Architecture/PackageCoverageHistoryGateTest.php` proves each
  refusal on a fixture repository, including a cited commit that is an
  ancestor of HEAD on a feature branch but not of main.

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
aggregate and distribution forms. `waaseyaa/ai-vector` was the persistence
calibration, chosen because it created its `embeddings` table at runtime
outside schema authority (#3110; `docs/specs/s1-schema-authority.md`).
`waaseyaa/groups` is the small domain package, chosen for its consumer
evidence in Sheguiandah.

Each audit is its own audit-only change that adds
`docs/audits/packages/<package>.md`, its structured ledger
`docs/audits/packages/<package>.ledger.json` and retained probes, and updates
that package's index row. Repairs follow as separate, independently reviewed
changes.

Calibration status (2026-09-26): admin-surface was the design case the method
was built from. ai-vector (#3135, #3137) was the persistence calibration.
groups, audited at `a4e88e88afb1d2806b12fbc7947d32a1484e8798` on 2026-09-25, is
the small domain package and the first end-to-end audit with the extracted
skill and profiles. Its record is not yet committed; it is being condensed
into the v2 format below before an audit-only change. The next audit should be
a high-fan-in shared authority, once the condensed groups record shows the v2
format is reviewable.

## Method v2 (2026-09-26)

The groups audit showed the method is rigorous but doesn't scale to the
roster. Two-lens verification corrected mechanisms, lowered 12 severities,
reopened 2 of 65 refutations and surfaced the highest-value cross-package
security finding. But the run took 121 agents, about 20M subagent tokens and
4.3 hours for 13 source files, and produced a 2,420-line record against
ai-vector's 365. It also showed three rule problems: "assessed" required a
filed issue per finding, which is a publication step; an untested standalone
`--no-dev` profile kept the audit open even though the gap had an owner; and
findings owned by relationship, api, access and ai-tools inflated the groups
ledger.

v2 changes the skill (`.agents/skills/waaseyaa-package-convergence/`):

- **Milestones.** Assessed, repair ready and converged are separate (see
  "States"). Assessed needs an accountable destination per finding, not an
  issue.
- **Tiered verification.** Two independent verifiers for security, medium or
  higher, and authority or public-surface removals; one verifier for low;
  grouped sampling for info and documentation gaps; tie-break only on
  disagreement (`references/audit-orchestration.md`).
- **Size budget.** The human record stays near 400 lines. Exhaustive rosters,
  checklist answers, refuted leads and probe metadata move to the structured
  ledger.
- **Attribution.** Every finding records the package it was discovered in, the
  package that owns it, affected consumers, and whether it blocks the
  assessment. Findings owned elsewhere are intake for that package's audit.
- **Profile-aware distribution.** Evidence is recorded by class (source,
  closure artifact, metapackage, installed consumer, standalone split,
  generated application, native host). A supported profile without evidence is
  a dispositioned qualification gap, not an open checklist item.
- **Security triage.** The orchestrator writes the private brief from
  verified evidence, the record carries a safe summary, and a redaction check
  runs before the record is final (`references/security-triage.md`).
- **Scorecard.** Each of the next audits records time, agents, findings by
  severity, verification reversals, record length, probes retained and open
  decisions.

Open decision: whether the index should gain dedicated states for "repair
ready" and "converged". v2 maps them onto the existing states because the
state vocabulary is pinned by the index test and mirrored in #3118.
