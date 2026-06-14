# Gate Wiring Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Wire the Gate into AccessChecker so `_gate` route options work, using an adapter that bridges GateInterface to EntityAccessHandler.

**Architecture:** EntityAccessGate adapter implements GateInterface, wraps EntityAccessHandler, translates ability/subject/user calls into check()/checkCreateAccess() calls. AccessChecker passes the account through to the gate.

**Tech Stack:** PHP 8.3+, PHPUnit 10.5, Symfony Routing

---

### Task 1: Fix AccessChecker to pass account to gate

**Files:**
- Modify: `packages/routing/src/AccessChecker.php:72-107`
- Modify: `packages/routing/tests/Unit/GateAccessTest.php:90-254`

**Step 1: Update existing tests to expect account parameter**

The `GateInterface::allows()` mock expectations currently use `->with('ability', $subject)` (2 args). Update all mock expectations to include the account as third arg: `->with('ability', $subject, $account)`.

Tests to update (all in `packages/routing/tests/Unit/GateAccessTest.php`):
- `gateAllowsReturnsAllowed` (line 96): `->with('config.export', null)` → `->with('config.export', null, $account)`
- `gateDeniesReturnsForbidden` (line 115): same pattern
- `gateCombinedWithPermissionBothPassReturnsAllowed` (line 149): same
- `gateCombinedWithPermissionGateFailsReturnsForbidden` (line 173): same
- `gateCombinedWithPermissionPermissionFailsReturnsForbidden` (line 198): same
- `gatePassesSubjectToGateCheck` (line 240): `->with('node.update', 'node')` → `->with('node.update', 'node', $account)`

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/routing/tests/Unit/GateAccessTest.php`
Expected: 6 failures — mock expectations now expect 3 args but `AccessChecker::checkGate()` only passes 2.

**Step 3: Update AccessChecker::check() and checkGate()**

In `packages/routing/src/AccessChecker.php`:

Change the `checkGate` call site (around line 76):
```php
// Before:
$result = $result->andIf($this->checkGate($gateOptions));

// After:
$result = $result->andIf($this->checkGate($gateOptions, $account));
```

Change `checkGate()` signature and body (around line 91):
```php
// Before:
private function checkGate(array $gateOptions): AccessResult

// After:
private function checkGate(array $gateOptions, AccountInterface $account): AccessResult
```

Change the `allows()` call (around line 104):
```php
// Before:
return $this->gate->allows($ability, $subject)

// After:
return $this->gate->allows($ability, $subject, $account)
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/routing/tests/Unit/GateAccessTest.php`
Expected: All 14 tests pass.

**Step 5: Commit**

```
feat(routing): pass account to gate in AccessChecker
```

---

### Task 2: Create EntityAccessGate adapter

**Files:**
- Create: `packages/access/src/Gate/EntityAccessGate.php`
- Create: `packages/access/tests/Unit/Gate/EntityAccessGateTest.php`

**Step 1: Write failing tests**

Create `packages/access/tests/Unit/Gate/EntityAccessGateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit\Gate;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\AccessDeniedException;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityAccessGate::class)]
final class EntityAccessGateTest extends TestCase
{
    // --- Interface ---

    #[Test]
    public function implementsGateInterface(): void
    {
        $handler = new EntityAccessHandler();
        $gate = new EntityAccessGate($handler);
        $this->assertInstanceOf(GateInterface::class, $gate);
    }

    // --- allows() with entity subject ---

    #[Test]
    public function allowsWithEntitySubjectDelegatesToHandler(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount(['administrator']);
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertTrue($gate->allows('view', $entity, $account));
    }

    #[Test]
    public function deniesWithEntitySubjectWhenPolicyReturnsNeutral(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount([]);
        $policy = $this->createPolicy('node', AccessResult::neutral());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->allows('view', $entity, $account));
    }

    // --- allows() with string subject (create access) ---

    #[Test]
    public function allowsCreateWithStringSubjectDelegatesToCreateAccess(): void
    {
        $account = $this->createAccount(['administrator']);
        $policy = $this->createPolicy('node_type', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertTrue($gate->allows('create', 'node_type', $account));
    }

    #[Test]
    public function deniesCreateWithStringSubjectWhenPolicyReturnsNeutral(): void
    {
        $account = $this->createAccount([]);
        $policy = $this->createPolicy('node_type', AccessResult::neutral());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->allows('create', 'node_type', $account));
    }

    // --- allows() with string subject, non-create ability ---

    #[Test]
    public function deniesNonCreateAbilityWithStringSubject(): void
    {
        $account = $this->createAccount(['administrator']);
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        // Can't check instance-level access without an entity.
        $this->assertFalse($gate->allows('view', 'node', $account));
    }

    // --- allows() without account ---

    #[Test]
    public function deniesWhenUserIsNull(): void
    {
        $entity = $this->createEntity('node');
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->allows('view', $entity));
    }

    #[Test]
    public function deniesWhenUserIsNotAccountInterface(): void
    {
        $entity = $this->createEntity('node');
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->allows('view', $entity, new \stdClass()));
    }

    // --- denies() ---

    #[Test]
    public function deniesIsInverseOfAllows(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount(['administrator']);
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->assertFalse($gate->denies('view', $entity, $account));
    }

    // --- authorize() ---

    #[Test]
    public function authorizeDoesNotThrowWhenAllowed(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount(['administrator']);
        $policy = $this->createPolicy('node', AccessResult::allowed());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $gate->authorize('view', $entity, $account);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function authorizeThrowsWhenDenied(): void
    {
        $entity = $this->createEntity('node');
        $account = $this->createAccount([]);
        $policy = $this->createPolicy('node', AccessResult::neutral());
        $handler = new EntityAccessHandler([$policy]);

        $gate = new EntityAccessGate($handler);

        $this->expectException(AccessDeniedException::class);
        $gate->authorize('view', $entity, $account);
    }

    // --- Unsupported subject types ---

    #[Test]
    public function deniesWithUnsupportedSubjectType(): void
    {
        $handler = new EntityAccessHandler();
        $gate = new EntityAccessGate($handler);
        $account = $this->createAccount(['administrator']);

        $this->assertFalse($gate->allows('view', 42, $account));
    }

    // --- Helpers ---

    private function createEntity(string $typeId): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($typeId);
        $entity->method('bundle')->willReturn($typeId);
        return $entity;
    }

    private function createAccount(array $roles): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('getRoles')->willReturn($roles);
        return $account;
    }

    private function createPolicy(string $entityTypeId, AccessResult $result): AccessPolicyInterface
    {
        $policy = $this->createMock(AccessPolicyInterface::class);
        $policy->method('appliesTo')
            ->willReturnCallback(fn(string $type) => $type === $entityTypeId);
        $policy->method('access')->willReturn($result);
        $policy->method('createAccess')->willReturn($result);
        return $policy;
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/access/tests/Unit/Gate/EntityAccessGateTest.php`
Expected: Error — class `EntityAccessGate` not found.

**Step 3: Write EntityAccessGate implementation**

Create `packages/access/src/Gate/EntityAccessGate.php`:

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Gate;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;

/**
 * Adapter that bridges GateInterface to EntityAccessHandler.
 *
 * Translates gate ability checks into EntityAccessHandler calls:
 * - Entity subject → check($entity, $ability, $account)
 * - String subject + "create" → checkCreateAccess($entityTypeId, '', $account)
 * - String subject + other ability → denied (instance required)
 */
final class EntityAccessGate implements GateInterface
{
    public function __construct(
        private readonly EntityAccessHandler $handler,
    ) {}

    public function allows(string $ability, mixed $subject, ?object $user = null): bool
    {
        if (!$user instanceof AccountInterface) {
            return false;
        }

        if ($subject instanceof EntityInterface) {
            return $this->handler->check($subject, $ability, $user)->isAllowed();
        }

        if (is_string($subject) && $ability === 'create') {
            return $this->handler->checkCreateAccess($subject, '', $user)->isAllowed();
        }

        return false;
    }

    public function denies(string $ability, mixed $subject, ?object $user = null): bool
    {
        return !$this->allows($ability, $subject, $user);
    }

    public function authorize(string $ability, mixed $subject, ?object $user = null): void
    {
        if ($this->denies($ability, $subject, $user)) {
            throw new AccessDeniedException(ability: $ability, subject: $subject);
        }
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/access/tests/Unit/Gate/EntityAccessGateTest.php`
Expected: All 12 tests pass.

**Step 5: Commit**

```
feat(access): add EntityAccessGate adapter bridging Gate to EntityAccessHandler
```

---

### Task 3: Wire gate into index.php

**Files:**
- Modify: `public/index.php`

**Step 1: Move EntityAccessHandler construction above AccessChecker**

In `public/index.php`, move the entity access handler block (currently at ~line 357-375) to just after `$userStorage` (line 331), before `$accessChecker`. Then create the gate and pass to AccessChecker.

The new ordering at ~line 331:

```php
$userStorage = $entityTypeManager->getStorage('user');

// --- Entity access handler -----------------------------------------------------

$accessHandler = new EntityAccessHandler([
    new NodeAccessPolicy(),
    new TermAccessPolicy(),
    new ConfigEntityAccessPolicy(entityTypeIds: [
        'node_type',
        'taxonomy_vocabulary',
        'media_type',
        'workflow',
        'pipeline',
    ]),
]);

$gate = new EntityAccessGate($accessHandler);
$accessChecker = new AccessChecker(gate: $gate);
$pipeline = (new HttpPipeline())
    ->withMiddleware(new SessionMiddleware($userStorage))
    ->withMiddleware(new AuthorizationMiddleware($accessChecker));
```

Remove the old entity access handler section (was between auth pipeline and dispatch).

Add import: `use Waaseyaa\Access\Gate\EntityAccessGate;`

Keep the `$account` type guard, but move it to just before the dispatch section (after the pipeline runs), since it's needed for controller construction, not for gate wiring.

**Step 2: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass (no integration tests directly test index.php wiring, but ensures nothing is broken).

**Step 3: Commit**

```
feat(access): wire EntityAccessGate into front controller AccessChecker
```

---

### Task 4: Run full verification

**Step 1: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests pass.

**Step 2: Verify no regressions in AccessChecker tests**

Run: `./vendor/bin/phpunit packages/routing/tests/Unit/GateAccessTest.php packages/access/tests/Unit/Gate/EntityAccessGateTest.php`
Expected: All 26 tests pass (14 GateAccess + 12 EntityAccessGate).
