# Anokii v0.1 — Form Builder (draft spec, mission seed)

**Draft status:** Seed for future Anokii-repo mission filing via `spec-kitty specify`. Pre-resolved per `anokii-distribution-scaffold-01KSEFT7/spec.md` §Pre-resolved decisions. Re-file in the Anokii repo as `form-builder-<ULID>` once Wave-2 begins.

**Cross-references:**
- **Framework directives:** DIR-004 (OCAP-by-architecture), DIR-005 (two-axis storage — form definitions revisioned + translatable; submissions are records), DIR-007 (Nuxt SPA).
- **Anokii directives:** DIR-A001 (AODA Level AA — Forms is the heaviest AODA-impact surface), DIR-A002 (offline-first — Forms is the marquee offline surface for Robinson-Huron intake workflows), DIR-A003 (translation pipeline — form labels in Ojibwe), DIR-A005 (OCAP inherits framework DIR-004).
- **Gap-matrix rows:** C (form-builder capability — conditional logic, branching, validators).
- **Design-doc source:** `/tmp/waaseyaa-design-accessibility.md` §5.3 (Forms AODA constraints).

## Why

Forms are the single most important offline-first surface for Robinson-Huron Treaty Nation workflows — intake forms, office notes, community surveys, funding-application drafts — all of which a field worker fills out where connectivity is intermittent or absent. Forms also drive the largest AODA AA surface area: every form field is a screen-reader interaction, every error message is an `aria-live` announcement, every conditional-visibility branch is a semantic question. Waaseyaa today ships typed fields and validation primitives; what Anokii ships at v0.1 is the form-builder UX, the conditional-logic engine, and the multi-submission-merge offline queue posture.

## Scope

### In scope

- **`form_definition` entity** (revisioned via framework two-axis). Fields: `id`, `uuid`, `title` (translatable), `description` (translatable), `slug`, `version`, `published_at`, `archived_at`, `classification_flag` (default `community-data`; opt-in `administrative` for LWW semantics).
- **`form_field` entity** (child of `form_definition`). Types: `text`, `textarea`, `email`, `phone`, `number`, `select`, `multiselect`, `radio`, `checkbox`, `date`, `datetime`, `file` (links to Drive), `relationship` (links to any entity type via reference), `signature` (canvas-drawn).
- **Conditional logic engine.** Rule shape: `when field <X> <operator> <value>, then <action> field <Z>` where `operator ∈ {equals, not_equals, in, not_in, present, absent}` and `action ∈ {show, hide, require, optional}`. Rules evaluated client-side for UX and server-side for submission validation.
- **Branching / multi-page forms.** Forms may have explicit page boundaries; conditional logic may jump to a non-sequential page.
- **Validators per field.** Required, regex, min/max (length for text, value for number), custom validator (server-side hook via framework `validation` package).
- **`form_submission` entity.** Carries `form_definition_id`, `form_definition_version` (immutable — submission preserves the form version it was submitted against), `submitted_by`, `submitted_at`, `offline_at` (nullable), `values` (typed bag per field), `classification_label`, `revision_id`.
- **Multi-submission-merge as DEFAULT** for `classification_flag = community-data`: every submission is a record; sync-on-reconnect creates new records rather than merging into a "latest". LWW available only when `classification_flag = administrative` (for admin-edited config forms where latest-is-canonical).
- **AODA constraints per DIR-A001:** fieldsets with `<legend>` grouping related fields; conditional visibility via `aria-hidden="true"` (NOT `display: none` alone); error summary at top of form `role="alert" aria-live="assertive"`; per-field errors linked via `aria-describedby`; required-field `aria-required="true"` plus visual indicator; confirmation dialog on submit with focus trap.
- **Admin Form Builder UI** in the Nuxt SPA: drag-drop field palette, rule editor (visual `when X equals Y, show Z`), preview pane, publish/archive workflow, submission list with audit drawer.
- **Translation pipeline integration per DIR-A003.** Form labels, help text, and validation messages extracted via the translation pipeline; per-Nation overrides resolved at render time.

### Out of scope

- **PDF / printable layout export.** A form is digital-first; printable layouts ship in a v0.5 Anokii mission.
- **Payment-collection fields.** No Stripe/Square integration at v0.1.
- **Workflow routing on submission** (e.g., "send to approver"). Notification on submit ships at v0.1; multi-stage approval routing ships with Data Rooms / Reporting Workflows in a separate mission.
- **Cross-form field reuse / form templates.** Each form is authored fresh at v0.1.
- **External form embed (iframe / public form on a public URL).** v0.1 forms are authenticated-only.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `form_definition` and `form_field` entities registered with framework `EntityTypeManager`; revisioned + translatable per DIR-005. |
| FR-002 | Mandatory | Conditional-logic engine evaluates rules client-side (UX hide/show, optional/require) AND server-side (submission validation) — never trust client-only enforcement per DIR-004. |
| FR-003 | Mandatory | `form_submission` preserves the `form_definition_version` it was submitted against; later form edits never retroactively invalidate prior submissions. |
| FR-004 | Mandatory | Multi-submission-merge is DEFAULT for `classification_flag = community-data`; LWW opt-in via `classification_flag = administrative`. Decision recorded on the form definition, surfaced to the form author. |
| FR-005 | Mandatory | AODA Level AA per DIR-A001: fieldsets with `<legend>`, conditional `aria-hidden`, error summary `role="alert" aria-live="assertive"`, required-field `aria-required="true"`, confirmation dialog focus trap. |
| FR-006 | Mandatory | Offline-first per DIR-A002: form definitions cached in Dexie on first load; submissions queue in Dexie when offline; sync on reconnect with conflict resolution per `classification_flag`; per-submission `offline_at` timestamp surfaces in audit log. |
| FR-007 | Mandatory | Form labels + help text + validation messages routed through Anokii translation pipeline per DIR-A003; default English; per-Nation Ojibwe overrides resolved at render. |
| FR-008 | Mandatory | OCAP audit on submission create, view, edit (admin), delete (admin), classification-change per DIR-A005. |
| FR-009 | Mandatory | Admin Form Builder UI in Nuxt SPA: field palette, rule editor, preview pane, publish/archive workflow, submission list, audit drawer. |
| NFR-001 | Mandatory | Conditional-logic-engine rule evaluation must be deterministic across client + server — same input, same effective field visibility. |
| NFR-002 | Mandatory | Submission validation server-side must never accept a payload that the client rules would have rejected — server is authoritative. |
| NFR-003 | Mandatory | axe-core CI gate passes for Form Builder Admin UI and rendered Form pages. |
| C-001 | Constraint | No Drive bypass: file fields upload via Drive entity, inheriting Drive ACLs + audit per `governed-drive.spec.md`. |
| C-002 | Constraint | No retroactive form edits invalidate prior submissions (FR-003). Form-version pinning is non-negotiable. |
| C-003 | Constraint | No public unauthenticated forms at v0.1 — every submission carries an authenticated `submitted_by`. |

## Acceptance

- All FRs met.
- axe-core CI gate passes per DIR-A001.
- Offline smoke: open a form, go offline, submit 3 distinct submissions, come back online — all 3 records appear on the server with `offline_at` timestamps; audit log captures each.
- Multi-submission-merge: 2 users submit the same governed form offline simultaneously; on sync, both records appear (no merge into single "latest" record).
- LWW opt-in: an `administrative` form edited offline by 2 admins simultaneously; on sync, the latest `offline_at` wins; audit preserves both attempts.

## Risks

- **Server-client rule divergence.** If client and server evaluate conditional-logic rules differently, the user experience and persisted record disagree. Mitigation: shared rule serialisation format (JSON) + a contract test running the same fixture through both evaluators.
- **AODA conditional-visibility trap.** Using `display: none` for hidden fields hides them from SR users correctly but breaks `aria-describedby` linking. Mitigation: `aria-hidden="true"` AND `inert` attribute on the hidden container; visual hide via CSS sibling rule.
- **Offline form-definition staleness.** A user offline for weeks may submit against an archived form version. Decision: submission is accepted (preserves DIR-004 — the user did the work in good faith) but flagged in admin UI as "submitted against archived version".

## Out-of-band

- Printable / PDF export layout → v0.5 Anokii mission.
- Multi-stage approval routing → consumed jointly with Data Rooms mission.
- Form templates / cross-form field reuse → v1.0 Anokii mission.
- Public unauthenticated forms → v1.0 Anokii mission (requires CAPTCHA + classification posture review).
