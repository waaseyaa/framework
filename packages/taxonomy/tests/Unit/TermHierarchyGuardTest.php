<?php

declare(strict_types=1);

namespace Waaseyaa\Taxonomy\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Taxonomy\Term;
use Waaseyaa\Taxonomy\TermHierarchyGuard;

#[CoversClass(TermHierarchyGuard::class)]
final class TermHierarchyGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
        EntityType::clearFromClassCache();
    }

    #[Test]
    public function persistence_boundary_rejects_self_parent_cycle_and_cross_vocabulary_parent(): void
    {
        [$manager, $repository] = $this->buildRepository();

        $root = $this->save($repository, 'places', 'Root');
        $child = $this->save($repository, 'places', 'Child', (int) $root->id());
        $otherVocabulary = $this->save($repository, 'topics', 'Other');

        $root->setParentId((int) $root->id());
        $this->assertRejected($repository, $root, 'cannot be its own parent');

        $root->setParentId((int) $child->id());
        $this->assertRejected($repository, $root, 'would create a cycle');

        $child->setParentId((int) $otherVocabulary->id());
        $this->assertRejected($repository, $child, 'same vocabulary');

        self::assertSame('places', $manager->getRepository('taxonomy_term')->find((string) $child->id())?->bundle());
    }

    /** @return array{EntityTypeManager, EntityRepository} */
    private function buildRepository(): array
    {
        EntityType::clearFromClassCache();
        $database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $registry = new FieldDefinitionRegistry();
        $resolver = new SingleConnectionResolver($database);

        $manager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $_entityTypeId, EntityTypeInterface $definition) use ($database, $dispatcher, $registry, $resolver): EntityRepository {
                (new SqlSchemaHandler($definition, $database, $registry))->ensureTable();

                return new EntityRepository(
                    $definition,
                    new SqlStorageDriver($resolver, $definition->getKeys()['id'] ?? 'id'),
                    $dispatcher,
                    database: $database,
                    fieldRegistry: $registry,
                );
            },
            fieldRegistry: $registry,
        );
        ContentEntityBase::setFieldRegistry($registry);
        $manager->registerEntityType(EntityType::fromClass(Term::class, group: 'taxonomy', bundleEntityType: 'taxonomy_vocabulary'));
        $dispatcher->addListener(EntityEvents::PRE_SAVE->value, new TermHierarchyGuard($manager));

        /** @var EntityRepository $repository */
        $repository = $manager->getRepository('taxonomy_term');

        return [$manager, $repository];
    }

    private function save(EntityRepository $repository, string $vocabulary, string $name, ?int $parentId = null): Term
    {
        /** @var Term $term */
        $term = $repository->create(['vid' => $vocabulary, 'name' => $name, 'parent_id' => $parentId]);
        $repository->save($term, validate: false);

        return $term;
    }

    private function assertRejected(EntityRepository $repository, Term $term, string $message): void
    {
        try {
            $repository->save($term, validate: false);
            self::fail('Expected the hierarchy mutation to be rejected.');
        } catch (\DomainException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
