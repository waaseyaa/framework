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
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('hasPermission')->willReturnCallback(
            fn(string $permission): bool => \in_array($permission, $permissions, true),
        );

        return $account;
    }
}
