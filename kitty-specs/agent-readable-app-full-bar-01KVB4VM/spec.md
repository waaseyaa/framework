# Feature Specification: Agent-Readable App (Full Acceptance Bar)

**Mission:** `agent-readable-app-full-bar-01KVB4VM`
**Type:** software-dev · **Target branch:** main · **Created:** 2026-06-17

## Summary

Make a stock Waaseyaa application "agent-readable" — directly consumable by AI agents and
LLM crawlers — to a full, externally-defined acceptance bar, shipped **in framework core and
enabled by default** so that a fresh `composer create-project` app has all of it out of the box.
Agent-readability means: the same content URL serves clean markdown to agents and HTML to
browsers; an indexed `llms.txt` advertises per-topic markdown; a public read-only MCP server lets
agents query content, search, and the site graph; the server is discoverable and registry-ready;
and the site passes the public isitagentready.com checklist (JS-free HTML, schema.org, sitemap).

## Actors

- **AI agent / LLM crawler** — fetches content as markdown via HTTP, queries the public MCP
  server, reads `llms.txt`, consumes schema.org/sitemap. Anonymous (unauthenticated).
- **Human visitor** — browses the same URLs and receives rendered HTML, including a visible
  affordance to view the raw markdown an agent would receive.
- **Site operator** — installs a stock app and gets agent-readability by default; may curate
  llms.txt topics and the server card via configuration.

## User Scenarios & Testing

1. **Markdown via the same URL.** An agent issues `GET /{path}` with `Accept: text/markdown`
   for a published entity page and receives clean markdown (not HTML); a browser issuing the
   same `GET` with `Accept: text/html` receives the existing rendered page. The response varies
   on `Accept` and is cached without cross-contamination between formats.
2. **Raw toggle.** A human opens a content page and clicks a visible "view as markdown" control;
   the bytes returned are byte-identical to what the agent receives via content negotiation.
3. **llms.txt discovery.** An agent fetches `/llms.txt` and finds an index of topics, each
   pointing to per-topic markdown URLs (not one giant concatenated file), and can follow those
   URLs to retrieve the markdown.
4. **Public MCP read.** An agent connects to the MCP server with no credentials, lists tools,
   and successfully calls entity-read, content/spec-search, and graph-traverse tools against
   public content.
5. **Public MCP write is impossible.** The same anonymous agent cannot see or invoke any
   write/destructive tool; an attempt is rejected, and the tool is absent from the tool list.
6. **Discovery + registry.** An agent fetches `/.well-known/mcp.json` and gets a server card
   with configurable identity and the declared public auth mode, containing the fields required
   to list the server in an MCP registry.
7. **isitagentready pass.** Running the isitagentready.com checklist against a live stock
   instance passes every check, or each non-applicable check has a documented concrete reason;
   rendered pages are JS-free HTML and include schema.org JSON-LD; `/sitemap.xml` and
   `/robots.txt` are publicly reachable.

### Edge cases

- Unpublished/draft entity requested as markdown → same visibility rules as HTML (not served to
  anonymous).
- Field/entity access restrictions → markdown output filters restricted fields identically to
  the JSON:API serializer (no leakage of internal/credential/view-only fields).
- Entity reference and image fields → rendered as markdown links and alt-texted images, never
  raw dumps.
- `Accept` with multiple media types and q-values → highest acceptable supported type wins;
  unknown/`*/*` falls back to HTML.
- MCP query over restricted content as anonymous → access-checked away (empty/forbidden), never
  bypassed.

## Requirements

### Functional (FR)

| ID | Requirement | Status |
|----|-------------|--------|
| FR-001 | Every published entity page MUST serve clean markdown when the request negotiates `text/markdown`, on the same URL as the HTML page (no separate API path). | Proposed |
| FR-002 | Markdown serialization MUST reuse the existing field-resolution and access-filtering rules so restricted/internal/credential fields never appear. | Proposed |
| FR-003 | Markdown MUST render entity references as links, images as alt-texted markdown images, and respect view modes. | Proposed |
| FR-004 | Responses MUST set `Vary: Accept`; the SSR cache MUST key on the negotiated media type so HTML and markdown never cross-contaminate. | Proposed |
| FR-005 | Every page MUST expose a visible raw/markdown toggle returning bytes identical to the negotiated markdown, via the same presenter (no parallel path). | Proposed |
| FR-006 | The app MUST serve `/llms.txt` publicly as an index of per-topic markdown URLs (not a single concatenated file). | Proposed |
| FR-007 | The llms.txt topic set MUST be derived by default from public content groupings and MUST be overridable by curated configuration. | Proposed |
| FR-008 | The MCP server MUST accept anonymous (unauthenticated) requests and resolve them to the anonymous account for read operations. | Proposed |
| FR-009 | The public MCP surface MUST expose entity-read, content/spec-search, and graph-traverse tools. | Proposed |
| FR-010 | The public MCP surface MUST make write/destructive tools structurally unreachable: absent from `tools/list` and rejected on `tools/call`. | Proposed |
| FR-011 | Read-only enforcement MUST hold at three independent layers: tool allowlist, per-tool capability grant, and access-checked queries. | Proposed |
| FR-012 | `/.well-known/mcp.json` MUST expose a server card with configurable name/description/URL/auth, declaring the public auth mode. | Proposed |
| FR-013 | The server card MUST include the fields required for an MCP-registry listing (verified against the registry schema, or implemented fields listed and flagged if the schema is unreachable). | Proposed |
| FR-014 | Rendered HTML pages MUST include schema.org JSON-LD in `<head>`, mapped per entity type. | Proposed |
| FR-015 | `/sitemap.xml` and `/robots.txt` MUST be publicly reachable. | Proposed |
| FR-016 | The full isitagentready.com checklist MUST pass on a live stock instance, or each failing/non-applicable check MUST have a documented concrete reason. | Proposed |
| FR-017 | All of the above MUST be present and enabled by default in a stock `composer create-project` app (default skeleton + default metapackage tier). | Proposed |

### Non-Functional (NFR)

| ID | Requirement | Status |
|----|-------------|--------|
| NFR-001 | Markdown and HTML for the same resource MUST be served from independent cache variants; a regression test MUST prove no cross-contamination on hit and miss. | Proposed |
| NFR-002 | The public MCP read path MUST add zero write capability; automated tests MUST prove an anonymous call to a destructive tool is rejected at each of the three layers. | Proposed |
| NFR-003 | Every query on a public surface MUST use `accessCheck(true)` with the anonymous account; none may use `accessCheck(false)` except documented crawler-inventory generators (sitemap/llms collection). | Proposed |
| NFR-004 | Rendered public pages MUST be functional with JavaScript disabled (server-rendered HTML). | Proposed |
| NFR-005 | New layer dependencies MUST satisfy `bin/check-package-layers`; router additions MUST NOT add new `KERNEL_EXEMPT_FILES` entries — relocate to the owning layer instead. | Proposed |

### Constraints (C)

| ID | Constraint | Status |
|----|------------|--------|
| C-001 | No backward-compatibility shims, deprecation layers, or migration paths (no deployed downstream apps). Prefer correct clean architecture. | Accepted |
| C-002 | Pull in a maintained CommonMark library where markdown↔HTML conversion/sanitisation is needed. | Accepted |
| C-003 | The test suite is Linux-first and split (Unit/Integration); new tests MUST respect that contract. | Accepted |
| C-004 | The security boundary (FR-010/011, NFR-002) and the cache-variant keying (FR-004/NFR-001) MUST be provably correct — no guessing. | Accepted |
| C-005 | Package/metapackage arrangement is delegated to implementation: choose the cleanest tiering and document it. | Accepted |

## Success Criteria

- SC-001: A single content URL returns clean markdown to an `Accept: text/markdown` client and
  HTML to a browser, verified by automated test and live curl.
- SC-002: The markdown a browser sees via the raw toggle is byte-identical to the negotiated
  markdown, verified by test.
- SC-003: `/llms.txt` returns a topic index whose links resolve to per-topic markdown, verified
  by live curl.
- SC-004: An anonymous MCP client can read content/search/traverse and provably cannot reach any
  write tool, verified by automated security tests.
- SC-005: `/.well-known/mcp.json` returns a configurable, registry-ready card declaring public
  auth, verified by test.
- SC-006: A live stock instance passes the isitagentready.com checklist (or documents each
  exception), with schema.org JSON-LD present in `<head>` and `/sitemap.xml` + `/robots.txt`
  reachable — verified by live curl output captured in the mission record.
- SC-007: A freshly created stock app exhibits SC-001..SC-006 with no extra configuration.

## Key Entities

No new persisted entities. New value objects/contracts (see `data-model.md`): media-type
negotiator, `EntityMarkdownPresenter`, `LlmsTopic`/`LlmsTxtGenerator`, public anonymous MCP auth,
read-only tool allowlist, configurable server card, per-entity-type schema.org mapper, extended
SSR cache variant.

## Assumptions

- A-001: "Topic" for llms.txt = a published, URL-addressable content grouping; default is one per
  public content entity type, with a curated config override. (Delegated decision; default chosen.)
- A-002: The public MCP server and the markdown/llms/schema.org mechanisms ship in the default
  content tier (metapackage `cms`) and the default skeleton, so stock apps get them. Exact
  metapackage edges finalized in `plan.md`. (Delegated decision.)
- A-003: The anonymous account holds exactly the read capabilities `tool.entity.read`,
  `tool.entity.search`, `tool.relationship.traverse`, `bimaaji.read`.
- A-004: CommonMark is needed only for raw-view HTML rendering/sanitisation; entity→markdown
  generation is direct string assembly.
- A-005: Live isitagentready verification runs against the local dev server; if the external
  service or MCP registry schema is unreachable from this environment, the implemented behavior
  is documented and the gap flagged rather than guessed.

## Scope

**In scope:** the six criteria above, in framework core, on by default; package/skeleton tiering;
runtime verification of criterion 6; security and cache-variant tests.

**Out of scope:** OAuth/authenticated MCP write surface; SSE streaming for MCP; non-content
(admin/API) page markdown; actual submission to an external MCP registry (readiness only);
app-specific content/topic curation beyond a sensible default.
