<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\ProjectInit;

/** @internal */
interface ProjectInitProcessRunnerInterface
{
    /**
     * @param non-empty-list<string> $command
     */
    public function run(array $command, string $cwd, bool $captureOutput): ProjectInitProcessResult;
}
