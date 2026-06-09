<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * The unified revision system's optional TRANSLATION AXIS: a revisionable +
 * translatable entity keeps per-language revision history in
 * `<entity>__translation__revision` with INDEPENDENT sequencing — editing
 * English does not bump the Anishinaabemowin revision count, and vice versa
 * (the M-004 FR-043 timeline).
 */
#[CoversClass(EntityRepository::class)]
final class EntityRepositoryTranslationAxisTest extends TestCase
{
    private DBALDatabase $db;
    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
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
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $this->repo = new EntityRepository(
            $entityType,
            new SqlStorageDriver($resolver),
            $dispatcher,
            new RevisionableStorageDriver($resolver, $entityType),
            $this->db,
        );
    }

    #[Test]
    public function languages_sequence_independently_fr043(): void
    {
        // 1. create en (en rev 1)        2. add oj (oj rev 1)
        $this->assertSame(1, $this->repo->saveTranslationRevision('1', 'en', ['title' => 'Teaching about turtles']));
        $this->assertSame(1, $this->repo->saveTranslationRevision('1', 'oj', ['title' => "Mikinaak-gikinoo'amaadiwin"]));
        // 3. edit en x3 -> en rev 2,3,4 (oj untouched)
        $this->assertSame(2, $this->repo->saveTranslationRevision('1', 'en', ['title' => 'en v2']));
        $this->assertSame(3, $this->repo->saveTranslationRevision('1', 'en', ['title' => 'en v3']));
        $this->assertSame(4, $this->repo->saveTranslationRevision('1', 'en', ['title' => 'en v4']));
        // 4. edit oj x2 -> oj rev 2,3 (en untouched)
        $this->assertSame(2, $this->repo->saveTranslationRevision('1', 'oj', ['title' => 'oj v2']));
        $this->assertSame(3, $this->repo->saveTranslationRevision('1', 'oj', ['title' => 'oj v3']));

        // Tips: en at 4, oj at 3 — independent.
        $en = $this->repo->loadTranslationTip('1', 'en');
        $oj = $this->repo->loadTranslationTip('1', 'oj');
        $this->assertNotNull($en);
        $this->assertNotNull($oj);
        $this->assertSame('en v4', $en->label());
        $this->assertSame('oj v3', $oj->label());

        // History counts independent.
        $this->assertCount(4, $this->repo->listTranslationRevisions('1', 'en'));
        $this->assertCount(3, $this->repo->listTranslationRevisions('1', 'oj'));

        // Languages enumerated.
        $this->assertSame(['en', 'oj'], $this->repo->translationLangcodes('1'));

        // A specific old revision is recoverable verbatim.
        $enFirst = $this->repo->loadTranslationRevision('1', 'en', 1);
        $this->assertNotNull($enFirst);
        $this->assertSame('Teaching about turtles', $enFirst->label());
    }

    #[Test]
    public function atomic_multi_language_write(): void
    {
        $created = $this->repo->saveTranslationRevisions('1', [
            'en' => ['title' => 'Hello'],
            'oj' => ['title' => 'Aanii'],
        ]);
        $this->assertSame(['en' => 1, 'oj' => 1], $created);

        $en = $this->repo->loadTranslationTip('1', 'en');
        $oj = $this->repo->loadTranslationTip('1', 'oj');
        $this->assertNotNull($en);
        $this->assertNotNull($oj);
        $this->assertSame('Hello', $en->label());
        $this->assertSame('Aanii', $oj->label());
    }

    #[Test]
    public function tip_is_null_for_an_untranslated_language(): void
    {
        $this->repo->saveTranslationRevision('1', 'en', ['title' => 'x']);
        $this->assertNull($this->repo->loadTranslationTip('1', 'fr'));
        $this->assertSame([], $this->repo->listTranslationRevisions('1', 'fr'));
    }

    #[Test]
    public function translatable_entity_gets_an_assigned_id_on_save(): void
    {
        // Translatable base tables do not autoincrement (PK is (id, langcode)),
        // so save() must assign the id for a new entity with none pre-set.
        $a = new TestRevisionableEntity(values: ['title' => 'Alpha', 'uuid' => 'a']);
        $a->enforceIsNew();
        $this->repo->save($a);
        $this->assertSame(1, (int) $a->id());

        $b = new TestRevisionableEntity(values: ['title' => 'Beta', 'uuid' => 'b']);
        $b->enforceIsNew();
        $this->repo->save($b);
        $this->assertSame(2, (int) $b->id());

        $loaded = $this->repo->find('1');
        $this->assertNotNull($loaded);
        $this->assertSame('Alpha', $loaded->label());
        // The single-axis revision was still recorded for the new entity.
        $this->assertCount(1, $this->repo->listRevisions('1'));
    }

    #[Test]
    public function save_translation_writes_a_peer_row_and_its_revision_atomically(): void
    {
        // English: the default-language base row, written the ordinary way.
        $en = new TestRevisionableEntity(values: ['title' => 'Seven Grandfathers', 'uuid' => 'g7']);
        $en->enforceIsNew();
        $this->repo->save($en);
        $id = (string) $en->id();

        // Anishinaabemowin: a true peer — its own (id, langcode) base row AND its
        // own per-language revision, in one call.
        $rev = $this->repo->saveTranslation($id, 'oj', ['title' => 'Niizhwaaswi Mishomis']);
        $this->assertSame(1, $rev);

        // The peer base row is the current oj value; the default row is unchanged.
        $ojNow = $this->repo->loadTranslation($id, 'oj');
        $this->assertNotNull($ojNow);
        $this->assertSame('Niizhwaaswi Mishomis', $ojNow->label());

        $default = $this->repo->find($id);
        $this->assertNotNull($default);
        $this->assertSame('Seven Grandfathers', $default->label());

        // find() by langcode reads the peer row directly.
        $ojByFind = $this->repo->find($id, 'oj');
        $this->assertNotNull($ojByFind);
        $this->assertSame('Niizhwaaswi Mishomis', $ojByFind->label());

        // The per-language revision history exists and sequences independently.
        $this->assertCount(1, $this->repo->listTranslationRevisions($id, 'oj'));
        $this->assertSame(2, $this->repo->saveTranslation($id, 'oj', ['title' => 'oj v2']));
        $this->assertCount(2, $this->repo->listTranslationRevisions($id, 'oj'));

        // The second save updated the same peer row in place (no duplicate).
        $ojV2 = $this->repo->loadTranslation($id, 'oj');
        $this->assertNotNull($ojV2);
        $this->assertSame('oj v2', $ojV2->label());

        // An old peer revision is still recoverable verbatim.
        $ojFirst = $this->repo->loadTranslationRevision($id, 'oj', 1);
        $this->assertNotNull($ojFirst);
        $this->assertSame('Niizhwaaswi Mishomis', $ojFirst->label());
    }

    #[Test]
    public function a_spurious_column_translations_sibling_does_not_hide_blob_peer_rows(): void
    {
        // Regression: a schema-sync may materialise a `<entity>_translations`
        // sibling (the sql-COLUMN translation model's table) alongside the blob
        // peer rows. That sibling is empty for a blob-translatable entity, and
        // the driver used to divert every language-scoped read into it, so a
        // translated language was wrongly reported as missing. The base-table
        // peer read must take precedence.
        $this->db->getConnection()->executeStatement(
            'CREATE TABLE test_revisionable_translations '
            . '(entity_id INTEGER NOT NULL, langcode VARCHAR(12) NOT NULL, _data TEXT, '
            . 'PRIMARY KEY (entity_id, langcode))',
        );

        $en = new TestRevisionableEntity(values: ['title' => 'Seven Grandfathers', 'uuid' => 'g7']);
        $en->enforceIsNew();
        $this->repo->save($en);
        $id = (string) $en->id();

        $this->repo->saveTranslation($id, 'oj', ['title' => 'Niizhwaaswi Mishomis']);

        // The Anishinaabemowin peer is found despite the sibling table existing.
        $oj = $this->repo->loadTranslation($id, 'oj');
        $this->assertNotNull($oj, 'a blob peer row must not be hidden by an empty column-translations sibling');
        $this->assertSame('Niizhwaaswi Mishomis', $oj->label());
        $this->assertSame('Niizhwaaswi Mishomis', $this->repo->find($id, 'oj')?->label());

        // English is still the default row; an untranslated language is null.
        $this->assertSame('Seven Grandfathers', $this->repo->find($id, 'en')?->label());
        $this->assertNull($this->repo->loadTranslation($id, 'fr'));

        // readMultiple is consistent: the peer row wins over the sibling.
        $driver = new SqlStorageDriver(new SingleConnectionResolver($this->db));
        $many = $driver->readMultiple('test_revisionable', [$id], 'oj');
        $this->assertArrayHasKey($id, $many);
        $this->assertSame('Niizhwaaswi Mishomis', $many[$id]['title'] ?? null);
    }
}
