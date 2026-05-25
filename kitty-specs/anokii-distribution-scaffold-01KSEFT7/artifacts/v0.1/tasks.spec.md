# Anokii v0.1 — Tasks (draft spec, mission seed)

**Draft status:** Seed for future Anokii-repo mission filing via `spec-kitty specify`. Pre-resolved per `anokii-distribution-scaffold-01KSEFT7/spec.md` §Pre-resolved decisions. Re-file in the Anokii repo as `tasks-<ULID>` once Wave-2 begins.

**Cross-references:**
- **Framework directives:** DIR-004 (OCAP-by-architecture), DIR-005 (two-axis storage — tasks revisioned for audit), DIR-007 (Nuxt SPA — kanban UI reuses framework `admin/workflows` kanban pattern).
- **Anokii directives:** DIR-A001 (AODA Level AA — kanban drag-drop has keyboard alternative), DIR-A002 (offline-first), DIR-A003 (translation pipeline — task titles localised on demand), DIR-A005 (OCAP).
- **Gap-matrix rows:** D1 (Tasks — Kanban / lists / assignments). Noted as the cheapest productivity surface because the admin kanban pattern is reusable from the existing workflows pipeline UI.

## Why

Tasks is the lowest-cost productivity surface in the v0.1 cluster because the framework already ships a kanban UX pattern (the workflows pipeline UI in `admin/workflows`, beta). Anokii's Tasks surface adds a general-purpose `task` entity + list/board views + assignee notifications + a due-date scheduler. The OCAP audit log + offline-first behaviour fall out naturally from the framework substrate. Shipping Tasks early validates the broader Anokii UI pattern (kanban + assignment + notifications) before the more complex surfaces (Docs, Sheets, Co-Intelligence) land.

## Scope

### In scope

- **`task` entity.** Fields: `id`, `uuid`, `title` (translatable), `description` (translatable), `assignee_id` (nullable, user reference), `due_at` (nullable), `status` (enum: `backlog`, `todo`, `doing`, `done`, `cancelled`), `parent_list_id` (relationship to `task_list`), `priority` (enum: `low`, `medium`, `high`, `urgent`), `classification_label`, `created_by`, `created_at`, `updated_at`, `revision_id`.
- **`task_list` entity.** Fields: `id`, `uuid`, `title` (translatable), `description` (translatable), `column_definitions` (JSON array of column slugs + display labels, defaults to backlog/todo/doing/done/cancelled), `owner_id` or `group_id`, `classification_label`, `created_at`, `updated_at`, `revision_id`.
- **Assignment notifications via framework `notification` package.** On assignment change, notify the new assignee. On due-date approach (configurable lead time), notify the assignee.
- **Due-date scheduler via framework `scheduler` package.** A cron-driven job sweeps tasks approaching due-date and dispatches notifications.
- **Admin Tasks UI in Nuxt SPA.** Two primary views: (1) Kanban board (drag-drop between columns; keyboard alternative per AODA) reusing the framework workflows pipeline kanban; (2) List view (filterable by assignee, due-date, classification).
- **Per-task audit per DIR-A005.** Audit rows on create, status change, assignment change, due-date change, classification change, delete.
- **Status changes are append-only events for audit trail.** Updating `task.status` writes a new revision per DIR-005; old status values are recoverable.

### Out of scope

- **Task dependencies / Gantt-style scheduling.** v0.5 mission.
- **Recurring tasks.** v0.5 mission (recurrence pattern + spawn-on-schedule logic).
- **Time tracking on tasks.** v1.0 mission.
- **Cross-tenant task sharing.** Tasks at v0.1 are scoped to a single Nation tenant.
- **Task templates / pre-built workflows.** v1.0 mission.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `task` and `task_list` entities registered with framework `EntityTypeManager`; revisioned + translatable per DIR-005. |
| FR-002 | Mandatory | Status changes write a new revision per DIR-005; full status-change history is recoverable from revisions. |
| FR-003 | Mandatory | Assignment changes dispatch a notification via framework `notification` package to the new assignee. |
| FR-004 | Mandatory | Due-date approach (configurable lead time, default 24h) dispatches a notification to the assignee via framework `scheduler`. |
| FR-005 | Mandatory | Admin Tasks UI in Nuxt SPA renders kanban board (reusing framework workflows pipeline UI pattern) + list view; kanban drag-drop has keyboard alternative per DIR-A001. |
| FR-006 | Mandatory | AODA Level AA per DIR-A001: kanban columns labelled with `<h2>`, cards reachable via Tab + Enter; drag-drop keyboard alternative (Space to grab, arrow keys to move, Enter to drop); status-change announcement via `aria-live="polite"`. |
| FR-007 | Mandatory | Offline-first per DIR-A002: tasks for own-assigned + own-created scope cached in Dexie; status/assignment edits queue offline; LWW per field on sync; `offline_at` timestamp on every event surfaces in audit. |
| FR-008 | Mandatory | OCAP audit per DIR-A005 on every task operation (create, status change, assignment change, due-date change, classification change, delete). |
| NFR-001 | Mandatory | Notification dispatch must not block the request that triggered it — async via framework `queue`. |
| NFR-002 | Mandatory | Kanban UI must render 100 tasks per column without measurable jank; virtualised scrolling required above 50 cards per column. |
| NFR-003 | Mandatory | Localised task titles per DIR-A003 use translation pipeline; fallback to English when no translation exists. |
| C-001 | Constraint | No third-party task-management vendor integration (Asana / Trello / Jira) at v0.1 per DIR-008 / DIR-A004 license posture. Tasks is a first-party surface. |
| C-002 | Constraint | Tasks at v0.1 are scoped to a single Nation tenant; cross-Nation task sharing is OUT until classification + audit semantics are designed for that crossing. |

## Acceptance

- All FRs met.
- axe-core CI gate passes per DIR-A001 (kanban drag-drop keyboard a11y is the highest-risk surface).
- Offline smoke: create 5 tasks offline, edit 3, come back online — all 5 land server-side with `offline_at` timestamps; status changes apply via LWW; audit log complete.
- Notification smoke: assign a task to user B; user B receives an in-app notification within 30s.

## Risks

- **Drag-drop keyboard a11y is the AODA blocker for this surface.** Native HTML drag-drop is not keyboard-accessible. Mitigation: build on a third-party keyboard-accessible kanban library (preferred: one already permissive-licensed and compatible with GPL-2.0-or-later) OR implement custom keyboard handlers per WAI-ARIA Authoring Practices kanban pattern. Either path is documented in the implementation plan, not the spec.
- **Notification spam from frequent status changes.** Mitigation: rate-limit per-task per-recipient notifications to 1 per 5 minutes; final state-change collapses intervening edits in the notification body.
- **Offline LWW on assignment.** Two users offline simultaneously reassign the same task to different people; on sync, the later `offline_at` wins. The earlier assignee receives a notification of unassignment, surfacing the conflict to humans.

## Out-of-band

- Recurring tasks → v0.5 Anokii mission.
- Task dependencies + Gantt → v0.5 Anokii mission.
- Time tracking → v1.0 Anokii mission.
- Cross-tenant task sharing → v1.0 Anokii mission (requires classification + audit design).
- Task templates → v1.0 Anokii mission.
