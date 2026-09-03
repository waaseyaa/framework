<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Mcp\Stdio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Mcp\Stdio\StdioMcpProtocol;

#[CoversClass(StdioMcpProtocol::class)]
final class StdioMcpProtocolTest extends TestCase
{
    /**
     * The first modern-era MCP revision: no `initialize` handshake, per-request
     * `_meta` version and capabilities, mandatory `server/discover`. Named here
     * so the assertions below read as the deliberate exclusion they are.
     */
    private const string FIRST_MODERN_REVISION = '2026-07-28';

    /** The only handshake revision whose receivers MUST accept batches. */
    private const string BATCHING_REVISION = '2025-03-26';

    #[Test]
    public function every_supported_version_is_echoed_back_verbatim(): void
    {
        foreach (StdioMcpProtocol::SUPPORTED as $version) {
            self::assertSame($version, StdioMcpProtocol::negotiate($version));
        }
    }

    #[Test]
    public function an_unknown_version_falls_back_to_the_latest_handshake_revision(): void
    {
        self::assertSame(StdioMcpProtocol::LATEST_HANDSHAKE_REVISION, StdioMcpProtocol::negotiate('2099-01-01'));
        self::assertSame(StdioMcpProtocol::LATEST_HANDSHAKE_REVISION, StdioMcpProtocol::negotiate(''));
    }

    #[Test]
    public function the_modern_era_is_neither_supported_nor_negotiated(): void
    {
        // This transport implements the `initialize` lifecycle, which the
        // modern era does not have. Advertising a modern revision would
        // promise `server/discover` and per-request `_meta` handling that
        // StdioMcpServer does not implement.
        self::assertNotContains(self::FIRST_MODERN_REVISION, StdioMcpProtocol::SUPPORTED);
        self::assertSame(
            StdioMcpProtocol::LATEST_HANDSHAKE_REVISION,
            StdioMcpProtocol::negotiate(self::FIRST_MODERN_REVISION),
        );
    }

    #[Test]
    public function the_batching_revision_is_not_advertised_by_a_server_that_rejects_batches(): void
    {
        self::assertNotContains(self::BATCHING_REVISION, StdioMcpProtocol::SUPPORTED);
        self::assertSame(
            StdioMcpProtocol::LATEST_HANDSHAKE_REVISION,
            StdioMcpProtocol::negotiate(self::BATCHING_REVISION),
        );
    }

    #[Test]
    public function every_supported_revision_predates_the_modern_era(): void
    {
        // The revisions are ISO-8601 dates, so a plain string comparison is a
        // valid ordering — anything sorting at or after the first modern
        // revision would be a lifecycle this server does not implement.
        foreach (StdioMcpProtocol::SUPPORTED as $version) {
            self::assertLessThan(self::FIRST_MODERN_REVISION, $version);
        }
    }

    #[Test]
    public function the_latest_handshake_revision_is_the_newest_member_of_supported(): void
    {
        self::assertContains(StdioMcpProtocol::LATEST_HANDSHAKE_REVISION, StdioMcpProtocol::SUPPORTED);
        self::assertSame(StdioMcpProtocol::LATEST_HANDSHAKE_REVISION, StdioMcpProtocol::SUPPORTED[0]);

        $sorted = StdioMcpProtocol::SUPPORTED;
        rsort($sorted);
        self::assertSame($sorted, StdioMcpProtocol::SUPPORTED, 'SUPPORTED must stay ordered newest-first.');
    }
}
