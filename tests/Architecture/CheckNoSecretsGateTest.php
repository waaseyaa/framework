<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversNothing]
final class CheckNoSecretsGateTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa_secrets_' . uniqid('', true);
        mkdir($this->tmpRoot . '/packages/demo', 0o755, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmpRoot);
    }

    #[Test]
    public function scans_secret_like_content_outside_defaults(): void
    {
        file_put_contents(
            $this->tmpRoot . '/packages/demo/fixture.txt',
            'token=' . 'ghp_' . str_repeat('a', 24) . "\n",
        );

        [$exit, $output] = $this->runGate();

        self::assertSame(1, $exit, "Repository-wide secret gate must fail outside defaults/.\n{$output}");
        self::assertStringContainsString('packages/demo/fixture.txt', $output);
    }

    #[Test]
    public function excludes_generated_dependency_and_distribution_trees(): void
    {
        foreach ([
            'vendor/dependency.txt',
            'node_modules/dependency.txt',
            'packages/admin/dist/bundle.js',
            'packages/admin/build/bundle.js',
            '.claude/worktrees/agent-copy/fixture.txt',
        ] as $relative) {
            $path = $this->tmpRoot . '/' . $relative;
            mkdir(dirname($path), 0o755, true);
            file_put_contents($path, 'token=' . 'ghp_' . str_repeat('b', 24) . "\n");
        }

        [$exit, $output] = $this->runGate();

        self::assertSame(0, $exit, "Generated dependency/dist trees must be excluded.\n{$output}");
    }

    /** @return array{int, string} */
    private function runGate(): array
    {
        $command = sprintf(
            'WAASEYAA_SECRET_SCAN_ROOT=%s bash %s 2>&1',
            escapeshellarg($this->tmpRoot),
            escapeshellarg(dirname(__DIR__, 2) . '/bin/check-no-secrets'),
        );
        exec($command, $lines, $exit);

        return [$exit, implode("\n", $lines)];
    }
}
