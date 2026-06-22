# Research — Agent-Readable App (Full Acceptance Bar)

**Mission:** `agent-readable-app-full-bar-01KVB4VM`
**Type:** software-dev · **Repo:** waaseyaa-framework @ alpha.220
**Date:** 2026-06-17

## 1. Goal

Make a stock Waaseyaa app "agent-readable" to a full acceptance bar, **in framework
core, on by default**, so a stock `composer create-project` app ships with all of it.
The bar is six criteria (markdown content negotiation, raw toggle, llms.txt index,
public read-only MCP, server card + registry readiness, isitagentready pass).

**Hard constraint (from mission brief):** there are NO deployed downstream apps. No
BC shims, no deprecation layers, no migration paths. Prefer correct clean architecture.
Where work touches the L0/L4 router-layering exemption (`KERNEL_EXEMPT_FILES`),
**relocate routers to their proper layer rather than adding exemptions.**

## 2. Method

Findings below rest on a prior file-level investigation (treated as ground truth) plus
direct verification reads this session. Every claim cites a file path. Items that could
not be settled by static review are flagged as runtime-verification tasks (criterion 6).

## 3. Per-criterion findings

### Criterion 1 — Markdown via HTTP Accept negotiation (same URL)

**Exists:**
- Public, JS-free SSR HTML pages already render server-side. Catch-all route `public.page`
  → `/{path}` → SSR: `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php:323-335`.
- Path→entity resolution via path aliases: `packages/ssr/src/SsrPageHandler.php:119-147`,
  `packages/path/src/PathAliasResolver.php:15-46`.
- Entity→structured-data serialization with field-access filtering + cast logic is built and
  ~70% reusable: `packages/api/src/ResourceSerializer.php:46` (`castAttributes()` @204,
  `normalizeAttributesForJson()` @226).
- Accept-**Language** quality-value negotiation exists as a model:
  `packages/routing/src/Language/AcceptHeaderNegotiator.php`.

**Missing (net-new):**
- No markdown anywhere — no commonmark dep, no `->toMarkdown()`, no markdown field formatter
  (`packages/ssr/src/FieldFormatterRegistry.php:15-44` has string/html/datetime/image only).
- No generic `Accept` media-type negotiation — SSR hard-codes `text/html`
  (`packages/ssr/src/Http/Router/SsrRouter.php:46`); no `Vary: Accept` on SSR responses.

**Decision:** build `EntityMarkdownPresenter` (L4 `api`, reusing `ResourceSerializer` field
resolution + access filtering). Add a media-type `AcceptNegotiator` modeled on the language
one. Wire negotiation into the SSR handler. Pull in `league/commonmark` (maintained) only
where markdown→HTML is needed (raw-view rendering / sanitisation); entity→markdown
generation is direct string building, not a parse step. **Cache-variant keying by
content-type is a hard-correctness item** (see §4.3).

### Criterion 2 — `?raw` toggle (exact same bytes, identical presenter)

**Exists:** SSR already supports query-param view selection (`view_mode`, `preview`) in
`SsrPageHandler.php` — the branch mechanism is in place.

**Missing:** a `?raw=1` (or `?format=md`) branch that returns the **identical** presenter
output as the negotiated markdown response, plus a UI affordance in the page Twig template.

**Decision:** route the toggle through the *same* `EntityMarkdownPresenter` call as Accept
negotiation — single code path, no parallel renderer. Strictly downstream of criterion 1.

### Criterion 3 — llms.txt as an index of per-topic `.md` URLs

**Exists:** sitemap collection over entity types is the pattern to copy:
`packages/seo/src/SitemapGenerator.php` (`collectFromEntityTypes()`, paginates with
`accessCheck(false)` for crawlers). `JsonLdBuilder`, `RobotsTxtGenerator`, `SeoTwigExtension`
show the L3 "emit a public text artifact" shape. **No `llms.txt` exists** (confirmed: nothing
tracked).

**Decision — topic model (see §4.1 for full rationale):** a "topic" = a published,
URL-addressable **content grouping**, with a layered resolution:
1. **Default:** one topic per *content entity type that has a public path/canonical URL*
   (e.g. `node`, `taxonomy_term`), each topic linking to that type's index `.md` URL and its
   member entity `.md` URLs (bounded/paginated).
2. **Override:** an optional curated config (`llms.topics` in config sync) lets an app declare
   explicit topics (title, summary, list of `.md` URLs) that supersede the derived set.
Build `LlmsTxtGenerator` alongside `SitemapGenerator` (L3 `seo`); route `/llms.txt` publicly.

### Criterion 4 — Public read-only MCP server (3-layer enforcement)

**Exists — substantially.** This is the headline finding.
- A real hosted MCP server (not a client): `packages/mcp/src/McpEndpoint.php` implements
  JSON-RPC 2.0 `initialize`/`ping`/`tools/list`/`tools/call` (protocol `2025-03-26`), routed
  at `POST /mcp` (`packages/mcp/src/McpRouteProvider.php:14-21`), wired in
  `packages/mcp/src/McpServiceProvider.php:101`.
- All three required read tools already exist, all `destructive: false`:
  - entity read → `packages/ai-tools/src/Entity/EntityReadTool.php:21-22` (`tool.entity.read`)
  - entity search → `packages/ai-tools/src/Entity/EntitySearchTool.php:24-25`
    (`tool.entity.search`); spec search → `packages/ai-agent/src/Tool/Bimaaji/SearchSpecsTool.php:29-30`
    (`bimaaji.read`)
  - graph traverse → `packages/ai-tools/src/Relationship/RelationshipTraverseTool.php:23-24`
    (`tool.relationship.traverse`); `packages/ai-agent/src/Tool/Bimaaji/IntrospectGraphTool.php:27-28`
- FTS search backend exists (`packages/search/`, `Fts5SearchProvider`) to back content search.

**Missing (net-new, bounded):**
- No public/anonymous mode. `packages/mcp/src/Auth/BearerTokenAuth.php:21-28` returns `null`
  (→ HTTP 401) when no bearer token; default token map is empty → **default-deny**.
- No read-only tool allowlist on the hosted surface.

**Decision — 3 independent enforcement layers (security boundary, see §4.2):**
1. **Tool allowlist** — a public-surface registry/filter that only ever exposes the
   `destructive:false` read tools to `tools/list`/`tools/call`. Write tools are structurally
   absent from the public bridge.
2. **Per-tool capability grants** — the anonymous account holds only read capabilities
   (`tool.entity.read`, `tool.entity.search`, `tool.relationship.traverse`, `bimaaji.read`);
   `AbstractAgentTool::requireCapability` already enforces per call.
3. **accessCheck on every query** — every query the public tools run goes through
   `setAccount(anonymous)` / `accessCheck(true)` (never `accessCheck(false)`), so entity-level
   and field-level policies apply. Guarded by `bin/check-getquery-bindings`.
Add a `PublicAnonymousAuth implements McpAuthInterface` resolving missing creds to the
anonymous account, and **tests proving a write/destructive tool is rejected anonymously at
each layer.**

### Criterion 5 — `.well-known/mcp.json` server card + registry readiness

**Exists:** the card is already served at `/.well-known/mcp.json`
(`packages/mcp/src/McpRouteProvider.php:23-30`, `packages/mcp/src/McpServerCard.php`),
advertising transport `streamable-http`, `tools:true`, `authentication:bearer`. Values are
**hard-coded** (name/version/description/auth).

**Missing:** configurable name/description/URL/auth; declaration of the new public auth mode;
fields an MCP-registry listing requires.

**Decision:** make the card config-driven; declare `authentication: { type: "none" }` (or
optional) for the public mode; include registry fields (verify against the live registry
schema — if unreachable, list implemented fields and flag). Registry *submission* is a manual
external step, not code.

### Criterion 6 — Full isitagentready pass (runtime-verified)

**Exists:** JS-free SSR HTML (Twig); sitemap generator (`packages/seo/src/SitemapGenerator.php`);
schema.org builders (`packages/seo/src/JsonLdBuilder.php` — `webSite`/`organization`/`breadcrumb`),
`MetaTagBuilder`, `RobotsTxtGenerator`.

**Unknown / must runtime-verify:**
- Whether schema.org JSON-LD is actually emitted into rendered SSR page `<head>` — builders
  exist but injection into the render path was NOT found. → curl a rendered page, grep for
  `application/ld+json`; if absent, wire `JsonLdBuilder` into the SSR render path with
  per-entity-type schema.org `@type` mapping.
- Whether `/sitemap.xml` and `/robots.txt` are routed publicly. → curl and confirm; wire routes
  if missing.
- The full isitagentready checklist has not been run against a live instance. → run, fix or
  document each check.

## 4. Cross-cutting architectural decisions

### 4.1 Package arrangement / skeleton tier (core-by-default)

**Problem:** the agent-readability stack (`ssr`, `mcp`, `ai-tools`, `ai-agent`) is required
**only by `packages/full/composer.json:7-15`**. `core` and `cms` pull none of it. A
`create-project` app on `core`/`cms` would get no SSR, no MCP, no agent tools — so "on by
default" is currently false.

**Decision (no BC constraint):** promote the agent-readable mechanism into the default tier.
The new generic mechanism packages (markdown presenter, media-type negotiator, llms.txt
generator, public-MCP auth) live in their proper existing layers (L4 api, L6 ssr/mcp, L3 seo).
The **skeleton** (`skeleton/`, which requires `waaseyaa/framework`) already gets everything via
the monorepo; the **metapackage** decision is: move `ssr` + `seo` + the markdown/negotiation/
llms pieces into `cms` (every content site needs them), and keep the public MCP server in `cms`
too (it is the agent-readability promise). Exact metapackage edges to be finalised in plan.md;
this is partly a product call flagged in §6.

### 4.2 MCP public read-only security boundary (do NOT guess)

Three independent layers (allowlist + capability + accessCheck), each individually sufficient to
block a write tool, composed so no single mistake opens the surface. Tests must prove an
anonymous `tools/call` to a destructive tool (e.g. `EntityCreateTool`/`EntityDeleteTool`) is
rejected — and that it is *absent from* `tools/list`. accessCheck must be `true` for all public
queries (never `accessCheck(false)`), tracked by `bin/check-getquery-bindings`.

### 4.3 SSR cache-variant keying by content-type (do NOT guess)

`SsrPageHandler` computes a cache surrogate/variant key (language, view mode, workflow state,
relationship-graph hash). The negotiated media type (`text/html` vs `text/markdown`) MUST be
part of that key, and responses MUST carry `Vary: Accept`. Otherwise a markdown response can be
served from an HTML cache entry (or vice-versa). This is a correctness gate with tests covering
both cache-hit and cache-miss across both media types.

### 4.4 Router layering (relocate, don't exempt)

New public routes: markdown negotiation belongs to L4 `api` (depends down on entity/access);
the MCP host is already L6. The `AuthOidcRouteServiceProvider` precedent (route wiring lifted to
L4) is the sanctioned pattern. Where this work touches `foundation/src/Http/Router/*` built-in
routers under `KERNEL_EXEMPT_FILES`, prefer relocating to the owning layer over adding new
exemptions (mission mandate). Audit which, if any, relocations are required.

## 5. Data / artifacts produced

See `data-model.md` for the value objects and contracts (presenter output, topic model, anon
account, server-card config, media-type set, cache-variant key).

## 6. Open questions / risks (feed into plan + tasks)

1. **Metapackage tiering is a product decision.** Should the public MCP server be in `cms`
   (every content site) or remain `full`-tier? Default proposal: `cms`. **Flag for user.**
2. **Topic granularity for llms.txt** — derived-from-entity-types default vs curated config:
   proposed layered model (§4.1); confirm the default is acceptable.
3. **MCP-registry schema** — must verify required card fields against the live registry; if
   unreachable from this environment, implement best-known fields and flag.
4. **Anonymous capability set** — which exact capabilities the anon account holds is a
   security-relevant config choice; default = the four read capabilities above.
5. **commonmark scope** — confirm we only need it for raw-view HTML rendering/sanitisation, not
   for entity→markdown generation.
6. **Windows/Linux test split** — suite is Linux-first; new tests must respect the split-suite
   contract (CLAUDE.md). Live curl verification (criterion 6) needs the dev server booted.
