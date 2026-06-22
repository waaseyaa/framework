<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\Cache\ConfigCacheCompiler;

final class OptimizeConfigHandler
{
    public function __construct(
        private readonly ConfigCacheCompiler $compiler,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $data = $this->compiler->compileAndCache();

        $io->writeln(sprintf(
            'Configuration cached: %d config objects compiled.',
            count($data),
        ));

        return 0;
    }
}
