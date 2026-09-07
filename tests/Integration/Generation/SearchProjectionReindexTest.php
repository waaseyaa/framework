<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Generation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\SearchReindexHandler;
use Waaseyaa\CLI\Site\Scaffold\SearchProjectionScaffoldCompiler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Projection\EntitySearchProjectionRegistry;
use Waaseyaa\Search\Projection\EntitySearchProjectorInterface;
use Waaseyaa\Tests\Integration\Generation\Fixtures\ScaffoldedStory;

/**
 * #2849: `search:reindex` discovers and executes the *generated* projector.
 *
 * The projector is compiled from the shipped scaffold compiler and handed to
 * the reindex command through the same `EntitySearchProjectionRegistry` the
 * runtime composes, and the index is a real FTS5 table on SQLite rather than
 * a recording double — so the assertions are about bytes that actually landed
 * in the index, which is the only place "protected content never enters the
 * index file" can be checked honestly.
 *
 * The subject is a *registered* entity type (see {@see ScaffoldedStory}),
 * because read levels resolve differently for registered and unregistered
 * types and only the registered branch is what a shipped application runs.
 */
#[CoversNothing]
final class SearchProjectionReindexTest extends TestCase
{
    private const string PUBLIC_TERM = 'harvestpublicterm';

    private const string INTERNAL_TERM = 'internalsecretterm';

    private const string RESTRICTED_TERM = 'restrictedsecretterm';

    private DBALDatabase $database;

    private Fts5SearchIndexer $indexer;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
    }

    #[Test]
    public function reindexExecutesTheGeneratedProjectorAndIndexesOnlyPublicContent(): void
    {
        $tester = $this->reindex();

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Reindex complete. 1 documents indexed.', $tester->getStdout());

        $rows = $this->indexedRows();
        self::assertCount(1, $rows, 'The generated projector must produce exactly one document.');
        self::assertSame('scaffold_story:1', $rows[0]['document_id']);
        self::assertSame('Fall harvest', $rows[0]['title']);
        self::assertStringContainsString(
            self::PUBLIC_TERM,
            $rows[0]['body'],
            'A field the application deliberately declared Public must reach the index.',
        );
    }

    #[Test]
    public function protectedAndInternalFieldsNeverEnterTheIndexFile(): void
    {
        $this->reindex();

        // Assert against the whole stored index, not just the row we expect:
        // a leak through any column or any extra row must fail this.
        $stored = json_encode($this->indexedRows(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(
            self::INTERNAL_TERM,
            $stored,
            'A registered entity type defaults an undeclared field to Internal; it must never be written to the index file.',
        );
        self::assertStringNotContainsString(
            self::RESTRICTED_TERM,
            $stored,
            'Protected is deny-unless-granted, and reindex runs with no account scope; it must never be written to the index file.',
        );
    }

    private function reindex(): CliTester
    {
        $handler = new SearchReindexHandler(
            $this->indexer,
            $this->entityTypeManager(),
            new EntitySearchProjectionRegistry([$this->generatedProjector()]),
        );

        $definition = new HandlerCommand(
            name: 'search:reindex',
            description: 'Rebuild the search index from all indexable entities',
            options: [
                new HandlerOption(
                    name: 'batch-size',
                    shortcut: 'b',
                    mode: HandlerOptionMode::Required,
                    description: 'Entities per batch',
                    default: '100',
                ),
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

        $tester = CliTester::for($definition, $container);
        $tester->execute([]);

        return $tester;
    }

    /** The projector exactly as `make:search-projection` emits it. */
    private function generatedProjector(): EntitySearchProjectorInterface
    {
        $class = 'App\\Search\\ScaffoldStorySearchProjector';
        if (!class_exists($class, false)) {
            $plan = new SearchProjectionScaffoldCompiler()->compile(
                'scaffold_story',
                'ScaffoldStory',
                ['body', 'internal_note', 'restricted_note'],
            );
            foreach ($plan->artifacts as $artifact) {
                if ($artifact->path !== 'src/Search/ScaffoldStorySearchProjector.php') {
                    continue;
                }
                $file = tempnam(sys_get_temp_dir(), 'waaseyaa_reindex_projector_') . '.php';
                file_put_contents($file, $artifact->content);
                require $file;
                unlink($file);
                break;
            }
        }
        self::assertTrue(class_exists($class, false), 'The compiled plan did not contain the generated projector.');

        return new $class();
    }

    private function entityTypeManager(): EntityTypeManagerInterface
    {
        $entity = new ScaffoldedStory([
            'id' => 1,
            'title' => 'Fall harvest',
            'body' => 'Wild rice camp opens Monday ' . self::PUBLIC_TERM,
            'internal_note' => self::INTERNAL_TERM,
            'restricted_note' => self::RESTRICTED_TERM,
        ]);

        $query = $this->createStub(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn(['1']);

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->method('findMany')->willReturn([$entity]);

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getDefinitions')->willReturn([
            'scaffold_story' => new EntityType(
                id: 'scaffold_story',
                label: 'Scaffolded Story',
                class: ScaffoldedStory::class,
                keys: ['id' => 'id', 'label' => 'title'],
            ),
        ]);
        $manager->method('getRepository')->willReturn($repository);

        return $manager;
    }

    /** @return list<array<string, mixed>> */
    private function indexedRows(): array
    {
        return array_values(iterator_to_array(
            $this->database->query('SELECT document_id, title, body FROM search_index'),
        ));
    }

}
