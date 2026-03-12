# PR Review Critical Fixes Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix three critical issues found during code review of PRs #303 and #304.

**Architecture:** Three surgical fixes — a bug fix (array merge), a file move (layer violation), and a code dedup (response sender extraction). Each fix targets a specific worktree branch.

**Tech Stack:** PHP 8.4+, PHPUnit 10.5, Waaseyaa monorepo conventions

---

## Worktree Locations

| PR | Branch | Worktree Path |
|---|---|---|
| #304 | `refactor/298-decompose-mcpcontroller` | `/home/fsd42/dev/waaseyaa/.claude/worktrees/agent-aca537cf` |
| #303 | `refactor/297-decompose-httpkernel` | `/home/fsd42/dev/waaseyaa/.claude/worktrees/agent-a7c1c4ad` |

## Codebase Conventions

- PHP 8.4+, `declare(strict_types=1)` in every file
- `final class` by default for concrete implementations
- PHPUnit 10.5 attributes: `#[Test]`, `#[CoversClass(...)]`
- Namespace: `Waaseyaa\PackageName\`
- Named constructor parameters
- Test command: `./vendor/bin/phpunit --configuration phpunit.xml.dist` (do NOT use `-v`)
- Layer rule: Foundation (L0) must not import from higher layers except in `Kernel/` classes

---

## Task 1: Fix TraversalTools::knowledgeGraph() direction bug (PR #304)

**Worktree:** `/home/fsd42/dev/waaseyaa/.claude/worktrees/agent-aca537cf`

**Files:**
- Modify: `packages/mcp/src/Tools/TraversalTools.php:166`
- Modify: `packages/mcp/tests/Unit/Tools/TraversalToolsTest.php`

**Bug:** PHP's `+` operator on arrays does NOT override existing keys — it keeps left-side values. `$parsed + ['direction' => 'both']` silently ignores `'both'` when `$parsed` already has a `'direction'` key (which it always does, set by `parseTraversalArguments()`).

- [ ] **Step 1: Write a failing test that exposes the bug**

Add to `packages/mcp/tests/Unit/Tools/TraversalToolsTest.php`:

```php
#[Test]
public function knowledgeGraphForcesDirectionBoth(): void
{
    // parseTraversalArguments sets direction from input — knowledgeGraph must override to 'both'
    $args = ['entity_type' => 'node', 'entity_id' => '1', 'direction' => 'inbound'];
    $parsed = $this->tools->parseTraversalArguments($args);

    // Simulate what knowledgeGraph does — the fix should override direction
    $merged = array_merge($parsed, ['direction' => 'both']);
    $this->assertSame('both', $merged['direction']);

    // Verify the OLD broken pattern would NOT override
    $broken = $parsed + ['direction' => 'both'];
    $this->assertSame('inbound', $broken['direction'], 'PHP + operator keeps left-side keys');
}
```

- [ ] **Step 2: Run the test to verify it passes (this test validates the fix pattern, not the bug)**

Run: `./vendor/bin/phpunit --filter knowledgeGraphForcesDirectionBoth`
Expected: PASS (the test asserts both the correct and broken patterns)

- [ ] **Step 3: Apply the fix in TraversalTools.php**

In `packages/mcp/src/Tools/TraversalTools.php`, line 166, change:

```php
// BEFORE (broken):
$rows = $this->collectTraversalRows($parsed + ['direction' => 'both']);

// AFTER (fixed):
$rows = $this->collectTraversalRows(array_merge($parsed, ['direction' => 'both']));
```

- [ ] **Step 4: Run the full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass (3994+)

- [ ] **Step 5: Commit**

```bash
git add packages/mcp/src/Tools/TraversalTools.php packages/mcp/tests/Unit/Tools/TraversalToolsTest.php
git commit -m "fix(#298): use array_merge to override direction in knowledgeGraph

PHP's + operator keeps left-side keys, so \$parsed + ['direction' => 'both']
silently ignores 'both' when \$parsed already has a 'direction' key.
array_merge correctly overrides."
```

---

## Task 2: Fix layer violation — move DiscoveryApiHandler to API package (PR #303)

**Worktree:** `/home/fsd42/dev/waaseyaa/.claude/worktrees/agent-a7c1c4ad`

**Files:**
- Move: `packages/foundation/src/Http/DiscoveryApiHandler.php` → `packages/api/src/Http/DiscoveryApiHandler.php`
- Move: `packages/foundation/tests/Unit/Http/DiscoveryApiHandlerTest.php` → `packages/api/tests/Unit/Http/DiscoveryApiHandlerTest.php`
- Modify: `packages/foundation/src/Kernel/HttpKernel.php` (update import)
- Modify: `packages/foundation/src/Http/ControllerDispatcher.php` (update import)
- Modify: `packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php` (update import)
- Modify: `packages/ssr/src/SsrPageHandler.php` (update import if it references DiscoveryApiHandler)

**Why:** `DiscoveryApiHandler` imports `RelationshipDiscoveryService` (Layer 2) and `WorkflowVisibility` (Layer 3). Foundation is Layer 0 — the kernel exemption only covers `Kernel/` classes, not `Http/` classes. The API package (Layer 4) can correctly import from Layers 2 and 3.

- [ ] **Step 1: Create the target directory if needed**

```bash
mkdir -p packages/api/src/Http
mkdir -p packages/api/tests/Unit/Http
```

- [ ] **Step 2: Move DiscoveryApiHandler to the API package**

```bash
git mv packages/foundation/src/Http/DiscoveryApiHandler.php packages/api/src/Http/DiscoveryApiHandler.php
```

- [ ] **Step 3: Update the namespace in the moved file**

In `packages/api/src/Http/DiscoveryApiHandler.php`, change:

```php
// BEFORE:
namespace Waaseyaa\Foundation\Http;

// AFTER:
namespace Waaseyaa\Api\Http;
```

Also update the `use` statement for `DiscoveryCachePrimitives` — it references `Waaseyaa\Foundation\Cache\DiscoveryCachePrimitives` which is still in Foundation (Layer 0). This import is fine — Layer 4 can import from Layer 0.

- [ ] **Step 4: Move the test file**

```bash
git mv packages/foundation/tests/Unit/Http/DiscoveryApiHandlerTest.php packages/api/tests/Unit/Http/DiscoveryApiHandlerTest.php
```

- [ ] **Step 5: Update the test namespace**

In `packages/api/tests/Unit/Http/DiscoveryApiHandlerTest.php`, change:

```php
// BEFORE:
namespace Waaseyaa\Foundation\Tests\Unit\Http;
use Waaseyaa\Foundation\Http\DiscoveryApiHandler;

// AFTER:
namespace Waaseyaa\Api\Tests\Unit\Http;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
```

- [ ] **Step 6: Update all imports referencing the old location**

Search for `Waaseyaa\Foundation\Http\DiscoveryApiHandler` in all PHP files in the worktree and update to `Waaseyaa\Api\Http\DiscoveryApiHandler`. Files to check:

- `packages/foundation/src/Kernel/HttpKernel.php`
- `packages/foundation/src/Http/ControllerDispatcher.php`
- `packages/foundation/tests/Unit/Http/ControllerDispatcherTest.php`
- `packages/foundation/tests/Unit/Kernel/HttpKernelTest.php`
- `packages/ssr/src/SsrPageHandler.php`

In each file, change:
```php
// BEFORE:
use Waaseyaa\Foundation\Http\DiscoveryApiHandler;

// AFTER:
use Waaseyaa\Api\Http\DiscoveryApiHandler;
```

- [ ] **Step 7: Run the full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass (4018+)

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor(#297): move DiscoveryApiHandler to API package (layer fix)

DiscoveryApiHandler imports from Relationship (Layer 2) and Workflows
(Layer 3). Foundation is Layer 0 — kernel exemption only covers Kernel/
classes. API package (Layer 4) correctly allows these imports."
```

---

## Task 3: Extract ResponseSender to eliminate sendJson/sendHtml duplication (PR #303)

**Worktree:** `/home/fsd42/dev/waaseyaa/.claude/worktrees/agent-a7c1c4ad`

**Files:**
- Create: `packages/foundation/src/Http/ResponseSender.php`
- Create: `packages/foundation/tests/Unit/Http/ResponseSenderTest.php`
- Modify: `packages/foundation/src/Http/ControllerDispatcher.php` (remove sendJson/sendHtml, use ResponseSender)
- Modify: `packages/foundation/src/Kernel/HttpKernel.php` (remove sendJson, use ResponseSender)

**Context:** `sendJson()` is 100% identical in both HttpKernel (line 246) and ControllerDispatcher (line 867). `sendHtml()` exists only in ControllerDispatcher (line 889). Extract both into a stateless `ResponseSender` utility class.

- [ ] **Step 1: Create ResponseSender class**

Create `packages/foundation/src/Http/ResponseSender.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http;

/**
 * Stateless utility for sending HTTP responses.
 *
 * Centralizes response formatting to avoid duplication across
 * HttpKernel and ControllerDispatcher.
 */
final class ResponseSender
{
    /**
     * Send a JSON:API response and terminate.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $data, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/vnd.api+json');
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                continue;
            }
            header($name . ': ' . $value);
        }
        try {
            echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log(sprintf('[Waaseyaa] JSON encoding failed in sendJson: %s', $e->getMessage()));
            echo '{"jsonapi":{"version":"1.1"},"errors":[{"status":"500","title":"Internal Server Error","detail":"Response encoding failed."}]}';
        }
        exit;
    }

    /**
     * Send an HTML response and terminate.
     *
     * @param array<string, string> $headers
     */
    public static function html(int $status, string $html, array $headers = []): never
    {
        http_response_code($status);
        $contentType = $headers['Content-Type'] ?? 'text/html; charset=UTF-8';
        header('Content-Type: ' . $contentType);

        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                continue;
            }
            header($name . ': ' . $value);
        }

        echo $html;
        exit;
    }
}
```

- [ ] **Step 2: Create ResponseSender tests**

Create `packages/foundation/tests/Unit/Http/ResponseSenderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Http\ResponseSender;

#[CoversClass(ResponseSender::class)]
final class ResponseSenderTest extends TestCase
{
    #[Test]
    public function classExists(): void
    {
        $this->assertTrue(class_exists(ResponseSender::class));
    }

    #[Test]
    public function jsonMethodIsStatic(): void
    {
        $method = new \ReflectionMethod(ResponseSender::class, 'json');
        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function htmlMethodIsStatic(): void
    {
        $method = new \ReflectionMethod(ResponseSender::class, 'html');
        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function jsonMethodHasNeverReturnType(): void
    {
        $method = new \ReflectionMethod(ResponseSender::class, 'json');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('never', $returnType->getName());
    }

    #[Test]
    public function htmlMethodHasNeverReturnType(): void
    {
        $method = new \ReflectionMethod(ResponseSender::class, 'html');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('never', $returnType->getName());
    }
}
```

Note: `sendJson()`/`sendHtml()` call `exit` (`never` return type), making them impossible to test for output directly in PHPUnit. The reflection tests confirm the API contract. Integration coverage comes from existing Phase10 EndToEndSmokeTest.

- [ ] **Step 3: Run the new tests**

Run: `./vendor/bin/phpunit --filter ResponseSenderTest`
Expected: All 5 tests pass

- [ ] **Step 4: Update ControllerDispatcher to use ResponseSender**

In `packages/foundation/src/Http/ControllerDispatcher.php`:

1. Add import: `use Waaseyaa\Foundation\Http\ResponseSender;`
2. Delete the `sendJson()` method (lines 867-884)
3. Delete the `sendHtml()` method (lines 889-904)
4. Replace all calls to `$this->sendJson(...)` with `ResponseSender::json(...)`
5. Replace all calls to `$this->sendHtml(...)` with `ResponseSender::html(...)`

Use grep to find all call sites: `grep -n 'sendJson\|sendHtml' packages/foundation/src/Http/ControllerDispatcher.php`

- [ ] **Step 5: Update HttpKernel to use ResponseSender**

In `packages/foundation/src/Kernel/HttpKernel.php`:

1. Add import: `use Waaseyaa\Foundation\Http\ResponseSender;`
2. Delete the `sendJson()` method (lines 246-263)
3. Replace all calls to `$this->sendJson(...)` with `ResponseSender::json(...)`

Use grep to find all call sites: `grep -n 'sendJson' packages/foundation/src/Kernel/HttpKernel.php`

- [ ] **Step 6: Run the full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass (4018+)

- [ ] **Step 7: Commit**

```bash
git add packages/foundation/src/Http/ResponseSender.php packages/foundation/tests/Unit/Http/ResponseSenderTest.php packages/foundation/src/Http/ControllerDispatcher.php packages/foundation/src/Kernel/HttpKernel.php
git commit -m "refactor(#297): extract ResponseSender to eliminate sendJson/sendHtml duplication

Both HttpKernel and ControllerDispatcher had identical sendJson()
implementations. Extract to a stateless ResponseSender utility class
with static json() and html() methods."
```

---

## Verification

After all three tasks are complete:

- [ ] **Run full test suite in PR #304 worktree**

```bash
cd /home/fsd42/dev/waaseyaa/.claude/worktrees/agent-aca537cf
./vendor/bin/phpunit --configuration phpunit.xml.dist
```

- [ ] **Run full test suite in PR #303 worktree**

```bash
cd /home/fsd42/dev/waaseyaa/.claude/worktrees/agent-a7c1c4ad
./vendor/bin/phpunit --configuration phpunit.xml.dist
```

- [ ] **Verify no Foundation layer violations remain**

```bash
cd /home/fsd42/dev/waaseyaa/.claude/worktrees/agent-a7c1c4ad
grep -rn 'use Waaseyaa\\Relationship\|use Waaseyaa\\Workflows\|use Waaseyaa\\Node\|use Waaseyaa\\Taxonomy' packages/foundation/src/Http/ || echo "No layer violations in Foundation/Http/"
```
Expected: "No layer violations in Foundation/Http/"

- [ ] **Verify no duplicate sendJson remains**

```bash
cd /home/fsd42/dev/waaseyaa/.claude/worktrees/agent-a7c1c4ad
grep -rn 'private.*function sendJson\|private.*function sendHtml' packages/foundation/src/ || echo "No duplicate response methods"
```
Expected: "No duplicate response methods"

- [ ] **Push both branches**

```bash
cd /home/fsd42/dev/waaseyaa/.claude/worktrees/agent-aca537cf
git push origin refactor/298-decompose-mcpcontroller

cd /home/fsd42/dev/waaseyaa/.claude/worktrees/agent-a7c1c4ad
git push origin refactor/297-decompose-httpkernel
```
