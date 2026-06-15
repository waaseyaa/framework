<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\CLI\Command\Audit\PruneCommand;
use Waaseyaa\CLI\CommandDefinition;
use Waaseyaa\CLI\OptionDefinition;
use Waaseyaa\CLI\OptionMode;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasNativeCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers the operator-facing `audit:*` CLI commands.
 *
 *  - `audit:prune` (FR-013, FR-014) — delete audit events older than a
 *    given ISO-8601 duration, with optional kind filter and --dry-run.
 *
 * @api
 */
final class AuditServiceProvider extends ServiceProvider implements HasNativeCommandsInterface
{
    public function register(): void
    {
        $this->singleton(
            PruneCommand::class,
            function (): PruneCommand {
                /** @var AuditQueryInterface $query */
                $query = $this->resolve(AuditQueryInterface::class);
                /** @var AuditWriterInterface $writer */
                $writer = $this->resolve(AuditWriterInterface::class);
                /** @var DatabaseInterface $db */
                $db = $this->resolve(DatabaseInterface::class);

                return new PruneCommand($query, $writer, $db);
            },
        );
    }

    public function nativeCommands(): iterable
    {
        yield new CommandDefinition(
            name: 'audit:prune',
            description: 'Delete audit events older than a given ISO-8601 duration (FR-013, FR-014). Emits a self-audit event on each real execution (FR-012).',
            options: [
                new OptionDefinition(
                    name: 'older-than',
                    mode: OptionMode::Required,
                    description: 'ISO-8601 duration (e.g. P30D, PT1H, P1Y). Events created before now()-duration are deleted.',
                    default: '',
                ),
                new OptionDefinition(
                    name: 'kind',
                    mode: OptionMode::Required,
                    description: 'Glob pattern for event kinds: * (all), entity.* (prefix), or a literal kind value.',
                    default: '*',
                ),
                new OptionDefinition(
                    name: 'dry-run',
                    mode: OptionMode::None,
                    description: 'Print the count that would be pruned without deleting.',
                ),
                new OptionDefinition(
                    name: 'confirm',
                    mode: OptionMode::None,
                    description: 'Required for real deletion. Without it the command refuses and prints the cutoff + row count it would delete.',
                ),
            ],
            handler: [PruneCommand::class, 'execute'],
        );
    }
}
