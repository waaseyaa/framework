# Package audit record template

An audit produces two files: the human record `docs/audits/packages/<package>.md`
and the structured ledger `docs/audits/packages/<package>.ledger.json`. The
record is what reviewers read: keep it near 400 lines, and past that, state
why and get the maintainer's agreement. The ledger holds everything
exhaustive. Delete the guidance lines in italics. Keep it plain: short
sentences, one entry per finding.

Section budgets are guides, not quotas:

| Section | Budget |
| --- | --- |
| Header and summary | 40 lines |
| Charter | 40 |
| Roster | one short row per file, or grouped by directory above about 25 files |
| Intake | one row per item |
| Package findings | about 150: table rows, plus detail blocks for medium and higher |
| Cross-package findings and handoffs | one row each |
| Qualification by profile | 20 |
| Profile checklists | 40 |
| Decisions, uncertainties, remediation plan, not reviewed, host limits | 60 |
| Evidence and scorecard | 40 |

---

# `waaseyaa/<package>` audit

- **Milestone:** assessed | in progress (*numbered reasons*). Repair ready: no | yes. Converged: not claimed | claimed at `<SHA>` with *evidence*.
- **Audit state:** *index value*. **Remediation state:** *index value*
- **Base:** `<full SHA>`, audited `<YYYY-MM-DD>`
- **Dependency identity:** `composer.lock` SHA-256 `<hash>` at the base; PHP `<version>`, host `<OS>`. *Consumer checkouts used as evidence, with their commit and lock hash.*
- **Evidence freshness:** *current at `<SHA>`* | *needs delta review: package source changed since the base in `<commits>`*
- **Owner:** `waaseyaa/framework#<n>` (*the program issue #3118 until a package umbrella exists*)
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
qualified (name the evidence class). *Longer notes go in the ledger. Never tie
a security ID to a file here.*

## Intake

*Items other audits routed to this package, and what this audit did with each.*

| Item | From | Disposition | Local finding |
| --- | --- | --- | --- |
| `<OTHER>-<AREA>-NNN` | *discovering audit* | confirmed / merged / refuted / re-owned | `<PKG>-<AREA>-NNN` or none |

## Package findings

*Findings this package owns. Group test and documentation gaps into one
finding per coherent repair slice.*

| ID | Title | Severity | Confidence | Level | Verified | Disposition | Destination | Next action |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `<PKG>-<AREA>-001` | *one line* | critical / high / medium / low / info | confirmed / likely / suspected | reproduced | A / B / C | repair / move / document / deprecate / remove / retain | #n, slice S1, decision D1, or accepted residual | *the next concrete step, or "none"* |
| `<PKG>-SEC-001` | *safe one-line class of issue* | withheld | confirmed | reproduced | A | repair | private report (framework advisory; owner `waaseyaa/<pkg>`) | private triage |

*Scale:* **info** means no consumer consequence (a hygiene, test or
documentation gap). **withheld** marks a security finding rated in private
triage. A grouped finding's severity is its highest member's, and the row says
so. *Verified* is the tier whose verification actually ran; write "pending"
when it hasn't.

**Detail blocks** are for medium and higher only. Low and info findings are
table rows; their full fields are in the ledger. Security findings get the
public row only ([security triage](security-triage.md)).

### `<PKG>-<AREA>-001`: *title*

- **Observed, with evidence:** *what happens, file:line, how to reproduce*
- **Expected contract:** *what the charter or contract says should happen*
- **Consequence and consumers:** *what breaks, for which runtime, generated or installed consumers*
- **Severity and confidence:** *why; what would change it*
- **Refutation:** *what was considered against it, and why it does or doesn't hold*
- **Disposition and destination:** *disposition, and its issue, slice, decision or residual rationale; for a consumer-driven audit, also required for unblock / independent / accepted limitation, with the rationale*
- **Dependencies:** *what must land first, or "none"*
- **Acceptance:** *the discriminating test or check that proves it's resolved*
- **Residual risk:** *what stays true after the fix, or "none"*
- **Next action:** *the next concrete step and who takes it*

## Cross-package findings

*Found here, owned elsewhere. They are intake for the owning package's audit,
not part of this package's remediation plan or counts. Security rows follow
the public-row rule.*

| ID | Owned by | Title | Severity | Affected consumers | Blocks this assessment | Destination |
| --- | --- | --- | --- | --- | --- | --- |

**Handed-off leads:** *count, and the owners; the leads are in the ledger's handoffs.*

## Refuted leads

*The count, plus only the refutations that correct an existing issue or a
likely repeat mistake, one line each. The rest are in the ledger.*

## Qualification by profile

*One row per piece of evidence; a profile may have several. Profiles and
classes are defined in the [distribution profile](profiles/distribution.md).*

| Profile | Supported | Evidence class | Evidence | Gap and owner |
| --- | --- | --- | --- | --- |
| kernel composition | yes | closure artifact | *run and job IDs, base, surfaces asserted* | |
| standalone split | *yes / no / undecided* | none | not run | *gap and owner, or decision* |

## Profile checklists

*One line per applied profile: items answered, items that are findings (IDs),
items that don't apply (one reason each), and gaps with destinations. Every
item's full answer is in the ledger.*

## Decisions and uncertainties

| ID | Decision or uncertainty | Findings | What would settle it | Who decides |
| --- | --- | --- | --- | --- |

## Remediation plan

*Proposed bounded slices for package-owned findings, in dependency order.
Each is filed as an issue before its remediation begins.*

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

*The fields in [running an audit](audit-orchestration.md).*

---

## Structured ledger

`docs/audits/packages/<package>.ledger.json` is UTF-8 JSON with LF line
endings, `schema_version` 1. Assemble it from structured agent output with a
script where possible. Keep it lean: verdicts and corrections, not verifier
transcripts. It never holds security specifics.

The shape below is enforced, not advisory: `bin/lib/package-audit-ledger.php`
is the validator and `tests/Architecture/PackageAuditLedgerTest.php` runs it
over every committed ledger, together with its record, its coverage-index row
and its retained probes. Check a draft before it leaves scratch:
`php bin/lib/package-audit-ledger.php <ledger.json> --record=<record.md> --index=docs/audits/packages/coverage-index.json`.
Keys and their order are fixed; the validator reports unknown and missing
keys, unknown enum values, dangling references and security-row leaks.

**Owner values.** `owned_by` and `owner` hold exactly one name: a Composer
package name (`waaseyaa/<name>`); `waaseyaa/framework` for the root aggregate,
CI and repository tooling; or `external:<repository>` for a downstream
application or upstream project. `co_owners` is a list of such names.
Qualifiers go in `notes`.

**Enums.** severity: critical, high, medium, low, info, withheld. confidence:
confirmed, likely, suspected. evidence_level: inventoried, reviewed,
reproduced, qualified. classification: the seven roster classifications.
checklist status: answered, does not apply, finding, gap. destination kind:
issue, slice, owner-audit, private-report, decision, residual. tier: A, B, C,
pending.

| Key | Content |
| --- | --- |
| `schema_version`, `package`, `base`, `audit_date` | as in the record header |
| `dependency_identity` | `composer_lock_sha256` (the 64-hex lock digest the index row cites), `php`, `host`, and optionally `evidence_freshness` and `consumers` |
| `milestone` | `assessed` (bool), `repair_ready` (bool), `converged` (null or `{sha, evidence}`), `reasons` (list) |
| `charter` | `question`, `answer`, `evidence` |
| `roster` | `file`, `role`, `classification`, `evidence_level`, `notes`, `findings` |
| `checklists` | `profile`, `item`, `status`, `answer`, `evidence`, `findings` |
| `intake` | `item`, `from`, `disposition`, `local_finding`, `notes` |
| `findings` | `id`, `title`, `area`, `severity`, `confidence`, `evidence_level`, `observed`, `expected_contract`, `consequence`, `refutation`, `disposition`, `destination` (`{kind, ref}` list), `dependencies`, `acceptance`, `residual_risk`, `next_action`, `discovered_in`, `owned_by`, `co_owners`, `affected_consumers` (`"withheld"` for security), `blocks_assessment`, `decision` (null or a decision ID), `consumer_unblock` (null, or required / independent / accepted limitation with rationale), `merged_into`, `verification` (`{tier, performed, reversals}`), `probes` (names; a count for security), `notes` |
| `refuted` | `id`, `lead`, `investigation`, `refutation`, `evidence` |
| `handoffs` | `id`, `lead`, `owner`, `co_owners`, `affected_consumers`, `issue`, `blocks_assessment` (always false) |
| `decisions` | `id`, `decision`, `findings`, `what_would_settle_it`, `who_decides` |
| `uncertainties` | `id`, `uncertainty`, `findings`, `what_would_settle_it` |
| `issue_reconciliation` | `issue`, `claim`, `status` at the base, `evidence` |
| `remediation_plan` | `slice`, `findings`, `acceptance`, `depends_on` |
| `qualification` | `profile`, `supported`, `evidence_class`, `evidence`, `gap_owner` |
| `probes` | `name`, `purpose`, `result`, `retained_path` (null when not committed), `reproduce` (the command) |
| `evidence_runs` | `command`, `base`, `dependency_identity`, `runner`, `host`, `proves`, `result` |
| `not_reviewed`, `host_limits` | lists of strings |
| `scorecard` | the scorecard fields |

A later audit finds its intake by reading every ledger's `findings` and
`handoffs` whose `owned_by`, `owner` or `co_owners` names its package.
