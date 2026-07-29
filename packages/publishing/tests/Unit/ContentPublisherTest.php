<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\ContentTypeDescriptor;
use Waaseyaa\Publishing\ContentValidatorInterface;
use Waaseyaa\Publishing\Exception\ContentAuthorizationException;
use Waaseyaa\Publishing\Exception\ContentValidationException;
use Waaseyaa\Publishing\Exception\IdempotencyConflictException;
use Waaseyaa\Publishing\Exception\SlugConflictException;
use Waaseyaa\Publishing\FieldSpec;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;
use Waaseyaa\Publishing\Tests\Fixtures\PublisherAccount;
use Waaseyaa\Publishing\Tests\Fixtures\SpyAuditWriter;
use Waaseyaa\Publishing\Tests\Fixtures\TestArticleEntity;
use Waaseyaa\Publishing\ValidationErrors;

#[CoversClass(ContentPublisher::class)]
final class ContentPublisherTest extends TestCase
{
    private const string CAPABILITY = 'publish test articles';

    private EntityRepository $repo;
    private SpyAuditWriter $audit;
    private ContentPublisher $publisher;
    private PublisherAccount $actor;

    protected function setUp(): void
    {
        $db = DBALDatabase::createSqlite();
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
        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            new EventDispatcher(),
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

    // --- authorization ---

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
    public function create_draft_is_never_public_and_returns_the_revision_token(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');

        self::assertFalse($draft['status']);
        self::assertSame('first-post', $draft['slug']);
        self::assertIsInt($draft['revision_id']);
        $stored = $this->repo->find((string) $draft['id']);
        self::assertNotNull($stored);
        self::assertSame(0, (int) $stored->get('status'));
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
    public function update_creates_a_new_revision_and_returns_the_new_token(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $updated = $this->publisher->updateDraft($this->actor, (string) $draft['id'], ['summary' => 'v2'], $draft['revision_id'], 'k2');

        self::assertGreaterThan($draft['revision_id'], $updated['revision_id']);
        self::assertSame('v2', $updated['summary']);
    }

    // --- publish / unpublish ---

    #[Test]
    public function publish_sets_status_stamps_the_note_and_audits(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $published = $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'k2', 'Go live');

        self::assertTrue($published['status']);
        $stored = $this->repo->find((string) $draft['id']);
        self::assertSame(1, (int) $stored?->get('status'));
        self::assertContains('content.published', $this->audit->kinds());

        $revisions = $this->publisher->revisions($this->actor, (string) $draft['id']);
        self::assertSame('Go live', $revisions[0]['log']);
    }

    #[Test]
    public function unpublish_preserves_the_record_and_history(): void
    {
        $draft = $this->publisher->createDraft($this->actor, $this->draftValues(), 'k1');
        $published = $this->publisher->publish($this->actor, (string) $draft['id'], $draft['revision_id'], 'k2', 'Go live');
        $unpublished = $this->publisher->unpublish($this->actor, (string) $draft['id'], $published['revision_id'], 'k3', 'Take down');

        self::assertFalse($unpublished['status']);
        self::assertNotNull($this->repo->find((string) $draft['id']));
        self::assertGreaterThanOrEqual(3, \count($this->publisher->revisions($this->actor, (string) $draft['id'])));
        self::assertContains('content.unpublished', $this->audit->kinds());
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
}
