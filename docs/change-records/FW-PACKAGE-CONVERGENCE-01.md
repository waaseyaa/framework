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

1. **Assessed** (audit state "assessed"): every production file in the roster;
   charter answered; each selected profile's checklist answered, marked not
   applicable, or recorded as a gap with a destination; every supported
   installation profile with qualifying evidence or a recorded qualification
   gap with an owner; every finding with severity, confidence, attribution, a
   disposition, an accountable destination, a next action and its tier's
   verification; no open finding or handoff that blocks the assessment; every
   intake item dispositioned; security findings verified at tier A with a
   private brief in durable custody, an owning package and the advisory route;
   refuted leads, decisions, uncertainties, base, date, dependency identity,
   evidence freshness, gaps and host limits recorded. A destination need not be
   a filed issue.
2. **Repair ready:** the record's remediation plan bounds every package-owned
   finding that needs work into slices with acceptance criteria. A slice is
   filed as an issue before its remediation begins, and every remaining
   finding has a bounded issue before this program's final reconciliation.
   Filing needs publication authority; a private security report is filed as
   soon as it is authorized, independent of any milestone.
3. **Converged:** repairs and required qualification have landed.

Audit and remediation states stay independent axes. Remediation state tracks
filed work: "planned" once at least one bounded slice is filed, "in progress",
"resolved" when every package-owned slice has landed, and "no action required"
or "accepted residual" when nothing needs a slice; it stays "not triaged" until
the first slice is filed, even when every finding is dispositioned. A
consumer-driven slice can be planned or in progress while its audit is still in
progress. Repair ready and converged are claims in the audit record's header;
the index has no state for either. Rows recorded before method v2 (the initial
states below, and ai-vector) keep their states until their next delta review.

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
roster. Verification corrected mechanisms, lowered 13 severities and surfaced
the highest-value cross-package security finding, and a refutation check
reopened 2 of the 65 refutations it checked. But the run took 121 agents, about 20M subagent tokens and
4.3 hours for 13 source files, and produced a 2,420-line record against
ai-vector's 365. It also showed three rule problems: "assessed" required a
filed issue per finding, which is a publication step; an untested standalone
`--no-dev` profile kept the audit open even though the gap had an owner; and
findings owned by relationship, api, access and ai-tools inflated the groups
ledger.

v2 changes the skill (`.agents/skills/waaseyaa-package-convergence/`):

- **Milestones.** Assessed, repair ready and converged are separate claims
  (see "States"). Assessed needs an accountable destination per finding, not
  an issue. Repair ready follows the program brief: bounded slices with
  acceptance exist; issues are filed before a slice's remediation begins, and
  for every remaining finding before final program reconciliation.
- **Tiered verification.** Two independent verifiers for security, medium or
  higher, and public-surface removals or changes to who may read or mutate
  data; one verifier for low; grouped sampling for info; tie-breaks on any
  material disagreement (`references/audit-orchestration.md`). Budgets are
  provisional, with a projection checkpoint after consolidation.
- **Size budget.** The human record stays near 400 lines, with detail blocks
  only for medium and higher. Exhaustive rosters, checklist answers, refuted
  leads, handoffs and probe metadata move to the structured ledger, whose keys
  and enums the template lists.
- **Attribution and intake.** Every finding records where it was discovered,
  its one owning package and any co-owners, and whether it blocks the
  assessment. Findings owned elsewhere, and handed-off leads, are intake that
  the owning package's audit collects and dispositions.
- **Profile-aware distribution.** Installation profiles and evidence classes
  are separate lists, with a map of which classes can qualify which profile.
  A supported profile without evidence is a qualification gap that, once
  recorded with an owner, does not keep the audit open.
- **Security triage.** Security-sensitive follows `SECURITY.md`. The
  discovering orchestrator writes the private brief from verified evidence and
  keeps it in durable private custody; the record carries one safe row per
  finding after a redaction check (`references/security-triage.md`).
- **Probes.** Retained probes cover medium-or-higher non-security findings
  and any probe an acceptance criterion cites, in a flat layout, runnable from
  the repository. Other probes stay in the ledger with a reproduction
  description, and their findings read "reproduced (probe not retained)".
- **Scorecard.** Each of the next audits records time, agents, findings by
  severity, verification reversals, record length, probes retained and open
  decisions.

Decisions (maintainer, 2026-09-26, after an independent review of the v2.1
candidate):

- **Index states stay as they are.** Repair ready and converged are recorded in
  the audit record's header only.
- **The ledger is an executable contract.** `bin/lib/package-audit-ledger.php`
  validates a ledger's keys, types, enums, owner values, internal references
  and security-row rules, and checks it against its record, its coverage-index
  row and its retained probes. `tests/Architecture/PackageAuditLedgerTest.php`
  runs it over every committed ledger and seeds each defect class. No ledger
  lands before this test does.
- **Lane write isolation is checked, not requested.** The skill ships
  `scripts/lane-integrity.php`: snapshot every checkout and directory the
  agents can reach before a run, verify after, and stop on any change outside
  the allowed output areas. `tests/Architecture/PackageAuditLaneIntegrityTest.php`
  proves it reports changes and ignores allowed areas.
- **Security briefs have a designated durable location** outside every
  repository, with access limited to the maintainer. Records and ledgers
  don't name it.
- **v2.1 is ready for one more bounded calibration, not yet proven for the
  whole roster.** The refinement of the groups audit alone took about 31
  agents and 8M subagent tokens. The next audit runs with a recorded budget and
  the projection checkpoint, and its scorecard decides whether the small-package
  budget (about 50 agents) holds.
- The skill change and the groups audit-only change are held until these
  controls are in place, the groups brief is in durable custody, its retained
  probes run from the repository, and its record and ledger have had an
  independent review.
