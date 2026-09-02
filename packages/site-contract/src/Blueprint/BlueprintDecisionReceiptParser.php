<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

use Waaseyaa\SiteContract\ManifestShapeReader;

/**
 * Structural typing for {@see BlueprintDecisionReceipt::fromArray()} (#2785,
 * design §6). A separate class rather than a static method on the readonly
 * receipt itself, because the receipt's constructor requires every field —
 * there is no default-constructible instance to parse onto.
 *
 * Not `@api`: invoked only by `BlueprintDecisionReceipt::fromArray()`.
 */
final class BlueprintDecisionReceiptParser
{
    use ManifestShapeReader;

    private const string DECIDED_AT_GRAMMAR = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D';

    /** @param array<string, mixed> $data */
    public function parse(array $data, string $source): BlueprintDecisionReceipt
    {
        $row = $this->shape(
            $data,
            ['schema', 'version', 'decision', 'blueprint_digest', 'manifest_digest', 'actor', 'decided_at', 'mechanism'],
            ['schema', 'version', 'decision', 'blueprint_digest', 'manifest_digest', 'actor', 'decided_at', 'mechanism'],
            '/',
            $source,
        );

        $schema = $this->string($row['schema'], '/schema', $source);
        if ($schema !== BlueprintDecisionReceipt::SCHEMA_ID) {
            $this->fail($source, 'SITE050_DECISION_RECEIPT_INVALID', '/schema', 'Expected waaseyaa.blueprint_decision.');
        }
        $version = $this->integer($row['version'], '/version', $source);
        if ($version !== BlueprintDecisionReceipt::CONTRACT_VERSION) {
            $this->fail($source, 'SITE050_DECISION_RECEIPT_INVALID', '/version', 'Unsupported decision receipt contract version.');
        }
        $decisionValue = $this->string($row['decision'], '/decision', $source);
        $decision = BlueprintDecision::tryFrom($decisionValue);
        if ($decision === null) {
            $this->fail($source, 'SITE050_DECISION_RECEIPT_INVALID', '/decision', 'Unknown blueprint decision.');
        }
        $blueprintDigest = $this->sha256($row['blueprint_digest'], '/blueprint_digest', $source);
        $manifestDigest = $this->sha256($row['manifest_digest'], '/manifest_digest', $source);
        $actor = $this->string($row['actor'], '/actor', $source);
        $mechanism = $this->string($row['mechanism'], '/mechanism', $source);
        $decidedAt = $this->string($row['decided_at'], '/decided_at', $source);
        if (preg_match(self::DECIDED_AT_GRAMMAR, $decidedAt) !== 1) {
            $this->fail($source, 'SITE050_DECISION_RECEIPT_INVALID', '/decided_at', 'Expected an RFC 3339 UTC timestamp.');
        }

        return new BlueprintDecisionReceipt($decision, $blueprintDigest, $manifestDigest, $actor, $decidedAt, $mechanism);
    }
}
