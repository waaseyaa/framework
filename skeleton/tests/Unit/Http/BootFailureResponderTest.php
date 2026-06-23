<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\BootFailureResponder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BootFailureResponderTest extends TestCase
{
    #[Test]
    public function production_hides_the_raw_exception_message(): void
    {
        // A boot failure must not leak internals (DSNs, paths) to the client
        // when APP_DEBUG is off — the front controller previously returned
        // $e->getMessage() unconditionally.
        $e = new \RuntimeException('SQLSTATE: pgsql://app:s3cr3t@db.internal/prod failed');

        foreach (['0', '', 'false', false, null] as $appDebug) {
            $detail = BootFailureResponder::detail($e, $appDebug);
            self::assertStringNotContainsString('s3cr3t', $detail);
            self::assertStringNotContainsString('pgsql', $detail);
            self::assertStringNotContainsString('db.internal', $detail);
        }
    }

    #[Test]
    public function debug_shows_the_raw_exception_message(): void
    {
        $e = new \RuntimeException('boom: the real detail');

        foreach (['1', 'true', 'on'] as $appDebug) {
            self::assertSame('boom: the real detail', BootFailureResponder::detail($e, $appDebug));
        }
    }
}
