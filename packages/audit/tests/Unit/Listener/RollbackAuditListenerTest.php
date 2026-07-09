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

/**
 * `EntityRepository::rollback()` dispatches NO {@see \Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent}
 * (it creates a new revision — the event is reserved for pointer moves
 * WITHOUT one), so it was invisible to {@see \Waaseyaa\Audit\Listener\PublishPointerAuditListener}
 * entirely. `RollbackAuditListener` closes that gap (CW-v1 WP-2 task 2.5, #1920)
 * by correlating rollback's own two post-write dispatches — `REVISION_CREATED`
 * then `REVISION_REVERTED`, always back-to-back for the SAME entity+revision —
 * without double-recording `setCurrentRevision()` / `setPublishedRevision()`,
 * which dispatch `REVISION_REVERTED` alone (no preceding `REVISION_CREATED`)
 * and are already covered by `PublishPointerAuditListener`.
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
    public function rollback_records_a_revision_rollback_audit_entry(): void
    {
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $entity = $this->revision('node', '12', 5);
        $listener->onRevisionCreated(new EntityEvent($entity));
        $listener->onRevisionReverted(new EntityEvent($entity));

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::RevisionRollback, $recorded[0]->kind);
        $this->assertSame('allowed', $recorded[0]->outcome);
        $this->assertSame('node', $recorded[0]->entityTypeId);
        $this->assertSame('/entities/node/12', $recorded[0]->subjectUri);
        $this->assertSame('rollback', $recorded[0]->attributes['operation']);
        $this->assertSame('12', $recorded[0]->attributes['entity_id']);
        $this->assertSame(5, $recorded[0]->attributes['to_revision_id']);
    }

    #[Test]
    public function a_bare_revision_reverted_without_a_preceding_revision_created_is_not_recorded(): void
    {
        // setCurrentRevision()/setPublishedRevision() dispatch REVISION_REVERTED
        // alone — PublishPointerAuditListener already covers those via
        // RevisionPointerMovedEvent; this listener must stay silent for them.
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertCount(0, $recorded);
    }

    #[Test]
    public function an_ordinary_revision_created_without_a_following_reverted_is_not_recorded(): void
    {
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $listener->onRevisionCreated(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertCount(0, $recorded);
    }

    #[Test]
    public function a_mismatched_revision_reverted_is_not_treated_as_a_rollback(): void
    {
        // A normal save creates revision 5 for entity 12; a LATER, unrelated
        // setCurrentRevision() reverts entity 12 to a DIFFERENT revision (3).
        // The revision ids do not match, so this must not be misattributed.
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $listener->onRevisionCreated(new EntityEvent($this->revision('node', '12', 5)));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 3)));

        $this->assertCount(0, $recorded);
    }

    #[Test]
    public function the_correlation_window_is_consumed_after_one_check(): void
    {
        // A second, unrelated REVISION_REVERTED must not match a stale pending
        // REVISION_CREATED consumed by the first.
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $listener->onRevisionCreated(new EntityEvent($this->revision('node', '12', 5)));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));
        $listener->onRevisionReverted(new EntityEvent($this->revision('node', '12', 5)));

        $this->assertCount(1, $recorded);
    }

    #[Test]
    public function it_prefers_the_account_context_actor(): void
    {
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded), accountContext: $this->contextHolding(4));

        $entity = $this->revision('node', '12', 5);
        $listener->onRevisionCreated(new EntityEvent($entity));
        $listener->onRevisionReverted(new EntityEvent($entity));

        $this->assertSame(4, $recorded[0]->accountUid);
    }

    #[Test]
    public function it_records_null_actor_when_there_is_no_acting_context(): void
    {
        $recorded = [];
        $listener = new RollbackAuditListener($this->spyWriter($recorded));

        $entity = $this->revision('node', '12', 5);
        $listener->onRevisionCreated(new EntityEvent($entity));
        $listener->onRevisionReverted(new EntityEvent($entity));

        $this->assertNull($recorded[0]->accountUid);
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
        $entity = $this->revision('node', '12', 5);

        $listener->onRevisionCreated(new EntityEvent($entity));
        $listener->onRevisionReverted(new EntityEvent($entity));

        $this->assertTrue(true, 'Best-effort: no exception bubbled up');
    }

    #[Test]
    public function it_subscribes_to_revision_created_and_revision_reverted(): void
    {
        $subscribed = RollbackAuditListener::getSubscribedEvents();

        $this->assertArrayHasKey(EntityEvents::REVISION_CREATED->value, $subscribed);
        $this->assertArrayHasKey(EntityEvents::REVISION_REVERTED->value, $subscribed);
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
