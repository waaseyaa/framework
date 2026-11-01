# FW-AI-FIRST-DELIVERY-01

Reconciled November 1, 2026 against Framework main `c4aff57585`.
Existing owners: #2653 delivery, #3118 package convergence, #3117 CLI,
#2660/#2664 generated guidance lifecycle, #2686 root guidance ownership,
#2661/#2662 corpus/search, #2663 client descriptors and #2665 installed acceptance.

## Retained evidence and current scope

The October 11 audit commit `19c77027bd4fe2730ba21627aa30b3867fdcff9b`
contained useful package reports and ledgers. Those six artifacts are retained
at `docs/audits/packages/ai-{agent,tools,development}.*`, with their original
base, dates, findings and limitations. ai-agent and ai-tools are partial audits;
ai-development was assessed as a two-file metapackage at that historical base.
The coverage index reflects that historical evidence, not current convergence.
Package source is unchanged except first-party version floors .305 to .308;
current dependencies and the proposed CMS-free closure still need delta review.

The old program's broad landscape, draft forge issue and dispatch briefs are
superseded by this reconciliation and FW-BASIC-APP-STARTER-01. They remain in
the original Git commit, not a parallel active plan or preservation branch.
Existing ADR-022 production/local-operator boundaries and ADR-025 generation
authority remain current. Historical proposals to revise them are not adopted
by importing audit evidence.

## Disposition of the useful unfinished work

| Work | Current disposition |
| --- | --- |
| Real argv bypass of Symfony configureIO | Carry the reviewed one-line repair and regression tests from bcd9f910a; FW-3117-CLI-IO-01 owns evidence. Main still reproduced both flag failures before the repair. |
| Root AGENTS.md region collision and partial install writes | Existing #2686 and #2660/#2664 own the generation contract. CLI refusal repair preserves the colliding human file but is not installer atomicity. Reconcile these owners before shared-source extraction. |
| Client configuration, corpus/search and upgrade/remove journey | Retain existing #2661-#2665 scope. This starter plan does not claim these journeys complete or make every broader AI feature a starter prerequisite. |
| AI package public surfaces, adapters and unreviewed subsystems | Partial ledgers stay under #3118. Extend BAS-14 to the final development and runtime closure; do not restart unaffected findings. |
| Runtime AI for a no-dev basic app | Slice 1 decides ownership using existing interfaces. Keep ai-agent/local-operator authority development-only and prove deterministic provider dispatch without paid calls. |

Both starters include AI by default. The basic app must exclude CMS packages
from both lock sections, including the AI development closure. New implementation
follows the bounded slices in [the starter record](FW-BASIC-APP-STARTER-01.md).
No package release, deployment, audit completion or issue closure is implied.
