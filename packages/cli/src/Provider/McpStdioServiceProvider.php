<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorPrincipal;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\Mcp\McpServeCommand;
use Waaseyaa\CLI\VersionResolver;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageGate;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers `mcp:serve` — the local stdio MCP transport (ADR-022 D-9.2, #2659).
 *
 * `waaseyaa/ai-agent` is only suggested by this package (it homes
 * {@see LocalOperatorPrincipal}, and ADR-022 D-3.0 / CP009 keep it out of
 * every production require closure of `waaseyaa/framework`, `core`, `cms`,
 * and `full`). When it is absent this provider binds nothing and yields no
 * command — a `--no-dev` consumer without the local AI-development plane sees
 * zero `mcp:*` commands rather than one whose handler cannot resolve, matching
 * the `ai:*` precedent {@see AiServiceProvider} set for #2826.
 *
 * `waaseyaa/ai-tools` is optional for the same reason: `waaseyaa/cms` requires
 * `waaseyaa/cli` but does not otherwise install the Layer-5 AI tool plane.
 * Requiring it here would silently widen every CMS consumer's production
 * closure for a development-only command. Both sentinels must be present
 * before this provider registers anything.
 *
 * @api
 */
final class McpStdioServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface, RequiresOptionalPackagesInterface
{
    public static function optionalPackageRequirements(): iterable
    {
        yield new OptionalPackageRequirement(
            package: 'waaseyaa/ai-agent',
            sentinelClass: LocalOperatorPrincipal::class,
            purpose: 'the mcp:serve local stdio MCP transport (ADR-022 D-9.2), which constructs LocalOperatorPrincipal',
        );
        yield new OptionalPackageRequirement(
            package: 'waaseyaa/ai-tools',
            sentinelClass: ToolRegistryInterface::class,
            purpose: 'the mcp:serve local stdio MCP transport, which dispatches through the transport-neutral tool registry',
        );
    }

    public function register(): void
    {
        if (!OptionalPackageGate::satisfied($this)) {
            return;
        }

        $this->singleton(McpServeCommand::class, function (): McpServeCommand {
            $services = $this->kernelServices;
            if ($services === null) {
                throw new \RuntimeException('mcp:serve requires the kernel-services bus.');
            }

            $registry = $services->get(ToolRegistryInterface::class);
            if (!$registry instanceof ToolRegistryInterface) {
                throw new \RuntimeException('mcp:serve requires a host-bound ToolRegistryInterface (waaseyaa/ai-tools).');
            }

            $ledger = $services->get(StrictAuditLedgerInterface::class);
            if (!$ledger instanceof StrictAuditLedgerInterface) {
                throw new \RuntimeException('mcp:serve requires a host-bound StrictAuditLedgerInterface (waaseyaa/audit).');
            }

            $logger = $services->get(LoggerInterface::class);

            return new McpServeCommand(
                toolRegistry: $registry,
                auditLedger: $ledger,
                runtimeConfig: $this->config,
                serverVersion: new VersionResolver($this->projectRoot)->resolve(),
                logger: $logger instanceof LoggerInterface ? $logger : new NullLogger(),
            );
        });
    }

    public function consoleCommands(): iterable
    {
        if (!OptionalPackageGate::satisfied($this)) {
            return;
        }

        yield new HandlerCommand(
            name: 'mcp:serve',
            description: 'Serve the approved local developer tool catalogue over stdio MCP (ADR-022 D-9.2). '
                . 'Speaks conformant JSON-RPC on stdin/stdout only — every diagnostic goes to stderr.',
            options: [
                new HandlerOption(
                    name: 'profile',
                    mode: HandlerOptionMode::Required,
                    description: 'Tool profile to serve. Only "developer" (the ADR-022 D-7 default read-only allowlist) exists today.',
                    default: 'developer',
                ),
            ],
            handler: [McpServeCommand::class, 'execute'],
        );
    }
}
