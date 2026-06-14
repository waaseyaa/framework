# v0.1 Design: Framework Skeleton & Content Model

**Date:** 2026-03-03
**Status:** Approved
**Milestone:** v0.1 — Framework Identity

> Historical note: This approved design predates the current published package naming. Package and onboarding references below have been normalized to the current `waaseyaa/waaseyaa` project skeleton package for discoverability; the design intent is unchanged.

## Vision

Waaseyaa becomes a real, installable PHP CMS framework. A developer can run
`composer create-project waaseyaa/waaseyaa mysite`, boot the framework, define
custom entity types, and manage content through the admin SPA and JSON:API.

v0.1 is about framework identity. v0.2 adds public rendering (SSR). v0.3+
builds real apps (diidjaaheer).

## Architecture

### Monorepo with Automated Splitting

The project skeleton lives inside the monorepo at `skeleton/`. Automation
(GitHub Actions + `splitsh/lite`) splits each `packages/*` subdirectory and
`skeleton/` into read-only repos, tagged with unified versions. Each is
registered on Packagist.

### Kernel

Two kernel classes replace the hardcoded `index.php` and `bin/waaseyaa`:

- **`Waaseyaa\Foundation\HttpKernel`** — boots services, registers entity types
  from packages (via service providers) and app config, builds router, runs
  middleware pipeline, dispatches to controllers.
- **`Waaseyaa\Foundation\ConsoleKernel`** — shares boot sequence, dispatches to
  Symfony Console application.

Both kernels:
1. Load app config from `config/` directory
2. Boot database, event dispatcher, entity type manager
3. Run service provider `register()` then `boot()` for all packages
4. Discover middleware and access policies via `PackageManifestCompiler`

### Config-Driven Entity Types

Core entity types (user, node, taxonomy_term, etc.) are registered by their
owning package's service provider — not hardcoded in the front controller.

App-specific entity types are defined in `config/entity-types.php`:

```php
return [
    'cultural_group' => [
        'label' => 'Cultural Group',
        'class' => \App\Entity\CulturalGroup::class,
        'keys' => ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
        'fieldDefinitions' => [
            'description' => ['type' => 'text', 'label' => 'Description'],
            'parent_id' => ['type' => 'entity_reference', 'label' => 'Parent',
                            'settings' => ['target_type' => 'cultural_group']],
        ],
    ],
];
```

### Skeleton Structure

```
skeleton/
├── public/index.php       # Thin HttpKernel bootstrap
├── bin/waaseyaa           # Thin ConsoleKernel bootstrap
├── config/
│   ├── waaseyaa.php       # Database, CORS, environment
│   ├── entity-types.php   # App-specific entity type definitions
│   ├── services.php       # App-level service overrides
│   └── sync/              # Config entity YAML export directory
├── storage/               # Cache, manifests, logs
├── src/                   # App namespace (App\)
└── composer.json          # Requires waaseyaa/* packages
```

### CLI

`ConsoleKernel` powers the CLI. Key commands for v0.1:

- `waaseyaa install` — create database, run schema handlers, seed config
- `waaseyaa entity:list <type>` — list entities
- `waaseyaa entity:create <type>` — create entity interactively
- `waaseyaa cache:clear` — clear compiled manifests
- `waaseyaa optimize:manifest` — recompile package discovery cache
- `waaseyaa about` — show version, environment info

### Publishing

- GitHub Action splits monorepo on tag push
- Each `packages/*` dir → read-only repo → Packagist package
- `skeleton/` → `waaseyaa/waaseyaa` on Packagist
- Unified versioning: all packages share the same version tag

## Scope

### Bug Fixes (Prerequisites)
- **#27** — Config entity creation (machine name from label)
- **#33** — CLI namespace rename (Aurora → Waaseyaa)

### New Infrastructure
1. `HttpKernel` — configurable bootstrap replacing `index.php`
2. `ConsoleKernel` — configurable bootstrap replacing `bin/waaseyaa`
3. Service provider entity registration — packages own their entity types
4. App-level config loading — `config/*.php` files
5. Skeleton directory with thin bootstrap files

### Publishing Automation
- `splitsh/lite` GitHub Action
- Read-only repos per package
- Packagist registration

### Not in v0.1
- No SSR / public frontend / theming (v0.2)
- No `waaseyaa new` CLI installer (use `composer create-project`)
- No dynamic field management in admin UI
- No migration system (schema handlers auto-create)

## Success Criteria

1. `composer create-project waaseyaa/waaseyaa mysite` works
2. `cd mysite && bin/waaseyaa install` creates database and tables
3. Admin SPA shows all entity types, supports CRUD
4. JSON:API endpoints return correct responses
5. App can define custom entity types in `config/entity-types.php`
6. Project is committable to Git and deployable

## Future Milestones

- **v0.2** — Native SSR engine: routing, templates, components, field
  formatters, view modes, hydration islands
- **v0.3+** — diidjaaheer rebuild on the SSR engine
