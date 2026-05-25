# API Surface Consolidation — JSON:API primary, GraphQL demoted to optional adapter

**Mission:** `api-surface-consolidation-jsonapi-primary-01KSEFTV`
**Status:** Spec
**Target branch:** `main`
**Tracks:** No GitHub issue at filing time. This mission is the constitutional ratification of the framework's primary API surface choice. Like the parallel Inertia-demotion mission, the downstream effect is the signal every distribution maintainer reads when they choose how to expose data.
**Pattern reference:** Twin to `inertia-demotion-nuxt-standardisation-01KSEFTS` — same shape (documentation + composer-manifest, no code deletion), same three-WP structure (README banner → manifest demotion → docs sweep), same pre-resolution (demote, don't remove). M5A `ai-observability-dashboard-01KSE9BX` provides the spec/plan/tasks shape; the charter-amendment mission provides the documentation-only mission body.

## Why this mission exists

The framework currently ships two parallel API surfaces:

- **JSON:API** (`packages/api/`, L4) — REST-shaped, JSON:API spec-compliant. Every admin SPA surface in `packages/admin/` already consumes JSON:API (queue, notifications, workflow guards, AI observability, broadcasting). The route registration pattern in `BuiltinRouteRegistrar` + `ApiServiceProvider::httpDomainRouters()` is the framework's canonical L4 wiring shape. Every recent mission (M4A-5, M4B, M4C, M5A) extends JSON:API.
- **GraphQL** (`packages/graphql/`, L6) — Schema-based, with `GraphQlServiceProvider` and a `/graphql` endpoint. The package is real and works but is **not** the surface any recent admin work or any documented framework gate references. The orchestration table in CLAUDE.md lists `packages/graphql/*` with no specialist skill, just a README pointer.

Two parallel API surfaces is a tax: every new entity or mutation needs to be exposed twice or the framework asymmetrically favours one surface (which it has, de facto — JSON:API everywhere, GraphQL nowhere new). The alpha-to-beta-plan calls this out as a Wave-2 substrate-hardening item: **commit to one primary API surface; the other becomes optional**.

This mission ratifies the de-facto choice:

1. **JSON:API is the framework's primary API surface.** `docs/specs/jsonapi.md` carries the constitutional commitment. Every new admin endpoint, every new mutation, every new read model defaults to JSON:API. Distributions consuming the framework should expect JSON:API to be the long-term-supported surface.
2. **GraphQL is demoted to optional/experimental, NOT removed.** Same treatment as `packages/inertia` per DIR-007's "don't break what works" logic and the decision-preference order's "minimise vendor lock-in" clause. Distributions that depend on GraphQL (or that have built consumer apps against the `/graphql` endpoint) continue to work; new framework cadence focuses elsewhere.
3. **A JSON:API ↔ GraphQL coverage matrix surfaces gaps.** The audit in WP03 identifies entities or operations GraphQL currently exposes that JSON:API doesn't. Each such gap becomes a small follow-up mission (out-of-band), not in-scope here.

**Pre-resolved (not deferred): GraphQL is demoted, not removed.** The Hard Rules direct us to pre-resolve the GraphQL fate based on assumptions.md and alpha-to-beta-plan. Removal is more disruptive (breaks consumers, loses test coverage, deletes working code) than the benefit of "fewer packages." Demotion preserves the option for distributions and matches the parallel Inertia treatment exactly.

## Scope

### In scope

**WP01 — JSON:API as primary, declared in spec:**
- `docs/specs/jsonapi.md` — add a top-of-document `## Status (primary API surface)` section declaring:
  - JSON:API is the framework's primary API surface as of this mission slug + date.
  - Every new admin endpoint, mutation, and read model defaults to JSON:API.
  - Cross-references: `packages/api/` (canonical implementation), `packages/admin/composables/` (canonical consumer), `BuiltinRouteRegistrar` + `ApiServiceProvider::httpDomainRouters()` (route registration shape), the M4B/M4A-5/M5A missions (recent JSON:API extension examples).
  - GraphQL is the alternative protocol adapter, retained as optional/experimental — see `packages/graphql/README.md`.
- Add a `## Feature parity matrix vs current GraphQL exposure` table near the bottom of the spec, populated by the WP03 audit. The matrix has columns: `Entity / Operation`, `JSON:API surface`, `GraphQL surface`, `Gap (if any)`, `Follow-up mission`.
- Stamp the file with `<!-- Spec reviewed YYYY-MM-DD - api-surface-consolidation-jsonapi-primary-01KSEFTV - WP01 - JSON:API primary declaration + parity matrix -->`.

**WP02 — GraphQL README banner + `waaseyaa/full` manifest demotion:**
- `packages/graphql/README.md` — insert at the top (after the H1) a prominent banner mirroring the Inertia banner shape:
  ```
  > **Alternative protocol — not the primary API surface.**
  >
  > Per the framework's API-surface consolidation (mission `api-surface-consolidation-jsonapi-primary-01KSEFTV`),
  > the framework's primary API surface is **JSON:API** in `packages/api/`. `waaseyaa/graphql`
  > remains supported as an **optional / experimental** L6 protocol adapter for distributions
  > whose consumers need GraphQL. It is not bundled by `waaseyaa/full`; install it explicitly
  > when your distribution chooses GraphQL.
  ```
- Add a `## Status` section after the existing class summary, mirroring the Inertia mission's status block (stability / bundle membership / decision provenance).
- `packages/full/composer.json` — remove `"waaseyaa/graphql"` from `require` (if present); add it to `suggest` with the description: `"GraphQL endpoint + schema introspection (L6, optional). Install if your distribution prefers GraphQL over the framework's primary JSON:API surface."`.
- `packages/graphql/composer.json` — `description` clarification only if needed (read first; conditional edit, same logic as the Inertia mission).
- Run `composer update --lock waaseyaa/graphql waaseyaa/full` to refresh `composer.lock`.

**WP03 — Coverage audit + parity matrix population + CHANGELOG:**
- Audit `packages/graphql/src/` for every type, query, and mutation it currently exposes. Cross-reference against `packages/api/src/Controller/` to identify what JSON:API exposes for the same data. For each entity/operation:
  - Both surfaces expose → record in the parity matrix as "parity" (no follow-up needed).
  - JSON:API exposes, GraphQL does not → record as "JSON:API only" (no gap).
  - GraphQL exposes, JSON:API does not → record as "GAP — JSON:API missing X". File a small follow-up mission (`api-jsonapi-gap-<entity-or-operation>-<short-ULID>`) for each gap; the mission slug goes in the matrix's "Follow-up mission" column.
- Populate the parity matrix in `docs/specs/jsonapi.md` (the table added by WP01).
- `CHANGELOG.md` `[Unreleased]` → **Changed**:
  - `Declared JSON:API the framework's primary API surface; demoted waaseyaa/graphql from waaseyaa/full require to suggest. GraphQL remains supported; it is no longer in the recommended bundle.`
- The list of out-of-band follow-up missions goes in spec.md "Out-of-band" below at finalisation time (each follow-up takes one line: slug + one-sentence summary).

### Out of scope

- Removing any code from `packages/graphql/`. The package stays fully functional.
- Deprecating `GraphQlServiceProvider`, removing the `/graphql` route, or breaking any existing GraphQL consumer.
- Editing `packages/graphql/`'s tests, fixtures, or composer.json `require` / `require-dev` blocks.
- Implementing the JSON:API gap-fills identified by WP03. Each gap is its own follow-up mission with its own implement-review loop.
- Reframing the parallel Inertia demotion (handled by `inertia-demotion-nuxt-standardisation-01KSEFTS`).
- Any change to `.kittify/charter/charter.md`. The JSON:API-primary commitment is a framework-architecture decision, not a constitutional directive — DIR-007 covers SPA; there is no DIR for API surface. If a charter directive is desired (DIR-009?), that is a separate charter-amendment mission.

## Requirements

| ID | Type | Requirement |
|---|---|---|
| FR-001 | functional | `docs/specs/jsonapi.md` carries a `## Status (primary API surface)` section at the top declaring JSON:API as the framework's primary API surface, with the cross-references enumerated in spec.md "Scope → WP01". |
| FR-002 | functional | `docs/specs/jsonapi.md` carries a `## Feature parity matrix vs current GraphQL exposure` table with columns `Entity / Operation | JSON:API surface | GraphQL surface | Gap (if any) | Follow-up mission`, populated by the WP03 audit. |
| FR-003 | functional | `docs/specs/jsonapi.md` is stamped with `<!-- Spec reviewed YYYY-MM-DD - api-surface-consolidation-jsonapi-primary-01KSEFTV - WP01 - JSON:API primary declaration + parity matrix -->`. |
| FR-004 | functional | `packages/graphql/README.md` carries the verbatim banner from plan.md §1 at the top (Markdown blockquote, references this mission slug + `packages/api/`). |
| FR-005 | functional | `packages/graphql/README.md` carries a `## Status` section documenting stability (optional/experimental), bundle membership (suggested by `waaseyaa/full`, not required), and decision provenance (this mission slug). |
| FR-006 | functional | `packages/full/composer.json` does NOT list `waaseyaa/graphql` in `require`. It DOES list `waaseyaa/graphql` in `suggest` with the verbatim description text from plan.md §2. |
| FR-007 | functional | `composer.lock` is regenerated after the manifest change. `composer install` on a fresh checkout no longer pulls `waaseyaa/graphql` via the `full` metapackage. |
| FR-008 | functional | The WP03 audit produces a complete enumeration of every GraphQL-exposed type / query / mutation. For each row, the audit records whether JSON:API exposes the equivalent. Every GAP row has a follow-up mission slug (filed as a small mission scaffold) listed in the matrix. |
| FR-009 | functional | `CHANGELOG.md` `[Unreleased]` → **Changed** records the consolidation using the verbatim text from spec.md "Scope → WP03 → CHANGELOG". |
| NFR-001 | non-functional | The mission is purely additive in `packages/graphql/` source: no code, no tests, no service provider, no resolver is added, removed, or modified. Only `README.md` (+ optionally `composer.json` description-only). |
| NFR-002 | non-functional | All codified-policy gates remain green: `composer cs-check`, `composer phpstan`, `bin/check-composer-policy`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`. |
| NFR-003 | non-functional | The mission's three WPs share a branch / PR; WP01 lands first (JSON:API primary declaration is what the WP02 banner and WP03 audit both reference). WP02 and WP03 MAY parallel after WP01. |
| C-001 | constraint | No code deletion. Implementer who is tempted to "tidy up" GraphQL source MUST NOT — that's a separate, charter-amendment-bearing decision. |
| C-002 | constraint | No changes to consumer apps. The framework remains backward-compatible: distributions that previously relied on the transitive pull through `full` get a single `composer require waaseyaa/graphql` to add it explicitly. The CHANGELOG entry is the migration note. |
| C-003 | constraint | The banner text + suggest description text are exact-match against plan.md §1 + §2 respectively. No paraphrasing — these are the canonical constitutional signals (same C-003 logic as the Inertia mission). |
| C-004 | constraint | The mission does NOT implement any of the gap-fills identified by WP03. Each gap is a separate follow-up mission. WP03 emits the follow-up mission slugs; the missions themselves are out-of-scope. |
| C-005 | constraint | The WP03 audit's parity matrix MUST be complete — every GraphQL type / query / mutation is in the matrix. A partial audit fails C-005 even if every row found is correct. |

## Acceptance

- All 9 FRs met.
- All codified gates green (NFR-002).
- `git diff --stat` for the mission's merge shows:
  - `docs/specs/jsonapi.md` (+lines for Status section + matrix table + stamp).
  - `packages/graphql/README.md` (+lines for banner + Status section).
  - `packages/full/composer.json` (1 line removed from `require`, lines added to `suggest`).
  - `composer.lock` (regenerated).
  - `CHANGELOG.md` (one Changed entry).
  - Plus one new spec.md per gap-fill follow-up mission (in `kitty-specs/api-jsonapi-gap-*/spec.md`, but those are separate missions' scaffolds, not part of this mission's owned files).
- `git diff packages/graphql/src` and `git diff packages/graphql/tests` are both empty (C-001).
- The parity matrix in `docs/specs/jsonapi.md` is complete (C-005); the audit list is reviewable.
- Every GAP row in the matrix has a non-empty "Follow-up mission" cell (C-004).

## Risks

- **Implementer assumes "demotion" means "remove code" (primary).** Same risk as the Inertia mission. C-001 + the WP02 owned-files allowlist (which does NOT include `packages/graphql/src/**`) are the explicit guard.
- **The audit is partial.** Missing a GraphQL-exposed entity from the matrix means missing a gap-fill follow-up, which means a consumer who used the GraphQL-only operation has no migration path. C-005 makes the audit completeness mandatory; reviewer cross-checks by greping `packages/graphql/src/Schema/` (or wherever the schema lives) for type / query / mutation definitions and verifying every one appears in the matrix.
- **GraphQL package state may already have been demoted at the README level.** Read first — if so, FR-004 / FR-005 become "verify, do not duplicate" (like FR-006 in the Inertia mission). The acceptance gate becomes lighter but the audit (WP03) is still required.
- **GAP rows without follow-up mission slugs.** A complete audit must have a slug for every gap. If a slug is missing, the gap is invisible to the wave plan. Reviewer verifies every GAP row's "Follow-up mission" cell is populated.
- **A consumer app silently depends on GraphQL transitively through `waaseyaa/full`.** The CHANGELOG entry is the migration note; document explicitly that the fix is `composer require waaseyaa/graphql`.
- **Composer policy gate rejects the change.** Same as the Inertia mission — `suggest` is policy-clean, but reviewer runs the gate before merge.

## Decisions pre-resolved

- **GraphQL is demoted, not removed.** Per the Hard Rules. Removal would break working code and the framework's small but real GraphQL surface. Demotion preserves optionality (decision-preference order: minimise vendor lock-in) and matches the Inertia treatment exactly.
- **JSON:API is the primary, not "co-equal."** "Co-equal" was rejected — it's the current state and the source of the tax. A clear primary signals to distributions what to commit to.
- **The parity matrix is in `docs/specs/jsonapi.md`, not a separate file.** Co-locating the matrix with the JSON:API spec means anyone reading "what does the framework's API surface support?" sees the matrix immediately. A separate file would invite the matrix to drift from the spec.
- **Gap-fills are separate follow-up missions, not this mission's WPs.** This mission is documentation + manifest only; mixing in code work would make it both larger and slower to land, defeating the purpose of pre-resolving the constitutional question early.
- **No charter directive added for API surface.** DIR-007 covers SPA; introducing a DIR-009 for API surface is a separate constitutional decision that can be filed later if the wave plan calls for it.

## Decisions deferred to implementer

- The exact follow-up mission slug naming for each GAP row (suggested template: `api-jsonapi-gap-<entity-or-operation>-<short-ULID>`, but the implementer may pick a clearer slug per gap).
- Whether to add per-row context in the parity matrix (e.g., consumer name, blocking severity) beyond the four required columns. The implementer may extend the table; the four required columns must be present.
- Whether `packages/graphql/composer.json`'s existing `description` field already conveys optional status — same conditional-edit logic as the Inertia mission.

Decision preference order (per charter): preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Out-of-band

- Per-gap follow-up missions filed by WP03. The implementer adds each slug here at finalisation time (one slug per line, format: `- <slug> — <one-sentence summary of the gap>`).
- **Placeholder list (to be replaced by the WP03 implementer with the real audit output):**
  - `- api-jsonapi-gap-<example>-<ULID> — JSON:API exposure for <entity or operation> currently only available via GraphQL.`
- If the audit finds zero gaps, this section reads: `No GAP rows in the parity matrix; no follow-up missions filed.`
- A future DIR-009 charter directive committing the framework to JSON:API at the constitutional level — defer to a separate charter-amendment mission only if a distribution explicitly asks for the stronger commitment.
- If `composer.lock` regeneration surfaces unrelated transitive changes, file a separate lock-file-refresh mission.
