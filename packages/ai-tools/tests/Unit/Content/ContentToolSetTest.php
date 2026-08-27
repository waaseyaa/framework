<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\Content\ContentOperationTool;
use Waaseyaa\AI\Tools\Content\ContentToolSet;
use Waaseyaa\AI\Tools\Content\MediaAssetStore;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisoryGate;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\Exception\ContentAuthorizationException;
use Waaseyaa\Publishing\ContentTypeDescriptor;
use Waaseyaa\Publishing\FieldSpec;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;
use Waaseyaa\Publishing\Preview\PreviewLinkService;
use Waaseyaa\Publishing\Tests\Fixtures\PublisherAccount;
use Waaseyaa\Publishing\Tests\Fixtures\TestArticleEntity;

#[CoversClass(ContentToolSet::class)]
#[CoversClass(ContentOperationTool::class)]
#[CoversClass(MediaAssetStore::class)]
final class ContentToolSetTest extends TestCase
{
    private const string CAPABILITY = 'publish test articles';
    /** 1x1 transparent PNG. */
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var array<string, AgentTool> */
    private array $tools = [];
    private PublisherAccount $actor;
    private string $uploadsDir;
    private string $filesRoot;
    private EntityRepository $mediaRepository;
    private DBALDatabase $database;
    private EventDispatcher $articleEvents;

    protected function setUp(): void
    {
        $db = $this->database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::publishing($db);
        $articleType = new EntityType(
            id: 'test_article',
            label: 'Test article',
            class: TestArticleEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $handler = new SqlSchemaHandler($articleType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();
        $resolver = new SingleConnectionResolver($db);
        $this->articleEvents = new EventDispatcher();
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $articleType,
            new SqlStorageDriver($resolver),
            $this->articleEvents,
            new RevisionableStorageDriver($resolver, $articleType),
            $db,
        );

        $descriptor = new ContentTypeDescriptor(
            entityTypeId: 'test_article',
            bundle: null,
            slugField: 'slug',
            statusField: 'status',
            writableFields: [
                'slug' => new FieldSpec(type: 'string', required: true),
                'title' => new FieldSpec(type: 'string', required: true),
                'summary' => new FieldSpec(type: 'text'),
                'body_html' => new FieldSpec(type: 'text', html: true),
                'promote' => new FieldSpec(type: 'bool'),
                'publish_on' => new FieldSpec(type: 'date', nullable: true),
                'related' => new FieldSpec(type: 'reference_list', maxItems: 3),
            ],
            htmlSanitizer: new \Waaseyaa\Publishing\Tests\Fixtures\SymfonyTestSanitizer(['p']),
            validators: [],
            publishCapability: self::CAPABILITY,
        );
        $publisher = new ContentPublisher($descriptor, $repo, new IdempotencyStore($db));

        // Media store over a second (bundle-carrying) entity type.
        $mediaType = new EntityType(
            id: 'test_media',
            label: 'Test media',
            class: \Waaseyaa\Publishing\Tests\Fixtures\TestArticleEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $mediaSchema = new SqlSchemaHandler($mediaType, $db);
        $mediaSchema->ensureTable();
        $mediaSchema->ensureRevisionTable();
        $mediaRepo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $mediaType,
            new SqlStorageDriver($resolver),
            new EventDispatcher(),
            new RevisionableStorageDriver($resolver, $mediaType),
            $db,
        );
        $this->mediaRepository = $mediaRepo;
        $this->filesRoot = sys_get_temp_dir() . '/waaseyaa_files_' . uniqid();
        $this->uploadsDir = $this->filesRoot . '/assets';
        $access = $this->createStub(EntityAccessHandler::class);
        $access->method('checkCreateAccess')->willReturn(AccessResult::allowed());
        // asset.get is gated on the catalog row's view access (#2517).
        $access->method('check')->willReturn(AccessResult::allowed());
        $assets = new MediaAssetStore($mediaRepo, $this->uploadsDir, '/media/uploads', $access, $this->filesRoot, bundle: 'test_media');

        $set = new ContentToolSet(
            $publisher,
            $descriptor,
            new PreviewLinkService('preview-secret'),
            static fn(string $id, int $exp, string $sig): string => "/news/preview/$id?exp=$exp&sig=$sig",
            $assets,
        );

        $tools = [];
        $registry = new class ($tools) implements ToolRegistryInterface {
            /** @param array<string, AgentTool> $tools */
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
        $this->tools = $tools;
        $this->actor = new PublisherAccount(permissions: [self::CAPABILITY]);
    }

    #[Test]
    public function asset_upload_fails_before_writing_when_media_create_access_is_denied(): void
    {
        $access = $this->createStub(EntityAccessHandler::class);
        $access->method('checkCreateAccess')->willReturn(AccessResult::forbidden('denied'));
        $directory = $this->uploadsDir . '/denied';
        $store = new MediaAssetStore($this->mediaRepository, $directory, '/media/uploads', $access, $this->filesRoot);
        $before = $this->mediaRepository->count();

        $this->expectException(ContentAuthorizationException::class);
        try {
            $store->upload('pixel.png', base64_decode(self::PNG_BASE64, true), $this->actor);
        } finally {
            self::assertDirectoryDoesNotExist($directory);
            self::assertSame($before, $this->mediaRepository->count());
        }
    }

    #[Test]
    public function asset_upload_attributes_the_catalog_save_to_the_authenticated_actor(): void
    {
        $access = $this->createStub(EntityAccessHandler::class);
        $access->method('checkCreateAccess')->willReturn(AccessResult::allowed());
        $directory = $this->uploadsDir . '/attributed';
        $store = new MediaAssetStore($this->mediaRepository, $directory, '/media/uploads', $access, $this->filesRoot);

        try {
            $store->upload('pixel.png', base64_decode(self::PNG_BASE64, true), $this->actor);
            $saved = $this->mediaRepository->findBy(['bundle' => 'image']);
            self::assertCount(1, $saved);
            $rows = iterator_to_array($this->database->query("SELECT json_extract(_data, '$.uid') AS uid FROM test_media LIMIT 1"));
            self::assertSame(900001, (int) $rows[0]['uid']);
            $revisions = $this->mediaRepository->listRevisions((string) $saved[0]->id());
            self::assertCount(1, $revisions);
            self::assertSame(900001, $revisions[0]->revisionMetadata()?->revisionAuthor);
        } finally {
            array_map(unlink(...), glob($directory . '/*') ?: []);
            @rmdir($directory);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadsDir)) {
            array_map(unlink(...), glob($this->uploadsDir . '/*') ?: []);
            @rmdir($this->uploadsDir);
        }
    }

    /** @return array<string, mixed> Decoded success payload. */
    private function call(string $tool, array $args): array
    {
        $result = $this->tools[$tool]->impl->execute($args, $this->actor);
        self::assertFalse($result->isError, 'Unexpected tool error: ' . json_encode($result->content));

        return json_decode((string) $result->content[0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> Decoded error envelope. */
    private function callExpectingError(string $tool, array $args): array
    {
        $result = $this->tools[$tool]->impl->execute($args, $this->actor);
        self::assertTrue($result->isError);

        return json_decode((string) $result->content[0]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function the_full_stable_tool_set_declares_risk_accurately_under_the_capability(): void
    {
        $expected = [
            'article.list', 'article.get', 'article.createDraft', 'article.updateDraft', 'article.preview',
            'article.publish', 'article.unpublish', 'article.revisions', 'article.rollback',
            'asset.upload', 'asset.get',
        ];
        self::assertSame($expected, array_keys($this->tools));

        $approvalRequired = [
            'article.createDraft',
            'article.updateDraft',
            'article.publish',
            'article.unpublish',
            'article.rollback',
            'asset.upload',
        ];
        foreach ($this->tools as $tool) {
            self::assertSame(
                in_array($tool->name, $approvalRequired, true),
                $tool->destructive,
                $tool->name,
            );
            self::assertSame(self::CAPABILITY, $tool->capability);
            self::assertSame('https://json-schema.org/draft/2020-12/schema', $tool->inputSchema['$schema']);
            self::assertFalse($tool->inputSchema['additionalProperties']);
            self::assertNotNull($tool->title);
            self::assertSame('object', $tool->outputSchema['type'] ?? null);
            self::assertFalse($tool->openWorld);
        }

        self::assertTrue($this->tools['article.createDraft']->idempotent);
        self::assertFalse($this->tools['asset.upload']->idempotent);

        $values = $this->tools['article.createDraft']->inputSchema['properties']['values']['properties'];
        self::assertSame([
            'anyOf' => [
                ['type' => 'string', 'format' => 'date'],
                ['type' => 'null'],
            ],
        ], $values['publish_on']);
        self::assertSame('array', $values['related']['type']);
        self::assertSame(3, $values['related']['maxItems']);
        self::assertTrue($values['related']['uniqueItems']);
    }

    #[Test]
    public function draft_publish_flow_works_end_to_end_through_the_tools(): void
    {
        $draft = $this->call('article.createDraft', [
            'values' => ['slug' => 'tool-post', 'title' => 'Tool post', 'body_html' => '<p>Hi</p><script>x</script>'],
            'idempotency_key' => 'tool-key-1',
        ]);
        self::assertFalse($draft['status']);
        self::assertStringNotContainsString('<script', (string) $draft['body_html']);
        self::assertSame($draft, $this->tools['article.createDraft']->impl->execute([
            'values' => ['slug' => 'tool-post', 'title' => 'Tool post', 'body_html' => '<p>Hi</p><script>x</script>'],
            'idempotency_key' => 'tool-key-1',
        ], $this->actor)->structuredContent);

        $published = $this->call('article.publish', [
            'id' => (string) $draft['id'],
            'expected_revision_id' => $draft['revision_id'],
            'idempotency_key' => 'tool-key-2',
            'note' => 'Ship it',
        ]);
        self::assertTrue($published['status']);

        $list = $this->call('article.list', ['published_only' => true]);
        self::assertCount(1, $list['items']);

        $revisions = $this->call('article.revisions', ['id' => (string) $draft['id']]);
        self::assertSame('Ship it', $revisions['revisions'][0]['log']);
    }

    #[Test]
    public function validation_failures_return_the_structured_field_specific_envelope(): void
    {
        $error = $this->callExpectingError('article.createDraft', [
            'values' => ['slug' => 'x', 'title' => 'T', 'nope' => 'y'],
            'idempotency_key' => 'tool-key-3',
        ]);
        self::assertSame('VALIDATION_FAILED', $error['code']);
        self::assertContains('nope', array_column($error['errors'], 'field'));
    }

    #[Test]
    public function draft_tools_expose_and_forward_structured_save_advisories(): void
    {
        foreach (['article.createDraft', 'article.updateDraft'] as $toolName) {
            $property = $this->tools[$toolName]->inputSchema['properties']['save_advisory_acknowledgements'];
            self::assertSame('array', $property['type']);
            self::assertSame(32, $property['maxItems']);
            self::assertSame('^[a-f0-9]{64}$', $property['items']['pattern']);
        }

        $this->articleEvents->addListener(BeforeSaveEvent::class, static function (BeforeSaveEvent $event): void {
            SaveAdvisoryGate::requireAcknowledged([
                SaveAdvisory::forEntityField(
                    $event->entity(),
                    'EDITORIAL_TITLE_REVIEW',
                    'title',
                    'Review the title before saving.',
                ),
            ], $event->saveContext());
        });

        $input = [
            'values' => ['slug' => 'advisory-tool', 'title' => 'Review me'],
            'idempotency_key' => 'advisory-tool-key',
        ];
        $error = $this->callExpectingError('article.createDraft', $input);
        self::assertSame('SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED', $error['code']);
        self::assertSame('EDITORIAL_TITLE_REVIEW', $error['meta']['save_advisories'][0]['code']);

        $saved = $this->call('article.createDraft', $input + [
            'save_advisory_acknowledgements' => [$error['meta']['save_advisories'][0]['acknowledgement']],
        ]);
        self::assertSame('Review me', $saved['title']);
    }

    #[Test]
    public function stale_revision_ids_return_the_conflict_envelope_with_both_revisions(): void
    {
        $draft = $this->call('article.createDraft', [
            'values' => ['slug' => 'conflict-post', 'title' => 'C'],
            'idempotency_key' => 'tool-key-4',
        ]);
        $this->call('article.updateDraft', [
            'id' => (string) $draft['id'], 'values' => ['summary' => 'v2'],
            'expected_revision_id' => $draft['revision_id'], 'idempotency_key' => 'tool-key-5',
        ]);

        $error = $this->callExpectingError('article.updateDraft', [
            'id' => (string) $draft['id'], 'values' => ['summary' => 'stale'],
            'expected_revision_id' => $draft['revision_id'], 'idempotency_key' => 'tool-key-6',
        ]);
        self::assertSame('REVISION_CONFLICT', $error['code']);
        self::assertSame($draft['revision_id'], $error['meta']['expected_revision_id']);
        self::assertGreaterThan($draft['revision_id'], $error['meta']['current_revision_id']);
    }

    #[Test]
    public function preview_returns_a_signed_url_and_mutates_nothing(): void
    {
        $draft = $this->call('article.createDraft', [
            'values' => ['slug' => 'preview-post', 'title' => 'P'],
            'idempotency_key' => 'tool-key-7',
        ]);
        $preview = $this->call('article.preview', ['id' => (string) $draft['id']]);

        self::assertMatchesRegularExpression('#^/news/preview/\d+\?exp=\d+&sig=[a-f0-9]{64}$#', $preview['preview_url']);
        $after = $this->call('article.get', ['id' => (string) $draft['id']]);
        self::assertSame($draft['revision_id'], $after['revision_id']);
        self::assertFalse($after['status']);
    }

    #[Test]
    public function asset_upload_accepts_a_real_png_and_asset_get_returns_it(): void
    {
        $uploaded = $this->call('asset.upload', ['filename' => 'social.png', 'content_base64' => self::PNG_BASE64]);

        self::assertSame('image/png', $uploaded['mime']);
        self::assertSame(1, $uploaded['width']);
        self::assertSame(1, $uploaded['height']);
        self::assertMatchesRegularExpression('#^[a-f0-9]{64}$#', (string) $uploaded['asset_id']);
        self::assertMatchesRegularExpression('#^/media/uploads/[a-f0-9]{64}\.png$#', $uploaded['url']);

        $fetched = $this->call('asset.get', ['asset_id' => (string) $uploaded['asset_id']]);
        self::assertSame($uploaded['url'], $fetched['url']);
    }

    #[Test]
    public function asset_upload_rejects_non_image_bytes_fail_closed(): void
    {
        $error = $this->callExpectingError('asset.upload', [
            'filename' => 'evil.png',
            'content_base64' => base64_encode('<?php echo "not an image";'),
        ]);
        self::assertSame('ASSET_REJECTED', $error['code']);

        $badBase64 = $this->callExpectingError('asset.upload', [
            'filename' => 'x.png',
            'content_base64' => '!!!not-base64!!!',
        ]);
        self::assertSame('ASSET_REJECTED', $badBase64['code']);
    }

    #[Test]
    public function audit_arguments_redact_binary_payloads(): void
    {
        $impl = $this->tools['asset.upload']->impl;
        $redacted = $impl->argumentsForAudit(['filename' => 'x.png', 'content_base64' => self::PNG_BASE64]);
        self::assertStringStartsWith('[redacted:', (string) $redacted['content_base64']);
    }

    #[Test]
    public function results_never_leak_credentials_or_secrets(): void
    {
        $result = $this->tools['article.list']->impl->execute([], $this->actor);
        $text = json_encode($result->content);
        self::assertStringNotContainsString('secret', strtolower((string) $text));
        self::assertStringNotContainsString('password', strtolower((string) $text));
    }
}
