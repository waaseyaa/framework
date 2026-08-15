<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\Oidc\MigrateSecretsCommand;
use Waaseyaa\CLI\Command\Oidc\SigningKeyLifecycleCommand;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers OIDC CLI commands.
 *
 * Uses FQCNs for types from waaseyaa/oidc so the oidc package class-map
 * can be autoloaded at runtime without a composer.json dep from cli → oidc
 * (both are L6; cross-L6 deps go through the monorepo root).
 *
 * @api
 */
final class OidcServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void
    {
        // Handlers are resolved from the container at dispatch time.
    }

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'oidc:init-signing-key',
            description: 'Explicitly initialize an empty OIDC signing-key lifecycle.',
            handler: [SigningKeyLifecycleCommand::class, 'initialize'],
            options: [$this->confirmOption()],
        );
        yield new HandlerCommand(
            name: 'oidc:stage-signing-key',
            description: 'Publish one verify-only signing-key successor.',
            handler: [SigningKeyLifecycleCommand::class, 'stage'],
            options: [$this->confirmOption()],
        );
        yield new HandlerCommand(
            name: 'oidc:record-signing-key-propagation',
            description: 'Attach observable propagation evidence to a staged successor.',
            handler: [SigningKeyLifecycleCommand::class, 'recordPropagation'],
            options: [
                new HandlerOption(
                    name: 'kid',
                    mode: HandlerOptionMode::Required,
                    description: 'Staged signing-key id.',
                ),
                new HandlerOption(
                    name: 'evidence-hash',
                    mode: HandlerOptionMode::Required,
                    description: 'SHA-256 of non-secret propagation evidence.',
                ),
                $this->confirmOption(),
            ],
        );
        yield new HandlerCommand(
            name: 'oidc:activate-signing-key',
            description: 'Activate an evidenced successor after its effective JWKS cache horizon.',
            handler: [SigningKeyLifecycleCommand::class, 'activate'],
            options: [
                new HandlerOption(
                    name: 'kid',
                    mode: HandlerOptionMode::Required,
                    description: 'Staged signing-key id.',
                ),
                new HandlerOption(
                    name: 'expected-active-version',
                    mode: HandlerOptionMode::Required,
                    description: 'Current active version fence.',
                ),
                $this->confirmOption(),
            ],
        );
        yield new HandlerCommand(
            name: 'oidc:cleanup-signing-keys',
            description: 'Remove only policy-expired retired verification keys.',
            handler: [SigningKeyLifecycleCommand::class, 'cleanup'],
            options: [$this->confirmOption()],
        );
        yield new HandlerCommand(
            name: 'oidc:migrate-secrets',
            description: 'Encrypt existing OIDC signing keys and opaque tokens with application-derived keys.',
            handler: [MigrateSecretsCommand::class, 'execute'],
            options: [
                new HandlerOption(
                    name: 'confirm',
                    mode: HandlerOptionMode::None,
                    description: 'Confirm the bounded in-place custody migration.',
                ),
            ],
        );
    }

    private function confirmOption(): HandlerOption
    {
        return new HandlerOption(
            name: 'confirm',
            mode: HandlerOptionMode::None,
            description: 'Confirm the bounded signing-key lifecycle mutation.',
        );
    }
}
