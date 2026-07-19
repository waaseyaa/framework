<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Integration\RevisionAuthor;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

/**
 * Mission revision-audit-provenance-01KTWY5V WP02 (T010) — end-to-end revision
 * author recording and readback over a direct repository + SQLite
 * (contracts/revision-author.md clauses 1–14).
 *
 * Covers: the record/readback matrix (account N / anonymous 0 / no context →
 * null), SaveContext override precedence incl. `withActorUid(null)`, revert
 * authorship, the pre-existing-table additive migration (SC-004), the
 * physical-column pin (clause 14 — author NOT folded into `_data`), the
 * publish/revert pointer events, and the two-axis translation path.
 */
#[CoversNothing]
final class RevisionAuthorTest extends TestCase
{
    private DBALDatabase $db;
    private RequestAccountContext $accountContext;
    private SpyEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->accountContext = new RequestAccountContext();
        $this->dispatcher = new SpyEventDispatcher();
    }

    // ------------------------------------------------------------------
    // 1. Record/readback matrix (clauses 3, 8, 9)
    // ------------------------------------------------------------------

    #[Test]
    public function authenticated_context_save_records_and_reads_back_the_account_id(): void
    {
        $repo = $this->buildRepository();
        $this->accountContext->set(new StubAccount(7));

        $this->saveSubject($repo, '1', 'v1');

        $revision = $repo->loadRevision('1', 1);
        self::assertInstanceOf(RevisionableEntityInterface::class, $revision);
        $metadata = $revision->revisionMetadata();
        self::assertNotNull($metadata, 'loadRevision() must hydrate RevisionMetadata.');
        self::assertSame(7, $metadata->revisionAuthor);
    }

    #[Test]
    public function anonymous_account_zero_round_trips_as_zero_not_null(): void
    {
        $repo = $this->buildRepository();
        $this->accountContext->set(new StubAccount(0));

        $this->saveSubject($repo, '1', 'v1');

        $metadata = $repo->loadRevision('1', 1)->revisionMetadata();
        self::assertNotNull($metadata);
        self::assertSame(0, $metadata->revisionAuthor, 'Anonymous (0) is an actor — must not collapse to null.');
    }

    #[Test]
    public function save_without_any_context_records_null_author(): void
    {
        $repo = $this->buildRepository();
        // No setAccountContext() call at all — the bare-construction default.

        $this->saveSubject($repo, '1', 'v1');

        $metadata = $repo->loadRevision('1', 1)->revisionMetadata();
        self::assertNotNull($metadata);
        self::assertNull($metadata->revisionAuthor);
    }

    #[Test]
    public function attached_context_holding_no_account_records_null_author(): void
    {
        $repo = $this->buildRepository(attachContext: true);
        // Context attached but empty (CLI/queue shape): current() === null.

        $this->saveSubject($repo, '1', 'v1');

        self::assertNull($repo->loadRevision('1', 1)->revisionMetadata()->revisionAuthor);
    }

    #[Test]
    public function deferred_id_save_records_the_same_resolved_author(): void
    {
        // New entity with NO preset id: the revision write defers until the
        // base insert assigns one (EntityRepository doSave deferred-id site).
        $repo = $this->buildRepository();
        $this->accountContext->set(new StubAccount(7));

        $entity = new RevisionAuthorSubject(['title' => 'auto-id', 'uuid' => 'auto-1']);
        $repo->save($entity);
        $id = (string) $entity->id();
        self::assertNotSame('', $id);

        self::assertSame(7, $repo->loadRevision($id, 1)->revisionMetadata()->revisionAuthor);
    }

    // ------------------------------------------------------------------
    // 2. Override precedence (clause 5)
    // ------------------------------------------------------------------

    #[Test]
    public function save_context_override_wins_over_ambient_account(): void
    {
        $repo = $this->buildRepository();
        $this->accountContext->set(new StubAccount(7));

        $entity = $this->newSubject('1', 'v1');
        $repo->save($entity, context: SaveContext::default()->withActorUid(99));

        self::assertSame(99, $repo->loadRevision('1', 1)->revisionMetadata()->revisionAuthor);
    }

    #[Test]
    public function withActorUid_null_forces_null_author_inside_authenticated_context(): void
    {
        $repo = $this->buildRepository();
        $this->accountContext->set(new StubAccount(7));

        $entity = $this->newSubject('1', 'v1');
        $repo->save($entity, context: SaveContext::default()->withActorUid(null));

        self::assertNull(
            $repo->loadRevision('1', 1)->revisionMetadata()->revisionAuthor,
            'An explicit withActorUid(null) must force a NULL author (system-attributed write).',
        );
    }

    #[Test]
    public function non_overridden_context_defers_to_the_ambient_account(): void
    {
        $repo = $this->buildRepository();
        $this->accountContext->set(new StubAccount(7));

        $entity = $this->newSubject('1', 'v1');
        $repo->save($entity, context: SaveContext::default()->withoutNewRevision()->withLangcode('en'));

        // withoutNewRevision on a NEW entity still creates revision 1 (first
        // save always does); the point is the un-overridden context defers.
        self::assertSame(7, $repo->loadRevision('1', 1)->revisionMetadata()->revisionAuthor);
    }

    // ------------------------------------------------------------------
    // 3. Revert authorship (clause 4)
    // ------------------------------------------------------------------

    #[Test]
    public function rollback_creates_a_revision_authored_by_the_reverter_and_leaves_the_target_untouched(): void
    {
        $repo = $this->buildRepository();

        // Revisions 1+2 authored by A (uid 7).
        $this->accountContext->set(new StubAccount(7));
        $this->saveSubject($repo, '1', 'v1');
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity);

        // B (uid 9) performs the revert to revision 1.
        $this->accountContext->set(new StubAccount(9));
        $reverted = $repo->rollback('1', 1);

        self::assertSame(3, $reverted->revisionId(), 'rollback() must create a NEW revision.');
        self::assertSame(
            9,
            $repo->loadRevision('1', 3)->revisionMetadata()->revisionAuthor,
            'The new revision is authored by whoever performed the revert.',
        );
        self::assertSame(
            7,
            $repo->loadRevision('1', 1)->revisionMetadata()->revisionAuthor,
            'The reverted-to target revision row must never be modified.',
        );
        self::assertSame('v1', $repo->loadRevision('1', 3)->get('title'));
    }

    // ------------------------------------------------------------------
    // 4 + 5. Pre-existing-table additive migration + physical-column pin
    //        (SC-004, clauses 12–14)
    // ------------------------------------------------------------------

    #[Test]
    public function pre_existing_revision_table_gains_the_column_additively_and_old_rows_read_null(): void
    {
        // Hand-create a PRE-MISSION-shaped revision table (no revision_author)
        // with a row, exactly what an existing install carries.
        $this->db->schema()->createTable('revision_author_subject_revision', [
            'fields' => [
                'entity_id' => ['type' => 'varchar', 'length' => 128, 'not null' => true],
                'revision_id' => ['type' => 'int', 'not null' => true],
                'revision_created' => ['type' => 'varchar', 'length' => 32, 'not null' => true],
                'revision_log' => ['type' => 'text', 'not null' => false],
                'title' => ['type' => 'varchar', 'length' => 255, 'not null' => true, 'default' => ''],
                'bundle' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
                'langcode' => ['type' => 'varchar', 'length' => 12, 'not null' => true, 'default' => 'en'],
                'uuid' => ['type' => 'varchar', 'length' => 128, 'not null' => true, 'default' => ''],
                '_data' => ['type' => 'text', 'not null' => true, 'default' => '{}'],
            ],
            'primary key' => ['entity_id', 'revision_id'],
            'indexes' => [],
        ]);
        $this->db->insert('revision_author_subject_revision')
            ->fields(['entity_id', 'revision_id', 'revision_created', 'revision_log', 'title', '_data'])
            ->values([
                'entity_id' => '1',
                'revision_id' => 1,
                'revision_created' => '2026-01-01 00:00:00',
                'revision_log' => 'pre-mission revision',
                'title' => 'old',
                '_data' => '{}',
            ])
            ->execute();
        self::assertFalse($this->db->schema()->fieldExists('revision_author_subject_revision', 'revision_author'));

        // Run the PRODUCTION sync path (not a hand-rolled ALTER): buildRepository()
        // routes through SqlSchemaHandler::ensureTable()/ensureRevisionTable().
        $repo = $this->buildRepository();

        // Clause 12: column physically added; idempotent re-run is a no-op.
        self::assertTrue($this->db->schema()->fieldExists('revision_author_subject_revision', 'revision_author'));

        // Base row so loadRevision() can resolve current/latest pointers.
        $this->db->insert('revision_author_subject')
            ->fields(['id', 'uuid', 'title', 'bundle', 'langcode', 'revision_id', '_data'])
            ->values([
                'id' => '1', 'uuid' => 'pre', 'title' => 'old',
                'bundle' => 'revision_author_subject', 'langcode' => 'en',
                'revision_id' => 1, '_data' => '{}',
            ])
            ->execute();

        // Clause 13: the pre-existing row reads back a null author.
        $metadata = $repo->loadRevision('1', 1)->revisionMetadata();
        self::assertNotNull($metadata);
        self::assertNull($metadata->revisionAuthor);
        self::assertSame('pre-mission revision', $metadata->revisionLog);

        // A new save on the migrated table records an author.
        $this->accountContext->set(new StubAccount(7));
        $entity = $repo->find('1');
        $entity->set('title', 'new');
        $repo->save($entity);
        self::assertSame(7, $repo->loadRevision('1', 2)->revisionMetadata()->revisionAuthor);

        // Clause 14 — the physical-column pin: revision_author is a REAL
        // column carrying the value, and it is NOT inside the `_data` blob
        // (a regressed sync arm would make foldData() absorb it silently).
        $raw = null;
        foreach ($this->db->query(
            'SELECT revision_author, _data FROM revision_author_subject_revision WHERE entity_id = ? AND revision_id = 2',
            ['1'],
        ) as $row) {
            $raw = (array) $row;
            break;
        }
        self::assertNotNull($raw);
        self::assertSame(7, (int) $raw['revision_author']);
        $blob = json_decode((string) $raw['_data'], associative: true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($blob);
        self::assertArrayNotHasKey('revision_author', $blob, 'The author must live in the physical column, not the _data blob.');
    }

    // ------------------------------------------------------------------
    // 6. Pointer events (FR-006, audit-attribution.md clauses 12, 14–15)
    // ------------------------------------------------------------------

    #[Test]
    public function set_published_revision_dispatches_publish_pointer_event_with_actor(): void
    {
        $repo = $this->buildRepository();
        $this->accountContext->set(new StubAccount(7));
        $this->saveSubject($repo, '1', 'v1');
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity);

        $this->dispatcher->events = [];
        $repo->setPublishedRevision('1', 1);

        $pointerEvents = $this->dispatcher->ofType(RevisionPointerMovedEvent::class);
        self::assertCount(1, $pointerEvents);
        [$eventName, $event] = $pointerEvents[0];
        self::assertSame(RevisionPointerMovedEvent::class, $eventName, 'Dispatched by FQCN.');
        self::assertSame('publish', $event->operation);
        self::assertSame('revision_author_subject', $event->entityTypeId);
        self::assertSame('1', $event->entityId);
        self::assertNull($event->fromRevisionId, 'Previously unpublished → from is null.');
        self::assertSame(1, $event->toRevisionId);
        self::assertSame(7, $event->actorUid);

        // Legacy REVISION_REVERTED preserved alongside.
        self::assertNotEmpty($this->dispatcher->named(EntityEvents::REVISION_REVERTED->value));

        // Re-publish: from→to transition reflects the prior pointer.
        $this->dispatcher->events = [];
        $repo->setPublishedRevision('1', 2);
        [, $second] = $this->dispatcher->ofType(RevisionPointerMovedEvent::class)[0];
        self::assertSame(1, $second->fromRevisionId);
        self::assertSame(2, $second->toRevisionId);
    }

    #[Test]
    public function set_current_revision_dispatches_revert_pointer_event_with_actor(): void
    {
        $repo = $this->buildRepository();
        $this->accountContext->set(new StubAccount(7));
        $this->saveSubject($repo, '1', 'v1');
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity);

        $this->accountContext->set(new StubAccount(9));
        $this->dispatcher->events = [];
        $repo->setCurrentRevision('1', 1);

        $pointerEvents = $this->dispatcher->ofType(RevisionPointerMovedEvent::class);
        self::assertCount(1, $pointerEvents);
        [, $event] = $pointerEvents[0];
        self::assertSame('revert', $event->operation);
        self::assertSame(2, $event->fromRevisionId, 'Prior base revision_id pointer.');
        self::assertSame(1, $event->toRevisionId);
        self::assertSame(9, $event->actorUid);

        self::assertNotEmpty($this->dispatcher->named(EntityEvents::REVISION_REVERTED->value));
    }

    #[Test]
    public function rollback_dispatches_no_pointer_event(): void
    {
        $repo = $this->buildRepository();
        $this->accountContext->set(new StubAccount(7));
        $this->saveSubject($repo, '1', 'v1');
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity);

        $this->dispatcher->events = [];
        $repo->rollback('1', 1);

        self::assertSame(
            [],
            $this->dispatcher->ofType(RevisionPointerMovedEvent::class),
            'rollback() creates a revision (REVISION_CREATED flow) — no pointer event (clause 15).',
        );
        self::assertNotEmpty($this->dispatcher->named(EntityEvents::REVISION_CREATED->value));
        self::assertNotEmpty($this->dispatcher->named(EntityEvents::REVISION_REVERTED->value));
    }

    // ------------------------------------------------------------------
    // 7. Translation paths (two-axis)
    // ------------------------------------------------------------------

    #[Test]
    public function translation_revision_records_the_author_in_the_translation_revision_table(): void
    {
        $repo = $this->buildTwoAxisRepository();
        $this->accountContext->set(new StubAccount(7));

        // Default-language base row so the peer-row upsert can copy identity.
        $entity = new RevisionAuthorTranslatableSubject([
            'id' => 1, 'uuid' => 'ta-1', 'title' => 'Hello',
            'langcode' => 'en', 'default_langcode' => 'en',
        ]);
        $entity->enforceIsNew();
        $repo->save($entity);

        $revisionId = $repo->saveTranslation('1', 'fr', ['title' => 'Bonjour']);

        // Raw row in <entity>__translation__revision carries the author.
        $raw = null;
        foreach ($this->db->query(
            'SELECT revision_author FROM revision_author_two_axis__translation__revision WHERE entity_id = ? AND langcode = ? AND revision_id = ?',
            ['1', 'fr', (string) $revisionId],
        ) as $row) {
            $raw = (array) $row;
            break;
        }
        self::assertNotNull($raw);
        self::assertSame(7, (int) $raw['revision_author']);

        // And reads back through the metadata surface.
        $translation = $repo->loadTranslationRevision('1', 'fr', $revisionId);
        self::assertInstanceOf(RevisionableEntityInterface::class, $translation);
        self::assertSame(7, $translation->revisionMetadata()->revisionAuthor);
    }

    #[Test]
    public function multi_language_translation_save_records_the_same_author_for_every_language(): void
    {
        $repo = $this->buildTwoAxisRepository();
        $this->accountContext->set(new StubAccount(7));

        $created = $repo->saveTranslationRevisions('1', [
            'en' => ['title' => 'Hello'],
            'fr' => ['title' => 'Bonjour'],
        ]);

        foreach ($created as $langcode => $revisionId) {
            $translation = $repo->loadTranslationRevision('1', $langcode, $revisionId);
            self::assertSame(
                7,
                $translation->revisionMetadata()->revisionAuthor,
                "Language {$langcode} must carry the once-resolved author.",
            );
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function subjectType(): EntityType
    {
        return new EntityType(
            id: 'revision_author_subject',
            label: 'Revision author subject',
            class: RevisionAuthorSubject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
    }

    private function buildRepository(bool $attachContext = true): EntityRepository
    {
        $entityType = $this->subjectType();

        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            $this->dispatcher,
            new RevisionableStorageDriver($resolver, $entityType),
            $this->db,
        );

        if ($attachContext) {
            $repo->setAccountContext($this->accountContext);
        }

        return $repo;
    }

    private function buildTwoAxisRepository(): EntityRepository
    {
        $entityType = new EntityType(
            id: 'revision_author_two_axis',
            label: 'Revision author two-axis subject',
            class: RevisionAuthorTranslatableSubject::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'revision' => 'revision_id',
                'langcode' => 'langcode',
                'default_langcode' => 'default_langcode',
            ],
            revisionable: true,
            revisionDefault: true,
            translatable: true,
        );

        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();
        $handler->ensureTranslationRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            $this->dispatcher,
            new RevisionableStorageDriver($resolver, $entityType),
            $this->db,
        );
        $repo->setAccountContext($this->accountContext);

        return $repo;
    }

    private function newSubject(string $id, string $title): RevisionAuthorSubject
    {
        $entity = new RevisionAuthorSubject(['id' => $id, 'uuid' => 'u-' . $id, 'title' => $title]);
        $entity->enforceIsNew();

        return $entity;
    }

    private function saveSubject(EntityRepository $repo, string $id, string $title): void
    {
        $repo->save($this->newSubject($id, $title));
    }
}

/**
 * Spy PSR-14 dispatcher recording (eventName, event) pairs as
 * EntityRepository::dispatchEvent() emits them.
 */
final class SpyEventDispatcher implements EventDispatcherInterface
{
    /** @var list<array{?string, object}> */
    public array $events = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->events[] = [$eventName, $event];

        return $event;
    }

    /**
     * @param class-string $class
     * @return list<array{?string, object}>
     */
    public function ofType(string $class): array
    {
        return array_values(array_filter($this->events, static fn(array $pair): bool => $pair[1] instanceof $class));
    }

    /**
     * @return list<array{?string, object}>
     */
    public function named(string $eventName): array
    {
        return array_values(array_filter($this->events, static fn(array $pair): bool => $pair[0] === $eventName));
    }
}

/** Minimal account stub: id-only, no roles/permissions. */
final class StubAccount implements AccountInterface
{
    public function __construct(private readonly int $uid) {}

    public function id(): int|string
    {
        return $this->uid;
    }

    public function hasPermission(string $permission): bool
    {
        return false;
    }

    public function getRoles(): array
    {
        return [];
    }

    public function isAuthenticated(): bool
    {
        return $this->uid > 0;
    }
}

/** Single-axis revisionable fixture implementing the WP07 read contract. */
#[ContentEntityType(id: 'revision_author_subject')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', revision: 'revision_id')]
class RevisionAuthorSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
{
    use RevisionableEntityTrait;

    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     * @param array<mixed> $fieldDefinitions
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}

/** Two-axis (revisionable + translatable) fixture. */
#[ContentEntityType(id: 'revision_author_two_axis')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', revision: 'revision_id', langcode: 'langcode', default_langcode: 'default_langcode')]
class RevisionAuthorTranslatableSubject extends ContentEntityBase implements RevisionableInterface, RevisionableEntityInterface
{
    use RevisionableEntityTrait;

    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $entityKeys
     * @param array<mixed> $fieldDefinitions
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
