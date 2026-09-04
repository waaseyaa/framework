<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\CanonicalJson;

/**
 * The input to one artifact apply (ADR-025 D-6.5).
 *
 * The request carries the plan **itself**, bytes included, not merely its
 * digest, and the compiler is not re-run at apply time. That is not
 * redundancy: a migration generator chooses its target filename from a clock
 * reading at compile time, so recompiling at apply would produce a different,
 * equally valid plan and the operator's review would bind nothing. Carrying
 * the plan makes apply's input provably the reviewed artifact for every
 * generator, time-dependent or not, and the digest is then a self-check
 * against transport corruption.
 *
 * @api
 */
final readonly class ArtifactApplyRequest
{
    public const string SCHEMA_ID = 'waaseyaa.artifact_apply_request';
    public const int CONTRACT_VERSION = 1;

    public string $planDigest;

    public function __construct(
        public ArtifactPlan $plan,
        string $planDigest,
        public string $projectStateDigest,
    ) {
        foreach (['plan_digest' => $planDigest, 'project_state_digest' => $projectStateDigest] as $member => $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new \InvalidArgumentException("Artifact apply request {$member} must be 64 lowercase hex characters.");
            }
        }
        // This is intentionally not derived from $plan. D-6.5 requires apply
        // to carry the digest the operator reviewed so the authority can
        // recompute the supplied plan under its lock and refuse a mismatch as
        // GEN005. Deriving it here would erase the evidence of transport
        // corruption before the authority could evaluate it.
        $this->planDigest = $planDigest;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_ID,
            'version' => self::CONTRACT_VERSION,
            'plan' => $this->plan->toArray(),
            'plan_digest' => $this->planDigest,
            'project_state_digest' => $this->projectStateDigest,
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }
}
