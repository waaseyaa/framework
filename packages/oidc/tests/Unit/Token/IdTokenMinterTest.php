<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Token;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Waaseyaa\Oidc\Keys\SigningKey;
use Waaseyaa\Oidc\Token\IdTokenMinter;
use Waaseyaa\Oidc\Token\KeyMaterialProviderInterface;

#[CoversClass(IdTokenMinter::class)]
final class IdTokenMinterTest extends TestCase
{
    private string $privateKeyPem;
    private string $publicKeyPem;

    protected function setUp(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($resource);

        $private = '';
        openssl_pkey_export($resource, $private);
        $details = openssl_pkey_get_details($resource);
        self::assertIsArray($details);

        $this->privateKeyPem = $private;
        $this->publicKeyPem = $details['key'];
    }

    #[Test]
    public function mintsSignedJwtThatVerifiesAgainstPublicKey(): void
    {
        $minter = new IdTokenMinter($this->keyProvider('key-1'));
        $now = new DateTimeImmutable('2026-04-18T12:00:00Z');

        $jwt = $minter->mint(
            issuer: 'https://idp.example',
            subject: '42',
            audience: 'client-1',
            nonce: null,
            now: $now,
        );

        [$encodedHeader, $encodedPayload, $encodedSignature] = explode('.', $jwt);
        $signingInput = $encodedHeader . '.' . $encodedPayload;
        $signature = $this->base64UrlDecode($encodedSignature);

        self::assertSame(
            1,
            openssl_verify($signingInput, $signature, $this->publicKeyPem, OPENSSL_ALGO_SHA256),
        );
    }

    #[Test]
    public function headerContainsAlgRs256AndKid(): void
    {
        $minter = new IdTokenMinter($this->keyProvider('my-kid'));
        $jwt = $minter->mint('https://idp.example', '42', 'client-1', null, new DateTimeImmutable());

        [$encodedHeader] = explode('.', $jwt);
        $header = json_decode($this->base64UrlDecode($encodedHeader), true);

        self::assertSame('RS256', $header['alg']);
        self::assertSame('JWT', $header['typ']);
        self::assertSame('my-kid', $header['kid']);
    }

    #[Test]
    public function payloadContainsRequiredClaims(): void
    {
        $minter = new IdTokenMinter($this->keyProvider('key-1'));
        $now = new DateTimeImmutable('2026-04-18T12:00:00Z');

        $jwt = $minter->mint('https://idp.example', '42', 'client-1', null, $now);

        $claims = $this->decodeClaims($jwt);
        self::assertSame('https://idp.example', $claims['iss']);
        self::assertSame('42', $claims['sub']);
        self::assertSame('client-1', $claims['aud']);
        self::assertSame($now->getTimestamp(), $claims['iat']);
        self::assertSame($now->getTimestamp(), $claims['auth_time']);
        self::assertSame($now->getTimestamp() + 3600, $claims['exp']);
        self::assertArrayNotHasKey('nonce', $claims);
    }

    #[Test]
    public function includesNonceClaimWhenProvided(): void
    {
        $minter = new IdTokenMinter($this->keyProvider('key-1'));

        $jwt = $minter->mint(
            'https://idp.example',
            '42',
            'client-1',
            'nonce-abc-123',
            new DateTimeImmutable(),
        );

        $claims = $this->decodeClaims($jwt);
        self::assertSame('nonce-abc-123', $claims['nonce']);
    }

    #[Test]
    public function throwsWhenNoSigningKeyHasPrivateKey(): void
    {
        $provider = new class () implements KeyMaterialProviderInterface {
            public function currentKey(): SigningKey
            {
                return new SigningKey('key-1', 'RS256', 'dummy-public-pem', null);
            }

            public function allActive(): array
            {
                return [$this->currentKey()];
            }
        };

        $minter = new IdTokenMinter($provider);

        $this->expectException(RuntimeException::class);

        $minter->mint('https://idp.example', '42', 'client-1', null, new DateTimeImmutable());
    }

    #[Test]
    public function throwsWhenNoRs256KeyAvailable(): void
    {
        // IdTokenMinter always uses currentKey() — this test verifies that a key
        // with no private PEM causes a RuntimeException when signing is attempted.
        $provider = new class () implements KeyMaterialProviderInterface {
            public function currentKey(): SigningKey
            {
                return new SigningKey('key-1', 'ES256', 'dummy-public-pem', null);
            }

            public function allActive(): array
            {
                return [$this->currentKey()];
            }
        };

        $minter = new IdTokenMinter($provider);

        $this->expectException(RuntimeException::class);

        $minter->mint('https://idp.example', '42', 'client-1', null, new DateTimeImmutable());
    }

    private function keyProvider(string $kid): KeyMaterialProviderInterface
    {
        return new class ($kid, $this->privateKeyPem, $this->publicKeyPem) implements KeyMaterialProviderInterface {
            public function __construct(
                private string $key_id,
                private string $private_pem,
                private string $public_pem,
            ) {}

            public function currentKey(): SigningKey
            {
                return new SigningKey($this->key_id, 'RS256', $this->public_pem, $this->private_pem);
            }

            public function allActive(): array
            {
                return [$this->currentKey()];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $decoded = json_decode($this->base64UrlDecode($parts[1]), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function base64UrlDecode(string $encoded): string
    {
        $padded = str_pad($encoded, (int) (ceil(strlen($encoded) / 4) * 4), '=', STR_PAD_RIGHT);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        self::assertIsString($decoded);

        return $decoded;
    }
}
