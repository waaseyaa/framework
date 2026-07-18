<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/** Value-free failure finalization for a reserved gateway attempt. @api */
final readonly class FieldStorageGatewayFailure
{
    public function __construct(
        public FieldStorageGatewayAttempt $attempt,
        public string $causeClass,
        public bool $backendInvocationStarted,
    ) {}
}
