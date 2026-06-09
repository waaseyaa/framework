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
}
