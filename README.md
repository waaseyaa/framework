# Waaseyaa

[![CI](https://github.com/waaseyaa/framework/actions/workflows/ci.yml/badge.svg)](https://github.com/waaseyaa/framework/actions/workflows/ci.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-8892BF.svg)](https://www.php.net/)

**A PHP framework for content-driven websites and applications, built around
entities, explicit access policies, and reusable Symfony components.**

Define your content model once, then compose storage, validation, APIs,
editorial workflows and presentation around it. Waaseyaa brings a Drupal-inspired
entity and field model to Composer packages without a Drupal runtime dependency.
Applications own their domain, branding and deployment.

**Status: alpha.** Contracts can change, and package convergence is ongoing.
The candidate production profile is a single application node with local SQLite.
S1 consumer certification is still pending. Start with the
[support contract](docs/specs/s1-support-lifecycle.md) and
[stability policy](docs/specs/stability-charter.md) when evaluating adoption.

[Get started](#start-an-application) · [Documentation](docs/README.md) ·
[Releases](https://github.com/waaseyaa/framework/releases) ·
[Roadmap](https://github.com/orgs/waaseyaa/projects/4) ·
[Report a bug](https://github.com/waaseyaa/framework/issues)

## What you can build

Waaseyaa is intended for websites and applications that need structured content,
application accounts, access rules and editorial workflows, with a custom user
experience. It supplies the reusable framework beneath Waaseyaa Studio and can
also be used independently of a builder or hosted service.

| Capability | Where to start |
| --- | --- |
| Content entities, typed fields and persistence | [Entity system](docs/specs/entity-system.md) |
| Entity and field access policies | [Access control](docs/specs/access-control.md) |
| Content lifecycle and editorial transitions | [Workflows](docs/specs/content-workflow.md) |
| JSON:API, with optional GraphQL integration | [JSON:API](docs/specs/jsonapi.md), [GraphQL](packages/graphql/README.md) |
| Declarative listings and server-rendered pages | [Listing recipe](docs/cookbook/listing-first-cut.md), [SSR](packages/ssr/README.md) |
| Page documents and a shared editor contract | [Page builder](docs/specs/page-builder.md) |
| Agent tools and local development integrations | [MCP](docs/specs/mcp-endpoint.md), [Bimaaji](docs/specs/bimaaji.md) |

These are composable capabilities with their own contracts and qualification
limits. A package's presence does not certify every combination or application
journey. [Package audit coverage](docs/audits/packages/coverage-index.json) tracks
assessment separately from remediation.

## Start an application

Use the application skeleton, `waaseyaa/waaseyaa`. This repository contains the
framework and its packages; it is not the application template.

You need PHP 8.5 with `pdo_sqlite`, `sqlite3` and `sodium`, Composer on the
feature line in the [support contract](docs/specs/s1-support-lifecycle.md), and
SQLite 3.40 or newer within the 3.x line. Composer checks the installed package
requirements. Node.js 24 is needed when building or testing the Admin SPA.

```bash
composer create-project waaseyaa/waaseyaa my-site --stability=dev
cd my-site
php vendor/bin/waaseyaa site:init
php vendor/bin/waaseyaa install:init
composer site-verify
composer run dev
```

Follow the initialization prompts, then open <http://127.0.0.1:8080>.
`site:init` generates the site contract; `install:init` materializes schema and
activates configuration. Verification checks the generated contract and
acceptance tests. The development command serves with FrankenPHP; its setup
and platform requirements are covered in the
[skeleton guide](skeleton/README.md#serving-with-frankenphp-composer-run-dev).

Next, read [application anatomy and ownership](skeleton/docs/application-anatomy.md)
to find where providers, routes, entities, templates and application policies
belong. The [skeleton guide](skeleton/README.md) covers the full lifecycle and
[site contract](docs/specs/site-golden-path.md) explains generated ownership.
For native Windows and Linux entrypoints, see
[native host support](docs/specs/native-host-support.md).

## Choose your package set

For an existing Composer project, the curated metapackages provide different
starting points. Follow their manifests for the exact dependency closure.

| Package | Scope |
| --- | --- |
| [`waaseyaa/core`](packages/core/composer.json) | Entity, field, storage, configuration, access and foundational services |
| [`waaseyaa/cms`](packages/cms/composer.json) | Core plus content types, page building, workflows, JSON:API, SSR and CLI |
| [`waaseyaa/full`](packages/full/composer.json) | CMS plus search, relationships, attachments, structured import and selected AI tooling |
| [`waaseyaa/ai-development`](packages/ai-development/composer.json) | Local AI development and testing tools; install under `require-dev` only |

`full` does not mean every package. Opt-in domains such as messaging and groups
are separate choices. The skeleton requires the root `waaseyaa/framework`
aggregate; its production closure also keeps opt-in domains separate. See the
[package architecture decision](docs/adr/004-framework-package-collapse.md) and
[development-plane boundary](docs/adr/022-ai-development-package-and-local-operator-trust-boundary.md).

## How the framework fits together

Packages are organized into seven layers: Foundation, Core Data, Content Types,
Services, API, AI and Interfaces. Layer checks constrain dependencies, with
explicitly tracked exceptions and existing cycles. The current
[architecture map](AGENTS.md#layer-architecture) lists ownership and enforcement.

Symfony supplies maintained infrastructure such as HTTP messages, routing,
console commands, events and validation. Waaseyaa owns its content model,
authorization policy and lifecycle contracts. The
[infrastructure reuse policy](docs/governance/agent-contract.md#maintained-infrastructure-before-custom-mechanisms)
requires evaluating Symfony before maintaining a custom equivalent.

Application behavior is composed through providers and the framework's entity
repository pipeline. Use those extension points rather than copying framework
internals into an application. Start with
[package discovery](docs/specs/package-discovery.md) and the
[extension SDK](docs/specs/external-extension-sdk.md).

Studio composes these capabilities into a builder experience. Framework remains
usable independently; [product boundaries](docs/specs/builder-product-boundaries.md)
explain that division and the acceptance work still outstanding.

## Support and upgrades

The [S1 support contract](docs/specs/s1-support-lifecycle.md) defines the candidate
production topology, tested toolchain and limits. S1 uses one application node
and one authoritative SQLite database on a local filesystem. Invalid database
DSN, URI, UNC and device paths are refused with `S1-DB001`; production also
refuses in-memory databases. See the [SQLite topology](docs/specs/s1-sqlite-topology.md).

H1 (multi-node serving), MySQL/PostgreSQL, shared/network database filesystems,
WebKit/Safari and unlisted web runtimes are unsupported. Development-runtime
availability is not production certification.

Alpha fixes ship forward in new releases. Review the [changelog](CHANGELOG.md),
[upgrade guidance](docs/upgrades/README.md) and
[stability charter](docs/specs/stability-charter.md) before changing versions.

## Contribute

Start with [AGENTS.md](AGENTS.md), the
[operating contract](docs/governance/agent-contract.md) and
[design-first workflow](docs/specs/workflow.md). Substantive changes use a portable
change record, an explicit intended contract, focused acceptance evidence and
review. Search existing issues and specs before opening overlapping work.

```bash
git clone https://github.com/waaseyaa/framework.git
cd framework
composer install
php vendor/bin/phpunit packages/entity/tests/
composer cs-check
```

That test command is a focused starting point, not whole-framework
qualification. Follow the [local testing policy](docs/local-testing-policy.md)
for checks appropriate to your change and host. Repository maintainer skills
live in [`.agents/skills/`](.agents/skills/README.md).

Report vulnerabilities through [SECURITY.md](SECURITY.md). Project stewardship
and continuity are described in [MAINTAINERS.md](MAINTAINERS.md) and
[SUCCESSION.md](SUCCESSION.md).

## License

[GPL-2.0-or-later](LICENSE).

See the [roadmap](docs/roadmap.md) for convergence priorities and planned shared
social capabilities, including posts, comments and conversations. Planned scope
is not a claim of released functionality.
