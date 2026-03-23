<?php

declare(strict_types=1);

namespace Waaseyaa\Notification\Channel;

use Waaseyaa\Mail\MailerInterface;
use Waaseyaa\Notification\ChannelInterface;
use Waaseyaa\Notification\NotifiableInterface;
use Waaseyaa\Notification\NotificationInterface;

final class MailChannel implements ChannelInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {}

    public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
    {
        if (!method_exists($notification, 'toMail')) {
            return;
        }

        $envelope = $notification->toMail($notifiable);
        $this->mailer->send($envelope);
    }
}
