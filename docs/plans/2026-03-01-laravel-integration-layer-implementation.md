# Laravel Integration Layer Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add package auto-discovery, typed middleware pipelines, and config caching to Waaseyaa's foundation layer.

**Architecture:** Three independent compilers (PackageManifestCompiler, MiddlewarePipelineCompiler, ConfigCacheCompiler) produce cached PHP artifacts in `storage/framework/`. A `waaseyaa optimize` CLI command orchestrates all three. Dev auto-compiles on first use; prod requires explicit compilation.

**Tech Stack:** PHP 8.3, Symfony Console 7.x, Symfony HttpFoundation, PHPUnit 10.5

---

### Task 1: Add storage/framework/ directory and gitignore

**Files:**
- Modify: `.gitignore`

**Step 1: Add storage/ to .gitignore**

Append to `.gitignore`:
```
# Compiled framework cache
storage/
```

**Step 2: Commit**

```bash
git add .gitignore
git commit -m "chore: gitignore storage/ directory for compiled framework cache"
```

---

### Task 2: AsMiddleware attribute

**Files:**
- Create: `packages/foundation/src/Attribute/AsMiddleware.php`
- Test: `packages/foundation/tests/Unit/Attribute/AsMiddlewareTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Attribute;

use Waaseyaa\Foundation\Attribute\AsMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsMiddleware::class)]
final class AsMiddlewareTest extends TestCase
{
    #[Test]
    public function attribute_is_discoverable_on_class(): void
    {
        $ref = new \ReflectionClass(SampleMiddleware::class);
        $attrs = $ref->getAttributes(AsMiddleware::class);

        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame('http', $instance->pipeline);
        $this->assertSame(100, $instance->priority);
    }

    #[Test]
    public function default_priority_is_zero(): void
    {
        $attr = new AsMiddleware(pipeline: 'event');

        $this->assertSame(0, $attr->priority);
    }

    #[Test]
    public function pipeline_must_be_valid(): void
    {
        $attr = new AsMiddleware(pipeline: 'job', priority: 50);

        $this->assertSame('job', $attr->pipeline);
        $this->assertSame(50, $attr->priority);
    }
}

#[AsMiddleware(pipeline: 'http', priority: 100)]
final class SampleMiddleware {}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter AsMiddlewareTest`
Expected: FAIL — class `AsMiddleware` not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsMiddleware
{
    public function __construct(
        public readonly string $pipeline,
        public readonly int $priority = 0,
    ) {}
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter AsMiddlewareTest`
Expected: PASS (3 tests, 3 assertions)

**Step 5: Commit**

```bash
git add packages/foundation/src/Attribute/AsMiddleware.php packages/foundation/tests/Unit/Attribute/AsMiddlewareTest.php
git commit -m "feat: add AsMiddleware attribute for pipeline middleware discovery"
```

---

### Task 3: AsFieldType and AsEntityType attributes

**Files:**
- Create: `packages/foundation/src/Attribute/AsFieldType.php`
- Create: `packages/foundation/src/Attribute/AsEntityType.php`
- Test: `packages/foundation/tests/Unit/Attribute/AsFieldTypeTest.php`
- Test: `packages/foundation/tests/Unit/Attribute/AsEntityTypeTest.php`

**Step 1: Write failing tests**

`packages/foundation/tests/Unit/Attribute/AsFieldTypeTest.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Attribute;

use Waaseyaa\Foundation\Attribute\AsFieldType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsFieldType::class)]
final class AsFieldTypeTest extends TestCase
{
    #[Test]
    public function attribute_carries_id_and_label(): void
    {
        $ref = new \ReflectionClass(SampleFieldType::class);
        $attrs = $ref->getAttributes(AsFieldType::class);

        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame('text', $instance->id);
        $this->assertSame('Text', $instance->label);
    }
}

#[AsFieldType(id: 'text', label: 'Text')]
final class SampleFieldType {}
```

`packages/foundation/tests/Unit/Attribute/AsEntityTypeTest.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Attribute;

use Waaseyaa\Foundation\Attribute\AsEntityType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsEntityType::class)]
final class AsEntityTypeTest extends TestCase
{
    #[Test]
    public function attribute_carries_id_and_label(): void
    {
        $ref = new \ReflectionClass(SampleEntityType::class);
        $attrs = $ref->getAttributes(AsEntityType::class);

        $this->assertCount(1, $attrs);

        $instance = $attrs[0]->newInstance();
        $this->assertSame('node', $instance->id);
        $this->assertSame('Content', $instance->label);
    }
}

#[AsEntityType(id: 'node', label: 'Content')]
final class SampleEntityType {}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter "AsFieldTypeTest|AsEntityTypeTest"`
Expected: FAIL — classes not found.

**Step 3: Write implementations**

`packages/foundation/src/Attribute/AsFieldType.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsFieldType
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
    ) {}
}
```

`packages/foundation/src/Attribute/AsEntityType.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsEntityType
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
    ) {}
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter "AsFieldTypeTest|AsEntityTypeTest"`
Expected: PASS (2 tests, 2 assertions each)

**Step 5: Commit**

```bash
git add packages/foundation/src/Attribute/AsFieldType.php packages/foundation/src/Attribute/AsEntityType.php packages/foundation/tests/Unit/Attribute/AsFieldTypeTest.php packages/foundation/tests/Unit/Attribute/AsEntityTypeTest.php
git commit -m "feat: add AsFieldType and AsEntityType discovery attributes"
```

---

### Task 4: PackageManifest value object

**Files:**
- Create: `packages/foundation/src/Discovery/PackageManifest.php`
- Test: `packages/foundation/tests/Unit/Discovery/PackageManifestTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use Waaseyaa\Foundation\Discovery\PackageManifest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageManifest::class)]
final class PackageManifestTest extends TestCase
{
    #[Test]
    public function creates_from_array(): void
    {
        $data = [
            'providers' => ['App\\FooProvider'],
            'commands' => ['App\\FooCommand'],
            'routes' => [],
            'migrations' => ['waaseyaa/foo' => '/path/to/migrations'],
            'field_types' => ['text' => 'App\\TextField'],
            'entity_types' => [],
            'listeners' => [],
            'middleware' => ['http' => [], 'event' => [], 'job' => []],
        ];

        $manifest = PackageManifest::fromArray($data);

        $this->assertSame(['App\\FooProvider'], $manifest->providers);
        $this->assertSame(['App\\FooCommand'], $manifest->commands);
        $this->assertSame(['text' => 'App\\TextField'], $manifest->fieldTypes);
        $this->assertSame(['waaseyaa/foo' => '/path/to/migrations'], $manifest->migrations);
    }

    #[Test]
    public function creates_empty_manifest(): void
    {
        $manifest = PackageManifest::empty();

        $this->assertSame([], $manifest->providers);
        $this->assertSame([], $manifest->commands);
        $this->assertSame([], $manifest->routes);
        $this->assertSame([], $manifest->migrations);
        $this->assertSame([], $manifest->fieldTypes);
        $this->assertSame([], $manifest->entityTypes);
        $this->assertSame([], $manifest->listeners);
        $this->assertSame(['http' => [], 'event' => [], 'job' => []], $manifest->middleware);
    }

    #[Test]
    public function converts_to_array_for_caching(): void
    {
        $manifest = PackageManifest::empty();
        $data = $manifest->toArray();

        $this->assertArrayHasKey('providers', $data);
        $this->assertArrayHasKey('middleware', $data);

        $restored = PackageManifest::fromArray($data);
        $this->assertEquals($manifest, $restored);
    }

    #[Test]
    public function loads_from_cache_file(): void
    {
        $tmpDir = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid();
        mkdir($tmpDir, 0777, true);

        $data = PackageManifest::empty()->toArray();
        file_put_contents($tmpDir . '/packages.php', '<?php return ' . var_export($data, true) . ';');

        $manifest = PackageManifest::load($tmpDir);

        $this->assertInstanceOf(PackageManifest::class, $manifest);

        // Cleanup
        unlink($tmpDir . '/packages.php');
        rmdir($tmpDir);
    }

    #[Test]
    public function load_returns_null_when_no_cache_file(): void
    {
        $manifest = PackageManifest::load('/nonexistent/path');

        $this->assertNull($manifest);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter PackageManifestTest`
Expected: FAIL — class not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

final class PackageManifest
{
    /**
     * @param list<class-string> $providers
     * @param list<class-string> $commands
     * @param list<class-string> $routes
     * @param array<string, string> $migrations Package name => path
     * @param array<string, class-string> $fieldTypes Field type ID => class
     * @param array<string, class-string> $entityTypes Entity type ID => class
     * @param array<class-string, list<array{class: class-string, priority: int}>> $listeners Event class => listener entries
     * @param array{http: list<array{class: class-string, priority: int}>, event: list<array{class: class-string, priority: int}>, job: list<array{class: class-string, priority: int}>} $middleware
     */
    public function __construct(
        public readonly array $providers,
        public readonly array $commands,
        public readonly array $routes,
        public readonly array $migrations,
        public readonly array $fieldTypes,
        public readonly array $entityTypes,
        public readonly array $listeners,
        public readonly array $middleware,
    ) {}

    public static function empty(): self
    {
        return new self(
            providers: [],
            commands: [],
            routes: [],
            migrations: [],
            fieldTypes: [],
            entityTypes: [],
            listeners: [],
            middleware: ['http' => [], 'event' => [], 'job' => []],
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            providers: $data['providers'] ?? [],
            commands: $data['commands'] ?? [],
            routes: $data['routes'] ?? [],
            migrations: $data['migrations'] ?? [],
            fieldTypes: $data['field_types'] ?? [],
            entityTypes: $data['entity_types'] ?? [],
            listeners: $data['listeners'] ?? [],
            middleware: $data['middleware'] ?? ['http' => [], 'event' => [], 'job' => []],
        );
    }

    public function toArray(): array
    {
        return [
            'providers' => $this->providers,
            'commands' => $this->commands,
            'routes' => $this->routes,
            'migrations' => $this->migrations,
            'field_types' => $this->fieldTypes,
            'entity_types' => $this->entityTypes,
            'listeners' => $this->listeners,
            'middleware' => $this->middleware,
        ];
    }

    public static function load(string $cachePath): ?self
    {
        $file = $cachePath . '/packages.php';
        if (!is_file($file)) {
            return null;
        }

        $data = require $file;

        return self::fromArray($data);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter PackageManifestTest`
Expected: PASS (5 tests)

**Step 5: Commit**

```bash
git add packages/foundation/src/Discovery/PackageManifest.php packages/foundation/tests/Unit/Discovery/PackageManifestTest.php
git commit -m "feat: add PackageManifest value object for compiled discovery cache"
```

---

### Task 5: PackageManifestCompiler

**Files:**
- Create: `packages/foundation/src/Discovery/PackageManifestCompiler.php`
- Test: `packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use Waaseyaa\Foundation\Attribute\AsFieldType;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageManifestCompiler::class)]
final class PackageManifestCompilerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_manifest_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $file = $this->tmpDir . '/packages.php';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function compiles_manifest_from_installed_data(): void
    {
        $installed = [
            'packages' => [
                [
                    'name' => 'waaseyaa/node',
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => ['Waaseyaa\\Node\\NodeServiceProvider'],
                            'commands' => ['Waaseyaa\\Node\\Command\\NodeCreateCommand'],
                            'routes' => ['Waaseyaa\\Node\\NodeRouteProvider'],
                            'migrations' => 'migrations/',
                        ],
                    ],
                ],
            ],
        ];

        $compiler = new PackageManifestCompiler();
        $manifest = $compiler->compileFromArray($installed);

        $this->assertContains('Waaseyaa\\Node\\NodeServiceProvider', $manifest->providers);
        $this->assertContains('Waaseyaa\\Node\\Command\\NodeCreateCommand', $manifest->commands);
        $this->assertContains('Waaseyaa\\Node\\NodeRouteProvider', $manifest->routes);
    }

    #[Test]
    public function skips_packages_without_waaseyaa_extra(): void
    {
        $installed = [
            'packages' => [
                ['name' => 'symfony/console', 'extra' => []],
            ],
        ];

        $compiler = new PackageManifestCompiler();
        $manifest = $compiler->compileFromArray($installed);

        $this->assertSame([], $manifest->providers);
    }

    #[Test]
    public function scans_classes_for_attributes(): void
    {
        $compiler = new PackageManifestCompiler();
        $manifest = $compiler->compileFromArray(
            installed: ['packages' => []],
            classesToScan: [
                CompilerTestFieldType::class,
                CompilerTestMiddleware::class,
            ],
        );

        $this->assertSame(['test_text' => CompilerTestFieldType::class], $manifest->fieldTypes);
        $this->assertCount(1, $manifest->middleware['http']);
        $this->assertSame(CompilerTestMiddleware::class, $manifest->middleware['http'][0]['class']);
        $this->assertSame(100, $manifest->middleware['http'][0]['priority']);
    }

    #[Test]
    public function writes_cache_file(): void
    {
        $compiler = new PackageManifestCompiler();
        $manifest = $compiler->compileFromArray(['packages' => []]);

        $compiler->writeCache($manifest, $this->tmpDir);

        $this->assertFileExists($this->tmpDir . '/packages.php');

        $loaded = PackageManifest::load($this->tmpDir);
        $this->assertNotNull($loaded);
        $this->assertEquals($manifest, $loaded);
    }

    #[Test]
    public function middleware_sorted_by_priority_descending(): void
    {
        $compiler = new PackageManifestCompiler();
        $manifest = $compiler->compileFromArray(
            installed: ['packages' => []],
            classesToScan: [
                CompilerTestMiddleware::class,
                CompilerTestLowPriorityMiddleware::class,
            ],
        );

        $httpMiddleware = $manifest->middleware['http'];
        $this->assertCount(2, $httpMiddleware);
        // Higher priority first
        $this->assertSame(100, $httpMiddleware[0]['priority']);
        $this->assertSame(10, $httpMiddleware[1]['priority']);
    }
}

#[AsFieldType(id: 'test_text', label: 'Test Text')]
final class CompilerTestFieldType {}

#[AsMiddleware(pipeline: 'http', priority: 100)]
final class CompilerTestMiddleware {}

#[AsMiddleware(pipeline: 'http', priority: 10)]
final class CompilerTestLowPriorityMiddleware {}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter PackageManifestCompilerTest`
Expected: FAIL — class not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

use Waaseyaa\Foundation\Attribute\AsEntityType;
use Waaseyaa\Foundation\Attribute\AsFieldType;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Event\Attribute\Listener;

final class PackageManifestCompiler
{
    /**
     * Compile a manifest from Composer's installed.json data and optional class scanning.
     *
     * @param array $installed Parsed installed.json content
     * @param list<class-string> $classesToScan Additional classes to scan for attributes
     */
    public function compileFromArray(array $installed, array $classesToScan = []): PackageManifest
    {
        $providers = [];
        $commands = [];
        $routes = [];
        $migrations = [];
        $fieldTypes = [];
        $entityTypes = [];
        $listeners = [];
        $middleware = ['http' => [], 'event' => [], 'job' => []];

        foreach ($installed['packages'] ?? [] as $package) {
            $extra = $package['extra']['waaseyaa'] ?? null;
            if ($extra === null) {
                continue;
            }

            foreach ($extra['providers'] ?? [] as $class) {
                $providers[] = $class;
            }
            foreach ($extra['commands'] ?? [] as $class) {
                $commands[] = $class;
            }
            foreach ($extra['routes'] ?? [] as $class) {
                $routes[] = $class;
            }
            if (isset($extra['migrations'])) {
                $migrations[$package['name']] = $extra['migrations'];
            }
        }

        foreach ($classesToScan as $className) {
            $this->scanClass($className, $fieldTypes, $entityTypes, $listeners, $middleware);
        }

        // Sort middleware by priority descending within each pipeline
        foreach ($middleware as $pipeline => $entries) {
            usort($entries, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);
            $middleware[$pipeline] = $entries;
        }

        return new PackageManifest(
            providers: $providers,
            commands: $commands,
            routes: $routes,
            migrations: $migrations,
            fieldTypes: $fieldTypes,
            entityTypes: $entityTypes,
            listeners: $listeners,
            middleware: $middleware,
        );
    }

    public function writeCache(PackageManifest $manifest, string $cachePath): void
    {
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0777, true);
        }

        $content = "<?php\n\nreturn " . var_export($manifest->toArray(), true) . ";\n";
        file_put_contents($cachePath . '/packages.php', $content);
    }

    private function scanClass(
        string $className,
        array &$fieldTypes,
        array &$entityTypes,
        array &$listeners,
        array &$middleware,
    ): void {
        $ref = new \ReflectionClass($className);

        foreach ($ref->getAttributes(AsFieldType::class) as $attr) {
            $instance = $attr->newInstance();
            $fieldTypes[$instance->id] = $className;
        }

        foreach ($ref->getAttributes(AsEntityType::class) as $attr) {
            $instance = $attr->newInstance();
            $entityTypes[$instance->id] = $className;
        }

        foreach ($ref->getAttributes(Listener::class) as $attr) {
            $instance = $attr->newInstance();
            // Determine event class from __invoke parameter type
            if ($ref->hasMethod('__invoke')) {
                $params = $ref->getMethod('__invoke')->getParameters();
                if (isset($params[0])) {
                    $type = $params[0]->getType();
                    if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                        $eventClass = $type->getName();
                        $listeners[$eventClass] ??= [];
                        $listeners[$eventClass][] = ['class' => $className, 'priority' => $instance->priority];
                    }
                }
            }
        }

        foreach ($ref->getAttributes(AsMiddleware::class) as $attr) {
            $instance = $attr->newInstance();
            $pipeline = $instance->pipeline;
            if (isset($middleware[$pipeline])) {
                $middleware[$pipeline][] = ['class' => $className, 'priority' => $instance->priority];
            }
        }
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter PackageManifestCompilerTest`
Expected: PASS (5 tests)

**Step 5: Commit**

```bash
git add packages/foundation/src/Discovery/PackageManifestCompiler.php packages/foundation/tests/Unit/Discovery/PackageManifestCompilerTest.php
git commit -m "feat: add PackageManifestCompiler for hybrid manifest + attribute discovery"
```

---

### Task 6: HTTP middleware interfaces and pipeline

**Files:**
- Create: `packages/foundation/src/Middleware/HttpMiddlewareInterface.php`
- Create: `packages/foundation/src/Middleware/HttpHandlerInterface.php`
- Create: `packages/foundation/src/Middleware/HttpPipeline.php`
- Test: `packages/foundation/tests/Unit/Middleware/HttpPipelineTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(HttpPipeline::class)]
final class HttpPipelineTest extends TestCase
{
    #[Test]
    public function empty_pipeline_calls_final_handler(): void
    {
        $pipeline = new HttpPipeline([]);
        $request = Request::create('/test');

        $response = $pipeline->handle($request, new class implements HttpHandlerInterface {
            public function handle(Request $request): Response {
                return new Response('final');
            }
        });

        $this->assertSame('final', $response->getContent());
    }

    #[Test]
    public function middleware_wraps_final_handler(): void
    {
        $middleware = new class implements HttpMiddlewareInterface {
            public function process(Request $request, HttpHandlerInterface $next): Response {
                $response = $next->handle($request);
                $response->headers->set('X-Wrapped', 'true');
                return $response;
            }
        };

        $pipeline = new HttpPipeline([$middleware]);
        $request = Request::create('/test');

        $response = $pipeline->handle($request, new class implements HttpHandlerInterface {
            public function handle(Request $request): Response {
                return new Response('body');
            }
        });

        $this->assertSame('body', $response->getContent());
        $this->assertSame('true', $response->headers->get('X-Wrapped'));
    }

    #[Test]
    public function middleware_executes_in_order(): void
    {
        $order = [];

        $first = new class($order) implements HttpMiddlewareInterface {
            private array &$order;
            public function __construct(array &$order) { $this->order = &$order; }
            public function process(Request $request, HttpHandlerInterface $next): Response {
                $this->order[] = 'first-before';
                $response = $next->handle($request);
                $this->order[] = 'first-after';
                return $response;
            }
        };

        $second = new class($order) implements HttpMiddlewareInterface {
            private array &$order;
            public function __construct(array &$order) { $this->order = &$order; }
            public function process(Request $request, HttpHandlerInterface $next): Response {
                $this->order[] = 'second-before';
                $response = $next->handle($request);
                $this->order[] = 'second-after';
                return $response;
            }
        };

        $pipeline = new HttpPipeline([$first, $second]);

        $pipeline->handle(Request::create('/'), new class implements HttpHandlerInterface {
            public function handle(Request $request): Response {
                return new Response('ok');
            }
        });

        $this->assertSame(['first-before', 'second-before', 'second-after', 'first-after'], $order);
    }

    #[Test]
    public function middleware_can_short_circuit(): void
    {
        $middleware = new class implements HttpMiddlewareInterface {
            public function process(Request $request, HttpHandlerInterface $next): Response {
                return new Response('blocked', 403);
            }
        };

        $pipeline = new HttpPipeline([$middleware]);
        $finalCalled = false;

        $response = $pipeline->handle(Request::create('/'), new class($finalCalled) implements HttpHandlerInterface {
            private bool &$called;
            public function __construct(bool &$called) { $this->called = &$called; }
            public function handle(Request $request): Response {
                $this->called = true;
                return new Response('ok');
            }
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($finalCalled);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter HttpPipelineTest`
Expected: FAIL — interfaces/class not found.

**Step 3: Write the implementation**

`packages/foundation/src/Middleware/HttpMiddlewareInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface HttpMiddlewareInterface
{
    public function process(Request $request, HttpHandlerInterface $next): Response;
}
```

`packages/foundation/src/Middleware/HttpHandlerInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface HttpHandlerInterface
{
    public function handle(Request $request): Response;
}
```

`packages/foundation/src/Middleware/HttpPipeline.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class HttpPipeline
{
    /** @param HttpMiddlewareInterface[] $middleware */
    public function __construct(private readonly array $middleware) {}

    public function handle(Request $request, HttpHandlerInterface $finalHandler): Response
    {
        $handler = $finalHandler;

        foreach (array_reverse($this->middleware) as $mw) {
            $next = $handler;
            $handler = new class($mw, $next) implements HttpHandlerInterface {
                public function __construct(
                    private readonly HttpMiddlewareInterface $middleware,
                    private readonly HttpHandlerInterface $next,
                ) {}

                public function handle(Request $request): Response
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $handler->handle($request);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter HttpPipelineTest`
Expected: PASS (4 tests)

**Step 5: Commit**

```bash
git add packages/foundation/src/Middleware/HttpMiddlewareInterface.php packages/foundation/src/Middleware/HttpHandlerInterface.php packages/foundation/src/Middleware/HttpPipeline.php packages/foundation/tests/Unit/Middleware/HttpPipelineTest.php
git commit -m "feat: add typed HTTP middleware pipeline with onion pattern"
```

---

### Task 7: Event middleware interfaces and pipeline

**Files:**
- Create: `packages/foundation/src/Middleware/EventMiddlewareInterface.php`
- Create: `packages/foundation/src/Middleware/EventHandlerInterface.php`
- Create: `packages/foundation/src/Middleware/EventPipeline.php`
- Test: `packages/foundation/tests/Unit/Middleware/EventPipelineTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use Waaseyaa\Foundation\Event\DomainEvent;
use Waaseyaa\Foundation\Middleware\EventHandlerInterface;
use Waaseyaa\Foundation\Middleware\EventMiddlewareInterface;
use Waaseyaa\Foundation\Middleware\EventPipeline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventPipeline::class)]
final class EventPipelineTest extends TestCase
{
    #[Test]
    public function empty_pipeline_calls_final_handler(): void
    {
        $pipeline = new EventPipeline([]);
        $called = false;

        $pipeline->handle(
            new TestEvent('node', '1'),
            new class($called) implements EventHandlerInterface {
                private bool &$called;
                public function __construct(bool &$called) { $this->called = &$called; }
                public function handle(DomainEvent $event): void { $this->called = true; }
            }
        );

        $this->assertTrue($called);
    }

    #[Test]
    public function middleware_wraps_handler(): void
    {
        $order = [];

        $middleware = new class($order) implements EventMiddlewareInterface {
            private array &$order;
            public function __construct(array &$order) { $this->order = &$order; }
            public function process(DomainEvent $event, EventHandlerInterface $next): void {
                $this->order[] = 'before';
                $next->handle($event);
                $this->order[] = 'after';
            }
        };

        $pipeline = new EventPipeline([$middleware]);
        $pipeline->handle(
            new TestEvent('node', '1'),
            new class($order) implements EventHandlerInterface {
                private array &$order;
                public function __construct(array &$order) { $this->order = &$order; }
                public function handle(DomainEvent $event): void { $this->order[] = 'handler'; }
            }
        );

        $this->assertSame(['before', 'handler', 'after'], $order);
    }

    #[Test]
    public function middleware_can_short_circuit(): void
    {
        $middleware = new class implements EventMiddlewareInterface {
            public function process(DomainEvent $event, EventHandlerInterface $next): void {
                // Don't call $next — short-circuit
            }
        };

        $pipeline = new EventPipeline([$middleware]);
        $handlerCalled = false;

        $pipeline->handle(
            new TestEvent('node', '1'),
            new class($handlerCalled) implements EventHandlerInterface {
                private bool &$called;
                public function __construct(bool &$called) { $this->called = &$called; }
                public function handle(DomainEvent $event): void { $this->called = true; }
            }
        );

        $this->assertFalse($handlerCalled);
    }
}

final class TestEvent extends DomainEvent
{
    public function getPayload(): array { return []; }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter EventPipelineTest`
Expected: FAIL — interfaces/class not found.

**Step 3: Write the implementation**

`packages/foundation/src/Middleware/EventMiddlewareInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Foundation\Event\DomainEvent;

interface EventMiddlewareInterface
{
    public function process(DomainEvent $event, EventHandlerInterface $next): void;
}
```

`packages/foundation/src/Middleware/EventHandlerInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Foundation\Event\DomainEvent;

interface EventHandlerInterface
{
    public function handle(DomainEvent $event): void;
}
```

`packages/foundation/src/Middleware/EventPipeline.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Foundation\Event\DomainEvent;

final class EventPipeline
{
    /** @param EventMiddlewareInterface[] $middleware */
    public function __construct(private readonly array $middleware) {}

    public function handle(DomainEvent $event, EventHandlerInterface $finalHandler): void
    {
        $handler = $finalHandler;

        foreach (array_reverse($this->middleware) as $mw) {
            $next = $handler;
            $handler = new class($mw, $next) implements EventHandlerInterface {
                public function __construct(
                    private readonly EventMiddlewareInterface $middleware,
                    private readonly EventHandlerInterface $next,
                ) {}

                public function handle(DomainEvent $event): void
                {
                    $this->middleware->process($event, $this->next);
                }
            };
        }

        $handler->handle($event);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter EventPipelineTest`
Expected: PASS (3 tests)

**Step 5: Commit**

```bash
git add packages/foundation/src/Middleware/EventMiddlewareInterface.php packages/foundation/src/Middleware/EventHandlerInterface.php packages/foundation/src/Middleware/EventPipeline.php packages/foundation/tests/Unit/Middleware/EventPipelineTest.php
git commit -m "feat: add typed event middleware pipeline"
```

---

### Task 8: Job middleware interfaces and pipeline

**Files:**
- Create: `packages/foundation/src/Middleware/JobMiddlewareInterface.php`
- Create: `packages/foundation/src/Middleware/JobNextHandlerInterface.php`
- Create: `packages/foundation/src/Middleware/JobPipeline.php`
- Test: `packages/foundation/tests/Unit/Middleware/JobPipelineTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use Waaseyaa\Foundation\Middleware\JobMiddlewareInterface;
use Waaseyaa\Foundation\Middleware\JobNextHandlerInterface;
use Waaseyaa\Foundation\Middleware\JobPipeline;
use Waaseyaa\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobPipeline::class)]
final class JobPipelineTest extends TestCase
{
    #[Test]
    public function empty_pipeline_calls_final_handler(): void
    {
        $pipeline = new JobPipeline([]);
        $job = new TestJob();
        $called = false;

        $pipeline->handle($job, new class($called) implements JobNextHandlerInterface {
            private bool &$called;
            public function __construct(bool &$called) { $this->called = &$called; }
            public function handle(Job $job): void { $this->called = true; }
        });

        $this->assertTrue($called);
    }

    #[Test]
    public function middleware_wraps_handler(): void
    {
        $order = [];

        $middleware = new class($order) implements JobMiddlewareInterface {
            private array &$order;
            public function __construct(array &$order) { $this->order = &$order; }
            public function process(Job $job, JobNextHandlerInterface $next): void {
                $this->order[] = 'before';
                $next->handle($job);
                $this->order[] = 'after';
            }
        };

        $pipeline = new JobPipeline([$middleware]);
        $pipeline->handle(new TestJob(), new class($order) implements JobNextHandlerInterface {
            private array &$order;
            public function __construct(array &$order) { $this->order = &$order; }
            public function handle(Job $job): void { $this->order[] = 'handler'; }
        });

        $this->assertSame(['before', 'handler', 'after'], $order);
    }

    #[Test]
    public function middleware_can_short_circuit(): void
    {
        $middleware = new class implements JobMiddlewareInterface {
            public function process(Job $job, JobNextHandlerInterface $next): void {
                // Short-circuit: don't call next
            }
        };

        $pipeline = new JobPipeline([$middleware]);
        $handlerCalled = false;

        $pipeline->handle(new TestJob(), new class($handlerCalled) implements JobNextHandlerInterface {
            private bool &$called;
            public function __construct(bool &$called) { $this->called = &$called; }
            public function handle(Job $job): void { $this->called = true; }
        });

        $this->assertFalse($handlerCalled);
    }
}

final class TestJob extends Job
{
    public function handle(): void {}
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter JobPipelineTest`
Expected: FAIL — interfaces/class not found.

**Step 3: Write the implementation**

`packages/foundation/src/Middleware/JobMiddlewareInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Queue\Job;

interface JobMiddlewareInterface
{
    public function process(Job $job, JobNextHandlerInterface $next): void;
}
```

`packages/foundation/src/Middleware/JobNextHandlerInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Queue\Job;

interface JobNextHandlerInterface
{
    public function handle(Job $job): void;
}
```

`packages/foundation/src/Middleware/JobPipeline.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Queue\Job;

final class JobPipeline
{
    /** @param JobMiddlewareInterface[] $middleware */
    public function __construct(private readonly array $middleware) {}

    public function handle(Job $job, JobNextHandlerInterface $finalHandler): void
    {
        $handler = $finalHandler;

        foreach (array_reverse($this->middleware) as $mw) {
            $next = $handler;
            $handler = new class($mw, $next) implements JobNextHandlerInterface {
                public function __construct(
                    private readonly JobMiddlewareInterface $middleware,
                    private readonly JobNextHandlerInterface $next,
                ) {}

                public function handle(Job $job): void
                {
                    $this->middleware->process($job, $this->next);
                }
            };
        }

        $handler->handle($job);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter JobPipelineTest`
Expected: PASS (3 tests)

**Step 5: Commit**

```bash
git add packages/foundation/src/Middleware/JobMiddlewareInterface.php packages/foundation/src/Middleware/JobNextHandlerInterface.php packages/foundation/src/Middleware/JobPipeline.php packages/foundation/tests/Unit/Middleware/JobPipelineTest.php
git commit -m "feat: add typed job middleware pipeline"
```

---

### Task 9: Integrate EventPipeline into EventBus

**Files:**
- Modify: `packages/foundation/src/Event/EventBus.php`
- Modify: `packages/foundation/tests/Unit/Event/EventBusTest.php`

**Step 1: Write the failing test (add to existing test file)**

Append these tests to `EventBusTest.php`:

```php
#[Test]
public function event_middleware_wraps_sync_dispatch(): void
{
    $middlewareCalled = false;
    $middleware = new class($middlewareCalled) implements \Waaseyaa\Foundation\Middleware\EventMiddlewareInterface {
        private bool &$called;
        public function __construct(bool &$called) { $this->called = &$called; }
        public function process(DomainEvent $event, \Waaseyaa\Foundation\Middleware\EventHandlerInterface $next): void {
            $this->called = true;
            $next->handle($event);
        }
    };

    $pipeline = new \Waaseyaa\Foundation\Middleware\EventPipeline([$middleware]);

    $bus = new EventBus(
        syncDispatcher: new EventDispatcher(),
        asyncBus: $this->createNullMessageBus(),
        broadcaster: $this->createNullBroadcaster(),
        eventPipeline: $pipeline,
    );

    $bus->dispatch(new TestNodeSaved('node', '42', ['title']));

    $this->assertTrue($middlewareCalled);
}

#[Test]
public function event_middleware_can_short_circuit_sync_dispatch(): void
{
    $syncReceived = false;
    $dispatcher = new EventDispatcher();
    $dispatcher->addListener(TestNodeSaved::class, function () use (&$syncReceived) {
        $syncReceived = true;
    });

    $blockingMiddleware = new class implements \Waaseyaa\Foundation\Middleware\EventMiddlewareInterface {
        public function process(DomainEvent $event, \Waaseyaa\Foundation\Middleware\EventHandlerInterface $next): void {
            // Don't call $next — block sync dispatch
        }
    };

    $pipeline = new \Waaseyaa\Foundation\Middleware\EventPipeline([$blockingMiddleware]);

    $bus = new EventBus(
        syncDispatcher: $dispatcher,
        asyncBus: $this->createNullMessageBus(),
        broadcaster: $this->createNullBroadcaster(),
        eventPipeline: $pipeline,
    );

    $bus->dispatch(new TestNodeSaved('node', '42', ['title']));

    $this->assertFalse($syncReceived);
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter EventBusTest`
Expected: FAIL — EventBus constructor doesn't accept `eventPipeline`.

**Step 3: Modify EventBus to accept optional EventPipeline**

Update `packages/foundation/src/Event/EventBus.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Event;

use Waaseyaa\Foundation\Middleware\EventHandlerInterface;
use Waaseyaa\Foundation\Middleware\EventPipeline;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class EventBus
{
    public function __construct(
        private readonly EventDispatcherInterface $syncDispatcher,
        private readonly MessageBusInterface $asyncBus,
        private readonly BroadcasterInterface $broadcaster,
        private readonly ?EventStoreInterface $eventStore = null,
        private readonly ?EventPipeline $eventPipeline = null,
    ) {}

    public function dispatch(DomainEvent $event): void
    {
        $this->eventStore?->append($event);

        if ($this->eventPipeline !== null) {
            $dispatcher = $this->syncDispatcher;
            $this->eventPipeline->handle($event, new class($dispatcher) implements EventHandlerInterface {
                public function __construct(private readonly EventDispatcherInterface $dispatcher) {}
                public function handle(DomainEvent $event): void {
                    $this->dispatcher->dispatch($event);
                }
            });
        } else {
            $this->syncDispatcher->dispatch($event);
        }

        $this->asyncBus->dispatch($event);
        $this->broadcaster->broadcast($event);
    }
}
```

**Step 4: Run all EventBus tests to verify they pass**

Run: `./vendor/bin/phpunit --filter EventBusTest`
Expected: PASS (all 7 tests — 5 existing + 2 new)

**Step 5: Commit**

```bash
git add packages/foundation/src/Event/EventBus.php packages/foundation/tests/Unit/Event/EventBusTest.php
git commit -m "feat: integrate EventPipeline into EventBus for event middleware support"
```

---

### Task 10: Integrate JobPipeline into JobHandler

**Files:**
- Modify: `packages/queue/src/Handler/JobHandler.php`
- Test: `packages/queue/tests/Unit/Handler/JobHandlerTest.php`

**Step 1: Write the failing test**

Create `packages/queue/tests/Unit/Handler/JobHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Handler;

use Waaseyaa\Foundation\Middleware\JobMiddlewareInterface;
use Waaseyaa\Foundation\Middleware\JobNextHandlerInterface;
use Waaseyaa\Foundation\Middleware\JobPipeline;
use Waaseyaa\Queue\Handler\JobHandler;
use Waaseyaa\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobHandler::class)]
final class JobHandlerTest extends TestCase
{
    #[Test]
    public function supports_job_instances(): void
    {
        $handler = new JobHandler();

        $this->assertTrue($handler->supports(new SimpleTestJob()));
        $this->assertFalse($handler->supports(new \stdClass()));
    }

    #[Test]
    public function handles_job_and_increments_attempts(): void
    {
        $handler = new JobHandler();
        $job = new SimpleTestJob();

        $handler->handle($job);

        $this->assertSame(1, $job->getAttempts());
        $this->assertTrue($job->wasHandled);
    }

    #[Test]
    public function handles_job_through_middleware_pipeline(): void
    {
        $middlewareCalled = false;
        $middleware = new class($middlewareCalled) implements JobMiddlewareInterface {
            private bool &$called;
            public function __construct(bool &$called) { $this->called = &$called; }
            public function process(Job $job, JobNextHandlerInterface $next): void {
                $this->called = true;
                $next->handle($job);
            }
        };

        $pipeline = new JobPipeline([$middleware]);
        $handler = new JobHandler($pipeline);
        $job = new SimpleTestJob();

        $handler->handle($job);

        $this->assertTrue($middlewareCalled);
        $this->assertTrue($job->wasHandled);
    }

    #[Test]
    public function middleware_can_prevent_job_execution(): void
    {
        $blockingMiddleware = new class implements JobMiddlewareInterface {
            public function process(Job $job, JobNextHandlerInterface $next): void {
                // Don't call $next
            }
        };

        $pipeline = new JobPipeline([$blockingMiddleware]);
        $handler = new JobHandler($pipeline);
        $job = new SimpleTestJob();

        $handler->handle($job);

        $this->assertFalse($job->wasHandled);
    }
}

final class SimpleTestJob extends Job
{
    public bool $wasHandled = false;

    public function handle(): void
    {
        $this->wasHandled = true;
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter JobHandlerTest`
Expected: FAIL — JobHandler constructor doesn't accept pipeline.

**Step 3: Modify JobHandler**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Handler;

use Waaseyaa\Foundation\Middleware\JobNextHandlerInterface;
use Waaseyaa\Foundation\Middleware\JobPipeline;
use Waaseyaa\Queue\Job;

final class JobHandler implements HandlerInterface
{
    public function __construct(
        private readonly ?JobPipeline $pipeline = null,
    ) {}

    public function supports(object $message): bool
    {
        return $message instanceof Job;
    }

    public function handle(object $message): void
    {
        /** @var Job $message */
        $message->incrementAttempts();

        if ($this->pipeline !== null) {
            $this->pipeline->handle($message, new class implements JobNextHandlerInterface {
                public function handle(Job $job): void
                {
                    $job->handle();
                }
            });
        } else {
            $message->handle();
        }
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter JobHandlerTest`
Expected: PASS (4 tests)

**Step 5: Commit**

```bash
git add packages/queue/src/Handler/JobHandler.php packages/queue/tests/Unit/Handler/JobHandlerTest.php
git commit -m "feat: integrate JobPipeline into JobHandler for job middleware support"
```

---

### Task 11: ConfigCacheCompiler

**Files:**
- Create: `packages/config/src/Cache/ConfigCacheCompiler.php`
- Test: `packages/config/tests/Unit/Cache/ConfigCacheCompilerTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Cache;

use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Waaseyaa\Config\Storage\MemoryStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigCacheCompiler::class)]
final class ConfigCacheCompilerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_config_cache_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $file = $this->tmpDir . '/config.php';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function compiles_all_config_to_cache_file(): void
    {
        $storage = new MemoryStorage();
        $storage->write('system.site', ['name' => 'Test', 'langcode' => 'en']);
        $storage->write('node.type.article', ['id' => 'article', 'label' => 'Article']);

        $compiler = new ConfigCacheCompiler($storage);
        $compiler->compile($this->tmpDir);

        $this->assertFileExists($this->tmpDir . '/config.php');

        $cached = require $this->tmpDir . '/config.php';
        $this->assertSame(['name' => 'Test', 'langcode' => 'en'], $cached['system.site']);
        $this->assertSame(['id' => 'article', 'label' => 'Article'], $cached['node.type.article']);
    }

    #[Test]
    public function applies_environment_overrides(): void
    {
        $storage = new MemoryStorage();
        $storage->write('system.site', ['name' => 'Dev Site', 'langcode' => 'en']);

        // Simulate environment override
        $envOverrides = ['system.site' => ['name' => 'Prod Site']];

        $compiler = new ConfigCacheCompiler($storage);
        $compiler->compile($this->tmpDir, $envOverrides);

        $cached = require $this->tmpDir . '/config.php';
        $this->assertSame('Prod Site', $cached['system.site']['name']);
        $this->assertSame('en', $cached['system.site']['langcode']);
    }

    #[Test]
    public function handles_empty_storage(): void
    {
        $storage = new MemoryStorage();
        $compiler = new ConfigCacheCompiler($storage);

        $compiler->compile($this->tmpDir);

        $cached = require $this->tmpDir . '/config.php';
        $this->assertSame([], $cached);
    }

    #[Test]
    public function clear_removes_cache_file(): void
    {
        $storage = new MemoryStorage();
        $storage->write('test', ['key' => 'value']);

        $compiler = new ConfigCacheCompiler($storage);
        $compiler->compile($this->tmpDir);

        $this->assertFileExists($this->tmpDir . '/config.php');

        $compiler->clear($this->tmpDir);

        $this->assertFileDoesNotExist($this->tmpDir . '/config.php');
    }

    #[Test]
    public function reports_compiled_count(): void
    {
        $storage = new MemoryStorage();
        $storage->write('a', ['x' => 1]);
        $storage->write('b', ['y' => 2]);
        $storage->write('c', ['z' => 3]);

        $compiler = new ConfigCacheCompiler($storage);
        $count = $compiler->compile($this->tmpDir);

        $this->assertSame(3, $count);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter ConfigCacheCompilerTest`
Expected: FAIL — class not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Cache;

use Waaseyaa\Config\StorageInterface;

final class ConfigCacheCompiler
{
    public function __construct(
        private readonly StorageInterface $activeStorage,
    ) {}

    /**
     * Compile all config into a single cached PHP file.
     *
     * @param string $cachePath Directory to write the cache file to
     * @param array<string, array<string, mixed>> $envOverrides Config name => key/value overrides
     * @return int Number of config objects compiled
     */
    public function compile(string $cachePath, array $envOverrides = []): int
    {
        $allNames = $this->activeStorage->listAll();
        $compiled = [];

        foreach ($allNames as $name) {
            $data = $this->activeStorage->read($name);
            if ($data === false) {
                continue;
            }

            // Apply environment overrides
            if (isset($envOverrides[$name])) {
                $data = array_replace_recursive($data, $envOverrides[$name]);
            }

            $compiled[$name] = $data;
        }

        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0777, true);
        }

        $content = "<?php\n\nreturn " . var_export($compiled, true) . ";\n";
        file_put_contents($cachePath . '/config.php', $content);

        return count($compiled);
    }

    public function clear(string $cachePath): void
    {
        $file = $cachePath . '/config.php';
        if (is_file($file)) {
            unlink($file);
        }
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter ConfigCacheCompilerTest`
Expected: PASS (5 tests)

**Step 5: Commit**

```bash
git add packages/config/src/Cache/ConfigCacheCompiler.php packages/config/tests/Unit/Cache/ConfigCacheCompilerTest.php
git commit -m "feat: add ConfigCacheCompiler for single-file config caching"
```

---

### Task 12: CachedConfigFactory decorator

**Files:**
- Create: `packages/config/src/Cache/CachedConfigFactory.php`
- Test: `packages/config/tests/Unit/Cache/CachedConfigFactoryTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Cache;

use Waaseyaa\Config\Cache\CachedConfigFactory;
use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(CachedConfigFactory::class)]
final class CachedConfigFactoryTest extends TestCase
{
    private string $tmpDir;
    private MemoryStorage $storage;
    private ConfigFactory $inner;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_cached_factory_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);

        $this->storage = new MemoryStorage();
        $this->inner = new ConfigFactory($this->storage, new EventDispatcher());
    }

    protected function tearDown(): void
    {
        $file = $this->tmpDir . '/config.php';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function implements_config_factory_interface(): void
    {
        $factory = new CachedConfigFactory($this->inner, $this->tmpDir);

        $this->assertInstanceOf(ConfigFactoryInterface::class, $factory);
    }

    #[Test]
    public function returns_config_from_cache_when_available(): void
    {
        // Write config and compile cache
        $this->storage->write('system.site', ['name' => 'Cached Site']);
        $compiler = new ConfigCacheCompiler($this->storage);
        $compiler->compile($this->tmpDir);

        // Clear the live storage to prove we're reading from cache
        $this->storage->deleteAll();

        $factory = new CachedConfigFactory($this->inner, $this->tmpDir);
        $config = $factory->get('system.site');

        $this->assertSame('Cached Site', $config->get('name'));
    }

    #[Test]
    public function falls_through_to_inner_when_no_cache(): void
    {
        $this->storage->write('system.site', ['name' => 'Live Site']);

        $factory = new CachedConfigFactory($this->inner, '/nonexistent/path');
        $config = $factory->get('system.site');

        $this->assertSame('Live Site', $config->get('name'));
    }

    #[Test]
    public function falls_through_for_config_not_in_cache(): void
    {
        // Compile cache with only one config
        $this->storage->write('cached.config', ['key' => 'cached']);
        $compiler = new ConfigCacheCompiler($this->storage);
        $compiler->compile($this->tmpDir);

        // Add another config to live storage
        $this->storage->write('live.config', ['key' => 'live']);

        $factory = new CachedConfigFactory($this->inner, $this->tmpDir);

        $cached = $factory->get('cached.config');
        $this->assertSame('cached', $cached->get('key'));

        $live = $factory->get('live.config');
        $this->assertSame('live', $live->get('key'));
    }

    #[Test]
    public function delegates_get_editable_to_inner(): void
    {
        $this->storage->write('system.site', ['name' => 'Test']);

        $factory = new CachedConfigFactory($this->inner, $this->tmpDir);
        $config = $factory->getEditable('system.site');

        // Should be mutable (from inner factory)
        $config->set('name', 'Updated');
        $this->assertSame('Updated', $config->get('name'));
    }

    #[Test]
    public function delegates_list_all_to_inner(): void
    {
        $this->storage->write('a.config', []);
        $this->storage->write('b.config', []);

        $factory = new CachedConfigFactory($this->inner, $this->tmpDir);

        $this->assertSame(['a.config', 'b.config'], $factory->listAll());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter CachedConfigFactoryTest`
Expected: FAIL — class not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Cache;

use Waaseyaa\Config\Config;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigInterface;
use Waaseyaa\Config\Storage\MemoryStorage;

final class CachedConfigFactory implements ConfigFactoryInterface
{
    private ?array $cache = null;
    private bool $cacheLoaded = false;

    public function __construct(
        private readonly ConfigFactoryInterface $inner,
        private readonly string $cachePath,
    ) {}

    public function get(string $name): ConfigInterface
    {
        if (!$this->cacheLoaded) {
            $this->cache = $this->loadCache();
            $this->cacheLoaded = true;
        }

        if ($this->cache !== null && array_key_exists($name, $this->cache)) {
            $readOnlyStorage = new MemoryStorage();
            return new Config(
                name: $name,
                storage: $readOnlyStorage,
                data: $this->cache[$name],
                immutable: true,
                isNew: false,
            );
        }

        return $this->inner->get($name);
    }

    public function getEditable(string $name): ConfigInterface
    {
        return $this->inner->getEditable($name);
    }

    public function loadMultiple(array $names): array
    {
        $configs = [];
        foreach ($names as $name) {
            $configs[$name] = $this->get($name);
        }
        return $configs;
    }

    public function rename(string $oldName, string $newName): static
    {
        $this->inner->rename($oldName, $newName);
        return $this;
    }

    public function listAll(string $prefix = ''): array
    {
        return $this->inner->listAll($prefix);
    }

    private function loadCache(): ?array
    {
        $file = $this->cachePath . '/config.php';
        if (!is_file($file)) {
            return null;
        }

        return require $file;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit --filter CachedConfigFactoryTest`
Expected: PASS (6 tests)

**Step 5: Commit**

```bash
git add packages/config/src/Cache/CachedConfigFactory.php packages/config/tests/Unit/Cache/CachedConfigFactoryTest.php
git commit -m "feat: add CachedConfigFactory decorator for cached config reads"
```

---

### Task 13: ConfigCacheInvalidator listener

**Files:**
- Create: `packages/config/src/Listener/ConfigCacheInvalidator.php`
- Test: `packages/config/tests/Unit/Listener/ConfigCacheInvalidatorTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Listener;

use Waaseyaa\Config\Event\ConfigEvent;
use Waaseyaa\Config\Listener\ConfigCacheInvalidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigCacheInvalidator::class)]
final class ConfigCacheInvalidatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_invalidator_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $file = $this->tmpDir . '/config.php';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function deletes_cache_file_on_config_event(): void
    {
        // Create a cache file
        file_put_contents($this->tmpDir . '/config.php', '<?php return [];');
        $this->assertFileExists($this->tmpDir . '/config.php');

        $invalidator = new ConfigCacheInvalidator($this->tmpDir);
        $invalidator->__invoke(new ConfigEvent('system.site', []));

        $this->assertFileDoesNotExist($this->tmpDir . '/config.php');
    }

    #[Test]
    public function does_not_throw_when_no_cache_file(): void
    {
        $invalidator = new ConfigCacheInvalidator($this->tmpDir);

        // Should not throw
        $invalidator->__invoke(new ConfigEvent('system.site', []));
        $this->assertTrue(true);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter ConfigCacheInvalidatorTest`
Expected: FAIL — class not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Listener;

use Waaseyaa\Config\Event\ConfigEvent;

final class ConfigCacheInvalidator
{
    public function __construct(
        private readonly string $cachePath,
    ) {}

    public function __invoke(ConfigEvent $event): void
    {
        $file = $this->cachePath . '/config.php';
        if (is_file($file)) {
            unlink($file);
        }
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter ConfigCacheInvalidatorTest`
Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add packages/config/src/Listener/ConfigCacheInvalidator.php packages/config/tests/Unit/Listener/ConfigCacheInvalidatorTest.php
git commit -m "feat: add ConfigCacheInvalidator to delete cache on config changes"
```

---

### Task 14: OptimizeManifestCommand

**Files:**
- Create: `packages/cli/src/Command/Optimize/OptimizeManifestCommand.php`
- Test: `packages/cli/tests/Unit/Command/Optimize/OptimizeManifestCommandTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Optimize;

use Waaseyaa\CLI\Command\Optimize\OptimizeManifestCommand;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(OptimizeManifestCommand::class)]
final class OptimizeManifestCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_optimize_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $file = $this->tmpDir . '/packages.php';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function compiles_manifest_and_outputs_success(): void
    {
        $compiler = new PackageManifestCompiler();

        $command = new OptimizeManifestCommand($compiler, $this->tmpDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('manifest', strtolower($tester->getDisplay()));
        $this->assertFileExists($this->tmpDir . '/packages.php');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter OptimizeManifestCommandTest`
Expected: FAIL — class not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Optimize;

use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'optimize:manifest',
    description: 'Compile the package manifest cache',
)]
final class OptimizeManifestCommand extends Command
{
    public function __construct(
        private readonly PackageManifestCompiler $compiler,
        private readonly string $cachePath,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manifest = $this->compiler->compileFromArray(['packages' => []]);
        $this->compiler->writeCache($manifest, $this->cachePath);

        $providerCount = count($manifest->providers);
        $output->writeln(sprintf(
            '<info>Compiling package manifest... done (%d providers)</info>',
            $providerCount,
        ));

        return Command::SUCCESS;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter OptimizeManifestCommandTest`
Expected: PASS (1 test)

**Step 5: Commit**

```bash
git add packages/cli/src/Command/Optimize/OptimizeManifestCommand.php packages/cli/tests/Unit/Command/Optimize/OptimizeManifestCommandTest.php
git commit -m "feat: add optimize:manifest CLI command"
```

---

### Task 15: OptimizeConfigCommand

**Files:**
- Create: `packages/cli/src/Command/Optimize/OptimizeConfigCommand.php`
- Test: `packages/cli/tests/Unit/Command/Optimize/OptimizeConfigCommandTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Optimize;

use Waaseyaa\CLI\Command\Optimize\OptimizeConfigCommand;
use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Waaseyaa\Config\Storage\MemoryStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(OptimizeConfigCommand::class)]
final class OptimizeConfigCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_opt_config_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $file = $this->tmpDir . '/config.php';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function compiles_config_and_outputs_count(): void
    {
        $storage = new MemoryStorage();
        $storage->write('system.site', ['name' => 'Test']);
        $storage->write('node.settings', ['preview' => true]);

        $compiler = new ConfigCacheCompiler($storage);
        $command = new OptimizeConfigCommand($compiler, $this->tmpDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('2', $tester->getDisplay());
        $this->assertFileExists($this->tmpDir . '/config.php');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter OptimizeConfigCommandTest`
Expected: FAIL — class not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Optimize;

use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'optimize:config',
    description: 'Compile the config cache',
)]
final class OptimizeConfigCommand extends Command
{
    public function __construct(
        private readonly ConfigCacheCompiler $compiler,
        private readonly string $cachePath,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->compiler->compile($this->cachePath);

        $output->writeln(sprintf(
            '<info>Compiling config cache... done (%d config objects)</info>',
            $count,
        ));

        return Command::SUCCESS;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter OptimizeConfigCommandTest`
Expected: PASS (1 test)

**Step 5: Commit**

```bash
git add packages/cli/src/Command/Optimize/OptimizeConfigCommand.php packages/cli/tests/Unit/Command/Optimize/OptimizeConfigCommandTest.php
git commit -m "feat: add optimize:config CLI command"
```

---

### Task 16: OptimizeClearCommand

**Files:**
- Create: `packages/cli/src/Command/Optimize/OptimizeClearCommand.php`
- Test: `packages/cli/tests/Unit/Command/Optimize/OptimizeClearCommandTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Optimize;

use Waaseyaa\CLI\Command\Optimize\OptimizeClearCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(OptimizeClearCommand::class)]
final class OptimizeClearCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_opt_clear_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['packages.php', 'middleware.php', 'config.php'] as $file) {
            $path = $this->tmpDir . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function clears_all_cache_files(): void
    {
        // Create cache files
        file_put_contents($this->tmpDir . '/packages.php', '<?php return [];');
        file_put_contents($this->tmpDir . '/middleware.php', '<?php return [];');
        file_put_contents($this->tmpDir . '/config.php', '<?php return [];');

        $command = new OptimizeClearCommand($this->tmpDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->tmpDir . '/packages.php');
        $this->assertFileDoesNotExist($this->tmpDir . '/middleware.php');
        $this->assertFileDoesNotExist($this->tmpDir . '/config.php');
    }

    #[Test]
    public function succeeds_when_no_cache_files_exist(): void
    {
        $command = new OptimizeClearCommand($this->tmpDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter OptimizeClearCommandTest`
Expected: FAIL — class not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Optimize;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'optimize:clear',
    description: 'Clear all compiled framework caches',
)]
final class OptimizeClearCommand extends Command
{
    private const array CACHE_FILES = ['packages.php', 'middleware.php', 'config.php'];

    public function __construct(
        private readonly string $cachePath,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cleared = 0;

        foreach (self::CACHE_FILES as $file) {
            $path = $this->cachePath . '/' . $file;
            if (is_file($path)) {
                unlink($path);
                $cleared++;
            }
        }

        $output->writeln(sprintf(
            '<info>Cleared %d compiled cache file(s).</info>',
            $cleared,
        ));

        return Command::SUCCESS;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter OptimizeClearCommandTest`
Expected: PASS (2 tests)

**Step 5: Commit**

```bash
git add packages/cli/src/Command/Optimize/OptimizeClearCommand.php packages/cli/tests/Unit/Command/Optimize/OptimizeClearCommandTest.php
git commit -m "feat: add optimize:clear CLI command"
```

---

### Task 17: OptimizeCommand (orchestrator)

**Files:**
- Create: `packages/cli/src/Command/Optimize/OptimizeCommand.php`
- Test: `packages/cli/tests/Unit/Command/Optimize/OptimizeCommandTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Optimize;

use Waaseyaa\CLI\Command\Optimize\OptimizeCommand;
use Waaseyaa\CLI\Command\Optimize\OptimizeConfigCommand;
use Waaseyaa\CLI\Command\Optimize\OptimizeManifestCommand;
use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(OptimizeCommand::class)]
final class OptimizeCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_opt_all_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['packages.php', 'middleware.php', 'config.php'] as $file) {
            $path = $this->tmpDir . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function runs_all_sub_commands_in_order(): void
    {
        $storage = new MemoryStorage();
        $storage->write('test.config', ['key' => 'val']);

        $app = new Application();
        $app->add(new OptimizeManifestCommand(new PackageManifestCompiler(), $this->tmpDir));
        $app->add(new OptimizeConfigCommand(new ConfigCacheCompiler($storage), $this->tmpDir));
        $app->add(new OptimizeCommand());

        $tester = new CommandTester($app->find('optimize'));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('optimized successfully', strtolower($display));
        $this->assertFileExists($this->tmpDir . '/packages.php');
        $this->assertFileExists($this->tmpDir . '/config.php');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter OptimizeCommandTest`
Expected: FAIL — class not found.

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Optimize;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'optimize',
    description: 'Compile all cached framework artifacts',
)]
final class OptimizeCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->getApplication();
        if ($app === null) {
            $output->writeln('<error>Application not available.</error>');
            return Command::FAILURE;
        }

        $subCommands = ['optimize:manifest', 'optimize:config'];

        foreach ($subCommands as $commandName) {
            if (!$app->has($commandName)) {
                continue;
            }

            $result = $app->find($commandName)->run(new ArrayInput([]), $output);
            if ($result !== Command::SUCCESS) {
                $output->writeln(sprintf('<error>Command %s failed.</error>', $commandName));
                return Command::FAILURE;
            }
        }

        $output->writeln('');
        $output->writeln('<info>Application optimized successfully.</info>');

        return Command::SUCCESS;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter OptimizeCommandTest`
Expected: PASS (1 test)

**Step 5: Commit**

```bash
git add packages/cli/src/Command/Optimize/OptimizeCommand.php packages/cli/tests/Unit/Command/Optimize/OptimizeCommandTest.php
git commit -m "feat: add optimize orchestrator command"
```

---

### Task 18: Run full test suite

**Step 1: Run all unit tests**

Run: `./vendor/bin/phpunit --testsuite Unit`
Expected: All tests pass. No regressions in existing tests.

**Step 2: Run all tests**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass.

**Step 3: Commit gitignore update from Task 1 if not done**

Verify `.gitignore` has `storage/` entry.

---

### Task 19: Final verification and summary commit

**Step 1: Verify all new files exist**

Run: `find packages/foundation/src/Attribute packages/foundation/src/Discovery packages/foundation/src/Middleware packages/config/src/Cache packages/config/src/Listener packages/cli/src/Command/Optimize -name '*.php' | sort`

Expected output (~27 files):
```
packages/cli/src/Command/Optimize/OptimizeClearCommand.php
packages/cli/src/Command/Optimize/OptimizeCommand.php
packages/cli/src/Command/Optimize/OptimizeConfigCommand.php
packages/cli/src/Command/Optimize/OptimizeManifestCommand.php
packages/config/src/Cache/CachedConfigFactory.php
packages/config/src/Cache/ConfigCacheCompiler.php
packages/config/src/Listener/ConfigCacheInvalidator.php
packages/foundation/src/Attribute/AsEntityType.php
packages/foundation/src/Attribute/AsFieldType.php
packages/foundation/src/Attribute/AsMiddleware.php
packages/foundation/src/Discovery/PackageManifest.php
packages/foundation/src/Discovery/PackageManifestCompiler.php
packages/foundation/src/Middleware/EventHandlerInterface.php
packages/foundation/src/Middleware/EventMiddlewareInterface.php
packages/foundation/src/Middleware/EventPipeline.php
packages/foundation/src/Middleware/HttpHandlerInterface.php
packages/foundation/src/Middleware/HttpMiddlewareInterface.php
packages/foundation/src/Middleware/HttpPipeline.php
packages/foundation/src/Middleware/JobMiddlewareInterface.php
packages/foundation/src/Middleware/JobNextHandlerInterface.php
packages/foundation/src/Middleware/JobPipeline.php
```

**Step 2: Run full test suite one final time**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass.

**Step 3: Verify no regressions in existing EventBus and JobHandler tests**

Run: `./vendor/bin/phpunit --filter "EventBusTest|JobHandlerTest"`
Expected: All pass (existing + new tests).
