# Implementation Plan: Agent-Readable App (Full Acceptance Bar)

**Branch**: `main` (planning) → lane worktrees at implement time | **Date**: 2026-06-17
**Spec**: [spec.md](spec.md) | **Research**: [research.md](research.md) | **Data model**: [data-model.md](data-model.md)

## Summary

Deliver the six agent-readability criteria in framework core, enabled by default. Primary
technical approach: (1) a media-type Accept negotiator + `EntityMarkdownPresenter` (reusing
`ResourceSerializer`) wired into the SSR render path with content-type-keyed caching and a `?raw`
toggle; (2) an `LlmsTxtGenerator` + public `/llms.txt` route in `seo`; (3) a public anonymous
read-only MCP mode with three-layer enforcement; (4) a configurable, registry-ready server card;
(5) schema.org JSON-LD wired into SSR `<head>` plus verified `/sitemap.xml` + `/robots.txt`; (6)
runtime isitagentready verification. Default-wiring (not package membership) is the lever for
"on by default" — see Structure Decision.

## Technical Context

**Language/Version**: PHP 8.5 (`declare(strict_types=1)`), Symfony 7.x components.
**Primary Dependencies**: `league/commonmark` (new, for raw-view markdown↔HTML sanitisation only);
existing `ResourceSerializer`, SSR Twig stack, `seo` generators, `mcp` endpoint, `ai-tools`/`ai-agent`
tool registry, `search` (FTS5), `relationship`.
**Storage**: existing entity storage via DBAL; no new tables. SSR cache backend (existing).
**Testing**: PHPUnit 10.5 split suites (Unit/Integration), Linux-first; `CliTester` for CLI; live
`curl` against `composer dev` server for criterion 6. New security + cache-variant tests are gates.
**Target Platform**: Linux server (CI); Windows dev supported with the documented POSIX-only
caveats.
**Project Type**: web (server-rendered HTTP + MCP endpoint).
**Performance Goals**: no added DB queries on the public MCP read path beyond the tool's own;
markdown response served from its own cache variant.
**Constraints**: security boundary (FR-010/011) and cache-variant keying (FR-004/NFR-001) must be
provably correct; no new `KERNEL_EXEMPT_FILES`; no BC shims (C-001).
**Scale/Scope**: framework-wide; touches `api`, `ssr`, `seo`, `mcp`, `foundation` (routing),
metapackages, skeleton defaults.

## Charter Check

*GATE: must pass before and after design.*

- **DIR layer discipline / `bin/check-package-layers`**: markdown presenter → L4 `api` (down-deps
  only); negotiator → L4 `api` or L0 `foundation/Http`; llms.txt → L3 `seo`; public MCP auth →
  L6 `mcp`; schema.org mapper → L3 `seo` consumed by L6 `ssr`. No upward edges introduced.
  **No new router exemptions** — if any new domain router is needed it is registered via a
  service provider in its owning layer (the `AuthOidcRouteServiceProvider` precedent), not added
  to `foundation/src/Http/Router/`. PASS by design; re-checked post-implementation.
- **DIR-004 framework vs distribution**: all work is framework substrate (not a distribution
  extension). PASS.
- **Quality gates**: `composer cs-check`, `composer phpstan` (L5), `bin/check-dead-code` (new
  public surfaces marked `@api`), `bin/check-getquery-bindings` (every public query bound with
  `setAccount`/`accessCheck`), `bin/check-composer-policy` (metapackage edits). All must stay green.
- **Testing standards**: security tests prove anonymous write rejection at three layers;
  cache-variant tests prove no HTML/markdown cross-contamination.

No charter violations anticipated → Complexity Tracking empty.

## Structure Decision (package arrangement — delegated decision, resolved)

**Finding:** `composer create-project` uses the skeleton `waaseyaa/waaseyaa`
(`skeleton/composer.json`, type `project`), which requires **`waaseyaa/framework`** — the entire
monorepo. A stock create-project app therefore already has *every* package (`ssr`, `mcp`,
`ai-tools`, `ai-agent`, `seo`, `search`, `relationship`). **"On by default" is a wiring problem,
not a package-membership problem.**

**Decision:**
1. **Default wiring (the real lever).** Ensure the new and existing providers are auto-discovered
   and active in a stock app: SSR markdown negotiation, `/llms.txt` route, schema.org injection,
   and the **public anonymous read-only MCP binding as the default** `McpAuthInterface` (replacing
   the default-deny empty `BearerTokenAuth`). No skeleton package additions needed; verify
   `skeleton/config` enables them and bump `waaseyaa/framework` constraint if required.
2. **Metapackage coherence fix (clean architecture, not required by create-project).** Today
   `seo`, `search`, `relationship` are in **no** consumer metapackage, so a `waaseyaa/full` install
   gets `ssr`+`mcp` but no sitemap/schema.org/llms (`seo`), no content-search backend (`search`),
   no graph traverse (`relationship`) — an incoherent agent-readability story. Add `seo`,
   `search`, `relationship` to the tier that owns agent-readability. **Chosen tiering:**
   - `cms` gains `ssr` + `seo` (every content site should render public, SEO-correct,
     agent-readable HTML/markdown). cms is renamed in intent to "headless CMS + public web surface."
   - `full` gains `search` + `relationship` (they back AI/MCP tools already in `full`).
   - The public MCP server stays in `full` *as a metapackage edge*, BUT is wired on-by-default in
     the skeleton/framework install (create-project). Rationale: à-la-carte `cms` consumers should
     opt into an unauthenticated public MCP explicitly; the batteries-included `full`/skeleton get
     it by default per the mission brief.
   - All edits run through `bin/sync-internal-versions` + `bin/check-composer-policy`.

   *This `cms`-vs-`full` split for the public MCP is the one place reasonable people could differ;
   it is surfaced to the user at plan approval. Default chosen as above.*

### Source code touch map (real paths)

```
packages/api/src/
  ├── Markdown/EntityMarkdownPresenter.php        (new, reuses ResourceSerializer)
  └── Http/ (negotiator if sited here)            (new)
packages/foundation/src/Http/ContentNegotiation/  (media-type negotiator, if L0-sited)  (new)
packages/routing/src/Language/AcceptHeaderNegotiator.php  (model to copy)
packages/ssr/src/
  ├── SsrPageHandler.php                           (negotiation branch + cache variant)
  ├── Http/Router/SsrRouter.php                    (content-type, Vary: Accept)
  ├── RenderController.php / EntityRenderer.php     (markdown path + JSON-LD head injection)
  └── templates/                                   (raw toggle affordance)
packages/seo/src/
  ├── LlmsTxtGenerator.php  + LlmsTopic.php         (new)
  ├── EntitySchemaOrgMapper.php                     (new, uses JsonLdBuilder)
  ├── SitemapGenerator.php / JsonLdBuilder.php / RobotsTxtGenerator.php  (existing; route-verify)
  └── (route provider for /llms.txt, /sitemap.xml, /robots.txt in owning layer)
packages/mcp/src/
  ├── Auth/PublicAnonymousAuth.php                 (new)
  ├── ReadOnlyToolAllowlist.php (+ bridge filter)  (new)
  ├── McpServerCard.php + McpServerCardConfig.php   (configurable)
  └── McpServiceProvider.php                        (default public binding)
packages/cms/composer.json, packages/full/composer.json  (tiering)
skeleton/config, skeleton/composer.json            (default-enable verification)
tests/Integration/...  +  packages/*/tests/        (security, cache-variant, negotiation, llms)
```

## Implementation phases (dependency-ordered → work packages)

1. **WP-A Media-type negotiation core** — `MediaTypeAcceptNegotiator` (+ `?raw`/`?format` mapping),
   unit tests. (foundation/api)
2. **WP-B EntityMarkdownPresenter** — reuse `ResourceSerializer`; refs→links, images→alt,
   view modes; unit tests incl. access filtering. (api) — depends on nothing; parallel to WP-A.
3. **WP-C SSR negotiation + cache-variant keying** — wire WP-A/WP-B into `SsrPageHandler`/
   `SsrRouter`; add media type to cache variant; `Vary: Accept`; cross-contamination tests.
   Depends WP-A, WP-B. **Correctness gate.**
4. **WP-D `?raw` toggle + template affordance** — same presenter path; byte-identity test.
   Depends WP-C.
5. **WP-E llms.txt** — `LlmsTopic` + `LlmsTxtGenerator` + topic model (derived default + curated
   config) + public `/llms.txt` route. Depends WP-B (md URLs). (seo)
6. **WP-F Public read-only MCP** — `PublicAnonymousAuth` + `ReadOnlyToolAllowlist` + anon
   capability grants + accessCheck audit; **three-layer security tests** (write tool absent from
   list + rejected on call). Independent of WP-A..E. **Security gate.** (mcp/ai-tools)
7. **WP-G Server card config + registry fields** — `McpServerCardConfig`, declare public auth,
   registry fields (verify schema or flag). Depends WP-F. (mcp)
8. **WP-H schema.org + sitemap/robots wiring** — `EntitySchemaOrgMapper` → JSON-LD in SSR `<head>`;
   verify/wire `/sitemap.xml` + `/robots.txt`. Depends WP-C. (seo/ssr)
9. **WP-I Default wiring + metapackage tiering** — default public-MCP binding; provider
   auto-discovery; cms/full edits via `bin/sync-internal-versions`; skeleton default-enable.
   Depends WP-C, WP-F, WP-H.
10. **WP-J Live isitagentready verification** — boot `composer dev`; curl markdown, llms.txt,
    `.well-known/mcp.json`, JSON-LD in `<head>`, sitemap/robots; run checklist; fix or document
    each. Capture output in mission record. Depends WP-I. **Acceptance gate.**

WP-A/B, and WP-F, are parallelizable; the rest follow the dependency edges above.

## Risk / correctness notes (do-not-guess items)

- **Cache variant (WP-C):** the negotiated media type MUST enter the SSR surrogate/cache key and
  the response MUST carry `Vary: Accept`. Tests assert markdown is never served from an HTML entry
  and vice-versa, on both hit and miss. No shortcut.
- **Security boundary (WP-F):** allowlist (write tools absent) + capability (anon lacks write caps)
  + accessCheck(true) on every query. Tests prove rejection at each layer independently.

## Complexity Tracking

*No charter violations identified — table intentionally empty.*
