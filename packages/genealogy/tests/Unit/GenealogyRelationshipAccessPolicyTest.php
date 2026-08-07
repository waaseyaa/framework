<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Genealogy\Access\GenealogyRelationshipAccessPolicy;
use Waaseyaa\Genealogy\Entity\GenealogyPerson;
use Waaseyaa\Genealogy\GenealogyRelationshipType;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\User\AnonymousUser;

final class GenealogyRelationshipAccessPolicyTest extends TestCase
{
    #[Test]
    public function genealogy_edge_view_requires_both_endpoints_allowed(): void
    {
        $p1 = new GenealogyPerson(['id' => 2, 'display_name' => 'A', 'tree_id' => 1]);
        $p2 = new GenealogyPerson(['id' => 1, 'display_name' => 'B', 'tree_id' => 1]);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->willReturnMap([
            ['2', $p1],
            ['1', $p2],
        ]);

        // C-22 WP3: read path now goes through the canonical repository.
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(
            static fn(string $id): ?GenealogyPerson => match ($id) {
                '2' => $p1,
                '1' => $p2,
                default => null,
            },
        );

        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn($repository);

        $handler = $this->createStub(EntityAccessHandler::class);
        $handler->method('check')->willReturn(AccessResult::allowed());

        $policy = new GenealogyRelationshipAccessPolicy($etm, $handler);
        $account = new AnonymousUser();
        $edge = new Relationship([
            'rid' => 1,
            'relationship_type' => GenealogyRelationshipType::PARENT_OF,
            'from_entity_type' => 'genealogy_person',
            'from_entity_id' => '2',
            'to_entity_type' => 'genealogy_person',
            'to_entity_id' => '1',
            'directionality' => 'directed',
            'status' => 1,
        ]);

        self::assertTrue($policy->access($edge, 'view', $account)->isAllowed());
    }

    #[Test]
    public function genealogy_edge_view_forbidden_when_an_endpoint_is_denied(): void
    {
        $p1 = new GenealogyPerson(['id' => 2, 'display_name' => 'A', 'tree_id' => 1]);
        $p2 = new GenealogyPerson(['id' => 1, 'display_name' => 'B', 'tree_id' => 1]);

        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->willReturnMap([
            ['2', $p1],
            ['1', $p2],
        ]);

        // C-22 WP3: read path now goes through the canonical repository.
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturnCallback(
            static fn(string $id): ?GenealogyPerson => match ($id) {
                '2' => $p1,
                '1' => $p2,
                default => null,
            },
        );

        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getStorage')->willReturn($storage);
        $etm->method('getRepository')->willReturn($repository);

        $handler = $this->createStub(EntityAccessHandler::class);
        $handler->method('check')->willReturn(AccessResult::forbidden('hidden'));

        $policy = new GenealogyRelationshipAccessPolicy($etm, $handler);
        $account = new AnonymousUser();
        $edge = new Relationship([
            'rid' => 1,
            'relationship_type' => GenealogyRelationshipType::PARENT_OF,
            'from_entity_type' => 'genealogy_person',
            'from_entity_id' => '2',
            'to_entity_type' => 'genealogy_person',
            'to_entity_id' => '1',
            'directionality' => 'directed',
            'status' => 1,
        ]);

        self::assertTrue($policy->access($edge, 'view', $account)->isForbidden());
    }

    #[Test]
    public function non_genealogy_edge_has_no_opinion(): void
    {
        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $handler = $this->createStub(EntityAccessHandler::class);
        $policy = new GenealogyRelationshipAccessPolicy($etm, $handler);
        $account = new AnonymousUser();
        $edge = new Relationship([
            'rid' => 1,
            'relationship_type' => 'references',
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'node',
            'to_entity_id' => '2',
            'directionality' => 'directed',
            'status' => 1,
        ]);

        self::assertTrue($policy->access($edge, 'view', $account)->isNeutral());
    }
}
