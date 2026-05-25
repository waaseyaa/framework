# L2 Harden: waaseyaa/messaging (post-L3-graduation)

**Mission:** `l2-harden-messaging-01KSEW82`
**Status:** Stub
**Parent mission:** `l2-content-types-consolidation-01KSEFTX` · audit: `docs/audits/2026-05-l2-content-types-audit.md`

## Why this mission exists

`waaseyaa/messaging` was classified **alpha — needs hardening (pre-graduation)** in the L2 content-types audit (2026-05). WP03 of the parent mission graduates `messaging` from L2 to L3 (chat substrate). This mission covers post-graduation hardening under its new L3 identity as a chat service.

Evidence from pre-graduation audit:
- Zero `@api`-tagged classes (`MessagingServiceProvider`, `MessageThread`, `ThreadMessage`, `ThreadParticipant` all untagged).
- Only 3 test files — the lowest test count of any substantive L2 package.
- Admin SPA references limited to i18n translation files (fr.json, en.json) — no functional admin pages for thread management.
- Source tree is minimal (4 PHP files total); the chat substrate contract is not documented.

Target Anokii surface: **Anokii Chat**.

## Pre-resolved decisions

- Post-graduation: package sits at L3 (`"messaging": 3` in `bin/check-package-layers` after WP03 lands).
- No regression to L2; hardening work begins only after WP03 is merged.
- Follow M5A pattern for new admin SPA API surface.
- The `docs/specs/messaging.md` spec created by WP03 is the contract baseline for this mission.

## Suggested WPs

- WP01: Add `@api` on `MessageThread`, `ThreadMessage`, `ThreadParticipant`; expose a `MessagingServiceInterface` for consumer injection; verify dead-code gate passes.
- WP02: Expand tests to ≥ 8 files; cover thread creation, message append, participant management, and access policy.
- WP03: Add admin SPA chat thread management pages (thread list, message moderation); wire to JSON:API endpoints.
- WP04: Verify `bin/check-package-layers` green at L3; confirm no L2 package imports messaging (enforced by WP03 gate).

## To be specified in implement-review

Real WP prompt files, acceptance criteria, and requirement IDs land in this mission's own `spec-kitty plan` + `spec-kitty tasks` invocations. This mission should not begin until `l2-content-types-consolidation-01KSEFTX` WP03 is merged.
