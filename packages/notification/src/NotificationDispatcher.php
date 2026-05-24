<?php

declare(strict_types=1);

namespace Waaseyaa\Notification;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Queue\QueueInterface;

/**
 * Dispatches notifications to their designated channels.
 * @api
 */
final class NotificationDispatcher
{
    /** @var array<string, ChannelInterface> */
    private readonly array $channels;

    private readonly LoggerInterface $logger;

    /**
     * @param array<string, ChannelInterface> $channels
     */
    public function __construct(
        private readonly QueueInterface $queue,
        array $channels,
        ?LoggerInterface $logger = null,
    ) {
        $this->channels = $channels;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Expose the configured channels map for read-only inspection.
     *
     * Used by the M4C admin notifications dashboard
     * (`Waaseyaa\Api\Controller\NotificationController`) to list registered
     * channels and look one up by type for a synthetic test send. Returns the
     * exact array passed at construction; do not mutate the result.
     *
     * @return array<string, ChannelInterface>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * Send a notification synchronously through all designated channels.
     */
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        $viaChannels = $notification->via($notifiable);

        foreach ($viaChannels as $channelName) {
            if (!isset($this->channels[$channelName])) {
                continue;
            }

            try {
                $this->channels[$channelName]->send($notifiable, $notification);
            } catch (\Throwable $e) {
                $this->logger->error("Channel {$channelName} failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Queue a notification for asynchronous delivery.
     */
    public function sendAsync(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        $this->queue->dispatch(new Job\SendNotificationJob(
            notifiableType: $notifiable->getNotifiableType(),
            notifiableId: $notifiable->getNotifiableId(),
            notificationClass: $notification::class,
            notificationData: $notification->toArray($notifiable),
            channels: $notification->via($notifiable),
        ));
    }

    /**
     * Send to multiple notifiables.
     *
     * @param iterable<NotifiableInterface> $notifiables
     */
    public function sendToMany(iterable $notifiables, NotificationInterface $notification): void
    {
        foreach ($notifiables as $notifiable) {
            $this->send($notifiable, $notification);
        }
    }
}
