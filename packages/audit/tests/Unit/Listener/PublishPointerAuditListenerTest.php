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
use Waaseyaa\Audit\Listener\PublishPointerAuditListener;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;

/**
 * FR-006 (#1645 S3): publish/revert pointer moves get their first-ever audit
 * rows — kind, transition payload, and three-state actor.
 */
#[CoversClass(PublishPointerAuditListener::class)]
final class PublishPointerAuditListenerTest extends TestCase
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
        $context->set(new PublishPointerListenerStubAccount($uid));

        return $context;
    }

    #[Test]
    public function it_records_revision_publish_with_the_transition_payload(): void
    {
        $recorded = [];
        $listener = new PublishPointerAuditListener($this->spyWriter($recorded));

        $listener->onPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '12',
            operation: 'publish',
            fromRevisionId: 3,
            toRevisionId: 5,
            actorUid: 7,
        ));

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::RevisionPublish, $recorded[0]->kind);
        $this->assertSame('allowed', $recorded[0]->outcome);
        $this->assertSame('node', $recorded[0]->entityTypeId);
        $this->assertSame('/entities/node/12', $recorded[0]->subjectUri);
        $this->assertSame([
            'entity_id'        => '12',
            'operation'        => 'publish',
            'from_revision_id' => 3,
            'to_revision_id'   => 5,
        ], $recorded[0]->attributes);
    }

    #[Test]
    public function it_records_revision_revert_for_the_revert_operation(): void
    {
        $recorded = [];
        $listener = new PublishPointerAuditListener($this->spyWriter($recorded));

        $listener->onPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '12',
            operation: 'revert',
            fromRevisionId: 5,
            toRevisionId: 3,
            actorUid: 7,
        ));

        $this->assertSame(AuditEventKind::RevisionRevert, $recorded[0]->kind);
        $this->assertSame('revert', $recorded[0]->attributes['operation']);
    }

    #[Test]
    public function it_carries_a_null_from_revision_as_null(): void
    {
        // First-ever publish: no prior published pointer.
        $recorded = [];
        $listener = new PublishPointerAuditListener($this->spyWriter($recorded));

        $listener->onPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '12',
            operation: 'publish',
            fromRevisionId: null,
            toRevisionId: 1,
        ));

        $this->assertNull($recorded[0]->attributes['from_revision_id']);
        $this->assertSame(1, $recorded[0]->attributes['to_revision_id']);
    }

    #[Test]
    public function it_prefers_the_event_actor_over_the_context(): void
    {
        $recorded = [];
        $listener = new PublishPointerAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(99),
        );

        $listener->onPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: null,
            toRevisionId: 1,
            actorUid: 7,
        ));

        $this->assertSame(7, $recorded[0]->accountUid);
    }

    #[Test]
    public function it_keeps_an_anonymous_zero_event_actor_without_falling_through(): void
    {
        $recorded = [];
        $listener = new PublishPointerAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(99),
        );

        $listener->onPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: null,
            toRevisionId: 1,
            actorUid: 0,
        ));

        $this->assertSame(0, $recorded[0]->accountUid, '0 means the anonymous account acted — not a fall-through trigger');
    }

    #[Test]
    public function it_falls_back_to_the_account_context_when_the_event_actor_is_null(): void
    {
        $recorded = [];
        $listener = new PublishPointerAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(4),
        );

        $listener->onPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '1',
            operation: 'revert',
            fromRevisionId: 2,
            toRevisionId: 1,
        ));

        $this->assertSame(4, $recorded[0]->accountUid);
    }

    #[Test]
    public function it_records_null_actor_when_neither_event_nor_context_carries_one(): void
    {
        $recorded = [];
        $listener = new PublishPointerAuditListener($this->spyWriter($recorded));

        $listener->onPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: null,
            toRevisionId: 1,
        ));

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

        $listener = new PublishPointerAuditListener($writer);

        // Must not throw — a broken audit path never disrupts the publish.
        $listener->onPointerMoved(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '1',
            operation: 'publish',
            fromRevisionId: null,
            toRevisionId: 1,
        ));

        $this->assertTrue(true, 'Best-effort: no exception bubbled up');
    }

    #[Test]
    public function it_subscribes_to_the_revision_pointer_moved_event(): void
    {
        $subscribed = PublishPointerAuditListener::getSubscribedEvents();

        $this->assertArrayHasKey(RevisionPointerMovedEvent::class, $subscribed);
    }
}

/** Minimal account stub: id-only. */
final class PublishPointerListenerStubAccount implements AccountInterface
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
