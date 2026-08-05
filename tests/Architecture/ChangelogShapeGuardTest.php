<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ChangelogShapeGuardTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function it_accepts_exactly_one_canonical_unreleased_section(): void
    {
        $result = $this->runGuard(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            ### Fixed

            - A release fix.

            ## [0.1.0-alpha.286] - 2026-08-04
            MARKDOWN);

        self::assertSame(0, $result['exit_code'], $result['output']);
    }

    #[Test]
    public function it_rejects_duplicate_canonical_unreleased_sections(): void
    {
        $result = $this->runGuard(<<<'MARKDOWN'
            # Changelog

            ## [Unreleased]

            - First entry.

            ## [Unreleased]

            - Stranded entry.
            MARKDOWN);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('exactly one', $result['output']);
    }

    #[Test]
    public function it_rejects_unbracketed_near_miss_headings(): void
    {
        $result = $this->runGuard(<<<'MARKDOWN'
            # Changelog

            ## Unreleased

            - This entry would be skipped by release-cut.

            ## [Unreleased]

            - Canonical entry.
            MARKDOWN);

        self::assertSame(1, $result['exit_code']);
        self::assertStringContainsString('near-miss', $result['output']);
    }

    #[Test]
    public function ci_and_release_cut_both_run_the_shape_guard(): void
    {
        $composer = json_decode(
            (string) file_get_contents($this->repoRoot . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $verify = $composer['scripts']['verify'] ?? [];

        self::assertContains('@check-changelog-shape', $verify);
        self::assertStringContainsString(
            'php bin/check-changelog-shape',
            (string) file_get_contents($this->repoRoot . '/.github/workflows/release-cut.yml'),
        );
    }

    /** @return array{exit_code: int, output: string} */
    private function runGuard(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'waaseyaa-changelog-');
        self::assertNotFalse($path);
        file_put_contents($path, $contents . "\n");

        $output = [];
        $exitCode = 0;
        exec(
            escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($this->repoRoot . '/bin/check-changelog-shape')
            . ' '
            . escapeshellarg($path)
            . ' 2>&1',
            $output,
            $exitCode,
        );
        unlink($path);

        return ['exit_code' => $exitCode, 'output' => implode("\n", $output)];
    }
}
