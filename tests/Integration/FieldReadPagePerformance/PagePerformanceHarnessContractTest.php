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
            self::assertSame($expectedHash, hash_file('sha256', $root . '/' . $relative), $relative);
        }
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
    public function repository_hydration_removal_is_a_failing_negative_control(): void
    {
        $baseline = $this->block('members_cold', 1_000_000, 'same-body', [
            'route' => 'performance.members',
            'controller' => 'MembersDirectoryController::index',
            'rendered_rows' => 100,
            'rendered_fields' => 200,
        ]);
        $withoutHydratedRows = $this->block('members_cold', 1_000_000, 'same-body', [
            'route' => 'performance.members',
            'controller' => 'MembersDirectoryController::index',
            'rendered_rows' => 0,
            'rendered_fields' => 0,
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
        $absoluteFailure = [];
        for ($i = 0; $i < 9; ++$i) {
            $baseline[] = $this->block('content', 1_000_000, 'same', ['sql' => 5, 'rows' => 1, 'fields' => 32]);
            $ratioFailure[] = $this->block('content', 1_040_000, 'same', ['sql' => 5, 'rows' => 1, 'fields' => 32]);
            $absoluteFailure[] = $this->block('content', 1_600_000, 'same', ['sql' => 5, 'rows' => 1, 'fields' => 32]);
        }

        self::assertFalse(PagePerformanceOrchestrator::comparePage($baseline, $ratioFailure)['passed']);
        self::assertFalse(PagePerformanceOrchestrator::comparePage($baseline, $absoluteFailure)['passed']);
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
    private function block(string $page, int $medianNs, string $bodyHash, array $trace): array
    {
        return [
            'page' => $page,
            'samples_ns' => array_fill(0, 200, $medianNs),
            'response' => ['sha256' => hash('sha256', $bodyHash), 'bytes' => strlen($bodyHash), 'status' => 200],
            'trace' => $trace,
            'environment' => ['php' => PHP_VERSION, 'ini_sha256' => 'same'],
            'workload_sha256' => 'same-workload',
        ];
    }
}
