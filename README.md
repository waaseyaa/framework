# Waaseyaa

A modern, entity-first, AI-native content management system built on PHP 8.3+ and Symfony 7.

Waaseyaa replaces Drupal's legacy runtime with a clean, modular architecture organized as independent Composer packages. Every subsystem — entities, fields, config, caching, routing, access control — is a standalone package with explicit interfaces, no global state, and no hidden coupling.

## Architecture

Waaseyaa is structured as 7 architectural layers with strict downward-only dependencies:

```
Layer 6  Interfaces     cli · ssr · admin
Layer 5  AI             ai-schema · ai-agent · ai-vector · ai-pipeline
Layer 4  API            api · graphql
Layer 3  Content Types  node · taxonomy · media · path · menu · workflows
Layer 2  Services       access · user · routing · queue · state · validation
Layer 1  Core Data      config · entity · field · entity-storage · database-legacy
Layer 0  Foundation     cache · plugin · typed-data
```

Three meta-packages provide convenient installation:

- **`waaseyaa/core`** — Foundation + Core Data + Services (14 packages)
- **`waaseyaa/cms`** — Core + Content Types + API + CLI (23 packages)
- **`waaseyaa/full`** — CMS + AI + GraphQL + SSR (29 packages)

## Packages

| Layer | Package | Description |
|-------|---------|-------------|
| 0 | `waaseyaa/cache` | Cache backends (Memory, Null) with tag-based invalidation |
| 0 | `waaseyaa/plugin` | Attribute-based plugin discovery and management |
| 0 | `waaseyaa/typed-data` | Typed data system with primitives, lists, and maps |
| 1 | `waaseyaa/config` | Configuration management with import/export and events |
| 1 | `waaseyaa/entity` | Entity type system with content and config entity bases |
| 1 | `waaseyaa/field` | Field type definitions, items, and lists |
| 1 | `waaseyaa/entity-storage` | SQL entity storage, queries, and schema management |
| 1 | `waaseyaa/database-legacy` | PDO database abstraction with query builders |
| 2 | `waaseyaa/access` | Permission-based access control with policy handlers |
| 2 | `waaseyaa/user` | User entity, authentication, and session management |
| 2 | `waaseyaa/routing` | Symfony-based routing with parameter upcasting |
| 2 | `waaseyaa/queue` | Message queue with in-memory and sync backends |
| 2 | `waaseyaa/state` | Key-value state storage |
| 2 | `waaseyaa/validation` | Constraint-based entity validation |
| 3 | `waaseyaa/node` | Node content type with access policies |
| 3 | `waaseyaa/taxonomy` | Vocabulary and term hierarchies |
| 3 | `waaseyaa/media` | Media entities with type-based handling |
| 3 | `waaseyaa/path` | URL path aliases and resolution |
| 3 | `waaseyaa/menu` | Menu links and tree building |
| 3 | `waaseyaa/workflows` | Editorial workflow state machines |
| 4 | `waaseyaa/api` | JSON:API resource layer with filtering, sorting, pagination |
| 4 | `waaseyaa/graphql` | GraphQL schema generation from entity types |
| 5 | `waaseyaa/ai-schema` | JSON Schema and MCP tool generation from entities |
| 5 | `waaseyaa/ai-agent` | AI agent orchestration with tool execution and audit logging |
| 5 | `waaseyaa/ai-vector` | Vector embedding storage and similarity search |
| 5 | `waaseyaa/ai-pipeline` | AI processing pipelines with step orchestration |
| 6 | `waaseyaa/cli` | Symfony Console commands for install, config, entities, scaffolding |
| 6 | `waaseyaa/ssr` | Twig component renderer with server-side rendering |
| 6 | `waaseyaa/admin` | React + Vite admin SPA scaffold |

## Requirements

- PHP 8.3 or later
- Composer 2.x

## Installation

```bash
composer create-project waaseyaa/waaseyaa my-site
cd my-site
```

## Running Tests

```bash
# All tests
./vendor/bin/phpunit

# Unit tests only
./vendor/bin/phpunit --testsuite Unit

# Integration tests only
./vendor/bin/phpunit --testsuite Integration
```

## Fixture Ergonomics

Waaseyaa includes deterministic fixture helpers for workflow/graph regression setup:

```bash
# Scaffold a workflow-aware fixture scenario
bin/waaseyaa fixture:scaffold \
  --key water_anchor \
  --title "Water Anchor" \
  --bundle teaching \
  --workflow-state published \
  --relationship-type related \
  --to-key river_memory \
  --output tests/fixtures/scenarios/water_anchor.json

# Refresh aggregate fixture pack + deterministic hash
bin/waaseyaa fixture:pack:refresh \
  --input-dir tests/fixtures/scenarios \
  --output tests/fixtures/scenarios/pack.json
```

For semantic index warm-ups tied to deterministic read-path validation:

```bash
bin/waaseyaa semantic:warm --type node --json
```

## v1.2 Tooling Workflows

Waaseyaa includes deterministic developer tooling added in v1.2:

```bash
# Scaffold config payloads
bin/waaseyaa scaffold:bundle --id article --label "Article"
bin/waaseyaa scaffold:relationship --id related --label "Related"
bin/waaseyaa scaffold:workflow --bundle article

# Generate deterministic fixture templates
bin/waaseyaa fixture:generate --template fanout --output tests/fixtures/scenarios/fanout.json

# Render workflow/traversal/SSR debug context
bin/waaseyaa debug:context --entity-type node --entity-id 1 --workflow-state review --relationship-counts 3:2

# Generate and compare perf baselines
bin/waaseyaa perf:baseline --snapshot-hash abc123 --threshold semantic_search:120 --threshold warm:500
bin/waaseyaa perf:compare --baseline tests/Baselines/perf_baseline.json --current tests/Baselines/perf_current.json --json
```

For consolidated upgrade and operations runbooks, see:

- `docs/specs/operations-playbooks.md`

## Project Stats

- **29** implementation packages + 3 meta-packages + 1 admin SPA
- **227** source files, ~15,000 lines of PHP
- **2,162** tests with **5,429** assertions
- **0** dependencies on Drupal core

## Key Design Principles

- **No global state.** Every service receives its dependencies through constructor injection.
- **Interface-first.** Public APIs are defined as interfaces. Implementations are swappable.
- **In-memory testable.** Every subsystem has in-memory implementations for fast, isolated testing.
- **Layered architecture.** Each layer only depends on layers below it. No circular dependencies.
- **AI-native.** Entity schemas automatically generate MCP tools, enabling AI agents to create, read, update, and query content through structured tool calls.

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
