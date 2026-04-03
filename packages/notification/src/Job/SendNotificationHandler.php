<?php

declare(strict_types=1);

namespace Waaseyaa\Notification\Job;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Notification\ChannelInterface;
use Waaseyaa\Notification\NotifiableInterface;
use Waaseyaa\Notification\NotificationInterface;
use Waaseyaa\Queue\Handler\HandlerInterface;

/**
 * Worker handler that processes SendNotificationJob instances.
 *
 * Reconstructs the notification from the job's serialized data
 * and sends it through the specified channels.
 */
final class SendNotificationHandler implements HandlerInterface
{
    private readonly LoggerInterface $logger;

    /**
     * @param array<string, ChannelInterface> $channels
     */
    public function __construct(
        private readonly array $channels,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(object $message): bool
    {
        return $message instanceof SendNotificationJob;
    }

    public function handle(object $message): void
    {
        /** @var SendNotificationJob $message */
        $notifiable = $this->buildNotifiable($message);
        $notification = $this->buildNotification($message);

        foreach ($message->channels as $channelName) {
            if (!isset($this->channels[$channelName])) {
                continue;
            }

            try {
                $this->channels[$channelName]->send($notifiable, $notification);
            } catch (\Throwable $e) {
                $this->logger->error("Async channel {$channelName} failed: {$e->getMessage()}");
                throw $e; // Let the worker handle retry
            }
        }
    }

    private function buildNotifiable(SendNotificationJob $job): NotifiableInterface
    {
        return new class ($job->notifiableType, $job->notifiableId) implements NotifiableInterface {
            public function __construct(
                private readonly string $type,
                private readonly string $id,
            ) {}

            public function routeNotificationFor(string $channel): mixed
            {
                return match ($channel) {
                    'database' => $this->id,
                    default => null,
                };
            }

            public function getNotifiableId(): string
            {
                return $this->id;
            }

            public function getNotifiableType(): string
            {
                return $this->type;
            }
        };
    }

    private function buildNotification(SendNotificationJob $job): NotificationInterface
    {
        return new class ($job->channels, $job->notificationData) implements NotificationInterface {
            public function __construct(
                private readonly array $channels,
                private readonly array $data,
            ) {}

            public function via(NotifiableInterface $notifiable): array
            {
                return $this->channels;
            }

            public function toArray(NotifiableInterface $notifiable): array
            {
                return $this->data;
            }
        };
    }
}
