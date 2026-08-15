<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

use Waaseyaa\Config\Schema\CanonicalConfigEncoder;

/** Closed signed envelope around exact canonical authored-manifest bytes. @api */
final readonly class SignedConfigManifestEnvelope
{
    public const FORMAT_V1 = 'waaseyaa.config-envelope/1';
    public const PAYLOAD_TYPE_V1 = 'application/vnd.waaseyaa.config-sync-bundle-manifest.v1+json';
    public const ALGORITHM_V1 = 'Ed25519';
    public const SIGNATURE_BYTES_V1 = 64;

    /** @param array<string, int|string> $protectedHeader */
    private function __construct(
        public array $protectedHeader,
        public string $manifestBytes,
        public string $signature,
    ) {}

    public static function sign(
        ConfigSyncBundleManifest $manifest,
        ConfigManifestSignerInterface $signer,
        ?CanonicalConfigEncoder $encoder = null,
    ): self {
        if ($signer->algorithm() !== self::ALGORITHM_V1) {
            throw new \InvalidArgumentException('CFG-03 envelope supports only Ed25519.');
        }
        $header = self::header(
            $manifest->document['bundle_scope'] ?? null,
            $manifest->document['bundle_sequence'] ?? null,
            $signer->trustKeyReference(),
        );
        $encoder ??= new CanonicalConfigEncoder();
        $signature = $signer->sign(ConfigEnvelopePreAuthentication::encode(
            $encoder->encode($header),
            $manifest->canonicalBytes,
        ));
        if (strlen($signature) !== self::SIGNATURE_BYTES_V1) {
            throw new \UnexpectedValueException('CFG-04 signer did not return an exact 64-byte Ed25519 signature.');
        }

        return new self($header, $manifest->canonicalBytes, $signature);
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        self::assertExactKeys($document, ['format', 'payload', 'protected', 'signature'], 'envelope');
        if (($document['format'] ?? null) !== self::FORMAT_V1 || !\is_array($document['protected'])) {
            throw new \InvalidArgumentException('Malformed CFG-03 envelope.');
        }
        /** @var array<string, mixed> $header */
        $header = $document['protected'];
        self::assertExactKeys($header, ['algorithm', 'bundle_scope', 'bundle_sequence', 'payload_type', 'trust_key_reference'], 'protected header');
        if (($header['algorithm'] ?? null) !== self::ALGORITHM_V1 || ($header['payload_type'] ?? null) !== self::PAYLOAD_TYPE_V1) {
            throw new \InvalidArgumentException('Unsupported CFG-03 envelope algorithm or payload type.');
        }
        $validated = self::header(
            $header['bundle_scope'] ?? null,
            $header['bundle_sequence'] ?? null,
            $header['trust_key_reference'] ?? null,
        );
        if (!\is_string($document['payload']) || !\is_string($document['signature'])) {
            throw new \InvalidArgumentException('Envelope payload and signature must be base64 strings.');
        }
        $payload = base64_decode($document['payload'], true);
        $signature = base64_decode($document['signature'], true);
        if (!\is_string($payload) || !\is_string($signature) || preg_match('//u', $payload) !== 1) {
            throw new \InvalidArgumentException('Envelope payload or signature encoding is invalid.');
        }
        if (strlen($signature) !== self::SIGNATURE_BYTES_V1) {
            throw new \InvalidArgumentException('Envelope signature must be an exact 64-byte Ed25519 value.');
        }

        return new self($validated, $payload, $signature);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT_V1,
            'payload' => base64_encode($this->manifestBytes),
            'protected' => $this->protectedHeader,
            'signature' => base64_encode($this->signature),
        ];
    }

    public function preAuthenticatedBytes(?CanonicalConfigEncoder $encoder = null): string
    {
        $encoder ??= new CanonicalConfigEncoder();

        return ConfigEnvelopePreAuthentication::encode($encoder->encode($this->protectedHeader), $this->manifestBytes);
    }

    /** @return array<string, int|string> */
    private static function header(mixed $scope, mixed $sequence, mixed $keyReference): array
    {
        if (!\is_string($scope) || preg_match('/^[a-z0-9][a-z0-9._:\/-]*$/D', $scope) !== 1 || !\is_int($sequence) || $sequence < 1) {
            throw new \InvalidArgumentException('Envelope scope and sequence are invalid.');
        }
        if (!\is_string($keyReference) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:\/-]*$/D', $keyReference) !== 1) {
            throw new \InvalidArgumentException('Envelope trust-key reference is invalid.');
        }

        return [
            'algorithm' => self::ALGORITHM_V1,
            'bundle_scope' => $scope,
            'bundle_sequence' => $sequence,
            'payload_type' => self::PAYLOAD_TYPE_V1,
            'trust_key_reference' => $keyReference,
        ];
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void
    {
        $keys = array_keys($value);
        sort($keys, \SORT_STRING);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException(sprintf('CFG-03 %s contains missing or unknown fields.', $label));
        }
    }
}
