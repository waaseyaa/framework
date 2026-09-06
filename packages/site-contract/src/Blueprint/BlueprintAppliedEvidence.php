<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

use Waaseyaa\SiteContract\Exception\ManifestViolation;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\ManifestShapeReader;
use Waaseyaa\SiteContract\SiteManifest;

/**
 * The closed durable evidence that an approved blueprint was applied through
 * the governed root generation transaction (#2787, ADR-023 D-5).
 *
 * Digest matching is deliberately deferred to {@see self::matches()} so an
 * otherwise valid prior generation remains readable as superseded evidence.
 *
 * @api
 */
final readonly class BlueprintAppliedEvidence
{
    use ManifestShapeReader;

    public string $generatorFeature;

    public BlueprintDecisionReceipt $decisionReceipt;

    /** @param array<string, mixed> $data */
    private function __construct(array $data, string $source)
    {
        $row = $this->shape(
            $data,
            ['generator_feature', 'decision_receipt'],
            ['generator_feature', 'decision_receipt'],
            '/',
            $source,
        );
        $generatorFeature = $this->string($row['generator_feature'], '/generator_feature', $source);
        if ($generatorFeature !== ApplicationBlueprint::GENERATOR_FEATURE) {
            $this->fail($source, 'SITE014_INVALID_VALUE', '/generator_feature', 'Expected the application blueprint generator feature.');
        }

        $receiptData = $this->shape($row['decision_receipt'], [], [], '/decision_receipt', $source, false);
        try {
            $decisionReceipt = BlueprintDecisionReceipt::fromArray($receiptData, $source);
        } catch (SiteManifestValidationException $exception) {
            throw new SiteManifestValidationException(
                $source,
                array_map(
                    static fn(ManifestViolation $violation): ManifestViolation => new ManifestViolation(
                        $violation->code,
                        $violation->path === '/' ? '/decision_receipt' : '/decision_receipt' . $violation->path,
                        $violation->message,
                    ),
                    $exception->violations,
                ),
                $exception,
            );
        }
        if ($decisionReceipt->decision !== BlueprintDecision::Approved) {
            $this->fail($source, 'SITE050_DECISION_RECEIPT_INVALID', '/decision_receipt/decision', 'Applied blueprint evidence requires an approved decision receipt.');
        }

        $this->generatorFeature = $generatorFeature;
        $this->decisionReceipt = $decisionReceipt;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $source = '<applied-evidence>'): self
    {
        return new self($data, $source);
    }

    public static function fromDecisionReceipt(BlueprintDecisionReceipt $receipt, string $source = '<decision-receipt>'): self
    {
        return self::fromArray([
            'generator_feature' => ApplicationBlueprint::GENERATOR_FEATURE,
            'decision_receipt' => $receipt->toArray(),
        ], $source);
    }

    public function matches(SiteManifest $manifest): bool
    {
        // The private constructor already validates the fixed feature token.
        return $this->decisionReceipt->matches($manifest);
    }

    /** @return array{generator_feature: string, decision_receipt: array<string, int|string>} */
    public function toArray(): array
    {
        return [
            'generator_feature' => $this->generatorFeature,
            'decision_receipt' => $this->decisionReceipt->toArray(),
        ];
    }
}
