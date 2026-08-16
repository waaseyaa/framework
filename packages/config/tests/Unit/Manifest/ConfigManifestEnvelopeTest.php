<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Manifest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Manifest\ConfigEnvelopePreAuthentication;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeVerifier;
use Waaseyaa\Config\Manifest\ConfigManifestSignatureVerifierInterface;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;
use Waaseyaa\Config\Manifest\ConfigReplayStateReaderInterface;
use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\Config\Manifest\UnsignedConfigPolicy;
use Waaseyaa\Config\Manifest\VerifiedConfigManifest;
use Waaseyaa\Config\Schema\ConfigContentHasher;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidationResult;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Config\Sync\ValidatedConfigSyncEntry;

#[CoversClass(ConfigEnvelopePreAuthentication::class)]
#[CoversClass(SignedConfigManifestEnvelope::class)]
#[CoversClass(ConfigManifestEnvelopeVerifier::class)]
#[CoversClass(UnsignedConfigPolicy::class)]
#[CoversClass(VerifiedConfigManifest::class)]
final class ConfigManifestEnvelopeTest extends TestCase
{
    #[Test]
    public function signedEnvelopeBindsManifestAndPassesReplayPreflightWithoutMutatingState(): void
    {
        $manifest = $this->manifest(sequence: 8);
        $signer = new TestConfigManifestSigner('test-secret');
        $envelope = SignedConfigManifestEnvelope::sign($manifest, $signer);
        $replay = new TestReplayStateReader(7);

        $verified = new ConfigManifestEnvelopeVerifier()->verifySigned(
            SignedConfigManifestEnvelope::fromArray($envelope->toArray()),
            new TestConfigManifestSignatureVerifier('test-secret'),
            $replay,
        );

        self::assertTrue($verified->signed);
        self::assertSame($manifest->manifestHash, $verified->manifestHash);
        self::assertSame(1, $replay->reads);
        self::assertSame(7, $replay->lastCommittedSequence('site:test', 'cfg04:test-key'));
    }

    #[Test]
    public function protectedHeaderTamperingFailsBeforeReplayStateIsRead(): void
    {
        $envelope = SignedConfigManifestEnvelope::sign($this->manifest(sequence: 8), new TestConfigManifestSigner('test-secret'));
        $document = $envelope->toArray();
        $document['protected']['bundle_scope'] = 'site:other';
        $replay = new TestReplayStateReader(7);

        try {
            new ConfigManifestEnvelopeVerifier()->verifySigned(
                SignedConfigManifestEnvelope::fromArray($document),
                new TestConfigManifestSignatureVerifier('test-secret'),
                $replay,
            );
            self::fail('Tampered protected header was accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $replay->reads);
        }
    }

    #[Test]
    public function unsupportedAlgorithmIsRejectedBeforeKeyVerification(): void
    {
        $document = SignedConfigManifestEnvelope::sign($this->manifest(), new TestConfigManifestSigner('test-secret'))->toArray();
        $document['protected']['algorithm'] = 'RS256';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported');
        SignedConfigManifestEnvelope::fromArray($document);
    }

    #[Test]
    public function malformedEd25519SignatureLengthIsRejectedByTheEnvelope(): void
    {
        $document = SignedConfigManifestEnvelope::sign($this->manifest(), new TestConfigManifestSigner('test-secret'))->toArray();
        $document['signature'] = base64_encode('short');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('64-byte Ed25519');
        SignedConfigManifestEnvelope::fromArray($document);
    }

    #[Test]
    public function invalidWellFormedSignatureIsRejectedBeforeReplayStateIsRead(): void
    {
        $envelope = SignedConfigManifestEnvelope::sign($this->manifest(sequence: 8), new TestConfigManifestSigner('signing-secret'));
        $replay = new TestReplayStateReader(7);

        try {
            new ConfigManifestEnvelopeVerifier()->verifySigned(
                $envelope,
                new TestConfigManifestSignatureVerifier('different-verification-secret'),
                $replay,
            );
            self::fail('Invalid manifest signature was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('signature verification failed', $exception->getMessage());
            self::assertSame(0, $replay->reads);
        }
    }

    #[Test]
    public function unknownManifestFieldFailsBeforeKeyResolution(): void
    {
        $envelope = SignedConfigManifestEnvelope::sign($this->manifest(), new TestConfigManifestSigner('test-secret'));
        $document = $envelope->toArray();
        $payload = json_decode($envelope->manifestBytes, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $payload['surprise'] = true;
        $encoder = new \Waaseyaa\Config\Schema\CanonicalConfigEncoder();
        $document['payload'] = base64_encode($encoder->encode($payload));
        $verifier = new TestConfigManifestSignatureVerifier('test-secret');

        try {
            new ConfigManifestEnvelopeVerifier()->verifySigned(
                SignedConfigManifestEnvelope::fromArray($document),
                $verifier,
                new TestReplayStateReader(null),
            );
            self::fail('Unknown manifest field was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('unknown fields', $exception->getMessage());
            self::assertSame(0, $verifier->calls);
        }
    }

    #[Test]
    public function replayedSequenceIsRejected(): void
    {
        $envelope = SignedConfigManifestEnvelope::sign($this->manifest(sequence: 7), new TestConfigManifestSigner('test-secret'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not newer');
        new ConfigManifestEnvelopeVerifier()->verifySigned(
            $envelope,
            new TestConfigManifestSignatureVerifier('test-secret'),
            new TestReplayStateReader(7),
        );
    }

    #[Test]
    public function productionRefusalNeedsNoKeyResolutionAndSealedLocalPolicyIsExplicit(): void
    {
        $manifest = $this->manifest();
        $verifier = new ConfigManifestEnvelopeVerifier();

        try {
            $verifier->verifyUnsigned($manifest, UnsignedConfigPolicy::refusing('authority:test'));
            self::fail('Unsigned production-style policy was accepted.');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }

        $verified = $verifier->verifyUnsigned($manifest, UnsignedConfigPolicy::sealedLocal(
            'authority:test',
            'sha256:' . str_repeat('a', 64),
        ));
        self::assertFalse($verified->signed);
        self::assertStringStartsWith('unsigned-sealed-local:', $verified->trustKeyReference);
    }

    private function manifest(int $sequence = 1): ConfigSyncBundleManifest
    {
        $registry = new ConfigSchemaRegistry();
        $registration = $registry->register('waaseyaa.system.site', 1, 'waaseyaa/config', 1, [
            'dialect' => ConfigSchemaRegistry::DIALECT_V1,
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
            'required' => ['title'],
        ]);
        $registry->freeze();
        $file = ConfigSyncFile::writable(
            entityType: 'system',
            entityId: 'site',
            uuid: ConfigSyncFile::deterministicUuid('system', 'site'),
            dependencies: [],
            langcode: 'en',
            fields: ['title' => 'Waaseyaa'],
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
        );
        $bytes = new ConfigSyncSerializer()->toYaml($file);
        $entry = new ValidatedConfigSyncEntry(
            $file,
            $bytes,
            new ConfigContentHasher()->hash($file, $bytes, $registry),
        );

        return ConfigSyncBundleManifest::fromValidatedBundle(
            new ConfigSyncBundleValidationResult([$entry], []),
            $registry,
            'site:test',
            $sequence,
            ['producer' => 'test-suite'],
            ['waaseyaa/config' => 1],
        );
    }
}

final readonly class TestConfigManifestSigner implements ConfigManifestSignerInterface
{
    public function __construct(private string $secret) {}

    public function algorithm(): string
    {
        return SignedConfigManifestEnvelope::ALGORITHM_V1;
    }

    public function trustKeyReference(): string
    {
        return 'cfg04:test-key';
    }

    public function sign(string $preAuthenticatedBytes): string
    {
        return hash_hmac('sha512', $preAuthenticatedBytes, $this->secret, true);
    }
}

final class TestConfigManifestSignatureVerifier implements ConfigManifestSignatureVerifierInterface
{
    public int $calls = 0;

    public function __construct(private string $secret) {}

    public function verify(string $trustKeyReference, string $algorithm, string $preAuthenticatedBytes, string $signature): bool
    {
        ++$this->calls;

        return $trustKeyReference === 'cfg04:test-key'
            && $algorithm === SignedConfigManifestEnvelope::ALGORITHM_V1
            && hash_equals(hash_hmac('sha512', $preAuthenticatedBytes, $this->secret, true), $signature);
    }
}

final class TestReplayStateReader implements ConfigReplayStateReaderInterface
{
    public int $reads = 0;

    public function __construct(private readonly ?int $last) {}

    public function lastCommittedSequence(string $bundleScope, string $trustKeyReference): ?int
    {
        ++$this->reads;

        return $this->last;
    }
}
