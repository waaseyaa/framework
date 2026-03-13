<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Validator;

final class ValidationReport
{
    public readonly \DateTimeImmutable $validatedAt;

    /** @param string[] $issues */
    public function __construct(
        public readonly string $sessionId,
        public readonly int $driftScore,
        public readonly float $semanticAlignment,
        public readonly float $structuralScore,
        public readonly float $contradictionScore,
        public readonly array $issues,
        public readonly string $recommendation,
    ) {
        $this->validatedAt = new \DateTimeImmutable();
    }

    /**
     * @return array{
     *   session_id: string,
     *   validated_at: string,
     *   drift_score: int,
     *   semantic_alignment: float,
     *   structural_score: float,
     *   contradiction_score: float,
     *   issues: string[],
     *   recommendation: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'validated_at' => $this->validatedAt->format(\DateTimeInterface::ATOM),
            'drift_score' => $this->driftScore,
            'semantic_alignment' => $this->semanticAlignment,
            'structural_score' => $this->structuralScore,
            'contradiction_score' => $this->contradictionScore,
            'issues' => $this->issues,
            'recommendation' => $this->recommendation,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
