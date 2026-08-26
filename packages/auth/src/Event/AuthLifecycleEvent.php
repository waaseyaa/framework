<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Event;

use Waaseyaa\Foundation\Event\DomainEvent;

/** Credential- and token-free account lifecycle signal. @api */
final class AuthLifecycleEvent extends DomainEvent
{
    public const string NAME = 'waaseyaa.auth.lifecycle';

    /** @param array<string, bool|int|string|null> $disposition */
    public function __construct(
        string $userId,
        public readonly string $action,
        public readonly array $disposition = [],
    ) {
        if (!in_array($action, ['registered', 'login_succeeded', 'logout_succeeded', 'email_verified'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown auth lifecycle action "%s".', $action));
        }
        parent::__construct('user', $userId);
    }

    public function getPayload(): array
    {
        return ['action' => $this->action, 'disposition' => $this->disposition];
    }
}
