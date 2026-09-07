# Framework implementation-authority audit

Tracking: https://github.com/waaseyaa/framework/issues/2985
Baseline: [issue-baseline.json](issue-baseline.json) (128 open issues; 127 excluding this new audit).

## Coverage contract
Every baseline issue receives exactly one primary lane and optional cross-lane links. Every Framework package also requires coverage even if it has no open issue. An issue is not affected merely because its package contains a finding. Report separately: issue-text leads, source-confirmed affected issues, disproved leads, justified duplication, unreviewed scope and unrelated feature work. No percentage complete without a fixed denominator and independently reviewed evidence.

## Parallel work units
Divide by capability authority, not arbitrary file counts: generation and lifecycle; boot/discovery and transport adapters; identity/access/policy; data/validation/persistence; ingestion/search/content; operations/distribution/developer tooling. The inventory pass assigns primary ownership and identifies shared boundaries before runtime audit dispatch. One root integrator owns cross-lane comparisons and deduplication. Workers do not refactor during assessment.

## Required artifact for each lane
1. Pinned source SHA, lock identity, package/symbol scope and issue roster.
2. Actual production entrypoint-to-effect call paths, including registration and policy.
3. Alternatives table: implementation, callers, runtime behavior, authority, published contract and intentional differences.
4. Findings with source lines, reproduction or focused discriminating proof, confidence, impact, proposed priority and compatibility constraints.
5. Coverage ledger: reviewed/uncertain/not-reviewed with reasons.
6. Existing issue/audit deduplication and independently reviewed disposition.

## Workflow and completion
Inventory -> bounded source audit -> independent finding validation -> issue reconciliation -> separate implementation. Security/correctness issues can interrupt the affected delivery lane immediately; broad cleanup does not automatically block Studio MVP. Search historical closed issues and CL records as well as open issues. Reuse canonical scanners; no second discovery engine, blanket full-suite run, deletion or API downgrade as an audit shortcut.

A lane completes only when its assigned roster is accounted for and findings are reviewed. The full audit completes only when the complete package/capability matrix and issue-impact map have reviewed dispositions. The first Studio-related batch is explicitly partial. Final report and matrix must be committed as a repository-portable artifact; local work files are working drafts.
## Inventory handoff

The [issue inventory](issue-coverage.md) and [structured assignments](issue-coverage.json) refine the capability groups above into thirteen disjoint review lanes. These are planning assignments, not evidence that source reviews have started. The media/upload/file-storage worker has reported a source-audit checkpoint, awaiting independent review; this is not complete coverage. See [README](README.md) for phases and evidence vocabulary.
