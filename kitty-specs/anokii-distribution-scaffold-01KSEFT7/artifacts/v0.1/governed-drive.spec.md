# Anokii v0.1 — Governed Drive (draft spec, mission seed)

**Draft status:** Seed for future Anokii-repo mission filing via `spec-kitty specify`. Pre-resolved per `anokii-distribution-scaffold-01KSEFT7/spec.md` §Pre-resolved decisions. Re-file in the Anokii repo as `governed-drive-<ULID>` once Wave-2 begins.

**Cross-references:**
- **Framework directives:** DIR-004 (OCAP-by-architecture), DIR-005 (two-axis storage), DIR-007 (Nuxt SPA), DIR-008 (GPL-2.0-or-later).
- **Anokii directives:** DIR-A001 (AODA Level AA), DIR-A002 (offline-first), DIR-A005 (OCAP inherits framework DIR-004).
- **Gap-matrix rows:** B1 (Governed Drive — folders + ACLs + classification + audit per item), B2 (Nation Drives — org-level shared drives).

## Why

Drive is the floor of the productivity surface — every other Anokii surface (Docs, Sheets, Forms attachments, Data Rooms, Co-Intelligence record sets) ultimately stores artifacts in Drive folders. Tsen'awt-comparable workspace parity requires folder hierarchy, ACL inheritance, classification labels, share-link revocation, and a unified OCAP audit log over every read/write/share/revoke/download. Waaseyaa today ships `media` (S3/local/custom backends) and `attachment` (file-on-entity) at beta, plus `path` for slugs — none of which provide a permission tree. Governed Drive is the largest new entity-type design of v0.1; it is the foundation surface other v0.1 missions compose on.

## Scope

### In scope

- **`drive_folder` entity** (Anokii distribution-namespace entity type). Fields: `id`, `uuid`, `name` (translatable), `parent_folder_id` (self-referential, nullable for root), `owner_id`, `classification_label` (relationship to taxonomy term), `created_at`, `updated_at`, `revision_id` (framework two-axis). Root folders are scoped to a Nation tenant.
- **ACL inheritance via FieldAccessPolicyInterface.** Effective ACL for a file = walk the parent-folder chain to root, accumulating per-folder ACL rules; explicit per-file ACL overrides any inherited rule. Neutral up the chain = accessible (open-by-default per framework `field-access` semantics); a single Forbidden at any level = inaccessible.
- **`drive_file` entity** wrapping framework `media` or `attachment`. Adds: `folder_id` (parent folder), `classification_label` (overrides folder inheritance when set), `share_link_id` (nullable), `revoked_at` (nullable).
- **`share_link` entity.** Fields: `uuid` (the public token), `file_id` or `folder_id`, `expires_at`, `revoked_at`, `created_by`, `created_at`. Revocation immediately removes session access; cached client copies are surfaced as "no longer shared" on next sync.
- **Per-folder + per-file classification labels** (`public`, `community`, `nation-restricted` from the Anokii default classification taxonomy seeded by WP03 of the scaffold mission). Labels propagate to descendants by default; explicit override per item is allowed.
- **OCAP audit on every operation.** Read, write, create, rename, move, share, revoke, download, classification-change. Audit rows include `actor_id`, `entity_uuid`, `event_type`, `classification_label`, `offline_at` (nullable — set when the action originated offline and synced later).
- **Admin Drive UI** in the Nuxt SPA: folder tree + breadcrumb nav; file list with classification chip; share-link management; revoke confirmation modal; classification editor; audit-log drawer per folder/file.
- **Nation Drives (B2).** A Drive variant scoped to a `groups` membership; otherwise identical entity shape.

### Out of scope

- **Per-character collaborative folder-rename.** Folder rename is atomic.
- **Content-addressable storage layer for dedup** (gap-matrix A1 follow-up).
- **File preview rendering for arbitrary MIME types.** Initial preview support: text, images, PDF. Other types download-only.
- **External federation of Drive contents to other Nations.** That is gap-matrix A6 (`portability primitives`) work, filed separately.
- **Recycle-bin / undelete UI.** Soft-delete via framework revisions covers durability; restoring is a CLI operation at v0.1.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `drive_folder` entity type registered with framework `EntityTypeManager`; storage uses framework two-axis revisions; localised `name` field exists per DIR-005. |
| FR-002 | Mandatory | ACL inheritance walks the parent-folder chain; effective ACL evaluated by a `FieldAccessPolicyInterface` implementation that calls back into framework AccessChecker per record per DIR-004. |
| FR-003 | Mandatory | `drive_file` ties to framework `media` storage; explicit per-file classification override defeats inherited label. |
| FR-004 | Mandatory | `share_link` entity ships with `expires_at` and `revoked_at`; revocation removes session access on next request; expired links 410 with a recovery hint. |
| FR-005 | Mandatory | Every Drive operation produces an OCAP audit row spanning read/write/share/revoke/download/classification-change per DIR-A005. |
| FR-006 | Mandatory | Admin Drive UI in the Nuxt SPA renders folder tree, file list, classification editor, share-link management, audit-log drawer; AODA Level AA per DIR-A001 (table semantics for file list, breadcrumb nav, keyboard navigation, file-icon `alt` text). |
| FR-007 | Mandatory | Nation Drives (B2) reuse `drive_folder` scoped to a `groups` membership; group ACLs intersect with per-folder/file ACLs. |
| FR-008 | Mandatory | Offline-first per DIR-A002: folder-tree metadata cached in Dexie; file blobs loaded on demand; ACL changes are server-authoritative read-only-offline; folder/file rename uses LWW on sync. |
| NFR-001 | Mandatory | Audit-row throughput must not bottleneck file reads — audit writes are append-only and async-queued; read-path SLA matches framework `media` baseline. |
| NFR-002 | Mandatory | The Drive permission model must compose on framework `FieldAccessPolicyInterface` without adding new access-check primitives — no Drive-specific access bypass. |
| NFR-003 | Mandatory | Localised `name` fallback follows framework language-fallback resolution (per DIR-005); displaying a folder in Anishinaabemowin falls back to English if no translation exists. |
| C-001 | Constraint | Per DIR-004, no Drive operation skips AccessChecker even for "internal" admin tools. AI agent tools reading Drive contents pass through the same `FieldAccessPolicyInterface` per gap-matrix A5. |
| C-002 | Constraint | Drive folders are NOT renumbered IDs — they use framework UUID keys per DIR-005 audit-trail substrate. |
| C-003 | Constraint | No proprietary file-preview vendor integration (e.g., no Google Drive Preview API). Preview rendering must remain in-process per DIR-008 (license posture) and DIR-A004. |

## Acceptance

- All FRs met.
- All gates green: framework `phpunit`, `cs-check`, `phpstan`, `check-package-layers`, `check-dead-code`, `check-getquery-bindings`, `check-composer-policy`, axe-core (per DIR-A001).
- Kernel-boot integration test seeds a 3-level folder tree with mixed classification labels across 2 Nations, confirms ACL inheritance + cross-Nation isolation + audit-log coverage.
- Offline smoke test: load a folder, go offline, rename it, come back online — rename propagates with LWW; audit row carries `offline_at`.

## Risks

- **Folder rename cascading audit explosion.** A single root rename does not generate per-descendant rows (rename is on the folder, not the descendants). Reviewer confirms audit cardinality matches operations performed, not entities touched.
- **ACL evaluation performance at depth.** Long parent chains (10+ levels) need walk-caching. Initial implementation walks per request; cache layer is a v0.5 follow-up if hot-path perf is impacted.
- **Classification-label drift on move.** Moving a file from `public` to `nation-restricted` folder: does the file's effective label change? Decision: yes, inherited label updates immediately; explicit overrides persist. Audit row records the effective label change.

## Out-of-band

- Drive recycle-bin / undelete UI → future Anokii mission.
- Content-addressable storage dedup (gap-matrix A1) → framework-side mission.
- Federation primitives for cross-Nation Drive sharing (gap-matrix A6) → framework-side mission, with Anokii UX overlay landing after.
- Preview rendering for additional MIME types → v0.5 Anokii mission.
