<?php

declare(strict_types=1);

namespace Waaseyaa\AgentOutput\Listener;

/**
 * Mutable counter + failure-list holder shared by the agent-output
 * PHPUnit extension's per-event subscribers. Lives in its own file
 * (rather than as an anonymous shape inside
 * {@see AgentOutputPhpUnitExtension}) so PHPStan can type-check the
 * field accesses without `mixed` inference through anonymous classes.
 *
 * @internal Only consumed by {@see AgentOutputPhpUnitExtension}.
 */
final class PhpUnitRunState
{
    public int $passed = 0;
    public int $failed = 0;
    public int $skipped = 0;

    /** @var list<array{test: string, file: string, line: int, message: string}> */
    public array $failures = [];
}
