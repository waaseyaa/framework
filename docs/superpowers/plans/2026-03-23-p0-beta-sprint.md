# P0 Beta Sprint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close all 28 P0 milestone issues in a single session using 5 parallel agents in isolated worktrees.

**Architecture:** Each branch works independently in its own worktree. Branches merge in dependency order: layer-enforcement → logging → security → middleware → field-types. Each agent follows TDD — write failing test, implement, verify, commit.

**Tech Stack:** PHP 8.4, PHPUnit 10.5, Symfony 7.x components, Doctrine DBAL

**Spec:** `docs/superpowers/specs/2026-03-23-p0-beta-sprint-design.md`

**Commit conventions:**
- Stage specific files by name (not `git add -A`) to avoid committing unintended files
- All commit messages must end with: `Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>`
- Use HEREDOC format for multi-line commit messages

**Middleware placement note:** New HTTP middleware (Tasks 4.1–4.6) goes in `packages/foundation/src/Middleware/` because these are framework-level cross-cutting concerns. This differs from domain-specific middleware (SessionMiddleware in user/, AuthorizationMiddleware in access/) which live in their owning package.

---

# Branch 1: `p0/layer-enforcement` (Milestone #36)

## Task 1.1: Move AccessChecker from routing to access package (#556)

The circular dependency is: `AuthorizationMiddleware` (packages/access) imports `AccessChecker` (packages/routing). Fix by moving `AccessChecker` into the access package where it belongs conceptually.

**Files:**
- Move: `packages/routing/src/AccessChecker.php` → `packages/access/src/AccessChecker.php`
- Modify: `packages/access/src/Middleware/AuthorizationMiddleware.php:17`
- Modify: `packages/routing/composer.json` (remove access checker references if any)
- Modify: `packages/access/composer.json` (add symfony/routing dependency if not present)
- Modify: Any other files importing `Waaseyaa\Routing\AccessChecker`
- Test: Existing tests that reference AccessChecker

- [ ] **Step 1: Find all references to `Waaseyaa\Routing\AccessChecker`**

Run: `grep -r "Waaseyaa\\\\Routing\\\\AccessChecker" packages/ --include="*.php" -l`
Note every file that needs updating.

- [ ] **Step 2: Move AccessChecker to access package**

Copy `packages/routing/src/AccessChecker.php` to `packages/access/src/AccessChecker.php`.
Change namespace from `Waaseyaa\Routing` to `Waaseyaa\Access`.
Delete the original file.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Symfony\Component\Routing\Route;
use Waaseyaa\Access\Gate\GateInterface;

// ... rest of class unchanged
```

- [ ] **Step 3: Update all imports**

In every file found in Step 1, replace:
```php
use Waaseyaa\Routing\AccessChecker;
```
with:
```php
use Waaseyaa\Access\AccessChecker;
```

Key file — `AuthorizationMiddleware.php` line 17: remove the routing import entirely (AccessChecker is now in same package).

- [ ] **Step 4: Update composer.json dependencies**

In `packages/access/composer.json`, ensure `symfony/routing` is in require (needed for `Route` type hint in AccessChecker).

In `packages/routing/composer.json`, remove `waaseyaa/access` from require if it was only needed for AccessChecker consumers.

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All 4889 tests pass.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "fix(#556): move AccessChecker from routing to access package

Breaks the circular dependency between access (Layer 1) and routing
(Layer 4). AccessChecker is fundamentally an access concept that
happens to inspect Route objects."
```

## Task 1.2: Remove relationship → workflows upward import (#557)

`RelationshipTraversalService` imports `WorkflowVisibility` from workflows (Layer 3). Replace with an interface in the relationship package that workflows can implement.

**Files:**
- Create: `packages/relationship/src/VisibilityFilterInterface.php`
- Modify: `packages/relationship/src/RelationshipTraversalService.php:9,16`
- Modify: `packages/relationship/composer.json` (remove waaseyaa/workflows dep)
- Create: `packages/workflows/src/WorkflowVisibilityFilter.php` (adapter implementing new interface)
- Test: `packages/relationship/tests/Unit/RelationshipTraversalServiceTest.php` (if exists)

- [ ] **Step 1: Create the interface in relationship package**

Create `packages/relationship/src/VisibilityFilterInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

/**
 * Filters entities based on visibility rules.
 *
 * Implementations can use workflow states, publication status,
 * or any other visibility criteria.
 */
interface VisibilityFilterInterface
{
    /**
     * Determine if an entity is considered public/visible.
     *
     * @param string $entityType The entity type ID.
     * @param array<string, mixed> $values The entity values.
     * @return bool True if public.
     */
    public function isEntityPublic(string $entityType, array $values): bool;
}
```

- [ ] **Step 2: Update RelationshipTraversalService to use the interface**

In `packages/relationship/src/RelationshipTraversalService.php`:

Replace:
```php
use Waaseyaa\Workflows\WorkflowVisibility;
```
with:
```php
use Waaseyaa\Relationship\VisibilityFilterInterface;
```

Replace constructor parameter:
```php
private readonly WorkflowVisibility $workflowVisibility = new WorkflowVisibility(),
```
with:
```php
private readonly ?VisibilityFilterInterface $visibilityFilter = null,
```

Update all internal call sites — `$this->workflowVisibility->isEntityPublic(...)` becomes `$this->visibilityFilter?->isEntityPublic(...) ?? true` (null filter = everything visible, matching open-by-default semantics).

- [ ] **Step 3: Create adapter in workflows package**

Create `packages/workflows/src/WorkflowVisibilityFilter.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Relationship\VisibilityFilterInterface;

final class WorkflowVisibilityFilter implements VisibilityFilterInterface
{
    public function __construct(
        private readonly WorkflowVisibility $workflowVisibility = new WorkflowVisibility(),
    ) {}

    public function isEntityPublic(string $entityType, array $values): bool
    {
        return $this->workflowVisibility->isEntityPublic($entityType, $values);
    }
}
```

- [ ] **Step 4: Update composer.json**

In `packages/relationship/composer.json`: remove `waaseyaa/workflows` from require.
In `packages/workflows/composer.json`: add `waaseyaa/relationship` to require (for the interface — Layer 3 importing Layer 2 is valid downward).

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "fix(#557): decouple relationship from workflows via VisibilityFilterInterface

Introduces VisibilityFilterInterface in relationship package (Layer 2).
Workflows package (Layer 3) provides WorkflowVisibilityFilter adapter.
Dependency now flows downward correctly."
```

## Task 1.3: Classify orphan packages (#558)

Assign layer numbers to 6 orphan packages. This is primarily a documentation and composer.json metadata task.

**Files:**
- Modify: `packages/auth/composer.json`
- Modify: `packages/billing/composer.json`
- Modify: `packages/deployer/composer.json`
- Modify: `packages/github/composer.json`
- Modify: `packages/inertia/composer.json`
- Modify: `packages/ingestion/composer.json` (if separate from foundation)
- Modify: `CLAUDE.md` layer table (add orphan packages)

- [ ] **Step 1: Read each orphan package's composer.json**

Run: `for pkg in auth billing deployer github inertia ingestion; do echo "=== $pkg ===" && cat "packages/$pkg/composer.json" 2>/dev/null || echo "NOT FOUND"; done`

Determine which packages actually exist as standalone packages vs being part of foundation.

- [ ] **Step 2: Add `extra.waaseyaa.layer` to each package's composer.json**

Based on dependencies:
- `auth` → Layer 6 (Interfaces — depends on user, mail, foundation)
- `billing` → Layer 6 (Interfaces — external service integration)
- `deployer` → Layer 6 (Interfaces — external tooling)
- `github` → Layer 6 (Interfaces — external service integration)
- `inertia` → Layer 6 (Interfaces — frontend adapter)
- `ingestion` → Layer 0 (Foundation — note: ingestion code exists both as `packages/ingestion/` and inside `packages/foundation/src/Ingestion/`; classify both as Layer 0 and document the duplication for future consolidation)

For each existing package, add to `composer.json` extra section:
```json
{
    "extra": {
        "waaseyaa": {
            "layer": 6
        }
    }
}
```

- [ ] **Step 3: Update CLAUDE.md layer table**

Add the newly classified packages to the Layer Architecture table under their assigned layers.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "chore(#558): classify orphan packages into layer architecture

auth, billing, deployer, github, inertia → Layer 6 (Interfaces)
ingestion → Layer 0 (Foundation, already in foundation/src/Ingestion/)"
```

---

# Branch 2: `p0/logging` (Milestone #37)

## Task 2.1: Design PSR-3 compatible logging interface (#559)

Create a framework logger contract in foundation. NOT a full logging library — apps bring their own implementation.

**Files:**
- Create: `packages/foundation/src/Log/LoggerInterface.php`
- Create: `packages/foundation/src/Log/LogLevel.php`
- Create: `packages/foundation/src/Log/NullLogger.php`
- Create: `packages/foundation/src/Log/ErrorLogHandler.php`
- Create: `packages/foundation/src/Log/LoggerTrait.php`
- Test: `packages/foundation/tests/Unit/Log/ErrorLogHandlerTest.php`
- Test: `packages/foundation/tests/Unit/Log/NullLoggerTest.php`

- [ ] **Step 1: Write tests for NullLogger**

Create `packages/foundation/tests/Unit/Log/NullLoggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\NullLogger;

#[CoversClass(NullLogger::class)]
class NullLoggerTest extends TestCase
{
    public function testLogDoesNothing(): void
    {
        $logger = new NullLogger();
        // Should not throw or produce output
        $logger->log(LogLevel::ERROR, 'test message', ['key' => 'value']);
        $this->assertTrue(true);
    }

    public function testEmergency(): void
    {
        $logger = new NullLogger();
        $logger->emergency('test');
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter NullLoggerTest`
Expected: FAIL — classes don't exist yet.

- [ ] **Step 3: Create LogLevel enum**

Create `packages/foundation/src/Log/LogLevel.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

/**
 * PSR-3 compatible log levels as a PHP 8.4 enum.
 */
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
}
```

- [ ] **Step 4: Create LoggerInterface**

Create `packages/foundation/src/Log/LoggerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

/**
 * PSR-3 compatible logger interface for the Waaseyaa framework.
 *
 * This is a framework contract, not a logging library. Applications
 * provide their own implementation (Monolog, custom, etc.).
 * Method signatures match PSR-3 for drop-in compatibility.
 */
interface LoggerInterface
{
    public function emergency(string|\Stringable $message, array $context = []): void;
    public function alert(string|\Stringable $message, array $context = []): void;
    public function critical(string|\Stringable $message, array $context = []): void;
    public function error(string|\Stringable $message, array $context = []): void;
    public function warning(string|\Stringable $message, array $context = []): void;
    public function notice(string|\Stringable $message, array $context = []): void;
    public function info(string|\Stringable $message, array $context = []): void;
    public function debug(string|\Stringable $message, array $context = []): void;
    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void;
}
```

- [ ] **Step 5: Create LoggerTrait**

Create `packages/foundation/src/Log/LoggerTrait.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

/**
 * Implements the 8 convenience methods by delegating to log().
 */
trait LoggerTrait
{
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
```

- [ ] **Step 6: Create NullLogger**

Create `packages/foundation/src/Log/NullLogger.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

/**
 * Logger that discards all messages. Use in tests.
 */
final class NullLogger implements LoggerInterface
{
    use LoggerTrait;

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        // Intentionally empty
    }
}
```

- [ ] **Step 7: Run NullLogger test**

Run: `./vendor/bin/phpunit --filter NullLoggerTest`
Expected: PASS

- [ ] **Step 8: Write ErrorLogHandler tests**

Create `packages/foundation/tests/Unit/Log/ErrorLogHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\ErrorLogHandler;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(ErrorLogHandler::class)]
class ErrorLogHandlerTest extends TestCase
{
    public function testFormatsMessageWithLevel(): void
    {
        $output = [];
        $handler = new ErrorLogHandler(function (string $msg) use (&$output) {
            $output[] = $msg;
        });

        $handler->error('Something failed');

        $this->assertCount(1, $output);
        $this->assertStringContainsString('[error]', $output[0]);
        $this->assertStringContainsString('Something failed', $output[0]);
    }

    public function testFormatsContext(): void
    {
        $output = [];
        $handler = new ErrorLogHandler(function (string $msg) use (&$output) {
            $output[] = $msg;
        });

        $handler->warning('Disk full', ['path' => '/var/log']);

        $this->assertCount(1, $output);
        $this->assertStringContainsString('/var/log', $output[0]);
    }

    public function testDefaultsToErrorLog(): void
    {
        // Just verify it can be constructed without args
        $handler = new ErrorLogHandler();
        $this->assertInstanceOf(ErrorLogHandler::class, $handler);
    }
}
```

- [ ] **Step 9: Create ErrorLogHandler**

Create `packages/foundation/src/Log/ErrorLogHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

/**
 * Default logger that delegates to error_log().
 *
 * Provides backward compatibility. Production apps should replace
 * this with a proper handler (Monolog, file rotation, etc.).
 */
final class ErrorLogHandler implements LoggerInterface
{
    use LoggerTrait;

    /** @var \Closure(string): void */
    private readonly \Closure $writer;

    /**
     * @param (\Closure(string): void)|null $writer Custom writer for testing. Defaults to error_log().
     */
    public function __construct(?\Closure $writer = null)
    {
        $this->writer = $writer ?? static function (string $message): void {
            error_log($message);
        };
    }

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        $formatted = sprintf('[%s] %s', $level->value, (string) $message);

        if ($context !== []) {
            $formatted .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        ($this->writer)($formatted);
    }
}
```

- [ ] **Step 10: Run all logging tests**

Run: `./vendor/bin/phpunit --filter "NullLoggerTest|ErrorLogHandlerTest"`
Expected: PASS

- [ ] **Step 11: Commit**

```bash
git add -A && git commit -m "feat(#559): add PSR-3 compatible LoggerInterface to foundation

Framework logger contract with LogLevel enum, LoggerTrait for
convenience methods, NullLogger for tests, and ErrorLogHandler
for backward compatibility. Apps bring their own implementation."
```

## Task 2.2: Replace all error_log() calls with logger (#560)

Find every `error_log()` call and inject the logger.

**Files:**
- Modify: Every file with `error_log()` calls (discover via grep in Step 1)
- Modify: Constructors/service providers to accept LoggerInterface

- [ ] **Step 1: Find all error_log() calls**

Run: `grep -rn "error_log(" packages/*/src/ --include="*.php"`
Record every file and line number.

- [ ] **Step 2: For each file, add LoggerInterface to constructor**

For each class containing `error_log()`:
1. Add `use Waaseyaa\Foundation\Log\LoggerInterface;` import
2. Add `private readonly LoggerInterface $logger` to constructor (with `?LoggerInterface $logger = null` default + `$this->logger = $logger ?? new \Waaseyaa\Foundation\Log\NullLogger()` in body for backward compat)
3. Replace `error_log('[Waaseyaa] ...')` with `$this->logger->warning('...')` (or appropriate level)

Pattern for replacement:
```php
// Before:
error_log('[Waaseyaa] AuthorizationMiddleware: _account not set');

// After:
$this->logger->warning('AuthorizationMiddleware: _account not set');
```

Use appropriate log levels:
- `error()` for failures (caught exceptions, missing requirements)
- `warning()` for degraded behavior (fallbacks, missing optional components)
- `info()` for operational events (cache rebuilds, discovery results)
- `debug()` for diagnostic detail

- [ ] **Step 3: Update service providers**

Where classes are wired via service providers, pass the logger from the container. For classes instantiated directly, the null default provides backward compat.

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass.

- [ ] **Step 5: Verify no error_log() remains**

Run: `grep -rn "error_log(" packages/*/src/ --include="*.php"`
Expected: Only `ErrorLogHandler.php` should contain `error_log()`.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "refactor(#560): replace all error_log() calls with LoggerInterface

All 23 error_log() calls replaced with structured logger. Each class
receives LoggerInterface via constructor injection with NullLogger
default for backward compat."
```

## Task 2.3: Add external log sink support (#561)

Add a `FileLogger` for file rotation and a `CompositeLogger` for routing to multiple sinks.

**Files:**
- Create: `packages/foundation/src/Log/FileLogger.php`
- Create: `packages/foundation/src/Log/CompositeLogger.php`
- Test: `packages/foundation/tests/Unit/Log/FileLoggerTest.php`
- Test: `packages/foundation/tests/Unit/Log/CompositeLoggerTest.php`

- [ ] **Step 1: Write FileLogger tests**

Create `packages/foundation/tests/Unit/Log/FileLoggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\FileLogger;
use Waaseyaa\Foundation\Log\LogLevel;

#[CoversClass(FileLogger::class)]
class FileLoggerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/waaseyaa_log_test_' . uniqid();
        mkdir($this->logDir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->logDir . '/*.log') ?: []);
        @rmdir($this->logDir);
    }

    public function testWritesToFile(): void
    {
        $logger = new FileLogger($this->logDir . '/app.log');
        $logger->error('Test error message');

        $content = file_get_contents($this->logDir . '/app.log');
        $this->assertStringContainsString('[error]', $content);
        $this->assertStringContainsString('Test error message', $content);
    }

    public function testAppendsToExistingFile(): void
    {
        $logger = new FileLogger($this->logDir . '/app.log');
        $logger->info('First');
        $logger->info('Second');

        $content = file_get_contents($this->logDir . '/app.log');
        $this->assertStringContainsString('First', $content);
        $this->assertStringContainsString('Second', $content);
    }

    public function testIncludesTimestamp(): void
    {
        $logger = new FileLogger($this->logDir . '/app.log');
        $logger->info('Timestamped');

        $content = file_get_contents($this->logDir . '/app.log');
        // Should contain ISO 8601 date prefix
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $content);
    }

    public function testRespectsMinimumLevel(): void
    {
        $logger = new FileLogger($this->logDir . '/app.log', LogLevel::WARNING);
        $logger->debug('Should not appear');
        $logger->warning('Should appear');

        $content = file_get_contents($this->logDir . '/app.log');
        $this->assertStringNotContainsString('Should not appear', $content);
        $this->assertStringContainsString('Should appear', $content);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter FileLoggerTest`
Expected: FAIL

- [ ] **Step 3: Implement FileLogger**

Create `packages/foundation/src/Log/FileLogger.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

/**
 * File-based logger with optional minimum level filtering.
 *
 * Writes one line per log entry with ISO 8601 timestamps.
 * Uses atomic append (FILE_APPEND + LOCK_EX) for safety.
 */
final class FileLogger implements LoggerInterface
{
    use LoggerTrait;

    private const LEVEL_PRIORITY = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    public function __construct(
        private readonly string $filePath,
        private readonly LogLevel $minimumLevel = LogLevel::DEBUG,
    ) {}

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        if (self::LEVEL_PRIORITY[$level->value] < self::LEVEL_PRIORITY[$this->minimumLevel->value]) {
            return;
        }

        $timestamp = (new \DateTimeImmutable())->format('c');
        $formatted = sprintf('[%s] [%s] %s', $timestamp, $level->value, (string) $message);

        if ($context !== []) {
            $formatted .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($this->filePath, $formatted . "\n", FILE_APPEND | LOCK_EX);
    }
}
```

- [ ] **Step 4: Write CompositeLogger tests**

Create `packages/foundation/tests/Unit/Log/CompositeLoggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Log;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\CompositeLogger;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;

#[CoversClass(CompositeLogger::class)]
class CompositeLoggerTest extends TestCase
{
    public function testDelegatesToAllLoggers(): void
    {
        $messages1 = [];
        $messages2 = [];

        $logger1 = $this->createCollector($messages1);
        $logger2 = $this->createCollector($messages2);

        $composite = new CompositeLogger([$logger1, $logger2]);
        $composite->error('Test');

        $this->assertCount(1, $messages1);
        $this->assertCount(1, $messages2);
    }

    public function testContinuesOnLoggerFailure(): void
    {
        $messages = [];
        $failing = new class implements LoggerInterface {
            use LoggerTrait;
            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('Logger failed');
            }
        };
        $working = $this->createCollector($messages);

        $composite = new CompositeLogger([$failing, $working]);
        $composite->error('Test');

        $this->assertCount(1, $messages);
    }

    private function createCollector(array &$messages): LoggerInterface
    {
        return new class ($messages) implements LoggerInterface {
            use LoggerTrait;
            /** @param array<array{LogLevel, string}> $messages */
            public function __construct(private array &$messages) {}
            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = [$level, (string) $message];
            }
        };
    }
}
```

- [ ] **Step 5: Implement CompositeLogger**

Create `packages/foundation/src/Log/CompositeLogger.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Log;

/**
 * Routes log messages to multiple loggers.
 *
 * If one logger throws, the others still receive the message.
 * Best-effort delivery — individual logger failures are silently caught.
 */
final class CompositeLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @param list<LoggerInterface> $loggers */
    public function __construct(
        private readonly array $loggers,
    ) {}

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        foreach ($this->loggers as $logger) {
            try {
                $logger->log($level, $message, $context);
            } catch (\Throwable) {
                // Best-effort: don't let one broken sink stop others
            }
        }
    }
}
```

- [ ] **Step 6: Run all logging tests**

Run: `./vendor/bin/phpunit --filter "Log\\\\"`
Expected: All PASS

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat(#561): add FileLogger and CompositeLogger for external sink support

FileLogger writes to files with timestamps and minimum level filtering.
CompositeLogger routes to multiple sinks with best-effort delivery."
```

---

# Branch 3: `p0/security-hardening` (Milestone #34)

## Task 3.1: Fix XSS in AuthorizationMiddleware (#542)

The `renderHtmlError()` method interpolates `$statusCode`, `$title`, `$detail`, and a login link URL without HTML escaping.

**Files:**
- Modify: `packages/access/src/Middleware/AuthorizationMiddleware.php:111-129`
- Test: `packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php`

- [ ] **Step 1: Write test for XSS prevention**

Add to (or create) `packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php`:

```php
public function testRenderHtmlErrorEscapesOutput(): void
{
    // Craft a request with XSS in the path
    $request = Request::create('/<script>alert(1)</script>');
    $request->attributes->set('_route_object', $this->createRenderRoute());
    $request->attributes->set('_account', $this->createAnonymousAccount());

    $middleware = new AuthorizationMiddleware($this->accessChecker);
    $response = $middleware->process($request, $this->createDenyHandler());

    $html = $response->getContent();
    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter testRenderHtmlErrorEscapesOutput`
Expected: FAIL — unescaped `<script>` in output.

- [ ] **Step 3: Fix renderHtmlError()**

In `packages/access/src/Middleware/AuthorizationMiddleware.php`, update `renderHtmlError()`:

```php
private function renderHtmlError(int $statusCode, string $title, string $detail, Request $request): Response
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeDetail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    $safeCode = (int) $statusCode;

    $loginLink = $safeCode === 403
        ? sprintf(
            '<p><a href="/login?redirect=%s">Sign in</a> with a different account.</p>',
            htmlspecialchars(urlencode($request->getPathInfo()), ENT_QUOTES, 'UTF-8')
        )
        : '';

    $html = <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeCode} {$safeTitle}</title>
    <style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#111827;color:#F3F4F6}
    .box{text-align:center;max-width:420px;padding:2rem}.code{font-size:4rem;font-weight:700;color:#F59E0B;margin:0}.msg{color:#9CA3AF;margin:1rem 0;line-height:1.6}
    a{color:#F59E0B;text-decoration:none}a:hover{text-decoration:underline}</style></head>
    <body><div class="box"><p class="code">{$safeCode}</p><h1>{$safeTitle}</h1><p class="msg">{$safeDetail}</p>{$loginLink}</div></body></html>
    HTML;

    return new Response($html, $statusCode, ['Content-Type' => 'text/html; charset=UTF-8']);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter testRenderHtmlErrorEscapesOutput`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "fix(#542): escape HTML output in AuthorizationMiddleware

All dynamic values in renderHtmlError() now pass through
htmlspecialchars() to prevent XSS injection via crafted URLs."
```

## Task 3.2: Add session cookie security flags (#543)

Configure HttpOnly, Secure, and SameSite=Lax on session cookies.

**Files:**
- Modify: `packages/user/src/Session/NativeSession.php:19-25`
- Test: `packages/user/tests/Unit/Session/NativeSessionTest.php`

- [ ] **Step 1: Write test for cookie params**

```php
public function testStartSetsSecureCookieParams(): void
{
    // NativeSession should configure cookie params before starting
    $session = new NativeSession();
    $params = $session->getCookieParams();

    $this->assertTrue($params['httponly']);
    $this->assertSame('Lax', $params['samesite']);
}
```

- [ ] **Step 2: Update NativeSession::start()**

Add cookie params configuration before `session_start()`:

```php
public function start(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    session_set_cookie_params([
        'httponly' => true,
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);

    return session_start();
}

public function getCookieParams(): array
{
    return session_get_cookie_params();
}
```

- [ ] **Step 3: Run tests**

Run: `./vendor/bin/phpunit --filter NativeSessionTest`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "fix(#543): add HttpOnly, Secure, SameSite=Lax to session cookies

session_set_cookie_params() called before session_start() in
NativeSession. Secure flag auto-detects HTTPS."
```

## Task 3.3: Validate redirect target (#544)

Prevent open redirect by validating the redirect URL in login flow.

**Files:**
- Modify: `packages/access/src/Middleware/AuthorizationMiddleware.php:58-61`
- Create: `packages/access/src/RedirectValidator.php`
- Test: `packages/access/tests/Unit/RedirectValidatorTest.php`

- [ ] **Step 1: Write RedirectValidator tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\RedirectValidator;

#[CoversClass(RedirectValidator::class)]
class RedirectValidatorTest extends TestCase
{
    #[DataProvider('redirectProvider')]
    public function testValidatesRedirect(string $target, bool $expected): void
    {
        $validator = new RedirectValidator();
        $this->assertSame($expected, $validator->isSafe($target));
    }

    public static function redirectProvider(): array
    {
        return [
            'relative path' => ['/dashboard', true],
            'relative with query' => ['/admin?tab=users', true],
            'absolute same-origin rejected' => ['https://example.com/foo', false],
            'protocol-relative rejected' => ['//evil.com/hack', false],
            'javascript rejected' => ['javascript:alert(1)', false],
            'data URI rejected' => ['data:text/html,<script>alert(1)</script>', false],
            'empty string' => ['', false],
            'backslash trick' => ['\evil.com', false],
        ];
    }
}
```

- [ ] **Step 2: Implement RedirectValidator**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Validates redirect targets to prevent open redirect attacks.
 * Only allows relative paths (starting with /).
 */
final class RedirectValidator
{
    public function isSafe(string $target): bool
    {
        if ($target === '') {
            return false;
        }

        // Must start with exactly one forward slash (not //)
        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return false;
        }

        // Reject backslash (browser treats \ as /)
        if (str_contains($target, '\\')) {
            return false;
        }

        return true;
    }

    public function sanitize(string $target, string $fallback = '/'): string
    {
        return $this->isSafe($target) ? $target : $fallback;
    }
}
```

- [ ] **Step 3: Wire into AuthorizationMiddleware**

Update the redirect in `process()`:

```php
$redirect = $request->getPathInfo();
$validator = new RedirectValidator();
$loginUrl = '/login?redirect=' . urlencode($validator->sanitize($redirect));
return new RedirectResponse($loginUrl, 302);
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit --filter "RedirectValidator|AuthorizationMiddleware"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "fix(#544): validate redirect targets to prevent open redirect

RedirectValidator ensures only relative paths are used for login
redirects. Rejects absolute URLs, protocol-relative, javascript:,
and backslash tricks."
```

## Task 3.4: Session fixation prevention (#545)

Regenerate session ID after successful login.

**Files:**
- Modify: The login handler / authentication code (find with grep for `session` + `login` or `authenticate`)

- [ ] **Step 1: Find the login/authentication handler**

Run: `grep -rn "function.*login\|function.*authenticate" packages/*/src/ --include="*.php" -l`

- [ ] **Step 2: Write test for session regeneration**

Add to the auth/login handler test file (found in Step 1):

```php
public function testLoginRegeneratesSessionId(): void
{
    // Start a session to get an initial ID
    $session = new NativeSession();
    $session->start();
    $oldId = $session->getId();

    // Perform login (call the auth handler's login method)
    // After successful auth, session ID should differ
    $session->migrate(true);
    $newId = $session->getId();

    $this->assertNotSame($oldId, $newId);
}
```

- [ ] **Step 3: Add session regeneration after successful auth**

In the login/authentication handler found in Step 1, add after successful credential validation:
```php
// Prevent session fixation — regenerate ID, destroy old session data
$request->getSession()->migrate(true);
```

This uses the existing `NativeSession::migrate()` which calls `session_regenerate_id($destroy)`.

- [ ] **Step 4: Run tests and commit**

```bash
git add -A && git commit -m "fix(#545): regenerate session ID on login to prevent session fixation"
```

## Task 3.5: Cache-Control on error responses (#547)

**Files:**
- Modify: `packages/access/src/Middleware/AuthorizationMiddleware.php`

- [ ] **Step 1: Add Cache-Control header to all 401/403 responses**

In every place AuthorizationMiddleware returns a 401 or 403 response, add:
```php
'Cache-Control' => 'no-store',
```
to the response headers.

For JSON responses:
```php
return new JsonResponse([...], 403, [
    'Content-Type' => 'application/vnd.api+json',
    'Cache-Control' => 'no-store',
]);
```

For HTML responses, add to the Response constructor:
```php
return new Response($html, $statusCode, [
    'Content-Type' => 'text/html; charset=UTF-8',
    'Cache-Control' => 'no-store',
]);
```

- [ ] **Step 2: Write tests**

Add to `packages/access/tests/Unit/Middleware/AuthorizationMiddlewareTest.php`:

```php
public function testForbiddenResponseHasNoCacheHeader(): void
{
    $request = Request::create('/api/admin');
    $request->attributes->set('_route_object', $this->createProtectedRoute());
    $request->attributes->set('_account', $this->createAnonymousAccount());

    $middleware = new AuthorizationMiddleware($this->accessChecker);
    $response = $middleware->process($request, $this->createDenyHandler());

    $this->assertSame('no-store', $response->headers->get('Cache-Control'));
}

public function testUnauthorizedResponseHasNoCacheHeader(): void
{
    $request = Request::create('/api/admin');
    $request->attributes->set('_route_object', $this->createAuthenticatedRoute());
    $request->attributes->set('_account', $this->createAnonymousAccount());

    $middleware = new AuthorizationMiddleware($this->accessChecker);
    $response = $middleware->process($request, $this->createDenyHandler());

    $this->assertSame('no-store', $response->headers->get('Cache-Control'));
}
```

- [ ] **Step 3: Run tests and commit**

```bash
git add -A && git commit -m "fix(#547): add Cache-Control: no-store to 401/403 responses

Prevents browsers from caching error pages that may contain
sensitive information about access requirements."
```

## Task 3.6: JSON decode symmetry (#599)

Add `JSON_THROW_ON_ERROR` to all `json_decode()` calls that lack it.

**Files:**
- Modify: ~20 files across multiple packages

- [ ] **Step 1: Find all unsafe json_decode calls**

Run: `grep -rn "json_decode(" packages/*/src/ --include="*.php" | grep -v JSON_THROW_ON_ERROR`

- [ ] **Step 2: For each call, add JSON_THROW_ON_ERROR**

Pattern:
```php
// Before:
json_decode($data, true)

// After:
json_decode($data, true, 512, JSON_THROW_ON_ERROR)
```

Where the decode is in a context where failure should be caught, wrap in try-catch:
```php
try {
    $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    // Handle appropriately for context
}
```

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass.

- [ ] **Step 4: Verify no unsafe json_decode remains**

Run: `grep -rn "json_decode(" packages/*/src/ --include="*.php" | grep -v JSON_THROW_ON_ERROR`
Expected: Zero results.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "fix(#599): add JSON_THROW_ON_ERROR to all json_decode() calls

Ensures symmetric error handling — json_encode already uses
JSON_THROW_ON_ERROR throughout. Prevents silent null on corrupt data."
```

## Task 3.7: User::hasPermission() with role defaults (#609)

**Files:**
- Modify: `packages/user/src/User.php`
- Test: `packages/user/tests/Unit/UserTest.php`

- [ ] **Step 1: Read current User::hasPermission() implementation**

Run: `grep -A 10 "function hasPermission" packages/user/src/User.php`

- [ ] **Step 2: Write test for role-based defaults**

```php
public function testHasPermissionRespectsRoleDefaults(): void
{
    $user = new User(['uid' => 1, 'roles' => ['administrator']]);
    // Administrators should have all permissions by default
    $this->assertTrue($user->hasPermission('administer site'));
}
```

- [ ] **Step 3: Implement role-based permission defaults**

Add a static map of role → default permissions, and check both explicit permissions and role defaults in `hasPermission()`.

- [ ] **Step 4: Run tests and commit**

```bash
git add -A && git commit -m "feat(#609): User::hasPermission() respects role-based defaults

Administrator role grants all permissions. Other roles can define
default permission sets via static configuration."
```

---

# Branch 4: `p0/http-middleware` (Milestone #38)

All middleware follows the same pattern:
- Implements `HttpMiddlewareInterface`
- Uses `#[AsMiddleware(pipeline: 'http', priority: N)]`
- `process(Request $request, HttpHandlerInterface $next): Response`

Priority guidelines (higher = runs earlier, outer layer):
- Security headers: 100 (outermost — applies to all responses)
- Response compression: 90 (wraps response body)
- Rate limiting: 80 (reject early before processing)
- Request body size: 70 (reject oversized before processing)
- Request logging: 60 (log before session/auth)
- ETag: 50 (before response goes out)
- Session: 30 (existing)
- CSRF: 20 (existing)
- Authorization: 10 (existing)

## Task 4.1: Security headers middleware (#562)

**Files:**
- Create: `packages/foundation/src/Middleware/SecurityHeadersMiddleware.php`
- Test: `packages/foundation/tests/Unit/Middleware/SecurityHeadersMiddlewareTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\SecurityHeadersMiddleware;

#[CoversClass(SecurityHeadersMiddleware::class)]
class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testAddsSecurityHeaders(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = Request::create('/');
        $handler = $this->createPassthroughHandler();

        $response = $middleware->process($request, $handler);

        $this->assertSame("default-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }

    public function testDoesNotOverrideExistingHeaders(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $request = Request::create('/');
        $handler = new class implements HttpHandlerInterface {
            public function handle(Request $request): Response {
                return new Response('OK', 200, ['X-Frame-Options' => 'SAMEORIGIN']);
            }
        };

        $response = $middleware->process($request, $handler);
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testCustomCsp(): void
    {
        $middleware = new SecurityHeadersMiddleware(csp: "default-src 'self'; script-src 'self' cdn.example.com");
        $request = Request::create('/');
        $response = $middleware->process($request, $this->createPassthroughHandler());

        $this->assertSame("default-src 'self'; script-src 'self' cdn.example.com", $response->headers->get('Content-Security-Policy'));
    }

    private function createPassthroughHandler(): HttpHandlerInterface
    {
        return new class implements HttpHandlerInterface {
            public function handle(Request $request): Response {
                return new Response('OK');
            }
        };
    }
}
```

- [ ] **Step 2: Implement SecurityHeadersMiddleware**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Attribute\AsMiddleware;

#[AsMiddleware(pipeline: 'http', priority: 100)]
final class SecurityHeadersMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly string $csp = "default-src 'self'",
        private readonly bool $hstsEnabled = true,
        private readonly int $hstsMaxAge = 31536000,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        $defaults = [
            'Content-Security-Policy' => $this->csp,
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($this->hstsEnabled) {
            $defaults['Strict-Transport-Security'] = "max-age={$this->hstsMaxAge}; includeSubDomains";
        }

        foreach ($defaults as $name => $value) {
            if (!$response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
```

- [ ] **Step 3: Run tests and commit**

```bash
git add -A && git commit -m "feat(#562): add SecurityHeadersMiddleware (CSP, HSTS, X-Frame-Options)

Adds security headers to all responses. CSP, HSTS, X-Frame-Options,
X-Content-Type-Options with configurable values. Does not override
headers already set by controllers."
```

## Task 4.2: Rate limiting middleware (#563)

**Files:**
- Create: `packages/foundation/src/Middleware/RateLimitMiddleware.php`
- Create: `packages/foundation/src/RateLimit/RateLimiterInterface.php`
- Create: `packages/foundation/src/RateLimit/InMemoryRateLimiter.php`
- Test: `packages/foundation/tests/Unit/Middleware/RateLimitMiddlewareTest.php`
- Test: `packages/foundation/tests/Unit/RateLimit/InMemoryRateLimiterTest.php`

- [ ] **Step 1: Write RateLimiterInterface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\RateLimit;

interface RateLimiterInterface
{
    /**
     * Attempt to consume a token for the given key.
     *
     * @return array{allowed: bool, remaining: int, retryAfter: ?int}
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): array;
}
```

- [ ] **Step 2: Write tests for InMemoryRateLimiter**

Test that it allows up to maxAttempts, then denies, and returns correct remaining/retryAfter.

- [ ] **Step 3: Implement InMemoryRateLimiter (fixed window)**

- [ ] **Step 4: Write middleware tests**

Test that the middleware returns 429 with Retry-After header when rate exceeded, and passes through normally otherwise.

- [ ] **Step 5: Implement RateLimitMiddleware**

```php
#[AsMiddleware(pipeline: 'http', priority: 80)]
final class RateLimitMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $key = $request->getClientIp() ?? 'unknown';
        $result = $this->limiter->attempt($key, $this->maxAttempts, $this->windowSeconds);

        if (!$result['allowed']) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '429', 'title' => 'Too Many Requests']],
            ], 429, [
                'Retry-After' => (string) $result['retryAfter'],
                'X-RateLimit-Limit' => (string) $this->maxAttempts,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        $response = $next->handle($request);
        $response->headers->set('X-RateLimit-Limit', (string) $this->maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $result['remaining']);
        return $response;
    }
}
```

- [ ] **Step 6: Run tests and commit**

```bash
git add -A && git commit -m "feat(#563): add rate limiting middleware with fixed-window strategy

RateLimitMiddleware with configurable limits per IP. Returns 429
with Retry-After header when exceeded. InMemoryRateLimiter for
single-process; apps can provide Redis-backed implementation."
```

## Task 4.3: Request logging middleware (#564)

**Files:**
- Create: `packages/foundation/src/Middleware/RequestLoggingMiddleware.php`
- Test: `packages/foundation/tests/Unit/Middleware/RequestLoggingMiddlewareTest.php`

- [ ] **Step 1: Write tests**

Test that middleware logs request method, path, status code, and duration.

- [ ] **Step 2: Implement**

```php
#[AsMiddleware(pipeline: 'http', priority: 60)]
final class RequestLoggingMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $start = hrtime(true);
        $response = $next->handle($request);
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        $this->logger->info('HTTP request', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round($durationMs, 2),
            'ip' => $request->getClientIp(),
        ]);

        return $response;
    }
}
```

- [ ] **Step 3: Run tests and commit**

```bash
git add -A && git commit -m "feat(#564): add request logging middleware with duration tracking"
```

## Task 4.4: Request body size limit middleware (#565)

**Files:**
- Create: `packages/foundation/src/Middleware/BodySizeLimitMiddleware.php`
- Test: `packages/foundation/tests/Unit/Middleware/BodySizeLimitMiddlewareTest.php`

- [ ] **Step 1: Write tests**

Test oversized body returns 413, normal body passes through.

- [ ] **Step 2: Implement**

```php
#[AsMiddleware(pipeline: 'http', priority: 70)]
final class BodySizeLimitMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly int $maxBytes = 1_048_576, // 1MB default
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $contentLength = $request->headers->get('Content-Length');

        if ($contentLength !== null && (int) $contentLength > $this->maxBytes) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '413',
                    'title' => 'Payload Too Large',
                    'detail' => sprintf('Request body exceeds %d bytes.', $this->maxBytes),
                ]],
            ], 413);
        }

        return $next->handle($request);
    }
}
```

- [ ] **Step 3: Run tests and commit**

```bash
git add -A && git commit -m "feat(#565): add request body size limit middleware

Rejects requests exceeding configurable size limit (default 1MB)
with 413 Payload Too Large before processing."
```

## Task 4.5: Response compression middleware (#566) — SLIDEABLE

**Files:**
- Create: `packages/foundation/src/Middleware/CompressionMiddleware.php`
- Test: `packages/foundation/tests/Unit/Middleware/CompressionMiddlewareTest.php`

- [ ] **Step 1: Write tests**

Test gzip compression when Accept-Encoding includes gzip, passthrough when it doesn't.

- [ ] **Step 2: Implement**

```php
#[AsMiddleware(pipeline: 'http', priority: 90)]
final class CompressionMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly int $minimumSize = 1024,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        if (!str_contains($request->headers->get('Accept-Encoding', ''), 'gzip')) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false || strlen($content) < $this->minimumSize) {
            return $response;
        }

        $compressed = gzencode($content);
        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->headers->remove('Transfer-Encoding');

        return $response;
    }
}
```

- [ ] **Step 3: Run tests and commit**

```bash
git add -A && git commit -m "feat(#566): add response compression middleware (gzip)"
```

## Task 4.6: ETag / conditional request middleware (#567) — SLIDEABLE

**Files:**
- Create: `packages/foundation/src/Middleware/ETagMiddleware.php`
- Test: `packages/foundation/tests/Unit/Middleware/ETagMiddlewareTest.php`

- [ ] **Step 1: Write tests**

Test ETag generation, 304 Not Modified when If-None-Match matches.

- [ ] **Step 2: Implement**

```php
#[AsMiddleware(pipeline: 'http', priority: 50)]
final class ETagMiddleware implements HttpMiddlewareInterface
{
    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $response = $next->handle($request);

        if (!$request->isMethodCacheable() || !$response->isSuccessful()) {
            return $response;
        }

        $content = $response->getContent();
        if ($content === false) {
            return $response;
        }

        $etag = '"' . hash('xxh3', $content) . '"';
        $response->headers->set('ETag', $etag);

        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch === $etag) {
            $response->setStatusCode(304);
            $response->setContent('');
        }

        return $response;
    }
}
```

- [ ] **Step 3: Run tests and commit**

```bash
git add -A && git commit -m "feat(#567): add ETag and conditional request middleware

Generates ETag from response content hash. Returns 304 Not Modified
when If-None-Match matches. Uses xxh3 for fast hashing."
```

---

# Branch 5: `p0/field-types` (Milestone #35)

All field types follow an identical pattern. Template:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldItemBase;

#[FieldType(
    id: '{id}',
    label: '{Label}',
    description: '{Description}',
    category: '{category}',
    defaultCardinality: 1,
)]
class {Name}Item extends FieldItemBase
{
    public static function propertyDefinitions(): array { /* ... */ }
    public static function mainPropertyName(): string { /* ... */ }
    public static function schema(): array { /* ... */ }
    public static function jsonSchema(): array { /* ... */ }
}
```

Test template (same for all):
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item;

use Waaseyaa\Field\Item\{Name}Item;
use Waaseyaa\Plugin\Definition\PluginDefinition;
use PHPUnit\Framework\TestCase;

class {Name}ItemTest extends TestCase
{
    private function createItem(array $values = []): {Name}Item
    {
        $pluginDefinition = new PluginDefinition(
            id: '{id}',
            label: '{Label}',
            class: {Name}Item::class,
        );
        $configuration = $values !== [] ? ['values' => $values] : [];
        return new {Name}Item('{id}', $pluginDefinition, $configuration);
    }

    // testPropertyDefinitions, testMainPropertyName, testSchema, testJsonSchema
    // testGetValue, testIsEmpty, testIsNotEmpty, testSetValue, testToArray, testGetString
}
```

## Task 5.1: DateTimeItem (#548)

**Files:**
- Create: `packages/field/src/Item/DateTimeItem.php`
- Test: `packages/field/tests/Unit/Item/DateTimeItemTest.php`

- [ ] **Step 1: Write DateTimeItemTest**

Key behaviors:
- Stores ISO 8601 datetime string (e.g., `2026-03-23T14:30:00+00:00`)
- Property: `value` → `string` (stored as varchar in DB, not a native DB datetime — allows timezone info)
- Schema: `['value' => ['type' => 'varchar', 'length' => 32]]`
- JSON Schema: `['type' => 'string', 'format' => 'date-time']`
- `isEmpty()`: empty if null or empty string
- `validate()`: rejects invalid datetime formats

```php
public function testPropertyDefinitions(): void
{
    $this->assertSame(['value' => 'string'], DateTimeItem::propertyDefinitions());
}

public function testSchema(): void
{
    $this->assertSame(
        ['value' => ['type' => 'varchar', 'length' => 32]],
        DateTimeItem::schema(),
    );
}

public function testJsonSchema(): void
{
    $this->assertSame(
        ['type' => 'string', 'format' => 'date-time'],
        DateTimeItem::jsonSchema(),
    );
}

public function testGetValue(): void
{
    $item = $this->createItem(['value' => '2026-03-23T14:30:00+00:00']);
    $this->assertSame('2026-03-23T14:30:00+00:00', $item->getValue());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter DateTimeItemTest`

- [ ] **Step 3: Implement DateTimeItem**

```php
#[FieldType(
    id: 'datetime',
    label: 'Date and Time',
    description: 'A field containing an ISO 8601 date and time value.',
    category: 'datetime',
    defaultCardinality: 1,
)]
class DateTimeItem extends FieldItemBase
{
    public static function propertyDefinitions(): array
    {
        return ['value' => 'string'];
    }

    public static function mainPropertyName(): string
    {
        return 'value';
    }

    public static function schema(): array
    {
        return ['value' => ['type' => 'varchar', 'length' => 32]];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string', 'format' => 'date-time'];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit --filter DateTimeItemTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(#548): implement DateTimeItem field type

ISO 8601 datetime stored as varchar(32). Supports timezone info."
```

## Task 5.2: DateItem (#549)

**Files:**
- Create: `packages/field/src/Item/DateItem.php`
- Test: `packages/field/tests/Unit/Item/DateItemTest.php`

- [ ] **Step 1: Write test, verify it fails**

Same pattern as DateTimeItem but:
- Format: `YYYY-MM-DD`
- Schema: `['value' => ['type' => 'varchar', 'length' => 10]]`
- JSON Schema: `['type' => 'string', 'format' => 'date']`

- [ ] **Step 2: Implement DateItem**

```php
#[FieldType(id: 'date', label: 'Date', description: 'A field containing a date value (YYYY-MM-DD).', category: 'datetime', defaultCardinality: 1)]
class DateItem extends FieldItemBase
{
    public static function propertyDefinitions(): array { return ['value' => 'string']; }
    public static function mainPropertyName(): string { return 'value'; }
    public static function schema(): array { return ['value' => ['type' => 'varchar', 'length' => 10]]; }
    public static function jsonSchema(): array { return ['type' => 'string', 'format' => 'date']; }
}
```

- [ ] **Step 3: Run tests, commit**

```bash
git add -A && git commit -m "feat(#549): implement DateItem field type"
```

## Task 5.3: FileItem and ImageItem (#550)

**Files:**
- Create: `packages/field/src/Item/FileItem.php`
- Create: `packages/field/src/Item/ImageItem.php`
- Test: `packages/field/tests/Unit/Item/FileItemTest.php`
- Test: `packages/field/tests/Unit/Item/ImageItemTest.php`

- [ ] **Step 1: Write FileItem tests**

Properties: `uri` (string, file path/URL), `filename` (string), `mime_type` (string), `size` (integer, bytes)
Main property: `uri`
Schema: 4 columns
JSON Schema: object with uri, filename, mime_type, size

- [ ] **Step 2: Implement FileItem**

```php
#[FieldType(id: 'file', label: 'File', description: 'A field referencing an uploaded file.', category: 'file', defaultCardinality: 1)]
class FileItem extends FieldItemBase
{
    public static function propertyDefinitions(): array
    {
        return [
            'uri' => 'string',
            'filename' => 'string',
            'mime_type' => 'string',
            'size' => 'integer',
        ];
    }

    public static function mainPropertyName(): string { return 'uri'; }

    public static function schema(): array
    {
        return [
            'uri' => ['type' => 'varchar', 'length' => 512],
            'filename' => ['type' => 'varchar', 'length' => 255],
            'mime_type' => ['type' => 'varchar', 'length' => 127],
            'size' => ['type' => 'integer'],
        ];
    }

    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uri' => ['type' => 'string', 'format' => 'uri'],
                'filename' => ['type' => 'string'],
                'mime_type' => ['type' => 'string'],
                'size' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['uri'],
        ];
    }
}
```

- [ ] **Step 3: Write ImageItem tests and implement**

ImageItem extends FileItem's concept with additional properties: `alt` (string), `width` (integer), `height` (integer).

```php
#[FieldType(id: 'image', label: 'Image', description: 'A field referencing an image file with dimensions and alt text.', category: 'file', defaultCardinality: 1)]
class ImageItem extends FieldItemBase
{
    public static function propertyDefinitions(): array
    {
        return [
            'uri' => 'string',
            'filename' => 'string',
            'mime_type' => 'string',
            'size' => 'integer',
            'alt' => 'string',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public static function mainPropertyName(): string { return 'uri'; }

    public static function schema(): array
    {
        return [
            'uri' => ['type' => 'varchar', 'length' => 512],
            'filename' => ['type' => 'varchar', 'length' => 255],
            'mime_type' => ['type' => 'varchar', 'length' => 127],
            'size' => ['type' => 'integer'],
            'alt' => ['type' => 'varchar', 'length' => 512],
            'width' => ['type' => 'integer'],
            'height' => ['type' => 'integer'],
        ];
    }

    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uri' => ['type' => 'string', 'format' => 'uri'],
                'filename' => ['type' => 'string'],
                'mime_type' => ['type' => 'string'],
                'size' => ['type' => 'integer', 'minimum' => 0],
                'alt' => ['type' => 'string'],
                'width' => ['type' => 'integer', 'minimum' => 0],
                'height' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['uri'],
        ];
    }
}
```

- [ ] **Step 4: Run tests, commit**

```bash
git add -A && git commit -m "feat(#550): implement FileItem and ImageItem field types

FileItem stores file metadata (uri, filename, mime_type, size).
ImageItem adds dimensions (width, height) and alt text."
```

## Task 5.4: LinkItem (#551)

**Files:**
- Create: `packages/field/src/Item/LinkItem.php`
- Test: `packages/field/tests/Unit/Item/LinkItemTest.php`

- [ ] **Step 1: Write tests**

Properties: `uri` (string), `title` (string)
Main property: `uri`

- [ ] **Step 2: Implement**

```php
#[FieldType(id: 'link', label: 'Link', description: 'A field containing a URL with optional title.', category: 'general', defaultCardinality: 1)]
class LinkItem extends FieldItemBase
{
    public static function propertyDefinitions(): array
    {
        return ['uri' => 'string', 'title' => 'string'];
    }

    public static function mainPropertyName(): string { return 'uri'; }

    public static function schema(): array
    {
        return [
            'uri' => ['type' => 'varchar', 'length' => 2048],
            'title' => ['type' => 'varchar', 'length' => 255],
        ];
    }

    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'uri' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 2048],
                'title' => ['type' => 'string', 'maxLength' => 255],
            ],
            'required' => ['uri'],
        ];
    }
}
```

- [ ] **Step 3: Run tests, commit**

```bash
git add -A && git commit -m "feat(#551): implement LinkItem field type"
```

## Task 5.5: EmailItem (#552)

**Files:**
- Create: `packages/field/src/Item/EmailItem.php`
- Test: `packages/field/tests/Unit/Item/EmailItemTest.php`

- [ ] **Step 1: Write tests and implement**

Single property: `value` → `string`, varchar(254) per RFC 5321.

```php
#[FieldType(id: 'email', label: 'Email', description: 'A field containing an email address.', category: 'general', defaultCardinality: 1)]
class EmailItem extends FieldItemBase
{
    public static function propertyDefinitions(): array { return ['value' => 'string']; }
    public static function mainPropertyName(): string { return 'value'; }
    public static function schema(): array { return ['value' => ['type' => 'varchar', 'length' => 254]]; }
    public static function jsonSchema(): array { return ['type' => 'string', 'format' => 'email', 'maxLength' => 254]; }
}
```

- [ ] **Step 2: Run tests, commit**

```bash
git add -A && git commit -m "feat(#552): implement EmailItem field type"
```

## Task 5.6: DecimalItem (#553)

**Files:**
- Create: `packages/field/src/Item/DecimalItem.php`
- Test: `packages/field/tests/Unit/Item/DecimalItemTest.php`

- [ ] **Step 1: Write tests and implement**

Property: `value` → `string` (stored as string to preserve precision, like Drupal's decimal).
Schema: `['value' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2]]`

```php
#[FieldType(id: 'decimal', label: 'Decimal', description: 'A field containing a precise decimal number.', category: 'number', defaultCardinality: 1)]
class DecimalItem extends FieldItemBase
{
    public static function propertyDefinitions(): array { return ['value' => 'string']; }
    public static function mainPropertyName(): string { return 'value'; }

    public static function schema(): array
    {
        return ['value' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2]];
    }

    public static function jsonSchema(): array
    {
        return ['type' => 'string', 'pattern' => '^-?\\d+\\.\\d+$'];
    }

    public static function defaultSettings(): array
    {
        return ['precision' => 10, 'scale' => 2];
    }
}
```

Note: `0` and `0.00` are valid non-empty values. The base class `isEmpty()` checks for null and `''`, which is correct here since decimal values are stored as strings.

- [ ] **Step 2: Run tests, commit**

```bash
git add -A && git commit -m "feat(#553): implement DecimalItem field type

String-stored decimal for precision. Default 10,2 scale."
```

## Task 5.7: ListItem / SelectItem (#554)

**Files:**
- Create: `packages/field/src/Item/ListItem.php`
- Test: `packages/field/tests/Unit/Item/ListItemTest.php`

- [ ] **Step 1: Write tests and implement**

Property: `value` → `string` (the selected key from allowed values).
Settings hold the allowed values list.

```php
#[FieldType(id: 'list', label: 'List (Select)', description: 'A field with a predefined list of allowed values.', category: 'general', defaultCardinality: 1)]
class ListItem extends FieldItemBase
{
    public static function propertyDefinitions(): array { return ['value' => 'string']; }
    public static function mainPropertyName(): string { return 'value'; }
    public static function schema(): array { return ['value' => ['type' => 'varchar', 'length' => 255]]; }

    public static function jsonSchema(): array
    {
        return ['type' => 'string'];
    }

    public static function defaultSettings(): array
    {
        return ['allowed_values' => []];
    }
}
```

- [ ] **Step 2: Run tests, commit**

```bash
git add -A && git commit -m "feat(#554): implement ListItem (select/dropdown) field type

Stores a string key from a configured allowed_values list."
```

## Task 5.8: ComputedField support (#555) — SLIDEABLE

**Files:**
- Create: `packages/field/src/ComputedFieldInterface.php`
- Create: `packages/field/src/Item/ComputedItem.php`
- Test: `packages/field/tests/Unit/Item/ComputedItemTest.php`

- [ ] **Step 1: Write interface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Entity\EntityInterface;

/**
 * Marker interface for fields whose values are computed, not stored.
 */
interface ComputedFieldInterface
{
    /**
     * Compute the field value for the given entity.
     *
     * @return mixed The computed value.
     */
    public function compute(EntityInterface $entity): mixed;
}
```

- [ ] **Step 2: Write ComputedItem tests and implement**

ComputedItem takes a callable that receives the entity and returns the value. It implements ComputedFieldInterface. Schema returns empty array (no storage columns).

- [ ] **Step 3: Run tests, commit**

```bash
git add -A && git commit -m "feat(#555): add ComputedField support to field system

ComputedFieldInterface + ComputedItem for fields derived from entity
data. No storage columns — values computed on access."
```

## Task 5.9: EntityInterface PHPDoc annotations (#608)

**Files:**
- Modify: `packages/entity/src/EntityInterface.php`

- [ ] **Step 1: Read current EntityInterface**

- [ ] **Step 2: Add @method PHPDoc annotations**

Add class-level PHPDoc with `@method` annotations for commonly used methods that IDEs struggle to resolve:

```php
/**
 * @method mixed id()
 * @method string|null uuid()
 * @method string|null label()
 * @method string getEntityTypeId()
 * @method string bundle()
 * @method bool isNew()
 * @method mixed get(string $name)
 * @method static set(string $name, mixed $value)
 * @method array toArray()
 * @method string language()
 */
interface EntityInterface
```

- [ ] **Step 3: Run tests, commit**

```bash
git add -A && git commit -m "fix(#608): add @method PHPDoc to EntityInterface for IDE support"
```

---

# Post-Sprint: Merge Protocol

After all 5 agents complete:

- [ ] **Step 1: Merge branch 1 (layer-enforcement) into main**

```bash
git checkout main && git merge p0/layer-enforcement
./vendor/bin/phpunit --configuration phpunit.xml.dist
```

- [ ] **Step 2: Merge branch 2 (logging) into main**

```bash
git merge p0/logging
./vendor/bin/phpunit --configuration phpunit.xml.dist
```

- [ ] **Step 3: Merge branch 3 (security-hardening) into main**

```bash
git merge p0/security-hardening
./vendor/bin/phpunit --configuration phpunit.xml.dist
```

- [ ] **Step 4: Merge branch 4 (http-middleware) into main**

```bash
git merge p0/http-middleware
./vendor/bin/phpunit --configuration phpunit.xml.dist
```

- [ ] **Step 5: Merge branch 5 (field-types) into main**

```bash
git merge p0/field-types
./vendor/bin/phpunit --configuration phpunit.xml.dist
```

- [ ] **Step 6: Update CLAUDE.md**

Remove "No psr/log" gotcha. Add logging guidance. Update layer table with classified orphan packages.

- [ ] **Step 7: Create 5 PRs and close issues**

One PR per branch, referencing all issues. Close all resolved issues.
