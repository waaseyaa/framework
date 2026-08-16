<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Jwks;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Oidc\Jwks\JwksController;
use Waaseyaa\Oidc\Jwks\JwksDocumentBuilder;
use Waaseyaa\Oidc\Key\SigningKeyLifecyclePolicy;
use Waaseyaa\Oidc\Keys\OpenSslKeyFactory;
use Waaseyaa\Oidc\Keys\SigningKey;
use Waaseyaa\Oidc\Keys\SigningKeySignerInterface;
use Waaseyaa\Oidc\Keys\SigningKeyState;
use Waaseyaa\Oidc\Token\KeyMaterialProviderInterface;

final class JwksControllerTest extends TestCase
{
    #[Test]
    public function publishes_every_live_public_key_with_policy_bounded_cache(): void
    {
        $keyPair = new OpenSslKeyFactory()->generateRsaKeyPair();
        $keys = [
            new SigningKey('active', 'RS256', $keyPair['public'], 1, SigningKeyState::ActiveSignAndVerify),
            new SigningKey('staged', 'RS256', $keyPair['public'], 2, SigningKeyState::StagedVerifyOnly),
            new SigningKey('retired', 'RS256', $keyPair['public'], 3, SigningKeyState::RetiredVerifyOnly),
            new SigningKey('revoked', 'RS256', $keyPair['public'], 4, SigningKeyState::Revoked),
        ];
        $provider = new class ($keys) implements KeyMaterialProviderInterface {
            /** @param list<SigningKey> $keys */
            public function __construct(private readonly array $keys) {}

            public function currentKey(): SigningKey
            {
                return $this->keys[0];
            }

            public function currentSigner(): SigningKeySignerInterface
            {
                throw new \LogicException('JWKS reads must not request a signer.');
            }

            public function allActive(): array
            {
                return $this->keys;
            }
        };
        $policy = new SigningKeyLifecyclePolicy(
            maximumTokenLifetimeSeconds: 100,
            maximumClockSkewSeconds: 3,
            jwksCacheLifetimeSeconds: 10,
            propagationMarginSeconds: 5,
        );

        $response = new JwksController($provider, new JwksDocumentBuilder(), $policy)();
        $document = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('max-age=10, public', $response->headers->get('Cache-Control'));
        self::assertSame(
            ['active', 'staged', 'retired'],
            array_column($document['keys'] ?? [], 'kid'),
        );
        self::assertStringNotContainsString('PRIVATE KEY', (string) $response->getContent());
    }
}
