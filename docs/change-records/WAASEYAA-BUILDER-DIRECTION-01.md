# WAASEYAA-BUILDER-DIRECTION-01

Date: 2026-09-16. Documentation candidate; direction accepted by Russell.
Base: `d1e63f9de2d1300c366cf18b0f1eecd369379c1e`.

## Scope and ownership

Codex owns `README.md`, `docs/specs/builder-product-boundaries.md`, this record
and `changes/unreleased/2783.builder-product-boundaries.changed.md` in an
isolated worktree. The Studio companion uses this same stable record ID.
No existing implementation, schema, dependency graph or runtime is changed.

Related existing program: Framework #2783, governed application blueprints.
The release fragment uses that program's issue identity; this documentation does
not close or expand its implementation acceptance.

## Decision and compatibility

Framework owns reusable application and authoring capabilities. OSS Studio
composes an installable builder; a separately branded commercial product and
other independent builders consume that base. The new specification records
product ownership, shared-editor reuse, AI proposal boundaries and the
second-builder acceptance target.

Existing page-builder, SSR, site-contract and blueprint contracts remain
authoritative and unchanged. This record does not promote design or package
presence to downstream acceptance. Licensing and existing release gates remain
unchanged. Studio owns the proposed roadmap conversation.

## Verification plan

Codex owns local document qualification: check changed Markdown links, Windows
Git whitespace, and agreement with the Studio companion and existing specs.
No executable changes are in scope, so runtime suites are not appropriate.
Broaden verification if executable or generated inputs change. No independent
authentication, persistence, execution or isolation implementation review is
needed for this documentation-only candidate.

This is a local candidate, not a merge, release or hosted-verification claim.
Framework's required review, hooks and hosted gates apply before integration.

## Local qualification

Windows PowerShell checks passed for all five new local Markdown links across
the four changed documents. Windows Git `diff --check` passed. Native PHP
`bin/changelog-fragments validate` passed for all 144 pending fragments.
Cross-repository document review confirmed the same ownership and acceptance
boundaries. No runtime tests ran; both original main checkouts remained clean.

A full README link scan also encountered the pre-existing missing `LICENSE.txt`
target. The candidate does not change that link; qualification above is scoped
to new links rather than claiming the whole README has no broken links.

## 2026-09-27 integration refresh

The documentation was rebased onto current main for direct integration. Public
Framework prose deliberately describes the separate commercial repository
without publishing its private locator. The Framework and Studio companions
retain the same ownership, shared-editor and second-builder boundaries.

## 2026-10-16 hosted developer direction reconciliation

Russell retained **Waaseyaa Studio**, selected developers as the product focus
and required a hosted browser experience for the first invited users. These
decisions supersede the earlier provisional name, mandatory separate commercial
branding and website-first proposal. The proposed first slice is one supported
content application, an inspectable saved/applied model and real app-admin use.
Exact supported variation, hosting profile, cohort and commercial packaging
remain open. No runtime criteria or ownership boundaries are removed.

Codex owns this documentation lane in an isolated worktree:
- Branch `codex/studio-direction-sync`, base `38bd59f386de7234de12c2a6171c6b6f2692cc69`.
- Files: `docs/specs/builder-product-boundaries.md`, this record and
  `changes/unreleased/2783.studio-developer-direction.changed.md`.

Document links, cross-repository decision consistency and whitespace are the
qualification scope. Studio also retains JavaScript syntax, 19 DOM-emulation
checks and planner/CSS checks for its offline concept. Actual browser layout and
native dialogs remain unverified because local-file access was blocked. No PHP
runtime, provider, container, installed-cohort or hosted-user qualification is
claimed. Broaden checks only if executable product files change.

Independent review remains pending; publication is a documentation review
checkpoint, not a reviewed landing or release. Existing issue acceptance stays
open. GitHub #21 and the Studio project overview should reflect these decisions
without promoting a proposal, mockup or source inspection to shipped behavior.

## 2026-10-17 review and integration

Russell authorized review and merge of Framework #3209 and Studio #67.
Independent non-author review by `review_delivery_policy` approved Framework
`787da1341cb4e2e43da9fbf28edaf1de3aecd37d` and Studio
`38c4c11f60d5e8f845138e0604e609211adc8ad5` with no findings. The review confirmed
matching ownership, truthful mockup limits and unchanged runtime acceptance.
Framework exact-head CI run `39086114281` passed, including full qualification;
the normal local push hook passed 42 gates, with 4 not applicable and no failures.

Studio's subsequent first-slice selection is recorded in its
`docs/mvp/hosted-reference-app.md`: fixed Community Events creation, browser owner
setup, useful private admin action and ordinary stop/restart with data retained.
Framework #3073 is the first bounded code repair. Hosted profile selection and
complete pilot acceptance remain separate. This follow-up records review and
selection only; the reviewed Framework product specification is unchanged.
No runtime implementation, release or deployment is part of this landing.
