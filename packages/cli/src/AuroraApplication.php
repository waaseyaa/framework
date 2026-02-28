<?php

declare(strict_types=1);

namespace Aurora\CLI;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

class AuroraApplication extends Application
{
    public function __construct()
    {
        parent::__construct(name: 'aurora', version: '0.1.0');
    }

    /**
     * Register multiple commands at once.
     *
     * @param Command[] $commands
     */
    public function registerCommands(array $commands): void
    {
        foreach ($commands as $command) {
            $this->add($command);
        }
    }
}
