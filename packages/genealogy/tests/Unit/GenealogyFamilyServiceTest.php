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
use Waaseyaa\Genealogy\Entity\GenealogyFamily;
use Waaseyaa\Genealogy\Entity\GenealogyPerson;
use Waaseyaa\Genealogy\GenealogyRelationshipType;
use Waaseyaa\Genealogy\Service\GenealogyFamilyService;
use Waaseyaa\Relationship\Relationship;

#[CoversClass(GenealogyFamilyService::class)]
final class GenealogyFamilyServiceTest extends TestCase
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
        $manager->registerEntityType(EntityType::fromClass(GenealogyFamily::class, group: 'content'));

        return $manager;
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
    }

    #[Test]
    public function member_person_ids_reads_member_of_family_edges(): void
    {
        $manager = $this->makeManager();
        $personRepository = $manager->getRepository('genealogy_person');
        $familyRepository = $manager->getRepository('genealogy_family');
        $relRepository = $manager->getRepository('relationship');

        $family = $familyRepository->create(['display_name' => 'House']);
        $familyRepository->save($family, validate: false);
        $m1 = $personRepository->create(['display_name' => 'M1']);
        $personRepository->save($m1, validate: false);
        $m2 = $personRepository->create(['display_name' => 'M2']);
        $personRepository->save($m2, validate: false);

        foreach ([$m1, $m2] as $member) {
            $e = $relRepository->create([
                'relationship_type' => GenealogyRelationshipType::MEMBER_OF_FAMILY,
                'from_entity_type' => 'genealogy_person',
                'from_entity_id' => (string) $member->id(),
                'to_entity_type' => 'genealogy_family',
                'to_entity_id' => (string) $family->id(),
                'directionality' => 'directed',
                'status' => 1,
            ]);
            $relRepository->save($e, validate: false);
        }

        $service = new GenealogyFamilyService($manager);

        self::assertSame([(string) $m1->id(), (string) $m2->id()], $service->memberPersonIds((string) $family->id()));
    }
}
