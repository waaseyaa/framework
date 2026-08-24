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
        for ($i = 0; $i < 20; ++$i) {
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
        for ($i = 0; $i < 20; ++$i) {
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
    public function the_gate_uses_twenty_blocks_and_a_deterministic_one_sided_bootstrap_bound(): void
    {
        self::assertSame(20, PagePerformanceOrchestrator::BLOCKS);
        self::assertSame(100_000, PagePerformanceOrchestrator::BOOTSTRAP_RESAMPLES);
        self::assertSame(0.95, PagePerformanceOrchestrator::BOOTSTRAP_CONFIDENCE);

        $baseline = [];
        $candidate = [];
        for ($i = 0; $i < 20; ++$i) {
            $baseline[] = $this->block('content_cold', 40_000_000 + ($i * 10_000), 'same', [
                'hydrated_entity_count' => 1,
            ]);
            $candidate[] = $this->block('content_cold', 40_200_000 + ($i * 10_000), 'same', [
                'hydrated_entity_count' => 1,
            ]);
        }

        $first = PagePerformanceOrchestrator::comparePage($baseline, $candidate);
        $second = PagePerformanceOrchestrator::comparePage($baseline, $candidate);

        self::assertSame($first['bootstrap'], $second['bootstrap']);
        self::assertSame(100_000, $first['bootstrap']['resamples']);
        self::assertSame(0.95, $first['bootstrap']['confidence']);
        self::assertLessThanOrEqual(1.03, $first['bootstrap']['paired_median_ratio_upper_bound']);
        self::assertLessThanOrEqual(500_000, $first['bootstrap']['paired_median_delta_upper_bound_ns']);
        self::assertTrue($first['passed']);
    }

    #[Test]
    public function one_noisy_block_is_visible_in_raw_diagnostics_but_does_not_become_the_gate(): void
    {
        $baseline = [];
        $candidate = [];
        for ($i = 0; $i < 20; ++$i) {
            $baseline[] = $this->block('content_cold', 40_000_000, 'same', ['hydrated_entity_count' => 1]);
            $candidate[] = $this->block(
                'content_cold',
                $i === 7 ? 44_000_000 : 40_000_000,
                'same',
                ['hydrated_entity_count' => 1],
            );
        }

        $comparison = PagePerformanceOrchestrator::comparePage($baseline, $candidate);

        self::assertTrue($comparison['passed']);
        self::assertSame(1.10, $comparison['raw_paired']['ratio_max']);
        self::assertSame(4_000_000.0, $comparison['raw_paired']['delta_max_ns']);
        self::assertSame(1, $comparison['consistent_regression']['ratio_budget_exceeded_blocks']);
        self::assertSame(1, $comparison['consistent_regression']['absolute_budget_exceeded_blocks']);
        self::assertFalse($comparison['consistent_regression']['large_consistent_regression']);
        self::assertSame(44_000_000, $comparison['pooled_requests']['candidate']['max_ns']);
    }

    #[Test]
    public function a_large_regression_across_three_quarters_of_blocks_is_surfaced_explicitly(): void
    {
        $baseline = [];
        $candidate = [];
        for ($i = 0; $i < 20; ++$i) {
            $baseline[] = $this->block('content_cold', 40_000_000, 'same', ['hydrated_entity_count' => 1]);
            $candidate[] = $this->block(
                'content_cold',
                $i < 15 ? 41_600_000 : 39_900_000,
                'same',
                ['hydrated_entity_count' => 1],
            );
        }

        $comparison = PagePerformanceOrchestrator::comparePage($baseline, $candidate);

        self::assertSame(15, $comparison['consistent_regression']['minimum_consistent_blocks']);
        self::assertSame(15, $comparison['consistent_regression']['ratio_budget_exceeded_blocks']);
        self::assertSame(15, $comparison['consistent_regression']['absolute_budget_exceeded_blocks']);
        self::assertTrue($comparison['consistent_regression']['large_consistent_regression']);
        self::assertFalse($comparison['passed']);
    }

    #[Test]
    public function a_consistent_raw_max_regression_is_surfaced_even_when_the_bootstrap_gate_passes(): void
    {
        $baseline = [];
        $candidate = [];
        for ($i = 0; $i < 20; ++$i) {
            $baselineBlock = $this->block('content_cold', 40_000_000, 'same', ['hydrated_entity_count' => 1]);
            $candidateBlock = $this->block('content_cold', 40_000_000, 'same', ['hydrated_entity_count' => 1]);
            if ($i < 15) {
                $candidateBlock['samples_ns'][199] = 44_000_000;
            }
            $baseline[] = $baselineBlock;
            $candidate[] = $candidateBlock;
        }

        $comparison = PagePerformanceOrchestrator::comparePage($baseline, $candidate);

        self::assertTrue($comparison['passed']);
        self::assertSame(15, $comparison['consistent_regression']['raw_max_ratio_budget_exceeded_blocks']);
        self::assertSame(15, $comparison['consistent_regression']['raw_max_absolute_budget_exceeded_blocks']);
        self::assertTrue($comparison['consistent_regression']['large_consistent_regression']);
        self::assertTrue($comparison['consistent_regression']['bootstrap_passed_with_large_consistent_regression']);
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

    #[Test]
    public function retained_probe_collector_drains_large_stdout_and_stderr(): void
    {
        // #2491 Wave 2a: runProbeWithDeadline() is retained rather than
        // converted, so its non-blocking both-stream drain needs its own volume
        // proof. The existing deadlock probe only ever hands this collector a
        // small JSON payload, so it never exercised the buffer-fill case that
        // wedges the sequential-drain shape. 256KiB on BOTH streams is well past
        // the ~64KB pipe buffer in either direction.
        $bytes = 262_144;
        $child = <<<'PHP'
            $bytes = (int) $argv[1];
            fwrite(STDERR, str_repeat('E', $bytes));
            fwrite(STDOUT, str_repeat('O', $bytes));
            exit(23);
            PHP;

        $result = $this->runProbeWithDeadline([PHP_BINARY, '-r', $child, (string) $bytes]);

        self::assertSame(23, $result['exit_code'], $result['stderr']);
        self::assertSame($bytes, strlen($result['stdout']));
        self::assertSame($bytes, strlen($result['stderr']));
        self::assertSame(hash('sha256', str_repeat('O', $bytes)), hash('sha256', $result['stdout']));
        self::assertSame(hash('sha256', str_repeat('E', $bytes)), hash('sha256', $result['stderr']));
    }

    /**
     * RETAINED proc_open (#2491 Wave 2a): this is one of two test/benchmark
     * runners deliberately not converted to symfony/process, because it is
     * already safe and because being an INDEPENDENT implementation is the point.
     *
     * Safety: both pipes are set non-blocking below, and both are drained on
     * every iteration of the same loop — never one to EOF before the other — so
     * the blocking sequential-drain deadlock cannot occur. The loop carries a
     * hard 3.0s deadline with SIGTERM escalated to SIGKILL, and both streams get
     * a final drain after the child exits. Proven at volume by
     * {@see self::retained_probe_collector_drains_large_stdout_and_stderr()}.
     *
     * Independence: this collector runs the deadlock probe for
     * benchmarks/BenchmarkProcessRunner.php. Routing it through the same
     * component the rest of the suite now uses would make the probe partly
     * self-referential; a hand-rolled collector keeps the measurement honest.
     *
     * @param  list<string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
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
