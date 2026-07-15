<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\Oidc\MigrateSecretsCommand;
use Waaseyaa\CLI\Command\Oidc\RotateSigningKeyCommand;
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
        // RotateSigningKeyCommand is resolved from the container at dispatch time.
        // OidcServiceProvider (waaseyaa/oidc) registers SigningKeyRepository.
    }

    public function consoleCommands(): iterable
    {
        yield new HandlerCommand(
            name: 'oidc:rotate-signing-key',
            description: 'Generate a new RS256 signing keypair and rotate out the current key (WP04).',
            handler: [RotateSigningKeyCommand::class, 'execute'],
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
}
