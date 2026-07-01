<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Tests\Unit\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
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
 * Unit tests for ParentDelegatedAccessPolicy.
 *
 * All external collaborators are stubbed to isolate delegation logic.
 */
#[CoversClass(ParentDelegatedAccessPolicy::class)]
final class ParentDelegatedAccessPolicyTest extends TestCase
{
    private EntityTypeManagerInterface $entityTypeManager;

    private EntityAccessHandler $accessHandler;

    private AccountInterface $account;

    private ParentDelegatedAccessPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
        $this->accessHandler = $this->createStub(EntityAccessHandler::class);
        $this->account = $this->createStub(AccountInterface::class);

        $this->policy = new ParentDelegatedAccessPolicy(
            $this->entityTypeManager,
            $this->accessHandler,
        );
    }

    // ── appliesTo ──────────────────────────────────────────────────────────────

    #[Test]
    public function appliesToReturnsTrueForAttachment(): void
    {
        self::assertTrue($this->policy->appliesTo('attachment'));
    }

    #[Test]
    public function appliesToReturnsFalseForOtherEntityTypes(): void
    {
        self::assertFalse($this->policy->appliesTo('node'));
        self::assertFalse($this->policy->appliesTo('user'));
        self::assertFalse($this->policy->appliesTo(''));
    }

    // ── Non-Attachment entity (defensive) ──────────────────────────────────────

    #[Test]
    public function accessReturnsNeutralForNonAttachmentEntity(): void
    {
        $otherEntity = $this->createStub(EntityInterface::class);

        $result = $this->policy->access($otherEntity, 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    // ── Empty parent reference ─────────────────────────────────────────────────

    #[Test]
    public function accessReturnsNeutralWhenParentEntityTypeIsEmpty(): void
    {
        $attachment = new Attachment([
            'parent_entity_type' => '',
            'parent_entity_id' => '42',
        ]);

        $result = $this->policy->access($attachment, 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    #[Test]
    public function accessReturnsNeutralWhenParentEntityIdIsEmpty(): void
    {
        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '',
        ]);

        $result = $this->policy->access($attachment, 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    #[Test]
    public function accessReturnsNeutralWhenBothParentFieldsAreEmpty(): void
    {
        $attachment = new Attachment([
            'parent_entity_type' => '',
            'parent_entity_id' => '',
        ]);

        $result = $this->policy->access($attachment, 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    // ── Missing parent entity ──────────────────────────────────────────────────

    #[Test]
    public function accessReturnsNeutralWhenParentEntityNotFound(): void
    {
        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '999',
        ]);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->with('999')->willReturn(null);

        $this->entityTypeManager
            ->method('getStorage')
            ->with('node')
            ->willReturn($storage);
        $this->entityTypeManager->method('getRepository')->with('node')->willReturn(new StorageBackedStubRepository($storage));

        $result = $this->policy->access($attachment, 'view', $this->account);

        self::assertTrue($result->isNeutral());
    }

    // ── View delegation ────────────────────────────────────────────────────────

    #[Test]
    public function accessDelegatesViewAllowedFromParent(): void
    {
        $parentEntity = $this->createStub(EntityInterface::class);
        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '1',
        ]);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->with('1')->willReturn($parentEntity);
        $this->entityTypeManager->method('getStorage')->with('node')->willReturn($storage);
        $this->entityTypeManager->method('getRepository')->with('node')->willReturn(new StorageBackedStubRepository($storage));

        $this->accessHandler
            ->method('check')
            ->with($parentEntity, 'view', $this->account)
            ->willReturn(AccessResult::allowed('parent allows view'));

        $result = $this->policy->access($attachment, 'view', $this->account);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function accessDelegatesViewForbiddenFromParent(): void
    {
        $parentEntity = $this->createStub(EntityInterface::class);
        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '2',
        ]);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->with('2')->willReturn($parentEntity);
        $this->entityTypeManager->method('getStorage')->with('node')->willReturn($storage);
        $this->entityTypeManager->method('getRepository')->with('node')->willReturn(new StorageBackedStubRepository($storage));

        $this->accessHandler
            ->method('check')
            ->with($parentEntity, 'view', $this->account)
            ->willReturn(AccessResult::forbidden('parent forbids view'));

        $result = $this->policy->access($attachment, 'view', $this->account);

        self::assertTrue($result->isForbidden());
    }

    // ── Update delegation ──────────────────────────────────────────────────────

    #[Test]
    public function accessDelegatesUpdateAllowedFromParent(): void
    {
        $parentEntity = $this->createStub(EntityInterface::class);
        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '3',
        ]);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->with('3')->willReturn($parentEntity);
        $this->entityTypeManager->method('getStorage')->with('node')->willReturn($storage);
        $this->entityTypeManager->method('getRepository')->with('node')->willReturn(new StorageBackedStubRepository($storage));

        $this->accessHandler
            ->method('check')
            ->with($parentEntity, 'update', $this->account)
            ->willReturn(AccessResult::allowed('parent allows update'));

        $result = $this->policy->access($attachment, 'update', $this->account);

        self::assertTrue($result->isAllowed());
    }

    // ── Delete delegation ──────────────────────────────────────────────────────

    #[Test]
    public function accessDelegatesDeleteOperationCorrectly(): void
    {
        $parentEntity = $this->createStub(EntityInterface::class);
        $attachment = new Attachment([
            'parent_entity_type' => 'node',
            'parent_entity_id' => '4',
        ]);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->with('4')->willReturn($parentEntity);
        $this->entityTypeManager->method('getStorage')->with('node')->willReturn($storage);
        $this->entityTypeManager->method('getRepository')->with('node')->willReturn(new StorageBackedStubRepository($storage));

        $this->accessHandler
            ->method('check')
            ->with($parentEntity, 'delete', $this->account)
            ->willReturn(AccessResult::allowed('parent allows delete'));

        $result = $this->policy->access($attachment, 'delete', $this->account);

        self::assertTrue($result->isAllowed());
    }

    // ── createAccess ───────────────────────────────────────────────────────────

    #[Test]
    public function createAccessReturnsNeutral(): void
    {
        $result = $this->policy->createAccess('attachment', 'attachment', $this->account);

        self::assertTrue($result->isNeutral());
    }

    // ── implements AccessPolicyInterface ──────────────────────────────────────

    #[Test]
    public function implementsAccessPolicyInterface(): void
    {
        self::assertInstanceOf(AccessPolicyInterface::class, $this->policy);
    }
}
