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
            null,
            // C-22 WP4: repository factory mirroring the kernel's getRepository() shape.
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
        $personRepository = $manager->getRepository('genealogy_person');
        $relRepository = $manager->getRepository('relationship');

        $child = $personRepository->create(['display_name' => 'Child']);
        $personRepository->save($child, validate: false);
        $parent = $personRepository->create(['display_name' => 'Parent']);
        $personRepository->save($parent, validate: false);

        $edge = $relRepository->create([
            'relationship_type' => GenealogyRelationshipType::PARENT_OF,
            'from_entity_type' => 'genealogy_person',
            'from_entity_id' => (string) $parent->id(),
            'to_entity_type' => 'genealogy_person',
            'to_entity_id' => (string) $child->id(),
            'directionality' => 'directed',
            'status' => 1,
        ]);
        $relRepository->save($edge, validate: false);

        $service = new GenealogyPedigreeService($manager);

        self::assertSame([(string) $parent->id()], $service->parentPersonIds((string) $child->id()));
        self::assertSame([(string) $child->id()], $service->childPersonIds((string) $parent->id()));
    }

    #[Test]
    public function spouse_query_handles_either_endpoint(): void
    {
        $manager = $this->makeManager();
        $personRepository = $manager->getRepository('genealogy_person');
        $relRepository = $manager->getRepository('relationship');

        $a = $personRepository->create(['display_name' => 'A']);
        $personRepository->save($a, validate: false);
        $b = $personRepository->create(['display_name' => 'B']);
        $personRepository->save($b, validate: false);

        $edge = $relRepository->create([
            'relationship_type' => GenealogyRelationshipType::SPOUSE_OF,
            'from_entity_type' => 'genealogy_person',
            'from_entity_id' => (string) $a->id(),
            'to_entity_type' => 'genealogy_person',
            'to_entity_id' => (string) $b->id(),
            'directionality' => 'bidirectional',
            'status' => 1,
        ]);
        $relRepository->save($edge, validate: false);

        $service = new GenealogyPedigreeService($manager);

        self::assertSame([(string) $b->id()], $service->spousePersonIds((string) $a->id()));
        self::assertSame([(string) $a->id()], $service->spousePersonIds((string) $b->id()));
    }

    #[Test]
    public function ancestor_generations_walks_parents_breadth_first(): void
    {
        $manager = $this->makeManager();
        $personRepository = $manager->getRepository('genealogy_person');
        $relRepository = $manager->getRepository('relationship');

        $child = $personRepository->create(['display_name' => 'Child']);
        $personRepository->save($child, validate: false);
        $p1 = $personRepository->create(['display_name' => 'P1']);
        $personRepository->save($p1, validate: false);
        $p2 = $personRepository->create(['display_name' => 'P2']);
        $personRepository->save($p2, validate: false);
        $gp = $personRepository->create(['display_name' => 'GP']);
        $personRepository->save($gp, validate: false);

        foreach ([
            [$p1->id(), $child->id()],
            [$p2->id(), $child->id()],
            [$gp->id(), $p1->id()],
        ] as [$fromId, $toId]) {
            $e = $relRepository->create([
                'relationship_type' => GenealogyRelationshipType::PARENT_OF,
                'from_entity_type' => 'genealogy_person',
                'from_entity_id' => (string) $fromId,
                'to_entity_type' => 'genealogy_person',
                'to_entity_id' => (string) $toId,
                'directionality' => 'directed',
                'status' => 1,
            ]);
            $relRepository->save($e, validate: false);
        }

        $service = new GenealogyPedigreeService($manager);
        $levels = $service->ancestorGenerations((string) $child->id(), 5);

        self::assertSame([(string) $child->id()], $levels[0]);
        self::assertSame([(string) $p1->id(), (string) $p2->id()], $levels[1]);
        self::assertSame([(string) $gp->id()], $levels[2]);
    }
}
