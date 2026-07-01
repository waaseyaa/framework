<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Tests\Unit;

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
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Genealogy\Entity\GenealogyPerson;
use Waaseyaa\Genealogy\GenealogyRelationshipType;
use Waaseyaa\Genealogy\Service\GenealogyPedigreeService;
use Waaseyaa\Relationship\Relationship;

#[CoversClass(GenealogyPedigreeService::class)]
final class GenealogyPedigreeServiceTest extends TestCase
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
            function (EntityTypeInterface $definition) use ($database, $dispatcher, $registry): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $database, $registry))->ensureTable();

                return new SqlEntityStorage($definition, $database, $dispatcher, $registry);
            },
            // C-22 WP2: repository factory mirroring the kernel's getRepository() shape.
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver, $database, $registry): EntityRepository {
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

        $manager->registerEntityType(EntityType::fromClass(GenealogyPerson::class, group: 'content'));

        return $manager;
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
    }

    #[Test]
    public function parent_and_child_queries_follow_directed_parent_edges(): void
    {
        $manager = $this->makeManager();
        $personStorage = $manager->getStorage('genealogy_person');
        $relStorage = $manager->getStorage('relationship');

        $child = $personStorage->create(['display_name' => 'Child']);
        $personStorage->save($child);
        $parent = $personStorage->create(['display_name' => 'Parent']);
        $personStorage->save($parent);

        $edge = $relStorage->create([
            'relationship_type' => GenealogyRelationshipType::PARENT_OF,
            'from_entity_type' => 'genealogy_person',
            'from_entity_id' => (string) $parent->id(),
            'to_entity_type' => 'genealogy_person',
            'to_entity_id' => (string) $child->id(),
            'directionality' => 'directed',
            'status' => 1,
        ]);
        $relStorage->save($edge);

        $service = new GenealogyPedigreeService($manager);

        self::assertSame([(string) $parent->id()], $service->parentPersonIds((string) $child->id()));
        self::assertSame([(string) $child->id()], $service->childPersonIds((string) $parent->id()));
    }

    #[Test]
    public function spouse_query_handles_either_endpoint(): void
    {
        $manager = $this->makeManager();
        $personStorage = $manager->getStorage('genealogy_person');
        $relStorage = $manager->getStorage('relationship');

        $a = $personStorage->create(['display_name' => 'A']);
        $personStorage->save($a);
        $b = $personStorage->create(['display_name' => 'B']);
        $personStorage->save($b);

        $edge = $relStorage->create([
            'relationship_type' => GenealogyRelationshipType::SPOUSE_OF,
            'from_entity_type' => 'genealogy_person',
            'from_entity_id' => (string) $a->id(),
            'to_entity_type' => 'genealogy_person',
            'to_entity_id' => (string) $b->id(),
            'directionality' => 'bidirectional',
            'status' => 1,
        ]);
        $relStorage->save($edge);

        $service = new GenealogyPedigreeService($manager);

        self::assertSame([(string) $b->id()], $service->spousePersonIds((string) $a->id()));
        self::assertSame([(string) $a->id()], $service->spousePersonIds((string) $b->id()));
    }

    #[Test]
    public function ancestor_generations_walks_parents_breadth_first(): void
    {
        $manager = $this->makeManager();
        $personStorage = $manager->getStorage('genealogy_person');
        $relStorage = $manager->getStorage('relationship');

        $child = $personStorage->create(['display_name' => 'Child']);
        $personStorage->save($child);
        $p1 = $personStorage->create(['display_name' => 'P1']);
        $personStorage->save($p1);
        $p2 = $personStorage->create(['display_name' => 'P2']);
        $personStorage->save($p2);
        $gp = $personStorage->create(['display_name' => 'GP']);
        $personStorage->save($gp);

        foreach ([
            [$p1->id(), $child->id()],
            [$p2->id(), $child->id()],
            [$gp->id(), $p1->id()],
        ] as [$fromId, $toId]) {
            $e = $relStorage->create([
                'relationship_type' => GenealogyRelationshipType::PARENT_OF,
                'from_entity_type' => 'genealogy_person',
                'from_entity_id' => (string) $fromId,
                'to_entity_type' => 'genealogy_person',
                'to_entity_id' => (string) $toId,
                'directionality' => 'directed',
                'status' => 1,
            ]);
            $relStorage->save($e);
        }

        $service = new GenealogyPedigreeService($manager);
        $levels = $service->ancestorGenerations((string) $child->id(), 5);

        self::assertSame([(string) $child->id()], $levels[0]);
        self::assertSame([(string) $p1->id(), (string) $p2->id()], $levels[1]);
        self::assertSame([(string) $gp->id()], $levels[2]);
    }
}
