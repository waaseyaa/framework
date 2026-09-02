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
 * `waaseyaa/ai-tools` is a hard `require` of this package, not optional: it is
 * already part of `waaseyaa/framework`'s and `waaseyaa/full`'s production
 * closure (it is where the transport-neutral dispatch contracts extracted by
 * #2657 live), so gating it here would buy nothing and would misstate which
 * package this command actually depends on conditionally.
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
