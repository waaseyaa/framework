<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisory;
use Waaseyaa\EntityStorage\Advisory\SaveAdvisoryGate;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Publishing\ContentMutationSnapshotReader;
use Waaseyaa\Publishing\ContentPublicationTransitionerInterface;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\ContentTypeDescriptor;
use Waaseyaa\Publishing\ContentValidatorInterface;
use Waaseyaa\Publishing\Exception\ContentAuthorizationException;
use Waaseyaa\Publishing\Exception\ContentNotFoundException;
use Waaseyaa\Publishing\Exception\ContentSaveAdvisoryException;
use Waaseyaa\Publishing\Exception\ContentValidationException;
use Waaseyaa\Publishing\Exception\IdempotencyConflictException;
use Waaseyaa\Publishing\Exception\SlugConflictException;
use Waaseyaa\Publishing\FieldSpec;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;
use Waaseyaa\Publishing\Preview\PreviewLinkService;
use Waaseyaa\Publishing\Tests\Fixtures\PublisherAccount;
use Waaseyaa\Publishing\Tests\Fixtures\SpyAuditWriter;
use Waaseyaa\Publishing\Tests\Fixtures\TestArticleEntity;
use Waaseyaa\Publishing\ValidationErrors;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

#[CoversClass(ContentPublisher::class)]
#[CoversClass(ContentMutationSnapshotReader::class)]
#[CoversClass(ContentTypeDescriptor::class)]
final class ContentPublisherTest extends TestCase
{
    private const string CAPABILITY = 'publish test articles';

    private EntityRepository $repo;
    private DBALDatabase $db;
    private SpyAuditWriter $audit;
    private ContentPublisher $publisher;
    private PublisherAccount $actor;
    private EventDispatcher $events;
    private ?EntityValueReadGuardInterface $priorGuard;

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
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();
        $resolver = new SingleConnectionResolver($db);
        $this->events = new EventDispatcher();
        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            $this->events,
            new RevisionableStorageDriver($resolver, $entityType),
            $db,
        );

        $this->audit = new SpyAuditWriter();
        $this->actor = new PublisherAccount(permissions: [self::CAPABILITY]);
        $this->publisher = new ContentPublisher(
            $this->descriptor(),
            $this->repo,
            new IdempotencyStore($db),
            $this->audit,
        );
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard($this->priorGuard);
    }

    private function descriptor(): ContentTypeDescriptor
    {
        $noDigitsInTitle = new class implements ContentValidatorInterface {
            public function validate(array $values, ValidationErrors $errors): void
            {
                $title = (string) ($values['title'] ?? '');
                if (preg_match('/\d/', $title) === 1) {
                    $errors->add('title', 'Digits are not allowed in the title (test editorial rule).');
                }
            }
        };

        return new ContentTypeDescriptor(
            entityTypeId: 'test_article',
            bundle: null,
            slugField: 'slug',
            statusField: 'status',
            writableFields: [
                'slug' => new FieldSpec(type: 'string', required: true, maxLength: 100),
                'title' => new FieldSpec(type: 'string', required: true, maxLength: 200),
                'summary' => new FieldSpec(type: 'text'),
                'body_html' => new FieldSpec(type: 'text', html: true),
                'promote' => new FieldSpec(type: 'bool'),
            ],
            htmlSanitizer: new \Waaseyaa\Publishing\Tests\Fixtures\SymfonyTestSanitizer(['p', 'strong']),
            validators: [$noDigitsInTitle],
            publishCapability: self::CAPABILITY,
        );
    }

    /** @return array<string, mixed> */
    private function draftValues(array $overrides = []): array
    {
        return $overrides + ['slug' => 'first-post', 'title' => 'First post', 'summary' => 'A summary.'];
    }

    private function authoredDescriptor(): ContentTypeDescriptor
    {
        $descriptor = $this->descriptor();

        return new ContentTypeDescriptor(
            entityTypeId: $descriptor->entityTypeId,
            bundle: $descriptor->bundle,
            slugField: $descriptor->slugField,
            statusField: $descriptor->statusField,
            writableFields: $descriptor->writableFields,
            htmlSanitizer: $descriptor->htmlSanitizer,
            validators: $descriptor->validators,
            publishCapability: $descriptor->publishCapability,
            authorField: 'author_id',
        );
    }

    private function ownerAccessHandler(): EntityAccessHandler
    {
        return new EntityAccessHandler([
            new class implements AccessPolicyInterface {
                public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
                {
                    $owner = $entity->get('author_id');

                    return $owner !== null && (string) $owner === (string) $account->id()
                        ? AccessResult::allowed('The principal owns this authored fixture.')
                        : AccessResult::neutral('The principal does not own this authored fixture.');
                }

                public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
                {
                    return AccessResult::allowed('The publishing capability is independently enforced.');
                }

                public function appliesTo(string $entityTypeId): bool
                {
                    return $entityTypeId === 'test_article';
                }
            },
        ]);
    }

    // --- authorization ---

    #[Test]
    public function dates_nullable_clears_and_bounded_reference_lists_are_validated_and_normalized(): void
    {
        $coerce = new \ReflectionMethod(ContentPublisher::class, 'coerce');

        $errors = new ValidationErrors();
        self::assertSame('2028-02-29', $coerce->invoke($this->publisher, 'publish_on', '2028-02-29', new FieldSpec('date'), $errors));
        self::assertSame([12, 'uuid-2'], $coerce->invoke($this->publisher, 'related', ['12', 'uuid-2'], new FieldSpec('reference_list', maxItems: 3), $errors));
        self::assertNull($coerce->invoke($this->publisher, 'publish_on', null, new FieldSpec('date', nullable: true), $errors));
        self::assertTrue($errors->isEmpty());

        foreach ([
            ['publish_on', '2027-02-29', new FieldSpec('date')],
            ['related', [1, 1], new FieldSpec('reference_list')],
            ['related', [1, 2, 3, 4], new FieldSpec('reference_list', maxItems: 3)],
            ['related', [''], new FieldSpec('reference_list')],
            ['publish_on', null, new FieldSpec('date')],
        ] as [$field, $value, $spec]) {
            $invalid = new ValidationErrors();
            self::assertNull($coerce->invoke($this->publisher, $field, $value, $spec, $invalid));
            self::assertFalse($invalid->isEmpty());
        }
    }

    #[Test]
    public function every_operation_requires_the_publish_capability(): void
    {
        $noCapability = new PublisherAccount(permissions: []);

        $this->expectException(ContentAuthorizationException::class);
        $this->publisher->createDraft($noCapability, $this->draftValues(), 'k1');
    }

    #[Test]
    public function reads_also_require_the_capability(): void
    {
        $this->expectException(ContentAuthorizationException::class);
        $this->publisher->list(new PublisherAccount(permissions: []));
    }

    // --- drafts ---

    #[Test]
    public function authored_draft_persists_entity_and_revision_owner_and_remains_readable_only_by_its_creator(): void
    {
        $publisher = new ContentPublisher(
            $this->authoredDescriptor(),
            $this->repo,
            new IdempotencyStore($this->db),
            $this->audit,
            $this->ownerAccessHandler(),
        );
        $draft = $publisher->createDraft($this->actor, $this->draftValues(), 'authored-draft');

        $stored = $this->repo->find((string) $draft['id']);
        self::assertNotNull($stored);
        self::assertSame($this->actor->id(), $stored->get('author_id'));
        self::assertSame($draft['id'], $publisher->get($this->actor, (string) $draft['id'])['id']);
        self::assertCount(1, $publisher->list($this->actor));

        $revisions = $publisher->revisions($this->actor, (string) $draft['id']);
        self::assertCount(1, $revisions);
        self::assertSame($this->actor->id(), $revisions[0]['author_uid']);

        $links = new PreviewLinkService('authored-preview-secret', fn(): int => 1_000_000);
        self::assertSame($draft['id'], $publisher->preview($this->actor, (string) $draft['id'], $links)['id']);
        self::assertSame(
            $draft['revision_id'],
            $publisher->previewRevision(
                $this->actor,
                (string) $draft['id'],
                $draft['revision_id'],
                $links,
            )['revision_id'],
        );

        $other = new PublisherAccount(uid: 900002, permissions: [self::CAPABILITY]);
        $this->expectException(ContentNotFoundException::class);
        $publisher->get($other, (string) $draft['id']);
    }

    #[Test]
    public function authored_draft_refuses_an_opaque_actor_before_writing(): void
    {
        $publisher = new ContentPublisher(
            $this->authoredDescriptor(),
            $this->repo,
            new IdempotencyStore($this->db),
            $this->audit,
            $this->ownerAccessHandler(),
        );
        $opaque = new PublisherAccount(uid: 'agent:opaque', permissions: [self::CAPABILITY]);

        try {
            $publisher->createDraft($opaque, $this->draftValues(), 'opaque-actor');
            self::fail('An authored draft accepted an opaque actor identity.');
        } catch (ContentAuthorizationException $exception) {
            self::assertSame('UNAUTHORIZED', $exception->errorCode);
            self::assertSame([], $this->repo->findBy([]));
        }
    }

    #[Test]
    public function authored_draft_normalizes_a_positive_numeric_string_actor_id(): void
    {
        $publisher = new ContentPublisher(
            $this->authoredDescriptor(),
            $this->repo,
            new IdempotencyStore($this->db),
            $this->audit,
            $this->ownerAccessHandler(),
        );
        $actor = new PublisherAccount(uid: '900003', permissions: [self::CAPABILITY]);

        $draft = $publisher->createDraft($actor, $this->draftValues(), 'numeric-string-actor');

        $stored = $this->repo->find((string) $draft['id']);
        self::assertNotNull($stored);
        self::assertSame(900003, $stored->get('author_id'));
    }

    #[Test]
    public function authored_draft_refuses_an_anonymous_numeric_actor_before_writing(): void
    {
        $publisher = new ContentPublisher(
            $this->authoredDescriptor(),
            $this->repo,
            new IdempotencyStore($this->db),
            $this->audit,
            $this->ownerAccessHandler(),
        );
        $anonymous = new PublisherAccount(
            uid: 900001,
            permissions: [self::CAPABILITY],
            authenticated: false,
        );

        try {
            $publisher->createDraft($anonymous, $this->draftValues(), 'anonymous-actor');
            self::fail('An authored draft accepted an anonymous actor identity.');
        } catch (ContentAuthorizationException $exception) {
            self::assertSame('UNAUTHORIZED', $exception->errorCode);
            self::assertSame([], $this->repo->findBy([]));
        }
    }

    #[Test]
    public function authored_draft_idempotency_is_scoped_to_the_server_owned_author(): void
    {
        $publisher = new ContentPublisher(
            $this->authoredDescriptor(),
            $this->repo,
            new IdempotencyStore($this->db),
            $this->audit,
            $this->ownerAccessHandler(),
        );
        $mine = $publisher->createDraft($this->actor, $this->draftValues(), 'author-scoped-key');

        // Since #2555 the replay record is partitioned by acting principal, so
        // a second author under the same key executes its own mutation instead
        // of conflicting with (or replaying) the first author's record.
        $other = new PublisherAccount(uid: 900002, permissions: [self::CAPABILITY]);
        $theirs = $publisher->createDraft($other, $this->draftValues(['slug' => 'their-post']), 'author-scoped-key');

        self::assertNotSame($mine['id'], $theirs['id']);
        self::assertSame($mine, $publisher->createDraft($this->actor, $this->draftValues(), 'author-scoped-key'));
    }

    #[Test]
    public function descriptor_keeps_the_author_field_server_owned(): void
    {
        $descriptor = $this->descriptor();

        $this->expectException(\InvalidArgumentException::class);
        new ContentTypeDescriptor(
            entityTypeId: $descriptor->entityTypeId,
            bundle: $descriptor->bundle,
            slugField: $descriptor->slugField,
            statusField: $descriptor->statusField,
            writableFields: $descriptor->writableFields + ['author_id' => new FieldSpec(type: 'int')],
            htmlSanitizer: $descriptor->htmlSanitizer,
            validators: $descriptor->validators,
            publishCapability: $descriptor->publishCapability,
            authorField: 'author_id',
        );
    }

    #[Test]
    public function descriptor_rejects_an_empty_author_field(): void
    {
        $descriptor = $this->descriptor();

        $this->expectException(\InvalidArgumentException::class);
        new ContentTypeDescriptor(
            entityTypeId: $descriptor->entityTypeId,
            bundle: $descriptor->bundle,
            slugField: $descriptor->slugField,
            statusField: $descriptor->statusField,
            writableFields: $descriptor->writableFields,
            htmlSanitizer: $descriptor->htmlSanitizer,
            validators: $descriptor->validators,
            publishCapability: $descriptor->publishCapability,
            authorField: ' ',
        );
    }

    #[Test]
    public function descriptor_keeps_the_author_field_distinct_from_status(): void
    {
        $descriptor = $this->descriptor();

        $this->expectException(\InvalidArgumentException::class);
        new ContentTypeDescriptor(
            entityTypeId: $descriptor->entityTypeId,
            bundle: $descriptor->bundle,
            slugField: $descriptor->slugField,
            statusField: $descriptor->statusField,
            writableFields: $descriptor->writableFields,
            htmlSanitizer: $descriptor->htmlSanitizer,
            validators: $descriptor->validators,
            publishCapability: $descriptor->publishCapability,
            authorField: $descriptor->statusField,
        );
    }

    #[Test]
    public function draft_create_returns_structured_advisories_and_binds_them_into_idempotency(): void
    {
        $this->requireTitleAdvisory();

        try {
            $this->publisher->createDraft($this->actor, $this->draftValues(), 'advisory-create');
            self::fail('An unacknowledged draft was saved.');
        } catch (ContentSaveAdvisoryException $exception) {
            self::assertSame('SAVE_ADVISORY_ACKNOWLEDGEMENT_REQUIRED', $exception->errorCode);
            self::assertCount(1, $exception->meta['save_advisories']);
            $advisory = $exception->meta['save_advisories'][0];
            self::assertSame('EDITORIAL_TITLE_REVIEW', $advisory['code']);
            self::assertSame('title', $advisory['field']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $advisory['acknowledgement']);
        }
        self::assertSame(0, $this->repo->count());

        $draft = $this->publisher->createDraft(
            $this->actor,
            $this->draftValues(),
            'advisory-create',
            [$advisory['acknowledgement']],
        );
        self::assertSame('First post', $draft['title']);

        $this->expectException(IdempotencyConflictException::class);
        $this->publisher->createDraft($this->actor, $this->draftValues(), 'advisory-create');
    }

    #[Test]
    public function draft_update_preserves_actor_and_revision_context_when_acknowledged(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'advisory-seed');
        $this->requireTitleAdvisory();

        try {
            $this->publisher->updateDraft(
                $this->actor,
                (string) $draft['id'],
                ['title' => 'Reviewed title'],
                $draft['revision_id'],
                'advisory-update',
            );
            self::fail('An unacknowledged update was saved.');
        } catch (ContentSaveAdvisoryException $exception) {
            $advisory = $exception->meta['save_advisories'][0];
        }
        self::assertCount(1, $this->publisher->revisions($this->actor, (string) $draft['id']));

        $updated = $this->publisher->updateDraft(
            $this->actor,
            (string) $draft['id'],
            ['title' => 'Reviewed title'],
            $draft['revision_id'],
            'advisory-update',
            [$advisory['acknowledgement']],
        );

        self::assertSame('Reviewed title', $updated['title']);
        self::assertGreaterThan($draft['revision_id'], $updated['revision_id']);
        self::assertSame($this->actor->id(), $this->publisher->revisions($this->actor, (string) $draft['id'])[0]['author_uid']);
    }

    #[Test]
    public function create_draft_is_never_public_and_returns_the_revision_token(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');

        self::assertFalse($draft['status']);
        self::assertSame('first-post', $draft['slug']);
        self::assertIsInt($draft['revision_id']);
        $stored = $this->repo->find((string) $draft['id']);
        self::assertNotNull($stored);
    }

    #[Test]
    public function slugs_are_unique(): void
    {
        $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');

        $this->expectException(SlugConflictException::class);
        $this->publisher->createDraft($this->actor, $this->draftValues(['title' => 'Other']), 'k2');
    }

    #[Test]
    public function validation_errors_are_field_specific_and_collected(): void
    {
        try {
            $this->publisher->createDraft($this->actor, [
                'slug' => 'ok-slug',
                'title' => 'Title with digit 7',
                'status' => true,
                'unknown_field' => 'x',
            ], 'k1');
            self::fail('Expected ContentValidationException.');
        } catch (ContentValidationException $e) {
            $fields = array_column($e->fieldErrors, 'field');
            self::assertContains('title', $fields);        // app validator
            self::assertContains('status', $fields);       // status not writable
            self::assertContains('unknown_field', $fields); // outside the schema
        }
    }

    #[Test]
    public function html_fields_are_sanitized_against_the_explicit_allowlist_before_persistence(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues([
            'body_html' => '<p>Keep <strong>this</strong></p><script>alert(1)</script><em>drop-tag</em>',
        ]), 'k1');

        $stored = $this->repo->find((string) $draft['id']);
        $body = (string) $stored?->get('body_html');
        self::assertStringContainsString('<p>Keep <strong>this</strong></p>', $body);
        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('alert(1)', $body);
        self::assertStringNotContainsString('<em>', $body);
    }

    // --- idempotency ---

    #[Test]
    public function replaying_the_same_idempotency_key_and_payload_does_not_execute_twice(): void
    {
        $first = $this->publisher->createDraft($this->actor, $this->draftValues(), 'same-key');
        $replay = $this->publisher->createDraft($this->actor, $this->draftValues(), 'same-key');

        self::assertSame($first, $replay);
        self::assertCount(1, $this->repo->findBy(['slug' => 'first-post']));
    }

    #[Test]
    public function idempotency_keys_are_namespaced_by_content_descriptor(): void
    {
        $first = $this->publisher->createDraft($this->actor, $this->draftValues(), 'shared-client-key');
        $descriptor = $this->descriptor();
        $otherPublisher = new ContentPublisher(
            new ContentTypeDescriptor(
                entityTypeId: 'other_article',
                bundle: null,
                slugField: $descriptor->slugField,
                statusField: $descriptor->statusField,
                writableFields: $descriptor->writableFields,
                htmlSanitizer: $descriptor->htmlSanitizer,
                validators: $descriptor->validators,
                publishCapability: $descriptor->publishCapability,
            ),
            $this->repo,
            new IdempotencyStore($this->db),
            $this->audit,
        );

        $second = $otherPublisher->createDraft($this->actor, $this->draftValues(['slug' => 'other-post']), 'shared-client-key');
        self::assertNotSame($first['id'], $second['id']);
    }

    #[Test]
    public function the_same_idempotency_key_with_a_different_payload_conflicts(): void
    {
        $this->publisher->createDraft($this->actor, $this->draftValues(), 'same-key');

        $this->expectException(IdempotencyConflictException::class);
        $this->publisher->createDraft($this->actor, $this->draftValues(['slug' => 'other-slug']), 'same-key');
    }

    #[Test]
    public function replaying_publish_with_the_same_note_does_not_execute_twice(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'draft-key');
        $first = $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'publish-key', 'Go live');
        $replay = $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'publish-key', 'Go live');

        self::assertSame($first, $replay);
        self::assertCount(2, $this->publisher->revisions($this->actor, (string) $draft['id']));
    }

    #[Test]
    public function reusing_publish_key_with_a_different_note_conflicts(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'draft-key');
        $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'publish-key', 'First note');

        $this->expectException(IdempotencyConflictException::class);
        $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'publish-key', 'Changed note');
    }

    #[Test]
    public function replaying_unpublish_with_the_same_note_does_not_execute_twice(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'draft-key');
        $published = $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'publish-key');
        $first = $this->publisher->unpublish($this->actor, (string) $draft['id'], $published['revision_id'], 'unpublish-key', 'Take down');
        $replay = $this->publisher->unpublish($this->actor, (string) $draft['id'], $published['revision_id'], 'unpublish-key', 'Take down');

        self::assertSame($first, $replay);
        self::assertCount(3, $this->publisher->revisions($this->actor, (string) $draft['id']));
    }

    #[Test]
    public function reusing_unpublish_key_with_a_different_note_conflicts(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'draft-key');
        $published = $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'publish-key');
        $this->publisher->unpublish($this->actor, (string) $draft['id'], $published['revision_id'], 'unpublish-key', 'First note');

        $this->expectException(IdempotencyConflictException::class);
        $this->publisher->unpublish($this->actor, (string) $draft['id'], $published['revision_id'], 'unpublish-key', 'Changed note');
    }

    #[Test]
    public function replaying_rollback_with_the_same_note_does_not_execute_twice(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(['title' => 'Original']), 'draft-key');
        $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['title' => 'Edited'], $draft['revision_id'], 'update-key');
        $first = $this->publisher->rollback($this->actor, (string) $draft['id'], $draft['revision_id'], 'rollback-key', 'Restore original');
        $replay = $this->publisher->rollback($this->actor, (string) $draft['id'], $draft['revision_id'], 'rollback-key', 'Restore original');

        self::assertSame($first, $replay);
        self::assertCount(3, $this->publisher->revisions($this->actor, (string) $draft['id']));
    }

    #[Test]
    public function reusing_rollback_key_with_a_different_note_conflicts(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(['title' => 'Original']), 'draft-key');
        $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['title' => 'Edited'], $draft['revision_id'], 'update-key');
        $this->publisher->rollback($this->actor, (string) $draft['id'], $draft['revision_id'], 'rollback-key', 'First note');

        $this->expectException(IdempotencyConflictException::class);
        $this->publisher->rollback($this->actor, (string) $draft['id'], $draft['revision_id'], 'rollback-key', 'Changed note');
    }

    // --- idempotency is partitioned by acting principal (#2555) ---

    #[Test]
    public function two_principals_sharing_a_key_and_payload_do_not_replay_each_others_response(): void
    {
        $first = $this->publisher->createDraft($this->actor, $this->draftValues(), 'shared-actor-key');

        $other = new PublisherAccount(uid: 900002, permissions: [self::CAPABILITY]);
        try {
            $this->publisher->createDraft($other, $this->draftValues(), 'shared-actor-key');
            self::fail('The second principal did not execute its own mutation.');
        } catch (SlugConflictException $exception) {
            // The slug clash proves the closure RAN for the second principal
            // rather than replaying the first principal's stored response.
            self::assertSame('SLUG_TAKEN', $exception->errorCode);
        }

        self::assertCount(1, $this->repo->findBy(['slug' => 'first-post']));
        self::assertSame($first, $this->publisher->createDraft($this->actor, $this->draftValues(), 'shared-actor-key'));
    }

    #[Test]
    public function each_principal_receives_its_own_response_under_a_shared_key(): void
    {
        $other = new PublisherAccount(uid: 900002, permissions: [self::CAPABILITY]);

        $mine = $this->publisher->createDraft($this->actor, $this->draftValues(), 'shared-actor-key');
        $theirs = $this->publisher->createDraft($other, $this->draftValues(['slug' => 'their-post']), 'shared-actor-key');

        self::assertNotSame($mine['id'], $theirs['id']);
        self::assertSame('first-post', $mine['slug']);
        self::assertSame('their-post', $theirs['slug']);

        // Each principal still replays its OWN record under that shared key.
        self::assertSame($mine, $this->publisher->createDraft($this->actor, $this->draftValues(), 'shared-actor-key'));
        self::assertSame($theirs, $this->publisher->createDraft($other, $this->draftValues(['slug' => 'their-post']), 'shared-actor-key'));
    }

    #[Test]
    public function a_second_principal_reusing_a_publish_key_executes_rather_than_replaying(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'draft-key');
        $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'publish-key', 'Go live');

        $other = new PublisherAccount(uid: 900002, permissions: [self::CAPABILITY]);

        // Identical key, identical payload, different principal: the mutation
        // executes and fails loudly on the now-stale revision instead of
        // silently handing back the first principal's response.
        $this->expectException(RevisionConflictException::class);
        $this->publisher->publish($other, (string) $draft['id'], $draft['revision_id'], 'publish-key', 'Go live');
    }

    #[Test]
    public function a_second_principal_reusing_a_rollback_key_executes_rather_than_replaying(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(['title' => 'Original']), 'draft-key');
        $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['title' => 'Edited'], $draft['revision_id'], 'update-key');
        $first = $this->publisher->rollback($this->actor, (string) $draft['id'], $draft['revision_id'], 'rollback-key', 'Restore original');

        $other = new PublisherAccount(uid: 900002, permissions: [self::CAPABILITY]);
        $second = $this->publisher->rollback($other, (string) $draft['id'], $draft['revision_id'], 'rollback-key', 'Restore original');

        // A replay would have returned the first response verbatim; a real
        // execution cuts a fourth revision and reports the new revision id.
        self::assertNotSame($first['revision_id'], $second['revision_id']);
        self::assertCount(4, $this->publisher->revisions($this->actor, (string) $draft['id']));
    }

    #[Test]
    public function principal_partitioning_treats_an_integer_and_its_digit_string_as_one_principal(): void
    {
        $numeric = new PublisherAccount(uid: 900003, permissions: [self::CAPABILITY]);
        $textual = new PublisherAccount(uid: '900003', permissions: [self::CAPABILITY]);

        $first = $this->publisher->createDraft($numeric, $this->draftValues(), 'canonical-id-key');
        $replay = $this->publisher->createDraft($textual, $this->draftValues(), 'canonical-id-key');

        self::assertSame($first, $replay);
        self::assertCount(1, $this->repo->findBy(['slug' => 'first-post']));
    }

    // --- optimistic concurrency ---

    #[Test]
    public function update_with_a_stale_revision_id_conflicts(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['summary' => 'v2'], $draft['revision_id'], 'k2');

        $this->expectException(RevisionConflictException::class);
        $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['summary' => 'v3-stale'], $draft['revision_id'], 'k3');
    }

    #[Test]
    public function rollback_with_a_stale_current_revision_refuses_before_copy_forward(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(['title' => 'Original']), 'k1');
        $updated = $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['title' => 'Edited'], $draft['revision_id'], 'k2');
        $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['title' => 'Newest'], $updated['revision_id'], 'k3');

        try {
            $this->publisher->rollback(
                $this->actor,
                (string) $draft['id'],
                $draft['revision_id'],
                'stale-restore',
                expectedCurrentRevisionId: $updated['revision_id'],
            );
            self::fail('A stale page-builder restore was accepted.');
        } catch (RevisionConflictException $exception) {
            self::assertSame($updated['revision_id'], $exception->expectedRevisionId);
            self::assertCount(3, $this->publisher->revisions($this->actor, (string) $draft['id']));
        }
    }

    #[Test]
    public function exact_historical_revision_read_preserves_content_and_history_flags(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(['title' => 'Original']), 'k1');
        $updated = $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['title' => 'Edited'], $draft['revision_id'], 'k2');

        $historical = $this->publisher->revision($this->actor, (string) $draft['id'], $draft['revision_id']);
        $current = $this->publisher->revision($this->actor, (string) $draft['id'], $updated['revision_id']);

        self::assertSame('Original', $historical['title']);
        self::assertFalse($historical['is_current']);
        self::assertSame('Edited', $current['title']);
        self::assertTrue($current['is_current']);
        self::assertTrue($current['is_latest']);
    }

    #[Test]
    public function update_creates_a_new_revision_and_returns_the_new_token(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $updated = $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['summary' => 'v2'], $draft['revision_id'], 'k2');

        self::assertGreaterThan($draft['revision_id'], $updated['revision_id']);
        self::assertSame('v2', $updated['summary']);
    }

    #[Test]
    public function preview_revision_issues_a_grant_for_only_the_observed_working_copy(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $links = new PreviewLinkService('preview-secret', fn(): int => 1_000_000);

        $grant = $this->publisher->previewRevision(
            $this->actor,
            (string) $draft['id'],
            $draft['revision_id'],
            $links,
            600,
        );

        self::assertSame($draft['revision_id'], $grant['revision_id']);
        self::assertTrue($links->verifyRevision(
            'test_article',
            (string) $draft['id'],
            $draft['revision_id'],
            $grant['expires_at'],
            $grant['signature'],
        ));

        $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['summary' => 'newer'], $draft['revision_id'], 'k2');

        $this->expectException(RevisionConflictException::class);
        $this->publisher->previewRevision($this->actor, (string) $draft['id'], $draft['revision_id'], $links, 600);
    }

    // --- publish / unpublish ---

    #[Test]
    public function publish_sets_status_stamps_the_note_and_audits(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $published = $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'k2', 'Go live');

        self::assertTrue($published['status']);
        $stored = $this->repo->find((string) $draft['id']);
        self::assertNotNull($stored);
        self::assertContains('content.published', $this->audit->kinds());

        $revisions = $this->publisher->revisions($this->actor, (string) $draft['id']);
        self::assertSame('Go live', $revisions[0]['log']);
    }

    #[Test]
    public function ordinary_draft_save_after_publish_does_not_replace_the_public_projection(): void
    {
        $draft = $this->publisher->createDraft(
            $this->actor,
            $this->draftValues(['title' => 'Third title']),
            'k1',
        );
        $published = $this->publisher->publish(
            $this->actor,
            (string) $draft['id'],
            $draft['revision_id'],
            'k2',
            'Go live',
        );
        $id = (string) $published['id'];
        $publishedRevisionId = $published['revision_id'];
        self::assertNotNull(
            $this->repo->loadPublishedRevision($id),
            'publish() must pin published_revision_id so later draft saves can stay revision-only.',
        );

        $updated = $this->publisher->updateDraft(
            $this->actor,
            $id,
            ['title' => 'UNPUBLISHED DRAFT EDIT'],
            $publishedRevisionId,
            'k3',
        );

        $served = $this->repo->find($id);
        $publishedRevision = $this->repo->loadPublishedRevision($id);
        $workingCopy = $this->repo->loadWorkingCopy($id);
        $baseRow = $this->db->getConnection()->fetchAssociative(
            'SELECT revision_id, published_revision_id FROM test_article WHERE id = ?',
            [$id],
        );

        self::assertNotNull($served);
        self::assertNotNull($publishedRevision);
        self::assertNotNull($workingCopy);
        self::assertIsArray($baseRow);
        self::assertSame('Third title', $served->get('title'));
        self::assertSame('Third title', $publishedRevision->get('title'));
        self::assertSame('UNPUBLISHED DRAFT EDIT', $workingCopy->get('title'));
        self::assertSame('UNPUBLISHED DRAFT EDIT', $updated['title']);
        self::assertSame($publishedRevisionId, (int) $baseRow['revision_id']);
        self::assertSame($publishedRevisionId, (int) $baseRow['published_revision_id']);
        self::assertGreaterThan($publishedRevisionId, $updated['revision_id']);
        self::assertNotSame(
            $publishedRevisionId,
            (int) $workingCopy->get('revision_id'),
            'The draft must be a new working revision, not an in-place rewrite of the published one.',
        );

        $hydratedPublic = $this->repo->findMany([$id]);
        self::assertCount(1, $hydratedPublic);
        self::assertSame('Third title', $hydratedPublic[0]->get('title'));
        $listed = $this->publisher->list($this->actor, publishedOnly: true);
        self::assertCount(1, $listed);
        self::assertSame('Third title', $listed[0]['title']);
    }

    #[Test]
    public function explicit_publish_after_a_forward_draft_moves_the_served_projection(): void
    {
        $draft = $this->publisher->createDraft(
            $this->actor,
            $this->draftValues(['title' => 'Third title']),
            'k1',
        );
        $published = $this->publisher->publish(
            $this->actor,
            (string) $draft['id'],
            $draft['revision_id'],
            'k2',
        );
        $id = (string) $published['id'];
        $updated = $this->publisher->updateDraft(
            $this->actor,
            $id,
            ['title' => 'UNPUBLISHED DRAFT EDIT'],
            $published['revision_id'],
            'k3',
        );

        $republished = $this->publisher->publish(
            $this->actor,
            $id,
            $updated['revision_id'],
            'k4',
            'Go live again',
        );

        self::assertTrue($republished['status']);
        self::assertSame('UNPUBLISHED DRAFT EDIT', $this->repo->find($id)?->get('title'));
        self::assertSame('UNPUBLISHED DRAFT EDIT', $this->repo->loadPublishedRevision($id)?->get('title'));
        self::assertSame(
            (int) $republished['revision_id'],
            (int) $this->repo->find($id)?->get('revision_id'),
        );
        $listed = $this->publisher->list($this->actor, publishedOnly: true);
        self::assertCount(1, $listed);
        self::assertSame('UNPUBLISHED DRAFT EDIT', $listed[0]['title']);
    }

    #[Test]
    public function pointered_undisciplined_repository_save_still_writes_the_base_row(): void
    {
        $draft = $this->publisher->createDraft(
            $this->actor,
            $this->draftValues(['slug' => 'playbook-h-post', 'title' => 'Live title']),
            'k1',
        );
        $published = $this->publisher->publish(
            $this->actor,
            (string) $draft['id'],
            $draft['revision_id'],
            'k2',
        );
        $id = (string) $published['id'];
        $publishedRevisionId = (int) $published['revision_id'];

        $entity = $this->repo->find($id);
        self::assertNotNull($entity);
        $entity->set('title', 'Playbook overwrite');
        $this->repo->save($entity);

        $baseRow = $this->db->getConnection()->fetchAssociative(
            'SELECT revision_id, published_revision_id FROM test_article WHERE id = ?',
            [$id],
        );
        self::assertIsArray($baseRow);
        self::assertSame('Playbook overwrite', $this->repo->find($id)?->get('title'));
        self::assertSame($publishedRevisionId, (int) $baseRow['published_revision_id']);
        self::assertGreaterThan($publishedRevisionId, (int) $baseRow['revision_id']);
    }

    #[Test]
    public function never_published_draft_saves_still_update_the_base_row(): void
    {
        $draft = $this->publisher->createDraft(
            $this->actor,
            $this->draftValues(['title' => 'Only draft']),
            'k1',
        );
        $id = (string) $draft['id'];

        $this->publisher->updateDraft(
            $this->actor,
            $id,
            ['title' => 'Still a draft'],
            $draft['revision_id'],
            'k2',
        );

        self::assertNull($this->repo->loadPublishedRevision($id));
        self::assertSame('Still a draft', $this->repo->find($id)?->get('title'));
        self::assertSame('Still a draft', $this->repo->loadWorkingCopy($id)?->get('title'));
    }

    #[Test]
    public function stale_expected_revision_still_conflicts_after_publish(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $published = $this->publisher->publish(
            $this->actor,
            (string) $draft['id'],
            $draft['revision_id'],
            'k2',
        );
        $this->publisher->updateDraft(
            $this->actor,
            (string) $published['id'],
            ['title' => 'Forward draft'],
            $published['revision_id'],
            'k3',
        );

        $this->expectException(RevisionConflictException::class);
        $this->publisher->updateDraft(
            $this->actor,
            (string) $published['id'],
            ['title' => 'Stale edit'],
            $published['revision_id'],
            'k4',
        );
    }

    #[Test]
    public function unpublish_preserves_the_record_and_history(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $published = $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'k2', 'Go live');
        $unpublished = $this->publisher->unpublish($this->actor, (string) $draft['id'], $published['revision_id'], 'k3', 'Take down');

        self::assertFalse($unpublished['status']);
        self::assertNotNull($this->repo->find((string) $draft['id']));
        self::assertNull($this->repo->loadPublishedRevision((string) $draft['id']));
        self::assertCount(0, $this->publisher->list($this->actor, publishedOnly: true));
        self::assertGreaterThanOrEqual(3, \count($this->publisher->revisions($this->actor, (string) $draft['id'])));
        self::assertContains('content.unpublished', $this->audit->kinds());
    }

    #[Test]
    public function unpublish_after_forward_draft_accepts_working_copy_token_and_does_not_leak_draft(): void
    {
        $draft = $this->publisher->createDraft(
            $this->actor,
            $this->draftValues(['title' => 'Third title']),
            'k1',
        );
        $published = $this->publisher->publish(
            $this->actor,
            (string) $draft['id'],
            $draft['revision_id'],
            'k2',
        );
        $id = (string) $published['id'];
        $updated = $this->publisher->updateDraft(
            $this->actor,
            $id,
            ['title' => 'UNPUBLISHED DRAFT EDIT'],
            $published['revision_id'],
            'k3',
        );

        $unpublished = $this->publisher->unpublish(
            $this->actor,
            $id,
            $updated['revision_id'],
            'k4',
            'Take down',
        );

        self::assertFalse($unpublished['status']);
        self::assertSame('Third title', $this->repo->find($id)?->get('title'));
        self::assertSame('UNPUBLISHED DRAFT EDIT', $this->repo->loadWorkingCopy($id)?->get('title'));
        self::assertNull($this->repo->loadPublishedRevision($id));
        self::assertCount(0, $this->publisher->list($this->actor, publishedOnly: true));
        $baseRow = $this->db->getConnection()->fetchAssociative(
            'SELECT revision_id, published_revision_id FROM test_article WHERE id = ?',
            [$id],
        );
        self::assertIsArray($baseRow);
        self::assertSame($published['revision_id'], (int) $baseRow['revision_id']);
        self::assertTrue(
            $baseRow['published_revision_id'] === null || (int) $baseRow['published_revision_id'] === 0,
        );
    }

    #[Test]
    public function unpublished_records_tip_track_on_later_draft_saves(): void
    {
        $draft = $this->publisher->createDraft(
            $this->actor,
            $this->draftValues(['title' => 'Live title']),
            'k1',
        );
        $published = $this->publisher->publish(
            $this->actor,
            (string) $draft['id'],
            $draft['revision_id'],
            'k2',
        );
        $unpublished = $this->publisher->unpublish(
            $this->actor,
            (string) $published['id'],
            $published['revision_id'],
            'k3',
        );
        $this->publisher->updateDraft(
            $this->actor,
            (string) $unpublished['id'],
            ['title' => 'Post unpublish edit'],
            $unpublished['revision_id'],
            'k4',
        );

        $id = (string) $published['id'];
        self::assertNull($this->repo->loadPublishedRevision($id));
        self::assertSame('Post unpublish edit', $this->repo->find($id)?->get('title'));
        self::assertSame('Post unpublish edit', $this->repo->loadWorkingCopy($id)?->get('title'));
    }

    #[Test]
    public function republish_restores_the_served_workflow_state(): void
    {
        $draft = $this->publisher->createDraft(
            $this->actor,
            $this->draftValues(['title' => 'Third title']),
            'k1',
        );
        $entity = $this->repo->find((string) $draft['id']);
        self::assertNotNull($entity);
        $entity->set('workflow_state', 'published');
        $this->repo->save($entity);
        $working = $this->repo->loadWorkingCopy((string) $draft['id']);
        self::assertNotNull($working);
        $published = $this->publisher->publish(
            $this->actor,
            (string) $draft['id'],
            (int) $working->get('revision_id'),
            'k2',
        );
        $id = (string) $published['id'];
        $updated = $this->publisher->updateDraft(
            $this->actor,
            $id,
            ['title' => 'UNPUBLISHED DRAFT EDIT'],
            $published['revision_id'],
            'k3',
        );
        self::assertSame('draft', $this->repo->loadWorkingCopy($id)?->get('workflow_state'));
        self::assertSame('published', $this->repo->find($id)?->get('workflow_state'));

        $this->publisher->publish(
            $this->actor,
            $id,
            $updated['revision_id'],
            'k4',
        );

        self::assertSame('published', $this->repo->find($id)?->get('workflow_state'));
        self::assertSame('published', $this->repo->loadPublishedRevision($id)?->get('workflow_state'));
        self::assertSame('UNPUBLISHED DRAFT EDIT', $this->repo->find($id)?->get('title'));
    }

    #[Test]
    public function a_bound_workflow_owns_publication_and_still_honors_optimistic_locking(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $transitioner = new class ($this->repo) implements ContentPublicationTransitionerInterface {
            public int $calls = 0;

            public function __construct(private readonly EntityRepository $repository) {}

            public function supports(EntityInterface $entity): bool
            {
                return true;
            }

            public function setPublished(EntityInterface $entity, bool $published, \Waaseyaa\Access\AuthorizationPrincipalInterface $actor): EntityInterface
            {
                ++$this->calls;
                $entity->set('status', $published ? 1 : 0);
                $this->repository->save($entity, true);

                return $this->repository->loadWorkingCopy((string) $entity->id()) ?? $entity;
            }
        };
        $publisher = new ContentPublisher(
            $this->descriptor(),
            $this->repo,
            new IdempotencyStore($this->db),
            $this->audit,
            publicationTransitioner: $transitioner,
        );

        $published = $publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'workflow-publish');
        self::assertTrue($published['status']);
        self::assertSame(1, $transitioner->calls);

        $this->expectException(RevisionConflictException::class);
        $publisher->unpublish($this->actor, (string) $draft['id'], $draft['revision_id'], 'workflow-stale');
    }

    // --- rollback / revisions ---

    #[Test]
    public function rollback_creates_a_new_revision_instead_of_deleting_history(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(['title' => 'Original']), 'k1');
        $updated = $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['title' => 'Edited'], $draft['revision_id'], 'k2');

        $before = \count($this->publisher->revisions($this->actor, (string) $draft['id']));
        $rolled = $this->publisher->rollback($this->actor, (string) $draft['id'], $draft['revision_id'], 'k3', 'Restore original');

        self::assertSame('Original', $rolled['title']);
        self::assertGreaterThan($updated['revision_id'], $rolled['revision_id']);
        self::assertSame($before + 1, \count($this->publisher->revisions($this->actor, (string) $draft['id'])));
        self::assertContains('content.rolled_back', $this->audit->kinds());
    }

    #[Test]
    public function revisions_lists_newest_first_with_metadata(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['summary' => 'v2'], $draft['revision_id'], 'k2');

        $revisions = $this->publisher->revisions($this->actor, (string) $draft['id']);
        self::assertGreaterThanOrEqual(2, \count($revisions));
        self::assertGreaterThan($revisions[1]['revision_id'], $revisions[0]['revision_id']);
        self::assertArrayHasKey('created_at', $revisions[0]);
        self::assertArrayHasKey('author_uid', $revisions[0]);
        self::assertArrayHasKey('log', $revisions[0]);
    }

    // --- reads ---

    #[Test]
    public function get_resolves_by_id_or_slug_and_list_filters_published(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $bySlug = $this->publisher->get($this->actor, 'first-post');
        self::assertSame($draft['id'], $bySlug['id']);

        self::assertCount(1, $this->publisher->list($this->actor));
        self::assertCount(0, $this->publisher->list($this->actor, publishedOnly: true));

        $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'k2', 'Go');
        self::assertCount(1, $this->publisher->list($this->actor, publishedOnly: true));
    }

    private function requireTitleAdvisory(): void
    {
        $this->events->addListener(BeforeSaveEvent::class, static function (BeforeSaveEvent $event): void {
            SaveAdvisoryGate::requireAcknowledged([
                SaveAdvisory::forEntityField(
                    $event->entity(),
                    'EDITORIAL_TITLE_REVIEW',
                    'title',
                    'Review the title before saving.',
                ),
            ], $event->saveContext());
        });
    }
}
