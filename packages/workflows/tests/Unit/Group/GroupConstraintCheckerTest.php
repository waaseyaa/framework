<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Group;

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
use Waaseyaa\Workflows\Group\GroupConstraintChecker;
use Waaseyaa\Workflows\WorkflowTransition;

/**
 * GroupConstraintChecker against a real GroupMembershipService backed by
 * real SQLite relationship storage (CW-v1 WP-3). Bootstrap copied from
 * {@see \Waaseyaa\Groups\Tests\Integration\GroupMembershipServiceTest} —
 * GroupMembershipService is `final` and takes no interface, so it cannot be
 * faked; exercising the real service is the only way to unit-test the
 * checker's own branching (null / unknown-kind / empty-groups / member /
 * non-member) in isolation from TransitionService.
 */
#[CoversClass(GroupConstraintChecker::class)]
final class GroupConstraintCheckerTest extends TestCase
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

    private function checker(EntityTypeManager $manager): GroupConstraintChecker
    {
        return new GroupConstraintChecker(new GroupMembershipService($manager));
    }

    private function transitionWithConstraint(?string $groupConstraint): WorkflowTransition
    {
        return new WorkflowTransition(
            id: 'publish',
            label: 'Publish',
            from: ['review'],
            to: 'published',
            permission: 'use dept transition publish',
            groupConstraint: $groupConstraint,
        );
    }

    #[Test]
    public function a_null_constraint_is_always_satisfied(): void
    {
        $manager = $this->makeManager();
        $checker = $this->checker($manager);

        $this->assertTrue($checker->satisfies($this->transitionWithConstraint(null), 'node', 1, 7));
    }

    #[Test]
    public function an_unknown_constraint_kind_fails_closed(): void
    {
        // Config that evaded WorkflowValidator (e.g. hand-edited storage) must
        // still deny rather than degrade to unconstrained (design invariant 5).
        $manager = $this->makeManager();
        $checker = $this->checker($manager);

        $this->assertFalse($checker->satisfies($this->transitionWithConstraint('not_a_real_kind'), 'node', 1, 7));
    }

    #[Test]
    public function content_with_no_recorded_group_fails_closed(): void
    {
        $manager = $this->makeManager();
        $checker = $this->checker($manager);

        // No group_content relationship row exists for node/1 at all.
        $this->assertFalse($checker->satisfies($this->transitionWithConstraint('content_groups'), 'node', 1, 7));
    }

    #[Test]
    public function a_member_of_the_contents_group_satisfies_the_constraint(): void
    {
        $manager = $this->makeManager();
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'group',
            'to_entity_id' => '10',
        ]);
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_type' => 'group',
            'to_entity_id' => '10',
        ]);
        $checker = $this->checker($manager);

        $this->assertTrue($checker->satisfies($this->transitionWithConstraint('content_groups'), 'node', 1, 7));
    }

    #[Test]
    public function a_non_member_of_the_contents_group_does_not_satisfy_the_constraint(): void
    {
        $manager = $this->makeManager();
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::CONTENT,
            'from_entity_type' => 'node',
            'from_entity_id' => '1',
            'to_entity_type' => 'group',
            'to_entity_id' => '10',
        ]);
        // Account 7 belongs to a DIFFERENT group, not the content's group 10.
        $this->createRelationship($manager, [
            'relationship_type' => GroupRelationshipTypes::MEMBERSHIP,
            'from_entity_type' => 'user',
            'from_entity_id' => '7',
            'to_entity_type' => 'group',
            'to_entity_id' => '99',
        ]);
        $checker = $this->checker($manager);

        $this->assertFalse($checker->satisfies($this->transitionWithConstraint('content_groups'), 'node', 1, 7));
    }
}
