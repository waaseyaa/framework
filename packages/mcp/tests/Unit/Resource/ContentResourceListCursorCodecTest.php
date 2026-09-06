<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\Resource\ContentResourceListResume;
use Waaseyaa\Mcp\Resource\ContentResourceListCursorCodec;
use Waaseyaa\Mcp\Tests\Support\McpContentResourceListCursorKeyring;

#[CoversClass(ContentResourceListCursorCodec::class)]
#[CoversClass(ContentResourceListResume::class)]
final class ContentResourceListCursorCodecTest extends TestCase
{
    #[Test]
    public function sealed_cursors_round_trip_and_refuse_tamper_expiry_and_binding_mismatch(): void
    {
        $now = 1_700_000_000;
        $codec = new ContentResourceListCursorCodec(
            McpContentResourceListCursorKeyring::create(),
            static fn(): int => $now,
        );
        $binding = hash('sha256', 'mcp-content-resource-list|7');
        $resume = new ContentResourceListResume('search', 'srv1token');

        $cursor = $codec->seal($resume, $binding);
        self::assertSame($resume->provider, $codec->open($cursor, $binding)->provider);
        self::assertSame($resume->token, $codec->open($cursor, $binding)->token);
        self::assertNotSame($resume->token, $cursor);

        foreach ([
            $cursor . 'x',
            substr($cursor, 0, -4) . 'AAAA',
            '',
        ] as $tampered) {
            try {
                $codec->open($tampered, $binding);
                self::fail('Expected tampered cursor refusal.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        try {
            $codec->open($cursor, hash('sha256', 'other'));
            self::fail('Expected principal-binding refusal.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $expired = new ContentResourceListCursorCodec(
            McpContentResourceListCursorKeyring::create(),
            static fn(): int => $now + ContentResourceListCursorCodec::DEFAULT_TTL_SECONDS + 1,
        );
        try {
            $expired->open($cursor, $binding);
            self::fail('Expected expiry refusal.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    #[Test]
    public function codec_rejects_invalid_ttls_bindings_wire_documents_and_sealed_claims(): void
    {
        $keyring = McpContentResourceListCursorKeyring::create();
        foreach ([0, 86_401] as $ttl) {
            try {
                new ContentResourceListCursorCodec($keyring, ttlSeconds: $ttl);
                self::fail('Expected invalid cursor TTL refusal.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $codec = new ContentResourceListCursorCodec($keyring, static fn(): int => 1_700_000_000);
        $principal = new class implements \Waaseyaa\Access\AuthorizationPrincipalInterface {
            public function id(): int|string { return 'principal-7'; }
            public function hasPermission(string $permission): bool { return true; }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return true; }
            public function claimsGeneration(): string { return 'cursor-codec-test'; }
            public function tenantId(): ?string { return null; }
            public function communityId(): ?string { return null; }
        };
        $binding = ContentResourceListCursorCodec::principalBinding($principal);
        self::assertSame(hash('sha256', 'mcp-content-resource-list|principal-7'), $binding);

        foreach (['', str_repeat('A', 64)] as $invalidBinding) {
            try {
                $codec->seal(new ContentResourceListResume('search', 'token'), $invalidBinding);
                self::fail('Expected invalid principal binding refusal.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        foreach ([
            'A',
            $this->wire('not-an-envelope'),
            $this->wire([]),
        ] as $invalidCursor) {
            try {
                $codec->open($invalidCursor, $binding);
                self::fail('Expected invalid cursor document refusal.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        foreach ([
            ['v' => 2, 'exp' => 1_700_000_100, 'p' => 'search', 't' => 'token', 'b' => $binding],
            ['v' => 1, 'exp' => 'soon', 'p' => 'search', 't' => 'token', 'b' => $binding],
            ['v' => 1, 'exp' => 1_700_000_100, 'p' => 'Not-Wire-Safe', 't' => 'token', 'b' => $binding],
        ] as $claims) {
            $envelope = $keyring->seal(
                ContentResourceListCursorCodec::PURPOSE,
                ContentResourceListCursorCodec::RECORD_IDENTITY,
                ContentResourceListCursorCodec::SCHEMA_VERSION,
                json_encode($claims, JSON_THROW_ON_ERROR),
            );
            try {
                $codec->open($this->wire($envelope->toArray()), $binding);
                self::fail('Expected invalid sealed cursor claims refusal.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function wire(mixed $value): string
    {
        return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
