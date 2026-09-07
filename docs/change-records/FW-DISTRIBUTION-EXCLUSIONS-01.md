# FW-DISTRIBUTION-EXCLUSIONS-01

Issue: #2648. Candidate status: reviewed; full qualification pending.

## Contract and implementation

One declarative policy governs the existing Docker, deploy-rsync and source archive exclusion surfaces. The enduring contract is [distribution-exclusion-policy](../specs/distribution-exclusion-policy.md). Managed outputs exclude secrets, machine configuration, local agent adapters and operator state as applicable; approved source documentation remains distributable. Docker remains a distinct documentation surface.

The gate is wired into the existing preflight and CI roster. Per-workflow rsync verification requires every declared exclusion. Self-tests mutate disposable trees and prove real Git and Composer archive behavior, preserving tracked source even if interrupted.

## Review and verification

The first candidate was rejected for omitted required rsync exclusions, incomplete category coverage, tracked-file self-test mutations, absent Composer archive proof, wildcard documentation guard bypass, and scoped style defects. Cursor repaired the preserved candidate; independent review reproduced the gaps and verified their repair. Focused qualification: 74 tests, 820 assertions; diff and scoped style checks pass. Exact committed candidate full qualification is required before landing.

## Residual scope

No deploy, release or account changes. Distribution allowlist and size budgets remain #2650; raw versus compiled corpus policy remains #2661; client guidance generation remains #2660. The existing Docker-daemon secret proof remains separate.

## Hosted packaged-consumer repair

PR #2957 ci/cli-sync-rules exposed unanchored adapter exclusions dropping canonical Foundation and skeleton rule resources. Independent Git and Composer archive controls first failed for both resource paths. Root-anchored local-adapter policy repairs the distinction while retaining root adapter exclusions. Fresh exact-head qualification supersedes the original receipt after this repair.
