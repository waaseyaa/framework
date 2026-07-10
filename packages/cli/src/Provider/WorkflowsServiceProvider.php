<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\WorkflowsBackfillStateHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers the `workflows:*` operator CLI commands (CW-v1 WP-2 task 2.7).
 *
 * Mirrors {@see HealthSchemaServiceProvider}'s `revisions:enable` registration
 * exactly: an empty `register()` (the handler's constructor dependencies —
 * {@see \Waaseyaa\Entity\EntityTypeManagerInterface}, an optional
 * {@see \Waaseyaa\Foundation\Log\LoggerInterface} — are container-autowireable,
 * so no manual singleton/factory binding is needed) and a `HandlerCommand`
 * yielded from `consoleCommands()`.
 *
 * @api
 */
final class WorkflowsServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'workflows:backfill-state',
            description: 'Stamp a workflow_state onto every existing row of an entity type/bundle that does not yet carry one. Run BEFORE adding the workflows.assignments binding (docs/specs/operations-playbooks.md).',
            arguments: [
                new HandlerArgument(
                    name: 'entity_type',
                    mode: HandlerArgumentMode::Required,
                    description: 'The entity type ID to backfill (e.g. node).',
                ),
                new HandlerArgument(
                    name: 'workflow_id',
                    mode: HandlerArgumentMode::Required,
                    description: 'The workflow config entity ID to backfill state from (e.g. editorial).',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'bundle',
                    mode: HandlerOptionMode::Required,
                    description: 'Restrict the backfill to a single bundle. Omit to backfill every bundle of the entity type.',
                ),
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Report what would change (counts per target state, sample ids) without writing anything.',
                ),
            ],
            handler: [WorkflowsBackfillStateHandler::class, 'execute'],
        );
    }
}
