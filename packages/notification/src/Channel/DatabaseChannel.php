<?php

declare(strict_types=1);

namespace Waaseyaa\Notification\Channel;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Notification\ChannelInterface;
use Waaseyaa\Notification\NotifiableInterface;
use Waaseyaa\Notification\NotificationInterface;

/**
 * Persists notifications to the waaseyaa_notifications table.
 */
final class DatabaseChannel implements ChannelInterface
{
    private const TABLE = 'waaseyaa_notifications';

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        $data = method_exists($notification, 'toDatabase')
            ? $notification->toDatabase($notifiable)
            : $notification->toArray($notifiable);

        $this->database->insert(self::TABLE)
            ->values([
                'type' => $notification::class,
                'notifiable_type' => $notifiable->getNotifiableType(),
                'notifiable_id' => $notifiable->getNotifiableId(),
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'created_at' => date('Y-m-d\TH:i:sP'),
                'read_at' => null,
            ])
            ->execute();
    }
}
