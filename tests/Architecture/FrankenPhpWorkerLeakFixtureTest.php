<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * In-process red proof for the test-only FrankenPHP leak fixture.
 *
 * The hosted lane's `--inject-leak` run is the HTTP proof. This test pins that
 * the fixture actually retains static state across two includes in one process.
 */
#[CoversNothing]
final class FrankenPhpWorkerLeakFixtureTest extends TestCase
{
    #[Test]
    public function deliberate_static_retention_keeps_the_previous_request_mark(): void
    {
        $fixture = dirname(__DIR__, 2) . '/tests/Acceptance/FrankenPhpWorker/leak.php';
        self::assertFileExists($fixture);

        $_SERVER['HTTP_X_WAASEYAA_ACCEPTANCE_MARK'] = 'alice';
        $_SERVER['HTTP_X_WAASEYAA_ACCEPTANCE_COMMUNITY'] = 'community-a';
        require $fixture;
        self::assertSame('alice', \WaaseyaaFrankenphpAcceptanceLeakStore::$previousMark);
        self::assertSame('community-a', \WaaseyaaFrankenphpAcceptanceLeakStore::$previousCommunity);

        $_SERVER['HTTP_X_WAASEYAA_ACCEPTANCE_MARK'] = 'bob';
        $_SERVER['HTTP_X_WAASEYAA_ACCEPTANCE_COMMUNITY'] = 'community-b';
        require $fixture;
        self::assertSame('bob', \WaaseyaaFrankenphpAcceptanceLeakStore::$previousMark);
        self::assertSame('community-b', \WaaseyaaFrankenphpAcceptanceLeakStore::$previousCommunity);
    }
}
