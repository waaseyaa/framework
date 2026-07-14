<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Diagnostic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Diagnostic\CleanUrlProbe;
use Waaseyaa\Foundation\Diagnostic\DiagnosticCode;

#[CoversClass(CleanUrlProbe::class)]
final class CleanUrlProbeTest extends TestCase
{
    #[Test]
    public function it_passes_only_when_the_known_clean_url_reaches_the_router(): void
    {
        $requestedUrl = null;
        $probe = new CleanUrlProbe(
            'https://example.test/base/',
            static function (string $url) use (&$requestedUrl): array {
                $requestedUrl = $url;

                return ['status' => 200, 'body' => CleanUrlProbe::SENTINEL];
            },
        );

        $result = $probe->check();

        self::assertSame('https://example.test/base/.well-known/waaseyaa/clean-url', $requestedUrl);
        self::assertSame('pass', $result->status);
        self::assertSame('Clean URL routing', $result->name);
    }

    #[Test]
    public function it_fails_loudly_when_the_web_server_returns_a_404_before_the_router(): void
    {
        $probe = new CleanUrlProbe(
            'https://example.test',
            static fn(string $url): array => ['status' => 404, 'body' => '<h1>Not Found</h1>'],
        );

        $result = $probe->check();

        self::assertSame('fail', $result->status);
        self::assertSame(DiagnosticCode::CLEAN_URL_ROUTING_UNREACHABLE, $result->code);
        self::assertSame(['status' => 404], $result->context);
        self::assertStringContainsString('front controller', $result->message);
        self::assertStringContainsString('FallbackResource', $result->remediation);
    }

    #[Test]
    public function it_fails_loudly_when_the_self_probe_cannot_connect(): void
    {
        $probe = new CleanUrlProbe(
            'https://example.test',
            static fn(string $url): never => throw new \RuntimeException('connection refused'),
        );

        $result = $probe->check();

        self::assertSame('fail', $result->status);
        self::assertSame(DiagnosticCode::CLEAN_URL_ROUTING_UNREACHABLE, $result->code);
        self::assertStringContainsString('connection refused', $result->message);
    }
}
