# Waaseyaa Codified Context MCP Server

Exposes Waaseyaa framework's spec documentation to Claude Code via stdio transport.

## Setup

```bash
cd mcp && npm install
```

Run once per clone before starting a Claude Code session.

## Tools

| Tool | Args | Description |
|---|---|---|
| `waaseyaa_list_specs` | — | List all specs with names and descriptions |
| `waaseyaa_get_spec` | `name: string` | Get full content of a spec (e.g. `"entity-system"`) |
| `waaseyaa_search_specs` | `query: string` | Keyword search across all specs with context |

Registered as `"waaseyaa"` in `.claude/settings.json`. Claude Code connects automatically at session start.
