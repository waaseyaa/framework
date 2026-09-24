<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Integration;

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
 * FW-AIV-COMP-01 (#3139), AIV-EXEC-002: POST_SAVE and POST_DELETE run after
 * the entity mutation has committed. A vector storage failure there must be
 * best-effort: logged, never surfaced as a failure of the committed mutation,
 * and never stopping later listeners for the same event.
 *
 * Runs through a real repository and unit of work from a booted kernel. The
 * ai-vector listeners are registered explicitly with a storage that always
 * fails, so this test doesn't depend on how the listeners are composed.
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
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php return [];");
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
    public function a_committed_delete_succeeds_logs_and_lets_later_listeners_run_when_vector_cleanup_fails(): void
    {
        $node = $this->saveNode(published: true);
        $this->listen(EntityEvents::POST_DELETE->value, [new EntityEmbeddingCleanupListener($this->failingStorage(), logger: $this->logger()), 'onPostDelete']);

        $this->repository()->delete($node);

        self::assertNull($this->repository()->find((string) $node->id()), 'the delete committed');
        $this->assertLoggedStorageFailure();
        self::assertSame(1, $this->laterListenerRuns, 'a later POST_DELETE listener still ran');
    }

    #[Test]
    public function a_committed_non_indexable_save_succeeds_logs_and_lets_later_listeners_run_when_vector_removal_fails(): void
    {
        $node = $this->saveNode(published: true);
        $this->listen(EntityEvents::POST_SAVE->value, [new EntityEmbeddingListener(storage: $this->failingStorage(), logger: $this->logger(), entityTypeManager: $this->kernel->getEntityTypeManager()), 'onPostSave']);

        // Unpublishing makes the node non-indexable, so the listener removes its vector.
        $node->set('status', false);
        $this->repository()->save($node);

        self::assertFalse((bool) $this->repository()->find((string) $node->id())?->get('status'), 'the save committed');
        $this->assertLoggedStorageFailure();
        self::assertSame(1, $this->laterListenerRuns, 'a later POST_SAVE listener still ran');
    }

    private function saveNode(bool $published): Node
    {
        $node = new Node(['title' => 'Probe', 'slug' => 'probe', 'type' => 'page', 'status' => $published, 'uid' => $this->ownerId]);
        $node->enforceIsNew();
        $this->repository()->save($node);

        return $node;
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

    private function assertLoggedStorageFailure(): void
    {
        $errors = array_values(array_filter($this->logged, static fn(array $entry): bool => $entry[0] === LogLevel::ERROR));
        self::assertCount(1, $errors, 'the storage failure is logged once, as an error');
        self::assertStringContainsString('vector storage unavailable', $errors[0][1]);
    }
}
