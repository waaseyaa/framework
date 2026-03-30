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
- **PSR-4 one-class-per-file** — each PHP file declares exactly one class/interface/enum. Namespace matches directory path.

### ServiceProvider DI Methods

Service providers extend `Waaseyaa\Foundation\ServiceProvider\ServiceProvider`. Register bindings in `register()`, use `boot()` for event subscribers and cache warming.

```php
// In register():
$this->singleton(MyInterface::class, fn () => new MyService($this->resolve(Dependency::class)));
$this->bind(TransientService::class, TransientService::class);  // new instance each time
$myService = $this->resolve(MyInterface::class);  // resolve a registered binding
$this->tag(MyInterface::class, 'my_tag');  // tag for grouped resolution
$this->entityType(new EntityType(...));  // register an entity type
```

**Method signatures** (from `Waaseyaa\Foundation\ServiceProvider\ServiceProvider`):

| Method | Signature | Purpose |
|--------|-----------|---------|
| `singleton()` | `protected singleton(string $abstract, string\|callable $concrete): void` | Bind as shared instance (resolved once) |
| `bind()` | `protected bind(string $abstract, string\|callable $concrete): void` | Bind as transient (new instance each call) |
| `resolve()` | `public resolve(string $abstract): mixed` | Resolve a binding (falls back to kernel resolver) |
| `tag()` | `protected tag(string $abstract, string $tag): void` | Tag a binding for grouped resolution |
| `entityType()` | `protected entityType(EntityTypeInterface $entityType): void` | Register an entity type definition |

### Key Framework Namespaces

| Interface | Full Namespace | Purpose |
|-----------|---------------|---------|
| `EntityRepositoryInterface` | `Waaseyaa\Entity\Repository\EntityRepositoryInterface` | Entity CRUD (find, findBy, save, delete, saveMany, deleteMany) |
| `AccessPolicyInterface` | `Waaseyaa\Access\AccessPolicyInterface` | Entity access control (access, createAccess, appliesTo) |
| `FieldAccessPolicyInterface` | `Waaseyaa\Access\FieldAccessPolicyInterface` | Field-level access (open-by-default, Forbidden restricts) |
| `QueueInterface` | `Waaseyaa\Queue\QueueInterface` | Dispatch messages: `dispatch(object $message): void` |
| `Job` | `Waaseyaa\Queue\Job` | Abstract queue job base class |
| `DatabaseInterface` | `Waaseyaa\Database\DatabaseInterface` | Raw SQL via Doctrine DBAL (for non-entity tables) |
| `ServiceProvider` | `Waaseyaa\Foundation\ServiceProvider\ServiceProvider` | Base class for service providers |

### Queue Job Pattern

```php
use Waaseyaa\Queue\Job;
use Waaseyaa\Queue\QueueInterface;

final class SendWelcomeEmail extends Job
{
    public int $tries = 3;        // max attempts
    public int $timeout = 30;     // seconds before timeout
    public int $retryAfter = 10;  // seconds between retries

    public function __construct(private readonly string $userId) {}

    public function handle(): void
    {
        // Job logic here
    }

    public function failed(\Throwable $e): void
    {
        // Cleanup on final failure (optional override)
    }
}

// Dispatch via QueueInterface:
$queue->dispatch(new SendWelcomeEmail($userId));
```

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
bin/waaseyaa-version                # Framework provenance (path SHA, lockfile, drift vs golden)
bin/waaseyaa-audit-site             # Mechanical convergence preflight (validate + bins + provenance)
bin/waaseyaa sync-rules             # Update framework rules from Waaseyaa
```

Set `WAASEYAA_GOLDEN_SHA` or add `.waaseyaa-golden-sha` for CI drift gates (see `docs/specs/version-provenance.md` in the framework repo).

**Per-site convergence audits:** follow [per-site-convergence-audit.md](https://github.com/waaseyaa/framework/blob/main/docs/specs/per-site-convergence-audit.md) in the Waaseyaa monorepo; record findings under `docs/audits/` per that spec.

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
