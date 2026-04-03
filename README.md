# Waaseyaa

[![CI](https://github.com/waaseyaa/framework/actions/workflows/ci.yml/badge.svg)](https://github.com/waaseyaa/framework/actions/workflows/ci.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE.txt)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-8892BF.svg)](https://www.php.net/)

A modern, entity-first, AI-native content management framework built on PHP 8.4+ and Symfony 7.

Waaseyaa replaces Drupal's legacy runtime with a clean, modular architecture organized as independent Composer packages. Every subsystem — entities, fields, config, caching, routing, access control — is a standalone package with explicit interfaces, no global state, and no hidden coupling.

## Features

- **Entity-first architecture** — Content types, users, config, and taxonomy are all entities with a unified persistence pipeline
- **JSON:API + GraphQL** — Dual API layer auto-generated from entity type definitions
- **AI-native** — Entity schemas automatically generate MCP tools, enabling AI agents to create, query, and manage content
- **Modular monorepo** — 52 independent packages organized in 7 architectural layers
- **Nuxt 3 admin SPA** — Vue 3 + TypeScript admin interface with i18n support
- **In-memory testable** — Every subsystem has in-memory implementations for fast, isolated testing
- **Zero Drupal dependency** — Clean-room implementation inspired by Drupal's entity model, built on Symfony components

## Requirements

- PHP 8.4 or later
- Composer 2.x
- SQLite 3 (default) or MySQL/PostgreSQL via Doctrine DBAL

## Quick Start

```bash
composer create-project waaseyaa/waaseyaa my-site
cd my-site
./vendor/bin/phpunit
bin/waaseyaa serve
```

The scaffold now creates `tests/Unit` and `tests/Integration`, so the default PHPUnit command is usable immediately. For static or marketing-style sites, you can start from the clean scaffold, add a `SiteServiceProvider`, `PageController`, Twig templates, and a small regression test before wiring deploy infrastructure.

Create your first content:

```bash
curl -X POST http://localhost:8081/api/note \
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
composer phpstan     # Static analysis (level 5)
```

## Key Design Principles

- **No global state.** Every service receives its dependencies through constructor injection.
- **Interface-first.** Public APIs are defined as interfaces. Implementations are swappable.
- **In-memory testable.** Every subsystem has in-memory implementations for fast, isolated testing.
- **Layered architecture.** Each layer only depends on layers below it. No circular dependencies.
- **AI-native.** Entity schemas automatically generate MCP tools, enabling AI agents to interact with content through structured tool calls.

## Contributing

Contributions are welcome. Please open an issue to discuss proposed changes before submitting a pull request.

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
