# FW-SPLIT-MAIN-FETDER-EXACT-SOURCE-01

Scope: complete the development-package split needed for FETDER's selected Framework source `9cefbdf93e771df8130ac6ae2a131dd556726b92`. FETDER's 42-package archive receipt found eight delivered packages, 28 matching locked archives, and six outstanding mirrors: `api,bimaaji,field,listing,media,workflows`. The existing split-main workflow cannot select `listing`, and a control change on main would make its exact-current-main gate reject the selected source.

## Contract

Keep the normal split-main path restricted to the exact current Framework main. Add one bounded exception for the selected source and the exact six-package cohort. The exception requires the source to remain an ancestor of current main and all `packages/` bytes to remain identical between the source and current main. The existing authorized-dispatcher, release-fan-out exclusion, package allowlist, authoritative green CI, force-with-lease, remote verification and immutable provenance gates continue to apply. Only `listing` is added to the existing narrow package allowlist.

The workflow-control commit is separate from the package-source commit. The control change must not modify package files. A split from this exception records `9cefbdf93e771df8130ac6ae2a131dd556726b92` as `source_sha`, not the later control commit. No tag, release, Packagist update, FETDER edit, or runtime acceptance is part of this change.

## Acceptance evidence

- Focused resolver and workflow contract tests fail before the change and pass afterward.
- Default preflight and exact-head hosted qualification pass for the control commit before a forward-only landing.
- The source commit remains an ancestor of main, package trees are unchanged, and both the source and control commits have their own authoritative CI evidence.
- The six-package split run succeeds and each mirror ref, split parent, package tree, source SHA and provenance artifact is verified.
- The complete 42-package handoff accounts for the previous eight, the 28 matching locks, and the six newly delivered refs without claiming FETDER installation or runtime qualification.

The source comparison and independent FETDER archive review are retained at `C:/dev/fetder/app-worktrees/social-posts-01/docs/evidence/split-adoption-20261012/`. The prior eight-package delivery evidence is retained at `C:/projects/waaseyaa-framework-publication-repair-20261011/`.
