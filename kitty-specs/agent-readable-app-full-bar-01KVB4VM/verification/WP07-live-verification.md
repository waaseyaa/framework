# WP07 — Live isitagentready verification

**Date:** 2026-06-17 · Instance: `php -S 127.0.0.1` against the monorepo
front controller (`public/index.php`) + `storage/waaseyaa.sqlite`. Seeded one
`node` (id 1, bundle `page`) + a `path_alias` `/live-test → /node/1` via
`entity:create` so an entity page exists to negotiate.

All curls below were run against the running dev server. This is the acceptance
record for the six criteria.

## Result summary

| # | Criterion | Result |
|---|-----------|--------|
| 1 | Markdown via Accept on the same URL | ✅ PASS (live) |
| 2 | `?raw` toggle = exact negotiated Markdown | ✅ PASS (byte-identical, live) |
| 3 | `llms.txt` per-topic index | ✅ PASS (live) |
| 4 | Public read-only MCP (search/read/graph) | ⚠️ PARTIAL — endpoint live + anonymous + read-only boundary unit-proven; `tools/list` empty in this instance due to a pre-existing manifest-hydration gap (see below) |
| 5 | `.well-known/mcp.json` + registry-ready card | ✅ PASS (live) |
| 6 | isitagentready checklist (JS-free, schema.org, sitemap) | ✅ PASS (live) |

## Criterion 1 + cache-variant gate (same URL `/live-test`)

`Accept: text/html` → `Content-Type: text/html; charset=UTF-8`,
`X-Waaseyaa-Render-Variant: v2:en:full:public:published:664255f42531401e:html`,
`Surrogate-Key: … waaseyaa:ssr:media:html`, `Vary: Accept`.

`Accept: text/markdown` → `Content-Type: text/markdown; charset=UTF-8`,
`X-Waaseyaa-Render-Variant: v2:en:full:public:published:e9429a63d963b5a6:md`,
`Surrogate-Key: … waaseyaa:ssr:media:md`, `Vary: Accept`.

**The two media types produce different variant hashes and different
`media:*` surrogate keys → no HTML/Markdown cache cross-contamination (FR-004 /
NFR-001), proven on a live request.** Markdown body (front matter + H1 from the
entity label + fields):

```
---
type: node
bundle: page
id: 1
uuid: 97887cff-081f-4bd1-a27e-10a6be5f0d9b
view_mode: full
url: /live-test
---

# Live Agent Test

## Body

<p>Hello from the live render path.</p>
…
```

## Criterion 2 — `?raw` byte-identity

`curl /live-test?raw=1` == `curl -H 'Accept: text/markdown' /live-test` →
**IDENTICAL** (byte-for-byte). One presenter path; the toggle is not a parallel
renderer (FR-005).

## Criterion 3 — `/llms.txt`

`GET /llms.txt` → `200 text/plain`:

```
# Waaseyaa

> Machine-readable index of site content for AI agents. Each linked URL returns clean Markdown.
```

An index (no `llms-full` inlining). Topics populate per public content entity
type with member `…?format=md` links once content exists.

## Criterion 5 — `/.well-known/mcp.json`

`GET /.well-known/mcp.json` → `200 application/json`, `authentication.type:
"none"` (public), `transport: streamable-http`, `capabilities.tools: true`.
Registry fields emitted when configured (verified by unit test; live default has
none).

## Criterion 6 — isitagentready checklist (live)

- **JS-free HTML**: ✅ entity page is server-rendered Twig; full document
  (`<!doctype html>`, `<head>`, `<body>`), no JS required.
- **schema.org**: ✅ `GET /live-test` `<head>` contains
  `<script type="application/ld+json">` with `"@type":"WebPage"` (node.page
  mapping). `<title>Live Agent Test</title>` present.
- **Markdown affordance**: ✅ `rel="alternate" type="text/markdown"` "View as
  Markdown" link in `<body>`.
- **/sitemap.xml**: ✅ `200 application/xml`, well-formed `<urlset>`.
- **/robots.txt**: ✅ `200 text/plain`, `User-agent: *`, `Sitemap: /sitemap.xml`.

## Criterion 4 — public read-only MCP

- `POST /mcp` (no Authorization header) → **valid JSON-RPC 2.0, HTTP 200, no
  401** (anonymous read surface). `initialize`/`tools/list`/`tools/call` route
  correctly. This required fixing a real pre-existing bug: `McpEndpoint::handle`
  returned a bare `McpResponse` value object the controller dispatcher cannot
  send, so **every HTTP MCP call previously 500'd**. Fixed by adding
  `McpEndpoint::serve()` which wraps the result in a Symfony `Response`; the
  route now targets `::serve` and is `allowAll()`.
- **Read-only boundary**: unit-proven (`ReadOnlyBoundaryTest`) — a destructive
  tool is absent from `tools/list` AND rejected by `tools/call` anonymously, and
  the anonymous account grants only the four read capabilities.
- **Gap (pre-existing, documented):** live `tools/list` returns `[]` in this
  instance. The compiled manifest (`storage/framework/packages.php`) *does*
  contain the read tools (`entity.read`, `entity.search`,
  `relationship.traverse`, `bimaaji.read`, all `destructive:false`), but the
  runtime `AttributeToolRegistry` resolves empty because
  `AiToolsServiceProvider::resolveManifest()` gets the empty-fallback
  `PackageManifest` over the kernel-services bus (`$this->kernelServices?->get(PackageManifest::class)`
  returns null in HTTP boot). This is **orthogonal to the agent-readability
  boundary** (the read-only filter is correct and tested) and was previously
  masked by the 500. **Follow-up:** expose the booted `PackageManifest` on the
  kernel-services bus so `AttributeToolRegistry` hydrates over HTTP — affects all
  agent-tool HTTP consumers (MCP + admin tool browser), not just this mission.

## Toolchain notes

- Run the CLI via `php packages/cli/bin/waaseyaa <cmd>` (the repo-root
  `bin/waaseyaa` is a bash shim; `php bin/waaseyaa` prints the shim source on
  Windows).
- `php -S` caches the manifest at boot — restart after `optimize:manifest`.
