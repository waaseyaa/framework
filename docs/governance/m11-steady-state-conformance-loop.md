# M11 Steady-State Conformance Loop

## Purpose

Define the canonical steady-state governance loop for M11 so intentional changes, periodic drift detection, remediation, and evidence retention operate as one continuous conformance system.

## Authoritative Inputs

- `#999` is the front-door mechanism for intentional governed changes.
- `#1000` is the backstop mechanism for periodic drift detection.
- `#987` (authoritative invariants), `#988` (dependency expectations), `#990` (verification evidence), `#991` (resolved-history boundary), and `#993` (affected-surface record) are authoritative inputs for the loop.
- [m11-periodic-drift-scan-protocol.md](./m11-periodic-drift-scan-protocol.md) defines the periodic-scan procedure used by the backstop side of the loop.
- [m11-drift-scan-log.md](../../.github/ISSUE_TEMPLATE/m11-drift-scan-log.md) defines the repo-local logging surface for clean scans and new `C17+` findings.
- [workflow.md](../specs/workflow.md) serves as the repo-local proxy backlink for the governed-change front door; `#999` still has no dedicated repo-local artifact today.

## Loop Components

- `#999` provides the governed-change front door for intentional changes to governed surfaces.
- The loop is anchored by the authoritative inputs that define what conformity means.
- Verification covers the affected surfaces and preserves the supporting evidence.
- Governance outcomes are limited to conforming, blocked, or remediated changes.
- `#1000` provides the periodic backstop for drift that may have bypassed the front door.
- Confirmed drift becomes a new `C17+` issue and re-enters the same canonical loop after remediation.

## Loop Cycle

1. Intake an intentional governed change through `#999`.
2. Check the proposed change against `#987`, `#988`, `#990`, `#991`, and `#993`.
3. Verify the affected surfaces and collect reproducible evidence.
4. Decide whether the change is conforming, blocked, or requires remediation.
5. Merge approved work and retain the evidence with the governed-change record.
6. Run the periodic backstop scan through `#1000` on its defined cadence or release trigger.
7. Record a clean scan or open a new `C17+` issue for confirmed drift.
8. Remediate confirmed drift, verify the fix, and return to steady state.

## Governance Decision Points

- Whether the change entered through the governed-change front door or arrived as a backstop finding.
- Whether the evidence demonstrates conformance to the authoritative inputs.
- Whether the proposed change may merge, must remain blocked, or needs remediation.
- Whether a periodic scan is clean or must become a new `C17+` governance record.
- Whether remediation evidence is sufficient to restore the affected surface to steady state.

## C17+ Handling

- `C17+` identifiers are reserved for newly confirmed drift detected by the periodic backstop.
- A confirmed anomaly becomes a new M11-linked governance issue, not a silent correction.
- Remediation work for a `C17+` item must preserve the violated invariant, affected surfaces, evidence, and corrective path.
- Resolved `C1` through `C16` history remains closed under `#991` and is not reopened by routine scans.

## Enforcement Rules

- Do not bypass governed-change intake for intentional governed changes; `#999` is the required front door.
- Do not silently correct confirmed drift; periodic-scan findings must be logged as clean scans or new `C17+` records.
- Do not mutate protected canonical references during loop execution or scan logging.
- Do not treat the periodic backstop as a substitute for governed-change intake; the front door and backstop have distinct roles.

## Evidence Requirements

- Every governance decision must be supported by reproducible evidence tied to the affected surfaces.
- Every approved intentional change must retain evidence with its governed-change record.
- Every periodic scan must produce evidence for either a clean result or a new `C17+` issue.
- The canonical loop document delegates scan-procedure detail to [m11-periodic-drift-scan-protocol.md](./m11-periodic-drift-scan-protocol.md) and logging detail to [m11-drift-scan-log.md](../../.github/ISSUE_TEMPLATE/m11-drift-scan-log.md) rather than duplicating them here.

## Continuity Rule

M11 does not close. It persists as the permanent governance layer that continuously links intentional governed-change intake, verification, merge evidence, periodic drift detection, `C17+` remediation, and repeat operation.
