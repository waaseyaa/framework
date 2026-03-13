<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Validator;

final class DriftScorer
{
    public function __construct(
        private readonly EmbeddingProviderInterface $embeddingProvider,
        private readonly StructuralChecker $structuralChecker,
        private readonly ContradictionChecker $contradictionChecker,
    ) {}

    /**
     * @param string[] $contextSections
     * @param string[] $referencedFiles
     * @param string[] $existingFiles
     * @param string[] $contextFacts
     */
    public function score(
        string $modelOutput,
        array $contextSections,
        array $referencedFiles,
        array $existingFiles,
        array $contextFacts,
    ): ValidationReport {
        // 1. Compute semantic alignment (0-60)
        $outputEmbedding = $this->embeddingProvider->embed($modelOutput);

        $semanticScore = 0.0;
        if (count($contextSections) > 0) {
            $sectionEmbeddings = $this->embeddingProvider->embedBatch($contextSections);
            $similarities = array_map(
                fn(array $sectionEmbedding) => $this->embeddingProvider->cosineSimilarity($outputEmbedding, $sectionEmbedding),
                $sectionEmbeddings,
            );
            $avgSimilarity = array_sum($similarities) / count($similarities);
            // Scale similarity (0.0–1.0) to semantic score (0–60)
            $semanticScore = $avgSimilarity * 60.0;
        } else {
            // No context sections — full semantic score
            $semanticScore = 60.0;
        }

        // 2. Structural check (0-20)
        $structuralResult = $this->structuralChecker->check($referencedFiles, $existingFiles);
        $structuralScore = $structuralResult['score'];

        // 3. Contradiction check (0-20)
        $contradictionResult = $this->contradictionChecker->check($modelOutput, $contextFacts);
        $contradictionScore = $contradictionResult['score'];

        // 4. Total score
        $total = (int) round($semanticScore + $structuralScore + $contradictionScore);
        $total = max(0, min(100, $total));

        // 5. Merge issues
        $issues = array_merge($structuralResult['issues'], $contradictionResult['issues']);

        // 6. Map to severity and recommendation
        $severity = self::severityFromScore($total);
        $recommendation = match ($severity) {
            'low' => 'Context alignment is strong. No action required.',
            'medium' => 'Minor drift detected. Review referenced files and context sections.',
            'high' => 'Significant drift detected. Update context or retrain on current specs.',
            'critical' => 'Critical drift. Model output is misaligned with codified context. Immediate review required.',
            default => 'Unknown severity.',
        };

        return new ValidationReport(
            sessionId: uniqid('session_', true),
            driftScore: $total,
            semanticAlignment: $semanticScore,
            structuralScore: $structuralScore,
            contradictionScore: $contradictionScore,
            issues: $issues,
            recommendation: $recommendation,
        );
    }

    public static function severityFromScore(int $score): string
    {
        if ($score >= 75) {
            return 'low';
        }
        if ($score >= 50) {
            return 'medium';
        }
        if ($score >= 25) {
            return 'high';
        }

        return 'critical';
    }
}
