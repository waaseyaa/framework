# S1 support and lifecycle implementation plan

Status: active  
Anchor: waaseyaa/framework#2336  
Finding: `F-REL-002`  
Specification: `docs/specs/s1-support-lifecycle.md`

## Outcome

Replace inferred platform support with a versioned, machine-checked S1 contract.
The framework will prove one explicit test point while keeping the downstream
consumer certification point visibly pending. No release, deployment, database
exercise, or H1 implementation is part of this work package.

## Work

1. Add an architecture test that requires the contract, lifecycle/security
   policy, executable checker, explicit Ubuntu 24.04 support job, and parity with
   PHP, Node, SQLite, and Playwright declarations. Capture the initial failure.
2. Add `support/s1-v1.json` with bounded axes, dependency roles, upstream
   horizons, review timing, unsupported surfaces, and separate framework versus
   consumer evidence states.
3. Add `SECURITY.md` describing supported alpha handling and private
   vulnerability reporting without promising a response-time SLA.
4. Add `bin/check-support-contract`. It must parse structures rather than rely
   only on substring tests, reject unknown/missing fields, compare executable
   constraints and lock data, and fail closed on stale review metadata.
5. Add an explicit `ubuntu-24.04` CI job that installs PHP 8.5 and Node 24,
   records exact PHP, Composer, Node, npm, SQLite, architecture, OS, and
   Playwright versions, and runs the checker. Keep consumer certification
   pending.
6. Run the focused architecture test, checker, YAML parse, Composer validation,
   architecture suite, and the repository's required split verification. Use
   exact-head GitHub CI as the remote authority.
7. Obtain an independent read-only review, remediate validated findings, and
   open one draft PR for this work package with immutable evidence recorded in
   the assurance repository.

## Acceptance

- Humans and automation identify exactly one candidate profile: S1.
- Every claimed axis is bounded and linked to executable or explicitly pending
  evidence.
- H1 and untested database, filesystem, web-runtime, and browser combinations
  are visibly unsupported.
- Framework CI uses an explicit OS test point and cannot pass when declarations
  drift.
- Lifecycle and vulnerability handling are documented without an invented SLA.
- The work remains a draft review candidate; no merge, tag, release, deployment,
  production mutation, or recovery exercise occurs.

