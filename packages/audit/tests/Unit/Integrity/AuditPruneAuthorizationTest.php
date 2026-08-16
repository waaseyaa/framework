<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Integrity\AuditPruneAuthorization;

#[CoversClass(AuditPruneAuthorization::class)]
final class AuditPruneAuthorizationTest extends TestCase
{
    #[Test]
    public function authorization_is_bound_to_checkpoint_hash_and_key(): void
    {
        $key = random_bytes(32);
        $checkpointHash = hash('sha256', 'checkpoint-one');
        $authorization = AuditPruneAuthorization::sign($checkpointHash, $key);

        self::assertMatchesRegularExpression(
            '/^hmac-sha256\.audit-prune\.hkdf-v1:[a-f0-9]{64}$/D',
            $authorization,
        );
        self::assertTrue(AuditPruneAuthorization::verify($authorization, $checkpointHash, $key));
        self::assertFalse(AuditPruneAuthorization::verify(
            $authorization,
            hash('sha256', 'checkpoint-two'),
            $key,
        ));
        self::assertFalse(AuditPruneAuthorization::verify($authorization, $checkpointHash, random_bytes(32)));
    }

    #[Test]
    public function checkpoint_signature_mac_cannot_be_reused_as_prune_authorization(): void
    {
        $key = random_bytes(32);
        $checkpointHash = hash('sha256', 'checkpoint');
        $checkpointSignature = 'hmac-sha256.hkdf-v1:' . hash_hmac('sha256', $checkpointHash, $key);

        self::assertFalse(AuditPruneAuthorization::verify($checkpointSignature, $checkpointHash, $key));
        self::assertFalse(AuditPruneAuthorization::verify('', $checkpointHash, $key));
    }
}
