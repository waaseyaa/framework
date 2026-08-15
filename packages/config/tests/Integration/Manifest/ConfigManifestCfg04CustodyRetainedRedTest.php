<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Integration\Manifest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Authority\ConfigurationAuthorityServiceProvider;
use Waaseyaa\Config\Manifest\ConfigManifestEd25519Signer;
use Waaseyaa\Config\Manifest\ConfigManifestEd25519Verifier;
use Waaseyaa\Config\Manifest\ConfigManifestSignatureVerifierInterface;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;
use Waaseyaa\Config\Manifest\ConfigManifestTrustPolicy;
use Waaseyaa\Config\Manifest\Ed25519ManifestSigningOperation;
use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretConsumptionException;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/** Retained-red proof for the CFG-03 interface / CFG-04 custody composition. */
final class ConfigManifestCfg04CustodyRetainedRedTest extends TestCase
{
    #[Test]
    public function guarded_signer_and_closed_trust_policy_compose_through_cfg03_interfaces(): void
    {
        [$secretKey, $publicKey] = $this->keyPair();
        $reference = $this->reference();
        $registry = $this->registry($secretKey);
        $signer = new ConfigManifestEd25519Signer(
            $registry,
            $reference,
            'cfg04:test-ed25519',
            $publicKey,
        );
        $policy = new ConfigManifestTrustPolicy([
            'cfg04:test-ed25519' => [
                'public_key' => base64_encode($publicKey),
                'algorithm' => SignedConfigManifestEnvelope::ALGORITHM_V1,
            ],
        ]);
        $verifier = new ConfigManifestEd25519Verifier($policy);
        $bytes = 'synthetic-cfg03-pae';
        $signature = $signer->sign($bytes);

        self::assertSame(SignedConfigManifestEnvelope::ALGORITHM_V1, $signer->algorithm());
        self::assertSame('cfg04:test-ed25519', $signer->trustKeyReference());
        self::assertSame(64, strlen($signature));
        self::assertTrue($verifier->verify(
            'cfg04:test-ed25519',
            SignedConfigManifestEnvelope::ALGORITHM_V1,
            $bytes,
            $signature,
        ));
    }

    #[Test]
    public function verification_cannot_bypass_algorithm_trust_or_revocation_policy(): void
    {
        [$secretKey, $publicKey] = $this->keyPair();
        $signer = new ConfigManifestEd25519Signer(
            $this->registry($secretKey),
            $this->reference(),
            'cfg04:test-ed25519',
            $publicKey,
        );
        $bytes = 'synthetic-cfg03-policy';
        $signature = $signer->sign($bytes);
        $trusted = new ConfigManifestEd25519Verifier(new ConfigManifestTrustPolicy([
            'cfg04:test-ed25519' => [
                'algorithm' => SignedConfigManifestEnvelope::ALGORITHM_V1,
                'public_key' => base64_encode($publicKey),
            ],
        ]));

        self::assertFalse($trusted->verify('cfg04:test-ed25519', 'RS256', $bytes, $signature));
        self::assertFalse($trusted->verify('cfg04:unknown', 'Ed25519', $bytes, $signature));
        self::assertFalse($trusted->verify('cfg04:test-ed25519', 'Ed25519', $bytes . '-tampered', $signature));
        self::assertFalse(new ConfigManifestEd25519Verifier(new ConfigManifestTrustPolicy(
            [
                'cfg04:test-ed25519' => [
                    'algorithm' => SignedConfigManifestEnvelope::ALGORITHM_V1,
                    'public_key' => base64_encode($publicKey),
                ],
            ],
            ['cfg04:test-ed25519'],
        ))->verify('cfg04:test-ed25519', 'Ed25519', $bytes, $signature));
    }

    #[Test]
    public function signer_diagnostic_and_serialization_surfaces_disclose_no_private_material(): void
    {
        [$secretKey, $publicKey] = $this->keyPair();
        $signer = new ConfigManifestEd25519Signer(
            $this->registry($secretKey),
            $this->reference(),
            'cfg04:test-ed25519',
            $publicKey,
        );
        $diagnostic = var_export($signer, true);

        self::assertStringNotContainsString(base64_encode($secretKey), $diagnostic);
        self::assertStringNotContainsString('tenant/config/manifest-signing', $diagnostic);
        try {
            clone $signer;
            self::fail('CFG-04 manifest signer was cloneable.');
        } catch (\Error) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\LogicException::class);
        serialize($signer);
    }

    #[Test]
    public function freshly_resolved_private_version_must_match_the_trusted_public_key(): void
    {
        [, $trustedPublicKey] = $this->keyPair();
        [$rotatedSecretKey] = $this->keyPair();
        $signer = new ConfigManifestEd25519Signer(
            $this->registry($rotatedSecretKey),
            $this->reference(),
            'cfg04:test-ed25519',
            $trustedPublicKey,
        );

        $this->expectException(SecretConsumptionException::class);
        $signer->sign('synthetic-stale-trust-reference');
    }

    #[Test]
    public function production_composition_binds_cfg03_interfaces_to_cfg04_custody(): void
    {
        [$secretKey, $publicKey] = $this->keyPair();
        $registry = new SecretResolverRegistry(new RedactorProcessor(), 'local');
        $registry->registerProvider(new SyntheticManifestSigningProvider($secretKey));
        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext('/synthetic/project', [
            'config_manifest_signing' => [
                'trust_keys' => [
                    'cfg04:production-composition' => [
                        'algorithm' => SignedConfigManifestEnvelope::ALGORITHM_V1,
                        'public_key' => base64_encode($publicKey),
                    ],
                ],
                'revoked_trust_keys' => [],
                'signing_key' => [
                    'trust_key_reference' => 'cfg04:production-composition',
                    'secret_reference' => [
                        'provider' => 'synthetic-vault',
                        'identifier' => 'tenant/config/manifest-signing',
                        'secret_class' => SecretClass::TokenSigningPrivateKey->value,
                        'purpose' => Ed25519ManifestSigningOperation::PURPOSE,
                    ],
                ],
            ],
        ], []);
        $provider->setKernelServices(new ManifestSigningKernelServices($registry));
        $provider->register();
        $registry->freeze();

        $signer = $provider->resolve(ConfigManifestSignerInterface::class);
        $verifier = $provider->resolve(ConfigManifestSignatureVerifierInterface::class);
        self::assertInstanceOf(ConfigManifestEd25519Signer::class, $signer);
        self::assertInstanceOf(ConfigManifestEd25519Verifier::class, $verifier);
        $bytes = 'synthetic-production-composition';
        $signature = $signer->sign($bytes);

        self::assertTrue($verifier->verify(
            $signer->trustKeyReference(),
            $signer->algorithm(),
            $bytes,
            $signature,
        ));
    }

    #[Test]
    public function verifier_only_composition_fails_closed_without_publishing_a_signer(): void
    {
        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext('/synthetic/project', ['environment' => 'testing'], []);
        $provider->register();

        $verifier = $provider->resolve(ConfigManifestSignatureVerifierInterface::class);
        self::assertInstanceOf(ConfigManifestEd25519Verifier::class, $verifier);
        self::assertFalse($verifier->verify('cfg04:unknown', 'Ed25519', 'bytes', str_repeat('x', 64)));
        self::assertNull($provider->resolveOptional(ConfigManifestSignerInterface::class));
    }

    #[Test]
    public function unknown_manifest_signing_fields_are_rejected(): void
    {
        $malformed = new ConfigurationAuthorityServiceProvider();
        $malformed->setKernelContext('/synthetic/project', [
            'environment' => 'testing',
            'config_manifest_signing' => [
                'private_key' => base64_encode(random_bytes(64)),
            ],
        ], []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown field');
        $malformed->register();
    }

    #[Test]
    public function raw_private_material_cannot_occupy_the_public_key_field(): void
    {
        [$secretKey] = $this->keyPair();
        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext('/synthetic/project', [
            'config_manifest_signing' => [
                'trust_keys' => [
                    'cfg04:private-material' => [
                        'algorithm' => SignedConfigManifestEnvelope::ALGORITHM_V1,
                        'public_key' => base64_encode($secretKey),
                    ],
                ],
            ],
        ], []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not canonical Ed25519 material');
        $provider->register();
    }

    /** @return array{string, string} */
    private function keyPair(): array
    {
        $pair = sodium_crypto_sign_seed_keypair(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));

        return [
            sodium_crypto_sign_secretkey($pair),
            sodium_crypto_sign_publickey($pair),
        ];
    }

    private function reference(): SecretReference
    {
        return SecretReference::create(
            'synthetic-vault',
            'tenant/config/manifest-signing',
            SecretClass::TokenSigningPrivateKey,
            Ed25519ManifestSigningOperation::PURPOSE,
        );
    }

    private function registry(string $secretKey): SecretResolverRegistry
    {
        $registry = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $registry->registerProvider(new SyntheticManifestSigningProvider($secretKey));
        $registry->allow(
            'synthetic-vault',
            'waaseyaa/config',
            SecretClass::TokenSigningPrivateKey,
            Ed25519ManifestSigningOperation::PURPOSE,
            ['testing'],
        );
        $registry->registerConsumer('waaseyaa/config', Ed25519ManifestSigningOperation::class);
        $registry->freeze();

        return $registry;
    }
}

final readonly class SyntheticManifestSigningProvider implements SecretProviderInterface
{
    public function __construct(#[\SensitiveParameter] private string $secretKey) {}

    public function id(): string
    {
        return 'synthetic-vault';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        return SensitiveValue::fromBytes(
            $this->secretKey,
            SecretClass::TokenSigningPrivateKey,
            'synthetic-v1',
        );
    }
}

final readonly class ManifestSigningKernelServices implements KernelServicesInterface
{
    public function __construct(private SecretResolverRegistry $registry) {}

    public function get(string $abstract): ?object
    {
        return $abstract === SecretResolverRegistry::class ? $this->registry : null;
    }
}
