<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerArgument;
use Waaseyaa\CLI\Command\HandlerArgumentMode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\MakeContentTypeHandler;
use Waaseyaa\CLI\Handler\MakeEntityTypeHandler;
use Waaseyaa\CLI\Handler\MakePluginHandler;
use Waaseyaa\CLI\Handler\MakeProviderHandler;
use Waaseyaa\CLI\Handler\MakePublicHandler;
use Waaseyaa\CLI\Handler\MakeTestHandler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MakeServiceProviderB extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        $projectRoot = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();

        yield new HandlerCommand(
            name: 'make:content-type',
            description: 'Scaffold a usable content type (entity + provider + registration) in one command',
            arguments: [
                new HandlerArgument(
                    name: 'name',
                    mode: HandlerArgumentMode::Required,
                    description: 'The content type name (e.g. "story")',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'fields',
                    mode: HandlerOptionMode::Required,
                    description: 'Comma-separated fields: name:type[,...] (types: string,text,integer,float,boolean,datetime,entity_reference; reference: author:entity_reference:user)',
                    default: 'title:string,body:text',
                ),
                new HandlerOption(
                    name: 'force',
                    mode: HandlerOptionMode::None,
                    description: 'Overwrite existing generated files',
                ),
            ],
            handler: \Closure::fromCallable([new MakeContentTypeHandler(projectRoot: $projectRoot), 'execute']),
        );

        yield new HandlerCommand(
            name: 'make:provider',
            description: 'Generate a service provider class',
            arguments: [
                new HandlerArgument(
                    name: 'name',
                    mode: HandlerArgumentMode::Required,
                    description: 'The provider class name (e.g. "Blog" or "BlogServiceProvider")',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'domain',
                    shortcut: 'd',
                    mode: HandlerOptionMode::None,
                    description: 'Generate a domain provider with entity type registration boilerplate',
                ),
            ],
            handler: [MakeProviderHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'make:public',
            description: 'Scaffold the canonical public/index.php front controller',
            options: [
                new HandlerOption(
                    name: 'force',
                    mode: HandlerOptionMode::None,
                    description: 'Overwrite an existing public/index.php',
                ),
            ],
            // MakePublicHandler requires a scalar string $projectRoot, which the
            // kernel handler container cannot auto-wire. Build it eagerly here —
            // same pattern as make:content-type above — instead of the
            // class-reference form, which crashed with "unresolvable parameter
            // $projectRoot" on a stock downstream app (D2).
            handler: \Closure::fromCallable([new MakePublicHandler(projectRoot: $projectRoot), 'execute']),
        );

        yield new HandlerCommand(
            name: 'make:test',
            description: 'Generate a PHPUnit test class',
            arguments: [
                new HandlerArgument(
                    name: 'name',
                    mode: HandlerArgumentMode::Required,
                    description: 'The test class name (e.g. "NodeRepositoryTest")',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'unit',
                    mode: HandlerOptionMode::None,
                    description: 'Generate a unit test (default is integration)',
                ),
            ],
            handler: [MakeTestHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'make:entity-type',
            description: 'Generate an entity type class',
            arguments: [
                new HandlerArgument(
                    name: 'name',
                    mode: HandlerArgumentMode::Required,
                    description: 'The entity type name (e.g. "event")',
                ),
            ],
            options: [
                new HandlerOption(
                    name: 'content',
                    mode: HandlerOptionMode::None,
                    description: 'Generate a content entity (default is config entity)',
                ),
            ],
            handler: [MakeEntityTypeHandler::class, 'execute'],
        );

        yield new HandlerCommand(
            name: 'make:plugin',
            description: 'Generate a plugin class with #[WaaseyaaPlugin] attribute',
            arguments: [
                new HandlerArgument(
                    name: 'name',
                    mode: HandlerArgumentMode::Required,
                    description: 'The plugin name (e.g. "my_formatter")',
                ),
            ],
            handler: [MakePluginHandler::class, 'execute'],
        );
    }
}
