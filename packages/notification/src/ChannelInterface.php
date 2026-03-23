<?php

declare(strict_types=1);

namespace Waaseyaa\Notification;

interface ChannelInterface
{
    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void;
}
