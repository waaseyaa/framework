<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\SiteManifest;

/**
 * An explicit, request-scoped approval or rejection decision over an exact
 * proposed blueprint and manifest (#2785, ADR-023 D-4).
 *
 * This is deliberately NOT a second site file. `site-contract` only defines
 * its closed shape and exact-digest matching; a higher layer decides how a
 * receipt is produced, authenticated, or retained (#2787).
 *
 * @api
 */
final readonly class BlueprintDecisionReceipt
{
    public const string SCHEMA_ID = 'waaseyaa.blueprint_decision';
    public const int CONTRACT_VERSION = 1;

    public function __construct(
        public BlueprintDecision $decision,
        public string $blueprintDigest,
        public string $manifestDigest,
        public string $actor,
        public string $decidedAt,
        public string $mechanism,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $source = '<receipt>'): self
    {
        return new BlueprintDecisionReceiptParser()->parse($data, $source);
    }

    /**
     * True iff `$manifest` has a blueprint and both digests match this
     * receipt exactly. Digest matching binds decision to exact proposal and
     * context bytes; it does not authenticate the actor (ADR-023 D-4).
     */
    public function matches(SiteManifest $manifest): bool
    {
        return $manifest->applicationBlueprint !== null
            && $this->blueprintDigest === $manifest->applicationBlueprint->digest
            && $this->manifestDigest === $manifest->digest;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_ID,
            'version' => self::CONTRACT_VERSION,
            'decision' => $this->decision->value,
            'blueprint_digest' => $this->blueprintDigest,
            'manifest_digest' => $this->manifestDigest,
            'actor' => $this->actor,
            'decided_at' => $this->decidedAt,
            'mechanism' => $this->mechanism,
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    /** SHA-256 of the canonical receipt bytes, without a trailing newline. */
    public function digest(): string
    {
        return hash('sha256', $this->canonicalJson());
    }
}
