<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Keys;

use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretConsumerInterface;

/** @implements SecretConsumerInterface<string> @internal */
final readonly class RsaSigningOperation implements SecretConsumerInterface
{
    public const string PURPOSE = 'waaseyaa.oidc.rs256-signing.v1';

    public function __construct(private string $signingInput) {}

    public static function id(): string
    {
        return 'waaseyaa.oidc.rs256-signing.v1';
    }

    public static function secretClass(): SecretClass
    {
        return SecretClass::TokenSigningPrivateKey;
    }

    public static function purpose(): string
    {
        return self::PURPOSE;
    }

    public function consume(#[\SensitiveParameter] string $secret, string $version): string
    {
        $signature = '';
        if (!openssl_sign($this->signingInput, $signature, $secret, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('OIDC signing operation failed.');
        }

        return $signature;
    }
}
