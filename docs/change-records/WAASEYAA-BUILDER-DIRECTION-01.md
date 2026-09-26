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
