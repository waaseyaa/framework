# Data Model — Agent-Readable App (Full Acceptance Bar)

These are the new value objects / contracts introduced. No new persisted entities are
required; everything is derived from existing entity storage, config, and the entity-type
registry. Layer in brackets.

## 1. Content negotiation

### `NegotiatedMediaType` (value object) — L4 `api` or L0 `foundation/Http`
- `mediaType: string` — e.g. `text/html`, `text/markdown`.
- `quality: float` — parsed q-value.
- Produced by `MediaTypeAcceptNegotiator` (modeled on
  `packages/routing/src/Language/AcceptHeaderNegotiator.php`).

### `MediaTypeAcceptNegotiator`
- `negotiate(string $acceptHeader, list<string> $supported, string $default): string`.
- Supported set initially `['text/html', 'text/markdown']`. RFC 7231 q-value rules.
- Also resolves the `?raw`/`?format=md` query override to the same media-type result so the
  toggle and Accept path converge on one branch.

## 2. Entity → Markdown

### `EntityMarkdownPresenter` — L4 `packages/api`
- `present(EntityInterface $entity, string $viewMode, ?EntityAccessHandler $access, ?AccountInterface $account): string`.
- Reuses `ResourceSerializer` field resolution + access filtering (same internal-field and
  field-access rules — no view-only/credential leakage).
- Field rendering rules (full version):
  - scalar/text fields → markdown body (headings for label, paragraphs for long text).
  - **entity reference** fields → markdown links `[label](canonical-url)` (never inline dumps).
  - **image/media** fields → markdown image with **alt text** `![alt](url)`.
  - respects `view_mode` (full/teaser/…) for which fields/format appear.
- Output is deterministic bytes; the `?raw` toggle returns exactly this string.

### `MarkdownDocument` (optional VO)
- `frontMatter: array<string,scalar>` (title, type, canonical url, updated) + `body: string`.
- Lets llms.txt and the page share one canonical per-entity `.md` rendering.

## 3. llms.txt topic model

### `LlmsTopic` (value object) — L3 `packages/seo`
- `key: string`, `title: string`, `summary: string`, `urls: list<string>` (per-topic `.md` URLs).
- Source = either derived (one per public content entity type) or curated config override.

### `LlmsTxtGenerator` — L3 `packages/seo`
- `generate(iterable<LlmsTopic>): string` → llms.txt index format (title + per-topic sections
  each linking `.md` URLs). NOT a concatenated llms-full.txt.
- `collectTopics()` default: iterate entity types with a public canonical path; bounded member
  enumeration with `accessCheck(false)` (public crawler surface, mirrors SitemapGenerator).
- Curated override read from config key `llms.topics` (config sync).

## 4. Public MCP read-only surface

### `PublicAnonymousAuth implements McpAuthInterface` — L6 `packages/mcp`
- `authenticate(?string $authorizationHeader): ?AccountInterface`.
- Missing/empty credentials → resolves to the framework **anonymous account**
  (`AnonymousUser`, id 0). Never returns null for the public surface (no 401 for read).
- Holds only the read capability set (config).

### `ReadOnlyToolAllowlist` (filter) — L6 `packages/mcp`
- Wraps the tool bridge so `tools/list`/`tools/call` only see `destructive === false` tools on
  the allowlist. Write/destructive tools are structurally absent — not merely denied.

### Anonymous capability set (config)
- `tool.entity.read`, `tool.entity.search`, `tool.relationship.traverse`, `bimaaji.read`.

## 5. Server card config

### `McpServerCardConfig` — L6 `packages/mcp`
- `name`, `description`, `url`/`endpoint`, `version`, `authentication` (`none` for public),
  `capabilities`, plus MCP-registry fields (e.g. `name` namespace, `repository`, `version`,
  `websiteUrl`, `$schema`) — exact set verified against registry schema, else flagged.
- Drives both `McpServerCard::serve()` (`/.well-known/mcp.json`) and any registry manifest.

## 6. schema.org emission

### `EntitySchemaOrgMapper` — L3 `packages/seo` (or L6 `ssr` wiring)
- `map(EntityInterface, EntityType): array` → JSON-LD via `JsonLdBuilder`, with per-entity-type
  `@type` mapping (e.g. node→`Article`/`WebPage`, taxonomy→`Thing`/`DefinedTerm`), injected into
  SSR page `<head>` as `<script type="application/ld+json">`.

## 7. Cache variant key (correctness-critical)

### Extended SSR cache variant
- Existing variant inputs: language, view mode, workflow state, relationship-graph hash.
- **Add:** negotiated `mediaType`. Surrogate/cache key includes it; response sets `Vary: Accept`.
- Invariant (tested): an `Accept: text/markdown` request never returns a cached `text/html`
  body and vice-versa, across hit and miss.

## Relationships / flow

```
HTTP request /{path}  ──▶ MediaTypeAcceptNegotiator ──▶ media type
   (Accept / ?raw)                                   │
                                                     ├─ text/html  ─▶ existing Twig render (+ JSON-LD in <head>)
                                                     └─ text/markdown ─▶ EntityMarkdownPresenter ─▶ same bytes as ?raw
SSR cache key = (path, lang, view_mode, workflow, graph_hash, MEDIA_TYPE)

/llms.txt   ──▶ LlmsTxtGenerator(collectTopics())  ──▶ index of per-topic .md URLs
/sitemap.xml, /robots.txt  ──▶ existing seo generators (routes verified/wired)

POST /mcp (anonymous) ──▶ PublicAnonymousAuth ──▶ AnonymousUser
                       ──▶ ReadOnlyToolAllowlist (only destructive:false)
                       ──▶ per-tool requireCapability(read caps)
                       ──▶ queries setAccount(anon) + accessCheck(true)
/.well-known/mcp.json ──▶ McpServerCard(McpServerCardConfig)  [authentication: none]
```
