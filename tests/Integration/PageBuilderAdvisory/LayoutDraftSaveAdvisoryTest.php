<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PageBuilderAdvisory;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisoryGate;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\PageBuilder\Command\ConfigureBlock;
use Waaseyaa\PageBuilder\Definition\BlockDefinition;
use Waaseyaa\PageBuilder\Definition\DefinitionRegistry;
use Waaseyaa\PageBuilder\Definition\LayoutDefinition;
use Waaseyaa\PageBuilder\Definition\TemplateDefinition;
use Waaseyaa\PageBuilder\Document\CanonicalLayoutCodec;
use Waaseyaa\PageBuilder\Document\LayoutDocument;
use Waaseyaa\PageBuilder\Draft\Exception\LayoutSaveAdvisoryException;
use Waaseyaa\PageBuilder\Draft\Exception\UnsupportedLayoutSaveAdvisoryAcknowledgementException;
use Waaseyaa\PageBuilder\Draft\LayoutDraftGatewayInterface;
use Waaseyaa\PageBuilder\Draft\LayoutDraftManager;
use Waaseyaa\PageBuilder\Draft\LayoutDraftSnapshot;
use Waaseyaa\PageBuilder\Editor\LayoutEditor;
use Waaseyaa\PageBuilder\Validation\LayoutValidator;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\ContentTypeDescriptor;
use Waaseyaa\Publishing\FieldSpec;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;
use Waaseyaa\Publishing\PageBuilder\PublishingLayoutDraftGateway;
use Waaseyaa\Publishing\Tests\Fixtures\PublisherAccount;
use Waaseyaa\Publishing\Tests\Fixtures\TestArticleEntity;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * A layout-only edit to a page that carries a save advisory on some *other*
 * field must be reviewable and retryable.
 *
 * The whole chain is real: a `BeforeSaveEvent` policy raising a candidate-bound
 * {@see SaveAdvisory}, a revisionable SQLite repository, {@see ContentPublisher},
 * {@see PublishingLayoutDraftGateway}, and {@see LayoutDraftManager}. Only the
 * editorial policy is a test double, exactly as an application would own it.
 */
#[CoversNothing]
final class LayoutDraftSaveAdvisoryTest extends TestCase
{
    private const string CAPABILITY = 'publish test articles';
    private const string LAYOUT_FIELD = 'summary';
    private const string ADVISORY_CODE = 'EDITORIAL_TITLE_REVIEW';

    /** Any title containing this marker is held for review on every save. */
    private const string REVIEWED_TITLE_MARKER = 'reserved';

    private DBALDatabase $db;
    private EntityRepository $repo;
    private ContentPublisher $publisher;
    private PublishingLayoutDraftGateway $gateway;
    private LayoutDraftManager $drafts;
    private PublisherAccount $actor;
    private CanonicalLayoutCodec $codec;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::publishing($this->db);

        $entityType = new EntityType(
            id: 'test_article',
            label: 'Test article',
            class: TestArticleEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $events = new EventDispatcher();
        $events->addListener(BeforeSaveEvent::class, static function (BeforeSaveEvent $event): void {
            $entity = $event->entity();
            $title = $entity->get('title');
            if (!is_string($title) || !str_contains($title, self::REVIEWED_TITLE_MARKER)) {
                return;
            }

            SaveAdvisoryGate::requireAcknowledged([
                SaveAdvisory::forEntityField(
                    $entity,
                    self::ADVISORY_CODE,
                    'title',
                    'This title is held for editorial review before any save.',
                ),
            ], $event->saveContext());
        });

        $resolver = new SingleConnectionResolver($this->db);
        $this->repo = V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            $events,
            new RevisionableStorageDriver($resolver, $entityType),
            $this->db,
        );

        $this->publisher = new ContentPublisher(
            new ContentTypeDescriptor(
                entityTypeId: 'test_article',
                bundle: null,
                slugField: 'slug',
                statusField: 'status',
                writableFields: [
                    'slug' => new FieldSpec(type: 'string', required: true, maxLength: 100),
                    'title' => new FieldSpec(type: 'string', required: true, maxLength: 200),
                    self::LAYOUT_FIELD => new FieldSpec(type: 'text'),
                ],
                htmlSanitizer: null,
                validators: [],
                publishCapability: self::CAPABILITY,
            ),
            $this->repo,
            new IdempotencyStore($this->db),
        );

        $this->actor = new PublisherAccount(permissions: [self::CAPABILITY]);
        $this->codec = new CanonicalLayoutCodec();
        $this->gateway = new PublishingLayoutDraftGateway($this->publisher, self::LAYOUT_FIELD);
        $this->drafts = new LayoutDraftManager(
            gateway: $this->gateway,
            codec: $this->codec,
            validator: new LayoutValidator($this->definitions()),
            editor: new LayoutEditor($this->codec, new LayoutValidator($this->definitions()), $this->definitions()),
        );
    }

    #[Test]
    public function the_exact_receipt_permits_a_layout_only_retry(): void
    {
        ['id' => $id, 'revision' => $revision, 'fingerprint' => $fingerprint] = $this->seedReviewedPage();

        $advisory = $this->captureAdvisory(
            fn(): mixed => $this->drafts->apply(
                actor: $this->actor,
                entityId: $id,
                expectedEntityRevisionId: $revision,
                expectedDocumentFingerprint: $fingerprint,
                command: new ConfigureBlock('blk_body', ['html' => '<p>Edited</p>']),
                idempotencyKey: 'layout-edit-1',
            ),
        );

        $applied = $this->drafts->apply(
            actor: $this->actor,
            entityId: $id,
            expectedEntityRevisionId: $revision,
            expectedDocumentFingerprint: $fingerprint,
            command: new ConfigureBlock('blk_body', ['html' => '<p>Edited</p>']),
            idempotencyKey: 'layout-edit-1',
            saveAdvisoryAcknowledgements: [$advisory['acknowledgement']],
        );

        self::assertSame(
            ['html' => '<p>Edited</p>'],
            $applied->document->sections()[0]['regions']['main'][0]['config'],
        );
    }

    #[Test]
    public function an_ordinary_layout_edit_keeps_its_legacy_behaviour(): void
    {
        ['id' => $id, 'revision' => $revision, 'fingerprint' => $fingerprint] = $this->seedPlainPage();

        $applied = $this->drafts->apply(
            actor: $this->actor,
            entityId: $id,
            expectedEntityRevisionId: $revision,
            expectedDocumentFingerprint: $fingerprint,
            command: new ConfigureBlock('blk_body', ['html' => '<p>Edited</p>']),
            idempotencyKey: 'plain-edit-1',
        );

        self::assertSame(
            ['html' => '<p>Edited</p>'],
            $applied->document->sections()[0]['regions']['main'][0]['config'],
        );
        self::assertSame($revision + 1, $applied->entityRevisionId);
    }

    #[Test]
    public function a_layout_only_edit_returns_a_candidate_bound_advisory_and_writes_nothing(): void
    {
        ['id' => $id, 'revision' => $revision, 'fingerprint' => $fingerprint] = $this->seedReviewedPage();
        $before = $this->storedLayout($id);

        $advisory = $this->captureAdvisory(
            fn(): mixed => $this->drafts->apply(
                actor: $this->actor,
                entityId: $id,
                expectedEntityRevisionId: $revision,
                expectedDocumentFingerprint: $fingerprint,
                command: new ConfigureBlock('blk_body', ['html' => '<p>Held</p>']),
                idempotencyKey: 'layout-edit-held',
            ),
        );

        self::assertSame(self::ADVISORY_CODE, $advisory['code']);
        self::assertSame('title', $advisory['field'], 'The advisory names the reviewed field, not the edited one.');
        self::assertSame('warning', $advisory['severity']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $advisory['acknowledgement']);
        self::assertSame($before, $this->storedLayout($id), 'A held layout edit must not write.');
    }

    #[Test]
    public function a_receipt_for_a_superseded_candidate_is_refused_and_writes_nothing(): void
    {
        ['id' => $id, 'revision' => $revision, 'fingerprint' => $fingerprint] = $this->seedReviewedPage();

        $first = $this->captureAdvisory(
            fn(): mixed => $this->drafts->apply(
                actor: $this->actor,
                entityId: $id,
                expectedEntityRevisionId: $revision,
                expectedDocumentFingerprint: $fingerprint,
                command: new ConfigureBlock('blk_body', ['html' => '<p>One</p>']),
                idempotencyKey: 'layout-edit-a',
            ),
        );

        // The reviewed candidate changes underneath: a new title is a new
        // decision, so the receipt issued for the previous one is spent.
        $renamed = $this->retitle($id, self::REVIEWED_TITLE_MARKER . ' page renamed');
        $current = $this->drafts->read($this->actor, $id);
        $before = $this->storedLayout($id);

        $second = $this->captureAdvisory(
            fn(): mixed => $this->drafts->apply(
                actor: $this->actor,
                entityId: $id,
                expectedEntityRevisionId: $current->entityRevisionId,
                expectedDocumentFingerprint: $current->documentFingerprint,
                command: new ConfigureBlock('blk_body', ['html' => '<p>Two</p>']),
                idempotencyKey: 'layout-edit-b',
                saveAdvisoryAcknowledgements: [$first['acknowledgement']],
            ),
        );

        self::assertNotSame(
            $first['acknowledgement'],
            $second['acknowledgement'],
            'A changed candidate must mint a different receipt.',
        );
        self::assertSame($before, $this->storedLayout($id), 'A refused replay must not write.');
        self::assertSame($renamed, $this->storedTitle($id));

        // The freshly issued receipt does let the same edit through.
        $applied = $this->drafts->apply(
            actor: $this->actor,
            entityId: $id,
            expectedEntityRevisionId: $current->entityRevisionId,
            expectedDocumentFingerprint: $current->documentFingerprint,
            command: new ConfigureBlock('blk_body', ['html' => '<p>Two</p>']),
            idempotencyKey: 'layout-edit-b',
            saveAdvisoryAcknowledgements: [$second['acknowledgement']],
        );
        self::assertSame(
            ['html' => '<p>Two</p>'],
            $applied->document->sections()[0]['regions']['main'][0]['config'],
        );
    }

    #[Test]
    public function a_wrong_receipt_never_satisfies_the_advisory(): void
    {
        ['id' => $id, 'revision' => $revision, 'fingerprint' => $fingerprint] = $this->seedReviewedPage();
        $before = $this->storedLayout($id);

        $advisory = $this->captureAdvisory(
            fn(): mixed => $this->drafts->apply(
                actor: $this->actor,
                entityId: $id,
                expectedEntityRevisionId: $revision,
                expectedDocumentFingerprint: $fingerprint,
                command: new ConfigureBlock('blk_body', ['html' => '<p>Nope</p>']),
                idempotencyKey: 'layout-edit-wrong',
                saveAdvisoryAcknowledgements: [str_repeat('0', 64)],
            ),
        );

        self::assertSame(self::ADVISORY_CODE, $advisory['code']);
        self::assertNotSame(str_repeat('0', 64), $advisory['acknowledgement']);
        self::assertSame($before, $this->storedLayout($id));
    }

    #[Test]
    public function a_legacy_gateway_handed_receipts_refuses_before_the_publisher_is_reached(): void
    {
        ['id' => $id, 'revision' => $revision, 'fingerprint' => $fingerprint] = $this->seedPlainPage();
        $before = $this->storedLayout($id);
        $legacy = $this->managerOver(new class ($this->gateway) implements LayoutDraftGatewayInterface {
            public function __construct(private readonly PublishingLayoutDraftGateway $inner) {}

            public function read(AuthorizationPrincipalInterface $actor, string $entityId): LayoutDraftSnapshot
            {
                return $this->inner->read($actor, $entityId);
            }

            public function update(
                AuthorizationPrincipalInterface $actor,
                string $entityId,
                string $encodedLayout,
                int $expectedRevisionId,
                string $idempotencyKey,
            ): LayoutDraftSnapshot {
                return $this->inner->update($actor, $entityId, $encodedLayout, $expectedRevisionId, $idempotencyKey);
            }
        });

        $this->expectException(UnsupportedLayoutSaveAdvisoryAcknowledgementException::class);
        try {
            $legacy->apply(
                actor: $this->actor,
                entityId: $id,
                expectedEntityRevisionId: $revision,
                expectedDocumentFingerprint: $fingerprint,
                command: new ConfigureBlock('blk_body', ['html' => '<p>Refused</p>']),
                idempotencyKey: 'legacy-edit-1',
                saveAdvisoryAcknowledgements: [str_repeat('a', 64)],
            );
        } finally {
            self::assertSame($before, $this->storedLayout($id), 'The refusal must land before any write.');
        }
    }

    /** @return array{id: string, revision: int, fingerprint: string} */
    private function seedPlainPage(): array
    {
        $draft = $this->publisher->createDraft($this->actor, [
            'slug' => 'plain-page',
            'title' => 'Plain page',
            self::LAYOUT_FIELD => $this->codec->encode($this->document('<p>Before</p>')),
        ], 'seed-plain');
        $read = $this->drafts->read($this->actor, (string) $draft['id']);

        return [
            'id' => (string) $draft['id'],
            'revision' => $read->entityRevisionId,
            'fingerprint' => $read->documentFingerprint,
        ];
    }

    private function retitle(string $id, string $title): string
    {
        $current = $this->drafts->read($this->actor, $id);
        $token = null;
        try {
            $this->publisher->updateDraft($this->actor, $id, ['title' => $title], $current->entityRevisionId, 'retitle-1');
        } catch (\Waaseyaa\Publishing\Exception\ContentSaveAdvisoryException $exception) {
            $token = $exception->meta['save_advisories'][0]['acknowledgement'];
        }
        self::assertIsString($token);
        $this->publisher->updateDraft(
            $this->actor,
            $id,
            ['title' => $title],
            $current->entityRevisionId,
            'retitle-1',
            [$token],
        );

        return $title;
    }

    private function managerOver(LayoutDraftGatewayInterface $gateway): LayoutDraftManager
    {
        return new LayoutDraftManager(
            gateway: $gateway,
            codec: $this->codec,
            validator: new LayoutValidator($this->definitions()),
            editor: new LayoutEditor($this->codec, new LayoutValidator($this->definitions()), $this->definitions()),
        );
    }

    private function storedLayout(string $id): string
    {
        return (string) $this->publisher->get($this->actor, $id)[self::LAYOUT_FIELD];
    }

    private function storedTitle(string $id): string
    {
        return (string) $this->publisher->get($this->actor, $id)['title'];
    }

    /** @return array{id: string, revision: int, fingerprint: string} */
    private function seedReviewedPage(): array
    {
        // A page whose title is under standing editorial review, persisted once
        // through the ordinary acknowledgement path. This is the migrated
        // collision shape: already stored, still reviewed on every later save.
        $values = [
            'slug' => 'reviewed-page',
            'title' => self::REVIEWED_TITLE_MARKER . ' page',
            self::LAYOUT_FIELD => $this->codec->encode($this->document('<p>Before</p>')),
        ];

        $token = null;
        try {
            $this->publisher->createDraft($this->actor, $values, 'seed-1');
        } catch (\Waaseyaa\Publishing\Exception\ContentSaveAdvisoryException $exception) {
            $token = $exception->meta['save_advisories'][0]['acknowledgement'];
        }
        self::assertIsString($token, 'Seeding a reviewed page must itself request review.');

        $draft = $this->publisher->createDraft($this->actor, $values, 'seed-1', [$token]);
        $read = $this->drafts->read($this->actor, (string) $draft['id']);

        return [
            'id' => (string) $draft['id'],
            'revision' => $read->entityRevisionId,
            'fingerprint' => $read->documentFingerprint,
        ];
    }

    /**
     * @param \Closure(): mixed $call
     * @return array<string, mixed>
     */
    private function captureAdvisory(\Closure $call): array
    {
        try {
            $call();
        } catch (LayoutSaveAdvisoryException $exception) {
            self::assertSame('SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED', LayoutSaveAdvisoryException::ERROR_CODE);

            return $exception->advisoryPayloads()[0];
        }

        self::fail('Expected the layout edit to be held for review.');
    }

    private function definitions(): DefinitionRegistry
    {
        $registry = new DefinitionRegistry();
        $registry->registerBlock(new BlockDefinition(
            id: 'rich_text',
            version: 1,
            label: 'Rich text',
            renderer: 'content.rich_text',
            configSchema: [
                'type' => 'object',
                'required' => ['html'],
                'additionalProperties' => false,
                'properties' => ['html' => ['type' => 'string']],
            ],
        ));
        $registry->registerLayout(new LayoutDefinition('one_column', 1, ['main'], ['main'], ['rich_text']));
        $registry->registerTemplate(new TemplateDefinition('standard', 1, ['one_column'], ['rich_text']));

        return $registry;
    }

    private function document(string $html): LayoutDocument
    {
        return LayoutDocument::fromArray([
            'schema' => 'waaseyaa.layout',
            'version' => 1,
            'template' => ['id' => 'standard', 'version' => 1],
            'sections' => [[
                'id' => 'sec_body',
                'layout' => ['id' => 'one_column', 'version' => 1],
                'regions' => ['main' => [[
                    'id' => 'blk_body',
                    'type' => 'rich_text',
                    'version' => 1,
                    'config' => ['html' => $html],
                ]]],
            ]],
        ]);
    }
}
