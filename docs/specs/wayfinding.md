# Wayfinding

<!-- Spec reviewed 2026-06-19 - Phase 5 (authenticated MCP write tier). Wayfinding write tools ship as four #[AsAgentTool] adapters in packages/ai-agent/src/Tool/Wayfinding/ (ai-agent L5 → wayfinding L4 downward dep): wayfinding_record_trail / wayfinding_rerecord_trail / wayfinding_get_trail (via TrailStore) + wayfinding_emit_beacon (anchor-validate via AnchorRegistry, push via BroadcastStorage). All capability:'present guided content', requireCapability fail-closed (FR-003/NFR-002); the write/emit ones destructive:true so ReadOnlyToolRegistry hides them from public /mcp (C-001). The separate authenticated tier lives in packages/mcp: new route POST /mcp/write → AuthenticatedMcpEndpoint over a CapabilityScopedToolRegistry (dual of ReadOnlyToolRegistry — exposes allowlisted capabilities incl. destructive; default ['present guided content'], config mcp.write_tier.capabilities) + WriteTierAuthInterface (bound to BearerTokenAuth([]) default = 401 fail-closed). Public /mcp untouched (FR-004). Acceptance: AuthenticatedMcpEndpointTest + CapabilityScopedToolRegistryTest (mcp), WayfindingTrailToolsTest + EmitBeaconToolTest (ai-agent). Full contract in mcp-endpoint.md. -->
<!-- Spec reviewed 2026-06-19 - Phase 4 (trail persistence + record-to-saved). wayfinding L4 gains the wayfinding_trail two-axis (revisionable + translatable, en+fr) entity (Entity/Trail.php), registered via EntityType::fromClass(revisionable:true, translatable:true); three tables (base, _revision, __translation__revision) materialised by EntitySchemaSync on schema:sync — the first shipping two-axis type. TrailStore (Trail/TrailStore.php, depends on concrete EntityRepository = L4→L1 entity-storage) realises the no-silent-overwrite rule on native two-axis primitives: live value = peer row (saveTranslation/loadTranslation); drafts/history = per-language revisions (saveTranslationRevision/listTranslationRevisions). record() makes a human-owned trail (origin=recorded); editAsHuman() advances + latches origin=human; reRecord() onto a human-owned trail appends a DRAFT revision and leaves the live value byte-for-byte intact (promoted=false), else advances it (promoted=true). TrailAccessPolicy: owner-only update/delete, capability-gated create/view. Persistence substrate only; the HTTP/MCP write tier exposing it is Phase 5. Acceptance: tests/Unit/Trail/TrailStoreTest.php proves SC-005 (FR-009/010/011). -->
<!-- Spec reviewed 2026-06-19 - Phase 3 (overlay/beacon component with full a11y). Admin SPA: new useBeacons composable builds a live trail from wayfinding.beacon SSE events (useRealtime now listens for that event); new WayfindingOverlay.vue mounted globally in app.vue renders the active beacon as an aria-live role=status region, fully keyboard-navigable (arrows move, Esc dismisses), spotlights the data-anchor element (outline ring + scroll + focus, no trap), dismissable, honours prefers-reduced-motion. Ships in the prebuilt bundle (dist rebuilt; served-bundle wf-beacon assertion). Contract detailed in docs/specs/admin-spa.md. -->
<!-- Spec reviewed 2026-06-19 - Phase 2 (session-scoped beacon delivery). foundation L0 gains SessionChannel (reserved `session:` namespace; token = substr(sha256(session_id),0,32)) and BroadcastRouter now strips client-supplied private channels + auto-subscribes each connection to its own server-derived session channel (resolveSubscriberChannels, pure + unit-tested) — enforcing NFR-001 (a client can only receive its own session's beacons). The SSE connected frame exposes the non-secret sessionToken. wayfinding L4 gains EmitBeaconController: POST /api/wayfinding/beacons, authenticated + 'present guided content' capability (fail-closed, re-checked in controller), validates anchor via AnchorRegistry::isValid, publishes a wayfinding.beacon to the target session's private channel via BroadcastStorage::push; content transported verbatim (escaping is Phase 3). Reconnect/resume inherited from Last-Event-ID. -->
<!-- Spec reviewed 2026-06-19 - Phase 1 (anchor registry + published catalog). New L4 package packages/wayfinding: AnchorRegistry derives the valid data-anchor catalog from EntityTypeManager + SchemaPresenter (byte-identical to the SPA scheme shipped alpha.227), and AnchorCatalogController publishes it read-only at GET /.well-known/waaseyaa-anchors.json (allowAll, mirrors the /llms.txt discovery family). Mission kitty-specs/wayfinding-01KVGH5X. -->

The human-facing complement to the alpha.221 agent-readable trio. Where 221 made
the app **readable** by agents, Wayfinding surfaces agent (and human-authored)
actions as guided, element-anchored **beacons** in the live UI — delivered live
per user session, or saved as reusable **trails**.

Canonical mission + locked design defaults: [kitty-specs/wayfinding-01KVGH5X/spec.md](../../kitty-specs/wayfinding-01KVGH5X/spec.md). This file is the enduring subsystem spec; it grows one section per shipped phase.

## Vocabulary

- **beacon** — one element-anchored tip (anchored to a single declared `data-anchor` target).
- **trail** — an ordered sequence of beacons.
- **live trail** — an agent emitting beacons in real time over a session-scoped channel.

## Architecture

- **Package:** `packages/wayfinding` — **Layer 4** (it reads `EntityTypeManager` (L1) and `SchemaPresenter` (api, L4) and registers an HTTP route via `routing` (L4)). The authenticated MCP write tools (Phase 5) live in an L5/L6 package importing wayfinding downward; the overlay (Phase 3) is admin-SPA frontend.
- **Reused substrates:** `BroadcastRouter` (alpha.224 bounded SSE loop) for delivery; `ContentAdminAccessPolicy` / `EntityAccessHandler` (alpha.223) for emit authorization; the `role="status"` aria-live region (alpha.226) as the overlay a11y seed; the schema-driven admin (`SchemaList`/`SchemaView`/`SchemaForm`) as the anchor source.

## Phase 1 — Anchor registry + published catalog (shipped)

### Anchor ID scheme

Beacons target a single `data-anchor` ID. The IDs are static and type-level
(entity type + field/operation identity) and are **byte-identical** to the inert
`data-anchor` attributes the schema-driven admin emits (see
[admin-spa.md](admin-spa.md) "Element anchors", shipped alpha.227):

| Kind | ID template | Source element |
|------|-------------|----------------|
| `list` | `list:{entityType}` | SchemaList container |
| `list-field` | `list-field:{entityType}:{field}` | SchemaList column header |
| `view` | `view:{entityType}` | SchemaView container |
| `field` | `field:{entityType}:{field}` | SchemaView / SchemaForm field |
| `form` | `form:{entityType}` | SchemaForm container |
| `action` | `action:{entityType}:{create\|edit\|delete\|submit}` | list-level Create-new (`create`) + SchemaList row (`edit`/`delete`) + SchemaForm (`submit`) |

### `AnchorRegistry` (`packages/wayfinding/src/Anchor/AnchorRegistry.php`)

Derives the catalog from `EntityTypeManagerInterface::getDefinitions()` and, per
type, the `SchemaPresenter` `properties` (`resolveFieldDefinitions()`), emitting:

- structural + action anchors per entity type (`list`/`view`/`form` + `create`/`edit`/`delete`/`submit`; `create` is the list-level "Create new" control, added in `wayfinding-showcase-hardening` P1-3);
- `field` and `list-field` anchors for each **non-hidden** field (`x-widget !== 'hidden'`), matching the SPA's field filter so the catalog mirrors what the admin renders.

Field enumeration is best-effort per type (a type whose schema cannot be
presented contributes no field anchors rather than failing). The catalog is
static and account-independent. `AnchorRegistry::isValid(string $id): bool` is the
source of truth for **FR-005** (an emit referencing an anchor absent from the
catalog is rejected) — consumed by the emit path in later phases.

### Published catalog (FR-007)

`AnchorCatalogController` publishes the catalog read-only at
`GET /.well-known/waaseyaa-anchors.json` (registered in
`WayfindingServiceProvider::routes()` with `allowAll()` + `priority(10)`, mirroring
the `/llms.txt` discovery family). Shape:

```json
{ "version": 1, "kinds": ["list","list-field","view","field","form","action"],
  "anchors": [ { "id": "field:node:title", "kind": "field", "entity_type": "node", "field": "title" }, … ] }
```

This completes the read/write symmetry with the 221 trio: an agent reads the
public catalog to learn valid anchors, then (in later phases) emits beacons via
the **separate, authenticated** write tier. The public 221 trio is unchanged
(C-001) — this only **adds** a read-only discovery surface.

When RFC 9727 discovery is enabled, `WayfindingServiceProvider` contributes
this public JSON anchor catalog as one API item. It does not contribute the
authenticated beacon emit, session-token, trail mutation, or admin surfaces.

## Phase 2 — Session-scoped beacon delivery (shipped)

Beacons are delivered to exactly one user session over the bounded alpha.224 SSE
loop, with server-side session isolation (LD-1 / FR-001 / FR-002 / NFR-001).

### Reserved per-session channels (`SessionChannel`, foundation L0)

A "private" channel lives in the reserved `session:` namespace. The subscribe side
(`BroadcastRouter`) **never** honours a client-supplied private channel: it strips
any `session:*` from `?channels=` and auto-subscribes each connection to its OWN
channel `session:<token>`, where `token = substr(sha256(session_id), 0, 32)` is
derived **server-side** from the connection's PHP session id. The non-secret token
is returned in the SSE `connected` frame (`sessionToken`) so an authorized
presenter can address that session without ever learning its raw session id. The
admin client surfaces it: `useRealtime()` captures the token from the `connected`
frame and returns it as a `sessionToken` ref (re-exposed by `useBeacons()`), so a
presenter-pairing UI / guiding agent can read this connection's own token and hand
it to the emit endpoint (alpha.234, mission `wayfinding-stress-remediation-01KVGK4Q`;
before this the client received the frame but dropped the token, so no presenter
could target a viewer's session). Net: a client can only ever receive its own
session's beacons regardless of what it requests —
`BroadcastRouter::resolveSubscriberChannels()` is pure and unit-tested for this
isolation contract.

### Emit endpoint (`EmitBeaconController`, wayfinding L4)

`POST /api/wayfinding/beacons` — **authenticated + the `present guided content`
capability** (fail-closed: 401 anonymous, 403 without the capability; re-checked in
the controller as defence-in-depth — LD-2/FR-003). Payload: `{ session?, anchor_id,
content, order? }`. The anchor is validated against the published catalog
(`AnchorRegistry::isValid`, FR-005); `content` is length-capped and transported
verbatim (escaping/constrained markup is Phase 3 — LD-4/A-004). The beacon is
published via `BroadcastStorage::pushRetained(SessionChannel::forToken(session), 'wayfinding.beacon', …, retainKey: anchorId, ttl: 3600)`
to the target session's private channel; omitting `session` self-targets the
caller. `pushRetained` both delivers live AND retains the beacon so it is
replayed on every (re)connect (Phase 6) — beacons no longer evaporate on a
reconnect; this superseded the bare `push` originally shipped here.

## Phase 3 — Overlay / beacon component with full a11y (shipped)

The flagship on-screen overlay (admin SPA), built on the alpha.226 `role="status"`
aria-live primitive (LD-6 / FR-012). Detailed in [admin-spa.md](admin-spa.md).

- **`useBeacons` composable** builds a live trail from `wayfinding.beacon` SSE
  events delivered on the connection's own per-session channel (Phase 2);
  `useRealtime` now also listens for that event. The overlay auto-advances to the
  newest beacon and a new beacon re-shows a dismissed overlay.
- **`WayfindingOverlay.vue`** (mounted globally in `app.vue`) renders the active
  beacon as an `aria-live` `role="status"` region. It is **fully keyboard-navigable**
  (←/→ or ↑/↓ move, Esc dismisses), **spotlights the declared `data-anchor` element**
  (outline ring + scroll-into-view + moves focus to it **without trapping** — a
  non-focusable anchor gets a transient `tabindex="-1"`), is **dismissable at any
  time**, and **honours `prefers-reduced-motion`**. It ships in the prebuilt admin
  bundle (dist rebuilt; the alpha.227 freshness gate + a served-bundle `wf-beacon`
  assertion guard it). Beacon content is still transported verbatim from Phase 2;
  the constrained-markup renderer is Phase 4/Phase-5 design under FR-008/NFR-003.

## Phase 4 — Trail persistence + record-to-saved (shipped)

Saved trails are **versioned + translatable** content entities, and recording a
live trail to a saved one is governed by the **human-owned-on-save /
no-silent-overwrite** revision rule (LD-5 / FR-009..FR-011 / SC-005). This phase
ships the persistence substrate only; the authenticated surface that exposes it
(HTTP/MCP write tools) is Phase 5.

### `Trail` two-axis entity (`packages/wayfinding/src/Entity/Trail.php`)

`wayfinding_trail` is registered (in `WayfindingServiceProvider::register()`) as
**revisionable + translatable** via `EntityType::fromClass(Trail::class,
revisionable: true, translatable: true)`. Keys: `tid` / `uuid` / `title` /
`revision_id` / `langcode` / `default_langcode`. Fields: `title`, `beacons` (a
JSON-encoded ordered list of `{anchor_id, content, order}` — `TrailStore` owns
(de)serialization so the column stays a plain string), `owner_uid`, and `origin`
(`recorded` | `human`). Its three tables (`wayfinding_trail`,
`wayfinding_trail_revision`, `wayfinding_trail__translation__revision`) are
materialised by `EntitySchemaSync` on `schema:sync` like any other type — the
first shipping two-axis entity in the framework.

### `TrailStore` (`packages/wayfinding/src/Trail/TrailStore.php`)

The persistence model maps one-to-one onto the framework's two-axis primitives,
which is the whole no-silent-overwrite mechanism:

- **Live / current value** = the per-language peer row — `saveTranslation()` /
  `loadTranslation()`. This is what plays back.
- **History + drafts** = the per-language revision log — `saveTranslationRevision()`
  (which does **not** touch the peer row) / `listTranslationRevisions()`.

Operations:

- **`record(langcode, title, beacons, ownerUid)`** (FR-010) — saves a new trail
  owned by `ownerUid`, live value `origin = recorded`.
- **`editAsHuman(id, langcode, …)`** — a human authors/edits a language; advances
  the live value and latches it `origin = human`. Creating a translation in a new
  language uses the same path (ownership inherited from the default-language row),
  so en + fr sequence **independently** (FR-009).
- **`reRecord(id, langcode, …)`** (FR-011) — if the target language is
  **human-owned**, the re-recording is appended as a **draft revision** and the
  live value is left byte-for-byte intact (`promoted = false`); otherwise
  (agent-recorded, or that language not yet translated) it is safe to advance the
  live value (`promoted = true`). Either branch creates a new revision.

`TrailStore` requires the concrete `EntityRepository` (the per-language revision
API is part of the two-axis surface beyond `EntityRepositoryInterface`), a
legitimate L4→L1 dependency (`waaseyaa/entity-storage`). The acceptance gate is
`tests/Unit/Trail/TrailStoreTest.php`, which drives the real `Trail` type over a
real two-axis SQLite store and proves SC-005 directly.

### `TrailAccessPolicy` (`packages/wayfinding/src/Access/TrailAccessPolicy.php`)

`#[PolicyAttribute(entityType: 'wayfinding_trail')]`. A trail becomes human-owned
on save, so **only its owner** may `update`/`delete`; `view` is the owner or any
`present guided content` capability holder; `create` is gated on that capability
(LD-2/FR-003). Field-level: `owner_uid` / `origin` and identity keys are
store-managed and not directly editable.

## Phase 5 — Authenticated MCP write tier (shipped)

The separate authenticated MCP write-tool surface for emitting live trails and
**managing saved trails**, capability-gated, leaving the read-only alpha.221 trio
untouched (FR-003/FR-004/NFR-002/C-001/SC-002).

### Wayfinding write tools (`packages/ai-agent/src/Tool/Wayfinding/`)

Five `#[AsAgentTool]` adapters — in **ai-agent (L5)**, importing wayfinding (L4)
downward, the established home for first-party tool adapters (mirrors the Bimaaji
family). All carry `capability: 'present guided content'`
({@see EmitBeaconController::CAPABILITY}) and `requireCapability` fail-closed:

- `wayfinding_record_trail` (`destructive: true`) → `TrailStore::record` (FR-010).
- `wayfinding_rerecord_trail` (`destructive: true`) → `TrailStore::reRecord`; the
  no-silent-overwrite rule rides through (FR-011): re-recording a human-owned trail
  reports `promoted: false` (draft).
- `wayfinding_edit_trail` (`destructive: true`) → `TrailStore::editAsHuman` (SC-005):
  advances the live value AND latches `origin = human`, so a later re-record lands
  as a draft instead of overwriting it. This is the authenticated surface that makes
  the "human edits are never overwritten" guarantee reachable from a running app —
  before it, `editAsHuman()` had no MCP/HTTP/admin/CLI caller and SC-005 was
  demonstrable only from a unit test reaching around the tool layer (closes CL-8).
- `wayfinding_get_trail` (`destructive: false`) → `TrailStore::current`.
- `wayfinding_emit_beacon` (`destructive: true`) → validates the anchor via
  `AnchorRegistry` (FR-005) and pushes a `wayfinding.beacon` to the target session
  channel via `BroadcastStorage` (the MCP analogue of the Phase-2 emit controller;
  the duplicated emit logic is logged as a cleanup item, CL-5).

The `destructive` tools are **structurally hidden** from the public read-only
`/mcp` surface by `ReadOnlyToolRegistry` (C-001), for free.

### The authenticated tier (`packages/mcp/`)

A SEPARATE route `POST /mcp/write` → `AuthenticatedMcpEndpoint`, over a
`CapabilityScopedToolRegistry` scoped to `present guided content` and
bearer-token auth (`WriteTierAuthInterface`, fail-closed 401 by default). The
public `/mcp` is byte-identical and untouched. Full contract:
[mcp-endpoint.md](mcp-endpoint.md) "Authenticated write tier".

## Phase 6 — Showcase hardening (shipped)

A single-clean-take dry run of the live showcase surfaced defects that the five
phases didn't (mission `wayfinding-showcase-hardening`). Each fix makes the
already-correct mechanism robust under a real, reconnecting browser; no redesign.

- **Beacon-reconnect race (P0-1) — the demo-killer.** `/api/broadcast` is a
  fire-and-forget cursor stream that starts each connection at "now", so a beacon
  emitted during the admin SPA's hydration reconnect window was delivered to a
  connection that no longer existed and never rendered. Fix: beacons are now
  **retained** and **replayed on every connect** (see [broadcasting.md](broadcasting.md)
  "Retained messages"). `EmitBeaconController`/`EmitBeaconTool` call
  `BroadcastStorage::pushRetained(channel, 'wayfinding.beacon', beacon, retainKey: anchorId, ttl: 3600)`;
  `BroadcastRouter` replays `retainedFor()` right after the `connected` frame
  (replay frames carry the original id in the JSON envelope for client de-dup but
  no SSE `id:` line). To stop the connection thrash that amplified the race, the
  admin client now shares **one** `EventSource` per channel set (ref-counted; see
  [admin-spa.md](admin-spa.md) `useRealtime`) — that, not a longer SSE lifetime,
  is the thrash fix (the per-connection cap stays at 30s; lengthening it starved
  the SPA's own fetches by pinning the browser's connection pool). A viewer
  dismissing the trail clears its own
  session's retained beacons via **`DELETE /api/wayfinding/beacons`**
  (`EmitBeaconController::clear`, own-session scoped, authenticated, no presenter
  capability) — "non-dismissed" survives reload, dismissed does not.
- **Session token had no supported read path (P0-2).** alpha.234 exposed the
  token in `useRealtime`/`useBeacons`, but reading it from outside still required
  intercepting the SSE wire and winning the hydration race. Added
  **`GET /api/wayfinding/session`** (`SessionTokenController`, authenticated,
  returns only the caller's own `{ sessionToken, channel }` — identical to the
  `connected` frame) and surfaced it in-page as **`data-wf-session`** on the
  document root (`plugins/wayfindingSession.client.ts`). No SSE interception, no
  race.
- **Create-new anchor coverage (P1-3).** `AnchorRegistry` now emits
  `action:{entityType}:create` and the list page's "Create new" control carries
  the matching `data-anchor`, so a presenter can beacon the create affordance
  directly (it was previously unanchored).
- **Quiet tool-resolution (folds CL-2 / P2-10).** Optional MCP tools whose deps
  are absent in a given kernel (Bimaaji routing-introspection without a
  `RouteCollection`; vector search without an embedding provider) were logged at
  `error` on boot and public `tools/list`. The tool container now raises a typed
  `ToolDependencyUnavailableException` for an unresolvable dependency and
  `AttributeToolRegistry` skips it at `debug` (genuine failures stay at `error`).
  The resolved tool set is unchanged — only the log level differs.

Acceptance (release gate): `BroadcastStorageTest` (retained push/replay/drop/TTL),
`AnchorRegistryTest` (create anchor), `SessionTokenControllerTest`,
`AutowiringToolContainerTest` + `AttributeToolRegistryTest` (typed-unavailable /
quiet skip), and admin Vitest (`useRealtime` shared connection, `useBeacons`
clear-on-dismiss).
