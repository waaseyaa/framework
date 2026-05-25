# Anokii v0.1 — Governed Sheets (draft spec, mission seed)

**Draft status:** Seed for future Anokii-repo mission filing via `spec-kitty specify`. Pre-resolved per `anokii-distribution-scaffold-01KSEFT7/spec.md` §Pre-resolved decisions. Re-file in the Anokii repo as `governed-sheets-<ULID>` once Wave-2 begins.

**Cross-references:**
- **Framework directives:** DIR-004 (OCAP — per-cell access via FieldAccessPolicyInterface), DIR-005 (two-axis storage — sheets revisioned), DIR-007 (Nuxt SPA).
- **Anokii directives:** DIR-A001 (AODA — sheet cell navigation a11y), DIR-A002 (offline-first), DIR-A003 (translation pipeline — sheet headers / column labels translatable), DIR-A005 (OCAP).
- **Gap-matrix rows:** B4 (Governed Sheets — tabular + formulas + OCAP sharing).

## Why

Sheets is the tabular peer to Docs. The framework today ships typed fields and listing primitives, but no tabular/cell field type and no formula engine. Anokii at v0.1 ships a `tabular_field` (cell grid) + minimal formula engine (SUM, AVG, COUNT, MIN, MAX, IF, basic arithmetic) + per-cell access via FieldAccessPolicyInterface evaluated against per-row classification labels. Advanced lookups (INDEX/MATCH/VLOOKUP), pivot tables, charts, and multi-user real-time edit are all explicitly deferred. The v0.1 goal is "useful tabular data with OCAP sharing", not "Excel parity".

## Scope

### In scope

- **`governed_sheet` entity.** Fields: `id`, `uuid`, `title` (translatable), `description` (translatable), `folder_id` (Drive folder link), `classification_label`, `owner_id`, `created_at`, `updated_at`, `revision_id`.
- **`tabular_field` value type.** Cell grid serialised as JSON: `{ rows: [...], columns: [...], cells: { "A1": "value", "B2": "=SUM(A1:A10)", ... } }`. Cell addresses use spreadsheet A1 notation.
- **Cell types:** text, number, date, formula (`= ...`), boolean, currency, relationship (link to another entity).
- **Minimal formula engine.** Functions: SUM, AVG, COUNT, MIN, MAX, IF, basic arithmetic operators (+, -, *, /, %), comparison operators (==, !=, <, >, <=, >=), reference (`A1`, `A1:A10`). NO INDEX/MATCH, NO VLOOKUP/HLOOKUP, NO array formulas at v0.1.
- **Per-cell access via FieldAccessPolicyInterface.** Each row carries a `classification_label`; cell access evaluated against the row's label + current user's permissions per DIR-004. Cells inaccessible to the current user render as `[restricted]` and do not contribute to formula evaluation visible to that user (server-side: full evaluation against owner's access; client-side: filtered re-evaluation against viewer's access).
- **CSV export with audit.** Export action writes an OCAP audit row capturing exported row count + classification labels exported.
- **Admin Sheets UI in Nuxt SPA.** Sheet list (filterable by folder, classification); Sheet editor (grid view with cell-coordinate breadcrumb, formula bar, column headers); revision-history drawer (browse + restore prior revision).
- **Offline-first per DIR-A002.** Full sheet cached in Dexie; cell edits queue offline; LWW per cell on sync conflict; formula recalculation runs locally with same engine as server.
- **Localised column headers per DIR-A003.** Column header strings flow through translation pipeline.

### Out of scope

- **Multi-user real-time cell editing.** v1.5 mission (CRDT or operational transform required).
- **Advanced lookup functions** (INDEX/MATCH, VLOOKUP, HLOOKUP, XLOOKUP). v1.0 mission.
- **Array formulas / spilled ranges.** v1.0 mission.
- **Pivot tables.** v1.0 mission.
- **Charts / data visualisation embedded in sheet.** v0.5 mission.
- **Conditional formatting** (cell color based on value). v0.5 mission.
- **Cell-level comments.** v0.5 mission.
- **Cross-sheet references.** v0.5 mission.
- **External data import** (CSV upload → sheet; Excel file import). v0.5 mission.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `governed_sheet` entity registered with framework `EntityTypeManager`; revisioned + translatable per DIR-005. |
| FR-002 | Mandatory | `tabular_field` value type implemented as a framework field type; JSON serialisation per spec; A1 cell addressing. |
| FR-003 | Mandatory | Cell types supported: text, number, date, formula, boolean, currency, relationship. |
| FR-004 | Mandatory | Minimal formula engine supports SUM, AVG, COUNT, MIN, MAX, IF, arithmetic, comparison, cell references and ranges. Evaluation deterministic across server + client. |
| FR-005 | Mandatory | Per-cell access via FieldAccessPolicyInterface per DIR-004; row-level classification labels; inaccessible cells render `[restricted]` for viewer. |
| FR-006 | Mandatory | CSV export writes OCAP audit row per DIR-A005 capturing exported row count + classification labels. |
| FR-007 | Mandatory | Admin Sheets UI in Nuxt SPA: sheet list, grid editor, formula bar, revision-history drawer; AODA Level AA per DIR-A001 — cell navigation via arrow keys; cell coordinate announced via `aria-label`; named-region support for SR; formula bar accessible. |
| FR-008 | Mandatory | Offline-first per DIR-A002: full sheet cached in Dexie; cell edits queue offline; LWW per cell on sync; formula recalculation deterministic across server + client. |
| FR-009 | Mandatory | Column headers per DIR-A003 routed through translation pipeline. |
| NFR-001 | Mandatory | Formula evaluation must be sandboxed — no `eval`, no arbitrary code execution; parser explicitly enumerates supported tokens. |
| NFR-002 | Mandatory | Sheet rendering must remain operational for ≥ 10000 cells without measurable jank; virtualised row rendering required above 500 visible rows. |
| NFR-003 | Mandatory | Formula evaluation server + client must agree on result for every supported function; contract test enforces this. |
| C-001 | Constraint | NO third-party spreadsheet vendor integration per DIR-008 / DIR-A004. Engine is in-process. |
| C-002 | Constraint | Per-cell access (FR-005) is NOT optional — every cell render passes through AccessChecker per DIR-004. |
| C-003 | Constraint | Server-side formula evaluation uses owner's access scope; client-side re-evaluates against viewer's access (which may produce different visible totals). The discrepancy is documented in UI ("totals reflect cells you can read"). |

## Acceptance

- All FRs met.
- Formula smoke: `=SUM(A1:A10)`, `=IF(B1>100, "high", "low")`, `=AVG(C1:C5)*1.05` all evaluate correctly on server + client; contract test green.
- Per-cell access smoke: viewer with `community` access reads a sheet with mixed `community` + `nation-restricted` rows; `nation-restricted` cells render `[restricted]`; formula `=SUM(A1:A10)` shows different result than owner-view.
- Offline smoke: open sheet, go offline, edit 20 cells across 5 rows, come back online — edits sync with LWW; audit log captures changes.
- CSV export smoke: export 100-row sheet; audit row captures count + labels.

## Risks

- **Per-cell access performance.** Evaluating FieldAccessPolicyInterface per cell on a 10000-cell sheet is expensive. Mitigation: classification labels are row-level (not cell-level) at v0.1, so evaluation is per-row not per-cell; cache evaluations per render.
- **Formula evaluation divergence client vs server.** Floating-point arithmetic + locale-sensitive number formatting can cause subtle disagreements. Mitigation: explicit number-handling specification (use bignum-safe operations for currency; document precision limits); contract test runs ≥ 100 fixture formulas through both.
- **CSV export bypass risk.** A viewer with cell-level restrictions exports the sheet — what do they get? Decision: export reflects viewer's access (restricted cells exported as empty string); audit row captures exporter's identity + restrictions visible to them.
- **Sheet size scaling.** 10000 cells is the v0.1 target; sheets larger than this are a future-mission concern (sharding, virtualised loading).

## Out-of-band

- INDEX/MATCH/VLOOKUP/XLOOKUP → v1.0 Anokii mission.
- Pivot tables → v1.0 Anokii mission.
- Charts in sheets → v0.5 Anokii mission.
- Conditional formatting → v0.5 Anokii mission.
- Cell-level comments → v0.5 Anokii mission.
- Cross-sheet references → v0.5 Anokii mission.
- External data import → v0.5 Anokii mission.
- Multi-user real-time cell editing → v1.5 Anokii mission (depends on CRDT research).
