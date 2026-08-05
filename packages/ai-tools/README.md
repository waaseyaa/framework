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

## Remote editorial mutations

`Content\ContentToolSet` is the canonical MCP editing surface. Applications
register one set per content bundle under stable app-owned names. Its schemas
are bundle-scoped and reject unknown fields; writes are draft-first,
idempotency-keyed, revision-aware, and optimistic-locking protected.

The stock `entity.*` mutation tools remain useful to trusted embedded agents,
but their entity-type argument makes them cross-bundle by construction. The MCP
write tier therefore withholds those generic mutations by default even when a
broad `tool.entity.*` capability is allowlisted.
