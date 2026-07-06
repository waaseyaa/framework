<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Workflows\Event\WorkflowEvents;
use Waaseyaa\Workflows\Event\WorkflowTransitionEvent;

/**
 * @covers \Waaseyaa\Workflows\Event\WorkflowTransitionEvent
 * @covers \Waaseyaa\Workflows\Event\WorkflowEvents
 */
#[CoversClass(WorkflowTransitionEvent::class)]
#[CoversClass(WorkflowEvents::class)]
final class WorkflowTransitionEventTest extends TestCase
{
    #[Test]
    public function event_carries_all_transition_values(): void
    {
        $entity = $this->createStub(EntityInterface::class);
        $account = $this->createStub(AccountInterface::class);

        $event = new WorkflowTransitionEvent(
            entity: $entity,
            workflowId: 'editorial',
            transitionId: 'publish',
            fromState: 'draft',
            toState: 'published',
            account: $account,
        );

        $this->assertSame($entity, $event->entity);
        $this->assertSame('editorial', $event->workflowId);
        $this->assertSame('publish', $event->transitionId);
        $this->assertSame('draft', $event->fromState);
        $this->assertSame('published', $event->toState);
        $this->assertSame($account, $event->account);
    }

    #[Test]
    public function event_accepts_a_null_account(): void
    {
        $entity = $this->createStub(EntityInterface::class);

        $event = new WorkflowTransitionEvent(
            entity: $entity,
            workflowId: 'editorial',
            transitionId: 'publish',
            fromState: 'draft',
            toState: 'published',
            account: null,
        );

        $this->assertNull($event->account);
    }

    #[Test]
    public function event_is_a_symfony_contracts_event(): void
    {
        $event = new WorkflowTransitionEvent(
            entity: $this->createStub(EntityInterface::class),
            workflowId: 'editorial',
            transitionId: 'publish',
            fromState: 'draft',
            toState: 'published',
            account: null,
        );

        $this->assertInstanceOf(\Symfony\Contracts\EventDispatcher\Event::class, $event);
    }

    #[Test]
    public function pre_and_post_transition_event_names_are_distinct(): void
    {
        $this->assertSame('waaseyaa.workflow.pre_transition', WorkflowEvents::PRE_TRANSITION->value);
        $this->assertSame('waaseyaa.workflow.post_transition', WorkflowEvents::POST_TRANSITION->value);
        $this->assertNotSame(WorkflowEvents::PRE_TRANSITION->value, WorkflowEvents::POST_TRANSITION->value);
    }
}
