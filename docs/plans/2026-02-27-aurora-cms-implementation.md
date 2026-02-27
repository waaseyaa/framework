# Aurora CMS v0.1.0 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Transform Drupal 11.2.10 into Aurora CMS v0.1.0 — a modular, entity-first, AI-native CMS decomposed into independent Composer packages.

**Architecture:** Modular decomposition of Drupal 11 core into layered `aurora/*` packages. Each package has a clean public API. Internal implementation wraps Drupal code initially, with facades hiding the guts. Dependencies flow strictly downward through 7 layers: Foundation → Core Data → Services → Content → API → AI → Interfaces.

**Tech Stack:** PHP 8.3+, Symfony 7.3, Twig 3, Doctrine DBAL (target, not v0.1.0), Monolog, Vite (admin SPA), React or Vue (admin SPA).

**Design doc:** `docs/plans/2026-02-27-aurora-cms-design.md`

---

## Phase Overview

| Phase | Focus | Tasks | Outcome |
|-------|-------|-------|---------|
| 1 | Monorepo scaffold | 1-5 | Package structure, Composer workspace, autoloading |
| 2 | Contract interfaces | 6-13 | Public API interfaces for all Layer 0-1 packages |
| 3 | Extract Layer 0 (Foundation) | 14-19 | aurora/cache, aurora/plugin, aurora/typed-data working standalone |
| 4 | Extract Layer 1 (Core Data) | 20-27 | aurora/config, aurora/entity, aurora/field, aurora/entity-storage |
| 5 | Extract Layer 2 (Services) | 28-33 | aurora/access, aurora/user, aurora/routing, aurora/queue, aurora/state, aurora/validation |
| 6 | Extract Layer 3 (Content) | 34-37 | aurora/node, aurora/taxonomy, aurora/media, aurora/path, aurora/menu, aurora/workflows |
| 7 | Build Layer 4 (API) | 38-41 | aurora/api (JSON:API + OpenAPI 3.1) |
| 8 | Build Layer 5 (AI) | 42-47 | aurora/ai-schema, aurora/ai-agent, aurora/ai-vector, aurora/ai-pipeline |
| 9 | Build Layer 6 (Interfaces) | 48-53 | aurora/cli, aurora/admin (SPA), aurora/ssr |
| 10 | Integration + cleanup | 54-57 | Meta-packages, removal of dead Drupal code, smoke tests |

---

## Phase 1: Monorepo Scaffold

### Task 1: Create monorepo directory structure

**Files:**
- Create: `packages/` directory tree
- Create: `packages/.gitkeep` files for empty dirs

**Step 1: Create all package directories**

```bash
# Layer 0 - Foundation
mkdir -p packages/typed-data/src packages/typed-data/tests
mkdir -p packages/plugin/src packages/plugin/tests
mkdir -p packages/cache/src packages/cache/tests

# Layer 1 - Core Data
mkdir -p packages/config/src packages/config/tests
mkdir -p packages/entity/src packages/entity/tests
mkdir -p packages/field/src packages/field/tests
mkdir -p packages/entity-storage/src packages/entity-storage/tests
mkdir -p packages/database-legacy/src packages/database-legacy/tests

# Layer 2 - Services
mkdir -p packages/access/src packages/access/tests
mkdir -p packages/user/src packages/user/tests
mkdir -p packages/routing/src packages/routing/tests
mkdir -p packages/queue/src packages/queue/tests
mkdir -p packages/state/src packages/state/tests
mkdir -p packages/validation/src packages/validation/tests

# Layer 3 - Content
mkdir -p packages/node/src packages/node/tests
mkdir -p packages/taxonomy/src packages/taxonomy/tests
mkdir -p packages/media/src packages/media/tests
mkdir -p packages/path/src packages/path/tests
mkdir -p packages/menu/src packages/menu/tests
mkdir -p packages/workflows/src packages/workflows/tests

# Layer 4 - API
mkdir -p packages/api/src packages/api/tests
mkdir -p packages/graphql/src packages/graphql/tests

# Layer 5 - AI
mkdir -p packages/ai-schema/src packages/ai-schema/tests
mkdir -p packages/ai-agent/src packages/ai-agent/tests
mkdir -p packages/ai-vector/src packages/ai-vector/tests
mkdir -p packages/ai-pipeline/src packages/ai-pipeline/tests

# Layer 6 - Interfaces
mkdir -p packages/ssr/src packages/ssr/tests
mkdir -p packages/admin  # JS project, separate structure
mkdir -p packages/cli/src packages/cli/tests

# Meta-packages
mkdir -p packages/core
mkdir -p packages/cms
mkdir -p packages/full
```

**Step 2: Verify structure**

Run: `find packages -type d | sort`
Expected: All directories listed above.

**Step 3: Commit**

```bash
git add packages/
git commit -m "scaffold: create monorepo package directory structure"
```

---

### Task 2: Create package composer.json files (Layer 0)

**Files:**
- Create: `packages/cache/composer.json`
- Create: `packages/plugin/composer.json`
- Create: `packages/typed-data/composer.json`

**Step 1: Create aurora/cache composer.json**

```json
{
    "name": "aurora/cache",
    "description": "Cache bins with cache tag invalidation for Aurora CMS",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3",
        "psr/cache": "^3.0",
        "psr/simple-cache": "^3.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Aurora\\Cache\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Aurora\\Cache\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable"
}
```

**Step 2: Create aurora/plugin composer.json**

```json
{
    "name": "aurora/plugin",
    "description": "Attribute-based plugin discovery and management for Aurora CMS",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3",
        "aurora/cache": "^0.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Aurora\\Plugin\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Aurora\\Plugin\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable"
}
```

**Step 3: Create aurora/typed-data composer.json**

```json
{
    "name": "aurora/typed-data",
    "description": "Type system with PHP-native facade for Aurora CMS",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3",
        "symfony/validator": "^7.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Aurora\\TypedData\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Aurora\\TypedData\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable"
}
```

**Step 4: Commit**

```bash
git add packages/cache/composer.json packages/plugin/composer.json packages/typed-data/composer.json
git commit -m "scaffold: add Layer 0 package composer.json files"
```

---

### Task 3: Create package composer.json files (Layer 1)

**Files:**
- Create: `packages/config/composer.json`
- Create: `packages/entity/composer.json`
- Create: `packages/field/composer.json`
- Create: `packages/entity-storage/composer.json`
- Create: `packages/database-legacy/composer.json`

**Step 1: Create aurora/config composer.json**

```json
{
    "name": "aurora/config",
    "description": "Configuration management as YAML with package-aware ownership for Aurora CMS",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3",
        "aurora/cache": "^0.1",
        "aurora/typed-data": "^0.1",
        "symfony/event-dispatcher": "^7.3",
        "symfony/yaml": "^7.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Aurora\\Config\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Aurora\\Config\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable"
}
```

**Step 2: Create aurora/entity composer.json**

```json
{
    "name": "aurora/entity",
    "description": "Entity type system — types, interfaces, lifecycle, queries. No storage.",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3",
        "aurora/typed-data": "^0.1",
        "aurora/plugin": "^0.1",
        "aurora/cache": "^0.1",
        "aurora/config": "^0.1",
        "symfony/event-dispatcher": "^7.3",
        "symfony/uid": "^7.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Aurora\\Entity\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Aurora\\Entity\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable"
}
```

**Step 3: Create aurora/field composer.json**

```json
{
    "name": "aurora/field",
    "description": "Field type system — pluggable field types, definitions, formatters for Aurora CMS",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3",
        "aurora/entity": "^0.1",
        "aurora/plugin": "^0.1",
        "aurora/typed-data": "^0.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Aurora\\Field\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Aurora\\Field\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable"
}
```

**Step 4: Create aurora/entity-storage composer.json**

```json
{
    "name": "aurora/entity-storage",
    "description": "Pluggable entity persistence. v0.1.0: SQL storage behind clean interfaces.",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3",
        "aurora/entity": "^0.1",
        "aurora/field": "^0.1",
        "aurora/cache": "^0.1",
        "aurora/database-legacy": "^0.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Aurora\\EntityStorage\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Aurora\\EntityStorage\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable"
}
```

**Step 5: Create aurora/database-legacy composer.json**

```json
{
    "name": "aurora/database-legacy",
    "description": "Database adapter wrapping Drupal DBAL. Interim until Doctrine migration.",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Aurora\\Database\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Aurora\\Database\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "stable"
}
```

**Step 6: Commit**

```bash
git add packages/config/composer.json packages/entity/composer.json packages/field/composer.json packages/entity-storage/composer.json packages/database-legacy/composer.json
git commit -m "scaffold: add Layer 1 package composer.json files"
```

---

### Task 4: Create remaining package composer.json files (Layers 2-6)

**Files:**
- Create: `packages/access/composer.json`
- Create: `packages/user/composer.json`
- Create: `packages/routing/composer.json`
- Create: `packages/queue/composer.json`
- Create: `packages/state/composer.json`
- Create: `packages/validation/composer.json`
- Create: `packages/node/composer.json`
- Create: `packages/taxonomy/composer.json`
- Create: `packages/media/composer.json`
- Create: `packages/path/composer.json`
- Create: `packages/menu/composer.json`
- Create: `packages/workflows/composer.json`
- Create: `packages/api/composer.json`
- Create: `packages/graphql/composer.json`
- Create: `packages/ai-schema/composer.json`
- Create: `packages/ai-agent/composer.json`
- Create: `packages/ai-vector/composer.json`
- Create: `packages/ai-pipeline/composer.json`
- Create: `packages/ssr/composer.json`
- Create: `packages/cli/composer.json`
- Create: `packages/core/composer.json` (meta-package)
- Create: `packages/cms/composer.json` (meta-package)
- Create: `packages/full/composer.json` (meta-package)

Each composer.json follows the same pattern as Layer 0-1 but with appropriate dependencies per the layer diagram in the design doc.

**Key dependency rules to enforce:**

| Package | Requires (aurora/*) |
|---------|-------------------|
| aurora/access | entity, routing |
| aurora/user | entity, field, access |
| aurora/routing | entity, access + symfony/routing |
| aurora/queue | + symfony/messenger |
| aurora/state | database-legacy |
| aurora/validation | typed-data + symfony/validator |
| aurora/node | entity, field, user |
| aurora/taxonomy | entity, field |
| aurora/media | entity, field |
| aurora/path | entity, routing |
| aurora/menu | entity |
| aurora/workflows | entity |
| aurora/api | entity, field, routing, access + serialization |
| aurora/graphql | entity, field, access |
| aurora/ai-schema | entity, field, config |
| aurora/ai-agent | plugin, ai-schema, entity, config, access |
| aurora/ai-vector | entity, field, plugin |
| aurora/ai-pipeline | ai-agent, entity, queue |
| aurora/ssr | entity, field, routing + twig/twig |
| aurora/cli | entity, config, field + symfony/console |
| aurora/core | (meta) cache, plugin, typed-data, config, entity, field, entity-storage, database-legacy, access, user, routing, queue, state, validation |
| aurora/cms | (meta) core + node, taxonomy, media, path, menu, workflows, api, cli |
| aurora/full | (meta) cms + ai-schema, ai-agent, ai-vector, ai-pipeline, ssr, graphql |

**Step 1: Create all Layer 2-6 composer.json files**

Follow the pattern from Tasks 2-3. Each file has: name, description, license (GPL-2.0-or-later), require block per the table above, PSR-4 autoload mapping to `Aurora\<PackageName>\`.

**Step 2: Create meta-package composer.json files**

Meta-packages have `"type": "metapackage"` and only a `require` block — no `autoload`, no `src/`.

Example for `packages/core/composer.json`:
```json
{
    "name": "aurora/core",
    "description": "Aurora CMS core engine — entities, fields, config, plugins, routing, access.",
    "type": "metapackage",
    "license": "GPL-2.0-or-later",
    "require": {
        "aurora/cache": "^0.1",
        "aurora/plugin": "^0.1",
        "aurora/typed-data": "^0.1",
        "aurora/config": "^0.1",
        "aurora/entity": "^0.1",
        "aurora/field": "^0.1",
        "aurora/entity-storage": "^0.1",
        "aurora/database-legacy": "^0.1",
        "aurora/access": "^0.1",
        "aurora/user": "^0.1",
        "aurora/routing": "^0.1",
        "aurora/queue": "^0.1",
        "aurora/state": "^0.1",
        "aurora/validation": "^0.1"
    },
    "minimum-stability": "stable"
}
```

**Step 3: Commit**

```bash
git add packages/*/composer.json
git commit -m "scaffold: add all package composer.json files with dependency graph"
```

---

### Task 5: Configure root composer.json for monorepo development

**Files:**
- Modify: `composer.json` (root)
- Create: `phpunit.xml.dist`

**Step 1: Replace root composer.json**

The root `composer.json` becomes the monorepo workspace. It uses path repositories to symlink local packages during development.

```json
{
    "name": "aurora/monorepo",
    "description": "Aurora CMS monorepo — a modern, entity-first, AI-native CMS",
    "type": "project",
    "license": "GPL-2.0-or-later",
    "repositories": [
        { "type": "path", "url": "packages/cache" },
        { "type": "path", "url": "packages/plugin" },
        { "type": "path", "url": "packages/typed-data" },
        { "type": "path", "url": "packages/config" },
        { "type": "path", "url": "packages/entity" },
        { "type": "path", "url": "packages/field" },
        { "type": "path", "url": "packages/entity-storage" },
        { "type": "path", "url": "packages/database-legacy" },
        { "type": "path", "url": "packages/access" },
        { "type": "path", "url": "packages/user" },
        { "type": "path", "url": "packages/routing" },
        { "type": "path", "url": "packages/queue" },
        { "type": "path", "url": "packages/state" },
        { "type": "path", "url": "packages/validation" },
        { "type": "path", "url": "packages/node" },
        { "type": "path", "url": "packages/taxonomy" },
        { "type": "path", "url": "packages/media" },
        { "type": "path", "url": "packages/path" },
        { "type": "path", "url": "packages/menu" },
        { "type": "path", "url": "packages/workflows" },
        { "type": "path", "url": "packages/api" },
        { "type": "path", "url": "packages/graphql" },
        { "type": "path", "url": "packages/ai-schema" },
        { "type": "path", "url": "packages/ai-agent" },
        { "type": "path", "url": "packages/ai-vector" },
        { "type": "path", "url": "packages/ai-pipeline" },
        { "type": "path", "url": "packages/ssr" },
        { "type": "path", "url": "packages/cli" },
        { "type": "path", "url": "packages/core" },
        { "type": "path", "url": "packages/cms" },
        { "type": "path", "url": "packages/full" }
    ],
    "require": {
        "php": ">=8.3",
        "aurora/full": "^0.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5",
        "phpstan/phpstan": "^1.10"
    },
    "minimum-stability": "stable",
    "prefer-stable": true,
    "config": {
        "sort-packages": true
    }
}
```

**Step 2: Create root phpunit.xml.dist**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         executionOrder="depends,defects"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>packages/*/tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>packages/*/tests/Integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>packages/*/src</directory>
        </include>
    </source>
</phpunit>
```

**Step 3: Commit**

```bash
git add composer.json phpunit.xml.dist
git commit -m "scaffold: configure monorepo root with path repositories and PHPUnit"
```

> **Note:** Do NOT run `composer install` yet. The packages have no `src/` code, so dependency resolution will fail. We'll install after Phase 2 when interfaces exist.

---

## Phase 2: Contract Interfaces

Define the public API for every Layer 0-1 package. These are the interfaces that consuming code depends on. Getting these right means internals can change without breaking anything.

### Task 6: Define aurora/cache contracts

**Files:**
- Create: `packages/cache/src/CacheBackendInterface.php`
- Create: `packages/cache/src/CacheTagsInvalidatorInterface.php`
- Create: `packages/cache/src/CacheItem.php`
- Create: `packages/cache/src/CacheFactoryInterface.php`
- Test: `packages/cache/tests/Unit/CacheItemTest.php`

**Step 1: Write CacheBackendInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Cache;

/**
 * Defines the interface for a cache backend.
 *
 * Cache backends store and retrieve cached data organized in bins.
 * Each bin is a separate namespace (e.g., 'entity', 'config', 'render').
 * All items support cache tags for targeted invalidation.
 */
interface CacheBackendInterface
{
    public const PERMANENT = -1;

    public function get(string $cid): CacheItem|false;

    /** @return array<string, CacheItem> */
    public function getMultiple(array &$cids): array;

    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void;

    public function delete(string $cid): void;

    public function deleteMultiple(array $cids): void;

    public function deleteAll(): void;

    public function invalidate(string $cid): void;

    public function invalidateMultiple(array $cids): void;

    public function invalidateAll(): void;

    public function removeBin(): void;
}
```

**Step 2: Write CacheTagsInvalidatorInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Cache;

/**
 * Invalidates cache items by tag across all bins.
 *
 * Cache tags enable precise invalidation: when entity 42 changes,
 * invalidate tag "entity:42" and all cached items tagged with it
 * are invalidated across every cache bin.
 */
interface CacheTagsInvalidatorInterface
{
    /** @param string[] $tags */
    public function invalidateTags(array $tags): void;
}
```

**Step 3: Write CacheItem value object**

```php
<?php

declare(strict_types=1);

namespace Aurora\Cache;

final readonly class CacheItem
{
    public function __construct(
        public string $cid,
        public mixed $data,
        public int $created,
        public int $expire = CacheBackendInterface::PERMANENT,
        public array $tags = [],
        public bool $valid = true,
    ) {}
}
```

**Step 4: Write CacheFactoryInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Cache;

interface CacheFactoryInterface
{
    public function get(string $bin): CacheBackendInterface;
}
```

**Step 5: Write test for CacheItem**

```php
<?php

declare(strict_types=1);

namespace Aurora\Cache\Tests\Unit;

use Aurora\Cache\CacheBackendInterface;
use Aurora\Cache\CacheItem;
use PHPUnit\Framework\TestCase;

final class CacheItemTest extends TestCase
{
    public function testConstructWithDefaults(): void
    {
        $item = new CacheItem(
            cid: 'test:1',
            data: ['key' => 'value'],
            created: time(),
        );

        $this->assertSame('test:1', $item->cid);
        $this->assertSame(['key' => 'value'], $item->data);
        $this->assertSame(CacheBackendInterface::PERMANENT, $item->expire);
        $this->assertSame([], $item->tags);
        $this->assertTrue($item->valid);
    }

    public function testConstructWithTags(): void
    {
        $item = new CacheItem(
            cid: 'entity:42',
            data: 'cached',
            created: time(),
            tags: ['entity:42', 'entity_list:node'],
        );

        $this->assertSame(['entity:42', 'entity_list:node'], $item->tags);
    }
}
```

**Step 6: Run test (will fail — no autoloader yet, that's expected)**

At this stage we're writing contracts. Tests verify the value objects are correct. We'll run them after Phase 2 when Composer can resolve all packages.

**Step 7: Commit**

```bash
git add packages/cache/src/ packages/cache/tests/
git commit -m "contracts: define aurora/cache public interfaces"
```

---

### Task 7: Define aurora/plugin contracts

**Files:**
- Create: `packages/plugin/src/PluginManagerInterface.php`
- Create: `packages/plugin/src/PluginInspectionInterface.php`
- Create: `packages/plugin/src/Definition/PluginDefinition.php`
- Create: `packages/plugin/src/Discovery/PluginDiscoveryInterface.php`
- Create: `packages/plugin/src/Factory/PluginFactoryInterface.php`
- Create: `packages/plugin/src/Attribute/AuroraPlugin.php`

**Step 1: Write PluginManagerInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Plugin;

/**
 * Manages plugins of a specific type.
 *
 * Plugin managers handle discovery, instantiation, and caching
 * of plugins. Each plugin type (field types, access policies,
 * AI agents, etc.) has its own manager.
 */
interface PluginManagerInterface
{
    public function getDefinition(string $pluginId): Definition\PluginDefinition;

    /** @return array<string, Definition\PluginDefinition> */
    public function getDefinitions(): array;

    public function hasDefinition(string $pluginId): bool;

    public function createInstance(string $pluginId, array $configuration = []): PluginInspectionInterface;
}
```

**Step 2: Write PluginInspectionInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Plugin;

interface PluginInspectionInterface
{
    public function getPluginId(): string;

    public function getPluginDefinition(): Definition\PluginDefinition;
}
```

**Step 3: Write PluginDefinition value object**

```php
<?php

declare(strict_types=1);

namespace Aurora\Plugin\Definition;

final readonly class PluginDefinition
{
    public function __construct(
        public string $id,
        public string $label,
        public string $class,
        public string $description = '',
        public string $package = '',
        public array $metadata = [],
    ) {}
}
```

**Step 4: Write AuroraPlugin attribute**

```php
<?php

declare(strict_types=1);

namespace Aurora\Plugin\Attribute;

/**
 * Base attribute for Aurora plugin discovery.
 *
 * All Aurora plugin types extend this attribute with
 * type-specific properties. The plugin manager discovers
 * classes annotated with the relevant attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class AuroraPlugin
{
    public function __construct(
        public readonly string $id,
        public readonly string $label = '',
        public readonly string $description = '',
        public readonly string $package = '',
    ) {}
}
```

**Step 5: Write PluginDiscoveryInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Plugin\Discovery;

use Aurora\Plugin\Definition\PluginDefinition;

interface PluginDiscoveryInterface
{
    /** @return array<string, PluginDefinition> */
    public function getDefinitions(): array;
}
```

**Step 6: Write PluginFactoryInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Plugin\Factory;

use Aurora\Plugin\PluginInspectionInterface;

interface PluginFactoryInterface
{
    public function createInstance(string $pluginId, array $configuration = []): PluginInspectionInterface;
}
```

**Step 7: Commit**

```bash
git add packages/plugin/src/
git commit -m "contracts: define aurora/plugin public interfaces and AuroraPlugin attribute"
```

---

### Task 8: Define aurora/typed-data contracts

**Files:**
- Create: `packages/typed-data/src/TypedDataManagerInterface.php`
- Create: `packages/typed-data/src/TypedDataInterface.php`
- Create: `packages/typed-data/src/DataDefinitionInterface.php`
- Create: `packages/typed-data/src/ComplexDataInterface.php`
- Create: `packages/typed-data/src/ListInterface.php`
- Create: `packages/typed-data/src/PrimitiveInterface.php`

**Step 1: Write TypedDataInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\TypedData;

/**
 * Base interface for all typed data.
 *
 * TypedData wraps values with type information and validation.
 * In v0.1.0 this wraps Drupal's TypedData internally. The public
 * API is designed so that we can refactor toward PHP-native typing
 * (enums, readonly properties, union types) without breaking callers.
 */
interface TypedDataInterface
{
    public function getValue(): mixed;

    public function setValue(mixed $value): void;

    public function getDataDefinition(): DataDefinitionInterface;

    public function validate(): \Symfony\Component\Validator\ConstraintViolationListInterface;

    public function getString(): string;
}
```

**Step 2: Write DataDefinitionInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\TypedData;

interface DataDefinitionInterface
{
    public function getDataType(): string;

    public function getLabel(): string;

    public function getDescription(): string;

    public function isRequired(): bool;

    public function isReadOnly(): bool;

    public function isList(): bool;

    /** @return \Symfony\Component\Validator\Constraint[] */
    public function getConstraints(): array;
}
```

**Step 3: Write ComplexDataInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\TypedData;

/**
 * For typed data with named properties (entities, field items).
 */
interface ComplexDataInterface extends TypedDataInterface, \Traversable
{
    public function get(string $name): TypedDataInterface;

    public function set(string $name, mixed $value): static;

    /** @return array<string, DataDefinitionInterface> */
    public function getProperties(): array;

    public function toArray(): array;
}
```

**Step 4: Write ListInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\TypedData;

/**
 * For ordered lists of typed data (multi-value fields).
 */
interface ListInterface extends TypedDataInterface, \Countable, \Traversable
{
    public function get(int $index): TypedDataInterface;

    public function set(int $index, mixed $value): void;

    public function first(): ?TypedDataInterface;

    public function isEmpty(): bool;

    public function appendItem(mixed $value = null): TypedDataInterface;

    public function removeItem(int $index): void;
}
```

**Step 5: Write PrimitiveInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\TypedData;

interface PrimitiveInterface extends TypedDataInterface
{
    public function getCastedValue(): string|int|float|bool|null;
}
```

**Step 6: Write TypedDataManagerInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\TypedData;

interface TypedDataManagerInterface
{
    public function createDataDefinition(string $dataType): DataDefinitionInterface;

    public function create(DataDefinitionInterface $definition, mixed $value = null): TypedDataInterface;

    public function createInstance(string $dataType, array $configuration = []): TypedDataInterface;

    /** @return array<string, DataDefinitionInterface> All registered data type definitions */
    public function getDefinitions(): array;
}
```

**Step 7: Commit**

```bash
git add packages/typed-data/src/
git commit -m "contracts: define aurora/typed-data public interfaces"
```

---

### Task 9: Define aurora/entity contracts (THE CRITICAL ONE)

**Files:**
- Create: `packages/entity/src/EntityInterface.php`
- Create: `packages/entity/src/ContentEntityInterface.php`
- Create: `packages/entity/src/ConfigEntityInterface.php`
- Create: `packages/entity/src/FieldableInterface.php`
- Create: `packages/entity/src/RevisionableInterface.php`
- Create: `packages/entity/src/TranslatableInterface.php`
- Create: `packages/entity/src/EntityTypeInterface.php`
- Create: `packages/entity/src/EntityTypeManagerInterface.php`
- Create: `packages/entity/src/Storage/EntityStorageInterface.php`
- Create: `packages/entity/src/Storage/RevisionableStorageInterface.php`
- Create: `packages/entity/src/Storage/EntityQueryInterface.php`
- Create: `packages/entity/src/Event/EntityEvents.php`
- Create: `packages/entity/src/Event/EntityEvent.php`

**Step 1: Write EntityInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity;

interface EntityInterface
{
    public function id(): int|string|null;

    public function uuid(): string;

    public function label(): string;

    public function getEntityTypeId(): string;

    public function bundle(): string;

    public function isNew(): bool;

    public function toArray(): array;

    public function language(): string;
}
```

**Step 2: Write FieldableInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity;

use Aurora\TypedData\ListInterface as FieldItemListInterface;

interface FieldableInterface
{
    public function hasField(string $name): bool;

    public function get(string $name): FieldItemListInterface;

    public function set(string $name, mixed $value): static;

    /** @return array<string, \Aurora\Field\FieldDefinitionInterface> */
    public function getFieldDefinitions(): array;
}
```

**Step 3: Write ContentEntityInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity;

interface ContentEntityInterface extends EntityInterface, FieldableInterface
{
    // Content entities are fieldable entities. Additional
    // capabilities (revisions, translations) are opt-in
    // via RevisionableInterface and TranslatableInterface.
}
```

**Step 4: Write RevisionableInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity;

interface RevisionableInterface
{
    public function getRevisionId(): int|string|null;

    public function isDefaultRevision(): bool;

    public function isLatestRevision(): bool;
}
```

**Step 5: Write TranslatableInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity;

/**
 * Entities implementing this support multiple languages.
 *
 * IMPORTANT: Unlike Drupal, an Aurora entity object represents
 * ONE language at a time. getTranslation() returns a separate
 * entity object for the requested language. This simplification
 * removes hidden state and makes field values unambiguous.
 */
interface TranslatableInterface
{
    public function language(): string;

    /** @return string[] Language codes */
    public function getTranslationLanguages(): array;

    public function hasTranslation(string $langcode): bool;

    public function getTranslation(string $langcode): static;
}
```

**Step 6: Write ConfigEntityInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity;

interface ConfigEntityInterface extends EntityInterface
{
    public function status(): bool;

    public function enable(): static;

    public function disable(): static;

    /** @return array<string, string[]> Keyed by dependency type ('package', 'config', 'content') */
    public function getDependencies(): array;

    public function toConfig(): array;
}
```

**Step 7: Write EntityTypeInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity;

interface EntityTypeInterface
{
    public function id(): string;

    public function getLabel(): string;

    public function getClass(): string;

    /** @return class-string<Storage\EntityStorageInterface> */
    public function getStorageClass(): string;

    public function getKeys(): array;

    public function isRevisionable(): bool;

    public function isTranslatable(): bool;

    public function getBundleEntityType(): ?string;

    public function getConstraints(): array;
}
```

**Step 8: Write EntityTypeManagerInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity;

interface EntityTypeManagerInterface
{
    public function getDefinition(string $entityTypeId): EntityTypeInterface;

    /** @return array<string, EntityTypeInterface> */
    public function getDefinitions(): array;

    public function hasDefinition(string $entityTypeId): bool;

    public function getStorage(string $entityTypeId): Storage\EntityStorageInterface;
}
```

**Step 9: Write EntityStorageInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity\Storage;

use Aurora\Entity\EntityInterface;

interface EntityStorageInterface
{
    public function create(array $values = []): EntityInterface;

    public function load(int|string $id): ?EntityInterface;

    /** @return array<int|string, EntityInterface> */
    public function loadMultiple(array $ids = []): array;

    /**
     * @return int SAVED_NEW (1) or SAVED_UPDATED (2)
     */
    public function save(EntityInterface $entity): int;

    /** @param EntityInterface[] $entities */
    public function delete(array $entities): void;

    public function getQuery(): EntityQueryInterface;

    public function getEntityTypeId(): string;
}
```

**Step 10: Write RevisionableStorageInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity\Storage;

use Aurora\Entity\EntityInterface;

interface RevisionableStorageInterface extends EntityStorageInterface
{
    public function loadRevision(int|string $revisionId): ?EntityInterface;

    /** @return array<int|string, EntityInterface> */
    public function loadMultipleRevisions(array $ids): array;

    public function deleteRevision(int|string $revisionId): void;

    public function getLatestRevisionId(int|string $entityId): int|string|null;
}
```

**Step 11: Write EntityQueryInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity\Storage;

interface EntityQueryInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static;

    public function exists(string $field): static;

    public function notExists(string $field): static;

    public function sort(string $field, string $direction = 'ASC'): static;

    public function range(int $offset, int $limit): static;

    public function count(): static;

    public function accessCheck(bool $check = true): static;

    /**
     * Execute the query and return entity IDs.
     *
     * Returns IDs, not entities. This separates querying from loading,
     * enabling batch loading, pagination, and count-only queries.
     *
     * @return array<int|string>
     */
    public function execute(): array;
}
```

**Step 12: Write EntityEvents enum and EntityEvent class**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity\Event;

enum EntityEvents: string
{
    case PRE_SAVE = 'aurora.entity.pre_save';
    case POST_SAVE = 'aurora.entity.post_save';
    case PRE_DELETE = 'aurora.entity.pre_delete';
    case POST_DELETE = 'aurora.entity.post_delete';
    case POST_LOAD = 'aurora.entity.post_load';
    case PRE_CREATE = 'aurora.entity.pre_create';
}
```

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity\Event;

use Aurora\Entity\EntityInterface;
use Symfony\Contracts\EventDispatcher\Event;

class EntityEvent extends Event
{
    public function __construct(
        public readonly EntityInterface $entity,
        public readonly ?EntityInterface $originalEntity = null,
    ) {}
}
```

**Step 13: Commit**

```bash
git add packages/entity/src/
git commit -m "contracts: define aurora/entity public interfaces — entity, storage, query, events"
```

---

### Task 10: Define aurora/field contracts

**Files:**
- Create: `packages/field/src/FieldDefinitionInterface.php`
- Create: `packages/field/src/FieldTypeInterface.php`
- Create: `packages/field/src/FieldTypeManagerInterface.php`
- Create: `packages/field/src/FieldItemInterface.php`
- Create: `packages/field/src/FieldItemListInterface.php`
- Create: `packages/field/src/Attribute/FieldType.php`

**Step 1: Write FieldDefinitionInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Field;

use Aurora\TypedData\DataDefinitionInterface;

/**
 * Defines a field on an entity type.
 *
 * Unlike Drupal, there is no separate FieldStorageDefinition.
 * The field definition declares everything: type, cardinality,
 * constraints, settings. The storage layer decides how to persist.
 */
interface FieldDefinitionInterface extends DataDefinitionInterface
{
    public function getName(): string;

    public function getType(): string;

    public function getCardinality(): int;

    public function isMultiple(): bool;

    public function getSettings(): array;

    public function getSetting(string $name): mixed;

    public function getTargetEntityTypeId(): string;

    public function getTargetBundle(): ?string;

    public function isTranslatable(): bool;

    public function isRevisionable(): bool;

    public function getDefaultValue(): mixed;

    /**
     * Returns the JSON Schema representation of this field.
     * Used by aurora/ai-schema for automatic schema generation.
     */
    public function toJsonSchema(): array;
}
```

**Step 2: Write FieldTypeInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Field;

use Aurora\Plugin\PluginInspectionInterface;

interface FieldTypeInterface extends PluginInspectionInterface
{
    /** @return array<string, array{type: string, description?: string}> Column definitions */
    public static function schema(): array;

    /** @return array<string, mixed> Default settings for this field type */
    public static function defaultSettings(): array;

    public static function defaultValue(): mixed;

    /**
     * Returns JSON Schema fragment for this field type's value.
     * Enables automatic OpenAPI/MCP schema generation.
     */
    public static function jsonSchema(): array;
}
```

**Step 3: Write FieldItemInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Field;

use Aurora\TypedData\ComplexDataInterface;

/**
 * A single field value. For example, one item in a multi-value field.
 *
 * Field items expose their properties as typed data.
 * A text field item has 'value' and 'format' properties.
 * An entity reference has 'target_id' and optionally 'entity'.
 */
interface FieldItemInterface extends ComplexDataInterface
{
    public function isEmpty(): bool;

    public function getFieldDefinition(): FieldDefinitionInterface;

    /** @return string[] Property names (e.g., ['value', 'format']) */
    public static function propertyDefinitions(): array;

    public static function mainPropertyName(): string;
}
```

**Step 4: Write FieldItemListInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Field;

use Aurora\TypedData\ListInterface;

/**
 * The list of values for a field on an entity.
 *
 * Single-value fields have one item. Multi-value fields have many.
 * Accessing $entity->get('title') returns a FieldItemListInterface.
 * The shorthand $entity->get('title')->value accesses the main
 * property of the first item.
 */
interface FieldItemListInterface extends ListInterface
{
    public function getFieldDefinition(): FieldDefinitionInterface;

    /** Shorthand: value of the main property of the first item. */
    public function __get(string $name): mixed;
}
```

**Step 5: Write FieldTypeManagerInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Field;

use Aurora\Plugin\PluginManagerInterface;

interface FieldTypeManagerInterface extends PluginManagerInterface
{
    public function getDefaultSettings(string $fieldType): array;

    /** @return array{type: string, description?: string}[] */
    public function getColumns(string $fieldType): array;
}
```

**Step 6: Write FieldType attribute**

```php
<?php

declare(strict_types=1);

namespace Aurora\Field\Attribute;

use Aurora\Plugin\Attribute\AuroraPlugin;

#[\Attribute(\Attribute::TARGET_CLASS)]
class FieldType extends AuroraPlugin
{
    public function __construct(
        string $id,
        string $label = '',
        string $description = '',
        public readonly string $category = 'general',
        public readonly int $defaultCardinality = 1,
        public readonly string $defaultWidget = '',
        public readonly string $defaultFormatter = '',
    ) {
        parent::__construct($id, $label, $description);
    }
}
```

**Step 7: Commit**

```bash
git add packages/field/src/
git commit -m "contracts: define aurora/field public interfaces — definitions, items, types"
```

---

### Task 11: Define aurora/config contracts

**Files:**
- Create: `packages/config/src/ConfigFactoryInterface.php`
- Create: `packages/config/src/ConfigInterface.php`
- Create: `packages/config/src/StorageInterface.php`
- Create: `packages/config/src/ConfigManagerInterface.php`
- Create: `packages/config/src/Ownership/PackageOwnership.php`

**Step 1: Write ConfigInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Config;

interface ConfigInterface
{
    public function getName(): string;

    public function get(string $key = ''): mixed;

    public function set(string $key, mixed $value): static;

    public function clear(string $key): static;

    public function delete(): static;

    public function save(): static;

    public function isNew(): bool;

    public function getRawData(): array;
}
```

**Step 2: Write ConfigFactoryInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Config;

interface ConfigFactoryInterface
{
    public function get(string $name): ConfigInterface;

    public function getEditable(string $name): ConfigInterface;

    /** @return ConfigInterface[] */
    public function loadMultiple(array $names): array;

    public function rename(string $oldName, string $newName): static;

    /** @return string[] */
    public function listAll(string $prefix = ''): array;
}
```

**Step 3: Write StorageInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Config;

interface StorageInterface
{
    public function exists(string $name): bool;

    public function read(string $name): array|false;

    /** @return array<string, array> */
    public function readMultiple(array $names): array;

    public function write(string $name, array $data): bool;

    public function delete(string $name): bool;

    public function rename(string $name, string $newName): bool;

    /** @return string[] */
    public function listAll(string $prefix = ''): array;

    public function deleteAll(string $prefix = ''): bool;

    public function createCollection(string $collection): static;

    public function getCollectionName(): string;

    /** @return string[] */
    public function getAllCollectionNames(): array;
}
```

**Step 4: Write ConfigManagerInterface (import/export)**

```php
<?php

declare(strict_types=1);

namespace Aurora\Config;

interface ConfigManagerInterface
{
    public function getActiveStorage(): StorageInterface;

    public function getSyncStorage(): StorageInterface;

    public function import(): ConfigImportResult;

    public function export(): void;

    public function diff(string $configName): array;
}
```

**Step 5: Write PackageOwnership**

```php
<?php

declare(strict_types=1);

namespace Aurora\Config\Ownership;

/**
 * Tracks which Composer package owns each config object.
 *
 * Replaces Drupal's module-based config ownership. When a package
 * is removed via Composer, its owned config objects are identified
 * for cleanup.
 */
final readonly class PackageOwnership
{
    public function __construct(
        public string $configName,
        public string $packageName,
        public string $versionConstraint,
        /** @var string[] Other packages this config depends on */
        public array $dependencies = [],
    ) {}
}
```

**Step 6: Write ConfigImportResult value object**

```php
<?php

declare(strict_types=1);

namespace Aurora\Config;

final readonly class ConfigImportResult
{
    public function __construct(
        /** @var string[] */
        public array $created = [],
        /** @var string[] */
        public array $updated = [],
        /** @var string[] */
        public array $deleted = [],
        /** @var string[] */
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }
}
```

**Step 7: Commit**

```bash
git add packages/config/src/
git commit -m "contracts: define aurora/config public interfaces with package-aware ownership"
```

---

### Task 12: Define aurora/entity-storage database seam

**Files:**
- Create: `packages/database-legacy/src/DatabaseInterface.php`
- Create: `packages/database-legacy/src/SelectInterface.php`
- Create: `packages/database-legacy/src/InsertInterface.php`
- Create: `packages/database-legacy/src/UpdateInterface.php`
- Create: `packages/database-legacy/src/DeleteInterface.php`
- Create: `packages/database-legacy/src/SchemaInterface.php`
- Create: `packages/database-legacy/src/TransactionInterface.php`

**Step 1: Write DatabaseInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Database;

/**
 * Thin database abstraction for entity storage.
 *
 * NOT a full query builder — just enough for entity storage to work
 * with either Drupal's DBAL or Doctrine DBAL. Intentionally narrow
 * to make the adapter swap straightforward.
 *
 * v0.1.0: DrupalDatabaseAdapter wraps Drupal\Core\Database\Connection
 * v0.2.0+: DoctrineDatabaseAdapter wraps Doctrine\DBAL\Connection
 */
interface DatabaseInterface
{
    public function select(string $table, string $alias = ''): SelectInterface;

    public function insert(string $table): InsertInterface;

    public function update(string $table): UpdateInterface;

    public function delete(string $table): DeleteInterface;

    public function schema(): SchemaInterface;

    public function transaction(string $name = ''): TransactionInterface;

    public function query(string $sql, array $args = []): \Traversable;
}
```

**Step 2: Write SelectInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Database;

interface SelectInterface
{
    public function fields(string $tableAlias, array $fields = []): static;

    public function addField(string $tableAlias, string $field, string $alias = ''): static;

    public function condition(string $field, mixed $value, string $operator = '='): static;

    public function isNull(string $field): static;

    public function isNotNull(string $field): static;

    public function orderBy(string $field, string $direction = 'ASC'): static;

    public function range(int $offset, int $limit): static;

    public function join(string $table, string $alias, string $condition): static;

    public function leftJoin(string $table, string $alias, string $condition): static;

    public function countQuery(): static;

    public function execute(): \Traversable;
}
```

**Step 3: Write InsertInterface, UpdateInterface, DeleteInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Database;

interface InsertInterface
{
    public function fields(array $fields): static;

    public function values(array $values): static;

    public function execute(): int|string;
}
```

```php
<?php

declare(strict_types=1);

namespace Aurora\Database;

interface UpdateInterface
{
    public function fields(array $fields): static;

    public function condition(string $field, mixed $value, string $operator = '='): static;

    public function execute(): int;
}
```

```php
<?php

declare(strict_types=1);

namespace Aurora\Database;

interface DeleteInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static;

    public function execute(): int;
}
```

**Step 4: Write SchemaInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Database;

interface SchemaInterface
{
    public function tableExists(string $table): bool;

    public function fieldExists(string $table, string $field): bool;

    public function createTable(string $name, array $spec): void;

    public function dropTable(string $table): void;

    public function addField(string $table, string $field, array $spec): void;

    public function dropField(string $table, string $field): void;

    public function addIndex(string $table, string $name, array $fields): void;

    public function dropIndex(string $table, string $name): void;

    public function addUniqueKey(string $table, string $name, array $fields): void;

    public function addPrimaryKey(string $table, array $fields): void;
}
```

**Step 5: Write TransactionInterface**

```php
<?php

declare(strict_types=1);

namespace Aurora\Database;

interface TransactionInterface
{
    public function commit(): void;

    public function rollBack(): void;
}
```

**Step 6: Commit**

```bash
git add packages/database-legacy/src/
git commit -m "contracts: define aurora/database-legacy interfaces — the Doctrine migration seam"
```

---

### Task 13: Verify all contracts compile and commit Phase 2

**Step 1: Run PHP syntax check on all contract files**

```bash
find packages -name '*.php' -exec php -l {} \;
```

Expected: No syntax errors.

**Step 2: Commit phase completion marker**

```bash
git add -A
git commit -m "contracts: Phase 2 complete — all Layer 0-1 public interfaces defined"
```

---

## Phase 3: Extract Layer 0 (Foundation)

Move Drupal code behind the Aurora interfaces. Each task extracts one package.

### Task 14: Implement aurora/cache with Drupal backend

**Goal:** Create a working cache system using Drupal's cache backends behind Aurora's CacheBackendInterface.

**Files:**
- Create: `packages/cache/src/Backend/MemoryBackend.php` — In-memory cache for testing and dev
- Create: `packages/cache/src/Backend/DatabaseBackend.php` — Wraps Drupal's DatabaseBackend (later migrates to Doctrine)
- Create: `packages/cache/src/Backend/NullBackend.php` — No-op cache
- Create: `packages/cache/src/CacheFactory.php` — Factory implementation
- Create: `packages/cache/src/CacheTagsInvalidator.php` — Tag invalidation implementation
- Test: `packages/cache/tests/Unit/Backend/MemoryBackendTest.php`

**Key decisions:**
- MemoryBackend is pure PHP, no external deps — ship first for testing
- DatabaseBackend wraps Drupal's implementation internally in v0.1.0
- CacheTagsInvalidator coordinates invalidation across all bins
- Tests use MemoryBackend exclusively (no database needed for unit tests)

**TDD flow:**
1. Write tests for MemoryBackend (get, set, delete, tags, invalidation)
2. Implement MemoryBackend
3. Write tests for NullBackend
4. Implement NullBackend
5. Write test for CacheFactory
6. Implement CacheFactory
7. Commit

---

### Task 15: Implement aurora/plugin with attribute discovery

**Goal:** Working plugin manager that discovers plugins via PHP 8 attributes.

**Files:**
- Create: `packages/plugin/src/DefaultPluginManager.php`
- Create: `packages/plugin/src/Discovery/AttributeDiscovery.php`
- Create: `packages/plugin/src/Factory/ContainerFactory.php`
- Create: `packages/plugin/src/PluginBase.php` — Abstract base class
- Test: `packages/plugin/tests/Unit/Discovery/AttributeDiscoveryTest.php`
- Test: `packages/plugin/tests/Unit/DefaultPluginManagerTest.php`

**Key decisions:**
- NO annotation support. Attributes only. This is the clean break.
- NO ModuleHandlerInterface dependency. Plugins are discovered from namespaces, not "modules."
- Discovery scans configured directories for classes with the target attribute.
- Plugin definitions are cached via aurora/cache.
- Derivative plugins (Drupal's concept of auto-generated plugin variants) are kept — useful for entity bundles.

**Source reference:** Port from `core/lib/Drupal/Core/Plugin/DefaultPluginManager.php` but strip:
- Annotation discovery (lines referencing `AnnotatedClassDiscovery`)
- Module handler dependency (alter hooks)
- All hook-based alteration (replace with event dispatch)

---

### Task 16: Implement aurora/typed-data facade

**Goal:** PHP-native facade over Drupal's TypedData. Clean public API, messy internals.

**Files:**
- Create: `packages/typed-data/src/TypedDataManager.php`
- Create: `packages/typed-data/src/DataDefinition.php` — Concrete definition class
- Create: `packages/typed-data/src/Type/StringData.php`
- Create: `packages/typed-data/src/Type/IntegerData.php`
- Create: `packages/typed-data/src/Type/BooleanData.php`
- Create: `packages/typed-data/src/Type/FloatData.php`
- Create: `packages/typed-data/src/Type/ListData.php`
- Create: `packages/typed-data/src/Type/MapData.php`
- Test: `packages/typed-data/tests/Unit/Type/StringDataTest.php`
- Test: `packages/typed-data/tests/Unit/TypedDataManagerTest.php`

**Key decisions:**
- Primitive types (string, int, bool, float) are simple wrappers with validation.
- Complex types (Map, List) use the ComplexDataInterface / ListInterface.
- The TypedDataManager acts as a registry + factory.
- v0.1.0 internals may delegate to Drupal's TypedDataManager for complex behaviors.
- The public API is designed so that in v1.0.0, TypedData types can be replaced with native PHP readonly classes + Symfony validation.

---

### Tasks 17-19: Unit tests, integration tests, Phase 3 verification

**Task 17:** Write comprehensive unit tests for aurora/cache MemoryBackend.
**Task 18:** Write comprehensive unit tests for aurora/plugin AttributeDiscovery.
**Task 19:** Run all tests, fix failures, commit Phase 3.

```bash
cd packages/cache && ../../vendor/bin/phpunit tests/
cd packages/plugin && ../../vendor/bin/phpunit tests/
cd packages/typed-data && ../../vendor/bin/phpunit tests/
```

---

## Phase 4: Extract Layer 1 (Core Data)

This is the hardest phase. Entity, field, config, and entity-storage are deeply intertwined in Drupal.

### Task 20: Implement aurora/config

**Goal:** Working config system with YAML storage and package-aware ownership.

**Source:** Port from `core/lib/Drupal/Core/Config/ConfigFactory.php` and `core/lib/Drupal/Core/Config/FileStorage.php`.

**Key changes from Drupal:**
- Replace module ownership with package ownership (`PackageOwnership` class).
- Replace `hook_config_schema_info_alter` with event dispatch.
- Config dependencies reference `aurora/*` package names, not module names.
- Typed config (schema validation) delegates to aurora/typed-data.
- Config import/export uses the same `StorageInterface` abstraction.

**Files to create:** ~10 files (ConfigFactory, FileStorage, ConfigManager, TypedConfigManager, events, etc.)

### Task 21: Implement aurora/entity core (no storage)

**Goal:** Entity type definitions, base entity classes, entity type manager, entity events.

**Source:** Port from:
- `core/lib/Drupal/Core/Entity/EntityTypeManager.php`
- `core/lib/Drupal/Core/Entity/EntityBase.php`
- `core/lib/Drupal/Core/Entity/ContentEntityBase.php`
- `core/lib/Drupal/Core/Entity/ConfigEntityBase.php`

**Key changes from Drupal:**
- EntityTypeManager extends aurora/plugin's DefaultPluginManager (entity types as plugins).
- Remove all hook invocations. Use Symfony events (EntityEvents enum).
- ContentEntityBase implements the "one language at a time" model.
- Remove `$entity->original` pattern. Pre-save event carries old state.
- Remove all render/display logic from entity classes.
- Remove `EntityViewBuilder`, `EntityListBuilder`, `EntityFormBase` — these are UI concerns.

**Critical file count:** ~15-20 files.

### Task 22: Implement aurora/field

**Goal:** Field type plugin manager, base field item classes, field definitions.

**Source:** Port from:
- `core/lib/Drupal/Core/Field/FieldTypePluginManager.php`
- `core/lib/Drupal/Core/Field/FieldItemBase.php`
- `core/lib/Drupal/Core/Field/FieldItemList.php`
- `core/lib/Drupal/Core/Field/BaseFieldDefinition.php`

**Key changes from Drupal:**
- Merge FieldStorageDefinition into FieldDefinition (single definition class).
- Field types discovered via `#[FieldType]` attribute, not annotations.
- `FieldDefinition::toJsonSchema()` method for automatic API schema generation.
- Remove field widget/formatter discovery — that's a UI concern (SPA admin handles it).
- Keep: StringItem, TextItem, IntegerItem, BooleanItem, EntityReferenceItem, DateTimeItem, LinkItem, FileItem, ImageItem.

### Task 23: Implement aurora/database-legacy

**Goal:** DrupalDatabaseAdapter implementing DatabaseInterface, wrapping Drupal's Connection.

**Source:** Adapter pattern wrapping `core/lib/Drupal/Core/Database/Connection.php`.

**Key design:** This is a thin adapter. Each method in DatabaseInterface maps to a Drupal Connection method. The adapter translates the narrow Aurora interface to Drupal's broader API.

### Task 24: Implement aurora/entity-storage (THE HARD TASK)

**Goal:** SqlEntityStorage implementing EntityStorageInterface, using aurora/database-legacy.

**Source:** Port from `core/lib/Drupal/Core/Entity/Sql/SqlContentEntityStorage.php` (~1800 lines).

**This is the single most complex task in the project.** Key subtasks:

1. **Port SqlContentEntityStorage** — Rewrite to use `Aurora\Database\DatabaseInterface` instead of Drupal's Connection directly.
2. **Port SqlContentEntityStorageSchema** — Internal schema manager. Not exposed publicly, but needed for table creation.
3. **Port DefaultTableMapping** — Internal table mapping. Not exposed publicly.
4. **Port SqlEntityQuery** — Entity query builder that produces SQL via DatabaseInterface.
5. **Adapt revision handling** — Same 4-table pattern internally, but behind RevisionableStorageInterface.
6. **Adapt translation handling** — Same internal storage, but entity object represents one language.

**Lines to port:** ~3000+ lines across storage, schema, table mapping, and query.
**Test strategy:** Integration tests with SQLite in-memory database.

### Tasks 25-27: Tests + integration verification

**Task 25:** Write integration tests for aurora/config (YAML round-trip, import/export).
**Task 26:** Write integration tests for aurora/entity + aurora/entity-storage (CRUD with SQLite).
**Task 27:** Run all Layer 0-1 tests, fix failures, commit Phase 4.

---

## Phase 5: Extract Layer 2 (Services)

### Task 28: Implement aurora/access

**Source:** Port from `core/lib/Drupal/Core/Access/`.
**Key change:** Remove node grants table. Entity access is policy-based.

### Task 29: Implement aurora/user

**Source:** Port from `core/modules/user/`.
**Key change:** User entity uses aurora/entity. Password hashing via native `password_hash()`. Sessions via Symfony.

### Task 30: Implement aurora/routing

**Source:** Port from `core/lib/Drupal/Core/Routing/`.
**Key change:** Pure Symfony routing with Aurora's param upcasting (entity route parameters auto-load entities) and access checking.

### Task 31: Implement aurora/queue

**Source:** New. Use Symfony Messenger directly. Define Aurora-specific message types for entity events, config changes, AI pipeline steps.

### Task 32: Implement aurora/state + aurora/validation

**Source:** Port from `core/lib/Drupal/Core/State/` and `core/lib/Drupal/Core/Validation/`.

### Task 33: Layer 2 integration tests + Phase 5 commit

---

## Phase 6: Extract Layer 3 (Content Types)

### Task 34: Implement aurora/node

**Source:** Port from `core/modules/node/`. Simplified: no node grants, no node access records. Just a content entity type with bundles.

### Task 35: Implement aurora/taxonomy

**Source:** Port from `core/modules/taxonomy/`. Vocabulary + Term entities.

### Task 36: Implement aurora/media + aurora/path + aurora/menu

**Source:** Port from respective modules. Media entities, file handling, URL aliases, menu links.

### Task 37: Implement aurora/workflows

**Source:** Port from `core/modules/workflows/` + `core/modules/content_moderation/`. State machines as config entities applied to content entities.

---

## Phase 7: Build Layer 4 (API)

### Task 38: Implement aurora/api — JSON:API

**Source:** Port from `core/modules/jsonapi/`. This module is already relatively clean and Symfony-based.

**Key enhancement:** Add OpenAPI 3.1 schema auto-generation.

### Task 39: Implement OpenAPI schema generation

**New code.** Walk all entity type definitions + field definitions. Generate a complete OpenAPI 3.1 spec at `GET /api/openapi.json`. Every entity type becomes a set of endpoints. Every field becomes a schema property with type, constraints, and description.

### Task 40: Implement JSON:API filtering, sorting, pagination

**Source:** Largely from existing jsonapi module. Ensure cursor-based pagination works alongside offset pagination.

### Task 41: API integration tests + Phase 7 commit

---

## Phase 8: Build Layer 5 (AI)

### Task 42: Implement aurora/ai-schema

**New code.** Auto-generate JSON Schema from entity/field/config definitions. Auto-generate MCP tool definitions from CRUD operations.

### Task 43: Implement MCP tool definitions

**New code.** For each entity type, generate: `create_{type}`, `read_{type}`, `update_{type}`, `delete_{type}`, `query_{type}` tools with input schemas derived from field definitions.

### Task 44: Implement aurora/ai-agent

**New code.** AI agent plugin type using `#[AuroraPlugin]`. MCP server that exposes tools from ai-schema. Agent event subscription system.

### Task 45: Implement aurora/ai-vector

**New code.** Embedding service interface with pluggable backends. Entity embedding on save. Vector query interface.

### Task 46: Implement aurora/ai-pipeline

**New code.** Pipeline as config entity. Steps as plugins. Uses aurora/queue for async execution.

### Task 47: AI layer tests + Phase 8 commit

---

## Phase 9: Build Layer 6 (Interfaces)

### Task 48: Implement aurora/cli

**New code.** Symfony Console application with commands:
- `aurora install` — Database setup, initial config, admin user
- `aurora config:export` / `aurora config:import`
- `aurora cache:clear`
- `aurora entity:create` / `aurora entity:list`
- `aurora user:create` / `aurora user:role`
- `aurora make:entity-type` / `aurora make:field-type` / `aurora make:plugin` — Scaffolding generators

### Task 49: Scaffold aurora/admin SPA

**New code.** Initialize JS project (React or Vue + Vite). Schema-driven admin that:
1. Fetches OpenAPI spec on startup.
2. Auto-generates navigation from entity type list.
3. Auto-generates list views from entity queries.
4. Auto-generates forms from field definitions.
5. Integrates AI assistant via MCP tools from ai-schema.

### Task 50: Implement admin content CRUD

### Task 51: Implement admin entity/field management

### Task 52: Implement aurora/ssr (optional)

**New code.** Twig Component renderer. Entity-to-component mapping. Symfony controllers returning rendered HTML.

### Task 53: Interfaces tests + Phase 9 commit

---

## Phase 10: Integration + Cleanup

### Task 54: Create meta-packages

Verify `aurora/core`, `aurora/cms`, `aurora/full` correctly pull in all dependencies.

### Task 55: Remove dead Drupal code

Delete everything from `core/` that is not wrapped by an aurora/* package. Delete all core themes, profiles, and removed modules.

### Task 56: End-to-end smoke tests

- Install via CLI (`aurora install`)
- Create a content type via API
- Add fields via API
- Create content via API
- Query content via API
- Verify OpenAPI spec is generated
- Verify MCP tools are generated
- Log into admin SPA
- Create content via admin

### Task 57: Tag v0.1.0

```bash
git tag -a v0.1.0 -m "Aurora CMS v0.1.0 — entity-first, AI-native CMS"
```
