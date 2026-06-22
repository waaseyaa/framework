<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class MakeTestHandler extends AbstractMakeHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $name = (string) $io->argument('name');
        $className = $this->toPascalCase($name);
        $isUnit = (bool) $io->option('unit');

        $namespace = $isUnit ? 'App\\Tests\\Unit' : 'App\\Tests\\Integration';

        $rendered = $this->renderStub('test', [
            'class' => $className,
            'namespace' => $namespace,
        ]);

        $io->write($rendered);

        return 0;
    }
}
