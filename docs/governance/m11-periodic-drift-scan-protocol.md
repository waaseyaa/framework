# M11 Periodic Drift-Scan Protocol

## Purpose

Define the canonical M11 steady-state process for periodically scanning the repo for governance drift, recording clean scans with evidence, and escalating confirmed anomalies into new C17+ issues under M11.

Note: this protocol is the backstop component of the broader M11 steady-state conformance loop; see [m11-steady-state-conformance-loop.md](./m11-steady-state-conformance-loop.md).

## Authoritative Inputs

- `#987` is the authoritative governance reference for the M11 drift-scan loop.
- `#986` is the execution baseline and rollback-safety reference for the scan.
- `#993` is the M10 execution proof record and closure baseline for inherited execution evidence.
- `#991` is resolved history for `C1` through `C16`; those cases remain closed and are not reopened by routine scans.
- `#988` provides the governing invariants that anomalies must be evaluated against.
- `#990` provides the dependency expectations that anomalies must be evaluated against.
- `#999` is the governed-change identity for the front-door mechanism used when intentional governed changes are recorded.
- [.github/ISSUE_TEMPLATE/m11-governed-change.md](../../.github/ISSUE_TEMPLATE/m11-governed-change.md) is the repo-local template used to instantiate that front-door mechanism for every new or modified governed surface since the last scan.
- The current repo state, drift detector output, milestone hygiene report, and the affected governed surfaces are the operational inputs for each scan.

## Scan Cadence

- Run a full drift scan every 4 weeks.
- Run the same protocol at release-candidate boundaries and at release-cut boundaries.
- If a scan is triggered by a release boundary, scope it to the surfaces touched by that boundary and expand as needed to cover adjacent governed surfaces.

## Responsible Role

The scan is owned by the active conformance governor or, when that role is not engaged, the release steward.

## Required Verification Inputs

Every scan must include:

- `bin/check-milestones` for milestone hygiene.
- `bash tools/drift-detector.sh 5` for drift detection.
- Inspection of changed routes, providers, manifests, contracts, adapters, and registries.

The steward selects targeted PHPUnit, Node, and integration suites based on the affected surfaces. The baseline suite options below are examples that can be narrowed or expanded, but they are not mandatory for every scan unless the affected surfaces require them.

## Scan Procedure

1. Confirm the current scan trigger, the owning role, and the affected surfaces.
2. Run `bin/check-milestones` and review the report for milestone drift or hygiene regressions.
3. Run `bash tools/drift-detector.sh 5` and capture the output for spec and governance drift.
4. Inspect the changed routes, providers, manifests, contracts, adapters, and registries that could explain the scan surface.
5. Verify that each new or modified governed surface since the last scan has a governed-change record instantiated from the `m11-governed-change` template.
6. Run the targeted PHPUnit, Node, and integration suites that cover the affected surfaces.
7. Evaluate any anomalies against the `#988` invariants and the `#990` dependency expectations before classifying the result.
8. Classify the result using the drift rules below.
9. Log the outcome with evidence, even when the scan is clean.

## Drift Classification Rules

- A clean scan means the reviewed surfaces match the authoritative inputs and no unresolved drift is detected.
- Confirmed divergence becomes a new C17+ issue under M11 after it has been evaluated against the `#988` invariants and the `#990` dependency expectations.
- Historical `C1` through `C16` items remain resolved and are not relabeled as fresh drift.
- Unknown or suspected divergence is not a clean scan; it remains under review until the evidence supports a clean result or a new C17+ issue.

## Clean-Scan Logging

Log every clean scan under M11 with:

- the scan date,
- the responsible role,
- the commands run,
- the governed surfaces reviewed,
- and evidence links or command output references.

The log entry must state that the scan completed cleanly and that no new drift was confirmed.

## C17+ Drift Logging

When the scan confirms a new anomaly:

- open a new M11-linked issue with a provisional `C17+` identifier,
- assign that identifier by taking the next available `C<number>` in the open/resolved governance log sequence at issue-creation time, then adjust only if a race is discovered,
- record the violated invariant,
- name the affected surfaces,
- attach the evidence,
- and include a remediation note that explains the next corrective step.

Do not convert a confirmed anomaly into a clean scan entry. Clean scans and drift findings are separate outcomes.

## Invariants

- `#987` remains the canonical M11 reference.
- `#991` keeps `C1` through `C16` closed and resolved.
- Every periodic scan produces either a clean-scan log entry or a new `C17+` issue with evidence.
- M11 governs the steady-state drift-scan loop; no alternate governance path supersedes it for this workflow.
- Any confirmed anomaly must be logged with enough detail to reproduce the result and plan remediation.

## Verification Commands

These are the baseline suite options for M11 drift scans:

```bash
bin/check-milestones
bash tools/drift-detector.sh 5
vendor/bin/phpunit
cd packages/admin && npm test
```

Use `bin/check-milestones` and `bash tools/drift-detector.sh 5` on every scan. Treat the PHPUnit and Node commands as baseline suite options to narrow or expand based on the governed surfaces affected by the scan.
