<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Groups\Membership\GroupMembershipService;
use Waaseyaa\Relationship\Relationship;

/**
 * GroupMembershipService against real SQLite relationship storage.
 *
 * Bootstrap mirrors {@see \Waaseyaa\Genealogy\Tests\Unit\GenealogyFamilyServiceTest}:
 * a real EntityTypeManager + SqlStorageDriver wired to `DBALDatabase::createSqlite()`,
 * with the `relationship` entity type registered via `TestEntityType::stub()` (no
 * production `relationship` package migrations needed for this shape-only test).
 */
#[CoversClass(GroupMembershipService::class)]
final class GroupMembershipServiceTest extends TestCase
{
    private function makeManager(): EntityTypeManager
    {
        EntityType::clearFromClassCache();
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $dispatcher = new EventDispatcher();
        $registry = new FieldDefinitionRegistry();

        $resolver = new SingleConnectionResolver($database);
        $manager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver, $database, $registry): EntityRepository {
                (new SqlSchemaHandler($definition, $database, $registry))->ensureTable();

                $idKey = $definition->getKeys()['id'] ?? 'id';

                return new EntityRepository(
                    $definition,
                    new SqlStorageDriver($resolver, $idKey),
                    $dispatcher,
                    database: $database,
                    fieldRegistry: $registry,
                );
            },
            fieldRegistry: $registry,
        );

        ContentEntityBase::setFieldRegistry($registry);

        $manager->registerEntityType(TestEntityType::stub(
            id: 'relationship',
            class: Relationship::class,
            keys: [
                'id' => 'rid',
                'uuid' => 'uuid',
                'label' => 'relationship_type',
                'bundle' => 'relationship_type',
            ],
            label: 'Relationship',
            fieldDefinitions: [
                'relationship_type' => ['type' => 'string', 'required' => true, 'weight' => 0],
                'from_entity_type' => ['type' => 'string', 'required' => true, 'weight' => 1],
                'from_entity_id' => ['type' => 'string', 'required' => true, 'weight' => 2],
                'to_entity_type' => ['type' => 'string', 'required' => true, 'weight' => 3],
                'to_entity_id' => ['type' => 'string', 'required' => true, 'weight' => 4],
                'directionality' => ['type' => 'string', 'weight' => 5, 'default' => 'directed'],
                'status' => ['type' => 'boolean', 'weight' => 6, 'default' => 1],
            ],
        ));

        return $manager;
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
    }

    /**
     * @param array{relationship_type: string, from_entity_type: string, from_entity_id: string, to_entity_type: string, to_entity_id: string} $values
     */
    private function createRelationship(EntityTypeManager $manager, array $values): void
    {
        $repository = $manager->getRepository('relationship');
        $entity = $repository->create($values + ['directionality' => 'directed', 'status' => 1]);
        $repository->save($entity, validate: false);
    }

    #[Test]
    public function group_ids_for_user_returns_groups_the_user_belongs_to(): void
    {
        $manager = $this->makeManager();
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_type' => 'group',
            'to_entity_id' => '1',
        ]);
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_type' => 'group',
            'to_entity_id' => '2',
        ]);

        $service = new GroupMembershipService($manager);

        self::assertSame(['1', '2'], $service->groupIdsForUser(7));
    }

    #[Test]
    public function group_ids_for_user_is_empty_for_a_user_with_no_memberships(): void
    {
        $manager = $this->makeManager();

        $service = new GroupMembershipService($manager);

        self::assertSame([], $service->groupIdsForUser(999));
    }

    #[Test]
    public function group_ids_for_user_ignores_rows_of_other_relationship_types(): void
    {
        $manager = $this->makeManager();
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_type' => 'group',
            'to_entity_id' => '1',
        ]);
        $this->createRelationship($manager, [
            'relationship_type' => 'some_other_edge',
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_type' => 'group',
            'to_entity_id' => '2',
        ]);

        $service = new GroupMembershipService($manager);

        self::assertSame([], $service->groupIdsForUser(7));
    }

    #[Test]
    public function group_ids_for_content_returns_groups_the_content_belongs_to(): void
    {
        $manager = $this->makeManager();
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_type' => 'group',
            'to_entity_id' => '5',
        ]);

        $service = new GroupMembershipService($manager);

        self::assertSame(['5'], $service->groupIdsForContent('node', 42));
    }

    #[Test]
    public function group_ids_for_content_ignores_rows_of_other_relationship_types(): void
    {
        $manager = $this->makeManager();
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_type' => 'group',
            'to_entity_id' => '5',
        ]);

        $service = new GroupMembershipService($manager);

        self::assertSame([], $service->groupIdsForContent('node', 42));
    }

    #[Test]
    public function is_member_of_any_is_true_when_the_user_belongs_to_one_of_the_groups(): void
    {
        $manager = $this->makeManager();
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_type' => 'group',
            'to_entity_id' => '2',
        ]);

        $service = new GroupMembershipService($manager);

        self::assertTrue($service->isMemberOfAny(7, ['1', '2', '3']));
    }

    #[Test]
    public function is_member_of_any_is_false_when_the_user_belongs_to_none_of_the_groups(): void
    {
        $manager = $this->makeManager();
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_type' => 'group',
            'to_entity_id' => '9',
        ]);

        $service = new GroupMembershipService($manager);

        self::assertFalse($service->isMemberOfAny(7, ['1', '2', '3']));
    }

    #[Test]
    public function is_member_of_any_is_false_for_an_empty_group_list(): void
    {
        $manager = $this->makeManager();

        $service = new GroupMembershipService($manager);

        self::assertFalse($service->isMemberOfAny(7, []));
    }
}
