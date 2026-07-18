<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/** Value-free strict-audit descriptor reserved before backend invocation. @api */
final readonly class FieldStorageGatewayAttempt
{
    public function __construct(
        public string $backendId,
        public string $fingerprint,
        public FieldStorageGatewayOperation $operation,
        public string $entityTypeId,
        public int|string|null $entityId,
        public ?string $fieldName,
    ) {}
}
