<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;

final class EntityMutationTokenTest extends TestCase
{
    #[Test]
    public function opaqueTokenRoundTripsEveryAuthoritySelectorCanonically(): void
    {
        $token = EntityMutationToken::issue(
            storageAuthority: 'primary',
            tenantId: 'community-a',
            entityTypeId: 'node',
            entityId: '42',
            aggregateVersion: 7,
            tag: str_repeat("\xA5", 32),
        );

        $encoded = $token->toOpaqueString();
        $decoded = EntityMutationToken::fromOpaqueString($encoded);

        self::assertStringStartsWith('emt1.', $encoded);
        self::assertSame($encoded, $decoded->toOpaqueString());
        self::assertSame('primary', $decoded->storageAuthority);
        self::assertSame('community-a', $decoded->tenantId);
        self::assertSame('node', $decoded->entityTypeId);
        self::assertSame('42', $decoded->entityId);
        self::assertSame(7, $decoded->aggregateVersion);
        self::assertSame('"' . $encoded . '"', $decoded->toStrongEtag());
    }

    #[Test]
    public function malformedNonCanonicalAndWeakValidatorsFailClosed(): void
    {
        foreach (['', 'garbage', 'emt1.', 'W/"emt1.invalid"', '*', '"a", "b"'] as $invalid) {
            try {
                EntityMutationToken::fromHttpIfMatch($invalid);
                self::fail("Invalid validator was accepted: {$invalid}");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function issueRejectsEmptyIdentityInvalidVersionAndWrongTagLength(): void
    {
        $valid = [
            'storageAuthority' => 'primary',
            'tenantId' => 'community-a',
            'entityTypeId' => 'node',
            'entityId' => '42',
            'aggregateVersion' => 1,
            'tag' => str_repeat('x', 32),
        ];

        foreach ([
            ['storageAuthority' => ''],
            ['tenantId' => ''],
            ['entityTypeId' => ''],
            ['entityId' => ''],
            ['aggregateVersion' => 0],
            ['tag' => 'short'],
        ] as $replacement) {
            $this->expectInvalidIssue(array_replace($valid, $replacement));
        }
    }

    /** @param array<string, mixed> $arguments */
    private function expectInvalidIssue(array $arguments): void
    {
        try {
            EntityMutationToken::issue(...$arguments);
            self::fail('Invalid mutation token authority was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }
}
