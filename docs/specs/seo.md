# SEO package (`waaseyaa/seo`)

<!-- Spec reviewed 2026-07-04 - audit-remediation batch R7 WP1 (entity label/title field-access channel): `EntitySchemaOrgMapper::map()` built the JSON-LD `name` from `$entity->label()` directly, bypassing field-level access. Combined with the WP04 alpha245 fix below (XSS escaping) this meant a field-access-restricted label was safely escaped but still DISCLOSED — a viewable entity (entity-level access Allowed) whose label-key field was Forbidden still exposed its real title in the JSON-LD. `map()` gained an optional trailing `?string $labelOverride = null` param; when provided it replaces `$entity->label()` for the `name` property (backward-compatible — omitting it preserves the old behavior for callers with no access context). `SsrPageHandler::buildSchemaOrgScript()` (packages/ssr, L6) now resolves the label via `Waaseyaa\Access\EntityAccessHandler::viewableLabel()` (see field-access.md) once per render and passes it as `$labelOverride`, falling back to the entity type id when the label field is Forbidden or no access handler is wired. Same fix applied at the SSR HTML `<title>` and the Markdown H1 — see api-layer.md and CHANGELOG "Security". `RenderCache::SCHEMA_VERSION` bumped v3->v4 (packages/ssr) to invalidate pre-fix cached HTML carrying a leaked title. -->
<!-- Spec reviewed 2026-07-03 - R6 PR3 (audit M3, closes the R5 residual on the SEO surface): SitemapGenerator::collectFromEntityTypes() and LlmsTxtGenerator::collectTopics() built their enumeration query with getQuery()->accessCheck(false) and gated ONLY on entity-type membership (SeoPublicController::NON_PUBLIC_TYPES) plus condition('status', 1) - never consulting an AccessPolicyInterface. A PUBLISHED entity carrying an entity-level Forbidden restriction (a classification hold via ClassificationFieldAccessPolicy, an OCAP/genealogy privacy rule via GenealogyContentAccessPolicy, etc.) still had its canonical path enumerated in /sitemap.xml and /llms.txt for any anonymous crawler. Both generators now accept an optional trailing `?AccountInterface $account` parameter and bind it via setAccount() instead of accessCheck(false) - SqlEntityQuery::execute() then runs its per-candidate EntityAccessHandler::check() pipeline (the SAME one every other read surface uses), so a Forbidden/Neutral policy result excludes the row. When $account is null the query is left at its fail-closed default (no setAccount(), no accessCheck(false) fallback) rather than reintroducing the bypass - a caller building a public surface MUST pass an account. SeoPublicController (packages/ssr, L6) now passes an explicit `Waaseyaa\User\AnonymousUser` on every call, so the public sitemap/llms.txt always reflects the anonymous-visible surface regardless of the requesting session. genealogy_person is NOT added to NON_PUBLIC_TYPES - the access-aware setAccount() enumeration already excludes a restricted genealogy_person via the real GenealogyContentAccessPolicy (unpublished tree, living-person redaction), verified by an integration test. Layering: `waaseyaa/seo` (L3) added `waaseyaa/access` (L1) as a require (downward, legal) for the `AccountInterface` type hint; `waaseyaa/ssr` (L6) added `waaseyaa/user` (L1) for `AnonymousUser`. Cache corollary: the sitemap/llms/robots routes set no cache headers (SeoPublicController returns a bare `Response` with only a Content-Type header) and there is no RenderCache path for this controller, so the fix takes effect immediately on the next request - no CDN/browser max-age to purge. -->

<!-- Spec reviewed 2026-06-23 - §2.8 residual-security-sweep (llms.txt injection): LlmsTxtGenerator::generate() emitted entity-derived title/summary/linkTitle and link URLs raw (escape() only handled [ and ]), so a newline in a title/summary forged ## sections / - link lines and a javascript:/data: URL passed through, in the agent-facing text/plain llms.txt. generate() now collapses CR/LF/control chars to spaces in all text fields (sanitizeText()) and skips a link only when its URL carries an explicit non-http(s) scheme (javascript:/data:/file:/vbscript: etc.) or contains whitespace/control chars (isSafeLinkUrl()). Relative URLs (no scheme, e.g. /articles/first.md) remain allowed - the generator is routing-agnostic and buildLink may emit site-relative paths. Public LlmsTxtGenerator signature unchanged; clean http(s) and relative-path data output byte-identical. -->

<!-- Spec reviewed 2026-06-23 - audit Medium (sitemap/llms unpublished leak): SitemapGenerator::collectFromEntityTypes() and LlmsTxtGenerator::collectTopics() enumerated entity IDs via getQuery()->accessCheck(false) with NO published filter, so /sitemap.xml and /llms.txt (anonymous-served) advertised unpublished/draft URLs to crawlers. The accessCheck(false) bypass (C-004) is retained for URL-generation performance, but both generators now add condition('status', 1) for entity types that declare a `status` field (types with no published concept are listed as-is), matching C-004's "public URLs" rationale. Public contracts (SitemapGenerator/LlmsTxtGenerator signatures) unchanged. -->
<!-- Spec reviewed 2026-06-22 - WP04 (alpha245 security): EntitySchemaOrgMapper::toScriptTag() and SeoTwigExtension::jsonLdScript() emitted JSON-LD into a <script> element with JSON_UNESCAPED_SLASHES but without JSON_HEX_TAG, so a `</script>` inside untrusted entity data (e.g. a node label rendered via SsrPageHandler) could close the element and inject markup (stored XSS). Both encoders now add JSON_HEX_TAG|JSON_HEX_AMP. Public contracts unchanged; output now hex-escapes `<`/`>`/`&`. -->
<!-- Spec reviewed 2026-05-20 - post-#1525 sweep: SitemapGenerator::collectFromEntityTypes() called getQuery() without binding an account or opting out, so /sitemap.xml threw MissingQueryAccountException under the fail-closed default introduced in v0.1.0-alpha.181. Marked as a documented system-context bypass via accessCheck(false) (entity-level access is enforced when the caller subsequently loads the entity to render its page; same shape as PathAliasResolver). Audit doc updated. Public contracts (SitemapGenerator, MetaTagBuilder, JsonLdBuilder, RobotsTxtGenerator) unchanged. -->
<!-- Spec reviewed 2026-05-19 - mission sql-entity-query-access-checking-01KRYP15 (#1495) incidental: test fixture `StubEntityQuery` (packages/seo/tests/Unit/) got the new `EntityQueryInterface::setAccount()` method to satisfy the interface contract. SEO package contracts, sitemap/meta/JSON-LD/robots generators unchanged. -->
<!-- Spec reviewed 2026-05-01 - README skeleton added under packages/seo/ (purpose, layer, key classes only); SitemapGenerator, MetaTagBuilder, JsonLdBuilder, RobotsTxtGenerator contracts unchanged from prior review (mission #824 WP09 surface F, closes #849) -->

**Audience:** framework contributors and app authors wiring sitemaps, robots, and head metadata.

## Scope

The SEO package provides **small, framework-agnostic building blocks**:

- **Sitemap:** `SitemapUrl`, `SitemapGenerator::toXml()`, and `collectFromEntityTypes()` (entity ID listing + application-provided URL callable).
- **Robots:** `RobotsTxtGenerator::toText()` (user-agent, allow/disallow lines, optional sitemap URL).
- **Meta tags:** `MetaTagBuilder::buildHeadSnippet()` (title, description, canonical).
- **JSON-LD:** `JsonLdBuilder` helpers for `WebSite`, `Organization`, and `BreadcrumbList` shapes.
- **Twig:** `SeoTwigExtension` with `seo_meta_head` and `seo_json_ld_script` (HTML-safe). JSON-LD encoders (`seo_json_ld_script`, `EntitySchemaOrgMapper::toScriptTag()`) hex-escape `<`/`>`/`&` (`JSON_HEX_TAG|JSON_HEX_AMP`) so untrusted entity data cannot break out of the `<script>` element.

There is **no HTTP response wrapper** and **no routing** dependency: applications choose routes and return `Response` bodies themselves.

## Service provider

`SeoServiceProvider` registers singletons for the generators/builders and `SeoTwigExtension`. Apps using Twig must still call `Environment::addExtension()` (or equivalent) with the resolved extension.

## Sitemap collection

`collectFromEntityTypes()` uses `EntityTypeManagerInterface::getStorage($type)->getQuery()->range(0, $limit)->execute()` to obtain IDs. URL shape, hostname, and optional `lastmod` come from the **`$buildLoc` callable**; returning `null` or `''` skips an ID.

Per-type options support optional `changefreq`, `priority` (numeric string or float), and `max` (override default cap per type).

## Related specs

- `docs/specs/entity-system.md` — entity storage/query contracts used by sitemap collection.
- `docs/specs/package-discovery.md` — `extra.waaseyaa.providers` registration.

## Change log

- **2026-04-08** — Initial package and spec (#613).

<!-- Spec reviewed 2026-05-17 - dead-code baseline reduction (#1493 / PR TBD): @api PHPDoc sweep on extension-point classes + WaaseyaaEntrypointProvider extended to recognize EntityBase/ContentEntityBase subclasses and their traits. No behavioural change. -->
