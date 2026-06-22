---
work_package_id: WP04
title: Ten artifact draft specs (8 surfaces + 2 cross-cutting)
dependencies:
- WP02
requirement_refs:
- FR-008
- FR-009
- FR-010
- NFR-003
- C-001
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T014
- T015
- T016
- T017
agent: ''
history: []
authoritative_surface: kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/
execution_mode: planning_artifact
mission_id: 01KSEFT768GN09JZXHWMAMJNFR
owned_files:
- kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/governed-drive.spec.md
- kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/form-builder.spec.md
- kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/tasks.spec.md
- kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/data-rooms.spec.md
- kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/governed-docs.spec.md
- kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/governed-sheets.spec.md
- kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/co-intelligence-workspaces.spec.md
- kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/admin-centre.spec.md
- kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/cross-cutting/offline-first.spec.md
- kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/cross-cutting/aoda-aa-baseline.spec.md
tags: []
wp_code: WP04
---

# WP04 — Ten artifact draft specs (8 surfaces + 2 cross-cutting)

**Mission:** `anokii-distribution-scaffold-01KSEFT7`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the Waaseyaa repo, NOT the Anokii repo

WP04 produces draft Markdown files inside this scaffold mission's lane at `kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/`. **Do NOT run `spec-kitty specify` against the Anokii repo from inside this WP** (C-002). The drafts are seeds that a follow-up agent (separate session, separate mission) will paste into `spec-kitty specify` invocations inside the Anokii repo.

## THE pattern to mirror (read before writing anything)

- **`ai-observability-dashboard-01KSE9BX/spec.md`** — canonical `spec.md` shape: Why / Cross-layer constraint or equivalent context section / Scope (in / out) / Requirements table (FR/NFR/Constraint) / Acceptance / Risks / Out-of-band. Mirror this shape for every draft.
- **`charter-amendment-anokii-track-01KSEFE0/spec.md` + `plan.md`** — quality benchmark for cross-referenced directive IDs and pre-resolved-decision narrative.
- **`/tmp/waaseyaa-design-offline-first.md`** — canonical source for the offline-first cross-cutting draft. Read end-to-end (~6 KB). Note: §10 names Dexie + Workbox as the v0.1 recommendation; §6 sketches the substrate effort; §1..§9 cover the conflict-resolution FSM and per-surface strategy.
- **`/tmp/waaseyaa-design-accessibility.md`** — canonical source for the AODA AA cross-cutting draft and the AODA sections of each surface draft. §5.1..§5.N enumerate per-surface constraints; §7.2 covers Co-Intelligence focus management + announcement.
- **`/tmp/waaseyaa-design-translation-pipeline.md`** — referenced from Co-Intelligence Workspaces draft, Admin Centre draft, and the charter (already authored in WP02). §7 names the English ↔ Anishinaabemowin Phase-1 scope.
- **`/mnt/c/Users/jones/Projects/RussellJones/projects/waaseyaa-platform/gap-matrix.md`** — capability rows referenced by surface drafts (B1/B2 → Drive; B3 → Docs; B4 → Sheets; C → Forms; D1 → Tasks; D2 → Data Rooms; A5 → Co-Intelligence; F → Admin Centre).
- **Anokii charter** (WP02's output) — for the concrete DIR-A001..DIR-A005 IDs to cross-reference.

## Subtasks

**T014 — Read source material end-to-end.**
- Read each of the source files above before authoring. Use `ctx_execute_file` or sandbox tools to avoid context flooding on the long design docs.
- Confirm DIR-A001..DIR-A005 IDs from the Anokii charter as authored by WP02. If WP02 renumbered or added directives, use the actual IDs.

**T015 — Author eight v0.1 surface drafts** at `artifacts/v0.1/<surface>.spec.md`. Each follows the canonical `spec.md` shape. Each ≥ 4 KB (target 6–10 KB). Each contains:
- A **header block** with title, "Draft status: seed for future Anokii repo mission filing via `spec-kitty specify`. Pre-resolved per `anokii-distribution-scaffold-01KSEFT7/spec.md` §Pre-resolved decisions."
- A **Why** section explaining the surface's place in the v0.1 cluster.
- A **Cross-references** block naming: framework DIR-IDs composed on (DIR-004 OCAP for all; DIR-005 two-axis storage for surfaces touching entity revisions/translations; DIR-006 gates for admin-centre; DIR-007 Nuxt SPA for all SPA surfaces; DIR-008 GPL for licensing-sensitive surfaces); Anokii DIR-A IDs governing it (DIR-A001 AODA for all; DIR-A002 offline for all v0.1; DIR-A003 translation for surfaces with end-user-facing strings; DIR-A004 GPL; DIR-A005 OCAP for all); gap-matrix capability rows (B1/B2 Drive, B3 Docs, B4 Sheets, C Forms, D1 Tasks, D2 Data Rooms, A5 Co-Intelligence, F Admin Centre).
- A **Scope (in / out)** section.
- A **Requirements** table with ≥ 8 entries (FR, NFR, Constraint mix).
- An **Acceptance** section.
- A **Risks** section.
- An **Out-of-band** section listing follow-up missions that branch from this surface (e.g., for governed Docs at v0.1: "three-way merge → v1.0 mission; per-character CRDT collab → v1.0 mission; Y.js/Automerge selection → v0.5 research mission").

Surface-by-surface authoring guidance:
- **`governed-drive.spec.md`** — `drive_folder` entity with parent-folder relationship for hierarchy; ACL inheritance via FieldAccessPolicyInterface evaluated up the parent chain; `classification_label` field per-folder + per-file with inheritance + override; share-link entity with `expires_at` + `revoked_at`; audit on read/write/share/revoke/download. Compose on framework two-axis storage (folder rename produces a revision; folder localization is translatable). Offline: folder-tree metadata cached in Dexie; file blobs loaded on demand; LWW for folder-metadata renames; multi-submission-merge for permission changes is OUT (server-authoritative read-only-offline for ACLs).
- **`form-builder.spec.md`** — `form_definition` entity (versioned), `form_field` (typed: text/email/number/select/date/file/relationship/signature), conditional logic engine (`when field X equals Y, show Z`), branching (multi-page forms), validators (required/regex/min/max/custom). `form_submission` entity with multi-submission-merge as DEFAULT for governed community data (every submission is a record) and LWW opt-in via `classification_flag: "administrative"`. AODA: fieldsets with `<legend>`; conditional visibility via `aria-hidden`; error summary `role="alert" aria-live="assertive"`; required-field `aria-required="true"`. Offline: form definitions cached; submissions queued in Dexie; sync on reconnect with multi-submission-merge resolution; per-submission `offline_at` timestamp surfaces in admin audit log.
- **`tasks.spec.md`** — `task` entity (assignee user_id, due_at, status enum, parent_list_id, body), `task_list` entity (kanban board model with column definitions). Assignment notifications via framework `notification` package. Due-date scheduler via framework `scheduler` package. Admin kanban UI reuses framework `admin/workflows` pipeline pattern (gap-matrix D1 — cheapest surface). Offline: tasks fully cached for own-assigned + own-created scope; LWW per field on sync; status changes are append-only events for audit trail.
- **`data-rooms.spec.md`** — `data_room` entity, `data_room_member` join with `consent_state` (pending/granted/revoked) state-machine via framework `state` + `workflows`. Multi-party invitation flow with email/in-app notification. Share-link with `expires_at`; revocation immediately removes session access (no offline grace). Watermarking on exported documents (filename + viewer-user + timestamp). OCAP audit: every join/leave/document-view/export logged. Offline: data-room metadata cached for current members; documents loaded on-demand; consent operations server-authoritative (no offline consent).
- **`governed-docs.spec.md`** — rich-text field type wrapping TipTap/ProseMirror integration; comment-thread entities as relationship records; entity-level revisions via framework two-axis storage. Save-conflict resolution at v0.1 (three-way merge deferred to v1.0; per-character CRDT collab deferred to v1.5 — `Out-of-band` items). Offline: doc body cached; local edits queue in Dexie with revision-vector tracking; on save-conflict, present resolution UI; auto-save via framework `FieldAutoSaveController`. AODA: heading-level hierarchy enforced; keyboard-shortcut help dialog; screen-reader announcements for inline comment threads.
- **`governed-sheets.spec.md`** — `tabular_field` (cell-grid serialization), minimal formula engine (SUM, AVG, COUNT, MIN, MAX, IF, basic arithmetic — no INDEX/MATCH/VLOOKUP at v0.1). Per-cell access via FieldAccessPolicyInterface evaluated against per-row classification labels. CSV export with audit. Offline: full sheet cached; LWW per cell on sync conflict; formula recalculation deterministic across server/client. AODA: cell navigation via keyboard; cell-coordinate announcement via `aria-label`; named-region support for SR users.
- **`co-intelligence-workspaces.spec.md`** — `co_intel_workspace` entity scoped to a Drive folder OR data room OR ad-hoc record set. Per-record AI toggle (the gap-matrix A5 flagship — consumes framework `per-record-ai-access-flagship-*` substrate mission). AI response surface with focus management (focus moves to response area on first token) + progressive announcement (`aria-live="assertive"` for processing state, `polite` for response chunks); long responses summarised with expandable detail. i18n on UI strings driven by Anokii translation pipeline (DIR-A003). Offline: AI features REQUIRE connectivity (server-authoritative for safety); offline mode hides Co-Intelligence chrome with explanatory message; cached prior conversations browsable read-only when offline.
- **`admin-centre.spec.md`** — tenant-management UI (list/create/edit Nation tenants from `config/tenants/*.yaml`); classification-policy editor consuming framework `config` admin surfaces (gap-matrix A4); unified audit-query UI consuming framework OCAP audit log (gap-matrix A3); Anokii-specific overlays for branded theme management + translation-pipeline glossary management (links into translation-pipeline mission deliverables, currently future-tense). Strictly admin-role gated via framework AccessChecker (DIR-004); composes on DIR-006 (gates audit visibility). Offline: read-only-offline for admin; mutations require connectivity.

**T016 — Author two cross-cutting drafts** at `artifacts/cross-cutting/<topic>.spec.md`. Each ≥ 6 KB. Same canonical shape.
- **`offline-first.spec.md`**:
  - Dexie schema design with composite-key mirror of framework `(entity_id, langcode, vid)` tuple. Per-entity-type table layout.
  - Workbox service-worker config: cache strategies per URL pattern (static / API GET / API mutation queue).
  - FSM-based sync engine: states `idle → syncing → conflict → resolved → idle`; conflict-resolution strategy table per surface (Drive metadata: LWW; Forms: multi-submission-merge default + LWW opt-in via classification flag; Tasks: LWW per field; Data Rooms: server-authoritative read-only-offline; Docs: save-conflict resolution UI at v0.1; Sheets: LWW per cell at v0.1; Co-Intelligence: server-authoritative; Admin Centre: read-only-offline).
  - Identity offline: token-cache with explicit `expires_at`; re-auth-on-reconnect with cached refresh-token; partial-trust (read own classified data offline; NOT other Nations' cached data — enforced by client-side FieldAccessPolicyInterface mirror); offline operations carry `offline_at` timestamp on every event; audit log syncs on reconnect with server-side reconciliation (server is authoritative on conflicts; client losers are surfaced to the user).
  - Network-aware UI affordances: "online" / "syncing" / "offline" / "conflict" status indicator in admin shell; per-surface pending-sync badge counts.
  - Cross-references: framework DIR-004 (OCAP must survive offline), DIR-005 (two-axis preserved client-side), DIR-007 (Nuxt SPA hosts the Dexie + Workbox); Anokii DIR-A002 (offline is constraint).
  - Pre-resolves all decisions in spec.md §Pre-resolved.
- **`aoda-aa-baseline.spec.md`**:
  - AODA Level AA target across all v0.1 surfaces.
  - Per-surface constraint catalogue mirroring design doc §5: Drive (table semantics, file-icon `alt`); Forms (fieldsets, error summary, conditional visibility); Tasks (kanban a11y, drag-drop keyboard alternative); Data Rooms (consent-flow focus management, watermark a11y); Docs (heading hierarchy, comment-thread SR); Sheets (cell navigation, named regions); Co-Intelligence (focus management + progressive announcement); Admin Centre (data-grid a11y, audit-log table semantics).
  - **Governance-aware accessibility:** hard access-denied (server-side OCAP forbidden) → `aria-live="assertive"` + `role="alert"` + actionable recovery hint ("Request access from your Nation admin"); soft denied (capability-not-granted in this session) → `aria-live="polite"` + softer recovery hint ("Sign in with elevated role").
  - **Co-Intelligence specifics:** focus moves to AI response surface on first token; multi-step "thinking..." progress in `aria-live="assertive"`; response chunks in `aria-live="polite"`; long responses (>500 words) summarised first with expandable detail.
  - axe-core CI gate enforces baseline; per-component a11y test in vitest + Playwright e2e; 13-component baseline pass list enumerated.
  - Cross-references: framework DIR-007 (Nuxt SPA), DIR-004 (OCAP visibility through SR); Anokii DIR-A001 (AODA is constraint), DIR-A002 (offline must remain accessible — offline status indicator has SR text).
  - Pre-resolves all decisions in spec.md §Pre-resolved.

**T017 — Verify drafts.**
- `ls -1 kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/v0.1/*.spec.md | wc -l` → `8`.
- `ls -1 kitty-specs/anokii-distribution-scaffold-01KSEFT7/artifacts/cross-cutting/*.spec.md | wc -l` → `2`.
- For each file: `wc -c <file>` ≥ `4000`.
- For each file: `grep -c '^## Requirements' <file>` → `1`.
- For each file: `grep -cE '^\| (FR|NFR|C)-[0-9]+ \|' <file>` → `≥ 8`.
- For each file: `grep -iE 'week|month|sprint|TBD|TODO' <file>` returns nothing (or only inside `Out-of-band` follow-up references, which are non-normative).
- For each file: `grep -E 'DIR-(004|005|006|007|008)|DIR-A00[0-9]+' <file>` returns ≥ 1 line (cross-reference present).

## Commits

- `docs(artifacts): v0.1 productivity surface draft specs — 8 files (anokii-distribution-scaffold-01KSEFT7)`
- `docs(artifacts): cross-cutting draft specs — offline-first + AODA AA baseline (anokii-distribution-scaffold-01KSEFT7)`

## Report back with

1. Commit SHAs in the Waaseyaa repo (this mission's lane).
2. Outputs of the T017 verification commands.
3. For each of the ten files: byte count + count of FR/NFR/Constraint rows in the Requirements table.
4. Confirmation that DIR-A001..DIR-A005 IDs match the IDs minted by WP02 (paste one cross-reference line per draft).

## Activity Log

- 2026-05-25T05:09:57Z – unknown – Opus review: new repo waaseyaa/anokii live with composer + LICENSE + README + charter (DIR-A001..DIR-A005) + deploy + branded tokens + Pilot Nation A tenant stub. Repo currently public (consider toggling to private). 10 v0.1 surface seeds left in Waaseyaa artifacts/ for future Anokii-repo re-filing.
