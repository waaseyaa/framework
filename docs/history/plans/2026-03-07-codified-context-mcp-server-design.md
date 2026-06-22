# Codified Context MCP Server Design

**Date:** 2026-03-07
**Repos:** waaseyaa/minoo, waaseyaa/framework
**Milestone:** framework → v0.7, minoo → v0.3

## Problem

Both CLAUDE.md files reference MCP tools (`waaseyaa_get_spec`, `minoo_get_spec`, etc.) that do not exist. Specs are currently accessed via raw `Read` tool calls on markdown files in `docs/specs/`. This works but bypasses the codified context architecture — Claude Code cannot discover, search, or retrieve specs programmatically.

## Solution

Two independent, file-backed MCP servers — one per repo — each registered in its own `.claude/settings.json`. Each server exposes three tools pointing at its `docs/specs/` directory. No shared infrastructure, no cross-repo dependencies.

## Architecture

```
waaseyaa/framework/
  mcp/
    server.js       # waaseyaa_* tools, reads ../docs/specs/
    package.json    # { "type": "module" }, @modelcontextprotocol/sdk dep
    README.md       # setup: cd mcp && npm install
  .claude/
    settings.json   # registers "waaseyaa" MCP server

waaseyaa/minoo/
  mcp/
    server.js       # minoo_* tools, reads ../docs/specs/
    package.json
    README.md
  .claude/
    settings.json   # registers "minoo" MCP server
```

## Tool Contract

Both servers expose the same three-tool interface, namespaced to their repo.

### Framework server

| Tool | Arguments | Behaviour |
|---|---|---|
| `waaseyaa_list_specs` | — | Returns name + first-line description for every `.md` file in `docs/specs/` |
| `waaseyaa_get_spec` | `name: string` | Returns full content of `docs/specs/{name}.md`; error if not found |
| `waaseyaa_search_specs` | `query: string` | Case-insensitive keyword search across all spec files; returns file name + matching excerpt per hit |

### Minoo server

Identical interface with `minoo_` prefix.

## Implementation

Each `server.js` is a self-contained ES module using `@modelcontextprotocol/sdk` with stdio transport. It:

1. Resolves `docs/specs/` relative to the repo root (one directory up from `mcp/`)
2. On `list_specs`: reads the directory, extracts the first non-empty line of each `.md` file as description
3. On `get_spec`: reads and returns the full file content; returns a structured error if the file does not exist
4. On `search_specs`: reads all files, scans for case-insensitive query matches, returns file name + one excerpt per file (the line containing the match, trimmed)

No database, no cache, no network. All reads are synchronous-style async (`fs/promises`).

## Registration

`.claude/settings.json` (framework):

```json
{
  "mcpServers": {
    "waaseyaa": {
      "command": "node",
      "args": ["mcp/server.js"]
    }
  }
}
```

`.claude/settings.json` (minoo):

```json
{
  "mcpServers": {
    "minoo": {
      "command": "node",
      "args": ["mcp/server.js"]
    }
  }
}
```

Claude Code uses stdio transport by default. No port, no auth, no daemon.

## Setup

```bash
cd mcp/
npm install
```

Must be run once per repo before Claude Code can connect. The README documents this.

## GitHub Workflow

| Repo | Milestone | Issue title |
|---|---|---|
| waaseyaa/framework | v0.7 | feat: codified context MCP server |
| waaseyaa/minoo | v0.3 | feat: codified context MCP server |

Each issue is created before implementation begins. PRs reference the issue: `feat(#N): add codified context MCP server`.

## Out of Scope

- `/entities`, `/workflows`, `/context` namespaces — added later when they naturally emerge
- MCP Resources protocol — tools only for now
- The production `packages/mcp/` PHP server (entity CRUD over HTTP) — separate concern, separate milestone
- Authentication — stdio transport, local only

## Extension Path

When new namespaces are needed:
1. Add tools (`waaseyaa_get_entity`, etc.) to the existing `server.js`
2. Point them at the relevant directory or file set
3. No server restarts needed — Claude Code reloads tools on reconnect
