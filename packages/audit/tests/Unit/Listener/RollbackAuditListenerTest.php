<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\RollbackAuditListener;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionMetadata;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;

/**
 * `EntityRepository::rollback()` dispatches NO {@see \Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent}
 * (it creates a new revision — that event is reserved for pointer moves
 * WITHOUT one), so it was invisible to {@see \Waaseyaa\Audit\Listener\PublishPointerAuditListener}
 * entirely. `RollbackAuditListener` closes that gap (CW-v1 WP-2 task 2.5,
 * #1920) by ARMING on `BeforeRevisionPointerMoveEvent(operation: 'rollback')`
 * — dispatched pre-write by exactly one code path, `rollback()` itself — and
 * consuming the armed slot on the following `REVISION_REVERTED` for the same
 * entity. The critical non-fabrication cases (task 2.5 review): an ordinary
 * save's `REVISION_CREATED` followed by a legitimate publish/revert of that
 * SAME revision must NOT produce a rollback record, and an aborted rollback's
 * stale arm must not correlate with a later unrelated revert.
 */
#[CoversClass(RollbackAuditListener::class)]
final class RollbackAuditListenerTest extends TestCase
{
    /**
     * @param list<AuditEventDescriptor> $recorded
     */
    private function spyWriter(array &$recorded): AuditWriterInterface
    {
        return new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };
    }

    private function contextHolding(int $uid): AccountContextInterface
    {
        $context = new RequestAccountContext();
        $context->set(new RollbackListenerStubAccount($uid));

        return $context;
    }

    /**
     * @param 'rollback'|'revert'|'publish'|'translation_save' $operation
     */
    private function preMoveEvent(
        string $operation,
        string $entityTypeId = 'node',
        string $entityId = '12',
        ?int $fromRevisionId = 4,
        ?int $toRevisionId = null,
        ?int $actorUid = null,
        ?int $sourceRevisionId = null,
    ): BeforeRevisionPointerMoveEvent {
        return new BeforeRevisionPointerMoveEvent(
            entityTypeId: $entityTypeId,
            entityId: $entityId,
            operation: $operation,
            fromRevisionId: $fromRevisionId,
            toRevisionId: $toRevisionId,
            actorUid: $actorUid,
            revisionValues: ['title' => 'Fixture'],
            sourceRevisionId: $sourceRevisionId,
        );
    }

    private function revision(string $entityTypeId, string $entityId, int $revisionId): RevisionableEntityInterface
    {
        return new class ($entityTypeId, $entityId, $revisionId) implements RevisionableEntityInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly string $entityId,
                private readonly int $revisionId,
            ) {}

            public function id(): int|string|null { return $this->entityId; }
            public function uuid(): string { return 'u-1'; }
            public function label(): string { return 'Fixture'; }
            public function getEntityTypeId(): string { return $this->entityTypeId; }
            public function bundle(): string { return 'article'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return null; }
            public function set(string $name, mixed $value): static { return $this; }
            public function toArray(): array { return []; }
            public function language(): string { return 'en'; }
            public function revisionId(): int|string|null { return $this->revisionId; }
            public function isCurrentRevision(): bool { return true; }
            public function revisionMetadata(): ?RevisionMetadata { return null; }
        };
    }

    #[Test]
    public function a_real_rollback_flow_records_exactly_one_rollback_audit_entry(): void
    {
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        // rollback(): pre-event (operation 'rollback', new revision id not
        // yet knowable) → write commits → REVISION_REVERTED with the NEW
        // revision (5).
        $listener->onBeforePointerMove($this->preMoveEvent('rollback', fromRevisionId: 4, actorUid: 7, sourceRevisionId: 2));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::RevisionRollback, $recorded[0]->kind);
        $this->assertSame('allowed', $recorded[0]->outcome);
        $this->assertSame('node', $recorded[0]->entityTypeId);
        $this->assertSame('/entities/node/12', $recorded[0]->subjectUri);
        $this->assertSame(7, $recorded[0]->accountUid);
        $this->assertSame([
            'entity_id'        => '12',
            'operation'        => 'rollback',
            'from_revision_id' => 4,
            'source_revision_id' => 2,
            'to_revision_id'   => 5,
        ], $recorded[0]->attributes);
    }

    #[Test]
    public function an_ordinary_save_followed_by_a_publish_of_the_same_revision_records_no_rollback(): void
    {
        // THE task 2.5 review scenario (forward-draft publish, task 2.6):
        // an ordinary save creates revision 5 (REVISION_CREATED fires — this
        // listener does not subscribe to it); later setPublishedRevision(12, 5)
        // promotes that SAME revision: pre-event 'publish' then
        // REVISION_REVERTED. Must record nothing — PublishPointerAuditListener
        // already covers the publish via RevisionPointerMovedEvent.
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $listener->onBeforePointerMove($this->preMoveEvent('publish', fromRevisionId: null, toRevisionId: 5));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertCount(0, $recorded);
    }

    #[Test]
    public function a_revert_pointer_move_records_no_rollback(): void
    {
        // setCurrentRevision(): pre-event 'revert' then REVISION_REVERTED —
        // already covered by PublishPointerAuditListener, must stay silent here.
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $listener->onBeforePointerMove($this->preMoveEvent('revert', fromRevisionId: 5, toRevisionId: 3));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 3)));

        $this->assertCount(0, $recorded);
    }

    #[Test]
    public function an_aborted_rollback_followed_by_a_legitimate_revert_records_nothing(): void
    {
        // The rollback pre-event armed the slot, but a guard subscriber threw
        // — no write, no REVISION_REVERTED ever arrives for it. The LATER
        // legitimate setCurrentRevision() dispatches its own pre-event
        // ('revert'), which must clear the stale arm before its
        // REVISION_REVERTED — no spurious rollback record.
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $listener->onBeforePointerMove($this->preMoveEvent('rollback'));
        // (aborted: no REVISION_REVERTED)
        $listener->onBeforePointerMove($this->preMoveEvent('revert', fromRevisionId: 5, toRevisionId: 3));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 3)));

        $this->assertCount(0, $recorded);
    }

    #[Test]
    public function a_bare_revision_reverted_with_no_armed_rollback_is_not_recorded(): void
    {
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertCount(0, $recorded);
    }

    #[Test]
    public function an_armed_rollback_is_never_recorded_against_a_different_entity(): void
    {
        // Defensive identity check: cannot happen through EntityRepository
        // today (every pointer path re-sets the slot via its own pre-event),
        // but a mismatched subject must never be recorded.
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $listener->onBeforePointerMove($this->preMoveEvent('rollback', entityId: '12'));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '99', 5)));

        $this->assertCount(0, $recorded);
    }

    #[Test]
    public function the_armed_slot_is_consumed_after_one_revert(): void
    {
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $listener->onBeforePointerMove($this->preMoveEvent('rollback'));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));
        // A second REVISION_REVERTED (e.g. from a later revert whose
        // pre-event somehow did not reach this listener) must not re-match.
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertCount(1, $recorded);
    }

    #[Test]
    public function it_prefers_the_pre_event_actor_over_the_context(): void
    {
        $recorded = [];
        $listener = new RollbackAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(99),
        );

        $listener->onBeforePointerMove($this->preMoveEvent('rollback', actorUid: 7));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertSame(7, $recorded[0]->accountUid);
    }

    #[Test]
    public function it_keeps_an_anonymous_zero_pre_event_actor_without_falling_through(): void
    {
        $recorded = [];
        $listener = new RollbackAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(99),
        );

        $listener->onBeforePointerMove($this->preMoveEvent('rollback', actorUid: 0));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertSame(0, $recorded[0]->accountUid, '0 means the anonymous account acted — not a fall-through trigger');
    }

    #[Test]
    public function it_falls_back_to_the_account_context_when_the_pre_event_actor_is_null(): void
    {
        $recorded = [];
        $listener = new RollbackAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(4),
        );

        $listener->onBeforePointerMove($this->preMoveEvent('rollback', actorUid: null));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertSame(4, $recorded[0]->accountUid);
    }

    #[Test]
    public function it_records_null_actor_when_neither_pre_event_nor_context_carries_one(): void
    {
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $listener->onBeforePointerMove($this->preMoveEvent('rollback', actorUid: null));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertNull($recorded[0]->accountUid, 'No acting context must be null — never 0');
    }

    #[Test]
    public function it_swallows_writer_failures_best_effort(): void
    {
        $writer = new class implements AuditWriterInterface {
            public function record(AuditEventDescriptor $d): void
            {
                throw new \RuntimeException('Writer broken — best-effort should swallow');
            }
        };

        $listener = new RollbackAuditListener($writer);

        // Must not throw — a broken audit path never disrupts the rollback.
        $listener->onBeforePointerMove($this->preMoveEvent('rollback'));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertTrue(true, 'Best-effort: no exception bubbled up');
    }

    #[Test]
    public function it_subscribes_to_the_pre_pointer_move_event_and_revision_reverted(): void
    {
        $subscribed = RollbackAuditListener::getSubscribedEvents();

        $this->assertArrayHasKey(BeforeRevisionPointerMoveEvent::class, $subscribed);
        $this->assertArrayHasKey(EntityEvents::REVISION_REVERTED->value, $subscribed);
        $this->assertArrayNotHasKey(
            EntityEvents::REVISION_CREATED->value,
            $subscribed,
            'REVISION_CREATED is ambiguous (every revision-creating save fires it) — the arm signal is the rollback pre-event, never event pairing.',
        );
    }
}

/** Minimal account stub: id-only. */
final class RollbackListenerStubAccount implements AccountInterface
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
