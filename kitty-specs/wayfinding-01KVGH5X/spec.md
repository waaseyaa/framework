# Feature Specification: Wayfinding (Flagship / North-Star Feature)

**Mission:** `wayfinding-01KVGH5X` · **Type:** software-dev · **Target:** main · **Created:** 2026-06-19 · **Status:** Flagship — subsystem build HELD for explicit green-light

## Vocabulary (canonical — use consistently)

- **Wayfinding** — the feature: the human-facing complement to the alpha.221 agent-readable trio.
- **beacon** — one element-anchored tip (anchored to a single declared `data-anchor` target).
- **trail** — an ordered sequence of beacons.
- **live trail** — an agent emitting beacons in real time over a session-scoped channel.

## Summary

The alpha.221 "agent-readable trio" made the app **readable** by agents (HTML, Markdown via content negotiation, public MCP `entity.read`). **Wayfinding is the inverse and complement**: it surfaces agent (and human-authored) actions as guided, element-anchored **beacons** in the *live* UI — a **trail** an agent walks a user through in real time (a **live trail**), or a saved, reusable trail an end user replays later. This is the framework's north-star, enterprise-grade, production-targeted user-facing capability: an AI agent (or a human author) can say "here is how you do this" and have the application *show* it, on the real screen, anchored to real elements, accessibly.

Wayfinding sits on four already-shipped substrates and is **enabled** by the in-flight remediation missions (see "Enabler sequencing"):

- alpha.221 — the agent-readable read side (read symmetry target for the anchor catalog).
- alpha.223 — `ContentAdminAccessPolicy` / the access-handler machinery (the emit-path authorization substrate).
- alpha.224 — the bounded SSE broadcast loop (`BroadcastRouter`) (the delivery substrate).
- alpha.226 — the `role="status"` aria-live busy region in the admin SPA (the a11y seed primitive for beacons).

**This mission is the SPEC and the PHASED PLAN only.** No Wayfinding subsystem code is built under this mission until an explicit green-light. The locked design defaults below are **requirements**, not options.

## Actors

- **Guiding agent (authenticated)** — emits a live trail of beacons into a specific user session via the authenticated Wayfinding write tier; never broadcasts globally.
- **End user (human)** — receives a live trail in their own session, or replays a saved trail; navigates beacons by keyboard, dismisses them, and is never trapped.
- **Trail author (human, authenticated)** — creates/edits saved trails as versioned, translatable content; owns any trail recorded from a live session once saved.
- **Anchor catalog consumer (agent)** — reads the published anchor catalog (read symmetry with the 221 trio) to know which `data-anchor` IDs exist before emitting beacons.

## Why this mission exists (north-star framing)

The 221 trio proved the app is legible to agents. The missing half is **agency made visible**: turning an agent's intended action into an on-screen, anchored, accessible guide a human can follow or save. Wayfinding is the feature that differentiates the platform — "the app an agent can not only read, but *show you around*." It is built to enterprise/production standards (authenticated, permission-gated, session-isolated, escaped content, fully accessible), not as a dev-only toy.

## Locked design defaults (LD) — these are requirements

| ID | Locked default |
|----|----------------|
| LD-1 | **Session-scoped delivery only.** Beacons are delivered per-session over the bounded alpha.224 SSE loop using per-session topics, with reconnect/resume. There is **never** a global broadcast of beacons. |
| LD-2 | **Authenticated, permission-gated emit.** Emitting a beacon/trail requires a *"present guided content"* capability, enforced through the alpha.223 `ContentAdminAccessPolicy` / `EntityAccessHandler` machinery. The public read-only MCP trio (221) is **unchanged**; Wayfinding adds a **separate authenticated write-tool tier**. |
| LD-3 | **Declared-anchor IDs only, validated against a published catalog.** Beacons may only target `data-anchor` IDs that exist in a published **anchor catalog**. The schema-driven admin (`SchemaView`/`SchemaForm`/`SchemaList`) **auto-derives** field anchors from schema field identity. The catalog is exposed on the agent-readable **read** side (read/write symmetry with 221). |
| LD-4 | **Beacon content is untrusted input.** Beacon text is escaped, limited to a constrained markup subset, and **never** rendered as raw HTML. |
| LD-5 | **Persistence model.** Human-authored trails are **versioned and translatable** (en + fr) content entities. Live agent trails are **ephemeral but recordable**. A recorded trail becomes **human-owned** on save; re-recording creates a **new revision/draft** and MUST NEVER silently overwrite human edits. |
| LD-6 | **Accessibility is required, not optional.** Keyboard navigation, `aria-live` beacon text, focus management, dismissable, and respects `prefers-reduced-motion`. Built on the alpha.226 `role="status"` region as the seed primitive. |

## Requirements

### Functional (FR)

| ID | Requirement | Status |
|----|-------------|--------|
| FR-001 | Wayfinding MUST deliver beacons to exactly one user session via a per-session SSE topic on the bounded `BroadcastRouter` loop; cross-session or global beacon delivery MUST be impossible by construction (LD-1). | Proposed |
| FR-002 | The SSE delivery MUST support client reconnect/resume (Last-Event-ID style) so a dropped connection resumes the live trail without replaying the whole trail or losing position (LD-1). | Proposed |
| FR-003 | A beacon/trail emit MUST be rejected unless the caller holds the *"present guided content"* capability, evaluated via the existing access-handler/`ContentAdminAccessPolicy` pipeline (LD-2). | Proposed |
| FR-004 | Wayfinding write operations MUST be exposed only through a **separate authenticated MCP write tier**; the public unauthenticated MCP trio surface (221) MUST remain read-only and unchanged (LD-2). | Proposed |
| FR-005 | A beacon MUST reference a target only by a `data-anchor` ID; an emit referencing an anchor not present in the **published anchor catalog** MUST be rejected with a clear error (LD-3). | Proposed |
| FR-006 | The schema-driven admin components (`SchemaView`/`SchemaForm`/`SchemaList`) MUST carry stable `data-anchor` IDs derived from schema field identity (entity type + field/operation), so field-level anchors exist without per-screen hand-authoring (LD-3). *(Phase-1 groundwork lands early in `admin-crud-correctness` — see Enabler sequencing.)* | Proposed |
| FR-007 | The anchor catalog MUST be published on the agent-readable read side (the same surface family as the 221 trio), so an agent can discover valid anchors before emitting (read/write symmetry) (LD-3). | Proposed |
| FR-008 | Beacon content MUST be escaped and restricted to a constrained markup subset (e.g. emphasis/links from an allowlist); raw HTML MUST never reach the DOM (LD-4). | Proposed |
| FR-009 | Saved trails MUST be modelled as versioned, translatable (en + fr) content entities, using the framework's revisionable + translatable storage (LD-5). | Proposed |
| FR-010 | A live trail MUST be ephemeral by default but **recordable**; recording a live trail to a saved trail MUST create a human-owned trail entity (LD-5). | Proposed |
| FR-011 | Re-recording over an existing saved trail MUST create a new revision/draft and MUST NEVER silently overwrite human edits to that trail (LD-5). | Proposed |
| FR-012 | The beacon overlay MUST be fully keyboard-navigable (next/prev/dismiss), expose beacon text via `aria-live`, manage focus to the anchored element without trapping it, be dismissable at any time, and respect `prefers-reduced-motion` (LD-6). | Proposed |

### Non-Functional / Security (NFR)

| ID | Requirement | Status |
|----|-------------|--------|
| NFR-001 | Session isolation MUST be enforced server-side (topic derived from the authenticated session), not by client cooperation; a client MUST NOT be able to subscribe to another session's beacon topic. | Proposed |
| NFR-002 | The emit authorization MUST fail closed: absent capability/account ⇒ rejected, consistent with the entity-level "deny unless granted" semantics. | Proposed |
| NFR-003 | The constrained-markup renderer MUST have an explicit allowlist with tests for XSS vectors (script, event handlers, `javascript:` URLs, data URIs). | Proposed |
| NFR-004 | The SSE delivery MUST stay within the alpha.224 bounded-loop guarantees (no unbounded blocking loop); per-session topics MUST not regress the broadcast loop bound. | Proposed |
| NFR-005 | Enterprise/production posture: Wayfinding MUST work with `APP_ENV=production` (no dependence on the dev auto-auth fallback), behind real authentication. | Proposed |

### Constraints (C)

| ID | Constraint | Status |
|----|------------|--------|
| C-001 | The 221 public read-only trio (HTML/Markdown/MCP `entity.read`) MUST NOT change behaviour or gain write capability. | Accepted |
| C-002 | No BC shims / deprecation layers (no deployed downstream apps). | Accepted |
| C-003 | Anchors are `data-anchor` IDs only — no CSS-selector or XPath targeting (stability + safety). | Accepted |
| C-004 | Subsystem build is HELD: this mission produces spec + phased plan only until an explicit green-light. The sole code that lands before the green-light is the Phase-1 anchor groundwork folded into `admin-crud-correctness`. | Accepted |

## Phased plan (DO NOT IMPLEMENT beyond Phase-1 groundwork without green-light)

- **Phase 1 — Anchor registry + published catalog.** Define the `data-anchor` ID scheme (entity-type + field/operation identity), auto-derive anchors in the schema-driven admin, build the registry, and publish the catalog on the agent-readable read side. *(Groundwork — stable `data-anchor` IDs on `SchemaView`/`SchemaForm`/`SchemaList` — lands early in `admin-crud-correctness`; the registry + published catalog land here.)*
- **Phase 2 — Session-scoped delivery.** Per-session SSE topics on the bounded `BroadcastRouter` loop, with reconnect/resume. Server-side session isolation.
- **Phase 3 — Overlay/beacon component with full a11y.** The on-screen beacon overlay built on the alpha.226 `role="status"` primitive: keyboard nav, `aria-live`, focus management, dismissable, reduced-motion.
- **Phase 4 — Trail persistence + record-to-saved.** Versioned, translatable saved-trail content entity; record-a-live-trail-to-saved-trail with the human-ownership / no-silent-overwrite revision rule.
- **Phase 5 — Authenticated MCP write tier.** The separate authenticated MCP write-tool surface for emitting live trails and managing saved trails, capability-gated, leaving the 221 public trio untouched.

Each phase is independently shippable and acceptance-gated. Phases 2–5 do not begin until the green-light; Phase-1 groundwork is pre-authorized via `admin-crud-correctness`.

## Enabler sequencing (missions that unblock Wayfinding)

Wayfinding depends on the demo-unblocker missions, re-sequenced as its enablers:

- **P0 (Wayfinding prerequisites + demo-unblockers):** `admin-crud-correctness` (D6 admin bundle freshness gate — *the beacon overlay ships in this same bundle*; D7 admin delete; **+ Phase-1 anchor groundwork**) and `cli-command-di-resolution` (D2 `make:public`). These must land first.
- **P1:** `public-content-routing` (D5); `windows-runtime-ergonomics` (D1 + D4 patch-path ergonomics).
- **P2/P3:** D3 (`route:list`), D8 (docs note only).

See each mission's `meta.json` (`parent_mission`, `roadmap_priority`) and the roadmap section of the cover note.

## Success Criteria

- SC-001: An authenticated agent emits a live trail into a single user session; a second concurrent session never receives those beacons (LD-1/NFR-001 demonstrated).
- SC-002: An emit without the "present guided content" capability is rejected; with it, accepted (LD-2/FR-003).
- SC-003: An emit targeting an anchor absent from the published catalog is rejected; a `SchemaView` field anchor present in the catalog is accepted (LD-3/FR-005/FR-006).
- SC-004: Beacon content containing `<script>` / `javascript:` / event-handler attributes is neutralised; no raw HTML reaches the DOM (LD-4/NFR-003).
- SC-005: A live trail recorded to a saved trail produces a versioned, translatable (en+fr) human-owned entity; re-recording creates a new revision and the prior human edits survive (LD-5/FR-009/FR-011).
- SC-006: The beacon overlay passes keyboard-only navigation, exposes `aria-live` text, manages focus, is dismissable, and honours reduced-motion (LD-6/FR-012).
- SC-007: Wayfinding functions under `APP_ENV=production` with real auth (NFR-005).

## Key Entities

- **AnchorCatalog / AnchorRegistry** — the published set of valid `data-anchor` IDs (Phase 1).
- **Beacon** — `{ anchor_id, content (constrained markup), order, ... }` (ephemeral in a live trail).
- **Trail** — saved: a versioned, translatable content entity holding an ordered beacon list; live: an ephemeral, recordable sequence.
- **Wayfinding write tier** — authenticated MCP tools + emit endpoint, capability-gated.
- Reused: `BroadcastRouter` (delivery), `ContentAdminAccessPolicy`/`EntityAccessHandler` (authz), `role="status"` region (a11y seed), schema admin components (anchor source).

## Assumptions

- A-001: "present guided content" is a new capability/permission expressed through the existing access pipeline, not a new parallel authz system.
- A-002: Per-session SSE topics are derivable from the authenticated session id already established by `SessionMiddleware`.
- A-003: Saved trails reuse the existing revisionable + translatable storage axes; no new storage engine.
- A-004: The constrained-markup subset is small and allowlist-defined; exact tokens decided at Phase-3/4 design under FR-008/NFR-003.

## Scope

**In:** the Wayfinding spec, the locked design defaults as requirements, the 5-phase plan, and the enabler re-sequencing. The ONLY pre-authorized code is the Phase-1 `data-anchor` groundwork in `admin-crud-correctness`.

**Out (held for green-light):** the anchor registry/catalog implementation, SSE session delivery, the overlay component, trail persistence/recording, and the authenticated MCP write tier. Any change to the 221 public trio. Global/broadcast delivery (forbidden by LD-1).
