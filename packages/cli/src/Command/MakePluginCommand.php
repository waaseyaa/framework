<?php

declare(strict_types=1);

namespace Aurora\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:plugin',
    description: 'Generate a plugin class with #[AuroraPlugin] attribute',
)]
class MakePluginCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The plugin name (e.g. "my_formatter")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $className = ucfirst($name);
        $pluginId = strtolower($name);

        $template = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Plugin;

        use Aurora\\Plugin\\Attribute\\AuroraPlugin;

        #[AuroraPlugin(id: '{$pluginId}', label: '{$className}')]
        class {$className}
        {
        }

        PHP;

        $output->write($template);

        return Command::SUCCESS;
    }
}
