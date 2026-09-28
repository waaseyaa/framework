# FW-AGENT-SKILL-EFFICIENCY-01: efficient remediation and branch CI routing

- Tracking issue: #3172
- Scope: Waaseyaa maintainer skill routing and its repository contracts
- Excludes: preflight selector changes, hosted workflow changes, package code,
  releases, publication and deployment

## Problem

The delivery skill described hosted-only ownership so absolutely that it could
be read as forbidding an explicit diagnostic run. Its CI repair route assumed
a pull request even though the sprint also uses branch checkpoints and exact-SHA
workflow dispatch. The package-convergence skill had one complete audit path
but no explicit entry for implementing an already accepted finding, which made
repeating settled audit work appear safer than consuming it.

## Contract

- Governed local preflight never auto-launches a hosted-only command. A
  maintainer may run the command explicitly on a capable local host for
  diagnosis, but the result is never hosted evidence or a substitute for the
  owning check.
- A full hosted qualification remains bound to the candidate when that exact
  SHA fast-forwards to `main`. The landing receives bounded main feedback; full
  qualification repeats only after source bytes change or policy requires it.
- Hosted CI repair accepts a pull request, pushed branch checkpoint or exact-SHA
  run as its starting surface. Framework records the upstream `gh-fix-ci`
  limitation and uses direct GitHub Actions inspection without vendoring it.
- An accepted package finding enters a short remediation lane only when its
  committed evidence, ownership, decisions, dependencies and issue acceptance
  are current and settled. New, stale, disputed or materially changed evidence
  returns to the complete audit workflow.
- Remediation produces bounded code, focused positive and refusal evidence,
  one risk-based review, required hooks, exact-head qualification, and precise
  audit, ledger, index and issue reconciliation.

## Verification

`MaintainerSkillsTest` distinguishes the remediation route from the full audit
route, rejects the former absolute hosted-only wording, and binds diagnostic
and same-SHA evidence limits. Both skill directories must pass the repository
validator and skill-creator validation before installation from the landed
commit.
