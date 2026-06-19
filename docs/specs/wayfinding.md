# Wayfinding

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
| `action` | `action:{entityType}:{edit\|delete\|submit}` | SchemaList / SchemaForm actions |

### `AnchorRegistry` (`packages/wayfinding/src/Anchor/AnchorRegistry.php`)

Derives the catalog from `EntityTypeManagerInterface::getDefinitions()` and, per
type, the `SchemaPresenter` `properties` (`resolveFieldDefinitions()`), emitting:

- structural + action anchors per entity type (`list`/`view`/`form` + `edit`/`delete`/`submit`);
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
presenter can address that session without ever learning its raw session id. Net:
a client can only ever receive its own session's beacons regardless of what it
requests — `BroadcastRouter::resolveSubscriberChannels()` is pure and unit-tested
for this isolation contract.

### Emit endpoint (`EmitBeaconController`, wayfinding L4)

`POST /api/wayfinding/beacons` — **authenticated + the `present guided content`
capability** (fail-closed: 401 anonymous, 403 without the capability; re-checked in
the controller as defence-in-depth — LD-2/FR-003). Payload: `{ session?, anchor_id,
content, order? }`. The anchor is validated against the published catalog
(`AnchorRegistry::isValid`, FR-005); `content` is length-capped and transported
verbatim (escaping/constrained markup is Phase 3 — LD-4/A-004). The beacon is
published via `BroadcastStorage::push(SessionChannel::forToken(session) , 'wayfinding.beacon', …)`
to the target session's private channel; omitting `session` self-targets the
caller. Reconnect/resume is inherited from the SSE loop's `Last-Event-ID` handling
(FR-002).

## Phases 3–5 (planned — see mission spec)

- **Phase 3** — the overlay/beacon component with full a11y (keyboard nav, `aria-live`, focus management, dismissable, reduced-motion).
- **Phase 4** — versioned + translatable saved-trail content entity; record-a-live-trail-to-saved with the human-owned-on-save / no-silent-overwrite rule.
- **Phase 5** — the authenticated MCP write tier (capability-gated emit + trail management), leaving the read-only trio untouched.
