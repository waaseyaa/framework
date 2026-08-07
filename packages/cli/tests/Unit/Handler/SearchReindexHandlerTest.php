<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\SearchReindexHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Search\BatchSearchIndexerInterface;
use Waaseyaa\Search\Projection\EntitySearchProjectionRegistry;
use Waaseyaa\Search\Projection\EntitySearchProjectorInterface;
use Waaseyaa\Search\Projection\NodeSearchProjector;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

#[CoversClass(SearchReindexHandler::class)]
final class SearchReindexHandlerTest extends TestCase
{
    #[Test]
    public function plain_node_entities_are_projected_and_indexed_during_a_full_reindex(): void
    {
        $indexer = new RecordingSearchIndexer();
        $tester = $this->tester($indexer, [
            $this->nodeEntity(1, 'Community Health Services', '<p>Wellness clinic hours</p>'),
            $this->nodeEntity(2, 'Band Office Jobs', 'Apply today'),
        ]);

        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Reindex complete. 2 documents indexed.', $tester->getStdout());
        $this->assertSame(['node:1', 'node:2'], array_keys($indexer->documents));
        $this->assertSame('Community Health Services', $indexer->documents['node:1']['title']);
        $this->assertSame('Wellness clinic hours', $indexer->documents['node:1']['body']);
    }

    #[Test]
    public function self_indexable_entities_keep_their_own_projection(): void
    {
        $indexer = new RecordingSearchIndexer();
        $tester = $this->tester($indexer, [
            $this->selfIndexableEntity('custom:9', 'Self projected'),
            $this->nodeEntity(1, 'Plain node', 'body'),
        ]);

        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Reindex complete. 2 documents indexed.', $tester->getStdout());
        $this->assertSame(['custom:9', 'node:1'], array_keys($indexer->documents));
        $this->assertSame('Self projected', $indexer->documents['custom:9']['title']);
    }

    #[Test]
    public function entities_nobody_projects_are_skipped(): void
    {
        $indexer = new RecordingSearchIndexer();
        $tester = $this->tester($indexer, [
            $this->plainEntity('taxonomy_term', 3),
        ]);

        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Reindex complete. 0 documents indexed.', $tester->getStdout());
        $this->assertSame([], $indexer->documents);
    }

    #[Test]
    public function a_projection_failure_is_reported_and_fails_the_command(): void
    {
        $indexer = new RecordingSearchIndexer();
        $projector = new class implements EntitySearchProjectorInterface {
            public function supports(EntityInterface $entity): bool { return true; }
            public function project(EntityInterface $entity): ?SearchIndexableInterface
            {
                throw new \RuntimeException('projection failure');
            }
        };
        $tester = $this->tester(
            $indexer,
            [$this->nodeEntity(1, 'Private title', 'Private body')],
            new EntitySearchProjectionRegistry([$projector]),
        );

        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Skipped 1 entities whose search projection failed.', $tester->getStdout());
        self::assertStringContainsString('Reindex failed: 1 entities could not be projected.', $tester->getStdout());
        self::assertStringNotContainsString('Private title', $tester->getStdout());
        self::assertSame([], $indexer->documents);
    }

    /**
     * @param list<EntityInterface> $entities
     */
    private function tester(
        RecordingSearchIndexer $indexer,
        array $entities,
        ?EntitySearchProjectionRegistry $projectionRegistry = null,
    ): CliTester
    {
        $handler = new SearchReindexHandler(
            $indexer,
            $this->entityTypeManager($entities),
            $projectionRegistry ?? new EntitySearchProjectionRegistry([new NodeSearchProjector()]),
        );
        $definition = new HandlerCommand(
            name: 'search:reindex',
            description: 'Rebuild the search index from all indexable entities',
            options: [
                new HandlerOption(name: 'batch-size', shortcut: 'b', mode: HandlerOptionMode::Required, description: 'Entities per batch', default: '100'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return CliTester::for($definition, $container);
    }

    /**
     * @param list<EntityInterface> $entities
     */
    private function entityTypeManager(array $entities): EntityTypeManagerInterface
    {
        $ids = array_map(static fn(EntityInterface $entity): string => (string) $entity->id(), $entities);

        $query = $this->createStub(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn($ids);

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->method('findMany')->willReturn($entities);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([
            'node' => new EntityType(id: 'node', label: 'Content', class: \stdClass::class, keys: ['id' => 'nid']),
        ]);
        $manager->method('getRepository')->willReturn($repository);

        return $manager;
    }

    private function nodeEntity(int $id, string $title, string $body): EntityInterface
    {
        return new class (['nid' => $id, 'title' => $title, 'body' => $body, 'type' => 'page']) extends ContentEntityBase {
            public function __construct(array $values)
            {
                parent::__construct($values, 'node', ['id' => 'nid', 'label' => 'title', 'bundle' => 'type']);
            }
        };
    }

    private function plainEntity(string $entityTypeId, int $id): EntityInterface
    {
        return new class ($entityTypeId, $id) extends \Waaseyaa\Entity\EntityBase {
            public function __construct(string $entityTypeId, int $id)
            {
                parent::__construct(['id' => $id], $entityTypeId, ['id' => 'id']);
            }
        };
    }

    private function selfIndexableEntity(string $documentId, string $title): EntityInterface
    {
        return new class ($documentId, $title) extends \Waaseyaa\Entity\EntityBase implements SearchIndexableInterface {
            public function __construct(private readonly string $documentId, private readonly string $title)
            {
                parent::__construct(['id' => 9], 'custom', ['id' => 'id']);
            }

            public function getSearchDocumentId(): string
            {
                return $this->documentId;
            }

            public function toSearchDocument(): array
            {
                return ['title' => $this->title, 'body' => 'B'];
            }

            public function toSearchMetadata(): array
            {
                return ['entity_type' => 'custom'];
            }
        };
    }
}

final class RecordingSearchIndexer implements SearchIndexerInterface, BatchSearchIndexerInterface
{
    /** @var array<string, array<string, string>> */
    public array $documents = [];

    public function index(SearchIndexableInterface $item): void
    {
        $this->documents[$item->getSearchDocumentId()] = $item->toSearchDocument();
    }

    public function reindexBatch(iterable $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            $this->index($item);
            ++$count;
        }

        return $count;
    }

    public function remove(string $documentId): void
    {
        unset($this->documents[$documentId]);
    }

    public function removeAll(): void
    {
        $this->documents = [];
    }

    public function getSchemaVersion(): string
    {
        return '2';
    }
}
