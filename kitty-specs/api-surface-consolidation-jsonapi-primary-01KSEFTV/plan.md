# Implementation Plan: API Surface Consolidation — JSON:API primary, GraphQL demoted

**Mission:** `api-surface-consolidation-jsonapi-primary-01KSEFTV` — see `spec.md`.
**Pattern reference:** Twin to `inertia-demotion-nuxt-standardisation-01KSEFTS`. Same shape: source-of-truth README/spec banner first, manifest demotion second (with composer.lock refresh), docs+audit sweep third. M5A `ai-observability-dashboard-01KSE9BX` for spec/plan/tasks structure.
**Three WPs:** WP01 (JSON:API spec primary declaration + parity-matrix scaffold) is sequential — WP02's banner and WP03's audit both reference it. WP02 (GraphQL README banner + manifest demotion) and WP03 (audit + matrix population + CHANGELOG) parallel after WP01.

## §1 — Canonical GraphQL README banner text (WP02, exact-match, C-003)

The following block goes at the top of `packages/graphql/README.md`, immediately after the `# waaseyaa/graphql` H1 and a single blank line, before any existing subhead. Markdown blockquote:

```
> **Alternative protocol — not the primary API surface.**
>
> Per the framework's API-surface consolidation (mission `api-surface-consolidation-jsonapi-primary-01KSEFTV`),
> the framework's primary API surface is **JSON:API** in `packages/api/`. `waaseyaa/graphql`
> remains supported as an **optional / experimental** L6 protocol adapter for distributions
> whose consumers need GraphQL. It is not bundled by `waaseyaa/full`; install it explicitly
> when your distribution chooses GraphQL.
```

Then, after the existing class summary paragraph, the `## Status` section:

```
## Status

- **Stability:** optional / experimental. The public API surface (`GraphQlServiceProvider`, the `/graphql` endpoint, the schema-loading mechanism, any documented resolvers / mutations) is frozen at its current shape. The framework cadence ships no new feature work for this package; community contributions are accepted under the same review bar.
- **Bundle membership:** suggested by `waaseyaa/full` (not required). To install: `composer require waaseyaa/graphql`.
- **Decision provenance:** API-surface consolidation by mission `api-surface-consolidation-jsonapi-primary-01KSEFTV`. JSON:API is declared the framework's primary API surface in `docs/specs/jsonapi.md`.
```

## §2 — Canonical `suggest` description text (WP02, exact-match, C-003)

`packages/full/composer.json` — the entry under the `suggest` key for `waaseyaa/graphql`:

```
"waaseyaa/graphql": "GraphQL endpoint + schema introspection (L6, optional). Install if your distribution prefers GraphQL over the framework's primary JSON:API surface."
```

Full WP02 manifest change in `packages/full/composer.json`:

1. Remove the line `"waaseyaa/graphql": "self.version",` (or the current constraint) from `require`.
2. Add the canonical entry above to the `suggest` block (alphabetical-by-name ordering; the Inertia mission's `waaseyaa/inertia` entry will already exist if that mission landed first — keep alphabetical order: `graphql` before `inertia`).
3. `composer update --lock waaseyaa/graphql waaseyaa/full` at the repo root.

`packages/graphql/composer.json` — read first. If the `description` field already signals optional/experimental status, leave alone. Otherwise update to: `"description": "GraphQL endpoint + schema introspection for Waaseyaa — optional/experimental L6 surface; see README for the primary JSON:API framing."`. **No `require` / `require-dev` / `autoload` / `extra` changes.**

## §3 — `docs/specs/jsonapi.md` Status + matrix sections (WP01)

Read `docs/specs/jsonapi.md` first to locate the current introduction and existing stamp comments.

Insert the `## Status (primary API surface)` section near the top — after any existing one-paragraph intro, before the first existing `## ` substantive section:

```
## Status (primary API surface)

JSON:API is the framework's **primary API surface** as of mission `api-surface-consolidation-jsonapi-primary-01KSEFTV` (<edit-date>). Every new admin endpoint, mutation, and read model defaults to JSON:API. Distributions consuming Waaseyaa should expect JSON:API to be the long-term-supported surface.

**Canonical implementation:** `packages/api/` (L4). Controllers in `packages/api/src/Controller/`; routers in `packages/api/src/Http/Router/`; service-provider wiring in `packages/api/src/ApiServiceProvider::httpDomainRouters()`. Route registration via string-FQCN in `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php`.

**Canonical consumer:** `packages/admin/app/composables/` (L6 Nuxt SPA). Recent extension examples: queue admin (M4B), notification channels (M4C), workflow guards (M4A-5), AI observability (M5A).

**Alternative surface:** `packages/graphql/` (L6) is the alternative protocol adapter, retained as **optional / experimental**. It is not bundled by `waaseyaa/full`. Distributions that need GraphQL install it explicitly. See `packages/graphql/README.md` for the alternative-protocol framing.
```

Add the parity matrix near the bottom of the spec (before any "Open questions" / "Out-of-scope" / footer-stamp area):

```
## Feature parity matrix vs current GraphQL exposure

The following matrix enumerates every entity, query, and mutation exposed by `packages/graphql/` and the equivalent JSON:API surface in `packages/api/`. Populated by mission `api-surface-consolidation-jsonapi-primary-01KSEFTV` WP03.

| Entity / Operation | JSON:API surface | GraphQL surface | Gap (if any) | Follow-up mission |
|---|---|---|---|---|
| <populated by WP03> | | | | |
```

Then stamp:

```
<!-- Spec reviewed YYYY-MM-DD - api-surface-consolidation-jsonapi-primary-01KSEFTV - WP01 - JSON:API primary declaration + parity matrix -->
```

`YYYY-MM-DD` is the edit date via `date -u +"%Y-%m-%d"`.

## §4 — Coverage audit method (WP03)

The audit must be reproducible. The implementer:

1. **Enumerate GraphQL exposure.** From `packages/graphql/`:
   - Read every `*Schema*.php`, `*Resolver*.php`, `*Type*.php`, `*Mutation*.php`, `*Query*.php`, `*Field*.php` in `src/` (or wherever the schema lives — read first).
   - List every type definition, every top-level query, every top-level mutation. Capture: name, what entity it operates on, what fields it returns, what arguments it accepts.
   - If GraphQL is fully schema-as-code, the enumeration is grepable. If it loads schemas from `.graphql` files, read those.
2. **Enumerate JSON:API exposure.** From `packages/api/`:
   - Read every `*Controller.php` in `packages/api/src/Controller/`.
   - List every controller method that handles a route (cross-reference `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` to confirm the route exists).
   - Capture: what entity each controller operates on, what fields it returns (via the resource serializer), what mutations it supports (create/update/delete).
3. **Cross-reference per entity/operation.** Build the matrix row-by-row. For each row:
   - "parity": both surfaces expose the entity / operation with equivalent semantics. No gap.
   - "JSON:API only": JSON:API exposes it; GraphQL does not. No gap (and no follow-up — JSON:API is primary).
   - "GraphQL only": GraphQL exposes it; JSON:API does not. **GAP** — file a follow-up mission.
4. **For each GAP row, file a follow-up mission scaffold.** Use `spec-kitty specify api-jsonapi-gap-<entity-or-operation>-<short-ULID>`. The mission's spec.md is a stub describing the gap; that mission's WPs are scoped separately and live outside this mission. Record the new mission slug in this matrix's "Follow-up mission" column.

The audit's completeness is the C-005 requirement. Reviewer cross-checks by running `rg -n 'type|query|mutation' packages/graphql/src/` (or the schema files) and verifying every match appears in the matrix.

## §5 — CHANGELOG entry (WP03)

`CHANGELOG.md` under `[Unreleased]` → `### Changed`:

```
- Declared JSON:API the framework's primary API surface; demoted `waaseyaa/graphql` from `waaseyaa/full` `require` to `suggest`. GraphQL remains supported; it is no longer in the recommended bundle. Distributions that want GraphQL: `composer require waaseyaa/graphql`. See `docs/specs/jsonapi.md` for the JSON:API ↔ GraphQL parity matrix and any follow-up missions for JSON:API gap-fills.
```

## Verification gate (each WP, in the mission worktree)

WP01:
1. `git diff docs/specs/jsonapi.md` — Status section + matrix scaffold + stamp; no other lines changed.
2. `composer cs-check && composer phpstan && bin/check-*` — green.

WP02:
1. `composer update --lock waaseyaa/graphql waaseyaa/full` succeeds.
2. `bin/check-composer-policy` — green.
3. `bin/check-package-layers` — green (GraphQL stays at L6).
4. `git diff packages/graphql/composer.json` — at most a `description` change.
5. `git diff packages/full/composer.json` — one removal from `require`, one addition to `suggest`.

WP03:
1. The parity matrix in `docs/specs/jsonapi.md` is populated with every GraphQL-exposed type / query / mutation (C-005).
2. Every GAP row has a non-empty "Follow-up mission" cell (C-004) and the named mission scaffold exists at `kitty-specs/<slug>/spec.md`.
3. `git diff packages/graphql/src` and `git diff packages/graphql/tests` are empty (C-001).
4. `git diff CHANGELOG.md` — one Changed entry.
5. The Out-of-band section of `spec.md` is replaced (post-merge) with the real list of follow-up missions filed.

Mission close:
1. `vendor/bin/phpunit` — green (no code changes; should be unaffected).
2. `composer cs-check && composer phpstan` — green.
3. All `bin/check-*` gates — green.
4. `cd packages/admin && npm test && npm run typecheck && npm run lint` — green.

## Reviewer focus

- (a) **C-001 / NFR-001 — no code deletion.** `git diff --stat packages/graphql/src packages/graphql/tests` MUST be empty.
- (b) **C-003 — exact-match wording.** Diff the WP02 banner against §1, the WP02 suggest description against §2, character-for-character.
- (c) **FR-006 — `full` no longer requires GraphQL.** Confirm post-merge.
- (d) **FR-007 — lock regenerated.** Same as the Inertia mission.
- (e) **C-005 — audit completeness.** Cross-check the parity matrix against a fresh `rg` for GraphQL schema definitions. Every type / query / mutation in the schema MUST appear in the matrix.
- (f) **C-004 — gap-fills are separate missions.** Every GAP row's "Follow-up mission" column has a populated slug; the corresponding `kitty-specs/<slug>/` exists. No gap is "fixed inline" in this mission.
- (g) **WP01 must land before WP02 and WP03.** Check commit / branch ordering.
- (h) **Out-of-band section is real, not placeholder.** Post-merge, the spec.md "Out-of-band" section MUST list the real follow-up mission slugs filed by WP03, not the example placeholder.
