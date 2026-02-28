# WebMCP Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create the `aurora/mcp` package that exposes Aurora's entity system as a remote MCP server over Streamable HTTP.

**Architecture:** Hybrid approach — `symfony/mcp-sdk` handles JSON-RPC protocol and tool dispatch; Aurora adds bridge adapters, pluggable auth, server card, and audit integration. The package sits in Layer 6 (Interfaces) alongside cli, ssr, and admin.

**Tech Stack:** PHP 8.3+, symfony/mcp-sdk, PHPUnit 10.5

**Design doc:** `docs/plans/2026-02-28-webmcp-design.md`

---

### Task 1: Package skeleton

**Files:**
- Create: `packages/mcp/composer.json`
- Create: `packages/mcp/phpunit.xml`
- Create: `packages/mcp/src/.gitkeep`
- Create: `packages/mcp/tests/.gitkeep`

**Step 1: Create directory structure**

```bash
mkdir -p packages/mcp/src/Auth packages/mcp/src/Bridge packages/mcp/tests/Unit/Auth packages/mcp/tests/Unit/Bridge
```

**Step 2: Write composer.json**

Create `packages/mcp/composer.json`:

```json
{
    "name": "aurora/mcp",
    "description": "Remote MCP server endpoint for Aurora CMS",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "repositories": [
        {"type": "path", "url": "../ai-schema"},
        {"type": "path", "url": "../ai-agent"},
        {"type": "path", "url": "../routing"},
        {"type": "path", "url": "../access"},
        {"type": "path", "url": "../entity"},
        {"type": "path", "url": "../plugin"},
        {"type": "path", "url": "../cache"},
        {"type": "path", "url": "../typed-data"},
        {"type": "path", "url": "../config"},
        {"type": "path", "url": "../field"},
        {"type": "path", "url": "../entity-storage"},
        {"type": "path", "url": "../database-legacy"}
    ],
    "require": {
        "php": ">=8.3",
        "aurora/ai-schema": "@dev",
        "aurora/ai-agent": "@dev",
        "aurora/routing": "@dev",
        "aurora/access": "@dev",
        "symfony/mcp-sdk": "^0.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Aurora\\Mcp\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Aurora\\Mcp\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

**Step 3: Write phpunit.xml**

Create `packages/mcp/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

**Step 4: Install dependencies**

```bash
cd packages/mcp && composer install
```

> **Note:** If `symfony/mcp-sdk` is not available at ^0.1, check packagist for the current version and adjust. The SDK namespace is `Symfony\AI\McpSdk\`. After install, verify the available classes:
> ```bash
> grep -r "class ToolChain" vendor/symfony/mcp-sdk/src/
> grep -r "interface MetadataInterface" vendor/symfony/mcp-sdk/src/
> grep -r "class JsonRpcHandler" vendor/symfony/mcp-sdk/src/
> ```
> If the SDK API differs from what's documented here, adapt the bridge classes accordingly. The bridge design isolates SDK-specific code to Task 3 and Task 6.

**Step 5: Commit**

```bash
git add packages/mcp/
git commit -m "chore(mcp): scaffold aurora/mcp package skeleton"
```

---

### Task 2: McpAuthInterface + BearerTokenAuth

**Files:**
- Create: `packages/mcp/src/Auth/McpAuthInterface.php`
- Create: `packages/mcp/src/Auth/BearerTokenAuth.php`
- Create: `packages/mcp/tests/Unit/Auth/BearerTokenAuthTest.php`

**Step 1: Write the failing test**

Create `packages/mcp/tests/Unit/Auth/BearerTokenAuthTest.php`:

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp\Tests\Unit\Auth;

use Aurora\Access\AccountInterface;
use Aurora\Mcp\Auth\BearerTokenAuth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BearerTokenAuth::class)]
final class BearerTokenAuthTest extends TestCase
{
    private AccountInterface $account;
    private BearerTokenAuth $auth;

    protected function setUp(): void
    {
        $this->account = $this->createMock(AccountInterface::class);
        $this->account->method('id')->willReturn(1);

        $this->auth = new BearerTokenAuth([
            'valid-token-123' => $this->account,
        ]);
    }

    #[Test]
    public function validTokenReturnsAccount(): void
    {
        $result = $this->auth->authenticate('Bearer valid-token-123');

        $this->assertSame($this->account, $result);
    }

    #[Test]
    public function invalidTokenReturnsNull(): void
    {
        $result = $this->auth->authenticate('Bearer wrong-token');

        $this->assertNull($result);
    }

    #[Test]
    public function missingHeaderReturnsNull(): void
    {
        $result = $this->auth->authenticate(null);

        $this->assertNull($result);
    }

    #[Test]
    public function emptyHeaderReturnsNull(): void
    {
        $result = $this->auth->authenticate('');

        $this->assertNull($result);
    }

    #[Test]
    public function malformedHeaderWithoutBearerPrefixReturnsNull(): void
    {
        $result = $this->auth->authenticate('Basic valid-token-123');

        $this->assertNull($result);
    }

    #[Test]
    public function bearerPrefixIsCaseInsensitive(): void
    {
        $result = $this->auth->authenticate('bearer valid-token-123');

        $this->assertSame($this->account, $result);
    }

    #[Test]
    public function tokenWithNoTokensConfiguredReturnsNull(): void
    {
        $emptyAuth = new BearerTokenAuth([]);

        $this->assertNull($emptyAuth->authenticate('Bearer any-token'));
    }
}
```

**Step 2: Run test to verify it fails**

```bash
cd packages/mcp && vendor/bin/phpunit tests/Unit/Auth/BearerTokenAuthTest.php -v
```

Expected: FAIL — class not found.

**Step 3: Write McpAuthInterface**

Create `packages/mcp/src/Auth/McpAuthInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp\Auth;

use Aurora\Access\AccountInterface;

/**
 * Pluggable authentication for MCP endpoint requests.
 *
 * MVP ships with BearerTokenAuth. OAuth 2.1 adapter replaces it
 * later without changes to McpEndpoint.
 */
interface McpAuthInterface
{
    /**
     * Authenticate from the Authorization header value.
     *
     * @param string|null $authorizationHeader The raw Authorization header value.
     * @return AccountInterface|null The authenticated account, or null if auth fails.
     */
    public function authenticate(?string $authorizationHeader): ?AccountInterface;
}
```

**Step 4: Write BearerTokenAuth**

Create `packages/mcp/src/Auth/BearerTokenAuth.php`:

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp\Auth;

use Aurora\Access\AccountInterface;

/**
 * MVP authentication: validates opaque bearer tokens against a static map.
 *
 * Each token maps to a specific user account, so MCP tool calls
 * respect entity access control downstream.
 */
final readonly class BearerTokenAuth implements McpAuthInterface
{
    /**
     * @param array<string, AccountInterface> $tokens Token string → account mapping.
     */
    public function __construct(
        private array $tokens,
    ) {}

    public function authenticate(?string $authorizationHeader): ?AccountInterface
    {
        if ($authorizationHeader === null || $authorizationHeader === '') {
            return null;
        }

        // Case-insensitive "Bearer " prefix check.
        if (!\str_starts_with(\strtolower($authorizationHeader), 'bearer ')) {
            return null;
        }

        $token = \substr($authorizationHeader, 7);

        return $this->tokens[$token] ?? null;
    }
}
```

**Step 5: Run test to verify it passes**

```bash
cd packages/mcp && vendor/bin/phpunit tests/Unit/Auth/BearerTokenAuthTest.php -v
```

Expected: 7 tests, 7 assertions, all PASS.

**Step 6: Commit**

```bash
git add packages/mcp/src/Auth/ packages/mcp/tests/Unit/Auth/
git commit -m "feat(mcp): add pluggable auth interface with bearer token adapter"
```

---

### Task 3: AuroraToolAdapter

Bridges Aurora's `McpToolDefinition` and `McpToolExecutor` to the Symfony MCP SDK's tool interfaces. One adapter class per tool that implements all three SDK interfaces: `MetadataInterface`, `ToolExecutorInterface`, and `IdentifierInterface`.

**Files:**
- Create: `packages/mcp/src/Bridge/AuroraToolAdapter.php`
- Create: `packages/mcp/tests/Unit/Bridge/AuroraToolAdapterTest.php`

**Step 1: Write the failing test**

Create `packages/mcp/tests/Unit/Bridge/AuroraToolAdapterTest.php`:

> **Important:** After Task 1's composer install, verify the exact SDK class names. The test imports below assume `Symfony\AI\McpSdk\Capability\Tool\*`. If different, adjust imports here and in the adapter.

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp\Tests\Unit\Bridge;

use Aurora\AI\Schema\Mcp\McpToolDefinition;
use Aurora\AI\Schema\Mcp\McpToolExecutor;
use Aurora\Mcp\Bridge\AuroraToolAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\McpSdk\Capability\Tool\ToolCall;
use Symfony\AI\McpSdk\Capability\Tool\ToolCallResult;

#[CoversClass(AuroraToolAdapter::class)]
final class AuroraToolAdapterTest extends TestCase
{
    private McpToolExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = $this->createMock(McpToolExecutor::class);
    }

    #[Test]
    public function getNameReturnsTool name(): void
    {
        $definition = new McpToolDefinition(
            name: 'create_node',
            description: 'Create a new Node entity.',
            inputSchema: ['type' => 'object', 'properties' => []],
        );

        $adapter = new AuroraToolAdapter($definition, $this->executor);

        $this->assertSame('create_node', $adapter->getName());
    }

    #[Test]
    public function getDescriptionReturnsToolDescription(): void
    {
        $definition = new McpToolDefinition(
            name: 'create_node',
            description: 'Create a new Node entity.',
            inputSchema: ['type' => 'object', 'properties' => []],
        );

        $adapter = new AuroraToolAdapter($definition, $this->executor);

        $this->assertSame('Create a new Node entity.', $adapter->getDescription());
    }

    #[Test]
    public function getInputSchemaReturnsToolSchema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'attributes' => ['type' => 'object'],
            ],
            'required' => ['attributes'],
        ];

        $definition = new McpToolDefinition(
            name: 'create_node',
            description: 'Create a new Node entity.',
            inputSchema: $schema,
        );

        $adapter = new AuroraToolAdapter($definition, $this->executor);

        $this->assertSame($schema, $adapter->getInputSchema());
    }

    #[Test]
    public function callDelegatesToExecutorAndReturnsResult(): void
    {
        $definition = new McpToolDefinition(
            name: 'read_node',
            description: 'Read a node.',
            inputSchema: ['type' => 'object', 'properties' => []],
        );

        $executorResult = [
            'content' => [
                ['type' => 'text', 'text' => '{"operation":"read","id":1}'],
            ],
        ];

        $this->executor
            ->expects($this->once())
            ->method('execute')
            ->with('read_node', ['id' => 42])
            ->willReturn($executorResult);

        $adapter = new AuroraToolAdapter($definition, $this->executor);
        $toolCall = new ToolCall('read_node', ['id' => 42]);
        $result = $adapter->call($toolCall);

        $this->assertInstanceOf(ToolCallResult::class, $result);
    }

    #[Test]
    public function callWrapsExecutorErrorAsToolCallResult(): void
    {
        $definition = new McpToolDefinition(
            name: 'read_node',
            description: 'Read a node.',
            inputSchema: ['type' => 'object', 'properties' => []],
        );

        $executorResult = [
            'content' => [
                ['type' => 'text', 'text' => '{"error":"Entity not found."}'],
            ],
            'isError' => true,
        ];

        $this->executor
            ->method('execute')
            ->willReturn($executorResult);

        $adapter = new AuroraToolAdapter($definition, $this->executor);
        $toolCall = new ToolCall('read_node', ['id' => 999]);
        $result = $adapter->call($toolCall);

        $this->assertInstanceOf(ToolCallResult::class, $result);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
cd packages/mcp && vendor/bin/phpunit tests/Unit/Bridge/AuroraToolAdapterTest.php -v
```

Expected: FAIL — AuroraToolAdapter class not found.

**Step 3: Write AuroraToolAdapter**

Create `packages/mcp/src/Bridge/AuroraToolAdapter.php`:

> **Important:** Verify SDK interface names after composer install. Adjust `use` statements if needed.

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp\Bridge;

use Aurora\AI\Schema\Mcp\McpToolDefinition;
use Aurora\AI\Schema\Mcp\McpToolExecutor;
use Symfony\AI\McpSdk\Capability\Tool\IdentifierInterface;
use Symfony\AI\McpSdk\Capability\Tool\MetadataInterface;
use Symfony\AI\McpSdk\Capability\Tool\ToolCall;
use Symfony\AI\McpSdk\Capability\Tool\ToolCallResult;
use Symfony\AI\McpSdk\Capability\Tool\ToolExecutorInterface;

/**
 * Bridges an Aurora McpToolDefinition to the Symfony MCP SDK's tool interfaces.
 *
 * One adapter instance per tool. Implements MetadataInterface (discovery),
 * ToolExecutorInterface (execution), and IdentifierInterface (name matching)
 * so the SDK's ToolChain can pair metadata with executor by name.
 */
final readonly class AuroraToolAdapter implements MetadataInterface, ToolExecutorInterface, IdentifierInterface
{
    public function __construct(
        private McpToolDefinition $definition,
        private McpToolExecutor $executor,
    ) {}

    public function getName(): string
    {
        return $this->definition->name;
    }

    public function getDescription(): string
    {
        return $this->definition->description;
    }

    public function getInputSchema(): array
    {
        return $this->definition->inputSchema;
    }

    public function call(ToolCall $input): ToolCallResult
    {
        $result = $this->executor->execute($this->definition->name, $input->arguments);

        // Extract the text content from MCP result format.
        $text = $result['content'][0]['text'] ?? '{}';
        $isError = $result['isError'] ?? false;

        return new ToolCallResult($text, $isError);
    }
}
```

**Step 4: Run test to verify it passes**

```bash
cd packages/mcp && vendor/bin/phpunit tests/Unit/Bridge/AuroraToolAdapterTest.php -v
```

Expected: 5 tests, all PASS.

> **SDK API note:** If `ToolCallResult` constructor differs (e.g. different parameter names or order), adapt `call()` method accordingly. The key contract: the adapter delegates to `McpToolExecutor::execute()` and wraps the result.

**Step 5: Commit**

```bash
git add packages/mcp/src/Bridge/ packages/mcp/tests/Unit/Bridge/
git commit -m "feat(mcp): add bridge adapter from Aurora tools to Symfony MCP SDK"
```

---

### Task 4: McpServerCard

**Files:**
- Create: `packages/mcp/src/McpServerCard.php`
- Create: `packages/mcp/tests/Unit/McpServerCardTest.php`

**Step 1: Write the failing test**

Create `packages/mcp/tests/Unit/McpServerCardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp\Tests\Unit;

use Aurora\Mcp\McpServerCard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpServerCard::class)]
final class McpServerCardTest extends TestCase
{
    #[Test]
    public function toArrayProducesValidServerCardStructure(): void
    {
        $card = new McpServerCard();
        $result = $card->toArray();

        $this->assertSame('Aurora CMS', $result['name']);
        $this->assertSame('0.1.0', $result['version']);
        $this->assertSame('/mcp', $result['endpoint']);
        $this->assertSame('streamable-http', $result['transport']);
        $this->assertTrue($result['capabilities']['tools']);
        $this->assertFalse($result['capabilities']['resources']);
        $this->assertFalse($result['capabilities']['prompts']);
        $this->assertSame('bearer', $result['authentication']['type']);
    }

    #[Test]
    public function constructorAcceptsCustomValues(): void
    {
        $card = new McpServerCard(
            name: 'My CMS',
            version: '2.0.0',
            endpoint: '/api/mcp',
        );

        $result = $card->toArray();

        $this->assertSame('My CMS', $result['name']);
        $this->assertSame('2.0.0', $result['version']);
        $this->assertSame('/api/mcp', $result['endpoint']);
    }

    #[Test]
    public function toJsonReturnsValidJson(): void
    {
        $card = new McpServerCard();
        $json = $card->toJson();

        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame('Aurora CMS', $decoded['name']);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
cd packages/mcp && vendor/bin/phpunit tests/Unit/McpServerCardTest.php -v
```

Expected: FAIL — McpServerCard class not found.

**Step 3: Write McpServerCard**

Create `packages/mcp/src/McpServerCard.php`:

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp;

/**
 * Value object for the /.well-known/mcp.json server card.
 *
 * Ahead of the June 2026 MCP spec which will standardize this format.
 * Provides pre-connection discovery for MCP clients.
 */
final readonly class McpServerCard
{
    public function __construct(
        private string $name = 'Aurora CMS',
        private string $version = '0.1.0',
        private string $endpoint = '/mcp',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'description' => 'AI-native content management system',
            'endpoint' => $this->endpoint,
            'transport' => 'streamable-http',
            'capabilities' => [
                'tools' => true,
                'resources' => false,
                'prompts' => false,
            ],
            'authentication' => [
                'type' => 'bearer',
            ],
        ];
    }

    public function toJson(): string
    {
        return \json_encode($this->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }
}
```

**Step 4: Run test to verify it passes**

```bash
cd packages/mcp && vendor/bin/phpunit tests/Unit/McpServerCardTest.php -v
```

Expected: 3 tests, all PASS.

**Step 5: Commit**

```bash
git add packages/mcp/src/McpServerCard.php packages/mcp/tests/Unit/McpServerCardTest.php
git commit -m "feat(mcp): add server card for /.well-known/mcp.json discovery"
```

---

### Task 5: McpRouteProvider

**Files:**
- Create: `packages/mcp/src/McpRouteProvider.php`
- Create: `packages/mcp/tests/Unit/McpRouteProviderTest.php`

**Step 1: Write the failing test**

Create `packages/mcp/tests/Unit/McpRouteProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp\Tests\Unit;

use Aurora\Mcp\McpRouteProvider;
use Aurora\Routing\AuroraRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpRouteProvider::class)]
final class McpRouteProviderTest extends TestCase
{
    #[Test]
    public function registerRoutesAddsMcpEndpointRoute(): void
    {
        $router = new AuroraRouter();
        $provider = new McpRouteProvider();

        $provider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $endpointRoute = $routes->get('mcp.endpoint');

        $this->assertNotNull($endpointRoute);
        $this->assertSame('/mcp', $endpointRoute->getPath());
        $this->assertContains('POST', $endpointRoute->getMethods());
        $this->assertContains('GET', $endpointRoute->getMethods());
    }

    #[Test]
    public function registerRoutesAddsServerCardRoute(): void
    {
        $router = new AuroraRouter();
        $provider = new McpRouteProvider();

        $provider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $cardRoute = $routes->get('mcp.server_card');

        $this->assertNotNull($cardRoute);
        $this->assertSame('/.well-known/mcp.json', $cardRoute->getPath());
        $this->assertContains('GET', $cardRoute->getMethods());
    }

    #[Test]
    public function serverCardRouteIsPublic(): void
    {
        $router = new AuroraRouter();
        $provider = new McpRouteProvider();

        $provider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $cardRoute = $routes->get('mcp.server_card');

        $this->assertTrue($cardRoute->getOption('_public'));
    }
}
```

**Step 2: Run test to verify it fails**

```bash
cd packages/mcp && vendor/bin/phpunit tests/Unit/McpRouteProviderTest.php -v
```

Expected: FAIL — McpRouteProvider class not found.

**Step 3: Write McpRouteProvider**

Create `packages/mcp/src/McpRouteProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp;

use Aurora\Routing\AuroraRouter;
use Aurora\Routing\RouteBuilder;

/**
 * Registers MCP routes on the Aurora router.
 *
 * Two routes:
 * - POST|GET /mcp         — MCP Streamable HTTP endpoint (auth required)
 * - GET /.well-known/mcp.json — Server card discovery (public)
 */
final readonly class McpRouteProvider
{
    public function registerRoutes(AuroraRouter $router): void
    {
        // MCP Streamable HTTP endpoint.
        $router->addRoute(
            'mcp.endpoint',
            RouteBuilder::create('/mcp')
                ->controller('Aurora\\Mcp\\McpEndpoint::handle')
                ->methods('POST', 'GET')
                ->build(),
        );

        // Server card for pre-connection discovery.
        $router->addRoute(
            'mcp.server_card',
            RouteBuilder::create('/.well-known/mcp.json')
                ->controller('Aurora\\Mcp\\McpServerCard::toJson')
                ->methods('GET')
                ->allowAll()
                ->build(),
        );
    }
}
```

**Step 4: Run test to verify it passes**

```bash
cd packages/mcp && vendor/bin/phpunit tests/Unit/McpRouteProviderTest.php -v
```

Expected: 3 tests, all PASS.

**Step 5: Commit**

```bash
git add packages/mcp/src/McpRouteProvider.php packages/mcp/tests/Unit/McpRouteProviderTest.php
git commit -m "feat(mcp): add route provider for /mcp and /.well-known/mcp.json"
```

---

### Task 6: McpResponse + McpEndpoint

The main HTTP handler. Receives request data, runs auth, builds the SDK's `JsonRpcHandler` with Aurora tool adapters, processes the request, and returns a response.

**Files:**
- Create: `packages/mcp/src/McpResponse.php`
- Create: `packages/mcp/src/McpEndpoint.php`
- Create: `packages/mcp/tests/Unit/McpEndpointTest.php`

**Step 1: Write the failing test**

Create `packages/mcp/tests/Unit/McpEndpointTest.php`:

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp\Tests\Unit;

use Aurora\Access\AccountInterface;
use Aurora\AI\Schema\Mcp\McpToolDefinition;
use Aurora\AI\Schema\Mcp\McpToolExecutor;
use Aurora\AI\Schema\SchemaRegistry;
use Aurora\Mcp\Auth\McpAuthInterface;
use Aurora\Mcp\McpEndpoint;
use Aurora\Mcp\McpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpEndpoint::class)]
#[CoversClass(McpResponse::class)]
final class McpEndpointTest extends TestCase
{
    private McpAuthInterface $auth;
    private SchemaRegistry $registry;
    private McpToolExecutor $executor;
    private AccountInterface $account;

    protected function setUp(): void
    {
        $this->auth = $this->createMock(McpAuthInterface::class);
        $this->registry = $this->createMock(SchemaRegistry::class);
        $this->executor = $this->createMock(McpToolExecutor::class);
        $this->account = $this->createMock(AccountInterface::class);
        $this->account->method('id')->willReturn(1);
    }

    private function createEndpoint(): McpEndpoint
    {
        return new McpEndpoint(
            auth: $this->auth,
            registry: $this->registry,
            executor: $this->executor,
        );
    }

    #[Test]
    public function missingAuthHeaderReturns401(): void
    {
        $this->auth->method('authenticate')->willReturn(null);

        $endpoint = $this->createEndpoint();
        $response = $endpoint->handle(
            method: 'POST',
            body: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
            authorizationHeader: null,
        );

        $this->assertSame(401, $response->statusCode);
        $decoded = \json_decode($response->body, true);
        $this->assertSame(-32001, $decoded['error']['code']);
        $this->assertSame('Unauthorized', $decoded['error']['message']);
    }

    #[Test]
    public function invalidTokenReturns401(): void
    {
        $this->auth->method('authenticate')->willReturn(null);

        $endpoint = $this->createEndpoint();
        $response = $endpoint->handle(
            method: 'POST',
            body: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
            authorizationHeader: 'Bearer bad-token',
        );

        $this->assertSame(401, $response->statusCode);
    }

    #[Test]
    public function toolsListReturnsToolDefinitions(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $tools = [
            new McpToolDefinition('create_node', 'Create a node.', [
                'type' => 'object',
                'properties' => ['attributes' => ['type' => 'object']],
                'required' => ['attributes'],
            ]),
        ];
        $this->registry->method('getTools')->willReturn($tools);

        $endpoint = $this->createEndpoint();
        $response = $endpoint->handle(
            method: 'POST',
            body: \json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ]),
            authorizationHeader: 'Bearer valid-token',
        );

        $this->assertSame(200, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertSame(1, $decoded['id']);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertCount(1, $decoded['result']['tools']);
        $this->assertSame('create_node', $decoded['result']['tools'][0]['name']);
    }

    #[Test]
    public function toolsCallExecutesToolAndReturnsResult(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $this->registry->method('getTools')->willReturn([
            new McpToolDefinition('read_node', 'Read a node.', [
                'type' => 'object',
                'properties' => [],
            ]),
        ]);

        $this->executor
            ->expects($this->once())
            ->method('execute')
            ->with('read_node', ['id' => 42])
            ->willReturn([
                'content' => [
                    ['type' => 'text', 'text' => '{"operation":"read","id":42}'],
                ],
            ]);

        $endpoint = $this->createEndpoint();
        $response = $endpoint->handle(
            method: 'POST',
            body: \json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'read_node',
                    'arguments' => ['id' => 42],
                ],
            ]),
            authorizationHeader: 'Bearer valid-token',
        );

        $this->assertSame(200, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        $this->assertSame(2, $decoded['id']);
        $this->assertArrayHasKey('result', $decoded);
        $this->assertSame('text', $decoded['result']['content'][0]['type']);
    }

    #[Test]
    public function toolsCallWithUnknownToolReturnsError(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);
        $this->registry->method('getTools')->willReturn([]);

        $endpoint = $this->createEndpoint();
        $response = $endpoint->handle(
            method: 'POST',
            body: \json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'nonexistent_tool',
                    'arguments' => [],
                ],
            ]),
            authorizationHeader: 'Bearer valid-token',
        );

        $this->assertSame(200, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        $this->assertArrayHasKey('error', $decoded);
    }

    #[Test]
    public function invalidJsonReturnsParseError(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $endpoint = $this->createEndpoint();
        $response = $endpoint->handle(
            method: 'POST',
            body: '{invalid json',
            authorizationHeader: 'Bearer valid-token',
        );

        $this->assertSame(200, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        $this->assertSame(-32700, $decoded['error']['code']);
    }

    #[Test]
    public function missingMethodFieldReturnsInvalidRequest(): void
    {
        $this->auth->method('authenticate')->willReturn($this->account);

        $endpoint = $this->createEndpoint();
        $response = $endpoint->handle(
            method: 'POST',
            body: \json_encode(['jsonrpc' => '2.0', 'id' => 1]),
            authorizationHeader: 'Bearer valid-token',
        );

        $this->assertSame(200, $response->statusCode);

        $decoded = \json_decode($response->body, true);
        $this->assertSame(-32600, $decoded['error']['code']);
    }

    #[Test]
    public function responseContentTypeIsJson(): void
    {
        $this->auth->method('authenticate')->willReturn(null);

        $endpoint = $this->createEndpoint();
        $response = $endpoint->handle(
            method: 'POST',
            body: '{}',
            authorizationHeader: null,
        );

        $this->assertSame('application/json', $response->contentType);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
cd packages/mcp && vendor/bin/phpunit tests/Unit/McpEndpointTest.php -v
```

Expected: FAIL — McpEndpoint, McpResponse not found.

**Step 3: Write McpResponse**

Create `packages/mcp/src/McpResponse.php`:

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp;

/**
 * Value object for MCP endpoint HTTP responses.
 *
 * The HTTP framework integration layer converts this to an actual
 * HTTP response object. Follows the same pattern as JsonApiDocument.
 */
final readonly class McpResponse
{
    public function __construct(
        public string $body,
        public int $statusCode = 200,
        public string $contentType = 'application/json',
    ) {}
}
```

**Step 4: Write McpEndpoint**

Create `packages/mcp/src/McpEndpoint.php`:

> **Note:** This implementation handles JSON-RPC dispatch directly for `tools/list` and `tools/call` rather than delegating to the SDK's `JsonRpcHandler`. This is intentional for the MVP — it keeps the endpoint simple and avoids coupling to SDK internals for the two methods we support. When we add resources/prompts support later, we can switch to the full SDK handler.

```php
<?php

declare(strict_types=1);

namespace Aurora\Mcp;

use Aurora\AI\Schema\Mcp\McpToolExecutor;
use Aurora\AI\Schema\SchemaRegistry;
use Aurora\Mcp\Auth\McpAuthInterface;

/**
 * MCP Streamable HTTP endpoint handler.
 *
 * Receives raw HTTP request data (method, body, auth header),
 * authenticates, dispatches JSON-RPC methods to Aurora's tool system,
 * and returns an McpResponse.
 *
 * Supports: tools/list, tools/call, initialize, ping.
 */
final readonly class McpEndpoint
{
    public function __construct(
        private McpAuthInterface $auth,
        private SchemaRegistry $registry,
        private McpToolExecutor $executor,
    ) {}

    /**
     * Handle an incoming MCP request.
     *
     * @param string      $method              HTTP method (POST or GET).
     * @param string      $body                Raw request body.
     * @param string|null $authorizationHeader Raw Authorization header value.
     */
    public function handle(
        string $method,
        string $body,
        ?string $authorizationHeader,
    ): McpResponse {
        // Authenticate.
        $account = $this->auth->authenticate($authorizationHeader);
        if ($account === null) {
            return new McpResponse(
                body: \json_encode([
                    'jsonrpc' => '2.0',
                    'error' => ['code' => -32001, 'message' => 'Unauthorized'],
                    'id' => null,
                ], \JSON_THROW_ON_ERROR),
                statusCode: 401,
            );
        }

        // Parse JSON-RPC request.
        try {
            $request = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->jsonRpcError(-32700, 'Parse error', null);
        }

        if (!\is_array($request) || !isset($request['method'])) {
            return $this->jsonRpcError(-32600, 'Invalid Request', $request['id'] ?? null);
        }

        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        return match ($request['method']) {
            'initialize' => $this->handleInitialize($id),
            'ping' => $this->handlePing($id),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolsCall($id, $params),
            default => $this->jsonRpcError(-32601, "Method not found: {$request['method']}", $id),
        };
    }

    private function handleInitialize(mixed $id): McpResponse
    {
        return $this->jsonRpcResult($id, [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'Aurora CMS',
                'version' => '0.1.0',
            ],
        ]);
    }

    private function handlePing(mixed $id): McpResponse
    {
        return $this->jsonRpcResult($id, []);
    }

    private function handleToolsList(mixed $id): McpResponse
    {
        $tools = [];
        foreach ($this->registry->getTools() as $tool) {
            $tools[] = $tool->toArray();
        }

        return $this->jsonRpcResult($id, ['tools' => $tools]);
    }

    private function handleToolsCall(mixed $id, array $params): McpResponse
    {
        $toolName = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if ($toolName === null) {
            return $this->jsonRpcError(-32602, 'Missing required parameter: name', $id);
        }

        // Verify the tool exists.
        $tool = $this->registry->getTool($toolName);
        if ($tool === null) {
            return $this->jsonRpcError(-32602, "Unknown tool: {$toolName}", $id);
        }

        $result = $this->executor->execute($toolName, $arguments);

        return $this->jsonRpcResult($id, $result);
    }

    private function jsonRpcResult(mixed $id, mixed $result): McpResponse
    {
        return new McpResponse(
            body: \json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ], \JSON_THROW_ON_ERROR),
        );
    }

    private function jsonRpcError(int $code, string $message, mixed $id): McpResponse
    {
        return new McpResponse(
            body: \json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => $code, 'message' => $message],
                'id' => $id,
            ], \JSON_THROW_ON_ERROR),
        );
    }
}
```

**Step 5: Run test to verify it passes**

```bash
cd packages/mcp && vendor/bin/phpunit tests/Unit/McpEndpointTest.php -v
```

Expected: 8 tests, all PASS.

**Step 6: Run all package tests**

```bash
cd packages/mcp && vendor/bin/phpunit -v
```

Expected: All tests across Auth, Bridge, and main classes pass.

**Step 7: Commit**

```bash
git add packages/mcp/src/McpResponse.php packages/mcp/src/McpEndpoint.php packages/mcp/tests/Unit/McpEndpointTest.php
git commit -m "feat(mcp): add MCP endpoint with JSON-RPC dispatch and auth"
```

---

### Task 7: Integration test

End-to-end test following the Phase 10 smoke test pattern. Boots the full Aurora stack, registers entity types, and verifies the MCP endpoint returns tools and executes them.

**Files:**
- Create: `tests/Integration/Phase11/McpEndpointSmokeTest.php`

**Step 1: Write the integration test**

Create `tests/Integration/Phase11/McpEndpointSmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Phase11;

use Aurora\Access\AccountInterface;
use Aurora\AI\Schema\EntityJsonSchemaGenerator;
use Aurora\AI\Schema\Mcp\McpToolExecutor;
use Aurora\AI\Schema\Mcp\McpToolGenerator;
use Aurora\AI\Schema\SchemaRegistry;
use Aurora\Entity\ContentEntityBase;
use Aurora\Entity\EntityTypeDefinition;
use Aurora\Entity\EntityTypeManagerInterface;
use Aurora\Entity\Storage\EntityQueryInterface;
use Aurora\Entity\Storage\EntityStorageInterface;
use Aurora\Mcp\Auth\BearerTokenAuth;
use Aurora\Mcp\McpEndpoint;
use Aurora\Mcp\McpRouteProvider;
use Aurora\Mcp\McpServerCard;
use Aurora\Routing\AuroraRouter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11: MCP endpoint end-to-end smoke test.
 *
 * Verifies the full MCP stack: auth → endpoint → tool discovery → tool execution.
 */
final class McpEndpointSmokeTest extends TestCase
{
    #[Test]
    public function mcpToolDiscoveryAndExecution(): void
    {
        // --- Setup: mock entity type manager with 'node' type ---
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $storage = $this->createMock(EntityStorageInterface::class);
        $query = $this->createMock(EntityQueryInterface::class);
        $definition = $this->createMock(EntityTypeDefinition::class);

        $definition->method('getLabel')->willReturn('Node');
        $definition->method('getKeys')->willReturn([
            'id' => 'nid', 'uuid' => 'uuid', 'label' => 'title',
        ]);

        $entityTypeManager->method('getDefinitions')->willReturn(['node' => $definition]);
        $entityTypeManager->method('getDefinition')->willReturn($definition);
        $entityTypeManager->method('hasDefinition')
            ->willReturnCallback(fn (string $id) => $id === 'node');
        $entityTypeManager->method('getStorage')->willReturn($storage);

        $storage->method('getQuery')->willReturn($query);

        // --- Build the MCP stack ---
        $schemaGenerator = new EntityJsonSchemaGenerator($entityTypeManager);
        $toolGenerator = new McpToolGenerator($entityTypeManager);
        $registry = new SchemaRegistry($schemaGenerator, $toolGenerator);
        $executor = new McpToolExecutor($entityTypeManager);

        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn(1);
        $account->method('hasPermission')->willReturn(true);

        $auth = new BearerTokenAuth(['test-token-abc' => $account]);

        $endpoint = new McpEndpoint(
            auth: $auth,
            registry: $registry,
            executor: $executor,
        );

        // --- Step 1: Server card ---
        $card = new McpServerCard();
        $cardData = $card->toArray();
        $this->assertSame('Aurora CMS', $cardData['name']);
        $this->assertTrue($cardData['capabilities']['tools']);

        // --- Step 2: Route registration ---
        $router = new AuroraRouter();
        $routeProvider = new McpRouteProvider();
        $routeProvider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $this->assertNotNull($routes->get('mcp.endpoint'));
        $this->assertNotNull($routes->get('mcp.server_card'));

        // --- Step 3: Auth failure ---
        $response = $endpoint->handle(
            method: 'POST',
            body: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
            authorizationHeader: 'Bearer wrong-token',
        );
        $this->assertSame(401, $response->statusCode);

        // --- Step 4: Tool discovery ---
        $response = $endpoint->handle(
            method: 'POST',
            body: \json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ]),
            authorizationHeader: 'Bearer test-token-abc',
        );

        $this->assertSame(200, $response->statusCode);
        $decoded = \json_decode($response->body, true);
        $tools = $decoded['result']['tools'];

        // 5 CRUD tools for 'node' entity type.
        $this->assertCount(5, $tools);
        $toolNames = \array_column($tools, 'name');
        $this->assertContains('create_node', $toolNames);
        $this->assertContains('read_node', $toolNames);
        $this->assertContains('update_node', $toolNames);
        $this->assertContains('delete_node', $toolNames);
        $this->assertContains('query_node', $toolNames);

        // Each tool has the required MCP fields.
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }

        // --- Step 5: Tool execution (read_node) ---
        $entity = $this->createMock(ContentEntityBase::class);
        $entity->method('id')->willReturn(42);
        $entity->method('toArray')->willReturn(['nid' => 42, 'title' => 'Hello']);

        $storage->method('load')->with(42)->willReturn($entity);

        $response = $endpoint->handle(
            method: 'POST',
            body: \json_encode([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'read_node',
                    'arguments' => ['id' => 42],
                ],
            ]),
            authorizationHeader: 'Bearer test-token-abc',
        );

        $this->assertSame(200, $response->statusCode);
        $decoded = \json_decode($response->body, true);
        $this->assertSame(2, $decoded['id']);
        $this->assertArrayHasKey('result', $decoded);

        $content = $decoded['result']['content'][0];
        $this->assertSame('text', $content['type']);
        $resultData = \json_decode($content['text'], true);
        $this->assertSame('read', $resultData['operation']);
        $this->assertSame(42, $resultData['id']);

        // --- Step 6: Initialize handshake ---
        $response = $endpoint->handle(
            method: 'POST',
            body: \json_encode([
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-03-26',
                    'clientInfo' => ['name' => 'test', 'version' => '1.0'],
                    'capabilities' => [],
                ],
            ]),
            authorizationHeader: 'Bearer test-token-abc',
        );

        $decoded = \json_decode($response->body, true);
        $this->assertSame('2025-03-26', $decoded['result']['protocolVersion']);
        $this->assertSame('Aurora CMS', $decoded['result']['serverInfo']['name']);
    }
}
```

**Step 2: Run integration test**

```bash
cd /home/fsd42/dev/drupal-11.2.10 && vendor/bin/phpunit tests/Integration/Phase11/McpEndpointSmokeTest.php -v
```

> **Note:** If the root project doesn't have `aurora/mcp` in its autoload path yet, you may need to run `composer dump-autoload` first, or add the package to the root composer.json repositories. Check how other packages are wired. If this test can't autoload the MCP classes, add `"aurora/mcp": "@dev"` to the root `composer.json` require section and `{"type": "path", "url": "packages/mcp"}` to its repositories, then run `composer update aurora/mcp`.

Expected: 1 test, multiple assertions, PASS.

**Step 3: Commit**

```bash
git add tests/Integration/Phase11/
git commit -m "test(phase11): add end-to-end smoke test for MCP endpoint"
```

---

### Task 8: Meta-package wiring

Wire `aurora/mcp` into the `aurora/full` meta-package so it's included in the full installation.

**Files:**
- Modify: `packages/full/composer.json` — add `aurora/mcp` dependency
- Modify: root `composer.json` — add path repository and require

**Step 1: Check current meta-package structure**

```bash
cat packages/full/composer.json
```

**Step 2: Add aurora/mcp to aurora/full**

Add to `packages/full/composer.json`:
- In `repositories`: `{"type": "path", "url": "../mcp"}`
- In `require`: `"aurora/mcp": "@dev"`

**Step 3: Add to root composer.json**

Add to root `composer.json`:
- In `repositories`: `{"type": "path", "url": "packages/mcp"}`
- In `require`: `"aurora/mcp": "@dev"`

**Step 4: Update dependencies**

```bash
cd /home/fsd42/dev/drupal-11.2.10 && composer update aurora/mcp
```

**Step 5: Run full test suite**

```bash
vendor/bin/phpunit -v
```

Expected: All existing tests still pass + new MCP tests pass.

**Step 6: Commit**

```bash
git add packages/full/composer.json composer.json composer.lock
git commit -m "chore(mcp): wire aurora/mcp into meta-packages"
```

---

## Task Dependency Graph

```
Task 1 (skeleton)
  ├── Task 2 (auth)
  ├── Task 3 (bridge adapter)
  ├── Task 4 (server card)
  └── Task 5 (route provider)
        └── Task 6 (endpoint) ← depends on 2, 3
              └── Task 7 (integration test)
                    └── Task 8 (meta-package wiring)
```

Tasks 2, 3, 4, and 5 are independent of each other and can be parallelized after Task 1.

---

## SDK API Adaptation Notes

The `symfony/mcp-sdk` package is new and its API may differ from what's documented. After Task 1's `composer install`:

1. **Verify SDK classes exist:** `grep -r "class ToolChain" vendor/symfony/`, `grep -r "interface MetadataInterface" vendor/symfony/`
2. **If `ToolCallResult` constructor differs:** Adapt `AuroraToolAdapter::call()` in Task 3
3. **If namespace differs:** The SDK might use `Symfony\Component\McpSdk\` instead of `Symfony\AI\McpSdk\`. Update all imports.
4. **If SDK is unavailable:** Remove `symfony/mcp-sdk` from composer.json. The bridge adapter (Task 3) becomes unnecessary. The endpoint (Task 6) already handles JSON-RPC dispatch directly and works standalone.

The design deliberately isolates SDK-specific code to `Bridge/AuroraToolAdapter.php`. If the SDK can't be used, delete the Bridge directory — the endpoint and all other classes work independently.
