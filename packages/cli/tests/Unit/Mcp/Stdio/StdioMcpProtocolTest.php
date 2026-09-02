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
    #[Test]
    public function every_supported_version_is_echoed_back_verbatim(): void
    {
        foreach (StdioMcpProtocol::SUPPORTED as $version) {
            self::assertSame($version, StdioMcpProtocol::negotiate($version));
        }
    }

    #[Test]
    public function an_unknown_version_falls_back_to_current(): void
    {
        self::assertSame(StdioMcpProtocol::CURRENT, StdioMcpProtocol::negotiate('2099-01-01'));
        self::assertSame(StdioMcpProtocol::CURRENT, StdioMcpProtocol::negotiate(''));
    }

    #[Test]
    public function current_is_a_member_of_supported(): void
    {
        self::assertContains(StdioMcpProtocol::CURRENT, StdioMcpProtocol::SUPPORTED);
    }
}
