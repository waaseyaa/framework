<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FieldReadPagePerformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PagePerformanceHarnessContractTest extends TestCase
{
    #[Test]
    public function every_measured_request_constructs_its_own_http_kernel_inside_the_timed_boundary(): void
    {
        $runner = (string) file_get_contents(__DIR__ . '/Fixtures/persistent_http_runner.php');

        self::assertMatchesRegularExpression(
            '/function requestPage\(string \$projectRoot, PDO \$pdo, string \$page, bool \$timed\).*?'
                . '\$started = hrtime\(true\);.*?new HttpKernel\(\$projectRoot\).*?->handle\(\).*?'
                . '\$elapsed = hrtime\(true\) - \$started;/s',
            $runner,
        );
    }

    #[Test]
    public function benchmark_page_sessions_are_isolated_by_project_and_page(): void
    {
        $sessionBoundary = __DIR__ . '/Fixtures/IsolatedPageSession.php';
        self::assertFileExists($sessionBoundary);
        require_once $sessionBoundary;

        $root = sys_get_temp_dir() . '/waaseyaa-page-session-' . bin2hex(random_bytes(6));
        $baseline = $root . '/baseline';
        $candidate = $root . '/candidate';
        self::assertTrue(mkdir($baseline, 0o755, true));
        self::assertTrue(mkdir($candidate, 0o755, true));

        try {
            Fixtures\IsolatedPageSession::start($baseline, 'content_cold');
            $_SESSION['waaseyaa_uid'] = 1;
            Fixtures\IsolatedPageSession::restore();

            Fixtures\IsolatedPageSession::start($candidate, 'content_cold');
            self::assertArrayNotHasKey('waaseyaa_uid', $_SESSION);
            Fixtures\IsolatedPageSession::restore();

            Fixtures\IsolatedPageSession::start($baseline, 'members_cold');
            self::assertArrayNotHasKey('waaseyaa_uid', $_SESSION);
            Fixtures\IsolatedPageSession::restore();
        } finally {
            Fixtures\IsolatedPageSession::restore();
            $this->removeDirectory($root);
        }
    }

    #[Test]
    public function benchmark_page_session_globals_are_restored_after_the_isolated_lifecycle(): void
    {
        $sessionBoundary = __DIR__ . '/Fixtures/IsolatedPageSession.php';
        require_once $sessionBoundary;

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $original = [
            'save_path' => (string) ini_get('session.save_path'),
            'use_cookies' => (string) ini_get('session.use_cookies'),
            'cache_limiter' => (string) ini_get('session.cache_limiter'),
            'name' => session_name(),
            'id' => session_id(),
        ];
        $root = sys_get_temp_dir() . '/waaseyaa-page-session-restore-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0o755, true));

        try {
            Fixtures\IsolatedPageSession::start($root, 'content_cold');
            self::assertNotSame($original['save_path'], (string) ini_get('session.save_path'));
        } finally {
            Fixtures\IsolatedPageSession::restore();
            $this->removeDirectory($root);
        }

        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame($original['save_path'], (string) ini_get('session.save_path'));
        self::assertSame($original['use_cookies'], (string) ini_get('session.use_cookies'));
        self::assertSame($original['cache_limiter'], (string) ini_get('session.cache_limiter'));
        self::assertSame($original['name'], session_name());
        self::assertSame($original['id'], session_id());
        self::assertStringNotContainsString($root, (string) ini_get('session.save_path'));
    }

    #[Test]
    public function authenticated_fixture_users_declare_the_canonical_bundle_across_framework_versions(): void
    {
        require_once __DIR__ . '/Fixtures/FieldReadPageCorpus.php';

        foreach (Fixtures\FieldReadPageCorpus::users() as $user) {
            self::assertSame('user', $user['bundle'] ?? null);
        }
    }

    #[Test]
    public function content_fixture_display_contains_only_the_frozen_public_field_set(): void
    {
        require_once __DIR__ . '/Fixtures/FieldReadPageCorpus.php';

        $display = Fixtures\FieldReadPageCorpus::contentDisplay();

        self::assertArrayNotHasKey('status', $display);
        self::assertSame(Fixtures\FieldReadPageCorpus::CONTENT_RENDERED_FIELDS, count($display));
    }

    #[Test]
    public function checked_in_frozen_fixture_manifest_matches_the_harness_files(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/fixture-manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($manifest);
        foreach ($manifest as $relative => $expectedHash) {
            self::assertFileExists($root . '/' . $relative);
            self::assertSame($expectedHash, hash_file('sha256', $root . '/' . $relative), (string) $relative);
        }
    }

    #[Test]
    public function benchmark_subprocess_drains_large_stdout_and_stderr_without_deadlock(): void
    {
        $root = dirname(__DIR__, 3);
        $bytes = 262_144;
        $childCode = <<<'PHP'
            $bytes = (int) $argv[1];
            fwrite(STDERR, str_repeat('E', $bytes));
            fwrite(STDOUT, str_repeat('O', $bytes));
            exit(37);
            PHP;
        $probeCode = <<<'PHP'
            require $argv[1];
            $result = \Waaseyaa\Benchmark\BenchmarkProcessRunner::run(
                [PHP_BINARY, '-r', $argv[2], $argv[3]],
                $argv[4],
            );
            echo json_encode([
                'exit_code' => $result['exit_code'],
                'stdout_bytes' => strlen($result['stdout']),
                'stdout_sha256' => hash('sha256', $result['stdout']),
                'stderr_bytes' => strlen($result['stderr']),
                'stderr_sha256' => hash('sha256', $result['stderr']),
            ], JSON_THROW_ON_ERROR);
            PHP;

        $result = $this->runProbeWithDeadline([
            PHP_BINARY,
            '-r',
            $probeCode,
            $root . '/benchmarks/BenchmarkProcessRunner.php',
            $childCode,
            (string) $bytes,
            $root,
        ]);

        self::assertSame(0, $result['exit_code'], $result['stderr']);
        $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(37, $payload['exit_code'] ?? null);
        self::assertSame($bytes, $payload['stdout_bytes'] ?? null);
        self::assertSame(hash('sha256', str_repeat('O', $bytes)), $payload['stdout_sha256'] ?? null);
        self::assertSame($bytes, $payload['stderr_bytes'] ?? null);
        self::assertSame(hash('sha256', str_repeat('E', $bytes)), $payload['stderr_sha256'] ?? null);
    }

    #[Test]
    public function it_rejects_the_same_source_tree_on_both_sides(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('different source trees');

        PagePerformanceOrchestrator::validateSourceTrees(__DIR__, __DIR__);
    }

    #[Test]
    public function it_rejects_fixture_manifest_drift(): void
    {
        $expected = ['templates/node.html.twig' => str_repeat('a', 64)];
        $actual = ['templates/node.html.twig' => str_repeat('b', 64)];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fixture manifest drift');

        PagePerformanceOrchestrator::assertSameFixtureManifest($expected, $actual);
    }

    #[Test]
    public function it_rejects_response_or_trace_drift_before_timing_is_compared(): void
    {
        $baseline = $this->block('content', 1_000_000, 'body-a', ['sql' => 5, 'rows' => 1, 'fields' => 32]);
        $candidate = $this->block('content', 1_000_000, 'body-b', ['sql' => 5, 'rows' => 1, 'fields' => 32]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('response/trace drift');

        PagePerformanceOrchestrator::assertComparableBlock($baseline, $candidate);
    }

    #[Test]
    public function it_rejects_authorization_or_audit_workload_drift_before_timing_is_compared(): void
    {
        $trace = [
            'rows' => 1,
            'authorization_mode' => 'anonymous',
            'privileged_read_ledger_rows' => 0,
        ];
        $baseline = $this->block('content_cold', 1_000_000, 'same-body', $trace);
        $candidate = $this->block('content_cold', 1_000_000, 'same-body', [
            ...$trace,
            'authorization_mode' => 'authenticated_uid_1',
            'privileged_read_ledger_rows' => 460,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('response/trace drift');

        PagePerformanceOrchestrator::assertComparableBlock($baseline, $candidate);
    }

    #[Test]
    public function repository_hydration_removal_is_a_failing_negative_control(): void
    {
        $baseline = $this->block('members_cold', 1_000_000, 'same-body', [
            'route' => 'performance.members',
            'controller' => 'MembersDirectoryController::index',
            'rendered_rows' => 100,
            'rendered_fields' => 200,
            'hydrated_entity_count' => 101,
        ]);
        $withoutHydratedRows = $this->block('members_cold', 1_000_000, 'same-body', [
            'route' => 'performance.members',
            'controller' => 'MembersDirectoryController::index',
            'rendered_rows' => 0,
            'rendered_fields' => 0,
            'hydrated_entity_count' => 101,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('response/trace drift');

        PagePerformanceOrchestrator::assertComparableBlock($baseline, $withoutHydratedRows);
    }

    #[Test]
    public function twig_output_removal_is_a_failing_negative_control(): void
    {
        $trace = [
            'route' => 'performance.members',
            'controller' => 'MembersDirectoryController::index',
            'rendered_rows' => 100,
            'rendered_fields' => 200,
        ];
        $baseline = $this->block('members_cold', 1_000_000, 'all-frozen-rows', $trace);
        $withoutTwigRows = $this->block('members_cold', 1_000_000, 'layout-without-rows', $trace);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('response/trace drift');

        PagePerformanceOrchestrator::assertComparableBlock($baseline, $withoutTwigRows);
    }

    #[Test]
    public function both_ratio_and_absolute_budgets_are_required_per_page(): void
    {
        $baseline = [];
        $ratioFailure = [];
        $absoluteBaseline = [];
        $absoluteFailure = [];
        for ($i = 0; $i < 9; ++$i) {
            $baseline[] = $this->block('content', 1_000_000, 'same', ['sql' => 5, 'rows' => 1, 'fields' => 32]);
            $ratioFailure[] = $this->block('content', 1_040_000, 'same', ['sql' => 5, 'rows' => 1, 'fields' => 32]);
            $absoluteBaseline[] = $this->block('content', 100_000_000, 'same', ['sql' => 5, 'rows' => 1, 'fields' => 32]);
            $absoluteFailure[] = $this->block('content', 100_600_000, 'same', ['sql' => 5, 'rows' => 1, 'fields' => 32]);
        }

        self::assertFalse(PagePerformanceOrchestrator::comparePage($baseline, $ratioFailure)['passed']);
        self::assertFalse(PagePerformanceOrchestrator::comparePage($absoluteBaseline, $absoluteFailure)['passed']);
    }

    #[Test]
    public function the_absolute_budget_scales_with_the_frozen_hydrated_entity_count(): void
    {
        $baseline = [];
        $candidate = [];
        for ($i = 0; $i < 9; ++$i) {
            $trace = [
                'sql' => 5,
                'rows' => 100,
                'fields' => 200,
                'hydrated_entity_count' => 100,
            ];
            $baseline[] = $this->block('members_cold', 100_000_000, 'same', $trace);
            $candidate[] = $this->block('members_cold', 102_000_000, 'same', $trace);
        }

        $comparison = PagePerformanceOrchestrator::comparePage($baseline, $candidate);

        self::assertSame(100, $comparison['hydrated_entity_count']);
        self::assertSame(5_000_000, $comparison['absolute_limit_ns']);
        self::assertTrue($comparison['passed']);
    }

    #[Test]
    public function it_rejects_a_page_without_a_frozen_hydrated_entity_count(): void
    {
        $baseline = $this->block(
            'content_cold',
            1_000_000,
            'same',
            ['sql' => 5, 'rows' => 1, 'fields' => 32],
            includeHydratedEntityCount: false,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hydrated entity count');

        PagePerformanceOrchestrator::assertComparableBlock($baseline, $baseline);
    }

    #[Test]
    public function it_rejects_a_hydrated_entity_count_that_differs_between_trees(): void
    {
        $baseline = $this->block('members_cold', 1_000_000, 'same', [
            'sql' => 5,
            'rows' => 100,
            'fields' => 200,
            'hydrated_entity_count' => 101,
        ]);
        $candidate = $this->block('members_cold', 1_000_000, 'same', [
            'sql' => 5,
            'rows' => 100,
            'fields' => 200,
            'hydrated_entity_count' => 100,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hydrated entity count mismatch');

        PagePerformanceOrchestrator::assertComparableBlock($baseline, $candidate);
    }

    #[Test]
    public function a_cache_hit_result_is_diagnostic_and_cannot_rescue_a_cold_failure(): void
    {
        $report = PagePerformanceOrchestrator::finalVerdict([
            'content_cold' => ['passed' => false],
            'members_cold' => ['passed' => true],
            'content_hit_diagnostic' => ['passed' => true],
        ]);

        self::assertFalse($report);
    }

    /** @param array<string, int|string> $trace */
    private function block(
        string $page,
        int $medianNs,
        string $bodyHash,
        array $trace,
        bool $includeHydratedEntityCount = true,
    ): array {
        if ($includeHydratedEntityCount && !array_key_exists('hydrated_entity_count', $trace)) {
            $trace['hydrated_entity_count'] = $trace['rendered_rows'] ?? $trace['rows'] ?? 1;
        }

        return [
            'page' => $page,
            'samples_ns' => array_fill(0, 200, $medianNs),
            'response' => ['sha256' => hash('sha256', $bodyHash), 'bytes' => strlen($bodyHash), 'status' => 200],
            'trace' => $trace,
            'environment' => ['php' => PHP_VERSION, 'ini_sha256' => 'same'],
            'workload_sha256' => 'same-workload',
        ];
    }

    /** @param list<string> $command @return array{exit_code:int,stdout:string,stderr:string} */
    private function runProbeWithDeadline(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__);
        self::assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 3.0;
        $status = proc_get_status($process);
        while ($status['running']) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                usleep(50_000);
                $terminated = proc_get_status($process);
                if ($terminated['running']) {
                    proc_terminate($process, 9);
                }
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                self::fail('Benchmark subprocess collector exceeded the 3 second deadline.');
            }
            usleep(10_000);
            $status = proc_get_status($process);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeExitCode = proc_close($process);

        return [
            'exit_code' => $status['exitcode'] >= 0 ? $status['exitcode'] : $closeExitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
