<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\EntityPersistedEvent;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Messaging\MessageThread;

/**
 * Makes creator ownership a required part of SQL thread creation.
 *
 * PRE_SAVE captures the acting account, never submitted created_by.
 * EntityPersistedEvent supplies the assigned identity inside the transaction;
 * owner insertion failure propagates and rolls back the thread and membership.
 * POST_SAVE is a commit notification and cannot enforce this invariant.
 */
final class ThreadParticipantBootstrapSubscriber implements EventSubscriberInterface
{
    /** @var \WeakMap<MessageThread, int> Zero denotes trusted actorless creation. */
    private \WeakMap $pendingCreators;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?AccountContextInterface $accountContext = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->pendingCreators = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::PRE_SAVE->value => 'onPreSave',
            EntityPersistedEvent::class => 'onPersisted',
        ];
    }

    public function onPreSave(EntityEvent $event): void
    {
        $entity = $event->entity;
        if (!$entity instanceof MessageThread) {
            return;
        }
        unset($this->pendingCreators[$entity]);
        if (!$entity->isNew()) {
            return;
        }
        // Refuse unsupported repository composition before a thread is written.
        foreach (['message_thread', 'thread_participant'] as $type) {
            $repository = $this->entityTypeManager->getRepository($type);
            if (!$repository instanceof EntityRepository || !$repository->supportsAtomicSqlWrites()) {
                throw new \LogicException('Messaging creation requires atomic SQL repositories.');
            }
        }
        $account = $this->accountContext?->current();
        $creator = $account !== null && $account->isAuthenticated() ? (int) $account->id() : null;
        if ($creator !== null && $creator <= 0) {
            throw new \LogicException('Authenticated messaging creation requires a positive account identity.');
        }
        $this->pendingCreators[$entity] = $creator ?? 0;
    }

    public function onPersisted(EntityPersistedEvent $event): void
    {
        $entity = $event->entity;
        if (!$entity instanceof MessageThread || !$event->isNew) {
            return;
        }
        if (!$this->pendingCreators->offsetExists($entity)) {
            throw new \LogicException('Messaging creation requires its PRE_SAVE actor context.');
        }
        $creator = $this->pendingCreators[$entity];
        unset($this->pendingCreators[$entity]);
        if ($creator === 0) {
            return; // Trusted actorless CLI/system creation retains its contract.
        }
        $repository = $this->entityTypeManager->getRepository('thread_participant');
        if (!$repository instanceof EntityRepository || !$repository->sharesTransactionWith($event->database)) {
            throw new \LogicException('Messaging owner membership requires the thread transaction connection.');
        }
        $threadId = (int) $entity->id();
        if ($threadId <= 0) {
            throw new \LogicException('Messaging creation requires a persisted positive thread identity.');
        }
        try {
            $existingOwners = $repository->getQuery()->accessCheck(false)
                ->condition('thread_id', $threadId)->condition('user_id', $creator)
                ->condition('role', 'owner')->range(0, 1)->execute();
            if ($existingOwners !== []) {
                return;
            }
            $participant = $repository->create([
                'thread_id' => $threadId,
                'user_id' => $creator,
                'thread_creator_id' => $creator,
                'role' => 'owner',
            ]);
            $repository->save($participant);
        } catch (\Throwable $failure) {
            $this->logger?->error('messaging.participant_bootstrap_failed', [
                'phase' => 'persisted', 'thread_id' => $threadId, 'user_id' => $creator,
                'error' => $failure->getMessage(),
            ]);
            throw $failure;
        }
    }
}
