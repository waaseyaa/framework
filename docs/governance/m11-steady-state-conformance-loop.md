# M11 Steady-State Conformance Loop

## Purpose

Define the canonical steady-state governance loop for M11 so intentional changes, periodic drift detection, remediation, and evidence retention operate as one continuous conformance system.

## Authoritative Inputs

- `#999` is the front-door mechanism for intentional governed changes.
- `#1000` is the backstop mechanism for periodic drift detection.
- `#987`, `#988`, `#990`, `#991`, and `#993` are authoritative inputs for the loop.
- [m11-periodic-drift-scan-protocol.md](./m11-periodic-drift-scan-protocol.md) defines the periodic-scan procedure used by the backstop side of the loop.
- [m11-drift-scan-log.md](../../.github/ISSUE_TEMPLATE/m11-drift-scan-log.md) defines the repo-local logging surface for clean scans and new `C17+` findings.
- [workflow.md](../specs/workflow.md) acts as the repo-local proxy backlink for the governed-change, front-door side because `#999` has no dedicated repo-local artifact today.

## Loop Components

- Governed-change intake through `#999` for intentional changes to governed surfaces.
- Evaluation against the authoritative invariants, dependency expectations, and prior resolved history.
- Verification of the affected surfaces and retention of supporting evidence.
- Governance decision on whether the change is conforming, blocked, or requires remediation.
- Merge and evidence retention for approved intentional changes.
- Periodic drift scan through `#1000` as the backstop when drift may have bypassed or escaped the front door.
- Creation of new `C17+` issues when the backstop confirms fresh drift.
- Remediation of confirmed drift, followed by re-entry into the same governance loop.

## Loop Cycle

1. Intake an intentional governed change through `#999`.
2. Evaluate the proposed change against `#987`, `#988`, `#990`, `#991`, and `#993`.
3. Verify the affected surfaces and gather reproducible evidence.
4. Make the governance decision to approve, block, or require remediation.
5. Merge approved work and retain the evidence with the governed-change record.
6. Run the periodic backstop scan through `#1000` on its defined cadence or release trigger.
7. If the scan is clean, log the evidence and continue the loop.
8. If the scan confirms new divergence, open a new `C17+` issue, remediate, verify, and return to steady state.

## Governance Decision Points

- Whether an intentional change has entered through the governed-change front door.
- Whether the evidence shows conformance to the authoritative inputs.
- Whether a proposed change may merge or must remain blocked pending remediation.
- Whether a periodic scan outcome is clean or must become a new `C17+` governance record.
- Whether remediation evidence is sufficient to return the affected surface to steady state.

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
