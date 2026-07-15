<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Integrity\AuditChainVerifier;
use Waaseyaa\Audit\Integrity\AuditCheckpointBuilder;
use Waaseyaa\Audit\Integrity\LegacyCheckpointSignatureMigrator;
use Waaseyaa\CLI\Command\Audit\CheckpointCommand;
use Waaseyaa\CLI\Command\Audit\MigrateCheckpointSignaturesCommand;
use Waaseyaa\CLI\Command\Audit\PruneCommand;
use Waaseyaa\CLI\Command\Audit\VerifyCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers the operator-facing `audit:*` CLI commands.
 *
 *  - `audit:prune` (FR-013, FR-014) — delete audit events older than a
 *    given ISO-8601 duration, with optional kind filter and --dry-run.
 *  - `audit:checkpoint` (WP2) — seal the next batch of unsealed audit_event
 *    rows into a tamper-evidence checkpoint.
 *  - `audit:verify` (WP3) — verify the audit-log hash chain and all sealed
 *    checkpoints; exit non-zero on tamper detection.
 *
 * @api
 */
final class AuditServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
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

        $this->singleton(
            CheckpointCommand::class,
            function (): CheckpointCommand {
                /** @var AuditCheckpointBuilder $builder */
                $builder = $this->resolve(AuditCheckpointBuilder::class);

                return new CheckpointCommand($builder);
            },
        );

        $this->singleton(
            VerifyCommand::class,
            function (): VerifyCommand {
                /** @var DatabaseInterface $db */
                $db = $this->resolve(DatabaseInterface::class);
                /** @var AuditWriterInterface $writer */
                $writer = $this->resolve(AuditWriterInterface::class);
                $applicationSecret = $this->resolve(ApplicationSecret::class);
                assert($applicationSecret instanceof ApplicationSecret);

                return new VerifyCommand(
                    new AuditChainVerifier(
                        $db,
                        hmacKey: $applicationSecret->derive(ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC),
                    ),
                    $writer,
                );
            },
        );

        $this->singleton(
            MigrateCheckpointSignaturesCommand::class,
            function (): MigrateCheckpointSignaturesCommand {
                $applicationSecret = $this->resolve(ApplicationSecret::class);
                assert($applicationSecret instanceof ApplicationSecret);

                return new MigrateCheckpointSignaturesCommand(new LegacyCheckpointSignatureMigrator(
                    $this->resolve(DatabaseInterface::class),
                    $applicationSecret->derive(ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC),
                ));
            },
        );
    }

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'audit:prune',
            description: 'Delete audit events older than a given ISO-8601 duration (FR-013, FR-014). Emits a self-audit event on each real execution (FR-012).',
            options: [
                new HandlerOption(
                    name: 'older-than',
                    mode: HandlerOptionMode::Required,
                    description: 'ISO-8601 duration (e.g. P30D, PT1H, P1Y). Events created before now()-duration are deleted.',
                    default: '',
                ),
                new HandlerOption(
                    name: 'kind',
                    mode: HandlerOptionMode::Required,
                    description: 'Glob pattern for event kinds: * (all), entity.* (prefix), or a literal kind value.',
                    default: '*',
                ),
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Print the count that would be pruned without deleting.',
                ),
                new HandlerOption(
                    name: 'confirm',
                    mode: HandlerOptionMode::None,
                    description: 'Required for real deletion. Without it the command refuses and prints the cutoff + row count it would delete.',
                ),
            ],
            handler: [PruneCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'audit:checkpoint',
            description: 'Seal the next batch of unsealed audit_event rows into a tamper-evidence checkpoint (WP2). Exports the checkpoint via the configured CheckpointSink.',
            options: [],
            handler: [CheckpointCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'audit:verify',
            description: 'Verify the audit-log hash chain + checkpoints; exit non-zero on tamper.',
            options: [
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Output the result as a JSON object.',
                ),
            ],
            handler: [VerifyCommand::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'audit:migrate-checkpoint-signatures',
            description: 'Explicitly authenticate an intact wholly-legacy audit checkpoint chain with the application-derived key.',
            options: [
                new HandlerOption(
                    name: 'confirm',
                    mode: HandlerOptionMode::None,
                    description: 'Required after taking and independently verifying a trusted backup.',
                ),
            ],
            handler: [MigrateCheckpointSignaturesCommand::class, 'execute'],
        );
    }
}
