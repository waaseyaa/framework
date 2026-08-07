<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Attribute\AccessPolicy;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;

#[CoversClass(EntityAccessHandler::class)]
final class EntityAccessHandlerBundleFilterTest extends TestCase
{
    private function entity(string $typeId, string $bundle): EntityInterface
    {
        $entity = $this->createStub(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn($typeId);
        $entity->method('bundle')->willReturn($bundle);

        return $entity;
    }

    private function account(): AccountInterface
    {
        return $this->createStub(AccountInterface::class);
    }

    #[Test]
    public function policyWithMatchingBundleIsConsulted(): void
    {
        $policy = new
            #[AccessPolicy(id: 'biz_only', entityTypes: ['group'], bundles: ['business'])]
            class implements AccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return AccessResult::allowed('business policy');
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::allowed('business policy');
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'group';
                }
            };

        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check($this->entity('group', 'business'), 'view', $this->account());

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function policyWithNonMatchingBundleIsSkipped(): void
    {
        $policy = new
            #[AccessPolicy(id: 'biz_only', entityTypes: ['group'], bundles: ['business'])]
            class implements AccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return AccessResult::allowed('business policy');
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::allowed('business policy');
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'group';
                }
            };

        $handler = new EntityAccessHandler([$policy]);

        $result = $handler->check($this->entity('group', 'organization'), 'view', $this->account());

        self::assertTrue($result->isNeutral(), 'Policy declared for bundles=[business] must not run on an organization entity.');
    }

    #[Test]
    public function policyWithMultipleBundlesMatchesAny(): void
    {
        $policy = new
            #[AccessPolicy(id: 'both', entityTypes: ['group'], bundles: ['business', 'organization'])]
            class implements AccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return AccessResult::allowed('multi-bundle policy');
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::allowed('multi-bundle policy');
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'group';
                }
            };

        $handler = new EntityAccessHandler([$policy]);

        self::assertTrue(
            $handler->check($this->entity('group', 'business'), 'view', $this->account())->isAllowed(),
        );
        self::assertTrue(
            $handler->check($this->entity('group', 'organization'), 'view', $this->account())->isAllowed(),
        );
    }

    #[Test]
    public function policyWithoutAttributeAppliesToEveryBundle(): void
    {
        // Back-compat: policies carrying no AccessPolicy attribute (or declaring
        // empty bundles) keep pre-spec semantics and run on every bundle.
        $policy = new class implements AccessPolicyInterface {
            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed('legacy policy');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed('legacy policy');
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'group';
            }
        };

        $handler = new EntityAccessHandler([$policy]);

        foreach (['business', 'organization', 'anything'] as $bundle) {
            self::assertTrue(
                $handler->check($this->entity('group', $bundle), 'view', $this->account())->isAllowed(),
                "Legacy policy must apply to bundle '$bundle'.",
            );
        }
    }

    #[Test]
    public function policyWithEmptyBundlesAppliesToEveryBundle(): void
    {
        $policy = new
            #[AccessPolicy(id: 'cover_all', entityTypes: ['group'], bundles: [])]
            class implements AccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return AccessResult::allowed('cover-all policy');
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::allowed('cover-all policy');
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'group';
                }
            };

        $handler = new EntityAccessHandler([$policy]);

        self::assertTrue($handler->check($this->entity('group', 'business'), 'view', $this->account())->isAllowed());
        self::assertTrue($handler->check($this->entity('group', 'organization'), 'view', $this->account())->isAllowed());
    }

    #[Test]
    public function checkCreateAccessRespectsBundleFilter(): void
    {
        $policy = new
            #[AccessPolicy(id: 'biz_create', entityTypes: ['group'], bundles: ['business'])]
            class implements AccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    return AccessResult::neutral();
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::allowed('business create');
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'group';
                }
            };

        $handler = new EntityAccessHandler([$policy]);

        self::assertTrue(
            $handler->checkCreateAccess('group', 'business', $this->account())->isAllowed(),
            'Bundle filter matches — policy runs.',
        );
        self::assertTrue(
            $handler->checkCreateAccess('group', 'organization', $this->account())->isNeutral(),
            'Bundle filter excludes — policy skipped; default neutral stands.',
        );
    }

    #[Test]
    public function checkFieldAccessRespectsBundleFilter(): void
    {
        $policy = new
            #[AccessPolicy(id: 'biz_fields', entityTypes: ['group'], bundles: ['business'])]
            class implements AccessPolicyInterface, FieldAccessPolicyInterface {
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
                    return $entityTypeId === 'group';
                }

                public function fieldAccess(
                    EntityInterface $entity,
                    string $fieldName,
                    string $operation,
                    AccountInterface $account,
                ): AccessResult {
                    return AccessResult::forbidden('business-only field rule');
                }
            };

        $handler = new EntityAccessHandler([$policy]);

        self::assertTrue(
            $handler->checkFieldAccess($this->entity('group', 'business'), 'community_id', 'view', $this->account())
                ->isForbidden(),
            'Business bundle must see the business-only field rule fire.',
        );
        self::assertTrue(
            $handler->checkFieldAccess($this->entity('group', 'organization'), 'community_id', 'view', $this->account())
                ->isNeutral(),
            'Organization bundle must be exempt from a business-scoped field policy.',
        );
    }
}
