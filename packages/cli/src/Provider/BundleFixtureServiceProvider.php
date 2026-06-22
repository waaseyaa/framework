<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\BundleScaffoldHandler;
use Waaseyaa\CLI\Handler\FixtureGenerateHandler;
use Waaseyaa\CLI\Handler\FixturePackRefreshHandler;
use Waaseyaa\CLI\Handler\FixtureScaffoldHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class BundleFixtureServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'scaffold:bundle',
            description: 'Generate deterministic bundle scaffold JSON',
            options: [
                new HandlerOption(
                    name: 'id',
                    mode: HandlerOptionMode::Required,
                    description: 'Bundle machine name',
                ),
                new HandlerOption(
                    name: 'label',
                    mode: HandlerOptionMode::Required,
                    description: 'Bundle label',
                ),
                new HandlerOption(
                    name: 'entity-type',
                    mode: HandlerOptionMode::Required,
                    description: 'Entity type ID',
                    default: 'node',
                ),
                new HandlerOption(
                    name: 'workflow',
                    mode: HandlerOptionMode::Required,
                    description: 'Workflow config ID',
                    default: 'editorial_default',
                ),
                new HandlerOption(
                    name: 'field',
                    mode: HandlerOptionMode::Array_,
                    description: 'Field definition in name:type:required form (repeatable)',
                ),
            ],
            handler: [BundleScaffoldHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'fixture:scaffold',
            description: 'Generate deterministic workflow + relationship fixture scenario JSON',
            options: [
                new HandlerOption(
                    name: 'key',
                    mode: HandlerOptionMode::Required,
                    description: 'Scenario node key (machine name)',
                ),
                new HandlerOption(
                    name: 'title',
                    mode: HandlerOptionMode::Required,
                    description: 'Scenario node title',
                ),
                new HandlerOption(
                    name: 'bundle',
                    mode: HandlerOptionMode::Required,
                    description: 'Node bundle/type',
                    default: 'article',
                ),
                new HandlerOption(
                    name: 'workflow-state',
                    mode: HandlerOptionMode::Required,
                    description: 'Workflow state',
                    default: 'draft',
                ),
                new HandlerOption(
                    name: 'status',
                    mode: HandlerOptionMode::Required,
                    description: 'Publication status override (0/1)',
                ),
                new HandlerOption(
                    name: 'uid',
                    mode: HandlerOptionMode::Required,
                    description: 'Fixture author UID',
                    default: '1',
                ),
                new HandlerOption(
                    name: 'timestamp',
                    mode: HandlerOptionMode::Required,
                    description: 'Deterministic fixture timestamp',
                    default: '1735689600',
                ),
                new HandlerOption(
                    name: 'relationship-type',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional relationship type',
                ),
                new HandlerOption(
                    name: 'to-key',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional target fixture key for relationship',
                ),
                new HandlerOption(
                    name: 'relationship-status',
                    mode: HandlerOptionMode::Required,
                    description: 'Relationship status (0/1)',
                    default: '1',
                ),
                new HandlerOption(
                    name: 'output',
                    shortcut: 'o',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional output file path (.json)',
                ),
            ],
            handler: [FixtureScaffoldHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'fixture:generate',
            description: 'Generate deterministic fixture scenario JSON from topology templates',
            options: [
                new HandlerOption(
                    name: 'template',
                    mode: HandlerOptionMode::Required,
                    description: 'Template: fanout, chain, mixed-workflow',
                ),
                new HandlerOption(
                    name: 'count',
                    mode: HandlerOptionMode::Required,
                    description: 'Node count',
                    default: '8',
                ),
                new HandlerOption(
                    name: 'prefix',
                    mode: HandlerOptionMode::Required,
                    description: 'Node key prefix',
                    default: 'fixture',
                ),
                new HandlerOption(
                    name: 'bundle',
                    mode: HandlerOptionMode::Required,
                    description: 'Node bundle/type',
                    default: 'article',
                ),
                new HandlerOption(
                    name: 'timestamp',
                    mode: HandlerOptionMode::Required,
                    description: 'Deterministic base timestamp',
                    default: '1735689600',
                ),
                new HandlerOption(
                    name: 'output',
                    shortcut: 'o',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional output file path (.json)',
                ),
            ],
            handler: [FixtureGenerateHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'fixture:pack:refresh',
            description: 'Refresh deterministic fixture-pack aggregate from scenario JSON files',
            options: [
                new HandlerOption(
                    name: 'input-dir',
                    mode: HandlerOptionMode::Required,
                    description: 'Directory containing scenario .json files',
                    default: 'tests/fixtures/scenarios',
                ),
                new HandlerOption(
                    name: 'output',
                    shortcut: 'o',
                    mode: HandlerOptionMode::Required,
                    description: 'Output aggregate JSON path',
                ),
                new HandlerOption(
                    name: 'json',
                    mode: HandlerOptionMode::None,
                    description: 'Print aggregate JSON to stdout',
                ),
                new HandlerOption(
                    name: 'fail-on-empty',
                    mode: HandlerOptionMode::None,
                    description: 'Return non-zero if no scenarios are found',
                ),
            ],
            handler: [FixturePackRefreshHandler::class, 'execute'],
        );
    }
}
