<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Messaging\MessageThread;

/**
 * Seeds the creating account as the first `thread_participant` (role: owner)
 * when a `message_thread` is created.
 *
 * Closes the participant-bootstrap deadlock documented in the L3-messaging
 * audit (#1915, R16): `MessagingAccessPolicy::fieldAccess()` requires the
 * acting account to already be a participant before it may create the FIRST
 * `thread_participant` row for a thread, and nothing anywhere auto-seeded
 * that first membership — so an ordinary non-admin account could create a
 * `message_thread` (allowed by `createAccess()`) but could never populate it,
 * making direct messaging unusable for non-admins through the documented API.
 *
 * The acting account is read from {@see AccountContextInterface}, NOT from
 * the saved entity's own `created_by` field — the same discipline
 * `Waaseyaa\Audit\Listener\EntityLifecycleAuditListener` documents (#1645):
 * trusting a mutable entity field for "who is acting" is spoofable, the
 * request-scoped acting-account holder is not.
 *
 * `isNew()` is captured at PRE_SAVE, keyed on the entity object
 * ({@see \WeakMap}) — the same two-phase pairing
 * `EntityLifecycleAuditListener` uses — because by POST_SAVE the entity has
 * already been assigned a real id and `enforceIsNew(false)`, so `isNew()`
 * no longer distinguishes create from update at that point. A listener-wide
 * boolean slot is not safe under `saveMany()`: PRE events for the whole
 * batch fire before any POST (#1856).
 *
 * Best-effort: seeding failures are logged, never thrown, so a storage hiccup
 * degrades to "thread has no participants yet" rather than breaking thread
 * creation (NFR-001 best-effort side-effect convention).
 */
final class ThreadParticipantBootstrapSubscriber implements EventSubscriberInterface
{
    /** @var \WeakMap<MessageThread, bool> */
    private \WeakMap $pendingIsNew;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?AccountContextInterface $accountContext = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->pendingIsNew = new \WeakMap();
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::PRE_SAVE->value  => 'onPreSave',
            EntityEvents::POST_SAVE->value => 'onPostSave',
        ];
    }

    public function onPreSave(EntityEvent $event): void
    {
        if (!$event->entity instanceof MessageThread) {
            return;
        }

        try {
            $this->pendingIsNew[$event->entity] = $event->entity->isNew();
        } catch (\Throwable $e) {
            $this->logger->warning('messaging.participant_bootstrap_failed', [
                'phase' => 'pre_save',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onPostSave(EntityEvent $event): void
    {
        $entity = $event->entity;
        if (!$entity instanceof MessageThread) {
            return;
        }

        $wasNew = $this->consumePendingIsNew($entity);

        if (!$wasNew) {
            return;
        }

        $account = $this->accountContext?->current();
        if ($account === null || !$account->isAuthenticated()) {
            // No acting account (CLI/queue/system context) — nothing to seed;
            // an admin (or the CLI operator) can add participants explicitly.
            return;
        }

        $threadId = (int) $entity->id();
        if ($threadId <= 0) {
            return;
        }

        $userId = (int) $account->id();

        try {
            $repository = $this->entityTypeManager->getRepository('thread_participant');

            // Defensive dedupe: this subscriber only fires for a genuinely new
            // thread, so no participant row should exist yet, but guard against
            // an unexpected re-dispatch rather than risk a duplicate row.
            $existing = $repository->getQuery()
                ->accessCheck(false) // bootstrap step, runs before any participant exists to check access against.
                ->condition('thread_id', $threadId)
                ->condition('user_id', $userId)
                ->range(0, 1)
                ->execute();

            if ($existing !== []) {
                return;
            }

            $participant = $repository->create([
                'thread_id' => $threadId,
                'user_id' => $userId,
                'thread_creator_id' => $userId,
                'role' => 'owner',
            ]);

            $repository->save($participant);
        } catch (\Throwable $e) {
            $this->logger->error('messaging.participant_bootstrap_failed', [
                'phase' => 'post_save',
                'thread_id' => $threadId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function consumePendingIsNew(MessageThread $entity): bool
    {
        if (!isset($this->pendingIsNew[$entity])) {
            return false;
        }

        $isNew = $this->pendingIsNew[$entity];
        unset($this->pendingIsNew[$entity]);

        return $isNew === true;
    }
}
