<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\Oidc\RotateSigningKeyCommand;
use Waaseyaa\CLI\CommandDefinition;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasNativeCommandsInterface;
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
final class OidcServiceProvider extends ServiceProvider implements HasNativeCommandsInterface
{
    public function register(): void
    {
        // RotateSigningKeyCommand is resolved from the container at dispatch time.
        // OidcServiceProvider (waaseyaa/oidc) registers SigningKeyRepository.
    }

    public function nativeCommands(): iterable
    {
        yield new CommandDefinition(
            name: 'oidc:rotate-signing-key',
            description: 'Generate a new RS256 signing keypair and rotate out the current key (WP04).',
            handler: [RotateSigningKeyCommand::class, 'execute'],
        );
    }
}
