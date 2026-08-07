<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\ContextAwareAccessPolicyInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;

/**
 * M-006 WP09 — translate operation.
 *
 * Contract (FR-047..FR-049):
 *  - 'translate' is a recognized operation alongside view/update/delete.
 *  - When the aggregate result is Neutral, EntityAccessHandler falls through
 *    to 'update' (translate ⊆ update by default).
 *  - An explicit Forbidden on 'translate' is honored and is NOT overridden by
 *    the update fallback.
 *  - Context for 'translate' includes ['langcode' => $lc] and is passed to
 *    policies implementing ContextAwareAccessPolicyInterface.
 */
#[CoversClass(EntityAccessHandler::class)]
final class TranslateOperationTest extends TestCase
{
    private function createEntity(string $typeId = 'node', string $bundle = 'article'): EntityInterface
    {
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($typeId);
        $entity->method('bundle')->willReturn($bundle);

        return $entity;
    }

    private function createAccount(): AccountInterface
    {
        return $this->createStub(AccountInterface::class);
    }

    #[Test]
    public function translateOperationIsRecognized(): void
    {
        $this->assertContains('translate', EntityAccessHandler::RECOGNIZED_OPERATIONS);
        $this->assertContains('view', EntityAccessHandler::RECOGNIZED_OPERATIONS);
        $this->assertContains('update', EntityAccessHandler::RECOGNIZED_OPERATIONS);
        $this->assertContains('delete', EntityAccessHandler::RECOGNIZED_OPERATIONS);
    }

    #[Test]
    public function translateFallsThroughToUpdateWhenNeutral(): void
    {
        // Policy: Allowed for 'update', Neutral for everything else.
        $policy = $this->createStub(AccessPolicyInterface::class);
        $policy->method('appliesTo')->willReturn(true);
        $policy->method('access')->willReturnCallback(
            fn(EntityInterface $e, string $op, AccountInterface $a): AccessResult =>
                $op === 'update'
                    ? AccessResult::allowed('can update')
                    : AccessResult::neutral(),
        );

        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check($this->createEntity(), 'translate', $this->createAccount());

        $this->assertTrue(
            $result->isAllowed(),
            'translate Neutral should fall through to update and pick up the Allowed verdict',
        );
    }

    #[Test]
    public function translateExplicitForbiddenIsHonoredOverUpdateFallback(): void
    {
        // Policy: Allowed for update, Forbidden for translate. Forbidden on
        // translate must NOT be overridden by the update fallback.
        $policy = $this->createStub(AccessPolicyInterface::class);
        $policy->method('appliesTo')->willReturn(true);
        $policy->method('access')->willReturnCallback(
            fn(EntityInterface $e, string $op, AccountInterface $a): AccessResult =>
                match ($op) {
                    'translate' => AccessResult::forbidden('locked for translation'),
                    'update' => AccessResult::allowed('can update'),
                    default => AccessResult::neutral(),
                },
        );

        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check($this->createEntity(), 'translate', $this->createAccount());

        $this->assertTrue($result->isForbidden(), 'explicit Forbidden on translate must win');
        $this->assertSame('locked for translation', $result->reason);
    }

    #[Test]
    public function translateNeutralWithUpdateForbiddenStaysForbidden(): void
    {
        // Translate is Neutral so it falls through to update; update is Forbidden.
        $policy = $this->createStub(AccessPolicyInterface::class);
        $policy->method('appliesTo')->willReturn(true);
        $policy->method('access')->willReturnCallback(
            fn(EntityInterface $e, string $op, AccountInterface $a): AccessResult =>
                $op === 'update'
                    ? AccessResult::forbidden('cannot update either')
                    : AccessResult::neutral(),
        );

        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check($this->createEntity(), 'translate', $this->createAccount());

        $this->assertTrue($result->isForbidden());
        $this->assertSame('cannot update either', $result->reason);
    }

    #[Test]
    public function translateContextContainsLangcode(): void
    {
        $capturedContext = null;

        // Context-aware policy captures the context bag for later inspection.
        $policy = new class($capturedContext) implements AccessPolicyInterface, ContextAwareAccessPolicyInterface {
            /** @param array<string, mixed>|null $captured */
            public function __construct(public ?array &$captured) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function accessWithContext(
                EntityInterface $entity,
                string $operation,
                AccountInterface $account,
                array $context,
            ): AccessResult {
                $this->captured = $context;

                return AccessResult::allowed('captured');
            }
        };

        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check(
            $this->createEntity(),
            'translate',
            $this->createAccount(),
            ['langcode' => 'fr'],
        );

        $this->assertTrue($result->isAllowed());
        $this->assertIsArray($capturedContext);
        $this->assertArrayHasKey('langcode', $capturedContext);
        $this->assertSame('fr', $capturedContext['langcode']);
    }

    #[Test]
    public function policyDiffersByLangcode(): void
    {
        // Fixture policy: allow translate into French, forbid translate into
        // Klingon (tlh). Demonstrates that context drives per-langcode verdicts.
        $policy = new class implements AccessPolicyInterface, ContextAwareAccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'node';
            }

            public function accessWithContext(
                EntityInterface $entity,
                string $operation,
                AccountInterface $account,
                array $context,
            ): AccessResult {
                if ($operation !== 'translate') {
                    return AccessResult::neutral();
                }

                $lc = $context['langcode'] ?? '';

                return match ($lc) {
                    'fr' => AccessResult::allowed('French translator'),
                    'tlh' => AccessResult::forbidden('no Klingon, sorry'),
                    default => AccessResult::neutral(),
                };
            }
        };

        $handler = new EntityAccessHandler([$policy]);
        $entity = $this->createEntity();
        $account = $this->createAccount();

        $french = $handler->check($entity, 'translate', $account, ['langcode' => 'fr']);
        $this->assertTrue($french->isAllowed(), 'French should be allowed');
        $this->assertSame('French translator', $french->reason);

        $klingon = $handler->check($entity, 'translate', $account, ['langcode' => 'tlh']);
        $this->assertTrue($klingon->isForbidden(), 'Klingon should be forbidden');
        $this->assertSame('no Klingon, sorry', $klingon->reason);

        // Spanish: neutral → falls through to update → no update opinion → still neutral.
        $spanish = $handler->check($entity, 'translate', $account, ['langcode' => 'es']);
        $this->assertTrue(
            $spanish->isNeutral(),
            'Spanish: neutral translate falls through to update, which is also neutral',
        );
    }

    #[Test]
    public function nonContextAwarePolicyStillReceivesStandardAccessCall(): void
    {
        // Legacy policy that does NOT implement ContextAwareAccessPolicyInterface
        // should still be invoked via access() and produce a usable verdict.
        $policy = $this->createMock(AccessPolicyInterface::class);
        $policy->method('appliesTo')->willReturn(true);
        $policy->expects($this->once())
            ->method('access')
            ->willReturn(AccessResult::allowed('legacy ok'));

        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check(
            $this->createEntity(),
            'translate',
            $this->createAccount(),
            ['langcode' => 'fr'],
        );

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function updateFallthroughRecursesOnlyOnce(): void
    {
        // Update returns Neutral too — ensures we don't infinite-loop; result is
        // Neutral and the handler returns cleanly.
        $policy = $this->createStub(AccessPolicyInterface::class);
        $policy->method('appliesTo')->willReturn(true);
        $policy->method('access')->willReturn(AccessResult::neutral());

        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check($this->createEntity(), 'translate', $this->createAccount());

        $this->assertTrue($result->isNeutral());
    }
}
