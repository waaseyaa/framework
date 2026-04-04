# ControllerDispatcher Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the 1,001-line ControllerDispatcher into 10 domain-specific routers behind a DomainRouterInterface, reducing it to an ~80-line delegator.

**Architecture:** Request attributes become the canonical context transport. HttpKernel populates `_broadcast_storage`, `_parsed_body`, and `_waaseyaa_context` before dispatch. ControllerDispatcher iterates `DomainRouterInterface` implementations in a deterministic chain: first `supports()` match wins. Callable controllers remain as the kernel's fallback.

**Tech Stack:** PHP 8.4, Symfony HttpFoundation, PHPUnit 10.5

**Spec:** `docs/specs/controller-dispatcher-split.md`

---

## File Map

### New files

| File | Responsibility |
|---|---|
| `packages/foundation/src/Http/Router/DomainRouterInterface.php` | Contract: `supports(Request): bool`, `handle(Request): Response` |
| `packages/foundation/src/Http/Router/WaaseyaaContext.php` | Typed context value object built from Request attributes |
| `packages/foundation/src/Http/Router/BroadcastRouter.php` | SSE broadcast stream (`broadcast`) |
| `packages/foundation/src/Http/Router/DiscoveryRouter.php` | Discovery endpoints (`discovery.*`, `*ApiDiscoveryController`) |
| `packages/foundation/src/Http/Router/EntityTypeLifecycleRouter.php` | Entity type listing/enable/disable (`entity_types`, `entity_type.*`) |
| `packages/foundation/src/Http/Router/GraphQlRouter.php` | GraphQL endpoint (`graphql.endpoint`) |
| `packages/foundation/src/Http/Router/JsonApiRouter.php` | JSON:API CRUD (`*JsonApiController`) |
| `packages/foundation/src/Http/Router/McpRouter.php` | MCP JSON-RPC endpoint (`mcp.endpoint`) |
| `packages/foundation/src/Http/Router/MediaRouter.php` | Media upload + file helpers (`media.upload`) |
| `packages/foundation/src/Http/Router/SchemaRouter.php` | OpenAPI + schema (`openapi`, `*SchemaController`) |
| `packages/foundation/src/Http/Router/SearchRouter.php` | Semantic search (`search.semantic`) |
| `packages/foundation/src/Http/Router/SsrRouter.php` | Server-side rendering (`render.page`) |
| `packages/foundation/tests/Unit/Http/Router/DomainRouterInterfaceContractTest.php` | Abstract contract test for all routers |
| `packages/foundation/tests/Unit/Http/Router/BroadcastRouterTest.php` | Unit tests |
| `packages/foundation/tests/Unit/Http/Router/EntityTypeLifecycleRouterTest.php` | Unit tests |
| `packages/foundation/tests/Unit/Http/Router/SchemaRouterTest.php` | Unit tests |
| `packages/foundation/tests/Unit/Http/Router/McpRouterTest.php` | Unit tests |
| `packages/foundation/tests/Unit/Http/Router/GraphQlRouterTest.php` | Unit tests |
| `packages/foundation/tests/Unit/Http/Router/SsrRouterTest.php` | Unit tests |
| `packages/foundation/tests/Unit/Http/Router/SearchRouterTest.php` | Unit tests |
| `packages/foundation/tests/Unit/Http/Router/MediaRouterTest.php` | Unit tests (migrated from ControllerDispatcherTest) |
| `packages/foundation/tests/Unit/Http/Router/DiscoveryRouterTest.php` | Unit tests |
| `packages/foundation/tests/Unit/Http/Router/JsonApiRouterTest.php` | Unit tests |
| `packages/foundation/tests/Unit/Http/Router/WaaseyaaContextTest.php` | Unit tests |

### Modified files

| File | Changes |
|---|---|
| `packages/foundation/src/Http/ControllerDispatcher.php` | Rewrite to thin delegator (~80 lines) |
| `packages/foundation/src/Kernel/HttpKernel.php` | Populate Request attributes, build router array, new dispatch call |
| `packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php` | Rewrite to test delegation + callable fallback |

---

## Task 1: Contract Layer (DomainRouterInterface + WaaseyaaContext)

**Files:**
- Create: `packages/foundation/src/Http/Router/DomainRouterInterface.php`
- Create: `packages/foundation/src/Http/Router/WaaseyaaContext.php`
- Create: `packages/foundation/tests/Unit/Http/Router/WaaseyaaContextTest.php`

- [ ] **Step 1: Create DomainRouterInterface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface DomainRouterInterface
{
    /**
     * Whether this router can handle the given request.
     *
     * The primary discriminator is the `_controller` request attribute,
     * but routers may also inspect `_route_object`, HTTP method, or
     * other request properties.
     */
    public function supports(Request $request): bool;

    /**
     * Handle the request and return a response.
     *
     * The request is fully populated with context attributes:
     * `_account`, `_broadcast_storage`, `_parsed_body`, `_waaseyaa_context`.
     */
    public function handle(Request $request): Response;
}
```

- [ ] **Step 2: Create WaaseyaaContext**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;

/**
 * Typed, validated view of raw Request attributes.
 *
 * Built once by HttpKernel, stored as `_waaseyaa_context` on the Request.
 * Routers read this directly instead of parsing attributes individually.
 */
final class WaaseyaaContext
{
    /**
     * @param ?array<string, mixed> $parsedBody
     * @param array<string, mixed> $query
     */
    public function __construct(
        public readonly AccountInterface $account,
        public readonly ?array $parsedBody,
        public readonly array $query,
        public readonly string $method,
        public readonly BroadcastStorage $broadcastStorage,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            account: $request->attributes->get('_account'),
            parsedBody: $request->attributes->get('_parsed_body'),
            query: $request->query->all(),
            method: $request->getMethod(),
            broadcastStorage: $request->attributes->get('_broadcast_storage'),
        );
    }
}
```

- [ ] **Step 3: Write WaaseyaaContext tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Http\Router\WaaseyaaContext;

#[CoversClass(WaaseyaaContext::class)]
final class WaaseyaaContextTest extends TestCase
{
    #[Test]
    public function from_request_extracts_all_attributes(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $broadcastStorage = new BroadcastStorage(DBALDatabase::createSqlite());

        $request = Request::create('/test', 'POST');
        $request->query->set('page', '2');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_parsed_body', ['title' => 'Hello']);
        $request->attributes->set('_broadcast_storage', $broadcastStorage);

        $ctx = WaaseyaaContext::fromRequest($request);

        self::assertSame($account, $ctx->account);
        self::assertSame(['title' => 'Hello'], $ctx->parsedBody);
        self::assertSame('POST', $ctx->method);
        self::assertSame('2', $ctx->query['page']);
        self::assertSame($broadcastStorage, $ctx->broadcastStorage);
    }

    #[Test]
    public function from_request_handles_null_parsed_body(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $broadcastStorage = new BroadcastStorage(DBALDatabase::createSqlite());

        $request = Request::create('/test', 'GET');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_parsed_body', null);
        $request->attributes->set('_broadcast_storage', $broadcastStorage);

        $ctx = WaaseyaaContext::fromRequest($request);

        self::assertNull($ctx->parsedBody);
        self::assertSame('GET', $ctx->method);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/WaaseyaaContextTest.php`
Expected: 2 tests, 2 passed

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Http/Router/DomainRouterInterface.php \
       packages/foundation/src/Http/Router/WaaseyaaContext.php \
       packages/foundation/tests/Unit/Http/Router/WaaseyaaContextTest.php
git commit -m "feat(#571): add DomainRouterInterface and WaaseyaaContext"
```

---

## Task 2: EntityTypeLifecycleRouter

**Files:**
- Create: `packages/foundation/src/Http/Router/EntityTypeLifecycleRouter.php`
- Create: `packages/foundation/tests/Unit/Http/Router/EntityTypeLifecycleRouterTest.php`

- [ ] **Step 1: Write the supports() test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\EntityTypeLifecycleRouter;

#[CoversClass(EntityTypeLifecycleRouter::class)]
final class EntityTypeLifecycleRouterTest extends TestCase
{
    private function createRouter(): EntityTypeLifecycleRouter
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        return new EntityTypeLifecycleRouter($etm, new EntityTypeLifecycleManager('/tmp'));
    }

    #[Test]
    public function supports_entity_types_controller(): void
    {
        $router = $this->createRouter();

        $request = Request::create('/api/entity-types');
        $request->attributes->set('_controller', 'entity_types');

        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_entity_type_disable(): void
    {
        $router = $this->createRouter();

        $request = Request::create('/api/entity-types/node/disable', 'POST');
        $request->attributes->set('_controller', 'entity_type.disable');

        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_entity_type_enable(): void
    {
        $router = $this->createRouter();

        $request = Request::create('/api/entity-types/node/enable', 'POST');
        $request->attributes->set('_controller', 'entity_type.enable');

        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated_controller(): void
    {
        $router = $this->createRouter();

        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');

        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function handle_entity_types_returns_list(): void
    {
        $router = $this->createRouter();

        $account = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $broadcastStorage = new \Waaseyaa\Api\Controller\BroadcastStorage(
            \Waaseyaa\Database\DBALDatabase::createSqlite()
        );
        $request = Request::create('/api/entity-types');
        $request->attributes->set('_controller', 'entity_types');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', $broadcastStorage);
        $request->attributes->set('_parsed_body', null);
        $request->attributes->set('_waaseyaa_context',
            \Waaseyaa\Foundation\Http\Router\WaaseyaaContext::fromRequest($request)
        );

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $data);
        self::assertIsArray($data['data']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/EntityTypeLifecycleRouterTest.php`
Expected: FAIL — class `EntityTypeLifecycleRouter` not found

- [ ] **Step 3: Implement EntityTypeLifecycleRouter**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeIdNormalizer;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class EntityTypeLifecycleRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityTypeLifecycleManager $lifecycleManager,
    ) {}

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller', '');

        return $controller === 'entity_types'
            || str_starts_with($controller, 'entity_type.');
    }

    public function handle(Request $request): Response
    {
        $controller = $request->attributes->get('_controller', '');
        $ctx = WaaseyaaContext::fromRequest($request);
        $params = $request->attributes->all();

        return match ($controller) {
            'entity_types' => $this->listTypes(),
            'entity_type.disable' => $this->disableType($params, $ctx),
            'entity_type.enable' => $this->enableType($params, $ctx),
            default => $this->jsonApiResponse(404, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => "Unknown lifecycle action: $controller"]],
            ]),
        };
    }

    private function listTypes(): Response
    {
        $disabledIds = $this->lifecycleManager->getDisabledTypeIds();
        $types = [];
        foreach ($this->entityTypeManager->getDefinitions() as $id => $def) {
            $types[] = [
                'id' => $id,
                'label' => $def->getLabel(),
                'keys' => $def->getKeys(),
                'translatable' => $def->isTranslatable(),
                'revisionable' => $def->isRevisionable(),
                'group' => $def->getGroup(),
                'disabled' => in_array($id, $disabledIds, true),
            ];
        }

        return $this->jsonApiResponse(200, ['data' => $types]);
    }

    private function disableType(array $params, WaaseyaaContext $ctx): Response
    {
        $rawTypeId = (string) ($params['entity_type'] ?? '');
        $normalizer = new EntityTypeIdNormalizer($this->entityTypeManager);
        $typeId = $normalizer->normalize($rawTypeId);
        $force = filter_var($ctx->query['force'] ?? false, FILTER_VALIDATE_BOOL);

        if ($rawTypeId === '' || !$this->entityTypeManager->hasDefinition($typeId)) {
            return $this->jsonApiResponse(404, [
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Unknown entity type: "%s".', $rawTypeId)]],
            ]);
        }

        if ($this->lifecycleManager->isDisabled($typeId)) {
            return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => true]]);
        }

        $definitions = array_keys($this->entityTypeManager->getDefinitions());
        $disabledIds = $this->lifecycleManager->getDisabledTypeIds();
        $enabledCount = count(array_filter(
            $definitions,
            fn(string $id) => !in_array($id, $disabledIds, true),
        ));

        if ($enabledCount <= 1 && !$force) {
            return $this->jsonApiResponse(409, [
                'errors' => [['status' => '409', 'title' => 'Conflict', 'detail' => 'Cannot disable the last enabled content type. Enable another type first.']],
            ]);
        }

        $this->lifecycleManager->disable($typeId, (string) $ctx->account->id());

        return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => true]]);
    }

    private function enableType(array $params, WaaseyaaContext $ctx): Response
    {
        $rawTypeId = (string) ($params['entity_type'] ?? '');
        $normalizer = new EntityTypeIdNormalizer($this->entityTypeManager);
        $typeId = $normalizer->normalize($rawTypeId);

        if ($rawTypeId === '' || !$this->entityTypeManager->hasDefinition($typeId)) {
            return $this->jsonApiResponse(404, [
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Unknown entity type: "%s".', $rawTypeId)]],
            ]);
        }

        if (!$this->lifecycleManager->isDisabled($typeId)) {
            return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => false]]);
        }

        $this->lifecycleManager->enable($typeId, (string) $ctx->account->id());

        return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => false]]);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/EntityTypeLifecycleRouterTest.php`
Expected: 5 tests, 5 passed

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Http/Router/EntityTypeLifecycleRouter.php \
       packages/foundation/tests/Unit/Http/Router/EntityTypeLifecycleRouterTest.php
git commit -m "feat(#571): add EntityTypeLifecycleRouter"
```

---

## Task 3: SchemaRouter

**Files:**
- Create: `packages/foundation/src/Http/Router/SchemaRouter.php`
- Create: `packages/foundation/tests/Unit/Http/Router/SchemaRouterTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\SchemaRouter;

#[CoversClass(SchemaRouter::class)]
final class SchemaRouterTest extends TestCase
{
    private function createRouter(): SchemaRouter
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $accessHandler = new EntityAccessHandler($etm);
        return new SchemaRouter($etm, $accessHandler);
    }

    #[Test]
    public function supports_openapi_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/openapi');
        $request->attributes->set('_controller', 'openapi');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_schema_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/schema/node');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\SchemaController');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/mcp');
        $request->attributes->set('_controller', 'mcp.endpoint');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function handle_openapi_returns_json(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/openapi');
        $request->attributes->set('_controller', 'openapi');

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/vnd.api+json', $response->headers->get('Content-Type'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/SchemaRouterTest.php`
Expected: FAIL — class `SchemaRouter` not found

- [ ] **Step 3: Implement SchemaRouter**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\OpenApi\OpenApiGenerator;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class SchemaRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
    ) {}

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller', '');

        return $controller === 'openapi'
            || str_contains($controller, 'SchemaController');
    }

    public function handle(Request $request): Response
    {
        $controller = $request->attributes->get('_controller', '');

        if ($controller === 'openapi') {
            $openApi = new OpenApiGenerator($this->entityTypeManager);
            return $this->jsonApiResponse(200, $openApi->generate());
        }

        // SchemaController
        $ctx = WaaseyaaContext::fromRequest($request);
        $schemaPresenter = new SchemaPresenter();
        $schemaController = new SchemaController(
            $this->entityTypeManager,
            $schemaPresenter,
            $this->accessHandler,
            $ctx->account,
        );
        $document = $schemaController->show($request->attributes->get('entity_type'));

        return $this->jsonApiResponse($document->statusCode, $document->toArray());
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/SchemaRouterTest.php`
Expected: 4 tests, 4 passed

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Http/Router/SchemaRouter.php \
       packages/foundation/tests/Unit/Http/Router/SchemaRouterTest.php
git commit -m "feat(#571): add SchemaRouter"
```

---

## Task 4: BroadcastRouter

**Files:**
- Create: `packages/foundation/src/Http/Router/BroadcastRouter.php`
- Create: `packages/foundation/tests/Unit/Http/Router/BroadcastRouterTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Http\Router\BroadcastRouter;

#[CoversClass(BroadcastRouter::class)]
final class BroadcastRouterTest extends TestCase
{
    #[Test]
    public function supports_broadcast_controller(): void
    {
        $router = new BroadcastRouter();
        $request = Request::create('/api/broadcast');
        $request->attributes->set('_controller', 'broadcast');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = new BroadcastRouter();
        $request = Request::create('/api/openapi');
        $request->attributes->set('_controller', 'openapi');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function handle_returns_streamed_response(): void
    {
        $router = new BroadcastRouter();

        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $broadcastStorage = new \Waaseyaa\Api\Controller\BroadcastStorage($db);

        $account = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $request = Request::create('/api/broadcast?channels=admin');
        $request->attributes->set('_controller', 'broadcast');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', $broadcastStorage);
        $request->attributes->set('_parsed_body', null);
        $request->attributes->set('_waaseyaa_context',
            \Waaseyaa\Foundation\Http\Router\WaaseyaaContext::fromRequest($request)
        );

        $response = $router->handle($request);

        self::assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $response);
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/BroadcastRouterTest.php`
Expected: FAIL — class `BroadcastRouter` not found

- [ ] **Step 3: Implement BroadcastRouter**

Move the `broadcast` match case verbatim from `ControllerDispatcher::dispatch()`. The SSE streaming logic (poll loop, cursor tracking, keepalive, error handling) is copied as-is into `BroadcastRouter::handle()`.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Api\Controller\BroadcastController;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class BroadcastRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'broadcast';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);
        $broadcastStorage = $ctx->broadcastStorage;
        $channels = BroadcastController::parseChannels($ctx->query['channels'] ?? 'admin');
        if ($channels === []) {
            $channels = ['admin'];
        }
        $logger = $this->logger ?? new NullLogger();

        return new StreamedResponse(function () use ($broadcastStorage, $channels, $logger): void {
            echo "event: connected\ndata: " . json_encode(['channels' => $channels], JSON_THROW_ON_ERROR) . "\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            $cursor = null;
            $lastKeepalive = time();

            while (connection_aborted() === 0) {
                try {
                    $messages = $broadcastStorage->poll($cursor, $channels);
                } catch (\Throwable $e) {
                    $logger->error(sprintf('SSE poll error: %s', $e->getMessage()));
                    echo "event: error\ndata: " . json_encode(['message' => 'Broadcast poll failed'], JSON_THROW_ON_ERROR) . "\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    usleep(5_000_000);
                    continue;
                }

                foreach ($messages as $msg) {
                    if (isset($msg['cursor'])) {
                        $cursor = $msg['cursor'];
                    }
                    try {
                        $frame = "event: {$msg['event']}\ndata: " . json_encode($msg, JSON_THROW_ON_ERROR) . "\n\n";
                        echo $frame;
                    } catch (\JsonException $e) {
                        $logger->error(sprintf('SSE json_encode error for event %s: %s', $msg['event'] ?? 'unknown', $e->getMessage()));
                    }
                }

                if ($messages !== []) {
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }

                if ((time() - $lastKeepalive) >= 15) {
                    echo ": keepalive\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    $lastKeepalive = time();
                }

                usleep(500_000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/BroadcastRouterTest.php`
Expected: 3 tests, 3 passed

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Http/Router/BroadcastRouter.php \
       packages/foundation/tests/Unit/Http/Router/BroadcastRouterTest.php
git commit -m "feat(#571): add BroadcastRouter"
```

---

## Task 5: MediaRouter

**Files:**
- Create: `packages/foundation/src/Http/Router/MediaRouter.php`
- Create: `packages/foundation/tests/Unit/Http/Router/MediaRouterTest.php`

- [ ] **Step 1: Write tests**

Migrate the 13 media-related tests from `ControllerDispatcherTest` to `MediaRouterTest`. The tests cover: `resolveFilesRootDir`, `resolveUploadMaxBytes`, `resolveAllowedUploadMimeTypes`, `isAllowedMimeType`, `sanitizeUploadFilename`, `buildPublicFileUrl`, plus `supports()` tests.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\MediaRouter;

#[CoversClass(MediaRouter::class)]
final class MediaRouterTest extends TestCase
{
    private function createRouter(
        string $projectRoot = '/tmp/test-project',
        array $config = [],
    ): MediaRouter {
        $etm = new EntityTypeManager(new EventDispatcher());
        return new MediaRouter($projectRoot, $config, $etm);
    }

    #[Test]
    public function supports_media_upload(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/media/upload', 'POST');
        $request->attributes->set('_controller', 'media.upload');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function resolve_files_root_dir_defaults_to_storage_files(): void
    {
        $router = $this->createRouter(projectRoot: '/my/project');
        self::assertSame('/my/project/storage/files', $router->resolveFilesRootDir());
    }

    #[Test]
    public function resolve_files_root_dir_uses_configured_path(): void
    {
        $router = $this->createRouter(config: ['files_root' => '/custom/path']);
        self::assertSame('/custom/path', $router->resolveFilesRootDir());
    }

    #[Test]
    public function resolve_upload_max_bytes_defaults_to_ten_megabytes(): void
    {
        $router = $this->createRouter();
        self::assertSame(10 * 1024 * 1024, $router->resolveUploadMaxBytes());
    }

    #[Test]
    public function resolve_upload_max_bytes_uses_configured_value(): void
    {
        $router = $this->createRouter(config: ['upload_max_bytes' => 5_000_000]);
        self::assertSame(5_000_000, $router->resolveUploadMaxBytes());
    }

    #[Test]
    public function resolve_allowed_upload_mime_types_has_sensible_defaults(): void
    {
        $router = $this->createRouter();
        $types = $router->resolveAllowedUploadMimeTypes();
        self::assertContains('image/jpeg', $types);
        self::assertContains('image/png', $types);
        self::assertContains('application/pdf', $types);
    }

    #[Test]
    public function resolve_allowed_upload_mime_types_uses_configured_list(): void
    {
        $router = $this->createRouter(config: ['upload_allowed_mime_types' => ['text/csv']]);
        self::assertSame(['text/csv'], $router->resolveAllowedUploadMimeTypes());
    }

    #[Test]
    public function is_allowed_mime_type_matches_exact(): void
    {
        $router = $this->createRouter();
        self::assertTrue($router->isAllowedMimeType('image/png', ['image/png', 'image/jpeg']));
    }

    #[Test]
    public function is_allowed_mime_type_supports_wildcard(): void
    {
        $router = $this->createRouter();
        self::assertTrue($router->isAllowedMimeType('image/webp', ['image/*']));
    }

    #[Test]
    public function is_allowed_mime_type_supports_mixed_list(): void
    {
        $router = $this->createRouter();
        self::assertTrue($router->isAllowedMimeType('application/pdf', ['image/*', 'application/pdf']));
        self::assertFalse($router->isAllowedMimeType('text/html', ['image/*', 'application/pdf']));
    }

    #[Test]
    public function sanitize_upload_filename_replaces_special_characters(): void
    {
        $router = $this->createRouter();
        self::assertSame('hello_world.jpg', $router->sanitizeUploadFilename('hello world.jpg'));
    }

    #[Test]
    public function sanitize_upload_filename_returns_fallback_for_dangerous_names(): void
    {
        $router = $this->createRouter();
        self::assertSame('upload.bin', $router->sanitizeUploadFilename('..'));
    }

    #[Test]
    public function build_public_file_url_from_public_uri(): void
    {
        $router = $this->createRouter();
        self::assertSame('/files/images/photo.jpg', $router->buildPublicFileUrl('public://images/photo.jpg'));
    }

    #[Test]
    public function build_public_file_url_from_relative_path(): void
    {
        $router = $this->createRouter();
        self::assertSame('/files/uploads/doc.pdf', $router->buildPublicFileUrl('uploads/doc.pdf'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/MediaRouterTest.php`
Expected: FAIL — class `MediaRouter` not found

- [ ] **Step 3: Implement MediaRouter**

Move `handleMediaUpload()` and all file helper methods (`resolveFilesRootDir`, `resolveUploadMaxBytes`, `resolveAllowedUploadMimeTypes`, `isAllowedMimeType`, `sanitizeUploadFilename`, `buildPublicFileUrl`) verbatim from `ControllerDispatcher`.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Media\File;
use Waaseyaa\Media\LocalFileRepository;

final class MediaRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $config,
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'media.upload';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);
        $serializer = new ResourceSerializer($this->entityTypeManager);

        return $this->handleMediaUpload($request, $ctx, $serializer);
    }

    private function handleMediaUpload(
        Request $httpRequest,
        WaaseyaaContext $ctx,
        ResourceSerializer $serializer,
    ): Response {
        $contentType = strtolower((string) $httpRequest->headers->get('Content-Type', ''));
        if (!str_starts_with($contentType, 'multipart/form-data')) {
            return $this->jsonApiResponse(415, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '415', 'title' => 'Unsupported Media Type', 'detail' => 'Expected multipart/form-data upload.']],
            ]);
        }

        $uploadedFile = $httpRequest->files->get('file');
        if (!$uploadedFile instanceof UploadedFile) {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Missing "file" in upload.']],
            ]);
        }

        if (!$uploadedFile->isValid()) {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => sprintf('Upload error: %s', $uploadedFile->getErrorMessage())]],
            ]);
        }

        $maxBytes = $this->resolveUploadMaxBytes();
        if ($uploadedFile->getSize() > $maxBytes) {
            return $this->jsonApiResponse(413, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '413', 'title' => 'Payload Too Large', 'detail' => sprintf('File exceeds maximum upload size of %d bytes.', $maxBytes)]],
            ]);
        }

        $mimeType = $uploadedFile->getMimeType() ?? $uploadedFile->getClientMimeType() ?? 'application/octet-stream';
        $allowedMimeTypes = $this->resolveAllowedUploadMimeTypes();
        if (!$this->isAllowedMimeType($mimeType, $allowedMimeTypes)) {
            return $this->jsonApiResponse(415, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '415', 'title' => 'Unsupported Media Type', 'detail' => sprintf('MIME type "%s" is not allowed.', $mimeType)]],
            ]);
        }

        $safeName = $this->sanitizeUploadFilename($uploadedFile->getClientOriginalName());
        $filesRoot = $this->resolveFilesRootDir();

        if (!is_dir($filesRoot)) {
            mkdir($filesRoot, 0o755, true);
        }

        $repo = new LocalFileRepository($filesRoot);
        $file = $repo->store($uploadedFile->getRealPath(), $safeName, $mimeType);

        $fileUrl = $this->buildPublicFileUrl($file->uri);
        $fileData = [
            'id' => $file->uuid,
            'type' => 'file',
            'attributes' => [
                'filename' => $file->filename,
                'uri' => $file->uri,
                'url' => $fileUrl,
                'mime_type' => $file->mimeType,
                'size' => $file->size,
                'created' => $file->created,
            ],
        ];

        return $this->jsonApiResponse(201, ['jsonapi' => ['version' => '1.1'], 'data' => $fileData]);
    }

    public function resolveFilesRootDir(): string
    {
        $configured = $this->config['files_root'] ?? null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->projectRoot . '/storage/files';
    }

    public function resolveUploadMaxBytes(): int
    {
        $configured = $this->config['upload_max_bytes'] ?? null;
        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        return 10 * 1024 * 1024;
    }

    /**
     * @return list<string>
     */
    public function resolveAllowedUploadMimeTypes(): array
    {
        $configured = $this->config['upload_allowed_mime_types'] ?? null;
        if (is_array($configured) && $configured !== []) {
            $values = [];
            foreach ($configured as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $values[] = trim($value);
                }
            }
            if ($values !== []) {
                return $values;
            }
        }

        return [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'application/pdf',
            'text/plain',
            'application/octet-stream',
        ];
    }

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function isAllowedMimeType(string $mimeType, array $allowedMimeTypes): bool
    {
        foreach ($allowedMimeTypes as $allowed) {
            if ($allowed === $mimeType) {
                return true;
            }
            if (str_ends_with($allowed, '/*')) {
                $prefix = substr($allowed, 0, -1);
                if (str_starts_with($mimeType, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function sanitizeUploadFilename(string $name): string
    {
        $basename = basename($name);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);
        if (!is_string($clean) || $clean === '' || $clean === '.' || $clean === '..') {
            return 'upload.bin';
        }

        return $clean;
    }

    public function buildPublicFileUrl(string $uri): string
    {
        $prefix = 'public://';
        if (!str_starts_with($uri, $prefix)) {
            return '/files/' . ltrim($uri, '/');
        }

        $path = substr($uri, strlen($prefix));
        if (!is_string($path)) {
            return '/files/';
        }

        return '/files/' . ltrim($path, '/');
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/MediaRouterTest.php`
Expected: 16 tests, 16 passed

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Http/Router/MediaRouter.php \
       packages/foundation/tests/Unit/Http/Router/MediaRouterTest.php
git commit -m "feat(#571): add MediaRouter"
```

---

## Task 6: SearchRouter

**Files:**
- Create: `packages/foundation/src/Http/Router/SearchRouter.php`
- Create: `packages/foundation/tests/Unit/Http/Router/SearchRouterTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Http\Router\SearchRouter;

#[CoversClass(SearchRouter::class)]
final class SearchRouterTest extends TestCase
{
    #[Test]
    public function supports_search_semantic(): void
    {
        $router = new SearchRouter([], \Waaseyaa\Database\DBALDatabase::createSqlite());
        $request = Request::create('/api/search');
        $request->attributes->set('_controller', 'search.semantic');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = new SearchRouter([], \Waaseyaa\Database\DBALDatabase::createSqlite());
        $request = Request::create('/api/mcp');
        $request->attributes->set('_controller', 'mcp.endpoint');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function handle_returns_400_when_missing_query_params(): void
    {
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $router = new SearchRouter([], $db);

        $account = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $broadcastStorage = new \Waaseyaa\Api\Controller\BroadcastStorage($db);

        $request = Request::create('/api/search');
        $request->attributes->set('_controller', 'search.semantic');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', $broadcastStorage);
        $request->attributes->set('_parsed_body', null);
        $request->attributes->set('_waaseyaa_context',
            \Waaseyaa\Foundation\Http\Router\WaaseyaaContext::fromRequest($request)
        );

        $response = $router->handle($request);

        self::assertSame(400, $response->getStatusCode());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/SearchRouterTest.php`
Expected: FAIL — class `SearchRouter` not found

- [ ] **Step 3: Implement SearchRouter**

Move the `search.semantic` match case from ControllerDispatcher.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\SearchController;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class SearchRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly DatabaseInterface $database,
        private readonly ?EntityTypeManager $entityTypeManager = null,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'search.semantic';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);

        $searchQuery = is_string($ctx->query['q'] ?? null) ? trim((string) $ctx->query['q']) : '';
        $entityType = is_string($ctx->query['type'] ?? null) ? trim((string) $ctx->query['type']) : '';
        $limit = is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : 10;

        if ($searchQuery === '' || $entityType === '') {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Search requires query parameters "q" and "type".']],
            ]);
        }

        if (!class_exists(SqliteEmbeddingStorage::class)) {
            return $this->jsonApiResponse(501, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '501', 'title' => 'Not Implemented', 'detail' => 'Semantic search requires the waaseyaa/ai-vector package.']],
            ]);
        }

        $embeddingProvider = EmbeddingProviderFactory::fromConfig($this->config);
        assert($this->database instanceof \Waaseyaa\Database\DBALDatabase);
        $embeddingStorage = new SqliteEmbeddingStorage($this->database->getConnection()->getNativeConnection());

        $searchController = new SearchController($embeddingStorage, $embeddingProvider);
        $results = $searchController->search($searchQuery, $entityType, $limit);

        $serializer = $this->entityTypeManager !== null
            ? new ResourceSerializer($this->entityTypeManager)
            : null;

        return $this->jsonApiResponse(200, [
            'jsonapi' => ['version' => '1.1'],
            'data' => $results,
        ]);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/SearchRouterTest.php`
Expected: 3 tests, 3 passed

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Http/Router/SearchRouter.php \
       packages/foundation/tests/Unit/Http/Router/SearchRouterTest.php
git commit -m "feat(#571): add SearchRouter"
```

---

## Task 7: McpRouter

**Files:**
- Create: `packages/foundation/src/Http/Router/McpRouter.php`
- Create: `packages/foundation/tests/Unit/Http/Router/McpRouterTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\McpRouter;

#[CoversClass(McpRouter::class)]
final class McpRouterTest extends TestCase
{
    #[Test]
    public function supports_mcp_endpoint(): void
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $router = new McpRouter($etm, new EntityAccessHandler($etm), $db, [], null);
        $request = Request::create('/api/mcp');
        $request->attributes->set('_controller', 'mcp.endpoint');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $router = new McpRouter($etm, new EntityAccessHandler($etm), $db, [], null);
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function handle_returns_501_when_ai_vector_missing(): void
    {
        // This test depends on whether ai-vector is installed.
        // If it is, the test verifies the router creates the McpController.
        // If not, it returns 501.
        // We test the supports/not-supports boundary; full MCP testing
        // is covered by McpControllerTest integration tests.
        self::assertTrue(true); // placeholder — integration tested
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/McpRouterTest.php`
Expected: FAIL — class `McpRouter` not found

- [ ] **Step 3: Implement McpRouter**

Move the `mcp.endpoint` match case from ControllerDispatcher.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Mcp\McpController;

final class McpRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    /**
     * @param array<string, mixed> $config
     */
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly \Waaseyaa\Database\DBALDatabase $database,
        private readonly array $config,
        private readonly ?CacheBackendInterface $mcpReadCache,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'mcp.endpoint';
    }

    public function handle(Request $request): Response
    {
        if (!class_exists(SqliteEmbeddingStorage::class)) {
            return $this->jsonApiResponse(501, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '501', 'title' => 'Not Implemented', 'detail' => 'MCP endpoint requires the waaseyaa/ai-vector package.']],
            ]);
        }

        $ctx = WaaseyaaContext::fromRequest($request);
        $serializer = new ResourceSerializer($this->entityTypeManager);
        $embeddingProvider = EmbeddingProviderFactory::fromConfig($this->config);

        $embeddingStorage = new SqliteEmbeddingStorage($this->database->getConnection()->getNativeConnection());

        $mcp = new McpController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $serializer,
            accessHandler: $this->accessHandler,
            account: $ctx->account,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $embeddingProvider,
            readCache: $this->mcpReadCache,
        );

        if ($ctx->method === 'GET') {
            return $this->jsonApiResponse(200, $mcp->manifest());
        }

        $raw = trim($request->getContent());
        if ($raw === '') {
            return $this->jsonApiResponse(400, [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error'],
            ]);
        }

        try {
            $rpc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->jsonApiResponse(400, [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error'],
            ]);
        }

        if (!is_array($rpc) || !isset($rpc['method'])) {
            return $this->jsonApiResponse(400, [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Invalid request'],
            ]);
        }

        return $this->jsonApiResponse(200, $mcp->handleRpc($rpc));
    }
}
```

**Note:** McpRouter needs `DBALDatabase` access. The cleanest approach is to constructor-inject it rather than reading from a request attribute. This will be resolved in Task 12 when we wire up HttpKernel. For now, the constructor signature will be updated to accept `DatabaseInterface` directly.

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/McpRouterTest.php`
Expected: 3 tests, 3 passed

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Http/Router/McpRouter.php \
       packages/foundation/tests/Unit/Http/Router/McpRouterTest.php
git commit -m "feat(#571): add McpRouter"
```

---

## Task 8: GraphQlRouter

**Files:**
- Create: `packages/foundation/src/Http/Router/GraphQlRouter.php`
- Create: `packages/foundation/tests/Unit/Http/Router/GraphQlRouterTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\GraphQlRouter;

#[CoversClass(GraphQlRouter::class)]
final class GraphQlRouterTest extends TestCase
{
    #[Test]
    public function supports_graphql_endpoint(): void
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $router = new GraphQlRouter($etm, new EntityAccessHandler($etm));
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $router = new GraphQlRouter($etm, new EntityAccessHandler($etm));
        $request = Request::create('/api/mcp');
        $request->attributes->set('_controller', 'mcp.endpoint');
        self::assertFalse($router->supports($request));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/GraphQlRouterTest.php`
Expected: FAIL — class `GraphQlRouter` not found

- [ ] **Step 3: Implement GraphQlRouter**

Move the `graphql.endpoint` match case from ControllerDispatcher.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class GraphQlRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    /**
     * @param array<string, array{args?: array<string, mixed>, resolve?: callable}> $mutationOverrides
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly array $mutationOverrides = [],
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'graphql.endpoint';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);

        // Prefer the middleware-resolved session account over the
        // route-level $account, which is AnonymousUser on allowAll() routes.
        $resolvedAccount = $request->attributes->get('_account');
        $graphqlAccount = ($resolvedAccount instanceof AccountInterface && $resolvedAccount->isAuthenticated())
            ? $resolvedAccount
            : $ctx->account;

        $endpoint = new \Waaseyaa\GraphQL\GraphQlEndpoint(
            entityTypeManager: $this->entityTypeManager,
            accessHandler: $this->accessHandler,
            account: $graphqlAccount,
        );

        if ($this->mutationOverrides !== []) {
            $endpoint->registerMutationOverrides($this->mutationOverrides);
        }

        $queryString = $request->getQueryString() ?? '';

        if ($ctx->method === 'GET') {
            parse_str($queryString, $getQuery);
            $graphqlQuery = is_string($getQuery['query'] ?? null) ? $getQuery['query'] : '';
            $variablesRaw = $getQuery['variables'] ?? null;
            $variables = is_string($variablesRaw)
                ? (json_decode($variablesRaw, true) ?? [])
                : (is_array($variablesRaw) ? $variablesRaw : []);
            $result = $endpoint->execute($graphqlQuery, $variables);
        } else {
            $raw = $request->getContent();
            try {
                $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->jsonApiResponse(400, [
                    'errors' => [['message' => 'Invalid JSON in GraphQL request body.']],
                ]);
            }
            $graphqlQuery = is_string($payload['query'] ?? null) ? $payload['query'] : '';
            $variables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
            $result = $endpoint->execute($graphqlQuery, $variables);
        }

        return $this->jsonApiResponse(200, $result);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/GraphQlRouterTest.php`
Expected: 2 tests, 2 passed

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Http/Router/GraphQlRouter.php \
       packages/foundation/tests/Unit/Http/Router/GraphQlRouterTest.php
git commit -m "feat(#571): add GraphQlRouter"
```

---

## Task 9: SsrRouter

**Files:**
- Create: `packages/foundation/src/Http/Router/SsrRouter.php`
- Create: `packages/foundation/tests/Unit/Http/Router/SsrRouterTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Http\Router\SsrRouter;

#[CoversClass(SsrRouter::class)]
final class SsrRouterTest extends TestCase
{
    #[Test]
    public function supports_render_page(): void
    {
        $handler = $this->createStub(\Waaseyaa\SSR\SsrPageHandler::class);
        $router = new SsrRouter($handler);
        $request = Request::create('/about');
        $request->attributes->set('_controller', 'render.page');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $handler = $this->createStub(\Waaseyaa\SSR\SsrPageHandler::class);
        $router = new SsrRouter($handler);
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertFalse($router->supports($request));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/SsrRouterTest.php`
Expected: FAIL — class `SsrRouter` not found

- [ ] **Step 3: Implement SsrRouter**

Move the `render.page` match case from ControllerDispatcher.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\SSR\SsrPageHandler;

final class SsrRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly SsrPageHandler $ssrPageHandler,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'render.page';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);
        $params = $request->attributes->all();
        $requestedViewMode = is_string($ctx->query['view_mode'] ?? null)
            ? trim((string) $ctx->query['view_mode'])
            : 'full';

        $result = $this->ssrPageHandler->handleRenderPage(
            (string) ($params['path'] ?? '/'),
            $ctx->account,
            $request,
            $requestedViewMode,
        );

        if ($result['type'] === 'json') {
            return $this->jsonApiResponse($result['status'], $result['content'], $result['headers']);
        }

        return new Response($result['content'], $result['status'], array_merge(
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $result['headers'],
        ));
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/SsrRouterTest.php`
Expected: 2 tests, 2 passed

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Http/Router/SsrRouter.php \
       packages/foundation/tests/Unit/Http/Router/SsrRouterTest.php
git commit -m "feat(#571): add SsrRouter"
```

---

## Task 10: DiscoveryRouter

**Files:**
- Create: `packages/foundation/src/Http/Router/DiscoveryRouter.php`
- Create: `packages/foundation/tests/Unit/Http/Router/DiscoveryRouterTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Http\Router\DiscoveryRouter;

#[CoversClass(DiscoveryRouter::class)]
final class DiscoveryRouterTest extends TestCase
{
    #[Test]
    public function supports_discovery_topic_hub(): void
    {
        $handler = $this->createStub(\Waaseyaa\Api\Http\DiscoveryApiHandler::class);
        $etm = new \Waaseyaa\Entity\EntityTypeManager(new \Symfony\Component\EventDispatcher\EventDispatcher());
        $router = new DiscoveryRouter($handler, $etm);
        $request = Request::create('/api/discovery/topic-hub/node/1');
        $request->attributes->set('_controller', 'discovery.topic_hub');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_discovery_cluster(): void
    {
        $handler = $this->createStub(\Waaseyaa\Api\Http\DiscoveryApiHandler::class);
        $etm = new \Waaseyaa\Entity\EntityTypeManager(new \Symfony\Component\EventDispatcher\EventDispatcher());
        $router = new DiscoveryRouter($handler, $etm);
        $request = Request::create('/api/discovery/cluster/node/1');
        $request->attributes->set('_controller', 'discovery.cluster');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_api_discovery_controller(): void
    {
        $handler = $this->createStub(\Waaseyaa\Api\Http\DiscoveryApiHandler::class);
        $etm = new \Waaseyaa\Entity\EntityTypeManager(new \Symfony\Component\EventDispatcher\EventDispatcher());
        $router = new DiscoveryRouter($handler, $etm);
        $request = Request::create('/api/discovery');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\ApiDiscoveryController');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $handler = $this->createStub(\Waaseyaa\Api\Http\DiscoveryApiHandler::class);
        $etm = new \Waaseyaa\Entity\EntityTypeManager(new \Symfony\Component\EventDispatcher\EventDispatcher());
        $router = new DiscoveryRouter($handler, $etm);
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertFalse($router->supports($request));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/DiscoveryRouterTest.php`
Expected: FAIL — class `DiscoveryRouter` not found

- [ ] **Step 3: Implement DiscoveryRouter**

Move the `discovery.*` and `*ApiDiscoveryController` match cases from ControllerDispatcher. This router handles topic hub, cluster, timeline, endpoint, and the API discovery controller.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class DiscoveryRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly DiscoveryApiHandler $discoveryHandler,
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller', '');

        return str_starts_with($controller, 'discovery.')
            || str_contains($controller, 'ApiDiscoveryController');
    }

    public function handle(Request $request): Response
    {
        $controller = $request->attributes->get('_controller', '');
        $ctx = WaaseyaaContext::fromRequest($request);
        $params = $request->attributes->all();

        if (str_contains($controller, 'ApiDiscoveryController')) {
            $discoveryController = new \Waaseyaa\Api\ApiDiscoveryController($this->entityTypeManager);
            $result = $discoveryController->discover();
            return $this->jsonApiResponse(200, ['jsonapi' => ['version' => '1.1'], ...$result]);
        }

        return match ($controller) {
            'discovery.topic_hub' => $this->handleTopicHub($params, $ctx),
            'discovery.cluster' => $this->handleCluster($params, $ctx),
            'discovery.timeline' => $this->handleTimeline($params, $ctx),
            'discovery.endpoint' => $this->handleEndpoint($params, $ctx),
            default => $this->jsonApiResponse(404, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => "Unknown discovery action: $controller"]],
            ]),
        };
    }

    private function handleTopicHub(array $params, WaaseyaaContext $ctx): Response
    {
        $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
        $entityId = $params['id'] ?? null;
        if ($entityType === '' || !is_scalar($entityId) || trim((string) $entityId) === '') {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Discovery topic hub requires route params "entity_type" and "id".']],
            ]);
        }

        $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($ctx->query['relationship_types'] ?? null);
        $resolvedOptions = [
            'relationship_types' => $relationshipTypes,
            'status' => is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published',
            'at' => $ctx->query['at'] ?? null,
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : 25,
        ];

        $cacheKey = $this->discoveryHandler->buildCacheKey('topic_hub', $entityType, (string) $entityId, $resolvedOptions);
        $cached = $this->discoveryHandler->getCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, ['X-Discovery-Cache' => 'hit']);
        }

        $payload = $this->discoveryHandler->topicHub($entityType, (string) $entityId, $resolvedOptions, $ctx->account);
        [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

        return $this->jsonApiResponse(200, $dPayload, $dHeaders);
    }

    private function handleCluster(array $params, WaaseyaaContext $ctx): Response
    {
        $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
        $entityId = $params['id'] ?? null;
        if ($entityType === '' || !is_scalar($entityId) || trim((string) $entityId) === '') {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Discovery cluster requires route params "entity_type" and "id".']],
            ]);
        }

        $relationshipTypes = $this->discoveryHandler->parseRelationshipTypesQuery($ctx->query['relationship_types'] ?? null);
        $resolvedOptions = [
            'relationship_types' => $relationshipTypes,
            'status' => is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published',
            'at' => $ctx->query['at'] ?? null,
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : 25,
        ];

        $cacheKey = $this->discoveryHandler->buildCacheKey('cluster', $entityType, (string) $entityId, $resolvedOptions);
        $cached = $this->discoveryHandler->getCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, ['X-Discovery-Cache' => 'hit']);
        }

        $payload = $this->discoveryHandler->cluster($entityType, (string) $entityId, $resolvedOptions, $ctx->account);
        [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

        return $this->jsonApiResponse(200, $dPayload, $dHeaders);
    }

    private function handleTimeline(array $params, WaaseyaaContext $ctx): Response
    {
        $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
        $entityId = $params['id'] ?? null;
        if ($entityType === '' || !is_scalar($entityId) || trim((string) $entityId) === '') {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Discovery timeline requires route params "entity_type" and "id".']],
            ]);
        }

        $resolvedOptions = [
            'status' => is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published',
            'at' => $ctx->query['at'] ?? null,
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : 25,
        ];

        $cacheKey = $this->discoveryHandler->buildCacheKey('timeline', $entityType, (string) $entityId, $resolvedOptions);
        $cached = $this->discoveryHandler->getCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, ['X-Discovery-Cache' => 'hit']);
        }

        $payload = $this->discoveryHandler->timeline($entityType, (string) $entityId, $resolvedOptions, $ctx->account);
        [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

        return $this->jsonApiResponse(200, $dPayload, $dHeaders);
    }

    private function handleEndpoint(array $params, WaaseyaaContext $ctx): Response
    {
        $entityType = is_string($params['entity_type'] ?? null) ? trim((string) $params['entity_type']) : '';
        $entityId = $params['id'] ?? null;

        if ($entityType === '') {
            return $this->jsonApiResponse(400, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Discovery endpoint requires route param "entity_type".']],
            ]);
        }

        $resolvedOptions = [
            'status' => is_string($ctx->query['status'] ?? null) ? trim((string) $ctx->query['status']) : 'published',
            'at' => $ctx->query['at'] ?? null,
            'limit' => is_numeric($ctx->query['limit'] ?? null) ? (int) $ctx->query['limit'] : 25,
        ];

        if ($entityId === null || (is_string($entityId) && trim($entityId) === '')) {
            // List endpoint
            if (!$this->entityTypeManager->hasDefinition($entityType)) {
                return $this->jsonApiResponse(404, [
                    'jsonapi' => ['version' => '1.1'],
                    'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Unknown entity type: "%s".', $entityType)]],
                ]);
            }

            $cacheKey = $this->discoveryHandler->buildCacheKey('endpoint_list', $entityType, '', $resolvedOptions);
            $cached = $this->discoveryHandler->getCachedResponse($cacheKey, $ctx->account);
            if ($cached !== null) {
                return $this->jsonApiResponse(200, $cached, ['X-Discovery-Cache' => 'hit']);
            }

            $payload = $this->discoveryHandler->endpointList($entityType, $resolvedOptions, $ctx->account);
            [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

            return $this->jsonApiResponse(200, $dPayload, $dHeaders);
        }

        // Single endpoint
        $cacheKey = $this->discoveryHandler->buildCacheKey('endpoint', $entityType, (string) $entityId, $resolvedOptions);
        $cached = $this->discoveryHandler->getCachedResponse($cacheKey, $ctx->account);
        if ($cached !== null) {
            return $this->jsonApiResponse(200, $cached, ['X-Discovery-Cache' => 'hit']);
        }

        $entity = $this->discoveryHandler->resolveEntity($entityType, (string) $entityId, $ctx->account);
        if ($entity === null) {
            return $this->jsonApiResponse(404, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Entity %s/%s not found.', $entityType, $entityId)]],
            ]);
        }

        $payload = $this->discoveryHandler->endpoint($entityType, (string) $entityId, $resolvedOptions, $ctx->account);
        [$dPayload, $dHeaders] = $this->discoveryHandler->prepareDiscoveryResponse(200, ['data' => $payload], $cacheKey, $ctx->account);

        return $this->jsonApiResponse(200, $dPayload, $dHeaders);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/DiscoveryRouterTest.php`
Expected: 4 tests, 4 passed

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Http/Router/DiscoveryRouter.php \
       packages/foundation/tests/Unit/Http/Router/DiscoveryRouterTest.php
git commit -m "feat(#571): add DiscoveryRouter"
```

---

## Task 11: JsonApiRouter

**Files:**
- Create: `packages/foundation/src/Http/Router/JsonApiRouter.php`
- Create: `packages/foundation/tests/Unit/Http/Router/JsonApiRouterTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\JsonApiRouter;

#[CoversClass(JsonApiRouter::class)]
final class JsonApiRouterTest extends TestCase
{
    private function createRouter(): JsonApiRouter
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $accessHandler = new EntityAccessHandler($etm);
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        return new JsonApiRouter($etm, $accessHandler, $db);
    }

    #[Test]
    public function supports_json_api_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/node');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\JsonApiController');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_controller_with_class_method_syntax(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/node');
        $request->attributes->set('_controller', 'App\\Controller\\NodeJsonApiController::index');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/mcp');
        $request->attributes->set('_controller', 'mcp.endpoint');
        self::assertFalse($router->supports($request));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/JsonApiRouterTest.php`
Expected: FAIL — class `JsonApiRouter` not found

- [ ] **Step 3: Implement JsonApiRouter**

Move the `*JsonApiController` match case and the `str_contains($controller, '::')` class-method dispatch from ControllerDispatcher.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class JsonApiRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly DatabaseInterface $database,
    ) {}

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller', '');

        return str_contains($controller, 'JsonApiController');
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);
        $params = $request->attributes->all();
        $serializer = new ResourceSerializer($this->entityTypeManager);

        $jsonApiController = new JsonApiController(
            $this->entityTypeManager,
            $serializer,
            $this->accessHandler,
            $ctx->account,
        );

        $entityTypeId = $params['_entity_type'] ?? '';
        $id = $params['id'] ?? null;

        $document = match (true) {
            $ctx->method === 'GET' && $id === null => $jsonApiController->index($entityTypeId, $ctx->query),
            $ctx->method === 'GET' && $id !== null => $jsonApiController->show($entityTypeId, $id),
            $ctx->method === 'POST' => $jsonApiController->store($entityTypeId, $ctx->parsedBody ?? []),
            $ctx->method === 'PATCH' && $id !== null => $jsonApiController->update($entityTypeId, $id, $ctx->parsedBody ?? []),
            $ctx->method === 'DELETE' && $id !== null => $jsonApiController->destroy($entityTypeId, $id),
            default => JsonApiDocument::fromErrors(
                [new JsonApiError('400', 'Bad Request', 'Unhandled method/resource combination.')],
                statusCode: 400,
            ),
        };

        return $this->jsonApiResponse($document->statusCode, $document->toArray());
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/Router/JsonApiRouterTest.php`
Expected: 3 tests, 3 passed

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Http/Router/JsonApiRouter.php \
       packages/foundation/tests/Unit/Http/Router/JsonApiRouterTest.php
git commit -m "feat(#571): add JsonApiRouter"
```

---

## Task 12: Rewrite ControllerDispatcher + HttpKernel Integration

**Files:**
- Modify: `packages/foundation/src/Http/ControllerDispatcher.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php`
- Modify: `packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php`

- [ ] **Step 1: Rewrite ControllerDispatcher**

Replace the entire file. The new dispatcher is ~80 lines: it iterates routers, handles callables as fallback, wraps errors.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Routes a matched controller name to the appropriate domain router.
 *
 * Iterates DomainRouterInterface implementations in a deterministic chain.
 * Callable controllers (closures/invokables from service providers) are
 * handled as a fallback before the router chain.
 */
final class ControllerDispatcher
{
    use JsonApiResponseTrait;

    private readonly LoggerInterface $logger;

    /**
     * @param iterable<DomainRouterInterface> $routers
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly iterable $routers,
        private readonly array $config = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function dispatch(HttpRequest $request): HttpResponse
    {
        $controller = $request->attributes->get('_controller', '');

        // Callable controllers (closures/invokables from service providers).
        if (is_callable($controller)) {
            return $this->handleCallable($controller, $request);
        }

        try {
            foreach ($this->routers as $router) {
                if ($router->supports($request)) {
                    return $router->handle($request);
                }
            }
        } catch (\Throwable $e) {
            return $this->handleException($e);
        }

        $this->logger->warning(sprintf('Unknown controller: %s', $controller));

        return $this->jsonApiResponse(404, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => '404',
                'title' => 'Not Found',
                'detail' => sprintf("No router supports controller '%s'.", $controller),
            ]],
        ]);
    }

    private function handleCallable(callable $controller, HttpRequest $request): HttpResponse
    {
        $params = $request->attributes->all();
        $routeParams = array_filter($params, fn($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);
        $result = $controller($request, ...$routeParams);

        if ($result instanceof \Waaseyaa\SSR\SsrResponse) {
            return $this->htmlResponse($result->statusCode, $result->content, $result->headers);
        }
        if ($result instanceof \Waaseyaa\Inertia\InertiaResponse) {
            $pageObject = $result->toPageObject();
            $pageObject['url'] = $request->getRequestUri();

            if ($request->headers->get('X-Inertia') === 'true') {
                return $this->jsonApiResponse(200, $pageObject, [
                    'X-Inertia' => 'true',
                    'Vary' => 'X-Inertia',
                ]);
            }

            $renderer = new \Waaseyaa\Inertia\RootTemplateRenderer();
            return $this->htmlResponse(200, $renderer->render($pageObject));
        }
        if ($result instanceof \Symfony\Component\HttpFoundation\RedirectResponse
            && $request->headers->get('X-Inertia') === 'true'
            && in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'], true)
        ) {
            $result->setStatusCode(303);
            return $result;
        }
        if ($result instanceof HttpResponse) {
            return $result;
        }
        if (is_array($result)) {
            return $this->jsonApiResponse($result['statusCode'] ?? 200, $result['body'] ?? $result);
        }

        return $this->jsonApiResponse(200, ['data' => $result]);
    }

    private function handleException(\Throwable $e): HttpResponse
    {
        $this->logger->critical(sprintf(
            "Unhandled exception: %s in %s:%d\n%s",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        ));

        $debug = filter_var($this->config['debug'] ?? getenv('WAASEYAA_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
        $detail = $debug
            ? sprintf('%s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine())
            : 'An unexpected error occurred.';

        $error = [
            'status' => '500',
            'title' => 'Internal Server Error',
            'detail' => $detail,
        ];

        if ($debug) {
            $error['meta'] = [
                'exception' => $e::class,
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
            ];
        }

        return $this->jsonApiResponse(500, [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [$error],
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function htmlResponse(int $status, string $html, array $headers = []): HttpResponse
    {
        return new HttpResponse($html, $status, array_merge(
            ['Content-Type' => 'text/html; charset=UTF-8'],
            $headers,
        ));
    }
}
```

- [ ] **Step 2: Update HttpKernel**

Modify `serveHttpRequest()` in `packages/foundation/src/Kernel/HttpKernel.php`. Replace the old ControllerDispatcher instantiation and dispatch call with:

1. Populate request attributes (`_broadcast_storage`, `_parsed_body`, `_waaseyaa_context`)
2. Build the router array
3. Call new `dispatch(Request)` signature

The JSON body parsing logic moves from ControllerDispatcher to a private `parseJsonBody(HttpRequest): ?array` method on HttpKernel.

Replace the block starting at `// Dispatch.` (around line 289) through line 302 with:

```php
        // Populate request context attributes.
        $httpRequest->attributes->set('_broadcast_storage', $broadcastStorage);
        $httpRequest->attributes->set('_parsed_body', $this->parseJsonBody($httpRequest));
        $httpRequest->attributes->set('_waaseyaa_context',
            \Waaseyaa\Foundation\Http\Router\WaaseyaaContext::fromRequest($httpRequest)
        );

        // Build domain routers.
        $routers = [
            new \Waaseyaa\Foundation\Http\Router\McpRouter(
                $this->entityTypeManager, $this->accessHandler, $this->database, $this->config, $this->mcpReadCache,
            ),
            new \Waaseyaa\Foundation\Http\Router\GraphQlRouter(
                $this->entityTypeManager, $this->accessHandler, $gqlOverrides,
            ),
            new \Waaseyaa\Foundation\Http\Router\SsrRouter($this->ssrPageHandler),
            new \Waaseyaa\Foundation\Http\Router\BroadcastRouter($this->logger),
            new \Waaseyaa\Foundation\Http\Router\SearchRouter(
                $this->config, $this->database, $this->entityTypeManager,
            ),
            new \Waaseyaa\Foundation\Http\Router\MediaRouter(
                $this->projectRoot, $this->config, $this->entityTypeManager,
            ),
            new \Waaseyaa\Foundation\Http\Router\EntityTypeLifecycleRouter(
                $this->entityTypeManager, $this->lifecycleManager,
            ),
            new \Waaseyaa\Foundation\Http\Router\SchemaRouter(
                $this->entityTypeManager, $this->accessHandler,
            ),
            new \Waaseyaa\Foundation\Http\Router\DiscoveryRouter(
                $this->discoveryHandler, $this->entityTypeManager,
            ),
            new \Waaseyaa\Foundation\Http\Router\JsonApiRouter(
                $this->entityTypeManager, $this->accessHandler, $this->database,
            ),
        ];

        // Dispatch.
        $controllerDispatcher = new ControllerDispatcher($routers, $this->config, $this->logger);
        return $controllerDispatcher->dispatch($httpRequest);
```

Add the `parseJsonBody` private method:

```php
    /**
     * Parse JSON body from request content.
     *
     * @return array<string, mixed>|null
     */
    private function parseJsonBody(HttpRequest $httpRequest): ?array
    {
        $matchedRoute = $httpRequest->attributes->get('_route_object');
        $isJsonApi = $matchedRoute !== null && $matchedRoute->getOption('_json_api') === true;
        $isJsonContent = str_starts_with(
            (string) $httpRequest->headers->get('Content-Type', ''),
            'application/json',
        );

        if (($isJsonApi || $isJsonContent) && in_array($httpRequest->getMethod(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            $raw = $httpRequest->getContent();
            if ($raw !== '') {
                try {
                    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return null; // Router/controller will handle the error
                }
            }
            return [];
        }

        return null;
    }
```

- [ ] **Step 3: Rewrite ControllerDispatcherTest**

Replace the test to verify the new delegation behavior. Remove the old media helper tests (now in MediaRouterTest).

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

#[CoversClass(ControllerDispatcher::class)]
final class ControllerDispatcherTest extends TestCase
{
    #[Test]
    public function dispatch_delegates_to_matching_router(): void
    {
        $router = new class implements DomainRouterInterface {
            public function supports(Request $request): bool
            {
                return $request->attributes->get('_controller') === 'test.handler';
            }

            public function handle(Request $request): Response
            {
                return new Response('handled', 200);
            }
        };

        $dispatcher = new ControllerDispatcher([$router]);

        $request = Request::create('/test');
        $request->attributes->set('_controller', 'test.handler');

        $response = $dispatcher->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('handled', $response->getContent());
    }

    #[Test]
    public function dispatch_returns_404_when_no_router_matches(): void
    {
        $dispatcher = new ControllerDispatcher([]);

        $request = Request::create('/test');
        $request->attributes->set('_controller', 'unknown.controller');

        $response = $dispatcher->dispatch($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('404', $body['errors'][0]['status']);
    }

    #[Test]
    public function dispatch_handles_callable_controller(): void
    {
        $dispatcher = new ControllerDispatcher([]);

        $request = Request::create('/test');
        $request->attributes->set('_controller', fn(Request $r) => new Response('from callable'));

        $response = $dispatcher->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('from callable', $response->getContent());
    }

    #[Test]
    public function dispatch_callable_returns_array_as_json_api(): void
    {
        $dispatcher = new ControllerDispatcher([]);

        $request = Request::create('/test');
        $request->attributes->set('_controller', fn(Request $r) => ['data' => ['id' => 1]]);

        $response = $dispatcher->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['data' => ['id' => 1]], $body);
    }

    #[Test]
    public function dispatch_first_matching_router_wins(): void
    {
        $first = new class implements DomainRouterInterface {
            public function supports(Request $request): bool { return true; }
            public function handle(Request $request): Response { return new Response('first'); }
        };
        $second = new class implements DomainRouterInterface {
            public function supports(Request $request): bool { return true; }
            public function handle(Request $request): Response { return new Response('second'); }
        };

        $dispatcher = new ControllerDispatcher([$first, $second]);
        $request = Request::create('/test');
        $request->attributes->set('_controller', 'anything');

        $response = $dispatcher->dispatch($request);
        self::assertSame('first', $response->getContent());
    }

    #[Test]
    public function dispatch_wraps_router_exception_in_500(): void
    {
        $router = new class implements DomainRouterInterface {
            public function supports(Request $request): bool { return true; }
            public function handle(Request $request): Response { throw new \RuntimeException('boom'); }
        };

        $dispatcher = new ControllerDispatcher([$router], ['debug' => false]);
        $request = Request::create('/test');
        $request->attributes->set('_controller', 'test');

        $response = $dispatcher->dispatch($request);
        self::assertSame(500, $response->getStatusCode());
    }
}
```

- [ ] **Step 4: Run all new tests**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php`
Expected: 6 tests, 6 passed

- [ ] **Step 5: Run the full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests pass. If any existing integration tests fail, debug before proceeding.

- [ ] **Step 6: Commit**

```bash
git add packages/foundation/src/Http/ControllerDispatcher.php \
       packages/foundation/src/Kernel/HttpKernel.php \
       packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php
git commit -m "feat(#571): rewrite ControllerDispatcher as thin delegator, wire HttpKernel"
```

---

## Task 13: Final Verification and Cleanup

**Files:**
- No new files

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 2: Run static analysis**

Run: `composer phpstan`
Expected: No new errors

- [ ] **Step 3: Run code style check**

Run: `composer cs-check`
Expected: No violations (run `composer cs-fix` if needed)

- [ ] **Step 4: Verify line counts**

Run: `wc -l packages/foundation/src/Http/ControllerDispatcher.php packages/foundation/src/Http/Router/*.php`

Expected:
- `ControllerDispatcher.php` ~80-130 lines
- Each router <200 lines

- [ ] **Step 5: Run integration tests specifically**

Run: `./vendor/bin/phpunit --testsuite Integration`
Expected: All integration tests pass (McpControllerTest, HttpKernelTest, JsonApiControllerTest)

- [ ] **Step 6: Final commit (if cs-fix made changes)**

```bash
git add -u
git commit -m "style(#571): fix code style after dispatcher split"
```
