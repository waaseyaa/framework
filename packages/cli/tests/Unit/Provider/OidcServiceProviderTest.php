<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Command\Oidc\EmergencyRevokeSigningKeyCommand;
use Waaseyaa\CLI\Command\Oidc\MigrateSecretsCommand;
use Waaseyaa\CLI\Command\Oidc\SigningKeyLifecycleCommand;
use Waaseyaa\CLI\Provider\OidcServiceProvider;

#[CoversClass(OidcServiceProvider::class)]
final class OidcServiceProviderTest extends TestCase
{
    #[Test]
    public function it_publishes_the_complete_gated_cfg04_operator_surface(): void
    {
        $commands = $this->commands();

        self::assertSame([
            'oidc:init-signing-key' => SigningKeyLifecycleCommand::class,
            'oidc:stage-signing-key' => SigningKeyLifecycleCommand::class,
            'oidc:record-signing-key-propagation' => SigningKeyLifecycleCommand::class,
            'oidc:activate-signing-key' => SigningKeyLifecycleCommand::class,
            'oidc:cleanup-signing-keys' => SigningKeyLifecycleCommand::class,
            'oidc:emergency-revoke-signing-key' => EmergencyRevokeSigningKeyCommand::class,
            'oidc:migrate-secrets' => MigrateSecretsCommand::class,
        ], array_map(static fn(HandlerCommand $command): string => $command->sourceClass(), $commands));
    }

    #[Test]
    public function every_custody_mutation_requires_an_explicit_confirm_flag(): void
    {
        foreach ($this->commands() as $name => $command) {
            $confirm = array_values(array_filter(
                $command->handlerOptions(),
                static fn(object $option): bool => $option->name === 'confirm',
            ));

            self::assertCount(1, $confirm, sprintf('%s must carry exactly one --confirm option.', $name));
            self::assertSame(
                HandlerOptionMode::None,
                $confirm[0]->mode,
                sprintf('%s --confirm must be a value-less flag.', $name),
            );
        }
    }

    #[Test]
    public function evidence_bound_commands_declare_their_required_identity_options(): void
    {
        $commands = $this->commands();
        $required = static fn(HandlerCommand $command): array => array_column(
            array_filter(
                $command->handlerOptions(),
                static fn(object $option): bool => $option->mode === HandlerOptionMode::Required,
            ),
            'name',
        );

        self::assertSame(
            ['kid', 'evidence-hash'],
            $required($commands['oidc:record-signing-key-propagation']),
        );
        self::assertSame(
            ['kid', 'expected-active-version'],
            $required($commands['oidc:activate-signing-key']),
        );
        self::assertSame(
            ['request-id', 'kid', 'actor', 'reason'],
            $required($commands['oidc:emergency-revoke-signing-key']),
        );
    }

    /**
     * @return array<string, HandlerCommand>
     */
    private function commands(): array
    {
        $provider = new OidcServiceProvider();
        $provider->register();
        $commands = [];
        foreach ($provider->consoleCommands() as $command) {
            $commands[(string) $command->name] = $command;
        }

        return $commands;
    }
}
