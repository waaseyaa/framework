# Anokii v0.1 — Governed Docs (draft spec, mission seed)

**Draft status:** Seed for future Anokii-repo mission filing via `spec-kitty specify`. Pre-resolved per `anokii-distribution-scaffold-01KSEFT7/spec.md` §Pre-resolved decisions. Re-file in the Anokii repo as `governed-docs-<ULID>` once Wave-2 begins.

**Cross-references:**
- **Framework directives:** DIR-004 (OCAP), DIR-005 (two-axis storage — Docs revisions + translatable rich text), DIR-007 (Nuxt SPA).
- **Anokii directives:** DIR-A001 (AODA — rich-text editor a11y is high-risk), DIR-A002 (offline-first — Docs is a marquee offline surface with save-conflict resolution at v0.1), DIR-A003 (translation pipeline — Doc bodies translatable), DIR-A005 (OCAP).
- **Gap-matrix rows:** B3 (Governed Docs — rich text + OCAP collab + comments + revisions).

## Why

Governed Docs is the rich-text peer to Drive (files) and Sheets (tabular). The framework today ships `note` (alpha, rich-text candidate), entity-level revisions (production), and `field` typed values (production) — but no rich-text field type and no collaborative-editing infrastructure. Anokii ships at v0.1: a rich-text field type wrapping TipTap/ProseMirror, comment threads as relationship records, entity-level revisions via the framework two-axis substrate, and a save-conflict resolution flow (three-way merge and per-character CRDT collab are explicitly deferred to v1.0+). This unblocks office-note workflows, meeting minutes, draft policy authoring — all of which are read-after-write within the user's own classification scope (so they must work offline per DIR-A002).

## Scope

### In scope

- **Rich-text field type** wrapping TipTap (ProseMirror). Supports: headings (H1–H4), paragraphs, bold/italic/underline, ordered/unordered lists, links, blockquotes, code blocks, horizontal rules, inline citations (relationship reference). NO tables in the rich-text field at v0.1 (Sheets is the tabular surface).
- **`governed_doc` entity.** Fields: `id`, `uuid`, `title` (translatable), `body` (translatable rich-text field), `folder_id` (link to Drive folder), `classification_label`, `owner_id`, `created_at`, `updated_at`, `revision_id`.
- **`doc_comment` entity** (relationship to `governed_doc`). Fields: `id`, `uuid`, `doc_id`, `body` (translatable plain text), `anchor_node_id` (ProseMirror node ID the comment is anchored to), `resolved_at` (nullable), `author_id`, `created_at`.
- **Entity-level revisions** via framework two-axis storage. Each save creates a new revision; full history queryable.
- **Save-conflict resolution at v0.1.** When two users save the same Doc and their revision-vectors diverge, the second-saver sees a conflict-resolution UI: side-by-side diff + "keep mine" / "keep theirs" / "merge manually" choices. Three-way merge automation is OUT.
- **Per-character collaborative editing** is OUT (deferred to v1.5; requires Y.js/Automerge CRDT selection research mission).
- **Auto-save via framework `FieldAutoSaveController`.** Local edits flush to server on a debounce; offline writes queue in Dexie.
- **Inline citations.** A citation is a relationship reference inside the rich-text body pointing at another framework entity (Drive file, Sheet, another Doc, a record from any registered entity type). Citation reads through framework `AccessChecker` — if the citing user lacks access to the cited entity, the citation renders as `[restricted reference]` per DIR-004.
- **OCAP audit per DIR-A005** on Doc create, view, edit (writes a revision), comment-add, comment-resolve, classification-change, delete.
- **Admin Docs UI in Nuxt SPA.** Doc list (filterable by folder, classification, owner); Doc editor (TipTap-based with toolbar); revision-history drawer (browse + restore prior revision); comment-thread sidebar (anchored to specific nodes); conflict-resolution UI (when triggered).

### Out of scope

- **Three-way merge automation for save conflicts.** v1.0 Anokii mission (requires `diff-match-patch` integration per offline-first design §6).
- **Per-character collaborative editing** (real-time multi-user typing). v1.5 Anokii mission — depends on CRDT library selection (Y.js vs Automerge).
- **Inline tables.** Use Sheets surface for tabular data; v0.1 Docs has no inline table support.
- **Image embedding beyond Drive link.** Inline image upload-in-editor → v0.5 mission.
- **Doc export to .docx / .pdf with full formatting fidelity.** v0.5 mission; v0.1 ships HTML/Markdown export only.
- **Doc templates / template gallery.** v1.0 mission.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | Rich-text field type integrated via TipTap (ProseMirror); supports heading/paragraph/bold/italic/underline/lists/links/blockquote/code/citation. No inline tables at v0.1. |
| FR-002 | Mandatory | `governed_doc` and `doc_comment` entities registered with framework `EntityTypeManager`; revisioned + translatable per DIR-005. |
| FR-003 | Mandatory | Each Doc save creates a new revision per framework two-axis storage; revision history is fully queryable and restorable. |
| FR-004 | Mandatory | Save-conflict resolution UI presents side-by-side diff + keep-mine/keep-theirs/merge-manually choices when revision vectors diverge on save. Three-way automation is OUT at v0.1. |
| FR-005 | Mandatory | Auto-save via framework `FieldAutoSaveController` on debounce; offline writes queue in Dexie per DIR-A002 and sync on reconnect with save-conflict resolution flow when needed. |
| FR-006 | Mandatory | Inline citations are relationship references; reading a citation passes through framework AccessChecker per DIR-004; insufficient access renders `[restricted reference]`. |
| FR-007 | Mandatory | OCAP audit per DIR-A005 on Doc create, view, edit, comment-add, comment-resolve, classification-change, delete. |
| FR-008 | Mandatory | Admin Docs UI in Nuxt SPA: Doc list, Doc editor with toolbar, revision-history drawer, comment-thread sidebar anchored to nodes, conflict-resolution UI; AODA Level AA per DIR-A001 — heading-level hierarchy enforced by editor, keyboard-shortcut help dialog, screen-reader announcements for inline comment threads. |
| FR-009 | Mandatory | Doc body is translatable per DIR-005 + DIR-A003; per-Nation Ojibwe overrides resolve at render; fallback to English when no translation exists. |
| NFR-001 | Mandatory | TipTap editor must remain operational without network — schema + extensions loaded from cached bundle; no runtime dependency on external CDN. |
| NFR-002 | Mandatory | Revision count per Doc must scale to ≥ 1000 revisions without measurable jank in revision-history drawer; lazy-load older revisions. |
| NFR-003 | Mandatory | axe-core CI gate passes for Doc editor surface — TipTap is the highest-risk a11y surface in v0.1 productivity cluster. |
| C-001 | Constraint | NO per-character collaborative editing at v0.1 (FR-004 is save-conflict resolution, not CRDT merge). |
| C-002 | Constraint | NO third-party doc-editing vendor integration (Google Docs / Microsoft Word web) per DIR-008 / DIR-A004. Editor is in-process. |
| C-003 | Constraint | Citation rendering must NEVER leak data through AccessChecker bypass — `[restricted reference]` placeholder reveals only that something existed, never what. |

## Acceptance

- All FRs met.
- Save-conflict smoke: two users open same Doc, both edit + save; second-saver sees conflict UI; choose keep-theirs; first saver's edit persists, second's discarded; audit shows both attempts.
- Offline smoke: open Doc, go offline, edit body + add comment, come back online — edits sync with potential save-conflict; comment persists.
- Citation smoke: insert citation to a Drive file the current user can read; another user without access loads the Doc and sees `[restricted reference]` in place of the citation.
- axe-core CI gate green on Doc editor + revision-history drawer + comment sidebar + conflict-resolution UI.

## Risks

- **TipTap a11y baseline.** ProseMirror is a complex contenteditable surface; AODA AA compliance requires careful menu/toolbar a11y. Mitigation: TipTap provides a11y-ready building blocks; implementation plan calls out a per-component a11y test pass.
- **Save-conflict UX friction.** Users hate conflict UIs. Mitigation: messaging emphasises "your edits are safe in your local queue"; conflict path is the exception, not the rule (auto-save reduces concurrent-divergence likelihood).
- **Inline citation cycle.** Doc A cites Doc B cites Doc A. Mitigation: citation resolution is shallow (does not recursively expand cited bodies); cycle is harmless.
- **Revision-history table growth.** Heavy editing creates many revisions; long-term storage cost. Mitigation: framework two-axis revisions are the storage substrate (already production-hardened); revision pruning policy is framework-side (DIR-005).

## Out-of-band

- Three-way merge automation → v1.0 Anokii mission.
- Per-character CRDT collab (Y.js / Automerge) → v1.5 Anokii mission (CRDT library research mission first).
- Inline tables → declined (use Sheets); or v1.0 mission if research shows demand.
- Inline image upload → v0.5 mission.
- .docx / .pdf full-fidelity export → v0.5 mission.
- Doc templates → v1.0 mission.
