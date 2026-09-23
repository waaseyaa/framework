# Package audit record template

Copy to `docs/audits/packages/<package>.md` and fill in. Delete the guidance
lines in italics. Keep it plain: short sentences, one entry per finding.

---

# `waaseyaa/<package>` audit

- **Audit state:** not assessed | inventory only | in progress | assessed | needs delta review
- **Remediation state:** not triaged | no action required | planned | in progress | resolved | accepted residual
- **Base:** `<full SHA>`, audited `<YYYY-MM-DD>`
- **Dependency identity:** `composer.lock` SHA-256 `<hash>` at the base; PHP `<version>`, host `<OS>`
- **Evidence freshness:** *current at `<SHA>`* | *needs delta review: package source changed since the base in `<commits>`*
- **Owner issue:** `waaseyaa/framework#<n>`; program #3118
- **Profiles applied:** *e.g.* persistence-execution, distribution. **Not applied:** *each with a one-line reason*

## Charter

- **Owns:**
- **Does not own:**
- **Consumers:** *runtime, generated applications, split-package users; name real ones*
- **Dependencies:** *required / optional / adapters*
- **Public surface:** *symbols and wire contracts, with their lifecycle*
- **Evidence it works:** *source and distributed form*

## Roster

*Every production file. Group by directory if large, but no file may be missing.*

| File | Role | Classification | Evidence level | Notes |
| --- | --- | --- | --- | --- |
| `src/Example.php` | *what it does* | owned and coherent | reviewed | |

*Classifications:* owned and coherent · necessary but under-specified ·
duplicated or drifting contract · wrong package or layer · unwired or obsolete ·
optional/required misrepresented · missing refusal, lifecycle, compatibility or
distribution evidence.
*Evidence levels:* inventoried · reviewed · reproduced · qualified (name the
installation profile).

## Findings

*Summary first, then one detail block per finding. Include refuted leads: a
lead you investigated and disproved stays in the ledger with its refutation, so
nobody re-investigates it.*

| ID | Title | Severity | Confidence | Level | Disposition | Owner | Next action |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `<PKG>-<AREA>-001` | *one line* | critical / high / medium / low | confirmed / likely / suspected / refuted | reproduced | repair / move / document / deprecate / remove / retain / refuted | #n | *the next concrete step, or "none"* |

### `<PKG>-<AREA>-001`: *title*

- **Observed, with evidence:** *what happens, file:line, how to reproduce*
- **Expected contract:** *what the charter or contract says should happen*
- **Consequence and consumers:** *what breaks, for which runtime, generated or installed consumers*
- **Severity and confidence:** *why this severity; what would raise or lower the confidence*
- **Refutation:** *evidence considered against the finding, and why it does or doesn't hold; "none considered" is not acceptable for a refuted row*
- **Disposition and owner:** *disposition, owning issue, and for consumer-driven audits: required for unblock / independent / accepted limitation, with rationale*
- **Dependencies:** *findings, issues or contracts that must land first, or "none"*
- **Acceptance:** *the discriminating test or check that proves it's resolved*
- **Residual risk:** *what stays true after the fix, or "none"*
- **Next action:** *the next concrete step and who takes it*

## Profile checklists

*For each applied profile, list each checklist item as answered (with evidence
or a finding ID) or "does not apply" (with a reason).*

## Not reviewed

*Areas, files or installation profiles not covered, and why.*

## Host limits

*Checks this host couldn't run, and the hosted job that owns them.*

## Evidence

*One row per run. Reused evidence keeps its original base, dependency identity
and runner; don't merge overlapping runs into one "unique" count.*

| Command or probe | Base | Dependency identity | Runner | Proves | Result |
| --- | --- | --- | --- | --- | --- |
| | *full SHA* | *lock SHA-256, or package versions* | *host, OS, PHP; local or hosted run ID* | *source read / synthetic probe / injected integration / installed consumer* | |
