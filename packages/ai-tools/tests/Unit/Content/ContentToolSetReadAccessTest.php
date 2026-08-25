<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\Content\ContentOperationTool;
use Waaseyaa\AI\Tools\Content\ContentToolSet;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\ContentTypeDescriptor;
use Waaseyaa\Publishing\FieldSpec;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;
use Waaseyaa\Publishing\Preview\PreviewLinkService;
use Waaseyaa\Publishing\Tests\Fixtures\PublisherAccount;
use Waaseyaa\Publishing\Tests\Fixtures\SlugScopedViewPolicy;
use Waaseyaa\Publishing\Tests\Fixtures\TestArticleEntity;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * Framework #2516 through the consumer-facing surface.
 *
 * The MCP content tools are thin adapters over `ContentPublisher`, so the
 * per-entity access decision must be observable exactly as an agent sees it:
 * `article.list` omits hidden items, and every single-item read comes back as
 * the `NOT_FOUND` error envelope — never `UNAUTHORIZED`, which would be an
 * existence oracle for content the credential may not see.
 */
#[CoversClass(ContentToolSet::class)]
#[CoversClass(ContentOperationTool::class)]
final class ContentToolSetReadAccessTest extends TestCase
{
    private const string CAPABILITY = 'publish test articles';

    private DBALDatabase $db;
    private EntityRepository $repo;
    private ContentTypeDescriptor $descriptor;
    private PublisherAccount $actor;
    private ?EntityValueReadGuardInterface $priorGuard = null;

    protected function setUp(): void
    {
        $this->priorGuard = EntityReadRuntime::guard();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            new AccountFieldReadScope(),
            static fn(): AccessResult => AccessResult::forbidden('No ambient protected-field grant.'),
        ));

        $db = $this->db = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::publishing($db);
        $entityType = new EntityType(
            id: 'test_article',
            label: 'Test article',
            class: TestArticleEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $schema = new SqlSchemaHandler($entityType, $db);
        $schema->ensureTable();
        $schema->ensureRevisionTable();
        $resolver = new SingleConnectionResolver($db);
        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            new EventDispatcher(),
            new RevisionableStorageDriver($resolver, $entityType),
            $db,
        );
        $this->descriptor = new ContentTypeDescriptor(
            entityTypeId: 'test_article',
            bundle: null,
            slugField: 'slug',
            statusField: 'status',
            writableFields: [
                'slug' => new FieldSpec(type: 'string', required: true),
                'title' => new FieldSpec(type: 'string', required: true),
                'summary' => new FieldSpec(type: 'text'),
            ],
            htmlSanitizer: null,
            validators: [],
            publishCapability: self::CAPABILITY,
        );
        $this->actor = new PublisherAccount(permissions: [self::CAPABILITY]);
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard($this->priorGuard);
    }

    #[Test]
    public function the_content_tools_omit_and_refuse_entities_the_credential_may_not_view(): void
    {
        $seeder = $this->publisher();
        $visible = $seeder->createDraft($this->actor, $this->values('visible-post', 'Visible'), 'seed-1');
        $hidden = $seeder->createDraft($this->actor, $this->values('secret-post', 'Secret'), 'seed-2');
        $seeder->updateDraft($this->actor, (string) $hidden['id'], ['summary' => 'v2'], $hidden['revision_id'], 'seed-3');

        $tools = $this->tools($this->publisher(['secret-post']));

        $listed = $this->call($tools, 'article.list', []);
        self::assertCount(1, $listed['items']);
        self::assertSame($visible['id'], $listed['items'][0]['id']);

        foreach ([
            ['article.get', ['id' => (string) $hidden['id']]],
            ['article.get', ['id' => 'secret-post']],
            ['article.revisions', ['id' => (string) $hidden['id']]],
            ['article.preview', ['id' => (string) $hidden['id']]],
        ] as [$tool, $arguments]) {
            $error = $this->callExpectingError($tools, $tool, $arguments);
            self::assertSame('NOT_FOUND', $error['code'], $tool . ' leaked a distinguishable refusal.');
        }

        // The visible sibling still works through the very same tool surface.
        self::assertSame($visible['id'], $this->call($tools, 'article.get', ['id' => (string) $visible['id']])['id']);
        self::assertNotSame([], $this->call($tools, 'article.revisions', ['id' => (string) $visible['id']])['revisions']);
    }

    #[Test]
    public function a_refused_read_is_shaped_exactly_like_a_read_of_an_absent_item(): void
    {
        $seeder = $this->publisher();
        $hidden = $seeder->createDraft($this->actor, $this->values('secret-post', 'Secret'), 'seed-1');

        $tools = $this->tools($this->publisher(['secret-post']));

        $refused = $this->callExpectingError($tools, 'article.get', ['id' => (string) $hidden['id']]);
        $absent = $this->callExpectingError($tools, 'article.get', ['id' => '999999']);

        self::assertSame(array_keys($absent), array_keys($refused));
        self::assertSame($absent['code'], $refused['code']);
        self::assertSame(
            \sprintf('No content found for "%s".', (string) $hidden['id']),
            $refused['message'],
        );
    }

    /**
     * `article.rollback` restores a revision's CONTENT and returns it, so it is
     * also a read of that revision. The per-revision fence must therefore hold
     * at the agent surface too, with the same NOT_FOUND envelope the read tools
     * use (#2516).
     */
    #[Test]
    public function the_rollback_tool_refuses_a_target_revision_the_credential_may_not_view(): void
    {
        $seeder = $this->publisher();
        $draft = $seeder->createDraft(
            $this->actor,
            ['slug' => 'open-post', 'title' => 'Open', 'summary' => 'ORIGINAL-SECRET'],
            'seed-1',
        );
        $seeder->updateDraft($this->actor, (string) $draft['id'], ['summary' => 'v2'], $draft['revision_id'], 'seed-2');

        $tools = $this->tools($this->publisher(deniedRevisionIds: [(int) $draft['revision_id']]));

        $error = $this->callExpectingError($tools, 'article.rollback', [
            'id' => (string) $draft['id'],
            'target_revision_id' => (int) $draft['revision_id'],
            'idempotency_key' => 'rollback-key-1',
        ]);

        self::assertSame('NOT_FOUND', $error['code']);
        self::assertStringNotContainsString('ORIGINAL-SECRET', json_encode($error, JSON_THROW_ON_ERROR));

        // The hidden revision did not become the working copy either.
        self::assertSame('v2', $seeder->get($this->actor, (string) $draft['id'])['summary']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param list<string> $deniedSlugs
     * @param list<int>    $deniedRevisionIds
     */
    private function publisher(array $deniedSlugs = [], array $deniedRevisionIds = []): ContentPublisher
    {
        return new ContentPublisher(
            $this->descriptor,
            $this->repo,
            new IdempotencyStore($this->db),
            null,
            new EntityAccessHandler([new SlugScopedViewPolicy('test_article', $deniedSlugs, $deniedRevisionIds)]),
        );
    }

    /** @return array<string, AgentTool> */
    private function tools(ContentPublisher $publisher): array
    {
        $set = new ContentToolSet(
            $publisher,
            $this->descriptor,
            new PreviewLinkService('preview-secret'),
            static fn(string $id, int $exp, string $sig): string => "/news/preview/$id?exp=$exp&sig=$sig",
        );

        $sink = [];
        $registry = new class ($sink) implements ToolRegistryInterface {
            /** @param array<string, AgentTool> $sink */
            public function __construct(public array &$sink) {}

            public function register(AgentTool $tool): void
            {
                $this->sink[$tool->name] = $tool;
            }

            public function get(string $name): AgentTool
            {
                return $this->sink[$name] ?? throw new ToolNotFoundException($name);
            }

            public function has(string $name): bool
            {
                return isset($this->sink[$name]);
            }

            public function all(): iterable
            {
                return $this->sink;
            }
        };
        $set->register($registry, 'article');

        return $sink;
    }

    /**
     * @param array<string, AgentTool> $tools
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(array $tools, string $tool, array $arguments): array
    {
        $result = $tools[$tool]->impl->execute($arguments, $this->actor);
        self::assertFalse($result->isError, 'Unexpected tool error: ' . json_encode($result->content));

        return json_decode((string) $result->content[0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, AgentTool> $tools
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function callExpectingError(array $tools, string $tool, array $arguments): array
    {
        $result = $tools[$tool]->impl->execute($arguments, $this->actor);
        self::assertTrue($result->isError, $tool . ' returned content instead of a refusal.');

        return json_decode((string) $result->content[0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function values(string $slug, string $title): array
    {
        return ['slug' => $slug, 'title' => $title, 'summary' => 'A summary.'];
    }
}
