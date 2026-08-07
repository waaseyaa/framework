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
use Waaseyaa\Audit\Entity\AuditEvent;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Listener\EntityLifecycleAuditListener;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;

#[CoversClass(EntityLifecycleAuditListener::class)]
final class EntityLifecycleAuditListenerTest extends TestCase
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
        $context->set(new LifecycleListenerStubAccount($uid));

        return $context;
    }
    #[Test]
    public function it_records_entity_write_on_post_save(): void
    {
        $recorded = [];
        $writer = new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };

        $entity = $this->createStub(EntityInterface::class);
        $entity->method('isNew')->willReturn(true);
        $entity->method('getEntityTypeId')->willReturn('note');
        $entity->method('id')->willReturn('1');
        $entity->method('uuid')->willReturn('00000000-0000-0000-0000-000000000001');

        $listener = new EntityLifecycleAuditListener($writer);

        // PRE_SAVE captures isNew.
        $listener->onPreSave(new EntityEvent($entity));
        // POST_SAVE records.
        $listener->onPostSave(new EntityEvent($entity));

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::EntityWrite, $recorded[0]->kind);
        $this->assertSame('allowed', $recorded[0]->outcome);
    }

    #[Test]
    public function it_records_entity_delete_on_post_delete(): void
    {
        $recorded = [];
        $writer = new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };

        $entity = $this->createStub(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('note');
        $entity->method('id')->willReturn('1');
        $entity->method('uuid')->willReturn('00000000-0000-0000-0000-000000000002');

        $listener = new EntityLifecycleAuditListener($writer);
        $listener->onPostDelete(new EntityEvent($entity));

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::EntityDelete, $recorded[0]->kind);
    }

    #[Test]
    public function it_does_not_bubble_exception_when_writer_fails(): void
    {
        $writer = new class implements AuditWriterInterface {
            public function record(AuditEventDescriptor $d): void
            {
                throw new \RuntimeException('Writer broken — best-effort should swallow');
            }
        };

        $entity = $this->createStub(EntityInterface::class);
        $entity->method('isNew')->willReturn(false);
        $entity->method('getEntityTypeId')->willReturn('note');
        $entity->method('id')->willReturn('1');
        $entity->method('uuid')->willReturn('00000000-0000-0000-0000-000000000003');

        $listener = new EntityLifecycleAuditListener($writer);
        $listener->onPreSave(new EntityEvent($entity));

        // Must not throw.
        $listener->onPostSave(new EntityEvent($entity));
        $this->assertTrue(true, 'Best-effort: no exception bubbled up');
    }

    #[Test]
    public function it_does_not_record_when_the_entity_is_itself_an_audit_event_on_save(): void
    {
        // Regression (#1587): auditing an AuditEvent save would re-enter the
        // writer → save → POST_SAVE → infinite recursion.
        $recorded = [];
        $writer = new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };

        $auditEvent = new AuditEvent(['id' => 1, 'uuid' => '00000000-0000-0000-0000-0000000000aa']);

        $listener = new EntityLifecycleAuditListener($writer);
        $listener->onPreSave(new EntityEvent($auditEvent));
        $listener->onPostSave(new EntityEvent($auditEvent));

        $this->assertCount(0, $recorded, 'AuditEvent saves must not be re-audited');
    }

    #[Test]
    public function it_does_not_record_when_the_entity_is_itself_an_audit_event_on_delete(): void
    {
        // Regression (#1587): same recursion guard on the delete path.
        $recorded = [];
        $writer = new class ($recorded) implements AuditWriterInterface {
            public function __construct(private array &$recorded) {}
            public function record(AuditEventDescriptor $d): void
            {
                $this->recorded[] = $d;
            }
        };

        $auditEvent = new AuditEvent(['id' => 1, 'uuid' => '00000000-0000-0000-0000-0000000000bb']);

        $listener = new EntityLifecycleAuditListener($writer);
        $listener->onPostDelete(new EntityEvent($auditEvent));

        $this->assertCount(0, $recorded, 'AuditEvent deletes must not be re-audited');
    }

    #[Test]
    public function it_subscribes_to_the_correct_entity_events(): void
    {
        $subscribed = EntityLifecycleAuditListener::getSubscribedEvents();

        $this->assertArrayHasKey(EntityEvents::PRE_SAVE->value, $subscribed);
        $this->assertArrayHasKey(EntityEvents::POST_SAVE->value, $subscribed);
        $this->assertArrayHasKey(EntityEvents::POST_DELETE->value, $subscribed);
    }

    // ------------------------------------------------------------------
    // Actor attribution matrix (#1645 S1): actor = acting account from
    // AccountContext — N / 0 / null — NEVER the saved entity's `uid` field.
    // ------------------------------------------------------------------

    #[Test]
    public function it_records_the_context_account_as_actor_on_save(): void
    {
        $recorded = [];
        $listener = new EntityLifecycleAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(42),
        );

        $entity = $this->createStub(EntityInterface::class);
        $entity->method('isNew')->willReturn(true);
        $entity->method('getEntityTypeId')->willReturn('note');
        $entity->method('id')->willReturn('1');
        $entity->method('uuid')->willReturn('00000000-0000-0000-0000-000000000010');

        $listener->onPreSave(new EntityEvent($entity));
        $listener->onPostSave(new EntityEvent($entity));

        $this->assertCount(1, $recorded);
        $this->assertSame(42, $recorded[0]->accountUid);
    }

    #[Test]
    public function it_records_anonymous_zero_when_the_context_holds_the_anonymous_account(): void
    {
        $recorded = [];
        $listener = new EntityLifecycleAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(0),
        );

        $entity = $this->createStub(EntityInterface::class);
        $entity->method('isNew')->willReturn(true);
        $entity->method('getEntityTypeId')->willReturn('note');
        $entity->method('id')->willReturn('1');
        $entity->method('uuid')->willReturn('00000000-0000-0000-0000-000000000011');

        $listener->onPreSave(new EntityEvent($entity));
        $listener->onPostSave(new EntityEvent($entity));

        $this->assertSame(0, $recorded[0]->accountUid, 'Anonymous IS an actor — 0, not null');
    }

    #[Test]
    public function it_records_null_actor_when_no_acting_context_exists(): void
    {
        // No context at all (bare-provider wiring) AND an empty context must
        // both yield null — never 0, never the entity's uid.
        $recorded = [];
        $listener = new EntityLifecycleAuditListener($this->spyWriter($recorded));

        $entity = $this->createStub(EntityInterface::class);
        $entity->method('isNew')->willReturn(true);
        $entity->method('getEntityTypeId')->willReturn('note');
        $entity->method('id')->willReturn('1');
        $entity->method('uuid')->willReturn('00000000-0000-0000-0000-000000000012');

        $listener->onPreSave(new EntityEvent($entity));
        $listener->onPostSave(new EntityEvent($entity));

        $this->assertNull($recorded[0]->accountUid);

        $recorded2 = [];
        $listener2 = new EntityLifecycleAuditListener(
            $this->spyWriter($recorded2),
            accountContext: new RequestAccountContext(),
        );
        $listener2->onPreSave(new EntityEvent($entity));
        $listener2->onPostSave(new EntityEvent($entity));

        $this->assertNull($recorded2[0]->accountUid);
    }

    #[Test]
    public function it_never_consults_the_saved_entitys_own_uid_field(): void
    {
        // The #1645 pin: a user-A (42) session saving an entity whose own
        // `uid` FIELD is user B (99) must record actor A — the entity's uid
        // is the owner, not the actor.
        $recorded = [];
        $listener = new EntityLifecycleAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(42),
        );

        $entity = new LifecycleListenerOwnedEntity(['id' => 7, 'uid' => 99, 'title' => 'Owned by B']);

        $listener->onPreSave(new EntityEvent($entity));
        $listener->onPostSave(new EntityEvent($entity));

        $this->assertCount(1, $recorded);
        $this->assertSame(42, $recorded[0]->accountUid, 'Actor must come from the context, never the entity uid field');
    }

    #[Test]
    public function it_records_null_actor_for_an_owned_entity_saved_with_no_context(): void
    {
        // Before this mission the listener fell back to the entity's uid
        // field (99 here); now absence of context is null, full stop.
        $recorded = [];
        $listener = new EntityLifecycleAuditListener($this->spyWriter($recorded));

        $entity = new LifecycleListenerOwnedEntity(['id' => 7, 'uid' => 99, 'title' => 'Owned by B']);

        $listener->onPreSave(new EntityEvent($entity));
        $listener->onPostSave(new EntityEvent($entity));

        $this->assertNull($recorded[0]->accountUid);
    }

    #[Test]
    public function it_records_the_context_account_as_actor_on_delete(): void
    {
        $recorded = [];
        $listener = new EntityLifecycleAuditListener(
            $this->spyWriter($recorded),
            accountContext: $this->contextHolding(42),
        );

        $entity = new LifecycleListenerOwnedEntity(['id' => 7, 'uid' => 99, 'title' => 'Owned by B']);
        $listener->onPostDelete(new EntityEvent($entity));

        $this->assertCount(1, $recorded);
        $this->assertSame(AuditEventKind::EntityDelete, $recorded[0]->kind);
        $this->assertSame(42, $recorded[0]->accountUid);
    }
}

/** Minimal account stub: id-only. */
final class LifecycleListenerStubAccount implements AccountInterface
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

/**
 * Fieldable entity with an owner `uid` FIELD — the value the pre-mission
 * listener misattributed as the actor (#1645).
 */
final class LifecycleListenerOwnedEntity extends ContentEntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'audit_test_owned', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title']);
    }

    public function get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }
}
