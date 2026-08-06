<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\ConfigEntityAccessPolicy;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
            'workflow',
            'pipeline',
        ]);
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
        $reflection = new \ReflectionClass(ConfigEntityAccessPolicy::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testAppliesToCoveredTypes(): void
    {
        $this->assertTrue($this->policy->appliesTo('node_type'));
        $this->assertTrue($this->policy->appliesTo('taxonomy_vocabulary'));
        $this->assertTrue($this->policy->appliesTo('media_type'));
        $this->assertTrue($this->policy->appliesTo('workflow'));
        $this->assertTrue($this->policy->appliesTo('pipeline'));
    }

    public function testDoesNotApplyToOtherEntityTypes(): void
    {
        $this->assertFalse($this->policy->appliesTo('node'));
        $this->assertFalse($this->policy->appliesTo('user'));
        $this->assertFalse($this->policy->appliesTo('taxonomy_term'));
        $this->assertFalse($this->policy->appliesTo(''));
    }

    // -----------------------------------------------------------------
    // access() — administrator role
    // -----------------------------------------------------------------

    public function testAccessAllowedForAdministrator(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $account = $this->createAccount(['administrator']);

        $result = $this->policy->access($entity, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testAccessAllowedForAdministratorOnUpdate(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $account = $this->createAccount(['administrator']);

        $result = $this->policy->access($entity, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testAccessAllowedForAdministratorOnDelete(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $account = $this->createAccount(['administrator']);

        $result = $this->policy->access($entity, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // access() — non-administrator
    // -----------------------------------------------------------------

    public function testAccessNeutralForNonAdministrator(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $account = $this->createAccount(['authenticated']);

        $result = $this->policy->access($entity, 'view', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testAccessNeutralForAnonymous(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $account = $this->createAccount([]);

        $result = $this->policy->access($entity, 'update', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // createAccess() — administrator role
    // -----------------------------------------------------------------

    public function testCreateAccessAllowedForAdministrator(): void
    {
        $account = $this->createAccount(['administrator']);

        $result = $this->policy->createAccess('node_type', '', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessAllowedForAdministratorWithBundle(): void
    {
        $account = $this->createAccount(['administrator']);

        $result = $this->policy->createAccess('taxonomy_vocabulary', 'tags', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // createAccess() — non-administrator
    // -----------------------------------------------------------------

    public function testCreateAccessNeutralForNonAdministrator(): void
    {
        $account = $this->createAccount(['authenticated']);

        $result = $this->policy->createAccess('node_type', '', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testCreateAccessNeutralForAnonymous(): void
    {
        $account = $this->createAccount([]);

        $result = $this->policy->createAccess('media_type', '', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Administrator among multiple roles
    // -----------------------------------------------------------------

    public function testAccessAllowedWhenAdministratorIsOneOfMultipleRoles(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $account = $this->createAccount(['authenticated', 'editor', 'administrator']);

        $result = $this->policy->access($entity, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessAllowedWhenAdministratorIsOneOfMultipleRoles(): void
    {
        $account = $this->createAccount(['authenticated', 'editor', 'administrator']);

        $result = $this->policy->createAccess('node_type', '', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // Constructor validation
    // -----------------------------------------------------------------

    public function testEmptyEntityTypeIdsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one entity type ID is required.');
        new ConfigEntityAccessPolicy([]);
    }

    public function testNonStringEntityTypeIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each entity type ID must be a non-empty string.');
        new ConfigEntityAccessPolicy(['node_type', 42]); // @phpstan-ignore argument.type
    }

    public function testEmptyStringEntityTypeIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each entity type ID must be a non-empty string.');
        new ConfigEntityAccessPolicy(['']); // @phpstan-ignore argument.type
    }

    public function testSingleEntityTypeId(): void
    {
        $policy = new ConfigEntityAccessPolicy(['node_type']);
        $this->assertTrue($policy->appliesTo('node_type'));
        $this->assertFalse($policy->appliesTo('taxonomy_vocabulary'));
    }

    // -----------------------------------------------------------------
    // Integration: ConfigEntityAccessPolicy through EntityAccessHandler
    // -----------------------------------------------------------------

    public function testHandlerGrantsCreateAccessForAdminOnConfigEntity(): void
    {
        $handler = new EntityAccessHandler([$this->policy]);
        $account = $this->createAccount(['administrator']);

        $result = $handler->checkCreateAccess('node_type', '', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testHandlerDeniesCreateAccessForNonAdminOnConfigEntity(): void
    {
        $handler = new EntityAccessHandler([$this->policy]);
        $account = $this->createAccount(['authenticated']);

        $result = $handler->checkCreateAccess('node_type', '', $account);
        $this->assertFalse($result->isAllowed());
    }

    public function testHandlerReturnsNeutralForUncoveredEntityType(): void
    {
        $handler = new EntityAccessHandler([$this->policy]);
        $account = $this->createAccount(['administrator']);

        $result = $handler->checkCreateAccess('user', '', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    /**
     * Creates a mock AccountInterface with the given roles.
     *
     * @param string[] $roles
     */
    private function createAccount(array $roles): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('getRoles')->willReturn($roles);

        return $account;
    }
}
