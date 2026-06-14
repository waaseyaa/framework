# Access Policy Wiring Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix 403 on entity CRUD by wiring access policies into `EntityAccessHandler` and creating admin-only policy for config entity types.

**Architecture:** Create `ConfigEntityAccessPolicy` in `packages/access/src/` covering config entity types (node_type, taxonomy_vocabulary, media_type, workflow, pipeline). Wire it plus existing `NodeAccessPolicy` and `TermAccessPolicy` into the `EntityAccessHandler` constructor in `public/index.php`.

**Tech Stack:** PHP 8.3, PHPUnit 10.5

---

### Task 1: ConfigEntityAccessPolicy — failing tests

**Files:**
- Create: `packages/access/tests/Unit/ConfigEntityAccessPolicyTest.php`

**Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\ConfigEntityAccessPolicy;
use Waaseyaa\Entity\EntityInterface;

#[CoversClass(ConfigEntityAccessPolicy::class)]
final class ConfigEntityAccessPolicyTest extends TestCase
{
    private ConfigEntityAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ConfigEntityAccessPolicy([
            'node_type',
            'taxonomy_vocabulary',
            'media_type',
        ]);
    }

    public function testImplementsAccessPolicyInterface(): void
    {
        $this->assertInstanceOf(AccessPolicyInterface::class, $this->policy);
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(ConfigEntityAccessPolicy::class);
        $this->assertTrue($reflection->isFinal());
    }

    // appliesTo

    public function testAppliesToConfiguredTypes(): void
    {
        $this->assertTrue($this->policy->appliesTo('node_type'));
        $this->assertTrue($this->policy->appliesTo('taxonomy_vocabulary'));
        $this->assertTrue($this->policy->appliesTo('media_type'));
    }

    public function testDoesNotApplyToUnconfiguredTypes(): void
    {
        $this->assertFalse($this->policy->appliesTo('node'));
        $this->assertFalse($this->policy->appliesTo('user'));
        $this->assertFalse($this->policy->appliesTo(''));
    }

    // createAccess

    public function testCreateAccessAllowedForAdminRole(): void
    {
        $account = $this->createAccountWithRoles(['administrator']);

        $result = $this->policy->createAccess('node_type', 'node_type', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessNeutralForNonAdmin(): void
    {
        $account = $this->createAccountWithRoles(['authenticated']);

        $result = $this->policy->createAccess('node_type', 'node_type', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testCreateAccessNeutralForAnonymous(): void
    {
        $account = $this->createAccountWithRoles(['anonymous']);

        $result = $this->policy->createAccess('node_type', 'node_type', $account);
        $this->assertTrue($result->isNeutral());
    }

    // access (view/update/delete on existing entity)

    public function testAccessAllowedForAdminRole(): void
    {
        $entity = $this->createEntityStub('node_type');
        $account = $this->createAccountWithRoles(['administrator']);

        foreach (['view', 'update', 'delete'] as $operation) {
            $result = $this->policy->access($entity, $operation, $account);
            $this->assertTrue($result->isAllowed(), "Expected allowed for '$operation'");
        }
    }

    public function testAccessNeutralForNonAdmin(): void
    {
        $entity = $this->createEntityStub('node_type');
        $account = $this->createAccountWithRoles(['authenticated']);

        foreach (['view', 'update', 'delete'] as $operation) {
            $result = $this->policy->access($entity, $operation, $account);
            $this->assertTrue($result->isNeutral(), "Expected neutral for '$operation'");
        }
    }

    // helpers

    /** @param string[] $roles */
    private function createAccountWithRoles(array $roles): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('getRoles')->willReturn($roles);

        return $account;
    }

    private function createEntityStub(string $entityTypeId): EntityInterface
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($entityTypeId);

        return $entity;
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/access/tests/Unit/ConfigEntityAccessPolicyTest.php`
Expected: FAIL — class `ConfigEntityAccessPolicy` not found.

---

### Task 2: ConfigEntityAccessPolicy — implementation

**Files:**
- Create: `packages/access/src/ConfigEntityAccessPolicy.php`

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for configuration entity types (node_type, taxonomy_vocabulary, etc.).
 *
 * Grants full access to accounts with the 'administrator' role.
 * Returns neutral for all other accounts.
 */
final class ConfigEntityAccessPolicy implements AccessPolicyInterface
{
    /** @param string[] $entityTypeIds Config entity type IDs this policy covers. */
    public function __construct(
        private readonly array $entityTypeIds,
    ) {}

    public function appliesTo(string $entityTypeId): bool
    {
        return \in_array($entityTypeId, $this->entityTypeIds, true);
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return $this->checkAdminRole($account);
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return $this->checkAdminRole($account);
    }

    private function checkAdminRole(AccountInterface $account): AccessResult
    {
        if (\in_array('administrator', $account->getRoles(), true)) {
            return AccessResult::allowed('Account has administrator role.');
        }

        return AccessResult::neutral('Account lacks administrator role.');
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/access/tests/Unit/ConfigEntityAccessPolicyTest.php`
Expected: All tests PASS.

**Step 5: Commit**

```bash
git add packages/access/src/ConfigEntityAccessPolicy.php packages/access/tests/Unit/ConfigEntityAccessPolicyTest.php
git commit -m "feat(access): add ConfigEntityAccessPolicy for admin-only config entity types"
```

---

### Task 3: Wire policies into index.php

**Files:**
- Modify: `public/index.php:70-71` (add imports)
- Modify: `public/index.php:356-358` (replace EntityAccessHandler construction)

**Step 6: Add imports to index.php**

Add after line 70 (`use Waaseyaa\Access\EntityAccessHandler;`):

```php
use Waaseyaa\Access\ConfigEntityAccessPolicy;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Taxonomy\TermAccessPolicy;
```

**Step 7: Wire policies into EntityAccessHandler**

Replace lines 356-358:

```php
// TODO: Populate with field-access policies from discovery/registry.
// With an empty policy set, open-by-default semantics apply: all fields are accessible.
$accessHandler = new EntityAccessHandler([]);
```

With:

```php
$accessHandler = new EntityAccessHandler([
    new NodeAccessPolicy(),
    new TermAccessPolicy(),
    new ConfigEntityAccessPolicy([
        'node_type',
        'taxonomy_vocabulary',
        'media_type',
        'workflow',
        'pipeline',
    ]),
]);
```

**Step 8: Run existing tests to verify nothing breaks**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests PASS.

**Step 9: Commit**

```bash
git add public/index.php
git commit -m "fix(access): wire access policies into EntityAccessHandler

NodeAccessPolicy, TermAccessPolicy, and ConfigEntityAccessPolicy are now
passed to EntityAccessHandler. Previously an empty array was used, causing
all entity CRUD operations to return 403."
```

---

### Task 4: Manual verification

**Step 10: Start the dev server and test**

Start server: `php -S localhost:8080 -t public`

Test node_type creation (should still 403 for anonymous, which is correct — no admin role):
```bash
curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost:8080/api/node_type \
  -H "Content-Type: application/json" \
  -d '{"data":{"type":"node_type","attributes":{"type":"article","name":"Article"}}}'
```
Expected: 403 (anonymous user has no administrator role).

Verify the error message changed from generic to role-based denial by checking response body.
