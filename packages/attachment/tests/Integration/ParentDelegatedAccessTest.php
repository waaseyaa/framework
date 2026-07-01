<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\Policy\ParentDelegatedAccessPolicy;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;

/**
 * Integration tests for ParentDelegatedAccessPolicy.
 *
 * Tests the policy with real EntityAccessHandler and a fake parent entity type,
 * verifying the delegation chain end-to-end. Full kernel boot with
 * auto-discovery is deferred to WP10 which exercises the complete access
 * pipeline with a running application kernel.
 *
 * See: spec.md FR-011, contracts/README.md F4.
 */
#[CoversNothing]
final class ParentDelegatedAccessTest extends TestCase
{
    private AccountInterface $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->createStub(AccountInterface::class);
    }

    /**
     * Account with view access on parent → can view attachment.
     */
    #[Test]
    public function viewAllowedWhenParentPolicyAllows(): void
    {
        $parentEntity = $this->createStub(EntityInterface::class);
        $account = $this->account;

        // Parent policy grants view access.
        $parentPolicy = new class ($account) implements AccessPolicyInterface {
            public function __construct(private readonly AccountInterface $expectedAccount) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed('parent allows');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
        };

        $parentAccessHandler = new EntityAccessHandler([$parentPolicy]);

        $storage = $this->buildStorageReturning($parentEntity);
        $entityTypeManager = $this->buildEntityTypeManager('node', $storage);

        $policy = new ParentDelegatedAccessPolicy($entityTypeManager, $parentAccessHandler);

        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
        ]);

        $result = $policy->access($attachment, 'view', $this->account);

        self::assertTrue($result->isAllowed(), 'Should be allowed when parent policy allows.');
    }

    /**
     * Account without view access on parent → cannot view attachment.
     */
    #[Test]
    public function viewForbiddenWhenParentPolicyForbids(): void
    {
        $parentEntity = $this->createStub(EntityInterface::class);

        // Parent policy denies view access.
        $parentPolicy = new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::forbidden('parent forbids');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
        };

        $parentAccessHandler = new EntityAccessHandler([$parentPolicy]);

        $storage = $this->buildStorageReturning($parentEntity);
        $entityTypeManager = $this->buildEntityTypeManager('node', $storage);

        $policy = new ParentDelegatedAccessPolicy($entityTypeManager, $parentAccessHandler);

        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '2',
        ]);

        $result = $policy->access($attachment, 'view', $this->account);

        self::assertTrue($result->isForbidden(), 'Should be forbidden when parent policy forbids.');
    }

    /**
     * Attachment with no parent registered policy → Neutral (no opinion).
     */
    #[Test]
    public function viewNeutralWhenParentHasNoPolicy(): void
    {
        $parentEntity = $this->createStub(EntityInterface::class);

        // No policies registered for the parent.
        $parentAccessHandler = new EntityAccessHandler([]);

        $storage = $this->buildStorageReturning($parentEntity);
        $entityTypeManager = $this->buildEntityTypeManager('node', $storage);

        $policy = new ParentDelegatedAccessPolicy($entityTypeManager, $parentAccessHandler);

        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '3',
        ]);

        $result = $policy->access($attachment, 'view', $this->account);

        // EntityAccessHandler.check() returns Neutral when no policy has an opinion.
        self::assertTrue($result->isNeutral(), 'Should be neutral when parent has no registered policy.');
    }

    /**
     * Missing parent entity → Neutral (referential integrity gap).
     */
    #[Test]
    public function viewNeutralWhenParentEntityMissing(): void
    {
        $parentAccessHandler = new EntityAccessHandler([]);

        $storage = $this->buildStorageReturning(null);
        $entityTypeManager = $this->buildEntityTypeManager('node', $storage);

        $policy = new ParentDelegatedAccessPolicy($entityTypeManager, $parentAccessHandler);

        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '999',
        ]);

        $result = $policy->access($attachment, 'view', $this->account);

        self::assertTrue($result->isNeutral(), 'Should be neutral when parent entity does not exist.');
    }

    /**
     * Delete operation is delegated correctly to parent's policy.
     */
    #[Test]
    public function deleteOperationDelegatesToParent(): void
    {
        $parentEntity = $this->createStub(EntityInterface::class);

        // Use a capturing policy that records which operation was received.
        $capturingPolicy = new CapturingPolicy();

        $parentAccessHandler = new EntityAccessHandler([$capturingPolicy]);

        $storage = $this->buildStorageReturning($parentEntity);
        $entityTypeManager = $this->buildEntityTypeManager('node', $storage);

        $policy = new ParentDelegatedAccessPolicy($entityTypeManager, $parentAccessHandler);

        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '5',
        ]);

        $result = $policy->access($attachment, 'delete', $this->account);

        self::assertTrue($result->isAllowed());
        self::assertSame('delete', $capturingPolicy->lastOperation, 'The delete operation must be forwarded to the parent policy.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function buildStorageReturning(?EntityInterface $entity): EntityStorageInterface
    {
        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->willReturn($entity);

        return $storage;
    }

    private function buildEntityTypeManager(string $entityTypeId, EntityStorageInterface $storage): EntityTypeManagerInterface
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getStorage')->with($entityTypeId)->willReturn($storage);
        // C-22 WP3: read path now goes through the canonical repository.
        $manager->method('getRepository')->with($entityTypeId)->willReturn(new StorageBackedStubRepository($storage));

        return $manager;
    }
}

/**
 * Test helper: access policy that records the last operation it received.
 */
final class CapturingPolicy implements AccessPolicyInterface
{
    public ?string $lastOperation = null;

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        $this->lastOperation = $operation;

        return AccessResult::allowed('capturing policy allows');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }
}
