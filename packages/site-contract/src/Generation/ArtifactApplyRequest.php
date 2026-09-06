<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Exception\ManifestViolation;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;

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

    /**
     * Decode one already-parsed request document (#2789).
     *
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document, string $source = '<apply-request>'): self
    {
        return new ArtifactApplyRequestParser()->parse($document, $source);
    }

    /**
     * Decode the exact bytes an emitter produced with {@see canonicalJson()}.
     *
     * D-6.5 makes apply's input the thing an operator reviewed, so the bytes
     * are the evidence and this boundary refuses anything that is not the
     * canonical serialization of the document it decoded: a re-ordered,
     * pretty-printed, slash-escaped or duplicate-keyed document decodes to
     * *something*, and accepting it would mean applying a document nobody
     * emitted. The only tolerated difference is one terminating newline, which
     * is this framework's own on-disk framing for a canonical document (the
     * plan digest, the change receipt and `site:init --json` all append it) and
     * not part of the document.
     *
     * The digests are still not verified here. Whether the transported plan
     * hashes to the reviewed `plan_digest`, and whether the project still
     * matches `project_state_digest`, are `GEN005` questions the execution
     * authority answers under its exclusive lock — a decoder that answered
     * them early would be a second, lock-free authority on staleness.
     */
    public static function fromCanonicalJson(string $json, string $source = '<apply-request>'): self
    {
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            self::refuse($source, 'SITE010_INVALID_TYPE', 'Expected a canonical JSON document.', $exception);
        }
        if (!$decoded instanceof \stdClass) {
            self::refuse($source, 'SITE010_INVALID_TYPE', 'Expected a canonical JSON object document.');
        }
        /** @var array<string, mixed> $document */
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $request = self::fromArray($document, $source);
        $canonical = $request->canonicalJson();
        if ($json !== $canonical && $json !== $canonical . "\n") {
            self::refuse($source, 'SITE014_INVALID_VALUE', 'Expected the canonical bytes of the document this request decodes to.');
        }

        return $request;
    }

    private static function refuse(string $source, string $code, string $message, ?\Throwable $previous = null): never
    {
        throw new SiteManifestValidationException($source, [new ManifestViolation($code, '/', $message)], $previous);
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
