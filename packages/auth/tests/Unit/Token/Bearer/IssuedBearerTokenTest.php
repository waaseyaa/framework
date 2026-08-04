<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Token\Bearer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Token\Bearer\BearerTokenRecord;
use Waaseyaa\Auth\Token\Bearer\IssuedBearerToken;

#[CoversClass(IssuedBearerToken::class)]
final class IssuedBearerTokenTest extends TestCase
{
    private const string SECRET = 'mbt_0123456789abcdef.' . 'f0e1d2c3b4a5968778695a4b3c2d1e0f'
        . 'f0e1d2c3b4a5968778695a4b3c2d1e0f';

    private function issued(): IssuedBearerToken
    {
        $now = new \DateTimeImmutable('2026-08-03 10:00:00', new \DateTimeZone('UTC'));

        return new IssuedBearerToken(
            record: new BearerTokenRecord(
                id: 'mbt_0123456789abcdef',
                accountUid: 42,
                audience: 'mcp:write',
                scopes: ['present guided content'],
                label: 'ci-agent',
                fingerprint: 'aabbccddeeff0011',
                issuedAt: $now,
                expiresAt: $now->modify('+1 hour'),
            ),
            secret: self::SECRET,
        );
    }

    #[Test]
    public function debug_output_redacts_the_secret(): void
    {
        $dump = print_r($this->issued(), true);

        self::assertStringNotContainsString(self::SECRET, $dump);

        ob_start();
        var_dump($this->issued());
        $varDump = (string) ob_get_clean();
        self::assertStringNotContainsString(self::SECRET, $varDump);
    }

    #[Test]
    public function json_encoding_redacts_the_secret(): void
    {
        $json = json_encode($this->issued(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::SECRET, $json);
        self::assertStringContainsString('mbt_0123456789abcdef', $json, 'the non-secret id may appear');
    }

    #[Test]
    public function php_serialization_of_the_one_time_reveal_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        serialize($this->issued());
    }
}
