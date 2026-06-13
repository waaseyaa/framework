# Layer 4: Entity Access Policies for Remaining Entity Types

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add access policies for the five uncovered entity types (user, media, path_alias, menu, menu_link) so that every entity type has access control.

**Architecture:** Two new policy classes (`UserAccessPolicy`, `MediaAccessPolicy`) for content entities that need ownership/permission checks. Config-like entities (path_alias, menu, menu_link) are added to the existing `ConfigEntityAccessPolicy` constructor list — no new classes needed. All policies are wired into `EntityAccessHandler` in `public/index.php`.

**Tech Stack:** PHP 8.3, PHPUnit 10.5

---

### Task 1: UserAccessPolicy — failing tests

**Files:**
- Create: `packages/user/tests/Unit/UserAccessPolicyTest.php`

**Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

#[CoversClass(UserAccessPolicy::class)]
final class UserAccessPolicyTest extends TestCase
{
    private UserAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new UserAccessPolicy();
    }

    // -----------------------------------------------------------------
    // Interface and appliesTo
    // -----------------------------------------------------------------

    public function testImplementsAccessPolicyInterface(): void
    {
        $this->assertInstanceOf(AccessPolicyInterface::class, $this->policy);
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(UserAccessPolicy::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testAppliesToUser(): void
    {
        $this->assertTrue($this->policy->appliesTo('user'));
    }

    public function testDoesNotApplyToOtherEntityTypes(): void
    {
        $this->assertFalse($this->policy->appliesTo('node'));
        $this->assertFalse($this->policy->appliesTo('media'));
        $this->assertFalse($this->policy->appliesTo(''));
    }

    // -----------------------------------------------------------------
    // View: admin bypass
    // -----------------------------------------------------------------

    public function testViewWithAdminPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 1]);
        $account = $this->createAccount(1, ['administer users']);

        $result = $this->policy->access($user, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // View: own account
    // -----------------------------------------------------------------

    public function testViewOwnAccountAllowed(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 1]);
        $account = $this->createAccount(5, ['access user profiles']);

        $result = $this->policy->access($user, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // View: other active account with permission
    // -----------------------------------------------------------------

    public function testViewOtherActiveAccountWithPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 1]);
        $account = $this->createAccount(10, ['access user profiles']);

        $result = $this->policy->access($user, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // View: other active account without permission
    // -----------------------------------------------------------------

    public function testViewOtherActiveAccountWithoutPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 1]);
        $account = $this->createAccount(10, []);

        $result = $this->policy->access($user, 'view', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // View: blocked account
    // -----------------------------------------------------------------

    public function testViewBlockedAccountDeniedForNonAdmin(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 0]);
        $account = $this->createAccount(10, ['access user profiles']);

        $result = $this->policy->access($user, 'view', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testViewBlockedAccountAllowedForAdmin(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 0]);
        $account = $this->createAccount(1, ['administer users']);

        $result = $this->policy->access($user, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // Update: own account
    // -----------------------------------------------------------------

    public function testUpdateOwnAccount(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(5, []);

        $result = $this->policy->access($user, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // Update: other account
    // -----------------------------------------------------------------

    public function testUpdateOtherAccountWithAdminPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(1, ['administer users']);

        $result = $this->policy->access($user, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testUpdateOtherAccountWithoutAdminPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(10, []);

        $result = $this->policy->access($user, 'update', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------

    public function testDeleteWithAdminPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(1, ['administer users']);

        $result = $this->policy->access($user, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testDeleteWithoutAdminPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(10, []);

        $result = $this->policy->access($user, 'delete', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testDeleteOwnAccountDenied(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(5, ['administer users']);

        $result = $this->policy->access($user, 'delete', $account);
        $this->assertTrue($result->isForbidden());
    }

    // -----------------------------------------------------------------
    // Create access
    // -----------------------------------------------------------------

    public function testCreateAccessWithAdminPermission(): void
    {
        $account = $this->createAccount(1, ['administer users']);

        $result = $this->policy->createAccess('user', 'user', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessWithoutAdminPermission(): void
    {
        $account = $this->createAccount(5, []);

        $result = $this->policy->createAccess('user', 'user', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Unknown operation
    // -----------------------------------------------------------------

    public function testUnknownOperationReturnsNeutral(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(10, ['administer users']);

        $result = $this->policy->access($user, 'unknown_op', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    /** @param string[] $permissions */
    private function createAccount(int $id, array $permissions): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('hasPermission')->willReturnCallback(
            fn(string $permission): bool => \in_array($permission, $permissions, true),
        );

        return $account;
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/UserAccessPolicyTest.php`
Expected: FAIL — class `UserAccessPolicy` not found.

---

### Task 2: UserAccessPolicy — implementation

**Files:**
- Create: `packages/user/src/UserAccessPolicy.php`

**Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for user entities.
 *
 * - View: active accounts visible with 'access user profiles'; blocked accounts admin-only.
 * - Update: own account always allowed; others require 'administer users'.
 * - Delete: admin-only; self-deletion is forbidden.
 * - Create: admin-only.
 */
final class UserAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'user';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer users')) {
            // Admin can do everything except delete themselves.
            if ($operation === 'delete' && $account->id() === $entity->id()) {
                return AccessResult::forbidden('Cannot delete own account.');
            }

            return AccessResult::allowed('User has "administer users" permission.');
        }

        assert($entity instanceof User);

        return match ($operation) {
            'view' => $this->viewAccess($entity, $account),
            'update' => $this->updateAccess($entity, $account),
            'delete' => AccessResult::neutral('Only administrators can delete accounts.'),
            default => AccessResult::neutral("No opinion on '$operation' operation."),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer users')) {
            return AccessResult::allowed('User has "administer users" permission.');
        }

        return AccessResult::neutral('Only administrators can create accounts.');
    }

    private function viewAccess(User $user, AccountInterface $account): AccessResult
    {
        if (!$user->isActive()) {
            return AccessResult::neutral('Blocked accounts are only visible to administrators.');
        }

        if ($account->hasPermission('access user profiles')) {
            return AccessResult::allowed('User has "access user profiles" permission.');
        }

        return AccessResult::neutral('User lacks "access user profiles" permission.');
    }

    private function updateAccess(User $user, AccountInterface $account): AccessResult
    {
        if ($account->id() === $user->id()) {
            return AccessResult::allowed('Users can edit their own account.');
        }

        return AccessResult::neutral('Only administrators or account owners can edit accounts.');
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/user/tests/Unit/UserAccessPolicyTest.php`
Expected: All tests PASS.

**Step 5: Commit**

```bash
git add packages/user/src/UserAccessPolicy.php packages/user/tests/Unit/UserAccessPolicyTest.php
git commit -m "feat(user): add UserAccessPolicy with ownership and admin checks"
```

---

### Task 3: MediaAccessPolicy — failing tests

**Files:**
- Create: `packages/media/tests/Unit/MediaAccessPolicyTest.php`

**Step 6: Write the failing tests**

Media follows a similar pattern to Node: ownership, published/unpublished, admin bypass. The key differences:
- Media uses `getOwnerId(): ?int` (nullable) instead of Node's `getAuthorId(): int`.
- Media uses `administer media` instead of `administer nodes`.
- Media bundles use `create {bundle} media` permission pattern.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\MediaAccessPolicy;

#[CoversClass(MediaAccessPolicy::class)]
final class MediaAccessPolicyTest extends TestCase
{
    private MediaAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new MediaAccessPolicy();
    }

    // -----------------------------------------------------------------
    // Interface and appliesTo
    // -----------------------------------------------------------------

    public function testImplementsAccessPolicyInterface(): void
    {
        $this->assertInstanceOf(AccessPolicyInterface::class, $this->policy);
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(MediaAccessPolicy::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testAppliesToMedia(): void
    {
        $this->assertTrue($this->policy->appliesTo('media'));
    }

    public function testDoesNotApplyToOtherEntityTypes(): void
    {
        $this->assertFalse($this->policy->appliesTo('node'));
        $this->assertFalse($this->policy->appliesTo('user'));
        $this->assertFalse($this->policy->appliesTo(''));
    }

    // -----------------------------------------------------------------
    // View: published media
    // -----------------------------------------------------------------

    public function testViewPublishedMediaWithPermission(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'status' => true, 'uid' => 5]);
        $account = $this->createAccount(10, ['access media']);

        $result = $this->policy->access($media, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testViewPublishedMediaWithoutPermission(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'status' => true, 'uid' => 5]);
        $account = $this->createAccount(10, []);

        $result = $this->policy->access($media, 'view', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // View: unpublished media
    // -----------------------------------------------------------------

    public function testViewUnpublishedMediaAsOwnerWithPermission(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'status' => false, 'uid' => 5]);
        $account = $this->createAccount(5, ['view own unpublished media']);

        $result = $this->policy->access($media, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testViewUnpublishedMediaAsOwnerWithoutPermission(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'status' => false, 'uid' => 5]);
        $account = $this->createAccount(5, []);

        $result = $this->policy->access($media, 'view', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testViewUnpublishedMediaAsNonOwner(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'status' => false, 'uid' => 5]);
        $account = $this->createAccount(10, ['view own unpublished media']);

        $result = $this->policy->access($media, 'view', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testViewUnpublishedMediaAsAdmin(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'status' => false, 'uid' => 5]);
        $account = $this->createAccount(1, ['administer media']);

        $result = $this->policy->access($media, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------

    public function testUpdateOwnMedia(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(5, ['edit own image media']);

        $result = $this->policy->access($media, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testUpdateOwnMediaWithoutPermission(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(5, []);

        $result = $this->policy->access($media, 'update', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testUpdateAnyMedia(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(10, ['edit any image media']);

        $result = $this->policy->access($media, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testUpdateOtherMediaWithoutAnyPermission(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(10, ['edit own image media']);

        $result = $this->policy->access($media, 'update', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testUpdateWithAdminPermission(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(1, ['administer media']);

        $result = $this->policy->access($media, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------

    public function testDeleteOwnMedia(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(5, ['delete own image media']);

        $result = $this->policy->access($media, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testDeleteOwnMediaWithoutPermission(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(5, []);

        $result = $this->policy->access($media, 'delete', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testDeleteAnyMedia(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(10, ['delete any image media']);

        $result = $this->policy->access($media, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testDeleteOtherMediaWithoutAnyPermission(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(10, ['delete own image media']);

        $result = $this->policy->access($media, 'delete', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testDeleteWithAdminPermission(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(1, ['administer media']);

        $result = $this->policy->access($media, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // Create access
    // -----------------------------------------------------------------

    public function testCreateAccessWithPermission(): void
    {
        $account = $this->createAccount(5, ['create image media']);

        $result = $this->policy->createAccess('media', 'image', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessWithoutPermission(): void
    {
        $account = $this->createAccount(5, []);

        $result = $this->policy->createAccess('media', 'image', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testCreateAccessWithAdminPermission(): void
    {
        $account = $this->createAccount(1, ['administer media']);

        $result = $this->policy->createAccess('media', 'image', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessWrongBundlePermission(): void
    {
        $account = $this->createAccount(5, ['create video media']);

        $result = $this->policy->createAccess('media', 'image', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Unknown operation
    // -----------------------------------------------------------------

    public function testUnknownOperationReturnsNeutral(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(10, ['access media']);

        $result = $this->policy->access($media, 'unknown_op', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Bundle-specific permissions
    // -----------------------------------------------------------------

    public function testEditPermissionIsBundleSpecific(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(5, ['edit own video media']);

        $result = $this->policy->access($media, 'update', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testDeletePermissionIsBundleSpecific(): void
    {
        $media = new Media(['mid' => 1, 'bundle' => 'image', 'uid' => 5]);
        $account = $this->createAccount(5, ['delete own video media']);

        $result = $this->policy->access($media, 'delete', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    /** @param string[] $permissions */
    private function createAccount(int $id, array $permissions): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('hasPermission')->willReturnCallback(
            fn(string $permission): bool => \in_array($permission, $permissions, true),
        );

        return $account;
    }
}
```

**Step 7: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/media/tests/Unit/MediaAccessPolicyTest.php`
Expected: FAIL — class `MediaAccessPolicy` not found.

---

### Task 4: MediaAccessPolicy — implementation

**Files:**
- Create: `packages/media/src/MediaAccessPolicy.php`

**Step 8: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for media entities.
 *
 * Mirrors the Node access model: admin bypass, ownership checks for
 * edit/delete, published status for view, bundle-specific permissions.
 */
final class MediaAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'media';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer media')) {
            return AccessResult::allowed('User has "administer media" permission.');
        }

        assert($entity instanceof Media);

        $bundle = $entity->bundle();
        $isOwner = $entity->getOwnerId() !== null && $account->id() === $entity->getOwnerId();

        return match ($operation) {
            'view' => $this->viewAccess($entity, $account, $isOwner),
            'update' => $this->editAccess($bundle, $account, $isOwner),
            'delete' => $this->deleteAccess($bundle, $account, $isOwner),
            default => AccessResult::neutral("No opinion on '$operation' operation."),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer media')) {
            return AccessResult::allowed('User has "administer media" permission.');
        }

        if ($account->hasPermission("create $bundle media")) {
            return AccessResult::allowed("User has 'create $bundle media' permission.");
        }

        return AccessResult::neutral("User lacks 'create $bundle media' permission.");
    }

    private function viewAccess(Media $media, AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($media->isPublished()) {
            if ($account->hasPermission('access media')) {
                return AccessResult::allowed('Published media and user has "access media" permission.');
            }

            return AccessResult::neutral('User lacks "access media" permission.');
        }

        if ($isOwner && $account->hasPermission('view own unpublished media')) {
            return AccessResult::allowed('Owner viewing own unpublished media.');
        }

        return AccessResult::neutral('User cannot view this unpublished media.');
    }

    private function editAccess(string $bundle, AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($account->hasPermission("edit any $bundle media")) {
            return AccessResult::allowed("User has 'edit any $bundle media' permission.");
        }

        if ($isOwner && $account->hasPermission("edit own $bundle media")) {
            return AccessResult::allowed("Owner has 'edit own $bundle media' permission.");
        }

        return AccessResult::neutral("User lacks edit permission for $bundle media.");
    }

    private function deleteAccess(string $bundle, AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($account->hasPermission("delete any $bundle media")) {
            return AccessResult::allowed("User has 'delete any $bundle media' permission.");
        }

        if ($isOwner && $account->hasPermission("delete own $bundle media")) {
            return AccessResult::allowed("Owner has 'delete own $bundle media' permission.");
        }

        return AccessResult::neutral("User lacks delete permission for $bundle media.");
    }
}
```

**Step 9: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/media/tests/Unit/MediaAccessPolicyTest.php`
Expected: All tests PASS.

**Step 10: Commit**

```bash
git add packages/media/src/MediaAccessPolicy.php packages/media/tests/Unit/MediaAccessPolicyTest.php
git commit -m "feat(media): add MediaAccessPolicy with ownership and bundle permissions"
```

---

### Task 5: Extend ConfigEntityAccessPolicy and wire all policies

**Files:**
- Modify: `public/index.php:336-346` (add new policies and extend config list)
- Modify: `public/index.php:70-76` (add imports)

**Step 11: Add imports to index.php**

Add after the existing `use Waaseyaa\Taxonomy\TermAccessPolicy;` line (line 76):

```php
use Waaseyaa\User\UserAccessPolicy;
use Waaseyaa\Media\MediaAccessPolicy;
```

**Step 12: Wire new policies and extend ConfigEntityAccessPolicy list**

Replace lines 336-346:

```php
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
```

With:

```php
$accessHandler = new EntityAccessHandler([
    new NodeAccessPolicy(),
    new TermAccessPolicy(),
    new UserAccessPolicy(),
    new MediaAccessPolicy(),
    new ConfigEntityAccessPolicy(entityTypeIds: [
        'node_type',
        'taxonomy_vocabulary',
        'media_type',
        'workflow',
        'pipeline',
        'path_alias',
        'menu',
        'menu_link',
    ]),
]);
```

**Step 13: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All tests PASS.

**Step 14: Commit**

```bash
git add public/index.php
git commit -m "feat(access): wire UserAccessPolicy, MediaAccessPolicy, and extend config coverage

All entity types now have access policies. UserAccessPolicy and
MediaAccessPolicy handle content entities with ownership checks.
ConfigEntityAccessPolicy extended to cover path_alias, menu, and
menu_link."
```

---

### Task 6: Update roadmap

**Files:**
- Modify: `docs/roadmap.md`

**Step 15: Update roadmap Layer 4 status**

Mark Layer 4 as done. The exact edit depends on current roadmap format — change the status indicator for "Uncovered entity policies" from planned/next to done.

**Step 16: Commit**

```bash
git add docs/roadmap.md
git commit -m "docs: mark Layer 4 entity access policies as complete"
```
