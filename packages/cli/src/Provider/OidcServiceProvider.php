<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\Oidc\EmergencyRevokeSigningKeyCommand;
use Waaseyaa\CLI\Command\Oidc\MigrateSecretsCommand;
use Waaseyaa\CLI\Command\Oidc\SigningKeyLifecycleCommand;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageGate;
use Waaseyaa\Foundation\ServiceProvider\Capability\OptionalPackageRequirement;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\RequiresOptionalPackagesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Oidc\Key\SigningKeyRepository;

/**
 * Registers OIDC CLI commands.
 *
 * Uses FQCNs for types from waaseyaa/oidc so the oidc package class-map
 * can be autoloaded at runtime without a composer.json dep from cli → oidc
 * (both are L6; cross-L6 deps go through the monorepo root).
 *
 * `waaseyaa/oidc` is only suggested by this package. When it is absent the
 * provider yields no commands, so a `--no-dev` consumer without the OIDC
 * runtime sees zero `oidc:*` commands instead of commands whose handlers
 * cannot resolve (the OIDC sibling of #2826, #2828).
 *
 * @api
 */
final class OidcServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface, RequiresOptionalPackagesInterface
{
    public static function optionalPackageRequirements(): iterable
    {
        yield new OptionalPackageRequirement(
            package: 'waaseyaa/oidc',
            sentinelClass: SigningKeyRepository::class,
            purpose: 'the oidc:init-signing-key, oidc:stage-signing-key, oidc:record-signing-key-propagation, '
                . 'oidc:activate-signing-key, oidc:cleanup-signing-keys, oidc:emergency-revoke-signing-key '
                . 'and oidc:migrate-secrets operator commands',
        );
    }

    public function register(): void
    {
        if (!OptionalPackageGate::satisfied($this)) {
            return;
        }

        // Handlers are resolved from the container at dispatch time.
    }

    public function consoleCommands(): iterable
    {
        if (!OptionalPackageGate::satisfied($this)) {
            return;
        }

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
            name: 'oidc:emergency-revoke-signing-key',
            description: 'Revoke compromised signing trust and enumerate affected tokens.',
            handler: [EmergencyRevokeSigningKeyCommand::class, 'execute'],
            options: [
                new HandlerOption(
                    name: 'request-id',
                    mode: HandlerOptionMode::Required,
                    description: 'Unique idempotency identity for this compromise response.',
                ),
                new HandlerOption(
                    name: 'kid',
                    mode: HandlerOptionMode::Required,
                    description: 'Compromised signing-key id.',
                ),
                new HandlerOption(
                    name: 'actor',
                    mode: HandlerOptionMode::Required,
                    description: 'Non-secret operator identity.',
                ),
                new HandlerOption(
                    name: 'reason',
                    mode: HandlerOptionMode::Required,
                    description: 'Non-secret compromise reason.',
                ),
                $this->confirmOption(),
            ],
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
