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
    public function publish_returns_false_when_not_configured(): void
    {
        $publisher = new MercurePublisher('', '');
        $this->assertFalse($publisher->publish('topic', ['data' => 'test']));
    }

    #[Test]
    public function generates_jwt_with_expiry_and_publish_claim(): void
    {
        $publisher = new MercurePublisher('https://hub.example.com', 'test-secret');

        $method = new \ReflectionMethod($publisher, 'generateJwt');
        $jwt = $method->invoke($publisher);

        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'JWT must have 3 parts');

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertSame('HS256', $header['alg']);
        $this->assertSame('JWT', $header['typ']);

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $this->assertSame(['publish' => ['*']], $payload['mercure']);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertGreaterThan($payload['iat'], $payload['exp']);
    }
}
