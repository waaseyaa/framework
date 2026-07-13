<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Mcp\McpCapabilitiesSource;
use Waaseyaa\AI\Agent\Mcp\McpServiceProvider;

#[CoversNothing]
final class McpServiceProviderManifestTest extends TestCase
{
    #[Test]
    public function packageManifestDiscoversAndBootsOutboundMcpProvider(): void
    {
        $packageRoot = dirname(__DIR__, 3);
        $manifest = json_decode(
            (string) file_get_contents($packageRoot . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertContains(McpServiceProvider::class, $manifest['extra']['waaseyaa']['providers']);

        $provider = new McpServiceProvider();
        $provider->setKernelContext(dirname($packageRoot, 2), [], []);
        $provider->register();
        $provider->boot();

        self::assertInstanceOf(McpCapabilitiesSource::class, $provider->resolve(McpCapabilitiesSource::class));
    }
}
