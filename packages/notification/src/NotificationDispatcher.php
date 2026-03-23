<?php

declare(strict_types=1);

namespace Waaseyaa\Notification;

use Waaseyaa\Queue\QueueInterface;

/**
 * Dispatches notifications to their designated channels.
 */
final class NotificationDispatcher
{
    /** @var array<string, ChannelInterface> */
    private readonly array $channels;

    /**
     * @param array<string, ChannelInterface> $channels
     */
    public function __construct(
        private readonly QueueInterface $queue,
        array $channels,
    ) {
        $this->channels = $channels;
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
                error_log("[notification] Channel {$channelName} failed: {$e->getMessage()}");
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
