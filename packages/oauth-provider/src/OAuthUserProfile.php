<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final readonly class OAuthUserProfile
{
    public function __construct(
        public string $providerId,
        public string $email,
        public string $name,
        public ?string $avatarUrl = null,
        public bool $emailVerified = false,
    ) {}
}
