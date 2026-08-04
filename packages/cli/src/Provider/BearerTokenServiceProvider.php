<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface;
use Waaseyaa\CLI\Command\BearerTokenConsoleCommands;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Owns the operator-facing bearer-token lifecycle commands at the CLI layer.
 *
 * @api
 */
final class BearerTokenServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        yield from new BearerTokenConsoleCommands(
            fn(): BearerTokenStoreInterface => $this->resolve(BearerTokenStoreInterface::class),
        )->commands();
    }
}
