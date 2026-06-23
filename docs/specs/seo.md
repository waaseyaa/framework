# SEO package (`waaseyaa/seo`)

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
