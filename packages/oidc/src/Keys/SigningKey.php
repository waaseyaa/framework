<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Keys;

final readonly class SigningKey
{
    public function __construct(
        public string $kid,
        public string $algorithm,
        public string $publicKeyPem,
    ) {}
}
