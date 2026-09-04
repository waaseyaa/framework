<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

/**
 * One observed target inside a captured project-state identity (ADR-025 D-6.2).
 *
 * A target records what evaluation saw at one project-relative path: whether a
 * file was there, its bytes, and its permission bits. It records an observation;
 * it never performs one. The path containment rule is the same one a generated
 * artifact's path already satisfies, because a target set is drawn from plan
 * paths and recorded paths, both of which are already contained.
 *
 * @api
 */
final readonly class ProjectStateTarget
{
    public function __construct(
        public string $path,
        public ObservedTargetState $state,
        public string $sha256 = ProjectStateIdentity::ABSENT_DIGEST,
        public ObservedTargetMode $mode = ObservedTargetMode::Unknown,
    ) {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains("/{$path}/", '/../')) {
            throw new \InvalidArgumentException('Project state target paths must be safe project-relative paths.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('Project state target sha256 must be 64 lowercase hex characters.');
        }
        $isAbsentDigest = $sha256 === ProjectStateIdentity::ABSENT_DIGEST;
        $isConsistent = match ($state) {
            ObservedTargetState::Absent => $isAbsentDigest && $mode === ObservedTargetMode::Unknown,
            ObservedTargetState::File => !$isAbsentDigest,
            ObservedTargetState::Other => $isAbsentDigest
                && in_array($mode, [ObservedTargetMode::Other, ObservedTargetMode::Unknown], true),
        };
        if (!$isConsistent) {
            throw new \InvalidArgumentException('Project state target observation is inconsistent.');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'state' => $this->state->value,
            'sha256' => $this->sha256,
            'mode' => $this->mode->value,
        ];
    }
}
