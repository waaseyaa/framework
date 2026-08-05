# waaseyaa/ai-tools

Shared agent-tool catalogue for Waaseyaa. Defines the `#[AsAgentTool]`
attribute, the `AgentTool` value object, `AgentToolInterface`, and the
attribute-discovered `AttributeToolRegistry` consumed by both
`Waaseyaa\AI\Agent\AgentExecutor` and the live
`Waaseyaa\Mcp\McpEndpoint` through `AgentToolRegistryBridge`.
Tool names are globally unique: duplicate discovered or manual registrations
raise `DuplicateToolNameException` instead of silently replacing a tool.

See `docs/specs/agent-executor.md` for the design spec and the eight
stock tools shipped in this package.

## Principal-safe content search

When the optional `waaseyaa/search` package is installed, the catalogue adds
`content.search`. The non-destructive tool returns ranked excerpts, bounded
metadata, and facets from Search's access-checked read surface. It passes the
acting `AuthorizationPrincipalInterface` unchanged, so entity access, guarded
field reads, tenant claims, and denied-result counts retain Search's fail-closed
semantics. Pagination is also bounded to a 1,000-result window so an anonymous
caller cannot amplify one rate-limited request into an unbounded offset scan.

Search is an explicit composition opt-in, not a hard dependency. The catalogue
checks only autoload availability during boot and `tools/list`; it resolves the
database-backed provider lazily on `tools/call`. An absent package means the
tool is absent. An installed but broken binding leaves the advertised tool
stable and returns a sanitized correlated `TOOL_UNAVAILABLE` error when called.
The adapter copies every result into an ai-tools-owned closed schema and rejects malformed or
oversized provider output. Audit arguments retain filters and pagination but
replace the query and free-text filter values with lengths or counts.

Titles, excerpts, URLs, and metadata are CMS-authored, untrusted data. Agent
integrators must treat returned hit text as evidence to inspect, never as
instructions that override the agent's policy or tool contract.

## Remote editorial mutations

`Content\ContentToolSet` is the canonical MCP editing surface. Applications
register one set per content bundle under stable app-owned names. Its schemas
are bundle-scoped and reject unknown fields; writes are draft-first,
idempotency-keyed, revision-aware, and optimistic-locking protected.

The stock `entity.*` mutation tools remain useful to trusted embedded agents,
but their entity-type argument makes them cross-bundle by construction. The MCP
write tier therefore withholds those generic mutations by default even when a
broad `tool.entity.*` capability is allowlisted.
