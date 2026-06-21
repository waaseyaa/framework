<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\FrankenPhp\Command\DevCommand;

/**
 * Regression guard for the dev server "launched but did not remain serving"
 * failure: a stale/orphaned FrankenPHP holding the listen port made a fresh
 * `composer run dev` print "Serving … http://…" and then exit on a buried
 * EADDRINUSE, so the browser got ERR_CONNECTION_REFUSED. The fix is a preflight
 * that detects the occupied address and fails fast instead of launching into a
 * doomed bind. These tests pin that detection.
 */
#[CoversClass(DevCommand::class)]
final class DevCommandPortPreflightTest extends TestCase
{
    #[Test]
    public function detects_a_genuinely_occupied_listen_address_and_clears_when_freed(): void
    {
        // Bind a real listening socket on a free ephemeral port, exactly the
        // condition an orphaned dev server creates.
        $errno = 0;
        $errstr = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($server, "could not open a test socket: {$errstr}");

        $listen = stream_socket_get_name($server, false); // "127.0.0.1:<port>"
        self::assertIsString($listen);

        // While the port is held, the launcher MUST see it as in use (so serve()
        // fails fast rather than printing a misleading "Serving").
        self::assertTrue(
            DevCommand::listenAddressInUse($listen),
            'an occupied listen address must be detected as in use',
        );

        // Once released, the same address is free again.
        fclose($server);
        self::assertFalse(
            DevCommand::listenAddressInUse($listen),
            'a freed listen address must report as available',
        );
    }

    #[Test]
    public function reflects_the_probe_result_via_injected_probe(): void
    {
        // Probe reports "something listening" → in use; "nothing there" → free.
        self::assertTrue(DevCommand::listenAddressInUse('127.0.0.1:8080', static fn(): bool => true));
        self::assertFalse(DevCommand::listenAddressInUse('127.0.0.1:8080', static fn(): bool => false));
    }

    #[Test]
    public function parses_host_and_port_forms_handed_to_the_binder(): void
    {
        $capture = static function (?array &$seen): callable {
            return static function (string $host, int $port) use (&$seen): bool {
                $seen = [$host, $port];

                return true;
            };
        };

        $seen = null;
        DevCommand::listenAddressInUse('0.0.0.0:9000', $capture($seen));
        self::assertSame(['0.0.0.0', 9000], $seen);

        $seen = null;
        DevCommand::listenAddressInUse('8080', $capture($seen)); // bare port → localhost
        self::assertSame(['127.0.0.1', 8080], $seen);

        $seen = null;
        DevCommand::listenAddressInUse(':8080', $capture($seen)); // empty host → localhost
        self::assertSame(['127.0.0.1', 8080], $seen);
    }

    #[Test]
    public function unparseable_address_never_blocks_a_launch(): void
    {
        // No port → the preflight defers to FrankenPHP's own validation and never
        // reports a false conflict (the binder is not even consulted).
        $called = false;
        $result = DevCommand::listenAddressInUse('not-an-address', static function () use (&$called): bool {
            $called = true;

            return false;
        });

        self::assertFalse($result);
        self::assertFalse($called, 'an unparseable address must short-circuit before binding');
    }
}
