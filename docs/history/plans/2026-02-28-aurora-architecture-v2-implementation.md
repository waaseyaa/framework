# Aurora Architecture v2 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement the 17 architectural pillars defined in `docs/history/plans/2026-02-28-aurora-architecture-v2-design.md`, transforming Aurora from a working CMS into a best-in-class framework.

**Architecture:** Five implementation phases following strict dependency order. Phase 1 (ServiceProviders, Domain Events, Migrations) is foundational — every subsequent phase depends on it. Each phase produces working, tested, committed code before the next begins.

**Tech Stack:** PHP 8.3+, Symfony 7.x, Doctrine DBAL, PHPUnit 10.5, Vue 3 + Nuxt (Phase 4+), Vite

**Reference:** All designs in `docs/history/plans/2026-02-28-aurora-architecture-v2-design.md`

---

## Phase 1: Foundation Infrastructure (Pillars 1-3)

### Task 1: Create `aurora/foundation` package skeleton

**Files:**
- Create: `packages/foundation/composer.json`
- Create: `packages/foundation/src/.gitkeep`
- Create: `packages/foundation/tests/Unit/.gitkeep`
- Modify: `composer.json` (root — add path repository + require)
- Modify: `packages/core/composer.json` (add aurora/foundation to meta-package)

**Step 1: Create composer.json for foundation package**

```json
{
    "name": "aurora/foundation",
    "description": "Core framework primitives: service providers, domain events, results, error handling",
    "type": "library",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3",
        "symfony/event-dispatcher": "^7.0",
        "symfony/uid": "^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Aurora\\Foundation\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Aurora\\Foundation\\Tests\\": "tests/"
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

**Step 2: Register in root composer.json**

Add to root `composer.json`:
- In `repositories` array: `{ "type": "path", "url": "packages/foundation" }`
- In `require`: `"aurora/foundation": "@dev"`
- In `autoload-dev.psr-4`: `"Aurora\\Foundation\\Tests\\": "packages/foundation/tests/"`

**Step 3: Add to aurora/core meta-package**

In `packages/core/composer.json`, add `"aurora/foundation": "^0.1"` to `require`.

**Step 4: Run composer update**

Run: `composer update aurora/foundation --no-interaction`
Expected: Package symlinked, autoloading updated.

**Step 5: Commit**

```
git add packages/foundation/ composer.json composer.lock packages/core/composer.json
git commit -m "chore(foundation): scaffold aurora/foundation package skeleton"
```

---

### Task 2: Implement `Result` type

**Files:**
- Create: `packages/foundation/src/Result/Result.php`
- Create: `packages/foundation/tests/Unit/Result/ResultTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Result;

use Aurora\Foundation\Result\Result;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Result::class)]
final class ResultTest extends TestCase
{
    #[Test]
    public function ok_result_is_ok(): void
    {
        $result = Result::ok('value');

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isFail());
        $this->assertSame('value', $result->unwrap());
    }

    #[Test]
    public function ok_result_with_null_value(): void
    {
        $result = Result::ok();

        $this->assertTrue($result->isOk());
        $this->assertNull($result->unwrap());
    }

    #[Test]
    public function fail_result_is_fail(): void
    {
        $result = Result::fail('error message');

        $this->assertTrue($result->isFail());
        $this->assertFalse($result->isOk());
        $this->assertSame('error message', $result->error());
    }

    #[Test]
    public function unwrap_on_failure_throws(): void
    {
        $result = Result::fail('something broke');

        $this->expectException(\LogicException::class);
        $result->unwrap();
    }

    #[Test]
    public function error_on_success_throws(): void
    {
        $result = Result::ok('fine');

        $this->expectException(\LogicException::class);
        $result->error();
    }

    #[Test]
    public function unwrap_or_returns_value_on_success(): void
    {
        $result = Result::ok('real');

        $this->assertSame('real', $result->unwrapOr('default'));
    }

    #[Test]
    public function unwrap_or_returns_default_on_failure(): void
    {
        $result = Result::fail('error');

        $this->assertSame('default', $result->unwrapOr('default'));
    }

    #[Test]
    public function map_transforms_success_value(): void
    {
        $result = Result::ok(5);
        $mapped = $result->map(fn (int $v) => $v * 2);

        $this->assertTrue($mapped->isOk());
        $this->assertSame(10, $mapped->unwrap());
    }

    #[Test]
    public function map_passes_through_failure(): void
    {
        $result = Result::fail('error');
        $mapped = $result->map(fn ($v) => $v * 2);

        $this->assertTrue($mapped->isFail());
        $this->assertSame('error', $mapped->error());
    }

    #[Test]
    public function map_error_transforms_failure(): void
    {
        $result = Result::fail('low');
        $mapped = $result->mapError(fn (string $e) => strtoupper($e));

        $this->assertTrue($mapped->isFail());
        $this->assertSame('LOW', $mapped->error());
    }

    #[Test]
    public function map_error_passes_through_success(): void
    {
        $result = Result::ok('fine');
        $mapped = $result->mapError(fn (string $e) => strtoupper($e));

        $this->assertTrue($mapped->isOk());
        $this->assertSame('fine', $mapped->unwrap());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Result/ResultTest.php`
Expected: FAIL — class `Aurora\Foundation\Result\Result` not found.

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Result;

/**
 * @template T
 * @template E
 */
final readonly class Result
{
    private function __construct(
        private bool $ok,
        private mixed $value,
    ) {}

    /** @return self<T, never> */
    public static function ok(mixed $value = null): self
    {
        return new self(ok: true, value: $value);
    }

    /** @return self<never, E> */
    public static function fail(mixed $error): self
    {
        return new self(ok: false, value: $error);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isFail(): bool
    {
        return !$this->ok;
    }

    /** @return T */
    public function unwrap(): mixed
    {
        if (!$this->ok) {
            throw new \LogicException('Called unwrap() on a failed Result.');
        }

        return $this->value;
    }

    /** @return T */
    public function unwrapOr(mixed $default): mixed
    {
        return $this->ok ? $this->value : $default;
    }

    /** @return E */
    public function error(): mixed
    {
        if ($this->ok) {
            throw new \LogicException('Called error() on a successful Result.');
        }

        return $this->value;
    }

    /** @return self<U, E> */
    public function map(\Closure $fn): self
    {
        if (!$this->ok) {
            return $this;
        }

        return self::ok($fn($this->value));
    }

    /** @return self<T, F> */
    public function mapError(\Closure $fn): self
    {
        if ($this->ok) {
            return $this;
        }

        return self::fail($fn($this->value));
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Result/ResultTest.php`
Expected: OK (12 tests, 12 assertions)

**Step 5: Commit**

```
git add packages/foundation/src/Result/ packages/foundation/tests/Unit/Result/
git commit -m "feat(foundation): add Result<T,E> type for domain operations"
```

---

### Task 3: Implement `DomainError` structured error

**Files:**
- Create: `packages/foundation/src/Result/DomainError.php`
- Create: `packages/foundation/tests/Unit/Result/DomainErrorTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Result;

use Aurora\Foundation\Result\DomainError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DomainError::class)]
final class DomainErrorTest extends TestCase
{
    #[Test]
    public function entity_not_found_factory(): void
    {
        $error = DomainError::entityNotFound('node', '42');

        $this->assertSame('aurora:entity/not-found', $error->type);
        $this->assertSame('Entity Not Found', $error->title);
        $this->assertStringContainsString('node', $error->detail);
        $this->assertStringContainsString('42', $error->detail);
        $this->assertSame(404, $error->statusCode);
    }

    #[Test]
    public function access_denied_factory(): void
    {
        $error = DomainError::accessDenied('update', 'node', '42');

        $this->assertSame('aurora:access/denied', $error->type);
        $this->assertSame(403, $error->statusCode);
    }

    #[Test]
    public function validation_failed_factory(): void
    {
        $violations = ['title' => 'Title is required', 'body' => 'Body is too short'];
        $error = DomainError::validationFailed($violations);

        $this->assertSame('aurora:validation/failed', $error->type);
        $this->assertSame(422, $error->statusCode);
        $this->assertSame($violations, $error->meta['violations']);
    }

    #[Test]
    public function translation_missing_factory(): void
    {
        $error = DomainError::translationMissing('node', '42', 'fr');

        $this->assertSame('aurora:i18n/translation-missing', $error->type);
        $this->assertSame(404, $error->statusCode);
    }

    #[Test]
    public function to_array_returns_rfc9457_structure(): void
    {
        $error = DomainError::entityNotFound('node', '42');
        $array = $error->toArray();

        $this->assertArrayHasKey('type', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('detail', $array);
        $this->assertArrayHasKey('status', $array);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Result/DomainErrorTest.php`
Expected: FAIL — class not found.

**Step 3: Write implementation**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Result;

final readonly class DomainError
{
    public function __construct(
        public string $type,
        public string $title,
        public string $detail,
        public int $statusCode = 400,
        public array $meta = [],
    ) {}

    public static function entityNotFound(string $entityType, string $id): self
    {
        return new self(
            type: 'aurora:entity/not-found',
            title: 'Entity Not Found',
            detail: sprintf('%s "%s" does not exist.', ucfirst($entityType), $id),
            statusCode: 404,
        );
    }

    public static function accessDenied(string $operation, string $entityType, string $id): self
    {
        return new self(
            type: 'aurora:access/denied',
            title: 'Access Denied',
            detail: sprintf('You do not have permission to %s %s "%s".', $operation, $entityType, $id),
            statusCode: 403,
        );
    }

    public static function validationFailed(array $violations): self
    {
        return new self(
            type: 'aurora:validation/failed',
            title: 'Validation Failed',
            detail: sprintf('%d validation error(s) occurred.', count($violations)),
            statusCode: 422,
            meta: ['violations' => $violations],
        );
    }

    public static function translationMissing(string $entityType, string $id, string $langcode): self
    {
        return new self(
            type: 'aurora:i18n/translation-missing',
            title: 'Translation Missing',
            detail: sprintf('No %s translation exists for %s "%s".', $langcode, $entityType, $id),
            statusCode: 404,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'title' => $this->title,
            'detail' => $this->detail,
            'status' => $this->statusCode,
            'meta' => $this->meta ?: null,
        ]);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Result/DomainErrorTest.php`
Expected: OK (5 tests)

**Step 5: Commit**

```
git add packages/foundation/src/Result/DomainError.php packages/foundation/tests/Unit/Result/DomainErrorTest.php
git commit -m "feat(foundation): add DomainError with RFC 9457 structure"
```

---

### Task 4: Implement `AuroraException` hierarchy

**Files:**
- Create: `packages/foundation/src/Exception/AuroraException.php`
- Create: `packages/foundation/src/Exception/StorageException.php`
- Create: `packages/foundation/src/Exception/ConfigException.php`
- Create: `packages/foundation/src/Exception/AuthenticationException.php`
- Create: `packages/foundation/tests/Unit/Exception/AuroraExceptionTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Exception;

use Aurora\Foundation\Exception\AuroraException;
use Aurora\Foundation\Exception\AuthenticationException;
use Aurora\Foundation\Exception\ConfigException;
use Aurora\Foundation\Exception\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuroraException::class)]
#[CoversClass(StorageException::class)]
#[CoversClass(ConfigException::class)]
#[CoversClass(AuthenticationException::class)]
final class AuroraExceptionTest extends TestCase
{
    #[Test]
    public function storage_exception_has_correct_defaults(): void
    {
        $e = new StorageException('Database is down');

        $this->assertSame('Database is down', $e->getMessage());
        $this->assertSame(503, $e->statusCode);
        $this->assertSame('aurora:storage/error', $e->problemType);
        $this->assertInstanceOf(AuroraException::class, $e);
    }

    #[Test]
    public function config_exception_has_correct_defaults(): void
    {
        $e = new ConfigException('Invalid YAML');

        $this->assertSame(500, $e->statusCode);
        $this->assertSame('aurora:config/error', $e->problemType);
    }

    #[Test]
    public function authentication_exception_has_correct_defaults(): void
    {
        $e = new AuthenticationException('Invalid token');

        $this->assertSame(401, $e->statusCode);
        $this->assertSame('aurora:auth/error', $e->problemType);
    }

    #[Test]
    public function exception_carries_context(): void
    {
        $e = new StorageException(
            'Query failed',
            context: ['query' => 'SELECT * FROM nodes', 'table' => 'nodes'],
        );

        $this->assertSame('SELECT * FROM nodes', $e->context['query']);
    }

    #[Test]
    public function exception_wraps_previous(): void
    {
        $pdo = new \PDOException('Connection refused');
        $e = new StorageException('Database is down', previous: $pdo);

        $this->assertSame($pdo, $e->getPrevious());
    }

    #[Test]
    public function to_api_error_returns_rfc9457_array(): void
    {
        $e = new StorageException('Database is down');
        $error = $e->toApiError();

        $this->assertSame('aurora:storage/error', $error['type']);
        $this->assertSame('Database is down', $error['detail']);
        $this->assertSame(503, $error['status']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Exception/AuroraExceptionTest.php`
Expected: FAIL — class not found.

**Step 3: Write implementation**

`AuroraException.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Exception;

abstract class AuroraException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $problemType = 'aurora:internal-error',
        public readonly int $statusCode = 500,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function toApiError(): array
    {
        return [
            'type' => $this->problemType,
            'title' => (new \ReflectionClass($this))->getShortName(),
            'detail' => $this->getMessage(),
            'status' => $this->statusCode,
        ];
    }
}
```

`StorageException.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Exception;

final class StorageException extends AuroraException
{
    public function __construct(
        string $message,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'aurora:storage/error', 503, $context, $previous);
    }
}
```

`ConfigException.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Exception;

final class ConfigException extends AuroraException
{
    public function __construct(
        string $message,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'aurora:config/error', 500, $context, $previous);
    }
}
```

`AuthenticationException.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Exception;

final class AuthenticationException extends AuroraException
{
    public function __construct(
        string $message,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 'aurora:auth/error', 401, $context, $previous);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Exception/AuroraExceptionTest.php`
Expected: OK (6 tests)

**Step 5: Commit**

```
git add packages/foundation/src/Exception/ packages/foundation/tests/Unit/Exception/
git commit -m "feat(foundation): add AuroraException hierarchy with RFC 9457 support"
```

---

### Task 5: Implement `ServiceProvider` abstract class

**Files:**
- Create: `packages/foundation/src/ServiceProvider/ServiceProvider.php`
- Create: `packages/foundation/src/ServiceProvider/ServiceProviderInterface.php`
- Create: `packages/foundation/tests/Unit/ServiceProvider/ServiceProviderTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\ServiceProvider;

use Aurora\Foundation\ServiceProvider\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceProvider::class)]
final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function register_records_singletons(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('FooInterface', 'FooImplementation');
            }
        };

        $provider->register();
        $bindings = $provider->getBindings();

        $this->assertArrayHasKey('FooInterface', $bindings);
        $this->assertSame('FooImplementation', $bindings['FooInterface']['concrete']);
        $this->assertTrue($bindings['FooInterface']['shared']);
    }

    #[Test]
    public function register_records_transient_bindings(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind('BarInterface', 'BarImplementation');
            }
        };

        $provider->register();
        $bindings = $provider->getBindings();

        $this->assertFalse($bindings['BarInterface']['shared']);
    }

    #[Test]
    public function tags_are_recorded(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('FooInterface', 'FooImpl');
                $this->tag('FooInterface', 'aurora.managers');
            }
        };

        $provider->register();
        $tags = $provider->getTags();

        $this->assertArrayHasKey('aurora.managers', $tags);
        $this->assertContains('FooInterface', $tags['aurora.managers']);
    }

    #[Test]
    public function boot_is_optional(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}
        };

        // boot() should not throw when not overridden
        $provider->boot();
        $this->assertTrue(true);
    }

    #[Test]
    public function provides_returns_empty_by_default(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}
        };

        $this->assertSame([], $provider->provides());
    }

    #[Test]
    public function deferred_provider_declares_provided_interfaces(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('FooInterface', 'FooImpl');
            }

            public function provides(): array
            {
                return ['FooInterface'];
            }
        };

        $this->assertSame(['FooInterface'], $provider->provides());
        $this->assertTrue($provider->isDeferred());
    }

    #[Test]
    public function non_deferred_provider(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}
        };

        $this->assertFalse($provider->isDeferred());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/ServiceProvider/ServiceProviderTest.php`
Expected: FAIL — class not found.

**Step 3: Write implementation**

`ServiceProviderInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\ServiceProvider;

interface ServiceProviderInterface
{
    public function register(): void;
    public function boot(): void;
    public function provides(): array;
    public function isDeferred(): bool;
}
```

`ServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\ServiceProvider;

abstract class ServiceProvider implements ServiceProviderInterface
{
    /** @var array<string, array{concrete: string|callable, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, list<string>> */
    private array $tags = [];

    abstract public function register(): void;

    public function boot(): void {}

    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return $this->provides() !== [];
    }

    protected function singleton(string $abstract, string|callable $concrete): void
    {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => true];
    }

    protected function bind(string $abstract, string|callable $concrete): void
    {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => false];
    }

    protected function tag(string $abstract, string $tag): void
    {
        $this->tags[$tag] ??= [];
        $this->tags[$tag][] = $abstract;
    }

    /** @return array<string, array{concrete: string|callable, shared: bool}> */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /** @return array<string, list<string>> */
    public function getTags(): array
    {
        return $this->tags;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/ServiceProvider/ServiceProviderTest.php`
Expected: OK (7 tests)

**Step 5: Commit**

```
git add packages/foundation/src/ServiceProvider/ packages/foundation/tests/Unit/ServiceProvider/
git commit -m "feat(foundation): add ServiceProvider abstract class with binding API"
```

---

### Task 6: Implement `ProviderDiscovery` — auto-discover providers from composer.json

**Files:**
- Create: `packages/foundation/src/ServiceProvider/ProviderDiscovery.php`
- Create: `packages/foundation/tests/Unit/ServiceProvider/ProviderDiscoveryTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\ServiceProvider;

use Aurora\Foundation\ServiceProvider\ProviderDiscovery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderDiscovery::class)]
final class ProviderDiscoveryTest extends TestCase
{
    #[Test]
    public function discovers_providers_from_installed_json(): void
    {
        $installed = [
            'packages' => [
                [
                    'name' => 'aurora/entity',
                    'extra' => [
                        'aurora' => [
                            'providers' => ['Aurora\\Entity\\EntityServiceProvider'],
                        ],
                    ],
                ],
                [
                    'name' => 'aurora/cache',
                    'extra' => [
                        'aurora' => [
                            'providers' => ['Aurora\\Cache\\CacheServiceProvider'],
                        ],
                    ],
                ],
                [
                    'name' => 'unrelated/package',
                    'extra' => [],
                ],
            ],
        ];

        $discovery = new ProviderDiscovery();
        $providers = $discovery->discoverFromArray($installed);

        $this->assertCount(2, $providers);
        $this->assertContains('Aurora\\Entity\\EntityServiceProvider', $providers);
        $this->assertContains('Aurora\\Cache\\CacheServiceProvider', $providers);
    }

    #[Test]
    public function skips_packages_without_aurora_extra(): void
    {
        $installed = [
            'packages' => [
                ['name' => 'symfony/console', 'extra' => []],
                ['name' => 'phpunit/phpunit'],
            ],
        ];

        $discovery = new ProviderDiscovery();
        $providers = $discovery->discoverFromArray($installed);

        $this->assertSame([], $providers);
    }

    #[Test]
    public function handles_multiple_providers_per_package(): void
    {
        $installed = [
            'packages' => [
                [
                    'name' => 'aurora/ai-schema',
                    'extra' => [
                        'aurora' => [
                            'providers' => [
                                'Aurora\\AiSchema\\SchemaServiceProvider',
                                'Aurora\\AiSchema\\McpToolServiceProvider',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $discovery = new ProviderDiscovery();
        $providers = $discovery->discoverFromArray($installed);

        $this->assertCount(2, $providers);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/ServiceProvider/ProviderDiscoveryTest.php`
Expected: FAIL — class not found.

**Step 3: Write implementation**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\ServiceProvider;

final class ProviderDiscovery
{
    /**
     * Discover provider class names from Composer's installed.json data.
     *
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function discoverFromArray(array $installed): array
    {
        $providers = [];

        foreach ($installed['packages'] ?? [] as $package) {
            $auroraExtra = $package['extra']['aurora'] ?? null;
            if ($auroraExtra === null) {
                continue;
            }

            foreach ($auroraExtra['providers'] ?? [] as $providerClass) {
                $providers[] = $providerClass;
            }
        }

        return $providers;
    }

    /**
     * Discover providers from the vendor directory's installed.json.
     *
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function discoverFromVendor(string $vendorPath): array
    {
        $installedPath = $vendorPath . '/composer/installed.json';
        if (!is_file($installedPath)) {
            return [];
        }

        $installed = json_decode(file_get_contents($installedPath), true);

        return $this->discoverFromArray($installed);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/ServiceProvider/ProviderDiscoveryTest.php`
Expected: OK (3 tests)

**Step 5: Commit**

```
git add packages/foundation/src/ServiceProvider/ProviderDiscovery.php packages/foundation/tests/Unit/ServiceProvider/ProviderDiscoveryTest.php
git commit -m "feat(foundation): add ProviderDiscovery for composer.json auto-discovery"
```

---

### Task 7: Implement `ContainerCompiler` — compile providers to Symfony container

**Files:**
- Create: `packages/foundation/src/ServiceProvider/ContainerCompiler.php`
- Create: `packages/foundation/tests/Unit/ServiceProvider/ContainerCompilerTest.php`
- Modify: `packages/foundation/composer.json` — add `symfony/dependency-injection` require

**Step 1: Add symfony/dependency-injection to foundation**

Add to `packages/foundation/composer.json` require: `"symfony/dependency-injection": "^7.0"`.
Run: `composer update aurora/foundation`

**Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\ServiceProvider;

use Aurora\Foundation\ServiceProvider\ContainerCompiler;
use Aurora\Foundation\ServiceProvider\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(ContainerCompiler::class)]
final class ContainerCompilerTest extends TestCase
{
    #[Test]
    public function compiles_singleton_bindings(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton(\DateTimeInterface::class, \DateTimeImmutable::class);
            }
        };

        $compiler = new ContainerCompiler();
        $container = new ContainerBuilder();
        $compiler->compile([$provider], $container);
        $container->compile();

        $this->assertTrue($container->has(\DateTimeInterface::class));
    }

    #[Test]
    public function compiles_transient_bindings(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind(\DateTimeInterface::class, \DateTimeImmutable::class);
            }
        };

        $compiler = new ContainerCompiler();
        $container = new ContainerBuilder();
        $compiler->compile([$provider], $container);
        $container->compile();

        $this->assertTrue($container->has(\DateTimeInterface::class));
        $def = $container->getDefinition(\DateTimeInterface::class);
        $this->assertFalse($def->isShared());
    }

    #[Test]
    public function compiles_tagged_services(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton(\DateTimeInterface::class, \DateTimeImmutable::class);
                $this->tag(\DateTimeInterface::class, 'aurora.time');
            }
        };

        $compiler = new ContainerCompiler();
        $container = new ContainerBuilder();
        $compiler->compile([$provider], $container);

        $tagged = $container->findTaggedServiceIds('aurora.time');
        $this->assertArrayHasKey(\DateTimeInterface::class, $tagged);
    }

    #[Test]
    public function calls_register_then_boot_in_order(): void
    {
        $order = [];
        $provider = new class($order) extends ServiceProvider {
            public function __construct(private array &$order) {}

            public function register(): void
            {
                $this->order[] = 'register';
                $this->singleton('Foo', \stdClass::class);
            }

            public function boot(): void
            {
                $this->order[] = 'boot';
            }
        };

        $compiler = new ContainerCompiler();
        $container = new ContainerBuilder();
        $compiler->compile([$provider], $container);

        $this->assertSame(['register', 'boot'], $order);
    }
}
```

**Step 3: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/ServiceProvider/ContainerCompilerTest.php`
Expected: FAIL — ContainerCompiler not found.

**Step 4: Write implementation**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\ServiceProvider;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ContainerCompiler
{
    /**
     * @param ServiceProviderInterface[] $providers
     */
    public function compile(array $providers, ContainerBuilder $container): void
    {
        // Phase 1: register all bindings
        foreach ($providers as $provider) {
            $provider->register();

            foreach ($provider->getBindings() as $abstract => $binding) {
                $concrete = $binding['concrete'];
                $definition = new Definition(is_string($concrete) ? $concrete : \stdClass::class);
                $definition->setShared($binding['shared']);
                $definition->setPublic(true);

                if (is_callable($concrete) && !is_string($concrete)) {
                    $definition->setFactory($concrete);
                }

                $container->setDefinition($abstract, $definition);
            }

            foreach ($provider->getTags() as $tag => $services) {
                foreach ($services as $serviceId) {
                    if ($container->hasDefinition($serviceId)) {
                        $container->getDefinition($serviceId)->addTag($tag);
                    }
                }
            }
        }

        // Phase 2: boot all providers (all bindings available)
        foreach ($providers as $provider) {
            $provider->boot();
        }
    }
}
```

**Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/ServiceProvider/ContainerCompilerTest.php`
Expected: OK (4 tests)

**Step 6: Commit**

```
git add packages/foundation/src/ServiceProvider/ContainerCompiler.php packages/foundation/tests/Unit/ServiceProvider/ContainerCompilerTest.php packages/foundation/composer.json
git commit -m "feat(foundation): add ContainerCompiler to bridge providers to Symfony DI"
```

---

### Task 8: Implement `DomainEvent` base class

**Files:**
- Create: `packages/foundation/src/Event/DomainEvent.php`
- Create: `packages/foundation/tests/Unit/Event/DomainEventTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Event;

use Aurora\Foundation\Event\DomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DomainEvent::class)]
final class DomainEventTest extends TestCase
{
    #[Test]
    public function carries_aggregate_identity(): void
    {
        $event = new class('node', '42') extends DomainEvent {
            public function getPayload(): array { return ['test' => true]; }
        };

        $this->assertSame('node', $event->aggregateType);
        $this->assertSame('42', $event->aggregateId);
    }

    #[Test]
    public function generates_uuid_event_id(): void
    {
        $event = new class('node', '1') extends DomainEvent {
            public function getPayload(): array { return []; }
        };

        $this->assertNotEmpty($event->eventId);
        // UUIDv7 format check: 36 chars with hyphens
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $event->eventId,
        );
    }

    #[Test]
    public function records_occurred_at_timestamp(): void
    {
        $before = new \DateTimeImmutable();
        $event = new class('node', '1') extends DomainEvent {
            public function getPayload(): array { return []; }
        };
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $event->occurredAt);
        $this->assertLessThanOrEqual($after, $event->occurredAt);
    }

    #[Test]
    public function carries_optional_tenant_and_actor(): void
    {
        $event = new class('node', '1', 'acme', 'user-7') extends DomainEvent {
            public function getPayload(): array { return []; }
        };

        $this->assertSame('acme', $event->tenantId);
        $this->assertSame('user-7', $event->actorId);
    }

    #[Test]
    public function tenant_and_actor_default_to_null(): void
    {
        $event = new class('node', '1') extends DomainEvent {
            public function getPayload(): array { return []; }
        };

        $this->assertNull($event->tenantId);
        $this->assertNull($event->actorId);
    }

    #[Test]
    public function two_events_have_different_ids(): void
    {
        $event1 = new class('node', '1') extends DomainEvent {
            public function getPayload(): array { return []; }
        };
        $event2 = new class('node', '1') extends DomainEvent {
            public function getPayload(): array { return []; }
        };

        $this->assertNotSame($event1->eventId, $event2->eventId);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Event/DomainEventTest.php`
Expected: FAIL — class not found.

**Step 3: Write implementation**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event;

use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\Event;

abstract class DomainEvent extends Event
{
    public readonly string $eventId;
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly ?string $tenantId = null,
        public readonly ?string $actorId = null,
    ) {
        $this->eventId = Uuid::v7()->toString();
        $this->occurredAt = new \DateTimeImmutable();
    }

    /**
     * Domain-specific payload for serialization and logging.
     */
    abstract public function getPayload(): array;
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Event/DomainEventTest.php`
Expected: OK (6 tests)

**Step 5: Commit**

```
git add packages/foundation/src/Event/DomainEvent.php packages/foundation/tests/Unit/Event/DomainEventTest.php
git commit -m "feat(foundation): add DomainEvent base class with UUIDv7 identity"
```

---

### Task 9: Implement `EventBus` with sync dispatch

**Files:**
- Create: `packages/foundation/src/Event/EventBus.php`
- Create: `packages/foundation/src/Event/EventStoreInterface.php`
- Create: `packages/foundation/src/Event/BroadcasterInterface.php`
- Create: `packages/foundation/tests/Unit/Event/EventBusTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Event;

use Aurora\Foundation\Event\BroadcasterInterface;
use Aurora\Foundation\Event\DomainEvent;
use Aurora\Foundation\Event\EventBus;
use Aurora\Foundation\Event\EventStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(EventBus::class)]
final class EventBusTest extends TestCase
{
    #[Test]
    public function dispatches_to_sync_listeners(): void
    {
        $dispatcher = new EventDispatcher();
        $received = null;
        $dispatcher->addListener(TestNodeSaved::class, function (TestNodeSaved $e) use (&$received) {
            $received = $e;
        });

        $bus = new EventBus(
            syncDispatcher: $dispatcher,
            asyncBus: $this->createNullMessageBus(),
            broadcaster: $this->createNullBroadcaster(),
        );

        $event = new TestNodeSaved('node', '42', ['title']);
        $bus->dispatch($event);

        $this->assertSame($event, $received);
    }

    #[Test]
    public function dispatches_to_async_bus(): void
    {
        $dispatched = [];
        $asyncBus = $this->createMock(MessageBusInterface::class);
        $asyncBus->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($message) use (&$dispatched) {
                $dispatched[] = $message;
                return new Envelope($message);
            });

        $bus = new EventBus(
            syncDispatcher: new EventDispatcher(),
            asyncBus: $asyncBus,
            broadcaster: $this->createNullBroadcaster(),
        );

        $bus->dispatch(new TestNodeSaved('node', '42', ['title']));

        $this->assertCount(1, $dispatched);
    }

    #[Test]
    public function dispatches_to_broadcaster(): void
    {
        $broadcast = [];
        $broadcaster = $this->createMock(BroadcasterInterface::class);
        $broadcaster->expects($this->once())
            ->method('broadcast')
            ->willReturnCallback(function (DomainEvent $e) use (&$broadcast) {
                $broadcast[] = $e;
            });

        $bus = new EventBus(
            syncDispatcher: new EventDispatcher(),
            asyncBus: $this->createNullMessageBus(),
            broadcaster: $broadcaster,
        );

        $bus->dispatch(new TestNodeSaved('node', '42', ['title']));

        $this->assertCount(1, $broadcast);
    }

    #[Test]
    public function appends_to_event_store_when_available(): void
    {
        $stored = [];
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects($this->once())
            ->method('append')
            ->willReturnCallback(function (DomainEvent $e) use (&$stored) {
                $stored[] = $e;
            });

        $bus = new EventBus(
            syncDispatcher: new EventDispatcher(),
            asyncBus: $this->createNullMessageBus(),
            broadcaster: $this->createNullBroadcaster(),
            eventStore: $store,
        );

        $bus->dispatch(new TestNodeSaved('node', '42', ['title']));

        $this->assertCount(1, $stored);
    }

    #[Test]
    public function works_without_event_store(): void
    {
        $bus = new EventBus(
            syncDispatcher: new EventDispatcher(),
            asyncBus: $this->createNullMessageBus(),
            broadcaster: $this->createNullBroadcaster(),
            eventStore: null,
        );

        // Should not throw
        $bus->dispatch(new TestNodeSaved('node', '42', ['title']));
        $this->assertTrue(true);
    }

    private function createNullMessageBus(): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(fn ($msg) => new Envelope($msg));
        return $bus;
    }

    private function createNullBroadcaster(): BroadcasterInterface
    {
        return $this->createMock(BroadcasterInterface::class);
    }
}

final class TestNodeSaved extends DomainEvent
{
    public function __construct(
        string $aggregateType,
        string $aggregateId,
        public readonly array $changedFields,
    ) {
        parent::__construct($aggregateType, $aggregateId);
    }

    public function getPayload(): array
    {
        return ['changed_fields' => $this->changedFields];
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Event/EventBusTest.php`
Expected: FAIL — EventBus not found.

**Step 3: Write implementation**

Add `"symfony/messenger": "^7.0"` to `packages/foundation/composer.json` require.
Run: `composer update aurora/foundation`

`EventStoreInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event;

interface EventStoreInterface
{
    public function append(DomainEvent $event): void;
}
```

`BroadcasterInterface.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event;

interface BroadcasterInterface
{
    public function broadcast(DomainEvent $event): void;
}
```

`EventBus.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class EventBus
{
    public function __construct(
        private readonly EventDispatcherInterface $syncDispatcher,
        private readonly MessageBusInterface $asyncBus,
        private readonly BroadcasterInterface $broadcaster,
        private readonly ?EventStoreInterface $eventStore = null,
    ) {}

    public function dispatch(DomainEvent $event): void
    {
        $this->eventStore?->append($event);
        $this->syncDispatcher->dispatch($event);
        $this->asyncBus->dispatch($event);
        $this->broadcaster->broadcast($event);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Event/EventBusTest.php`
Expected: OK (5 tests)

**Step 5: Commit**

```
git add packages/foundation/src/Event/ packages/foundation/tests/Unit/Event/ packages/foundation/composer.json
git commit -m "feat(foundation): add EventBus with sync, async, broadcast, and store channels"
```

---

### Task 10: Implement `#[Listener]` and `#[Async]` attributes

**Files:**
- Create: `packages/foundation/src/Event/Attribute/Listener.php`
- Create: `packages/foundation/src/Event/Attribute/Async.php`
- Create: `packages/foundation/src/Event/Attribute/Broadcast.php`
- Create: `packages/foundation/tests/Unit/Event/Attribute/ListenerAttributeTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Event\Attribute;

use Aurora\Foundation\Event\Attribute\Async;
use Aurora\Foundation\Event\Attribute\Broadcast;
use Aurora\Foundation\Event\Attribute\Listener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Listener::class)]
#[CoversClass(Async::class)]
#[CoversClass(Broadcast::class)]
final class ListenerAttributeTest extends TestCase
{
    #[Test]
    public function listener_attribute_discoverable_on_class(): void
    {
        $ref = new \ReflectionClass(SampleListener::class);
        $attrs = $ref->getAttributes(Listener::class);

        $this->assertCount(1, $attrs);
    }

    #[Test]
    public function async_attribute_discoverable_on_method(): void
    {
        $ref = new \ReflectionMethod(SampleListener::class, '__invoke');
        $attrs = $ref->getAttributes(Async::class);

        $this->assertCount(1, $attrs);
    }

    #[Test]
    public function broadcast_attribute_carries_channel(): void
    {
        $ref = new \ReflectionClass(SampleBroadcastListener::class);
        $attrs = $ref->getAttributes(Broadcast::class);
        $broadcast = $attrs[0]->newInstance();

        $this->assertSame('admin.{aggregateType}', $broadcast->channel);
    }

    #[Test]
    public function listener_has_optional_priority(): void
    {
        $ref = new \ReflectionClass(SampleListener::class);
        $listener = $ref->getAttributes(Listener::class)[0]->newInstance();

        $this->assertSame(0, $listener->priority);
    }
}

#[Listener]
final class SampleListener
{
    #[Async]
    public function __invoke(): void {}
}

#[Listener]
#[Broadcast(channel: 'admin.{aggregateType}')]
final class SampleBroadcastListener
{
    public function __invoke(): array { return []; }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Event/Attribute/ListenerAttributeTest.php`
Expected: FAIL — Listener attribute class not found.

**Step 3: Write implementation**

`Listener.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Listener
{
    public function __construct(
        public readonly int $priority = 0,
    ) {}
}
```

`Async.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Async {}
```

`Broadcast.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Event\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Broadcast
{
    public function __construct(
        public readonly string $channel,
    ) {}
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Event/Attribute/ListenerAttributeTest.php`
Expected: OK (4 tests)

**Step 5: Commit**

```
git add packages/foundation/src/Event/Attribute/ packages/foundation/tests/Unit/Event/Attribute/
git commit -m "feat(foundation): add Listener, Async, Broadcast event attributes"
```

---

### Task 11: Implement concrete entity domain events

**Files:**
- Create: `packages/entity/src/Event/EntitySaved.php`
- Create: `packages/entity/src/Event/EntityDeleted.php`
- Create: `packages/entity/tests/Unit/Event/EntitySavedTest.php`
- Modify: `packages/entity/composer.json` — add aurora/foundation dependency

**Step 1: Add aurora/foundation to entity package**

In `packages/entity/composer.json`:
- Add repository: `{ "type": "path", "url": "../foundation" }`
- Add require: `"aurora/foundation": "@dev"`

Run: `composer update aurora/entity`

**Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Entity\Tests\Unit\Event;

use Aurora\Entity\Event\EntityDeleted;
use Aurora\Entity\Event\EntitySaved;
use Aurora\Foundation\Event\DomainEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntitySaved::class)]
#[CoversClass(EntityDeleted::class)]
final class EntitySavedTest extends TestCase
{
    #[Test]
    public function entity_saved_is_domain_event(): void
    {
        $event = new EntitySaved(
            entityTypeId: 'node',
            entityId: '42',
            changedFields: ['title', 'body'],
            isNew: false,
        );

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertSame('node', $event->aggregateType);
        $this->assertSame('42', $event->aggregateId);
    }

    #[Test]
    public function entity_saved_carries_changed_fields(): void
    {
        $event = new EntitySaved(
            entityTypeId: 'node',
            entityId: '42',
            changedFields: ['title', 'body'],
            isNew: true,
        );

        $this->assertSame(['title', 'body'], $event->changedFields);
        $this->assertTrue($event->isNew);
    }

    #[Test]
    public function entity_saved_payload(): void
    {
        $event = new EntitySaved(
            entityTypeId: 'node',
            entityId: '42',
            changedFields: ['title'],
            isNew: false,
        );

        $payload = $event->getPayload();

        $this->assertSame('node', $payload['entity_type']);
        $this->assertSame('42', $payload['entity_id']);
        $this->assertSame(['title'], $payload['changed_fields']);
        $this->assertFalse($payload['is_new']);
    }

    #[Test]
    public function entity_saved_carries_tenant_and_actor(): void
    {
        $event = new EntitySaved(
            entityTypeId: 'node',
            entityId: '42',
            changedFields: [],
            isNew: false,
            tenantId: 'acme',
            actorId: 'user-7',
        );

        $this->assertSame('acme', $event->tenantId);
        $this->assertSame('user-7', $event->actorId);
    }

    #[Test]
    public function entity_deleted_is_domain_event(): void
    {
        $event = new EntityDeleted(
            entityTypeId: 'node',
            entityId: '42',
        );

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertSame('node', $event->aggregateType);
        $this->assertSame('42', $event->aggregateId);
    }
}
```

**Step 3: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/entity/tests/Unit/Event/EntitySavedTest.php`
Expected: FAIL — EntitySaved not found.

**Step 4: Write implementation**

`EntitySaved.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Entity\Event;

use Aurora\Foundation\Event\DomainEvent;

final class EntitySaved extends DomainEvent
{
    public function __construct(
        string $entityTypeId,
        string $entityId,
        public readonly array $changedFields,
        public readonly bool $isNew,
        ?string $tenantId = null,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateType: $entityTypeId,
            aggregateId: $entityId,
            tenantId: $tenantId,
            actorId: $actorId,
        );
    }

    public function getPayload(): array
    {
        return [
            'entity_type' => $this->aggregateType,
            'entity_id' => $this->aggregateId,
            'changed_fields' => $this->changedFields,
            'is_new' => $this->isNew,
        ];
    }
}
```

`EntityDeleted.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Entity\Event;

use Aurora\Foundation\Event\DomainEvent;

final class EntityDeleted extends DomainEvent
{
    public function __construct(
        string $entityTypeId,
        string $entityId,
        ?string $tenantId = null,
        ?string $actorId = null,
    ) {
        parent::__construct(
            aggregateType: $entityTypeId,
            aggregateId: $entityId,
            tenantId: $tenantId,
            actorId: $actorId,
        );
    }

    public function getPayload(): array
    {
        return [
            'entity_type' => $this->aggregateType,
            'entity_id' => $this->aggregateId,
        ];
    }
}
```

**Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/entity/tests/Unit/Event/EntitySavedTest.php`
Expected: OK (5 tests)

**Step 6: Commit**

```
git add packages/entity/src/Event/EntitySaved.php packages/entity/src/Event/EntityDeleted.php packages/entity/tests/Unit/Event/EntitySavedTest.php packages/entity/composer.json
git commit -m "feat(entity): add EntitySaved and EntityDeleted domain events"
```

---

### Task 12: Implement `Migration` base class and `SchemaBuilder`

**Files:**
- Create: `packages/foundation/src/Migration/Migration.php`
- Create: `packages/foundation/src/Migration/SchemaBuilder.php`
- Create: `packages/foundation/src/Migration/TableBuilder.php`
- Create: `packages/foundation/src/Migration/ColumnDefinition.php`
- Create: `packages/foundation/tests/Unit/Migration/SchemaBuilderTest.php`

**Step 1: Add doctrine/dbal to foundation**

Add to `packages/foundation/composer.json` require: `"doctrine/dbal": "^4.0"`.
Run: `composer update aurora/foundation`

**Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Migration;

use Aurora\Foundation\Migration\Migration;
use Aurora\Foundation\Migration\SchemaBuilder;
use Aurora\Foundation\Migration\TableBuilder;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaBuilder::class)]
#[CoversClass(TableBuilder::class)]
#[CoversClass(Migration::class)]
final class SchemaBuilderTest extends TestCase
{
    private SchemaBuilder $schema;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->schema = new SchemaBuilder($connection);
    }

    #[Test]
    public function create_table_with_columns(): void
    {
        $this->schema->create('users', function (TableBuilder $table) {
            $table->id();
            $table->string('name');
            $table->string('mail')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $this->assertTrue($this->schema->hasTable('users'));
        $this->assertTrue($this->schema->hasColumn('users', 'id'));
        $this->assertTrue($this->schema->hasColumn('users', 'name'));
        $this->assertTrue($this->schema->hasColumn('users', 'mail'));
        $this->assertTrue($this->schema->hasColumn('users', 'active'));
        $this->assertTrue($this->schema->hasColumn('users', 'created'));
        $this->assertTrue($this->schema->hasColumn('users', 'changed'));
    }

    #[Test]
    public function create_table_with_json_column(): void
    {
        $this->schema->create('nodes', function (TableBuilder $table) {
            $table->id();
            $table->json('_data')->nullable();
        });

        $this->assertTrue($this->schema->hasColumn('nodes', '_data'));
    }

    #[Test]
    public function drop_table(): void
    {
        $this->schema->create('temp', function (TableBuilder $table) {
            $table->id();
        });
        $this->assertTrue($this->schema->hasTable('temp'));

        $this->schema->drop('temp');
        $this->assertFalse($this->schema->hasTable('temp'));
    }

    #[Test]
    public function drop_if_exists_does_not_throw_for_missing(): void
    {
        $this->schema->dropIfExists('nonexistent');
        $this->assertFalse($this->schema->hasTable('nonexistent'));
    }

    #[Test]
    public function entity_base_convention(): void
    {
        $this->schema->create('nodes', function (TableBuilder $table) {
            $table->entityBase();
        });

        $this->assertTrue($this->schema->hasColumn('nodes', 'id'));
        $this->assertTrue($this->schema->hasColumn('nodes', 'entity_type'));
        $this->assertTrue($this->schema->hasColumn('nodes', 'bundle'));
        $this->assertTrue($this->schema->hasColumn('nodes', '_data'));
        $this->assertTrue($this->schema->hasColumn('nodes', 'created'));
        $this->assertTrue($this->schema->hasColumn('nodes', 'changed'));
    }

    #[Test]
    public function translation_columns_convention(): void
    {
        $this->schema->create('node_translations', function (TableBuilder $table) {
            $table->string('entity_id');
            $table->translationColumns();
        });

        $this->assertTrue($this->schema->hasColumn('node_translations', 'langcode'));
        $this->assertTrue($this->schema->hasColumn('node_translations', 'default_langcode'));
        $this->assertTrue($this->schema->hasColumn('node_translations', 'translation_source'));
    }

    #[Test]
    public function migration_class_has_up_and_down(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('test', function (TableBuilder $table) {
                    $table->id();
                });
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('test');
            }
        };

        $migration->up($this->schema);
        $this->assertTrue($this->schema->hasTable('test'));

        $migration->down($this->schema);
        $this->assertFalse($this->schema->hasTable('test'));
    }

    #[Test]
    public function table_prefix_applied(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new SchemaBuilder($connection, tablePrefix: 'acme_');

        $schema->create('nodes', function (TableBuilder $table) {
            $table->id();
        });

        $this->assertTrue($schema->hasTable('nodes'));
        // Underlying table is acme_nodes — verified via raw SQL
        $result = $connection->executeQuery("SELECT name FROM sqlite_master WHERE type='table' AND name='acme_nodes'");
        $this->assertNotFalse($result->fetchOne());
    }
}
```

**Step 3: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Migration/SchemaBuilderTest.php`
Expected: FAIL — classes not found.

**Step 4: Write implementation**

`Migration.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Migration;

abstract class Migration
{
    /** @var list<string> Package names this migration must run after */
    public array $after = [];

    abstract public function up(SchemaBuilder $schema): void;

    public function down(SchemaBuilder $schema): void {}
}
```

`ColumnDefinition.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Migration;

final class ColumnDefinition
{
    private bool $isNullable = false;
    private mixed $defaultValue = null;
    private bool $hasDefault = false;
    private bool $isUnique = false;

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
    ) {}

    public function nullable(): self
    {
        $this->isNullable = true;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->defaultValue = $value;
        $this->hasDefault = true;
        return $this;
    }

    public function unique(): self
    {
        $this->isUnique = true;
        return $this;
    }

    public function isNullable(): bool { return $this->isNullable; }
    public function hasDefaultValue(): bool { return $this->hasDefault; }
    public function getDefaultValue(): mixed { return $this->defaultValue; }
    public function isUnique(): bool { return $this->isUnique; }
}
```

`TableBuilder.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Migration;

final class TableBuilder
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<array{columns: list<string>}> */
    private array $indexes = [];

    /** @var list<array{columns: list<string>}> */
    private array $uniqueIndexes = [];

    private ?array $primaryKey = null;

    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->string($name, 128);
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'string', $length);
        $this->columns[] = $col;
        return $col;
    }

    public function text(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'text');
        $this->columns[] = $col;
        return $col;
    }

    public function integer(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'integer');
        $this->columns[] = $col;
        return $col;
    }

    public function boolean(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'boolean');
        $this->columns[] = $col;
        return $col;
    }

    public function float(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'float');
        $this->columns[] = $col;
        return $col;
    }

    public function json(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'json');
        $this->columns[] = $col;
        return $col;
    }

    public function timestamp(string $name): ColumnDefinition
    {
        $col = new ColumnDefinition($name, 'datetime_immutable');
        $this->columns[] = $col;
        return $col;
    }

    public function timestamps(): void
    {
        $this->timestamp('created');
        $this->timestamp('changed');
    }

    public function primary(array $columns): void
    {
        $this->primaryKey = $columns;
    }

    public function unique(array|string $columns): void
    {
        $this->uniqueIndexes[] = ['columns' => (array) $columns];
    }

    public function index(array|string $columns, ?string $name = null): void
    {
        $this->indexes[] = ['columns' => (array) $columns, 'name' => $name];
    }

    public function entityBase(): void
    {
        $this->id();
        $this->string('entity_type', 64);
        $this->string('bundle', 64);
        $this->json('_data')->nullable();
        $this->timestamps();
    }

    public function translationColumns(): void
    {
        $this->string('langcode', 12);
        $this->boolean('default_langcode')->default(true);
        $this->string('translation_source', 12)->nullable();
    }

    public function revisionColumns(): void
    {
        $this->string('revision_id', 128);
        $this->timestamp('revision_created');
        $this->text('revision_log')->nullable();
    }

    /** @return list<ColumnDefinition> */
    public function getColumns(): array { return $this->columns; }
    public function getIndexes(): array { return $this->indexes; }
    public function getUniqueIndexes(): array { return $this->uniqueIndexes; }
    public function getPrimaryKey(): ?array { return $this->primaryKey; }
}
```

`SchemaBuilder.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

final class SchemaBuilder
{
    private const TYPE_MAP = [
        'string' => Types::STRING,
        'text' => Types::TEXT,
        'integer' => Types::INTEGER,
        'boolean' => Types::BOOLEAN,
        'float' => Types::FLOAT,
        'json' => Types::JSON,
        'datetime_immutable' => Types::DATETIME_IMMUTABLE,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tablePrefix = '',
    ) {}

    public function create(string $table, \Closure $callback): void
    {
        $builder = new TableBuilder();
        $callback($builder);

        $prefixedTable = $this->tablePrefix . $table;
        $schema = new Schema();
        $dbalTable = $schema->createTable($prefixedTable);

        $primaryColumns = [];
        foreach ($builder->getColumns() as $col) {
            $type = self::TYPE_MAP[$col->type] ?? Types::STRING;
            $options = [];
            if ($col->length !== null) {
                $options['length'] = $col->length;
            }
            if ($col->isNullable()) {
                $options['notnull'] = false;
            }
            if ($col->hasDefaultValue()) {
                $options['default'] = $col->getDefaultValue();
            }
            $dbalTable->addColumn($col->name, $type, $options);

            if ($col->isUnique()) {
                $dbalTable->addUniqueIndex([$col->name]);
            }
        }

        $pk = $builder->getPrimaryKey();
        if ($pk !== null) {
            $dbalTable->setPrimaryKey($pk);
        } elseif ($this->hasColumnNamed($builder, 'id')) {
            $dbalTable->setPrimaryKey(['id']);
        }

        foreach ($builder->getUniqueIndexes() as $idx) {
            $dbalTable->addUniqueIndex($idx['columns']);
        }

        foreach ($builder->getIndexes() as $idx) {
            $dbalTable->addIndex($idx['columns'], $idx['name'] ?? null);
        }

        $platform = $this->connection->getDatabasePlatform();
        foreach ($schema->toSql($platform) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    public function drop(string $table): void
    {
        $prefixed = $this->tablePrefix . $table;
        $this->connection->executeStatement("DROP TABLE {$prefixed}");
    }

    public function dropIfExists(string $table): void
    {
        $prefixed = $this->tablePrefix . $table;
        $this->connection->executeStatement("DROP TABLE IF EXISTS {$prefixed}");
    }

    public function hasTable(string $table): bool
    {
        $prefixed = $this->tablePrefix . $table;
        return $this->connection->createSchemaManager()->tablesExist([$prefixed]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        $prefixed = $this->tablePrefix . $table;
        $columns = $this->connection->createSchemaManager()->listTableColumns($prefixed);
        foreach ($columns as $col) {
            if ($col->getName() === $column) {
                return true;
            }
        }
        return false;
    }

    private function hasColumnNamed(TableBuilder $builder, string $name): bool
    {
        foreach ($builder->getColumns() as $col) {
            if ($col->name === $name) {
                return true;
            }
        }
        return false;
    }
}
```

**Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Migration/SchemaBuilderTest.php`
Expected: OK (8 tests)

**Step 6: Commit**

```
git add packages/foundation/src/Migration/ packages/foundation/tests/Unit/Migration/ packages/foundation/composer.json
git commit -m "feat(foundation): add Migration, SchemaBuilder, TableBuilder with Doctrine DBAL"
```

---

### Task 13: Implement `Migrator` — runner with dependency ordering

**Files:**
- Create: `packages/foundation/src/Migration/Migrator.php`
- Create: `packages/foundation/src/Migration/MigrationRepository.php`
- Create: `packages/foundation/src/Migration/MigrationDiscovery.php`
- Create: `packages/foundation/src/Migration/MigrationResult.php`
- Create: `packages/foundation/tests/Unit/Migration/MigratorTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Migration;

use Aurora\Foundation\Migration\Migration;
use Aurora\Foundation\Migration\MigrationRepository;
use Aurora\Foundation\Migration\MigrationResult;
use Aurora\Foundation\Migration\Migrator;
use Aurora\Foundation\Migration\SchemaBuilder;
use Aurora\Foundation\Migration\TableBuilder;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Migrator::class)]
#[CoversClass(MigrationRepository::class)]
#[CoversClass(MigrationResult::class)]
final class MigratorTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private SchemaBuilder $schema;
    private MigrationRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->schema = new SchemaBuilder($this->connection);
        $this->repository = new MigrationRepository($this->connection);
        $this->repository->createTable();
    }

    #[Test]
    public function runs_pending_migrations(): void
    {
        $migrations = [
            'aurora/test' => [
                '2026_03_01_000001_create_test' => new class extends Migration {
                    public function up(SchemaBuilder $schema): void
                    {
                        $schema->create('test', function (TableBuilder $table) {
                            $table->id();
                            $table->string('name');
                        });
                    }
                },
            ],
        ];

        $migrator = new Migrator($this->connection, $this->repository);
        $result = $migrator->run($migrations);

        $this->assertSame(1, $result->count);
        $this->assertTrue($this->schema->hasTable('test'));
    }

    #[Test]
    public function skips_already_run_migrations(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('test', function (TableBuilder $table) {
                    $table->id();
                });
            }
        };

        $migrations = ['aurora/test' => ['2026_03_01_000001_create_test' => $migration]];

        $migrator = new Migrator($this->connection, $this->repository);
        $migrator->run($migrations);
        $result = $migrator->run($migrations);

        $this->assertSame(0, $result->count);
    }

    #[Test]
    public function rollback_reverses_last_batch(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('test', function (TableBuilder $table) {
                    $table->id();
                });
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('test');
            }
        };

        $migrations = ['aurora/test' => ['2026_03_01_000001_create_test' => $migration]];

        $migrator = new Migrator($this->connection, $this->repository);
        $migrator->run($migrations);
        $this->assertTrue($this->schema->hasTable('test'));

        $result = $migrator->rollback($migrations);
        $this->assertSame(1, $result->count);
        $this->assertFalse($this->schema->hasTable('test'));
    }

    #[Test]
    public function respects_package_ordering_via_after(): void
    {
        $order = [];

        $migrationA = new class($order) extends Migration {
            public array $after = ['aurora/base'];
            public function __construct(private array &$order) {}
            public function up(SchemaBuilder $schema): void { $this->order[] = 'A'; }
        };

        $migrationB = new class($order) extends Migration {
            public function __construct(private array &$order) {}
            public function up(SchemaBuilder $schema): void { $this->order[] = 'B'; }
        };

        // B is in aurora/base, A depends on aurora/base — B must run first
        $migrations = [
            'aurora/dependent' => ['2026_03_01_000001_a' => $migrationA],
            'aurora/base' => ['2026_03_01_000001_b' => $migrationB],
        ];

        $migrator = new Migrator($this->connection, $this->repository);
        $migrator->run($migrations);

        $this->assertSame(['B', 'A'], $order);
    }

    #[Test]
    public function status_reports_pending_and_completed(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };

        $migrations = [
            'aurora/test' => [
                '2026_03_01_000001_first' => $migration,
                '2026_03_01_000002_second' => $migration,
            ],
        ];

        $migrator = new Migrator($this->connection, $this->repository);

        // Before running
        $status = $migrator->status($migrations);
        $this->assertCount(2, $status['pending']);
        $this->assertCount(0, $status['completed']);

        // Run one batch
        $migrator->run($migrations);

        $status = $migrator->status($migrations);
        $this->assertCount(0, $status['pending']);
        $this->assertCount(2, $status['completed']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Migration/MigratorTest.php`
Expected: FAIL — Migrator not found.

**Step 3: Write implementation**

`MigrationResult.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Migration;

final readonly class MigrationResult
{
    public function __construct(
        public int $count,
        public array $migrations = [],
    ) {}
}
```

`MigrationRepository.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Migration;

use Doctrine\DBAL\Connection;

final class MigrationRepository
{
    private const TABLE = 'aurora_migrations';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function createTable(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                package VARCHAR(128) NOT NULL,
                batch INTEGER NOT NULL,
                ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
        );
    }

    public function hasRun(string $migration): bool
    {
        $result = $this->connection->executeQuery(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE migration = ?',
            [$migration],
        );

        return (int) $result->fetchOne() > 0;
    }

    public function getLastBatchNumber(): int
    {
        $result = $this->connection->executeQuery(
            'SELECT MAX(batch) FROM ' . self::TABLE,
        );
        return (int) $result->fetchOne();
    }

    public function record(string $migration, string $package, int $batch): void
    {
        $this->connection->insert(self::TABLE, [
            'migration' => $migration,
            'package' => $package,
            'batch' => $batch,
        ]);
    }

    public function remove(string $migration): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE migration = ?',
            [$migration],
        );
    }

    /** @return list<array{migration: string, package: string, batch: int}> */
    public function getByBatch(int $batch): array
    {
        $result = $this->connection->executeQuery(
            'SELECT migration, package, batch FROM ' . self::TABLE . ' WHERE batch = ? ORDER BY id DESC',
            [$batch],
        );
        return $result->fetchAllAssociative();
    }

    /** @return list<string> */
    public function getCompleted(): array
    {
        $result = $this->connection->executeQuery(
            'SELECT migration FROM ' . self::TABLE . ' ORDER BY id',
        );
        return $result->fetchFirstColumn();
    }
}
```

`Migrator.php`:
```php
<?php

declare(strict_types=1);

namespace Aurora\Foundation\Migration;

use Doctrine\DBAL\Connection;

final class Migrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationRepository $repository,
    ) {}

    /**
     * @param array<string, array<string, Migration>> $migrations package => [name => Migration]
     */
    public function run(array $migrations): MigrationResult
    {
        $ordered = $this->resolveDependencyOrder($migrations);
        $batch = $this->repository->getLastBatchNumber() + 1;
        $ran = [];

        foreach ($ordered as ['package' => $package, 'name' => $name, 'migration' => $migration]) {
            if ($this->repository->hasRun($name)) {
                continue;
            }

            $schema = new SchemaBuilder($this->connection);
            $migration->up($schema);
            $this->repository->record($name, $package, $batch);
            $ran[] = $name;
        }

        return new MigrationResult(count($ran), $ran);
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     */
    public function rollback(array $migrations): MigrationResult
    {
        $batch = $this->repository->getLastBatchNumber();
        if ($batch === 0) {
            return new MigrationResult(0);
        }

        $records = $this->repository->getByBatch($batch);
        $flat = $this->flattenMigrations($migrations);
        $rolledBack = [];

        foreach ($records as $record) {
            $name = $record['migration'];
            if (isset($flat[$name])) {
                $schema = new SchemaBuilder($this->connection);
                $flat[$name]->down($schema);
            }
            $this->repository->remove($name);
            $rolledBack[] = $name;
        }

        return new MigrationResult(count($rolledBack), $rolledBack);
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     * @return array{pending: list<string>, completed: list<string>}
     */
    public function status(array $migrations): array
    {
        $completed = $this->repository->getCompleted();
        $all = array_keys($this->flattenMigrations($migrations));
        $pending = array_values(array_diff($all, $completed));

        return ['pending' => $pending, 'completed' => $completed];
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     * @return list<array{package: string, name: string, migration: Migration}>
     */
    private function resolveDependencyOrder(array $migrations): array
    {
        // Topological sort: packages with no $after run first
        $packageOrder = $this->topologicalSort($migrations);

        $ordered = [];
        foreach ($packageOrder as $package) {
            foreach ($migrations[$package] ?? [] as $name => $migration) {
                $ordered[] = ['package' => $package, 'name' => $name, 'migration' => $migration];
            }
        }

        return $ordered;
    }

    /**
     * @param array<string, array<string, Migration>> $migrations
     * @return list<string> package names in dependency order
     */
    private function topologicalSort(array $migrations): array
    {
        $packages = array_keys($migrations);
        $deps = [];

        foreach ($migrations as $package => $packageMigrations) {
            $deps[$package] = [];
            foreach ($packageMigrations as $migration) {
                foreach ($migration->after as $dep) {
                    if (in_array($dep, $packages, true)) {
                        $deps[$package][] = $dep;
                    }
                }
            }
            $deps[$package] = array_unique($deps[$package]);
        }

        $sorted = [];
        $visited = [];

        $visit = function (string $package) use (&$visit, &$sorted, &$visited, $deps): void {
            if (isset($visited[$package])) {
                return;
            }
            $visited[$package] = true;

            foreach ($deps[$package] ?? [] as $dep) {
                $visit($dep);
            }

            $sorted[] = $package;
        };

        foreach ($packages as $package) {
            $visit($package);
        }

        return $sorted;
    }

    /**
     * @return array<string, Migration>
     */
    private function flattenMigrations(array $migrations): array
    {
        $flat = [];
        foreach ($migrations as $packageMigrations) {
            foreach ($packageMigrations as $name => $migration) {
                $flat[$name] = $migration;
            }
        }
        return $flat;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Migration/MigratorTest.php`
Expected: OK (5 tests)

**Step 5: Commit**

```
git add packages/foundation/src/Migration/ packages/foundation/tests/Unit/Migration/
git commit -m "feat(foundation): add Migrator with dependency ordering and batch rollback"
```

---

### Task 14: Run full test suite to verify no regressions

**Step 1: Run all tests**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All existing tests pass plus new foundation tests.

**Step 2: Verify test count increased**

Count new tests: ~48 new assertions across Tasks 2-13.
All 200+ existing tests should still pass.

**Step 3: Commit if any fixes needed**

If any regressions, fix them and commit:
```
git commit -m "fix: resolve regressions from foundation package integration"
```

---

## Phase 2: Multilingual + Error Handling (Pillars 4-5)

> Detailed TDD tasks will be written when Phase 1 is complete. Below is the task-level breakdown with files.

### Task 15: Create `aurora/i18n` package skeleton

**Files:**
- Create: `packages/i18n/composer.json`
- Create: `packages/i18n/src/Language.php`
- Create: `packages/i18n/src/LanguageManagerInterface.php`
- Create: `packages/i18n/src/LanguageManager.php`
- Create: `packages/i18n/src/LanguageContext.php`
- Create: `packages/i18n/src/FallbackChain.php`
- Modify: `composer.json` (root)
- Modify: `packages/core/composer.json`

### Task 16: Implement Language and LanguageManager

**Test:** `packages/i18n/tests/Unit/LanguageManagerTest.php`
- Test default language retrieval
- Test language listing
- Test fallback chain resolution (es -> pt -> en)
- Test isMultilingual() returns false with one language

### Task 17: Implement LanguageContext

**Test:** `packages/i18n/tests/Unit/LanguageContextTest.php`
- Test two-axis language context (content + interface)
- Test immutable withContentLanguage/withInterfaceLanguage

### Task 18: Add TranslatableInterface to entity package

**Files:**
- Create: `packages/entity/src/TranslatableInterface.php`
- Modify: `packages/field/src/FieldDefinition.php` — add `translatable` property

### Task 19: Implement translation storage in entity-storage

**Files:**
- Modify: `packages/entity-storage/src/SqlEntityStorage.php` — language-aware load with COALESCE fallback
- Modify: `packages/entity-storage/src/SqlSchemaHandler.php` — create translation tables
- Create: `packages/entity-storage/tests/Unit/TranslationStorageTest.php`
- Key tests: load with language, load with fallback chain, save translation, delete translation, getAvailableLanguages

### Task 20: Implement config translation

**Files:**
- Modify: `packages/config/src/ConfigFactoryInterface.php` — add getTranslated(), getOriginal()
- Create: `packages/config/src/TranslatableConfigFactory.php`
- Create: `packages/config/tests/Unit/TranslatableConfigFactoryTest.php`

### Task 21: Implement LanguageNegotiator middleware

**Files:**
- Create: `packages/routing/src/Language/LanguageNegotiator.php`
- Create: `packages/routing/src/Language/LanguageNegotiatorInterface.php`
- Create: `packages/routing/src/Language/UrlPrefixNegotiator.php`
- Create: `packages/routing/src/Language/AcceptHeaderNegotiator.php`
- Create: `packages/routing/tests/Unit/Language/LanguageNegotiatorTest.php`

### Task 22: Add translation endpoints to JSON:API

**Files:**
- Modify: `packages/api/src/Controller/JsonApiController.php` — langcode parameter
- Create: `packages/api/src/Controller/TranslationController.php` — CRUD sub-endpoints
- Create: `packages/api/tests/Unit/TranslationControllerTest.php`

### Task 23: Add language-aware vector embeddings

**Files:**
- Modify: `packages/ai-vector/src/EntityEmbedding.php` — add langcode field
- Modify: `packages/ai-vector/src/InMemoryVectorStore.php` — language-scoped search
- Create: `packages/ai-vector/tests/Unit/LanguageAwareVectorTest.php`

### Task 24: Add translation-aware MCP tools

**Files:**
- Modify: `packages/ai-schema/src/McpToolGenerator.php` — langcode param on all tools
- Create: `packages/ai-schema/src/TranslationToolGenerator.php` — translation CRUD tools

### Task 25: Implement ExceptionHandler

**Files:**
- Create: `packages/foundation/src/Exception/ExceptionHandler.php`
- Create: `packages/foundation/src/Exception/RequestContext.php`
- Create: `packages/foundation/tests/Unit/Exception/ExceptionHandlerTest.php`
- Key tests: renders API errors as JSON:API, CLI errors as formatted text, carries context

---

## Phase 3: Storage + Security + Config (Pillars 6-8, 14)

### Task 26: Implement config schema validation

**Files:**
- Create: `packages/config/src/Schema/ConfigSchemaValidator.php`
- Create: `packages/config/config/schema/` — schema files per package
- Test: schema validation on import, rejection of invalid values

### Task 27: Implement environment config overrides

**Files:**
- Create: `packages/config/src/EnvironmentConfigFactory.php`
- Test: base -> env overlay -> env var resolution order

### Task 28: Implement config manifest versioning

**Files:**
- Create: `packages/config/src/ConfigManifest.php`
- Test: manifest generation, checksum verification, backward version warning

### Task 29: Implement EntityRepositoryInterface

**Files:**
- Create: `packages/entity/src/Repository/EntityRepositoryInterface.php`
- Create: `packages/entity-storage/src/EntityRepository.php`
- Test: find, findBy, save (dispatches domain events), delete, query, exists, count

### Task 30: Implement EntityStorageDriverInterface

**Files:**
- Create: `packages/entity-storage/src/Driver/EntityStorageDriverInterface.php`
- Create: `packages/entity-storage/src/Driver/SqlStorageDriver.php`
- Create: `packages/entity-storage/src/Driver/InMemoryStorageDriver.php`
- Test: driver reads/writes rows without entity hydration or event dispatch

### Task 31: Implement ConnectionResolverInterface

**Files:**
- Create: `packages/entity-storage/src/Connection/ConnectionResolverInterface.php`
- Create: `packages/entity-storage/src/Connection/SingleConnectionResolver.php`
- Test: single connection always returned, getDefaultConnectionName

### Task 32: Implement UnitOfWork

**Files:**
- Create: `packages/entity-storage/src/UnitOfWork.php`
- Test: transaction commits on success, rolls back on exception, buffers domain events

### Task 33: Implement TenantContext and NullTenantResolver

**Files:**
- Create: `packages/foundation/src/Tenant/TenantContext.php`
- Create: `packages/foundation/src/Tenant/TenantResolverInterface.php`
- Create: `packages/foundation/src/Tenant/NullTenantResolver.php`
- Create: `packages/foundation/src/Tenant/TenantMiddleware.php`
- Test: NullTenantResolver returns null, TenantMiddleware sets scoped services

### Task 34: Implement GateInterface and Policy system

**Files:**
- Create: `packages/access/src/Gate/GateInterface.php`
- Create: `packages/access/src/Gate/Gate.php`
- Create: `packages/access/src/Gate/PolicyAttribute.php`
- Test: Gate resolves policies by convention, allows/denies, authorize throws

### Task 35: Wire Gate into routing

**Files:**
- Create: `packages/routing/src/Attribute/GateAttribute.php`
- Modify: `packages/routing/src/AccessChecker.php` — check Gate abilities on routes
- Test: route with #[Gate('config.export')] denies unauthorized users

---

## Phase 4: Admin SPA + DX (Pillars 9-11)

### Task 36: Initialize Nuxt project in packages/admin

**Files:**
- Replace: `packages/admin/` — full Nuxt 3 scaffold with TypeScript
- Create: `packages/admin/nuxt.config.ts`
- Create: `packages/admin/app/app.vue`
- Create: `packages/admin/package.json`

### Task 37: Implement schema endpoint

**Files:**
- Create: `packages/api/src/Controller/SchemaController.php`
- Create: `packages/api/src/Schema/SchemaPresenter.php`
- Test: returns JSON Schema with widget hints, permissions, field metadata

### Task 38: Build SchemaForm Vue component

**Files:**
- Create: `packages/admin/app/components/schema/SchemaForm.vue`
- Create: `packages/admin/app/components/schema/SchemaField.vue`
- Create: `packages/admin/app/composables/useSchema.ts`
- Create: `packages/admin/app/composables/useEntity.ts`

### Task 39: Build widget components

**Files:**
- Create: `packages/admin/app/components/widgets/TextInput.vue`
- Create: `packages/admin/app/components/widgets/RichText.vue`
- Create: `packages/admin/app/components/widgets/Select.vue`
- Create: `packages/admin/app/components/widgets/Toggle.vue`
- Create: `packages/admin/app/components/widgets/EntityAutocomplete.vue`

### Task 40: Build SchemaList and NavBuilder

**Files:**
- Create: `packages/admin/app/components/schema/SchemaList.vue`
- Create: `packages/admin/app/components/layout/AdminShell.vue`
- Create: `packages/admin/app/components/layout/NavBuilder.vue`
- Create: `packages/admin/app/pages/[entityType]/index.vue`
- Create: `packages/admin/app/pages/[entityType]/[id].vue`
- Create: `packages/admin/app/pages/[entityType]/create.vue`

### Task 41: Implement i18n in admin SPA

**Files:**
- Create: `packages/admin/app/i18n/en.json`
- Create: `packages/admin/app/composables/useLanguage.ts`
- Create: `packages/admin/app/pages/translations/[entityType]/[id].vue`

### Task 42: Create `aurora/testing` package

**Files:**
- Create: `packages/testing/composer.json`
- Create: `packages/testing/src/AuroraTestCase.php`
- Create: `packages/testing/src/Factory/EntityFactory.php`
- Create: `packages/testing/src/Traits/CreatesApplication.php`
- Create: `packages/testing/src/Traits/InteractsWithAuth.php`
- Create: `packages/testing/src/Traits/InteractsWithApi.php`
- Create: `packages/testing/src/Traits/InteractsWithEvents.php`
- Create: `packages/testing/src/Traits/RefreshDatabase.php`
- Test: factories create entities, actingAs sets current user, assertion helpers work

### Task 43: Implement `aurora make:*` scaffolding commands

**Files:**
- Create: `packages/cli/src/Command/Make/MakeEntityCommand.php`
- Create: `packages/cli/src/Command/Make/MakeMigrationCommand.php`
- Create: `packages/cli/src/Command/Make/MakeProviderCommand.php`
- Create: `packages/cli/src/Command/Make/MakeListenerCommand.php`
- Create: `packages/cli/src/Command/Make/MakeJobCommand.php`
- Create: `packages/cli/src/Command/Make/MakePolicyCommand.php`
- Create: `packages/cli/src/Command/Make/MakeTestCommand.php`
- Create stubs: `packages/cli/stubs/entity.stub`, `migration.stub`, etc.

### Task 44: Implement introspection commands

**Files:**
- Create: `packages/cli/src/Command/AboutCommand.php`
- Create: `packages/cli/src/Command/RouteListCommand.php`
- Create: `packages/cli/src/Command/EntityListCommand.php`
- Create: `packages/cli/src/Command/EventListCommand.php`
- Create: `packages/cli/src/Command/PermissionListCommand.php`

---

## Phase 5: Runtime + Operations (Pillars 12-13, 15-17)

### Task 45: Implement Job base class and dispatch

**Files:**
- Create: `packages/queue/src/Job/Job.php`
- Create: `packages/queue/src/Job/ChainedJobs.php`
- Create: `packages/queue/src/Job/BatchedJobs.php`
- Create: `packages/queue/src/Attribute/UniqueJob.php`
- Create: `packages/queue/src/Attribute/RateLimited.php`
- Create: `packages/queue/src/Attribute/OnQueue.php`
- Create: `packages/queue/src/FailedJobRepository.php`

### Task 46: Implement SSE broadcaster

**Files:**
- Create: `packages/foundation/src/Broadcasting/SseBroadcaster.php`
- Create: `packages/foundation/src/Broadcasting/BroadcastMessage.php`
- Create: `packages/api/src/Controller/BroadcastController.php`

### Task 47: Implement realtime composable in admin SPA

**Files:**
- Create: `packages/admin/app/composables/useRealtime.ts`
- Wire into SchemaList for auto-refresh on entity changes

### Task 48: Create `aurora/telescope` package

**Files:**
- Create: `packages/telescope/composer.json`
- Create: `packages/telescope/src/TelescopeServiceProvider.php`
- Create: `packages/telescope/src/Recorder/QueryRecorder.php`
- Create: `packages/telescope/src/Recorder/EventRecorder.php`
- Create: `packages/telescope/src/Recorder/RequestRecorder.php`
- Create: `packages/telescope/src/Recorder/CacheRecorder.php`
- Create: `packages/telescope/src/Storage/SqliteTelescopeStore.php`

### Task 49: Implement telescope CLI

**Files:**
- Create: `packages/cli/src/Command/Telescope/TelescopeCommand.php`
- Create: `packages/cli/src/Command/Telescope/TelescopeQueriesCommand.php`
- Create: `packages/cli/src/Command/Telescope/TelescopeClearCommand.php`

### Task 50: Implement cache backend configuration

**Files:**
- Create: `packages/cache/src/CacheConfiguration.php`
- Create: `packages/cache/src/Backend/DatabaseBackend.php`
- Modify: `packages/cache/src/CacheFactory.php` — bin-to-backend mapping from config

### Task 51: Implement entity cache invalidation listeners

**Files:**
- Create: `packages/cache/src/Listener/EntityCacheInvalidator.php`
- Create: `packages/cache/src/Listener/ConfigCacheInvalidator.php`
- Create: `packages/cache/src/Listener/TranslationCacheInvalidator.php`

### Task 52: Implement HTTP cache headers middleware

**Files:**
- Create: `packages/api/src/Cache/ApiCacheMiddleware.php`
- Test: ETag generation, Vary header, Cache-Control for entity/collection/schema responses

### Task 53: Implement AssetManager

**Files:**
- Create: `packages/foundation/src/Asset/AssetManagerInterface.php`
- Create: `packages/foundation/src/Asset/ViteAssetManager.php`
- Create: `packages/foundation/src/Asset/TenantAssetResolver.php`

### Task 54: Implement Vue island runtime

**Files:**
- Create: `packages/ssr/assets/js/islands/island-runtime.ts`
- Create: `packages/ssr/vite.config.ts`

### Task 55: Final integration test — full stack smoke test

**Files:**
- Create: `tests/Integration/Phase12/FullArchitectureTest.php`
- Test: boot Aurora with providers, create entity, dispatch domain event, verify cache invalidation, verify migration tracking, verify i18n loading

---

## Execution Notes

- Each task produces a working commit. No task leaves broken tests.
- Phase 1 is fully specified with TDD steps. Execute it first.
- Phases 2-5 task breakdowns will be expanded with full TDD steps as each phase begins — the task-level outlines above provide the roadmap and exact file paths.
- Run `./vendor/bin/phpunit --configuration phpunit.xml.dist` after every commit to catch regressions.
- The `aurora/foundation` package is the keystone — Pillars 1-3 all land there.
