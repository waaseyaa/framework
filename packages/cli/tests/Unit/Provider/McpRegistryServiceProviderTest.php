<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Provider\McpRegistryServiceProvider;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageGate;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;
use Waaseyaa\Mcp\Registry\McpRegistryManifest;

/**
 * `mcp:registry-manifest` is an optional contribution gated on `waaseyaa/mcp`
 * (#2638, the MCP sibling of #2826 / #2828). The monorepo always has the
 * package, so this test pins the declaration and the present-side behaviour.
 */
#[CoversClass(McpRegistryServiceProvider::class)]
final class McpRegistryServiceProviderTest extends TestCase
{
    #[Test]
    public function the_provider_declares_mcp_as_its_optional_package(): void
    {
        self::assertInstanceOf(RequiresOptionalPackagesInterface::class, new McpRegistryServiceProvider());

        $requirements = iterator_to_array(McpRegistryServiceProvider::optionalPackageRequirements(), false);
        self::assertCount(1, $requirements);
        self::assertSame('waaseyaa/mcp', $requirements[0]->package);
        self::assertSame(McpRegistryManifest::class, $requirements[0]->sentinelClass);

        $cliManifest = json_decode((string) file_get_contents(\dirname(__DIR__, 3) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('waaseyaa/mcp', $cliManifest['require'], 'cli must not require mcp unconditionally.');
        self::assertArrayHasKey('waaseyaa/mcp', $cliManifest['suggest'], 'The optional package stays declared in suggest.');

        $mcpManifest = json_decode((string) file_get_contents(\dirname(__DIR__, 4) . '/mcp/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $owned = false;
        foreach (array_keys($mcpManifest['autoload']['psr-4']) as $prefix) {
            $owned = $owned || str_starts_with($requirements[0]->sentinelClass, $prefix);
        }
        self::assertTrue($owned, 'The sentinel must be a class the optional package itself autoloads.');
    }

    #[Test]
    public function with_mcp_present_the_command_is_advertised(): void
    {
        self::assertTrue(OptionalPackageGate::satisfied(McpRegistryServiceProvider::class), 'The monorepo installs mcp.');

        $provider = new McpRegistryServiceProvider();
        $commands = iterator_to_array($provider->consoleCommands(), false);

        self::assertCount(1, $commands);
        self::assertInstanceOf(HandlerCommand::class, $commands[0]);
        self::assertSame('mcp:registry-manifest', $commands[0]->getName());
    }

    #[Test]
    public function register_and_console_commands_both_open_with_the_gate_check(): void
    {
        $source = (string) file_get_contents((string) new \ReflectionClass(McpRegistryServiceProvider::class)->getFileName());
        self::assertSame(
            2,
            substr_count($source, 'if (!OptionalPackageGate::satisfied($this)) {'),
            'register() and consoleCommands() must both open with the same gate check.',
        );
    }
}
