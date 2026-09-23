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

| ID | Finding and evidence | Level | Consequence | Disposition | Owner | Acceptance |
| --- | --- | --- | --- | --- | --- | --- |
| `<PKG>-<AREA>-001` | *what happens, with file:line and how to reproduce* | reproduced | *who is affected* | repair / move / document / deprecate / remove / retain | #n | *the test or check that proves it's fixed* |

*For consumer-driven audits add a column: required for unblock / independent / accepted limitation.*

## Profile checklists

*For each applied profile, list each checklist item as answered (with evidence
or a finding ID) or "does not apply" (with a reason).*

## Not reviewed

*Areas, files or installation profiles not covered, and why.*

## Host limits

*Checks this host couldn't run, and the hosted job that owns them.*

## Evidence

| Command or probe | Base | Result |
| --- | --- | --- |
| | | |
