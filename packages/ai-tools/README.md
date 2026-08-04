# waaseyaa/ai-tools

Shared agent-tool catalogue for Waaseyaa. Defines the `#[AsAgentTool]`
attribute, the `AgentTool` value object, `AgentToolInterface`, and the
attribute-discovered `AttributeToolRegistry` consumed by both
`Waaseyaa\AI\Agent\AgentExecutor` and `Waaseyaa\Mcp\McpController`.
Tool names are globally unique: duplicate discovered or manual registrations
raise `DuplicateToolNameException` instead of silently replacing a tool.

See `docs/specs/agent-executor.md` for the design spec and the eight
stock tools shipped in this package.
