# M4: Public Surface Unification — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every public API element in Waaseyaa an intentional v1 contract by triaging 144 elements across 35 packages, adding `@internal` annotations to implementation details, updating specs, and producing an authoritative surface map.

**Architecture:** Contract-first cleanup. Define the public surface top-down per layer from specs, reconcile with code. Each element gets a disposition: Public, Internal, Extract, or Remove. A verification test enforces the surface map.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, `@internal` docblock annotations

---

### Task 1: Create M4 Milestone and Issues on waaseyaa/waaseyaa

**Files:**
- None (GitHub operations only)

- [ ] **Step 1: Create the M4 milestone**

```bash
gh api repos/waaseyaa/waaseyaa/milestones -f title="M4: Public Surface Unification" -f description="Make every public API element an intentional v1 contract. Triage 144 elements, add @internal annotations, update specs, produce surface map."
```

- [ ] **Step 2: Create issues for each layer task**

Create 8 issues (one per task 3-9 below, plus the governance cleanup) and assign them to the M4 milestone. Title format: `refactor(#M4): L0 Foundation surface cleanup`, etc.

- [ ] **Step 3: Commit**

No code changes. Issues are the deliverable.

---

### Task 2: Surface Verification Test

**Files:**
- Create: `tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php`

This test scans all packages for interfaces, abstract classes, and traits, then asserts each one is listed in the surface map with a disposition. It fails if any public API element exists without an explicit decision.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\SurfaceMap;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PublicSurfaceVerificationTest extends TestCase
{
    private const SURFACE_MAP_PATH = __DIR__ . '/../../../docs/public-surface-map.php';

    #[Test]
    public function every_public_element_has_a_disposition(): void
    {
        $surfaceMap = require self::SURFACE_MAP_PATH;
        $discoveredElements = $this->discoverPublicElements();

        $unmapped = [];
        foreach ($discoveredElements as $fqn) {
            if (!isset($surfaceMap[$fqn])) {
                $unmapped[] = $fqn;
            }
        }

        self::assertSame(
            [],
            $unmapped,
            sprintf(
                "%d public API element(s) have no disposition in surface map:\n%s",
                count($unmapped),
                implode("\n", $unmapped),
            ),
        );
    }

    #[Test]
    public function surface_map_contains_no_stale_entries(): void
    {
        $surfaceMap = require self::SURFACE_MAP_PATH;
        $discoveredElements = $this->discoverPublicElements();

        $stale = [];
        foreach (array_keys($surfaceMap) as $fqn) {
            if (!in_array($fqn, $discoveredElements, true)) {
                $stale[] = $fqn;
            }
        }

        self::assertSame(
            [],
            $stale,
            sprintf(
                "%d surface map entry(ies) reference elements that no longer exist:\n%s",
                count($stale),
                implode("\n", $stale),
            ),
        );
    }

    #[Test]
    public function no_public_element_lacks_internal_annotation_unless_mapped_public(): void
    {
        $surfaceMap = require self::SURFACE_MAP_PATH;
        $publicElements = array_keys(array_filter($surfaceMap, fn(string $disposition) => $disposition === 'public'));

        $discoveredElements = $this->discoverPublicElements();
        $missingAnnotation = [];

        foreach ($discoveredElements as $fqn) {
            if (in_array($fqn, $publicElements, true)) {
                continue;
            }
            $disposition = $surfaceMap[$fqn] ?? null;
            if ($disposition === 'internal' || $disposition === 'remove') {
                $rc = new \ReflectionClass($fqn);
                $doc = $rc->getDocComment();
                if ($doc === false || !str_contains($doc, '@internal')) {
                    $missingAnnotation[] = $fqn;
                }
            }
        }

        self::assertSame(
            [],
            $missingAnnotation,
            sprintf(
                "%d element(s) marked 'internal' in surface map lack @internal annotation:\n%s",
                count($missingAnnotation),
                implode("\n", $missingAnnotation),
            ),
        );
    }

    /**
     * @return list<class-string>
     */
    private function discoverPublicElements(): array
    {
        $packagesDir = __DIR__ . '/../../../packages';
        $elements = [];

        foreach (new \DirectoryIterator($packagesDir) as $package) {
            if ($package->isDot() || !$package->isDir()) {
                continue;
            }
            $srcDir = $package->getPathname() . '/src';
            if (!is_dir($srcDir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                if (preg_match('/^(interface|abstract class|trait)\s+(\w+)/m', $content, $match)) {
                    if (preg_match('/^namespace\s+([^;]+);/m', $content, $nsMatch)) {
                        $fqn = $nsMatch[1] . '\\' . $match[2];
                        $elements[] = $fqn;
                    }
                }
            }
        }

        sort($elements);
        return $elements;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php`
Expected: FAIL — surface map file does not exist yet.

- [ ] **Step 3: Create the empty surface map file**

```php
<?php
// docs/public-surface-map.php
// Authoritative disposition map for all public API elements.
// Format: 'Fully\Qualified\ClassName' => 'public|internal|extract|remove'
// This file is verified by PublicSurfaceVerificationTest.

declare(strict_types=1);

return [
    // Populated by M4 tasks 3-7
];
```

- [ ] **Step 4: Run test to verify it fails with unmapped elements**

Run: `./vendor/bin/phpunit tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php`
Expected: FAIL — "144 public API element(s) have no disposition in surface map"

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php docs/public-surface-map.php
git commit -m "test(#M4): add public surface verification test and empty surface map"
```

---

### Task 3: L0 Foundation Surface Triage and Annotation

**Files:**
- Modify: `docs/public-surface-map.php` (add 68 entries)
- Modify: ~15 files across foundation, cache, database-legacy, plugin, typed-data, testing, i18n, queue, scheduler, state, mail, http-client, ingestion packages (add `@internal` annotations)

The disposition decisions for L0 (68 elements):

**Public (43):**
- `Waaseyaa\Foundation\Asset\AssetManagerInterface` — extension point for theme asset management
- `Waaseyaa\Foundation\Broadcasting\BroadcasterInterface` — event broadcasting contract
- `Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface` — operator diagnostics contract
- `Waaseyaa\Foundation\Log\LoggerInterface` — framework logging contract
- `Waaseyaa\Foundation\Log\Handler\HandlerInterface` — log handler extension point
- `Waaseyaa\Foundation\Log\Formatter\FormatterInterface` — log formatter extension point
- `Waaseyaa\Foundation\Log\Processor\ProcessorInterface` — log processor extension point
- `Waaseyaa\Foundation\Log\LoggerTrait` — convenience trait for LoggerInterface consumers
- `Waaseyaa\Foundation\Middleware\HttpHandlerInterface` — HTTP middleware pipeline contract
- `Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface` — HTTP middleware contract
- `Waaseyaa\Foundation\Middleware\JobHandlerInterface` — job middleware pipeline contract
- `Waaseyaa\Foundation\Middleware\JobMiddlewareInterface` — job middleware contract
- `Waaseyaa\Foundation\RateLimit\RateLimiterInterface` — rate limiting contract
- `Waaseyaa\Foundation\Schema\SchemaRegistryInterface` — ingestion schema registry contract
- `Waaseyaa\Foundation\ServiceProvider\ServiceProviderInterface` — service provider contract
- `Waaseyaa\Foundation\ServiceProvider\ServiceProvider` (abstract) — base service provider
- `Waaseyaa\Foundation\Event\DomainEvent` (abstract) — base domain event
- `Waaseyaa\Foundation\Exception\WaaseyaaException` (abstract) — base exception
- `Waaseyaa\Foundation\Http\JsonApiResponseTrait` — shared JSON:API response helpers
- `Waaseyaa\Foundation\Migration\Migration` (abstract) — base migration class
- `Waaseyaa\Cache\CacheBackendInterface` — cache backend contract
- `Waaseyaa\Cache\CacheFactoryInterface` — cache factory contract
- `Waaseyaa\Cache\CacheTagsInvalidatorInterface` — cache tag invalidation contract
- `Waaseyaa\Cache\TagAwareCacheInterface` — tag-aware cache contract
- `Waaseyaa\Database\DatabaseInterface` — database abstraction contract
- `Waaseyaa\Database\SelectInterface` — select query builder
- `Waaseyaa\Database\InsertInterface` — insert query builder
- `Waaseyaa\Database\UpdateInterface` — update query builder
- `Waaseyaa\Database\DeleteInterface` — delete query builder
- `Waaseyaa\Database\SchemaInterface` — schema manipulation
- `Waaseyaa\Database\TransactionInterface` — transaction management
- `Waaseyaa\Plugin\PluginInspectionInterface` — plugin introspection contract
- `Waaseyaa\Plugin\PluginManagerInterface` — plugin manager contract
- `Waaseyaa\Plugin\PluginBase` (abstract) — base plugin class
- `Waaseyaa\TypedData\TypedDataInterface` — typed data root contract
- `Waaseyaa\TypedData\DataDefinitionInterface` — data definition contract
- `Waaseyaa\TypedData\ComplexDataInterface` — complex data contract
- `Waaseyaa\TypedData\ListInterface` — list data contract
- `Waaseyaa\TypedData\PrimitiveInterface` — primitive data contract
- `Waaseyaa\TypedData\TypedDataManagerInterface` — typed data manager contract
- `Waaseyaa\I18n\LanguageManagerInterface` — language manager contract
- `Waaseyaa\I18n\TranslatorInterface` — translation contract
- `Waaseyaa\Queue\QueueInterface` — queue contract

**Internal (20):**
- `Waaseyaa\Foundation\Kernel\AbstractKernel` — bootstrap orchestrator (kernel exemption)
- `Waaseyaa\Foundation\Tenant\TenantResolverInterface` — already `@internal`, not yet used by consumers
- `Waaseyaa\Plugin\Discovery\PluginDiscoveryInterface` — internal discovery mechanism
- `Waaseyaa\Plugin\Extension\KnowledgeToolingExtensionInterface` — internal extension point
- `Waaseyaa\Plugin\Factory\PluginFactoryInterface` — internal factory mechanism
- `Waaseyaa\Queue\Handler\HandlerInterface` — internal queue handler dispatch
- `Waaseyaa\Queue\Transport\TransportInterface` — internal transport mechanism
- `Waaseyaa\Queue\FailedJobRepositoryInterface` — internal failed job tracking
- `Waaseyaa\Queue\Job` (abstract) — internal job base, consumers use QueueInterface
- `Waaseyaa\Scheduler\Lock\LockInterface` — internal scheduler locking
- `Waaseyaa\Scheduler\ScheduleInterface` — internal scheduler contract
- `Waaseyaa\State\StateInterface` — internal state machine contract
- `Waaseyaa\Mail\MailerInterface` — internal mailer (see #798 consolidation)
- `Waaseyaa\Mail\MailDriverInterface` — internal mail driver
- `Waaseyaa\Mail\Transport\TransportInterface` — internal mail transport
- `Waaseyaa\HttpClient\HttpClientInterface` — internal HTTP client wrapper
- `Waaseyaa\Ingestion\PayloadValidatorInterface` — internal ingestion validation
- `Waaseyaa\Ingestion\EnvelopeValidator` (abstract) — internal envelope validation
- `Waaseyaa\Testing\WaaseyaaTestCase` (abstract) — test infrastructure
- `Waaseyaa\Testing\GraphQL\AbstractGraphQlSchemaContractTestCase` — test infrastructure

**Public (testing traits, 5):**
- `Waaseyaa\Testing\Traits\CreatesApplication` — consumer test helper
- `Waaseyaa\Testing\Traits\InteractsWithApi` — consumer test helper
- `Waaseyaa\Testing\Traits\InteractsWithAuth` — consumer test helper
- `Waaseyaa\Testing\Traits\InteractsWithEvents` — consumer test helper
- `Waaseyaa\Testing\Traits\RefreshDatabase` — consumer test helper

- [ ] **Step 1: Add all 68 L0 entries to surface map**

Add all entries listed above to `docs/public-surface-map.php` with their dispositions.

- [ ] **Step 2: Run verification test**

Run: `./vendor/bin/phpunit tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php --filter every_public_element_has_a_disposition`
Expected: PASS for L0 entries (still fails for L1-L6 unmapped elements)

- [ ] **Step 3: Add `@internal` annotations to the 20 internal elements**

For each element marked `internal`, add `@internal` to its class-level docblock. Example for `AbstractKernel`:

```php
/**
 * Base kernel for Waaseyaa applications.
 *
 * @internal Bootstrap orchestrator. Not a public API contract.
 */
abstract class AbstractKernel
```

Files to modify:
- `packages/foundation/src/Kernel/AbstractKernel.php`
- `packages/foundation/src/Tenant/TenantResolverInterface.php` (already has it)
- `packages/plugin/src/Discovery/PluginDiscoveryInterface.php`
- `packages/plugin/src/Extension/KnowledgeToolingExtensionInterface.php`
- `packages/plugin/src/Factory/PluginFactoryInterface.php`
- `packages/queue/src/Handler/HandlerInterface.php`
- `packages/queue/src/Transport/TransportInterface.php`
- `packages/queue/src/FailedJobRepositoryInterface.php`
- `packages/queue/src/Job.php`
- `packages/scheduler/src/Lock/LockInterface.php`
- `packages/scheduler/src/ScheduleInterface.php`
- `packages/state/src/StateInterface.php`
- `packages/mail/src/MailerInterface.php`
- `packages/mail/src/MailDriverInterface.php`
- `packages/mail/src/Transport/TransportInterface.php`
- `packages/http-client/src/HttpClientInterface.php`
- `packages/ingestion/src/PayloadValidatorInterface.php`
- `packages/ingestion/src/EnvelopeValidator.php`
- `packages/testing/src/WaaseyaaTestCase.php`
- `packages/testing/src/GraphQL/AbstractGraphQlSchemaContractTestCase.php`

- [ ] **Step 4: Run verification test for @internal annotations**

Run: `./vendor/bin/phpunit tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php --filter no_public_element_lacks_internal_annotation`
Expected: PASS for L0 elements

- [ ] **Step 5: Run full test suite to confirm no breakage**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All existing tests pass

- [ ] **Step 6: Commit**

```bash
git add docs/public-surface-map.php packages/foundation/ packages/plugin/ packages/queue/ packages/scheduler/ packages/state/ packages/mail/ packages/http-client/ packages/ingestion/ packages/testing/ packages/cache/ packages/database-legacy/ packages/typed-data/ packages/i18n/
git commit -m "refactor(#M4): L0 Foundation surface triage — 48 public, 20 internal"
```

---

### Task 4: L1 Core Data Surface Triage and Annotation

**Files:**
- Modify: `docs/public-surface-map.php` (add 41 entries)
- Modify: ~5 files for `@internal` annotations

The disposition decisions for L1 (41 elements):

**Public (37):**
All entity package interfaces and abstract classes are public (core consumer contracts):
- `Waaseyaa\Entity\EntityInterface`
- `Waaseyaa\Entity\EntityBase` (abstract)
- `Waaseyaa\Entity\ContentEntityBase` (abstract)
- `Waaseyaa\Entity\ContentEntityInterface`
- `Waaseyaa\Entity\ConfigEntityBase` (abstract)
- `Waaseyaa\Entity\ConfigEntityInterface`
- `Waaseyaa\Entity\EntityTypeInterface`
- `Waaseyaa\Entity\EntityTypeManagerInterface`
- `Waaseyaa\Entity\FieldableInterface`
- `Waaseyaa\Entity\RevisionableInterface`
- `Waaseyaa\Entity\TranslatableInterface`
- `Waaseyaa\Entity\RevisionableEntityTrait`
- `Waaseyaa\Entity\Repository\EntityRepositoryInterface`
- `Waaseyaa\Entity\Event\EntityEventFactoryInterface`
- `Waaseyaa\Entity\Storage\EntityStorageInterface`
- `Waaseyaa\Entity\Storage\RevisionableStorageInterface`
- `Waaseyaa\Entity\Storage\EntityQueryInterface`
- `Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface`
- `Waaseyaa\EntityStorage\Connection\ConnectionResolverInterface`
- `Waaseyaa\Access\AccountInterface`
- `Waaseyaa\Access\AccessPolicyInterface`
- `Waaseyaa\Access\FieldAccessPolicyInterface`
- `Waaseyaa\Access\PermissionHandlerInterface`
- `Waaseyaa\Access\Gate\GateInterface`
- `Waaseyaa\Config\ConfigInterface`
- `Waaseyaa\Config\ConfigFactoryInterface`
- `Waaseyaa\Config\ConfigManagerInterface`
- `Waaseyaa\Config\StorageInterface`
- `Waaseyaa\Config\TranslatableConfigFactoryInterface`
- `Waaseyaa\Field\FieldItemInterface`
- `Waaseyaa\Field\FieldItemListInterface`
- `Waaseyaa\Field\FieldDefinitionInterface`
- `Waaseyaa\Field\FieldTypeInterface`
- `Waaseyaa\Field\FieldFormatterInterface`
- `Waaseyaa\Field\FieldTypeManagerInterface`
- `Waaseyaa\Field\FieldItemBase` (abstract)
- `Waaseyaa\Field\ViewModeConfigInterface`

**Internal (4):**
- `Waaseyaa\Access\ErrorPageRendererInterface` — internal error rendering, not a consumer contract
- `Waaseyaa\Field\ComputedFieldInterface` — internal computed field mechanism
- `Waaseyaa\Auth\Token\AuthTokenRepositoryInterface` — internal auth token storage
- `Waaseyaa\Auth\RateLimiterInterface` — internal auth rate limiter (distinct from foundation's)

- [ ] **Step 1: Add all 41 L1 entries to surface map**

Add all entries listed above to `docs/public-surface-map.php`.

- [ ] **Step 2: Run verification test**

Run: `./vendor/bin/phpunit tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php --filter every_public_element_has_a_disposition`
Expected: Closer to passing (L0+L1 mapped, L2-L6 still unmapped)

- [ ] **Step 3: Add `@internal` annotations to the 4 internal elements**

Files to modify:
- `packages/access/src/ErrorPageRendererInterface.php`
- `packages/field/src/ComputedFieldInterface.php`
- `packages/auth/src/Token/AuthTokenRepositoryInterface.php`
- `packages/auth/src/RateLimiterInterface.php`

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add docs/public-surface-map.php packages/entity/ packages/entity-storage/ packages/access/ packages/config/ packages/field/ packages/auth/
git commit -m "refactor(#M4): L1 Core Data surface triage — 37 public, 4 internal"
```

---

### Task 5: L2-L4 Surface Triage and Annotation

**Files:**
- Modify: `docs/public-surface-map.php` (add 14 entries)
- Modify: ~2 files for `@internal` annotations

**Layer 2 — Content Types (3 elements, all public):**
- `Waaseyaa\Media\FileRepositoryInterface` — public, media storage contract
- `Waaseyaa\Path\PathAliasManagerInterface` — public, URL alias contract
- `Waaseyaa\Relationship\VisibilityFilterInterface` — public, relationship visibility contract

**Layer 3 — Services (8 elements):**
- `Waaseyaa\Search\SearchProviderInterface` — public, search provider contract
- `Waaseyaa\Search\SearchIndexerInterface` — public, search indexing contract
- `Waaseyaa\Search\SearchIndexableInterface` — public, marks entities as searchable
- `Waaseyaa\Notification\NotificationInterface` — public, notification contract
- `Waaseyaa\Notification\NotifiableInterface` — public, marks entities as notifiable
- `Waaseyaa\Notification\NotifiableTrait` — public, convenience trait
- `Waaseyaa\Notification\ChannelInterface` — public, notification channel contract
- `Waaseyaa\Billing\StripeClientInterface` — internal, vendor-specific implementation detail

**Layer 4 — API (3 elements):**
- `Waaseyaa\Api\JsonResponseTrait` — public, shared JSON response helpers
- `Waaseyaa\Api\MutableTranslatableInterface` — public, translation mutation contract
- `Waaseyaa\Routing\Language\LanguageNegotiatorInterface` — public, language negotiation contract

- [ ] **Step 1: Add all 14 L2-L4 entries to surface map**

- [ ] **Step 2: Add `@internal` to StripeClientInterface**

File: `packages/billing/src/StripeClientInterface.php`

- [ ] **Step 3: Run verification test**

Run: `./vendor/bin/phpunit tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php --filter every_public_element_has_a_disposition`
Expected: Only L5-L6 remain unmapped

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add docs/public-surface-map.php packages/billing/ packages/media/ packages/path/ packages/relationship/ packages/search/ packages/notification/ packages/api/ packages/routing/
git commit -m "refactor(#M4): L2-L4 surface triage — 13 public, 1 internal"
```

---

### Task 6: L5-L6 Surface Triage and Annotation

**Files:**
- Modify: `docs/public-surface-map.php` (add 21 entries)
- Modify: ~4 files for `@internal` annotations

**Layer 5 — AI (9 elements):**
- `Waaseyaa\AI\Agent\AgentInterface` — public, AI agent contract
- `Waaseyaa\AI\Agent\ToolRegistryInterface` — public, tool registry for agents
- `Waaseyaa\AI\Agent\Provider\ProviderInterface` — public, LLM provider contract
- `Waaseyaa\AI\Agent\Provider\StreamingProviderInterface` — public, streaming LLM contract
- `Waaseyaa\AI\Pipeline\PipelineStepInterface` — public, AI pipeline step contract
- `Waaseyaa\AI\Vector\VectorStoreInterface` — public, vector store contract
- `Waaseyaa\AI\Vector\EmbeddingProviderInterface` — public, embedding provider contract
- `Waaseyaa\AI\Vector\EmbeddingInterface` — public, embedding contract
- `Waaseyaa\AI\Vector\EmbeddingStorageInterface` — public, embedding storage contract

**Layer 6 — Interfaces (12 elements):**
- `Waaseyaa\Cli\Ingestion\SourceConnectorInterface` — public, ingestion source connector
- `Waaseyaa\AdminSurface\Action\SurfaceActionHandler` — public, admin action contract
- `Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost` (abstract) — public, admin host base
- `Waaseyaa\Mcp\Bridge\ToolExecutorInterface` — public, MCP tool execution contract
- `Waaseyaa\Mcp\Bridge\ToolRegistryInterface` — public, MCP tool registry
- `Waaseyaa\Mcp\Auth\McpAuthInterface` — public, MCP authentication contract
- `Waaseyaa\Ssr\ThemeInterface` — public, SSR theme contract
- `Waaseyaa\Telescope\Storage\TelescopeStoreInterface` — internal, telescope-specific storage
- `Waaseyaa\Telescope\CodifiedContext\Validator\EmbeddingProviderInterface` — internal, telescope-specific embedding
- `Waaseyaa\Telescope\CodifiedContext\Storage\CodifiedContextStoreInterface` — internal, telescope-specific storage
- `Waaseyaa\Cli\Command\Make\AbstractMakeCommand` (abstract) — internal, CLI scaffolding base
- `Waaseyaa\Mcp\Tools\McpTool` (abstract) — internal, MCP tool base class

- [ ] **Step 1: Add all 21 L5-L6 entries to surface map**

- [ ] **Step 2: Add `@internal` to the 4 internal elements**

Files to modify:
- `packages/telescope/src/Storage/TelescopeStoreInterface.php`
- `packages/telescope/src/CodifiedContext/Validator/EmbeddingProviderInterface.php`
- `packages/telescope/src/CodifiedContext/Storage/CodifiedContextStoreInterface.php`
- `packages/cli/src/Command/Make/AbstractMakeCommand.php`

Note: `McpTool` abstract class — check if consumers extend this directly. If yes, promote to public. If only internal MCP tools extend it, mark internal.

- [ ] **Step 3: Run verification test — all 144 elements should now be mapped**

Run: `./vendor/bin/phpunit tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php`
Expected: ALL THREE tests pass

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add docs/public-surface-map.php packages/ai-agent/ packages/ai-pipeline/ packages/ai-vector/ packages/cli/ packages/admin-surface/ packages/mcp/ packages/ssr/ packages/telescope/
git commit -m "refactor(#M4): L5-L6 surface triage — 17 public, 4 internal"
```

---

### Task 7: Generate Human-Readable Surface Map

**Files:**
- Create: `docs/public-surface-map.md`

The PHP file is machine-readable for tests. The markdown file is the human-readable v1 contract reference.

- [ ] **Step 1: Generate markdown surface map from the PHP map**

Create `docs/public-surface-map.md` with all public elements organized by layer and package:

```markdown
# Waaseyaa Public Surface Map

This document lists every intentionally public API element in the Waaseyaa framework.
Elements not listed here are `@internal` and may change without notice.

Generated from `docs/public-surface-map.php`. Verified by `PublicSurfaceVerificationTest`.

## Layer 0: Foundation

### foundation
| Element | Type | Purpose |
|---------|------|---------|
| `AssetManagerInterface` | interface | Theme asset management |
| `BroadcasterInterface` | interface | Event broadcasting |
...
```

Include all public elements (115 public, 29 internal based on triage above). Group by layer, then package, with type and one-line purpose.

- [ ] **Step 2: Commit**

```bash
git add docs/public-surface-map.md
git commit -m "docs(#M4): add human-readable public surface map"
```

---

### Task 8: Update Subsystem Specs

**Files:**
- Modify: `docs/specs/infrastructure.md` — add promoted interfaces (SchemaRegistry, RateLimiter, AssetManager, HealthChecker)
- Modify: `docs/specs/entity-system.md` — confirm EntityEventFactoryInterface is documented
- Modify: `docs/specs/access-control.md` — note ErrorPageRendererInterface is internal
- Modify: `docs/specs/middleware-pipeline.md` — confirm EventHandlerInterface coverage

- [ ] **Step 1: Update infrastructure.md**

Add sections for newly promoted interfaces that were underspecified:
- SchemaRegistryInterface — what it does, method signatures, when to use
- RateLimiterInterface — contract, how consumers implement
- AssetManagerInterface — theme asset management contract
- HealthCheckerInterface — operator diagnostics contract

- [ ] **Step 2: Update entity-system.md**

Verify EntityEventFactoryInterface and EntityRepositoryInterface are documented. Add if missing.

- [ ] **Step 3: Update access-control.md**

Note that ErrorPageRendererInterface is `@internal`. Ensure the public contracts (AccessPolicyInterface, FieldAccessPolicyInterface, AccountInterface, GateInterface, PermissionHandlerInterface) are all documented.

- [ ] **Step 4: Run drift detector**

Run: `tools/drift-detector.sh`
Expected: All specs up to date

- [ ] **Step 5: Commit**

```bash
git add docs/specs/
git commit -m "docs(#M4): update specs to reflect surface triage decisions"
```

---

### Task 9: Close Governance Issues on waaseyaa/framework

**Files:**
- None (GitHub operations only)

- [ ] **Step 1: Add comment to each of the 13 M4 governance issues**

On each issue (#915-#928), add a comment:

```
Superseded by concrete M4 issues on waaseyaa/waaseyaa. The public surface triage is complete with 115 public and 29 internal dispositions across 144 API elements. See waaseyaa/waaseyaa docs/public-surface-map.md for the authoritative surface.
```

- [ ] **Step 2: Close all 13 issues**

```bash
for issue_num in 915 916 917 918 919 920 921 922 923 924 925 926 927 928; do
  gh issue close "$issue_num" --repo waaseyaa/framework --comment "Superseded by concrete M4 execution on waaseyaa/waaseyaa. Surface map complete."
done
```

- [ ] **Step 3: Verify M4 milestone is clear**

```bash
gh issue list --repo waaseyaa/framework --milestone "M4: Public Surface Unification" --state open
```
Expected: 0 open issues

---

## Verification Checklist

After all tasks complete:

- [ ] `./vendor/bin/phpunit tests/Integration/SurfaceMap/PublicSurfaceVerificationTest.php` — all 3 tests pass
- [ ] `./vendor/bin/phpunit` — full suite passes
- [ ] `composer phpstan` — static analysis passes
- [ ] `composer cs-check` — code style passes
- [ ] `docs/public-surface-map.md` exists with all public elements
- [ ] `docs/public-surface-map.php` has 144 entries
- [ ] All 13 governance issues on waaseyaa/framework are closed
- [ ] M4 milestone on waaseyaa/waaseyaa has all issues closed
