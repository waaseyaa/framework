<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Integration\Manifest;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Manifest\ConfigManifestEd25519Signer;
use Waaseyaa\Config\Manifest\ConfigManifestEd25519Verifier;
use Waaseyaa\Config\Manifest\ConfigManifestTrustPolicy;
use Waaseyaa\Config\Manifest\Ed25519ManifestSigningOperation;
use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;

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
        );
        $policy = new ConfigManifestTrustPolicy([
            'cfg04:test-ed25519' => [
                'algorithm' => SignedConfigManifestEnvelope::ALGORITHM_V1,
                'public_key' => base64_encode($publicKey),
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
        [$secretKey] = $this->keyPair();
        $signer = new ConfigManifestEd25519Signer(
            $this->registry($secretKey),
            $this->reference(),
            'cfg04:test-ed25519',
        );
        $diagnostic = var_export($signer, true);

        self::assertStringNotContainsString(base64_encode($secretKey), $diagnostic);
        self::assertStringNotContainsString('tenant/config/manifest-signing', $diagnostic);
        $this->expectException(\LogicException::class);
        serialize($signer);
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
