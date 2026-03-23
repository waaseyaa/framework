<?php

declare(strict_types=1);

namespace Waaseyaa\Notification;

/**
 * Contract for notification definitions.
 *
 * Implementations define which channels to use via via() and provide
 * channel-specific representations via toMail(), toDatabase(), etc.
 * These methods are checked via method_exists() rather than interface
 * requirements, keeping the interface lean.
 */
interface NotificationInterface
{
    /**
     * Get the channels this notification should be sent through.
     *
     * @return list<string> Channel names (e.g., 'mail', 'database')
     */
    public function via(NotifiableInterface $notifiable): array;

    /**
     * Get a generic array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(NotifiableInterface $notifiable): array;
}
