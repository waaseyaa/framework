<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Security;

use Waaseyaa\Foundation\Security\SensitiveKey;

/** @api */
final class OpaqueTokenProtector
{
    private readonly SecretBoxEnvelope $envelope;
    private readonly SensitiveKey $lookupKey;

    public function __construct(
        #[\SensitiveParameter]
        string $encryptionKey,
        #[\SensitiveParameter]
        string $lookupKey,
    ) {
        if (strlen($lookupKey) !== 32) {
            throw new \InvalidArgumentException('OIDC token lookup keys must be 32 bytes.');
        }

        $this->envelope = new SecretBoxEnvelope($encryptionKey);
        $this->lookupKey = new SensitiveKey($lookupKey);
    }

    public function seal(#[\SensitiveParameter] string $token): string
    {
        return $this->envelope->seal($token);
    }

    public function open(string $envelope): string
    {
        return $this->envelope->open($envelope);
    }

    public function lookup(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, $this->lookupKey->bytes());
    }

    /** @return array{envelope: string, lookup_key: string} */
    public function __debugInfo(): array
    {
        return ['envelope' => SecretBoxEnvelope::class, 'lookup_key' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('OIDC token protectors cannot be serialized.');
    }
}
