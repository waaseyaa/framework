# Waaseyaa roadmap

Updated 9 October 2026. Waaseyaa is alpha; this roadmap sequences work, not
released capability. [Specs](specs/workflow.md) own contracts, change records own
durable scope/evidence, and [GitHub issues](https://github.com/waaseyaa/framework/issues)
own execution status. Product roadmaps and adoption decisions belong downstream.

## Current: Framework convergence

[#3118](https://github.com/waaseyaa/framework/issues/3118) coordinates package,
composition, specification and repository convergence. Use the
[coverage index](audits/packages/coverage-index.json) and
[existing-owner reconciliation](audits/packages/backlog-reconciliation.md).
Resolve confirmed defects and required repairs, qualify supported cross-package
journeys, retire superseded docs/code and reconcile issue status. A clean board
means defects resolved with evidence and future features separately triaged,
not merely closing or relabelling unresolved findings.

Listing's bounded repairs and messaging's completed repairs retain their proof;
remaining messaging domain/profile decisions proceed through shared social design.
Framework-wide convergence is not yet complete. New feature absence alone is not
a defect, and this program does not block unrelated delivery.

## Next sprint: installation to homepage

[#3199](https://github.com/waaseyaa/framework/issues/3199) is ready for assessment:
empty-directory Composer creation, initialization, cold/warm homepage, failure
controls and production no-dev composition. Read the [journey contract](specs/framework-audit-journeys.md)
and [Sprint 1 handoff](change-records/FW-PACKAGE-CONVERGENCE-01.md#sprint-1-contract).
Runtime execution has not begun. Existing urgent repairs continue; later feature
design does not block the baseline. Package coverage and journeys are complementary.

## Next design slice: reusable social capabilities

[#3197](https://github.com/waaseyaa/framework/issues/3197) and
[FW-SOCIAL-CAPABILITIES-01](change-records/FW-SOCIAL-CAPABILITIES-01.md) coordinate
the [social contract](specs/social-capabilities.md). Scope is accepted for planning;
transition defaults and runtime delivery remain pending.

| Phase | Outcome | Exit evidence |
| --- | --- | --- |
| 1. Contracts and ownership | Identity, visibility, consent/blocking, relationship and lifecycle tables; comments/replies explicitly included | Owning specs amended, defaults ratified, existing issues mapped; suitable Symfony components evaluated |
| 2. Foundations | Required access, integrity, retry and lifecycle repairs in existing packages | Discriminating failure/revocation/concurrency controls; no competing authorities |
| 3. Bounded social journeys | Posts/feeds/comments/reactions, membership, conversations and notifications in dependency order | Each accepted slice works across its owning packages; missing future features remain explicit |
| 4. Installed qualification | Supported kernel applications with selected opt-in packages and production no-dev installs | Exact installed versions, real HTTP/session and trusted non-HTTP journeys, negative controls and upgrade effects |

Existing owners remain #2756 (engagement), #1627/#2762/#1957 (groups),
#2745/#1624 (notifications), #3005/#2859 (listing composition), and #2055
(downstream dead paths). Messaging audit D2/D3 remain explicit decisions.
#2054 is separate AI/corpus chat, not human conversation ownership.

## Later and release boundaries

No dates or new version promises are assigned here. Federation, voice/video,
algorithmic ranking, paid contact and end-to-end encryption claims are excluded
from this initial social contract. Broader planned capabilities remain in their
existing issues. Release eligibility follows the [stability charter](specs/stability-charter.md)
and [S1 support contract](specs/s1-support-lifecycle.md), not the old milestone
narrative. A reviewed documentation or code commit is not package publication,
consumer certification or deployment.
