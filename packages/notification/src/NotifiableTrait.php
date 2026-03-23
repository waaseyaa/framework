<?php

declare(strict_types=1);

namespace Waaseyaa\Notification;

/**
 * Default NotifiableInterface implementation for entities.
 *
 * Expects the using class to have get() and id() methods
 * (as provided by EntityBase/ContentEntityBase).
 */
trait NotifiableTrait
{
    public function routeNotificationFor(string $channel): mixed
    {
        return match ($channel) {
            'mail' => $this->get('mail') ?? $this->get('email'),
            'database' => (string) $this->id(),
            default => null,
        };
    }

    public function getNotifiableId(): string
    {
        return (string) $this->id();
    }

    public function getNotifiableType(): string
    {
        return $this->getEntityTypeId();
    }
}
