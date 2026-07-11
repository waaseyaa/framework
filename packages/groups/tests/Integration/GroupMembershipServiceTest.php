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
use Waaseyaa\Groups\Group;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Groups\GroupsServiceProvider;
use Waaseyaa\Groups\Membership\GroupMembershipService;
use Waaseyaa\Relationship\Relationship;

/**
 * GroupMembershipService against real SQLite relationship storage.
 *
 * Bootstrap mirrors {@see \Waaseyaa\Genealogy\Tests\Unit\GenealogyFamilyServiceTest}:
 * a real EntityTypeManager + SqlStorageDriver wired to `DBALDatabase::createSqlite()`,
 * with the `relationship` entity type registered via `TestEntityType::stub()` (no
 * production `relationship` package migrations needed for this shape-only test).
 *
 * CW-v1 WP-4: also registers the real `group` entity type (pulled from
 * {@see GroupsServiceProvider}, same pattern as
 * {@see \Waaseyaa\Groups\Tests\Integration\TwoBundleCoexistenceTest}) so the
 * write methods' group-existence check has something real to load.
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

        $manager->registerEntityType($this->groupEntityType());

        return $manager;
    }

    private function groupEntityType(): EntityTypeInterface
    {
        $provider = new GroupsServiceProvider();
        $provider->register();
        foreach ($provider->getEntityTypes() as $type) {
            if ($type->id() === 'group') {
                return $type;
            }
        }
        self::fail('Groups provider did not register "group" entity type.');
    }

    /**
     * Create a real `group` entity via the manager's repository, returning
     * its gid.
     */
    private function createGroup(EntityTypeManager $manager, string $gid, string $type = 'department'): string
    {
        $repository = $manager->getRepository('group');
        $entity = $repository->create([
            'gid' => $gid,
            'type' => $type,
            'name' => $gid,
        ]);
        \assert($entity instanceof Group);
        $entity->enforceIsNew();
        $repository->save($entity, validate: false);

        return (string) $entity->id();
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
    }

    /**
     * @param array{relationship_type: string, from_entity_type: string, from_entity_id: string, to_entity_type: string, to_entity_id: string, status?: int} $values
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

    #[Test]
    public function group_ids_for_user_ignores_a_soft_revoked_membership_row(): void
    {
        // Adversarial-review fix (#1920, WP-3): status is relationship
        // liveness (schema default 1, int), not a temporal window — a
        // status=0 row must not count as membership.
        $manager = $this->makeManager();
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_type' => 'group',
            'to_entity_id' => '1',
            'status' => 0,
        ]);

        $service = new GroupMembershipService($manager);

        self::assertSame([], $service->groupIdsForUser(7));
    }

    #[Test]
    public function group_ids_for_content_ignores_a_soft_revoked_content_group_row(): void
    {
        $manager = $this->makeManager();
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_type' => 'group',
            'to_entity_id' => '5',
            'status' => 0,
        ]);

        $service = new GroupMembershipService($manager);

        self::assertSame([], $service->groupIdsForContent('node', 42));
    }

    // ----- CW-v1 WP-4: membership write surface -----

    #[Test]
    public function add_member_creates_a_live_row_visible_to_reads(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '1');
        $service = new GroupMembershipService($manager);

        $service->addMember(7, '1');

        self::assertSame(['1'], $service->groupIdsForUser(7));
        self::assertTrue($service->isMemberOfAny(7, ['1']));
        self::assertSame(1, $manager->getRepository('relationship')->count([
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_id' => '1',
        ]));
    }

    #[Test]
    public function add_member_is_idempotent_and_does_not_duplicate_the_row(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '1');
        $service = new GroupMembershipService($manager);

        $service->addMember(7, '1');
        $service->addMember(7, '1');
        $service->addMember(7, '1');

        self::assertSame(['1'], $service->groupIdsForUser(7));
        self::assertSame(1, $manager->getRepository('relationship')->count([
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_id' => '1',
        ]));
    }

    #[Test]
    public function add_member_throws_for_an_unknown_group(): void
    {
        $manager = $this->makeManager();
        $service = new GroupMembershipService($manager);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nonexistent-group/');

        $service->addMember(7, 'nonexistent-group');
    }

    #[Test]
    public function remove_member_soft_revokes_and_excludes_from_reads(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '1');
        $service = new GroupMembershipService($manager);
        $service->addMember(7, '1');

        $service->removeMember(7, '1');

        self::assertSame([], $service->groupIdsForUser(7));
        self::assertFalse($service->isMemberOfAny(7, ['1']));
        // Soft-revoke, never delete: the row still exists (count ignores status).
        self::assertSame(1, $manager->getRepository('relationship')->count([
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_id' => '1',
        ]));
    }

    #[Test]
    public function remove_member_is_a_no_op_when_no_row_exists(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '1');
        $service = new GroupMembershipService($manager);

        $service->removeMember(7, '1');

        self::assertSame([], $service->groupIdsForUser(7));
        self::assertSame(0, $manager->getRepository('relationship')->count([
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_id' => '1',
        ]));
    }

    #[Test]
    public function add_member_reactivates_a_soft_revoked_row_instead_of_duplicating(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '1');
        $service = new GroupMembershipService($manager);
        $service->addMember(7, '1');
        $service->removeMember(7, '1');
        self::assertSame([], $service->groupIdsForUser(7));

        $service->addMember(7, '1');

        self::assertSame(['1'], $service->groupIdsForUser(7));
        self::assertSame(1, $manager->getRepository('relationship')->count([
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_id' => '1',
        ]));
    }

    #[Test]
    public function assign_content_creates_a_live_row_visible_to_reads(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '5');
        $service = new GroupMembershipService($manager);

        $service->assignContent('node', 42, '5');

        self::assertSame(['5'], $service->groupIdsForContent('node', 42));
        self::assertSame(1, $manager->getRepository('relationship')->count([
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_id' => '5',
        ]));
    }

    #[Test]
    public function assign_content_is_idempotent_and_does_not_duplicate_the_row(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '5');
        $service = new GroupMembershipService($manager);

        $service->assignContent('node', 42, '5');
        $service->assignContent('node', 42, '5');

        self::assertSame(['5'], $service->groupIdsForContent('node', 42));
        self::assertSame(1, $manager->getRepository('relationship')->count([
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_id' => '5',
        ]));
    }

    #[Test]
    public function assign_content_throws_for_an_unknown_group(): void
    {
        $manager = $this->makeManager();
        $service = new GroupMembershipService($manager);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nonexistent-group/');

        $service->assignContent('node', 42, 'nonexistent-group');
    }

    #[Test]
    public function unassign_content_soft_revokes_and_excludes_from_reads(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '5');
        $service = new GroupMembershipService($manager);
        $service->assignContent('node', 42, '5');

        $service->unassignContent('node', 42, '5');

        self::assertSame([], $service->groupIdsForContent('node', 42));
        // Soft-revoke, never delete: the row still exists (count ignores status).
        self::assertSame(1, $manager->getRepository('relationship')->count([
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_id' => '5',
        ]));
    }

    #[Test]
    public function unassign_content_is_a_no_op_when_no_row_exists(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '5');
        $service = new GroupMembershipService($manager);

        $service->unassignContent('node', 42, '5');

        self::assertSame([], $service->groupIdsForContent('node', 42));
        self::assertSame(0, $manager->getRepository('relationship')->count([
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_id' => '5',
        ]));
    }

    #[Test]
    public function assign_content_reactivates_a_soft_revoked_row_instead_of_duplicating(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '5');
        $service = new GroupMembershipService($manager);
        $service->assignContent('node', 42, '5');
        $service->unassignContent('node', 42, '5');
        self::assertSame([], $service->groupIdsForContent('node', 42));

        $service->assignContent('node', 42, '5');

        self::assertSame(['5'], $service->groupIdsForContent('node', 42));
        self::assertSame(1, $manager->getRepository('relationship')->count([
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_id' => '5',
        ]));
    }

    #[Test]
    public function remove_member_revokes_all_duplicate_live_rows_for_the_same_triple(): void
    {
        // Reviewer finding (PR #1956): duplicate relationship rows for the
        // same (relationship_type, from, group) triple can exist — pre-WP-4
        // hand-crafted rows, or a race between concurrent addMember() calls
        // (find-then-create is not atomic; no unique DB index). Before the
        // fix, revokeRelationship() found and revoked only ONE of the two
        // live rows (range(0, 1)), leaving the other live — so the user
        // stayed a member.
        $manager = $this->makeManager();
        $this->createGroup($manager, '1');
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
            'to_entity_id' => '1',
        ]);
        $repository = $manager->getRepository('relationship');
        self::assertSame(2, $repository->count([
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_id' => '1',
        ]));

        $service = new GroupMembershipService($manager);
        $service->removeMember(7, '1');

        self::assertSame([], $service->groupIdsForUser(7));
        $rows = $repository->findBy([
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_id' => '1',
        ]);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            \assert($row instanceof Relationship);
            self::assertSame(0, (int) $row->get('status'), 'Every duplicate live row must be revoked.');
        }
    }

    #[Test]
    public function unassign_content_revokes_all_duplicate_live_rows_for_the_same_triple(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '5');
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_type' => 'group',
            'to_entity_id' => '5',
        ]);
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_type' => 'group',
            'to_entity_id' => '5',
        ]);
        $repository = $manager->getRepository('relationship');
        self::assertSame(2, $repository->count([
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_id' => '5',
        ]));

        $service = new GroupMembershipService($manager);
        $service->unassignContent('node', 42, '5');

        self::assertSame([], $service->groupIdsForContent('node', 42));
        $rows = $repository->findBy([
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '42',
            'to_entity_id' => '5',
        ]);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            \assert($row instanceof Relationship);
            self::assertSame(0, (int) $row->get('status'), 'Every duplicate live row must be revoked.');
        }
    }

    #[Test]
    public function remove_member_does_not_require_the_group_to_still_exist(): void
    {
        $manager = $this->makeManager();
        $this->createGroup($manager, '1');
        $service = new GroupMembershipService($manager);
        $service->addMember(7, '1');

        // Revokes don't validate the group still exists (design decision 6):
        // deleting the group entity elsewhere must not strand the revoke path.
        $manager->getRepository('group')->delete($manager->getRepository('group')->find('1'));

        $service->removeMember(7, '1');

        self::assertSame([], $service->groupIdsForUser(7));
    }
}
