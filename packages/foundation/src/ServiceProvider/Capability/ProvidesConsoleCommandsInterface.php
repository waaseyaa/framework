<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\ServiceProvider\Capability;

use Symfony\Component\Console\Command\Command;

/**
 * Provider capability: exposes Symfony Console commands to the CLI runtime.
 *
 * Implement this on a ServiceProvider to register console commands during
 * console boot. Entries may be command instances, command FQCNs, or service ids
 * resolvable through the kernel handler container.
 */
interface ProvidesConsoleCommandsInterface
{
    /**
     * @return iterable<Command|class-string<Command>|string>
     */
    public function consoleCommands(): iterable;
}
