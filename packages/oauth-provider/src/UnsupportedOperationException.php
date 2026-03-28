<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final class UnsupportedOperationException extends \RuntimeException
{
    public static function refreshNotSupported(string $provider): self
    {
        return new self("Token refresh is not supported by the '{$provider}' provider");
    }
}
