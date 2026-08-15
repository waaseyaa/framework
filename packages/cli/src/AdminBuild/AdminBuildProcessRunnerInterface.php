<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

/** @internal */
interface AdminBuildProcessRunnerInterface
{
    /**
     * @param non-empty-list<string> $command
     * @param array<string, string> $environment
     * @param callable(string): void $stdout
     * @param callable(string): void $stderr
     */
    public function run(
        array $command,
        string $cwd,
        array $environment,
        RedactorProcessor $sanitizer,
        callable $stdout,
        callable $stderr,
    ): int;
}
