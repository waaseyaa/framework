<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

require_once dirname(__DIR__, 2) . '/bin/lib/repository-files.php';

/**
 * bin/check-package-coverage-history (FW-PACKAGE-CONVERGENCE-01) against a
 * fixture repository with real history: it passes a consistent index and
 * fails closed on a shallow clone, a wrong dependency identity, an altered
 * evidence copy, a source commit off the checked-out history, and a missing
 * audit base.
 */
#[CoversNothing]
final class PackageCoverageHistoryGateTest extends TestCase
{
    private string $scratch = '';
    private string $fixture = '';
    private string $base = '';

    protected function setUp(): void
    {
        $this->scratch = str_replace('\\', '/', sys_get_temp_dir()) . '/waaseyaa_coverage_history_' . uniqid('', true);
        $this->fixture = $this->scratch . '/repo';
        mkdir($this->fixture . '/docs', 0o755, true);
        $this->git($this->fixture, 'init', '--quiet');
        file_put_contents($this->fixture . '/composer.lock', "lock v1\n");
        file_put_contents($this->fixture . '/docs/record.md', "original record\r\n");
        $this->base = $this->commit('audit base');
    }

    protected function tearDown(): void
    {
        if (PHP_OS_FAMILY === 'Windows' && is_dir($this->scratch)) {
            // Git for Windows marks loose objects read-only, which unlink() refuses.
            exec(sprintf('attrib -R %s /S /D', escapeshellarg(str_replace('/', '\\', $this->scratch) . '\\*')));
        }
        new Filesystem()->remove($this->scratch);
    }

    #[Test]
    public function a_consistent_index_passes(): void
    {
        $this->writeIndex();

        [$output, $exitCode] = $this->gate($this->fixture);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('1 audited rows and 1 commit-sourced evidence copies verified', $output);
    }

    #[Test]
    public function a_shallow_clone_is_refused(): void
    {
        $this->writeIndex();
        $shallow = $this->scratch . '/shallow';
        $this->git($this->scratch, 'clone', '--quiet', '--depth', '1', 'file://' . $this->fixture, $shallow);

        [$output, $exitCode] = $this->gate($shallow);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('this clone is shallow; the gate needs full history', $output);
    }

    #[Test]
    public function a_wrong_dependency_identity_fails(): void
    {
        $this->writeIndex(lock: 'composer.lock sha256:' . str_repeat('0', 64));

        [$output, $exitCode] = $this->gate($this->fixture);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('does not match composer.lock sha256:' . hash('sha256', "lock v1\n"), $output);
    }

    #[Test]
    public function an_altered_evidence_copy_fails(): void
    {
        $this->writeIndex(copied: "altered record\r\n");

        [$output, $exitCode] = $this->gate($this->fixture);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('differs from ' . $this->base . ':docs/record.md', $output);
    }

    #[Test]
    public function a_source_commit_off_the_checked_out_history_fails(): void
    {
        $this->git($this->fixture, 'checkout', '--quiet', '-b', 'side');
        file_put_contents($this->fixture . '/docs/record.md', "branch-only record\n");
        $branchOnly = $this->commit('branch only');
        $this->git($this->fixture, 'checkout', '--quiet', '-');
        $this->writeIndex(source: $branchOnly, copied: "branch-only record\n");

        [$output, $exitCode] = $this->gate($this->fixture);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('cites ' . $branchOnly . ', which is not an ancestor of HEAD', $output);
    }

    #[Test]
    public function a_missing_audit_base_fails(): void
    {
        $missing = str_repeat('a', 40);
        $this->writeIndex(base: $missing);

        [$output, $exitCode] = $this->gate($this->fixture);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('audit base ' . $missing . ' is not in the repository history', $output);
    }

    private function writeIndex(?string $base = null, ?string $lock = null, ?string $source = null, string $copied = "original record\r\n"): void
    {
        $evidence = 'docs/audits/packages/evidence/demo/record.txt';
        mkdir(dirname($this->fixture . '/' . $evidence), 0o755, true);
        file_put_contents(
            $this->fixture . '/' . $evidence,
            sprintf("source: commit %s docs/record.md\ncaptured: 2026-09-23\nbody-sha256: %s\n---\n%s", $source ?? $this->base, hash('sha256', $copied), $copied),
        );
        file_put_contents($this->fixture . '/docs/audits/packages/coverage-index.json', json_encode(['packages' => [[
            'package' => 'waaseyaa/demo',
            'audit_state' => 'in progress',
            'audit_base' => $base ?? $this->base,
            'dependency_identity' => $lock ?? 'composer.lock sha256:' . hash('sha256', "lock v1\n"),
            'evidence' => [['kind' => 'repository-path', 'path' => $evidence]],
        ]]], JSON_THROW_ON_ERROR));
        $this->commit('index');
    }

    private function commit(string $message): string
    {
        $this->git($this->fixture, 'add', '--all');
        $this->git($this->fixture, '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '--quiet', '-m', $message);

        return trim($this->git($this->fixture, 'rev-parse', 'HEAD'));
    }

    private function git(string $root, string ...$arguments): string
    {
        [$exitCode, $stdout, $stderr] = \repositoryGit($root, ['-c', 'core.autocrlf=false', ...$arguments]);
        self::assertSame(0, $exitCode, $stderr);

        return $stdout;
    }

    /** @return array{0: string, 1: int} */
    private function gate(string $root): array
    {
        exec(sprintf(
            '%s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 2) . '/bin/check-package-coverage-history'),
            escapeshellarg('--root=' . $root),
        ), $output, $exitCode);

        return [implode("\n", $output), $exitCode];
    }
}
