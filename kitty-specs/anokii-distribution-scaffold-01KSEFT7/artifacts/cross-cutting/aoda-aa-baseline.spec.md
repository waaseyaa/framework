# Anokii v0.1 — AODA Level AA Baseline (draft spec, mission seed)

**Draft status:** Seed for future Anokii-repo mission filing via `spec-kitty specify`. Pre-resolved per `anokii-distribution-scaffold-01KSEFT7/spec.md` §Pre-resolved decisions. Re-file in the Anokii repo as `aoda-aa-baseline-<ULID>` once Wave-2 begins.

**Cross-references:**
- **Framework directives:** DIR-004 (OCAP visibility must reach SR users — access-denied messages SR-accessible), DIR-007 (Nuxt SPA is the implementation substrate for axe-core CI gate + per-component test pass).
- **Anokii directives:** DIR-A001 (AODA Level AA is a design constraint, not optional feature — this draft is the canonical implementation reference for that directive), DIR-A002 (offline status indicators must be SR-accessible).
- **Gap-matrix rows:** Cross-cutting — touches every B/C/D/F surface. AODA does not have a single gap-matrix row; it is a constraint applied to every surface.
- **Design-doc source:** `/tmp/waaseyaa-design-accessibility.md` (entire document is the canonical source for this draft).

## Why

Per DIR-A001, AODA Level AA (which subsumes WCAG 2.1 Level AA + AODA-specific procurement-legibility requirements) is a design constraint baked into every Anokii v0.1 surface — not an optional feature, not a "make it accessible later" deferral. This positions Anokii as procurement-legible for Ontario government, federal Indigenous funders, and Nation IT procurement teams that require AODA compliance as a non-negotiable. The framework's Nuxt SPA + schema-driven admin surface (DIR-007) provides the implementation substrate; axe-core CI gates + per-component a11y tests in vitest + Playwright e2e enforce the baseline.

Co-Intelligence's AI-response surface is the most novel a11y challenge in the v0.1 cluster — few accessible-AI-response patterns exist in the wild — so it gets a dedicated subsection. Access-denied messages get a dedicated subsection because OCAP-by-architecture (DIR-004) is the framework's defining product claim and the access-denial UX is where governance becomes user-visible.

## Scope

### In scope

- **AODA Level AA target across all v0.1 surfaces.** Drive, Forms, Tasks, Data Rooms, Docs, Sheets, Co-Intelligence, Admin Centre.
- **Per-surface constraint catalogue** (mirrors design-doc §5):
  - **Drive:** table semantics on file list (`<table>` + `<th scope="col">`), file-icon `alt` text, breadcrumb nav, folder-tree keyboard nav (arrow keys to expand/collapse), share-link revoke confirmation modal with focus trap.
  - **Forms:** fieldsets with `<legend>`, conditional visibility via `aria-hidden="true"` + `inert` (not `display:none` alone), error summary `role="alert" aria-live="assertive"`, per-field errors via `aria-describedby`, required-field `aria-required="true"` + visual indicator, submit confirmation modal with focus trap.
  - **Tasks:** kanban columns labelled with `<h2>`, cards reachable via Tab + Enter, drag-drop keyboard alternative (Space to grab, arrow keys to move, Enter to drop), status-change announcement via `aria-live="polite"`.
  - **Data Rooms:** consent-flow focus management (focus moves through invitation → consent → confirmation), watermark `alt` text on export-preview, revoke confirmation focus trap.
  - **Docs:** heading-level hierarchy enforced by editor (no skipped levels), keyboard-shortcut help dialog (toggled by `?` key), screen-reader announcements for inline comment threads, conflict-resolution UI focus management.
  - **Sheets:** cell navigation via arrow keys, cell coordinate announced via `aria-label="<column letter><row number>"`, named-region support for SR, formula bar accessible, column header `<th scope="col">`.
  - **Co-Intelligence:** focus moves to AI response surface on first response token; multi-step "thinking..." in `aria-live="assertive"`; response chunks in `aria-live="polite"`; long responses (> 500 words) summarised first with expandable detail; completion focuses follow-up input.
  - **Admin Centre:** data-grid table semantics, audit-log row keyboard navigation, alert panel `role="alert" aria-live="polite"`, confirmation modals with focus trap.
- **Governance-aware accessibility (access-denied UX).**
  - Hard access-denied (server-side OCAP `Forbidden`): `aria-live="assertive"` + `role="alert"` + actionable recovery hint ("Request access from your Nation admin"). Visible AND announced.
  - Soft access-denied (capability-not-granted in current session, e.g., admin-only action while logged in as community user): `aria-live="polite"` + softer recovery hint ("Sign in with elevated role").
  - Both produce a same-page inline message; do NOT route to a separate 403 page (loses context).
- **Co-Intelligence response surface specifics** (per design-doc §7.2):
  - Wrap response: `<div aria-live="polite" aria-label="AI assistant response">`.
  - Processing state: "Thinking... (step N of M)" in `aria-live="assertive"`.
  - Long responses summarised first; "Show more" expansion preserves SR position.
- **axe-core CI gate** enforces the baseline. Gate runs per-route in vitest @vue/test-utils environment + per-flow in Playwright e2e. Fails CI on any new AXE violation (baseline-suppressed for pre-existing issues only).
- **13-component baseline pass list.** Enumerated by component name: AdminShell, AdminSidebar, AuthLogin, AuthSession, AuditLogDrawer, ClassificationChip, ConfirmationModal, DataGrid, FileList, FormBuilder, KanbanBoard, RichTextEditor, SheetGrid. Each gets a per-component a11y test in vitest exercising keyboard nav, SR announcements, focus management, color-contrast.
- **Offline status indicators per DIR-A002** are SR-accessible: `aria-live="polite"` on `online`/`syncing`/`offline`/`conflict` state changes; per-surface pending-sync badge has `aria-label` with count.

### Out of scope

- **AODA Level AAA target.** AA is the v0.1 baseline; AAA is a long-tail compliance push not justified for v0.1.
- **Per-language SR voice tuning for Anishinaabemowin.** Browser/OS SR engines handle pronunciation; voice tuning is a v1.0+ research mission.
- **Sign-language video accompaniment for instructional content.** v1.0+ mission (specific use cases TBD).
- **Custom high-contrast theme.** Browser/OS forced-colors mode is the v0.1 path; custom themes are v0.5+.
- **Reduced-motion preference handling beyond CSS `prefers-reduced-motion`.** v0.1 respects the media query; richer per-user toggles are v0.5+.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | Every v0.1 surface (Drive, Forms, Tasks, Data Rooms, Docs, Sheets, Co-Intelligence, Admin Centre) implements the per-surface constraint catalogue in §In scope. |
| FR-002 | Mandatory | Hard access-denied messages use `aria-live="assertive"` + `role="alert"` + actionable recovery hint per DIR-004 / DIR-A005; soft denied use `aria-live="polite"`; both inline (not route-redirect). |
| FR-003 | Mandatory | Co-Intelligence response surface: focus to response on first token; "Thinking... (step N of M)" in `aria-live="assertive"`; response chunks in `aria-live="polite"`; long responses summarised first. |
| FR-004 | Mandatory | axe-core CI gate runs per-route in vitest + per-flow in Playwright; fails CI on any new violation; baseline-suppressed for pre-existing only. |
| FR-005 | Mandatory | 13-component baseline pass list (AdminShell, AdminSidebar, AuthLogin, AuthSession, AuditLogDrawer, ClassificationChip, ConfirmationModal, DataGrid, FileList, FormBuilder, KanbanBoard, RichTextEditor, SheetGrid) — each has a per-component a11y test in vitest. |
| FR-006 | Mandatory | Offline status indicators per DIR-A002 are SR-accessible: state changes via `aria-live="polite"`; pending-sync badges have `aria-label` with counts. |
| FR-007 | Mandatory | Keyboard navigation is comprehensive — every interactive element reachable via Tab; every drag-drop has a keyboard alternative; modal dialogs trap focus and restore on close. |
| FR-008 | Mandatory | Color contrast meets WCAG 2.1 AA (4.5:1 for normal text, 3:1 for large text) across the deep-teal palette + neutral palette + state-color palette. Anokii branded tokens (per scaffold WP03) are validated for contrast at sign-off. |
| FR-009 | Mandatory | Forms-specific: error summary `role="alert" aria-live="assertive"`; conditional visibility via `aria-hidden="true"` + `inert`; required-field `aria-required="true"`; submit confirmation focus-trap. |
| FR-010 | Mandatory | Headings hierarchy semantically correct (no skipped levels) across every page; landmarks (`<header>`, `<nav>`, `<main>`, `<aside>`, `<footer>`) used appropriately. |
| NFR-001 | Mandatory | axe-core gate runtime overhead in CI must not exceed a documented budget; per-component test suite parallelisable. |
| NFR-002 | Mandatory | SR testing performed at least once per v0.1 release against NVDA (Windows) and VoiceOver (macOS) — manual sign-off step documented. |
| NFR-003 | Mandatory | Focus management on dynamic content changes (e.g., conditional form fields appearing, modal dialogs, AI response surfaces) MUST be deterministic and tested. |
| C-001 | Constraint | AODA Level AA is the floor, not the ceiling — surfaces MAY exceed AA but MUST NOT fall below it per DIR-A001. |
| C-002 | Constraint | Inaccessible third-party libraries (e.g., a charting library without keyboard nav) MUST NOT be adopted per DIR-A001; alternatives evaluated. |
| C-003 | Constraint | NO use of `display: none` alone for conditional visibility (FR-009) — breaks `aria-describedby` resolution per design-doc §5.3. |
| C-004 | Constraint | Co-Intelligence response surface focus management (FR-003) is non-negotiable — this is the highest-risk novel a11y pattern in the v0.1 cluster. |

## Acceptance

- All FRs met.
- axe-core CI gate passes for all v0.1 surfaces with zero new violations.
- Per-component a11y test suite green for all 13 baseline components.
- NVDA + VoiceOver manual sign-off completed and documented per v0.1 release.
- Access-denied UX smoke (per OCAP test): SR user attempts a forbidden action; "assertive" announcement heard with actionable recovery hint; user remains in context (no page redirect).
- Co-Intelligence a11y smoke: SR user submits prompt; "AI is responding..." heard; response chunks heard; long response summarised; focus lands on follow-up input. axe-core green.

## Risks

- **AODA AA across a complex SPA is a moving target.** New components introduce new violations. Mitigation: per-component a11y test gate; axe-core CI gate; component review checklist.
- **Co-Intelligence focus management is novel.** Few accessible-AI patterns in the wild. Mitigation: per-component review with SR user prior to first release; pattern documented in this spec for future reference.
- **TipTap rich-text editor a11y baseline** is the highest-risk component. Mitigation: TipTap provides a11y-ready primitives; per-component a11y test is mandatory; SR sign-off prior to Docs surface release.
- **axe-core false positives on framework-shipped components.** If `packages/admin` (framework) has axe violations, they pre-date Anokii and the baseline suppresses them. Mitigation: Anokii's CI gate only fails on NEW violations introduced by Anokii code; framework-side violations are framework's to fix.
- **Color contrast in branded deep-teal palette.** The deep-teal hex values (`#0d4f4f`, `#0f766e`, `#14b8a6`) must be validated against background colors used in admin chrome. Mitigation: contrast validation at WP03 sign-off (scaffold mission); per-component test exercises text-on-token combinations.

## Out-of-band

- AODA Level AAA → not pursued at v0.1; reconsider for v1.0 based on adoption signal.
- Anishinaabemowin SR voice tuning → v1.0+ research mission.
- Sign-language video accompaniment → v1.0+ mission (use-case driven).
- Custom high-contrast theme → v0.5 Anokii mission.
- Reduced-motion per-user toggles beyond `prefers-reduced-motion` → v0.5 Anokii mission.
