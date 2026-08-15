<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Keys;

final readonly class SigningKey
{
    public function __construct(
        public string $kid,
        public string $algorithm,
        public string $publicKeyPem,
        public int $version = 0,
        public SigningKeyState $state = SigningKeyState::ActiveSignAndVerify,
        public ?int $stagedAt = null,
        public ?int $activatedAt = null,
        public ?int $rotatedAt = null,
        public ?int $retainUntil = null,
        public ?int $revokedAt = null,
    ) {}
}
