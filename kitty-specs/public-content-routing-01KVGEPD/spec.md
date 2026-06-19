# Feature Specification: Public Content Routing — Canonical Identifier for `/{type}/{id}`

**Mission:** `public-content-routing-01KVGEPD` · **Type:** software-dev · **Target:** main · **Created:** 2026-06-19

## Summary

The demo could not show the public published-Story page: `GET /story/{uuid}` returned 404, so the alpha.221 "agent-readable trio" (HTML / Markdown / MCP for one published item) could not be demonstrated.

Root cause: the canonical public content path is `/{entityType}/{id}` where `{id}` is the **numeric primary key**, not the UUID. `/story/<uuid>` falls to the SSR `public.page` catch-all, `resolveCanonicalContentPath()` accepts any second segment and returns `['story','<uuid>']`, then `SqlEntityStorage::load()` queries the INTEGER `id` column with the uuid string → 0 rows → `renderNotFound()` → 404. There is **no** UUID-based public lookup anywhere. So the published Story's real URLs are `/story/1` and `/story/3`; `/story/{uuid}` can never resolve under the current loader.

A **coupled** concern surfaced during triage: even `/story/{id}` may be denied because a freshly-registered content type with **no view-granting AccessPolicy** fails closed in `shouldDenyContentGroupRender()` (open-by-default for collections, fail-closed for single render). my-app's Story has no AccessPolicy. This mission resolves the public-addressability contract (id vs uuid) **and** clarifies/loosens-or-documents the policy-less published-content render path so a published item is demonstrably reachable.

**Constraint:** this touches a routing + access boundary — the published-vs-unpublished and authenticated-vs-anonymous boundaries MUST hold.

## Resolved Decisions (locked — no longer open)

- **Canonical identifier stays numeric `/{type}/{id}`.** The advertised public URL remains the numeric id.
- **ADD uuid resolution in `SsrPageHandler`.** When the id segment is non-numeric and the type has a `uuid` key, resolve via a uuid lookup before `renderNotFound()`, so a valid entity **never 404s purely on identifier shape** (`/story/{uuid}` resolves to the same entity as `/story/{id}`). The lookup honours the getquery-bindings discipline (`setAccount()` / explicit `accessCheck(false)` + baseline comment). `load()` MUST NOT be dual-keyed on one column — disambiguate by segment shape.
- **Default view policy: published content is anonymous-viewable unless a stricter `AccessPolicy` exists; drafts stay gated.** This resolves the policy-less fail-closed coupling so a published item is reachable without each app hand-authoring a view policy. Links #1605 (200-empty/403 until a policy exists) and #1649 (forbidden-not-404 existence oracle).

**This mission is HELD at P1** — these decisions are locked, but implementation awaits an explicit go (it is not in the P0 trio).

## Actors

- **Anonymous reader / AI agent** — fetches a published content item over HTTP (HTML, `Accept: text/markdown`) and via MCP `entity.read`.
- **Author / operator** — publishes a content item and shares its canonical public URL.
- **Routing + access owners** — must sign off on the identifier contract and the policy-less render decision.

## Evidence (the failures to eliminate)

1. Canonical path is id-keyed: [packages/ssr/src/SsrPageHandler.php:720-738](packages/ssr/src/SsrPageHandler.php) `resolveCanonicalContentPath()` maps `/{entityTypeId}/{id}` for `content`-group types and accepts any single id segment (numeric or not).
2. Loader is id-only: [packages/ssr/src/SsrPageHandler.php:169-176](packages/ssr/src/SsrPageHandler.php) calls `$targetStorage->load($entityId)`; [packages/entity-storage/src/SqlEntityStorage.php:107-134](packages/entity-storage/src/SqlEntityStorage.php) runs `WHERE <idKey> = :id` with `idKey = keys['id'] ?? 'id'` (the numeric column). There is **no** `loadByUuid` / uuid fallback in this path.
3. No uuid route exists: [packages/foundation/src/Kernel/BuiltinRouteRegistrar.php:385-411](packages/foundation/src/Kernel/BuiltinRouteRegistrar.php) registers only `public.home` `/` and `public.page` `/{path}`. `EntityDeepLinkRouteBuilder` builds `/{segment}/{type}/{id}` deep links keyed on id and has **zero** production callers.
4. my-app state: `story` table has `id` INTEGER pk, `uuid` CLOB; rows id=1 (published), id=2 (draft), id=3 (published); `path_alias` table empty. The spec `kitty-specs/author-path-remediation-01KVBP4S/spec.md` FR-006 itself defines the contract as `/{entityType}/{id}` — implying numeric-id, so the demo's `/story/{uuid}` was likely operator error.
5. Coupled access concern: `shouldDenyContentGroupRender()` ([SsrPageHandler.php:754-762](packages/ssr/src/SsrPageHandler.php)) fails closed for a non-`node` content type with no AccessPolicy and `accessHandler === null`. my-app's `src/Access/` is empty (`.gitkeep`), and Story declares `status` but no policy — overlaps open issues **#1605** (new content type 200-empty/403 until a view-granting policy exists) and **#1649** (show responds forbidden-not-404). This means even `/story/1` may not render for anonymous until a policy exists — to be verified.

## User Scenarios & Testing

1. **Read a published item by its canonical URL.** An anonymous reader GETs the canonical public URL of a published Story and receives 200 HTML; with `Accept: text/markdown` (or `?format=md`) receives 200 markdown; via MCP `entity.read` receives the same content (the trio).
2. **Drafts stay private.** GET of an unpublished item's canonical URL returns 404/403 to anonymous (boundary preserved).
3. **Operator gets the right URL.** The surfaces that advertise a content item's public URL (sitemap, `llms.txt`, schema.org `canonicalUrl`) emit the SAME identifier shape the loader resolves — so an operator/agent never constructs a URL the loader cannot serve.

### Edge cases

- A non-numeric segment that is a valid UUID resolves to the entity **iff** the chosen contract supports uuid addressing; otherwise it 404s and the surfaces never advertise a uuid URL.
- The numeric-id and uuid identifier spaces MUST NOT be conflated on the same column (no silently dual-keyed `load()`).
- A policy-less published content type's render behaviour must be deterministic and documented (render vs fail-closed), consistent with #1605/#1649.

## Requirements

### Functional (FR)

| ID | Requirement | Status |
|----|-------------|--------|
| FR-001 | The canonical public identifier stays numeric `/{type}/{id}` (advertised), AND `SsrPageHandler` MUST resolve a non-numeric segment via a uuid lookup before `renderNotFound()`, so a valid entity never 404s purely on identifier shape. `load()` MUST NOT be dual-keyed on one column. | **Accepted** |
| FR-002 | The advertised URLs (sitemap.xml, llms.txt, schema.org `canonicalUrl` via `SeoPublicController`/`EntitySchemaOrgMapper`) MUST emit the numeric `/{type}/{id}` identifier; uuid is an accepted alias, not the advertised form. | **Accepted** |
| FR-003 | A published content item MUST be anonymously reachable on its canonical URL across the trio (HTML, markdown, MCP) under the **default view policy: published content is anonymous-viewable unless a stricter `AccessPolicy` exists**, replacing the policy-less fail-closed behaviour in [shouldDenyContentGroupRender](packages/ssr/src/SsrPageHandler.php). | **Accepted** |
| FR-004 | An unpublished/draft content item MUST NOT be anonymously reachable on any surface (published-gating preserved), regardless of the default view policy. | **Accepted** |

### Non-Functional / Security (NFR)

| ID | Requirement | Status |
|----|-------------|--------|
| NFR-001 | If uuid resolution is added, the lookup MUST honour the getquery-bindings discipline (`setAccount()` or explicit `accessCheck(false)` with a baseline comment) to avoid a `bin/check-getquery-bindings` CI failure. | Proposed |
| NFR-002 | 404-vs-403 semantics for denied/missing content MUST be intentional and consistent with the existence-oracle concern in #1649 (avoid leaking existence on the public surface). | Proposed |

### Constraints (C)

| ID | Constraint | Status |
|----|------------|--------|
| C-001 | MUST NOT make `load()` dual-key (numeric id vs uuid) on the same column — disambiguate by segment shape and route to the correct key. | Accepted |
| C-002 | This mission MUST hold the published/unpublished and anonymous/authenticated boundaries; any change is access-reviewed. | Accepted |
| C-003 | No BC shims (no deployed downstream apps). | Accepted |

## Success Criteria

- SC-001: `GET /story/1` and `/story/3` (published) return 200 HTML for anonymous in the documented minimal setup; `/story/2` (draft) returns 404/403; `/story/1` with `Accept: text/markdown` returns 200 markdown.
- SC-002: If uuid addressing is adopted, `GET /story/<uuid-of-id-1>` returns the same 200 as `/story/1`; if not adopted, the surfaces never advertise a uuid URL and the contract is documented.
- SC-003: A handler-level/SSR test (extending `packages/ssr/tests/Unit/CanonicalContentPathTest.php`) asserts a seeded published item is reachable on the canonical contract and an unpublished one is not.
- SC-004: `bin/check-getquery-bindings` and `composer verify` stay green.

## Key Entities

- `SsrPageHandler` (`resolveCanonicalContentPath`, `shouldDenyContentGroupRender`, render path), `SqlEntityStorage` (`load`, possible `loadByUuid`), `BuiltinRouteRegistrar` (public routes), `SeoPublicController` / `EntitySchemaOrgMapper` (advertised URLs), `EntityAccessHandler` + content `AccessPolicy`.
- my-app `App\Entity\Story`, `StoryServiceProvider` (reference for the demo).

## Assumptions

- A-001: The alpha.221 "trio" intends a single canonical public URL per published item; the demo's `/story/{uuid}` was not a surfaced URL (sitemap/llms.txt/schema.org all emit `/{type}/{id}` numeric) and was likely hand-constructed. To confirm where the uuid URL came from.
- A-002: my-app's Story has no AccessPolicy; whether `/story/1` renders anonymously today (vs fails closed) must be verified against a running server before fixing — it determines whether D5 is purely a usage/identifier issue or also an access-default fix.
- A-003: Numeric incrementing ids in public URLs may be undesirable (enumeration); uuid addressability is the nicer contract but is a product decision, not assumed.

## Scope

**In:** deciding and unifying the canonical public identifier (id vs uuid) across loader + advertised surfaces; the policy-less published-content render decision/documentation; tests for reachable-published / unreachable-draft on the chosen contract.

**Out:** route precedence between app routes and the SSR fallback (#1632/#1532); the JSON:API anonymous-discovery existence-oracle fix beyond keeping SSR consistent (#1649); write/mutation public surfaces; non-content entity public read.
