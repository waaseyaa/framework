<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\ClientRegistry;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Oidc\Entity\OidcClient;

/** Closed typed authority for pre-auth protocol validation and registry maintenance. @api */
final class OidcClientSystemReader
{
    /** @var \Closure(EntityBase): array<string, mixed> */
    private readonly \Closure $valueAuthority;

    public function __construct()
    {
        $authority = \Closure::bind(
            static fn(EntityBase $entity): array => $entity->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
        $this->valueAuthority = $authority;
    }

    public function registration(OidcClient $client): OidcClientRegistration
    {
        $values = ($this->valueAuthority)($client);

        return new OidcClientRegistration(
            is_string($values['name'] ?? null) ? $values['name'] : '',
            $this->stringList($values['redirect_uris'] ?? []),
            $this->stringList($values['scopes'] ?? ['openid']),
            $this->stringList($values['grant_types'] ?? ['authorization_code']),
            (bool) ($values['is_confidential'] ?? false),
        );
    }

    /** Secret material never leaves this fixed comparison operation. */
    public function verifySecret(OidcClient $client, string $candidate): bool
    {
        $values = ($this->valueAuthority)($client);
        $hash = $values['client_secret_hash'] ?? null;

        return is_string($hash) && $hash !== '' && password_verify($candidate, $hash);
    }

    /** Test/maintenance assertion without releasing the stored secret hash. */
    public function hasStoredSecretHash(OidcClient $client, string $expectedHash): bool
    {
        $values = ($this->valueAuthority)($client);

        return is_string($values['client_secret_hash'] ?? null)
            && hash_equals($values['client_secret_hash'], $expectedHash);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
