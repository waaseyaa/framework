<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AiVector;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\AI\Vector\EntityEmbeddingCleanupListener;
use Waaseyaa\AI\Vector\EntityEmbeddingListener;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\User\User;
use Waaseyaa\User\UserServiceProvider;

/**
 * Default invalidation runs inside the source transaction and fails closed:
 * projection failure rolls back source changes before postcommit callbacks.
 * Standalone freshly sourced postcommit indexing/cleanup remains best-effort:
 * failure is logged without changing the already committed source outcome.
 *
 * Runs through a real repository and unit of work from a booted kernel. The
 * Listeners are registered explicitly with a storage that always fails.
 * Separate composition tests pin the default transaction-side topology.
 */
#[CoversNothing]
final class PostCommitVectorFailureTest extends TestCase
{
    private string $projectRoot;
    private ConsoleKernel $kernel;
    /** @var list<array{LogLevel, string}> */
    private array $logged = [];
    private int $laterListenerRuns = 0;
    private int|string $ownerId = 0;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_aiv_post_commit_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage/framework', 0o755, true);
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', "<?php return ['database' => ':memory:', 'environment' => 'testing'];");
        file_put_contents($this->projectRoot . '/config/entity-types.php', '<?php return [];');
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/aiv-post-commit-test',
            'extra' => ['waaseyaa' => ['providers' => [UserServiceProvider::class, NodeServiceProvider::class]]],
        ], JSON_THROW_ON_ERROR));

        $this->kernel = new ConsoleKernel($this->projectRoot);
        (fn() => $this->boot())->call($this->kernel);
        $database = $this->kernel->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $manager = $this->kernel->getEntityTypeManager();
        RuntimeSchemaMigrations::entities($database, $manager, $manager->getDefinitions());

        $type = new NodeType(['type' => 'page', 'name' => 'Page']);
        $type->enforceIsNew();
        $manager->getRepository('node_type')->save($type);

        $owner = new User(['name' => 'owner', 'mail' => 'owner@example.test', 'pass' => 'x', 'status' => true]);
        $owner->enforceIsNew();
        $manager->getRepository('user')->save($owner);
        $this->ownerId = $owner->id() ?? 0;
    }

    protected function tearDown(): void
    {
        new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry')->setValue(null, null);
        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function transactional_invalidation_failure_rolls_back_source_before_postcommit_events(): void
    {
        $node = $this->saveNode(published: true);
        $sourceListener = new \Waaseyaa\AI\Vector\EmbeddingSourceChangedListener($this->failingStorage(), new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard());
        $this->listen(\Waaseyaa\EntityStorage\Event\EntitySourceChangedEvent::class, [$sourceListener, 'onSourceChanged']);
        $dispatcher = (fn() => $this->dispatcher)->call($this->kernel);
        $postcommit = 0;
        $dispatcher->addListener(EntityEvents::POST_SAVE->value, static function () use (&$postcommit): void {
            ++$postcommit;
        });
        $node->set('status', false);
        try {
            $this->repository()->save($node);
            self::fail('Projection invalidation failure must abort the mutation.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('vector storage unavailable', $error->getMessage());
        }
        self::assertSame(1, $this->storedStatus($node), 'source unpublication rolled back');
        self::assertSame(0, $postcommit, 'no postcommit save event on refused source change');
        self::assertSame(0, $this->laterListenerRuns, 'later source subscriber was not run after refusal');
    }

    #[Test]
    public function a_committed_delete_succeeds_logs_and_lets_later_listeners_run_when_vector_cleanup_fails(): void
    {
        $node = $this->saveNode(published: true);
        $this->listen(EntityEvents::POST_DELETE->value, [new EntityEmbeddingCleanupListener($this->failingStorage(), logger: $this->logger(), executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard(), entityTypeManager: $this->kernel->getEntityTypeManager()), 'onPostDelete']);

        $this->repository()->delete($node);

        self::assertNull($this->repository()->find((string) $node->id()), 'the delete committed');
        $this->assertLoggedStorageFailure();
        self::assertSame(1, $this->laterListenerRuns, 'a later POST_DELETE listener still ran');
    }

    #[Test]
    public function a_committed_non_indexable_save_succeeds_logs_and_lets_later_listeners_run_when_vector_removal_fails(): void
    {
        $node = $this->saveNode(published: true);
        $this->listen(EntityEvents::POST_SAVE->value, [new EntityEmbeddingListener(storage: $this->failingStorage(), logger: $this->logger(), entityTypeManager: $this->kernel->getEntityTypeManager(), executionGuard: new \Waaseyaa\AI\Vector\Testing\InMemoryEmbeddingExecutionGuard()), 'onPostSave']);

        // Unpublishing makes the node non-indexable, so the listener removes its vector.
        $node->set('status', false);
        $this->repository()->save($node);

        self::assertSame(0, $this->storedStatus($node), 'the unpublish committed');
        $this->assertLoggedStorageFailure('AIV-EXECUTION-007');
        self::assertSame(1, $this->laterListenerRuns, 'a later POST_SAVE listener still ran');
    }

    private function saveNode(bool $published): Node
    {
        $node = new Node(['title' => 'Probe', 'slug' => 'probe', 'type' => 'page', 'status' => $published, 'uid' => $this->ownerId]);
        $node->enforceIsNew();
        $this->repository()->save($node);

        return $node;
    }

    /** Reads the committed row directly, without a field-read context. */
    private function storedStatus(Node $node): int
    {
        $database = $this->kernel->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $row = $database->getConnection()->fetchAssociative('SELECT * FROM node WHERE nid = ?', [$node->id()]);
        self::assertIsArray($row);
        if (array_key_exists('status', $row)) {
            return (int) $row['status'];
        }
        $data = json_decode((string) $row['_data'], true, 512, JSON_THROW_ON_ERROR);

        return (int) ($data['status'] ?? -1);
    }

    private function repository(): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
    {
        return $this->kernel->getEntityTypeManager()->getRepository('node');
    }

    /** Registers the ai-vector listener, then a later one that records whether it ran. */
    private function listen(string $event, callable $listener): void
    {
        $dispatcher = (fn() => $this->dispatcher)->call($this->kernel);
        $dispatcher->addListener($event, $listener, 0);
        $dispatcher->addListener($event, function (): void {
            $this->laterListenerRuns++;
        }, -100);
    }

    private function failingStorage(): EmbeddingStorageInterface
    {
        return new class implements EmbeddingStorageInterface {
            public function store(string $entityType, string $id, array $vector): void
            {
                throw new \RuntimeException('vector storage unavailable');
            }

            public function findSimilar(array $queryVector, string $entityType, int $limit): array
            {
                throw new \RuntimeException('vector storage unavailable');
            }

            public function delete(string $entityType, string $id): void
            {
                throw new \RuntimeException('vector storage unavailable');
            }
        };
    }

    private function logger(): LoggerInterface
    {
        $logged = &$this->logged;

        return new class ($logged) implements LoggerInterface {
            use LoggerTrait;

            /** @param list<array{LogLevel, string}> $logged */
            public function __construct(private array &$logged) {}

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->logged[] = [$level, (string) $message];
            }
        };
    }

    private function assertLoggedStorageFailure(string $expected = 'vector storage unavailable'): void
    {
        $errors = array_values(array_filter($this->logged, static fn(array $entry): bool => $entry[0] === LogLevel::ERROR));
        self::assertCount(1, $errors, 'the storage failure is logged once, as an error');
        self::assertStringContainsString($expected, $errors[0][1]);
    }
}
