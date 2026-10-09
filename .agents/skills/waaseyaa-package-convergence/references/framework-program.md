# Run the Framework convergence program

Use this mode for program planning and cross-package journeys under #3118 /
FW-PACKAGE-CONVERGENCE-01. Keep the package method and delivery skill; do not
create another audit system or reset completed assessments.

## Establish one queue

Refresh all open issues/PRs and active Project items with completeness checks.
Use repository-reconciliation.md for dispositions and spec/document traceability.
One issue owns each defect; program issues group work, not duplicate acceptance.
Native sub-issues encode ownership; blocked-by edges require a demonstrated
acceptance dependency. A related issue is not automatically a blocker.

Retain one readiness carrier and one explicit priority per issue. Missing
readiness is Needs Triage, not Ready. Review priority is distinct from finding
severity. Resolve conflicting carriers explicitly; preserve milestone, release
membership and ordering as separate axes. Apply board synchronization only from
its reviewed, verified plan. Future features never absorb unresolved defects.

## Select the next journey

Read docs/specs/framework-audit-journeys.md and the existing program change record.
Choose a bounded observable outcome and supported installation/host profiles.
Map each transition to its owning spec/package and existing evidence. Run package
coverage alongside journeys: unused optional packages still need disposition.
A happy homepage does not prove the whole Framework, and a file census does not
prove a real application works.

Start with the documented published installation. Record template revision,
resolved packages/lock and actual runtimes. Keep current-source candidate probes
separate; never selectively overlay vendor or borrow another checkout's vendor.
Distinguish development-host support from production certification.

## Close one bounded loop

1. Revalidate the journey's current boundary and inherited findings.
2. Settle ambiguous intended behavior in owning specs before repair. Inspect
   Symfony fit and competing/dead authorities at the affected boundaries.
3. Capture failure/refusal evidence and assign the existing root-cause owner.
4. Prepare a small repair slice with acceptance, dependency and verification
   scope. Ready for assessment does not mean ready for implementation.
5. Use waaseyaa-delivery for authorized repair, immutable independent review and
   qualification. Reuse unaffected audit evidence instead of rediscovery.
6. Reconcile package ledgers, journey evidence, docs/issues and Project mirrors.
   Close only the acceptance actually delivered. Release remains separate.

## Sprint handoff

A sprint can start when its outcome, initial executable baseline, owner, profiles,
acceptance, test-selection and stop conditions are recorded; required design
choices for that first action are settled. Findings discovered during the audit
need not be known in advance. Do not pre-authorize unknown repairs or require
whole-program design completion before starting a bounded assessment.

Limit concurrent repair work to what can be reviewed and qualified coherently.
Parallel agent work requires authorization; this mode does not authorize it.
Report assessment, repair readiness, landed repairs and journey qualification
separately. A sprint is not complete while its required qualification is unknown.
