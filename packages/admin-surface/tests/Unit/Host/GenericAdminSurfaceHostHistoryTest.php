<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionMetadata;

/**
 * Per-record history (#2419).
 *
 * The admin surface had no way to answer "show me the history of THIS record":
 * `get()` is the record editor and the pipeline answers for the entity type.
 * This action is the read side of the addressable history surface.
 *
 * Two properties matter beyond "it returns rows":
 *
 * - It is gated by the *record's own* view access, and a principal who may not
 *   view the record gets no surface rather than an empty one.
 * - It returns revision **metadata only** — never field values. History must
 *   not become a side channel that reveals field content the record's own
 *   field-access rules would withhold.
 */
#[CoversClass(GenericAdminSurfaceHost::class)]
final class GenericAdminSurfaceHostHistoryTest extends TestCase
{
    #[Test]
    public function history_lists_revisions_newest_first_with_actors_and_timestamps(): void
    {
        $result = $this->runHistory(accountId: 99);

        self::assertTrue($result->ok, json_encode($result->error));
        self::assertSame([
            [
                'revisionId' => 3,
                'createdAt' => '2026-03-03T00:00:00+00:00',
                'author' => 7,
                'log' => 'Third pass',
                'isCurrent' => false,
                'isLatest' => true,
            ],
            [
                'revisionId' => 2,
                'createdAt' => '2026-02-02T00:00:00+00:00',
                'author' => null,
                'log' => null,
                'isCurrent' => false,
                'isLatest' => false,
            ],
            [
                'revisionId' => 1,
                'createdAt' => '2026-01-01T00:00:00+00:00',
                'author' => 0,
                'log' => 'Created',
                'isCurrent' => true,
                'isLatest' => false,
            ],
        ], $result->data['revisions']);
    }

    /**
     * `revision_author` is NULL for rows written without an acting context and
     * 0 if and only if the anonymous account acted. Collapsing null to 0 would
     * attribute an unattributed revision to a real account.
     */
    #[Test]
    public function an_unattributed_revision_is_not_reported_as_anonymous(): void
    {
        $revisions = $this->runHistory(accountId: 99)->data['revisions'];

        self::assertNull($revisions[1]['author'], 'A NULL revision author must stay null.');
        self::assertSame(0, $revisions[2]['author'], 'The anonymous account must stay 0.');
    }

    #[Test]
    public function history_identifies_the_record_it_answers_for(): void
    {
        $result = $this->runHistory(accountId: 99);

        self::assertSame('article', $result->data['entityType']);
        self::assertSame('1', $result->data['entityId']);
    }

    /**
     * The whole point of the surface: metadata, not content. A revision row
     * carries the record's field values, and echoing them here would bypass
     * the field-access rules the record's own read path enforces.
     */
    #[Test]
    public function history_carries_no_field_values(): void
    {
        $encoded = json_encode($this->runHistory(accountId: 99)->data);

        self::assertIsString($encoded);
        self::assertStringNotContainsString('Third pass title', $encoded);
        self::assertStringNotContainsString('attributes', $encoded);
    }

    #[Test]
    public function a_principal_who_may_not_view_the_record_gets_no_history_surface(): void
    {
        $result = $this->runHistory(accountId: 1);

        self::assertFalse($result->ok);
        self::assertSame(403, $result->error['status']);
        self::assertNull($result->data);
    }

    #[Test]
    public function a_missing_record_has_no_history(): void
    {
        $result = $this->host(revisions: [], entity: null)->action('article', 'history', ['id' => '404']);

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
    }

    #[Test]
    public function an_unknown_entity_type_has_no_history(): void
    {
        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(false);

        $result = new GenericAdminSurfaceHost($etm)->action('nope', 'history', ['id' => '1']);

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
    }

    #[Test]
    public function a_request_without_a_record_id_is_refused(): void
    {
        $result = $this->host(revisions: [], entity: $this->revision(1, 'Only', null))
            ->action('article', 'history', []);

        self::assertFalse($result->ok);
        self::assertSame(400, $result->error['status']);
    }

    /**
     * A non-revisionable type has no history surface at all — the repository
     * raises rather than returning an empty list, and that must not reach the
     * client as a 500.
     */
    #[Test]
    public function a_non_revisionable_type_has_no_history_surface(): void
    {
        $entity = $this->revision(1, 'Plain', null);

        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($entity);
        $repository->method('listRevisions')->willThrowException(new \LogicException('Revision driver not configured'));

        $result = $this->hostWith($repository)->action('article', 'history', ['id' => '1']);

        self::assertFalse($result->ok);
        self::assertSame(404, $result->error['status']);
    }

    private function runHistory(int $accountId): AdminSurfaceResultData
    {
        $revisions = [
            // A forward draft is in flight: the tip (3) is NOT the published
            // revision (1). History must distinguish them.
            $this->revision(3, 'Third pass title', new RevisionMetadata(new \DateTimeImmutable('2026-03-03T00:00:00+00:00'), 7, 'Third pass'), isCurrent: false, isLatest: true),
            $this->revision(2, 'Second title', new RevisionMetadata(new \DateTimeImmutable('2026-02-02T00:00:00+00:00'), null, null)),
            $this->revision(1, 'First title', new RevisionMetadata(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), 0, 'Created'), isCurrent: true),
        ];

        return $this->host($revisions, $this->revision(3, 'Third pass title', null))
            ->action('article', 'history', ['id' => '1'], $accountId);
    }

    /**
     * @param list<EntityInterface> $revisions
     */
    private function host(array $revisions, ?EntityInterface $entity): GenericAdminSurfaceHostHistoryHarness
    {
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('find')->willReturn($entity);
        $repository->method('listRevisions')->willReturn($revisions);

        return $this->hostWith($repository);
    }

    private function hostWith(EntityRepositoryInterface $repository): GenericAdminSurfaceHostHistoryHarness
    {
        $etm = $this->createStub(EntityTypeManagerInterface::class);
        $etm->method('hasDefinition')->willReturn(true);
        $etm->method('getDefinition')->willReturn(new EntityType(id: 'article', label: 'Article', class: \stdClass::class, keys: ['id' => 'id', 'label' => 'title']));
        $etm->method('getRepository')->willReturn($repository);

        return new GenericAdminSurfaceHostHistoryHarness(
            new GenericAdminSurfaceHost($etm, new EntityAccessHandler([$this->viewGatedPolicy(viewerId: 99)])),
        );
    }

    private function revision(int $revisionId, string $title, ?RevisionMetadata $metadata, bool $isCurrent = false, bool $isLatest = false): EntityInterface
    {
        $entity = new TestEntity(['id' => 1, 'uuid' => 'u-1', 'title' => $title], 'article');
        $entity->enforceIsNew(false);
        // The same structural hydration the repository performs when loading a
        // historical revision, so the fixture cannot pass by a route production
        // does not take.
        $entity->_hydrateStructuralRevision($revisionId, tip: $isLatest, default: $isCurrent);
        $entity->setRevisionMetadata($metadata);

        return $entity;
    }

    private function viewGatedPolicy(int $viewerId): AccessPolicyInterface
    {
        return new class ($viewerId) implements AccessPolicyInterface {
            public function __construct(private readonly int $viewerId) {}

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return $account->id() === $this->viewerId
                    ? AccessResult::allowed('viewer')
                    : AccessResult::forbidden('not a viewer');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::allowed();
            }
        };
    }
}

/** Binds a session before dispatching, so each test states the acting account. */
final class GenericAdminSurfaceHostHistoryHarness
{
    public function __construct(private readonly GenericAdminSurfaceHost $host) {}

    /** @param array<string, mixed> $payload */
    public function action(string $type, string $action, array $payload, int $accountId = 99): AdminSurfaceResultData
    {
        $request = Request::create('/admin/_surface/session');
        $request->attributes->set('_account', new AuthorizationPrincipal($accountId, true, ['administrator'], [], 'test'));
        $this->host->resolveSession($request);

        return $this->host->action($type, $action, $payload);
    }
}
