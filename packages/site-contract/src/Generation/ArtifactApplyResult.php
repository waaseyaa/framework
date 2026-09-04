<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;

/**
 * The result/error envelope of one artifact apply (ADR-025 D-6.4).
 *
 * A strict superset of today's initialization result: no information available
 * today is lost, and `dryRun` and `cancelled` are absorbed into `outcome`.
 *
 * This type is also the generation binding of the governed-change protocol's
 * receipt envelope (D-14.7): these members are retained verbatim and relocated
 * under a receipt's `domain_payload`, with the envelope members added around
 * them rather than mixed into them.
 *
 * @api
 */
final readonly class ArtifactApplyResult
{
    public const string SCHEMA_ID = 'waaseyaa.artifact_result';
    public const int CONTRACT_VERSION = 1;

    /**
     * @param array<string, ArtifactStatus> $status
     * @param list<string> $changed the paths actually published, sorted
     * @param list<GenerationViolation> $errors empty unless the outcome is refused
     */
    public function __construct(
        public ArtifactApplyOutcome $outcome,
        public string $planDigest,
        public string $projectStateDigest,
        public array $status,
        public array $changed,
        public bool $recoveredInterruptedTransaction = false,
        public bool $cleanupPending = false,
        public array $errors = [],
    ) {
        foreach (['plan_digest' => $planDigest, 'project_state_digest' => $projectStateDigest] as $member => $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new \InvalidArgumentException("Artifact apply result {$member} must be 64 lowercase hex characters.");
            }
        }
        $previous = null;
        foreach ($changed as $path) {
            if ($previous !== null && strcmp($previous, $path) >= 0) {
                throw new \InvalidArgumentException('Artifact apply result changed must be sorted and unique.');
            }
            $previous = $path;
        }
        foreach ($changed as $path) {
            if (!array_key_exists($path, $status)) {
                throw new \InvalidArgumentException("Artifact apply result changed a path it reports no status for: {$path}");
            }
        }
        if ($outcome !== ArtifactApplyOutcome::Refused && $errors !== []) {
            throw new \InvalidArgumentException('Artifact apply result errors are empty unless the outcome is refused.');
        }
        if ($outcome === ArtifactApplyOutcome::Refused && $errors === []) {
            throw new \InvalidArgumentException('A refused artifact apply result must carry at least one coded error.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_ID,
            'version' => self::CONTRACT_VERSION,
            'outcome' => $this->outcome->value,
            'plan_digest' => $this->planDigest,
            'project_state_digest' => $this->projectStateDigest,
            // A path-keyed map is never a JSON list, EXCEPT when it is empty:
            // `array_is_list([])` is true, so a planned or no-op result would
            // emit `[]` where every other result emits `{}`. One member of a
            // closed v1 document cannot have two JSON types, and this map is
            // relocated verbatim into every change receipt's domain payload.
            'status' => $this->status === []
                ? new \stdClass()
                : array_map(static fn(ArtifactStatus $status): string => $status->value, $this->status),
            'changed' => $this->changed,
            'recovered_interrupted_transaction' => $this->recoveredInterruptedTransaction,
            'cleanup_pending' => $this->cleanupPending,
            'errors' => array_map(
                static fn(GenerationViolation $violation): array => $violation->toArray(),
                $this->errors,
            ),
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }
}
