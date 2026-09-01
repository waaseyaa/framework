# Framework assurance gates and current verdicts

`PASS` means the audited evidence supports the stated bounded contract. `FAIL` means
a reproduced defect or unresolved design owner exists. `PARTIAL` means positive
controls passed but required variants remain unqualified. `NOT REVIEWED` is never
treated as pass.

| Gate | Required evidence | Current verdict | Owners |
|---|---|---|---|
| G1 Canonical composition | Real kernel/provider wiring; sequential compositions; no test-only injection/reset dependency | FAIL | #2729 #2760 #2764 #2771 |
| G2 Semantic-to-physical invariant | Stable IDs, uniqueness, canonical values and lifecycle flags enforced at persistence boundary | FAIL | #2670 #2753–#2756 #2762 #2766 |
| G3 Transaction/durable outcome | PRE guards inside transaction; POST/effects only after outer commit; fenced ack/retry | FAIL | #2733 #2734 #2740 #2741 #2743 #2745 #2747 #2750 |
| G4 Schema lifecycle parity | Fresh/upgrade/replay/rollback/preview/apply share one transition model on supported layouts | FAIL | #1625 #2682 #2730–#2732 #2761 |
| G5 Deployment recovery | Nonempty relational fixtures; identity mapping; kill/restart at every activation phase; directory durability | FAIL | #2548 #2549 #2765 |
| G6 Authorization/read custody | Explicit grant, Forbidden wins, audited privileged reads and fail-closed missing authority through real routes | PARTIAL | A2; #2729 #2766 |
| G7 Observability | Production logger reaches every catch/downgrade; failures have typed state and recovery path | FAIL | #1608 #2763 and A7 logger cluster |
| G8 Adapter convergence | Shared validation/transport/config/policy; typed optional absence; no success-shaped placeholder | FAIL | #1606 #1608 #2737 #2746 #2751 #2759 |
| G9 Storage-profile honesty | Each entity/feature declares supported layouts; supported layouts pass real round trips; unsupported ones refuse | PARTIAL | A1/A2; #2682 |
| G10 Worker/request isolation | Principal, field-read, Inertia and schema/cache state cleared or composition-scoped across sequential requests | PARTIAL | A3; #2764 |
| G11 Distributed consumer truth | Sealed split install, no-dev boot, extension authoring examples and negative conformance controls | PARTIAL | A6 #2726 #2055 |
| G12 Safe retirement | Runtime/test/tool/generated/distribution/downstream inventory, replacement, migration and absence guard | FAIL | #1588 #2055 |
| G13 Evidence integrity | Exact SHA/lock/runner; separate exits/full logs; no masked aggregate result; baseline counterexample | PASS (audit process) | A0/A6 |
| G14 Admin committed bundle | Source signature, acceptance manifest/markers and coercion scanner+self-test | PASS (committed bytes) | A4 |
| G15 Platform/runtime matrix | Linux, Windows, frontend, worker and Pi where supported | PARTIAL | #2676; A3/A6 |

## Proposed repository gates

1. `check-production-provider-composition`: boot each provider cohort through the
   real kernel and assert advertised bindings resolve; ban production `NullLogger`
   fallback for failure-catching collaborators.
2. `check-persisted-invariant-owners`: every declared stable/natural identifier and
   lifecycle immutability flag maps to storage metadata plus a duplicate/bypass test.
3. `check-sequential-composition-isolation`: build two kernels with different config,
   definitions and principals in one process; the second must observe only its state.
4. `check-transaction-outcome-boundary`: run repository/effect/queue settlement both
   standalone and inside an already-open transaction, including outer rollback.
5. `check-schema-lifecycle-matrix`: fresh, populated upgrade, replay, rollback,
   preview/apply and opaque-op cases on every supported layout.
6. `check-crash-recovery-phases`: child-process termination at persisted deploy phases,
   then real recovery in a fresh process; POSIX directory fsync ordering inspected.
7. `check-installed-consumer-contracts`: build sealed splits, install dev/no-dev
   consumers, execute documented extension examples and deliberate bad plugins.
8. `check-retirement-proof`: require a machine-readable consumer/distribution roster,
   replacement locator and absence guard before deleting public or shipped paths.

These gates should be introduced with self-tests and bounded runtime cost. They must
not weaken existing preflight, coverage, admin-dist or package-boundary checks.
