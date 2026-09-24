<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\AiVector;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\AI\Vector\AiVectorServiceProvider;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\User\User;
use Waaseyaa\User\UserServiceProvider;

/**
 * FW-AIV-COMP-01 (#3139), maintainer decision D2-B: outside HTTP (CLI,
 * imports, workers) a save or delete removes any existing vector, including a
 * save of indexable content, and never calls the embedding provider.
 * `semantic:refresh` re-indexes.
 *
 * Discriminating: an embedding provider IS configured, pointing at a closed
 * local port. A composition that embedded on save would fail to embed and
 * keep the old vector; one that registered no listeners (the #3139 base)
 * would keep it too. Only safe invalidation removes it.
 */
#[CoversNothing]
final class ConsoleVectorInvalidationTest extends TestCase
{
    private string $projectRoot;
    private ConsoleKernel $kernel;
    private int|string $ownerId = 0;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_aiv_console_invalidation_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage/framework', 0o755, true);
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing', 'ai' => ['embedding_provider' => 'ollama', 'ollama_endpoint' => 'http://127.0.0.1:9/api/embeddings']];",
        );
        file_put_contents($this->projectRoot . '/config/entity-types.php', '<?php return [];');
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/aiv-console-invalidation-test',
            'extra' => ['waaseyaa' => ['providers' => [UserServiceProvider::class, NodeServiceProvider::class, AiVectorServiceProvider::class]]],
        ], JSON_THROW_ON_ERROR));

        $this->kernel = new ConsoleKernel($this->projectRoot);
        (fn() => $this->boot())->call($this->kernel);
        $database = $this->kernel->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $manager = $this->kernel->getEntityTypeManager();
        RuntimeSchemaMigrations::entities($database, $manager, $manager->getDefinitions());
        RuntimeSchemaMigrations::aiVector($database);

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
    public function a_console_save_of_indexable_content_removes_the_existing_vector(): void
    {
        $node = $this->saveNode('Original title');
        $this->storage()->store('node', (string) $node->id(), [1.0, 0.0]);
        self::assertTrue($this->hasVector($node), 'precondition: an indexed vector exists');

        $node->set('title', 'Edited in the CLI');
        $this->repository()->save($node);

        self::assertFalse($this->hasVector($node), 'the stale vector is removed; semantic:refresh re-indexes');
    }

    #[Test]
    public function a_console_delete_removes_the_vector(): void
    {
        $node = $this->saveNode('To be deleted');
        $this->storage()->store('node', (string) $node->id(), [1.0, 0.0]);

        $this->repository()->delete($node);

        self::assertFalse($this->hasVector($node));
    }

    private function saveNode(string $title): Node
    {
        $node = new Node(['title' => $title, 'slug' => 'n-' . bin2hex(random_bytes(3)), 'type' => 'page', 'status' => true, 'uid' => $this->ownerId]);
        $node->enforceIsNew();
        $this->repository()->save($node);

        return $node;
    }

    private function hasVector(Node $node): bool
    {
        $database = $this->kernel->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $database);

        return (int) $database->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM embeddings WHERE entity_type = ? AND entity_id = ?',
            ['node', (string) $node->id()],
        ) === 1;
    }

    private function storage(): EmbeddingStorageInterface
    {
        foreach ((fn() => $this->providers)->call($this->kernel) as $provider) {
            if ($provider instanceof AiVectorServiceProvider) {
                $storage = $provider->resolve(EmbeddingStorageInterface::class);
                self::assertInstanceOf(EmbeddingStorageInterface::class, $storage);

                return $storage;
            }
        }

        self::fail('AiVectorServiceProvider is not registered.');
    }

    private function repository(): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
    {
        return $this->kernel->getEntityTypeManager()->getRepository('node');
    }
}
