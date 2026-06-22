<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\ExtensionScaffoldHandler;
use Waaseyaa\CLI\Handler\RelationshipTypeScaffoldHandler;
use Waaseyaa\CLI\Handler\ScaffoldAuthHandler;
use Waaseyaa\CLI\Handler\WorkflowScaffoldHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class OtherScaffoldsServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'scaffold:relationship',
            description: 'Generate deterministic relationship-type scaffold JSON',
            options: [
                new HandlerOption(
                    name: 'id',
                    mode: HandlerOptionMode::Required,
                    description: 'Relationship type machine name',
                ),
                new HandlerOption(
                    name: 'label',
                    mode: HandlerOptionMode::Required,
                    description: 'Relationship type label',
                ),
                new HandlerOption(
                    name: 'directionality',
                    mode: HandlerOptionMode::Required,
                    description: 'directed or bidirectional',
                    default: 'directed',
                ),
                new HandlerOption(
                    name: 'inverse',
                    mode: HandlerOptionMode::Required,
                    description: 'Optional inverse relationship type ID',
                ),
                new HandlerOption(
                    name: 'default-status',
                    mode: HandlerOptionMode::Required,
                    description: 'Default publication status (0/1)',
                    default: '1',
                ),
            ],
            handler: [RelationshipTypeScaffoldHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'scaffold:workflow',
            description: 'Generate deterministic workflow scaffold JSON',
            options: [
                new HandlerOption(
                    name: 'id',
                    mode: HandlerOptionMode::Required,
                    description: 'Workflow machine name',
                ),
                new HandlerOption(
                    name: 'bundle',
                    mode: HandlerOptionMode::Required,
                    description: 'Bundle ID the workflow applies to',
                ),
                new HandlerOption(
                    name: 'state',
                    mode: HandlerOptionMode::Array_,
                    description: 'State IDs (repeatable)',
                ),
                new HandlerOption(
                    name: 'transition',
                    mode: HandlerOptionMode::Array_,
                    description: 'Transition in id:from:to:permission form (repeatable)',
                ),
            ],
            handler: [WorkflowScaffoldHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'scaffold:extension',
            description: 'Generate deterministic external extension SDK scaffold JSON',
            options: [
                new HandlerOption(
                    name: 'id',
                    mode: HandlerOptionMode::Required,
                    description: 'Plugin ID (machine name)',
                ),
                new HandlerOption(
                    name: 'label',
                    mode: HandlerOptionMode::Required,
                    description: 'Plugin label',
                ),
                new HandlerOption(
                    name: 'package',
                    mode: HandlerOptionMode::Required,
                    description: 'Composer package name (vendor/package)',
                ),
                new HandlerOption(
                    name: 'namespace',
                    mode: HandlerOptionMode::Required,
                    description: 'Root PHP namespace (auto-derived from package when omitted)',
                ),
                new HandlerOption(
                    name: 'class',
                    mode: HandlerOptionMode::Required,
                    description: 'Extension class name',
                    default: 'KnowledgeExtension',
                ),
                new HandlerOption(
                    name: 'description',
                    mode: HandlerOptionMode::Required,
                    description: 'Plugin description',
                    default: 'External knowledge tooling extension',
                ),
                new HandlerOption(
                    name: 'workflow-tag',
                    mode: HandlerOptionMode::Required,
                    description: 'Default workflow tag',
                    default: 'external-extension',
                ),
                new HandlerOption(
                    name: 'relationship-type',
                    mode: HandlerOptionMode::Required,
                    description: 'Default traversal relationship type',
                    default: 'related',
                ),
                new HandlerOption(
                    name: 'discovery-hint',
                    mode: HandlerOptionMode::Required,
                    description: 'Default discovery hint',
                    default: 'external-discovery-hint',
                ),
            ],
            handler: [ExtensionScaffoldHandler::class, 'execute'],
        );

        $projectRoot = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();
        $scaffoldAuthHandler = new ScaffoldAuthHandler(projectRoot: $projectRoot);

        yield new HandlerCommand(
            name: 'scaffold:auth',
            description: 'Copy framework auth UI files into your app for customization',
            options: [
                new HandlerOption(
                    name: 'force',
                    shortcut: 'f',
                    mode: HandlerOptionMode::None,
                    description: 'Overwrite existing files',
                ),
                new HandlerOption(
                    name: 'dry-run',
                    mode: HandlerOptionMode::None,
                    description: 'Show what would be copied without writing',
                ),
            ],
            handler: \Closure::fromCallable([$scaffoldAuthHandler, 'execute']),
        );
    }
}
