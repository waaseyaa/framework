# Remediation dependency plan

## Ordering rules

1. Design decisions precede public-contract or persisted-identity changes.
2. Canonical owners and red composition tests land before deleting fallbacks.
3. Consumer/package migration precedes public test API or legacy code deletion.
4. Deployment qualification follows schema/identity correctness; it cannot certify
   a candidate whose logical merge/signature rules are unresolved.
5. Each issue remains independently reviewable. This plan does not authorize merge,
   release or deployment.

## Dependency DAG

```text
D0 contract decisions
  ├─ identity: #2670 #2549 #2756 #2762 #2766
  ├─ async outcome: #2740 #2741 #2743 #2745 #2747 #2750
  ├─ optional/adapters: #1606 #1608 #2737 #2759 #2763
  └─ deploy durability: #2765

D1 canonical internals
  ├─ schema model: #1625 #2682 #2730 #2731 #2732
  ├─ transaction/effects: #2733 #2734
  ├─ runtime composition: #2729 #2760 #2764
  └─ physical identity constraints: #2753 #2754 #2755 #2762 #2766

D2 convergence and removal
  ├─ remove boot repair: #2761
  ├─ converge adapters: #2737 #2746 #2751 #2758 #2759 #2763
  ├─ typed absence: #1606 #1608
  └─ delete no-op/shadow paths: #2752 and A6-approved test helpers

D3 lifecycle qualification
  ├─ relational/identity handoff: #2548 #2549
  ├─ crash-recoverable activation: #2765
  └─ installed-consumer and supported-profile matrix: A6 assurance gates

D4 retirement
  └─ #1588 #2055 only after distribution and consumer evidence
```

## Recommended small-PR sequence

1. **Composition truth:** #2729, #2760 and #2764; add sequential kernel/provider
   tests and the logger-wiring architecture gate.
2. **Transaction truth:** #2733/#2734, then queue/broadcast/billing settlement design
   and implementations (#2740/#2741/#2743/#2747/#2750).
3. **Physical identity:** ratify #2670 and #2766 first, then domain uniqueness and
   canonicality (#2753/#2754/#2755/#2762). Run duplicate-data upgrade diagnostics
   before adding constraints.
4. **Schema authority:** #2730/#2731/#2732 with #1625/#2682; remove #2761 boot DDL
   only when migration/fresh-install ownership covers the same schema.
5. **Adapter convergence:** #2737, #2751, #2759 and #2763; then typed optional
   outcomes #1606/#1608 and malformed-input handling #2746.
6. **Deployment:** resolve #2548/#2549, ratify #2765, then run crash/restart and
   relational aggregate qualification.
7. **Distribution cleanup:** repair A6 conformance examples/hooks; delete #2752;
   retire #1588/#2055 paths only after installed-consumer and downstream proof.

## Review protocol

Every remediation PR needs: exact base/head; red boundary reproduction; real
production composition; explicit backend/lifecycle profile; negative control;
separate command exits/full logs; changed-line coverage; public compatibility and
upgrade note; independent adversarial review against the issue invariant. A green
full suite is necessary but not a substitute for the boundary proof.
