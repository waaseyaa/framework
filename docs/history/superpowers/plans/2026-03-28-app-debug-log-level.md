# APP_DEBUG & LOG_LEVEL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `APP_DEBUG` (bool) and `LOG_LEVEL` (string) environment variables to Waaseyaa's Foundation package, with a production safety guard that refuses to boot with debug enabled in production.

**Architecture:** Extend `AbstractKernel` with `isDebugMode()` and a boot guard. Add `LogLevel::severity()` for level comparison. Update config files and `.env.example`. `isDevelopmentMode()` moves from `HttpKernel` (private) to `AbstractKernel` (protected) so all kernel subclasses share it.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, Waaseyaa Foundation package

**Issue:** waaseyaa/framework#729

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Modify | `packages/foundation/src/Log/LogLevel.php` | Add `severity(): int` for level comparison |
| Modify | `packages/foundation/src/Kernel/AbstractKernel.php` | Add `isDebugMode()`, `isDevelopmentMode()`, boot guard |
| Modify | `packages/foundation/src/Kernel/HttpKernel.php` | Remove private `isDevelopmentMode()`, use inherited |
| Modify | `config/waaseyaa.php` | Add `debug` and `log_level` keys |
| Modify | `skeleton/config/waaseyaa.php` | Add `debug` and `log_level` keys |
| Modify | `.env.example` | Document `APP_DEBUG` and `LOG_LEVEL` |
| Modify | `composer.json` | Add `APP_DEBUG=true` to `composer dev` script |
| Create | `packages/foundation/tests/Unit/Log/LogLevelSeverityTest.php` | Test severity ordering |
| Create | `packages/foundation/tests/Unit/Kernel/DebugModeTest.php` | Test `isDebugMode()`, `isDevelopmentMode()`, boot guard |

---

### Task 1: Add `LogLevel::severity()` Method

**Files:**
- Modify: `packages/foundation/src/Log/LogLevel.php`
- Create: `packages/foundation/tests/Unit/Log/LogLevelSeverityTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/foundation/tests/Unit/Log/LogLevelSeverityTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(LogLevel::class)]
final class LogLevelSeverityTest extends TestCase
{
    #[Test]
    public function emergency_is_highest_severity(): void
    {
        $this->assertGreaterThan(
            LogLevel::ALERT->severity(),
            LogLevel::EMERGENCY->severity(),
        );
    }

    #[Test]
    public function debug_is_lowest_severity(): void
    {
        $this->assertLessThan(
            LogLevel::INFO->severity(),
            LogLevel::DEBUG->severity(),
        );
    }

    #[Test]
    public function severity_ordering_matches_rfc_5424(): void
    {
        $expected = [
            LogLevel::DEBUG,
            LogLevel::INFO,
            LogLevel::NOTICE,
            LogLevel::WARNING,
            LogLevel::ERROR,
            LogLevel::CRITICAL,
            LogLevel::ALERT,
            LogLevel::EMERGENCY,
        ];

        for ($i = 1; $i < count($expected); $i++) {
            $this->assertGreaterThan(
                $expected[$i - 1]->severity(),
                $expected[$i]->severity(),
                sprintf('%s should be more severe than %s', $expected[$i]->value, $expected[$i - 1]->value),
            );
        }
    }

    #[Test]
    public function from_name_resolves_valid_level(): void
    {
        $this->assertSame(LogLevel::WARNING, LogLevel::fromName('warning'));
        $this->assertSame(LogLevel::DEBUG, LogLevel::fromName('DEBUG'));
        $this->assertSame(LogLevel::ERROR, LogLevel::fromName('Error'));
    }

    #[Test]
    public function from_name_returns_null_for_invalid_level(): void
    {
        $this->assertNull(LogLevel::fromName('invalid'));
        $this->assertNull(LogLevel::fromName(''));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Log/LogLevelSeverityTest.php`
Expected: FAIL — `severity()` and `fromName()` methods do not exist.

- [ ] **Step 3: Implement `severity()` and `fromName()` on LogLevel**

Edit `packages/foundation/src/Log/LogLevel.php` — add two methods to the enum:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

enum LogLevel: string
{
    case EMERGENCY = 'emergency';
    case ALERT = 'alert';
    case CRITICAL = 'critical';
    case ERROR = 'error';
    case WARNING = 'warning';
    case NOTICE = 'notice';
    case INFO = 'info';
    case DEBUG = 'debug';

    /**
     * Numeric severity (higher = more severe). Matches RFC 5424 ordering.
     */
    public function severity(): int
    {
        return match ($this) {
            self::DEBUG     => 0,
            self::INFO      => 1,
            self::NOTICE    => 2,
            self::WARNING   => 3,
            self::ERROR     => 4,
            self::CRITICAL  => 5,
            self::ALERT     => 6,
            self::EMERGENCY => 7,
        };
    }

    /**
     * Resolve a level from its string name (case-insensitive).
     * Returns null if the name is not a valid level.
     */
    public static function fromName(string $name): ?self
    {
        return self::tryFrom(strtolower($name));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Log/LogLevelSeverityTest.php`
Expected: OK (5 tests, 5+ assertions)

- [ ] **Step 5: Commit**

```bash
git add packages/foundation/src/Log/LogLevel.php packages/foundation/tests/Unit/Log/LogLevelSeverityTest.php
git commit -m "feat(foundation): add LogLevel::severity() and fromName() for level comparison (#729)"
```

---

### Task 2: Add `isDebugMode()` and `isDevelopmentMode()` to AbstractKernel

**Files:**
- Modify: `packages/foundation/src/Kernel/AbstractKernel.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php`
- Create: `packages/foundation/tests/Unit/Kernel/DebugModeTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/foundation/tests/Unit/Kernel/DebugModeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;

#[CoversClass(AbstractKernel::class)]
final class DebugModeTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_debug_test_' . uniqid();
        mkdir($this->projectRoot . '/config', 0755, true);
        mkdir($this->projectRoot . '/storage', 0755, true);

        // Clean env before each test.
        putenv('APP_DEBUG');
        putenv('APP_ENV');
        putenv('LOG_LEVEL');
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
        putenv('APP_ENV');
        putenv('LOG_LEVEL');

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    private function writeConfig(array $overrides = []): void
    {
        $config = array_merge(['database' => ':memory:'], $overrides);
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            '<?php return ' . var_export($config, true) . ';',
        );
        file_put_contents(
            $this->projectRoot . '/config/entity-types.php',
            "<?php\nreturn [\n    new \\Waaseyaa\\Entity\\EntityType(\n        id: 'test',\n        label: 'Test',\n        class: \\stdClass::class,\n        keys: ['id' => 'id'],\n    ),\n];",
        );
    }

    #[Test]
    public function debug_mode_defaults_to_false(): void
    {
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function debugMode(): bool
            {
                return $this->isDebugMode();
            }
        };

        $this->assertFalse($kernel->debugMode());
    }

    #[Test]
    public function debug_mode_reads_env_var(): void
    {
        putenv('APP_DEBUG=true');

        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function debugMode(): bool
            {
                return $this->isDebugMode();
            }
        };

        $this->assertTrue($kernel->debugMode());
    }

    #[Test]
    public function debug_mode_reads_config_key(): void
    {
        $this->writeConfig(['debug' => true]);

        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }

            public function debugMode(): bool
            {
                return $this->isDebugMode();
            }
        };
        $kernel->publicBoot();

        $this->assertTrue($kernel->debugMode());
    }

    #[Test]
    public function debug_mode_env_var_trumps_config(): void
    {
        putenv('APP_DEBUG=false');
        $this->writeConfig(['debug' => true]);

        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }

            public function debugMode(): bool
            {
                return $this->isDebugMode();
            }
        };
        $kernel->publicBoot();

        $this->assertFalse($kernel->debugMode());
    }

    #[Test]
    public function development_mode_detects_local_env(): void
    {
        putenv('APP_ENV=local');

        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function devMode(): bool
            {
                return $this->isDevelopmentMode();
            }
        };

        $this->assertTrue($kernel->devMode());
    }

    #[Test]
    public function development_mode_detects_dev_env(): void
    {
        putenv('APP_ENV=dev');

        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function devMode(): bool
            {
                return $this->isDevelopmentMode();
            }
        };

        $this->assertTrue($kernel->devMode());
    }

    #[Test]
    public function development_mode_defaults_to_false(): void
    {
        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function devMode(): bool
            {
                return $this->isDevelopmentMode();
            }
        };

        $this->assertFalse($kernel->devMode());
    }

    #[Test]
    public function boot_guard_refuses_debug_in_production(): void
    {
        putenv('APP_ENV=production');
        putenv('APP_DEBUG=true');
        $this->writeConfig();

        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG must not be enabled in production');
        $kernel->publicBoot();
    }

    #[Test]
    public function boot_allows_debug_in_local_env(): void
    {
        putenv('APP_ENV=local');
        putenv('APP_DEBUG=true');
        $this->writeConfig();

        $kernel = new class($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }

            public function debugMode(): bool
            {
                return $this->isDebugMode();
            }
        };

        $kernel->publicBoot();
        $this->assertTrue($kernel->debugMode());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/DebugModeTest.php`
Expected: FAIL — `isDebugMode()` and `isDevelopmentMode()` do not exist on AbstractKernel.

- [ ] **Step 3: Add `isDebugMode()` and `isDevelopmentMode()` to AbstractKernel**

Edit `packages/foundation/src/Kernel/AbstractKernel.php`.

Add these two methods after the constructor:

```php
    /**
     * Whether debug mode is enabled.
     *
     * Resolution: APP_DEBUG env var (string 'true'/'1') > config 'debug' key > false.
     * Env var always wins over config to allow runtime override.
     */
    protected function isDebugMode(): bool
    {
        $envValue = getenv('APP_DEBUG');
        if (is_string($envValue) && $envValue !== '') {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var($this->config['debug'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the application is running in a development environment.
     *
     * Resolution: config 'environment' key > APP_ENV env var > '' (not dev).
     */
    protected function isDevelopmentMode(): bool
    {
        $env = $this->config['environment'] ?? getenv('APP_ENV') ?: '';
        if (!is_string($env)) {
            return false;
        }

        return in_array(strtolower($env), ['dev', 'development', 'local'], true);
    }
```

Add the boot guard at the beginning of `boot()`, after `EnvLoader::load()` and `ConfigLoader::load()` but before the rest of boot:

```php
    protected function boot(): void
    {
        if ($this->booted) {
            return;
        }

        EnvLoader::load($this->projectRoot . '/.env');

        $this->config = ConfigLoader::load($this->projectRoot . '/config/waaseyaa.php');

        // Safety guard: refuse to boot with debug enabled in production.
        if ($this->isDebugMode() && !$this->isDevelopmentMode()) {
            $env = $this->config['environment'] ?? getenv('APP_ENV') ?: 'production';
            throw new \RuntimeException(
                sprintf('APP_DEBUG must not be enabled in production (APP_ENV=%s). Aborting boot.', $env),
            );
        }

        $this->dispatcher         = new EventDispatcher();
        // ... rest unchanged
```

- [ ] **Step 4: Remove `isDevelopmentMode()` from HttpKernel**

Edit `packages/foundation/src/Kernel/HttpKernel.php`.

Delete the private `isDevelopmentMode()` method (lines 293–301). The inherited `protected isDevelopmentMode()` from `AbstractKernel` has identical logic, so all call sites (`handleCors()`, `shouldUseDevFallbackAccount()`) continue to work.

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/DebugModeTest.php`
Expected: OK (9 tests, 9 assertions)

- [ ] **Step 6: Run existing kernel tests to verify no regression**

Run: `./vendor/bin/phpunit packages/foundation/tests/Unit/Kernel/`
Expected: All tests pass (no regression from moving `isDevelopmentMode()`).

- [ ] **Step 7: Commit**

```bash
git add packages/foundation/src/Kernel/AbstractKernel.php packages/foundation/src/Kernel/HttpKernel.php packages/foundation/tests/Unit/Kernel/DebugModeTest.php
git commit -m "feat(foundation): add isDebugMode(), move isDevelopmentMode() to AbstractKernel, add production boot guard (#729)"
```

---

### Task 3: Update Config Files and `.env.example`

**Files:**
- Modify: `config/waaseyaa.php`
- Modify: `skeleton/config/waaseyaa.php`
- Modify: `.env.example`
- Modify: `composer.json`

- [ ] **Step 1: Add `debug` and `log_level` keys to `config/waaseyaa.php`**

Add after the opening `return [` and before the `'database'` key:

```php
    // Debug mode. Controls error detail display, debug toolbar, and debug headers.
    // Override with APP_DEBUG env var. MUST be false in production.
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),

    // Minimum log level for the default log handler.
    // Override with LOG_LEVEL env var. Values: debug, info, notice, warning, error, critical, alert, emergency.
    'log_level' => getenv('LOG_LEVEL') ?: 'warning',

    // Application environment. Controls dev-only features (fallback account, CORS relaxation).
    // Override with APP_ENV env var. Values: local, dev, development, staging, production.
    'environment' => getenv('APP_ENV') ?: 'production',
```

- [ ] **Step 2: Add same keys to `skeleton/config/waaseyaa.php`**

Add the same three keys (`debug`, `log_level`, `environment`) at the top of the return array in `skeleton/config/waaseyaa.php`, with identical code.

- [ ] **Step 3: Update `.env.example`**

Add below the `APP_ENV=local` line:

```
# Debug mode: enables detailed error pages, debug toolbar, and debug headers.
# MUST be false in production — the kernel refuses to boot if APP_DEBUG=true
# when APP_ENV is not local/dev/development.
# Default: false
#
APP_DEBUG=true

# Minimum log level for the default log handler.
# Values: debug, info, notice, warning, error, critical, alert, emergency
# Default: warning
#
# LOG_LEVEL=warning
```

- [ ] **Step 4: Add `APP_DEBUG=true` to `composer dev` script**

Edit `composer.json`. In the `scripts.dev` array, update the shell command to include `APP_DEBUG=true`:

Change:
```
APP_ENV=local WAASEYAA_DEV_FALLBACK_ACCOUNT=true PHP_CLI_SERVER_WORKERS=4 php -S localhost:8081 -t public
```
To:
```
APP_ENV=local APP_DEBUG=true WAASEYAA_DEV_FALLBACK_ACCOUNT=true PHP_CLI_SERVER_WORKERS=4 php -S localhost:8081 -t public
```

- [ ] **Step 5: Run full test suite to verify no regression**

Run: `./vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add config/waaseyaa.php skeleton/config/waaseyaa.php .env.example composer.json
git commit -m "feat(foundation): add APP_DEBUG and LOG_LEVEL to config, .env.example, and composer dev script (#729)"
```

---

### Task 4: Run Code Quality Checks

**Files:** None (verification only)

- [ ] **Step 1: Run code style check**

Run: `composer cs-check`
Expected: No violations. If there are violations, run `composer cs-fix` and include fixes in a follow-up commit.

- [ ] **Step 2: Run static analysis**

Run: `composer phpstan`
Expected: No new errors.

- [ ] **Step 3: Run full test suite one final time**

Run: `./vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 4: Fix any issues and commit if needed**

If cs-fix or phpstan found issues:

```bash
git add -u
git commit -m "chore(foundation): fix code style and static analysis issues (#729)"
```
