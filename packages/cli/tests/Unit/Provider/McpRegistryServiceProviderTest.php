<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\Mcp\McpRegistryManifestCommand;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Provider\McpRegistryServiceProvider;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageGate;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Mcp\McpImplementationInfo;
use Waaseyaa\Mcp\Registry\McpRegistryManifest;
use Waaseyaa\Mcp\Registry\McpRegistryManifestConfig;

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
    public function registered_command_resolves_the_host_manifest_through_kernel_services(): void
    {
        $manifest = new McpRegistryManifest(
            McpRegistryManifestConfig::fromArray([
                'name' => 'io.github.waaseyaa/framework',
                'description' => 'Access-controlled CMS content and editorial tools',
                'remote_url' => 'https://cms.example/mcp',
            ]),
            new McpImplementationInfo('Waaseyaa', '0.1.0-alpha.286'),
        );
        $provider = new McpRegistryServiceProvider();
        $provider->setKernelServices(new readonly class ($manifest) implements KernelServicesInterface {
            public function __construct(private McpRegistryManifest $manifest) {}

            public function get(string $abstract): ?object
            {
                return $abstract === McpRegistryManifest::class ? $this->manifest : null;
            }
        });
        $provider->register();

        $command = $provider->resolve(McpRegistryManifestCommand::class);
        self::assertInstanceOf(McpRegistryManifestCommand::class, $command);
        $stdout = new BufferedOutput();
        $exit = $command->execute(new SymfonyCommandIO(new ArrayInput([]), $stdout));

        self::assertSame(0, $exit);
        self::assertSame($manifest->toJson(), $stdout->fetch());
    }

    #[Test]
    public function registered_command_refuses_resolution_without_kernel_services(): void
    {
        $provider = new McpRegistryServiceProvider();
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires the kernel-services bus');
        $provider->resolve(McpRegistryManifestCommand::class);
    }

    #[Test]
    public function registered_command_refuses_a_wrong_host_service_type(): void
    {
        $provider = new McpRegistryServiceProvider();
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                return $abstract === McpRegistryManifest::class ? new \stdClass() : null;
            }
        });
        $provider->register();
        $command = $provider->resolve(McpRegistryManifestCommand::class);
        self::assertInstanceOf(McpRegistryManifestCommand::class, $command);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a host-bound McpRegistryManifest');
        $command->execute(new SymfonyCommandIO(new ArrayInput([]), new BufferedOutput()));
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
