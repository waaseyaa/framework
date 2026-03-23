<?php

declare(strict_types=1);

namespace Waaseyaa\Notification;

interface NotifiableInterface
{
    /**
     * Get the routing information for the given channel.
     *
     * For 'mail': returns email address string.
     * For 'database': returns the notifiable ID string.
     */
    public function routeNotificationFor(string $channel): mixed;

    public function getNotifiableId(): string;

    public function getNotifiableType(): string;
}
