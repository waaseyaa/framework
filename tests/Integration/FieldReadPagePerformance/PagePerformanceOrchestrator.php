<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FieldReadPagePerformance;

/**
 * Version-neutral comparison logic for the frozen real-page performance gate.
 *
 * This file is loaded by the parent benchmark process, never through either
 * compared framework tree. Keep it free of Waaseyaa runtime dependencies.
 */
final class PagePerformanceOrchestrator
{
    public const int BLOCKS = 9;
    public const int WARMUPS = 30;
    public const int SAMPLES = 200;
    public const float RATIO_LIMIT = 1.03;
    public const int ABSOLUTE_FLOOR_NS = 500_000;
    public const int PER_HYDRATED_ENTITY_NS = 50_000;

    public static function validateSourceTrees(string $baseline, string $candidate): void
    {
        $baselineReal = realpath($baseline);
        $candidateReal = realpath($candidate);
        if ($baselineReal === false || $candidateReal === false) {
            throw new \InvalidArgumentException('Both source trees must exist.');
        }
        if ($baselineReal === $candidateReal) {
            throw new \InvalidArgumentException('Baseline and candidate must be different source trees.');
        }
        foreach ([$baselineReal, $candidateReal] as $tree) {
            if (!is_file($tree . '/vendor/autoload.php')) {
                throw new \InvalidArgumentException(sprintf('Source tree has no vendor/autoload.php: %s', $tree));
            }
        }
    }

    /** @param array<string, string> $expected @param array<string, string> $actual */
    public static function assertSameFixtureManifest(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new \RuntimeException('Frozen fixture manifest drift detected.');
        }
    }

    /** @param array<string, mixed> $baseline @param array<string, mixed> $candidate */
    public static function assertComparableBlock(array $baseline, array $candidate): void
    {
        $baselineHydratedEntities = self::hydratedEntityCount($baseline);
        $candidateHydratedEntities = self::hydratedEntityCount($candidate);
        if ($baselineHydratedEntities !== $candidateHydratedEntities) {
            throw new \RuntimeException('Frozen hydrated entity count mismatch between source trees.');
        }

        $keys = ['page', 'response', 'trace', 'workload_sha256'];
        foreach ($keys as $key) {
            if (($baseline[$key] ?? null) !== ($candidate[$key] ?? null)) {
                throw new \RuntimeException(sprintf('Page response/trace drift detected at %s.', $key));
            }
        }

        $baselineEnvironment = is_array($baseline['environment'] ?? null) ? $baseline['environment'] : [];
        $candidateEnvironment = is_array($candidate['environment'] ?? null) ? $candidate['environment'] : [];
        foreach (['php', 'php_binary_sha256', 'ini_sha256', 'extensions_sha256', 'fixture_sha256'] as $key) {
            if (($baselineEnvironment[$key] ?? null) !== ($candidateEnvironment[$key] ?? null)) {
                throw new \RuntimeException(sprintf('Page environment drift detected at %s.', $key));
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $baselineBlocks
     * @param list<array<string, mixed>> $candidateBlocks
     * @return array<string, mixed>
     */
    public static function comparePage(array $baselineBlocks, array $candidateBlocks): array
    {
        if (count($baselineBlocks) !== self::BLOCKS || count($candidateBlocks) !== self::BLOCKS) {
            throw new \InvalidArgumentException(sprintf('Each page comparison requires exactly %d process blocks.', self::BLOCKS));
        }

        $ratios = [];
        $deltas = [];
        $baselineMedians = [];
        $candidateMedians = [];
        $hydratedEntityCounts = [];
        foreach ($baselineBlocks as $index => $baseline) {
            $candidate = $candidateBlocks[$index];
            self::assertComparableBlock($baseline, $candidate);
            $hydratedEntityCounts[] = self::hydratedEntityCount($baseline);
            $baselineSamples = self::samples($baseline);
            $candidateSamples = self::samples($candidate);
            $baselineMedian = self::median($baselineSamples);
            $candidateMedian = self::median($candidateSamples);
            if ($baselineMedian <= 0) {
                throw new \RuntimeException('Baseline median must be positive.');
            }
            $baselineMedians[] = $baselineMedian;
            $candidateMedians[] = $candidateMedian;
            $ratios[] = $candidateMedian / $baselineMedian;
            $deltas[] = $candidateMedian - $baselineMedian;
        }

        sort($ratios, SORT_NUMERIC);
        sort($deltas, SORT_NUMERIC);
        $ratioUpper95 = self::nearestRank($ratios, 0.95);
        $deltaUpper95 = self::nearestRank($deltas, 0.95);
        $hydratedEntityCounts = array_values(array_unique($hydratedEntityCounts));
        if (count($hydratedEntityCounts) !== 1) {
            throw new \RuntimeException('Frozen hydrated entity count changed between process blocks.');
        }
        $hydratedEntityCount = $hydratedEntityCounts[0];
        $absoluteLimit = max(
            self::ABSOLUTE_FLOOR_NS,
            self::PER_HYDRATED_ENTITY_NS * $hydratedEntityCount,
        );

        return [
            'baseline_median_ns' => self::median($baselineMedians),
            'candidate_median_ns' => self::median($candidateMedians),
            'paired_ratios' => $ratios,
            'paired_deltas_ns' => $deltas,
            'ratio_upper_95' => $ratioUpper95,
            'delta_upper_95_ns' => $deltaUpper95,
            'ratio_limit' => self::RATIO_LIMIT,
            'hydrated_entity_count' => $hydratedEntityCount,
            'absolute_floor_ns' => self::ABSOLUTE_FLOOR_NS,
            'per_hydrated_entity_ns' => self::PER_HYDRATED_ENTITY_NS,
            'absolute_limit_ns' => $absoluteLimit,
            'passed' => $ratioUpper95 <= self::RATIO_LIMIT && $deltaUpper95 <= $absoluteLimit,
        ];
    }

    /** @param array<string, array<string, mixed>> $pages */
    public static function finalVerdict(array $pages): bool
    {
        return ($pages['content_cold']['passed'] ?? false) === true
            && ($pages['members_cold']['passed'] ?? false) === true;
    }

    /** @param array<string, mixed> $block @return list<int|float> */
    private static function samples(array $block): array
    {
        $samples = $block['samples_ns'] ?? null;
        if (!is_array($samples) || count($samples) !== self::SAMPLES) {
            throw new \RuntimeException(sprintf('Every process block must contain exactly %d samples.', self::SAMPLES));
        }
        foreach ($samples as $sample) {
            if ((!is_int($sample) && !is_float($sample)) || $sample <= 0) {
                throw new \RuntimeException('Timing samples must be positive numbers.');
            }
        }

        return array_values($samples);
    }

    /** @param array<string, mixed> $block */
    private static function hydratedEntityCount(array $block): int
    {
        $trace = $block['trace'] ?? null;
        $count = is_array($trace) ? ($trace['hydrated_entity_count'] ?? null) : null;
        if (!is_int($count) || $count < 1) {
            throw new \RuntimeException('Every page trace must declare a positive frozen hydrated entity count.');
        }

        return $count;
    }

    /** @param list<int|float> $values */
    private static function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        if ($count === 0) {
            throw new \InvalidArgumentException('Cannot calculate a median of no values.');
        }
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    /** @param list<int|float> $sorted */
    private static function nearestRank(array $sorted, float $quantile): float
    {
        $rank = max(1, (int) ceil($quantile * count($sorted)));

        return (float) $sorted[$rank - 1];
    }
}
