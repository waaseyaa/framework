<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Validator;

final class ContradictionChecker
{
    private const MAX_SCORE = 20.0;
    private const PENALTY_PER_CONTRADICTION = 5.0;

    /**
     * @param string[] $contextFacts
     * @return array{score: float, issues: array<string>}
     */
    public function check(string $modelOutput, array $contextFacts): array
    {
        $issues = [];
        $penalty = 0.0;
        $outputLower = strtolower($modelOutput);

        foreach ($contextFacts as $fact) {
            $negatedTerms = $this->extractNegatedTerms(strtolower($fact));

            foreach ($negatedTerms as $term) {
                if (str_contains($outputLower, $term)) {
                    $issues[] = "Contradiction: output contains '{$term}' which is negated in context fact: {$fact}";
                    $penalty += self::PENALTY_PER_CONTRADICTION;
                    break; // only penalize once per fact
                }
            }
        }

        $penalty = min($penalty, self::MAX_SCORE);
        $score = self::MAX_SCORE - $penalty;

        return ['score' => $score, 'issues' => $issues];
    }

    /** @return string[] */
    private function extractNegatedTerms(string $fact): array
    {
        $terms = [];

        // Match "not X" patterns — capture the word(s) after "not"
        if (preg_match_all('/\bnot\s+(\w+(?:\s+\w+)?)\b/', $fact, $matches)) {
            foreach ($matches[1] as $match) {
                $terms[] = trim($match);
            }
        }

        // Match "no X" patterns — capture the word(s) after "no"
        if (preg_match_all('/\bno\s+(\w+(?:\s+\w+)?)\b/', $fact, $matches)) {
            foreach ($matches[1] as $match) {
                $terms[] = trim($match);
            }
        }

        return $terms;
    }
}
