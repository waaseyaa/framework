<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Key;

/** Immutable, non-secret evidence for one emergency signing-key revocation. @api */
final readonly class SigningKeyRevocationRecord
{
    public function __construct(
        public string $requestId,
        public string $kid,
        public int $keyVersion,
        public int $revokedAt,
        public string $actor,
        public string $reason,
        public int $statelessWindowFrom,
        public int $statelessWindowTo,
        public int $persistedAccessAffected,
        public string $persistedAccessJtiHash,
        public int $persistedRefreshAffected,
        public string $persistedRefreshJtiHash,
    ) {}
}
