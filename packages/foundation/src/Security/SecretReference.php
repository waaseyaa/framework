<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/**
 * Non-secret, deployable reference to externally held secret material.
 *
 * Ordinary configuration may serialize the complete reference. Diagnostic
 * views intentionally expose only its stable fingerprint, class and purpose.
 *
 * @api
 */
final class SecretReference implements \JsonSerializable
{
    private function __construct(
        private readonly string $provider,
        private readonly string $identifier,
        private readonly SecretClass $secretClass,
        private readonly string $purpose,
        private readonly string $fingerprint,
    ) {}

    public static function create(
        string $provider,
        string $identifier,
        SecretClass $secretClass,
        string $purpose,
    ): self {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $provider)) {
            throw new \InvalidArgumentException('Secret-reference provider IDs must be stable lowercase identifiers.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@+-]{0,511}$/D', $identifier)) {
            throw new \InvalidArgumentException('Secret-reference identifiers must be non-empty stable opaque identifiers.');
        }
        if (!preg_match('/^waaseyaa\.[a-z0-9.-]+\.v[1-9][0-9]*$/D', $purpose)) {
            throw new \InvalidArgumentException('Secret-reference purposes must be versioned Waaseyaa identifiers.');
        }

        $fields = [
            'provider' => $provider,
            'identifier' => $identifier,
            'secret_class' => $secretClass->value,
            'purpose' => $purpose,
        ];

        return new self(
            $provider,
            $identifier,
            $secretClass,
            $purpose,
            hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        );
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function secretClass(): SecretClass
    {
        return $this->secretClass;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /** @return array{provider: string, identifier: string, secret_class: string, purpose: string} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'identifier' => $this->identifier,
            'secret_class' => $this->secretClass->value,
            'purpose' => $this->purpose,
        ];
    }

    /** @return array{reference: string, class: string, purpose: string} */
    public function __debugInfo(): array
    {
        return [
            'reference' => $this->fingerprint,
            'class' => $this->secretClass->value,
            'purpose' => $this->purpose,
        ];
    }

    /** @return array{provider: string, identifier: string, secret_class: string, purpose: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
