# Anokii v0.1 — Offline-First Substrate (draft spec, mission seed)

**Draft status:** Seed for future Anokii-repo mission filing via `spec-kitty specify`. Pre-resolved per `anokii-distribution-scaffold-01KSEFT7/spec.md` §Pre-resolved decisions. Re-file in the Anokii repo as `offline-first-substrate-<ULID>` once Wave-2 begins.

**Cross-references:**
- **Framework directives:** DIR-004 (OCAP must survive offline — client-side access policies mirror server-side semantics within own classification scope), DIR-005 (two-axis storage — Dexie composite key mirrors `(entity_id, langcode, vid)` tuple), DIR-007 (Nuxt SPA hosts Dexie + Workbox).
- **Anokii directives:** DIR-A001 (offline status indicators must be SR-accessible), DIR-A002 (offline-first is design constraint, not optional feature), DIR-A005 (offline operations are audited).
- **Gap-matrix rows:** A7 (Offline-first sync — Dexie-backed client store, Workbox service worker, sync engine with FSM-based conflict resolution per surface).
- **Design-doc source:** `/tmp/waaseyaa-design-offline-first.md` (entire document is the canonical source for this draft).

## Why

Per DIR-A002, offline-first is a design constraint, not an optional feature. Robinson-Huron Treaty Nation geography (per FN datacenter thesis Part 11) has intermittent connectivity — a field worker filling out an intake form in a remote office, a community organiser collecting survey responses in a community hall, a band council staff member drafting a report from their truck — all expect their work to persist locally and sync when connectivity returns. Waaseyaa's two-axis revision substrate (`RevisionableStorageDriver` with `(entity_id, langcode, vid)` tuple) is production-hardened and maps cleanly to Dexie's composite-key schema, eliminating the need to redesign revision semantics on the client. The biggest gap is zero offline infrastructure today (no service worker, no IndexedDB, no sync queue). Anokii ships the substrate (Dexie + Workbox + FSM sync engine) plus the per-surface conflict-resolution strategy table that every v0.1 surface composes on.

## Scope

### In scope

- **Dexie (IndexedDB) schema.**
  - Per-entity-type table layout: one table per registered Anokii entity type (e.g., `drive_folder`, `governed_doc`, `task`).
  - Composite key per row: `[entity_uuid, langcode, vid]` mirroring the framework two-axis tuple per DIR-005.
  - Indexes: `entity_uuid` (lookup), `[entity_type, classification_label]` (per-classification queries), `[updated_at]` (sync window).
  - Out-of-band queue tables: `pending_mutations` (queued writes), `conflict_resolutions` (resolved conflict log for audit), `cached_audit_events` (offline audit rows pending sync).
- **Workbox service-worker.**
  - Nuxt PWA config, web app manifest with Anokii branding (deep-teal palette from scaffold WP03).
  - Cache strategies per URL pattern: static assets (cache-first); API GET (network-first with cache fallback); API mutations (network-only with background-sync queue fallback when offline).
  - Background-sync API integration for `FieldAutoSave` and POST endpoint replay on reconnect.
- **FSM-based sync engine.**
  - States: `idle → syncing → conflict → resolved → idle`.
  - Transitions: `online` event triggers `idle → syncing`; conflict detected during sync triggers `syncing → conflict`; user resolves OR engine auto-resolves per surface policy triggers `conflict → resolved`; resolved triggers `resolved → idle`.
  - Engine reads Mercure SSE stream when online (`entity.saved` / `entity.deleted` events), updates Dexie; on reconnect, drains `pending_mutations` table to server.
- **Per-surface conflict-resolution strategy table.**

  | Surface | Strategy | Rationale |
  |---|---|---|
  | Drive metadata (folder/file rename, classification) | LWW per field | Folder/file metadata is small; latest intent typically wins. |
  | Drive ACLs | Server-authoritative, read-only-offline | Permission state is security-critical; offline edits dangerous. |
  | Forms (community-data classification) | Multi-submission-merge | Every submission is a record; never overwrite per governance posture. |
  | Forms (administrative classification) | LWW per submission | Admin-edited config; latest is canonical. |
  | Tasks | LWW per field | Status/assignment edits; conflict surfaces in audit. |
  | Data Rooms | Server-authoritative, read-only-offline | Consent state is security-critical. |
  | Docs (body) | Save-conflict resolution UI at v0.1 | Three-way merge deferred to v1.0. |
  | Sheets (cells) | LWW per cell | Per-cell granularity; rare divergence given typical sheet workflows. |
  | Co-Intelligence | Server-authoritative, read-only-offline | AI execution requires connectivity. |
  | Admin Centre | Read-only-offline | All mutations require connectivity. |

- **Identity offline.**
  - Tokens cached locally with explicit `expires_at` (matches server-side session TTL).
  - Re-auth-on-reconnect using cached refresh token if available; full login prompt if refresh expired.
  - Partial-trust: user can read OWN classified data offline within their access scope; CANNOT read other Nations' cached data (client-side `FieldAccessPolicyInterface` mirror enforces; full client-side classification check on every cached-row render).
  - Offline operations carry `offline_at` timestamp on every audit event; server reconciles on sync (server is authoritative on conflicts; client losers surface to user in audit log).
- **Network-aware UI affordances.**
  - Status indicator in admin shell: `online` (green), `syncing` (yellow with count of pending mutations), `offline` (red), `conflict` (orange with count of unresolved conflicts).
  - Per-surface pending-sync badge counts (e.g., "3 form submissions pending sync").
  - All status indicators have `aria-live="polite"` announcements per DIR-A001 + DIR-A002.

### Out of scope

- **Yjs / Automerge CRDT collab.** Deferred to v1.0+ (per governed-docs.spec.md and governed-sheets.spec.md `Out-of-band`).
- **Offline LLM execution.** Per co-intelligence-workspaces.spec.md, AI requires connectivity at v0.1.
- **Offline file blob caching beyond on-demand.** Drive files load on demand at v0.1; offline file-blob preload is a v0.5 surface mission.
- **Cross-device sync via local network** (e.g., a Nation office where 5 devices share a local server during an internet outage). v1.0+ — substantial design + governance.
- **Background-sync API on browsers that don't support it.** Fallback degrades to "sync on next page-open" (documented in UI).

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | Dexie schema implemented with per-entity-type tables; composite key `[entity_uuid, langcode, vid]` mirrors framework two-axis tuple per DIR-005. |
| FR-002 | Mandatory | Workbox service-worker integrated via Nuxt PWA module; web manifest carries Anokii branding (deep-teal palette per scaffold WP03). |
| FR-003 | Mandatory | Cache strategies per URL pattern: static cache-first; API GET network-first-with-cache; API mutations network-only-with-bg-sync-fallback. |
| FR-004 | Mandatory | FSM sync engine implemented with the documented state set + transitions; `pending_mutations` table drains on reconnect. |
| FR-005 | Mandatory | Per-surface conflict-resolution strategy table implemented exactly per the table in §In scope; surface ownership routes through the configured strategy. |
| FR-006 | Mandatory | Identity offline: tokens cached with explicit `expires_at`; re-auth via cached refresh on reconnect; partial-trust enforced client-side via `FieldAccessPolicyInterface` mirror. |
| FR-007 | Mandatory | Offline operations carry `offline_at` timestamp on every event; server-side reconciliation is authoritative on conflicts; losers surface in audit log per DIR-A005. |
| FR-008 | Mandatory | Network-aware UI status indicator (`online` / `syncing` / `offline` / `conflict`) in admin shell with per-surface pending-sync badge counts; `aria-live="polite"` announcements per DIR-A001. |
| FR-009 | Mandatory | Mercure SSE event stream consumed by sync engine when online; `entity.saved` / `entity.deleted` events update Dexie. |
| NFR-001 | Mandatory | Dexie storage growth bounded — older revisions evicted per a configurable retention window (default 30 days of revisions cached locally; framework retention is authoritative on the server). |
| NFR-002 | Mandatory | Sync engine state transitions deterministic and testable — FSM defined as a pure function; test fixtures exercise all transitions. |
| NFR-003 | Mandatory | Partial-trust client-side check (FR-006) MUST be a mirror of server-side `FieldAccessPolicyInterface` semantics — divergence is a security defect. Contract test enforces. |
| NFR-004 | Mandatory | axe-core CI gate passes for offline status indicators per DIR-A001; SR users receive offline/syncing/conflict announcements as actionable text. |
| C-001 | Constraint | NO offline LLM execution at v0.1 (per co-intelligence-workspaces.spec.md C-001). |
| C-002 | Constraint | NO offline consent operations for Data Rooms (per data-rooms.spec.md C-001) — security posture. |
| C-003 | Constraint | NO partial-trust bypass — client-side FieldAccessPolicyInterface mirror is non-negotiable per DIR-004 / DIR-A005. |
| C-004 | Constraint | NO Yjs/Automerge CRDT at v0.1 (deferred to v1.0+ Anokii missions). |

## Acceptance

- All FRs met.
- Sync engine FSM contract test green (all transitions, all states, deterministic outcomes).
- Partial-trust contract test green (client-side classification check matches server-side per fixture).
- End-to-end offline smoke (per surface, per strategy table row): go offline, perform the surface's typical operation, come back online, verify expected resolution behaviour.
- Dexie storage growth smoke: 30-day rolling revision retention validated; older revisions purged client-side; server-side full history preserved.
- axe-core CI gate passes for offline UI affordances.

## Risks

- **Browser quotas on IndexedDB.** Persistent quota varies by browser; large sheets / many Doc revisions can hit limits. Mitigation: NFR-001 retention window; per-entity-type size budget documented; admin UI warns when approaching quota.
- **Service-worker upgrade pain.** Service workers cache aggressively; bad cache → users see stale UI. Mitigation: Workbox skipWaiting/clientsClaim pattern + version-tagged cache names; explicit "refresh available" indicator.
- **Partial-trust bypass risk.** A client-side classification check is by definition tamperable — but the threat model is "honest user, intermittent connectivity", not "malicious user with browser dev tools". Server-side checks remain authoritative on every server interaction. Mitigation: document the threat model explicitly; offline cache contains only data the user already had server-side access to.
- **Mercure SSE reconnection edge cases.** SSE streams can drop silently on flaky networks. Mitigation: reconnection with exponential backoff; last-event-id replay for missed events.
- **Conflict-resolution UX for multi-submission-merge.** Form submissions never conflict (they are append-only records), but admin UI must surface "3 submissions arrived for the same form" clearly.

## Out-of-band

- Yjs / Automerge CRDT → v1.0+ Anokii mission (after CRDT research).
- Offline LLM execution → v1.0+ Anokii mission.
- Offline file blob preload (Drive) → v0.5 Anokii mission.
- Cross-device sync via local network → v1.0+ Anokii mission (significant design work).
- Background-sync API fallback for older browsers → already in scope as "sync on next page-open"; documented in UI.
