<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\Mcp\McpRegistryManifestCommand;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageGate;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Mcp\Registry\McpRegistryManifest;

/**
 * Registers `mcp:registry-manifest` — emit official Registry `server.json` (#2638).
 *
 * `waaseyaa/mcp` is only suggested by this package. When it is absent the
 * provider binds nothing and yields no command, so a `--no-dev` consumer
 * without the HTTP MCP package sees zero registry-manifest command rather
 * than one whose handler cannot resolve (the MCP sibling of #2826 / #2828).
 * This is a different gate from {@see McpStdioServiceProvider}: the local
 * stdio transport needs the Layer-5 AI plane; this command needs only the
 * Layer-4 manifest emitter.
 *
 * @api
 */
final class McpRegistryServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface, RequiresOptionalPackagesInterface
{
    public static function optionalPackageRequirements(): iterable
    {
        yield new OptionalPackageRequirement(
            package: 'waaseyaa/mcp',
            sentinelClass: McpRegistryManifest::class,
            purpose: 'the mcp:registry-manifest command that emits official Registry server.json',
        );
    }

    public function register(): void
    {
        if (!OptionalPackageGate::satisfied($this)) {
            return;
        }

        $this->singleton(McpRegistryManifestCommand::class, function (): McpRegistryManifestCommand {
            $services = $this->kernelServices;
            if ($services === null) {
                throw new \RuntimeException('mcp:registry-manifest requires the kernel-services bus.');
            }

            return new McpRegistryManifestCommand(function () use ($services): McpRegistryManifest {
                $manifest = $services->get(McpRegistryManifest::class);
                if (!$manifest instanceof McpRegistryManifest) {
                    throw new \RuntimeException('mcp:registry-manifest requires a host-bound McpRegistryManifest (waaseyaa/mcp).');
                }

                return $manifest;
            });
        });
    }

    public function consoleCommands(): iterable
    {
        if (!OptionalPackageGate::satisfied($this)) {
            return;
        }

        yield new HandlerCommand(
            name: 'mcp:registry-manifest',
            description: 'Emit the official MCP Registry server.json for this deployment. '
                . 'Writes JSON to stdout. Does not publish to the Registry.',
            handler: [McpRegistryManifestCommand::class, 'execute'],
        );
    }
}
