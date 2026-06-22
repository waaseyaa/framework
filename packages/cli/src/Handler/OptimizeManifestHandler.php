<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;

final class OptimizeManifestHandler
{
    public function __construct(
        private readonly PackageManifestCompiler $compiler,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $manifest = $this->compiler->compileAndCache();

        $io->writeln(sprintf(
            'Package manifest compiled: %d providers, %d attribute entity types, %d field types, %d middleware stacks.',
            count($manifest->providers),
            count($manifest->attributeEntityTypes),
            count($manifest->fieldTypes),
            count($manifest->middleware),
        ));

        return 0;
    }
}
