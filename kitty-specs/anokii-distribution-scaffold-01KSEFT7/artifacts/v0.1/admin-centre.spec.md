# Anokii v0.1 — Admin Centre (draft spec, mission seed)

**Draft status:** Seed for future Anokii-repo mission filing via `spec-kitty specify`. Pre-resolved per `anokii-distribution-scaffold-01KSEFT7/spec.md` §Pre-resolved decisions. Re-file in the Anokii repo as `admin-centre-<ULID>` once Wave-2 begins.

**Cross-references:**
- **Framework directives:** DIR-004 (OCAP — Admin Centre is admin-role-gated end-to-end), DIR-005 (two-axis storage — audit log queries respect revisions), DIR-006 (codified policy gates — Admin Centre surfaces gate status to operators), DIR-007 (Nuxt SPA), DIR-008 (GPL-2.0-or-later license surface).
- **Anokii directives:** DIR-A001 (AODA — admin grids + audit-log table), DIR-A002 (offline — admin is read-only-offline), DIR-A003 (translation pipeline glossary management lives here), DIR-A005 (OCAP).
- **Gap-matrix rows:** F (Operations / multi-tenant / intel surface — tenant management, classification policy editor, unified audit-query UI). Also touches A3 (audit logging — unified OCAP audit log) and A4 (classification + retention policy engine).

## Why

Admin Centre is the operator-facing surface that makes Anokii administrable: create + edit Nation tenants, manage the classification taxonomy, query the OCAP audit log across all surfaces, manage Anokii-specific brand theme overlays, and curate the translation-pipeline glossary. It is the place where DIR-006 (codified policy gates as trust substrate) becomes operator-visible — Nation IT staff and procurement reviewers see the gate status, audit completeness, and tenant configuration in one surface. This is the highest-trust UI in Anokii; every interaction is admin-role-gated; every change writes an audit row.

## Scope

### In scope

- **Tenant management UI.** List / create / edit Nation tenants. Each tenant is represented by a `config/tenants/<nation>.yaml` file (scaffold-time) plus a runtime `tenant` entity (registered by Anokii at runtime). Fields: `nation_name`, `nation_short`, `language`, `dialect`, `oiatc_member`, `classification_taxonomy`, `storage_bucket`, `theme`.
- **Classification-policy editor.** Consumes framework `config` admin surface; edits the active classification taxonomy (the levels defined in `config/classification.anokii-default.yaml` shipped by scaffold WP03). Changes audited per DIR-A005; classification renames require explicit confirmation (renames affect every record's `classification_label`).
- **Unified audit-query UI.** Reads framework OCAP audit log (gap-matrix A3). Filterable by: actor, event_type, classification_label, time range, surface (Drive, Forms, Tasks, Data Rooms, Docs, Sheets, Co-Intelligence, Admin Centre, Tenant), `offline_at` (yes/no — surfaces operations originated offline). Exportable as CSV with its own audit row.
- **Translation-pipeline glossary management.** Curate the per-Nation Ojibwe glossary (20–30 terms at pilot scale per DIR-A003). Admin UI for: term lookup, English ↔ Anishinaabemowin pairing, dialect tagging (southern / northern), language-keeper attribution, glossary-review workflow status.
- **Branded theme overlay management.** Surface for switching the active Anokii admin theme tokens (deep-teal default from scaffold; per-Nation overrides if a Nation customises their brand). Limited to token-level override at v0.1; full theme authoring is a v0.5+ mission.
- **Policy-gate status surface.** Reads gate status from the framework (`bin/check-*` family per DIR-006) and surfaces a green/yellow/red status panel to operators. Procurement-legible "Anokii system invariants" panel showing which gates passed in the latest deploy.
- **Admin alerts.** AI cost runaway (per Co-Intelligence v0.1 soft-cap from `co-intelligence-workspaces.spec.md`), audit-log anomalies (e.g., spike in access-denied events from a single actor), revoked-share-link followups.

### Out of scope

- **Multi-tenant isolation enforcement at the infrastructure layer.** Anokii at v0.1 ships per-Nation tenant configuration; physical isolation (separate database per Nation, separate storage bucket per Nation) is configured at deploy time via the deployer overlay (`deploy.php` shipped by scaffold WP03), not via runtime admin UI.
- **Tenant deletion** at v0.1 — too destructive without a clear retention story (deferred to v0.5 with explicit retention-policy work).
- **Cross-Nation federation administration.** Federation is gap-matrix A6 framework work; Anokii Admin Centre surfaces federation status when the framework substrate lands.
- **Billing / subscription management.** Not relevant at scaffold; if Anokii later adopts hosted-tier billing, that is a separate mission.
- **Backup / restore UI.** Backup is a deployer concern at v0.1 (scaffolded via the framework reference recipe); restore is a CLI operation.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | Admin Centre is admin-role-gated end-to-end via framework AccessChecker per DIR-004; non-admin access returns 403 at route level. |
| FR-002 | Mandatory | Tenant management UI lists / creates / edits Nation tenants; each create produces a `config/tenants/<nation>.yaml` file edit (commit via framework `config:export`) + a runtime tenant entity. |
| FR-003 | Mandatory | Classification-policy editor consumes framework `config` admin; edits audited per DIR-A005; renames require explicit confirmation with impact-count preview. |
| FR-004 | Mandatory | Unified audit-query UI reads framework OCAP audit log; filterable by actor / event_type / classification_label / time range / surface / `offline_at`; CSV export writes its own audit row. |
| FR-005 | Mandatory | Translation-pipeline glossary management surface curates per-Nation Ojibwe glossary per DIR-A003 (term lookup, EN ↔ Ojibwe pairing, dialect tagging, language-keeper attribution, glossary-review workflow status). |
| FR-006 | Mandatory | Branded theme overlay management surfaces token-level override per Nation; default tokens shipped by scaffold (deep-teal). |
| FR-007 | Mandatory | Policy-gate status surface reads `bin/check-*` family per DIR-006 and renders green/yellow/red status per gate; procurement-legible "Anokii system invariants" panel. |
| FR-008 | Mandatory | Admin alerts: AI cost runaway, audit-log anomalies, revoked-share-link followups — surface in a dedicated alerts panel. |
| FR-009 | Mandatory | AODA Level AA per DIR-A001 — data-grid table semantics (`<table>`, `<th scope="col">`), audit-log row keyboard navigation, alert panel `role="alert" aria-live="polite"`, confirmation modals with focus trap. |
| FR-010 | Mandatory | Offline behaviour per DIR-A002: read-only-offline; mutations require connectivity; explanatory message when offline. |
| NFR-001 | Mandatory | Audit-query UI must remain responsive at ≥ 100k audit rows — server-side pagination + indexed filtering; no full-table client-side scan. |
| NFR-002 | Mandatory | Classification rename impact-count preview must be accurate (count of records carrying the renamed label) before user confirms. |
| NFR-003 | Mandatory | Policy-gate status surface reads gate output from the deployer pipeline / CI artifact, never re-runs gates from the running app (read-only display). |
| NFR-004 | Mandatory | axe-core CI gate passes for Admin Centre surfaces — data grids and audit-log table are the highest a11y risk. |
| C-001 | Constraint | Admin Centre is admin-role-gated at the route layer (DIR-004); controllers do NOT re-check the role (single source of truth per framework pattern). |
| C-002 | Constraint | Tenant deletion is OUT at v0.1 (destructive; needs retention design). |
| C-003 | Constraint | NO direct database mutation tools (raw SQL admin) at v0.1 — all changes flow through framework entity API per DIR-004 / DIR-A005. |
| C-004 | Constraint | NO third-party admin/observability vendor integration per DIR-008 / DIR-A004 license posture. |

## Acceptance

- All FRs met.
- Admin-role smoke: non-admin user attempts to access Admin Centre route; 403 returned. Admin accesses; full surface visible.
- Audit-query smoke: query for `event_type=access_denied AND actor=<id>` across 7-day window; results paginated server-side; CSV export produces correct file + audit row.
- Classification rename smoke: rename `community` → `community-internal`; impact-count preview shows N records affected; confirm; rename propagates with audit row per record's `classification_label` change.
- Glossary smoke: add a term `commitment ↔ giizhitoon` with dialect=southern + language-keeper attribution; appears in glossary review workflow.
- Gate-status smoke: surface displays current `bin/check-composer-policy`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, axe-core gate states.
- Offline smoke: enter offline; Admin Centre renders read-only; mutation attempts surface explanatory message.

## Risks

- **Classification rename cascade.** Renaming a label touches every record carrying it — large impact, irreversible without restore-from-revision. Mitigation: impact-count preview before confirm; rename writes a revision per affected record per DIR-005 so restore is possible.
- **Audit-log volume at scale.** OCAP audit log grows fast — every read/write/share is a row. Mitigation: framework provides audit-log retention policy (gap-matrix A4 + A3); Anokii surfaces retention configuration via tenant config; UI paginates.
- **Translation-pipeline glossary governance.** Who decides the canonical translation? Mitigation: glossary-review workflow status (FR-005) routes through language-keeper attribution; admin UI does not auto-approve.
- **Gate-status surface staleness.** If gate status comes from CI artifacts, the surface reflects the last deploy, not the current code. Mitigation: surface labels the gate-status time-of-evaluation explicitly; admin sees "last evaluated: <timestamp>".

## Out-of-band

- Tenant deletion → v0.5 Anokii mission (with retention design).
- Cross-Nation federation admin → after framework gap-matrix A6 lands.
- Backup / restore UI → v1.0 Anokii mission.
- Theme authoring (beyond token override) → v0.5 Anokii mission.
- Billing / subscription management → out-of-scope until business model clarifies; separate Anokii mission if/when.
- Multi-tenant infrastructure-level isolation UI → deploy-time concern; not an Admin Centre v0.1 feature.
