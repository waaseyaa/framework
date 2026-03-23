<?php

declare(strict_types=1);

namespace Waaseyaa\Notification\Job;

use Waaseyaa\Queue\Job;

/**
 * Queued job for asynchronous notification delivery.
 *
 * Stores the notification data for later dispatch by the worker.
 */
final class SendNotificationJob extends Job
{
    public int $tries = 3;
    public int $retryAfter = 30;

    public function __construct(
        public readonly string $notifiableType,
        public readonly string $notifiableId,
        public readonly string $notificationClass,
        /** @var array<string, mixed> */
        public readonly array $notificationData,
        /** @var list<string> */
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
