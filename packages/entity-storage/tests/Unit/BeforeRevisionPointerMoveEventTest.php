<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * CW-v1 WP-2 task 2.4 (#1920) — the bypass choke point. Every revision
 * POINTER-MOVE path (`rollback()`, `setCurrentRevision()`,
 * `setPublishedRevision()`, and the `saveTranslationRevision()` /
 * `saveTranslationRevisions()` / `saveTranslation()` trio) must dispatch
 * {@see BeforeRevisionPointerMoveEvent} BEFORE any write, and a subscriber
 * that throws {@see AbortOperationException} must leave storage completely
 * untouched.
 */
#[CoversClass(EntityRepository::class)]
#[CoversClass(BeforeRevisionPointerMoveEvent::class)]
final class BeforeRevisionPointerMoveEventTest extends TestCase
{
    private DBALDatabase $db;
    private EventDispatcher $dispatcher;
    private RequestAccountContext $accountContext;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->dispatcher = new EventDispatcher();
        $this->accountContext = new RequestAccountContext();
        $this->accountContext->set(new class implements AccountInterface {
            public function id(): int|string
            {
                return 7;
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
                return true;
            }
        });
    }

    private function buildSingleAxisRepo(): EntityRepository
    {
        $entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

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
        $repo->setAccountContext($this->accountContext);

        return $repo;
    }

    private function buildTwoAxisRepo(): EntityRepository
    {
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

    // ------------------------------------------------------------------
    // rollback()
    // ------------------------------------------------------------------

    #[Test]
    public function rollback_dispatches_before_event_with_rollback_operation(): void
    {
        $repo = $this->buildSingleAxisRepo();
        $captured = [];
        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event) use (&$captured): void {
                $captured[] = $event;
            },
        );

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity);

        $repo->rollback('1', 1, $this->mutationToken($repo, '1'));

        $this->assertCount(1, $captured);
        $event = $captured[0];
        $this->assertSame('rollback', $event->operation);
        $this->assertSame('test_revisionable', $event->entityTypeId);
        $this->assertSame('1', $event->entityId);
        $this->assertSame(2, $event->fromRevisionId, 'prior current/tip revision pointer');
        $this->assertNull($event->toRevisionId, 'new revision id is assigned by the write, not knowable yet');
        $this->assertSame(7, $event->actorUid);
        $this->assertSame('v1', $event->revisionValues['title'], 'the TARGET revision (1) being rolled back to');
    }

    #[Test]
    public function rollback_abort_from_subscriber_leaves_storage_unchanged(): void
    {
        $repo = $this->buildSingleAxisRepo();
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity);

        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event): void {
                throw new AbortOperationException('rollback refused');
            },
        );

        try {
            $repo->rollback('1', 1, $this->mutationToken($repo, '1'));
            $this->fail('AbortOperationException should have propagated out of rollback().');
        } catch (AbortOperationException $e) {
            $this->assertSame('rollback refused', $e->reason);
        }

        $this->assertCount(2, $repo->listRevisions('1'), 'no third revision was created');
        $this->assertSame('v2', $repo->find('1')->label(), 'the tip is unchanged');
    }

    // ------------------------------------------------------------------
    // setCurrentRevision()
    // ------------------------------------------------------------------

    #[Test]
    public function set_current_revision_dispatches_before_event_with_revert_operation(): void
    {
        $repo = $this->buildSingleAxisRepo();
        $captured = [];
        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event) use (&$captured): void {
                $captured[] = $event;
            },
        );

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity);

        $repo->setCurrentRevision('1', 1, $this->mutationToken($repo, '1'));

        $this->assertCount(1, $captured);
        $event = $captured[0];
        $this->assertSame('revert', $event->operation);
        $this->assertSame(2, $event->fromRevisionId, 'prior base revision_id pointer');
        $this->assertSame(1, $event->toRevisionId, 'target revision id is known up front');
        $this->assertSame(7, $event->actorUid);
        $this->assertSame('v1', $event->revisionValues['title']);
    }

    #[Test]
    public function set_current_revision_abort_leaves_pointer_unchanged(): void
    {
        $repo = $this->buildSingleAxisRepo();
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity);

        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event): void {
                throw new AbortOperationException('revert refused');
            },
        );

        try {
            $repo->setCurrentRevision('1', 1, $this->mutationToken($repo, '1'));
            $this->fail('AbortOperationException should have propagated out of setCurrentRevision().');
        } catch (AbortOperationException $e) {
            $this->assertSame('revert refused', $e->reason);
        }

        $this->assertSame('v2', $repo->find('1')->label(), 'the base-row pointer never moved');
    }

    // ------------------------------------------------------------------
    // setPublishedRevision()
    // ------------------------------------------------------------------

    #[Test]
    public function set_published_revision_dispatches_before_event_with_publish_operation(): void
    {
        $repo = $this->buildSingleAxisRepo();
        $captured = [];
        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event) use (&$captured): void {
                $captured[] = $event;
            },
        );

        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity);

        $repo->setPublishedRevision('1', 1, $this->mutationToken($repo, '1'));

        $this->assertCount(1, $captured);
        $event = $captured[0];
        $this->assertSame('publish', $event->operation);
        $this->assertNull($event->fromRevisionId, 'previously unpublished');
        $this->assertSame(1, $event->toRevisionId);
        $this->assertSame(7, $event->actorUid);
        $this->assertSame('v1', $event->revisionValues['title']);

        // Republish a different revision: fromRevisionId now reflects the
        // prior published pointer.
        $captured = [];
        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event) use (&$captured): void {
                $captured[] = $event;
            },
        );
        $repo->setPublishedRevision('1', 2, $this->mutationToken($repo, '1'));
        $this->assertSame(1, $captured[0]->fromRevisionId);
        $this->assertSame(2, $captured[0]->toRevisionId);
    }

    #[Test]
    public function set_published_revision_abort_leaves_published_pointer_unchanged(): void
    {
        $repo = $this->buildSingleAxisRepo();
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);

        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event): void {
                throw new AbortOperationException('publish refused');
            },
        );

        try {
            $repo->setPublishedRevision('1', 1, $this->mutationToken($repo, '1'));
            $this->fail('AbortOperationException should have propagated out of setPublishedRevision().');
        } catch (AbortOperationException $e) {
            $this->assertSame('publish refused', $e->reason);
        }

        $this->assertNull($repo->loadPublishedRevision('1'), 'the published pointer never moved');
    }

    // ------------------------------------------------------------------
    // saveTranslationRevision()
    // ------------------------------------------------------------------

    #[Test]
    public function save_translation_revision_dispatches_before_event_with_translation_save_operation(): void
    {
        $repo = $this->buildTwoAxisRepo();
        $this->seedTwoAxisAggregate($repo);
        $captured = [];
        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event) use (&$captured): void {
                $captured[] = $event;
            },
        );

        $repo->saveTranslationRevision('1', 'fr', ['title' => 'Bonjour'], expected: $this->mutationToken($repo, '1'));

        $this->assertCount(1, $captured);
        $event = $captured[0];
        $this->assertSame('translation_save', $event->operation);
        $this->assertSame('1', $event->entityId);
        $this->assertNull($event->fromRevisionId, 'no prior revision for this langcode');
        $this->assertNull($event->toRevisionId, 'assigned by the write');
        $this->assertSame(7, $event->actorUid);
        $this->assertSame('Bonjour', $event->revisionValues['title']);

        // A second save for the same language reports the prior tip.
        $captured = [];
        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event) use (&$captured): void {
                $captured[] = $event;
            },
        );
        $repo->saveTranslationRevision('1', 'fr', ['title' => 'Bonjour v2'], expected: $this->mutationToken($repo, '1'));
        $this->assertSame(1, $captured[0]->fromRevisionId);
    }

    #[Test]
    public function save_translation_revision_abort_prevents_the_write(): void
    {
        $repo = $this->buildTwoAxisRepo();
        $this->seedTwoAxisAggregate($repo);

        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event): void {
                throw new AbortOperationException('translation save refused');
            },
        );

        try {
            $repo->saveTranslationRevision('1', 'fr', ['title' => 'Bonjour'], expected: $this->mutationToken($repo, '1'));
            $this->fail('AbortOperationException should have propagated out of saveTranslationRevision().');
        } catch (AbortOperationException $e) {
            $this->assertSame('translation save refused', $e->reason);
        }

        $this->assertNull($repo->loadTranslationTip('1', 'fr'), 'no revision was written');
    }

    // ------------------------------------------------------------------
    // saveTranslationRevisions()
    // ------------------------------------------------------------------

    #[Test]
    public function save_translation_revisions_dispatches_one_before_event_per_langcode(): void
    {
        $repo = $this->buildTwoAxisRepo();
        $this->seedTwoAxisAggregate($repo);
        $captured = [];
        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event) use (&$captured): void {
                $captured[] = $event;
            },
        );

        $repo->saveTranslationRevisions('1', [
            'en' => ['title' => 'Hello'],
            'fr' => ['title' => 'Bonjour'],
        ], expected: $this->mutationToken($repo, '1'));

        $this->assertCount(2, $captured);
        $this->assertSame('translation_save', $captured[0]->operation);
        $this->assertSame('translation_save', $captured[1]->operation);
        $this->assertSame('Hello', $captured[0]->revisionValues['title']);
        $this->assertSame('Bonjour', $captured[1]->revisionValues['title']);
        $this->assertSame(7, $captured[0]->actorUid);
    }

    #[Test]
    public function save_translation_revisions_abort_on_second_langcode_rolls_back_whole_batch(): void
    {
        $repo = $this->buildTwoAxisRepo();
        $this->seedTwoAxisAggregate($repo);

        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event): void {
                if ($event->revisionValues['title'] === 'Bonjour') {
                    throw new AbortOperationException('fr refused');
                }
            },
        );

        try {
            $repo->saveTranslationRevisions('1', [
                'en' => ['title' => 'Hello'],
                'fr' => ['title' => 'Bonjour'],
            ], expected: $this->mutationToken($repo, '1'));
            $this->fail('AbortOperationException should have propagated out of saveTranslationRevisions().');
        } catch (AbortOperationException $e) {
            $this->assertSame('fr refused', $e->reason);
        }

        $this->assertNull($repo->loadTranslationTip('1', 'en'), 'the WHOLE batch rolled back, including the first language');
        $this->assertNull($repo->loadTranslationTip('1', 'fr'));
    }

    // ------------------------------------------------------------------
    // saveTranslation()
    // ------------------------------------------------------------------

    #[Test]
    public function save_translation_dispatches_before_event_with_translation_save_operation(): void
    {
        $repo = $this->buildTwoAxisRepo();
        $entity = new TestRevisionableEntity(values: [
            'id' => '1', 'uuid' => 'a', 'title' => 'Hello',
            'langcode' => 'en', 'default_langcode' => 'en',
        ]);
        $entity->enforceIsNew();
        $repo->save($entity);

        $captured = [];
        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event) use (&$captured): void {
                $captured[] = $event;
            },
        );

        $repo->saveTranslation('1', 'fr', ['title' => 'Bonjour'], expected: $this->mutationToken($repo, '1'));

        $this->assertCount(1, $captured);
        $event = $captured[0];
        $this->assertSame('translation_save', $event->operation);
        $this->assertNull($event->fromRevisionId);
        $this->assertNull($event->toRevisionId);
        $this->assertSame(7, $event->actorUid);
        $this->assertSame('Bonjour', $event->revisionValues['title']);
    }

    #[Test]
    public function save_translation_abort_prevents_peer_row_and_revision_write(): void
    {
        $repo = $this->buildTwoAxisRepo();
        $entity = new TestRevisionableEntity(values: [
            'id' => '1', 'uuid' => 'a', 'title' => 'Hello',
            'langcode' => 'en', 'default_langcode' => 'en',
        ]);
        $entity->enforceIsNew();
        $repo->save($entity);

        $this->dispatcher->addListener(
            BeforeRevisionPointerMoveEvent::class,
            function (BeforeRevisionPointerMoveEvent $event): void {
                throw new AbortOperationException('translation save refused');
            },
        );

        try {
            $repo->saveTranslation('1', 'fr', ['title' => 'Bonjour'], expected: $this->mutationToken($repo, '1'));
            $this->fail('AbortOperationException should have propagated out of saveTranslation().');
        } catch (AbortOperationException $e) {
            $this->assertSame('translation save refused', $e->reason);
        }

        $this->assertNull($repo->loadTranslation('1', 'fr'), 'no peer row was created');
    }

    private function mutationToken(EntityRepository $repository, string $entityId): \Waaseyaa\Entity\Concurrency\EntityMutationToken
    {
        $token = $repository->find($entityId)?->mutationToken();
        self::assertNotNull($token);

        return $token;
    }

    private function seedTwoAxisAggregate(EntityRepository $repository): void
    {
        $entity = new TestRevisionableEntity(values: [
            'id' => '1',
            'uuid' => 'translation-authority',
            'title' => 'Hello',
            'langcode' => 'en',
            'default_langcode' => 'en',
        ]);
        $entity->enforceIsNew();
        $repository->save($entity);
    }
}
