<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Keys;

use Waaseyaa\Foundation\Security\SecretHandle;

/**
 * @internal Guarded RS256 signer; private PEM is never an object property.
 * @api Security boundary whose magic-method rejection surface is intentional.
 */
final class RsaSigningKeySigner implements SigningKeySignerInterface
{
    public function __construct(
        private readonly SigningKey $key,
        private readonly SecretHandle $privateKey,
    ) {}

    public static function fromPrivatePem(
        SigningKey $key,
        #[\SensitiveParameter]
        string $privateKeyPem,
    ): self {
        return new self(
            $key,
            SecretHandle::fromBytes(
                $privateKeyPem,
                RsaSigningOperation::secretClass(),
                RsaSigningOperation::purpose(),
                'oidc-' . substr(hash('sha256', $key->kid), 0, 32),
                [RsaSigningOperation::class],
            ),
        );
    }

    public function key(): SigningKey
    {
        return $this->key;
    }

    public function sign(string $signingInput): string
    {
        return $this->privateKey->consume(new RsaSigningOperation($signingInput));
    }

    /** @return array{signer: string, kid: string, algorithm: string} */
    public function __debugInfo(): array
    {
        return [
            'signer' => '[NON_EXPORTING]',
            'kid' => $this->key->kid,
            'algorithm' => $this->key->algorithm,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Signing handles cannot be serialized.');
    }

    private function __clone() {}
}
