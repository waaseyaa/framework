<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Publishing\Preview\PreviewLinkService;

#[CoversClass(PreviewLinkService::class)]
final class PreviewLinkServiceTest extends TestCase
{
    #[Test]
    public function issued_tokens_verify_until_expiry(): void
    {
        $now = 1_000_000;
        $service = new PreviewLinkService('secret-a', fn(): int => $now);
        $token = $service->issue('node', '42', 600);

        self::assertTrue($service->verify('node', '42', $token->expiresAt, $token->signature));
    }

    #[Test]
    public function expired_tokens_never_verify(): void
    {
        $now = 1_000_000;
        $service = new PreviewLinkService('secret-a', function () use (&$now): int {
            return $now;
        });
        $token = $service->issue('node', '42', 600);

        $now += 601;
        self::assertFalse($service->verify('node', '42', $token->expiresAt, $token->signature));
    }

    #[Test]
    public function tokens_are_bound_to_the_exact_entity(): void
    {
        $service = new PreviewLinkService('secret-a', fn(): int => 1_000_000);
        $token = $service->issue('node', '42', 600);

        self::assertFalse($service->verify('node', '43', $token->expiresAt, $token->signature));
        self::assertFalse($service->verify('media', '42', $token->expiresAt, $token->signature));
    }

    #[Test]
    public function forged_or_foreign_secret_signatures_fail(): void
    {
        $issuer = new PreviewLinkService('secret-a', fn(): int => 1_000_000);
        $verifier = new PreviewLinkService('secret-b', fn(): int => 1_000_000);
        $token = $issuer->issue('node', '42', 600);

        self::assertFalse($verifier->verify('node', '42', $token->expiresAt, $token->signature));
        self::assertFalse($issuer->verify('node', '42', $token->expiresAt, 'not-a-signature'));
        // Tampering with the expiry invalidates the signature (no TTL extension).
        self::assertFalse($issuer->verify('node', '42', $token->expiresAt + 3600, $token->signature));
    }
}
