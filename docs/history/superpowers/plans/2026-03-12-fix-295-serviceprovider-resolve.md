# Fix #295: ServiceProvider::commands() Cannot Resolve Bindings

> **For agentic workers:** REQUIRED: Use superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow service providers to resolve their own registered bindings inside `commands()`, eliminating the need to duplicate construction logic.

**Architecture:** Add a `resolve()` method to the `ServiceProvider` base class that looks up bindings from its own `$bindings` array, invokes callables, and caches shared instances. No method signature changes. Fully backward compatible.

**Tech Stack:** PHP 8.4, PHPUnit 10.5

---

## Root Cause

`ServiceProvider::commands()` receives `(EntityTypeManager, PdoDatabase, EventDispatcherInterface)` but cannot access bindings registered via `singleton()`/`bind()` in `register()`. The `$bindings` array is private and only exposed via `getBindings()` for the `ContainerCompiler`. Providers that need a bound service in `commands()` must duplicate the construction logic.

## Fix Design

Add `protected function resolve(string $abstract): mixed` to `ServiceProvider`:
- Looks up `$abstract` in `$this->bindings`
- If the concrete is callable, invokes it; if it's a class name string, instantiates it
- If the binding is `shared: true`, caches the resolved instance for reuse
- Throws `\RuntimeException` if no binding found

This is the minimal fix: no signature changes, no container dependency, fully backward compatible.

---

### Task 1: Write failing test for resolve()

**Files:**
- Create: `packages/foundation/tests/Unit/ServiceProvider/ServiceProviderResolveTest.php`

- [ ] **Step 1: Create test file with failing tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\ServiceProvider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

#[CoversClass(ServiceProvider::class)]
final class ServiceProviderResolveTest extends TestCase
{
    #[Test]
    public function resolve_singleton_returns_same_instance(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton(\stdClass::class, fn() => new \stdClass());
            }

            public function resolvePublic(string $abstract): mixed
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $a = $provider->resolvePublic(\stdClass::class);
        $b = $provider->resolvePublic(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $a);
        $this->assertSame($a, $b);
    }

    #[Test]
    public function resolve_bind_returns_new_instance_each_time(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->bind(\stdClass::class, fn() => new \stdClass());
            }

            public function resolvePublic(string $abstract): mixed
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $a = $provider->resolvePublic(\stdClass::class);
        $b = $provider->resolvePublic(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $a);
        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function resolve_throws_for_unknown_binding(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void {}

            public function resolvePublic(string $abstract): mixed
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No binding registered for');
        $provider->resolvePublic('Nonexistent\\Class');
    }

    #[Test]
    public function resolve_with_string_concrete_instantiates_class(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton(\stdClass::class, \stdClass::class);
            }

            public function resolvePublic(string $abstract): mixed
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $this->assertInstanceOf(\stdClass::class, $provider->resolvePublic(\stdClass::class));
    }

    #[Test]
    public function commands_can_use_resolve_for_dependencies(): void
    {
        $provider = new class extends ServiceProvider {
            public function register(): void
            {
                $this->singleton('test.service', fn() => new \stdClass());
            }

            public function resolvePublic(string $abstract): mixed
            {
                return $this->resolve($abstract);
            }
        };
        $provider->register();

        $service = $provider->resolvePublic('test.service');
        $this->assertInstanceOf(\stdClass::class, $service);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/ServiceProvider/ServiceProviderResolveTest.php`
Expected: FAIL — `resolve()` method does not exist

### Task 2: Implement resolve() method

**Files:**
- Modify: `packages/foundation/src/ServiceProvider/ServiceProvider.php`

- [ ] **Step 1: Add resolved cache and resolve method**

Add after the existing `tag()` method:

```php
/** @var array<string, mixed> */
private array $resolved = [];

/**
 * Resolve a binding registered via singleton() or bind().
 */
protected function resolve(string $abstract): mixed
{
    if (isset($this->resolved[$abstract])) {
        return $this->resolved[$abstract];
    }

    if (!isset($this->bindings[$abstract])) {
        throw new \RuntimeException("No binding registered for {$abstract}.");
    }

    $binding = $this->bindings[$abstract];
    $concrete = $binding['concrete'];

    $instance = is_callable($concrete) ? $concrete() : new $concrete();

    if ($binding['shared']) {
        $this->resolved[$abstract] = $instance;
    }

    return $instance;
}
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/ServiceProvider/ServiceProviderResolveTest.php`
Expected: 5 tests, all PASS

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass, no regressions

- [ ] **Step 4: Run PHPStan**

Run: `php -d memory_limit=512M ./vendor/bin/phpstan analyse`
Expected: No new errors

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/ServiceProvider/ServiceProvider.php \
       packages/foundation/tests/Unit/ServiceProvider/ServiceProviderResolveTest.php
git commit -m "fix(#295): allow ServiceProvider::commands() to resolve bindings via resolve()"
```
