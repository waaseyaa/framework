<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Relationship\RelationshipEndpointVisibilityPolicy;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\Tests\Fixtures\ArrayAccount;

/**
 * Isolated unit coverage for {@see RelationshipEndpointVisibilityPolicy}: the
 * field-access policy that redacts a relationship edge's endpoint-identity
 * fields (`to_entity_type`/`to_entity_id`, `from_entity_type`/`from_entity_id`)
 * when the viewing account cannot view the referenced endpoint entity.
 *
 * Mirrors {@see \Waaseyaa\Genealogy\Tests\Unit\GenealogyRelationshipAccessPolicyTest},
 * but exercises fieldAccess() (field-level redaction) rather than access()
 * (whole-edge denial) — this policy is deliberately field-only.
 */
#[CoversClass(RelationshipEndpointVisibilityPolicy::class)]
final class RelationshipEndpointVisibilityPolicyTest extends TestCase
{
    private function edge(array $overrides = []): Relationship
    {
        return new Relationship($overrides + [
            'rid' => 1,
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '10',
            'to_entity_type' => 'node',
            'to_entity_id' => '20',
            'directionality' => 'directed',
            'status' => 1,
        ]);
    }

    /**
     * @param ?\Closure(string): (\Waaseyaa\Entity\EntityInterface|null) $endpointLoader Stubs repository->find().
     * @return array{0: RelationshipEndpointVisibilityPolicy, 1: EntityAccessHandler&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function policy(bool $knownType = true, ?\Closure $endpointLoader = null): array
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        if ($endpointLoader !== null) {
            $repository->method('find')->willReturnCallback($endpointLoader);
        }

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn($knownType);
        $etm->method('getRepository')->willReturn($repository);

        $handler = $this->createMock(EntityAccessHandler::class);

        return [new RelationshipEndpointVisibilityPolicy($etm, $handler), $handler];
    }

    #[Test]
    public function applies_only_to_relationship_entity_type(): void
    {
        [$policy] = $this->policy();

        self::assertTrue($policy->appliesTo('relationship'));
        self::assertFalse($policy->appliesTo('node'));
    }

    #[Test]
    public function implements_both_access_and_field_access_interfaces(): void
    {
        [$policy] = $this->policy();

        self::assertInstanceOf(AccessPolicyInterface::class, $policy);
        self::assertInstanceOf(FieldAccessPolicyInterface::class, $policy);
    }

    #[Test]
    public function never_opines_at_the_entity_level(): void
    {
        [$policy] = $this->policy();
        $account = new ArrayAccount(0, ['access content']);
        $edge = $this->edge();

        foreach (['view', 'update', 'delete'] as $operation) {
            self::assertTrue(
                $policy->access($edge, $operation, $account)->isNeutral(),
                "access() must stay neutral for '$operation' — entity-level view/update/delete stays owned by RelationshipAccessPolicy.",
            );
        }
        self::assertTrue($policy->createAccess('relationship', 'default', $account)->isNeutral());
    }

    #[Test]
    public function to_endpoint_fields_are_forbidden_when_endpoint_is_not_viewable(): void
    {
        [$policy, $handler] = $this->policy(endpointLoader: fn(string $id) => $this->createMock(\Waaseyaa\Entity\EntityInterface::class));
        $handler->method('check')->willReturn(AccessResult::forbidden('hidden'));

        $account = new ArrayAccount(0, ['access content']);
        $edge = $this->edge();

        self::assertTrue($policy->fieldAccess($edge, 'to_entity_type', 'view', $account)->isForbidden());
        self::assertTrue($policy->fieldAccess($edge, 'to_entity_id', 'view', $account)->isForbidden());
    }

    #[Test]
    public function to_endpoint_fields_are_neutral_when_endpoint_is_viewable(): void
    {
        [$policy, $handler] = $this->policy(endpointLoader: fn(string $id) => $this->createMock(\Waaseyaa\Entity\EntityInterface::class));
        $handler->method('check')->willReturn(AccessResult::allowed('visible'));

        $account = new ArrayAccount(0, ['access content']);
        $edge = $this->edge();

        self::assertTrue($policy->fieldAccess($edge, 'to_entity_type', 'view', $account)->isNeutral());
        self::assertTrue($policy->fieldAccess($edge, 'to_entity_id', 'view', $account)->isNeutral());
    }

    #[Test]
    public function from_endpoint_fields_are_independently_gated(): void
    {
        [$policy, $handler] = $this->policy(
            endpointLoader: fn(string $id) => $this->createMock(\Waaseyaa\Entity\EntityInterface::class),
        );
        // 'from' endpoint forbidden, but the check is invoked per-field — this
        // handler always returns forbidden, so both directions independently
        // redact when their own endpoint is hidden.
        $handler->method('check')->willReturn(AccessResult::forbidden('hidden'));

        $account = new ArrayAccount(0, ['access content']);
        $edge = $this->edge();

        self::assertTrue($policy->fieldAccess($edge, 'from_entity_type', 'view', $account)->isForbidden());
        self::assertTrue($policy->fieldAccess($edge, 'from_entity_id', 'view', $account)->isForbidden());
    }

    #[Test]
    public function non_endpoint_fields_are_neutral(): void
    {
        [$policy] = $this->policy();
        $account = new ArrayAccount(0, ['access content']);
        $edge = $this->edge();

        self::assertTrue($policy->fieldAccess($edge, 'relationship_type', 'view', $account)->isNeutral());
        self::assertTrue($policy->fieldAccess($edge, 'status', 'view', $account)->isNeutral());
    }

    #[Test]
    public function edit_operation_is_not_gated_by_this_policy(): void
    {
        [$policy, $handler] = $this->policy();
        $handler->method('check')->willReturn(AccessResult::forbidden('hidden'));

        $account = new ArrayAccount(0, ['access content']);
        $edge = $this->edge();

        self::assertTrue($policy->fieldAccess($edge, 'to_entity_type', 'edit', $account)->isNeutral());
    }

    #[Test]
    public function non_relationship_entities_are_not_gated(): void
    {
        [$policy] = $this->policy();
        $account = new ArrayAccount(0, ['access content']);

        $notARelationship = $this->createMock(\Waaseyaa\Entity\EntityInterface::class);

        self::assertTrue($policy->fieldAccess($notARelationship, 'to_entity_type', 'view', $account)->isNeutral());
    }

    #[Test]
    public function fails_closed_when_endpoint_type_is_unregistered(): void
    {
        [$policy] = $this->policy(knownType: false);
        $account = new ArrayAccount(0, ['access content']);
        $edge = $this->edge();

        self::assertTrue($policy->fieldAccess($edge, 'to_entity_type', 'view', $account)->isForbidden());
    }

    #[Test]
    public function fails_closed_when_endpoint_fails_to_load(): void
    {
        [$policy] = $this->policy(endpointLoader: fn(string $id) => null);
        $account = new ArrayAccount(0, ['access content']);
        $edge = $this->edge();

        self::assertTrue($policy->fieldAccess($edge, 'to_entity_id', 'view', $account)->isForbidden());
    }

    #[Test]
    public function fails_closed_when_endpoint_id_is_empty(): void
    {
        [$policy] = $this->policy();
        $account = new ArrayAccount(0, ['access content']);
        $edge = $this->edge(['to_entity_id' => '']);

        self::assertTrue($policy->fieldAccess($edge, 'to_entity_id', 'view', $account)->isForbidden());
    }
}
