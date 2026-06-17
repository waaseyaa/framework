<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;

/**
 * ConsoleKernel::handle() is a thin wrapper that delegates to the Symfony Console application.
 *
 * The full CLI behaviour (command dispatch, exit codes) is covered by the
 * Symfony Console integration tests. These tests verify only that the kernel
 * correctly forwards argv and projectRoot, and that the known exit-code
 * semantics hold end-to-end against the real project.
 */
#[CoversClass(ConsoleKernel::class)]
final class ConsoleKernelTest extends TestCase
{
    /** @var list<string> */
    private array $originalArgv;

    protected function setUp(): void
    {
        $this->originalArgv = $_SERVER['argv'] ?? [];
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->originalArgv;
    }

    #[Test]
    public function handle_returns_non_zero_for_unknown_command(): void
    {
        $projectRoot = dirname(__DIR__, 6); // repo root in the worktree
        $_SERVER['argv'] = ['waaseyaa', 'not-a-real-command'];

        $kernel = new ConsoleKernel($projectRoot);

        ob_start();
        $exitCode = $kernel->handle();
        ob_get_clean();

        $this->assertNotSame(0, $exitCode);
    }

    #[Test]
    public function handle_returns_zero_when_no_command_given(): void
    {
        $projectRoot = dirname(__DIR__, 6); // repo root in the worktree
        // Symfony runtime shows the short no-command hint when no command is supplied.
        $_SERVER['argv'] = ['waaseyaa'];

        $kernel = new ConsoleKernel($projectRoot);

        ob_start();
        $exitCode = $kernel->handle();
        ob_get_clean();

        $this->assertSame(0, $exitCode);
    }
}
