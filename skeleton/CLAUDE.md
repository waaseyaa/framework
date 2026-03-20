# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this application.

## Overview

<!-- Replace with your app description -->
A Waaseyaa application built on the [Waaseyaa framework](https://github.com/waaseyaa/framework).

## Architecture

```
src/
├── Access/        Authorization policies
├── Controller/    HTTP controllers (thin orchestration)
├── Domain/        Domain logic grouped by bounded context
├── Entity/        Entity classes (extend ContentEntityBase)
├── Provider/      Service providers (DI, routing, entity registration)
└── Support/       Cross-cutting utilities
```

### Key Patterns

- **Entities** extend `ContentEntityBase` and register via `EntityTypeManager`
- **Persistence** uses `EntityRepository` + `SqlStorageDriver` (see `.claude/rules/waaseyaa-framework.md`)
- **Routes** defined in `ServiceProvider::routes()` via `WaaseyaaRouter`
- **Auth** via `Waaseyaa\Auth\AuthManager` (session-based)
- **Config** via `config/waaseyaa.php` — use `getenv()` or `env()` helper, NEVER `$_ENV`

## Orchestration Table

<!-- Map file patterns to skills and specs as you add them -->
| File Pattern | Skill | Spec |
|-------------|-------|------|
| `src/Entity/**` | `waaseyaa:entity-system` | entity-system.md |
| `src/Access/**` | `waaseyaa:access-control` | access-control.md |
| `src/Provider/**` | `feature-dev` | — |
| `.claude/rules/**` | `updating-codified-context` | — |
| `docs/specs/**` | `updating-codified-context` | — |

<!-- Note: waaseyaa:* skills are placeholders. They will not function
     until the skills are built. The entries document intended routing. -->

## MCP Federation

Register Waaseyaa's MCP server in `.claude/settings.json` for on-demand framework specs:

```json
{
  "mcpServers": {
    "waaseyaa": {
      "command": "node",
      "args": ["vendor/waaseyaa/mcp/server.js"],
      "cwd": "."
    }
  }
}
```

## Development

```bash
composer install                    # Install dependencies
php -S localhost:8080 -t public     # Dev server
./vendor/bin/phpunit                # Run tests
bin/waaseyaa                        # CLI
bin/waaseyaa sync-rules             # Update framework rules from Waaseyaa
```

## Codified Context

This app uses a three-tier codified context system inherited from Waaseyaa:

| Tier | Location | Purpose |
|------|----------|---------|
| **Constitution** | `CLAUDE.md` (this file) | Architecture, conventions, orchestration |
| **Rules** | `.claude/rules/waaseyaa-*.md` | Framework invariants (always active, never cited) |
| **Specs** | `docs/specs/*.md` | Domain contracts for each subsystem |

Framework rules are owned by Waaseyaa. Update them via `bin/waaseyaa sync-rules` after `composer update`.

When modifying a subsystem, update its spec in the same PR.

## Known Gaps

<!-- Track technical debt and migration items here -->

## Gotchas

- **Never use `$_ENV`** — Waaseyaa's `EnvLoader` only populates `putenv()`/`getenv()`. Use `getenv()` or the `env()` helper.
- **SQLite write access** — Both the `.sqlite` file AND its parent directory need write permissions for WAL/journal files.
