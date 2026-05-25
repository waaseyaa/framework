# Anokii v0.1 — Data Rooms (draft spec, mission seed)

**Draft status:** Seed for future Anokii-repo mission filing via `spec-kitty specify`. Pre-resolved per `anokii-distribution-scaffold-01KSEFT7/spec.md` §Pre-resolved decisions. Re-file in the Anokii repo as `data-rooms-<ULID>` once Wave-2 begins.

**Cross-references:**
- **Framework directives:** DIR-004 (OCAP — Data Rooms are the most security-critical surface), DIR-005 (two-axis storage — consent state + audit), DIR-007 (Nuxt SPA).
- **Anokii directives:** DIR-A001 (AODA — consent flow + watermark a11y), DIR-A002 (offline — server-authoritative read-only-offline for consent), DIR-A005 (OCAP).
- **Gap-matrix rows:** D2 (Data Rooms — multi-party + consent + audit + revocability).

## Why

Data Rooms is the multi-party governance surface — invite external counterparties (funding agencies, federal consultation panels, partner Nations) into a sandboxed read-area containing specific records, with consent state, expiry, revocability, and watermarking on exported documents. This is the surface where OCAP-by-architecture becomes user-facing trust signal: every join, every view, every export is logged; revocation is immediate; consent is explicit and state-machined. Waaseyaa's `state` + `workflows` + `access` provide the substrate primitives; Data Rooms composes them into a single product surface.

## Scope

### In scope

- **`data_room` entity.** Fields: `id`, `uuid`, `title` (translatable), `description` (translatable), `owner_id`, `classification_label`, `created_at`, `expires_at` (nullable — room can have a hard expiry), `revoked_at` (nullable), `revision_id`.
- **`data_room_member` join entity.** Fields: `id`, `data_room_id`, `account_id` (or invitation_email if not yet a registered user), `consent_state` (enum: `pending`, `granted`, `revoked`), `invited_at`, `consented_at` (nullable), `revoked_at` (nullable), `revision_id`.
- **`data_room_artifact` join entity.** Links a `data_room` to a specific record (Drive file, Doc, Sheet, set of database rows, etc.) — many-to-many. Inclusion is explicit; no implicit "all of folder X".
- **Consent state machine via framework `state` + `workflows`.** Transitions: `pending → granted` (member accepts invitation), `pending → revoked` (member declines or owner revokes), `granted → revoked` (member revokes own consent OR owner revokes).
- **Multi-party invitation flow.** Owner invites by email (creates `data_room_member` with `pending` + sends invitation via framework `mail`); accepted invitations create the user account (if external) and transition to `granted`.
- **Share-link with `expires_at`.** Public-link variant: token-based URL for time-bounded access; revocation removes session access on next request.
- **Watermarking on exported documents.** PDF/image exports overlay: viewer email, viewer name, room title, timestamp, "CONFIDENTIAL — data room export, do not redistribute".
- **OCAP audit on every operation.** Join, leave, document-view, document-download, document-export, share-link-create, share-link-revoke, consent-change. Audit rows carry `data_room_id` for cross-room queries.
- **Admin Data Rooms UI in Nuxt SPA.** Room list (owned + invited-to); room detail view (members, artifacts, audit log drawer); consent flow (member-side accept/decline); revoke confirmation.

### Out of scope

- **End-to-end encryption of room contents.** v1.0 mission — substrate work (key management, per-room key rotation, recovery) is significant.
- **Per-document DRM beyond watermarking.** No copy-prevention, no screenshot prevention.
- **External-identity SSO for invited users** (e.g., login via Microsoft/Google for the invited federal agency). v1.0 mission once OIDC provider is complete.
- **Bulk-add artifacts via folder reference** (e.g., "share all of folder X"). v0.5 mission — requires careful semantics around future folder content additions.
- **Cross-Data-Room federation.** Each room is isolated at v0.1.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `data_room`, `data_room_member`, `data_room_artifact` entities registered with framework `EntityTypeManager`; revisioned per DIR-005. |
| FR-002 | Mandatory | Consent state machine implemented via framework `state` + `workflows`; transitions audited per DIR-A005. |
| FR-003 | Mandatory | Invitation flow uses framework `mail` for outbound email; accepted invitations create user accounts via framework `user` package. |
| FR-004 | Mandatory | Revocation removes session access on next request; cached client copies surface a "no longer shared" message on attempted access. |
| FR-005 | Mandatory | Watermarking on PDF/image exports overlays viewer email, viewer name, room title, timestamp, "CONFIDENTIAL" banner. |
| FR-006 | Mandatory | OCAP audit per DIR-A005 on every operation: join, leave, document-view, document-download, document-export, share-link-create, share-link-revoke, consent-change. |
| FR-007 | Mandatory | Admin Data Rooms UI in Nuxt SPA: room list, room detail (members + artifacts + audit log drawer), consent flow, revoke confirmation; AODA Level AA per DIR-A001 — consent-flow focus management, watermark `alt` text on export-preview, confirmation modals with focus trap. |
| FR-008 | Mandatory | Offline-first per DIR-A002: room metadata cached for current members; documents loaded on-demand; consent operations are server-authoritative (no offline consent — security posture). |
| NFR-001 | Mandatory | Revocation latency MUST be sub-second from owner click to session-removal — measured end-to-end. |
| NFR-002 | Mandatory | Watermark rendering must not corrupt the original document file — watermark is overlay-only on the exported copy. |
| NFR-003 | Mandatory | Audit row throughput must not bottleneck document-view operations — audit is async-queued. |
| C-001 | Constraint | No offline consent operations (FR-008) — consent is a security-critical state and must always be server-authoritative. |
| C-002 | Constraint | Watermarking is the only DRM at v0.1; no proprietary DRM vendor integration per DIR-008 / DIR-A004 license posture. |
| C-003 | Constraint | Per DIR-004, every artifact-view passes through AccessChecker — Data Room inclusion does NOT bypass underlying record ACLs; a member can be in a room and still be denied a specific artifact if their per-record access is forbidden. |

## Acceptance

- All FRs met.
- Revocation latency smoke test: room owner clicks revoke; within 1s, the revoked member's next request returns 403.
- Watermark smoke test: viewer exports a PDF; opened PDF shows the watermark overlay with viewer's email and timestamp.
- Audit smoke test: complete a full join → view-document → export → revoke cycle; all 4+ audit rows present with correct `data_room_id` and `event_type`.

## Risks

- **Watermark removability.** Determined adversary can crop or redact the watermark. Mitigation: spec explicitly notes watermarking is a deterrent, not DRM; combine with classification labels + legal agreement at invitation time. Document the limitation in admin UI tooltip.
- **Cached document access after revocation.** Mitigated by FR-008 (offline access loads documents on-demand; revoked room returns 403 on next document-load) but a window exists where a freshly-cached document persists locally. Mitigation: client-side wipe on receiving a `data-room.revoked` event via Mercure SSE.
- **Email invitation latency.** Outbound email delivery is not guaranteed sub-minute. Mitigation: in-app invitation indicator surfaces in invited user's notification panel; email is the secondary signal.
- **Consent-state regression on offline operations.** Per FR-008, consent is server-authoritative — no risk if rule is enforced.

## Out-of-band

- End-to-end encryption → v1.0 Anokii mission.
- External-identity SSO for invited users → v1.0 mission (after framework OIDC provider completes).
- Bulk artifact inclusion via folder reference → v0.5 mission.
- Document copy-prevention beyond watermarking → research mission (likely not technically achievable in a web browser).
