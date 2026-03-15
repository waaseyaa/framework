<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Displays system information about the Waaseyaa installation.
 */
#[AsCommand(
    name: 'about',
    description: 'Display information about the Waaseyaa installation',
)]
final class AboutCommand extends Command
{
    /**
     * @param array<string, string> $info Key-value pairs of system information.
     */
    public function __construct(
        private readonly array $info = [],
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Waaseyaa</info>');
        $output->writeln('');

        $info = array_merge($this->getDefaultInfo(), $this->info);

        $table = new Table($output);
        $table->setStyle('compact');
        $table->setHeaders(['Key', 'Value']);

        foreach ($info as $key => $value) {
            $table->addRow([$key, $value]);
        }

        $table->render();

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function getDefaultInfo(): array
    {
        return [
            'Waaseyaa Version' => '0.1.0',
            'PHP Version' => PHP_VERSION,
            'Environment' => $_ENV['APP_ENV'] ?? 'production',
            'Debug Mode' => ($_ENV['APP_DEBUG'] ?? '0') === '1' ? 'ON' : 'OFF',
            'Database' => $_ENV['WAASEYAA_DB'] ?? './waaseyaa.sqlite',
            'Config Dir' => $_ENV['WAASEYAA_CONFIG_DIR'] ?? './config/sync',
            'OS' => PHP_OS_FAMILY,
        ];
    }
}
