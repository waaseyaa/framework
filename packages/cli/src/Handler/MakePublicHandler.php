<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class MakePublicHandler extends AbstractMakeHandler
{
    public function __construct(private readonly string $projectRoot) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $force = (bool) $io->option('force');

        $publicDir = $this->projectRoot . '/public';
        $target = $publicDir . '/index.php';
        $stub = dirname(__DIR__, 2) . '/templates/public/index.php.stub';

        if (!is_dir($publicDir) && !@mkdir($publicDir, 0o755, true) && !is_dir($publicDir)) {
            $io->writeln(sprintf('<error>Failed to create directory %s</error>', $publicDir));

            return 1;
        }

        if (is_file($target) && !$force) {
            $io->writeln(sprintf('<comment>%s already exists. Use --force to overwrite.</comment>', $target));

            return 0;
        }

        if (!is_file($stub)) {
            $io->writeln(sprintf('<error>Template not found: %s</error>', $stub));

            return 1;
        }

        $contents = @file_get_contents($stub);
        if ($contents === false) {
            $io->writeln(sprintf('<error>Failed to read template: %s</error>', $stub));

            return 1;
        }

        if (@file_put_contents($target, $contents) === false) {
            $io->writeln(sprintf('<error>Failed to write %s</error>', $target));

            return 1;
        }

        $io->writeln(sprintf('<info>Created %s</info>', $target));

        return 0;
    }
}
