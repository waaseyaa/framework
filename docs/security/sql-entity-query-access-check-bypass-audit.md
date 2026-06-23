# SqlEntityQuery `accessCheck(false)` Bypass Audit

**Status:** Living document. Updated whenever a new `accessCheck(false)` call site lands.
**Last full audit:** 2026-05-18 (mission `sql-entity-query-access-checking-01KRYP15`, #1495).

## Why this document exists

After mission `sql-entity-query-access-checking-01KRYP15` landed, `SqlEntityQuery::accessCheck(true)` became the default and the no-op stub was replaced with a real per-row filter against `EntityAccessHandler::check('view', $account)`. The `accessCheck(false)` opt-out is preserved for system contexts that legitimately need to see every row — index warmers, validators, internal lookups, integrity checks — but every such call site must be documented here.

If you add a new `accessCheck(false)` call, you MUST:

1. Add a row to the table below with a one-line justification and today's date in "Last reviewed".
2. Add an inline comment at the call site referencing this document.

Prefer `->setAccount($account)` over `->accessCheck(false)` whenever the calling code has access to the request's authenticated account (typically via the `_account` request attribute set by `SessionMiddleware`, or via the GraphQL context). The bypass should remain a deliberate, audited choice — not a convenience default.

## Current call sites (post-mission state on `kitty/mission-sql-entity-query-access-checking-01KRYP15-lane-c`)

### Unconditional bypass — pure system context

These sites never have an end-user `AccountInterface` available and run with full-database visibility by design. They are the canonical "system context" call sites.

| File | Line | Justification | Last reviewed |
|---|---|---|---|
| `packages/oidc/src/ClientRegistry/OidcClientLookup.php` | 30 | OIDC client registry lookup runs pre-auth during the OAuth handshake; there is no authenticated session yet. The OIDC client is a global registry entry, not an access-controlled entity. | 2026-05-18 |
| `packages/oidc/src/ClientRegistry/OidcClientSeeder.php` | 125 | Boot-time seeding of OIDC clients from config; runs without any account context. | 2026-05-18 |
| `packages/relationship/src/RelationshipValidator.php` | 275 | Referential-integrity check spans access boundaries — a user cannot be allowed to violate FK constraints because they happen to lack `view` on the referenced entity. Validator runs inside the entity-save transaction. | 2026-05-18 |
| `packages/relationship/src/RelationshipDeleteGuardListener.php` | 40 | Pre-delete guard inspects inbound references regardless of caller's `view` access — the guard exists to prevent FK breakage, not to filter user data. | 2026-05-18 |
| `packages/relationship/src/RelationshipDeleteGuardListener.php` | 46 | Same guard, inverse side of the bidirectional check. | 2026-05-18 |
| `packages/ai-vector/src/SemanticIndexWarmer.php` | 283 | Background index-warming job; needs to see every entity to build the embedding store. Runs out of the request lifecycle (CLI / queue worker). | 2026-05-18 |
| `packages/workflows/src/DomainValidationListener.php` | 136 | Workflow validator runs inside the entity-save transaction; the system needs unrestricted read to evaluate state-machine guards. | 2026-05-18 |
| `packages/entity-storage/src/SqlEntityStorage.php` | 239 | `loadByKey()` is the system-context identity primitive used by storage internals (UUID/ID lookups during load). Bypassing here is intentional and called out by an inline comment referencing (C-004) in the mission contracts. | 2026-05-18 |
| `packages/cli/src/Handler/EntityListHandler.php` | 27 | The `waaseyaa entity:list` CLI handler runs without a request-scoped account and is an operator-facing tool; operator authority is established at the shell, not at the query layer. | 2026-05-18 |
| `packages/genealogy/src/Service/GenealogyFamilyService.php` | 33 | Genealogy graph computation traverses ancestral relations across user boundaries by design (a user inspecting their family tree must be able to see related individuals whose entity-level `view` policy might decline a direct request). Demo-grade in v1; revisit when genealogy ships a per-edge access policy. | 2026-05-18 |
| `packages/genealogy/src/Service/GenealogyPedigreeService.php` | 36 | Same rationale as `GenealogyFamilyService`; pedigree traversal must cross access boundaries. | 2026-05-18 |
| `packages/genealogy/src/Service/GenealogyPedigreeService.php` | 59 | Same. | 2026-05-18 |
| `packages/genealogy/src/Service/GenealogyPedigreeService.php` | 240 | Same. | 2026-05-18 |
| `packages/genealogy/src/Ssr/GenealogySsrController.php` | 155 | SSR controller hydrates pedigree data for the demo surface; mirrors the service-layer policy above. Revisit when genealogy has a proper account-aware access policy. | 2026-05-18 |
| `packages/genealogy/src/Ssr/GenealogySsrController.php` | 164 | Same SSR controller, sibling query. | 2026-05-18 |
| `packages/mcp/src/Tools/McpTool.php` | 68 | MCP relationship-id lookup feeds tool-execution context; the MCP surface authenticates via its own credential layer (not the user account model), so the entity-query account is intentionally unset. | 2026-05-18 |
| `packages/path/src/PathAliasResolver.php` | 25 | System-context URL → entity-id lookup. Path aliases must resolve globally for any visitor; entity-level access is enforced when the caller subsequently loads the resolved entity. Missed during the original audit and caught when Phase13 SSR integration tests started returning HTTP 500 instead of 200/403/404 after v0.1.0-alpha.181 shipped. | 2026-05-19 |
| `packages/user/src/Http/AuthController.php` | 67 | Pre-authentication identity resolution: `findUserByName()` runs *before* the user has a session — there is no account to bind. Mirrors `SqlEntityStorage::loadByKey` (C-004). Name-lookup chain. Missed during the original audit and caught when the admin SPA login surface returned HTTP 500 on `POST /api/auth/login` after v0.1.0-alpha.181 shipped (#1525). | 2026-05-20 |
| `packages/user/src/Http/AuthController.php` | 76 | Same controller, mail-fallback chain. Runs only when the name lookup misses, but identical justification: no bound account exists pre-authentication. (#1525) | 2026-05-20 |
| `packages/seo/src/SitemapGenerator.php` | 100 | Sitemap generation enumerates public URLs for crawlers (`/sitemap.xml` is anonymous-served by definition). Same shape as `PathAliasResolver` — entity-level access is enforced when the caller subsequently loads the entity to render its page. Found during the post-#1525 pre-auth sweep. **2026-06-23 (audit Medium): the `accessCheck(false)` bypass is retained for URL-generation performance, but a `condition('status', 1)` filter was added for entity types that declare a `status` field, so the enumeration matches this row's "public URLs" rationale and no longer advertises unpublished/draft URLs. The sibling `packages/seo/src/Llms/LlmsTxtGenerator.php:104` got the same filter.** | 2026-05-20 |
| `packages/user/src/UserBlockService.php` | 27 | Block-relationship existence is an integrity primitive (mirrors `RelationshipValidator` / `RelationshipDeleteGuardListener`). The yes/no answer cannot be gated by either party's `view` policy on the `user_block` entity without breaking the safety semantics this service exists to enforce. Found during the post-#1525 pre-auth sweep. | 2026-05-20 |

### Conditional fallback — set account when available, bypass otherwise

These sites were rewritten by WP03 to bind `setAccount($account)` whenever the request's authenticated account is in scope and to fall back to `accessCheck(false)` only when the controller is invoked without an account (e.g. console driver, internal tooling). They are listed here because the bypass branch is still reachable; in the request-driven path the bypass does not run.

| File | Line | Justification (bypass branch only) | Last reviewed |
|---|---|---|---|
| `packages/api/src/JsonApiController.php` | 58 | Count query in JSON:API list endpoint; bypass fires only when the controller is invoked without an account in scope. Request-driven path takes `setAccount($account)`. | 2026-05-18 |
| `packages/api/src/JsonApiController.php` | 74 | Main list-query in JSON:API list endpoint; same fallback shape as line 58. | 2026-05-18 |
| `packages/api/src/JsonApiController.php` | 466 | UUID lookup helper; same fallback shape. | 2026-05-18 |
| `packages/graphql/src/Resolver/EntityResolver.php` | 70 | GraphQL count query; bypass only when no account is in the resolver context. | 2026-05-18 |
| `packages/graphql/src/Resolver/EntityResolver.php` | 92 | GraphQL main entity-list query; same fallback shape. | 2026-05-18 |
| `packages/graphql/src/Resolver/EntityResolver.php` | 229 | GraphQL UUID resolver; same fallback shape. | 2026-05-18 |
| `packages/ai-vector/src/SearchController.php` | 178 | Semantic search relationship-id prefetch; bypass only when controller has no account. Request-driven path binds the request's account. | 2026-05-18 |
| `packages/ai-vector/src/SearchController.php` | 317 | Keyword-search fallback in semantic search controller; same shape. | 2026-05-18 |

### Documentation references (not call sites)

These two matches are documentation strings inside `MissingQueryAccountException` itself — they describe the bypass to operators and are not enforcement points.

- `packages/entity-storage/src/Exception/MissingQueryAccountException.php:22` — class docblock describing the opt-out.
- `packages/entity-storage/src/Exception/MissingQueryAccountException.php:44` — exception message text referencing the opt-out.

## Removed during mission (formerly unconditional `accessCheck(false)`)

These call sites were classified as user-facing and switched to bind `$account` via `setAccount($account)` (with a system-context fallback documented above). Before this mission they were unconditional bypasses leaking cardinality and unfiltered rows:

- `packages/graphql/src/Resolver/EntityResolver.php:65` (count query) — was leaking unfiltered cardinality.
- `packages/graphql/src/Resolver/EntityResolver.php:81` (main query) — was returning rows the user couldn't access.
- `packages/graphql/src/Resolver/EntityResolver.php:211` (uuid lookup) — was returning entities by UUID without policy check.
- `packages/api/src/JsonApiController.php:52` (count query) — was leaking JSON:API list cardinality.
- `packages/api/src/JsonApiController.php:63` (main query) — was returning JSON:API list rows without filter.
- `packages/api/src/JsonApiController.php:450` (uuid lookup) — was returning entity by UUID without policy check.
- `packages/ai-vector/src/SearchController.php:173` — was returning relationship-keyed semantic-search results without filter.
- `packages/ai-vector/src/SearchController.php:303` — was returning keyword-search results without filter.
- `packages/genealogy/src/Service/GenealogyFamilyService.php:28` (former unconditional bypass) — now binds account where available; bypass branch is fallback only.
- `packages/genealogy/src/Service/GenealogyPedigreeService.php:32,:50,:226` (former unconditional bypass sites) — same shape.

(The genealogy entries appear in both the "current" and "removed" sections because the unconditional pre-mission bypass was replaced by a conditional-fallback pattern; the bypass *line* still exists but only executes when the caller has no account, which the v1 demo wiring routinely does not. Tracked for a follow-up policy pass.)

## How to audit

To regenerate this list:

```
grep -rn "accessCheck(false)" packages/*/src/ --include="*.php"
```

For each result, decide:

- **Keep (unconditional bypass)**: system context — runs without a user, or genuinely needs to see all rows. Add a comment at the call site if missing, and add a row to the "Unconditional bypass" table above.
- **Keep (conditional fallback)**: controller may run with or without an account; bind account when available, bypass otherwise. Add to the "Conditional fallback" table.
- **Switch**: user-facing — replace with `->setAccount($account)` unconditionally. The account source is request-specific; mirror the pattern from `JsonApiController` (`_account` request attribute) or GraphQL context.

## Future automation

A CI grep gate is a candidate follow-up to enforce that no new `accessCheck(false)` lands without an audit-doc update. Not implemented in v1.
