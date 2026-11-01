# Core and starter dependency readiness

Status: Draft checklist under [#3118](https://github.com/waaseyaa/framework/issues/3118).
Observed November 1, 2026. [Starter contract](../../specs/basic-app-starter.md) /
[change record](../../change-records/FW-BASIC-APP-STARTER-01.md) /
[planner](../../plans/basic-app-starter.html).

## Authority and observation

This is a readiness view of the existing convergence program, not a second
findings database or a new audit umbrella. The coverage index, package reports,
ledgers and existing issue acceptance remain authoritative. No assessment status
was advanced, person assigned, chat dispatched or issue changed by this plan.

Live GitHub coverage-index blob: `18fdd458dcbaf83993ae2ecad922d4b2d21f3181`, retrieved November 1.
[Coverage source](https://github.com/waaseyaa/framework/blob/main/docs/audits/packages/coverage-index.json).
The 22-package core roster agrees with the static closure inspected at the
starter planning baseline. Counts: three assessed/remediation planned, two in
progress/remediation planned, seventeen not assessed. Not assessed means no
completed assessment recorded in this convergence index, not never reviewed.

Live issue bodies/states checked: #3118, #3125, #3203, #3213, #3226, #2741,
#2992, all open. This bounded check is not a complete open/closed issue search or
fresh defect reproduction. Existing evidence retains its original source dates.

## Explicit package checklist

Every row is pending readiness. Existing audit owners below come from the index;
assessment destinations are proposed work-package names, not dispatched tasks.
The example issue column is deliberately non-exhaustive. A missing evidence
cell cannot be satisfied by a passing starter boot.

| Package | Recorded audit; remediation | Existing audit owner | Evidence / unresolved findings | Existing issue example | Assessment destination / readiness |
| --- | --- | --- | --- | --- | --- |
| access | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-1 / access; pending |
| audit | assessed; planned | [#3118](https://github.com/waaseyaa/framework/issues/3118) | [audit.md](https://github.com/waaseyaa/framework/blob/main/docs/audits/packages/audit.md), [audit.ledger.json](https://github.com/waaseyaa/framework/blob/main/docs/audits/packages/audit.ledger.json) | Reconcile canonical ledger/intake; not a clean bill | CA-5 / audit; pending |
| auth | not assessed; not triaged | No package assessment owner recorded | Missing | [#2992](https://github.com/waaseyaa/framework/issues/2992) | CA-1 / auth; pending |
| cache | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-4 / cache; pending |
| config | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-2 / config; pending |
| core | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-5 / core; pending |
| database-legacy | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-2 / database-legacy; pending |
| entity | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-2 / entity; pending |
| entity-storage | assessed; planned | [#3118](https://github.com/waaseyaa/framework/issues/3118) | [entity-storage.md](https://github.com/waaseyaa/framework/blob/main/docs/audits/packages/entity-storage.md), [entity-storage.ledger.json](https://github.com/waaseyaa/framework/blob/main/docs/audits/packages/entity-storage.ledger.json) | [#3213](https://github.com/waaseyaa/framework/issues/3213) | CA-2 / entity-storage; pending |
| error-handler | not assessed; not triaged | [#3203](https://github.com/waaseyaa/framework/issues/3203) | Missing | [#3203](https://github.com/waaseyaa/framework/issues/3203) | CA-3 / error-handler; pending |
| field | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-2 / field; pending |
| foundation | assessed; planned | [#3123](https://github.com/waaseyaa/framework/issues/3123) | [foundation.md](https://github.com/waaseyaa/framework/blob/main/docs/audits/packages/foundation.md), [foundation.ledger.json](https://github.com/waaseyaa/framework/blob/main/docs/audits/packages/foundation.ledger.json) | Reconcile canonical ledger/intake; not a clean bill | CA-3 / foundation; pending |
| i18n | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-4 / i18n; pending |
| mail | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-4 / mail; pending |
| plugin | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-3 / plugin; pending |
| queue | not assessed; not triaged | No package assessment owner recorded | Missing | [#2741](https://github.com/waaseyaa/framework/issues/2741) | CA-4 / queue; pending |
| routing | in progress; planned | [#3125](https://github.com/waaseyaa/framework/issues/3125) | [issue-3125.txt](https://github.com/waaseyaa/framework/blob/main/docs/audits/packages/evidence/routing/issue-3125.txt) | [#3125](https://github.com/waaseyaa/framework/issues/3125) | CA-3 / routing; pending |
| scheduler | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-4 / scheduler; pending |
| state | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-4 / state; pending |
| typed-data | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-2 / typed-data; pending |
| user | in progress; planned | [#3118](https://github.com/waaseyaa/framework/issues/3118) | [user.md](https://github.com/waaseyaa/framework/blob/main/docs/audits/packages/user.md), [user.ledger.json](https://github.com/waaseyaa/framework/blob/main/docs/audits/packages/user.ledger.json) | [#3226](https://github.com/waaseyaa/framework/issues/3226) | CA-1 / user; pending |
| validation | not assessed; not triaged | No package assessment owner recorded | Missing | Reconcile canonical ledger/intake; not a clean bill | CA-2 / validation; pending |

## Named assessment destinations

All destinations remain under FW-PACKAGE-CONVERGENCE-01 / #3118. Before dispatch,
reconcile the workboard, actual existing chats and issue intake, identify an
accountable executor and acceptance evidence destination, then record the actual
assignment. No synthetic chat or new issue is implied by these names.

- **CA-1: identity and access.** access, auth, user. Continue user evidence and
  independent verification; reuse #2992 and #3226. These repairs are not full
  assessments of auth or user.
- **CA-2: data and configuration.** config, database-legacy, entity,
  entity-storage, field, typed-data, validation. Supplement existing storage
  evidence and reuse #3213; assess unrecorded packages rather than inferring
  coverage from storage tests.
- **CA-3: composition and failures.** foundation, routing, plugin, error-handler.
  Reuse #3123, #3125 and #3203, coordinated with #3122 and CLI #3117. Assess the
  starter's changed boundaries before final extraction design is accepted.
- **CA-4: infrastructure lifecycle.** cache, i18n, mail, queue, scheduler, state.
  Reuse #2741 and all additional owners found during intake. Include real
  worker/recovery paths where claimed; do not infer qualification from boot.
- **CA-5: audit and package composition.** audit and core metapackage. Continue
  existing audit ledger; assess core's transitive declarations, optionality,
  installed discovery and compatibility rather than inventing runtime source
  review for a metapackage.
- **CA-6: default starter extensions.** CLI, AI development tools, runtime AI
  contracts and every additional first-party package in the actual default
  development/production closures. Reuse #3117, #3124 and other existing owners
  after reconciliation. The exact roster is a slice-1 output, not assumed to be
  the current broad AI closure. Newly extracted packages need assessment too.

These groups bound coordination; they are not instructions to run six agents or
combine unrelated repairs into one change. Prioritize integrity/access and
changed setup/composition boundaries, retaining existing active assignments.

## Per-package completion criteria

For every row, record links to the canonical evidence for all of the following:

1. Named assessment/execution owner and destination, exact source revision,
   dependency/lock identity, date, runner and supported installation profiles.
2. Completed assessment: charter, complete reviewed production/public-surface
   roster, actual consumers and terminal effects, specification fidelity,
   maintained-component fit, and competing/dead implementations. Static inventory
   or test-directory success alone is insufficient.
3. Each unresolved finding has its existing finding ID, remediation owner,
   linked issue/record and observable acceptance. Search/reconcile open and
   relevant closed work before proposing any new issue. Keep private evidence
   in its existing custody; link permitted summaries only.
4. Required repairs are resolved and verified. Any accepted residual needs an
   explicit authorized decision, rationale, scope and review trigger; it is not
   permission to skip an assessment or relabel a blocking defect. An issue being
   filed or closed is not sufficient evidence by itself.
5. Required independent review and exact-candidate evidence are linked. Existing
   completed assessments are reused with a delta review for changed source,
   dependencies or composition, not blindly restarted or treated as evergreen.
6. Coverage, owning report/ledger and starter checklist reconcile. Distinguish
   assessed, repair-ready, converged and installed-profile qualification.

## Starter readiness gate

Development and bounded refactoring can proceed alongside assessments once
implementation is authorized. Starter readiness/publication cannot be claimed
until both package and consumer evidence pass. Do not make completion of every
optional Framework package a blanket prerequisite for basic-starter work.

- Complete the 22 core rows and extend the checklist to the exact final basic
  starter closure, including AI and development tooling. Derive the final roster
  from actual Composer locks/installed manifests and compare against coverage;
  a missing row blocks readiness. Track third-party compatibility/security under
  existing dependency policy rather than as first-party package audits.
- Qualify the installed basic app and CMS regression journeys separately under
  BAS-01 through BAS-13. CMS-only changed boundaries need relevant assessment
  or delta review; basic closure readiness does not certify the whole CMS.
- No unfinished assessment, unowned finding, unresolved required repair or
  missing mandatory review may be hidden by a green homepage. Record the
  readiness verdict and exact source/template/lock evidence explicitly.
- Preserve separate candidate, published-package, consumer-certification and
  deployment gates. Do not close #3118 when only this starter subset is ready.

## Current result

November 1 reconciliation adds the existing October 11 AI reports/ledgers to
the coverage index: [ai-agent](ai-agent.md) and [ai-tools](ai-tools.md) remain
partial; [ai-development](ai-development.md) was assessed at its recorded base.
Their source differs from current main only in first-party version floors.
These are historical assessments, not proof for the new CMS-free closure.
The current integration carries the existing CLI noninteraction repair under
[FW-3117-CLI-IO-01](../../change-records/FW-3117-CLI-IO-01.md); the remaining
CLI charter/roster/ownership work stays under #3117. The 22 core rows above
retain their existing statuses. Extend, rather than restart, this intake for
slice 1 and perform delta assessment of the final dependency composition.

Readiness is **pending**. This document supplies a complete initial roster and
named proposed assessment destinations, not completed audits or accepted
assignments. Next work is intake/owner reconciliation and final CLI/AI dependency
mapping, followed by bounded assessments and repairs alongside starter slices.

