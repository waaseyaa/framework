# L2 Content-Types Consolidation — audit, hardening hand-offs, messaging→L3 graduation

**Mission:** `l2-content-types-consolidation-01KSEFTX`
**Status:** Spec
**Target branch:** `main`
**Tracks:** No GitHub issue at filing time. The mission is the source-of-truth for execution. The downstream effect is mission-list: this mission ships an audit document + one architectural change (messaging graduates to L3) + a list of follow-up missions per L2 package that needs hardening.
**Pattern reference:** M5A `ai-observability-dashboard-01KSE9BX` for spec/plan/tasks/wps.yaml shape. The audit-mission shape is similar to the API parity matrix mission (`api-surface-consolidation-jsonapi-primary-01KSEFTV` WP03) — enumerate, classify, emit follow-up mission slugs.

## Why this mission exists

**L2 (Content Types)** today carries 13 packages per the inventory + CLAUDE.md layer table: `node, taxonomy, media, path, menu, note, relationship, groups, engagement, messaging, attachment` — plus `structured-import` per the inventory listing (which sits at L2 despite the CLAUDE.md table not explicitly listing it; the orchestration table treats it as part of the work-surface group). That's a lot of surface for "content types," and the inventory + gap-matrix flag a mix of states:

- Some packages are production-shaped and actively extended (e.g. `node`, `media`, `taxonomy`).
- Some are alpha and need hardening (e.g. `relationship`, `engagement`).
- Some have unclear status — they exist with READMEs but no obvious consumer in the current admin surfaces.
- One — `messaging` — was placed at L2 because "threads + messages + participants" looks like a content type. But on inspection, messaging is the substrate for the chat surface (gap-matrix capability **C-1 — chat surface**, which the Anokii distribution needs as its workspace's primary collaboration channel). Chat is a service, not a content type — it has read-receipt semantics, presence, real-time broadcast, and routing rules that L2 content-type infrastructure does not provide.

The Hard Rules pre-resolve the architectural questions here:

1. **KEEP each L2 package as a separate composer package.** Merging `node + taxonomy + path + menu` into a giant `waaseyaa/cms-content-types` metapackage would re-introduce a Drupal-monolith shape that the framework was deliberately decomposed to escape. Distributions need the granularity.
2. **CONSOLIDATION here means: standardise on EntityType registration patterns, document each package's intended productivity-surface mapping (Anokii's surfaces — Anokii Wiki, Anokii Files, Anokii Chat, etc.), and identify dead-or-near-dead L2 packages.** The output is an audit document + per-package classification + per-needs-hardening package a follow-up mission scaffold.
3. **GRADUATE `messaging` to L3 as a chat service.** Per gap-matrix C-1 (chat surface) and the cleaner abstraction logic: messaging-as-service is the right shape for Anokii Chat, supports per-thread access policies, and admits a real-time broadcast + presence add-on. The package moves from L2 to L3, the layer file updates, route registration moves to a new `MessagingRouteServiceProvider`, and the L3 home unblocks the future Chat substrate work (a separate mission).

This mission does NOT touch the source code of audited-but-healthy L2 packages, does NOT fix the "needs hardening" packages itself (those are follow-up missions), and does NOT remove any code from packages classified as dead (each dead-package follow-up handles its own removal with its own charter-bearing decision).

## Scope

### In scope

**WP01 — Audit + classification document:**
- Produce `docs/audits/2026-05-l2-content-types-audit.md` covering each of the L2 packages enumerated by inventory + CLAUDE.md layer table. For each package, fields:
  - **Package:** `waaseyaa/<name>`.
  - **Source state:** brief — entity types registered, controllers exposed, public API classes, tests present.
  - **Consumer surfaces:** which admin SPA pages or kernel-services consume it (cross-reference `packages/admin/app/` and `packages/api/src/Controller/`).
  - **Recent activity:** number of commits in the package over the last ~3 months (informational only; not a classification driver).
  - **Anokii productivity-surface mapping:** which Anokii surface (per the parallel charter / anokii-distribution-scaffold work) this package is intended to power. If none clearly maps, "framework substrate (no distribution-specific surface)".
  - **Classification:**
    - **production-ready** — actively extended, has consumers, has tests, has a clear spec or README.
    - **alpha — needs hardening** — exists, has structure, but missing pieces (no consumer, no spec, sparse tests, or known dead-code-baseline entries). A follow-up mission is filed for each.
    - **dead — propose removal** — exists in the tree but has no consumer, no tests of substance, no clear future role. A follow-up mission is filed proposing removal with the appropriate charter-bearing rationale.
  - **Decision rationale (one paragraph):** why the classification.
- The audit MUST cover every package in the L2 row of CLAUDE.md's layer table plus `attachment` and `structured-import` if they currently sit at L2 (read the layer table at edit time; the wording was last updated in the M4A-5 wave so `attachment` is part of work-surface, not L2 strictly — verify).
- The audit document is the deliverable; it lives in `docs/audits/`, not `docs/specs/` (it's a snapshot, not a long-lived spec).

**WP02 — File follow-up missions for needs-hardening + dead packages:**
- For each L2 package classified as **alpha — needs hardening** in WP01: file a fresh mission scaffold (`spec-kitty specify l2-harden-<package>-<short-ULID>`). The mission's spec.md is a stub describing the gaps + the hardening scope (e.g., "add EntityType registration test", "wire to admin SPA", "extract dead-code baseline entries"). The mission's WPs are scoped separately and live outside this mission.
- For each L2 package classified as **dead — propose removal**: file a fresh mission scaffold (`spec-kitty specify l2-remove-<package>-<short-ULID>`). The mission's spec.md describes the removal rationale, the charter-bearing decision context (DIR-X if applicable), the migration path for any consumer (likely none, since the package is classified dead), and the gate verification (`bin/check-package-layers` must remain green after removal).
- Update the audit document's "Follow-up mission" column with the slug per row.
- Update `kitty-specs/_wave-plan.md` (if present) with the per-package follow-ups under the appropriate wave.

**WP03 — Messaging → L3 graduation:**
- Architectural change: `packages/messaging` graduates from L2 (Content Types) to L3 (Services). This is the only code-bearing WP in the mission.
- File changes:
  - `CLAUDE.md` — update the layer-table row: remove `messaging` from L2 (Content Types), add to L3 (Services). The new L3 row reads `workflows, search, seo, notification, billing, github, migration, northcloud, listing, messaging`.
  - `bin/check-package-layers` configuration (the in-script layer map) — update the L2 / L3 sets so `messaging` is at L3. Verify the gate stays green.
  - `packages/messaging/composer.json` — no functional change; the package remains `waaseyaa/messaging`. The layer is metadata, not a require constraint.
  - `packages/messaging/README.md` — update the `**Layer 2 — Content Types**` line to `**Layer 3 — Services**` and add a one-paragraph "Why L3" rationale: messaging is the substrate for the chat surface (per gap-matrix C-1); chat is a service abstraction, not a content type; the graduation supports future real-time broadcast + presence add-ons.
  - Route registration: if any messaging routes are currently registered via direct L4 wiring, they continue to work (L4 → L3 is downward-clean). If routes were registered via L2-specific scaffolding, lift them to a `Waaseyaa\Routing\MessagingRouteServiceProvider` (analogous to `AuthOidcRouteServiceProvider`). **Read first** — most likely no change is needed because routes already register via L4. If a lift is needed, it counts as part of WP03.
  - `docs/specs/` — add a new spec `docs/specs/messaging.md` if one doesn't exist, documenting the L3 graduation rationale + the chat-substrate role + the per-thread access-policy model. Stamp with the mission slug.
  - Add a `## Out-of-scope` section in `docs/specs/messaging.md` listing real-time presence, read-receipt UI, federated XMPP-bridge, etc. — each is a follow-up mission, not in this mission.
- The future "Chat substrate / Anokii Chat surface" mission is **separate** — it builds on the L3 home but is not in this mission's scope. WP03's deliverable is the layer move + spec, not the chat surface itself.

### Out of scope

- Implementing the per-package hardening for any L2 package classified as alpha. Each is a follow-up mission.
- Implementing any L2-package removal for any package classified as dead. Each is a follow-up mission.
- Building the chat surface. This mission ships only the L3 graduation; the chat surface (Anokii Chat) is a separate post-WP03 mission.
- Merging any L2 packages into a metapackage. The CMS-style group (node + taxonomy + path + menu) stays as four separate packages. The content-attachment group (note + attachment + media) stays as three separate packages.
- Changing `engagement` package's home. Engagement is a content-type abstraction (likes, reactions, comments — per-entity engagement signals); it stays at L2.
- Modifying the `relationship`, `genealogy`, or `groups` packages' architecture. Their audit classification + follow-up mission per-package handles them.
- Editing `.kittify/charter/charter.md`. The messaging→L3 graduation is a layer-map adjustment, not a charter directive; if a future need arises to constitutionalise the chat-as-service stance, that is a separate charter-amendment mission.

## Requirements

| ID | Type | Requirement |
|---|---|---|
| FR-001 | functional | `docs/audits/2026-05-l2-content-types-audit.md` exists and covers every L2 package per the CLAUDE.md layer table (verified at edit time). For each package: source state, consumer surfaces, Anokii productivity-surface mapping, classification, decision rationale, follow-up mission slug (if any). |
| FR-002 | functional | Every L2 package classified as **alpha — needs hardening** has a corresponding `kitty-specs/l2-harden-<package>-<short-ULID>/spec.md` filed via `spec-kitty specify`. The audit document's "Follow-up mission" column carries the slug. |
| FR-003 | functional | Every L2 package classified as **dead — propose removal** has a corresponding `kitty-specs/l2-remove-<package>-<short-ULID>/spec.md` filed via `spec-kitty specify`. The audit document's "Follow-up mission" column carries the slug. The removal mission's spec.md states the charter-bearing rationale + the gate-verification plan. |
| FR-004 | functional | `CLAUDE.md` layer-architecture table is updated: `messaging` removed from L2 (Content Types) row, added to L3 (Services) row. The L3 row reads `workflows, search, seo, notification, billing, github, migration, northcloud, listing, messaging` (alphabetical-by-existing-convention if applicable, otherwise appended). |
| FR-005 | functional | `bin/check-package-layers` is updated so `messaging` is L3; the gate runs green against the new layer map. No existing `waaseyaa/messaging` require edge becomes a violation. |
| FR-006 | functional | `packages/messaging/README.md` line `**Layer 2 — Content Types**` becomes `**Layer 3 — Services**`. The "Why L3" paragraph documents the chat-substrate role per gap-matrix C-1. |
| FR-007 | functional | `docs/specs/messaging.md` exists (new file). It documents the L3 graduation rationale, the per-thread access-policy model, the existing data model (`MessageThread`, `ThreadParticipant`, `ThreadMessage`), and the out-of-scope follow-ups (presence, read-receipt UI, federation). Stamped with `<!-- Spec reviewed YYYY-MM-DD - l2-content-types-consolidation-01KSEFTX - WP03 - messaging L3 graduation -->`. |
| FR-008 | functional | `CLAUDE.md` orchestration table is updated to point `packages/messaging/*` at `docs/specs/messaging.md` (new spec). |
| FR-009 | functional | `CHANGELOG.md` `[Unreleased]` carries two entries — one **Changed** (messaging L3 graduation) and one **Added** (`docs/audits/2026-05-l2-content-types-audit.md`). |
| NFR-001 | non-functional | The mission is purely additive in `packages/messaging/src/`: no source code changes, no test changes. Only README + composer.json (description-only if needed) + the layer-map updates in CLAUDE.md and `bin/check-package-layers`. |
| NFR-002 | non-functional | All codified gates green: `composer cs-check`, `composer phpstan`, `bin/check-composer-policy`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`. The package-layers gate runs against the new layer map after WP03. |
| NFR-003 | non-functional | Audit findings are reproducible. The audit document lists the sources used (file paths greps, package READMEs, layer tables, etc.) so a reader can verify each classification. |
| C-001 | constraint | No L2 package source code is added, removed, or modified by this mission — except `packages/messaging/README.md` (FR-006) which is a metadata edit, not a source edit. |
| C-002 | constraint | No L2 packages are merged into metapackages. Each remains a separate composer package per the pre-resolved decision in spec.md "Why". |
| C-003 | constraint | The audit classification for each package is justified by the rationale paragraph. A classification without rationale fails this constraint. |
| C-004 | constraint | The messaging → L3 graduation is the ONLY architectural change in this mission. No other package's layer changes. No new services-tier package is created. |
| C-005 | constraint | Follow-up missions filed by WP02 are scaffold-only (spec.md stub). The actual hardening / removal work happens in those missions' own implement-review loops. |

## Acceptance

- All 9 FRs met.
- All codified gates green (NFR-002). `bin/check-package-layers` green against the post-WP03 layer map.
- `docs/audits/2026-05-l2-content-types-audit.md` exists, covers every L2 package, and each row has a populated classification + rationale + follow-up slug (where applicable).
- Every alpha-classified package has a `kitty-specs/l2-harden-*/spec.md`. Every dead-classified package has a `kitty-specs/l2-remove-*/spec.md`.
- `packages/messaging/README.md` says `**Layer 3 — Services**`.
- `CLAUDE.md`'s L3 row contains `messaging`; the L2 row does not.
- `docs/specs/messaging.md` exists + stamped.
- `git diff packages/messaging/src` and `git diff packages/messaging/tests` are both empty (C-001).
- `git diff packages/{node,taxonomy,media,path,menu,note,relationship,groups,engagement,attachment}/{src,tests}` are all empty (C-001).

## Risks

- **Implementer audits too generously (everything is "production-ready").** A weak audit ships zero follow-up missions and the mission's value is null. Mitigation: NFR-003 requires reproducible audit sources; reviewer cross-checks at least three classifications against the actual package state.
- **Implementer audits too harshly (everything is "dead").** Over-aggressive removal proposals would gut the framework. Mitigation: the audit is a proposal, not a removal — each dead-classified package's follow-up mission is a separate charter-bearing decision; this mission only files the proposal.
- **`bin/check-package-layers` fails after the WP03 layer-map update.** If any existing `waaseyaa/messaging` require edge is from a higher layer (L4+), the gate stays green (those are downward edges to L3 now). If any L1 / L2 package requires `messaging`, the gate fails — that's the test. Mitigation: WP03's verification gate explicitly runs `bin/check-package-layers` and asks the implementer to report the result.
- **Messaging package has consumers that assume L2 entity semantics.** If `MessageThread` is treated as a content type (registered in the entity-type manager and listed in admin "content types"), the L3 graduation could surface unexpected gaps. Mitigation: the audit (WP01) covers messaging's consumer surfaces; if any consumer relies on the L2-content-type framing, the implementer documents the issue and either resolves it within this mission's WP03 or files a follow-up.
- **The chat surface is conflated with this mission.** Implementers may try to build the chat UI in WP03 because "messaging is now L3 and L3 is services." That's wrong — the L3 graduation is purely a layer move; chat is a separate mission. Mitigation: spec.md's Out-of-scope is explicit.
- **CLAUDE.md edits are easy to get wrong.** The layer table is sensitive — column ordering, alphabetical placement, the orchestration-table cross-reference all matter. Mitigation: WP03's verification gate includes diffing CLAUDE.md against the existing format.

## Decisions pre-resolved

- **Each L2 package stays as a separate composer package.** Per the Hard Rules — no metapackage consolidation. Distributions need granularity.
- **`messaging` graduates to L3 as a chat substrate.** Per gap-matrix C-1 + the cleaner abstraction logic. This is the mission's one architectural change.
- **`engagement` stays at L2.** It models per-entity engagement (likes, reactions, comments per node/taxonomy/etc.) — a content-type abstraction, not a service.
- **No new spec for `node`, `taxonomy`, `path`, `menu`, `note`, `relationship`, `groups`, `attachment`, `media` written in this mission.** If the audit finds a package needs a spec (a "production-ready" package without one), the spec is filed as part of the per-package hardening follow-up mission, not this mission.
- **The audit document lives in `docs/audits/`, not `docs/specs/`.** Audits are snapshots dated to a moment; specs are long-lived. Mixing them in `docs/specs/` invites spec-drift.
- **Dead-package removal is per-package mission, not bulk.** Each removal carries its own charter-bearing decision (per the codified-policy-gate sensitivity) and its own gate verification. Bulk removal would short-circuit that.

## Decisions deferred to implementer

- The exact classification for each L2 package — that's the audit work. The implementer reads the package, reads its consumers, makes the call. Reviewer challenges any classification the rationale doesn't support.
- The exact wording of each follow-up mission's spec.md stub. The stubs are scaffolds; the missions' real specs land in their own implement-review loops.
- Whether `attachment` and `structured-import` belong in the audit. Read CLAUDE.md's L2 row at edit time. If they're listed, include. If they're in the work-surface group (per the orchestration table), document the exclusion in the audit's introduction.
- Whether the messaging → L3 graduation requires a route-lift to a new `MessagingRouteServiceProvider`. Read the current route registration first; only lift if needed.

Decision preference order (per charter): preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Out-of-band

Hardening follow-up missions filed by WP02 (4 alpha packages):
- `l2-harden-attachment-01KSEW72` — add README, `@api` tags, and at-most-one-active invariant tests for `waaseyaa/attachment`.
- `l2-harden-groups-01KSEW7E` — add `@api` tags, expand tests, wire admin SPA group management pages for `waaseyaa/groups`.
- `l2-harden-engagement-01KSEW7Y` — add `@api` tags, expand tests, wire admin SPA engagement moderation pages for `waaseyaa/engagement`.
- `l2-harden-messaging-01KSEW82` — post-L3-graduation hardening: `@api` tags, expanded tests, admin SPA chat management; begins after WP03 lands.

No removal proposal missions filed (0 dead-classified packages).
- The **chat substrate / Anokii Chat surface** mission — a separate, post-WP03 mission that builds on the L3 messaging graduation. Scope: real-time broadcast via the broadcasting infrastructure, presence, read receipts, Anokii Chat admin SPA page. Not filed by this mission; the parallel `anokii-distribution-scaffold-*` mission (or a fresh wave-2 mission) handles it.
- A future spec for any other L2 package that the audit identifies as needing one but doesn't already have a follow-up under "needs hardening" (rare case; reviewer flags it).
- If the audit identifies a layer-table inconsistency outside L2 (e.g., a package listed in the wrong row), file a separate "layer-map cleanup" mission; do not bundle into this mission.
