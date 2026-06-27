<?php

declare(strict_types=1);

namespace Waaseyaa\Mercure\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mercure\MercurePublisher;

#[CoversClass(MercurePublisher::class)]
final class MercurePublisherTest extends TestCase
{
    #[Test]
    public function is_not_configured_with_empty_hub_url(): void
    {
        $publisher = new MercurePublisher('', 'secret');
        $this->assertFalse($publisher->isConfigured());
    }

    #[Test]
    public function is_not_configured_with_empty_secret(): void
    {
        $publisher = new MercurePublisher('https://hub.example.com', '');
        $this->assertFalse($publisher->isConfigured());
    }

    #[Test]
    public function is_configured_with_both_values(): void
    {
        $publisher = new MercurePublisher('https://hub.example.com', 'secret');
        $this->assertTrue($publisher->isConfigured());
    }

    #[Test]
    public function is_not_configured_with_a_non_https_hub_url(): void
    {
        // A token POSTed to a cleartext hub leaks in transit — treat an http://
        // (or any non-https) hub as unconfigured so publish() never ships it.
        $this->assertFalse(new MercurePublisher('http://hub.example.com', 'secret')->isConfigured());
        $this->assertFalse(new MercurePublisher('ftp://hub.example.com', 'secret')->isConfigured());
        $this->assertFalse(new MercurePublisher('hub.example.com', 'secret')->isConfigured());
    }

    #[Test]
    public function publish_returns_false_when_not_configured(): void
    {
        $publisher = new MercurePublisher('', '');
        $this->assertFalse($publisher->publish('topic', ['data' => 'test']));
    }

    #[Test]
    public function generates_jwt_with_expiry_and_publish_claim_scoped_to_the_topic(): void
    {
        $publisher = new MercurePublisher('https://hub.example.com', 'test-secret');

        $method = new \ReflectionMethod($publisher, 'generateJwt');
        $jwt = $method->invoke($publisher, '/notes/42');

        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'JWT must have 3 parts');

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        // The claim must grant publish on the exact topic — never the '*' wildcard.
        $this->assertSame(['publish' => ['/notes/42']], $payload['mercure']);
        $this->assertNotContains('*', $payload['mercure']['publish']);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertGreaterThan($payload['iat'], $payload['exp']);
    }

    #[Test]
    public function scopes_the_publish_claim_to_a_different_topic(): void
    {
        $publisher = new MercurePublisher('https://hub.example.com', 'test-secret');
        $method = new \ReflectionMethod($publisher, 'generateJwt');

        $payload = json_decode(
            base64_decode(strtr(explode('.', $method->invoke($publisher, '/users/7/inbox'))[1], '-_', '+/')),
            true,
        );

        $this->assertSame(['publish' => ['/users/7/inbox']], $payload['mercure']);
    }

    #[Test]
    public function minted_jwt_signature_verifies_against_the_configured_secret(): void
    {
        $secret = 'test-secret';
        $publisher = new MercurePublisher('https://hub.example.com', $secret);

        $method = new \ReflectionMethod($publisher, 'generateJwt');
        $jwt = $method->invoke($publisher, '/notes/42');

        [$header, $payload, $signature] = explode('.', $jwt);
        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', "{$header}.{$payload}", $secret, true),
        ), '+/', '-_'), '=');

        $this->assertSame($expected, $signature, 'Signature must verify against the configured secret');
        // A wrong secret must NOT verify.
        $wrong = rtrim(strtr(base64_encode(
            hash_hmac('sha256', "{$header}.{$payload}", 'wrong-secret', true),
        ), '+/', '-_'), '=');
        $this->assertNotSame($wrong, $signature);
    }
}
