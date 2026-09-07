# Waaseyaa

[![CI](https://github.com/waaseyaa/framework/actions/workflows/ci.yml/badge.svg)](https://github.com/waaseyaa/framework/actions/workflows/ci.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE.txt)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-8892BF.svg)](https://www.php.net/)

> Governance: see [MAINTAINERS.md](MAINTAINERS.md) for the current maintainer roster and [SUCCESSION.md](SUCCESSION.md) for the framework's continuity plan across Tiers 0–4.

A modern, entity-first, AI-native content management framework built on PHP 8.5 and Symfony 7.

Waaseyaa replaces Drupal's legacy runtime with a clean, modular architecture organized as independent Composer packages. Every subsystem — entities, fields, config, caching, routing, access control — is a standalone package with explicit interfaces, no global state, and no hidden coupling.

## Features

- **Entity-first architecture** — Content types, users, config, and taxonomy are all entities with a unified persistence pipeline
- **JSON:API + GraphQL** — Dual API layer auto-generated from entity type definitions
- **AI-native** — Entity schemas automatically generate MCP tools, enabling AI agents to create, query, and manage content
- **Modular monorepo** — 62 active packages (plus 3 meta-packages: `core`, `cms`, `full`) organized in 7 architectural layers; see [CLAUDE.md](CLAUDE.md#layer-architecture) for the full layer table
- **Nuxt 3 admin SPA** — Vue 3 + TypeScript admin interface with i18n support
- **In-memory testable** — Every subsystem has in-memory implementations for fast, isolated testing
- **Zero Drupal dependency** — Clean-room implementation inspired by Drupal's entity model, built on Symfony components

## Requirements

- PHP `>=8.5 <8.6` (latest available 8.5 patch)
- `ext-sodium` (required transitively by `waaseyaa/oidc` → `lcobucci/jwt` for JWT signing)
- exact Composer 2.x feature line recorded in the S1 contract (currently 2.10)
- SQLite `>=3.40 <4` for the S1 profile

S1 is one application node with one authoritative SQLite database on a local,
non-network filesystem. An optional second SQLite file may hold only a
non-authoritative, rebuildable search projection. File connections verify WAL,
foreign keys, and a 5000 ms busy timeout. Invalid DSN, URI, UNC, and device
paths fail with `S1-DB001`; production refuses `:memory:`.

The complete boundaries are [S1 support and lifecycle](docs/specs/s1-support-lifecycle.md)
and the [S1 SQLite topology](docs/specs/s1-sqlite-topology.md). S1 consumer certification is still pending its named downstream evidence. H1,
MySQL/PostgreSQL, remote/shared filesystems, WebKit/Safari, and unlisted web
runtimes are not supported claims.

The native developer and verification entrypoints are classified in the
[native host support contract](docs/specs/native-host-support.md). It
distinguishes verified native behavior and normative targets from POSIX-only
maintainer automation, and keeps WSL/Git Bash optional rather than treating
them as native Windows proof.

## Quick Start

The `composer create-project` target below installs the published Waaseyaa project skeleton package (`waaseyaa/waaseyaa`). This repository is the `waaseyaa/framework` monorepo that supplies the underlying framework packages.

```bash
composer create-project waaseyaa/waaseyaa my-site
cd my-site
./vendor/bin/phpunit
bin/waaseyaa serve
```

The scaffold now creates `tests/Unit` and `tests/Integration`, so the default PHPUnit command is usable immediately. For static or marketing-style sites, you can start from the clean scaffold, add a `SiteServiceProvider`, `PageController`, Twig templates, and a small regression test before wiring deploy infrastructure.

Create your first content:

```bash
curl -X POST http://localhost:8080/api/note \
  -H "Content-Type: application/vnd.api+json" \
  -d '{
    "data": {
      "type": "note",
      "attributes": {
        "title": "Hello, Waaseyaa",
        "body": "My first note."
      }
    }
  }'
```

Waaseyaa ships with a built-in `core.note` content type that is always available at boot. To define custom content types, see the [`waaseyaa/node`](packages/node) package as a reference.

## Fresh App Workflow

Use this minimal sequence for a new public-facing site:

```bash
composer create-project waaseyaa/waaseyaa my-site --stability=dev
cd my-site
./vendor/bin/phpunit
php bin/waaseyaa optimize:manifest
bin/waaseyaa serve
```

When turning the scaffold into a site:

1. Add a failing integration test for your public routes and rendered HTML.
2. Register your site provider in `composer.json` under `extra.waaseyaa.providers`.
3. Add your `PageController`, `SiteServiceProvider`, shared Twig layout, and site templates.
4. Re-run PHPUnit and `php bin/waaseyaa optimize:manifest`.
5. Add repo-local deployment files (`deploy.php`, `.github/workflows/*`) only after the site passes locally.

## Architecture

Waaseyaa is structured as 7 architectural layers with strict downward-only dependencies:

```
Layer 6  Interfaces      cli, admin, admin-surface, graphql, mcp, ssr,
                         telescope, deployer, inertia
Layer 5  AI              ai-schema, ai-agent, ai-vector, ai-pipeline
Layer 4  API             api, routing
Layer 3  Services        workflows, search, notification, billing, github
Layer 2  Content Types   node, taxonomy, media, path, menu, note, relationship
Layer 1  Core Data       entity, entity-storage, access, user, config, field, auth
Layer 0  Foundation      foundation, cache, plugin, typed-data, database-legacy,
                         testing, i18n, queue, scheduler, state, validation,
                         mail, http-client, ingestion
```

Three meta-packages provide convenient installation:

| Meta-package | Includes |
|---|---|
| `waaseyaa/core` | Foundation + Core Data |
| `waaseyaa/cms` | Core + Content Types + API + CLI |
| `waaseyaa/full` | CMS + AI + GraphQL + SSR + Admin |

## Entity Persistence Pipeline

All content follows a single, consistent pipeline:

```
Entity (extends EntityBase or ContentEntityBase)
  -> EntityType registered via EntityTypeManager
  -> EntityStorageDriverInterface (SqlStorageDriver)
  -> EntityRepository (hydration, events, validation)
  -> DatabaseInterface (Doctrine DBAL)
```

## CLI

Waaseyaa includes a comprehensive CLI built on Symfony Console:

```bash
bin/waaseyaa install              # Set up database and initial config
bin/waaseyaa serve                # Start the dev server
bin/waaseyaa migrate              # Run pending migrations
bin/waaseyaa entity-type:list     # List registered entity types
bin/waaseyaa entity:create node   # Create an entity interactively
bin/waaseyaa schema:check         # Detect schema drift
bin/waaseyaa health:check         # Run diagnostic health checks
bin/waaseyaa optimize:manifest    # Rebuild attribute-discovery manifest
bin/waaseyaa config:export        # Export config to sync directory
bin/waaseyaa config:import        # Import config from sync directory
```

Code generation scaffolding:

```bash
bin/waaseyaa make:entity          # Generate a content entity class
bin/waaseyaa make:entity-type     # Generate an entity type class
bin/waaseyaa make:policy          # Generate an access policy class
bin/waaseyaa make:provider        # Generate a service provider class
bin/waaseyaa make:migration       # Generate a migration file
bin/waaseyaa make:plugin          # Generate a plugin class
bin/waaseyaa make:listener        # Generate an event listener class
bin/waaseyaa make:job             # Generate a queue job class
```

## Testing

```bash
# All tests
./vendor/bin/phpunit

# Unit tests only
./vendor/bin/phpunit --testsuite Unit

# Integration tests only
./vendor/bin/phpunit --testsuite Integration

# Single package
./vendor/bin/phpunit packages/entity/tests/

# Pattern matching
./vendor/bin/phpunit --filter EntityRepository
```

Code quality:

```bash
composer cs-check    # Check code style (PHP-CS-Fixer dry-run)
composer cs-fix      # Auto-fix code style
composer phpstan     # Static analysis (level 5, PHPStan 2)
```

## Key Design Principles

- **No global state.** Every service receives its dependencies through constructor injection.
- **Interface-first.** Public APIs are defined as interfaces. Implementations are swappable.
- **In-memory testable.** Every subsystem has in-memory implementations for fast, isolated testing.
- **Layered architecture.** Each layer only depends on layers below it. No circular dependencies.
- **AI-native.** Entity schemas automatically generate MCP tools, enabling AI agents to interact with content through structured tool calls.

## Contributing

Contributions and **AI coding agents** should follow the **[anchor-issue + design-first workflow](docs/specs/workflow.md)**: multi-PR efforts open a GitHub anchor issue, and design/specs land in `docs/specs/` before implementation. Human-oriented entry points: [`CLAUDE.md`](CLAUDE.md) and [`AGENTS.md`](AGENTS.md).

- **PRs:** Fill [`.github/pull_request_template.md`](.github/pull_request_template.md) — `Closes #N` or `Part of #N`, with `#N` in the title.
- **GitHub issues:** Lightweight — no enforced milestone or taxonomy. **M11 governed** work still uses the [governed-change template](.github/ISSUE_TEMPLATE/m11-governed-change.md) as the filing front door.

```bash
# Clone the repository
git clone https://github.com/waaseyaa/framework.git
cd framework
composer install

# Run the full test suite
./vendor/bin/phpunit

# Check code style
composer cs-check
```

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
