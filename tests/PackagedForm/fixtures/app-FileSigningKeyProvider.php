<?php

declare(strict_types=1);

namespace App\Provider;

use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SensitiveValue;

/** Resolves the Ed25519 signing key from a file the authoring host owns. */
final class FileSigningKeyProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'authoring-file';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        if ($reference->secretClass() !== SecretClass::TokenSigningPrivateKey) {
            throw new \RuntimeException('Authoring custody serves signing keys only.');
        }

        $path = getenv('WAASEYAA_AUTHORING_SIGNING_KEY_FILE');
        if (!is_string($path) || $path === '' || !is_file($path)) {
            // Never echo the path or any provider detail into the message.
            throw new \RuntimeException('Authoring signing custody is unavailable.');
        }

        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('Authoring signing custody is unavailable.');
        }

        return SensitiveValue::fromBytes($bytes, SecretClass::TokenSigningPrivateKey, 'v1');
    }
}
