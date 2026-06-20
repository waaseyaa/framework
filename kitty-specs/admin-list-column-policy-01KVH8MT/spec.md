# Feature Specification: Admin List-View Column Policy (UX-1)

**Mission:** `admin-list-column-policy-01KVH8MT` · **Type:** software-dev · **Target:** main · **Created:** 2026-06-20 · **Status:** Active — shipping finish-and-ship in alpha.235.

**Parent / context:** Routed out of `wayfinding-stress-remediation-01KVGK4Q` (its triage table marks **UX-1** as "ROUTE OUT to its own admin-list fix mission"). This mission owns the fix end to end.

## Summary

Every admin SPA list table is schema-driven: `SchemaList` renders the first six non-hidden fields of a content type's JSON Schema as table columns, with no bound on cell content. A content type with a long-text / rich-text body field (`text` / `text_long` field types) therefore dumps the **entire body** into a table cell, blowing out row height and making the list (`/admin/story` and, by construction, every other content-type table) nearly unusable.

The fix gives `SchemaList` a **list-view column policy**, framework-wide rather than story-specific: by default, exclude rich-text / text-format fields from the columns and truncate every remaining text cell to a short snippet. The full body remains untouched on the detail and edit views (`SchemaView` / `SchemaForm`), which select their fields independently. Finish-and-ship: the acceptance test is the release gate; no BC shims (there are no deployed downstream apps — parent C-002 holds).

## Actors

- **Content author** — browses `/admin/<type>` list tables; needs a scannable table where each row is one line, not a wall of body text.
- **Framework integrator** — defines content types with body fields and expects the admin list to be usable out of the box, with an opt-in (`x-list-display`) escape hatch when they want a specific column set.

## Root cause (verified first-hand)

`packages/admin/app/components/schema/SchemaList.vue` `columns` computed:

```js
const all = sortedProperties(false).filter(([, prop]) => prop['x-widget'] !== 'hidden')
const explicit = all.filter(([, prop]) => prop['x-list-display'] === true)
return explicit.length > 0 ? explicit : all.slice(0, 6)
```

Two gaps: (1) the default branch (`all.slice(0, 6)`) includes rich-text fields with no exclusion, and (2) `formatCellValue` returns `String(value)` verbatim with no length bound. The schema already distinguishes the field kinds — `SchemaPresenter::WIDGET_MAP` maps `text` → `x-widget: 'textarea'` (long plain text) and `text_long` → `x-widget: 'richtext'` (rich text / text-format) — so the policy can key off `x-widget` with no backend change.

## Requirements

- **FR-1 — Exclude rich-text columns by default.** When no field declares `x-list-display: true`, fields with `x-widget: 'richtext'` are excluded from `columns`. They remain in the schema and on `SchemaView` / `SchemaForm` (which use `sortedProperties()` independently).
- **FR-2 — Truncate text cells to a snippet.** Every string cell value is collapsed to one line (whitespace/newlines → single spaces) and capped at `SNIPPET_MAX_CHARS` (120) with an ellipsis. Boolean and `date-time` cells (already short) are unaffected.
- **FR-3 — Honor explicit opt-in.** An explicit `x-list-display: true` set still wins (the author's chosen column set), including a rich-text field if they opt it back in — but its cells are still snippet-truncated (FR-2).
- **FR-4 — Framework-wide.** The policy lives in `SchemaList`, not in any content-type definition, so it applies to every content type.
- **NFR-1 — Defense-in-depth.** A CSS `max-width` + `overflow: hidden; text-overflow: ellipsis` on `.entity-table td:not(.actions)` bounds column width even if a value ever slips past truncation; the actions column is exempt.
- **NFR-2 — No public admin surface contract change.** No new i18n keys; no schema/API change. The prebuilt admin bundle is rebuilt (dist-freshness gate D6).
- **C-001 — No XSS.** Cell values render through Vue mustache interpolation (escaped); truncating raw markup mid-string remains escaped text, never injected HTML.

## Acceptance criteria (release gate)

`packages/admin/tests/components/schema/SchemaListColumnPolicy.test.ts` (Vitest, `@nuxt/test-utils`):

1. A content type with `title` (text), `summary` (textarea), `body` (richtext) renders columns that **exclude** the rich-text `body` (no `list-field:<type>:body` header; 2 data columns + actions), and the full body string is **not** present anywhere in the rendered table.
2. The long-text `summary` cell is truncated: not equal to the full value, length ≤ `SNIPPET_MAX_CHARS + 1`, ends with `…`.
3. An explicit `x-list-display: true` on a rich-text field renders it as a column but still snippet-truncates the cell.

Plus the existing `SchemaList.test.ts` (Edit-busy, anchors, delete-error) stays green.

## Out of scope / deferred

- A per-column "show full value" tooltip / expandable cell (re-introduces full body into the DOM; the detail view is the home for full content).
- Configurable snippet length or per-type column overrides beyond the existing `x-list-display` opt-in.
- Server-side list projection (returning truncated values from the API) — the bound is a presentation concern and belongs in the SPA.
