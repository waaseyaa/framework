<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Validator;

final class StructuralChecker
{
    private const MAX_SCORE = 20.0;
    private const PENALTY_PER_MISSING_FILE = 4.0;
    private const PENALTY_PER_LAYER_VIOLATION = 5.0;

    /**
     * @param string[] $references
     * @param string[] $existingFiles
     * @param string[] $layerViolations
     * @return array{score: float, issues: array<string>}
     */
    public function check(array $references, array $existingFiles, array $layerViolations = []): array
    {
        $issues = [];
        $penalty = 0.0;

        $existingSet = array_flip($existingFiles);

        foreach ($references as $ref) {
            if (!isset($existingSet[$ref])) {
                $issues[] = "Missing referenced file: {$ref}";
                $penalty += self::PENALTY_PER_MISSING_FILE;
            }
        }

        foreach ($layerViolations as $violation) {
            $issues[] = "Layer violation: {$violation}";
            $penalty += self::PENALTY_PER_LAYER_VIOLATION;
        }

        $score = max(0.0, self::MAX_SCORE - $penalty);

        return ['score' => $score, 'issues' => $issues];
    }
}
