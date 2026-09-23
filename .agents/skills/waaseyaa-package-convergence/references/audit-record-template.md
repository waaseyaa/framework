# Package audit record template

Copy to `docs/audits/packages/<package>.md` and fill in. Delete the guidance
lines in italics. Keep it plain: short sentences, one finding per row.

---

# `waaseyaa/<package>` audit

- **Audit state:** not assessed | inventory only | in progress | assessed | needs delta review
- **Remediation state:** not triaged | no action required | planned | in progress | resolved | accepted residual
- **Base:** `<full SHA>`, audited `<YYYY-MM-DD>`
- **Lock / runtime:** `composer.lock` SHA-256 `<hash>`, PHP `<version>`, host `<OS>`
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

| ID | Finding and evidence | Level | Expected contract | Consequence and consumers | Disposition | Owner | Acceptance | Residual risk |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `<PKG>-<AREA>-001` | *what happens, with file:line and how to reproduce* | reproduced | *what the charter or contract says should happen* | *what breaks, and for which consumers* | repair / move / document / deprecate / remove / retain | #n | *the test or check that proves it's fixed* | *what stays true even after the fix, or "none"* |

*For consumer-driven audits add a column: required for unblock / independent / accepted limitation (with rationale).*

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
