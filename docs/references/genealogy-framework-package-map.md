# Waaseyaa packages — framework reconnaissance for genealogy (A–C)

**Status:** After the **pre-A1 `waaseyaa/genealogy` hygiene PR** lands (see [genealogy plan](file:///home/fsd42/.cursor/plans/genealogy_first-class_gaps_681bbc45.plan.md) §Pre-A1), **re-run this document** so the **Genealogy dep?** column matches new runtime `require`s (notably `waaseyaa/user`; optional `waaseyaa/field` per split todos).

**Purpose:** Reference map before Phase A1 — **not** a build decision. Enumerates every directory under [`packages/`](file:///home/fsd42/dev/waaseyaa/packages), with maturity hints and genealogy relevance. **Edit scope:** the Waaseyaa monorepo under `~/dev/waaseyaa/packages/*` is **in scope** for framework fixes unless a row notes otherwise (e.g. `admin` is the Nuxt app, not a PHP Composer library).

**Related plan:** [genealogy first-class plan](file:///home/fsd42/.cursor/plans/genealogy_first-class_gaps_681bbc45.plan.md) (Cursor plans path; copy or link into repo docs if desired).

**Method:** `packages/*` directory listing (2026-04-21); `composer.json` name/description/type; no package-level `CHANGELOG.md` found in roots — maturity inferred from `^0.1.0-alpha.*` constraints, metapackage role, and centrality to kernel.

### Maturity legend

| Tag | Meaning |
| --- | --- |
| **kernel** | Core path many apps depend on; specs in `docs/specs/`; still `0.1.x-alpha` but **least volatile** relative to feature packages. |
| **alpha** | Normal library: `minimum-stability: dev`, alpha semver — expect API drift. |
| **volatile** | Fast-moving or product-adjacent (AI, billing, observability stacks). |
| **meta** | Metapackage only — logic lives in dependents; edit those, not the meta stub. |
| **app** | Not a PHP Composer package (`admin` Nuxt SPA). |

---

## Package table

Alphabetical by directory. **Genealogy dep:** `Y` = `require` in `waaseyaa/genealogy`; `dev` = `require-dev` only; `N` = not declared (may still be transitive via apps).

| Directory | Composer `name` | Primary surface (one line) | Maturity | Genealogy relevance (A–C) | Genealogy dep? | Suggested action |
| --- | --- | --- | --- | --- | --- | --- |
| `access` | `waaseyaa/access` | `AccessPolicyInterface`, `EntityAccessHandler`, `Gate`, `PolicyAttribute`, permissions model | **kernel** | A2 private defaults; C1 `genealogy_share` + graph policies; field access | **Y** | Prefer policy/helper fixes here if cross-cutting |
| `admin` | — (Nuxt) | Admin SPA: Vue/Nuxt, Playwright, `package.json` | **app** | C3 product surfaces; not a PHP dep for genealogy | **N** | JS sibling; out of PHP package graph |
| `admin-surface` | `waaseyaa/admin-surface` | PHP boundary for Admin SPA (routes, auth bridge, integration) | **alpha** | C3 when exposing genealogy in admin | **N** | Extend when wiring admin CRUD for trees/shares |
| `ai-agent` | `waaseyaa/ai-agent` | Agent orchestration, tools | **volatile** | Long-term: natural-language tree help; not A1 | **N** | Defer |
| `ai-observability` | `waaseyaa/ai-observability` | Traces, cost, anomalies for agents | **volatile** | Ops for future AI+genealogy | **N** | Defer |
| `ai-pipeline` | `waaseyaa/ai-pipeline` | Queue-backed AI pipelines | **volatile** | Future batch (e.g. GEDCOM-era) | **N** | Defer |
| `ai-schema` | `waaseyaa/ai-schema` | AI-facing schema / entity metadata | **volatile** | MCP/LLM tree summaries later | **N** | Defer |
| `ai-vector` | `waaseyaa/ai-vector` | Embeddings + similarity | **volatile** | Research / dedup later | **N** | Defer |
| `analytics` | `waaseyaa/analytics` | Umami integration, Twig/JS helpers | **alpha** | Low: public page analytics only if ever exposing trees | **N** | Privacy review if enabled on genealogy routes |
| `api` | `waaseyaa/api` | JSON:API controller, query, serialization | **kernel** | A–C: all entity CRUD/list filtering | **dev** | Promote to runtime `require` if genealogy ships write API without app shim |
| `auth` | `waaseyaa/auth` | Login, register, 2FA, password flows | **alpha** | B–C: authenticated share accept; SSR session | **N** | Use via app; extend only if genealogy needs auth hooks |
| `billing` | `waaseyaa/billing` | Stripe subscriptions, portal | **volatile** | Low unless genealogy is paid feature | **N** | Defer |
| `bimaaji` | `waaseyaa/bimaaji` | Graph introspection, agent-safe mutation | **volatile** | C: tool surface must respect same policies as JSON:API | **N** | Coordinate when exposing tree to agents |
| `cache` | `waaseyaa/cache` | Cache bins, tag invalidation | **alpha** | Perf for pedigree queries / policy | **N** (transitive) | Use for hot paths if profiling says so |
| `cli` | `waaseyaa/cli` | `bin/waaseyaa`, console kernel | **alpha** | Dev ergonomics; migrations via app | **N** (transitive) | — |
| `cms` | `waaseyaa/cms` | **Metapackage**: CMS bundle of node, taxonomy, media, api, cli… | **meta** | Indirect only | **N** | Edit leaf packages, not this stub |
| `config` | `waaseyaa/config` | YAML config loading, package ownership | **kernel** | App + package defaults | **N** (transitive) | Centralize genealogy default config keys if needed |
| `core` | `waaseyaa/core` | **Metapackage**: entity+storage+access+routing+user+… | **meta** | Stack composition | **N** | Edit leaf packages |
| `database-legacy` | `waaseyaa/database-legacy` | Drupal DBAL adapter, connection | **kernel** | All persistence | **dev** | Schema/migrations live with entity types |
| `debug` | `waaseyaa/debug` | Dev toolbar, dumps | **alpha** | Dev only | **N** | — |
| `deployer` | `waaseyaa/deployer` | Deployer recipes (deploy automation) | **alpha** | Ops, not runtime | **N** | — |
| `engagement` | `waaseyaa/engagement` | Reaction, comment, follow entities + routes | **alpha** | **Different** from genealogy share — avoid conflating “follow” with tree grant | **N** | Do not reuse for C1 grants |
| `entity` | `waaseyaa/entity` | Entity types, `ContentEntityBase`, queries, interfaces | **kernel** | A1 `genealogy_tree` / `genealogy_share`; keys, bundles | **Y** | Extend types / interfaces if tenancy needs first-class support |
| `entity-storage` | `waaseyaa/entity-storage` | SQL storage, `EntityTypeManager` integration | **kernel** | Migrations, storage for new types | **dev** | Schema for new entities |
| `error-handler` | `waaseyaa/error-handler` | Rich error pages, hints | **alpha** | UX on deny/forbidden | **N** (transitive) | — |
| `field` | `waaseyaa/field` | Field definitions, types, formatters, registry | **kernel** | A1 owner/`tree_id` via `addBundleFields`; bundle subtables | **Y** | Genealogy registers `FieldDefinition` cores + apps may `mergeCoreFields()` for overlays |
| `foundation` | `waaseyaa/foundation` | Kernel, service providers, `PackageManifestCompiler`, discovery, bootstrap | **kernel** | Policy discovery, events, lifecycle | **Y** | Fix discovery/boot if genealogy needs new artifact types |
| `full` | `waaseyaa/full` | **Metapackage**: CMS + AI + SSR + MCP | **meta** | Product stacks | **N** | — |
| `genealogy` | `waaseyaa/genealogy` | Persons, families, events, pedigree services, SSR routes, policies | **alpha** | Subject of plan | **self** | Implement A–C here + targeted sibling fixes |
| `geo` | `waaseyaa/geo` | Distance, coordinates | **alpha** | Optional: place of birth / map (not in v1 plan) | **N** | Defer |
| `github` | `waaseyaa/github` | GitHub REST client | **alpha** | CI/ops | **N** | — |
| `graphql` | `waaseyaa/graphql` | Auto schema from entity types | **alpha** | Alt read API; privacy same as entities | **N** | Optional later |
| `groups` | `waaseyaa/groups` | `group` + `group_type` multi-bundle entity | **alpha** | C1 optional recipient sets; **not** `genealogy_tree` | **N** | Gate per plan; README/composer hygiene PR |
| `http-client` | `waaseyaa/http-client` | Minimal HTTP client | **alpha** | C1 outbound (webhooks, external id) | **N** | Add if integrating external genealogy APIs |
| `i18n` | `waaseyaa/i18n` | Language, fallback chains | **alpha** | A3 SSR strings, redaction labels | **N** (transitive) | Use for placeholder copy consistency |
| `inertia` | `waaseyaa/inertia` | Inertia.js server adapter | **alpha** | If a future SPA reads trees | **N** | Defer |
| `ingestion` | `waaseyaa/ingestion` | Payload validation utilities | **alpha** | GEDCOM-era import (non-goal now) | **N** | Defer |
| `mail` | `waaseyaa/mail` | Transport-agnostic mail | **alpha** | C1 invite / share notification emails | **N** | Wire when product wants email invites |
| `mcp` | `waaseyaa/mcp` | MCP server endpoint for Waaseyaa | **alpha** | C+: tool exposure must respect policies | **N** | Spec traversal + access together |
| `media` | `waaseyaa/media` | Media entity, files | **alpha** | Optional: person photos | **N** | Defer |
| `menu` | `waaseyaa/menu` | Menu + links | **alpha** | C3 nav when feature on | **N** | Minoo/menu integration |
| `mercure` | `waaseyaa/mercure` | SSE hub publisher | **alpha** | Live collaboration on tree (future) | **N** | Defer |
| `messaging` | `waaseyaa/messaging` | Threads, messages, participants | **alpha** | C: “discuss this share” UX optional | **N** | Not same as `genealogy_share` |
| `node` | `waaseyaa/node` | `node` content entity | **alpha** | Orthogonal to genealogy domain | **N** | — |
| `northcloud` | `waaseyaa/northcloud` | NC client, sync, search provider | **alpha** | Minoo ingestion; not core genealogy | **N** | — |
| `note` | `waaseyaa/note` | Built-in Note type | **alpha** | Low | **N** | — |
| `notification` | `waaseyaa/notification` | Multi-channel notifications | **alpha** | C1 share events, digest | **N** | Consider for share accepted / revoked |
| `oauth-provider` | `waaseyaa/oauth-provider` | OAuth2 provider abstraction | **alpha** | Enterprise SSO (optional) | **N** | Defer |
| `oidc` | `waaseyaa/oidc` | OIDC issuer | **alpha** | Ecosystem SSO | **N** | Defer |
| `path` | `waaseyaa/path` | Path aliases | **alpha** | Pretty URLs for public trees (if ever) | **N** | — |
| `plugin` | `waaseyaa/plugin` | Plugin discovery (`#[AsPlugin]` etc.) | **alpha** | Extensibility | **N** (transitive) | — |
| `queue` | `waaseyaa/queue` | Async jobs | **alpha** | Heavy reindex, future import | **N** | Defer |
| `relationship` | `waaseyaa/relationship` | `relationship` entity, discovery/traversal services, validators | **kernel** | A–C graph edges; visibility with genealogy policies | **Y** | Extend if traversal needs access-aware APIs; avoid overloading for share **documents** (use `genealogy_share`) |
| `routing` | `waaseyaa/routing` | `RouteBuilder`, HTTP router | **kernel** | A3 SSR routes | **Y** | — |
| `scheduler` | `waaseyaa/scheduler` | Cron scheduling | **alpha** | Background maintenance | **N** | Defer |
| `search` | `waaseyaa/search` | Search provider interface | **alpha** | Indexing people (privacy-sensitive) | **N** | Careful if enabling on trees |
| `seo` | `waaseyaa/seo` | Sitemap, meta, JSON-LD | **alpha** | Public tree pages only if policy allows | **N** | Must not leak private nodes |
| `ssr` | `waaseyaa/ssr` | Twig SSR, theme, formatters | **kernel** | A3 templates, redaction UX | **Y** | Shared helpers if redaction pattern is cross-app |
| `state` | `waaseyaa/state` | Key-value state storage | **alpha** | Could hold feature flags; **C3 plan prefers user preference** on `user` in app | **N** | Prefer `user`/config over `state` for opt-in unless product wants anon state |
| `taxonomy` | `waaseyaa/taxonomy` | Vocab + terms | **alpha** | Optional tagging of events | **N** | Defer |
| `telescope` | `waaseyaa/telescope` | In-app observability UI | **volatile** | Dev/staging debugging | **N** | Not compliance audit log |
| `testing` | `waaseyaa/testing` | PHPUnit bases, factories | **alpha** | A1–A3 security tests in apps/packages | **dev** | Extend factories for tree/share |
| `typed-data` | `waaseyaa/typed-data` | Typed data API | **kernel** | Field/item plumbing | **N** (transitive) | — |
| `user` | `waaseyaa/user` | User entity, roles, sessions | **kernel** | A1 owner; B2 identity; C1 grantee | **Y** | Runtime `require` in `waaseyaa/genealogy` for tree `owner_uid` + policy checks |
| `validation` | `waaseyaa/validation` | Symfony-style constraints | **alpha** | C1 share payload validation; A1 date rules | **N** | Add dep when validating share scopes server-side |
| `workflows` | `waaseyaa/workflows` | Workflow states, visibility helpers tied to relationships | **alpha** | Spec cross-links `WorkflowVisibility` for relationship discovery | **Y** | Genealogy content policy uses `WorkflowVisibility` for published checks |

---

## Underused or misaligned today (genealogy package)

These are **suggestions**, not tasks.

1. **`waaseyaa/field` + bundle registry** — Genealogy field metadata comes from the entity classes registered by `GenealogyServiceProvider`; host apps may use the field registry for product overlays.

2. **`waaseyaa/workflows`** — Genealogy **`GenealogyContentAccessPolicy`** now depends on **`WorkflowVisibility`** for published checks alongside tenancy + living rules. Keep relationship discovery aligned per [`relationship-modeling.md`](file:///home/fsd42/dev/waaseyaa/docs/specs/relationship-modeling.md).

3. **`waaseyaa/api`** — **Plan:** keep **out of genealogy `require`** (optional transport; host app wires JSON:API). **`waaseyaa/genealogy-api`** is the documented escape hatch for package-owned HTTP later. **Invariant (enforceable):** `packages/genealogy/**/*.php` must not contain **`use Waaseyaa\Api\`** or other **`Waaseyaa\Api\`** class coupling (`extends`, `instanceof`, `new`, `::class` on api types); **rg + CI** should fail on violation. **Allowed:** attributes, config keys, docblocks that api reads when present. **require-dev** may remain for tests only.

4. **`waaseyaa/validation`** — Share grant forms and API payloads (C1) should use shared validators; genealogy should **depend on validation** when C1 lands, not reimplement.

5. **`waaseyaa/notification` / `waaseyaa/mail`** — No dependency today. For “share accepted / revoked / invited,” **prefer** notification abstractions over custom mail in genealogy **if** those packages are the canonical product path (confirm with maintainers).

6. **Audit / compliance logging** — There is **no dedicated “audit log” package** in this list. **Telescope** / **ai-observability** are **ops/debug**, not immutable audit. If C1 needs **compliance-grade access events**, the gap is a **new package or a deliberate app module** — flag as **platform gap**, not hidden inside genealogy.

7. **Policy “base class”** — Access stack uses **`AccessPolicyInterface`** + **`PolicyAttribute`** discovery; there is **no required genealogy-specific base class**. If repeated boilerplate appears across entity policies, **add a small helper or abstract in `access`** (or genealogy-internal trait) — prefer **`access`** if three+ entity types across apps need it.

---

## Edit-scope confirmation

| Area | In scope? |
| --- | --- |
| All of `~/dev/waaseyaa/packages/*` listed above (PHP libraries) | **Yes** — editable framework; prefer sibling fix over genealogy/Minoo workaround. |
| `packages/admin` (Nuxt) | **Yes** for front-end, but it is **not** Composer PHP — coordinate JS/PHP releases separately. |
| Metapackages (`cms`, `core`, `full`) | **Edit only** when bumping dependency sets; **no** business logic there. |
| `deployer` | Recipes only — in scope for deploy story, not genealogy runtime. |

**Uncertainty:** None flagged as read-only. If a package is later **vendored** or **split to another repo**, re-confirm ownership before editing.

---

## Gaps not covered by an existing package

- **Immutable audit trail** for sensitive genealogy access — not found as a first-class package; design explicitly if required.
- **User preference / consent primitives** — **`user`** + app fields (Minoo plan C3) is the current direction; **`state`** is an alternative store but not a “preference framework” by itself.

---

*Generated as reconnaissance input to the genealogy first-class plan. Re-run listing when packages are added or removed.*
