# Package audit record template

An audit produces two files: the human record `docs/audits/packages/<package>.md`
and the structured ledger `docs/audits/packages/<package>.ledger.json`. The
record is what reviewers read: keep it near 400 lines, and if it can't fit,
say why. The ledger holds everything exhaustive. Delete the guidance lines in
italics. Keep it plain: short sentences, one entry per finding.

Section budgets are guides, not quotas:

| Section | Budget |
| --- | --- |
| Header and summary | 40 lines |
| Charter | 40 |
| Roster | one short row per file, or grouped by directory above about 25 files |
| Package findings | table, then detail blocks by severity (see below) |
| Cross-package findings | one table row each |
| Qualification by profile | 20 |
| Profile checklists | 40 |
| Decisions, remediation plan, not reviewed, host limits | 60 |
| Evidence and scorecard | 40 |

---

# `waaseyaa/<package>` audit

- **Milestone:** assessed | in progress (*numbered reasons*). Repair ready: no | yes. Converged: not claimed | claimed at `<SHA>` with *evidence*.
- **Audit state:** *index value*. **Remediation state:** *index value* (*"not triaged" until bounded slices exist*)
- **Base:** `<full SHA>`, audited `<YYYY-MM-DD>`
- **Dependency identity:** `composer.lock` SHA-256 `<hash>` at the base; PHP `<version>`, host `<OS>`. *Consumer checkouts used as evidence, with their commit and lock hash.*
- **Evidence freshness:** *current at `<SHA>`* | *needs delta review: package source changed since the base in `<commits>`*
- **Owner:** `waaseyaa/framework#<n>`; program #3118
- **Profiles applied:** *each with one line of scope*. **Not applied:** *each with a one-line reason*
- **Structured ledger:** `docs/audits/packages/<package>.ledger.json`

## Summary

*Five to ten lines: what the package is, the verdict, the findings that
matter most, and what a consumer should know today.*

## Charter

- **Owns:**
- **Does not own:**
- **Consumers:** *runtime, generated applications, split-package users; name real ones*
- **Dependencies:** *required / optional / adapters, including string-level ones*
- **Public surface:** *symbols and wire contracts, with their lifecycle*
- **Installation profiles:** *which are supported, and by what decision*
- **Evidence it works:** *source, and each supported profile*

## Roster

| File | Role | Classification | Level | Findings |
| --- | --- | --- | --- | --- |
| `src/Example.php` | *what it does* | owned and coherent | reviewed | |

*Classifications:* owned and coherent · necessary but under-specified ·
duplicated or drifting contract · wrong package or layer · unwired or obsolete ·
optional/required misrepresented · missing refusal, lifecycle, compatibility or
distribution evidence. *Evidence levels:* inventoried · reviewed · reproduced ·
qualified (name the installation profile). *Longer notes go in the ledger.*

## Package findings

*Findings this package owns. Group test and documentation gaps into one
finding per coherent repair slice.*

| ID | Title | Severity | Confidence | Level | Verified | Disposition | Destination | Next action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `<PKG>-<AREA>-001` | *one line* | critical / high / medium / low / info | confirmed / likely / suspected | reproduced | A / B / C | repair / move / document / deprecate / remove / retain | #n, slice S1, decision D1, or accepted residual | *the next concrete step, or "none"* |
| `<PKG>-SEC-001` | *safe one-line class of issue* | withheld | | | A | repair | private report (`waaseyaa/<owner>`) | private triage |

*Scale:* **info** means no consumer consequence (a hygiene, test or
documentation gap). **withheld** marks a security finding rated in private
triage. *Verified* is the tier that checked it.

**Detail blocks.** Medium and higher get every field below. Low findings get
observed, consequence, disposition and destination, and acceptance, in a few
lines; the other fields are in the ledger. Info findings get the table row only.
Security findings get the safe summary only ([security triage](security-triage.md)).

### `<PKG>-<AREA>-001`: *title*

- **Observed, with evidence:** *what happens, file:line, how to reproduce*
- **Expected contract:** *what the charter or contract says should happen*
- **Consequence and consumers:** *what breaks, for which runtime, generated or installed consumers*
- **Severity and confidence:** *why; what would change it*
- **Refutation:** *what was considered against it, and why it does or doesn't hold*
- **Disposition and destination:** *disposition, and its issue, slice, decision or residual rationale*
- **Dependencies:** *what must land first, or "none"*
- **Acceptance:** *the discriminating test or check that proves it's resolved*
- **Residual risk:** *what stays true after the fix, or "none"*
- **Next action:** *the next concrete step and who takes it*

## Cross-package findings

*Found here, owned elsewhere. They are intake for the owning package's audit,
not part of this package's remediation.*

| ID | Owned by | Title | Severity | Affected consumers | Blocks this assessment | Destination |
| --- | --- | --- | --- | --- | --- | --- |

## Refuted leads

*The count, plus only the refutations that correct an existing issue or a
likely repeat mistake, one line each. The rest are in the ledger.*

## Qualification by profile

| Profile | Supported | Evidence class | Evidence | Gap and owner |
| --- | --- | --- | --- | --- |
| source | yes | source | *suite run* | |
| standalone split, `--no-dev` | *yes / no / undecided* | not run | | *gap, owner* |

*Evidence classes are defined in the [distribution profile](profiles/distribution.md).*

## Profile checklists

*One line per applied profile: items answered, items that are findings (IDs),
items that don't apply (one reason each), and open gaps with destinations.
Every item's full answer is in the ledger.*

## Decisions needed

| ID | Decision | Findings | Who decides |
| --- | --- | --- | --- |

## Remediation plan

*Proposed bounded slices, in dependency order. Each becomes an issue at the
repair-ready milestone.*

| Slice | Findings | Acceptance | Depends on |
| --- | --- | --- | --- |

## Not reviewed

*Areas, files or installation profiles not covered, and why.*

## Host limits

*Checks this host couldn't run, and the hosted job that owns them.*

## Evidence

*The runs the findings rest on. Reused evidence keeps its base, dependency
identity and runner. The full list is in the ledger.*

| Command or probe | Base | Dependency identity | Runner | Proves | Result |
| --- | --- | --- | --- | --- | --- |

## Scorecard

*The fields in [running an audit](audit-orchestration.md): time, agents,
findings by severity, verification reversals, record length, probes retained,
open decisions.*

---

## Structured ledger

`docs/audits/packages/<package>.ledger.json` is UTF-8 JSON with LF line
endings. It never holds security specifics: security findings carry only
their safe summary. Top-level keys:

| Key | Content |
| --- | --- |
| `schema_version` | `1` |
| `package`, `base`, `audit_date`, `dependency_identity` | as in the record header |
| `milestone` | `assessed` or `in progress`, with `reasons` |
| `charter` | one entry per charter question: `question`, `answer`, `evidence` |
| `roster` | one entry per production file: `file`, `role`, `classification`, `evidence_level`, `notes`, `findings` |
| `checklists` | one entry per checklist item: `profile`, `item`, `status` (answered, does not apply, finding, gap), `answer`, `evidence`, `findings` |
| `findings` | every finding, package-owned and cross-package, with every template field plus `discovered_in`, `owned_by`, `affected_consumers`, `blocks_assessment`, `destination`, `verification` (tier and reversals) and `probes` |
| `refuted` | every refuted lead: `id`, `lead`, `investigation`, `refutation`, `evidence` |
| `decisions` | `id`, `decision`, `findings`, `who_decides` |
| `remediation_plan` | `slice`, `findings`, `acceptance`, `depends_on` |
| `qualification` | one entry per installation profile: `profile`, `supported`, `evidence_class`, `evidence`, `gap_owner` |
| `probes` | `name`, `purpose`, `result`, `retained_path` (null when not committed) |
| `evidence_runs` | `command`, `base`, `dependency_identity`, `runner`, `proves`, `result` |
| `scorecard` | the scorecard fields |

A later audit finds its intake by reading every ledger's `findings` for
`owned_by` equal to its package.
