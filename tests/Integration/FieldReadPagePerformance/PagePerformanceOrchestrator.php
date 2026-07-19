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
    public const int BLOCKS = 20;
    public const int WARMUPS = 30;
    public const int SAMPLES = 200;
    public const int BOOTSTRAP_RESAMPLES = 100_000;
    public const float BOOTSTRAP_CONFIDENCE = 0.95;
    public const int BOOTSTRAP_SEED = 20_642_064;
    public const float RATIO_LIMIT = 1.03;
    public const int ABSOLUTE_FLOOR_NS = 500_000;
    public const int PER_HYDRATED_ENTITY_NS = 50_000;
    public const float CONSISTENT_REGRESSION_FRACTION = 0.75;

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
        $requestP95Ratios = [];
        $requestP95Deltas = [];
        $requestMaxRatios = [];
        $requestMaxDeltas = [];
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
            $baselineP95 = self::quantile($baselineSamples, 0.95);
            $candidateP95 = self::quantile($candidateSamples, 0.95);
            $baselineMax = max($baselineSamples);
            $candidateMax = max($candidateSamples);
            $requestP95Ratios[] = $candidateP95 / $baselineP95;
            $requestP95Deltas[] = $candidateP95 - $baselineP95;
            $requestMaxRatios[] = $candidateMax / $baselineMax;
            $requestMaxDeltas[] = $candidateMax - $baselineMax;
        }

        $hydratedEntityCounts = array_values(array_unique($hydratedEntityCounts));
        if (count($hydratedEntityCounts) !== 1) {
            throw new \RuntimeException('Frozen hydrated entity count changed between process blocks.');
        }
        $hydratedEntityCount = $hydratedEntityCounts[0];
        $absoluteLimit = max(
            self::ABSOLUTE_FLOOR_NS,
            self::PER_HYDRATED_ENTITY_NS * $hydratedEntityCount,
        );
        $bootstrap = self::bootstrapPairedMedians($ratios, $deltas);
        $ratioUpper95 = $bootstrap['paired_median_ratio_upper_bound'];
        $deltaUpper95 = $bootstrap['paired_median_delta_upper_bound_ns'];
        $ratioOverBudget = count(array_filter(
            $ratios,
            static fn(float $ratio): bool => $ratio > self::RATIO_LIMIT,
        ));
        $deltaOverBudget = count(array_filter(
            $deltas,
            static fn(float $delta): bool => $delta > $absoluteLimit,
        ));
        $minimumConsistentBlocks = (int) ceil(self::CONSISTENT_REGRESSION_FRACTION * self::BLOCKS);
        $maxRatioOverBudget = count(array_filter(
            $requestMaxRatios,
            static fn(float $ratio): bool => $ratio > self::RATIO_LIMIT,
        ));
        $maxDeltaOverBudget = count(array_filter(
            $requestMaxDeltas,
            static fn(float $delta): bool => $delta > $absoluteLimit,
        ));
        $largeConsistentRegression = $maxRatioOverBudget >= $minimumConsistentBlocks
            || $maxDeltaOverBudget >= $minimumConsistentBlocks;
        $baselinePooled = self::pooledSamples($baselineBlocks);
        $candidatePooled = self::pooledSamples($candidateBlocks);
        $baselineP95 = self::quantile($baselinePooled, 0.95);
        $candidateP95 = self::quantile($candidatePooled, 0.95);
        $baselineMax = max($baselinePooled);
        $candidateMax = max($candidatePooled);
        $passed = $ratioUpper95 <= self::RATIO_LIMIT && $deltaUpper95 <= $absoluteLimit;

        return [
            'baseline_median_ns' => self::median($baselineMedians),
            'candidate_median_ns' => self::median($candidateMedians),
            'paired_median_ratio' => self::median($ratios),
            'paired_median_delta_ns' => self::median($deltas),
            'paired_ratios' => $ratios,
            'paired_deltas_ns' => $deltas,
            'ratio_upper_95' => $ratioUpper95,
            'delta_upper_95_ns' => $deltaUpper95,
            'bootstrap' => $bootstrap,
            'raw_paired' => [
                'ratio_p95' => self::quantile($ratios, 0.95),
                'ratio_max' => max($ratios),
                'delta_p95_ns' => self::quantile($deltas, 0.95),
                'delta_max_ns' => max($deltas),
                'positive_delta_blocks' => count(array_filter(
                    $deltas,
                    static fn(float $delta): bool => $delta > 0,
                )),
                'ratios' => $ratios,
                'deltas_ns' => $deltas,
                'request_p95_ratios' => $requestP95Ratios,
                'request_p95_deltas_ns' => $requestP95Deltas,
                'request_max_ratios' => $requestMaxRatios,
                'request_max_deltas_ns' => $requestMaxDeltas,
            ],
            'pooled_requests' => [
                'samples_per_tree' => count($baselinePooled),
                'baseline' => ['p95_ns' => $baselineP95, 'max_ns' => $baselineMax],
                'candidate' => ['p95_ns' => $candidateP95, 'max_ns' => $candidateMax],
                'p95_ratio' => $candidateP95 / $baselineP95,
                'p95_delta_ns' => $candidateP95 - $baselineP95,
                'max_ratio' => $candidateMax / $baselineMax,
                'max_delta_ns' => $candidateMax - $baselineMax,
            ],
            'consistent_regression' => [
                'threshold_fraction' => self::CONSISTENT_REGRESSION_FRACTION,
                'minimum_consistent_blocks' => $minimumConsistentBlocks,
                'ratio_budget_exceeded_blocks' => $ratioOverBudget,
                'absolute_budget_exceeded_blocks' => $deltaOverBudget,
                'raw_max_ratio_budget_exceeded_blocks' => $maxRatioOverBudget,
                'raw_max_absolute_budget_exceeded_blocks' => $maxDeltaOverBudget,
                'large_consistent_regression' => $largeConsistentRegression,
                'bootstrap_passed_with_large_consistent_regression' => $passed && $largeConsistentRegression,
            ],
            'ratio_limit' => self::RATIO_LIMIT,
            'hydrated_entity_count' => $hydratedEntityCount,
            'absolute_floor_ns' => self::ABSOLUTE_FLOOR_NS,
            'per_hydrated_entity_ns' => self::PER_HYDRATED_ENTITY_NS,
            'absolute_limit_ns' => $absoluteLimit,
            'passed' => $passed,
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

    /**
     * @param list<float> $ratios
     * @param list<float> $deltas
     * @return array{resamples:int,confidence:float,seed:int,paired_median_ratio_upper_bound:float,paired_median_delta_upper_bound_ns:float}
     */
    private static function bootstrapPairedMedians(array $ratios, array $deltas): array
    {
        $count = count($ratios);
        if ($count !== self::BLOCKS || count($deltas) !== $count) {
            throw new \InvalidArgumentException('Bootstrap input must contain one ratio and delta for every paired block.');
        }

        $ratioStatistics = [];
        $deltaStatistics = [];
        $randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937(self::BOOTSTRAP_SEED));
        for ($resample = 0; $resample < self::BOOTSTRAP_RESAMPLES; ++$resample) {
            $sampledRatios = [];
            $sampledDeltas = [];
            for ($draw = 0; $draw < $count; ++$draw) {
                $index = $randomizer->getInt(0, $count - 1);
                $sampledRatios[] = $ratios[$index];
                $sampledDeltas[] = $deltas[$index];
            }
            $ratioStatistics[] = self::median($sampledRatios);
            $deltaStatistics[] = self::median($sampledDeltas);
        }

        return [
            'resamples' => self::BOOTSTRAP_RESAMPLES,
            'confidence' => self::BOOTSTRAP_CONFIDENCE,
            'seed' => self::BOOTSTRAP_SEED,
            'paired_median_ratio_upper_bound' => self::quantile($ratioStatistics, self::BOOTSTRAP_CONFIDENCE),
            'paired_median_delta_upper_bound_ns' => self::quantile($deltaStatistics, self::BOOTSTRAP_CONFIDENCE),
        ];
    }

    /** @param list<array<string, mixed>> $blocks @return list<int|float> */
    private static function pooledSamples(array $blocks): array
    {
        $pooled = [];
        foreach ($blocks as $block) {
            array_push($pooled, ...self::samples($block));
        }

        return $pooled;
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

    /** @param list<int|float> $values */
    private static function quantile(array $values, float $quantile): float
    {
        if ($values === [] || $quantile <= 0 || $quantile > 1) {
            throw new \InvalidArgumentException('Nearest-rank quantile requires values and a quantile in (0, 1].');
        }
        sort($values, SORT_NUMERIC);
        $rank = max(1, (int) ceil($quantile * count($values)));

        return (float) $values[$rank - 1];
    }
}
