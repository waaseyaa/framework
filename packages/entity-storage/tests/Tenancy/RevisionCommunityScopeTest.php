<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriverV2;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tenancy\CommunityScope;
use Waaseyaa\EntityStorage\Tenancy\TenancyViolationException;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;
use Waaseyaa\Foundation\Community\CommunityContext;

#[CoversClass(RevisionableStorageDriver::class)]
#[CoversClass(SqlStorageDriver::class)]
#[CoversClass(SqlStorageDriverV2::class)]
#[CoversClass(EntityRepository::class)]
final class RevisionCommunityScopeTest extends TestCase
{
    private DBALDatabase $database;
    private CommunityContext $context;
    private EntityRepository $repository;
    private RevisionableStorageDriver $revisionDriver;
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->context = new CommunityContext();
        $this->context->set('community-a');
        $scope = new CommunityScope($this->context);
        $type = new EntityType(
            id: 'scoped_revisionable',
            label: 'Scoped revisionable',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
            tenancy: ['scope' => EntityType::TENANCY_SCOPE_COMMUNITY],
        );
        $schema = new SqlSchemaHandler($type, $this->database);
        $schema->ensureTable();
        $schema->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->database);
        $baseDriver = new SqlStorageDriver($resolver, communityScope: $scope);
        $this->revisionDriver = new RevisionableStorageDriver($resolver, $type, communityScope: $scope);
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event, ?string $eventName = null): object {
            $this->events[] = $eventName ?? $event::class;

            return $event;
        });
        $this->repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            $baseDriver,
            $dispatcher,
            $this->revisionDriver,
            $this->database,
        );

        $entity = new TestRevisionableEntity(values: [
            'id' => '1',
            'uuid' => 'scoped-a',
            'title' => 'Community A',
            'community_id' => 'community-a',
        ], entityTypeId: 'scoped_revisionable', entityKeys: [
            'id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id',
        ]);
        $entity->enforceIsNew();
        $this->repository->save($entity, validate: false);
        $this->events = [];
    }

    #[Test]
    public function foreignRevisionReadsAreIndistinguishableFromMissing(): void
    {
        $this->context->set('community-b');

        self::assertNull($this->repository->find('1'));
        self::assertNull($this->repository->loadRevision('1', 1));
        self::assertSame([], $this->repository->listRevisions('1'));
        self::assertNull($this->repository->loadWorkingCopy('1'));
        self::assertNull($this->revisionDriver->getLatestRevisionId('1'));
        self::assertSame([], $this->revisionDriver->getRevisionIds('1'));
    }

    #[Test]
    public function foreignRevisionMutationsFailBeforeEventsOrWrites(): void
    {
        $this->context->set('community-b');
        $before = $this->revisionRows();
        $foreign = new TestRevisionableEntity(values: [
            'id' => '1',
            'uuid' => 'scoped-a',
            'title' => 'Forged update',
            'community_id' => 'community-a',
        ], entityTypeId: 'scoped_revisionable', entityKeys: [
            'id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id',
        ]);

        try {
            $this->repository->save($foreign, validate: false);
            self::fail('A foreign save must be refused.');
        } catch (TenancyViolationException) {
        }

        self::assertSame([], $this->events);
        self::assertSame($before, $this->revisionRows());

        foreach ([
            fn() => $this->repository->delete($foreign),
            fn() => $this->repository->rollback('1', 1),
            fn() => $this->repository->setCurrentRevision('1', 1),
            fn() => $this->repository->setPublishedRevision('1', 1),
            fn() => $this->repository->pruneRevisions('1', RevisionPruningPolicy::keepLastUniform(0)),
            fn() => $this->revisionDriver->writeRevision('1', ['title' => 'forged'], null),
            fn() => $this->revisionDriver->updateRevision('1', 1, ['title' => 'forged']),
            fn() => $this->revisionDriver->deleteRevision('1', 1),
            fn() => $this->revisionDriver->deleteAllRevisions('1'),
            fn() => $this->revisionDriver->setCurrentLangcodeRevision('1', 'en', 1),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('A foreign revision mutation must be refused.');
            } catch (TenancyViolationException) {
            }
        }

        self::assertSame($before, $this->revisionRows());
    }

    #[Test]
    public function inactiveScopePreservesExistingRevisionBehavior(): void
    {
        $this->context->clear();

        self::assertNotNull($this->repository->loadRevision('1', 1));
        self::assertSame([1], $this->revisionDriver->getRevisionIds('1'));
    }

    #[Test]
    public function scopedDriverRefusesOrphanRevisionHistory(): void
    {
        $this->expectException(TenancyViolationException::class);

        $this->revisionDriver->writeRevision('404', ['community_id' => 'community-a'], null);
    }

    #[Test]
    public function foreignTranslationRevisionReadsAndPointersAreHidden(): void
    {
        $type = new EntityType(
            id: 'scoped_two_axis',
            label: 'Scoped two axis',
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
            tenancy: ['scope' => EntityType::TENANCY_SCOPE_COMMUNITY],
        );
        $schema = new SqlSchemaHandler($type, $this->database);
        $schema->ensureTable();
        $schema->ensureRevisionTable();
        $this->database->query(<<<'SQL'
            CREATE TABLE scoped_two_axis__translation__revision (
                entity_id TEXT NOT NULL,
                langcode TEXT NOT NULL,
                revision_id INTEGER NOT NULL,
                revision_created TEXT,
                revision_log TEXT,
                revision_author INTEGER,
                _data TEXT,
                PRIMARY KEY (entity_id, langcode, revision_id)
            )
            SQL);

        $scope = new CommunityScope($this->context);
        $resolver = new SingleConnectionResolver($this->database);
        $base = new SqlStorageDriver($resolver, communityScope: $scope);
        $driver = new RevisionableStorageDriver($resolver, $type, communityScope: $scope);
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event, ?string $eventName = null): object {
            $this->events[] = $eventName ?? $event::class;

            return $event;
        });
        $repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            $base,
            $dispatcher,
            $driver,
            $this->database,
        );
        $base->write('scoped_two_axis', '7', [
            'id' => 7,
            'uuid' => 'two-axis-a',
            'title' => 'Community A translation',
            'langcode' => 'en',
            'default_langcode' => 'en',
        ]);
        self::assertSame(1, $repository->backfillMutationAuthorities('tenancy two-axis fixture'));
        $expected = $repository->find('7')?->mutationToken();
        self::assertNotNull($expected);
        self::assertSame(1, $driver->writeRevision('7', ['title' => 'English'], null, 'en'));
        $driver->setCurrentLangcodeRevision('7', 'en', 1);
        self::assertSame(1, $repository->saveTranslation('7', 'oj', ['title' => 'Anishinaabemowin'], expected: $expected));
        $expected = $repository->find('7')?->mutationToken();
        self::assertNotNull($expected);
        self::assertSame(2, $repository->saveTranslation('7', 'oj', ['title' => 'Anishinaabemowin'], expected: $expected));
        $expected = $repository->find('7')?->mutationToken();
        self::assertNotNull($expected);
        self::assertSame('community-a', $this->peerCommunity('oj'));
        self::assertNotNull($repository->loadTranslation('7', 'oj'));
        self::assertContains(EntityEvents::REVISION_CREATED->value, $this->events);
        $this->events = [];
        $before = $this->translationRevisionRows();
        $beforePeer = $this->peerRow('oj');

        $this->context->set('community-b');

        self::assertNull($driver->readLangcodeRevision('7', 'en', 1));
        self::assertNull($driver->getLatestLangcodeRevisionId('7', 'en'));
        self::assertSame([], $driver->getLangcodeRevisionIds('7', 'en'));
        self::assertSame([], $driver->getLangcodesWithRevisions('7'));
        self::assertNull($driver->currentLangcodeRevision('7', 'en'));
        self::assertFalse($driver->hasCurrentLangcodeRevision('7', 'en'));
        self::assertNull($repository->loadTranslationRevision('7', 'en', 1));
        self::assertNull($repository->loadTranslation('7', 'oj'));
        self::assertSame([], $repository->translationLangcodes('7'));

        foreach ([
            fn() => $repository->saveTranslation('7', 'oj', ['title' => 'Forged'], expected: $expected),
            fn() => $repository->saveTranslationRevision('7', 'en', ['title' => 'Forged'], expected: $expected),
            fn() => $repository->saveTranslationRevisions('7', ['en' => ['title' => 'Forged']], expected: $expected),
            fn() => $base->writeLangcodePeer(
                'scoped_two_axis',
                '7',
                'oj',
                'en',
                ['title' => 'Forged', 'default_langcode' => 'en'],
            ),
            fn() => $driver->writeRevision('7', ['title' => 'Forged'], null, 'en'),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('A foreign translation revision mutation must be refused.');
            } catch (TenancyViolationException) {
            }
        }

        self::assertSame([], $this->events);
        self::assertSame($before, $this->translationRevisionRows());
        self::assertSame($beforePeer, $this->peerRow('oj'));
    }

    #[Test]
    public function ownerlessExactPeerRefusesBeforeEventsOrWrites(): void
    {
        [$repository, $base] = $this->scopedTwoAxisRepository();
        $expected = $repository->find('7')?->mutationToken();
        self::assertNotNull($expected);
        $this->database->insert('scoped_two_axis')
            ->fields(['id', 'uuid', 'title', 'langcode', 'default_langcode', 'community_id'])
            ->values(['7', 'two-axis-a', 'Legacy empty peer', 'oj', 'en', ''])
            ->execute();
        $beforePeer = $this->peerRow('oj');
        $beforeRevisions = $this->translationRevisionRows();
        $this->events = [];

        foreach ([
            fn() => $repository->saveTranslation('7', 'oj', ['title' => 'Must refuse'], expected: $expected),
            fn() => $base->writeLangcodePeer(
                'scoped_two_axis',
                '7',
                'oj',
                'en',
                ['title' => 'Must refuse', 'default_langcode' => 'en'],
            ),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('An ownerless exact peer mutation must be refused.');
            } catch (TenancyViolationException) {
            }
        }

        self::assertSame([], $this->events);
        self::assertSame($beforePeer, $this->peerRow('oj'));
        self::assertSame($beforeRevisions, $this->translationRevisionRows());
    }

    #[Test]
    public function ownedSiblingCannotSubstituteForCanonicalBaseOwnership(): void
    {
        [, $base] = $this->scopedTwoAxisRepository();
        $this->database->update('scoped_two_axis')
            ->fields(['community_id' => ''])
            ->condition('id', '7')
            ->condition('langcode', 'en')
            ->execute();
        $this->database->insert('scoped_two_axis')
            ->fields(['id', 'uuid', 'title', 'langcode', 'default_langcode', 'community_id'])
            ->values(['7', 'two-axis-a', 'Owned sibling', 'fr', 'en', 'community-a'])
            ->execute();

        foreach (['fr', 'oj'] as $langcode) {
            try {
                $base->writeLangcodePeer(
                    'scoped_two_axis',
                    '7',
                    $langcode,
                    'en',
                    ['title' => 'Must refuse', 'default_langcode' => 'en'],
                );
                self::fail('A sibling peer cannot substitute for canonical base ownership.');
            } catch (TenancyViolationException) {
            }
        }

        self::assertNull($this->peerRow('oj'));
        self::assertSame('Owned sibling', $this->peerRow('fr')['title'] ?? null);
    }

    /** @return array{EntityRepository, SqlStorageDriver} */
    private function scopedTwoAxisRepository(): array
    {
        $type = new EntityType(
            id: 'scoped_two_axis',
            label: 'Scoped two axis',
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
            tenancy: ['scope' => EntityType::TENANCY_SCOPE_COMMUNITY],
        );
        $schema = new SqlSchemaHandler($type, $this->database);
        $schema->ensureTable();
        $schema->ensureRevisionTable();
        $this->database->query(<<<'SQL'
            CREATE TABLE scoped_two_axis__translation__revision (
                entity_id TEXT NOT NULL,
                langcode TEXT NOT NULL,
                revision_id INTEGER NOT NULL,
                revision_created TEXT,
                revision_log TEXT,
                revision_author INTEGER,
                _data TEXT,
                PRIMARY KEY (entity_id, langcode, revision_id)
            )
            SQL);
        $scope = new CommunityScope($this->context);
        $resolver = new SingleConnectionResolver($this->database);
        $base = new SqlStorageDriver($resolver, communityScope: $scope);
        $driver = new RevisionableStorageDriver($resolver, $type, communityScope: $scope);
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event, ?string $eventName = null): object {
            $this->events[] = $eventName ?? $event::class;

            return $event;
        });
        $repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            $base,
            $dispatcher,
            $driver,
            $this->database,
        );
        $base->write('scoped_two_axis', '7', [
            'id' => 7,
            'uuid' => 'two-axis-a',
            'title' => 'Community A',
            'langcode' => 'en',
            'default_langcode' => 'en',
        ]);
        self::assertSame(1, $repository->backfillMutationAuthorities('tenancy two-axis fixture'));

        return [$repository, $base];
    }

    private function peerCommunity(string $langcode): ?string
    {
        foreach ($this->database->query(
            'SELECT community_id FROM scoped_two_axis WHERE id = ? AND langcode = ?',
            ['7', $langcode],
        ) as $row) {
            return (string) ((array) $row)['community_id'];
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function peerRow(string $langcode): ?array
    {
        foreach ($this->database->query(
            'SELECT * FROM scoped_two_axis WHERE id = ? AND langcode = ?',
            ['7', $langcode],
        ) as $row) {
            return (array) $row;
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function revisionRows(): array
    {
        $rows = [];
        foreach ($this->database->query('SELECT * FROM scoped_revisionable_revision ORDER BY revision_id') as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function translationRevisionRows(): array
    {
        $rows = [];
        foreach ($this->database->query(
            'SELECT * FROM scoped_two_axis__translation__revision ORDER BY entity_id, langcode, revision_id',
        ) as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }
}
