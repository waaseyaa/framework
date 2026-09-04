<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;

/**
 * The result of evaluating one {@see ArtifactPlan} against one project
 * (ADR-025 D-6.2).
 *
 * Constructed only by the execution authority, because only the execution
 * authority observes a project. That asymmetry is the whole point of the D-6
 * type split: the plan is a pure function of compiler input and cannot carry a
 * status, because a pure compiler cannot know whether a file it renders
 * already exists.
 *
 * Both digests are derived here rather than accepted, so an evaluation can
 * never disagree with the plan and project state it was computed from.
 *
 * Unlike the plan, the request and the result, this type is deliberately NOT
 * one of the four versioned `waaseyaa.*` documents: it is a return value, so it
 * carries no schema string and no digest of its own.
 *
 * @api
 */
final readonly class EvaluatedArtifactPlan
{
    public string $planDigest;
    public string $projectStateDigest;

    /**
     * @param array<string, ArtifactStatus> $status keyed by the plan's artifact paths
     * @param list<string> $adds the D-6.2 setDelta additions, sorted and unique
     * @param list<string> $drops the D-6.2 setDelta removals, sorted and unique
     * @param list<GenerationViolation> $refusals the coded detail behind every refused status
     */
    public function __construct(
        public ArtifactPlan $plan,
        public ProjectStateIdentity $projectState,
        public array $status,
        public array $adds = [],
        public array $drops = [],
        public array $refusals = [],
    ) {
        foreach (['adds' => $adds, 'drops' => $drops, 'refusals' => $refusals] as $member => $values) {
            self::assertList($values, $member);
        }
        $planned = array_map(static fn(GeneratedArtifact $artifact): string => $artifact->path, $plan->artifacts);
        foreach ($status as $path => $outcome) {
            if (!in_array($path, $planned, true)) {
                throw new \InvalidArgumentException("Evaluated plan status names a path the plan does not carry: {$path}");
            }
        }
        if (count($status) !== count($planned)) {
            throw new \InvalidArgumentException('Evaluated plan status must name every plan artifact exactly once.');
        }

        self::assertSortedUnique($adds, 'adds');
        self::assertSortedUnique($drops, 'drops');

        $addressed = [];
        foreach ($refusals as $refusal) {
            if ($refusal->path !== null) {
                $addressed[] = $refusal->path;
            }
        }
        foreach ($status as $path => $outcome) {
            if ($outcome === ArtifactStatus::Refused && !in_array($path, $addressed, true)) {
                throw new \InvalidArgumentException("Evaluated plan refusal detail is missing for: {$path}");
            }
        }

        $this->planDigest = $plan->digest;
        $this->projectStateDigest = $projectState->digest;
    }

    /** @return array{adds: list<string>, drops: list<string>} */
    public function setDelta(): array
    {
        return ['adds' => $this->adds, 'drops' => $this->drops];
    }

    /**
     * The paths this evaluation would publish, sorted.
     *
     * @return list<string>
     */
    public function changed(): array
    {
        $changed = [];
        foreach ($this->status as $path => $outcome) {
            if ($outcome === ArtifactStatus::Created || $outcome === ArtifactStatus::Changed) {
                $changed[] = $path;
            }
        }
        sort($changed, SORT_STRING);

        return $changed;
    }

    /** @param list<string> $values */
    private static function assertSortedUnique(array $values, string $member): void
    {
        $previous = null;
        foreach ($values as $value) {
            if ($previous !== null && strcmp($previous, $value) >= 0) {
                throw new \InvalidArgumentException("Evaluated plan {$member} must be sorted and unique.");
            }
            $previous = $value;
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function assertList(array $values, string $member): void
    {
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException("Evaluated plan {$member} must be a list.");
        }
    }
}
