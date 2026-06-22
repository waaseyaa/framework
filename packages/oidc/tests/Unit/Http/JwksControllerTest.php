<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Waaseyaa\Oidc\Http\JwksController;
use Waaseyaa\Oidc\Keys\OidcKeyLoaderInterface;
use Waaseyaa\Oidc\Keys\OpenSslKeyFactory;
use Waaseyaa\Oidc\Keys\SigningKey;

#[CoversClass(JwksController::class)]
final class JwksControllerTest extends TestCase
{
    #[Test]
    public function serveReturnsJsonResponseWithKeysArray(): void
    {
        $controller = new JwksController(
            keyLoader: $this->loaderReturning([$this->generateSigningKey('key-1')]),
        );

        $response = $controller->serve();

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('keys', $body);
        self::assertCount(1, $body['keys']);
    }

    #[Test]
    public function eachJwkDeclaresRequiredRsaFields(): void
    {
        $controller = new JwksController(
            keyLoader: $this->loaderReturning([$this->generateSigningKey('alpha-1')]),
        );

        $body = json_decode((string) $controller->serve()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $jwk = $body['keys'][0];

        self::assertSame('RSA', $jwk['kty']);
        self::assertSame('sig', $jwk['use']);
        self::assertSame('RS256', $jwk['alg']);
        self::assertSame('alpha-1', $jwk['kid']);
        self::assertArrayHasKey('n', $jwk);
        self::assertArrayHasKey('e', $jwk);
        self::assertIsString($jwk['n']);
        self::assertIsString($jwk['e']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk['n']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk['e']);
        self::assertStringNotContainsString('=', $jwk['n']);
        self::assertStringNotContainsString('=', $jwk['e']);
    }

    #[Test]
    public function jwkModulusAndExponentMatchOpensslKeyDetails(): void
    {
        $signingKey = $this->generateSigningKey('match-key');
        $controller = new JwksController(keyLoader: $this->loaderReturning([$signingKey]));

        $body = json_decode((string) $controller->serve()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $jwk = $body['keys'][0];

        $pub = openssl_pkey_get_public($signingKey->publicKeyPem);
        self::assertNotFalse($pub);
        $details = openssl_pkey_get_details($pub);
        self::assertIsArray($details);

        self::assertSame($this->base64UrlEncode($details['rsa']['n']), $jwk['n']);
        self::assertSame($this->base64UrlEncode($details['rsa']['e']), $jwk['e']);
    }

    #[Test]
    public function serveEmitsKeysInLoaderOrder(): void
    {
        $controller = new JwksController(
            keyLoader: $this->loaderReturning([
                $this->generateSigningKey('a-key'),
                $this->generateSigningKey('z-key'),
            ]),
        );

        $body = json_decode((string) $controller->serve()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            ['a-key', 'z-key'],
            array_map(static fn(array $jwk): string => $jwk['kid'], $body['keys']),
        );
    }

    #[Test]
    public function serveRaisesWhenLoaderReturnsNoKeys(): void
    {
        $controller = new JwksController(keyLoader: $this->loaderReturning([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no signing keys');

        $controller->serve();
    }

    #[Test]
    public function serveRaisesWhenKeyIsNotRsa(): void
    {
        $publicKeyPem = new OpenSslKeyFactory()->generateEcPublicKey();

        $controller = new JwksController(
            keyLoader: $this->loaderReturning([
                new SigningKey(kid: 'ec-key', algorithm: 'RS256', publicKeyPem: $publicKeyPem),
            ]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not RSA');

        $controller->serve();
    }

    private function generateSigningKey(string $kid): SigningKey
    {
        $keyPair = new OpenSslKeyFactory()->generateRsaKeyPair();

        return new SigningKey(kid: $kid, algorithm: 'RS256', publicKeyPem: $keyPair['public']);
    }

    /**
     * @param list<SigningKey> $keys
     */
    private function loaderReturning(array $keys): OidcKeyLoaderInterface
    {
        return new class($keys) implements OidcKeyLoaderInterface {
            /** @param list<SigningKey> $keys */
            public function __construct(private readonly array $keys) {}

            public function loadSigningKeys(): array
            {
                return $this->keys;
            }
        };
    }

    private function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
