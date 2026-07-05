<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;

/**
 * @api
 */
final class MakePolicyHandler extends AbstractMakeHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $name = (string) $io->argument('name');
        try {
            $this->validateIdentifier($name, 'name');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $className = $this->toPascalCase($name);

        $rendered = $this->renderStub('policy', [
            'class' => $className,
        ]);

        $io->write($rendered);

        return 0;
    }
}
