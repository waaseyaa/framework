<?php

declare(strict_types=1);

namespace Waaseyaa\Notification\Job;

use Waaseyaa\Notification\NotificationInterface;
use Waaseyaa\Queue\Job;

/**
 * Queued job for asynchronous notification delivery.
 *
 * Carries the real {@see NotificationInterface} instance (not a flattened
 * `toArray()` snapshot), so the worker renders every channel — including
 * `toMail()`/`toDatabase()` — exactly as the synchronous path does. The job is
 * `serialize()`d onto the queue, so the notification must be serializable (the
 * standard contract for any queued payload).
 * @api
 */
final class SendNotificationJob extends Job
{
    public int $tries = 3;
    public int $retryAfter = 30;

    /**
     * @param list<string> $channels
     */
    public function __construct(
        public readonly string $notifiableType,
        public readonly string $notifiableId,
        public readonly NotificationInterface $notification,
        public readonly array $channels,
    ) {}

    public function handle(): void
    {
        // Processed by SendNotificationHandler registered with the Worker.
        // If this is reached, the handler was not registered.
        throw new \RuntimeException(
            'SendNotificationJob requires SendNotificationHandler to be registered with the Worker.',
        );
    }
}
