<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PortableRepositoryPathsTest extends TestCase
{
    private const GATE = __DIR__ . '/../../bin/check-portable-paths';

    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/waaseyaa-portable-paths-' . bin2hex(random_bytes(6));
        mkdir($this->fixture, 0o755, true);
        $this->execute('git init --quiet');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->fixture, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->fixture);
    }

    #[Test]
    public function rejects_windows_illegal_characters_in_tracked_paths(): void
    {
        file_put_contents($this->fixture . '/get|-', '');
        $this->execute("git add 'get|-'");

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('get|-', $output);
        self::assertStringContainsString('Windows-illegal character', $output);
    }

    #[Test]
    public function rejects_reserved_names_trailing_dots_and_case_collisions(): void
    {
        file_put_contents($this->fixture . '/CON.txt', 'reserved');
        file_put_contents($this->fixture . '/trailing.', 'dot');
        file_put_contents($this->fixture . '/Readme.md', 'upper');
        file_put_contents($this->fixture . '/README.md', 'lower');
        $this->execute("git add 'CON.txt' 'trailing.' 'Readme.md' 'README.md'");

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('reserved Windows device name', $output);
        self::assertStringContainsString('trailing dot or space', $output);
        self::assertStringContainsString('case-insensitive collision', $output);
    }

    #[Test]
    public function accepts_portable_tracked_paths(): void
    {
        mkdir($this->fixture . '/docs');
        file_put_contents($this->fixture . '/docs/portable-name.md', 'portable');
        $this->execute('git add docs/portable-name.md');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('portable repository paths', $output);
    }

    /** @return array{int, string} */
    private function runGate(): array
    {
        return $this->execute(
            'PORTABLE_PATH_ROOT=' . escapeshellarg($this->fixture) . ' php ' . escapeshellarg(self::GATE),
            true,
        );
    }

    /** @return array{int, string} */
    private function execute(string $command, bool $allowFailure = false): array
    {
        exec('cd ' . escapeshellarg($this->fixture) . ' && ' . $command . ' 2>&1', $lines, $exitCode);
        $output = implode("\n", $lines);
        if (!$allowFailure) {
            self::assertSame(0, $exitCode, $output);
        }

        return [$exitCode, $output];
    }
}
